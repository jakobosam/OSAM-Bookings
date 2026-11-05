'use strict';
/*
 * OSAM update server — updates.osamweb.com
 *
 * Serves signed OSAM Bookings releases from GitHub to client sites. Node 18.17+ with no
 * dependencies, so in cPanel there is nothing to "Run NPM Install" for.
 *
 * The server never signs anything. Releases and rollbacks are signed in GitHub Actions with
 * OSAM's private key, and the plugin checks those signatures itself. This server also checks
 * them (against PUBLIC_KEY) and refuses to cache or serve anything that doesn't verify.
 *
 * Settings come from environment variables (cPanel → Setup Node.js App → Environment variables):
 *   GITHUB_REPO       owner/repo that holds the releases, e.g. jakobosam/OSAM-Bookings   (required)
 *   GITHUB_TOKEN      fine-grained token, read-only "Contents" on that repo (required for a private repo)
 *   PUBLIC_KEY        OSAM release-signing public key (base64, from tools/keygen.html)     (required)
 *   ADMIN_TOKEN       long random string for /v1/sync, /v1/status and /v1/rollbacks        (required)
 *   PUBLIC_URL        https://updates.osamweb.com (used in download links)
 *   WEBHOOK_SECRET    optional: secret of a GitHub "Releases" webhook to /v1/webhook/github
 *   UPDATE_KEY        optional: sites must send this key (matches OSB_UPDATE_KEY in wp-config.php)
 *   DATA_DIR          optional: where releases and state are kept (default ./data next to app.js)
 *   SYNC_MINUTES      optional: how often to look for new releases (default 15)
 */

const http = require('node:http');
const crypto = require('node:crypto');
const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');

const VERSION = '1.0.0';
const env = process.env;
const CONF = {
  repo: String(env.GITHUB_REPO || '').trim(),
  token: String(env.GITHUB_TOKEN || '').trim(),
  publicKey: String(env.PUBLIC_KEY || '').trim(),
  adminToken: String(env.ADMIN_TOKEN || '').trim(),
  publicUrl: String(env.PUBLIC_URL || '').trim().replace(/\/+$/, ''),
  webhookSecret: String(env.WEBHOOK_SECRET || '').trim(),
  updateKey: String(env.UPDATE_KEY || '').trim(),
  dataDir: path.resolve(env.DATA_DIR || path.join(__dirname, 'data')),
  syncMinutes: Math.max(1, Number(env.SYNC_MINUTES || 15)),
  githubApi: String(env.GITHUB_API || 'https://api.github.com').replace(/\/+$/, ''),
  linkDays: 3,
};

/* ------------------------------------------------------------------ helpers */

const log = (...a) => console.log(new Date().toISOString(), ...a);

function cmpVersion(a, b) {
  // Semver-style compare that agrees with PHP version_compare for x.y.z and x.y.z-beta.N.
  const split = (v) => {
    const [main, pre = ''] = String(v).replace(/^v/i, '').split(/-(.*)/s);
    return { nums: main.split('.').map((n) => parseInt(n, 10) || 0), pre: pre ? pre.split('.') : [] };
  };
  const x = split(a);
  const y = split(b);
  for (let i = 0; i < Math.max(x.nums.length, y.nums.length); i++) {
    const d = (x.nums[i] || 0) - (y.nums[i] || 0);
    if (d) return d < 0 ? -1 : 1;
  }
  if (!x.pre.length || !y.pre.length) return x.pre.length === y.pre.length ? 0 : x.pre.length ? -1 : 1;
  for (let i = 0; i < Math.max(x.pre.length, y.pre.length); i++) {
    if (x.pre[i] === undefined) return -1;
    if (y.pre[i] === undefined) return 1;
    const nx = /^\d+$/.test(x.pre[i]);
    const ny = /^\d+$/.test(y.pre[i]);
    if (nx && ny && +x.pre[i] !== +y.pre[i]) return +x.pre[i] < +y.pre[i] ? -1 : 1;
    if (nx !== ny) return nx ? -1 : 1;
    if (x.pre[i] !== y.pre[i]) return x.pre[i] < y.pre[i] ? -1 : 1;
  }
  return 0;
}

