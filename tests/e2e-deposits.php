<?php
/**
 * End-to-end check of Deposits & part payments inside WordPress Playground (SQLite).
 *
 * Self-contained: boots WordPress, activates the plugin, and fakes Stripe's API (nothing leaves the sandbox).
 * Run from the tools folder:
 *   npx wp-playground-cli php --mount-dir <repo> /wordpress/wp-content/plugins/osam-bookings --mount-dir <repo>/tests /tests -- /tests/e2e-deposits.php
 *
 * Prints one PASS/FAIL line per check and "ALL PASSED" only when everything passed (exit 1 otherwise).
 * Any PHP warning/notice/deprecation raised from the plugin's own files fails the run.
 *
 * @package OSAM_Bookings
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

$GLOBALS['osb_dep_fail']  = 0;
$GLOBALS['osb_dep_php']   = array();
$GLOBALS['osb_dep_other'] = 0;

/**
 * One check.
 *
 * @param bool   $cond Passed?
 * @param string $msg  What was checked.
 * @return bool
 */
function ok( $cond, $msg ) {
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . PHP_EOL;
	if ( ! $cond ) {
		++$GLOBALS['osb_dep_fail'];
	}
	return (bool) $cond;
}

set_error_handler(
	static function ( $no, $str, $file, $line ) {
		$rep = error_reporting();
		if ( 0 === $rep || 0 === ( $rep & ~( E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE ) ) ) {
			return false; // Silenced with @.
		}
		if ( false !== strpos( str_replace( '\\', '/', (string) $file ), '/plugins/osam-bookings/' ) ) {
			$line = 'PHP problem ' . $no . ': ' . $str . ' in ' . $file . ':' . $line;
			if ( ! in_array( $line, $GLOBALS['osb_dep_php'], true ) ) {
				$GLOBALS['osb_dep_php'][] = $line;
				echo $line . PHP_EOL;
			}
		} else {
			++$GLOBALS['osb_dep_other']; // WordPress core / Playground: counted, not failed.
		}
		return true;
	}
);

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

update_option( 'timezone_string', 'Europe/London' );
$r = activate_plugin( 'osam-bookings/osam-bookings.php' );
ok( ! is_wp_error( $r ), 'plugin activates' . ( is_wp_error( $r ) ? ': ' . $r->get_error_message() : '' ) );
if ( ! did_action( 'osb_loaded' ) ) {
	OSB_Plugin::instance();
}
OSB_Install::activate();
ok( '2.17.0' === get_option( 'osb_db_version' ) && OSB_Deposits::ready(), 'database at 2.17.0 (deposit columns added)' );

/* ---------------------------------------------------------------- Sandbox: emails, HTTP, fake Stripe */
$mails = array();
add_filter(
	'pre_wp_mail',
	function ( $pre, $atts ) use ( &$mails ) {
		$mails[] = array(
			'to'      => is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'],
			'subject' => (string) $atts['subject'],
			'body'    => (string) $atts['message'],
		);
		return true;
	},
	10,
	2
);
$stripe = array(); // Every Checkout Session asked for: the posted parameters.
$http   = array();
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$stripe, &$http ) {
		if ( 0 === strpos( $url, 'https://api.stripe.com/v1/checkout/sessions' ) && 'POST' === $args['method'] ) {
			parse_str( (string) $args['body'], $p );
			$stripe[] = $p;
			$id       = 'cs_test_' . count( $stripe );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/' . $id ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
			);
		}
		$http[] = $url;
		return new WP_Error( 'blocked', 'blocked in test' );
	},
	10,
	3
);
add_filter( 'osb_rate_limit', function () { return 10000; } );

OSB_Settings::update(
	array(
		'staff_mode'            => 'solo',
		'onsite_enabled'        => 1,
		'onsite_status'         => 'confirmed',
		'stripe_enabled'        => 1,
		'stripe_mode'           => 'test',
		'stripe_test_secret'    => 'sk_test_osb_e2e_not_a_real_key',
		'enable_invoices'       => 1,
		'inv_pay_button'        => 1,
		'inv_email_later'       => 1,
		'allow_customer_cancel' => 1,
		'cancel_cutoff_hours'   => 24,
		'min_notice_hours'      => 2,
		'enable_events'         => 1,
		'enable_repeat'         => 1,
		'repeat_types'          => array( 'service', 'resource', 'table' ),
		'repeat_freqs'          => array( 'weekly' ),
		'enable_restaurant'     => 1,
		'rest_payment_mode'     => 'deposit',
		'rest_deposit_type'     => 'per_cover',
		'rest_deposit_amount'   => 10,
		'rest_payment_min_party' => 1,
		'rest_confirm'          => 'auto',
		'rest_min_notice_minutes' => 60,
		'rest_max_advance_days' => 60,
		// Saved while the feature was on earlier: must be ignored while it's off.
		'deposit_type'          => 'percent',
		'deposit_amount'        => 25,
		'deposit_full'          => 1,
		'deposit_reminder_days' => 30,
	)
);
ok( ! OSB_Settings::feature( 'deposits' ) && 0 === (int) OSB_Settings::defaults()['enable_deposits'], 'Deposits & part payments is off by default' );
ok( isset( OSB_Settings::features()['deposits'] ) && 'enable_deposits' === OSB_Settings::features()['deposits'][3], 'it has a switch in Settings → Features' );

/* ---------------------------------------------------------------- Helpers */
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

