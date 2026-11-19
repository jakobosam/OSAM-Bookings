# OSAM update server (updates.osamweb.com)

Serves signed OSAM Bookings releases from this GitHub repo to client sites. Node 18.17+ and **no npm packages**, so in cPanel there's nothing to install.

The server never signs anything. GitHub Actions signs each release with OSAM's private key, and the plugin checks the signature itself before installing. If this server were hacked, it still couldn't push code to client sites.

All steps below are in the browser (cPanel, GitHub). No terminal needed.

---

## 1. Make the keys

Open `tools/keygen.html` from this repo on your computer (download it, double-click it) and click **Generate**. It gives you four values. Keep the tab open until steps 2–4 are done, then close it.

## 2. GitHub settings (OSAM-Bookings repo)

1. **Settings → Environments → New environment** → name it `release-signing`.
   - Tick **Required reviewers** and add yourself (every release and rollback then waits for your approval).
   - Under **Environment secrets**, add `OSAM_SIGNING_KEY` = the private key.
2. **Settings → Secrets and variables → Actions**:
   - **Variables** tab: `OSAM_PUBLIC_KEY` = public key, `UPDATE_SERVER_URL` = `https://updates.osamweb.com`
   - **Secrets** tab: `UPDATE_SERVER_ADMIN_TOKEN` = admin token
3. **Read-only token for the server**: your profile → Settings → Developer settings → Personal access tokens → **Fine-grained tokens** → Generate new token.
   - Repository access: *Only select repositories* → OSAM-Bookings
   - Permissions → Repository → **Contents: Read-only** (nothing else)
   - Note the expiry date; when it runs out, make a new one and paste it into step 3.4 below.

## 3. cPanel (the account for updates.osamweb.com)

1. **File Manager** → in your home folder create a folder `osam-update-server` (not inside `public_html`). Open it, **Upload** `osam-update-server.zip`, then right-click it → **Extract**. You should see `app.js` and `package.json` directly in the folder.
2. **Setup Node.js App → Create Application**
   - Node.js version: 20 (or the highest offered, at least 18)
   - Application mode: Production
   - Application root: `osam-update-server`
   - Application URL: `updates.osamweb.com`
   - Application startup file: `app.js`
3. Click **Create**. Ignore "Run NPM Install" — there are no packages.
4. In the same screen, **Environment variables → Add variable** for each:

   | Name | Value |
   |---|---|
   | `GITHUB_REPO` | `jakobosam/OSAM-Bookings` |
   | `GITHUB_TOKEN` | the fine-grained token from 2.3 |
   | `PUBLIC_KEY` | public key |
   | `ADMIN_TOKEN` | admin token |
   | `PUBLIC_URL` | `https://updates.osamweb.com` |
   | `WEBHOOK_SECRET` | *(optional)* webhook secret |
   | `UPDATE_KEY` | *(optional)* only if sites have `OSB_UPDATE_KEY` in wp-config.php |

   **Save**, then **Restart**.
5. **SSL/TLS Status** → make sure `updates.osamweb.com` has a certificate (Run AutoSSL if not).
6. Open `https://updates.osamweb.com/` in a browser. You should see `{"ok":true,"service":"osam-update-server",…}`.

## 4. Optional: GitHub webhook

Repo → Settings → Webhooks → Add webhook: Payload URL `https://updates.osamweb.com/v1/webhook/github`, content type `application/json`, Secret = webhook secret, *Let me select individual events* → **Releases** only. Without it, the server still picks up new releases within 15 minutes, and the release workflow also pings it.

## 5. First release (2.16.0)

1. Repo → **Releases → Draft a new release** → Choose a tag → type `v2.16.0` → Create new tag → **Publish release**.
2. **Actions** tab → the *Release* run → approve the `release-signing` step. It builds, signs and attaches `osam-bookings-2.16.0.zip` and `release.json` to the release.
3. Open `https://updates.osamweb.com/v1/status?token=ADMIN_TOKEN` — 2.16.0 should be listed under `releases`. (If not, open `/v1/sync?token=ADMIN_TOKEN` and read the message.)
4. **Migration**: sites on 2.15.x still ask connect.osamweb.com. Download the signed zip from the GitHub release and upload it once to the connector's Updates tab. Once `/v1/status` shows every site on 2.16.0 (`site_versions`), delete all releases from the connector's Updates tab.

Test on one site first: update it, then in **OSAM Bookings → Settings → General → Updates** click *Check for updates now* — it should say you have the latest version.

## Day to day