const isPre = (v) => String(v).includes('-');
const validVersion = (v) => /^\d+(\.\d+){1,3}(-[0-9A-Za-z.]+)?$/.test(String(v));
const validSlug = (s) => /^[a-z0-9][a-z0-9-]{0,63}$/.test(String(s));

function hostOf(site) {
  let h = String(site || '').trim().toLowerCase();
  try {
    if (/^[a-z][a-z0-9+.-]*:\/\//.test(h)) h = new URL(h).hostname;
  } catch (e) {
    return '';
  }
  return h.replace(/[/:].*$/, '').replace(/^www\./, '');
}

function safeEqual(a, b) {
  const x = Buffer.from(String(a));
  const y = Buffer.from(String(b));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
}

let pubKeyObj = null;
function publicKey() {
  if (pubKeyObj) return pubKeyObj;
  const raw = Buffer.from(CONF.publicKey, 'base64');
  if (raw.length !== 32) throw new Error('PUBLIC_KEY must be the base64 32-byte Ed25519 public key');
  pubKeyObj = crypto.createPublicKey({ key: Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), raw]), format: 'der', type: 'spki' });
  return pubKeyObj;
}

function verify(message, signature) {
  try {
    const sig = Buffer.from(String(signature || ''), 'base64');
    return sig.length === 64 && crypto.verify(null, Buffer.from(message, 'utf8'), publicKey(), sig);
  } catch (e) {
    return false;
  }
}

async function readJson(file, fallback) {
  try {
    return JSON.parse(await fsp.readFile(file, 'utf8'));
  } catch (e) {
    return fallback;
  }
}

async function writeJson(file, data) {
  await fsp.mkdir(path.dirname(file), { recursive: true });
  const tmp = `${file}.${process.pid}.tmp`;
  await fsp.writeFile(tmp, JSON.stringify(data, null, 2));
  await fsp.rename(tmp, file);
}

const P = {
  releases: () => path.join(CONF.dataDir, 'releases'),
  config: () => path.join(CONF.dataDir, 'config.json'),
  sites: () => path.join(CONF.dataDir, 'sites.json'),
  rollbacks: () => path.join(CONF.dataDir, 'rollbacks.json'),
  secret: () => path.join(CONF.dataDir, '.link-secret'),
  state: () => path.join(CONF.dataDir, 'state.json'),
};

/* ------------------------------------------------------------------ state */

const S = {
  releases: new Map(), // "slug@version" -> meta
  rollbacks: [],
  sites: {},
  config: null,
  configMtime: 0,
  linkSecret: null,
  lastSync: null, // { at, ok, message, added }
  syncing: null,
  sitesDirty: false,
};

const DEFAULT_CONFIG = {
  default_channel: 'stable',
  allowed_sites: [],
  minimum_version: {},
  sites: {},
};

async function config() {
  try {
    const st = await fsp.stat(P.config());
    if (!S.config || st.mtimeMs !== S.configMtime) {
      const c = await readJson(P.config(), null);
      if (c && typeof c === 'object') {
        S.config = { ...DEFAULT_CONFIG, ...c };
        S.configMtime = st.mtimeMs;
      } else if (!S.config) {
        log('config.json could not be read; using defaults');
        S.config = { ...DEFAULT_CONFIG };
      }
    }
  } catch (e) {
    if (!S.config) {
      S.config = { ...DEFAULT_CONFIG };
      await writeJson(P.config(), S.config).catch(() => {});
    }
  }
  return S.config;
}

function minimumFor(cfg, slug) {
  const m = cfg.minimum_version;
  if (!m) return '';
  if (typeof m === 'string') return m;
  return String(m[slug] || '');
}

async function loadReleases() {
  S.releases.clear();
  const root = P.releases();
  let slugs = [];
  try {
    slugs = await fsp.readdir(root);
  } catch (e) {
    return;
  }
  for (const slug of slugs) {
    if (!validSlug(slug)) continue;
    for (const f of await fsp.readdir(path.join(root, slug)).catch(() => [])) {
      if (!f.endsWith('.json')) continue;
      const meta = await readJson(path.join(root, slug, f), null);
      if (meta && meta.slug === slug && validVersion(meta.version) && fs.existsSync(zipPath(slug, meta.version)) && releaseSigned(meta)) {
        S.releases.set(`${slug}@${meta.version}`, meta);
      }
    }
  }
}

