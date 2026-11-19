'use strict';
// End-to-end tests: a fake GitHub, releases signed with the real .github/scripts/osam-sign.mjs,
// and the server running as a child process. Run with: node --test test/
const test = require('node:test');
const assert = require('node:assert');
const http = require('node:http');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn, execFileSync } = require('node:child_process');

const SIGN = path.resolve(__dirname, '../../.github/scripts/osam-sign.mjs');
const APP = path.resolve(__dirname, '../app.js');
const ADMIN = 'admin-token-for-tests-0123456789abcdef';

function keypair() {
  const { privateKey, publicKey } = crypto.generateKeyPairSync('ed25519');
  return {
    seed: privateKey.export({ format: 'der', type: 'pkcs8' }).subarray(-32).toString('base64'),
    pub: publicKey.export({ format: 'der', type: 'spki' }).subarray(-32).toString('base64'),
  };
}

const KEY = keypair();
const OTHER = keypair();
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'osam-us-'));

function makeRelease(version, key = KEY, { tamper = false, slug = 'osam-bookings' } = {}) {
  const dir = fs.mkdtempSync(path.join(tmp, 'rel-'));
  fs.writeFileSync(path.join(dir, `${slug}.php`), `<?php\n/**\n * Plugin Name: ${slug}\n * Version: ${version}\n * Requires at least: 6.2\n * Requires PHP: 7.4\n */\n`);
  fs.writeFileSync(path.join(dir, 'readme.txt'), `=== ${slug} ===\n\n== Changelog ==\n\n= ${version} =\n* Change in ${version}\n`);
  const zip = path.join(dir, `${slug}-${version}.zip`);
  fs.writeFileSync(zip, crypto.randomBytes(2048));
  execFileSync('node', [SIGN, 'release', zip, slug, version, path.join(dir, `${slug}.php`)], { cwd: dir, env: { ...process.env, OSAM_SIGNING_KEY: key.seed, OSAM_PUBLIC_KEY: '' } });
  if (tamper) fs.appendFileSync(zip, 'x');
  return { version, slug, zip: fs.readFileSync(zip), meta: fs.readFileSync(path.join(dir, 'release.json')) };
}

function signRollback(env, key = KEY) {
  return JSON.parse(execFileSync('node', [SIGN, 'rollback'], { env: { ...process.env, OSAM_SIGNING_KEY: key.seed, RB_SLUG: 'osam-bookings', ...env } }).toString());
}

// ---- fake GitHub
let releases = [];
let assets = new Map();
let ghBase;
function setReleases(list) {
  releases = [];
  assets = new Map();
  let id = 1;
  for (const r of list) {
    const a = [];
    for (const [name, buf] of [['release.json', r.meta], [`${r.slug}-${r.version}.zip`, r.zip]]) {
      const aid = id++;
      assets.set(String(aid), buf);
      a.push({ id: aid, name, url: `${ghBase}/repos/o/r/releases/assets/${aid}` });
    }
    releases.push({ tag_name: r.slug === 'osam-bookings' ? `v${r.version}` : `${r.slug}-v${r.version}`, draft: false, prerelease: r.version.includes('-'), published_at: '2026-11-05T10:00:00Z', assets: a });
  }
}
const gh = http.createServer((req, res) => {
  if (req.headers.authorization !== 'Bearer ghtoken' && !req.url.startsWith('/storage/')) {
    res.writeHead(401).end();
    return;
  }
  if (req.url.startsWith('/repos/o/r/releases?')) {
    res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify(releases));
  } else if (req.url.startsWith('/repos/o/r/releases/assets/')) {
    res.writeHead(302, { Location: `${ghBase}/storage/${req.url.split('/').pop()}` }).end();
  } else if (req.url.startsWith('/storage/')) {
    const b = assets.get(req.url.split('/').pop());
    res.writeHead(b ? 200 : 404).end(b);
  } else res.writeHead(404).end();
});

