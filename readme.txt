=== OSAM Bookings ===
Contributors: osam
Tags: booking, appointments, events, courses, stripe, paypal, woocommerce, zoom
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.16.0
License: GPLv2 or later

Booking system for services (appointments, group sessions), events and multi-session courses, with Stripe, PayPal, WooCommerce and pay-on-site payments.

== Quick setup ==

The setup wizard opens after activation (or Bookings → Settings → Run setup wizard). It asks whether you work solo or as a team, your hours, services, payments, emails and which page to use.

Solo mode ("Just me"): no staff to manage — one diary, no "choose staff" step, no staff emails. Bookings → Working hours sets your week and holidays. Switch in Settings → General at any time.

Admin bookings: Bookings → Add booking (or click an empty slot in the Calendar) lets you book any service at any time, including outside hours, on days off or over another booking. Live warnings explain any clash; nothing is blocked. Rescheduling works the same way.

Manual setup (instead of the wizard):

1. Upload the zip under Plugins → Add New → Upload and activate. A "Book Online" page with [osam_booking] is created.
2. Bookings → Staff & hours: add staff (people, rooms or equipment), set weekly hours and days off. Add business closures at the bottom.
3. Bookings → Services: add services and tick who provides each one.
4. Bookings → Events: add any ticketed events. Bookings → Courses: add courses and their intakes (see below).
5. Bookings → Settings → Payments: enable on-site, Stripe, PayPal and/or WooCommerce.
6. Settings → Emails: set the From address (a domain mailbox with SPF/DKIM) and edit templates; send a test.

== Features on/off ==

Settings → Features (the first settings tab) has a switch for each optional part: Events, Courses, Waitlist, Discount codes & vouchers, Customer rescheduling, After-visit emails, Calendar sync, Online meeting links, Customers and Reports. Anything switched off disappears from the menus, settings, booking form, shortcodes page, Elementor widget and manual booking form. Nothing is deleted — switching it back on brings everything back. Switching on Calendar sync or Online meetings takes you to its setup.

== Online meetings (fixed link, Zoom, Google Meet, Teams) ==

On any service, event or course choose "Online" and how the link is made:
* The same link for everyone — paste a meeting room, webinar or livestream URL.
* Zoom — Settings → Online meetings: create a Server-to-Server OAuth app in the Zoom Marketplace (scopes to create, update and delete meetings), paste the Account ID, Client ID and secret, then "Test Zoom connection". Each appointment gets its own meeting (moved when rescheduled, deleted when cancelled); an event gets one shared meeting (moved when the event moves); a course intake gets one recurring meeting so the link is the same every session.
* Google Meet / Microsoft Teams (services and events) — the link is added to the entry calendar sync makes in the staff member's own calendar, so that person must be connected with "Add bookings to my calendar" on. Teams needs a work or school Microsoft 365 account.
The link is made before the confirmation email (or when an online payment completes) and appears as a "Join online" button in emails, in the calendar invite, on the customer's booking page and on the booking in the admin, where it can also be changed by hand. Problems (e.g. wrong Zoom details) never stop the booking; they're listed under Settings → Online meetings and on the booking.

== Customers & GDPR ==

Bookings → Customers lists everyone who has booked, matched by email: bookings, visits, no-shows, cancellations, total spent, last visit and next booking, with search, tag filter, sorting and CSV export. Each customer has a profile with their booking history, private notes and tags, and a "New booking" button that pre-fills their details.
GDPR: "Download their data" (JSON) and "Erase their personal data" on the profile, and the plugin is registered with WordPress's Tools → Export / Erase Personal Data. Erasing removes names, contact details, form answers, notes, waitlist entries and gateway data from past bookings while keeping dates and amounts for your accounts; upcoming bookings are kept until you cancel them. Suggested privacy-policy text is added under Settings → Privacy.

== Reports ==