const zipPath = (slug, version) => path.join(P.releases(), slug, `${slug}-${version}.zip`);
const releaseSigned = (m) => /^[0-9a-f]{64}$/.test(String(m.sha256)) && verify(`OSAMSIG1\n${m.slug}\n${m.version}\n${m.sha256}`, m.signature);

/* ------------------------------------------------------------------ rollbacks */

function decodeRollback(item) {
  if (!item || typeof item.payload !== 'string' || typeof item.signature !== 'string') return null;
  if (!verify(`OSAMROLL1\n${item.payload}`, item.signature)) return null;
  let p;
  try {
    p = JSON.parse(Buffer.from(item.payload, 'base64').toString('utf8'));
  } catch (e) {
    return null;
  }
  if (!p || p.type !== 'rollback' || !validSlug(p.slug) || !validVersion(p.target_version) || !Array.isArray(p.block_versions) || !Array.isArray(p.sites)) return null;
  p.block_versions = p.block_versions.map(String);
  p.sites = p.sites.map((s) => (s === '*' ? '*' : hostOf(s)));
  return p;
}

const coversSite = (p, host) => p.sites.includes('*') || p.sites.includes(host);

/* ------------------------------------------------------------------ GitHub sync */

async function gh(url, opts = {}) {
  const headers = { 'User-Agent': 'osam-update-server', 'X-GitHub-Api-Version': '2022-11-28', Accept: 'application/vnd.github+json', ...(opts.headers || {}) };
  if (CONF.token) headers.Authorization = `Bearer ${CONF.token}`;
  return fetch(url.startsWith('http') ? url : `${CONF.githubApi}${url}`, { ...opts, headers, signal: AbortSignal.timeout(120000) });
}

async function downloadAsset(asset) {
  // The API answers with a redirect to GitHub's file storage; follow it without our token.
  let res = await gh(asset.url, { headers: { Accept: 'application/octet-stream' }, redirect: 'manual' });
  if (res.status >= 300 && res.status < 400 && res.headers.get('location')) {
    res = await fetch(res.headers.get('location'), { headers: { 'User-Agent': 'osam-update-server' }, signal: AbortSignal.timeout(300000) });
  }
  if (!res.ok) throw new Error(`download of ${asset.name} failed (HTTP ${res.status})`);
  return Buffer.from(await res.arrayBuffer());
}