// ---- server under test
let proc;
let base;
const dataDir = path.join(tmp, 'data');
async function startServer(extraEnv = {}) {
  const port = 20000 + Math.floor(Math.random() * 20000);
  extraEnv = { PUBLIC_URL: `http://127.0.0.1:${port}`, ...extraEnv };
  proc = spawn('node', [APP], {
    env: { ...process.env, PORT: String(port), GITHUB_REPO: 'o/r', GITHUB_TOKEN: 'ghtoken', GITHUB_API: ghBase, PUBLIC_KEY: KEY.pub, ADMIN_TOKEN: ADMIN, DATA_DIR: dataDir, WEBHOOK_SECRET: 'whsec', ...extraEnv },
    stdio: ['ignore', 'pipe', 'pipe', 'ipc'],
  });
  base = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 50; i++) {
    try {
      await fetch(`${base}/`);
      return;
    } catch (e) {
      await new Promise((r) => setTimeout(r, 100));
    }
  }
  throw new Error('server did not start');
}
// Ask over IPC so the server saves its files first (Windows has no SIGTERM for child processes).
const stopServer = () => new Promise((r) => (proc.exitCode !== null ? r() : (proc.on('exit', r), proc.connected ? proc.send('shutdown') : proc.kill('SIGTERM'))));
const admin = { Authorization: `Bearer ${ADMIN}` };
const upd = async (site, version, extra = '') => (await fetch(`${base}/v1/update?slug=osam-bookings&site=${encodeURIComponent(site)}&version=${version}&caps=force,rollback,signed${extra}`)).json();
const sync = async () => (await fetch(`${base}/v1/sync`, { headers: admin })).json();

test.before(async () => {
  await new Promise((r) => gh.listen(0, '127.0.0.1', r));
  ghBase = `http://127.0.0.1:${gh.address().port}`;
  setReleases([makeRelease('2.15.0'), makeRelease('2.16.0'), makeRelease('2.17.0-beta.1')]);
  await startServer();
});
test.after(async () => {
  await stopServer();
  gh.close();
});

test('serves the newest stable signed release and its zip', async () => {
  const s = await sync();
  assert.equal(s.ok, true, JSON.stringify(s));
  const r = await upd('https://www.client.co.uk/', '2.15.0');
  assert.equal(r.ok, true);
  assert.equal(r.version, '2.16.0');
  assert.equal(r.forced, false);
  assert.equal(r.instruction, null);
  assert.match(r.sections.changelog, /Change in 2\.16\.0/);
  const zip = Buffer.from(await (await fetch(r.package)).arrayBuffer());
  assert.equal(crypto.createHash('sha256').update(zip).digest('hex'), r.sha256);
  const pub = crypto.createPublicKey({ key: Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), Buffer.from(KEY.pub, 'base64')]), format: 'der', type: 'spki' });
  assert.ok(crypto.verify(null, Buffer.from(`OSAMSIG1\nosam-bookings\n2.16.0\n${r.sha256}`), pub, Buffer.from(r.signature, 'base64')));
});

test('download links are tied to the site and refuse tampering', async () => {
  const r = await upd('client.co.uk', '2.15.0');
  const u = new URL(r.package);
  u.searchParams.set('site', 'other.com');
  assert.equal((await fetch(u)).status, 403);
  u.searchParams.set('site', 'client.co.uk');
  u.searchParams.set('exp', String(Number(u.searchParams.get('exp')) + 1));
  assert.equal((await fetch(u)).status, 403);
});

test('ignores releases signed with another key or with an altered zip', async () => {
  setReleases([makeRelease('2.15.0'), makeRelease('2.16.0'), makeRelease('2.17.0-beta.1'), makeRelease('9.0.0', OTHER), makeRelease('9.1.0', KEY, { tamper: true })]);
  const s = await sync();
  assert.equal(s.skipped.length, 2, JSON.stringify(s));
  assert.equal((await upd('client.co.uk', '2.15.0')).version, '2.16.0');
});

