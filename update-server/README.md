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

## Updating this server

Upload the new `osam-update-server.zip` into the same folder, extract and overwrite, then **Restart** in Setup Node.js App. Don't delete the `data` folder — it holds the cached releases, rollbacks, site list and `config.json`.

## Endpoints

| Route | Who | What |
|---|---|---|
| `GET /v1/update` | sites | latest release for the site (plus a signed rollback when one applies) |
| `GET /v1/download/{slug}/{version}.zip` | sites | zip, via a link valid for 3 days and tied to the site |
| `POST /v1/webhook/github` | GitHub | triggers a sync (HMAC-checked) |
| `GET /v1/sync`, `GET /v1/status` | admin token | sync now / releases, rollbacks, sites |
| `POST /v1/rollbacks` | admin token + signature | stores a signed rollback (sent by the Rollback workflow) |

Tests (for developers): `node --test test/*.test.js`.
