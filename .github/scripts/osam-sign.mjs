// Signing helpers for OSAM releases and rollbacks. Node built-ins only.
//
//   node osam-sign.mjs release  <zip> <slug> <version> <main-plugin-file>   -> writes release.json
//   node osam-sign.mjs rollback                                              -> prints {payload, signature}
//
// The private key comes from the OSAM_SIGNING_KEY secret (base64, 32-byte
// Ed25519 seed from tools/keygen.html). It only exists inside the
// protected "release-signing" GitHub environment.

import crypto from 'node:crypto';
import fs from 'node:fs';

function privateKey() {
  const seed = Buffer.from(String(process.env.OSAM_SIGNING_KEY || '').trim(), 'base64');
  if (seed.length !== 32) throw new Error('OSAM_SIGNING_KEY must be a base64 32-byte Ed25519 private key');
  const der = Buffer.concat([Buffer.from('302e020100300506032b657004220420', 'hex'), seed]);
  return crypto.createPrivateKey({ key: der, format: 'der', type: 'pkcs8' });
}

const sign = (msg) => crypto.sign(null, Buffer.from(msg, 'utf8'), privateKey()).toString('base64');

function header(file, name) {
  if (!fs.existsSync(file)) return '';
  const src = fs.readFileSync(file, 'utf8').slice(0, 8192);
  const m = src.match(new RegExp(`^[\\s*#@/]*${name}:\\s*(.+)$`, 'mi'));
  return m ? m[1].trim() : '';
}

const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// readme.txt "== Changelog ==" section -> simple HTML (same rules the
// connector used, so the "View details" popup looks the same).
function changelogHtml(readmeFile) {
  if (!fs.existsSync(readmeFile)) return '';
  const m = fs.readFileSync(readmeFile, 'utf8').match(/^==\s*Changelog\s*==\s*$([\s\S]*?)(?=^==[^=]|(?![\s\S]))/mi);
  if (!m) return '';
  let html = '';
  let open = false;
  for (const raw of m[1].trim().split(/\r?\n/)) {
    const line = raw.trim();
    let x;
    if ((x = line.match(/^=\s*(.+?)\s*=$/))) {
      html += `${open ? '</ul>' : ''}<h4>${esc(x[1])}</h4>`;
      open = false;
    } else if ((x = line.match(/^[*-]\s*(.+)$/))) {
      html += `${open ? '' : '<ul>'}<li>${esc(x[1])}</li>`;
      open = true;
    } else if (line) {
      html += `${open ? '</ul>' : ''}<p>${esc(line)}</p>`;
      open = false;
    }
  }
  return html + (open ? '</ul>' : '');
}

const [mode, ...args] = process.argv.slice(2);

if (mode === 'release') {
  const [zip, slug, version, mainFile] = args;
  // The public key baked into the zip must belong to this private key, or sites would reject it.
  const pub = crypto.createPublicKey(privateKey()).export({ format: 'der', type: 'spki' }).subarray(-32).toString('base64');
  if (process.env.OSAM_PUBLIC_KEY && process.env.OSAM_PUBLIC_KEY.trim() !== pub) {
    throw new Error(`OSAM_PUBLIC_KEY variable (${process.env.OSAM_PUBLIC_KEY.trim()}) doesn't match the signing key (public key ${pub})`);
  }
  const sha256 = crypto.createHash('sha256').update(fs.readFileSync(zip)).digest('hex');
  const meta = {
    slug,
    version,
    sha256,
    signature: sign(`OSAMSIG1\n${slug}\n${version}\n${sha256}`),
    name: header(mainFile, 'Plugin Name') || slug,
    requires: header(mainFile, 'Requires at least'),
    requires_php: header(mainFile, 'Requires PHP'),
    tested: header(mainFile, 'Tested up to') || header(`${mainFile.replace(/[^/]+$/, '')}readme.txt`, 'Tested up to'),
    description: header(mainFile, 'Description'),
    changelog_html: changelogHtml(`${mainFile.replace(/[^/]+$/, '')}readme.txt`),
  };
  if (header(mainFile, 'Version') !== version) {
    throw new Error(`Version header in ${mainFile} (${header(mainFile, 'Version')}) does not match ${version}`);
  }
  fs.writeFileSync('release.json', JSON.stringify(meta, null, 2));
  console.log(`Signed ${slug} ${version} (sha256 ${sha256})`);
} else if (mode === 'rollback') {
  const e = process.env;
  const list = (s) => String(s || '').split(',').map((x) => x.trim().toLowerCase().replace(/^www\./, '')).filter(Boolean);
  const now = Math.floor(Date.now() / 1000);
  const payload = {
    type: 'rollback',
    id: crypto.randomUUID(),
    slug: e.RB_SLUG,
    target_version: String(e.RB_TARGET || '').replace(/^v/, ''),
    block_versions: list(e.RB_BLOCK).map((v) => v.replace(/^v/, '')),
    sites: list(e.RB_SITES || '*'),
    reason: String(e.RB_REASON || '').slice(0, 300),
    issued_at: now,
    expires_at: now + Number(e.RB_DAYS || 30) * 86400,
  };
  if (!payload.slug || !payload.target_version) throw new Error('RB_SLUG and RB_TARGET are required');
  const b64 = Buffer.from(JSON.stringify(payload)).toString('base64');
  process.stdout.write(JSON.stringify({ payload: b64, signature: sign(`OSAMROLL1\n${b64}`) }));
} else {
  console.error('usage: osam-sign.mjs release|rollback ...');
  process.exit(2);
}
