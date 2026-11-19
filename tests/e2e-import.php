<?php
/**
 * End-to-end check of the Amelia / Wix importers inside WordPress Playground (SQLite).
 *
 * Run all tests:  node tests/run.mjs 8.3
 * Fixtures: tests/fixtures/ (fake Amelia tables, Wix CSV exports). Fails on any PHP warning/notice/deprecation.
 *
 * @package OSAM_Bookings
 */

require __DIR__ . '/lib/harness.php';
require '/wordpress/wp-load.php';
osb_t_after_load();
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

update_option( 'timezone_string', 'Europe/London' );
$r = activate_plugin( 'osam-bookings/osam-bookings.php' );
ok( ! is_wp_error( $r ), 'plugin activates' . ( is_wp_error( $r ) ? ': ' . $r->get_error_message() : '' ) );
if ( ! did_action( 'osb_loaded' ) ) {
	OSB_Plugin::instance();
}
OSB_Install::activate();
wp_set_current_user( 1 );
OSB_Settings::update( array( 'staff_mode' => 'team', 'enable_events' => 1, 'enable_calendar_sync' => 1, 'reminder_hours' => 24 ) );

// Count anything that would email, call out, or fire booking hooks.
$mails = 0;
$http  = array();
$hooks = array();
add_filter( 'pre_wp_mail', function () use ( &$mails ) { ++$mails; return true; } );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$http ) {
	if ( isset( $GLOBALS['osb_test_wix_mock'] ) ) {
		return call_user_func( $GLOBALS['osb_test_wix_mock'], $url, $args );
	}
	$http[] = $url;
	return new WP_Error( 'blocked', 'blocked in test' );
}, 10, 3 );
add_action( 'all', function ( $tag ) use ( &$hooks ) {
	if ( in_array( $tag, array( 'osb_booking_created', 'osb_booking_notify', 'osb_booking_status_changed', 'osb_booking_paid', 'osb_booking_rescheduled', 'osb_booking_deleted', 'osb_booking_admin_updated', 'osb_event_saved', 'osb_service_saved' ), true ) ) {
		$hooks[] = $tag;
	}
} );

// A scheduled custom email that would otherwise pick up recently-ended visits.
$ce = OSB_Custom_Emails::save( OSB_Custom_Emails::sanitize( array( 'name' => 'Thanks', 'enabled' => 1, 'trigger' => 'after_end', 'offset' => 1, 'unit' => 'hours', 'subject' => 'Thanks', 'body' => 'Thanks', 'statuses' => array( 'confirmed', 'completed' ) ) ) );

/* ---------------------------------------------------------------- Amelia */
require __DIR__ . '/fixtures/amelia-fake-tables.php';
osb_test_make_amelia();
ok( OSB_Import_Amelia::available(), 'Amelia tables detected' );

// Fake calendar connection for Alice (created as staff by the import) — we add it after staff exist, so pre-create Alice.
$alice = OSB_DB::insert( 'staff', array( 'name' => 'Alice Moor', 'email' => 'alice@example.com', 'status' => 'active', 'created_at' => current_time( 'mysql' ) ) );
$conn  = OSB_DB::insert( 'calendar_connections', array( 'staff_id' => $alice, 'provider' => 'google', 'via' => '', 'account' => 'a@example.com', 'access_token' => '', 'refresh_token' => '', 'expires_at' => 0, 'calendar_id' => 'cal1', 'calendar_name' => 'Work', 'push' => 1, 'pull' => 0, 'status' => 'active', 'created_at' => current_time( 'mysql' ) ) );

$data = OSB_Import_Amelia::dataset();
ok( is_array( $data ), 'Amelia dataset built' );
$job = OSB_Migrate::store( $data );
$p   = $job['plan']['counts'];
echo 'Plan: ' . wp_json_encode( $p ) . PHP_EOL;
echo 'Reasons: ' . wp_json_encode( $job['plan']['reasons'] ) . PHP_EOL;
echo 'Warnings: ' . wp_json_encode( $job['plan']['warnings'], JSON_UNESCAPED_UNICODE ) . PHP_EOL;
echo 'Emails: ' . wp_json_encode( $job['plan']['emails'], JSON_UNESCAPED_UNICODE ) . PHP_EOL;
ok( 2 === $p['services']['create'] && 1 === $p['staff']['create'] && 1 === $p['staff']['match'], 'plan: 2 new services, Dan new, Alice matched by email' );
ok( 5 === $p['bookings']['create'] && 1 === $p['bookings']['skip'] && isset( $job['plan']['reasons']['waiting'] ), 'plan: 5 bookings to create, waiting-list one left out' );
ok( 1 === $p['events']['create'], 'plan: 1 event' );