// Run an admin-post handler: nonce in place, redirect caught instead of exit.
$admin_post = function ( $callable, $nonce_action, array $post ) {
	wp_set_current_user( 1 );
	$_POST             = array_merge( $post, array( '_wpnonce' => wp_create_nonce( $nonce_action ) ) );
	$_REQUEST          = $_POST;
	$GLOBALS['osb_rd'] = '';
	add_filter(
		'wp_redirect',
		function ( $location ) {
			$GLOBALS['osb_rd'] = $location;
			throw new RuntimeException( 'redirect' );
		}
	);
	try {
		call_user_func( $callable );
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	remove_all_filters( 'wp_redirect' );
	$_POST    = array();
	$_REQUEST = array();
	wp_set_current_user( 0 );
	return $GLOBALS['osb_rd'];
};

$save_service = function ( array $fields ) use ( $admin_post ) {
	$to = $admin_post(
		array( 'OSB_Admin_Services', 'save' ),
		'osb_save_service',
		array_merge(
			array(
				'duration'        => 60,
				'capacity'        => 1,
				'payment_methods' => array( 'onsite', 'stripe' ),
				'status'          => 'active',
			),
			$fields
		)
	);
	return preg_match( '/[?&]id=(\d+)/', $to, $m ) ? (int) $m[1] : 0;
};

// Weekday dates with free times, from 7 days ahead; each call hands out the next free hour.
$used = array();
$slot = function ( $sid, $from_days = 7 ) use ( $rest, &$used ) {
	for ( $i = $from_days; $i < $from_days + 30; $i++ ) {
		$date = wp_date( 'Y-m-d', time() + $i * DAY_IN_SECONDS );
		if ( (int) wp_date( 'N', strtotime( $date . ' 12:00:00' ) ) > 5 ) {
			continue;
		}
		$res = $rest( 'GET', '/availability/day', null, array( 'service_id' => $sid, 'date' => $date ) );
		foreach ( 200 === $res->get_status() ? (array) $res->get_data() : array() as $s ) {
			if ( ':00' === substr( $s['time'], 2 ) && empty( $used[ $date . ' ' . $s['time'] ] ) ) {
				$used[ $date . ' ' . $s['time'] ] = 1;
				return array( $date, $s['time'] );
			}
		}
	}
	return array( '', '' );
};

$book = function ( array $body ) use ( $rest ) {
	$res = $rest( 'POST', '/bookings', array_merge( array( 'seats' => 1, 'return_url' => home_url( '/book/' ), 'fields' => array( 'name' => 'Dee Posit', 'email' => 'dee@example.com', 'phone' => '0123' ) ), $body ) );
	$d   = $res->get_data();
	return array( $res->get_status(), $d, ! empty( $d['token'] ) ? OSB_DB::booking_by_token( $d['token'] ) : null );
};

$last_charge = function () use ( &$stripe ) {
	$p = end( $stripe );
	return $p ? round( (int) $p['line_items'][0]['price_data']['unit_amount'] / 100, 2 ) + 0.0 : -1.0;
};

$fresh = function ( $b ) {
	return OSB_DB::get( 'bookings', (int) $b->id );
};

$panel = function ( $b ) {
	ob_start();
	do_action( 'osb_admin_booking_side', $b );
	return (string) ob_get_clean();
};

$money = function ( $n ) {
	return OSB_Settings::money( $n );
};

// Playground's SQLite layer can't parse an unquoted column called `online`, so the plugin's own CREATE TABLE
// for these three restaurant tables fails there (it works on MySQL). Make them here, column for column.
global $wpdb;
$suppress = $wpdb->suppress_errors( true );
foreach (
	array(
		'rest_areas'  => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name varchar(191) NOT NULL DEFAULT '', `online` tinyint(1) NOT NULL DEFAULT 1, plan_width int(11) NOT NULL DEFAULT 1000, plan_height int(11) NOT NULL DEFAULT 640, status varchar(20) NOT NULL DEFAULT 'active', sort_order int(11) NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY (id)",
		'rest_tables' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT, area_id bigint(20) unsigned NOT NULL DEFAULT 0, name varchar(64) NOT NULL DEFAULT '', min_covers int(11) NOT NULL DEFAULT 1, max_covers int(11) NOT NULL DEFAULT 2, `online` tinyint(1) NOT NULL DEFAULT 1, priority int(11) NOT NULL DEFAULT 0, shape varchar(20) NOT NULL DEFAULT 'square', plan_x int(11) NOT NULL DEFAULT 40, plan_y int(11) NOT NULL DEFAULT 40, plan_w int(11) NOT NULL DEFAULT 80, plan_h int(11) NOT NULL DEFAULT 80, rotation int(11) NOT NULL DEFAULT 0, status varchar(20) NOT NULL DEFAULT 'active', sort_order int(11) NOT NULL DEFAULT 0, created_at datetime NOT NULL, PRIMARY KEY (id)",
		'rest_combos' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name varchar(191) NOT NULL DEFAULT '', table_ids varchar(255) NOT NULL DEFAULT '', min_covers int(11) NOT NULL DEFAULT 1, max_covers int(11) NOT NULL DEFAULT 4, `online` tinyint(1) NOT NULL DEFAULT 1, status varchar(20) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, PRIMARY KEY (id)",
	) as $tbl => $cols
) {
	if ( false === $wpdb->query( 'SELECT COUNT(*) FROM ' . OSB_Install::table( $tbl ) ) ) {
		$wpdb->query( 'CREATE TABLE ' . OSB_Install::table( $tbl ) . " ( $cols )" ); // phpcs:ignore
	}
}
$wpdb->suppress_errors( $suppress );

// Restaurant: one area, two tables for up to 4, open every evening.
$now  = current_time( 'mysql' );
$area = OSB_DB::insert( 'rest_areas', array( 'name' => 'Main room', 'online' => 1, 'status' => 'active', 'created_at' => $now ) );
OSB_DB::insert( 'rest_tables', array( 'area_id' => $area, 'name' => 'T1', 'min_covers' => 1, 'max_covers' => 4, 'online' => 1, 'status' => 'active', 'created_at' => $now ) );
OSB_DB::insert( 'rest_tables', array( 'area_id' => $area, 'name' => 'T2', 'min_covers' => 1, 'max_covers' => 4, 'online' => 1, 'status' => 'active', 'created_at' => $now ) );
OSB_DB::insert( 'rest_periods', array( 'name' => 'Dinner', 'weekdays' => '0,1,2,3,4,5,6', 'start_time' => '18:00:00', 'last_seating' => '21:00:00', 'slot_interval' => 15, 'status' => 'active', 'created_at' => $now ) );
OSB_Restaurant::flush();
$table_day = wp_date( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );

/* ================================================================ FEATURE OFF: nothing changes */
echo PHP_EOL . '--- Feature off ---' . PHP_EOL;

$s_off = $save_service( array( 'name' => 'Off massage', 'price' => '100.00', 'deposit_type' => 'percent', 'deposit_amount' => 50, 'deposit_full' => 0 ) );
$row   = OSB_DB::get( 'services', $s_off );
ok( $row && '' === $row->deposit_type && 0.0 === (float) $row->deposit_amount, 'feature off: deposit fields posted on the service screen are not saved' );
// Pretend it was given its own 50% deposit while the feature was on.
OSB_DB::update( 'services', $s_off, array( 'deposit_type' => 'percent', 'deposit_amount' => 50, 'deposit_full' => 0 ) );

ob_start();
OSB_Deposits::item_fields( OSB_DB::get( 'services', $s_off ) );
OSB_Deposits::settings_card( OSB_Settings::all() );
ok( '' === ob_get_clean(), 'feature off: no deposit fields on item screens or Settings → Payments' );
ok( ! isset( OSB_Deposits::status_choices( 'unpaid' )['part_paid'] ) && array_keys( OSB_Deposits::status_choices( 'paid' ) ) === array( 'unpaid', 'paid', 'refunded', 'free', 'card_held' ), 'feature off: staff Payment dropdown unchanged' );
ok( ! isset( OSB_Settings::email_labels()['customer_balance_due'] ) && ! OSB_Settings::email_available( 'customer_balance_due' ), 'feature off: "Balance due" email hidden and never sent' );
ok( ! isset( OSB_Frontend::global_config()['deposits'] ), 'feature off: booking form config unchanged' );
ok( array() === OSB_Deposits::tags(), 'feature off: no new placeholders listed' );

$svc_list = $rest( 'GET', '/services' )->get_data();
$svc_row  = current( array_filter( (array) $svc_list, function ( $x ) use ( $s_off ) { return (int) $x['id'] === $s_off; } ) );
ok( $svc_row && ! array_key_exists( 'deposit', $svc_row ), 'feature off: GET /services has no deposit info' );

list( $date, $time ) = $slot( $s_off );
list( $code, $res, $b ) = $book( array( 'type' => 'service', 'service_id' => $s_off, 'date' => $date, 'time' => $time, 'payment_method' => 'stripe' ) );
ok( 200 === $code && $b && 'pending_payment' === $b->status, 'feature off: online booking created, awaiting payment' );
ok( $b && 0.0 === (float) $b->deposit && 100.0 === (float) $b->amount, 'feature off: no deposit recorded, price £100' );
ok( 100.0 === $last_charge() && $b && 100.0 === OSB_Repeat::charge_total( $b ), 'feature off: Stripe asked for the full £100 (' . $last_charge() . ')' );
OSB_Bookings::mark_paid( $b, 'stripe', 'pi_off_1', 100 );
$b = $fresh( $b );
ok( 'paid' === $b->payment_status && 'confirmed' === $b->status && 0.0 === (float) $b->amount_paid, 'feature off: paid in full → Paid, confirmed (amount_paid untouched)' );
ok( 0.0 === OSB_Payments::outstanding( $b ), 'feature off: nothing outstanding' );
$d = OSB_Bookings::details( $b );
ok( ! isset( $d['deposit_paid'] ) && ! isset( $d['balance_due'] ) && ! isset( $d['deposit_due'] ) && 'Paid' === $d['payment_status'], 'feature off: booking details unchanged' );
ok( false === strpos( $panel( $b ), 'osb-deposit-card' ), 'feature off: no Deposit card on the admin booking screen' );
$ph = OSB_Emails::placeholders( $b );
ok( '' === $ph['{balance_due}'] && '' === $ph['{deposit_paid}'], 'feature off: {balance_due}/{deposit_paid} are empty' );
ok( false === strpos( OSB_Emails::details_card( $b, $d, OSB_Emails::design() ), 'Deposit' ), 'feature off: email details card unchanged' );

// Pay on arrival is untouched.
list( $date, $time ) = $slot( $s_off );
list( $code, $res, $b_on ) = $book( array( 'type' => 'service', 'service_id' => $s_off, 'date' => $date, 'time' => $time, 'payment_method' => 'onsite' ) );
ok( $b_on && 'confirmed' === $b_on->status && 'unpaid' === $b_on->payment_status && 0.0 === (float) $b_on->deposit, 'feature off: pay on arrival confirmed, unpaid, no deposit' );

// Restaurant deposit works as it always has.
list( $code, $res, $t_off ) = $book( array( 'type' => 'table', 'date' => $table_day, 'time' => '19:00', 'seats' => 2, 'payment_method' => 'stripe' ) );
ok( 200 === $code && $t_off && 20.0 === (float) $t_off->amount && 0.0 === (float) $t_off->deposit && 20.0 === $last_charge(), 'feature off: table deposit £20 for 2 charged as before' . ( 200 !== $code ? ' — ' . wp_json_encode( $res ) : '' ) );
if ( $t_off ) {
	OSB_Bookings::mark_paid( $t_off, 'stripe', 'pi_off_t', 20 );
	$t_off = $fresh( $t_off );
}
ok( $t_off && 'paid' === $t_off->payment_status, 'feature off: paid table deposit shows as Paid (as before)' );
$mails_before = count( $mails );
OSB_Deposits::send_balance_reminders();
ok( count( $mails ) === $mails_before, 'feature off: balance reminder job sends nothing' );

/* ================================================================ FEATURE ON */
echo PHP_EOL . '--- Feature on ---' . PHP_EOL;
OSB_Settings::update( array( 'enable_deposits' => 1 ) );
ok( OSB_Settings::feature( 'deposits' ), 'feature switched on' );
ok( isset( OSB_Settings::email_labels()['customer_balance_due'] ) && OSB_Settings::email_available( 'customer_balance_due' ) && isset( OSB_Settings::email_sections()['deposits'] ), '"Balance due" email listed in its own Deposits section' );
ok( isset( OSB_Admin_Custom_Emails::standard_when()['customer_balance_due'] ), '"Sent when" text for the Balance due email' );

// Site default 25% (inherited), and a service with its own £30 per booking, no "pay in full".
$s1 = $save_service( array( 'name' => 'Deep tissue', 'price' => '100.00', 'deposit_type' => '', 'deposit_amount' => '0', 'deposit_full' => '1' ) );
$s2 = $save_service( array( 'name' => 'Facial', 'price' => '80.00', 'deposit_type' => 'per_booking', 'deposit_amount' => '30', 'deposit_full' => '0' ) );
$r2 = OSB_DB::get( 'services', $s2 );
ok( $r2 && 'per_booking' === $r2->deposit_type && 30.0 === (float) $r2->deposit_amount && 0 === (int) $r2->deposit_full, 'service screen saves its own deposit (£30 per booking, no pay-in-full)' );
ok( '' === OSB_DB::get( 'services', $s1 )->deposit_type, 'service left on the site default' );

$svc_list = $rest( 'GET', '/services' )->get_data();
$by_id    = array();
foreach ( (array) $svc_list as $x ) {
	$by_id[ (int) $x['id'] ] = $x;
}
ok( isset( $by_id[ $s1 ]['deposit']['mode'] ) && 'percent' === $by_id[ $s1 ]['deposit']['mode'] && 25.0 === (float) $by_id[ $s1 ]['deposit']['amount'] && true === $by_id[ $s1 ]['deposit']['full'], 'GET /services: inherited 25% deposit, pay in full allowed' );
ok( isset( $by_id[ $s2 ]['deposit']['mode'] ) && 'per_booking' === $by_id[ $s2 ]['deposit']['mode'] && false === $by_id[ $s2 ]['deposit']['full'], 'GET /services: own £30 deposit' );
ok( ! empty( OSB_Frontend::global_config()['deposits'] ) && ! empty( OSB_Frontend::global_config()['i18n']['depPayDeposit'] ), 'booking form told about deposits' );

/* ---- Percentage deposit on a service */
list( $date, $time ) = $slot( $s1 );
list( $code, $res, $b1 ) = $book( array( 'type' => 'service', 'service_id' => $s1, 'date' => $date, 'time' => $time, 'payment_method' => 'stripe' ) );
ok( 200 === $code && $b1 && 25.0 === (float) $b1->deposit && 100.0 === (float) $b1->amount && 'pending_payment' === $b1->status, 'service 25%: booking for £100 asks a £25 deposit' );
ok( 25.0 === $last_charge(), 'service 25%: Stripe asked for £25 (' . $last_charge() . ')' );
ok( ! empty( $res['details']['deposit_due'] ) && $money( 25 ) === $res['details']['deposit_due'], 'service 25%: booking response shows the deposit to pay now' );
$mails_before = count( $mails );
OSB_Bookings::mark_paid( $b1, 'stripe', 'pi_dep_1', 25 );
$b1 = $fresh( $b1 );
ok( 'part_paid' === $b1->payment_status && 25.0 === (float) $b1->amount_paid && 'confirmed' === $b1->status, 'deposit paid → "Deposit paid" (part_paid), £25 received, confirmed' );
ok( 75.0 === OSB_Deposits::balance( $b1 ) && 75.0 === OSB_Payments::outstanding( $b1 ) && 75.0 === OSB_Payments::payable( $b1 ), 'balance £75 outstanding and payable online' );
$d = OSB_Bookings::details( $b1 );
ok( 'Deposit paid' === $d['payment_status'] && $money( 25 ) === $d['deposit_paid'] && $money( 75 ) === $d['balance_due'], 'customer booking page details: Deposit paid £25, balance £75' );
ok( $money( 75 ) === $d['amount_due'] && 'Pay the balance' === $d['pay_label'] && ! empty( $d['pay_url'] ), 'customer booking page: "Pay the balance — £75" button' );
$conf = array_values( array_filter( array_slice( $mails, $mails_before ), function ( $m ) { return 'dee@example.com' === $m['to']; } ) );
ok( $conf && false !== strpos( $conf[0]['body'], 'Deposit paid' ) && false !== strpos( $conf[0]['body'], 'Balance to pay' ) && false !== strpos( $conf[0]['body'], $money( 75 ) ), 'confirmation email details card shows Deposit paid and Balance to pay £75' );
$ph = OSB_Emails::placeholders( $b1 );
ok( esc_html( $money( 25 ) ) === $ph['{deposit_paid}'] && esc_html( $money( 75 ) ) === $ph['{balance_due}'], '{deposit_paid} = £25, {balance_due} = £75' );
$inv = OSB_Invoices::for_booking( $b1, 'invoice' );
ok( 1 === count( $inv ) && 25.0 === (float) $inv[0]->total && 0 === strpos( $inv[0]->lines[0]['desc'], 'Deposit for' ), 'invoice for the deposit: £25, "Deposit for …"' );
ok( $inv && 75.0 === OSB_Invoices::balance_due( $inv[0] ) && '' !== OSB_Invoices::pay_url( $inv[0] ), 'deposit invoice: "Still to pay" £75 with a Pay now link' );
$pdf = $inv ? OSB_Invoices::pdf( $inv[0] ) : '';
ok( is_string( $pdf ) && 0 === strpos( $pdf, '%PDF' ), 'deposit invoice PDF renders' );
ok( ' · ' . sprintf( '%s to pay', $money( 75 ) ) === OSB_Deposits::list_suffix( $b1 ), 'admin bookings list: "Deposit paid · £75 to pay"' );
$p = $panel( $b1 );
ok( false !== strpos( $p, 'osb-deposit-card' ) && false !== strpos( $p, 'osb_deposit_balance' ) && false !== strpos( $p, 'value="75.00"' ), 'admin booking screen: Deposit card with "Record payment" for £75' );
ok( isset( OSB_Deposits::status_choices( 'part_paid' )['part_paid'] ), 'staff Payment dropdown shows "Deposit paid" on a part-paid booking' );
wp_set_current_user( 1 );
$_GET = array( 'page' => 'osb-bookings', 'action' => 'view', 'id' => $b1->id );
ob_start();
OSB_Admin_Bookings::page();
$view = (string) ob_get_clean();
$_GET = array( 'page' => 'osb-bookings' );
ob_start();
OSB_Admin_Bookings::page();
$list = (string) ob_get_clean();
$_GET = array();
ob_start();
OSB_Deposits::item_fields( OSB_DB::get( 'services', $s2 ) );
OSB_Deposits::settings_card( OSB_Settings::all() );
$forms = (string) ob_get_clean();
wp_set_current_user( 0 );
ok( false !== strpos( $view, 'Deposit paid' ) && false !== strpos( $view, 'osb-deposit-card' ) && false !== strpos( $list, 'Deposit paid · ' ), 'admin booking screen and bookings list render with "Deposit paid"' );
ok( false !== strpos( $forms, 'name="deposit_type"' ) && false !== strpos( $forms, 'name="deposit_reminder_days"' ) && false !== strpos( $forms, 'value="per_booking" selected' ), 'deposit fields on the service screen and Settings → Payments' );
wp_set_current_user( 1 );
$cal = $rest( 'GET', '/admin/cal/item', null, array( 'kind' => 'booking', 'id' => $b1->id ) )->get_data();
wp_set_current_user( 0 );
ok( isset( $cal['html'] ) && false !== strpos( $cal['html'], 'Deposit paid' ) && false !== strpos( $cal['html'], 'Mark balance paid' ), 'calendar panel: Deposit paid, balance, "Mark balance paid"' );
$card = OSB_Portal::card( $b1 );
ok( false !== strpos( wp_json_encode( $card['rows'] ), 'Deposit paid' ) && false !== strpos( $card['pay_label'], $money( 75 ) ), 'My bookings card: Deposit paid row and Pay the balance — £75' );
$page = OSB_Frontend::pay_page_html( $b1 );
ok( false !== strpos( $page, 'Pay the rest of your booking' ) && false !== strpos( $page, $money( 75 ) ), 'Pay now page: "Pay the rest of your booking", £75 due' );

/* ---- Pay now takes the balance online */
$n_before = count( $stripe );
$start    = OSB_Payments::start_payment( $b1, 'stripe' );
$sp       = end( $stripe );
ok( is_array( $start ) && ! empty( $start['redirect'] ) && count( $stripe ) === $n_before + 1 && 75.0 === $last_charge() && '1' === ( $sp['metadata']['osb_pay'] ?? '' ), 'Pay now: Stripe checkout for the £75 balance' );
$mails_before = count( $mails );
OSB_Bookings::mark_paid( $fresh( $b1 ), 'stripe', 'pi_bal_1', 75 );
$b1 = $fresh( $b1 );
ok( 'paid' === $b1->payment_status && 100.0 === (float) $b1->amount_paid && 'confirmed' === $b1->status, 'balance paid online → Paid, £100 received' );
ok( 0.0 === OSB_Payments::outstanding( $b1 ) && '' === OSB_Bookings::details( $b1 )['pay_url'], 'nothing left to pay, no Pay now button' );
$inv = OSB_Invoices::for_booking( $b1, 'invoice' );
ok( 2 === count( $inv ) && 75.0 === (float) $inv[1]->total && 0 === strpos( $inv[1]->lines[0]['desc'], 'Balance for' ), 'second invoice for the balance: £75, "Balance for …"' );
ok( (bool) array_filter( array_slice( $mails, $mails_before ), function ( $m ) { return false !== strpos( $m['subject'], 'invoice' ); } ), 'balance receipt (invoice) emailed' );

/* ---- Fixed deposit, no pay-in-full option: the customer's request is ignored */
list( $date, $time ) = $slot( $s2 );
list( $code, $res, $b2 ) = $book( array( 'type' => 'service', 'service_id' => $s2, 'date' => $date, 'time' => $time, 'payment_method' => 'stripe', 'pay_in_full' => 1 ) );
ok( $b2 && 30.0 === (float) $b2->deposit && 30.0 === $last_charge(), 'service £30 fixed: deposit £30 charged (pay in full not offered)' );

/* ---- Customer chooses to pay in full */
list( $date, $time ) = $slot( $s1 );
list( $code, $res, $bf ) = $book( array( 'type' => 'service', 'service_id' => $s1, 'date' => $date, 'time' => $time, 'payment_method' => 'stripe', 'pay_in_full' => 1 ) );
ok( $bf && 0.0 === (float) $bf->deposit && 100.0 === $last_charge(), 'customer chose pay in full: no deposit, Stripe asked for £100' );
OSB_Bookings::mark_paid( $bf, 'stripe', 'pi_full_1', 100 );
ok( 'paid' === $fresh( $bf )->payment_status, 'paid in full → Paid' );

/* ---- Pay on arrival: no deposit */
list( $date, $time ) = $slot( $s1 );
list( $code, $res, $bo ) = $book( array( 'type' => 'service', 'service_id' => $s1, 'date' => $date, 'time' => $time, 'payment_method' => 'onsite' ) );
ok( $bo && 0.0 === (float) $bo->deposit && 'confirmed' === $bo->status && 'unpaid' === $bo->payment_status, 'pay on arrival: no deposit, works as before' );

/* ---- Events: fixed per person and percentage */
$ev_start = wp_date( 'Y-m-d 18:00:00', time() + 20 * DAY_IN_SECONDS );
$ev_end   = wp_date( 'Y-m-d 20:00:00', time() + 20 * DAY_IN_SECONDS );
$e1       = OSB_DB::insert( 'events', array( 'name' => 'Wine tasting', 'start_at' => $ev_start, 'end_at' => $ev_end, 'capacity' => 20, 'max_per_booking' => 6, 'price' => 40, 'status' => 'active', 'deposit_type' => 'per_person', 'deposit_amount' => 10, 'deposit_full' => 1, 'created_at' => $now ) );
$e2       = OSB_DB::insert( 'events', array( 'name' => 'Pottery day', 'start_at' => $ev_start, 'end_at' => $ev_end, 'capacity' => 20, 'max_per_booking' => 6, 'price' => 90, 'status' => 'active', 'deposit_type' => 'percent', 'deposit_amount' => 20, 'deposit_full' => 0, 'created_at' => $now ) );
$evs      = $rest( 'GET', '/events' )->get_data();
$ev_dep   = array();
foreach ( (array) $evs as $x ) {
	$ev_dep[ (int) $x['id'] ] = $x['deposit'] ?? null;
}
ok( isset( $ev_dep[ $e1 ]['mode'] ) && 'per_person' === $ev_dep[ $e1 ]['mode'] && isset( $ev_dep[ $e2 ]['mode'] ) && 'percent' === $ev_dep[ $e2 ]['mode'], 'GET /events: each event\'s own deposit' );
list( $code, $res, $be1 ) = $book( array( 'type' => 'event', 'event_id' => $e1, 'seats' => 3, 'payment_method' => 'stripe' ) );
ok( $be1 && 120.0 === (float) $be1->amount && 30.0 === (float) $be1->deposit && 30.0 === $last_charge(), 'event £10 per person × 3: deposit £30 of £120' . ( ! $be1 ? ' — ' . wp_json_encode( $res ) : '' ) );
list( $code, $res, $be2 ) = $book( array( 'type' => 'event', 'event_id' => $e2, 'seats' => 2, 'payment_method' => 'stripe' ) );
ok( $be2 && 180.0 === (float) $be2->amount && 36.0 === (float) $be2->deposit && 36.0 === $last_charge(), 'event 20% × 2 places: deposit £36 of £180' );
OSB_Bookings::mark_paid( $be2, 'stripe', 'pi_ev2', 36 );
$be2 = $fresh( $be2 );
ok( 'part_paid' === $be2->payment_status && 144.0 === OSB_Deposits::balance( $be2 ), 'event deposit paid → Deposit paid, £144 to pay' );

/* ---- Courses and resources use their own deposit too (the rule as applied to the row being saved) */
$co  = OSB_DB::insert( 'courses', array( 'name' => 'Six-week yoga', 'price' => 120, 'status' => 'active', 'deposit_type' => 'per_booking', 'deposit_amount' => 40, 'created_at' => $now ) );
$res_id = OSB_DB::insert( 'resources', array( 'name' => 'Meeting room', 'status' => 'active', 'deposit_type' => 'percent', 'deposit_amount' => 10, 'created_at' => $now ) );
$row_c  = OSB_Deposits::insert_data( array( 'type' => 'course', 'course_id' => $co, 'amount' => 120, 'seats' => 1, 'status' => 'pending_payment' ), array() );
$row_r  = OSB_Deposits::insert_data( array( 'type' => 'resource', 'amount' => 250, 'seats' => 1, 'status' => 'pending_payment' ), array( 'resource_ids' => array( $res_id ) ) );
$row_a  = OSB_Deposits::insert_data( array( 'type' => 'resource', 'amount' => 250, 'seats' => 1, 'status' => 'pending_payment' ), array( 'resource_ids' => array( $res_id ), 'admin' => true ) );
ok( 40.0 === (float) ( $row_c['deposit'] ?? 0 ) && 25.0 === (float) ( $row_r['deposit'] ?? 0 ) && ! isset( $row_a['deposit'] ), 'course £40 deposit, resource 10% of £250 = £25; staff bookings never take a deposit' );
ok( isset( OSB_Resources::json( OSB_DB::get( 'resources', $res_id ) )['deposit']['mode'] ), 'resource list for the booking form includes its deposit' );

/* ---- Balance paid in person (staff), in two goes */
$r1 = OSB_Deposits::record_payment( $be2, 44, false );
ok( ! is_wp_error( $r1 ) && 'part_paid' === $r1->payment_status && 100.0 === OSB_Deposits::balance( $r1 ), 'staff record £44 in person → still Deposit paid, £100 left' );
wp_set_current_user( 1 );
$req = new WP_REST_Request( 'POST', '/osb/v1/admin/cal/action' );
$req->set_body_params( array( 'id' => $be2->id, 'act' => 'paid', 'notify' => 0 ) );
$cal_res = rest_do_request( $req );
wp_set_current_user( 0 );
$be2 = $fresh( $be2 );
ok( 200 === $cal_res->get_status() && 'paid' === $be2->payment_status && 180.0 === (float) $be2->amount_paid, 'calendar "Mark balance paid" → Paid, £180 received' );
$manual = array_values( array_filter( OSB_DB::payments( $be2->id ), function ( $p ) { return 'manual' === $p->gateway; } ) );
ok( 2 === count( $manual ) && 44.0 === (float) $manual[0]->amount && 100.0 === (float) $manual[1]->amount, 'in-person payments recorded for £44 and £100 (not the full price again)' );
$inv = OSB_Invoices::for_booking( $be2, 'invoice' );
ok( 3 === count( $inv ) && 0 === strpos( end( $inv )->lines[0]['desc'], 'Balance for' ), 'invoices: deposit, part payment, balance' );

/* ---- Staff mark Paid from the booking screen's Payment dropdown */
OSB_Bookings::mark_paid( $be1, 'stripe', 'pi_ev1', 30 );
$admin_post( array( 'OSB_Admin_Bookings', 'update' ), 'osb_update_booking_' . $be1->id, array( 'id' => $be1->id, 'status' => 'confirmed', 'payment_status' => 'paid' ) );
$be1    = $fresh( $be1 );
$manual = array_values( array_filter( OSB_DB::payments( $be1->id ), function ( $p ) { return 'manual' === $p->gateway; } ) );
ok( 'paid' === $be1->payment_status && 120.0 === (float) $be1->amount_paid && 1 === count( $manual ) && 90.0 === (float) $manual[0]->amount, 'booking screen Payment → Paid records just the £90 balance' );

/* ---- Cancelling a part-paid booking */
list( $date, $time ) = $slot( $s1 );
list( $code, $res, $bc ) = $book( array( 'type' => 'service', 'service_id' => $s1, 'date' => $date, 'time' => $time, 'payment_method' => 'stripe' ) );
OSB_Bookings::mark_paid( $bc, 'stripe', 'pi_cancel', 25 );
$c = $rest( 'POST', '/bookings/' . $bc->token . '/cancel' );
$bc = $fresh( $bc );
ok( 200 === $c->get_status() && 'cancelled' === $bc->status && 'part_paid' === $bc->payment_status && 25.0 === (float) $bc->amount_paid, 'customer cancels a part-paid booking: cancelled, deposit kept on record' );
ok( 0.0 === OSB_Payments::outstanding( $bc ) && empty( OSB_Bookings::details( $bc )['balance_due'] ) && '' === OSB_Deposits::list_suffix( $bc ), 'cancelled: the balance is no longer due' );
ok( false !== strpos( $panel( $bc ), 'has been kept' ), 'admin booking screen explains the deposit was kept and how to refund it' );
$admin_post( array( 'OSB_Admin_Bookings', 'update' ), 'osb_update_booking_' . $bc->id, array( 'id' => $bc->id, 'status' => 'cancelled', 'payment_status' => 'refunded' ) );
$bc = $fresh( $bc );
$cn = OSB_Invoices::for_booking( $bc, 'credit_note' );
ok( 'refunded' === $bc->payment_status && 1 === count( $cn ) && 25.0 === (float) $cn[0]->total, 'refund → credit note for the £25 deposit (what was paid)' );

/* ---- Repeat booking: each date's deposit taken together */
list( $date, $time ) = $slot( $s1, 8 );
$prev = $rest( 'POST', '/repeat/preview', array( 'type' => 'service', 'service_id' => $s1, 'date' => $date, 'time' => $time, 'seats' => 1, 'repeat' => array( 'freq' => 'weekly', 'count' => 3 ) ) )->get_data();
ok( isset( $prev['deposit_total'] ) && 3 === (int) $prev['ok'] && 75.0 === (float) $prev['deposit_total'] && 300.0 === (float) $prev['total'], 'repeat preview: 3 dates, £300, deposits £75' . ( isset( $prev['ok'] ) ? '' : ' — ' . wp_json_encode( $prev ) ) );
list( $code, $res, $lead ) = $book( array( 'type' => 'service', 'service_id' => $s1, 'date' => $date, 'time' => $time, 'payment_method' => 'stripe', 'repeat' => array( 'freq' => 'weekly', 'count' => 3 ) ) );
$series = $lead ? OSB_Repeat::series( $lead ) : array();
ok( 3 === count( $series ) && 75.0 === $last_charge(), 'repeat: 3 dates booked, first payment asks for 3 × £25 = £75 (' . $last_charge() . ')' );
if ( $lead ) {
	OSB_Bookings::mark_paid( $lead, 'stripe', 'pi_series', 75 );
}
$series = $lead ? OSB_Repeat::series( $lead ) : array();
$okall  = 3 === count( $series );
foreach ( $series as $x ) {
	$okall = $okall && 'part_paid' === $x->payment_status && 25.0 === (float) $x->amount_paid && 'confirmed' === $x->status && 75.0 === OSB_Payments::outstanding( $x );
}
ok( $okall, 'repeat: every date Deposit paid (£25 each), confirmed, £75 balance each' );

/* ---- Reports include deposits received */
$sum = OSB_Reports::summary( wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ), wp_date( 'Y-m-d', time() + 90 * DAY_IN_SECONDS ) );
global $wpdb;
$t    = OSB_Install::table( 'bookings' );
$want = (float) $wpdb->get_var( "SELECT SUM(CASE WHEN payment_status = 'paid' THEN amount WHEN payment_status = 'part_paid' THEN amount_paid ELSE 0 END) FROM $t WHERE status <> 'pending_payment'" ); // phpcs:ignore
$owed = (float) $wpdb->get_var( "SELECT SUM(CASE WHEN payment_status = 'unpaid' AND status IN ('pending','confirmed','completed') THEN amount WHEN payment_status = 'part_paid' AND status IN ('pending','confirmed','completed') THEN amount - amount_paid ELSE 0 END) FROM $t WHERE status <> 'pending_payment'" ); // phpcs:ignore
ok( abs( $sum['revenue'] - $want ) < 0.01 && abs( $sum['outstanding'] - $owed ) < 0.01, 'reports: revenue counts deposits received (' . $sum['revenue'] . '), outstanding counts balances (' . $sum['outstanding'] . ')' );