test('admin routes need the token; webhook needs its signature', async () => {
  assert.equal((await fetch(`${base}/v1/sync`)).status, 401);
  assert.equal((await fetch(`${base}/v1/status`, { headers: { Authorization: 'Bearer nope' } })).status, 401);
  assert.equal((await fetch(`${base}/v1/status?token=nope`)).status, 401);
  assert.equal((await fetch(`${base}/v1/status?token=${ADMIN}`)).status, 200);
  assert.equal((await fetch(`${base}/v1/rollbacks?token=${ADMIN}`, { method: 'POST', body: '{}' })).status, 401);
  const body = JSON.stringify({ action: 'published' });
  assert.equal((await fetch(`${base}/v1/webhook/github`, { method: 'POST', body, headers: { 'X-GitHub-Event': 'release', 'X-Hub-Signature-256': 'sha256=00' } })).status, 401);
  const sig = `sha256=${crypto.createHmac('sha256', 'whsec').update(body).digest('hex')}`;
  assert.equal((await fetch(`${base}/v1/webhook/github`, { method: 'POST', body, headers: { 'X-GitHub-Event': 'release', 'X-Hub-Signature-256': sig } })).status, 202);
});

test('channels, pins and minimum version from config.json', async () => {
  fs.writeFileSync(path.join(dataDir, 'config.json'), JSON.stringify({ default_channel: 'stable', allowed_sites: [], minimum_version: { 'osam-bookings': '2.16.0' }, sites: { 'beta.test': { channel: 'beta' }, 'pinned.test': { pin: '2.15.0' } } }));
  await new Promise((r) => setTimeout(r, 20));
  assert.equal((await upd('beta.test', '2.16.0')).version, '2.17.0-beta.1');
  const pinned = await upd('pinned.test', '2.15.0');
  assert.equal(pinned.version, '2.15.0');
  const old = await upd('old.test', '2.15.0');
  assert.equal(old.version, '2.16.0');
  assert.equal(old.forced, true);
  assert.equal((await upd('current.test', '2.16.0')).forced, false);
});

test('allowed_sites limits who gets updates', async () => {
  fs.writeFileSync(path.join(dataDir, 'config.json'), JSON.stringify({ allowed_sites: ['client.co.uk'] }));
  await new Promise((r) => setTimeout(r, 20));
  assert.equal((await upd('www.client.co.uk', '2.15.0')).ok, true);
  const no = await upd('stranger.com', '2.15.0');
  assert.equal(no.ok, false);
  assert.equal(no.code, 'not_allowed');
  fs.writeFileSync(path.join(dataDir, 'config.json'), JSON.stringify({}));
  await new Promise((r) => setTimeout(r, 20));
});

test('a second plugin (osam-turnstile) with its own tags, pins and versions', async () => {
  const ts = { slug: 'osam-turnstile' };
  setReleases([makeRelease('2.15.0'), makeRelease('2.16.0'), makeRelease('2.17.0-beta.1'), makeRelease('1.3.0', KEY, ts), makeRelease('1.4.0', KEY, ts)]);
  const s = await sync();
  assert.equal(s.ok, true, JSON.stringify(s));
  assert.equal(s.skipped.length, 0, JSON.stringify(s));
  const tsUrl = (site, v) => `${base}/v1/update?slug=osam-turnstile&site=${site}&version=${v}&caps=force,rollback,signed`;
  const t = await (await fetch(tsUrl('client.co.uk', '1.3.0'))).json();
  assert.equal(t.version, '1.4.0');
  assert.match(t.package, /\/v1\/download\/osam-turnstile\/1\.4\.0\.zip/);
  assert.equal(crypto.createHash('sha256').update(Buffer.from(await (await fetch(t.package)).arrayBuffer())).digest('hex'), t.sha256);
  assert.equal((await upd('client.co.uk', '2.16.0')).version, '2.16.0'); // Bookings unaffected
  // A plain pin / minimum means OSAM Bookings only; an object names the plugin.
  fs.writeFileSync(path.join(dataDir, 'config.json'), JSON.stringify({ minimum_version: '2.16.0', sites: { 'p.test': { pin: '2.15.0' }, 'q.test': { pin: { 'osam-turnstile': '1.3.0' } } } }));
  await new Promise((r) => setTimeout(r, 20));
  assert.equal((await (await fetch(tsUrl('p.test', '1.3.0'))).json()).version, '1.4.0');
  assert.equal((await (await fetch(tsUrl('p.test', '1.3.0'))).json()).forced, false);
  assert.equal((await upd('p.test', '2.15.0')).version, '2.15.0');
  assert.equal((await (await fetch(tsUrl('q.test', '1.3.0'))).json()).version, '1.3.0');
  assert.equal((await upd('q.test', '2.15.0')).version, '2.16.0');
  fs.writeFileSync(path.join(dataDir, 'config.json'), JSON.stringify({}));
  await new Promise((r) => setTimeout(r, 20));
  const st = await (await fetch(`${base}/v1/status`, { headers: admin })).json();
  assert.equal(st.site_versions['osam-turnstile']['1.3.0'] >= 1, true);
  assert.equal(st.sites['client.co.uk'].plugins['osam-turnstile'].version, '1.3.0');
  assert.equal(st.sites['client.co.uk'].version, '2.16.0');
});