Bookings → Reports: pick a range (last 7/30 days, this/last month, next 30 days, this year or custom) and count by appointment date or date booked. Shows paid revenue (and what's still to pay), bookings, how full the diary was (appointment time ÷ working hours), customers and new customers — each compared with the previous period — plus cancellation and no-show rates, event and course fill rates, discounts, refunds, a bookings-per-day chart, revenue by service/event/course, by staff member, busiest days and start times, top customers and discount-code use. Export CSV.

== Calendar sync (Google & Microsoft) ==

Two-way: bookings, hosted events and course sessions are written into each person's calendar and kept up to date; busy times in the calendars they choose block online booking (admins see a warning instead). Entries this plugin adds never block themselves; "free" entries are ignored.

Easy setup (default): sites use OSAM's shared app through the OSAM Calendar Connector (https://connect.osamweb.com — change under Settings → Calendar sync → Connector address, or define OSB_CONNECTOR_URL in wp-config.php). The site's domain must be on the connector's allowed list. Then:
1. Staff & hours → person (or Working hours in solo mode) → Calendar sync → Connect Google Calendar / Connect Microsoft 365.
2. Sign in and allow access. Their main calendar is chosen and both directions are switched on automatically; they can change the calendar and the busy calendars afterwards.

Own app (advanced): fill in a Google and/or Microsoft app under Settings → Calendar sync → Advanced (setup steps are on that page). When filled in, the site's own app is used instead of the connector. Google: publish the OAuth app (apps left in "Testing" disconnect everyone after 7 days). Microsoft: client secrets expire — renew before the date shown in Entra.

Sync runs every 5 minutes on WP-Cron (plus a few seconds after any booking change); busy times refresh about every 10 minutes. A connection whose access is revoked shows "Needs reconnecting" and a notice on the plugin screens. Tokens are stored encrypted with the site's salts.

== Courses ==

A course is sold for one fee and books the customer onto every session of an intake.

* Course — name, description, location, tutor, fee, places per intake, max per booking, payment methods.
* Intake — one run of the course with its own name (required, e.g. "January evenings") and session dates. Places and fee can be overridden per intake. Duplicate an intake to create the next run a week after it ends.
* Sessions — any dates and times. "Repeat" fills in weekly, fortnightly, daily, weekday or monthly sessions; any single session can then be moved, given its own location, tutor or note, or removed.
* Enrolment closes when the first session starts (no late joining) or when the intake is full.
* Sessions block the tutor's appointment diary (in solo mode, tick "Block my appointment diary").
* Emails list every date ({schedule}) and attach one calendar file with all sessions. A reminder goes out before each session ("Customer — course session reminder"). Changing dates on an intake with bookings can email everyone the new schedule ("Customer — course dates changed").
* Class list & register: tick attendance per session, mark a whole session present, export to CSV.

== Rescheduling, waitlists, discount codes & questions ==

* Customer rescheduling — customers can move an appointment from the link in their email to any free time with the same person, up to a cut-off (Settings → General, default 24 hours). They and you are emailed, the calendar invite updates and any Zoom meeting moves. Admin moves can email the customer too.
* Waitlist — when an event or course intake is full, customers can join a waitlist. When a place frees up, the next person is emailed a link and the place is held for them (default 24 hours) before it's offered to the next. Bookings → Waitlist shows who's waiting and lets you offer a place or remove someone.
* Discount codes & gift vouchers — Bookings → Codes & vouchers. Codes take a percentage or fixed amount off, optionally limited to certain services/events/courses, a minimum spend, a date range, a number of uses, once per customer or first visit only. Gift vouchers have a balance that is used up across bookings and returned if a booking is cancelled. Vouchers are issued by you (they're not sold online). A code box appears on the booking form whenever an active code exists.
* Per-service questions — any booking-form field can be shown for all bookings or only for chosen services, events or courses (Settings → Form fields → "Ask this for").

== Importing from a spreadsheet ==

Services, Events and Courses each have Import and Export buttons. Import takes a CSV file (Excel: File → Save As → CSV UTF-8), shows what will be added, updated or needs fixing line by line, and only changes anything when you confirm.
* Download the template from the import screen, or Export what you have, edit it and import it back.
* Rows match existing items by ID, otherwise by name (events: name and date). On an update a blank cell keeps the current value. Switch off "Update … that already exist" to only add new ones.
* Dates as 2026-11-14 or 14/11/2026 (UK order); times as 18:00, 6pm or 6.30pm; prices may include £ and commas.
* Courses: one row per session, with the course and intake names repeated on each row. Course details are read from the first row that has them. An intake that already has bookings keeps its dates (change those on the intake page so customers are emailed).
* Staff are matched by name (services: several names separated by |).

== Invoices & credit notes ==

Switch on under Settings → Features → Invoices (on by default), then fill in Settings → Invoices: business name, address, logo, company number, VAT and numbering.

* Every completed payment gets a numbered PDF invoice (INV-0001, INV-0002…, no gaps). Online payments (Stripe, PayPal, WooCommerce) are invoiced the moment the payment lands, so the invoice is attached to the confirmation email. Bookings you mark as paid later (e.g. pay on arrival) get an invoice emailed on its own ("Customer — invoice" template). Restaurant no-show fees are invoiced too. A repeat booking paid in one go gets one invoice listing every date.
* VAT: "Not VAT registered" (default) shows totals only. "VAT registered — prices include VAT" shows each line ex VAT, the VAT at your rate and the total, with your VAT number. Prices charged never change.
* Refunds: issued invoices are never edited. Setting a booking's payment to Refunded (or a WooCommerce refund) issues a credit note (CN-0001…) for whatever is left on the invoice. Partial credit notes can be issued from the invoice screen with a reason. The money itself is refunded in Stripe/PayPal/Woo as before.
* Bookings → Invoices: search by number, name, email, booking ref or payment ref; filter by invoices/credit notes, status, payment method and date (this/last month, quarter, year, UK tax year or custom). Totals for invoiced, credited, net and VAT. Export CSV for your accountant, or download the PDFs as a .zip (all matching, or ticked ones).
* Each invoice: PDF preview, download, email to the customer (or anyone), edit the "Bill to" (company name, address) and add a note such as a PO number. Each booking shows its invoices; bookings paid before 2.13 can be invoiced with "Create invoice".
* Customers get a "Download invoice" button on their booking page and in My bookings (private link). Placeholders: {invoice_number}, {invoice_total}, {invoice_date}, {invoice_link}, {invoice_button}, {credit_note_number}, {credit_note_total}, {credit_note_reason}.
* Business details are copied onto each document when issued, so later changes don't alter old invoices. Invoices are kept when a customer's personal data is erased, as they're accounting records.
* PDFs are made by the plugin itself (no extra libraries), so they work on any host. Hooks: osb_create_invoice, osb_invoice_booking_lines, osb_invoice_insert_data, osb_invoice_pdf, osb_invoice_email_keys, osb_invoice_created, osb_credit_note_created.

== Shortcode ==

[osam_booking]                          services, events and courses (tabs)
[osam_booking type="services"]          services only
[osam_booking type="events"]            events only
[osam_booking service="3"]              one service (skips the service step)
[osam_booking service="3,4" staff="2"]  limit services, fix staff
[osam_booking category="Massage"]       one category
[osam_booking event="7"]                one event
[osam_booking type="courses"]           courses only (also [osam_courses])
[osam_booking course="4"]               one course's open intakes (also [osam_course id="4"])
Display: design="card|flat|minimal" layout="list|grid" columns="2|3|4" buttons="rounded|pill|square" density="comfortable|compact" accent="#e64980"
Show/hide (yes|no): images, descriptions, durations, prices, filter, progress, summary, seats_left
Bookings → Shortcodes lists ready-made codes for every service, event and staff member, with a builder and live preview.

Elementor: drag in the "OSAM Booking Form" widget for the same options plus colour, typography, spacing and layout controls.

== Payments ==

* Pay on site — bookings confirmed immediately or held for approval (setting).
* Stripe — hosted Stripe Checkout (cards, Apple/Google Pay, Link etc. as enabled in your Stripe dashboard). Add a secret key (restricted keys work) and a webhook to /wp-json/osb/v1/webhooks/stripe with checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed and checkout.session.expired.
* PayPal — Orders v2 REST API. Create a REST app at developer.paypal.com and paste the client ID and secret. Sandbox mode available.
* WooCommerce — sends the customer to the Woo checkout so any Woo gateway can be used. The booking confirms when the order reaches Processing or Completed, and cancels if the order is cancelled, failed or refunded. HPOS compatible.

Online bookings hold the slot as "Awaiting payment" until paid. Unpaid holds are released after the hold time (minimum 35 minutes). Each service or event can limit which methods are offered.

== Emails ==

Customer: received (pending), confirmed, rescheduled, cancelled, reminder, after-visit thank you / review request, waitlist place offered, course session reminder, course dates changed. Admin and staff: new, rescheduled, cancelled. All editable with placeholders and an optional .ics calendar attachment.

Settings → Emails has a visual designer with a live preview: logo (upload and width), header style (colour band, clean or centred), six ready-made themes, every colour (accent, header, buttons, page, card, panels, text, muted, footer), font and corner rounding. Templates use building blocks such as {details_card}, {manage_button}, {status_badge}, {schedule}, {meeting_button} and {review_button}; each template has Preview and Restore default. Templates you never edited are upgraded to the new designs automatically; edited ones are left as they are.

After-visit email: sent a set number of hours after the appointment ends (default 3) to visits that were confirmed or completed, with an optional "Leave a review" button (Settings → Emails → After the visit). Off by default. Reminders and hold expiry run on WP-Cron every 5 minutes — on low-traffic sites add a real cron job:
*/5 * * * * wget -q -O - https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
and define('DISABLE_WP_CRON', true); in wp-config.php.

== Customising ==

* Settings → Booking form: add/reorder fields (text, email, phone, paragraph, number, date, dropdown, radio, checkbox), half/full width.
* Settings → Appearance & text: colours, radius, every label on the form, custom CSS.
* CSS variables on .osb-booking: --osb-accent, --osb-on-accent, --osb-text, --osb-muted, --osb-bg, --osb-border, --osb-soft, --osb-radius.

Developer hooks (selection):
Filters — osb_payment_gateways, osb_form_fields, osb_validate_fields, osb_booking_amount, osb_booking_insert_data, osb_day_slots, osb_working_ranges, osb_resolve_any_staff, osb_service_price, osb_paid_status, osb_customer_can_cancel, osb_email_placeholders, osb_email_args, osb_email_html, osb_send_email, osb_ics_lines, osb_stripe_session_params, osb_paypal_order_body, osb_shortcode_config, osb_rest_services, osb_rest_events, osb_rest_courses, osb_course_price, osb_course_closed_reason, osb_attendance_statuses, osb_feature_enabled, osb_calendar_items, osb_customer_can_reschedule, osb_send_followup, osb_meeting_pre_create, osb_zoom_meeting_body.
Actions — osb_booking_created, osb_booking_status_changed, osb_booking_paid, osb_booking_notify, osb_booking_cancelled_by_customer, osb_booking_admin_updated, osb_booking_deleted, osb_email_sent, osb_course_saved, osb_course_intake_saved, osb_course_schedule_changed, osb_course_register_saved, osb_calendar_connected, osb_calendar_synced, osb_booking_rescheduled, osb_meeting_created, osb_customer_erased, osb_settings_saved.

Custom gateway: extend OSB_Gateway (process(), handle_return(), settings_fields()) and add the class name via the osb_payment_gateways filter.

== Notes ==

* Times use the site timezone (Settings → General). Set it to a city (e.g. London) so BST changes are handled.
* Data is kept on uninstall unless "Delete all booking data" is ticked in Settings → General.

= Booking more than one place =

When a customer books more than one place on an event, course or group service, the details step opens a "Place 2", "Place 3"... block for each extra person. Ticking "Use my details for every place" hides them and books every place under the customer's details. Name is always asked for each place; email is asked by default. To ask another question for each place, open Settings → Booking form and switch on "Ask for each place" for that field. Attendee names show on the booking, the course register, in emails (placeholder {attendees}) and in CSV exports.

= Updates =

New versions come from the OSAM Connector (connect.osamweb.com) and show under Dashboard → Updates like any other plugin, with "View details" and the changelog. "Install updates automatically" (Settings → General → Updates, on by default) lets WordPress install them by itself. Only sites on the connector's allowed list get updates. If the connector has an update key set, add define( 'OSB_UPDATE_KEY', '…' ); to wp-config.php. To switch updates off completely, add define( 'OSB_UPDATES', false );.

= Custom emails =

Bookings → Settings → Custom emails. Create as many extra emails as you like, each with:

* When: straight after a booking is made, confirmed, paid, moved, cancelled, completed or marked no-show; a set time (minutes, hours, days or weeks) before the start, after the end or after it was booked; instead of one of the standard emails for matching bookings; or only by hand from a booking.
* Who: the customer, everyone else on a group booking, the staff member/host/tutor, the admin addresses, extra addresses, a BCC copy. Each person gets their own copy. Choose the reply-to (sender, customer, staff member or any address) and the from name.
* Which bookings: all, or chosen appointments, service categories, events and courses; staff members; booking statuses; payment method; paid/unpaid; first-time or returning customers; minimum places; with or without a discount code.
* Message: subject and HTML body with every placeholder plus {recipient_name}/{recipient_first_name}, the shared email design, optional calendar file, live preview using a matching booking, and "Send me a test".

Ready-made starting points: aftercare, how to prepare, welcome for first-timers, payment reminder, time to book again, staff brief. Scheduled emails only go to bookings from the moment they're switched on (no backlog) and each booking gets each email once.

Every email sent about a booking (standard and custom) is listed under "Emails sent" on the booking.

== Changelog ==

= 2.17.0-beta.2 =
* OSAM subscription: the status address is now always OSAM's billing (https://osamweb.com/portal/modules/addons/osam_client_bookings/status.php). It's shown on the Subscription tab but can't be changed there; an address saved on a site before is no longer used.

= 2.17.0-beta.1 =
* New: move to OSAM Bookings from Amelia. Services, staff, customers, events and every past and upcoming booking are read from Amelia's data on the same site, with times converted to your timezone. Amelia's email wording can be brought across too, with its placeholders translated.
* New: move from Wix Bookings, by uploading Wix's booking list and contacts CSV files or by connecting with a Wix API key (the key isn't kept afterwards).
* Imports show exactly what will be added, matched or left out before anything changes, never create duplicates if run again, and are silent: no emails, reminders, Zoom meetings or calendar entries for imported bookings. Large histories are imported in batches so nothing times out. Find it under Settings → Import, or from the setup wizard.
* Simpler Stripe setup: paste your Stripe Secret key and save. It's checked with Stripe straight away, live or test is worked out from the key, and payment confirmations (the webhook) are set up automatically — there's no webhook secret to copy any more. Clear messages if the wrong key is pasted, a "Check connection" button and a "Disconnect" button. Sites that already had a webhook secret pasted keep working.
* New "Pay now" button when money is still owed: in invoice emails, on the invoice PDF (clickable), on the customer's booking page and in My bookings. It opens a simple payment page with the amount due, lets the customer pay by Stripe, PayPal or WooCommerce, then marks the booking paid and emails the invoice. A booking that's already paid can't be paid again, and leaving checkout part-way never cancels a booking. Switch it off under Settings → Invoices.
* Tidier menus: the WordPress sidebar now shows one link per area (Dashboard, Bookings, Calendar, Customers, Invoices, Services & events, Working hours, Settings). Everything else is in the tabs at the top of each screen, and old links still work.
* Settings are grouped under Your business, Booking form, Connections and Advanced, with plainer names, and open on General instead of Features. Import now has its own tab under Settings.
* OSAM subscription: the connection details are tucked away once the site is linked.
* Zoom: meeting times are now always sent in UTC, so they're right for every WordPress timezone (including ones Zoom doesn't list and manual UTC offsets). Restoring a cancelled booking gets a fresh meeting; later dates of a paid repeat series get their meetings; deleting a booking, an event or a course intake deletes its Zoom meeting. Problems moving or cancelling a meeting are now shown on the booking and under Recent problems. "Test Zoom connection" now creates, moves and deletes a test meeting, so it checks every permission bookings need.
* Fixed: invoices could fail to be numbered on databases that don't support MySQL's GET_LOCK.

