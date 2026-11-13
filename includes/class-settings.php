<?php
/**
 * Settings storage and defaults.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * All plugin settings live in one option, merged over these defaults.
 */
class OSB_Settings {

	/**
	 * Cached merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		$admin_email = get_option( 'admin_email' );
		$site        = get_bloginfo( 'name' );

		$defaults = array(
			// General.
			'staff_mode'              => 'solo',
			'enable_events'           => 1,
			'enable_courses'          => 1,
			'enable_restaurant'       => 0,
			'enable_resources'        => 0,
			'res_multi'               => 1,

			// Subscription (WHMCS) status.
			'licence_key'              => '',
			'licence_url'              => '',
			'licence_service'          => '',
			'licence_customer_message' => '',
			'booking_page'            => 0,
			'portal_page'             => 0,

			// Restaurant table booking.
			'rest_assignment'         => 'auto',
			'rest_turn_times'         => array( array( 2, 90 ), array( 4, 120 ), array( 6, 150 ), array( 999, 180 ) ),
			'rest_turn_buffer'        => 0,
			'rest_min_party'          => 1,
			'rest_max_party_online'   => 8,
			'rest_large_party'        => 'request',
			'rest_max_party_request'  => 20,
			'rest_min_notice_minutes' => 60,
			'rest_max_advance_days'   => 60,
			'rest_area_choice'        => 1,
			'rest_confirm'            => 'auto',
			'rest_payment_mode'       => 'none',
			'rest_payment_min_party'  => 1,
			'rest_deposit_type'       => 'per_cover',
			'rest_deposit_amount'     => 10,
			'rest_noshow_fee'         => 20,
			'rest_payment_methods'    => '',
			'rest_policy_text'        => '',
			'rest_phone'              => '',
			'currency'                => 'GBP',
			'currency_position'       => 'before',
			'slot_interval'           => 15,
			'min_notice_hours'        => 2,
			'max_advance_days'        => 90,
			'pending_payment_minutes' => 30,
			'allow_customer_cancel'   => 1,
			'cancel_cutoff_hours'     => 24,
			'allow_customer_reschedule' => 1,
			'waitlist_enabled'        => 1,
			'enable_coupons'          => 1,
			'enable_addons'           => 1,
			'enable_bundles'          => 1,
			'enable_repeat'           => 0,
			'repeat_types'            => array( 'service', 'resource', 'table' ),
			'repeat_freqs'            => array( 'weekly', 'fortnightly', 'monthly' ),
			'repeat_max'              => 12,
			'enable_calendar_sync'    => 1,
			'cal_use_connector'       => 1,
			'cal_connector_url'       => '',
			'auto_update'             => 1,
			'enable_meetings'         => 1,
			'zoom_account_id'         => '',
			'zoom_client_id'          => '',
			'zoom_client_secret'      => '',
			'enable_customers'        => 1,
			'enable_reports'          => 1,
			'waitlist_hold_hours'     => 24,
			'reschedule_cutoff_hours' => 24,
			'any_staff_option'        => 1,
			'show_staff_step'         => 1,
			'terms_text'              => '',
			'delete_data_on_uninstall'=> 0,

			// Payments.
			'onsite_enabled'          => 1,
			'onsite_title'            => __( 'Pay on arrival', 'osam-bookings' ),
			'onsite_description'      => __( 'Pay in person when you arrive.', 'osam-bookings' ),
			'onsite_status'           => 'confirmed',
			'stripe_enabled'          => 0,
			'stripe_title'            => __( 'Card (Stripe)', 'osam-bookings' ),
			'stripe_mode'             => 'test',
			'stripe_test_secret'      => '',
			'stripe_live_secret'      => '',
			'stripe_webhook_secret'   => '', // Pasted by hand (before 2.17, or when automatic setup isn't possible).
			'stripe_live_account'        => '',
			'stripe_test_account'        => '',
			'stripe_live_webhook_id'     => '',
			'stripe_live_webhook_secret' => '',
			'stripe_live_webhook_error'  => '',
			'stripe_test_webhook_id'     => '',
			'stripe_test_webhook_secret' => '',
			'stripe_test_webhook_error'  => '',
			'paypal_enabled'          => 0,
			'paypal_title'            => __( 'PayPal', 'osam-bookings' ),
			'paypal_mode'             => 'sandbox',
			'paypal_client_id'        => '',
			'paypal_secret'           => '',
			'woo_enabled'             => 0,
			'woo_title'               => __( 'Checkout', 'osam-bookings' ),
			'free_status'             => 'confirmed',

			// Invoices.
			'enable_invoices'         => 1,
			'inv_pay_button'          => 1,
			'inv_business_name'       => $site,
			'inv_address'             => '',
			'inv_email'               => $admin_email,
			'inv_phone'               => '',
			'inv_website'             => preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) ),
			'inv_company_no'          => '',
			'inv_vat_mode'            => 'none',
			'inv_vat_rate'            => 20,
			'inv_vat_number'          => '',
			'inv_prefix'              => 'INV-',
			'cn_prefix'               => 'CN-',
			'inv_start'               => 1,
			'inv_padding'             => 4,
			'inv_logo_id'             => 0,
			'inv_accent'              => '',
			'inv_footer'              => __( 'Thank you for your business.', 'osam-bookings' ),
			'inv_bill_to_fields'      => '',
			'inv_attach'              => 1,
			'inv_attach_admin'        => 0,
			'inv_email_later'         => 1,
			'inv_email_credit'        => 1,
			'inv_customer_download'   => 1,

			// Calendar sync.
			'gcal_client_id'          => '',
			'gcal_client_secret'      => '',
			'mscal_client_id'         => '',
			'mscal_client_secret'     => '',
			'mscal_tenant'            => 'common',

			// Emails.
			'email_from_name'         => $site,
			'email_from_address'      => $admin_email,
			'email_admin_recipients'  => $admin_email,
			'email_logo'              => '',
			'email_logo_id'           => 0,
			'email_logo_width'        => 160,
			'email_header_style'      => 'band',
			'email_accent'            => '#161a40',
			'email_header_bg'         => '',
			'email_header_text'       => '#ffffff',
			'email_button_text'       => '#ffffff',
			'email_page_bg'           => '#f1f3f9',
			'email_card_bg'           => '#ffffff',
			'email_panel_bg'          => '#f5f7ff',
			'email_text'              => '#1f2933',
			'email_muted'             => '#667085',
			'email_footer_text'       => '#8a94a6',
			'email_font'              => 'system',
			'email_radius'            => 12,
			'email_footer_site_link'  => 1,
			'email_footer'            => $site,
			'email_attach_ics'        => 1,
			'reminder_hours'          => 24,
			'followup_hours'          => 3,
			'review_url'              => '',
			'review_button_label'     => '',
			'emails'                  => self::default_emails(),

			// Appearance.
			'primary_color'           => '#161a40',
			'text_color'              => '#1f2933',
			'background_color'        => '#ffffff',
			'border_radius'           => 10,
			'on_accent_color'         => '#ffffff',
			'design'                  => 'card',
			'service_layout'          => 'list',
			'service_columns'         => 2,
			'button_shape'            => 'rounded',
			'density'                 => 'comfortable',
			'font_family'             => 'inherit',
			'font_size'               => 15,
			'max_width'               => 0,
			'show_images'             => 1,
			'show_descriptions'       => 1,
			'show_duration'           => 1,
			'show_prices'             => 1,
			'show_progress'           => 1,
			'show_summary'            => 1,
			'show_category_filter'    => 1,
			'show_seats_left'         => 1,
			'custom_css'              => '',
			'labels'                  => self::default_labels(),

			// Form.
			'form_fields'             => self::default_fields(),
		);

		return apply_filters( 'osb_default_settings', $defaults );
	}

	/**
	 * Front-end text the site owner can rewrite.
	 *
	 * @return array
	 */
	public static function default_labels() {
		return array(
			'tab_services'   => __( 'Appointments', 'osam-bookings' ),
			'tab_events'     => __( 'Events', 'osam-bookings' ),
			'tab_courses'    => __( 'Courses', 'osam-bookings' ),
			'tab_tables'     => __( 'Book a table', 'osam-bookings' ),
			'tab_resources'  => __( 'Hire a space', 'osam-bookings' ),
			'step_resource'  => __( 'Choose what to book', 'osam-bookings' ),
			'step_res_when'  => __( 'When & how', 'osam-bookings' ),
			'res_places'     => __( 'Places', 'osam-bookings' ),
			'res_book_out'   => __( 'Book it out (sole use)', 'osam-bookings' ),
			'res_share'      => __( 'Book places', 'osam-bookings' ),
			'res_time'       => __( 'By time', 'osam-bookings' ),
			'res_half'       => __( 'Half day', 'osam-bookings' ),
			'res_day'        => __( 'Full day', 'osam-bookings' ),
			'res_morning'    => __( 'Morning', 'osam-bookings' ),
			'res_afternoon'  => __( 'Afternoon', 'osam-bookings' ),
			'res_length'     => __( 'How long', 'osam-bookings' ),
			'res_days'       => __( 'Days', 'osam-bookings' ),
			'no_resources'   => __( 'There’s nothing available to book right now.', 'osam-bookings' ),
			'res_none_free'  => __( 'Nothing free on this day for that choice.', 'osam-bookings' ),
			'res_until'      => __( 'until %s', 'osam-bookings' ),
			'step_table'     => __( 'Find a table', 'osam-bookings' ),
			'covers'         => __( 'Guests', 'osam-bookings' ),
			'table_item'     => __( 'Table for %d', 'osam-bookings' ),
			'area'           => __( 'Seating area', 'osam-bookings' ),
			'area_any'       => __( 'No preference', 'osam-bookings' ),
			'no_tables'      => __( 'Sorry, we have no tables for that many guests on this day.', 'osam-bookings' ),
			'large_party'    => __( 'For more than %d guests, please call us and we’ll do our best to fit you in.', 'osam-bookings' ),
			'request_note'   => __( 'This is a large-party request — we’ll check we can seat you and confirm by email.', 'osam-bookings' ),
			'deposit_note'   => __( 'A deposit secures your table and comes off your bill.', 'osam-bookings' ),
			'card_hold_note' => __( 'We’ll save your card but won’t charge it. A no-show fee of %s may be charged if you don’t arrive and don’t cancel in time.', 'osam-bookings' ),
			'step_service'   => __( 'Choose a service', 'osam-bookings' ),
			'step_staff'     => __( 'Choose who you would like', 'osam-bookings' ),
			'step_datetime'  => __( 'Pick a date & time', 'osam-bookings' ),
			'step_event'     => __( 'Choose an event', 'osam-bookings' ),
			'step_course'    => __( 'Choose a course', 'osam-bookings' ),
			'step_details'   => __( 'Your details', 'osam-bookings' ),
			'step_payment'   => __( 'Payment', 'osam-bookings' ),
			'any_staff'      => __( 'Any available', 'osam-bookings' ),
			'no_slots'       => __( 'No times available on this day.', 'osam-bookings' ),
			'no_events'      => __( 'There are no upcoming events.', 'osam-bookings' ),
			'no_courses'     => __( 'There are no courses open for enrolment right now.', 'osam-bookings' ),
			'sessions'       => __( '%d sessions', 'osam-bookings' ),
			'schedule'       => __( 'Course dates', 'osam-bookings' ),
			'show_dates'     => __( 'Show all dates', 'osam-bookings' ),
			'seats'          => __( 'Places', 'osam-bookings' ),
			'seats_left'     => __( '%d places left', 'osam-bookings' ),
			'sold_out'       => __( 'Fully booked', 'osam-bookings' ),
			'waitlist_join'  => __( 'Join the waitlist', 'osam-bookings' ),
			'waitlist_title' => __( 'Join the waitlist', 'osam-bookings' ),
			'waitlist_text'  => __( 'We’ll email you if a place comes up, and hold it for you for a while.', 'osam-bookings' ),
			'waitlist_done'  => __( 'You’re on the waitlist. We’ll email you if a place comes up.', 'osam-bookings' ),
			'next'           => __( 'Continue', 'osam-bookings' ),
			'back'           => __( 'Back', 'osam-bookings' ),
			'book'           => __( 'Confirm booking', 'osam-bookings' ),
			'pay'            => __( 'Pay & book', 'osam-bookings' ),
			'free'           => __( 'Free', 'osam-bookings' ),
			'summary'        => __( 'Booking summary', 'osam-bookings' ),
			'success_title'  => __( 'Booking received', 'osam-bookings' ),
			'success_text'   => __( 'Thank you — we have emailed your booking details to you.', 'osam-bookings' ),
			'pending_text'   => __( 'Thank you — your booking is awaiting confirmation. We will email you shortly.', 'osam-bookings' ),
			'cancelled_text' => __( 'Payment was cancelled and your booking has not been made.', 'osam-bookings' ),
		);
	}