test('signed rollbacks: scoped, forced, and withdrawn versions never offered', async () => {
  // Unsigned / wrongly signed instructions are refused.
  const bad = signRollback({ RB_TARGET: '2.15.0', RB_BLOCK: '2.16.0', RB_SITES: '*' }, OTHER);
  assert.equal((await fetch(`${base}/v1/rollbacks`, { method: 'POST', headers: admin, body: JSON.stringify(bad) })).status, 400);
  // Target must exist.
  const missing = signRollback({ RB_TARGET: '1.0.0', RB_BLOCK: '2.16.0', RB_SITES: '*' });
  assert.equal((await fetch(`${base}/v1/rollbacks`, { method: 'POST', headers: admin, body: JSON.stringify(missing) })).status, 400);

  const rb = signRollback({ RB_TARGET: '2.15.0', RB_BLOCK: '2.16.0', RB_SITES: 'client.co.uk' });
  const res = await fetch(`${base}/v1/rollbacks`, { method: 'POST', headers: admin, body: JSON.stringify(rb) });
  assert.equal(res.status, 201);
  assert.equal((await fetch(`${base}/v1/rollbacks`, { method: 'POST', headers: admin, body: JSON.stringify(rb) })).status, 200);

  const hit = await upd('client.co.uk', '2.16.0');
  assert.equal(hit.version, '2.15.0');
  assert.equal(hit.forced, true);
  assert.equal(hit.rollback, true);
  assert.deepEqual(hit.instruction, rb);

  // Same site on 2.15.0: gets 2.15.0, never 2.16.0 again.
  const after = await upd('client.co.uk', '2.15.0');
  assert.equal(after.version, '2.15.0');
  assert.equal(after.instruction, null);

  // Another site isn't affected.
  assert.equal((await upd('other.com', '2.16.0')).version, '2.16.0');
  assert.equal((await upd('other.com', '2.16.0')).instruction, null);
});

/* ---------------------------------------------------------------- reports, events, dashboard */

const post = (p, body, headers = {}) => fetch(`${base}${p}`, { method: 'POST', headers: { 'Content-Type': 'application/json', ...headers }, body: typeof body === 'string' ? body : JSON.stringify(body) });
const sampleReport = (over = {}) => ({
  generated: Math.floor(Date.now() / 1000),
  env: { wp: '6.7.1', php: '8.2.10', db: { type: 'mariadb', version: '10.6.12' }, multisite: false, locale: 'en_GB', timezone: 'Europe/London', title: 'Client Ltd', home: 'https://client.co.uk/', theme: 'Astra', active_plugins: 14, https: true, wp_debug: false, disable_wp_cron: true },
  updates: { auto: true, last_forced: null, blocked: [], last_check: { time: 1, latest: '2.16.0', error: '' } },
  cron: { state: 'ok', last_run: Math.floor(Date.now() / 1000) - 60, gap: 300, real_cron: true, server: true, late: false },
  features: ['events', 'coupons'],
  payments: { gateways: ['stripe', 'onsite'], stripe: { mode: 'live', webhook: true } },
  zoom: false,
  calendar: { enabled: true, connections: 2, errors: 0 },
  subscription: { linked: true, status: 'active' },
  problems: { day: { total: 0, errors: 0, warnings: 0, by_area: {} }, week: { total: 1, errors: 0, warnings: 1, by_area: { email: 1 } }, last_updates: null, last_cron: null },
  bookings: { total: 120, last_30d: 14, upcoming: 6 },
  ...over,
});
const report = (site, version, rep = sampleReport(), extra = {}) => post('/v1/report', { schema: 1, slug: 'osam-bookings', site, version, report: rep, ...extra });
const siteJson = async (host) => (await fetch(`${base}/v1/dashboard/sites/${host}`, { headers: admin })).json();

