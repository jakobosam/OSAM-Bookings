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
 *   MAX_SITES         optional: most sites kept in sites/reports/history (default 1000; the
 *                     longest-silent ones are dropped first)
 *
 * Since 1.2.0 sites also send a daily status report (POST /v1/report) and update results
 * (POST /v1/event), and there is a dashboard at /dashboard (log in with ADMIN_TOKEN).
 */

const http = require('node:http');
const crypto = require('node:crypto');
const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');

const VERSION = '1.2.0';
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
  maxSites: Math.max(10, Number(env.MAX_SITES || 1000)),
  flushSeconds: Math.max(1, Number(env.FLUSH_SECONDS || 30)),
};

// Limits on what sites may send (everything from a site is untrusted).
const LIMIT = {
  body: 32 * 1024, // raw POST body
  report: 16 * 1024, // stored report after cleaning
  slugsPerSite: 4,
  reportsPerHour: 6, // per site and plugin
  eventsPerHour: 20, // per site
  perIp: 120, // report + event requests per IP per 10 minutes
  versions: 40, // version timeline entries per site and plugin
  updates: 50, // update results kept per site
  daily: 30, // daily summaries kept per site and plugin
  downloads: 20, // downloads kept per site
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
  if (!h || h.length > 500) return '';
  try {
    // Bare hosts go through URL too, so odd characters are refused and IDNs become punycode.
    h = new URL(/^[a-z][a-z0-9+.-]*:\/\//.test(h) ? h : `http://${h}`).hostname;
  } catch (e) {
    return '';
  }
  h = h.replace(/\.$/, '').replace(/^www\./, '');
  return validHost(h) ? h : '';
}

const validHost = (h) => typeof h === 'string' && h.length <= 253 && (/^[a-z0-9_](?:[a-z0-9_-]{0,62}[a-z0-9_])?(?:\.[a-z0-9_](?:[a-z0-9_-]{0,62}[a-z0-9_])?)*$/.test(h) || /^\[[0-9a-f:.]+\]$/.test(h));

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

async function writeJson(file, data, compact = false) {
  await fsp.mkdir(path.dirname(file), { recursive: true });
  const tmp = `${file}.${process.pid}.tmp`;
  await fsp.writeFile(tmp, compact ? JSON.stringify(data) : JSON.stringify(data, null, 2));
  await fsp.rename(tmp, file);
}

const P = {
  releases: () => path.join(CONF.dataDir, 'releases'),
  config: () => path.join(CONF.dataDir, 'config.json'),
  sites: () => path.join(CONF.dataDir, 'sites.json'),
  rollbacks: () => path.join(CONF.dataDir, 'rollbacks.json'),
  secret: () => path.join(CONF.dataDir, '.link-secret'),
  state: () => path.join(CONF.dataDir, 'state.json'),
  reports: () => path.join(CONF.dataDir, 'reports.json'),
  history: () => path.join(CONF.dataDir, 'history.json'),
  downloads: () => path.join(CONF.dataDir, 'downloads.json'),
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
  reports: {}, // host -> slug -> { received_at, version, ip, report }
  history: {}, // host -> { versions: { slug: [{ version, first_seen }] }, updates: [], daily: [], downloads: [] }
  downloads: {}, // "slug@version" -> { count, last_at, hosts: [] }
  dirty: new Set(), // which of sites/reports/history/downloads need saving
};

// Saved every FLUSH_SECONDS and on shutdown; compact JSON for the big ones.
const STORES = {
  sites: { file: () => P.sites(), get: () => S.sites, compact: false },
  reports: { file: () => P.reports(), get: () => S.reports, compact: true },
  history: { file: () => P.history(), get: () => S.history, compact: true },
  downloads: { file: () => P.downloads(), get: () => S.downloads, compact: false },
};
const dirty = (...names) => names.forEach((n) => S.dirty.add(n));

async function flush() {
  const names = [...S.dirty];
  S.dirty.clear();
  for (const n of names) {
    await writeJson(STORES[n].file(), STORES[n].get(), STORES[n].compact).catch((e) => log(`could not save ${n}:`, e.message));
  }
}

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
  if (typeof m === 'string') return slug === 'osam-bookings' ? m : ''; // a plain string means OSAM Bookings
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
        // Tags are v1.2.3 (OSAM Bookings) or <slug>-v1.2.3 (other plugins, e.g. osam-turnstile-v1.3.0).
        const tagVersion = String(rel.tag_name || '').replace(new RegExp(`^${meta && validSlug(meta.slug) ? meta.slug : '\\0'}-`), '').replace(/^v/, '');
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

const siteConfig = (cfg, host) => {
  const s = cfg.sites && typeof cfg.sites === 'object' ? cfg.sites[host] || cfg.sites[`www.${host}`] : null;
  return s && typeof s === 'object' ? s : {};
};
const channelFor = (cfg, host) => {
  const s = siteConfig(cfg, host);
  return s.channel === 'beta' || (!s.channel && cfg.default_channel === 'beta') ? 'beta' : 'stable';
};

/* ------------------------------------------------------------------ sites, history, size limits */

function historyOf(host) {
  const h = (S.history[host] = S.history[host] || {});
  h.versions = h.versions && typeof h.versions === 'object' ? h.versions : {};
  for (const k of ['updates', 'daily', 'downloads']) h[k] = Array.isArray(h[k]) ? h[k] : [];
  return h;
}

// Version timeline: a new entry whenever a site's version of a plugin changes.
function noteVersion(host, slug, version, at) {
  if (!validVersion(version)) return;
  const list = (historyOf(host).versions[slug] = historyOf(host).versions[slug] || []);
  const last = list[list.length - 1];
  if (last && last.version === version) return;
  list.push({ version, first_seen: at });
  if (list.length > LIMIT.versions) list.splice(0, list.length - LIMIT.versions);
  dirty('history');
}

// Called for every update check and report.
function noteSite(host, slug, version, extra = {}) {
  const isNew = !S.sites[host];
  const prev = S.sites[host] || {};
  const at = new Date().toISOString();
  const prevPlugins = prev.plugins || (prev.slug ? { [prev.slug]: { version: prev.version, last_seen: prev.last_seen } } : {});
  const plugins = { ...prevPlugins, [slug]: { ...(prevPlugins[slug] || {}), version, last_seen: at } };
  S.sites[host] = { ...prev, ...Object.fromEntries(Object.entries(extra).filter(([, v]) => v !== undefined && v !== '')), last_seen: at, plugins };
  if (slug === 'osam-bookings') Object.assign(S.sites[host], { slug, version });
  noteVersion(host, slug, version, at);
  dirty('sites');
  if (isNew) pruneSites();
}

// Keep the data files bounded: drop the sites that have been silent longest.
function pruneSites() {
  const hosts = new Set([...Object.keys(S.sites), ...Object.keys(S.reports), ...Object.keys(S.history)]);
  if (hosts.size <= CONF.maxSites) return;
  const seen = (h) => Date.parse((S.sites[h] && S.sites[h].last_seen) || '') || 0;
  const drop = [...hosts].sort((a, b) => seen(a) - seen(b)).slice(0, hosts.size - CONF.maxSites);
  for (const h of drop) {
    delete S.sites[h];
    delete S.reports[h];
    delete S.history[h];
  }
  dirty('sites', 'reports', 'history');
  log(`dropped ${drop.length} long-silent site(s) to stay under MAX_SITES`);
}

// Untrusted JSON from a site -> plain data with bounded size: limited depth, keys, array lengths
// and string lengths; only JSON scalars, objects and arrays survive.
function clean(v, depth = 0) {
  if (v === null || typeof v === 'boolean') return v;
  if (typeof v === 'number') return Number.isFinite(v) && Math.abs(v) < 1e15 ? v : null;
  if (typeof v === 'string') return v.replace(/[\u0000-\u001f\u007f\u2028\u2029]/g, ' ').slice(0, 300);
  if (depth >= 5 || typeof v !== 'object') return null;
  if (Array.isArray(v)) return v.slice(0, 50).map((x) => clean(x, depth + 1));
  const out = {};
  let n = 0;
  for (const [k, x] of Object.entries(v)) {
    if (n >= 60) break;
    if (!/^[A-Za-z0-9_.-]{1,40}$/.test(k) || k === '__proto__' || k === 'constructor' || k === 'prototype') continue;
    out[k] = clean(x, depth + 1);
    n++;
  }
  return out;
}

// One line per site, plugin and day, for the history table.
function dailySummary(slug, version, r) {
  const g = (o, ...keys) => keys.reduce((a, k) => (a && typeof a === 'object' ? a[k] : undefined), o);
  const num = (x) => (typeof x === 'number' ? x : null);
  return {
    date: new Date().toISOString().slice(0, 10),
    slug,
    version,
    wp: String(g(r, 'env', 'wp') || ''),
    php: String(g(r, 'env', 'php') || ''),
    cron: String(g(r, 'cron', 'state') || ''),
    errors_24h: num(g(r, 'problems', 'day', 'errors')),
    warnings_24h: num(g(r, 'problems', 'day', 'warnings')),
    bookings_30d: num(g(r, 'bookings', 'last_30d')),
    upcoming: num(g(r, 'bookings', 'upcoming')),
    subscription: String(g(r, 'subscription', 'status') || ''),
  };
}

/* ------------------------------------------------------------------ rate limits */

const RL = new Map(); // key -> { start, count }
function limited(key, max, windowMs) {
  const now = Date.now();
  let e = RL.get(key);
  if (!e || now - e.start >= windowMs) {
    e = { start: now, count: 0, windowMs };
    RL.set(key, e);
  }
  e.count++;
  return e.count > max ? Math.ceil((e.start + windowMs - now) / 1000) : 0;
}
setInterval(() => {
  const now = Date.now();
  for (const [k, e] of RL) if (now - e.start >= e.windowMs) RL.delete(k);
}, 10 * 60000).unref();

// The visitor's IP. Behind cPanel's Passenger/Apache the socket is local (or a unix socket), so the
// address the proxy appended (the last X-Forwarded-For entry) is used; earlier entries can be forged.
function clientIp(req) {
  const ra = String((req.socket && req.socket.remoteAddress) || '');
  const local = !ra || ra === '::1' || ra.startsWith('127.') || ra === '::ffff:127.0.0.1';
  const xff = String(req.headers['x-forwarded-for'] || '').split(',').map((s) => s.trim()).filter(Boolean);
  return (local && xff.length ? xff[xff.length - 1] : ra || 'local').slice(0, 64);
}

/* ------------------------------------------------------------------ reports and events from sites */

async function readSiteJson(req, res) {
  if (Number(req.headers['content-length'] || 0) > LIMIT.body) {
    send(res, 413, { ok: false, code: 'too_large', message: 'Report too large.' });
    return null;
  }
  let raw;
  try {
    raw = await readBody(req, LIMIT.body);
  } catch (e) {
    if (!res.headersSent) send(res, 413, { ok: false, code: 'too_large', message: 'Report too large.' });
    return null;
  }
  let body;
  try {
    body = JSON.parse(raw.toString('utf8'));
  } catch (e) {
    body = null;
  }
  if (!body || typeof body !== 'object' || Array.isArray(body)) {
    send(res, 400, { ok: false, code: 'bad_request', message: 'Send a JSON object.' });
    return null;
  }
  return body;
}

// Common checks for /v1/report and /v1/event. Returns { host, slug, version } or null (answered).
async function siteFromBody(req, res, body, kind) {
  const ip = clientIp(req);
  const wait = limited(`ip:${ip}`, LIMIT.perIp, 10 * 60000);
  if (wait) {
    send(res, 429, { ok: false, code: 'rate_limited', message: 'Too many requests.' }, { 'Retry-After': String(wait) });
    return null;
  }
  const slug = String(body.slug || '');
  const version = String(body.version || '');
  const host = hostOf(body.site);
  if (!validSlug(slug) || !validVersion(version) || !host) {
    send(res, 400, { ok: false, code: 'bad_request', message: 'slug, site and version are required.' });
    return null;
  }
  if (CONF.updateKey && !safeEqual(typeof body.key === 'string' ? body.key : '', CONF.updateKey)) {
    send(res, 403, { ok: false, code: 'bad_key', message: 'Update key missing or wrong.' });
    return null;
  }
  const cfg = await config();
  const allowed = (cfg.allowed_sites || []).map(hostOf).filter(Boolean);
  if (allowed.length && !allowed.includes(host)) {
    send(res, 403, { ok: false, code: 'not_allowed', message: 'This site is not on OSAM’s update list.' });
    return null;
  }
  const known = Object.keys((S.sites[host] && S.sites[host].plugins) || {});
  if (!known.includes(slug) && known.length >= LIMIT.slugsPerSite) {
    send(res, 400, { ok: false, code: 'bad_request', message: 'Too many plugins for one site.' });
    return null;
  }
  const w = kind === 'report' ? limited(`report:${host}:${slug}`, LIMIT.reportsPerHour, 3600000) : limited(`event:${host}`, LIMIT.eventsPerHour, 3600000);
  if (w) {
    send(res, 429, { ok: false, code: 'rate_limited', message: 'Too many reports from this site.' }, { 'Retry-After': String(w) });
    return null;
  }
  return { host, slug, version, ip, cfg };
}

async function receiveReport(req, res) {
  const body = await readSiteJson(req, res);
  if (!body) return;
  const s = await siteFromBody(req, res, body, 'report');
  if (!s) return;
  if (!body.report || typeof body.report !== 'object' || Array.isArray(body.report)) return send(res, 400, { ok: false, code: 'bad_request', message: 'report must be an object.' });
  const report = clean(body.report);
  if (Buffer.byteLength(JSON.stringify(report)) > LIMIT.report) return send(res, 413, { ok: false, code: 'too_large', message: 'Report too large.' });
  const env = report.env && typeof report.env === 'object' ? report.env : {};
  noteSite(s.host, s.slug, s.version, {
    channel: channelFor(s.cfg, s.host),
    wp: typeof env.wp === 'string' ? env.wp.slice(0, 40) : undefined,
    php: typeof env.php === 'string' ? env.php.slice(0, 40) : undefined,
  });
  const at = new Date().toISOString();
  S.sites[s.host].plugins[s.slug].last_report = at;
  S.reports[s.host] = S.reports[s.host] || {};
  S.reports[s.host][s.slug] = { received_at: at, version: s.version, ip: s.ip, schema: Number(body.schema) || 0, report };
  const h = historyOf(s.host);
  const day = dailySummary(s.slug, s.version, report);
  h.daily = h.daily.filter((d) => !(d.slug === s.slug && d.date === day.date));
  h.daily.push(day);
  const mine = h.daily.filter((d) => d.slug === s.slug);
  if (mine.length > LIMIT.daily) {
    const dropDates = new Set(mine.slice(0, mine.length - LIMIT.daily).map((d) => d.date));
    h.daily = h.daily.filter((d) => d.slug !== s.slug || !dropDates.has(d.date));
  }
  dirty('reports', 'history', 'sites');
  return send(res, 202, { ok: true });
}

async function receiveEvent(req, res) {
  const body = await readSiteJson(req, res);
  if (!body) return;
  const s = await siteFromBody(req, res, body, 'event');
  if (!s) return;
  const e = body.event;
  if (!e || typeof e !== 'object' || e.type !== 'update') return send(res, 400, { ok: false, code: 'bad_request', message: 'event.type must be "update".' });
  const v = (x) => (validVersion(x) ? String(x) : '');
  const ev = {
    at: new Date().toISOString(),
    slug: s.slug,
    from: v(e.from),
    to: v(e.to),
    ok: e.ok === true,
    forced: e.forced === true,
    error: e.ok === true ? '' : clean(String(e.error || '')).slice(0, 300),
    site_time: Number.isFinite(e.time) ? Math.floor(e.time) : null,
  };
  const h = historyOf(s.host);
  const dup = h.updates.find((u) => u.slug === ev.slug && u.from === ev.from && u.to === ev.to && u.ok === ev.ok && Date.now() - Date.parse(u.at) < 10 * 60000);
  if (dup) return send(res, 200, { ok: true, duplicate: true });
  h.updates.push(ev);
  if (h.updates.length > LIMIT.updates) h.updates.splice(0, h.updates.length - LIMIT.updates);
  if (!S.sites[s.host]) noteSite(s.host, s.slug, s.version, { channel: channelFor(s.cfg, s.host) });
  if (ev.ok && ev.to) noteVersion(s.host, s.slug, ev.to, ev.at);
  dirty('history');
  return send(res, 202, { ok: true });
}

function countDownload(slug, version, host) {
  const key = `${slug}@${version}`;
  const d = (S.downloads[key] = S.downloads[key] || { count: 0, last_at: null, hosts: [] });
  d.count++;
  d.last_at = new Date().toISOString();
  if (!d.hosts.includes(host) && d.hosts.length < 2000) d.hosts.push(host);
  if (S.sites[host] || S.history[host]) {
    const h = historyOf(host);
    h.downloads.push({ at: d.last_at, slug, version });
    if (h.downloads.length > LIMIT.downloads) h.downloads.splice(0, h.downloads.length - LIMIT.downloads);
    dirty('history');
  }
  dirty('downloads');
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
  const siteCfg = siteConfig(cfg, host);
  const channel = channelFor(cfg, host);

  // Note the site for /v1/status and the dashboard.
  noteSite(host, slug, validVersion(installed) ? installed : String(installed).slice(0, 40), {
    wp: String(q.get('wp') || '').slice(0, 40),
    php: String(q.get('php') || '').slice(0, 40),
    channel,
  });

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
  // 2. A pinned version for this site: "pin": "2.16.0" (OSAM Bookings) or "pin": { "osam-turnstile": "1.3.0" }.
  const pin = siteCfg.pin && typeof siteCfg.pin === 'object' ? siteCfg.pin[slug] : slug === 'osam-bookings' ? siteCfg.pin : '';
  if (!chosen && pin) {
    chosen = all.find((m) => m.version === String(pin) && !blocked.has(m.version)) || null;
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

/* ------------------------------------------------------------------ dashboard: data */

const DAY = 86400000;

function latestOf(slug) {
  const blockedAll = new Set(S.rollbacks.map((r) => r.decoded).filter((p) => p && p.slug === slug && p.sites.includes('*')).flatMap((p) => p.block_versions));
  const all = [...S.releases.values()].filter((m) => m.slug === slug && !blockedAll.has(m.version)).sort((a, b) => cmpVersion(b.version, a.version));
  const stable = (all.find((m) => !m.prerelease) || {}).version || '';
  const beta = (all.find((m) => m.prerelease) || {}).version || '';
  return { stable, beta: beta && (!stable || cmpVersion(beta, stable) > 0) ? beta : stable };
}

function allSlugs() {
  const s = new Set([...S.releases.values()].map((m) => m.slug));
  for (const site of Object.values(S.sites)) for (const k of Object.keys(site.plugins || {})) s.add(k);
  return [...s].filter(validSlug).sort((a, b) => (a === 'osam-bookings' ? -1 : b === 'osam-bookings' ? 1 : a.localeCompare(b)));
}

function isLiveSite(host, cfg, report) {
  const sc = siteConfig(cfg, host);
  if (sc.live === false || sc.environment === 'staging') return false;
  if (sc.live === true) return true;
  const envType = report && report.env && report.env.environment;
  if (['staging', 'development', 'local'].includes(envType)) return false;
  return !/(^|[.-])(staging|stage|dev|test|demo|local|localhost)([.-]|$)|\.(test|local|localhost|invalid|example)$/.test(host);
}

const num = (x) => (typeof x === 'number' && Number.isFinite(x) ? x : Number(x) || 0);
const g = (o, ...keys) => keys.reduce((a, k) => (a && typeof a === 'object' ? a[k] : undefined), o);

// Health of one site: level ok | warn | bad | quiet, and the reasons.
function siteHealth(host, cfg) {
  const issues = [];
  const add = (level, msg) => issues.push({ level, msg });
  const site = S.sites[host] || {};
  const seen = Date.parse(site.last_seen || '') || 0;
  const quiet = Date.now() - seen > 7 * DAY;
  const reps = S.reports[host] || {};
  const failed = new Set();
  for (const [slug, entry] of Object.entries(reps)) {
    const r = (entry && entry.report) || {};
    const tag = slug === 'osam-bookings' ? '' : ` (${slug})`;
    const live = isLiveSite(host, cfg, r);
    const errs = num(g(r, 'problems', 'day', 'errors'));
    if (errs > 0) add('bad', `${errs} error${errs === 1 ? '' : 's'} in the last 24h${tag}`);
    const cron = g(r, 'cron', 'state');
    if (cron === 'late' || cron === 'never') add('bad', cron === 'never' ? 'Scheduled jobs have never run' : 'Scheduled jobs are late');
    const lf = g(r, 'updates', 'last_forced');
    if (lf && lf.ok === false && lf.to && lf.to !== entry.version) {
      add('bad', `Required update to ${lf.to} failed${tag}`);
      failed.add(slug);
    }
    if (g(r, 'updates', 'last_check', 'error')) add('warn', `Update check reported an error${tag}`);
    const sub = r.subscription;
    if (sub && sub.linked === true) {
      if (sub.status === 'suspended' || sub.status === 'terminated') add('bad', `Subscription ${sub.status}`);
      else if (sub.status === 'overdue') add('warn', 'Subscription overdue');
    }
    const stripe = g(r, 'payments', 'stripe');
    if (stripe && typeof stripe === 'object') {
      if (stripe.mode === 'test' && live) add('warn', 'Stripe in test mode on a live site');
      if (stripe.webhook === false) add('bad', 'Stripe webhook not set up');
    }
    const calErr = num(g(r, 'calendar', 'errors'));
    if (calErr > 0) add('warn', `${calErr} calendar connection${calErr === 1 ? '' : 's'} in error`);
    if (g(r, 'env', 'wp_debug') === true && live) add('warn', 'WP_DEBUG is on');
    const age = Date.now() - (Date.parse(entry.received_at || '') || 0);
    if (!quiet && age > 3 * DAY) add('warn', `No report for ${Math.floor(age / DAY)} days${tag}`);
  }
  const ups = (S.history[host] && S.history[host].updates) || [];
  for (const slug of new Set(ups.map((u) => u.slug))) {
    const last = ups.filter((u) => u.slug === slug).pop();
    if (last && !last.ok && !failed.has(slug)) add('bad', `Update ${last.from || '?'} → ${last.to || '?'} failed${slug === 'osam-bookings' ? '' : ` (${slug})`}`);
  }
  if (quiet) add('quiet', seen ? `Not seen for ${Math.floor((Date.now() - seen) / DAY)} days` : 'Never seen');
  const level = quiet ? 'quiet' : issues.some((i) => i.level === 'bad') ? 'bad' : issues.length ? 'warn' : 'ok';
  return { level, issues };
}

function pinFor(cfg, host, slug) {
  const pin = siteConfig(cfg, host).pin;
  return String((pin && typeof pin === 'object' ? pin[slug] : slug === 'osam-bookings' ? pin : '') || '');
}

function siteRows(cfg) {
  const rows = [];
  const latest = Object.fromEntries(allSlugs().map((s) => [s, latestOf(s)]));
  for (const [host, site] of Object.entries(S.sites)) {
    const health = siteHealth(host, cfg);
    const channel = channelFor(cfg, host);
    for (const [slug, p] of Object.entries(site.plugins || (site.slug ? { [site.slug]: { version: site.version, last_seen: site.last_seen } } : {}))) {
      const rep = (S.reports[host] || {})[slug];
      const l = latest[slug] || { stable: '', beta: '' };
      rows.push({
        host,
        slug,
        version: String(p.version || ''),
        channel,
        wp: String(site.wp || ''),
        php: String(site.php || ''),
        last_seen: p.last_seen || site.last_seen || null,
        last_report: rep ? rep.received_at : null,
        title: String(g(S.reports[host], 'osam-bookings', 'report', 'env', 'title') || g(rep, 'report', 'env', 'title') || ''),
        health: health.level,
        issues: health.issues.map((i) => i.msg),
        pin: pinFor(cfg, host, slug),
        up_to_date: !!p.version && (p.version === l[channel] || p.version === pinFor(cfg, host, slug)),
      });
    }
  }
  return rows.sort((a, b) => a.host.localeCompare(b.host) || a.slug.localeCompare(b.slug));
}

async function overviewData() {
  const cfg = await config();
  const now = Date.now();
  const hosts = Object.keys(S.sites);
  const seenAgo = (h) => now - (Date.parse(S.sites[h].last_seen || '') || 0);
  const plugins = {};
  for (const slug of allSlugs()) {
    const l = latestOf(slug);
    const counts = {};
    for (const s of Object.values(S.sites)) {
      const p = (s.plugins || {})[slug];
      if (p && p.version) counts[p.version] = (counts[p.version] || 0) + 1;
    }
    const versions = Object.entries(counts).map(([version, sites]) => ({ version, sites })).sort((a, b) => cmpVersion(b.version, a.version));
    plugins[slug] = {
      latest_stable: l.stable,
      latest_beta: l.beta,
      total: versions.reduce((a, v) => a + v.sites, 0),
      on_latest_stable: counts[l.stable] || 0,
      on_latest_beta: l.beta && l.beta !== l.stable ? counts[l.beta] || 0 : 0,
      versions,
    };
  }
  const channels = { stable: 0, beta: 0 };
  for (const h of hosts) channels[channelFor(cfg, h)]++;
  const problems = hosts.map((host) => ({ host, ...siteHealth(host, cfg) })).filter((x) => x.level === 'bad' || x.level === 'warn').sort((a, b) => (a.level === b.level ? a.host.localeCompare(b.host) : a.level === 'bad' ? -1 : 1));
  let reports24 = 0;
  for (const r of Object.values(S.reports)) for (const e of Object.values(r)) if (now - (Date.parse(e.received_at || '') || 0) < DAY) reports24++;
  const releases = [...S.releases.values()].sort((a, b) => (Date.parse(b.published_at || '') || 0) - (Date.parse(a.published_at || '') || 0)).slice(0, 8).map((m) => ({ slug: m.slug, version: m.version, channel: m.prerelease ? 'beta' : 'stable', published_at: m.published_at || null }));
  return {
    server: VERSION,
    sites: { total: hosts.length, seen_24h: hosts.filter((h) => seenAgo(h) < DAY).length, seen_7d: hosts.filter((h) => seenAgo(h) < 7 * DAY).length, quiet: hosts.filter((h) => seenAgo(h) >= 7 * DAY).length },
    reports_24h: reports24,
    plugins,
    channels,
    problems,
    last_sync: S.lastSync,
    releases,
  };
}

function releasesData() {
  const out = {};
  const running = {};
  for (const s of Object.values(S.sites)) for (const [slug, p] of Object.entries(s.plugins || {})) running[`${slug}@${p.version}`] = (running[`${slug}@${p.version}`] || 0) + 1;
  const blocked = new Set(S.rollbacks.map((r) => r.decoded).filter(Boolean).flatMap((p) => p.block_versions.map((v) => `${p.slug}@${v}`)));
  for (const m of [...S.releases.values()].sort((a, b) => cmpVersion(b.version, a.version))) {
    const key = `${m.slug}@${m.version}`;
    const d = S.downloads[key] || {};
    (out[m.slug] = out[m.slug] || []).push({
      version: m.version,
      channel: m.prerelease ? 'beta' : 'stable',
      published_at: m.published_at || null,
      sites: running[key] || 0,
      downloads: d.count || 0,
      download_sites: Array.isArray(d.hosts) ? d.hosts.length : 0,
      last_download: d.last_at || null,
      size: m.size || 0,
      sha256: m.sha256,
      github_url: m.github_url || '',
      withdrawn: blocked.has(key),
    });
  }
  const rollbacks = S.rollbacks.map((r) => ({ ...r.decoded, received_at: r.received_at || null }));
  return { releases: out, rollbacks };
}

async function siteData(host) {
  if (!validHost(host) || (!S.sites[host] && !S.reports[host] && !S.history[host])) return null;
  const cfg = await config();
  const latest = Object.fromEntries(allSlugs().map((s) => [s, latestOf(s)]));
  return {
    host,
    site: S.sites[host] || {},
    channel: channelFor(cfg, host),
    live: isLiveSite(host, cfg, g(S.reports[host], 'osam-bookings', 'report')),
    health: siteHealth(host, cfg),
    config: siteConfig(cfg, host),
    latest,
    reports: S.reports[host] || {},
    history: S.history[host] || { versions: {}, updates: [], daily: [], downloads: [] },
  };
}

async function dashboardJson(name, url) {
  if (name === 'overview') return overviewData();
  if (name === 'sites') return { sites: siteRows(await config()) };
  if (name === 'releases') return releasesData();
  if (name === 'config') return { config: await config() };
  const m = name.match(/^sites\/([^/]+)$/);
  if (m) {
    let host = '';
    try {
      host = decodeURIComponent(m[1]).toLowerCase();
    } catch (e) {
      return null;
    }
    return siteData(host);
  }
  return null;
}

/* ------------------------------------------------------------------ dashboard: login sessions */

const SESSION_HOURS = 12;
const LOCK = { tries: 5, minutes: 15 };
const revoked = new Map(); // session nonce -> expiry (ms)
const lockouts = new Map(); // ip -> { count, first, until }

// Changing ADMIN_TOKEN (or the data folder's .link-secret) logs every session out.
const sessKey = () => crypto.createHmac('sha256', S.linkSecret).update(`dashboard-session|${CONF.adminToken}`).digest();
const b64u = (b) => Buffer.from(b).toString('base64url');

function makeSession() {
  const payload = b64u(JSON.stringify({ n: crypto.randomBytes(16).toString('hex'), exp: Date.now() + SESSION_HOURS * 3600000 }));
  return `${payload}.${b64u(crypto.createHmac('sha256', sessKey()).update(payload).digest())}`;
}

function cookies(req) {
  const out = {};
  for (const part of String(req.headers.cookie || '').split(';')) {
    const i = part.indexOf('=');
    if (i > 0) out[part.slice(0, i).trim()] = part.slice(i + 1).trim();
  }
  return out;
}

function readSession(req) {
  if (!S.linkSecret || !CONF.adminToken || CONF.adminToken.length < 24) return null;
  const c = cookies(req).osam_dash || '';
  const [payload, sig] = c.split('.');
  if (!payload || !sig || !safeEqual(sig, b64u(crypto.createHmac('sha256', sessKey()).update(payload).digest()))) return null;
  let s;
  try {
    s = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8'));
  } catch (e) {
    return null;
  }
  if (!s || typeof s.n !== 'string' || !(s.exp > Date.now()) || revoked.has(s.n)) return null;
  return s;
}

const csrfFor = (sess) => crypto.createHmac('sha256', sessKey()).update(`csrf|${sess.n}`).digest('hex');

const COOKIE = (name, value, maxAge) => `${name}=${value}; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=${maxAge}`;

setInterval(() => {
  const now = Date.now();
  for (const [n, exp] of revoked) if (exp < now) revoked.delete(n);
  for (const [ip, l] of lockouts) if (l.until < now && now - l.first > LOCK.minutes * 60000) lockouts.delete(ip);
}, 10 * 60000).unref();

async function readForm(req) {
  const raw = (await readBody(req, 8 * 1024)).toString('utf8');
  return Object.fromEntries(new URLSearchParams(raw));
}

/* ------------------------------------------------------------------ dashboard: HTML */

class Raw {
  constructor(s) {
    this.s = s;
  }
}
const raw = (s) => new Raw(String(s));
const render = (v) => (v instanceof Raw ? v.s : Array.isArray(v) ? v.map(render).join('') : v === null || v === undefined || v === false ? '' : esc(v));
// Template tag: every ${value} is HTML-escaped unless it is already-built HTML (Raw).
function h(strings, ...vals) {
  let out = strings[0];
  vals.forEach((v, i) => {
    out += render(v) + strings[i + 1];
  });
  return raw(out);
}

function fmtDate(t) {
  const d = typeof t === 'number' ? new Date(t) : new Date(Date.parse(t || ''));
  return Number.isNaN(d.getTime()) || d.getTime() <= 0 ? '' : d.toISOString().replace('T', ' ').slice(0, 16) + ' UTC';
}
function ago(t) {
  const ms = typeof t === 'number' ? t : Date.parse(t || '');
  if (!ms) return 'never';
  const s = Math.round((Date.now() - ms) / 1000);
  const abs = Math.abs(s);
  const txt = abs < 90 ? `${abs}s` : abs < 5400 ? `${Math.round(abs / 60)}m` : abs < 129600 ? `${Math.round(abs / 3600)}h` : `${Math.round(abs / 86400)}d`;
  return s >= 0 ? `${txt} ago` : `in ${txt}`;
}
const when = (t) => (t ? h`<time title="${fmtDate(t)}">${ago(t)}</time>` : h`<span class="muted">never</span>`);
const fmtSize = (n) => (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.round((n || 0) / 1024)} KB`);
const BADGE = { ok: 'OK', warn: 'Check', bad: 'Problem', quiet: 'Quiet' };
const badge = (level, text) => h`<span class="b b-${['ok', 'warn', 'bad', 'quiet', 'off', 'beta', 'stable'].includes(level) ? level : 'off'}">${text || BADGE[level] || level}</span>`;
const PLUGIN_NAMES = { 'osam-bookings': 'OSAM Bookings', 'osam-turnstile': 'OSAM Turnstile' };
const pname = (slug) => PLUGIN_NAMES[slug] || slug;
const safeHref = (u) => (/^https?:\/\/[^\s"'<>]+$/i.test(String(u || '')) ? String(u) : '');

const CSS = `
:root{--bg:#f6f7f9;--card:#fff;--fg:#1d2327;--muted:#646970;--line:#dcdfe4;--accent:#2563eb;--ok:#137333;--okbg:#e6f4ea;--warn:#8a5a00;--warnbg:#fef3c7;--bad:#b42318;--badbg:#fde8e7;--qbg:#eceef1}
@media (prefers-color-scheme:dark){:root{--bg:#111418;--card:#1a1f25;--fg:#e6e8eb;--muted:#9aa3ad;--line:#2c333b;--accent:#6ea0ff;--ok:#7fd49a;--okbg:#17321f;--warn:#f5c56b;--warnbg:#3a2c0b;--bad:#ff9b92;--badbg:#3d1614;--qbg:#262c33}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}
header{display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;padding:10px 16px;background:var(--card);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:2}
.brand{font-weight:700}nav{display:flex;flex-wrap:wrap;gap:4px;flex:1}nav a{padding:6px 10px;border-radius:6px;color:var(--fg)}nav a.on{background:var(--qbg);font-weight:600}
main{max-width:1200px;margin:0 auto;padding:16px}h1{font-size:22px;margin:4px 0 14px}h2{font-size:17px;margin:22px 0 10px}h3{font-size:15px;margin:0 0 8px}
.grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(240px,1fr))}.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px;min-width:0;overflow-x:auto}
.stat{font-size:28px;font-weight:700}.muted{color:var(--muted)}.small{font-size:13px}
.tw{overflow-x:auto;background:var(--card);border:1px solid var(--line);border-radius:10px}
table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:7px 10px;border-bottom:1px solid var(--line);vertical-align:top}tr:last-child td{border-bottom:0}
th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);white-space:nowrap}th[data-k]{cursor:pointer;user-select:none}th[data-k]:after{content:" \\2195";opacity:.4}
table.kv th{text-transform:none;letter-spacing:0;font-size:13px;width:1%;white-space:nowrap}table.kv td{word-break:break-word}table.kv table.kv{margin:0}table.kv table.kv th,table.kv table.kv td{padding:3px 6px}
.b{display:inline-block;padding:1px 8px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap;background:var(--qbg);color:var(--muted)}
.b-ok{background:var(--okbg);color:var(--ok)}.b-warn{background:var(--warnbg);color:var(--warn)}.b-bad{background:var(--badbg);color:var(--bad)}.b-beta{background:var(--warnbg);color:var(--warn)}.b-stable{background:var(--okbg);color:var(--ok)}
ul.issues{margin:4px 0 0;padding-left:18px}.flash{padding:10px 14px;border-radius:8px;background:var(--okbg);color:var(--ok);margin-bottom:12px}.flash.err{background:var(--badbg);color:var(--bad)}
form.inline{display:inline}button,.btn{font:inherit;padding:7px 14px;border-radius:7px;border:1px solid var(--line);background:var(--card);color:var(--fg);cursor:pointer}button.primary{background:var(--accent);border-color:var(--accent);color:#fff}
input,select{font:inherit;padding:7px 9px;border-radius:7px;border:1px solid var(--line);background:var(--card);color:var(--fg);max-width:100%}
.filters{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;align-items:center}.filters input[type=search]{flex:1;min-width:180px}
pre{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px;overflow-x:auto;font-size:13px;margin:0}
progress{width:100%;height:8px}.login{max-width:380px;margin:12vh auto;padding:0 16px}.login input{width:100%;margin:8px 0 12px}
footer{max-width:1200px;margin:0 auto;padding:8px 16px 24px;color:var(--muted);font-size:12px}
@media (max-width:640px){header{position:static}nav{order:3;flex-basis:100%}header form.inline{margin-left:auto}main{padding:12px}th,td{padding:6px 7px}.hide-s{display:none}}
`;

function securityHeaders(nonce) {
  return {
    'Cache-Control': 'no-store, max-age=0',
    Pragma: 'no-cache',
    'X-Content-Type-Options': 'nosniff',
    'X-Frame-Options': 'DENY',
    'Referrer-Policy': 'no-referrer',
    'Content-Security-Policy': `default-src 'none'; style-src 'nonce-${nonce}'; script-src 'nonce-${nonce}'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'`,
    'Cross-Origin-Opener-Policy': 'same-origin',
    'Cross-Origin-Resource-Policy': 'same-origin',
    'Permissions-Policy': 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
    'Strict-Transport-Security': 'max-age=31536000',
    'X-Robots-Tag': 'noindex, nofollow',
  };
}

