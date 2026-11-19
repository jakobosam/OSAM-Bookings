<?php
/**
 * End-to-end check of the core booking flows inside WordPress Playground (SQLite):
 * service -> booking through the booking form's REST route -> confirm -> "Pay now" amount -> pay ->
 * invoice -> cancel, plus emails, the problem log and the scheduled jobs.
 *
 * Run all tests:  node tests/run.mjs 8.3
 * Fails on any PHP warning/notice/deprecation. Nothing is really emailed and no HTTP leaves the sandbox.
 *
 * @package OSAM_Bookings
 */

require __DIR__ . '/lib/harness.php';
require '/wordpress/wp-load.php';
osb_t_after_load();
require_once ABSPATH . 'wp-admin/includes/plugin.php';

update_option( 'timezone_string', 'Europe/London' );
$r = activate_plugin( 'osam-bookings/osam-bookings.php' );
ok( ! is_wp_error( $r ), 'plugin activates' . ( is_wp_error( $r ) ? ': ' . $r->get_error_message() : '' ) );
if ( ! did_action( 'osb_loaded' ) ) {
	OSB_Plugin::instance();
}
OSB_Install::activate();

// Count emails instead of sending them; block (and record) outgoing HTTP.
$mails = array();
$http  = array();
add_filter(
	'pre_wp_mail',
	function ( $pre, $atts ) use ( &$mails ) {
		$mails[] = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) . ': ' . $atts['subject'] : $atts['to'] . ': ' . $atts['subject'];
		return true;
	},
	10,
	2
);
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$http ) {
		$http[] = $url;
		return new WP_Error( 'blocked', 'blocked in test' );
	},
	10,
	3
);

// Pay on site starts as "pending" (so we can confirm it); a Stripe test key that's never called makes
// the online "Pay now" button available.
OSB_Settings::update(
	array(
		'staff_mode'            => 'solo',
		'onsite_enabled'        => 1,
		'onsite_status'         => 'pending',
		'stripe_enabled'        => 1,
		'stripe_mode'           => 'test',
		'stripe_test_secret'    => 'sk_test_osb_e2e_not_a_real_key',
		'enable_invoices'       => 1,
		'inv_pay_button'        => 1,
		'allow_customer_cancel' => 1,
		'cancel_cutoff_hours'   => 24,
		'min_notice_hours'      => 2,
	)
);

/* ---------------------------------------------------------------- Service (through the admin save handler) */
wp_set_current_user( 1 );
$_POST    = array(
	'_wpnonce'        => wp_create_nonce( 'osb_save_service' ),
	'name'            => 'E2E Massage',
	'duration'        => 60,
	'price'           => '40.00',
	'capacity'        => 1,
	'payment_methods' => array( 'onsite', 'stripe' ),
	'status'          => 'active',
);
$_REQUEST = $_POST;
$redirect = '';
add_filter(
	'wp_redirect',
	function ( $location ) use ( &$redirect ) {
		$redirect = $location;
		throw new RuntimeException( 'redirect' ); // Stop before the handler's exit.
	}
);
try {
	OSB_Admin_Services::save();
} catch ( RuntimeException $e ) {
	unset( $e );
}
remove_all_filters( 'wp_redirect' );
$_POST    = array();
$_REQUEST = array();
$sid      = preg_match( '/[?&]id=(\d+)/', $redirect, $m ) ? (int) $m[1] : 0;
$service  = $sid ? OSB_DB::get( 'services', $sid ) : null;
ok( $service && 'E2E Massage' === $service->name && 40.0 === (float) $service->price && 'active' === $service->status, 'service saved by the admin save handler (id ' . $sid . ')' );
ok( $service && OSB_DB::staff_for_service( $sid ), 'solo mode: the owner provides the service' );
wp_set_current_user( 0 );

/* ---------------------------------------------------------------- Booking through the booking form's REST route */
$rest = function ( $method, $route, $body = null, $query = array() ) {
	$req = new WP_REST_Request( $method, '/osb/v1' . $route );
	if ( $query ) {
		$req->set_query_params( $query );
	}
	if ( null !== $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $req );
};

// First weekday at least 7 days ahead with free times (the owner works Mon-Fri 09:00-17:00).
$date  = '';
$slots = array();
for ( $i = 7; $i < 21 && ! $slots; $i++ ) {
	$try = wp_date( 'Y-m-d', time() + $i * DAY_IN_SECONDS );
	if ( (int) wp_date( 'N', strtotime( $try . ' 12:00:00' ) ) > 5 ) {
		continue;
	}
	$res = $rest( 'GET', '/availability/day', null, array( 'service_id' => $sid, 'date' => $try ) );
	if ( 200 === $res->get_status() && $res->get_data() ) {
		$date  = $try;
		$slots = $res->get_data();
	}
}
ok( $slots && isset( $slots[0]['time'] ), 'availability: free times found on ' . $date . ' (' . count( $slots ) . ' slots)' );
$time = $slots ? $slots[0]['time'] : '10:00';