	/**
	 * Is a form field also asked for each extra place?
	 *
	 * @param array $f Field.
	 * @return int
	 */
	public static function field_per_place( array $f ) {
		if ( 'name' === $f['key'] ) {
			return 1;
		}
		return isset( $f['per_place'] ) ? (int) $f['per_place'] : ( 'email' === $f['key'] ? 1 : 0 );
	}

	/**
	 * Questions asked for each extra place (guests) — name always, email by default,
	 * plus any field set to "Ask for each place". Email and phone are optional for guests.
	 *
	 * @param string $type    service|event|course.
	 * @param int    $item_id Item.
	 * @return array
	 */
	public static function guest_fields( $type, $item_id ) {
		$out = array();
		foreach ( self::form_fields_for( $type, $item_id ) as $f ) {
			if ( ! self::field_per_place( $f ) ) {
				continue;
			}
			if ( 'name' === $f['key'] ) {
				$f['required'] = 1;
			} elseif ( in_array( $f['key'], array( 'email', 'phone' ), true ) ) {
				$f['required'] = 0;
			}
			$f['show_for'] = array();
			$out[]         = $f;
		}
		return apply_filters( 'osb_guest_fields', $out, $type, $item_id );
	}

	/**
	 * Default booking form fields. name/email are core and always present.
	 *
	 * @return array
	 */
	public static function default_fields() {
		return array(
			array( 'key' => 'name',  'label' => __( 'Full name', 'osam-bookings' ), 'type' => 'text', 'required' => 1, 'options' => '', 'placeholder' => '', 'width' => 'full' ),
			array( 'key' => 'email', 'label' => __( 'Email', 'osam-bookings' ), 'type' => 'email', 'required' => 1, 'options' => '', 'placeholder' => '', 'width' => 'half' ),
			array( 'key' => 'phone', 'label' => __( 'Phone', 'osam-bookings' ), 'type' => 'tel', 'required' => 0, 'options' => '', 'placeholder' => '', 'width' => 'half' ),
			array( 'key' => 'notes', 'label' => __( 'Anything we should know?', 'osam-bookings' ), 'type' => 'textarea', 'required' => 0, 'options' => '', 'placeholder' => '', 'width' => 'full' ),
		);
	}