async function syncFromGithub() {
  if (S.syncing) return S.syncing;
  S.syncing = (async () => {
    const added = [];
    const skipped = [];
    try {
      if (!/^[\w.-]+\/[\w.-]+$/.test(CONF.repo)) throw new Error('GITHUB_REPO is not set (owner/repo)');
      publicKey();
      const list = [];
      for (let page = 1; page <= 3; page++) {
        const res = await gh(`/repos/${CONF.repo}/releases?per_page=50&page=${page}`);
        if (!res.ok) throw new Error(`GitHub answered HTTP ${res.status} listing releases${res.status === 404 ? ' (check GITHUB_REPO and the token can read it)' : ''}`);
        const batch = await res.json();
        list.push(...batch);
        if (batch.length < 50) break;
      }
      const seen = new Set();
      for (const rel of list) {
        if (rel.draft) continue;
        const assets = rel.assets || [];
        const metaAsset = assets.find((a) => a.name === 'release.json');
        if (!metaAsset) continue;
        let meta;
        try {
          meta = JSON.parse((await downloadAsset(metaAsset)).toString('utf8'));
        } catch (e) {
          skipped.push(`${rel.tag_name}: ${e.message}`);
          continue;
        }
        const tagVersion = String(rel.tag_name || '').replace(/^v/, '');
        if (!meta || !validSlug(meta.slug) || meta.version !== tagVersion || !releaseSigned(meta)) {
          skipped.push(`${rel.tag_name}: release.json is not signed by PUBLIC_KEY or doesn't match the tag`);
          continue;
        }
        const key = `${meta.slug}@${meta.version}`;
        seen.add(key);
        const have = S.releases.get(key);
        if (have && have.sha256 === meta.sha256 && fs.existsSync(zipPath(meta.slug, meta.version))) {
          have.prerelease = !!rel.prerelease || isPre(meta.version);
          continue;
        }
        const zipAsset = assets.find((a) => a.name === `${meta.slug}-${meta.version}.zip`);
        if (!zipAsset) {
          skipped.push(`${rel.tag_name}: no ${meta.slug}-${meta.version}.zip attached`);
          continue;
        }
        const zip = await downloadAsset(zipAsset);
        const sha = crypto.createHash('sha256').update(zip).digest('hex');
        if (sha !== meta.sha256) {
          skipped.push(`${rel.tag_name}: zip doesn't match its signed hash`);
          continue;
        }
        const dir = path.join(P.releases(), meta.slug);
        await fsp.mkdir(dir, { recursive: true });
        const tmp = `${zipPath(meta.slug, meta.version)}.${process.pid}.tmp`;
        await fsp.writeFile(tmp, zip);
        await fsp.rename(tmp, zipPath(meta.slug, meta.version));
        const full = {
          ...meta,
          size: zip.length,
          prerelease: !!rel.prerelease || isPre(meta.version),
          published_at: rel.published_at || rel.created_at || new Date().toISOString(),
          github_url: rel.html_url || '',
        };
        await writeJson(path.join(dir, `${meta.version}.json`), full);
        S.releases.set(key, full);
        added.push(key);
      }
      // Releases deleted from GitHub stop being offered (files are kept on disk).
      for (const key of [...S.releases.keys()]) if (!seen.has(key)) S.releases.delete(key);
      S.lastSync = { at: new Date().toISOString(), ok: true, added, skipped, releases: S.releases.size };
      if (added.length || skipped.length) log('sync:', JSON.stringify(S.lastSync));
    } catch (e) {
      S.lastSync = { at: new Date().toISOString(), ok: false, message: e.message, added, skipped };
      log('sync failed:', e.message);
    } finally {
      await writeJson(P.state(), { lastSync: S.lastSync }).catch(() => {});
      S.syncing = null;
    }
    return S.lastSync;
  })();
  return S.syncing;
}

/* ------------------------------------------------------------------ download links */

function linkSig(slug, version, host, exp) {
  return crypto.createHmac('sha256', S.linkSecret).update(`${slug}|${version}|${host}|${exp}`).digest('hex');
}

function packageUrl(base, slug, version, host) {
  const exp = Math.floor(Date.now() / 1000) + CONF.linkDays * 86400;
  const q = new URLSearchParams({ site: host, exp: String(exp), sig: linkSig(slug, version, host, exp) });
  return `${base}/v1/download/${slug}/${version}.zip?${q}`;
}