$mails = array();
$res   = $rest(
	'POST',
	'/bookings',
	array(
		'type'           => 'service',
		'service_id'     => $sid,
		'date'           => $date,
		'time'           => $time,
		'seats'          => 1,
		'payment_method' => 'onsite',
		'fields'         => array(
			'name'  => 'Erin Example',
			'email' => 'erin@example.com',
			'phone' => '07700 900123',
		),
	)
);
$data = $res->get_data();
ok( 200 === $res->get_status() && ! empty( $data['token'] ), 'POST /osb/v1/bookings creates a booking (HTTP ' . $res->get_status() . ( 200 !== $res->get_status() ? ': ' . wp_json_encode( $data ) : '' ) . ')' );
$booking = ! empty( $data['token'] ) ? OSB_DB::booking_by_token( $data['token'] ) : null;
ok( $booking && 'pending' === $booking->status && 'unpaid' === $booking->payment_status && 40.0 === (float) $booking->amount, 'booking is pending, unpaid, £40' . ( $booking ? ' (' . $booking->status . ', ' . $booking->payment_status . ', ' . $booking->amount . ')' : '' ) );
ok( $booking && $date . ' ' . $time . ':00' === $booking->start_at && 'erin@example.com' === $booking->customer_email, 'booking has the chosen time and the customer details' );
ok( count( $mails ) >= 1, 'new booking: emails attempted (' . count( $mails ) . ': ' . implode( ' | ', $mails ) . ')' );
$again = $rest( 'GET', '/availability/day', null, array( 'service_id' => $sid, 'date' => $date ) );
ok( ! in_array( $time, wp_list_pluck( (array) $again->get_data(), 'time' ), true ), 'the booked time is no longer offered' );

/* ---------------------------------------------------------------- Confirm */
$id    = $booking ? (int) $booking->id : 0;
$mails = array();
ok( OSB_Bookings::set_status( $id, 'confirmed' ), 'confirm: set_status pending -> confirmed' );
$booking = OSB_DB::get( 'bookings', $id );
ok( $booking && 'confirmed' === $booking->status, 'booking is confirmed' );
ok( count( $mails ) >= 1, 'confirm: approval email attempted (' . count( $mails ) . ')' );

/* ---------------------------------------------------------------- Pay now amount, pay, invoice */
ok( 40.0 === OSB_Payments::outstanding( $booking ), 'before paying: outstanding is £40 (' . OSB_Payments::outstanding( $booking ) . ')' );
ok( 40.0 === OSB_Payments::payable( $booking ), 'before paying: "Pay now" amount is £40 (' . OSB_Payments::payable( $booking ) . ')' );
ok( 0 === strpos( OSB_Payments::pay_label( $booking ), 'Pay now' ), 'button reads "Pay now"' );
ok( ! OSB_Invoices::for_booking( $booking ), 'no invoice before paying' );

$mails = array();
ok( true === OSB_Bookings::mark_paid( $booking, 'stripe', 'pi_e2e_1', 40.0 ), 'mark_paid records the payment' );
ok( false === OSB_Bookings::mark_paid( $booking, 'stripe', 'pi_e2e_1', 40.0 ), 'mark_paid is idempotent per transaction' );
$booking = OSB_DB::get( 'bookings', $id );
ok( 'paid' === $booking->payment_status && 'confirmed' === $booking->status, 'booking is paid and still confirmed' );
$payments = OSB_DB::payments( $id );
ok( 1 === count( $payments ) && 40.0 === (float) $payments[0]->amount && 'completed' === $payments[0]->status, 'one completed £40 payment row' );
$invoices = OSB_Invoices::for_booking( $booking );
ok( 1 === count( $invoices ), 'one invoice created (' . count( $invoices ) . ')' );
$inv = $invoices ? $invoices[0] : null;
ok( $inv && ! empty( $inv->number ) && 40.0 === (float) $inv->total, 'invoice has a number and a £40 total' . ( $inv ? ' (' . $inv->number . ', ' . $inv->total . ')' : '' ) );
ok( $inv && 0.0 === (float) OSB_Invoices::balance_due( $inv ), 'invoice balance due is 0' );
ok( count( $mails ) >= 1, 'payment: receipt email attempted (' . count( $mails ) . ': ' . implode( ' | ', $mails ) . ')' );
ok( 0.0 === OSB_Payments::outstanding( $booking ), 'after paying: outstanding is 0' );
ok( 0.0 === OSB_Payments::payable( $booking ), 'after paying: no "Pay now" amount' );