function sendHtml(res, code, ctx, title, body, headers = {}) {
  const nav = ctx.sess
    ? h`<nav>${[
        ['', 'Overview'],
        ['/sites', 'Sites'],
        ['/releases', 'Releases'],
        ['/config', 'Config'],
      ].map(([p, t]) => h`<a href="${ctx.base}${p}" class="${ctx.active === t ? 'on' : ''}">${t}</a>`)}</nav>
      <form method="post" action="${ctx.base}/logout" class="inline"><input type="hidden" name="csrf" value="${ctx.csrf}"><button type="submit">Log out</button></form>`
    : '';
  const page = h`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
<title>${title} · OSAM updates</title><style nonce="${ctx.nonce}">${raw(CSS)}</style></head><body>
${ctx.sess ? h`<header><div class="brand">OSAM updates</div>${nav}</header>` : ''}
<main>${body}</main><footer>osam-update-server ${VERSION}</footer></body></html>`;
  res.writeHead(code, { 'Content-Type': 'text/html; charset=utf-8', ...securityHeaders(ctx.nonce), ...headers });
  res.end(page.s);
}

function redirect(res, location, headers = {}) {
  res.writeHead(303, { Location: location, 'Cache-Control': 'no-store', 'Content-Length': 0, ...headers });
  res.end();
}