- **New version**: bump `Version:` and `OSB_VERSION` in `osam-bookings.php` (and the readme changelog), then publish a release with tag `vX.Y.Z`. Tags with a dash (`v2.18.0-beta.1`) are beta-only.
- **OSAM Turnstile**: its files are in the `osam-turnstile/` folder. Bump `Version:` in `osam-turnstile/osam-turnstile.php` (and its readme changelog), then publish a release with tag `osam-turnstile-vX.Y.Z` (workflow *Release OSAM Turnstile*). Same key, approval and server.
- **Rollback**: Actions → *Rollback* → Run workflow → plugin, target version, versions to withdraw (blank = everything newer), sites (`*` or domains) → approve. Affected sites move back within about an hour and never install the withdrawn versions again. To fix, release a new, higher version.
- **Per-site settings**: File Manager → `osam-update-server/data/config.json` (no restart needed):

```json
{
  "default_channel": "stable",
  "allowed_sites": [],
  "minimum_version": { "osam-bookings": "", "osam-turnstile": "" },
  "sites": {
    "staging.example.co.uk": { "channel": "beta" },
    "fragile-client.co.uk": { "pin": "2.16.0" },
    "other-client.co.uk": { "pin": { "osam-bookings": "2.16.0", "osam-turnstile": "1.3.0" } }
  }
}
```

  - `allowed_sites`: empty = any site; otherwise only these domains get updates (both plugins).
  - `channel`: applies to both plugins.
  - `minimum_version`: per plugin; sites below it are updated straight away, even with auto-updates off.
  - `pin`: keep a site on one version (only moves it forward; going back needs a rollback). A plain version pins OSAM Bookings only; use `{ "plugin": "version" }` to pin either plugin.
  - `/v1/status` → `site_versions` counts sites per plugin and version.

## Dashboard

Open `https://updates.osamweb.com/dashboard` and log in with the **ADMIN_TOKEN** (works on a phone too).

- **Overview** — problem-log totals for 7 days (links to Errors), sites by plugin (Bookings only / Turnstile only / both), how many sites (seen in the last 24 hours / 7 days / quiet for more than 7 days), versions in use per plugin and how many are on the latest stable or beta, channels, every site with a problem and why, the last GitHub sync with a **Sync now** button, and the latest releases.
- **Sites** — tabs for **All**, **OSAM Bookings**, **OSAM Turnstile** and **Both plugins**, with a separate table per plugin (a site with both shows "Also: …" under its name). Each row: version with *latest* / *behind* / *ahead* (and *pinned*), channel, WordPress, PHP, last seen and a health badge for that plugin (OK / Check / Problem / Quiet). Search, filter by health, channel or version state, and click a column heading to sort.
  - *latest*: on the newest release for the site's channel (or its pin). A beta-channel site on the newest beta is *latest*, not behind.
  - *ahead*: newer than that — e.g. a beta on a stable-channel site, or a release that is still waiting for signing approval. Nothing to do.
  - *behind*: older; the site will be offered the update.
- **Errors** — the problem log from every site for the last 30 days, newest first (payments, calendar sync, emails, Zoom, subscription, updates, scheduled jobs): when, site, error or warning, area, message, how many times. Search and filter by level or area. Each site page has the same log for that site.
- **Site page** (click a site) — everything the site reported, what needs attention, the version timeline (when each version was first seen), update results (from → to, OK or failed, the error), the last 30 daily summaries, recent downloads and the site's `config.json` entry (read-only).
- **Releases** — every release per plugin: channel, published date, how many sites run it, download count; plus the rollbacks.
- **Config** — `config.json` (read-only; edit it in File Manager as before), which environment variables are set (not their values), and data file sizes.

A site shows as a **Problem** when it reports errors in the last 24 hours, its scheduled jobs are late or have never run, an update failed, its OSAM subscription is suspended, or the Stripe webhook isn't set up; **Check** for an overdue subscription, Stripe in test mode on a live site, WP_DEBUG on a live site, calendar connections in error, an update-check error or no report for 3 days; **Quiet** when it hasn't been in touch for 7 days. A site counts as *not live* (so Stripe test mode is fine) when its host looks like `staging.`/`dev.`/`test.`…, WordPress says it's a staging/development site, or you add `"live": false` to its entry in `config.json` (`"live": true` overrides the guess).

**Security**: the token is swapped for a signed session cookie (HttpOnly, Secure, SameSite=Strict) that lasts 12 hours; changing ADMIN_TOKEN logs everyone out. Five wrong tokens lock that IP address out for 15 minutes. Every form has a CSRF token, every value from a site is HTML-escaped, and pages are sent with a strict Content-Security-Policy (per-page nonce), no-store and anti-framing headers. The dashboard needs HTTPS (the Secure cookie) — `http://localhost` works for testing.

## What sites send (status reports)

OSAM Bookings 2.17+ sends a small JSON report once a day (`POST /v1/report`, about 2 KB), and OSAM Turnstile a lighter one. They contain only operational information:

- versions: plugin, WordPress, PHP, database (MySQL / MariaDB / SQLite and version), OSAM database schema
- site: title, home URL, multisite, language, timezone, active theme, number of active plugins, memory limits, web server, HTTPS, WP_DEBUG, DISABLE_WP_CRON, WordPress environment type
- updates: auto-updates on/off, last required-version install (from → to, ok, error), withdrawn versions, last update check
- scheduled jobs: state (ok / server / visitor / late / never), last run, whether a server cron job runs them
- features switched on; payment methods; Stripe live/test and whether its webhook is set up; Zoom connected; calendar connections and how many are in error; subscription linked and its status
- problem-log counts for 24 hours and 7 days by area, and (from 2.17.0) the newest 25 problem-log entries from the last 14 days, with personal details removed on the site first: email addresses become [email], anything in quotes (such as an email subject that holds a name) becomes “…”, and phone or card numbers (9+ digits) become [number]; only id-style details (booking id, gateway, calendar connection) are kept
- booking counts: total, created in the last 30 days, upcoming

Never customer names, emails, phone numbers or booking details. After each update of the plugin (or a failed required install) the site also sends `POST /v1/event` with from → to, ok/failed and the error.

**To switch it off on a site**: add `define( 'OSB_REPORT', false );` to its `wp-config.php`. Updates keep working; the site just stops sending reports (it still appears in the dashboard from its hourly update checks). Sites with `OSB_UPDATES` false send nothing at all.

The server treats reports as untrusted: bodies over 48 KB are refused, every value is trimmed (strings to 300 characters, limited nesting, keys and list lengths), a stored report is at most 16 KB (the problem-log entries are kept separately, 100 per site), each site can send 6 reports per plugin and 20 update results per hour, and each IP 120 requests per 10 minutes. If `UPDATE_KEY` is set, reports need it too. `allowed_sites` applies as well. Without UPDATE_KEY anyone could send a report pretending to be a site — it can only change what the dashboard shows for that site, never what is installed anywhere.

Data files stay bounded: at most `MAX_SITES` sites (default 1000; the longest-silent are dropped first), 40 version-timeline entries per plugin, 50 update results, 30 daily summaries per plugin and 20 downloads per site.

## Updating this server (e.g. to 1.3.0)

1. **File Manager** → `osam-update-server` → **Upload** the new `osam-update-server.zip` → right-click → **Extract** → overwrite. You should see the new `app.js`, `package.json` and `README.md` in the folder. Don't delete the `data` folder — it holds the cached releases, rollbacks, site list, reports and `config.json`.
2. **Setup Node.js App** → the app → **Restart**. Nothing to install; no new environment variables are needed (optional: `MAX_SITES`).
3. Check `https://updates.osamweb.com/` shows `"version":"1.3.0"`, then open `https://updates.osamweb.com/dashboard` and log in with ADMIN_TOKEN.
4. Sites start sending reports once they run OSAM Bookings 2.17 (Turnstile with its next release); until then the dashboard shows them from their hourly update checks.

## Endpoints

| Route | Who | What |
|---|---|---|
| `GET /v1/update` | sites | latest release for the site (plus a signed rollback when one applies) |
| `GET /v1/download/{slug}/{version}.zip` | sites | zip, via a link valid for 3 days and tied to the site (counted per release) |
| `POST /v1/report` | sites (+ UPDATE_KEY if set) | daily status report: `{schema, slug, site, version, key?, report: {…}}` |
| `POST /v1/event` | sites (+ UPDATE_KEY if set) | update result: `{slug, site, version, key?, event: {type: "update", from, to, ok, error, forced, time}}` |
| `POST /v1/webhook/github` | GitHub | triggers a sync (HMAC-checked) |
| `GET /v1/sync`, `GET /v1/status` | admin token | sync now / releases, rollbacks, sites (unchanged) |
| `POST /v1/rollbacks` | admin token + signature | stores a signed rollback (sent by the Rollback workflow) |
| `GET /v1/dashboard/overview`, `/v1/dashboard/sites`, `/v1/dashboard/sites/{host}`, `/v1/dashboard/releases`, `/v1/dashboard/errors`, `/v1/dashboard/config` | admin token or dashboard session | the dashboard's data as JSON |
| `GET /dashboard…`, `POST /dashboard/login`, `/dashboard/logout`, `/dashboard/sync` | ADMIN_TOKEN login | the dashboard |

New files in `data/`: `reports.json` (latest report per site and plugin), `history.json` (version timeline, update results, daily summaries, downloads per site), `downloads.json` (download counts per release).

Tests (for developers): `node --test test/*.test.js`.
