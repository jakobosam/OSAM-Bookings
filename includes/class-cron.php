<?php
/**
 * Scheduled housekeeping: expire unpaid holds, send reminders, complete past bookings.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron jobs.
 */
class OSB_Cron {

	const HOOK = 'osb_cron';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	/**
	 * Five-minute interval.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules['osb_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (OSAM Bookings)', 'osam-bookings' ),
		);
		return $schedules;
	}

	/**
	 * Schedule.
	 */
	public static function schedule() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'osb_five_minutes', self::HOOK );
		}
	}

	/**
	 * Unschedule.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Option holding when the 5-minute job last ran: [ last, prev, cli ].
	 */
	const BEAT = 'osb_cron_beat';

	/**
	 * How the scheduled jobs are doing.
	 *
	 * @return array [ last_run (unix, 0 = never), gap (seconds between the last two runs),
	 *                 real_cron (DISABLE_WP_CRON is on, so a server cron job is expected),
	 *                 late (no run for over 15 minutes), state ok|late|never|visitor ]
	 */
	public static function health() {
		$b    = get_option( self::BEAT, array() );
		$b    = is_array( $b ) ? $b : array();
		$last = (int) ( $b['last'] ?? 0 );
		$real = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$late = ! $last || time() - $last > 15 * MINUTE_IN_SECONDS;
		$cli  = ! empty( $b['cli'] ) && (int) $b['cli'] > time() - DAY_IN_SECONDS; // A server cron job ran it in the last day.
		if ( ! $last ) {
			$state = 'never';
		} elseif ( $late ) {
			$state = 'late';
		} elseif ( $real ) {
			$state = 'ok';
		} else {
			$state = $cli ? 'server' : 'visitor'; // server = cron job works, wp-config line still to add.
		}
		return array(
			'last_run'  => $last,
			'gap'       => $last && ! empty( $b['prev'] ) ? $last - (int) $b['prev'] : 0,
			'real_cron' => $real,
			'server'    => $cli,
			'late'      => $late,
			'state'     => $state,
		);
	}

	/**
	 * Was this run started by a server cron job rather than a visitor?
	 *
	 * @return bool
	 */
	private static function from_server() {
		if ( 'cli' === PHP_SAPI ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of how wp-cron.php was called.
		return isset( $_GET['doing_wp_cron'] ) && '' === (string) $_GET['doing_wp_cron'];
	}

	/**
	 * Note that the jobs ran (for health()).
	 */
	private static function beat() {
		$b = get_option( self::BEAT, array() );
		$b = is_array( $b ) ? $b : array();
		update_option(
			self::BEAT,
			array(
				'last' => time(),
				'prev' => (int) ( $b['last'] ?? 0 ),
				// A server cron job, not a visitor: run from the command line (cPanel "php wp-cron.php"), or
				// wp-cron.php fetched with a bare ?doing_wp_cron (the wget command). WordPress's own visitor
				// trigger always puts a timestamp after doing_wp_cron=.
				'cli'  => self::from_server() ? time() : (int) ( $b['cli'] ?? 0 ),
			),
			false
		);
		if ( ! empty( $b['last'] ) && time() - (int) $b['last'] > HOUR_IN_SECONDS ) {
			/* translators: %s time */
			OSB_Log::warn( 'cron', sprintf( __( 'The 5-minute booking jobs didn’t run for %s, so reminders, payment time-outs and calendar sync were late. Set up a server cron job (see the dashboard).', 'osam-bookings' ), human_time_diff( (int) $b['last'] ) ) );
		}
	}

	/**
	 * Run all jobs.
	 */
	public static function run() {
		self::beat();
		if ( OSB_Licence::full_off() ) {
			return; // Subscription suspended: reminders, emails and syncing pause until it's paid.
		}
		self::expire_unpaid();
		self::send_reminders();
		OSB_Courses::send_session_reminders();
		OSB_Deposits::send_balance_reminders();
		self::complete_past();
		self::send_followups();
		if ( ! OSB_Licence::bookings_off() ) {
			OSB_Waitlist::process_all(); // No point offering places that can't be booked.
		}
		OSB_Access::send_due();
		OSB_Custom_Emails::run();
		OSB_Calendar_Sync::run();
	}

	/**
	 * Cancel online-payment holds that were never paid.
	 */
	public static function expire_unpaid() {
		global $wpdb;
		$t       = OSB_Install::table( 'bookings' );
		$minutes = max( 35, (int) OSB_Settings::get( 'pending_payment_minutes' ) );
		$cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $minutes * 60 );
		// Woo orders manage their own unpaid-order timeout; once an order exists we follow its status instead.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE status = 'pending_payment' AND wc_order_id = 0 AND created_at < %s LIMIT 200", $cutoff ) );
		foreach ( $ids as $id ) {
			OSB_Bookings::payment_failed( (int) $id );
		}
	}

	/**
	 * Reminder emails. Courses are reminded per session instead (OSB_Courses::send_session_reminders()).
	 */
	public static function send_reminders() {
		$hours = (float) OSB_Settings::get( 'reminder_hours' );
		if ( $hours <= 0 ) {
			return;
		}
		global $wpdb;
		$t     = OSB_Install::table( 'bookings' );
		$now   = current_time( 'mysql' );
		$until = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + (int) ( $hours * HOUR_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = 'confirmed' AND type NOT IN ('course','bundle') AND reminder_sent = 0 AND start_at > %s AND start_at <= %s LIMIT 100", $now, $until ) );
		foreach ( $rows as $booking ) {
			OSB_DB::update( 'bookings', $booking->id, array( 'reminder_sent' => 1 ) );
			// Skip if it was booked inside the reminder window — they have just had a confirmation.
			if ( strtotime( $booking->start_at ) - strtotime( $booking->created_at ) < $hours * HOUR_IN_SECONDS ) {
				continue;
			}
			OSB_Emails::send( 'customer_reminder', $booking, $booking->customer_email );
		}
	}

	/**
	 * After-visit thank-you / review emails.
	 */
	public static function send_followups() {
		$templates = OSB_Settings::get( 'emails' );
		if ( empty( $templates['customer_followup']['enabled'] ) ) {
			return;
		}
		global $wpdb;
		$t     = OSB_Install::table( 'bookings' );
		$now   = strtotime( current_time( 'mysql' ) );
		$hours = max( 0, (float) OSB_Settings::get( 'followup_hours' ) );
		$until = gmdate( 'Y-m-d H:i:s', $now - (int) ( $hours * HOUR_IN_SECONDS ) );
		// Only recent visits — switching this on never emails people about visits from months ago.
		$since = gmdate( 'Y-m-d H:i:s', $now - (int) ( $hours * HOUR_IN_SECONDS ) - 3 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE followup_sent = 0 AND type <> 'bundle' AND status IN ('confirmed','completed') AND end_at <= %s AND end_at > %s LIMIT 50", $until, $since ) );
		foreach ( $rows as $booking ) {
			OSB_DB::update( 'bookings', $booking->id, array( 'followup_sent' => 1 ) );
			if ( apply_filters( 'osb_send_followup', true, $booking ) ) {
				OSB_Emails::send( 'customer_followup', $booking, $booking->customer_email );
			}
		}
	}

	/**
	 * Mark finished confirmed bookings as completed.
	 */
	public static function complete_past() {
		if ( ! apply_filters( 'osb_auto_complete', true ) ) {
			return;
		}
		global $wpdb;
		$t = OSB_Install::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE status = 'confirmed' AND type <> 'bundle' AND end_at < %s LIMIT 200", current_time( 'mysql' ) ) );
		foreach ( $ids as $id ) {
			OSB_Bookings::set_status( (int) $id, 'completed', false );
		}
	}
}