function cookieFrom(res, name) {
  const all = typeof res.headers.getSetCookie === 'function' ? res.headers.getSetCookie() : [res.headers.get('set-cookie') || ''];
  const c = all.find((x) => x.startsWith(`${name}=`));
  return c ? { value: c.split(';')[0].slice(name.length + 1), raw: c } : null;
}
let ipN = 0;
async function login(token = ADMIN, ip = `10.0.0.${++ipN}`) {
  const page = await fetch(`${base}/dashboard/login`, { headers: { 'X-Forwarded-For': ip } });
  const pre = cookieFrom(page, 'osam_pre').value;
  assert.match(await page.text(), new RegExp(`name="pre" value="${pre}"`));
  const res = await fetch(`${base}/dashboard/login`, {
    method: 'POST',
    redirect: 'manual',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: `osam_pre=${pre}`, 'X-Forwarded-For': ip },
    body: new URLSearchParams({ pre, token }).toString(),
  });
  return { res, cookie: cookieFrom(res, 'osam_dash') };
}
const page = (p, cookie) => fetch(`${base}${p}`, { redirect: 'manual', headers: cookie ? { Cookie: `osam_dash=${cookie}` } : {} });
const csrfOf = (html) => (html.match(/name="csrf" value="([0-9a-f]+)"/) || [])[1];

test('reports: a valid report is stored with a daily summary and version timeline', async () => {
  const r = await report('https://www.client.co.uk/', '2.16.0');
  assert.equal(r.status, 202, await r.text());
  assert.equal((await report('client.co.uk', '2.16.0', sampleReport({ bookings: { total: 121, last_30d: 15, upcoming: 6 } }))).status, 202);
  const d = await siteJson('client.co.uk');
  assert.equal(d.ok, true);
  assert.equal(d.reports['osam-bookings'].report.env.title, 'Client Ltd');
  assert.equal(d.reports['osam-bookings'].report.bookings.last_30d, 15);
  assert.equal(d.history.daily.filter((x) => x.slug === 'osam-bookings').length, 1, 'one summary per day');
  assert.equal(d.history.daily[0].bookings_30d, 15);
  assert.ok(d.history.versions['osam-bookings'].some((v) => v.version === '2.16.0' && v.first_seen));
  assert.equal(d.health.level, 'ok', JSON.stringify(d.health));
  // Odd keys and long strings are dropped / cut.
  const odd = JSON.parse('{"__proto__":{"x":1},"env":{"title":"' + 'a'.repeat(5000) + '","bad key!":1}}');
  assert.equal((await report('odd.test', '2.16.0', odd)).status, 202);
  const o = await siteJson('odd.test');
  assert.equal(o.reports['osam-bookings'].report.env.title.length, 300);
  assert.equal(o.reports['osam-bookings'].report.env['bad key!'], undefined);
  assert.equal(Object.keys(o.reports['osam-bookings'].report).includes('__proto__'), false);
  // The second plugin reports too.
  assert.equal((await post('/v1/report', { slug: 'osam-turnstile', site: 'client.co.uk', version: '1.3.0', report: { env: { wp: '6.7.1' }, updates: { auto: true } } })).status, 202);
  assert.ok((await siteJson('client.co.uk')).reports['osam-turnstile']);
});

test('reports: oversized, malformed and invalid ones are refused', async () => {
  assert.equal((await report('big.test', '2.16.0', sampleReport({ padding: 'x'.repeat(40 * 1024) }))).status, 413);
  const many = {};
  for (let i = 0; i < 59; i++) many[`k${i}`] = 'y'.repeat(200);
  assert.equal((await report('big2.test', '2.16.0', { a: many, b: many })).status, 413, 'too big after cleaning');
  assert.equal((await post('/v1/report', '{not json')).status, 400);
  assert.equal((await post('/v1/report', [1, 2])).status, 400);
  assert.equal((await report('<script>x</script>', '2.16.0')).status, 400);
  assert.equal((await report('ok.test', 'not-a-version')).status, 400);
  assert.equal((await report('ok.test', '2.16.0', 'string')).status, 400);
  assert.equal((await post('/v1/report', { slug: 'BAD SLUG', site: 'ok.test', version: '2.16.0', report: {} })).status, 400);
  assert.equal((await fetch(`${base}/v1/dashboard/sites/big.test`, { headers: admin })).status, 404);
});