= 2.16.0 =
* Updates now come from OSAM's new update server (updates.osamweb.com) instead of connect.osamweb.com. Calendar sign-in and the subscription check still use the connector.
* Every update is now signed by OSAM. The plugin checks the signature before installing anything and refuses unsigned or altered zips, so updates stay safe even if an update server were compromised.
* Rollbacks are signed too, and only move sites that are on a withdrawn version. Withdrawn versions are remembered and never installed again.

= 2.15.0 =
* Central calendar (Bookings → Calendar): appointments, events, course sessions, resources and restaurant tables in one place, colour-coded, with month, week, day and list views.
* Show or hide each kind of booking, and switch on extra layers: resource maintenance, closures and days off (business, staff, resources, restaurant), and busy times from connected Google/Outlook calendars.
* Filter to one staff member's diary or one resource, include cancelled bookings, or search for a customer by name, email, phone or booking ref. Your choices are remembered.
* Events appear as one block with places filled (e.g. 12/20); course sessions show how many are enrolled.
* Click anything to open a detail panel: customer and contact details, form answers, places, add-ons, payment and invoices, access codes, staff notes and clashes. Confirm, cancel, mark paid, mark completed or no-show from the panel (optionally emailing the customer). Events and sessions list everyone booked — click a name to open their booking.
* Striped entries are pending or awaiting payment; a dot marks bookings not paid yet.