	/**
	 * Email templates keyed by trigger.
	 *
	 * @return array
	 */
	public static function default_emails() {
		$foot = "<p>Need to change anything? Just reply to this email.</p>\n<p>Thanks,<br>{site_name}</p>";

		return array(
			'customer_pending'   => array(
				'enabled' => 1,
				'subject' => 'We’ve received your booking — {item_name}',
				'body'    => "<h1>Thanks, {customer_first_name}!</h1>\n<p>We’ve received your booking and will confirm it shortly. Here’s what you asked for:</p>\n{details_card}\n{schedule}\n{manage_button}\n$foot",
			),
			'customer_confirmed' => array(
				'enabled' => 1,
				'subject' => 'You’re booked in — {item_name} on {date}',
				'body'    => "<h1>You’re booked in, {customer_first_name}</h1>\n<p>Your booking with {site_name} is confirmed. We’ve attached a calendar invite so it’s in your diary.</p>\n{details_card}\n{schedule}\n{manage_button}\n$foot",
			),
			'customer_cancelled' => array(
				'enabled' => 1,
				'subject' => 'Your booking has been cancelled — {item_name}',
				'body'    => "<h1>Your booking has been cancelled</h1>\n<p>Hi {customer_first_name}, the booking below has been cancelled. If you didn’t expect this, please get in touch and we’ll sort it out.</p>\n{details_card}\n<p><a href=\"{site_url}\">Book again</a></p>\n<p>Thanks,<br>{site_name}</p>",
			),
			'customer_reminder'  => array(
				'enabled' => 1,
				'subject' => 'See you soon — {item_name} on {date} at {time}',
				'body'    => "<h1>See you soon, {customer_first_name}</h1>\n<p>Just a friendly reminder about your upcoming booking.</p>\n{details_card}\n{manage_button}\n$foot",
			),
			'customer_session_reminder' => array(
				'enabled' => 1,
				'subject' => 'Reminder: {item_name} session {session_number} on {session_date}',
				'body'    => "<h1>Session {session_number} is coming up</h1>\n<p>Hi {customer_first_name}, a reminder that session {session_number} of {session_count} of <strong>{item_name}</strong> is on:</p>\n<blockquote><strong>{session_date}</strong><br>{session_time} – {session_end_time}<br>{session_location}</blockquote>\n<p>{session_note}</p>\n{manage_button}\n<p>See you there,<br>{site_name}</p>",
			),
			'customer_schedule_changed' => array(
				'enabled' => 1,
				'subject' => 'Updated dates for {item_name}',
				'body'    => "<h1>Your course dates have changed</h1>\n<p>Hi {customer_first_name}, the dates for <strong>{item_name}</strong> have been updated. Your new schedule is below, and the attached calendar file replaces the old one.</p>\n{schedule}\n<p>If the new dates don’t work for you, just reply and we’ll help.</p>\n{manage_button}\n<p>Thanks,<br>{site_name}</p>",
			),
			'customer_rescheduled' => array(
				'enabled' => 1,
				'subject' => 'Your booking has moved — {item_name} on {date} at {time}',
				'body'    => "<h1>All changed, {customer_first_name}</h1>\n<p>Your booking has been moved. Here are the new details — the attached calendar invite replaces the old one.</p>\n{details_card}\n{manage_button}\n$foot",
			),
			'customer_waitlist_offer' => array(
				'enabled' => 1,
				'subject' => 'A place has opened up — {item_name}',
				'body'    => "<h1>Good news, {customer_first_name}!</h1>\n<p>A place has become available on <strong>{item_name}</strong> ({date}, {time}). We’re holding it for you until <strong>{waitlist_expires}</strong>.</p>\n{waitlist_button}\n<p>If you no longer need it, just ignore this email and we’ll offer it to the next person.</p>\n<p>Thanks,<br>{site_name}</p>",
			),
			'customer_followup' => array(
				'enabled' => 0,
				'subject' => 'Thanks for visiting {site_name}',
				'body'    => "<h1>Thanks for coming, {customer_first_name}</h1>\n<p>We hope you enjoyed your {item_name}. If you have a moment, a quick review really helps a small business like ours.</p>\n{review_button}\n<p>Fancy coming back? <a href=\"{booking_url}\">Book your next visit</a>.</p>\n<p>Thanks again,<br>{site_name}</p>",
			),
			'customer_access_code' => array(
				'enabled' => 1,
				'subject' => 'Your access code for {item_name} on {date}',
				'body'    => "<h1>Here’s how to get in, {customer_first_name}</h1>\n<p>Your booking for <strong>{item_name}</strong> on {date} at {time} is coming up{location_line}. Use this code when you arrive:</p>\n{access_details}\n{manage_button}\n$foot",
			),
			'customer_bundle' => array(
				'enabled' => 1,
				'subject' => 'Your bundle is ready — {bundle_name}',
				'body'    => "<h1>Thanks, {customer_first_name}!</h1>\n<p>Your <strong>{bundle_name}</strong> is ready to use. Enter this code when you book and the booking is paid for from your bundle:</p>\n{bundle_code_box}\n<p>It can be used for: {bundle_covers}. Keep this email safe — anyone with the code can use it.</p>\n<p><a href=\"{booking_url}\">Book now</a></p>\n{portal_link}\n<p>Thanks,<br>{site_name}</p>",
			),
			'customer_login' => array(
				'enabled' => 1,
				'subject' => 'Your sign-in link for {site_name}',
				'body'    => "<h1>Sign in to your bookings</h1>\n<p>Tap the button to see and manage your bookings. The link works once and only for the next 30 minutes.</p>\n{login_button}\n<p>Or enter this code on the sign-in page: <strong style=\"font-size:20px;letter-spacing:.2em\">{login_code}</strong></p>\n<p>If you didn’t ask for this, you can ignore this email — nobody can sign in without it.</p>\n<p>{site_name}</p>",
			),
			'customer_payment_failed' => array(
				'enabled' => 1,
				'subject' => 'Your booking wasn’t completed — {item_name}',
				'body'    => "<h1>Your booking wasn’t completed</h1>\n<p>Hi {customer_first_name}, we didn’t receive payment for the booking below, so it hasn’t been made and the time has been released. You haven’t been charged.</p>\n{details_card}\n<p>If you’d still like to come, please book again:</p>\n{book_again_button}\n<p>Thanks,<br>{site_name}</p>",
			),
			'customer_invoice' => array(
				'enabled' => 1,
				'subject' => 'Your invoice {invoice_number} from {site_name}',
				'body'    => "<h1>Thanks for your payment, {customer_first_name}</h1>\n<p>Your invoice <strong>{invoice_number}</strong> for <strong>{invoice_total}</strong> is attached for your records.</p>\n{details_card}\n{pay_button}\n{invoice_button}\n$foot",
			),
			'customer_credit_note' => array(
				'enabled' => 1,
				'subject' => 'Credit note {credit_note_number} from {site_name}',
				'body'    => "<h1>Your refund, {customer_first_name}</h1>\n<p>We’ve issued credit note <strong>{credit_note_number}</strong> for <strong>{credit_note_total}</strong> against invoice {invoice_number}. It’s attached for your records.</p>\n<p>Refunds usually reach your account within 5–10 working days, depending on your bank.</p>\n{invoice_button}\n$foot",
			),
			'admin_payment_failed' => array(
				'enabled' => 0,
				'subject' => 'Payment not completed for booking #{booking_id}: {item_name} — {date}',
				'body'    => "<h2>Payment not completed {status_badge}</h2>\n<p>{customer_name} started this booking but didn’t finish paying, so it was released. It isn’t in your calendar.</p>\n{details_card}\n<p>{customer_name}<br><a href=\"mailto:{customer_email}\">{customer_email}</a><br>{customer_phone}</p>\n{admin_button}",
			),
			'admin_new'          => array(
				'enabled' => 1,
				'subject' => 'New booking #{booking_id}: {item_name} — {date} {time}',
				'body'    => "<h2>New booking {status_badge}</h2>\n{details_card}\n<h3>Customer</h3>\n<p>{customer_name}<br><a href=\"mailto:{customer_email}\">{customer_email}</a><br>{customer_phone}</p>\n{fields_table}\n{schedule}\n{admin_button}",
			),
			'admin_rescheduled'  => array(
				'enabled' => 1,
				'subject' => 'Booking #{booking_id} moved by the customer: {item_name} — {date} {time}',
				'body'    => "<h2>Booking rescheduled {status_badge}</h2>\n<p>{customer_name} moved their booking. It was {previous_when}; the new details are below.</p>\n{details_card}\n{admin_button}",
			),
			'admin_cancelled'    => array(
				'enabled' => 1,
				'subject' => 'Booking #{booking_id} cancelled: {item_name} — {date}',
				'body'    => "<h2>Booking cancelled {status_badge}</h2>\n{details_card}\n<p>{customer_name} — <a href=\"mailto:{customer_email}\">{customer_email}</a></p>\n{admin_button}",
			),
			'staff_new'          => array(
				'enabled' => 1,
				'subject' => 'New booking: {item_name} — {date} {time}',
				'body'    => "<h2>Hi {staff_name}, you have a new booking</h2>\n{details_card}\n<h3>Customer</h3>\n<p>{customer_name}<br>{customer_email}<br>{customer_phone}</p>\n{fields_table}",
			),
			'staff_cancelled'    => array(
				'enabled' => 1,
				'subject' => 'Booking cancelled: {item_name} — {date} {time}',
				'body'    => "<h2>Hi {staff_name}, a booking has been cancelled</h2>\n{details_card}\n<p>{customer_name}</p>",
			),
		);
	}