const FLASH = {
  sync_ok: ['GitHub sync finished.', ''],
  sync_failed: ['GitHub sync failed — see “Last GitHub sync”.', 'err'],
  sync_running: ['The sync is still running. Refresh in a minute.', ''],
  logged_out: ['You have been logged out.', ''],
};
const flash = (url) => {
  const f = FLASH[url.searchParams.get('msg')];
  return f ? h`<div class="flash ${f[1]}">${f[0]}</div>` : '';
};

// Any value a site sent, as HTML (escaped). Unix times are shown as dates.
const TIME_KEYS = new Set(['generated', 'last_run', 'time', 'checked', 'last', 'site_time']);
function renderVal(v, key) {
  if (v === null || v === undefined) return h`<span class="muted">—</span>`;
  if (typeof v === 'boolean') return v ? badge('ok', 'yes') : badge('off', 'no');
  if (typeof v === 'number' && TIME_KEYS.has(key) && v > 1e9) return h`${fmtDate(v * 1000)} <span class="muted">(${ago(v * 1000)})</span>`;
  if (typeof v === 'number' && key === 'gap') return h`${Math.round(v / 60)} min`;
  if (typeof v !== 'object') return h`${String(v)}`;
  if (Array.isArray(v)) {
    if (!v.length) return h`<span class="muted">none</span>`;
    if (v.every((x) => x === null || typeof x !== 'object')) return h`${v.map(String).join(', ')}`;
    return h`${v.map((x) => h`<div>${renderVal(x)}</div>`)}`;
  }
  const entries = Object.entries(v);
  if (!entries.length) return h`<span class="muted">none</span>`;
  return h`<table class="kv">${entries.map(([k, x]) => h`<tr><th>${k}</th><td>${renderVal(x, k)}</td></tr>`)}</table>`;
}