// Preview screen renders.
$_GET = array( 'page' => 'osb-import', 'from' => 'amelia', 'step' => 'preview' );
ob_start();
OSB_Admin_Import::page();
$html = ob_get_clean();
ok( false !== strpos( $html, 'What will happen' ) && false !== strpos( $html, '%booking_reference_code%' ), 'preview screen renders with unknown placeholder listed' );

OSB_Migrate::start( array( 'emails' => 1 ) );
$n = 0;
do {
	$s = OSB_Migrate::step( 8 );
	++$n;
} while ( is_array( $s ) && ! $s[0] && $n < 50 );
ok( is_array( $s ) && $s[0], 'Amelia import finished in ' . $n . ' step(s)' );
$res = OSB_Migrate::last_result()['res'];
echo 'Result: ' . wp_json_encode( $res ) . PHP_EOL;

global $wpdb;
$b    = OSB_Install::table( 'bookings' );
$rows = $wpdb->get_results( "SELECT type, service_id, event_id, staff_id, start_at, end_at, seats, customer_name, customer_email, status, payment_status, payment_method, amount, reminder_sent, followup_sent, internal_note FROM $b ORDER BY start_at", ARRAY_A );
foreach ( $rows as $row ) {
	echo '  ' . implode( ' | ', array_map( function ( $v ) { return str_replace( "\n", ' / ', (string) $v ); }, $row ) ) . PHP_EOL;
}
$by = array();
foreach ( $rows as $row ) {
	$by[ $row['start_at'] ] = $row;
}
ok( isset( $by['2025-03-10 10:00:00'] ) && 'completed' === $by['2025-03-10 10:00:00']['status'] && 'paid' === $by['2025-03-10 10:00:00']['payment_status'], 'past approved+paid → completed, paid; 10:00 UTC in March = 10:00 London' );
ok( isset( $by['2027-07-01 10:00:00'] ) && 'pending' === $by['2027-07-01 10:00:00']['status'] && 'Caroline Vale' === $by['2027-07-01 10:00:00']['customer_name'], 'future pending kept; 09:00 UTC in July = 10:00 BST; guest name from info' );
ok( isset( $by['2026-01-05 14:00:00'] ) && 'cancelled' === $by['2026-01-05 14:00:00']['status'], 'cancelled appointment → cancelled' );
ok( isset( $by['2027-02-01 18:00:00'] ) && 2 === (int) $by['2027-02-01 18:00:00']['seats'] && 'unpaid' === $by['2027-02-01 18:00:00']['payment_status'] && 30.0 === (float) $by['2027-02-01 18:00:00']['amount'], 'group booking: 2 people, part-paid → unpaid, amount 2 × 15' );
ok( isset( $by['2027-03-03 17:00:00'] ) && 'event' === $by['2027-03-03 17:00:00']['type'] && 'paypal' === $by['2027-03-03 17:00:00']['payment_method'], 'event booking imported, paid by PayPal' );
ok( 0 === $mails, 'no emails sent (' . $mails . ')' );
ok( ! $hooks, 'no osb_booking_* / saved hooks fired: ' . implode( ',', array_unique( $hooks ) ) );
ok( ! $http, 'no outgoing HTTP: ' . implode( ',', $http ) );

$tpl = OSB_Settings::get( 'emails' );
ok( 'Booked: {service_name}' === $tpl['customer_confirmed']['subject'] && false !== strpos( $tpl['customer_confirmed']['body'], '%booking_reference_code%' ) && false !== strpos( $tpl['customer_confirmed']['body'], '{date} {time}' ), 'approved template mapped, unknown placeholder kept visible' );
$customs = wp_list_pluck( OSB_Custom_Emails::all(), 'enabled', 'name' );
echo 'Custom emails: ' . wp_json_encode( $customs ) . PHP_EOL;
ok( isset( $customs['Amelia: Provider appointment pending'] ) && 0 === (int) $customs['Amelia: Provider appointment pending'], 'unmatched template kept as switched-off custom email' );