/* ---- Balance reminder email */
$wpdb->query( $wpdb->prepare( "UPDATE $t SET created_at = %s WHERE series_id = %d", wp_date( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ), (int) $lead->id ) ); // phpcs:ignore
$mails_before = count( $mails );
OSB_Cron::run();
$sent = array_filter( array_slice( $mails, $mails_before ), function ( $m ) { return 0 === strpos( $m['subject'], 'Balance due for' ); } );
ok( 3 === count( $sent ) && false !== strpos( current( $sent )['body'], $money( 75 ) ) && false !== strpos( current( $sent )['body'], $money( 25 ) ), 'the 5-minute job sends "Balance due" for each part-paid date (with £25 paid / £75 due)' );
$mails_before = count( $mails );
OSB_Deposits::send_balance_reminders();
ok( count( $mails ) === $mails_before, 'balance reminder is sent once' );

/* ---- Restaurant table deposit: same part-paid state */
list( $code, $res, $tb ) = $book( array( 'type' => 'table', 'date' => $table_day, 'time' => '19:30', 'seats' => 3, 'payment_method' => 'stripe' ) );
ok( 200 === $code && $tb && 30.0 === (float) $tb->amount && 30.0 === (float) $tb->deposit && 30.0 === $last_charge(), 'table: £10 per guest × 3 = £30 deposit charged as before' . ( 200 !== $code ? ' — ' . wp_json_encode( $res ) : '' ) );
if ( $tb ) {
	OSB_Bookings::mark_paid( $tb, 'stripe', 'pi_table', 30 );
	$tb = $fresh( $tb );
}
$td = $tb ? OSB_Bookings::details( $tb ) : array();
ok( $tb && 'part_paid' === $tb->payment_status && 30.0 === (float) $tb->amount_paid && 'confirmed' === $tb->status && ! empty( $td['balance_note'] ) && 0.0 === OSB_Payments::outstanding( $tb ), 'table deposit paid → Deposit paid, rest of the bill on the night (no Pay now)' );
$tr = $tb ? OSB_Deposits::record_payment( $tb, 55.5, false ) : null;
ok( $tr && ! is_wp_error( $tr ) && 'paid' === $tr->payment_status && 85.5 === (float) $tr->amount && 85.5 === (float) $tr->amount_paid, 'staff record the rest of the bill (£55.50) → Paid, total £85.50' );

/* ---------------------------------------------------------------- Done */
ok( ! $http, 'no other outgoing HTTP: ' . implode( ', ', array_unique( $http ) ) );
ok( ! $GLOBALS['osb_dep_php'], 'no PHP warnings or notices from the plugin (' . count( $GLOBALS['osb_dep_php'] ) . ')' );
if ( $GLOBALS['osb_dep_other'] ) {
	echo 'Note: ' . (int) $GLOBALS['osb_dep_other'] . ' PHP notice(s) from WordPress itself were ignored.' . PHP_EOL;
}

$fails = (int) $GLOBALS['osb_dep_fail'];
echo PHP_EOL . ( $fails ? $fails . ' FAILED' : 'ALL PASSED' ) . PHP_EOL;
exit( $fails ? 1 : 0 );
