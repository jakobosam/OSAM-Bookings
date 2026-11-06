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
    stdio: ['ignore', 'pipe', 'pipe'],
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
const stopServer = () => new Promise((r) => (proc.exitCode !== null ? r() : (proc.on('exit', r), proc.kill('SIGTERM'))));
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

test('state survives a restart; status lists sites', async () => {
  await stopServer();
  await startServer();
  const st = await (await fetch(`${base}/v1/status`, { headers: admin })).json();
  assert.equal(st.ok, true);
  assert.ok(st.releases.some((r) => r.version === '2.16.0'));
  assert.equal(st.rollbacks.length, 1);
  assert.ok(st.sites['client.co.uk']);
  assert.equal((await upd('client.co.uk', '2.16.0')).version, '2.15.0');
});

test('UPDATE_KEY is enforced when set', async () => {
  await stopServer();
  await startServer({ UPDATE_KEY: 'sitekey' });
  assert.equal((await upd('client.co.uk', '2.15.0')).code, 'bad_key');
  assert.equal((await upd('other.com', '2.15.0', '&key=sitekey')).ok, true);
  // Keep a copy of a real response for the PHP check.
  fs.writeFileSync(path.join(tmp, 'response-rollback.json'), JSON.stringify(await upd('client.co.uk', '2.16.0', '&key=sitekey')));
  fs.writeFileSync(path.join(tmp, 'response-normal.json'), JSON.stringify(await upd('other.com', '2.15.0', '&key=sitekey')));
  fs.writeFileSync(path.join(tmp, 'pubkey'), KEY.pub);
  if (process.env.OSAM_TEST_OUT) for (const f of ['response-rollback.json', 'response-normal.json', 'pubkey']) fs.copyFileSync(path.join(tmp, f), path.join(process.env.OSAM_TEST_OUT, f));
});
