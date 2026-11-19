#!/usr/bin/env node
// Runs every tests/e2e-*.php inside WordPress Playground (WordPress + SQLite, no server or database needed).
//
//   node tests/run.mjs [php-version] [name-filter]
//   node tests/run.mjs 7.4
//   node tests/run.mjs 8.3 core        (only files whose name contains "core")
//
// Each script must print "ALL PASSED" as its last summary line and exit 0. Anything else fails the run.
// The plugin is mounted at /wordpress/wp-content/plugins/osam-bookings and tests/ at /tests,
// so a test runs as /tests/e2e-<name>.php (see tests/lib/harness.php for the output convention).
//
// Environment:
//   WP_VERSION            WordPress version for Playground (default: latest)
//   OSB_TEST_TIMEOUT_MIN  Minutes before one test file is killed (default: 15)
//
// No dependencies; needs Node 20.18+ (Playground CLI requirement) and network access the first time
// (npx fetches the pinned Playground CLI, Playground fetches WordPress).

import { spawn } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// Pinned: the version this suite was written and verified against. Bump on purpose, not by accident.
const PLAYGROUND_CLI = '@wp-playground/cli@3.1.64';
const PHP_VERSIONS = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

const testsDir = dirname(fileURLToPath(import.meta.url));
const repoDir = resolve(testsDir, '..');
const php = process.argv[2] || '8.3';
const filter = process.argv[3] || '';
const wp = process.env.WP_VERSION || 'latest';
const timeoutMs = Number(process.env.OSB_TEST_TIMEOUT_MIN || 15) * 60 * 1000;

if (!PHP_VERSIONS.includes(php)) {
  console.error(`Unknown PHP version "${php}". Use one of: ${PHP_VERSIONS.join(', ')}`);
  process.exit(2);
}

const files = readdirSync(testsDir)
  .filter((f) => /^e2e-.+\.php$/.test(f) && f.includes(filter))
  .sort();
if (!files.length) {
  console.error(`No tests/e2e-*.php files found${filter ? ` matching "${filter}"` : ''}.`);
  process.exit(2);
}

const isWin = process.platform === 'win32';
const quote = (a) => (isWin && /[\s"&|<>^()]/.test(a) ? `"${a.replace(/"/g, '""')}"` : a);

function runOne(file) {
  const args = [
    '--yes', PLAYGROUND_CLI, 'php',
    '--php', php,
    '--wp', wp,
    '--verbosity', 'quiet',
    '--mount-dir', repoDir, '/wordpress/wp-content/plugins/osam-bookings',
    '--mount-dir', testsDir, '/tests',
    '--', `/tests/${file}`,
  ];
  return new Promise((done) => {
    const started = Date.now();
    // npx is a .cmd on Windows, which needs a shell (given one command string).
    const child = spawn(isWin ? ['npx.cmd', ...args].map(quote).join(' ') : 'npx', isWin ? [] : args, {
      cwd: repoDir,
      shell: isWin,
      env: { ...process.env, MSYS_NO_PATHCONV: '1' },
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    let out = '';
    const onData = (stream) => (chunk) => {
      out += chunk.toString();
      stream.write(chunk);
    };
    child.stdout.on('data', onData(process.stdout));
    child.stderr.on('data', onData(process.stderr));
    const timer = setTimeout(() => {
      out += `\nTIMEOUT after ${timeoutMs / 60000} minutes\n`;
      child.kill('SIGKILL');
    }, timeoutMs);
    child.on('error', (err) => {
      out += `\nCould not start npx: ${err.message}\n`;
    });
    child.on('close', (code, signal) => {
      clearTimeout(timer);
      const lines = out.split(/\r?\n/).map((l) => l.trim());
      const allPassed = lines.includes('ALL PASSED');
      const failLines = lines.filter((l) => l.startsWith('FAIL ') || l.startsWith('PHP '));
      done({
        file,
        ok: code === 0 && allPassed && !failLines.length,
        code: code ?? signal,
        allPassed,
        failLines,
        secs: Math.round((Date.now() - started) / 1000),
      });
    });
  });
}

console.log(`OSAM Bookings e2e tests: PHP ${php}, WordPress ${wp}, ${PLAYGROUND_CLI}`);
const results = [];
for (const file of files) {
  console.log(`\n===== ${file} (PHP ${php}) =====`);
  results.push(await runOne(file));
}

console.log(`\n===== Summary (PHP ${php}) =====`);
for (const r of results) {
  const why = r.ok
    ? ''
    : ` (exit ${r.code}${r.allPassed ? '' : ', no "ALL PASSED"'}${r.failLines.length ? `, ${r.failLines.length} FAIL/PHP line(s)` : ''})`;
  console.log(`${r.ok ? 'PASS' : 'FAIL'} ${r.file} in ${r.secs}s${why}`);
  for (const l of r.ok ? [] : r.failLines) console.log(`     ${l}`);
}
const failed = results.filter((r) => !r.ok).length;
if (failed && process.env.GITHUB_ACTIONS) {
  for (const r of results.filter((x) => !x.ok)) {
    console.log(`::error title=e2e ${r.file} (PHP ${php})::${(r.failLines[0] || `exit ${r.code}`).replace(/[\r\n%]/g, ' ')}`);
  }
}
console.log(failed ? `\n${failed} of ${results.length} test file(s) FAILED on PHP ${php}` : `\nAll ${results.length} test file(s) passed on PHP ${php}`);
process.exit(failed ? 1 : 0);