function loginPage(res, ctx, code, message, headers = {}, info = '') {
  const pre = crypto.randomBytes(16).toString('hex');
  sendHtml(
    res,
    code,
    ctx,
    'Log in',
    h`<div class="login card"><h1>OSAM update dashboard</h1>${info ? h`<div class="flash">${info}</div>` : ''}${message ? h`<div class="flash err">${message}</div>` : ''}
<form method="post" action="${ctx.base}/login"><input type="hidden" name="pre" value="${pre}">
<label for="token">Admin token</label><input id="token" name="token" type="password" autocomplete="off" required autofocus>
<button type="submit" class="primary">Log in</button></form>
<p class="muted small">The ADMIN_TOKEN from Setup Node.js App. Five wrong tries lock this address out for ${LOCK.minutes} minutes.</p></div>`,
    { 'Set-Cookie': COOKIE('osam_pre', pre, 3600), ...headers }
  );
}

async function overviewPage(res, ctx, url) {
  const o = await overviewData();
  const ls = o.last_sync;
  const pluginCards = Object.entries(o.plugins).map(
    ([slug, p]) => h`<div class="card"><h3>${pname(slug)}</h3>
<div class="small muted">Latest stable <b>${p.latest_stable || '—'}</b>${p.latest_beta && p.latest_beta !== p.latest_stable ? h` · beta <b>${p.latest_beta}</b>` : ''}</div>
<p><span class="stat">${p.on_latest_stable}</span> <span class="muted">of ${p.total} on latest stable</span>${p.on_latest_beta ? h` · ${p.on_latest_beta} on beta` : ''}</p>
<progress max="${p.total || 1}" value="${p.on_latest_stable}"></progress>
<table class="kv">${p.versions.map((v) => h`<tr><th><a href="${ctx.base}/sites?plugin=${encodeURIComponent(slug)}&amp;q=${encodeURIComponent(v.version)}">${v.version}</a> ${v.version === p.latest_stable ? badge('stable', 'latest') : v.version === p.latest_beta ? badge('beta', 'beta') : ''}</th><td>${v.sites} site${v.sites === 1 ? '' : 's'}</td></tr>`)}</table></div>`
  );
  sendHtml(
    res,
    200,
    { ...ctx, active: 'Overview' },
    'Overview',
    h`${flash(url)}<h1>Overview</h1>
<div class="grid">
<div class="card"><h3>Sites</h3><div class="stat">${o.sites.total}</div><div class="small">${o.sites.seen_24h} seen in 24h · ${o.sites.seen_7d} in 7 days · <a href="${ctx.base}/sites?health=quiet">${o.sites.quiet} quiet &gt; 7 days</a></div><div class="small muted">${o.reports_24h} status reports in the last 24h</div></div>
<div class="card"><h3>Channels</h3><table class="kv"><tr><th>stable</th><td>${o.channels.stable}</td></tr><tr><th>beta</th><td>${o.channels.beta}</td></tr></table></div>
<div class="card"><h3>Last GitHub sync</h3>${ls ? h`<div>${badge(ls.ok ? 'ok' : 'bad', ls.ok ? 'OK' : 'Failed')} ${when(ls.at)}</div>${ls.message ? h`<div class="small">${ls.message}</div>` : ''}${ls.added && ls.added.length ? h`<div class="small">Added: ${ls.added.join(', ')}</div>` : ''}${ls.skipped && ls.skipped.length ? h`<div class="small muted">Skipped: ${ls.skipped.join('; ')}</div>` : ''}` : h`<div class="muted">Not synced yet</div>`}
<form method="post" action="${ctx.base}/sync"><input type="hidden" name="csrf" value="${ctx.csrf}"><p><button type="submit" class="primary">Sync now</button></p></form></div>
</div>
<h2>Versions in use</h2><div class="grid">${pluginCards.length ? pluginCards : h`<div class="card muted">No sites yet.</div>`}</div>
<h2>Sites with problems (${o.problems.length})</h2>
${o.problems.length ? h`<div class="tw"><table><thead><tr><th>Site</th><th>Health</th><th>Why</th></tr></thead><tbody>${o.problems.map((p) => h`<tr><td><a href="${ctx.base}/site/${encodeURIComponent(p.host)}">${p.host}</a></td><td>${badge(p.level)}</td><td>${p.issues.map((i) => i.msg).join(' · ')}</td></tr>`)}</tbody></table></div>` : h`<div class="card">${badge('ok', 'All clear')} No site reports a problem.</div>`}
<h2>Latest releases</h2><div class="tw"><table><thead><tr><th>Plugin</th><th>Version</th><th>Channel</th><th>Published</th></tr></thead><tbody>${o.releases.map((r) => h`<tr><td>${pname(r.slug)}</td><td>${r.version}</td><td>${badge(r.channel, r.channel)}</td><td>${when(r.published_at)}</td></tr>`)}</tbody></table></div>`
  );
}