	/**
	 * Human labels for the email triggers.
	 *
	 * @return array
	 */
	public static function email_labels() {
		return array(
			'customer_pending'   => __( 'Customer — booking received (awaiting approval)', 'osam-bookings' ),
			'customer_confirmed' => __( 'Customer — booking confirmed', 'osam-bookings' ),
			'customer_cancelled' => __( 'Customer — booking cancelled', 'osam-bookings' ),
			'customer_reminder'  => __( 'Customer — reminder', 'osam-bookings' ),
			'customer_session_reminder' => __( 'Customer — course session reminder', 'osam-bookings' ),
			'customer_schedule_changed' => __( 'Customer — course dates changed', 'osam-bookings' ),
			'customer_rescheduled'      => __( 'Customer — booking moved', 'osam-bookings' ),
			'customer_waitlist_offer'   => __( 'Customer — waitlist place available', 'osam-bookings' ),
			'customer_followup'         => __( 'Customer — after-visit thank you / review request', 'osam-bookings' ),
			'customer_access_code'      => __( 'Customer — access code', 'osam-bookings' ),
			'customer_bundle'           => __( 'Customer — bundle bought (with their code)', 'osam-bookings' ),
			'customer_login'            => __( 'Customer — sign-in link for My bookings', 'osam-bookings' ),
			'customer_payment_failed'   => __( 'Customer — payment not completed', 'osam-bookings' ),
			'customer_invoice'          => __( 'Customer — invoice (sent on its own)', 'osam-bookings' ),
			'customer_credit_note'      => __( 'Customer — credit note / refund', 'osam-bookings' ),
			'admin_payment_failed'      => __( 'Admin — payment not completed', 'osam-bookings' ),
			'admin_rescheduled'         => __( 'Admin — booking moved by customer', 'osam-bookings' ),
			'admin_new'          => __( 'Admin — new booking', 'osam-bookings' ),
			'admin_cancelled'    => __( 'Admin — booking cancelled', 'osam-bookings' ),
			'staff_new'          => __( 'Staff member — new booking', 'osam-bookings' ),
			'staff_cancelled'    => __( 'Staff member — booking cancelled', 'osam-bookings' ),
		);
	}