// Calendar: imported upcoming bookings are recorded as already in the calendar, so a sync makes no calls.
$ce_rows = $wpdb->get_results( 'SELECT object_key, remote_id FROM ' . OSB_Install::table( 'calendar_events' ), ARRAY_A );
echo 'Calendar records: ' . wp_json_encode( $ce_rows ) . PHP_EOL;
ok( 1 === count( $ce_rows ) && 'booking-' === substr( $ce_rows[0]['object_key'], 0, 8 ) && 0 === strpos( $ce_rows[0]['remote_id'], 'osb-imported-' ), 'upcoming booking for the connected staff member recorded as already in their calendar' );
ok( 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $b WHERE staff_id = $alice" ), 'Alice (matched by email) gets all 3 of her appointments' );
$stats = OSB_Calendar_Sync::sync( OSB_DB::get( 'calendar_connections', $conn ) );
ok( 0 === $stats[0] && 0 === $stats[1] && 0 === $stats[3] && ! $http, 'calendar sync afterwards: nothing to create/update for imported bookings (' . wp_json_encode( $stats ) . ')' );

// Cron jobs afterwards send nothing about the imported bookings.
OSB_Cron::send_reminders();
OSB_Cron::send_followups();
OSB_Cron::complete_past();
OSB_Custom_Emails::run();
ok( 0 === $mails, 'cron jobs after import send nothing (' . $mails . ')' );

// Run again: nothing new.
$hooks = array();
$job2  = OSB_Migrate::store( OSB_Import_Amelia::dataset() );
$p2    = $job2['plan']['counts'];
ok( 0 === $p2['services']['create'] && 0 === $p2['bookings']['create'] && 0 === $p2['events']['create'] && 0 === $p2['staff']['create'] && 0 === $p2['customers']['create'], 'second check: nothing new (' . wp_json_encode( $p2 ) . ')' );
OSB_Migrate::start( array() );
do {
	$s = OSB_Migrate::step( 8 );
} while ( is_array( $s ) && ! $s[0] );
$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $b" );
ok( 5 === $count, 'second run adds no bookings (total ' . $count . ')' );

/* ---------------------------------------------------------------- Wix CSV */
$tmp1 = wp_tempnam( 'b.csv' );
$tmp2 = wp_tempnam( 'c.csv' );
copy( __DIR__ . '/fixtures/wix-bookings-sample.csv', $tmp1 );
copy( __DIR__ . '/fixtures/wix-contacts-sample.csv', $tmp2 );
$w = OSB_Import_Wix::dataset_from_csv( $tmp1, $tmp2, 'auto' );
ok( is_array( $w ), 'Wix CSV dataset built' . ( is_wp_error( $w ) ? ': ' . $w->get_error_message() : '' ) );
echo 'Columns: ' . wp_json_encode( $w['columns'], JSON_UNESCAPED_UNICODE ) . PHP_EOL;
echo 'Services: ' . wp_json_encode( $w['services'] ) . PHP_EOL;
$job = OSB_Migrate::store( $w );
echo 'Plan: ' . wp_json_encode( $job['plan']['counts'] ) . ' reasons ' . wp_json_encode( $job['plan']['reasons'] ) . PHP_EOL;
echo 'Warnings: ' . wp_json_encode( $job['plan']['warnings'], JSON_UNESCAPED_UNICODE ) . PHP_EOL;
ok( 3 === $job['plan']['counts']['services']['create'], 'Wix: 3 services from distinct names' );
ok( 60 === $w['services'][ 'name:deep_tissue_massage' ]['duration'] && 50.0 === (float) $w['services'][ 'name:deep_tissue_massage' ]['price'] && 12.0 === (float) $w['services'][ 'name:beginners_pilates' ]['price'] && 20 === $w['services'][ 'name:free_consultation' ]['duration'], 'Wix: durations and per-person prices worked out' );
OSB_Migrate::start( array() );
do {
	$s = OSB_Migrate::step( 8 );
} while ( is_array( $s ) && ! $s[0] );
$res = OSB_Migrate::last_result()['res'];
echo 'Result: ' . wp_json_encode( $res ) . PHP_EOL;
ok( 4 === $res['bookings']['create'] && 1 === $res['bookings']['match'] && 1 === $res['bookings']['skip'], 'Wix: 4 created, duplicate row matched, waitlist left out' );
$hp = $wpdb->get_row( "SELECT * FROM $b WHERE customer_email = 'hannah@example.com'" );
ok( $hp && '2025-03-03 10:00:00' === $hp->start_at && 'completed' === $hp->status && 'paid' === $hp->payment_status && 50.0 === (float) $hp->amount, 'Wix: past paid booking imported as completed/paid at 10:00' );
$jo = $wpdb->get_row( "SELECT * FROM $b WHERE customer_email = 'jo@example.com'" );
ok( $jo && '2027-11-20 18:00:00' === $jo->start_at && 2 === (int) $jo->seats && 'unpaid' === $jo->payment_status, 'Wix: 6:00 PM read as 18:00, 2 participants, unpaid' );
$ken = $wpdb->get_row( "SELECT * FROM $b WHERE customer_email = 'ken@example.com'" );
ok( $ken && 'free' === $ken->payment_status && 'pending' === $ken->status, 'Wix: no price → free; pending kept' );
$cust = $wpdb->get_col( 'SELECT email FROM ' . OSB_Install::table( 'customers' ) );
ok( in_array( 'lena@example.com', $cust, true ) && in_array( 'hannah@example.com', $cust, true ), 'Wix: contacts saved as customer records' );
$again = OSB_Migrate::store( OSB_Import_Wix::dataset_from_csv( $tmp1, $tmp2, 'auto' ) );
ok( 0 === $again['plan']['counts']['bookings']['create'] && 0 === $again['plan']['counts']['services']['create'], 'Wix: second check finds nothing new' );
OSB_Migrate::discard();
@unlink( $tmp1 );
@unlink( $tmp2 );

// Date parsing.
ok( '2026-11-14 18:00:00' === OSB_Import_Wix::parse_when( '14.11.2026 18:00', 'dmy' ), 'parse 14.11.2026 18:00' );
ok( '2026-04-03 09:30:00' === OSB_Import_Wix::parse_when( '03/04/2026 9:30 am', 'dmy' ), 'parse 03/04/2026 dmy' );
ok( '2026-03-04 09:30:00' === OSB_Import_Wix::parse_when( '03/04/2026 9:30 am', 'mdy' ), 'parse 03/04/2026 mdy' );
ok( '2026-07-01 10:00:00' === OSB_Import_Wix::parse_when( '2026-07-01T09:00:00Z' ), 'parse ISO Z → BST' );
ok( '2026-11-14 10:00:00' === OSB_Import_Wix::parse_when( 'Saturday, November 14, 2026 10:00 AM' ), 'parse long English date' );

/* ---------------------------------------------------------------- Wix API (mocked responses shaped like Wix's docs) */
$calls = array();
$GLOBALS['osb_test_wix_mock'] = function ( $url, $args ) use ( &$calls ) {
	$calls[] = array( $url, $args['headers'] );
	$body    = json_decode( $args['body'], true );
	if ( false !== strpos( $url, '/services/query' ) ) {
		$out = array( 'services' => array(
			array( 'id' => 'svc-a', 'name' => 'Hot stone massage', 'type' => 'APPOINTMENT', 'defaultCapacity' => 1, 'hidden' => false, 'category' => array( 'name' => 'Massage' ), 'payment' => array( 'rateType' => 'FIXED', 'fixed' => array( 'price' => array( 'value' => '65.00', 'currency' => 'GBP' ) ) ), 'schedule' => array( 'availabilityConstraints' => array( 'sessionDurations' => array( 75 ), 'timeBetweenSessions' => 15 ) ), 'staffMemberIds' => array( 'res-1' ) ),
			array( 'id' => 'svc-c', 'name' => '6-week course', 'type' => 'COURSE', 'payment' => array( 'rateType' => 'FIXED', 'fixed' => array( 'price' => array( 'value' => '90', 'currency' => 'GBP' ) ) ) ),
		), 'pagingMetadata' => array( 'count' => 2, 'offset' => 0, 'total' => 2 ) );
	} elseif ( false !== strpos( $url, 'extended-bookings/query' ) ) {
		if ( empty( $body['query']['cursorPaging']['cursor'] ) ) {
			$out = array( 'extendedBookings' => array( array( 'booking' => array( 'id' => 'bk-1', 'bookedEntity' => array( 'slot' => array( 'serviceId' => 'svc-a', 'startDate' => '2027-06-01T08:00:00.000Z', 'endDate' => '2027-06-01T09:15:00.000Z', 'timezone' => 'Europe/London', 'resource' => array( 'id' => 'res-1', 'name' => 'Mia Hart', 'email' => 'mia@example.com' ) ), 'tags' => array( 'INDIVIDUAL' ) ), 'contactDetails' => array( 'firstName' => 'Pat', 'lastName' => 'Lee', 'email' => 'pat@example.com', 'phone' => '0777' ), 'status' => 'CONFIRMED', 'paymentStatus' => 'PAID', 'totalParticipants' => 1, 'createdDate' => '2026-09-01T10:00:00.000Z' ) ) ), 'pagingMetadata' => array( 'count' => 1, 'cursors' => array( 'next' => 'CUR2' ) ) );
		} else {
			$out = array( 'extendedBookings' => array( array( 'booking' => array( 'id' => 'bk-2', 'bookedEntity' => array( 'schedule' => array( 'serviceId' => 'svc-c', 'scheduleId' => 'x' ), 'tags' => array( 'COURSE' ) ), 'contactDetails' => array( 'firstName' => 'Sue', 'email' => 'sue@example.com' ), 'status' => 'CONFIRMED', 'paymentStatus' => 'NOT_PAID', 'startDate' => '2027-01-01T10:00:00Z', 'endDate' => '2027-02-01T10:00:00Z' ) ) ), 'pagingMetadata' => array( 'count' => 1, 'cursors' => array() ) );
		}
	} else {
		$out = array( 'contacts' => array( array( 'id' => 'c1', 'info' => array( 'name' => array( 'first' => 'Pat', 'last' => 'Lee' ) ), 'primaryInfo' => array( 'email' => 'pat@example.com', 'phone' => '0777' ) ) ), 'pagingMetadata' => array( 'count' => 1, 'offset' => 0, 'total' => 1 ) );
	}
	return array( 'headers' => array(), 'body' => wp_json_encode( $out ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
};
ok( true === OSB_Import_Wix::fetch_start( 'IST.secret-key', '1a2b3c4d-1111-2222-3333-444455556666' ), 'Wix API: fetch started' );
$stored = get_option( 'osb_migrate_wixfetch_1' );
ok( is_array( $stored ) && false === strpos( wp_json_encode( $stored ), 'IST.secret-key' ), 'Wix API: key not stored in plain text while fetching' );
$n = 0;
do {
	$r = OSB_Import_Wix::fetch_step( 8 );
	++$n;
} while ( is_array( $r ) && ! $r[0] && $n < 20 );
ok( is_array( $r ) && $r[0], 'Wix API: fetch finished' . ( is_wp_error( $r ) ? ': ' . $r->get_error_message() : '' ) );
ok( null === get_option( 'osb_migrate_wixfetch_1', null ), 'Wix API: key deleted after fetching' );
ok( 'IST.secret-key' === $calls[0][1]['Authorization'] && '1a2b3c4d-1111-2222-3333-444455556666' === $calls[0][1]['wix-site-id'], 'Wix API: headers Authorization + wix-site-id sent' );
echo 'Calls: ' . implode( ', ', wp_list_pluck( $calls, 0 ) ) . PHP_EOL;
$job = OSB_Migrate::store( $r[1] );
echo 'Plan: ' . wp_json_encode( $job['plan']['counts'] ) . ' reasons ' . wp_json_encode( $job['plan']['reasons'] ) . PHP_EOL;
OSB_Migrate::start( array() );
do {
	$s = OSB_Migrate::step( 8 );
} while ( is_array( $s ) && ! $s[0] );
$pat = $wpdb->get_row( "SELECT * FROM $b WHERE customer_email = 'pat@example.com'" );
ok( $pat && '2027-06-01 09:00:00' === $pat->start_at && '2027-06-01 10:15:00' === $pat->end_at && 'paid' === $pat->payment_status && 65.0 === (float) $pat->amount, 'Wix API: booking at 09:00 BST, 75 min, paid, £65' );
ok( isset( OSB_Migrate::last_result()['res'] ) && 1 === OSB_Migrate::last_result()['res']['bookings']['skip'], 'Wix API: course booking left out' );
unset( $GLOBALS['osb_test_wix_mock'] );
ok( 0 === $mails, 'still no emails sent' );

osb_t_done();