/* ------------------------------------------------------------------ the update answer */

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function answerUpdate(q, base) {
  const slug = String(q.get('slug') || 'osam-bookings');
  const installed = String(q.get('version') || '0');
  const host = hostOf(q.get('site'));
  if (!validSlug(slug)) return [400, { ok: false, code: 'bad_request', message: 'Unknown plugin.' }];
  if (CONF.updateKey && !safeEqual(q.get('key') || '', CONF.updateKey)) {
    return [403, { ok: false, code: 'bad_key', message: 'This site is not authorised for OSAM updates (update key missing or wrong).' }];
  }
  if (!host) return [400, { ok: false, code: 'bad_request', message: 'Site address missing.' }];
  const cfg = await config();
  const allowed = (cfg.allowed_sites || []).map(hostOf).filter(Boolean);
  if (allowed.length && !allowed.includes(host)) {
    return [403, { ok: false, code: 'not_allowed', message: 'This site is not on OSAM’s update list. Please contact OSAM.' }];
  }
  const siteCfg = (cfg.sites && (cfg.sites[host] || cfg.sites[`www.${host}`])) || {};
  const channel = siteCfg.channel === 'beta' || (!siteCfg.channel && cfg.default_channel === 'beta') ? 'beta' : 'stable';

  // Note the site for /v1/status.
  S.sites[host] = { ...(S.sites[host] || {}), slug, version: installed, wp: String(q.get('wp') || ''), php: String(q.get('php') || ''), channel, last_seen: new Date().toISOString() };
  S.sitesDirty = true;

  // Pick up new releases if the scheduled sync hasn't run for a while (Passenger may have slept).
  const stale = !S.lastSync || Date.now() - Date.parse(S.lastSync.at) > CONF.syncMinutes * 60000;
  if (stale && !S.syncing) syncFromGithub();
  if (!S.releases.size && S.syncing) await S.syncing;

  const rbs = S.rollbacks.map((r) => ({ r, p: r.decoded })).filter(({ p }) => p && p.slug === slug && coversSite(p, host));
  const blocked = new Set(rbs.flatMap(({ p }) => p.block_versions));
  const all = [...S.releases.values()].filter((m) => m.slug === slug);
  const offerable = all.filter((m) => !blocked.has(m.version) && (channel === 'beta' || !m.prerelease)).sort((a, b) => cmpVersion(b.version, a.version));

  let chosen = null;
  let instruction = null;
  let forced = false;

  // 1. A signed rollback that applies to this site's installed version.
  const now = Math.floor(Date.now() / 1000);
  const live = rbs.filter(({ p }) => p.expires_at > now && p.block_versions.includes(installed)).sort((a, b) => b.p.issued_at - a.p.issued_at);
  for (const { r, p } of live) {
    const target = all.find((m) => m.version === p.target_version && !blocked.has(m.version));
    if (target) {
      chosen = target;
      instruction = { payload: r.payload, signature: r.signature };
      forced = true;
      break;
    }
  }
  // 2. A pinned version for this site.
  if (!chosen && siteCfg.pin) {
    chosen = all.find((m) => m.version === String(siteCfg.pin) && !blocked.has(m.version)) || null;
  }
  // 3. The newest release on the site's channel.
  if (!chosen) chosen = offerable[0] || null;
  if (!chosen) return [200, { ok: false, code: 'no_release', message: 'No release is available yet.' }];

  if (!instruction) {
    const min = minimumFor(cfg, slug);
    forced = !!min && cmpVersion(installed, min) < 0 && cmpVersion(chosen.version, installed) > 0 && cmpVersion(chosen.version, min) >= 0;
  }
  const published = String(chosen.published_at || '').replace('T', ' ').replace(/\..*$|Z$/, '');
  return [
    200,
    {
      ok: true,
      slug,
      name: chosen.name || 'OSAM Bookings',
      version: chosen.version,
      package: packageUrl(base, slug, chosen.version, host),
      requires: chosen.requires || '',
      requires_php: chosen.requires_php || '',
      tested: chosen.tested || '',
      author: 'OSAM',
      last_updated: published,
      size: chosen.size || 0,
      sections: {
        description: chosen.description ? `<p>${esc(chosen.description)}</p>` : '',
        changelog: chosen.changelog_html || '',
      },
      forced,
      rollback: !!instruction && cmpVersion(chosen.version, installed) < 0,
      sha256: chosen.sha256,
      signature: chosen.signature,
      instruction,
      channel,
    },
  ];
}

/* ------------------------------------------------------------------ HTTP */

function send(res, code, body, headers = {}) {
  const json = typeof body === 'string' ? body : JSON.stringify(body);
  res.writeHead(code, { 'Content-Type': typeof body === 'string' ? 'text/plain; charset=utf-8' : 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff', ...headers });
  res.end(json);
}

function readBody(req, limit = 1024 * 1024) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (c) => {
      size += c.length;
      if (size > limit) {
        reject(new Error('too large'));
        req.destroy();
      } else chunks.push(c);
    });
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}

function isAdmin(req, url) {
  const m = String(req.headers.authorization || '').match(/^Bearer\s+(.+)$/i);
  // ?token= lets /v1/status and /v1/sync be opened in a browser (GET only).
  const given = m ? m[1].trim() : req.method === 'GET' && url ? String(url.searchParams.get('token') || '') : '';
  return !!CONF.adminToken && !!given && safeEqual(given, CONF.adminToken);
}

function baseUrl(req) {
  if (CONF.publicUrl) return CONF.publicUrl;
  const proto = String(req.headers['x-forwarded-proto'] || 'https').split(',')[0].trim();
  return `${proto}://${req.headers['x-forwarded-host'] || req.headers.host}`;
}

