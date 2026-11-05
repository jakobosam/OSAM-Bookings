# OSAM Bookings

WordPress booking plugin built by OSAM for client sites: services, events, multi-session courses, restaurant tables and bookable resources, with staff calendars, Google & Outlook sync, online payments, invoices and fully templated emails.

**Current version:** 2.16.0 · Requires WordPress 6.2+ and PHP 7.4+

## Features

- **Appointments** – services with staff, working hours, buffers, solo or team mode, repeat bookings and customer rescheduling.
- **Events & courses** – ticketed events and multi-session courses with intakes, a register and waitlists.
- **Resources** – rooms, fields, halls or equipment by the slot, hour, half day or day; shared places or sole use; seasonal opening hours (including UK clock changes) and maintenance times.
- **Restaurant tables** – party sizes, floor plan, table combinations, service periods, deposits and card holds.
- **Central calendar** – every booking type plus maintenance, closures, days off and synced busy time, with a detail panel and quick actions.
- **Payments** – Stripe, PayPal, WooCommerce and pay on site.
- **Invoices** – numbered PDF invoices on every payment, VAT support, credit notes, CSV/ZIP export and customer downloads.
- **Extras** – add-ons, bundles, discount codes and gift vouchers, access codes, Zoom/Meet/Teams links, customer portal, reports and GDPR tools.
- **Integrations** – shortcodes, a native Elementor widget, signed private updates via updates.osamweb.com and WHMCS licence checks.

## Installing on a site

1. Download the repository as a ZIP (**Code → Download ZIP**) or use a packaged release zip.
2. Make sure the zip contains a single `osam-bookings/` folder with `osam-bookings.php` inside it (rename the folder if GitHub has called it `OSAM-Bookings-main`).
3. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip, install and activate.

Full setup notes, shortcodes and the changelog are in [`readme.txt`](readme.txt). WHMCS licence integration is described in [`WHMCS-INTEGRATION.md`](WHMCS-INTEGRATION.md).

## Repository layout

```
osam-bookings.php      Plugin bootstrap (version, constants, autoloader)
includes/              Core classes (bookings, availability, payments, invoices, calendar…)
includes/admin/        Admin screens
includes/gateways/     Stripe, PayPal, WooCommerce and on-site gateways
includes/elementor/    Elementor widget
assets/                CSS, JavaScript, images and vendor libraries (FullCalendar)
languages/             Translation files
uninstall.php          Clean-up when the plugin is deleted (only if opted in)
.github/               Release (build + sign) and Rollback workflows
tools/keygen.html      Makes the release-signing keys in your browser
update-server/         Node.js app for updates.osamweb.com (see update-server/README.md)
```

## Releases and updates

Publishing a GitHub release tagged `vX.Y.Z` builds the plugin zip, signs it and makes it available to client sites through updates.osamweb.com. Setup and day-to-day use are in [`update-server/README.md`](update-server/README.md). `tools/`, `update-server/` and the Markdown docs are left out of the plugin zip (see `.distignore`).

## Licence

GPL-2.0-or-later. FullCalendar is bundled under its MIT licence (`assets/vendor/fullcalendar/LICENSE.md`).