	/**
	 * Standard emails grouped by the part of the plugin they belong to, in display order.
	 * Emails in a section whose feature is off are hidden and never sent.
	 *
	 * @return array section id => [ title, description, on (bool), keys[], off_hint ]
	 */
	public static function email_sections() {
		$team = ! OSB_DB::is_solo();
		$core = array( 'customer_pending', 'customer_confirmed', 'customer_rescheduled', 'customer_cancelled', 'customer_reminder', 'admin_new', 'admin_cancelled' );
		if ( $team ) {
			array_push( $core, 'staff_new', 'staff_cancelled' );
		}
		$sections = array(
			'booking'    => array( __( 'Every booking', 'osam-bookings' ), __( 'Sent for every kind of booking.', 'osam-bookings' ), true, $core, '' ),
			'payments'   => array( __( 'Online payments', 'osam-bookings' ), __( 'Only used while Stripe, PayPal or WooCommerce is switched on.', 'osam-bookings' ), self::online_payments_on(), array( 'customer_payment_failed', 'admin_payment_failed' ), __( 'Switch on an online payment method in Settings → Payments.', 'osam-bookings' ) ),
			'reschedule' => array( __( 'Customer rescheduling', 'osam-bookings' ), __( 'When customers move their own booking.', 'osam-bookings' ), self::feature( 'reschedule' ), array( 'admin_rescheduled' ), '' ),
			'followup'   => array( __( 'After the visit', 'osam-bookings' ), __( 'A thank-you with an optional review link. Switching this email on or off also switches the feature.', 'osam-bookings' ), true, array( 'customer_followup' ), '' ),
			'portal'     => array( __( 'My bookings sign-in', 'osam-bookings' ), __( 'For customers signing in to see their bookings.', 'osam-bookings' ), true, array( 'customer_login' ), '' ),
			'invoices'   => array( __( 'Invoices', 'osam-bookings' ), '', self::feature( 'invoices' ), array( 'customer_invoice', 'customer_credit_note' ), '' ),
			'courses'    => array( __( 'Courses', 'osam-bookings' ), '', self::feature( 'courses' ), array( 'customer_session_reminder', 'customer_schedule_changed' ), '' ),
			'waitlist'   => array( __( 'Waitlist', 'osam-bookings' ), '', self::feature( 'waitlist' ), array( 'customer_waitlist_offer' ), __( 'Needs Waitlist and Events or Courses switched on.', 'osam-bookings' ) ),
			'resources'  => array( __( 'Resources', 'osam-bookings' ), '', self::feature( 'resources' ), array( 'customer_access_code' ), '' ),
			'bundles'    => array( __( 'Bundles', 'osam-bookings' ), '', self::feature( 'bundles' ), array( 'customer_bundle' ), '' ),
		);
		$out = array();
		foreach ( $sections as $id => $s ) {
			$out[ $id ] = array(
				'title'    => $s[0],
				'desc'     => $s[1],
				'on'       => (bool) $s[2],
				'keys'     => $s[3],
				'off_hint' => $s[4],
			);
		}
		return apply_filters( 'osb_email_sections', $out );
	}