test('reports: per-site rate limit', async () => {
  for (let i = 0; i < 6; i++) assert.equal((await report('busy.test', '2.16.0')).status, 202);
  const r = await report('busy.test', '2.16.0');
  assert.equal(r.status, 429);
  assert.ok(Number(r.headers.get('retry-after')) > 0);
  assert.equal((await report('calm.test', '2.16.0')).status, 202, 'other sites unaffected');
});

test('events: update results, duplicates and health', async () => {
  const ev = (e) => post('/v1/event', { slug: 'osam-bookings', site: 'evt.test', version: '2.15.0', event: { type: 'update', time: 1, ...e } });
  assert.equal((await ev({ from: '2.15.0', to: '2.16.0', ok: false, error: 'Download failed.', forced: true })).status, 202);
  assert.equal((await (await ev({ from: '2.15.0', to: '2.16.0', ok: false, error: 'Download failed.' })).json()).duplicate, true);
  assert.equal((await post('/v1/event', { slug: 'osam-bookings', site: 'evt.test', version: '2.15.0', event: { type: 'nope' } })).status, 400);
  let d = await siteJson('evt.test');
  assert.equal(d.history.updates.length, 1);
  assert.equal(d.history.updates[0].error, 'Download failed.');
  assert.equal(d.health.level, 'bad');
  const ov = await (await fetch(`${base}/v1/dashboard/overview`, { headers: admin })).json();
  assert.ok(ov.problems.some((p) => p.host === 'evt.test'));
  assert.equal((await ev({ from: '2.15.0', to: '2.16.0', ok: true })).status, 202);
  d = await siteJson('evt.test');
  assert.equal(d.health.level, 'ok', JSON.stringify(d.health));
  assert.ok(d.history.versions['osam-bookings'].some((v) => v.version === '2.16.0'));
  // Problems in a report show up too: cron late, Stripe test mode on a live site, webhook missing.
  await report('shop.example.com', '2.16.0', sampleReport({ cron: { state: 'late', late: true }, payments: { gateways: ['stripe'], stripe: { mode: 'test', webhook: false } } }));
  const issues = (await siteJson('shop.example.com')).health.issues.map((i) => i.msg).join('|');
  assert.match(issues, /late/);
  assert.match(issues, /test mode/);
  assert.match(issues, /webhook/);
  await report('staging.example.com', '2.16.0', sampleReport({ payments: { gateways: ['stripe'], stripe: { mode: 'test', webhook: true } } }));
  assert.equal((await siteJson('staging.example.com')).health.level, 'ok', 'test mode is fine on staging');
});

test('downloads are counted per release (GET only)', async () => {
  const before = (await (await fetch(`${base}/v1/dashboard/releases`, { headers: admin })).json()).releases['osam-bookings'].find((r) => r.version === '2.16.0').downloads;
  const r = await upd('dl.test', '2.15.0');
  assert.equal((await fetch(r.package, { method: 'HEAD' })).status, 200);
  await (await fetch(r.package)).arrayBuffer();
  const rel = (await (await fetch(`${base}/v1/dashboard/releases`, { headers: admin })).json()).releases['osam-bookings'].find((x) => x.version === '2.16.0');
  assert.equal(rel.downloads, before + 1);
  assert.ok(rel.sites >= 1);
  assert.equal((await siteJson('dl.test')).history.downloads[0].version, '2.16.0');
});

