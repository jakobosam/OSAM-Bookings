<?php
/**
 * Uninstall: only removes data if the admin opted in under Settings → General.
 *
 * @package OSAM_Bookings
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$osb_settings = get_option( 'osb_settings', array() );
if ( empty( $osb_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'services', 'staff', 'service_staff', 'schedule', 'days_off', 'events', 'courses', 'course_intakes', 'course_sessions', 'attendance', 'calendar_connections', 'calendar_events', 'coupons', 'coupon_uses', 'waitlist', 'customers', 'bookings', 'payments', 'email_log', 'rest_areas', 'rest_tables', 'rest_combos', 'rest_periods', 'rest_closures', 'booking_tables', 'resources', 'res_hours', 'res_seasons', 'res_maintenance', 'res_closures', 'booking_resources', 'addons', 'booking_addons', 'bundles', 'passes', 'pass_uses', 'invoices' ) as $osb_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'osb_' . $osb_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
}
foreach ( array( 'osb_settings', 'osb_db_version', 'osb_availability_version', 'osb_wc_product_id', 'osb_setup_done', 'osb_meeting_refs', 'osb_meeting_errors', 'osb_custom_emails', 'osb_custom_email_seq', 'osb_emails_seeded', 'osb_portal_key' ) as $osb_option ) {
	delete_option( $osb_option );
}
wp_clear_scheduled_hook( 'osb_cron' );
wp_clear_scheduled_hook( 'osb_calendar_sync' );
delete_site_transient( 'osb_update_info' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_osb\_%' OR option_name LIKE '\_transient\_timeout\_osb\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$osb_role = get_role( 'administrator' );
if ( $osb_role ) {
	$osb_role->remove_cap( 'osb_manage_bookings' );
}
