<?php
/**
 * Problem log: one place where every part of the plugin records things that went wrong
 * (payments, calendar sync, emails, Zoom, the subscription check, updates, scheduled jobs).
 *
 * Kept small on purpose: the newest 200 entries in one option that isn't autoloaded. The same
 * problem happening again straight after is counted instead of added twice. Shown on the
 * dashboard ("Recent problems") and summarised to OSAM's update server so problems on client
 * sites can be spotted (counts and short messages only, never customer details).
 *
 * Use: OSB_Log::error( 'payments', 'Stripe refused the key', array( 'gateway' => 'stripe' ) );
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Problem log.
 */
class OSB_Log {

	const OPTION = 'osb_problem_log';
	const MAX    = 200;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_mail_failed', array( __CLASS__, 'mail_failed' ) );
		add_action( 'admin_post_osb_log_clear', array( __CLASS__, 'clear_action' ) );
	}

	/**
	 * Areas and their names.
	 *
	 * @return array
	 */
	public static function areas() {
		return array(
			'payments'     => __( 'Payments', 'osam-bookings' ),
			'calendar'     => __( 'Calendar sync', 'osam-bookings' ),
			'email'        => __( 'Emails', 'osam-bookings' ),
			'zoom'         => __( 'Online meetings', 'osam-bookings' ),
			'subscription' => __( 'OSAM subscription', 'osam-bookings' ),
			'updates'      => __( 'Updates', 'osam-bookings' ),
			'cron'         => __( 'Scheduled jobs', 'osam-bookings' ),
			'import'       => __( 'Import', 'osam-bookings' ),
			'general'      => __( 'General', 'osam-bookings' ),
		);
	}

	/**
	 * Record an error.
	 *
	 * @param string $area    One of areas().
	 * @param string $message Plain message (no customer details).
	 * @param array  $context Small extra details (ids, codes).
	 */
	public static function error( $area, $message, array $context = array() ) {
		self::add( 'error', $area, $message, $context );
	}

	/**
	 * Record a warning (something to look at, but nothing failed for a customer).
	 *
	 * @param string $area    Area.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function warn( $area, $message, array $context = array() ) {
		self::add( 'warning', $area, $message, $context );
	}

	/**
	 * Record an entry.
	 *
	 * @param string $level   error|warning.
	 * @param string $area    Area.
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function add( $level, $area, $message, array $context = array() ) {
		$area    = isset( self::areas()[ $area ] ) ? $area : 'general';
		$level   = 'warning' === $level ? 'warning' : 'error';
		$message = trim( wp_strip_all_tags( (string) $message ) );
		if ( '' === $message ) {
			return;
		}
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 500 ) : substr( $message, 0, 500 );
		$ctx     = array();
		foreach ( array_slice( $context, 0, 8, true ) as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$ctx[ sanitize_key( (string) $k ) ] = substr( (string) $v, 0, 120 );
			}
		}
		$log = self::all();
		$now = time();
		if ( $log && $log[0]['area'] === $area && $log[0]['msg'] === $message && $now - (int) $log[0]['last'] < DAY_IN_SECONDS ) {
			$log[0]['count'] = (int) $log[0]['count'] + 1;
			$log[0]['last']  = $now;
			$log[0]['ctx']   = $ctx ? $ctx : $log[0]['ctx'];
		} else {
			array_unshift(
				$log,
				array(
					'time'  => $now,
					'last'  => $now,
					'level' => $level,
					'area'  => $area,
					'msg'   => $message,
					'ctx'   => $ctx,
					'count' => 1,
				)
			);
		}
		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
		do_action( 'osb_problem_logged', $level, $area, $message, $ctx );
	}

	/**
	 * Every entry, newest first.
	 *
	 * @return array
	 */
	public static function all() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
	}

	/**
	 * Newest entries.
	 *
	 * @param int    $limit How many.
	 * @param string $area  Only this area ('' = all).
	 * @return array
	 */
	public static function recent( $limit = 20, $area = '' ) {
		$out = array();
		foreach ( self::all() as $e ) {
			if ( '' === $area || $area === $e['area'] ) {
				$out[] = $e;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * How many problems happened since a time (repeats included).
	 *
	 * @param int $since Unix time.
	 * @return array [ total, errors, warnings, by_area => [ area => n ], last => unix time or 0 ]
	 */
	public static function counts( $since ) {
		$out = array(
			'total'    => 0,
			'errors'   => 0,
			'warnings' => 0,
			'by_area'  => array(),
			'last'     => 0,
		);
		foreach ( self::all() as $e ) {
			if ( (int) $e['last'] < $since ) {
				continue;
			}
			$n                = max( 1, (int) $e['count'] );
			$out['total']    += $n;
			$out[ 'warning' === $e['level'] ? 'warnings' : 'errors' ] += $n;
			$out['by_area'][ $e['area'] ] = ( $out['by_area'][ $e['area'] ] ?? 0 ) + $n;
			$out['last']      = max( $out['last'], (int) $e['last'] );
		}
		return $out;
	}

	/**
	 * Empty the log.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * "Clear" button on the dashboard.
	 */
	public static function clear_action() {
		OSB_Admin::verify( 'osb_log_clear', 'manage_options' );
		self::clear();
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-dashboard' ), 'log_cleared' );
	}

	/**
	 * WordPress couldn't send an email (any email on the site; ours are the ones that matter most).
	 *
	 * @param WP_Error $error Error from wp_mail().
	 */
	public static function mail_failed( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}
		$data    = (array) $error->get_error_data();
		$subject = isset( $data['subject'] ) ? (string) $data['subject'] : '';
		/* translators: 1: subject 2: reason */
		self::error( 'email', sprintf( __( 'An email couldn’t be sent (“%1$s”): %2$s', 'osam-bookings' ), $subject, $error->get_error_message() ) );
	}
}