test('dashboard: login, session cookie, security headers, CSRF and logout', async () => {
  const anon = await page('/dashboard');
  assert.equal(anon.status, 303);
  assert.match(anon.headers.get('location'), /\/dashboard\/login$/);
  assert.equal((await fetch(`${base}/v1/dashboard/overview`)).status, 401);

  assert.equal((await login('wrong-token-wrong-token-wrong')).res.status, 401);
  const { res, cookie } = await login();
  assert.equal(res.status, 303);
  assert.ok(cookie, 'session cookie set');
  assert.match(cookie.raw, /HttpOnly/);
  assert.match(cookie.raw, /Secure/);
  assert.match(cookie.raw, /SameSite=Strict/);
  assert.match(cookie.raw, /Max-Age=43200/);

  const ov = await page('/dashboard', cookie.value);
  assert.equal(ov.status, 200);
  const csp = ov.headers.get('content-security-policy');
  assert.match(csp, /script-src 'nonce-[^']+'/);
  assert.doesNotMatch(csp, /unsafe-inline/);
  assert.match(ov.headers.get('cache-control'), /no-store/);
  assert.equal(ov.headers.get('x-frame-options'), 'DENY');
  const html = await ov.text();
  assert.match(html, /Overview/);
  assert.match(html, /Sync now/);
  for (const p of ['/dashboard/sites', '/dashboard/releases', '/dashboard/config', '/dashboard/site/client.co.uk']) assert.equal((await page(p, cookie.value)).status, 200, p);
  assert.equal((await page('/dashboard/site/nowhere.test', cookie.value)).status, 404);
  assert.equal((await fetch(`${base}/v1/dashboard/overview`, { headers: { Cookie: `osam_dash=${cookie.value}` } })).status, 200);

  // A forged or altered cookie is ignored.
  assert.equal((await page('/dashboard', cookie.value.replace(/^./, (c) => (c === 'a' ? 'b' : 'a')))).status, 303);

  // POSTs need the CSRF token.
  const csrf = csrfOf(html);
  assert.ok(csrf);
  const form = (p, body) => fetch(`${base}${p}`, { method: 'POST', redirect: 'manual', headers: { Cookie: `osam_dash=${cookie.value}`, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body).toString() });
  assert.equal((await form('/dashboard/sync', {})).status, 403);
  assert.equal((await form('/dashboard/sync', { csrf: 'f'.repeat(64) })).status, 403);
  const s = await form('/dashboard/sync', { csrf });
  assert.equal(s.status, 303);
  assert.match(s.headers.get('location'), /msg=sync_ok/);
  assert.equal((await form('/dashboard/logout', {})).status, 403);
  const out = await form('/dashboard/logout', { csrf });
  assert.equal(out.status, 303);
  assert.match(cookieFrom(out, 'osam_dash').raw, /Max-Age=0/);
  assert.equal((await page('/dashboard', cookie.value)).status, 303, 'old cookie no longer works');

  // Login form needs its own pre-login token.
  const bad = await fetch(`${base}/dashboard/login`, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Forwarded-For': '10.1.1.1' }, body: `token=${ADMIN}` });
  assert.equal(bad.status, 400);
});

test('dashboard: five wrong tokens lock the address out for 15 minutes', async () => {
  const ip = '10.66.66.66';
  for (let i = 1; i <= 4; i++) assert.equal((await login(`wrong-${i}-xxxxxxxxxxxxxxxxxxxxxxx`, ip)).res.status, 401);
  const fifth = await login('wrong-5-xxxxxxxxxxxxxxxxxxxxxxx', ip);
  assert.equal(fifth.res.status, 429);
  const right = await login(ADMIN, ip);
  assert.equal(right.res.status, 429, 'locked even with the right token');
  assert.equal(right.cookie, null);
  assert.match(await right.res.text(), /Too many wrong tries/);
  assert.equal((await login(ADMIN, '10.66.66.67')).res.status, 303, 'other addresses unaffected');
});

test('dashboard: everything a site sent is HTML-escaped', async () => {
  const evil = '<script>alert(1)</script>';
  const evil2 = '"><img src=x onerror=alert(2)>';
  const rep = sampleReport({ env: { ...sampleReport().env, title: evil, theme: evil2, home: 'javascript:alert(3)' }, features: [evil2], extra: { [`k`]: evil } });
  assert.equal((await report('xss.test', '2.16.0', rep)).status, 202);
  await post('/v1/event', { slug: 'osam-bookings', site: 'xss.test', version: '2.16.0', event: { type: 'update', from: '2.16.0', to: '<b>9</b>', ok: false, error: evil2 } });
  await fetch(`${base}/v1/update?slug=osam-bookings&site=xss.test&version=2.16.0&wp=${encodeURIComponent(evil)}&php=${encodeURIComponent(evil2)}`);
  const { cookie } = await login();
  for (const p of ['/dashboard/site/xss.test', '/dashboard/sites', '/dashboard']) {
    const html = await (await page(p, cookie.value)).text();
    assert.ok(!html.includes(evil), `${p}: raw script tag`);
    assert.ok(!html.includes('<img src=x'), `${p}: raw img tag`);
    assert.ok(!html.includes('<b>9</b>'), `${p}: raw b tag`);
    assert.ok(!html.includes('href="javascript:'), `${p}: javascript link`);
    if (p !== '/dashboard') assert.ok(html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'), `${p}: escaped title shown`);
  }
});

test('state survives a restart; status lists sites', async () => {
  await stopServer();
  await startServer();
  const st = await (await fetch(`${base}/v1/status`, { headers: admin })).json();
  assert.equal(st.ok, true);
  assert.ok(st.releases.some((r) => r.version === '2.16.0'));
  assert.equal(st.rollbacks.length, 1);
  assert.ok(st.sites['client.co.uk']);
  assert.equal((await upd('client.co.uk', '2.16.0')).version, '2.15.0');
  // Reports, history and download counts were saved too.
  const d = await siteJson('client.co.uk');
  assert.equal(d.reports['osam-bookings'].report.env.title, 'Client Ltd');
  assert.ok(d.history.daily.length >= 1);
  assert.equal((await siteJson('evt.test')).history.updates.length, 2);
  const rel = (await (await fetch(`${base}/v1/dashboard/releases`, { headers: admin })).json()).releases['osam-bookings'].find((x) => x.version === '2.16.0');
  assert.ok(rel.downloads >= 1);
  // Sessions survive a restart (same ADMIN_TOKEN and data folder).
  const { cookie } = await login();
  assert.equal((await page('/dashboard', cookie.value)).status, 200);
});

test('data files stay bounded: MAX_SITES drops the longest-silent sites', async () => {
  await stopServer();
  await startServer({ MAX_SITES: '12', FLUSH_SECONDS: '1' });
  for (let i = 0; i < 15; i++) await upd(`many-${i}.test`, '2.16.0');
  const st = await (await fetch(`${base}/v1/status`, { headers: admin })).json();
  assert.ok(Object.keys(st.sites).length <= 12, String(Object.keys(st.sites).length));
  assert.ok(st.sites['many-14.test'], 'newest kept');
  await new Promise((r) => setTimeout(r, 1500));
  assert.ok(Object.keys(JSON.parse(fs.readFileSync(path.join(dataDir, 'sites.json'), 'utf8'))).length <= 12);
});

test('UPDATE_KEY is enforced when set', async () => {
  await stopServer();
  await startServer({ UPDATE_KEY: 'sitekey' });
  assert.equal((await upd('client.co.uk', '2.15.0')).code, 'bad_key');
  assert.equal((await upd('other.com', '2.15.0', '&key=sitekey')).ok, true);
  // Reports and events need the key too.
  assert.equal((await report('other.com', '2.16.0')).status, 403);
  assert.equal((await report('other.com', '2.16.0', sampleReport(), { key: 'wrong' })).status, 403);
  assert.equal((await report('other.com', '2.16.0', sampleReport(), { key: 'sitekey' })).status, 202);
  assert.equal((await post('/v1/event', { slug: 'osam-bookings', site: 'other.com', version: '2.16.0', event: { type: 'update', ok: true } })).status, 403);
  // Keep a copy of a real response for the PHP check.
  fs.writeFileSync(path.join(tmp, 'response-rollback.json'), JSON.stringify(await upd('client.co.uk', '2.16.0', '&key=sitekey')));
  fs.writeFileSync(path.join(tmp, 'response-normal.json'), JSON.stringify(await upd('other.com', '2.15.0', '&key=sitekey')));
  fs.writeFileSync(path.join(tmp, 'pubkey'), KEY.pub);
  if (process.env.OSAM_TEST_OUT) for (const f of ['response-rollback.json', 'response-normal.json', 'pubkey']) fs.copyFileSync(path.join(tmp, f), path.join(process.env.OSAM_TEST_OUT, f));
});
