=== OSAM Turnstile ===
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later

Cloudflare Turnstile spam protection for Elementor Pro forms, Divi contact forms and the WordPress login, using OSAM's shared Turnstile frame (built-form-worker). No settings.

== Setup ==

1. Remove any reCAPTCHA from the site's forms: Elementor reCAPTCHA fields, and in Divi set the Contact Form module's Spam Protection to off (the basic captcha can stay or go).
2. Upload and activate the plugin.
3. Updates come from updates.osamweb.com. Only install a zip from the plugin's GitHub release (osam-turnstile-X.zip): it carries OSAM's signing key, and a copy without it gets no updates.
4. If the site sends a Content-Security-Policy header, allow frame-src https://captcha.connect.osamweb.com.

Options (wp-config.php):

* define( 'OSAM_TS_LOGIN', false ); switches off the check on the login, registration and lost-password pages.
* define( 'OSAM_TS_AUTO_UPDATE', false ); switches off automatic updates (forced updates and rollbacks still happen).
* define( 'OSAM_TS_UPDATES', false ); switches off updates from OSAM completely.
* define( 'OSB_UPDATE_KEY', '…' ); if the update server has an update key (shared with OSAM Bookings).

== Changelog ==

= 1.3.0 =
* Updates now come from updates.osamweb.com instead of the OSAM Connector, and every release is signed: the plugin refuses any update zip that isn't signed by OSAM.
* Rollbacks need a signed instruction, and withdrawn versions are never installed again.

= 1.2.0 =
* New: Turnstile on Divi Contact Form modules.

= 1.1.0 =
* New: Turnstile on the WordPress login, registration and lost-password pages (and Elementor's Login widget).
* New: updates from the OSAM Connector, with automatic, forced and rolled-back updates.

= 1.0.0 =
* Turnstile on Elementor Pro forms.