async function sitesPage(res, ctx, url) {
  const cfg = await config();
  const rows = siteRows(cfg);
  const f = { q: String(url.searchParams.get('q') || '').slice(0, 100), plugin: String(url.searchParams.get('plugin') || ''), health: String(url.searchParams.get('health') || ''), channel: String(url.searchParams.get('channel') || '') };
  const slugs = allSlugs();
  const sel = (name, label, opts) => h`<select name="${name}" id="f-${name}" aria-label="${label}"><option value="">${label}: all</option>${opts.map(([v, t]) => h`<option value="${v}"${f[name] === v ? raw(' selected') : ''}>${t}</option>`)}</select>`;
  const search = (r) => `${r.host} ${r.title} ${r.slug} ${r.version} ${r.wp} ${r.php} ${r.issues.join(' ')}`.toLowerCase();
  // Without JavaScript the same filters are applied here.
  const shown = rows.filter((r) => (!f.q || search(r).includes(f.q.toLowerCase())) && (!f.plugin || r.slug === f.plugin) && (!f.health || r.health === f.health) && (!f.channel || r.channel === f.channel));
  const js = `(function(){var t=document.getElementById('sites');if(!t)return;var b=t.tBodies[0],rows=[].slice.call(b.rows),q=document.getElementById('f-q'),c=document.getElementById('count');
var fs=['plugin','health','channel'].map(function(n){return document.getElementById('f-'+n)});
function apply(){var s=q.value.toLowerCase(),n=0;rows.forEach(function(r){var ok=(!s||r.getAttribute('data-search').indexOf(s)>-1);fs.forEach(function(f){if(f.value&&r.getAttribute('data-'+f.name)!==f.value)ok=false});r.hidden=!ok;if(ok)n++});c.textContent=n;}
document.getElementById('filters').addEventListener('submit',function(e){e.preventDefault();apply()});q.addEventListener('input',apply);fs.forEach(function(f){f.addEventListener('change',apply)});
var dir={};[].forEach.call(t.tHead.rows[0].cells,function(th,i){if(!th.getAttribute('data-k'))return;th.addEventListener('click',function(){dir[i]=-(dir[i]||-1);rows.sort(function(a,b){var x=a.cells[i].getAttribute('data-s')||a.cells[i].textContent,y=b.cells[i].getAttribute('data-s')||b.cells[i].textContent;return dir[i]*x.localeCompare(y,undefined,{numeric:true,sensitivity:'base'})});rows.forEach(function(r){b.appendChild(r)})})});
rows.forEach(function(r){r.hidden=false});apply();})();`;
  sendHtml(
    res,
    200,
    { ...ctx, active: 'Sites' },
    'Sites',
    h`<h1>Sites <span class="muted small"><span id="count">${shown.length}</span> of ${rows.length} rows</span></h1>
<form class="filters" id="filters" method="get" action="${ctx.base}/sites"><input type="search" name="q" id="f-q" placeholder="Search site, title, version, problem…" value="${f.q}">
${sel('plugin', 'Plugin', slugs.map((s) => [s, pname(s)]))}${sel('health', 'Health', ['ok', 'warn', 'bad', 'quiet'].map((s) => [s, BADGE[s]]))}${sel('channel', 'Channel', [['stable', 'stable'], ['beta', 'beta']])}<button type="submit">Filter</button></form>
<div class="tw"><table id="sites"><thead><tr><th data-k="1">Site</th><th data-k="1">Plugin</th><th data-k="1">Version</th><th data-k="1">Channel</th><th data-k="1" class="hide-s">WP</th><th data-k="1" class="hide-s">PHP</th><th data-k="1">Last seen</th><th data-k="1">Health</th></tr></thead><tbody>
${rows.map((r) => {
  const hide = !shown.includes(r);
  return h`<tr data-search="${search(r)}" data-plugin="${r.slug}" data-health="${r.health}" data-channel="${r.channel}"${hide ? raw(' hidden') : ''}>
<td data-s="${r.host}"><a href="${ctx.base}/site/${encodeURIComponent(r.host)}">${r.host}</a>${r.title ? h`<div class="small muted">${r.title}</div>` : ''}</td>
<td>${pname(r.slug)}</td><td data-s="${r.version}">${r.version} ${r.pin ? badge('off', 'pinned') : ''}${r.up_to_date ? '' : badge('warn', 'behind')}</td><td>${r.channel}</td><td class="hide-s">${r.wp}</td><td class="hide-s">${r.php}</td>
<td data-s="${r.last_seen || ''}">${when(r.last_seen)}</td><td data-s="${{ bad: 0, warn: 1, quiet: 2, ok: 3 }[r.health]}" title="${r.issues.join(' · ')}">${badge(r.health)}</td></tr>`;
})}</tbody></table></div>
<script nonce="${ctx.nonce}">${raw(js)}</script>`
  );
}