async function handle(req, res) {
  const url = new URL(req.url, 'http://x');
  // Passenger may mount the app under a sub-path; only the tail matters.
  const route = url.pathname.replace(/^.*?(\/v1\/|$)/, '$1').replace(/\/+$/, '') || '/';
  const method = req.method;

  if (route === '/' || route === '/v1/health') {
    return send(res, 200, { ok: true, service: 'osam-update-server', version: VERSION, releases: S.releases.size, last_sync: S.lastSync ? S.lastSync.at : null });
  }

  if (route === '/v1/update' && method === 'GET') {
    const [code, body] = await answerUpdate(url.searchParams, baseUrl(req));
    return send(res, code, body);
  }

  let m;
  if ((m = route.match(/^\/v1\/download\/([a-z0-9-]+)\/([0-9A-Za-z.-]+?)(?:\.zip)?$/)) && (method === 'GET' || method === 'HEAD')) {
    const [, slug, version] = m;
    const host = hostOf(url.searchParams.get('site'));
    const exp = Number(url.searchParams.get('exp') || 0);
    const sig = String(url.searchParams.get('sig') || '');
    if (!host || exp < Date.now() / 1000 || !safeEqual(sig, linkSig(slug, version, host, exp))) {
      return send(res, 403, { ok: false, code: 'link_expired', message: 'This download link has expired. Check for updates again.' });
    }
    const meta = S.releases.get(`${slug}@${version}`);
    if (!meta) return send(res, 404, { ok: false, code: 'not_found', message: 'That release is no longer available.' });
    const file = zipPath(slug, version);
    const st = await fsp.stat(file).catch(() => null);
    if (!st) return send(res, 404, { ok: false, code: 'not_found', message: 'That release is no longer available.' });
    res.writeHead(200, { 'Content-Type': 'application/zip', 'Content-Length': st.size, 'Content-Disposition': `attachment; filename="${slug}-${version}.zip"`, 'Cache-Control': 'no-store' });
    if (method === 'HEAD') return res.end();
    return fs.createReadStream(file).pipe(res);
  }

  if (route === '/v1/webhook/github' && method === 'POST') {
    if (!CONF.webhookSecret) return send(res, 404, { ok: false, code: 'not_found' });
    const raw = await readBody(req);
    const want = `sha256=${crypto.createHmac('sha256', CONF.webhookSecret).update(raw).digest('hex')}`;
    if (!safeEqual(String(req.headers['x-hub-signature-256'] || ''), want)) return send(res, 401, { ok: false, code: 'bad_signature' });
    const event = String(req.headers['x-github-event'] || '');
    if (event === 'ping') return send(res, 200, { ok: true, pong: true });
    if (event === 'release') syncFromGithub();
    return send(res, 202, { ok: true, event });
  }

  if (route === '/v1/sync' && (method === 'GET' || method === 'POST')) {
    if (!isAdmin(req, url)) return send(res, 401, { ok: false, code: 'unauthorised' });
    const r = await syncFromGithub();
    return send(res, r.ok ? 200 : 502, r);
  }

  if (route === '/v1/status' && method === 'GET') {
    if (!isAdmin(req, url)) return send(res, 401, { ok: false, code: 'unauthorised' });
    const cfg = await config();
    const releases = [...S.releases.values()].sort((a, b) => cmpVersion(b.version, a.version)).map((r) => ({ slug: r.slug, version: r.version, prerelease: r.prerelease, published_at: r.published_at, size: r.size }));
    const versions = {};
    for (const s of Object.values(S.sites)) versions[s.version] = (versions[s.version] || 0) + 1;
    return send(res, 200, {
      ok: true,
      server: VERSION,
      last_sync: S.lastSync,
      config: cfg,
      releases,
      rollbacks: S.rollbacks.map((r) => r.decoded),
      site_versions: versions,
      sites: S.sites,
    });
  }

  if (route === '/v1/rollbacks' && method === 'POST') {
    if (!isAdmin(req)) return send(res, 401, { ok: false, code: 'unauthorised' });
    let item;
    try {
      item = JSON.parse((await readBody(req, 64 * 1024)).toString('utf8'));
    } catch (e) {
      return send(res, 400, { ok: false, code: 'bad_request', message: 'Send JSON {payload, signature}.' });
    }
    const p = decodeRollback(item);
    if (!p) return send(res, 400, { ok: false, code: 'bad_signature', message: 'The rollback instruction is not signed by PUBLIC_KEY, or is malformed.' });
    if (p.expires_at < Date.now() / 1000) return send(res, 400, { ok: false, code: 'expired', message: 'The instruction has already expired.' });
    if (!S.releases.has(`${p.slug}@${p.target_version}`)) await syncFromGithub();
    if (!S.releases.has(`${p.slug}@${p.target_version}`)) {
      return send(res, 400, { ok: false, code: 'no_target', message: `Version ${p.target_version} isn't an available signed release, so sites couldn't roll back to it.` });
    }
    if (p.block_versions.includes(p.target_version)) return send(res, 400, { ok: false, code: 'bad_request', message: 'The target version is also in the withdrawn list.' });
    if (S.rollbacks.some((r) => r.decoded.id === p.id)) return send(res, 200, { ok: true, duplicate: true, rollback: p });
    S.rollbacks.push({ payload: item.payload, signature: item.signature, decoded: p, received_at: new Date().toISOString() });
    await writeJson(P.rollbacks(), S.rollbacks.map(({ decoded, ...rest }) => rest));
    log('rollback stored:', JSON.stringify(p));
    return send(res, 201, { ok: true, rollback: p });
  }

  return send(res, 404, { ok: false, code: 'not_found' });
}