= 2.14.0 =
* Resources — seasonal opening hours: add hours for part of the year on top of the standard week. "Summer (clocks forward)" and "Winter (clocks back)" follow the UK clock changes automatically every year; you can also use the same dates every year (e.g. 1 May – 30 Sep) or specific dates once. Each resource shows which hours apply over the next 12 months, and the resource schedule names the hours in use.
* Resources — maintenance: set aside times for upkeep, either once (including over several days) or every week on chosen days, with an optional end date and staff note. These times can't be booked online, staff get a warning, saving warns about bookings that already clash, and the times show as hatched bars on the resource schedule.

= 2.13.0 =
* Invoices: a numbered PDF invoice for every payment, attached to the confirmation email, with VAT support, credit notes for refunds, a searchable and filterable Invoices screen, CSV and .zip export, and customer downloads from their booking page and My bookings.

= 2.12.0 =
* My bookings: a customer panel ([osam_my_bookings], page created automatically). Customers sign in with an emailed link or 6-digit code — no passwords — and see upcoming and past bookings, access codes and bundle balances, and can cancel, change times or cancel the rest of a repeat booking. Signed sessions last 30 days; the panel loads separately so page caching can't break it.
* Repeat bookings: customers can book appointments, resources and tables every week, fortnight or month in one go (Settings → Features, then Settings → General). Free dates are checked before paying; one payment and one confirmation cover them all; "cancel this and later dates" for customers and staff. Staff can repeat any booking, and events can be copied to later dates.
* Add-ons: extras on the booking form — per booking, per person, per hour or per day, tick box or quantity, optional compulsory fees — offered on chosen services, events, courses, resources or tables.
* Bundles: sell packs of sessions with a code ([osam_bundles] or a "Bundles" tab). Credits per booking or per place, expiry, limited to chosen items; credits come back on cancellation. Staff can give bundles, adjust credits and see each bundle's history.
* New emails: bundle code, sign-in link. Every manage button in emails now also links to My bookings.
* Fix: one-off jobs after an update now wait until WordPress has fully loaded.