async function sitePage(res, ctx, host) {
  const d = await siteData(host);
  if (!d) return sendHtml(res, 404, ctx, 'Not found', h`<h1>Site not found</h1><p><a href="${ctx.base}/sites">Back to sites</a></p>`);
  const bookings = g(d.reports, 'osam-bookings', 'report') || {};
  const anyRep = Object.values(d.reports)[0];
  const title = g(bookings, 'env', 'title') || g(anyRep, 'report', 'env', 'title') || '';
  const home = safeHref(g(bookings, 'env', 'home') || g(anyRep, 'report', 'env', 'home'));
  const plugins = Object.entries(d.site.plugins || {});
  const pin = (slug) => (d.config.pin && typeof d.config.pin === 'object' ? d.config.pin[slug] : slug === 'osam-bookings' ? d.config.pin : '') || '';
  const h2 = d.history;
  const reportCards = Object.entries(d.reports).map(
    ([slug, e]) => h`<h2>${pname(slug)} — status report</h2><p class="small muted">Received ${when(e.received_at)} (${fmtDate(e.received_at)}) for version ${e.version} · from ${e.ip || '?'} · schema ${e.schema}</p>
<div class="grid">${Object.entries(e.report || {}).filter(([k]) => k !== 'generated').map(([k, v]) => h`<div class="card"><h3>${k}</h3>${renderVal(v, k)}</div>`)}</div>`
  );
  const timeline = Object.entries(h2.versions || {}).flatMap(([slug, list]) => (Array.isArray(list) ? list : []).map((v) => ({ slug, ...v }))).sort((a, b) => String(b.first_seen).localeCompare(String(a.first_seen)));
  sendHtml(
    res,
    200,
    { ...ctx, active: 'Sites' },
    host,
    h`<p class="small"><a href="${ctx.base}/sites">← Sites</a></p>
<h1>${host} ${badge(d.health.level)}</h1>
${title ? h`<p><b>${title}</b>${home ? h` · <a href="${home}" target="_blank" rel="noopener noreferrer">${home}</a>` : ''}</p>` : ''}
${d.health.issues.length ? h`<div class="card"><h3>Needs attention</h3><ul class="issues">${d.health.issues.map((i) => h`<li>${badge(i.level)} ${i.msg}</li>`)}</ul></div>` : ''}
<h2>Plugins</h2><div class="tw"><table><thead><tr><th>Plugin</th><th>Version</th><th>Latest for channel</th><th>Channel</th><th>Pin</th><th>Last update check</th><th>Last report</th></tr></thead><tbody>
${plugins.map(([slug, p]) => {
  const l = d.latest[slug] || {};
  return h`<tr><td>${pname(slug)}</td><td>${p.version}</td><td>${l[d.channel] || '—'}</td><td>${d.channel}</td><td>${pin(slug) || '—'}</td><td>${when(p.last_seen)}</td><td>${when(p.last_report)}</td></tr>`;
})}</tbody></table></div>
<p class="small muted">WordPress ${d.site.wp || '?'} · PHP ${d.site.php || '?'} · ${d.live ? 'treated as a live site' : 'treated as a staging/test site'}</p>
${reportCards.length ? reportCards : h`<h2>Status report</h2><div class="card muted">No status report yet (the site runs a version before 2.17, or has OSB_REPORT off).</div>`}
<h2>Version timeline</h2>${timeline.length ? h`<div class="tw"><table><thead><tr><th>First seen</th><th>Plugin</th><th>Version</th></tr></thead><tbody>${timeline.map((t) => h`<tr><td>${fmtDate(t.first_seen)}</td><td>${pname(t.slug)}</td><td>${t.version}</td></tr>`)}</tbody></table></div>` : h`<div class="card muted">Nothing yet.</div>`}
<h2>Update results</h2>${h2.updates && h2.updates.length ? h`<div class="tw"><table><thead><tr><th>When</th><th>Plugin</th><th>From → to</th><th>Result</th><th>Error</th></tr></thead><tbody>${h2.updates.slice().reverse().map((u) => h`<tr><td>${fmtDate(u.at)}</td><td>${pname(u.slug)}</td><td>${u.from || '?'} → ${u.to || '?'}${u.forced ? h` ${badge('warn', 'required')}` : ''}</td><td>${badge(u.ok ? 'ok' : 'bad', u.ok ? 'OK' : 'Failed')}</td><td>${u.error || ''}</td></tr>`)}</tbody></table></div>` : h`<div class="card muted">No update results reported yet.</div>`}
<h2>Daily summaries</h2>${h2.daily && h2.daily.length ? h`<div class="tw"><table><thead><tr><th>Date</th><th>Plugin</th><th>Version</th><th>WP</th><th>PHP</th><th>Cron</th><th>Errors 24h</th><th>Warnings 24h</th><th>Bookings 30d</th><th>Upcoming</th><th>Subscription</th></tr></thead><tbody>${h2.daily.slice().reverse().map((x) => h`<tr><td>${x.date}</td><td>${pname(x.slug)}</td><td>${x.version}</td><td>${x.wp}</td><td>${x.php}</td><td>${x.cron}</td><td>${x.errors_24h ?? ''}</td><td>${x.warnings_24h ?? ''}</td><td>${x.bookings_30d ?? ''}</td><td>${x.upcoming ?? ''}</td><td>${x.subscription}</td></tr>`)}</tbody></table></div>` : h`<div class="card muted">No daily reports yet.</div>`}
<h2>Downloads</h2>${h2.downloads && h2.downloads.length ? h`<div class="tw"><table><thead><tr><th>When</th><th>Plugin</th><th>Version</th></tr></thead><tbody>${h2.downloads.slice().reverse().map((x) => h`<tr><td>${fmtDate(x.at)}</td><td>${pname(x.slug)}</td><td>${x.version}</td></tr>`)}</tbody></table></div>` : h`<div class="card muted">No downloads recorded.</div>`}
<h2>config.json entry (read-only)</h2><pre>${Object.keys(d.config).length ? JSON.stringify(d.config, null, 2) : '(none — defaults apply)'}</pre>`
  );
}

