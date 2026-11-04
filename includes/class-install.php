<?php
/**
 * Installation, database schema and upgrades.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles tables, default options and the booking page.
 */
class OSB_Install {

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::create_tables();
		self::maybe_create_booking_page();
		OSB_Portal::maybe_create_page();
		self::add_caps();
		OSB_Cron::schedule();
		update_option( 'osb_db_version', OSB_DB_VERSION );
		self::seed_emails();
		if ( false === get_option( 'osb_settings', false ) ) {
			add_option( 'osb_settings', array() );
		}
		if ( ! get_option( 'osb_setup_done' ) ) {
			set_transient( 'osb_activation_redirect', 1, 60 );
		}
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		OSB_Cron::unschedule();
	}

	/**
	 * Run the schema when the stored version differs (covers updates via zip upload).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'osb_db_version' ) !== OSB_DB_VERSION ) {
			self::migrate( (string) get_option( 'osb_db_version' ) );
			self::create_tables();
			self::add_caps();
			update_option( 'osb_db_version', OSB_DB_VERSION );
			// Pages and starter emails need WordPress fully loaded (rewrite rules, translations).
			if ( did_action( 'init' ) ) {
				self::after_upgrade();
			} else {
				add_action( 'init', array( __CLASS__, 'after_upgrade' ), 20 );
			}
		}
	}

	/**
	 * One-off jobs after an update.
	 */
	public static function after_upgrade() {
		self::seed_emails();
		OSB_Portal::maybe_create_page();
	}

	/**
	 * Add a starter set of the site's own emails, switched off, so the Emails page shows what's possible.
	 * Runs once per site; deleted ones never come back.
	 */
	public static function seed_emails() {
		if ( get_option( 'osb_emails_seeded' ) || ! class_exists( 'OSB_Custom_Emails' ) ) {
			return;
		}
		update_option( 'osb_emails_seeded', 1, false );
		if ( OSB_Custom_Emails::all() ) {
			return; // They've already made their own.
		}
		$presets = OSB_Custom_Emails::presets();
		foreach ( array( 'prep', 'unpaid', 'welcome', 'staff_brief', 'rebook' ) as $key ) {
			if ( empty( $presets[ $key ][2] ) ) {
				continue;
			}
			$def            = wp_parse_args( $presets[ $key ][2], OSB_Custom_Emails::defaults() );
			$def['enabled'] = 0;
			OSB_Custom_Emails::save( OSB_Custom_Emails::sanitize( $def ) );
		}
	}

	/**
	 * Version-specific data migrations.
	 *
	 * @param string $from Previously installed DB version ('' on fresh installs).
	 */
	private static function migrate( $from ) {
		if ( $from && version_compare( $from, '1.1.0', '<' ) ) {
			// 1.1.0 added solo mode (now the default). Sites already set up keep the team behaviour
			// they had unless they only ever had one diary, and skip the first-run wizard.
			global $wpdb;
			$settings = get_option( 'osb_settings', array() );
			$settings = is_array( $settings ) ? $settings : array();
			if ( empty( $settings['staff_mode'] ) ) {
				$count                  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'staff' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$settings['staff_mode'] = $count > 1 ? 'team' : 'solo';
				update_option( 'osb_settings', $settings );
			}
			update_option( 'osb_setup_done', 1 );
		}
		if ( $from && version_compare( $from, '2.0.0', '<' ) ) {
			self::upgrade_email_templates();
		}
	}

	/**
	 * Swap saved email templates that were never edited for the new designed defaults.
	 * Anything the site owner changed is left exactly as it is.
	 */
	public static function upgrade_email_templates() {
		$settings = get_option( 'osb_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['emails'] ) || ! is_array( $settings['emails'] ) ) {
			return;
		}
		// Hashes of the wording that shipped in 1.0–1.3 ('s' prefix = subject).
		$old = array(
			'dde0f1b8d7a0c6aaa4397a25560c9fc4' => 'customer_pending',
			'sb85f1edd3e68b557d4d727b739b8caf2' => 'customer_pending',
			'a8cdf8d10323f531782bd9febb4f8551' => 'customer_confirmed',
			's9f63a7836150cc962c719ec5a7cb07c0' => 'customer_confirmed',
			'0667763e7b78e730c5156569a0f88363' => 'customer_cancelled',
			's3726cfec2a3700b9d1d039bfef0f4a32' => 'customer_cancelled',
			'1802f041c4181df2e14a7140d52ca0ed' => 'customer_reminder',
			's680203e8684ef0f124c478357d5d60b3' => 'customer_reminder',
			'b8ad3023393c7a1e32f542c3635ae147' => 'admin_new',
			's9355afb19b56e218f91acd08881bb6a5' => 'admin_new',
			'f8d58879cbd7e598ee7149e7db4acf48' => 'admin_cancelled',
			'sad8888a871b39733a1292c4b15855f4f' => 'admin_cancelled',
			'58fe89a47d4980f89a54c295f203b794' => 'staff_new',
			'sbfa66c60d2611a4bba30c63e175cbaaa' => 'staff_new',
			'e1cca875994ab77e59b7280fb57ec27b' => 'staff_cancelled',
			'sa13934fd9844c22604cf3c959052b001' => 'staff_cancelled',
			'faa9bc7f5899cfe3edb3bca3c502cbc6' => 'customer_pending',
			'e969d650f1603a7429fbecfeddaa46b2' => 'customer_confirmed',
			'50262f8577ec47a05c62a1bb69aadb0a' => 'customer_cancelled',
			'083fb2d8ad593422dd1f79efca438728' => 'customer_reminder',
			'e98c408e91956bdbcaa537e313a2ced7' => 'customer_session_reminder',
			'sb0b449c94db6ed720c6dd107af91fc3c' => 'customer_session_reminder',
			'f224fd228114d2e37fcac95e69034c93' => 'customer_schedule_changed',
			's90d64b19a1f5f9f85d79a6463b208139' => 'customer_schedule_changed',
			'a129365b0c8b3fec4f67dbc7fe0128df' => 'admin_new',
			'a3a7b891efad1b9f048cbde5ea1e93a3' => 'admin_cancelled',
			'9cdd3624572355251242fd99b33c4d81' => 'staff_new',
			'73c88c4b2fb93251d506ca77f2806c6a' => 'staff_cancelled',
		);
		$norm     = static function ( $s ) {
			return md5( preg_replace( '/\s+/', ' ', trim( str_replace( "\r", '', (string) $s ) ) ) );
		};
		$defaults = OSB_Settings::default_emails();
		$changed  = false;
		foreach ( $settings['emails'] as $key => $tpl ) {
			if ( ! isset( $defaults[ $key ] ) || ! is_array( $tpl ) ) {
				continue;
			}
			if ( isset( $tpl['body'] ) && isset( $old[ $norm( $tpl['body'] ) ] ) && $old[ $norm( $tpl['body'] ) ] === $key ) {
				$settings['emails'][ $key ]['body'] = $defaults[ $key ]['body'];
				$changed                            = true;
				if ( isset( $tpl['subject'] ) && isset( $old[ 's' . $norm( $tpl['subject'] ) ] ) ) {
					$settings['emails'][ $key ]['subject'] = $defaults[ $key ]['subject'];
				}
			}
		}
		if ( $changed ) {
			update_option( 'osb_settings', $settings );
		}
	}

	/**
	 * Give administrators the manage capability.
	 */
	public static function add_caps() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( 'osb_manage_bookings' ) ) {
			$role->add_cap( 'osb_manage_bookings' );
		}
		$editor = get_role( 'shop_manager' );
		if ( $editor && ! $editor->has_cap( 'osb_manage_bookings' ) ) {
			$editor->add_cap( 'osb_manage_bookings' );
		}
	}

	/**
	 * Create a "Book Online" page with the shortcode on first activation.
	 */
	private static function maybe_create_booking_page() {
		$settings = get_option( 'osb_settings', array() );
		if ( ! empty( $settings['booking_page'] ) && get_post( $settings['booking_page'] ) ) {
			return;
		}
		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Book Online', 'osam-bookings' ),
				'post_content' => '<!-- wp:shortcode -->[osam_booking]<!-- /wp:shortcode -->',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		if ( $page_id && ! is_wp_error( $page_id ) ) {
			$settings                 = is_array( $settings ) ? $settings : array();
			$settings['booking_page'] = (int) $page_id;
			update_option( 'osb_settings', $settings );
		}
	}

	/**
	 * Table names.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'osb_' . $name;
	}

	/**
	 * Create or update tables with dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$c = $wpdb->get_charset_collate();

		$sql = array();

		$sql[] = 'CREATE TABLE ' . self::table( 'services' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			description text NULL,
			category varchar(191) NOT NULL DEFAULT '',
			duration int(11) NOT NULL DEFAULT 60,
			price decimal(12,2) NOT NULL DEFAULT 0.00,
			capacity int(11) NOT NULL DEFAULT 1,
			buffer_before int(11) NOT NULL DEFAULT 0,
			buffer_after int(11) NOT NULL DEFAULT 0,
			color varchar(20) NOT NULL DEFAULT '#0273b0',
			image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			payment_methods varchar(191) NOT NULL DEFAULT '',
			online_mode varchar(20) NOT NULL DEFAULT '',
			online_url varchar(500) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'staff' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			description text NULL,
			image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'service_staff' ) . " (
			service_id bigint(20) unsigned NOT NULL,
			staff_id bigint(20) unsigned NOT NULL,
			price decimal(12,2) NULL DEFAULT NULL,
			PRIMARY KEY  (service_id,staff_id),
			KEY staff_id (staff_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'schedule' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			staff_id bigint(20) unsigned NOT NULL,
			weekday tinyint(1) NOT NULL,
			start_time time NOT NULL,
			end_time time NOT NULL,
			PRIMARY KEY  (id),
			KEY staff_day (staff_id,weekday)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'days_off' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			staff_id bigint(20) unsigned NOT NULL DEFAULT 0,
			date_from date NOT NULL,
			date_to date NOT NULL,
			note varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY staff_id (staff_id),
			KEY dates (date_from,date_to)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'events' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			description text NULL,
			location varchar(255) NOT NULL DEFAULT '',
			start_at datetime NOT NULL,
			end_at datetime NOT NULL,
			capacity int(11) NOT NULL DEFAULT 10,
			max_per_booking int(11) NOT NULL DEFAULT 10,
			price decimal(12,2) NOT NULL DEFAULT 0.00,
			staff_id bigint(20) unsigned NOT NULL DEFAULT 0,
			image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			color varchar(20) NOT NULL DEFAULT '#e8590c',
			payment_methods varchar(191) NOT NULL DEFAULT '',
			online_mode varchar(20) NOT NULL DEFAULT '',
			online_url varchar(500) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY start_at (start_at),
			KEY status (status)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'courses' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			description text NULL,
			location varchar(255) NOT NULL DEFAULT '',
			price decimal(12,2) NOT NULL DEFAULT 0.00,
			capacity int(11) NOT NULL DEFAULT 10,
			max_per_booking int(11) NOT NULL DEFAULT 1,
			staff_id bigint(20) unsigned NOT NULL DEFAULT 0,
			image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			color varchar(20) NOT NULL DEFAULT '#2f9e44',
			payment_methods varchar(191) NOT NULL DEFAULT '',
			online_mode varchar(20) NOT NULL DEFAULT '',
			online_url varchar(500) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'course_intakes' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			course_id bigint(20) unsigned NOT NULL,
			name varchar(191) NOT NULL DEFAULT '',
			capacity int(11) NOT NULL DEFAULT 0,
			price decimal(12,2) NULL DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			first_start datetime NULL DEFAULT NULL,
			last_end datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY course_id (course_id),
			KEY first_start (first_start)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'course_sessions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			intake_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL DEFAULT 0,
			start_at datetime NOT NULL,
			end_at datetime NOT NULL,
			location varchar(255) NOT NULL DEFAULT '',
			staff_id bigint(20) unsigned NOT NULL DEFAULT 0,
			note varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY intake_start (intake_id,start_at),
			KEY staff_start (staff_id,start_at)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'attendance' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			session_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'booked',
			reminder_sent tinyint(1) NOT NULL DEFAULT 0,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY booking_session (booking_id,session_id),
			KEY session_id (session_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'calendar_connections' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			staff_id bigint(20) unsigned NOT NULL,
			provider varchar(20) NOT NULL DEFAULT '',
			via varchar(20) NOT NULL DEFAULT '',
			account varchar(191) NOT NULL DEFAULT '',
			access_token text NULL,
			refresh_token text NULL,
			expires_at bigint(20) NOT NULL DEFAULT 0,
			calendar_id varchar(255) NOT NULL DEFAULT '',
			calendar_name varchar(191) NOT NULL DEFAULT '',
			calendars longtext NULL,
			busy_calendars text NULL,
			push tinyint(1) NOT NULL DEFAULT 1,
			pull tinyint(1) NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'active',
			last_error text NULL,
			last_sync datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY staff_id (staff_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'calendar_events' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connection_id bigint(20) unsigned NOT NULL,
			object_key varchar(40) NOT NULL DEFAULT '',
			remote_id varchar(255) NOT NULL DEFAULT '',
			calendar_id varchar(255) NOT NULL DEFAULT '',
			hash char(32) NOT NULL DEFAULT '',
			start_at datetime NULL DEFAULT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY conn_key (connection_id,object_key),
			KEY remote_id (remote_id(64))
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'coupons' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(64) NOT NULL DEFAULT '',
			kind varchar(20) NOT NULL DEFAULT 'discount',
			type varchar(20) NOT NULL DEFAULT 'percent',
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			balance decimal(12,2) NOT NULL DEFAULT 0.00,
			applies_to varchar(20) NOT NULL DEFAULT 'all',
			item_ids text NULL,
			min_spend decimal(12,2) NOT NULL DEFAULT 0.00,
			max_uses int(11) NOT NULL DEFAULT 0,
			uses int(11) NOT NULL DEFAULT 0,
			once_per_customer tinyint(1) NOT NULL DEFAULT 0,
			first_visit tinyint(1) NOT NULL DEFAULT 0,
			starts_at date NULL DEFAULT NULL,
			expires_at date NULL DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			recipient varchar(191) NOT NULL DEFAULT '',
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY kind (kind)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'coupon_uses' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			coupon_id bigint(20) unsigned NOT NULL,
			booking_id bigint(20) unsigned NOT NULL,
			email varchar(191) NOT NULL DEFAULT '',
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			status varchar(20) NOT NULL DEFAULT 'applied',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY coupon_id (coupon_id),
			KEY booking_id (booking_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'waitlist' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL DEFAULT 'event',
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			intake_id bigint(20) unsigned NOT NULL DEFAULT 0,
			seats int(11) NOT NULL DEFAULT 1,
			name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			token varchar(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'waiting',
			offered_at datetime NULL DEFAULT NULL,
			expires_at datetime NULL DEFAULT NULL,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY item (type,event_id,intake_id,status),
			KEY token (token)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'customers' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(191) NOT NULL DEFAULT '',
			name varchar(191) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			notes text NULL,
			tags varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'bookings' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL DEFAULT 'service',
			service_id bigint(20) unsigned NOT NULL DEFAULT 0,
			staff_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			course_id bigint(20) unsigned NOT NULL DEFAULT 0,
			intake_id bigint(20) unsigned NOT NULL DEFAULT 0,
			start_at datetime NOT NULL,
			end_at datetime NOT NULL,
			seats int(11) NOT NULL DEFAULT 1,
			customer_name varchar(191) NOT NULL DEFAULT '',
			customer_email varchar(191) NOT NULL DEFAULT '',
			customer_phone varchar(50) NOT NULL DEFAULT '',
			fields longtext NULL,
			guests longtext NULL,
			internal_note text NULL,
			status varchar(30) NOT NULL DEFAULT 'pending',
			payment_method varchar(30) NOT NULL DEFAULT '',
			payment_status varchar(30) NOT NULL DEFAULT 'unpaid',
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			currency varchar(3) NOT NULL DEFAULT 'GBP',
			token varchar(64) NOT NULL DEFAULT '',
			wc_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reminder_sent tinyint(1) NOT NULL DEFAULT 0,
			followup_sent tinyint(1) NOT NULL DEFAULT 0,
			discount decimal(12,2) NOT NULL DEFAULT 0.00,
			coupon_code varchar(64) NOT NULL DEFAULT '',
			meeting_url varchar(500) NOT NULL DEFAULT '',
			meeting_id varchar(191) NOT NULL DEFAULT '',
			area_id bigint(20) unsigned NOT NULL DEFAULT 0,
			series_id bigint(20) unsigned NOT NULL DEFAULT 0,
			repeat_rule varchar(40) NOT NULL DEFAULT '',
			pass_id bigint(20) unsigned NOT NULL DEFAULT 0,
			bundle_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY series_id (series_id),
			KEY staff_start (staff_id,start_at),
			KEY event_id (event_id),
			KEY intake_id (intake_id),
			KEY status (status),
			KEY token (token),
			KEY customer_email (customer_email)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'payments' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			gateway varchar(30) NOT NULL DEFAULT '',
			transaction_id varchar(191) NOT NULL DEFAULT '',
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			currency varchar(3) NOT NULL DEFAULT 'GBP',
			status varchar(30) NOT NULL DEFAULT '',
			data longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY transaction_id (transaction_id)
		) $c;";

		// Restaurant table booking: dining areas, tables, table combinations, service periods,
		// closures, and which tables each booking sits at.
		$sql[] = 'CREATE TABLE ' . self::table( 'rest_areas' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			online tinyint(1) NOT NULL DEFAULT 1,
			plan_width int(11) NOT NULL DEFAULT 1000,
			plan_height int(11) NOT NULL DEFAULT 640,
			status varchar(20) NOT NULL DEFAULT 'active',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'rest_tables' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			area_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(64) NOT NULL DEFAULT '',
			min_covers int(11) NOT NULL DEFAULT 1,
			max_covers int(11) NOT NULL DEFAULT 2,
			online tinyint(1) NOT NULL DEFAULT 1,
			priority int(11) NOT NULL DEFAULT 0,
			shape varchar(20) NOT NULL DEFAULT 'square',
			plan_x int(11) NOT NULL DEFAULT 40,
			plan_y int(11) NOT NULL DEFAULT 40,
			plan_w int(11) NOT NULL DEFAULT 80,
			plan_h int(11) NOT NULL DEFAULT 80,
			rotation int(11) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY area_id (area_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'rest_combos' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			table_ids varchar(255) NOT NULL DEFAULT '',
			min_covers int(11) NOT NULL DEFAULT 1,
			max_covers int(11) NOT NULL DEFAULT 4,
			online tinyint(1) NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'rest_periods' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			weekdays varchar(20) NOT NULL DEFAULT '',
			start_time time NOT NULL,
			last_seating time NOT NULL,
			slot_interval int(11) NOT NULL DEFAULT 15,
			max_covers_slot int(11) NOT NULL DEFAULT 0,
			max_covers_total int(11) NOT NULL DEFAULT 0,
			areas varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'rest_closures' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			date_from date NOT NULL,
			date_to date NOT NULL,
			period_id bigint(20) unsigned NOT NULL DEFAULT 0,
			note varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY dates (date_from,date_to)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'booking_tables' ) . " (
			booking_id bigint(20) unsigned NOT NULL,
			table_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (booking_id,table_id),
			KEY table_id (table_id)
		) $c;";

		// Bookable resources (meeting rooms, fields, halls…): opening hours, closures, and which
		// resources each booking takes (places or the whole thing).
		$sql[] = 'CREATE TABLE ' . self::table( 'resources' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			description text NULL,
			category varchar(191) NOT NULL DEFAULT '',
			location varchar(255) NOT NULL DEFAULT '',
			image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			color varchar(20) NOT NULL DEFAULT '#0c8599',
			capacity int(11) NOT NULL DEFAULT 1,
			share_mode varchar(20) NOT NULL DEFAULT 'both',
			max_per_booking int(11) NOT NULL DEFAULT 0,
			time_mode varchar(20) NOT NULL DEFAULT 'slots',
			slot_length int(11) NOT NULL DEFAULT 60,
			max_slots int(11) NOT NULL DEFAULT 1,
			slot_options text NULL,
			slot_step int(11) NOT NULL DEFAULT 0,
			step int(11) NOT NULL DEFAULT 30,
			min_minutes int(11) NOT NULL DEFAULT 60,
			max_minutes int(11) NOT NULL DEFAULT 240,
			buffer_before int(11) NOT NULL DEFAULT 0,
			buffer_after int(11) NOT NULL DEFAULT 0,
			hourly tinyint(1) NOT NULL DEFAULT 1,
			half_day tinyint(1) NOT NULL DEFAULT 0,
			half_split time NOT NULL DEFAULT '13:00:00',
			full_day tinyint(1) NOT NULL DEFAULT 0,
			max_days int(11) NOT NULL DEFAULT 1,
			price_place decimal(12,2) NOT NULL DEFAULT 0.00,
			price_exclusive decimal(12,2) NOT NULL DEFAULT 0.00,
			price_half_place decimal(12,2) NOT NULL DEFAULT 0.00,
			price_half_exclusive decimal(12,2) NOT NULL DEFAULT 0.00,
			price_day_place decimal(12,2) NOT NULL DEFAULT 0.00,
			price_day_exclusive decimal(12,2) NOT NULL DEFAULT 0.00,
			min_notice_hours decimal(6,2) NULL DEFAULT NULL,
			max_advance_days int(11) NOT NULL DEFAULT 0,
			payment_methods varchar(191) NOT NULL DEFAULT '',
			access_mode varchar(10) NOT NULL DEFAULT '',
			access_code varchar(64) NOT NULL DEFAULT '',
			access_length tinyint(2) NOT NULL DEFAULT 4,
			access_label varchar(60) NOT NULL DEFAULT '',
			access_note text NULL,
			access_release int(11) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'res_hours' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			resource_id bigint(20) unsigned NOT NULL,
			season_id bigint(20) unsigned NOT NULL DEFAULT 0,
			weekday tinyint(1) NOT NULL,
			start_time time NOT NULL,
			end_time time NOT NULL,
			PRIMARY KEY  (id),
			KEY res_day (resource_id,weekday),
			KEY res_season (resource_id,season_id)
		) $c;";

		// Seasonal opening hours (their weekly hours are res_hours rows with this season_id).
		$sql[] = 'CREATE TABLE ' . self::table( 'res_seasons' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			resource_id bigint(20) unsigned NOT NULL,
			name varchar(100) NOT NULL DEFAULT '',
			mode varchar(10) NOT NULL DEFAULT 'dates',
			date_from date NULL DEFAULT NULL,
			date_to date NULL DEFAULT NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY resource_id (resource_id)
		) $c;";

		// Maintenance: one-off periods or a weekly slot, during which a resource can't be booked.
		$sql[] = 'CREATE TABLE ' . self::table( 'res_maintenance' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			resource_id bigint(20) unsigned NOT NULL,
			repeat_mode varchar(10) NOT NULL DEFAULT 'once',
			date_from date NOT NULL,
			date_to date NULL DEFAULT NULL,
			weekdays varchar(20) NOT NULL DEFAULT '',
			start_time time NULL DEFAULT NULL,
			end_time time NULL DEFAULT NULL,
			note varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY resource_id (resource_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'res_closures' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			resource_id bigint(20) unsigned NOT NULL DEFAULT 0,
			date_from date NOT NULL,
			date_to date NOT NULL,
			note varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY resource_id (resource_id),
			KEY dates (date_from,date_to)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'booking_resources' ) . " (
			booking_id bigint(20) unsigned NOT NULL,
			resource_id bigint(20) unsigned NOT NULL,
			places int(11) NOT NULL DEFAULT 1,
			exclusive tinyint(1) NOT NULL DEFAULT 0,
			hire varchar(10) NOT NULL DEFAULT 'time',
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			access_code varchar(64) NOT NULL DEFAULT '',
			access_sent tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (booking_id,resource_id),
			KEY resource_id (resource_id)
		) $c;";

		// Add-ons (extras) and the ones chosen on each booking.
		$sql[] = 'CREATE TABLE ' . self::table( 'addons' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			description text NULL,
			price decimal(12,2) NOT NULL DEFAULT 0.00,
			price_type varchar(20) NOT NULL DEFAULT 'booking',
			max_qty int(11) NOT NULL DEFAULT 1,
			required tinyint(1) NOT NULL DEFAULT 0,
			applies text NULL,
			image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'booking_addons' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			addon_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(191) NOT NULL DEFAULT '',
			qty int(11) NOT NULL DEFAULT 1,
			unit_price decimal(12,2) NOT NULL DEFAULT 0.00,
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id)
		) $c;";

		// Bundles for sale, the passes customers buy, and each booking a pass paid for.
		$sql[] = 'CREATE TABLE ' . self::table( 'bundles' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			description text NULL,
			credits int(11) NOT NULL DEFAULT 10,
			unit varchar(10) NOT NULL DEFAULT 'booking',
			price decimal(12,2) NOT NULL DEFAULT 0.00,
			valid_days int(11) NOT NULL DEFAULT 365,
			applies text NULL,
			payment_methods varchar(191) NOT NULL DEFAULT '',
			image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			color varchar(20) NOT NULL DEFAULT '#0273b0',
			status varchar(20) NOT NULL DEFAULT 'active',
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'passes' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bundle_id bigint(20) unsigned NOT NULL DEFAULT 0,
			code varchar(32) NOT NULL DEFAULT '',
			name varchar(191) NOT NULL DEFAULT '',
			customer_name varchar(191) NOT NULL DEFAULT '',
			customer_email varchar(191) NOT NULL DEFAULT '',
			credits int(11) NOT NULL DEFAULT 0,
			used int(11) NOT NULL DEFAULT 0,
			unit varchar(10) NOT NULL DEFAULT 'booking',
			applies text NULL,
			expires_at date NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			note text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY customer_email (customer_email),
			KEY booking_id (booking_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'pass_uses' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pass_id bigint(20) unsigned NOT NULL,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			credits int(11) NOT NULL DEFAULT 1,
			note varchar(191) NOT NULL DEFAULT '',
			refunded tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY pass_id (pass_id),
			KEY booking_id (booking_id)
		) $c;";

		// Every email sent about a booking (standard and custom), for the booking's history and so
		// scheduled custom emails go out once.
		$sql[] = 'CREATE TABLE ' . self::table( 'email_log' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email_key varchar(64) NOT NULL DEFAULT '',
			dedupe varchar(191) NOT NULL DEFAULT '',
			recipient varchar(255) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			sent tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY email_dedupe (email_key,dedupe)
		) $c;";

		// Invoices and credit notes. Amounts never change once issued; credit notes point at their invoice.
		$sql[] = 'CREATE TABLE ' . self::table( 'invoices' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(20) NOT NULL DEFAULT 'invoice',
			number varchar(40) NOT NULL DEFAULT '',
			seq int(11) unsigned NOT NULL DEFAULT 0,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			booking_ids varchar(500) NOT NULL DEFAULT '',
			payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'paid',
			customer_name varchar(191) NOT NULL DEFAULT '',
			customer_email varchar(191) NOT NULL DEFAULT '',
			bill_to text NULL,
			currency varchar(3) NOT NULL DEFAULT 'GBP',
			subtotal decimal(12,2) NOT NULL DEFAULT 0.00,
			vat_rate decimal(5,2) NOT NULL DEFAULT 0.00,
			vat_amount decimal(12,2) NOT NULL DEFAULT 0.00,
			total decimal(12,2) NOT NULL DEFAULT 0.00,
			items longtext NULL,
			seller longtext NULL,
			notes text NULL,
			payment_method varchar(30) NOT NULL DEFAULT '',
			payment_label varchar(100) NOT NULL DEFAULT '',
			payment_ref varchar(191) NOT NULL DEFAULT '',
			paid_at datetime NULL,
			issued_at datetime NOT NULL,
			emailed_at datetime NULL,
			token varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY number (number),
			KEY kind_seq (kind,seq),
			KEY booking_id (booking_id),
			KEY payment_id (payment_id),
			KEY parent_id (parent_id),
			KEY customer_email (customer_email),
			KEY issued_at (issued_at),
			KEY token (token)
		) $c;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
