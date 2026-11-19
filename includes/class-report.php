<?php
/**
 * Daily status report to OSAM's update server, so OSAM can see how every client site is doing
 * (versions, scheduled jobs, problem counts, which features and payment methods are on).
 *
 * Sent once a day as a small JSON POST to {update server}/v1/report, separately from the hourly
 * update check, so the update check stays small and keeps working whatever happens here. Update
 * results (from → to, ok or failed, error message) are sent to {update server}/v1/event straight
 * after an update of this plugin, or when a version OSAM requires couldn't be installed.
 *
 * Only operational information is sent: versions, settings switches, counts. Never customer
 * names, emails, phone numbers, booking details, or the text of problem-log messages other than
 * the latest 'updates' and 'cron' message (those never contain customer details).
 *
 * Turn off with define( 'OSB_REPORT', false ); in wp-config.php (updates keep working). Nothing is
 * sent either when updates from OSAM are off (OSB_UPDATES false).
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status report.
 */
class OSB_Report {

	const SCHEMA = 1;
	const SENT   = 'osb_report_sent';   // [ time, ok, code ] of the last daily report.
	const QUEUE  = 'osb_report_events'; // Update results waiting to be sent.

	/**
	 * Set while OSB_Updater::enforce() installs a required version.
	 *
	 * @var bool
	 */
	public static $forced = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! self::on() ) {
			return;
		}
		add_action( 'osb_cron', array( __CLASS__, 'maybe_send' ), 100 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 20, 2 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download_result' ), 99, 4 );
	}

	/**
	 * Is reporting switched on?
	 *
	 * @return bool
	 */
	public static function on() {
		if ( defined( 'OSB_REPORT' ) && ! OSB_REPORT ) {
			return false;
		}
		return ! ( defined( 'OSB_UPDATES' ) && ! OSB_UPDATES );
	}

	/**
	 * Address of the update server.
	 *
	 * @return string
	 */
	private static function server() {
		return class_exists( 'OSB_Updater' ) ? OSB_Updater::server() : 'https://updates.osamweb.com';
	}

	/**
	 * Send the daily report when it's due (runs from the 5-minute job), and any queued update results.
	 */
	public static function maybe_send() {
		if ( ! self::on() ) {
			return;
		}
		self::flush_events();
		$last = get_option( self::SENT, array() );
		$last = is_array( $last ) ? $last : array();
		$wait = ! empty( $last['ok'] ) ? DAY_IN_SECONDS : 3 * HOUR_IN_SECONDS; // Retry sooner after a failure.
		if ( time() - (int) ( $last['time'] ?? 0 ) < $wait || get_transient( 'osb_report_lock' ) ) {
			return;
		}
		set_transient( 'osb_report_lock', 1, 5 * MINUTE_IN_SECONDS );
		self::send();
		delete_transient( 'osb_report_lock' );
	}

	/**
	 * Build and send the report now.
	 *
	 * @return bool Sent.
	 */
	public static function send() {
		$res  = self::post( '/v1/report', array( 'report' => self::build() ) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		update_option(
			self::SENT,
			array(
				'time' => time(),
				'ok'   => $code >= 200 && $code < 300,
				'code' => $code,
			),
			false
		);
		return $code >= 200 && $code < 300;
	}

	/**
	 * POST JSON to the update server, with the fields every request carries.
	 *
	 * @param string $path    Path.
	 * @param array  $payload Extra fields.
	 * @return array|WP_Error
	 */
	private static function post( $path, array $payload ) {
		$body = array_merge(
			array(
				'schema'  => self::SCHEMA,
				'slug'    => class_exists( 'OSB_Updater' ) ? OSB_Updater::SLUG : 'osam-bookings',
				'site'    => home_url( '/' ),
				'version' => OSB_VERSION,
			),
			$payload
		);
		if ( defined( 'OSB_UPDATE_KEY' ) && OSB_UPDATE_KEY ) {
			$body['key'] = (string) OSB_UPDATE_KEY;
		}
		return wp_remote_post(
			self::server() . $path,
			array(
				'timeout' => 8,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => (string) wp_json_encode( $body ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * The report
	 * ------------------------------------------------------------------ */

	/**
	 * Run a part of the report; a part that fails is left out instead of breaking the rest.
	 *
	 * @param callable $fn Builder.
	 * @return mixed
	 */
	private static function part( $fn ) {
		try {
			return call_user_func( $fn );
		} catch ( Exception $e ) {
			return array( 'error' => substr( get_class( $e ), 0, 60 ) );
		} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound
			return array( 'error' => substr( get_class( $e ), 0, 60 ) );
		}
	}

	/**
	 * The whole report (what leaves the site).
	 *
	 * @return array
	 */
	public static function build() {
		return array(
			'generated'    => time(),
			'env'          => self::part( array( __CLASS__, 'env' ) ),
			'updates'      => self::part( array( __CLASS__, 'updates' ) ),
			'cron'         => self::part( array( __CLASS__, 'cron' ) ),
			'features'     => self::part( array( __CLASS__, 'features' ) ),
			'payments'     => self::part( array( __CLASS__, 'payments' ) ),
			'zoom'         => self::part(
				static function () {
					return class_exists( 'OSB_Meetings' ) && method_exists( 'OSB_Meetings', 'zoom_ready' ) ? (bool) OSB_Meetings::zoom_ready() : null;
				}
			),
			'calendar'     => self::part( array( __CLASS__, 'calendar' ) ),
			'subscription' => self::part( array( __CLASS__, 'subscription' ) ),
			'problems'     => self::part( array( __CLASS__, 'problems' ) ),
			'bookings'     => self::part( array( __CLASS__, 'bookings' ) ),
		);
	}

	/**
	 * Short text, tags removed.
	 *
	 * @param mixed $s   Text.
	 * @param int   $max Length.
	 * @return string
	 */
	private static function text( $s, $max = 120 ) {
		$s = trim( wp_strip_all_tags( (string) $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/**
	 * WordPress, PHP, database and server.
	 *
	 * @return array
	 */
	public static function env() {
		global $wp_version;
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		$plugs = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugs = array_merge( $plugs, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		return array(
			'wp'              => (string) $wp_version,
			'php'             => PHP_VERSION,
			'db'              => self::db(),
			'db_schema'       => defined( 'OSB_DB_VERSION' ) ? OSB_DB_VERSION : '',
			'db_schema_site'  => (string) get_option( 'osb_db_version', '' ),
			'multisite'       => is_multisite(),
			'locale'          => self::text( get_locale(), 20 ),
			'timezone'        => self::text( get_option( 'timezone_string' ) ? get_option( 'timezone_string' ) : 'UTC' . ( (float) get_option( 'gmt_offset' ) >= 0 ? '+' : '' ) . (float) get_option( 'gmt_offset' ), 60 ),
			'title'           => self::text( get_bloginfo( 'name' ), 120 ),
			'home'            => esc_url_raw( home_url( '/' ) ),
			'theme'           => $theme ? self::text( $theme->get( 'Name' ), 80 ) : '',
			'theme_version'   => $theme ? self::text( $theme->get( 'Version' ), 20 ) : '',
			'active_plugins'  => count( array_unique( $plugs ) ),
			'memory_limit'    => self::text( ini_get( 'memory_limit' ), 20 ),
			'wp_memory_limit' => defined( 'WP_MEMORY_LIMIT' ) ? self::text( WP_MEMORY_LIMIT, 20 ) : '',
			'server'          => self::text( $server, 80 ),
			'https'           => 0 === strpos( home_url( '/' ), 'https://' ),
			'wp_debug'        => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'disable_wp_cron' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'environment'     => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
		);
	}

	/**
	 * Database type and version.
	 *
	 * @return array [ type mysql|mariadb|sqlite|unknown, version ]
	 */
	private static function db() {
		global $wpdb;
		$sqlite = ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) || ( is_object( $wpdb ) && false !== stripos( get_class( $wpdb ), 'sqlite' ) );
		if ( $sqlite ) {
			$v = class_exists( 'SQLite3' ) ? SQLite3::version() : array();
			return array(
				'type'    => 'sqlite',
				'version' => isset( $v['versionString'] ) ? (string) $v['versionString'] : '',
			);
		}
		$info = '';
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ) {
			$info = (string) $wpdb->db_server_info();
		}
		if ( '' === $info && is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ) {
			$info = (string) $wpdb->db_version();
		}
		$maria = false !== stripos( $info, 'mariadb' );
		// MariaDB may report "5.5.5-10.6.12-MariaDB"; the real version is the second part.
		$ver = preg_match( $maria ? '/(?:5\.5\.5-)?(\d+\.\d+\.\d+)/' : '/(\d+\.\d+(?:\.\d+)?)/', $info, $m ) ? $m[1] : '';
		return array(
			'type'    => '' === $info ? 'unknown' : ( $maria ? 'mariadb' : 'mysql' ),
			'version' => $ver,
		);
	}

	/**
	 * Updates: auto-updates, last required-version install, withdrawn versions, last check.
	 *
	 * @return array
	 */
	public static function updates() {
		$out = array(
			'auto'        => class_exists( 'OSB_Settings' ) ? (bool) OSB_Settings::get( 'auto_update', 1 ) : null,
			'last_forced' => null,
			'blocked'     => class_exists( 'OSB_Updater' ) ? array_slice( OSB_Updater::blocked(), -20 ) : array(),
			'last_check'  => null,
			'required'    => class_exists( 'OSB_Updater' ) && method_exists( 'OSB_Updater', 'required' ) ? OSB_Updater::required() : '',
			'report_last' => get_option( self::SENT, null ),
		);
		$f = class_exists( 'OSB_Updater' ) ? get_option( OSB_Updater::FORCE_STATUS ) : null;
		if ( is_array( $f ) ) {
			$out['last_forced'] = array(
				'time' => (int) ( $f['time'] ?? 0 ),
				'from' => self::text( $f['from'] ?? '', 30 ),
				'to'   => self::text( $f['to'] ?? '', 30 ),
				'ok'   => ! empty( $f['ok'] ),
				'msg'  => self::text( $f['msg'] ?? '', 300 ),
			);
		}
		$i = class_exists( 'OSB_Updater' ) ? get_site_transient( OSB_Updater::CACHE ) : null;
		if ( is_array( $i ) ) {
			$out['last_check'] = array(
				'time'   => (int) ( $i['checked'] ?? 0 ),
				'latest' => isset( $i['version'] ) ? self::text( $i['version'], 30 ) : '',
				'error'  => isset( $i['error'] ) ? self::text( $i['error'], 300 ) : '',
			);
		}
		return $out;
	}

	/**
	 * Scheduled jobs.
	 *
	 * @return array|null
	 */
	public static function cron() {
		if ( ! class_exists( 'OSB_Cron' ) || ! method_exists( 'OSB_Cron', 'health' ) ) {
			return null;
		}
		$h = (array) OSB_Cron::health();
		return array(
			'state'     => self::text( $h['state'] ?? '', 20 ),
			'last_run'  => (int) ( $h['last_run'] ?? 0 ),
			'gap'       => (int) ( $h['gap'] ?? 0 ),
			'real_cron' => ! empty( $h['real_cron'] ),
			'server'    => ! empty( $h['server'] ),
			'late'      => ! empty( $h['late'] ),
		);
	}

	/**
	 * Optional features that are switched on.
	 *
	 * @return string[]
	 */
	public static function features() {
		if ( ! class_exists( 'OSB_Settings' ) || ! method_exists( 'OSB_Settings', 'features' ) ) {
			return array();
		}
		$on = array();
		foreach ( array_keys( OSB_Settings::features() ) as $f ) {
			if ( OSB_Settings::feature( $f ) ) {
				$on[] = sanitize_key( $f );
			}
		}
		return $on;
	}

	/**
	 * Payment methods.
	 *
	 * @return array
	 */
	public static function payments() {
		$out = array(
			'gateways' => array(),
			'stripe'   => null,
			'currency' => class_exists( 'OSB_Settings' ) ? self::text( OSB_Settings::get( 'currency', '' ), 3 ) : '',
		);
		if ( ! function_exists( 'osb' ) || ! is_object( osb()->payments ) ) {
			return $out;
		}
		$out['gateways'] = array_values( array_map( 'sanitize_key', array_keys( osb()->payments->enabled() ) ) );
		$s               = osb()->payments->get( 'stripe' );
		if ( $s && in_array( 'stripe', $out['gateways'], true ) ) {
			$out['stripe'] = array(
				'mode'    => 'live' === OSB_Settings::get( 'stripe_mode' ) ? 'live' : 'test',
				'webhook' => method_exists( $s, 'webhook_ready' ) ? (bool) $s->webhook_ready() : null,
			);
		}
		return $out;
	}

	/**
	 * Calendar connections: how many, how many in error.
	 *
	 * @return array
	 */
	public static function calendar() {
		global $wpdb;
		$on = class_exists( 'OSB_Settings' ) && OSB_Settings::feature( 'calendar_sync' );
		if ( ! class_exists( 'OSB_Install' ) ) {
			return array( 'enabled' => $on );
		}
		$t    = OSB_Install::table( 'calendar_connections' );
		$rows = $wpdb->get_results( "SELECT status, provider, COUNT(*) AS n FROM $t GROUP BY status, provider" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$out  = array(
			'enabled'     => $on,
			'connections' => 0,
			'errors'      => 0,
			'providers'   => array(),
		);
		foreach ( (array) $rows as $r ) {
			$n                   = (int) $r->n;
			$out['connections'] += $n;
			if ( 'active' !== (string) $r->status ) {
				$out['errors'] += $n;
			}
			$p                      = sanitize_key( (string) $r->provider );
			$out['providers'][ $p ] = ( $out['providers'][ $p ] ?? 0 ) + $n;
		}
		return $out;
	}

	/**
	 * OSAM subscription (status only; no invoice links or messages).
	 *
	 * @return array
	 */
	public static function subscription() {
		if ( ! class_exists( 'OSB_Licence' ) ) {
			return array( 'linked' => false );
		}
		$linked = (bool) OSB_Licence::enabled();
		$s      = $linked ? (array) OSB_Licence::state() : array();
		return array(
			'linked'      => $linked,
			'status'      => $linked ? sanitize_key( (string) ( $s['status'] ?? '' ) ) : '',
			'mode'        => $linked ? sanitize_key( (string) ( $s['mode'] ?? '' ) ) : '',
			'due_date'    => $linked ? self::text( $s['due_date'] ?? '', 20 ) : '',
			'grace_until' => $linked ? self::text( $s['grace_until'] ?? '', 20 ) : '',
			'checked'     => $linked ? (int) ( $s['checked'] ?? 0 ) : 0,
			'error'       => $linked && ! empty( $s['error'] ),
		);
	}

	/**
	 * Problem-log counts, plus the latest 'updates' and 'cron' messages only (other areas such as
	 * emails and payments can mention customers, so only their counts are sent).
	 *
	 * @return array
	 */
	public static function problems() {
		if ( ! class_exists( 'OSB_Log' ) ) {
			return null;
		}
		$c   = static function ( $since ) {
			$x = OSB_Log::counts( $since );
			return array(
				'total'    => (int) $x['total'],
				'errors'   => (int) $x['errors'],
				'warnings' => (int) $x['warnings'],
				'by_area'  => array_map( 'intval', (array) $x['by_area'] ),
				'last'     => (int) $x['last'],
			);
		};
		$out = array(
			'day'  => $c( time() - DAY_IN_SECONDS ),
			'week' => $c( time() - WEEK_IN_SECONDS ),
		);
		foreach ( array( 'updates', 'cron' ) as $area ) {
			$e                       = OSB_Log::recent( 1, $area );
			$out[ 'last_' . $area ] = $e ? array(
				'time'  => (int) $e[0]['last'],
				'level' => 'warning' === $e[0]['level'] ? 'warning' : 'error',
				'count' => (int) $e[0]['count'],
				'msg'   => self::text( $e[0]['msg'], 300 ),
			) : null;
		}
		return $out;
	}

	/**
	 * Booking totals (plain counts).
	 *
	 * @return array
	 */
	public static function bookings() {
		global $wpdb;
		if ( ! class_exists( 'OSB_Install' ) ) {
			return null;
		}
		$t   = OSB_Install::table( 'bookings' );
		$now = current_time( 'mysql' );
		$ago = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - 30 * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array(
			'total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ),
			'last_30d' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE created_at >= %s", $ago ) ),
			'upcoming' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE start_at > %s AND status IN ('confirmed','pending','pending_payment')", $now ) ),
		);
		// phpcs:enable
	}

	/* ---------------------------------------------------------------------
	 * Update results
	 * ------------------------------------------------------------------ */

	/**
	 * Note an update result and try to send it straight away.
	 *
	 * @param string $from   Version before.
	 * @param string $to     Version wanted.
	 * @param bool   $ok     Installed.
	 * @param string $error  Reason when it failed.
	 * @param bool   $forced A version OSAM required (forced update or rollback).
	 */
	public static function update_result( $from, $to, $ok, $error = '', $forced = false ) {
		if ( ! self::on() ) {
			return;
		}
		$e = array(
			'type'   => 'update',
			'time'   => time(),
			'from'   => self::text( $from, 30 ),
			'to'     => self::text( $to, 30 ),
			'ok'     => (bool) $ok,
			'error'  => $ok ? '' : self::text( $error, 300 ),
			'forced' => (bool) ( $forced || self::$forced ),
		);
		$q = get_option( self::QUEUE, array() );
		$q = is_array( $q ) ? $q : array();
		foreach ( $q as $old ) {
			// The same result twice in a few minutes (upgrader hook and the forced-install check).
			if ( is_array( $old ) && $old['from'] === $e['from'] && $old['to'] === $e['to'] && $old['ok'] === $e['ok'] && $e['time'] - (int) $old['time'] < 10 * MINUTE_IN_SECONDS ) {
				return;
			}
		}
		$q[] = $e;
		update_option( self::QUEUE, array_slice( $q, -20 ), false );
		self::flush_events();
	}

	/**
	 * Send queued update results.
	 */
	public static function flush_events() {
		$q = get_option( self::QUEUE, array() );
		if ( ! is_array( $q ) || ! $q || get_transient( 'osb_report_event_wait' ) ) {
			return;
		}
		$keep = array();
		foreach ( $q as $e ) {
			if ( ! is_array( $e ) || ! empty( $e['sent'] ) ) {
				continue;
			}
			$res  = self::post( '/v1/event', array( 'event' => $e ) );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			if ( $code >= 200 && $code < 300 ) {
				$e['sent'] = 1;
			} elseif ( $code >= 400 && $code < 500 && 429 !== $code ) {
				continue; // Refused for good (bad key, not allowed): drop it.
			} else {
				set_transient( 'osb_report_event_wait', 1, 15 * MINUTE_IN_SECONDS );
			}
			$keep[] = $e;
		}
		// Sent ones are kept briefly so duplicates can still be spotted.
		$keep = array_values(
			array_filter(
				$keep,
				static function ( $e ) {
					return empty( $e['sent'] ) || time() - (int) $e['time'] < HOUR_IN_SECONDS;
				}
			)
		);
		update_option( self::QUEUE, $keep, false );
	}

	/**
	 * After WordPress installed plugin updates: did OSAM Bookings change version?
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $extra    Details.
	 */
	public static function after_upgrade( $upgrader, $extra ) {
		if ( empty( $extra['type'] ) || 'plugin' !== $extra['type'] || 'update' !== ( $extra['action'] ?? 'update' ) ) {
			return;
		}
		$base = plugin_basename( OSB_FILE );
		$ours = ( isset( $extra['plugins'] ) && in_array( $base, (array) $extra['plugins'], true ) ) || ( isset( $extra['plugin'] ) && $base === $extra['plugin'] );
		if ( ! $ours ) {
			return;
		}
		// This request still runs the old code, so OSB_VERSION is the version before the update.
		$data = get_file_data( OSB_FILE, array( 'Version' => 'Version' ) );
		$now  = (string) $data['Version'];
		$tr   = get_site_transient( 'update_plugins' );
		$want = is_object( $tr ) && isset( $tr->response[ $base ]->new_version ) ? (string) $tr->response[ $base ]->new_version : $now;
		if ( '' !== $now && OSB_VERSION !== $now ) {
			self::update_result( OSB_VERSION, $now, true );
			return;
		}
		$err    = '';
		$result = isset( $upgrader->skin->result ) ? $upgrader->skin->result : null;
		if ( is_wp_error( $result ) ) {
			$err = $result->get_error_message();
		} elseif ( isset( $upgrader->skin ) && method_exists( $upgrader->skin, 'get_errors' ) && is_wp_error( $upgrader->skin->get_errors() ) && $upgrader->skin->get_errors()->has_errors() ) {
			$err = $upgrader->skin->get_errors()->get_error_message();
		}
		if ( '' !== $err || $want !== OSB_VERSION ) {
			self::update_result( OSB_VERSION, $want, false, '' !== $err ? $err : 'The version on disk did not change.' );
		}
	}

	/**
	 * Our download step refused or failed (signature, withdrawn version, network).
	 *
	 * @param bool|string|WP_Error $reply      Result so far.
	 * @param string               $package    Package URL.
	 * @param WP_Upgrader          $upgrader   Upgrader.
	 * @param array                $hook_extra Details.
	 * @return bool|string|WP_Error Unchanged.
	 */
	public static function download_result( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( is_wp_error( $reply ) && isset( $hook_extra['plugin'] ) && plugin_basename( OSB_FILE ) === $hook_extra['plugin'] ) {
			$tr   = get_site_transient( 'update_plugins' );
			$base = plugin_basename( OSB_FILE );
			$want = is_object( $tr ) && isset( $tr->response[ $base ]->new_version ) ? (string) $tr->response[ $base ]->new_version : '';
			self::update_result( OSB_VERSION, $want, false, $reply->get_error_message() );
		}
		return $reply;
	}
}