async function releasesPage(res, ctx) {
  const d = releasesData();
  sendHtml(
    res,
    200,
    { ...ctx, active: 'Releases' },
    'Releases',
    h`<h1>Releases</h1>
${Object.keys(d.releases).length ? '' : h`<div class="card muted">No releases cached yet — run a sync from the overview.</div>`}
${Object.entries(d.releases).map(
  ([slug, list]) => h`<h2>${pname(slug)}</h2><div class="tw"><table><thead><tr><th>Version</th><th>Channel</th><th>Published</th><th>Sites running</th><th>Downloads</th><th class="hide-s">Size</th><th class="hide-s">SHA-256</th></tr></thead><tbody>
${list.map((r) => {
  const gh = safeHref(r.github_url);
  return h`<tr><td>${gh ? h`<a href="${gh}" target="_blank" rel="noopener noreferrer">${r.version}</a>` : r.version}${r.withdrawn ? h` ${badge('bad', 'withdrawn')}` : ''}</td><td>${badge(r.channel, r.channel)}</td><td>${fmtDate(r.published_at)}</td><td>${r.sites}</td><td>${r.downloads}${r.download_sites ? h` <span class="small muted">(${r.download_sites} sites)</span>` : ''}</td><td class="hide-s">${fmtSize(r.size)}</td><td class="hide-s small"><code>${String(r.sha256 || '').slice(0, 12)}…</code></td></tr>`;
})}</tbody></table></div>`
)}
<h2>Rollbacks</h2>${d.rollbacks.length ? h`<div class="tw"><table><thead><tr><th>Plugin</th><th>Back to</th><th>Withdrawn versions</th><th>Sites</th><th>Issued</th><th>Expires</th><th>Received</th></tr></thead><tbody>${d.rollbacks.map((r) => h`<tr><td>${pname(r.slug)}</td><td>${r.target_version}</td><td>${(r.block_versions || []).join(', ')}</td><td>${(r.sites || []).join(', ')}</td><td>${r.issued_at ? fmtDate(r.issued_at * 1000) : ''}</td><td>${r.expires_at ? h`${fmtDate(r.expires_at * 1000)}${r.expires_at * 1000 < Date.now() ? h` ${badge('off', 'expired')}` : ''}` : ''}</td><td>${fmtDate(r.received_at)}</td></tr>`)}</tbody></table></div>` : h`<div class="card muted">No rollbacks.</div>`}`
  );
}

async function configPage(res, ctx) {
  const cfg = await config();
  const files = [];
  for (const [name, file] of [['config.json', P.config()], ['sites.json', P.sites()], ['reports.json', P.reports()], ['history.json', P.history()], ['downloads.json', P.downloads()], ['rollbacks.json', P.rollbacks()], ['state.json', P.state()]]) {
    const st = await fsp.stat(file).catch(() => null);
    files.push([name, st ? fmtSize(st.size) : '—', st ? fmtDate(st.mtimeMs) : '']);
  }
  const vars = [['GITHUB_REPO', CONF.repo], ['GITHUB_TOKEN', CONF.token ? 'set' : ''], ['PUBLIC_KEY', CONF.publicKey ? 'set' : ''], ['ADMIN_TOKEN', CONF.adminToken ? 'set' : ''], ['PUBLIC_URL', CONF.publicUrl], ['WEBHOOK_SECRET', CONF.webhookSecret ? 'set' : ''], ['UPDATE_KEY', CONF.updateKey ? 'set' : ''], ['SYNC_MINUTES', String(CONF.syncMinutes)], ['MAX_SITES', String(CONF.maxSites)]];
  sendHtml(
    res,
    200,
    { ...ctx, active: 'Config' },
    'Config',
    h`<h1>Config</h1><p class="muted">Read-only. Edit <code>data/config.json</code> in cPanel File Manager; changes apply without a restart.</p>
<pre>${JSON.stringify(cfg, null, 2)}</pre>
<h2>Environment variables</h2><div class="tw"><table><tbody>${vars.map(([k, v]) => h`<tr><th>${k}</th><td>${v || h`<span class="muted">not set</span>`}</td></tr>`)}</tbody></table></div>
<h2>Data files</h2><div class="tw"><table><thead><tr><th>File</th><th>Size</th><th>Changed</th></tr></thead><tbody>${files.map(([a, b, c]) => h`<tr><td>${a}</td><td>${b}</td><td>${c}</td></tr>`)}</tbody></table></div>
<p class="small muted">Limits: ${CONF.maxSites} sites, reports up to ${LIMIT.report / 1024} KB, ${LIMIT.reportsPerHour} reports per site and plugin per hour, ${LIMIT.daily} daily summaries and ${LIMIT.updates} update results per site.</p>`
  );
}

