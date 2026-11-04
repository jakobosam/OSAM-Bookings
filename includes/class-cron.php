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
	 * Run all jobs.
	 */
	public static function run() {
		if ( OSB_Licence::full_off() ) {
			return; // Subscription suspended: reminders, emails and syncing pause until it's paid.
		}
		self::expire_unpaid();
		self::send_reminders();
		OSB_Courses::send_session_reminders();
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