= 2.11.0 =
* Access codes for resources: none, the same code every time (e.g. a key safe) or a new random code per booking. Shown in the confirmation, the email and the customer's booking page once the booking is confirmed — or released a set number of hours before the start and emailed then. Staff can resend or issue a new code from the booking.
* Unpaid online bookings never appear on the calendar, restaurant timeline, resource schedule, dashboard, bookings list or synced Google/Outlook calendars. The time is held while the customer pays; if payment fails, is abandoned or times out, the booking becomes "Payment not completed", the time is released and the customer gets an email.
* New standard emails: access code, payment not completed (customer), payment not completed (admin, off by default).
* Bookings → Emails now lists every standard email with when it's sent and an on/off switch, alongside your own. Five ready-made extra emails are added switched off. Any standard email can have a different version for some services, events or courses.
* New custom email trigger: an online payment doesn't go through.

= 2.10.0 =
* Redesigned booking form. It now sizes itself to the space it's placed in rather than the screen, so it no longer stretches in full-width sections and stacks neatly in narrow columns and sidebars.
* Calendar days are a fixed, compact size; time buttons no longer overflow; drop-downs (party size, length, area) only take the width they need.
* The form is protected from theme and page-builder button, input and select styles, so it looks the same on every site.
* Resource time slots show the start time with "until …" underneath, instead of a long range.
* New default colour is OSAM navy (#161A40). Sites that have set their own brand colour keep it.
* Admin rebranded with the OSAM colours and logo.

= 2.9.1 =
* Resources: fixed slots can now have several lengths (e.g. 30 minutes, 1 hour, 2 hours), each with its own price per place and price to book it out. Customers pick the length; the booking form shows each one's price.
* New "Slots start every" setting (15 min to 2 hours, or the shortest length), counted from opening time.
* Resources set up in 2.9.0 keep working as before and switch to the new list the next time they're saved.

= 2.9.0 =
* New: Resources (Settings → Features → Resources, off by default) for booking rooms, fields, halls, desks or equipment, with its own menu section, schedule and [osam_resources] shortcode (also an Elementor option and a tab in [osam_booking]).
* Each resource has places and a sharing rule: sole use, shared places, or either — customers can book places or book it out, and booking out is only offered when nobody else is booked in.
* Booked by fixed slots (optionally several in a row), a flexible length between a minimum and maximum, half days (morning/afternoon) or whole days (one or several in a row).
* Prices per place and for booking out — per slot or hour, per half day and per day. Own opening hours, closed dates, gaps before/after, notice and booking window per resource.
* Customers can book several resources at once when they're booked the same way (switchable).
* Resource schedule: one row per resource for the day, showing places used and book-outs, plus a staff booking form that warns about clashes but doesn't block them.
* Email placeholders {resources}, {booked_out} and {hire}; "Ask this for → Resources" on form fields.

= 2.8.0 =
* New: OSAM subscription link (Settings → Subscription). When OSAM's billing (WHMCS) reports the site suspended, new bookings are switched off and booking forms show a message; they switch back on by themselves once paid. Nothing is deleted.
* Two ways in: the site checks a status address hourly (every 10 minutes while overdue/suspended), and billing can call a signed webhook (/wp-json/osb/v1/licence/notify) for instant changes. Both directions are HMAC-signed with a per-site key.
* "Overdue" shows a warning to the site owner; "full" suspension also locks the booking admin screens and pauses reminders and other scheduled emails.
* Fail-safe: if billing can't be reached the last known status is kept, so an outage never switches a paying site off.
* Settings can be locked with OSB_LICENCE_KEY / OSB_LICENCE_SERVICE in wp-config.php (the status address is fixed to OSAM's billing; OSB_LICENCE_URL overrides it for testing only). See WHMCS-INTEGRATION.md for the billing side.

= 2.7.1 =
* New: OSAM can force an update or roll the plugin back from the connector. Required versions install by themselves, even with automatic updates off, and a withdrawn version that's reinstalled by hand is replaced again.
* Updates are now checked hourly instead of every 6 hours.

= 2.7.0 =
* New: restaurant table booking (Settings → Features → Restaurant tables, off by default). Guests pick party size, optional seating area, date and time; [osam_table_booking] shortcode and an Elementor option.
* New: dining areas and tables with a drag-and-drop floor-plan editor (shapes, sizes, rotation, min/max guests, online or staff-only, priority).
* New: table combinations for larger parties. A combination is only offered when all its tables are free; a single table that fits is always used first.
* New: service periods (days, first/last seating, interval), pacing (guests per seating and per period), areas per period and closed dates.
* New: table assignment setting — automatic best fit, or staff assign from the timeline. Online times are only offered when every overlapping booking can still be seated.
* New: time at the table by party size, plus reset time between parties.
* New: Service screen with a timeline (drag to move, reseat or resize) and a live floor plan with a time slider; phone bookings, walk-ins, Seated status, clash warnings.
* New: securing tables — nothing, a deposit (per guest or per booking, online methods only) or a Stripe card hold with a one-click no-show charge. Can apply only to parties above a size.
* New: large-party requests (sent as pending for you to confirm) or "please call us" above a set size.
* New: email placeholders {covers}, {tables} and {noshow_fee}; "Ask this for → All table bookings" on form fields.

= 2.6.0 =
* New: custom emails (Settings → Custom emails) with triggers, timing, recipients, conditions for services/categories/events/courses/staff and more, ready-made templates, live preview and test sends.
* New: a custom email can replace a standard email for chosen bookings, e.g. a different confirmation for one category.
* New: "Emails sent" history on each booking; custom emails in the booking's "Send an email" list.

= 2.5.2 =
* Fix: "Sync now" on a calendar could time out on shared hosting (503 Service Unavailable) when there were lots of bookings to send. It now sends what it can in about 12 seconds and finishes the rest in the background. Background syncs also work to a time limit and pick up where they left off.
* Google sign-in (own-app mode) asks for calendar.calendarlist.readonly instead of calendar.readonly.

= 2.5.1 =
* Test release to check private updates are working. No other changes.

= 2.5.0 =
* New: private updates from the OSAM Connector, with one-click and automatic updates (Settings → General → Updates).

= 2.4.0 =
* New: when booking more than one place (events, courses and group services), enter details for each person, or tick "Use my details for every place".
* New: "Ask for each place" switch per booking-form field.
* New: attendees shown on the booking screen, course register, emails ({attendees} placeholder) and CSV exports; included in GDPR export and cleared on erase.

= 2.3.0 =
* New: import services, events and courses (with intakes and sessions) from a CSV file, with a check-before-import preview, a template and matching Export.

= 2.2.2 =
* Settings → Calendar sync points to where each person connects their own calendar when none are connected yet.

= 2.2.1 =
* Clearer message when the connector address shows a web page instead of the connector (usually permalinks on the connector site).

= 2.2.0 =
* Changed: tidier admin. The top menu is now six sections — Dashboard, Bookings, Customers, Services & events, Staff & hours (Working hours in solo mode), Settings — with the pages inside each shown as tabs underneath. The WordPress menu follows the same order.
* Changed: the save bar floats at the bottom of the screen; service descriptions use a smaller editor; colour pickers and shortcode lists cleaned up; the shortcode attribute reference is folded away.
* Changed: the admin calendar script is bundled with the plugin (no CDN), and dates follow the site's language (e.g. "Mon 19 Oct").
* Fixed: today's schedule on the dashboard lists course sessions in time order with bookings.

= 2.1.0 =
* New: one-click calendar connections through the OSAM Calendar Connector — no Google Cloud or Microsoft Entra setup on each site. A site's own app details still work and take priority.

= 2.0.0 =
* New: Settings → Features — one place to switch every optional part on or off, including calendar sync.
* New: redesigned, fully styled emails with a visual designer (logo, header styles, themes, colours, font, rounding), live preview, per-template preview and restore.
* New: customers can reschedule their own appointments; admin moves can email the customer.
* New: discount codes and gift vouchers.
* New: waitlists for full events and course intakes, with automatic offers and held places.
* New: booking-form questions can be limited to particular services, events or courses.
* New: after-visit thank-you / review email.
* New: online services, events and courses with a fixed link, automatic Zoom meetings, or Google Meet / Teams links from the staff member's calendar.
* New: Customers — list, profiles with history, notes and tags, CSV export, GDPR export and erase (also via WordPress privacy tools).
* New: Reports — revenue, bookings, occupancy, cancellations, no-shows, busiest times, top customers and code use, compared with the previous period.
* Changed: the Events/Courses switches moved from Settings → General to Settings → Features. The admin menu wraps onto two lines when every section is switched on.

= 1.3.0 =
* New: two-way Google Calendar and Microsoft 365 / Outlook sync per staff member — bookings pushed, personal busy times block online booking.
* New: Settings → General → Features to switch Events and Courses off.
* Changed: intakes must have a name, entered first when adding an intake; duplicated intakes are named "… (copy)".
* Admin booking warnings now include clashes with the person's connected calendar.

= 1.2.0 =
* New: Courses — one fee books every session of an intake. Multiple intakes per course, schedule builder with repeat/shift helpers, per-session location, tutor and notes.
* New: enrolment closes automatically when the first session starts.
* New: class list and attendance register with CSV export.
* New: per-session reminder emails, "course dates changed" email, {schedule} placeholder and multi-session .ics.
* New: [osam_courses] / [osam_course id=""] shortcodes, course options in the shortcode builder and Elementor widget.
* Course sessions block the tutor's diary and show in the admin calendar and dashboard.