/* ---------------------------------------------------------------- Cancel (customer link, REST) */
$statuses = array();
add_action(
	'osb_booking_status_changed',
	function ( $b, $old, $new ) use ( &$statuses ) {
		$statuses[] = $old . '->' . $new;
	},
	10,
	3
);
$mails = array();
$res   = $rest( 'POST', '/bookings/' . $booking->token . '/cancel', array() );
ok( 200 === $res->get_status(), 'POST /bookings/{token}/cancel succeeds (HTTP ' . $res->get_status() . ( 200 !== $res->get_status() ? ': ' . wp_json_encode( $res->get_data() ) : '' ) . ')' );
$booking = OSB_DB::get( 'bookings', $id );
ok( 'cancelled' === $booking->status, 'booking is cancelled' );
ok( array( 'confirmed->cancelled' ) === $statuses, 'status change fired once: ' . implode( ', ', $statuses ) );
ok( count( $mails ) >= 1, 'cancel: emails attempted (' . count( $mails ) . ')' );
ok( 0.0 === OSB_Payments::payable( $booking ), 'cancelled: no "Pay now" amount' );
$again = $rest( 'GET', '/availability/day', null, array( 'service_id' => $sid, 'date' => $date ) );
ok( in_array( $time, wp_list_pluck( (array) $again->get_data(), 'time' ), true ), 'the cancelled time is offered again' );

/* ---------------------------------------------------------------- Problem log */
OSB_Log::clear();
OSB_Log::error( 'payments', 'E2E test problem', array( 'booking' => $id ) );
OSB_Log::error( 'payments', 'E2E test problem', array( 'booking' => $id ) );
$log = OSB_Log::recent( 5 );
ok( 1 === count( $log ) && 'error' === $log[0]['level'] && 'payments' === $log[0]['area'] && 'E2E test problem' === $log[0]['msg'] && 2 === (int) $log[0]['count'], 'OSB_Log records an error and counts a repeat instead of adding it twice' );
do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP connect() failed', array( 'subject' => 'Your booking' ) ) );
$log = OSB_Log::recent( 5, 'email' );
ok( 1 === count( $log ) && false !== strpos( $log[0]['msg'], 'SMTP connect() failed' ), 'a failed wp_mail() is logged under Emails' );
$c = OSB_Log::counts( time() - HOUR_IN_SECONDS );
ok( 3 === $c['total'] && 3 === $c['errors'] && 2 === $c['by_area']['payments'] && 1 === $c['by_area']['email'], 'OSB_Log::counts adds them up (' . wp_json_encode( $c ) . ')' );
// The daily report carries the entries for the update dashboard, with personal details taken out.
OSB_Log::error( 'email', 'Couldn’t send “Booking for Jane Smith” to jane.smith@example.com, call +44 7700 900123 on 2026-11-19 (v2.17.0)', array( 'booking' => $id, 'email' => 'jane@example.com' ) );
$entries = OSB_Report::log_entries();
$top     = $entries[0];
ok( 3 === count( $entries ) && 'email' === $top['area'] && 'error' === $top['level'], 'the report sends the newest log entries (' . count( $entries ) . ')' );
ok( false === strpos( $top['msg'], 'Jane' ) && false === strpos( $top['msg'], '@' ) && false === strpos( $top['msg'], '900123' ), 'names in quotes, email addresses and phone numbers are removed: ' . $top['msg'] );
ok( false !== strpos( $top['msg'], '2026-11-19' ) && false !== strpos( $top['msg'], 'v2.17.0' ), 'dates and versions are kept' );
ok( isset( $top['ctx']['booking'] ) && ! isset( $top['ctx']['email'] ), 'only id-style context is sent' );
ok( isset( OSB_Report::build()['log'] ), 'build() includes the log' );

/* ---------------------------------------------------------------- Scheduled jobs */
delete_option( OSB_Cron::BEAT );
$h = OSB_Cron::health();
ok( is_array( $h ) && 'never' === $h['state'] && 0 === $h['last_run'], 'cron health before any run: never' );
OSB_Cron::run();
$h = OSB_Cron::health();
ok( $h['last_run'] >= time() - 60 && false === $h['late'] && in_array( $h['state'], array( 'ok', 'visitor', 'server' ), true ), 'OSB_Cron::run() runs and health() reports it (' . wp_json_encode( $h ) . ')' );
OSB_Cron::run();
ok( 'never' !== OSB_Cron::health()['state'], 'a second run is fine too' );

echo 'HTTP calls blocked: ' . ( $http ? implode( ', ', array_unique( $http ) ) : 'none' ) . PHP_EOL;

osb_t_done();