	/**
	 * Is an online payment method (one customers pay through) switched on?
	 *
	 * @return bool
	 */
	public static function online_payments_on() {
		if ( ! function_exists( 'osb' ) || ! osb() || empty( osb()->payments ) ) {
			return true; // Too early to tell: don't hide anything.
		}
		foreach ( osb()->payments->enabled() as $gateway ) {
			if ( 'pending_payment' === $gateway->initial_status() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does this standard email belong to a part of the plugin that's switched on?
	 * Emails that aren't in any section (e.g. added by another plugin) count as available.
	 *
	 * @param string $key Template key.
	 * @return bool
	 */
	public static function email_available( $key ) {
		if ( in_array( $key, array( 'staff_new', 'staff_cancelled' ), true ) && OSB_DB::is_solo() ) {
			return false;
		}
		foreach ( self::email_sections() as $s ) {
			if ( in_array( $key, $s['keys'], true ) ) {
				return $s['on'];
			}
		}
		return true;
	}

	/**
	 * Who an email goes to, from its key.
	 *
	 * @param string $key Template key.
	 * @return string
	 */
	public static function email_recipient( $key ) {
		if ( 0 === strpos( $key, 'admin_' ) ) {
			return __( 'You', 'osam-bookings' );
		}
		if ( 0 === strpos( $key, 'staff_' ) ) {
			return __( 'Staff member', 'osam-bookings' );
		}
		return __( 'Customer', 'osam-bookings' );
	}

	/**
	 * Email templates in their sections, leaving out sections for switched-off features.
	 *
	 * @return array section title => keys
	 */
	public static function email_groups() {
		$groups = array();
		foreach ( self::email_sections() as $s ) {
			if ( $s['on'] && $s['keys'] ) {
				$groups[ $s['title'] ] = $s['keys'];
			}
		}
		return $groups;
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored   = get_option( 'osb_settings', array() );
			$stored   = is_array( $stored ) ? $stored : array();
			$defaults = self::defaults();
			$merged   = array_merge( $defaults, $stored );

			// Nested arrays merge one level deeper so new defaults appear after updates.
			$merged['labels'] = array_merge( $defaults['labels'], isset( $stored['labels'] ) && is_array( $stored['labels'] ) ? $stored['labels'] : array() );
			$emails           = $defaults['emails'];
			if ( isset( $stored['emails'] ) && is_array( $stored['emails'] ) ) {
				foreach ( $stored['emails'] as $key => $tpl ) {
					$emails[ $key ] = array_merge( isset( $emails[ $key ] ) ? $emails[ $key ] : array(), (array) $tpl );
				}
			}
			$merged['emails'] = $emails;
			if ( empty( $merged['form_fields'] ) || ! is_array( $merged['form_fields'] ) ) {
				$merged['form_fields'] = $defaults['form_fields'];
			}
			self::$cache = $merged;
		}
		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist a partial settings array.
	 *
	 * @param array $values Values to merge.
	 */
	public static function update( array $values ) {
		$stored = get_option( 'osb_settings', array() );
		$stored = is_array( $stored ) ? $stored : array();
		update_option( 'osb_settings', array_merge( $stored, $values ) );
		self::$cache = null;
	}

	/**
	 * Is an optional part of the plugin switched on? (Settings → Features.)
	 *
	 * @param string $feature events|courses.
	 * @return bool
	 */
	public static function feature( $feature ) {
		switch ( $feature ) {
			case 'waitlist':
				$on = self::get( 'waitlist_enabled', 1 ) && ( self::feature( 'events' ) || self::feature( 'courses' ) );
				break;
			case 'reschedule':
				$on = self::get( 'allow_customer_reschedule', 1 );
				break;
			case 'followup':
				$t  = self::get( 'emails', array() );
				$on = ! empty( $t['customer_followup']['enabled'] );
				break;
			default:
				$on = self::get( 'enable_' . $feature, 1 );
		}
		return (bool) apply_filters( 'osb_feature_enabled', (bool) $on, $feature );
	}

	/**
	 * Optional parts of the plugin, for the Features tab.
	 *
	 * @return array key => [ title, description, dashicon, setting key ]
	 */
	public static function features() {
		return array(
			'events'        => array( __( 'Events', 'osam-bookings' ), __( 'One-off dated sessions with a set number of places — workshops, classes, open days.', 'osam-bookings' ), 'dashicons-tickets-alt', 'enable_events' ),
			'courses'       => array( __( 'Courses', 'osam-bookings' ), __( 'One fee for a run of sessions, with named intakes, a register and session reminders.', 'osam-bookings' ), 'dashicons-welcome-learn-more', 'enable_courses' ),
			'resources'     => array( __( 'Resources', 'osam-bookings' ), __( 'Book out rooms, fields, halls or equipment by the slot, hour, half day or day — shared places or sole use.', 'osam-bookings' ), 'dashicons-building', 'enable_resources' ),
			'restaurant'    => array( __( 'Restaurant tables', 'osam-bookings' ), __( 'Table reservations by party size, with a floor plan, table combinations, service periods, a live service timeline, deposits or card holds.', 'osam-bookings' ), 'dashicons-food', 'enable_restaurant' ),
			'waitlist'      => array( __( 'Waitlist', 'osam-bookings' ), __( 'When an event or course is full, customers can join a list and are offered places that free up.', 'osam-bookings' ), 'dashicons-clock', 'waitlist_enabled' ),
			'repeat'        => array( __( 'Repeat bookings', 'osam-bookings' ), __( 'Customers book the same time every week, fortnight or month in one go — appointments, resources and tables. Staff can repeat any booking, and events can be created as a series.', 'osam-bookings' ), 'dashicons-backup', 'enable_repeat' ),
			'addons'        => array( __( 'Add-ons', 'osam-bookings' ), __( 'Extras customers can add when they book — equipment, refreshments, extra time — priced per booking, per person, per hour or per day.', 'osam-bookings' ), 'dashicons-plus-alt', 'enable_addons' ),
			'bundles'       => array( __( 'Bundles', 'osam-bookings' ), __( 'Sell packs of sessions or credits in advance (e.g. 10 for the price of 8). Customers get a code to pay for bookings with.', 'osam-bookings' ), 'dashicons-tickets', 'enable_bundles' ),
			'coupons'       => array( __( 'Discount codes & vouchers', 'osam-bookings' ), __( 'Percentage or fixed-amount codes, and gift vouchers with a balance, entered at checkout.', 'osam-bookings' ), 'dashicons-tag', 'enable_coupons' ),
			'reschedule'    => array( __( 'Customer rescheduling', 'osam-bookings' ), __( 'Customers can move their own appointment from the link in their email.', 'osam-bookings' ), 'dashicons-calendar', 'allow_customer_reschedule' ),
			'followup'      => array( __( 'After-visit emails', 'osam-bookings' ), __( 'A thank-you a few hours after the appointment, with an optional review link.', 'osam-bookings' ), 'dashicons-star-filled', 'followup' ),
			'calendar_sync' => array( __( 'Calendar sync', 'osam-bookings' ), __( 'Two-way sync with Google and Microsoft calendars for each staff member.', 'osam-bookings' ), 'dashicons-update', 'enable_calendar_sync' ),
			'meetings'      => array( __( 'Online meeting links', 'osam-bookings' ), __( 'Run services, events or courses online with a fixed link, Zoom, Google Meet or Teams.', 'osam-bookings' ), 'dashicons-video-alt2', 'enable_meetings' ),
			'customers'     => array( __( 'Customers', 'osam-bookings' ), __( 'A list of everyone who has booked, with history, private notes, CSV export and GDPR tools.', 'osam-bookings' ), 'dashicons-id', 'enable_customers' ),
			'invoices'      => array( __( 'Invoices', 'osam-bookings' ), __( 'A numbered PDF invoice for every payment, attached to the confirmation email, with credit notes for refunds and a searchable list.', 'osam-bookings' ), 'dashicons-media-spreadsheet', 'enable_invoices' ),
			'reports'       => array( __( 'Reports', 'osam-bookings' ), __( 'Revenue, bookings, occupancy, cancellations and no-shows over any date range.', 'osam-bookings' ), 'dashicons-chart-bar', 'enable_reports' ),
		);
	}

	/**
	 * Get a label.
	 *
	 * @param string $key Label key.
	 * @return string
	 */
	public static function label( $key ) {
		$labels = self::get( 'labels', array() );
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/**
	 * Form fields including guaranteed name/email.
	 *
	 * @return array
	 */
	public static function form_fields() {
		$fields = self::get( 'form_fields', array() );
		$keys   = wp_list_pluck( $fields, 'key' );
		foreach ( array( 'email', 'name' ) as $core ) {
			if ( ! in_array( $core, $keys, true ) ) {
				foreach ( self::default_fields() as $def ) {
					if ( $def['key'] === $core ) {
						array_unshift( $fields, $def );
					}
				}
			}
		}
		foreach ( $fields as &$field ) {
			if ( in_array( $field['key'], array( 'name', 'email' ), true ) ) {
				$field['required'] = 1;
			}
		}
		return apply_filters( 'osb_form_fields', $fields );
	}

	/**
	 * Does a form field apply to this booking? Fields with no "show for" apply everywhere.
	 *
	 * @param array  $field   Field.
	 * @param string $type    service|event|course.
	 * @param int    $item_id Service, event or course id.
	 * @return bool
	 */
	public static function field_applies( array $field, $type, $item_id ) {
		$for = isset( $field['show_for'] ) ? (array) $field['show_for'] : array();
		if ( ! $for || in_array( $field['key'], array( 'name', 'email' ), true ) ) {
			return true;
		}
		$kind = array(
			'service' => array( 'services', 's' ),
			'event'   => array( 'events', 'e' ),
			'course'  => array( 'courses', 'c' ),
			'table'   => array( 'tables', 't' ),
			'resource' => array( 'resources', 'r' ),
		);
		if ( ! isset( $kind[ $type ] ) ) {
			return true;
		}
		return in_array( $kind[ $type ][0], $for, true ) || in_array( $kind[ $type ][1] . ':' . (int) $item_id, $for, true );
	}

	/**
	 * Form fields for one booking.
	 *
	 * @param string $type    Type.
	 * @param int    $item_id Item.
	 * @return array
	 */
	public static function form_fields_for( $type, $item_id ) {
		return array_values(
			array_filter(
				self::form_fields(),
				static function ( $f ) use ( $type, $item_id ) {
					return self::field_applies( $f, $type, $item_id );
				}
			)
		);
	}

	/**
	 * Front-end display choices shared by settings, shortcode attributes and Elementor.
	 *
	 * @return array
	 */
	public static function display_choices() {
		return array(
			'design'         => array(
				'card'    => __( 'Card — bordered panel with shadow', 'osam-bookings' ),
				'flat'    => __( 'Flat — soft tinted panel, no border', 'osam-bookings' ),
				'minimal' => __( 'Minimal — no panel, blends into the page', 'osam-bookings' ),
			),
			'service_layout' => array(
				'list' => __( 'List', 'osam-bookings' ),
				'grid' => __( 'Grid', 'osam-bookings' ),
			),
			'service_columns' => array(
				2 => '2',
				3 => '3',
				4 => '4',
			),
			'button_shape'   => array(
				'rounded' => __( 'Rounded', 'osam-bookings' ),
				'pill'    => __( 'Pill', 'osam-bookings' ),
				'square'  => __( 'Square', 'osam-bookings' ),
			),
			'density'        => array(
				'comfortable' => __( 'Comfortable', 'osam-bookings' ),
				'compact'     => __( 'Compact', 'osam-bookings' ),
			),
			'font_family'    => array(
				'inherit' => __( 'Use the theme font', 'osam-bookings' ),
				'system'  => __( 'System sans-serif', 'osam-bookings' ),
				'serif'   => __( 'Serif', 'osam-bookings' ),
			),
		);
	}

	/**
	 * Visibility toggles: setting key => [ shortcode attribute, label ].
	 *
	 * @return array
	 */
	public static function display_toggles() {
		return array(
			'show_images'          => array( 'images', __( 'Images on services, staff, events & courses', 'osam-bookings' ) ),
			'show_descriptions'    => array( 'descriptions', __( 'Descriptions', 'osam-bookings' ) ),
			'show_duration'        => array( 'durations', __( 'Service durations', 'osam-bookings' ) ),
			'show_prices'          => array( 'prices', __( 'Prices', 'osam-bookings' ) ),
			'show_category_filter' => array( 'filter', __( 'Category filter', 'osam-bookings' ) ),
			'show_progress'        => array( 'progress', __( 'Step progress bar', 'osam-bookings' ) ),
			'show_summary'         => array( 'summary', __( 'Booking summary panel', 'osam-bookings' ) ),
			'show_seats_left'      => array( 'seats_left', __( '“Places left” on events, courses & group slots', 'osam-bookings' ) ),
		);
	}

	/**
	 * Supported currencies (symbol map).
	 *
	 * @return array
	 */
	public static function currencies() {
		return apply_filters(
			'osb_currencies',
			array(
				'GBP' => '£',
				'EUR' => '€',
				'USD' => '$',
				'AUD' => 'A$',
				'CAD' => 'C$',
				'NZD' => 'NZ$',
				'CHF' => 'CHF ',
				'SEK' => 'kr ',
				'NOK' => 'kr ',
				'DKK' => 'kr ',
				'PLN' => 'zł ',
				'ZAR' => 'R',
				'INR' => '₹',
			)
		);
	}

	/**
	 * Format money.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function money( $amount, $currency = '' ) {
		$currency = $currency ? $currency : self::get( 'currency' );
		$symbols  = self::currencies();
		$symbol   = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $currency . ' ';
		$number   = number_format_i18n( (float) $amount, 2 );
		return 'after' === self::get( 'currency_position' ) ? trim( $number . ' ' . $symbol ) : $symbol . $number;
	}
}