/* ------------------------------------------------------------------ start */

async function start() {
  await fsp.mkdir(CONF.dataDir, { recursive: true });
  try {
    S.linkSecret = Buffer.from((await fsp.readFile(P.secret(), 'utf8')).trim(), 'hex');
  } catch (e) {
    S.linkSecret = crypto.randomBytes(32);
    await fsp.writeFile(P.secret(), S.linkSecret.toString('hex'), { mode: 0o600 });
  }
  const problems = [];
  if (!CONF.repo) problems.push('GITHUB_REPO');
  if (!CONF.adminToken || CONF.adminToken.length < 24) problems.push('ADMIN_TOKEN (24+ characters)');
  try {
    publicKey();
  } catch (e) {
    problems.push('PUBLIC_KEY');
  }
  if (problems.length) log(`WARNING: set these environment variables: ${problems.join(', ')}`);

  await config();
  S.sites = await readJson(P.sites(), {});
  S.rollbacks = (await readJson(P.rollbacks(), []))
    .map((r) => ({ ...r, decoded: problems.includes('PUBLIC_KEY') ? null : decodeRollback(r) }))
    .filter((r) => r.decoded);
  S.lastSync = ((await readJson(P.state(), {})) || {}).lastSync || null;
  if (!problems.includes('PUBLIC_KEY')) await loadReleases();

  const server = http.createServer((req, res) => {
    handle(req, res).catch((e) => {
      log('error:', e && e.stack ? e.stack : e);
      if (!res.headersSent) send(res, 500, { ok: false, code: 'server_error', message: 'Update server error.' });
      else res.end();
    });
  });
  server.listen(env.PORT || 3000, () => log(`osam-update-server ${VERSION} listening on ${env.PORT || 3000}`));

  setInterval(async () => {
    if (S.sitesDirty) {
      S.sitesDirty = false;
      await writeJson(P.sites(), S.sites).catch((e) => log('could not save sites.json:', e.message));
    }
  }, 30000).unref();
  setInterval(() => syncFromGithub(), CONF.syncMinutes * 60000).unref();
  if (!problems.length) syncFromGithub();

  const stop = async () => {
    if (S.sitesDirty) await writeJson(P.sites(), S.sites).catch(() => {});
    process.exit(0);
  };
  process.on('SIGTERM', stop);
  process.on('SIGINT', stop);
  return server;
}

// cPanel's Passenger loads this file through its own wrapper, so start unconditionally.
if (!process.env.OSAM_NO_AUTOSTART) start();

module.exports = { start, cmpVersion, hostOf, _state: S, _conf: CONF };
