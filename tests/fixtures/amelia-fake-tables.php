<?php
/**
 * Creates and fills minimal fake Amelia tables for testing the Amelia importer.
 *
 * Use:  wp eval-file amelia-fake-tables.php        (or include it after wp-load.php and call osb_test_make_amelia()).
 * Safe to run again: it drops and recreates the fake tables. Times are UTC, as Amelia stores them.
 * Deliberately leaves out some columns newer Amelia versions have (e.g. customer_bookings.aggregatedPrice)
 * to check the importer copes. Plain SQL so it also works on the SQLite database integration.
 */

if ( ! function_exists( 'osb_test_make_amelia' ) ) {
	/**
	 * Build the tables.
	 */
	function osb_test_make_amelia() {
		global $wpdb;
		$p = $wpdb->prefix . 'amelia_';
		$tables = array(
			'users'                               => 'id INTEGER PRIMARY KEY AUTO_INCREMENT, status VARCHAR(20), type VARCHAR(20), firstName VARCHAR(255), lastName VARCHAR(255), email VARCHAR(255), phone VARCHAR(63), note TEXT, description TEXT',
			'categories'                          => 'id INTEGER PRIMARY KEY AUTO_INCREMENT, status VARCHAR(20), name VARCHAR(255), position INTEGER',
			'services'                            => 'id INTEGER PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255), description TEXT, color VARCHAR(255), price DECIMAL(10,2), status VARCHAR(20), categoryId INTEGER, minCapacity INTEGER, maxCapacity INTEGER, duration INTEGER, timeBefore INTEGER, timeAfter INTEGER',
			'providers_to_services'               => 'id INTEGER PRIMARY KEY AUTO_INCREMENT, userId INTEGER, serviceId INTEGER, price DECIMAL(10,2) NULL',
			'appointments'                        => 'id INTEGER PRIMARY KEY AUTO_INCREMENT, status VARCHAR(20), bookingStart DATETIME, bookingEnd DATETIME, serviceId INTEGER, providerId INTEGER, internalNotes TEXT',
			'customer_bookings'                   => 'id INTEGER PRIMARY KEY AUTO_INCREMENT, appointmentId INTEGER NULL, customerId INTEGER, status VARCHAR(20), price DECIMAL(10,2), persons INTEGER, info TEXT NULL, customFields TEXT NULL, created DATETIME NULL',
			'payments'                            => 'id INTEGER PRIMARY KEY AUTO_INCREMENT, customerBookingId INTEGER, amount DECIMAL(10,2), dateTime DATETIME, status VARCHAR(20), gateway VARCHAR(20)',
			'events'                              => 'id INTEGER PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255), status VARCHAR(20), maxCapacity INTEGER, price DECIMAL(10,2), description TEXT, color VARCHAR(255), customLocation VARCHAR(255), `show` INTEGER',
			'events_periods'                      => 'id INTEGER PRIMARY KEY AUTO_INCREMENT, eventId INTEGER, periodStart DATETIME, periodEnd DATETIME',
			'events_to_providers'                 => 'id INTEGER PRIMARY KEY AUTO_INCREMENT, eventId INTEGER, userId INTEGER',
			'customer_bookings_to_events_periods' => 'id INTEGER PRIMARY KEY AUTO_INCREMENT, customerBookingId INTEGER, eventPeriodId INTEGER',
			'notifications'                       => 'id INTEGER PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255), customName VARCHAR(255) NULL, status VARCHAR(20), type VARCHAR(20), entity VARCHAR(20), sendTo VARCHAR(20), subject VARCHAR(255), content TEXT',
		);
		foreach ( $tables as $t => $cols ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$p}{$t}" ); // phpcs:ignore
			$wpdb->query( "CREATE TABLE {$p}{$t} ( $cols )" ); // phpcs:ignore
		}
		$ins = static function ( $t, array $rows ) use ( $wpdb, $p ) {
			foreach ( $rows as $r ) {
				$wpdb->insert( $p . $t, $r );
			}
		};
		$ins( 'users', array(
			array( 'id' => 1, 'status' => 'visible', 'type' => 'provider', 'firstName' => 'Alice', 'lastName' => 'Moor', 'email' => 'alice@example.com', 'phone' => '0111', 'note' => '', 'description' => 'Massage therapist' ),
			array( 'id' => 2, 'status' => 'visible', 'type' => 'customer', 'firstName' => 'Bob', 'lastName' => 'Stone', 'email' => 'bob@example.com', 'phone' => '0222', 'note' => 'Prefers mornings', 'description' => '' ),
			array( 'id' => 3, 'status' => 'visible', 'type' => 'customer', 'firstName' => 'Carol', 'lastName' => 'Vale', 'email' => 'carol@example.com', 'phone' => '0333', 'note' => '', 'description' => '' ),
			array( 'id' => 4, 'status' => 'visible', 'type' => 'provider', 'firstName' => 'Dan', 'lastName' => 'Ray', 'email' => 'dan@example.com', 'phone' => '0444', 'note' => '', 'description' => 'Yoga teacher' ),
		) );
		$ins( 'categories', array( array( 'id' => 1, 'status' => 'visible', 'name' => 'Wellbeing', 'position' => 1 ) ) );
		$ins( 'services', array(
			array( 'id' => 1, 'name' => 'Full body massage', 'description' => '<p>Relaxing.</p>', 'color' => '#1788FB', 'price' => 45, 'status' => 'visible', 'categoryId' => 1, 'minCapacity' => 1, 'maxCapacity' => 1, 'duration' => 3600, 'timeBefore' => 0, 'timeAfter' => 900 ),
			array( 'id' => 2, 'name' => 'Yoga class', 'description' => '', 'color' => '#4BBEC6', 'price' => 12, 'status' => 'visible', 'categoryId' => 1, 'minCapacity' => 1, 'maxCapacity' => 8, 'duration' => 5400, 'timeBefore' => 0, 'timeAfter' => 0 ),
		) );
		$ins( 'providers_to_services', array(
			array( 'userId' => 1, 'serviceId' => 1, 'price' => 45 ),
			array( 'userId' => 4, 'serviceId' => 2, 'price' => 15 ),
		) );
		$ins( 'appointments', array(
			array( 'id' => 1, 'status' => 'approved', 'bookingStart' => '2025-03-10 10:00:00', 'bookingEnd' => '2025-03-10 11:00:00', 'serviceId' => 1, 'providerId' => 1, 'internalNotes' => 'Regular client' ),
			array( 'id' => 2, 'status' => 'pending', 'bookingStart' => '2027-07-01 09:00:00', 'bookingEnd' => '2027-07-01 10:00:00', 'serviceId' => 1, 'providerId' => 1, 'internalNotes' => '' ),
			array( 'id' => 3, 'status' => 'canceled', 'bookingStart' => '2026-01-05 14:00:00', 'bookingEnd' => '2026-01-05 15:00:00', 'serviceId' => 1, 'providerId' => 1, 'internalNotes' => '' ),
			array( 'id' => 4, 'status' => 'approved', 'bookingStart' => '2027-02-01 18:00:00', 'bookingEnd' => '2027-02-01 19:30:00', 'serviceId' => 2, 'providerId' => 4, 'internalNotes' => '' ),
		) );
		$ins( 'customer_bookings', array(
			array( 'id' => 1, 'appointmentId' => 1, 'customerId' => 2, 'status' => 'approved', 'price' => 45, 'persons' => 1, 'info' => null, 'customFields' => '{"1":{"label":"Allergies","value":"Nuts","type":"text"}}', 'created' => '2025-03-01 08:00:00' ),
			array( 'id' => 2, 'appointmentId' => 2, 'customerId' => 3, 'status' => 'pending', 'price' => 45, 'persons' => 1, 'info' => '{"firstName":"Caroline","lastName":"Vale","phone":"0999"}', 'customFields' => null, 'created' => '2026-10-01 08:00:00' ),
			array( 'id' => 3, 'appointmentId' => 3, 'customerId' => 2, 'status' => 'approved', 'price' => 45, 'persons' => 1, 'info' => null, 'customFields' => null, 'created' => '2025-12-01 08:00:00' ),
			array( 'id' => 4, 'appointmentId' => 4, 'customerId' => 2, 'status' => 'approved', 'price' => 15, 'persons' => 2, 'info' => null, 'customFields' => null, 'created' => '2026-10-02 08:00:00' ),
			array( 'id' => 5, 'appointmentId' => 4, 'customerId' => 3, 'status' => 'waiting', 'price' => 15, 'persons' => 1, 'info' => null, 'customFields' => null, 'created' => '2026-10-03 08:00:00' ),
			array( 'id' => 6, 'appointmentId' => null, 'customerId' => 3, 'status' => 'approved', 'price' => 30, 'persons' => 1, 'info' => null, 'customFields' => null, 'created' => '2026-10-04 08:00:00' ),
		) );
		$ins( 'payments', array(
			array( 'customerBookingId' => 1, 'amount' => 45, 'dateTime' => '2025-03-01 08:00:00', 'status' => 'paid', 'gateway' => 'stripe' ),
			array( 'customerBookingId' => 4, 'amount' => 10, 'dateTime' => '2026-10-02 08:00:00', 'status' => 'partiallyPaid', 'gateway' => 'onSite' ),
			array( 'customerBookingId' => 6, 'amount' => 30, 'dateTime' => '2026-10-04 08:00:00', 'status' => 'paid', 'gateway' => 'payPal' ),
		) );
		$ins( 'events', array( array( 'id' => 1, 'name' => 'Candle workshop', 'status' => 'approved', 'maxCapacity' => 10, 'price' => 30, 'description' => 'Make two candles.', 'color' => '#e8590c', 'customLocation' => 'The Studio', 'show' => 1 ) ) );
		$ins( 'events_periods', array( array( 'id' => 1, 'eventId' => 1, 'periodStart' => '2027-03-03 17:00:00', 'periodEnd' => '2027-03-03 19:00:00' ) ) );
		$ins( 'events_to_providers', array( array( 'eventId' => 1, 'userId' => 4 ) ) );
		$ins( 'customer_bookings_to_events_periods', array( array( 'customerBookingId' => 6, 'eventPeriodId' => 1 ) ) );
		$ins( 'notifications', array(
			array( 'name' => 'customer_appointment_approved', 'customName' => null, 'status' => 'enabled', 'type' => 'email', 'entity' => 'appointment', 'sendTo' => 'customer', 'subject' => 'Booked: %service_name%', 'content' => '<p>Hi %customer_full_name%, see you on %appointment_date_time% with %employee_full_name%. Ref %booking_reference_code%.</p>' ),
			array( 'name' => 'customer_appointment_next_day_reminder', 'customName' => null, 'status' => 'enabled', 'type' => 'email', 'entity' => 'appointment', 'sendTo' => 'customer', 'subject' => 'Tomorrow: %service_name%', 'content' => '<p>Reminder for %appointment_start_time% at %location_address%.</p>' ),
			array( 'name' => 'provider_appointment_pending', 'customName' => null, 'status' => 'enabled', 'type' => 'email', 'entity' => 'appointment', 'sendTo' => 'provider', 'subject' => 'New request from %customer_full_name%', 'content' => '<p>%customer_full_name% asked for %service_name% on %appointment_date%.</p>' ),
			array( 'name' => 'customer_appointment_rejected', 'customName' => null, 'status' => 'disabled', 'type' => 'email', 'entity' => 'appointment', 'sendTo' => 'customer', 'subject' => 'Sorry', 'content' => '<p>We can’t do %appointment_date%.</p>' ),
			array( 'name' => 'customer_appointment_approved', 'customName' => null, 'status' => 'enabled', 'type' => 'sms', 'entity' => 'appointment', 'sendTo' => 'customer', 'subject' => '', 'content' => 'SMS text, ignored' ),
		) );
		update_option( 'amelia_settings', wp_json_encode( array( 'payments' => array( 'currency' => 'GBP' ) ) ) );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	osb_test_make_amelia();
	WP_CLI::success( 'Fake Amelia tables created.' );
}