/* ------------------------------------------------------------------ dashboard: routes */

async function dashboard(req, res, url, route, prefix) {
  const method = req.method;
  const base = `${prefix}/dashboard`;
  const ctx = { base, nonce: crypto.randomBytes(16).toString('base64'), sess: null, csrf: '' };
  if (!CONF.adminToken || CONF.adminToken.length < 24 || !S.linkSecret) {
    return sendHtml(res, 503, ctx, 'Dashboard off', h`<div class="login card"><h1>Dashboard is off</h1><p>Set ADMIN_TOKEN (24+ characters) in Setup Node.js App and restart.</p></div>`);
  }
  const ip = clientIp(req);

  if (route === '/dashboard/login') {
    if (method === 'GET') return readSession(req) ? redirect(res, base) : loginPage(res, ctx, 200, '', {}, url.searchParams.get('msg') === 'logged_out' ? 'You have been logged out.' : '');
    if (method !== 'POST') return send(res, 405, { ok: false, code: 'method_not_allowed' });
    const lock = lockouts.get(ip);
    if (lock && lock.until > Date.now()) {
      return loginPage(res, ctx, 429, `Too many wrong tries. Try again in ${Math.ceil((lock.until - Date.now()) / 60000)} minutes.`, { 'Retry-After': String(Math.ceil((lock.until - Date.now()) / 1000)) });
    }
    let form;
    try {
      form = await readForm(req);
    } catch (e) {
      return loginPage(res, ctx, 413, 'That was too long.');
    }
    const pre = cookies(req).osam_pre || '';
    if (!pre || !safeEqual(String(form.pre || ''), pre)) return loginPage(res, ctx, 400, 'The form expired. Please try again.');
    if (!safeEqual(String(form.token || ''), CONF.adminToken)) {
      const now = Date.now();
      let l = lockouts.get(ip);
      if (!l || now - l.first > LOCK.minutes * 60000) l = { count: 0, first: now, until: 0 };
      l.count++;
      if (l.count >= LOCK.tries) {
        l.until = now + LOCK.minutes * 60000;
        l.count = 0;
        l.first = now;
        log(`dashboard: ${ip} locked out for ${LOCK.minutes} minutes after ${LOCK.tries} wrong tokens`);
      }
      if (lockouts.size > 10000) lockouts.delete(lockouts.keys().next().value);
      lockouts.set(ip, l);
      return l.until > now ? loginPage(res, ctx, 429, `Too many wrong tries. Try again in ${LOCK.minutes} minutes.`) : loginPage(res, ctx, 401, 'Wrong token.');
    }
    lockouts.delete(ip);
    log(`dashboard: login from ${ip}`);
    res.setHeader('Set-Cookie', [COOKIE('osam_dash', makeSession(), SESSION_HOURS * 3600), COOKIE('osam_pre', '', 0)]);
    return redirect(res, base);
  }

  const sess = readSession(req);
  if (!sess) {
    if (method === 'GET') return redirect(res, `${base}/login`);
    return sendHtml(res, 401, ctx, 'Logged out', h`<div class="login card"><h1>Please log in again</h1><p><a href="${base}/login">Log in</a></p></div>`);
  }
  ctx.sess = sess;
  ctx.csrf = csrfFor(sess);

  if (method === 'POST') {
    let form;
    try {
      form = await readForm(req);
    } catch (e) {
      return send(res, 413, { ok: false, code: 'too_large' });
    }
    if (!safeEqual(String(form.csrf || ''), ctx.csrf)) {
      return sendHtml(res, 403, ctx, 'Expired', h`<h1>That form has expired</h1><p>Nothing was changed. <a href="${base}">Back to the dashboard</a> and try again.</p>`);
    }
    if (route === '/dashboard/logout') {
      revoked.set(sess.n, sess.exp);
      return redirect(res, `${base}/login?msg=logged_out`, { 'Set-Cookie': COOKIE('osam_dash', '', 0) });
    }
    if (route === '/dashboard/sync') {
      const r = await Promise.race([syncFromGithub(), new Promise((resolve) => setTimeout(() => resolve(null), 25000).unref())]);
      return redirect(res, `${base}?msg=${r ? (r.ok ? 'sync_ok' : 'sync_failed') : 'sync_running'}`);
    }
    return send(res, 404, { ok: false, code: 'not_found' });
  }
  if (method !== 'GET' && method !== 'HEAD') return send(res, 405, { ok: false, code: 'method_not_allowed' });

  if (route === '/dashboard') return overviewPage(res, ctx, url);
  if (route === '/dashboard/sites') return sitesPage(res, ctx, url);
  if (route === '/dashboard/releases') return releasesPage(res, ctx);
  if (route === '/dashboard/config') return configPage(res, ctx);
  const m = route.match(/^\/dashboard\/site\/([^/]+)$/);
  if (m) {
    let host = '';
    try {
      host = decodeURIComponent(m[1]).toLowerCase();
    } catch (e) {
      host = '';
    }
    return sitePage(res, ctx, host);
  }
  return sendHtml(res, 404, ctx, 'Not found', h`<h1>Not found</h1><p><a href="${base}">Back to the overview</a></p>`);
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
  const mm = url.pathname.match(/^(.*?)(\/v1\/.*|\/dashboard(?:\/.*)?)$/);
  const route = (mm ? mm[2] : '').replace(/\/+$/, '') || '/';
  // The sub-path, for links in the dashboard (only plain path segments, so it can't redirect elsewhere).
  const prefix = mm && /^(\/[A-Za-z0-9._~-]+)*$/.test(mm[1]) ? mm[1] : '';
  const method = req.method;

  if (route === '/' || route === '/v1/health') {
    return send(res, 200, { ok: true, service: 'osam-update-server', version: VERSION, releases: S.releases.size, last_sync: S.lastSync ? S.lastSync.at : null });
  }

  if (route === '/dashboard' || route.startsWith('/dashboard/')) return dashboard(req, res, url, route, prefix);

  if (route === '/v1/report' && method === 'POST') return receiveReport(req, res);
  if (route === '/v1/event' && method === 'POST') return receiveEvent(req, res);

  if (route.startsWith('/v1/dashboard/') && method === 'GET') {
    if (!isAdmin(req, url) && !readSession(req)) return send(res, 401, { ok: false, code: 'unauthorised' });
    const data = await dashboardJson(route.slice('/v1/dashboard/'.length), url);
    return data ? send(res, 200, { ok: true, ...data }) : send(res, 404, { ok: false, code: 'not_found' });
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
    countDownload(slug, version, host);
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
    // { "osam-bookings": { "2.16.0": 12 }, "osam-turnstile": { "1.3.0": 9 } }
    for (const s of Object.values(S.sites)) {
      for (const [slug, p] of Object.entries(s.plugins || (s.slug ? { [s.slug]: { version: s.version } } : {}))) {
        versions[slug] = versions[slug] || {};
        versions[slug][p.version] = (versions[slug][p.version] || 0) + 1;
      }
    }
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
  const obj = (x) => (x && typeof x === 'object' && !Array.isArray(x) ? x : {});
  S.sites = obj(await readJson(P.sites(), {}));
  S.reports = obj(await readJson(P.reports(), {}));
  S.history = obj(await readJson(P.history(), {}));
  S.downloads = obj(await readJson(P.downloads(), {}));
  pruneSites();
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

  setInterval(() => flush(), CONF.flushSeconds * 1000).unref();
  setInterval(() => syncFromGithub(), CONF.syncMinutes * 60000).unref();
  if (!problems.length) syncFromGithub();

  const stop = async () => {
    await flush();
    process.exit(0);
  };
  process.on('SIGTERM', stop);
  process.on('SIGINT', stop);
  // Windows has no SIGTERM for child processes; a parent (the tests) can ask over IPC instead.
  if (process.send) process.on('message', (m) => m === 'shutdown' && stop());
  return server;
}

// cPanel's Passenger loads this file through its own wrapper, so start unconditionally.
if (!process.env.OSAM_NO_AUTOSTART) start();

module.exports = { start, cmpVersion, hostOf, _state: S, _conf: CONF };
