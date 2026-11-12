<?php
/**
 * Reads Amelia's own tables on this site into an import dataset (see OSB_Migrate).
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Amelia keeps its data in {prefix}amelia_* tables, which stay in the database after Amelia is
 * switched off. Every query is SELECT * and each column is read with a fallback, so tables from
 * older or newer Amelia versions with extra or missing columns still work.
 *
 * Amelia stores appointment and event times in UTC; this plugin stores the site's local time, so
 * every time is converted with the site's timezone (Settings → General).
 */
class OSB_Import_Amelia {

	/**
	 * Full table name.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	private static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'amelia_' . $name;
	}

	/**
	 * Does a table exist? (A plain SELECT works on MySQL and on SQLite.)
	 *
	 * @param string $name Short name.
	 * @return bool
	 */
	public static function has( $name ) {
		global $wpdb;
		static $known = array();
		if ( ! isset( $known[ $name ] ) ) {
			$was = $wpdb->suppress_errors( true );
			$wpdb->last_error = '';
			$wpdb->get_var( 'SELECT 1 FROM ' . self::t( $name ) . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$known[ $name ] = '' === (string) $wpdb->last_error;
			$wpdb->suppress_errors( $was );
		}
		return $known[ $name ];
	}

	/**
	 * Every row of a table (empty if it isn't there).
	 *
	 * @param string $name Short name.
	 * @return array
	 */
	private static function rows( $name ) {
		global $wpdb;
		if ( ! self::has( $name ) ) {
			return array();
		}
		return (array) $wpdb->get_results( 'SELECT * FROM ' . self::t( $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count rows.
	 *
	 * @param string $name  Short name.
	 * @param string $where Fixed WHERE clause.
	 * @return int
	 */
	private static function count( $name, $where = '' ) {
		global $wpdb;
		if ( ! self::has( $name ) ) {
			return 0;
		}
		$was = $wpdb->suppress_errors( true );
		$n   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( $name ) . ( $where ? ' WHERE ' . $where : '' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->suppress_errors( $was );
		return $n;
	}

	/**
	 * Column value with a fallback.
	 *
	 * @param array  $r   Row.
	 * @param string $k   Column.
	 * @param mixed  $def Fallback.
	 * @return mixed
	 */
	private static function v( array $r, $k, $def = '' ) {
		return isset( $r[ $k ] ) && null !== $r[ $k ] ? $r[ $k ] : $def;
	}

	/**
	 * Is Amelia's data on this site?
	 *
	 * @return bool
	 */
	public static function available() {
		return self::has( 'users' ) && self::has( 'services' ) && self::has( 'appointments' ) && self::has( 'customer_bookings' );
	}

	/**
	 * What's there, for the start screen.
	 *
	 * @return array label => count
	 */
	public static function summary() {
		return array(
			__( 'Services', 'osam-bookings' )      => self::count( 'services' ),
			__( 'Employees', 'osam-bookings' )     => self::count( 'users', "type = 'provider'" ),
			__( 'Customers', 'osam-bookings' )     => self::count( 'users', "type = 'customer'" ),
			__( 'Appointments', 'osam-bookings' )  => self::count( 'appointments' ),
			__( 'Events', 'osam-bookings' )        => self::count( 'events' ),
			__( 'Email templates', 'osam-bookings' ) => self::count( 'notifications', "type = 'email'" ),
		);
	}

	/**
	 * Amelia booking status → ours ('' = leave it out).
	 *
	 * @param string $s Amelia status.
	 * @return array [ status, skip reason ]
	 */
	private static function status( $s ) {
		switch ( strtolower( (string) $s ) ) {
			case 'approved':
				return array( 'confirmed', '' );
			case 'pending':
				return array( 'pending', '' );
			case 'canceled':
			case 'cancelled':
			case 'rejected':
				return array( 'cancelled', '' );
			case 'no-show':
			case 'noshow':
			case 'no_show':
				return array( 'no_show', '' );
			case 'waiting':
				return array( '', 'waiting' );
		}
		return array( 'confirmed', '' );
	}

	/**
	 * Amelia payment gateway → ours.
	 *
	 * @param string $g Gateway.
	 * @return string
	 */
	private static function gateway( $g ) {
		$map = array(
			'onsite' => 'onsite',
			'paypal' => 'paypal',
			'stripe' => 'stripe',
			'wc'     => 'woocommerce',
		);
		$g   = strtolower( (string) $g );
		return isset( $map[ $g ] ) ? $map[ $g ] : 'onsite';
	}

	/**
	 * Build the dataset.
	 *
	 * @return array|WP_Error
	 */
	public static function dataset() {
		if ( ! self::available() ) {
			return new WP_Error( 'osb_amelia_missing', __( 'We couldn’t find Amelia’s data on this site. Amelia must have been used on this same WordPress site (it can be switched off, but not deleted with “remove all data”).', 'osam-bookings' ) );
		}
		$warn = array();

		// Currency.
		$amelia_set = json_decode( (string) get_option( 'amelia_settings', '' ), true );
		$currency   = is_array( $amelia_set ) && ! empty( $amelia_set['payments']['currency'] ) ? strtoupper( (string) $amelia_set['payments']['currency'] ) : '';
		if ( $currency && $currency !== strtoupper( (string) OSB_Settings::get( 'currency' ) ) ) {
			/* translators: 1: Amelia currency 2: this site's currency */
			$warn[] = sprintf( __( 'Amelia used %1$s but this site is set to %2$s. Imported bookings keep %1$s; new services use the prices as they are. Check the currency in Settings → Payments.', 'osam-bookings' ), $currency, OSB_Settings::get( 'currency' ) );
		}

		// People.
		$staff     = array();
		$customers = array();
		$people    = array();
		foreach ( self::rows( 'users' ) as $u ) {
			$name                         = trim( self::v( $u, 'firstName' ) . ' ' . self::v( $u, 'lastName' ) );
			$people[ (int) $u['id'] ] = array(
				'name'  => $name,
				'email' => trim( (string) self::v( $u, 'email' ) ),
				'phone' => trim( (string) self::v( $u, 'phone' ) ),
			);
			if ( 'provider' === self::v( $u, 'type' ) ) {
				$staff[ (string) $u['id'] ] = array(
					'name'        => '' !== $name ? $name : __( 'Staff member', 'osam-bookings' ),
					'email'       => $people[ (int) $u['id'] ]['email'],
					'phone'       => $people[ (int) $u['id'] ]['phone'],
					'description' => (string) self::v( $u, 'description' ),
				);
			} elseif ( 'customer' === self::v( $u, 'type' ) ) {
				$customers[] = array(
					'name'  => $name,
					'email' => $people[ (int) $u['id'] ]['email'],
					'phone' => $people[ (int) $u['id'] ]['phone'],
					'notes' => (string) self::v( $u, 'note' ),
					'tags'  => 'Amelia',
				);
			}
		}

		// Services.
		$cats = array();
		foreach ( self::rows( 'categories' ) as $c ) {
			$cats[ (int) $c['id'] ] = (string) self::v( $c, 'name' );
		}
		$who = array();
		foreach ( self::rows( 'providers_to_services' ) as $p ) {
			$who[ (int) self::v( $p, 'serviceId', 0 ) ][ (string) self::v( $p, 'userId', 0 ) ] = '' !== (string) self::v( $p, 'price' ) ? (float) $p['price'] : '';
		}
		$services = array();
		$svc_rows = array();
		foreach ( self::rows( 'services' ) as $s ) {
			$id              = (int) $s['id'];
			$svc_rows[ $id ] = $s;
			$status          = (string) self::v( $s, 'status', 'visible' );
			if ( 'disabled' === $status ) {
				$status = 'hidden';
			}
			$services[ (string) $id ] = array(
				'name'          => (string) self::v( $s, 'name', '#' . $id ),
				'description'   => (string) self::v( $s, 'description' ),
				'category'      => isset( $cats[ (int) self::v( $s, 'categoryId', 0 ) ] ) ? $cats[ (int) $s['categoryId'] ] : '',
				'duration'      => max( 5, (int) round( (int) self::v( $s, 'duration', 3600 ) / 60 ) ),
				'price'         => (float) self::v( $s, 'price', 0 ),
				'capacity'      => max( 1, (int) self::v( $s, 'maxCapacity', 1 ) ),
				'buffer_before' => (int) round( (int) self::v( $s, 'timeBefore', 0 ) / 60 ),
				'buffer_after'  => (int) round( (int) self::v( $s, 'timeAfter', 0 ) / 60 ),
				'color'         => (string) self::v( $s, 'color', '#0273b0' ),
				'status'        => 'visible' === $status ? 'active' : 'hidden',
				'staff'         => isset( $who[ $id ] ) ? $who[ $id ] : array(),
			);
		}

		// Events (one Amelia event can run over several periods: it becomes one event from the first start to the last end).
		$periods = array();
		$per_ev  = array();
		foreach ( self::rows( 'events_periods' ) as $p ) {
			$start = OSB_Migrate::utc_to_local( self::v( $p, 'periodStart' ) );
			$end   = OSB_Migrate::utc_to_local( self::v( $p, 'periodEnd' ) );
			$eid   = (int) self::v( $p, 'eventId', 0 );
			$periods[ (int) $p['id'] ] = $eid;
			if ( '' === $start ) {
				continue;
			}
			$per_ev[ $eid ][] = array( $start, $end ? $end : $start );
		}
		$hosts = array();
		foreach ( self::rows( 'events_to_providers' ) as $h ) {
			$hosts[ (int) self::v( $h, 'eventId', 0 ) ] = (string) self::v( $h, 'userId', '' );
		}
		$events  = array();
		$ev_rows = array();
		$multi   = 0;
		foreach ( self::rows( 'events' ) as $e ) {
			$id             = (int) $e['id'];
			$ev_rows[ $id ] = $e;
			$list           = isset( $per_ev[ $id ] ) ? $per_ev[ $id ] : array();
			sort( $list );
			$start = $list ? $list[0][0] : '';
			$end   = '';
			foreach ( $list as $p ) {
				$end = max( $end, $p[1] );
			}
			if ( count( $list ) > 1 ) {
				++$multi;
			}
			$organiser             = (string) self::v( $e, 'organizerId', '' );
			$events[ (string) $id ] = array(
				'name'        => (string) self::v( $e, 'name', '#' . $id ),
				'description' => (string) self::v( $e, 'description' ),
				'location'    => (string) self::v( $e, 'customLocation' ),
				'start'       => $start,
				'end'         => $end,
				'capacity'    => max( 1, (int) self::v( $e, 'maxCapacity', 10 ) ),
				'price'       => (float) self::v( $e, 'price', 0 ),
				'staff'       => '' !== $organiser ? $organiser : ( isset( $hosts[ $id ] ) ? $hosts[ $id ] : '' ),
				'status'      => in_array( self::v( $e, 'status', 'approved' ), array( 'approved', 'pending' ), true ) && (int) self::v( $e, 'show', 1 ) ? 'active' : 'hidden',
				'color'       => (string) self::v( $e, 'color', '#e8590c' ),
			);
		}
		if ( $multi ) {
			/* translators: %d events */
			$warn[] = sprintf( _n( '%d Amelia event ran over several separate dates. It comes across as one event from its first start to its last finish — check its times afterwards.', '%d Amelia events ran over several separate dates. Each comes across as one event from its first start to its last finish — check their times afterwards.', $multi, 'osam-bookings' ), $multi );
		}

		// Payments per customer booking.
		$paid = array();
		foreach ( self::rows( 'payments' ) as $p ) {
			$bid = (int) self::v( $p, 'customerBookingId', 0 );
			if ( ! $bid ) {
				continue;
			}
			if ( ! isset( $paid[ $bid ] ) ) {
				$paid[ $bid ] = array( 'sum' => 0.0, 'status' => '', 'gateway' => '' );
			}
			$st = (string) self::v( $p, 'status' );
			if ( in_array( $st, array( 'paid', 'partiallyPaid' ), true ) ) {
				$paid[ $bid ]['sum'] += (float) self::v( $p, 'amount', 0 );
			}
			if ( 'refunded' === $st || '' === $paid[ $bid ]['status'] || 'pending' === $paid[ $bid ]['status'] ) {
				$paid[ $bid ]['status'] = $st;
			}
			$paid[ $bid ]['gateway'] = (string) self::v( $p, 'gateway', $paid[ $bid ]['gateway'] );
		}

		// Which event period each event booking belongs to.
		$booking_event = array();
		foreach ( self::rows( 'customer_bookings_to_events_periods' ) as $l ) {
			$pid = (int) self::v( $l, 'eventPeriodId', 0 );
			if ( isset( $periods[ $pid ] ) ) {
				$booking_event[ (int) self::v( $l, 'customerBookingId', 0 ) ] = $periods[ $pid ];
			}
		}

		$appts = array();
		foreach ( self::rows( 'appointments' ) as $a ) {
			$appts[ (int) $a['id'] ] = $a;
		}

		$bookings = array();
		$package  = 0;
		foreach ( self::rows( 'customer_bookings' ) as $cb ) {
			$bid   = (int) $cb['id'];
			$aid   = (int) self::v( $cb, 'appointmentId', 0 );
			$info  = json_decode( (string) self::v( $cb, 'info', '' ), true );
			$info  = is_array( $info ) ? $info : array();
			$cust  = isset( $people[ (int) self::v( $cb, 'customerId', 0 ) ] ) ? $people[ (int) $cb['customerId'] ] : array( 'name' => '', 'email' => '', 'phone' => '' );
			$name  = trim( ( isset( $info['firstName'] ) ? $info['firstName'] : '' ) . ' ' . ( isset( $info['lastName'] ) ? $info['lastName'] : '' ) );
			$name  = '' !== $name ? $name : $cust['name'];
			$phone = ! empty( $info['phone'] ) ? (string) $info['phone'] : $cust['phone'];
			list( $status, $skip ) = self::status( self::v( $cb, 'status' ) );
			$persons = max( 1, (int) self::v( $cb, 'persons', 1 ) );
			$price   = (float) self::v( $cb, 'price', 0 );
			$note    = array();

			if ( $aid && isset( $appts[ $aid ] ) ) {
				$a = $appts[ $aid ];
				if ( in_array( self::v( $a, 'status' ), array( 'canceled', 'rejected' ), true ) && '' === $skip ) {
					$status = 'cancelled';
				}
				$kind  = 'service';
				$item  = (string) self::v( $a, 'serviceId', '' );
				$prov  = (string) self::v( $a, 'providerId', '' );
				$start = OSB_Migrate::utc_to_local( self::v( $a, 'bookingStart' ) );
				$end   = OSB_Migrate::utc_to_local( self::v( $a, 'bookingEnd' ) );
				if ( '' !== trim( (string) self::v( $a, 'internalNotes' ) ) ) {
					$note[] = (string) $a['internalNotes'];
				}
				// Older Amelia versions have no aggregatedPrice column: the price was per person for group services.
				$per_person = (int) self::v( $cb, 'aggregatedPrice', ( isset( $svc_rows[ (int) $item ] ) ? (int) self::v( $svc_rows[ (int) $item ], 'aggregatedPrice', 1 ) : 1 ) );
				if ( self::v( $cb, 'packageCustomerServiceId', '' ) ) {
					++$package;
					$note[] = __( 'Part of an Amelia package.', 'osam-bookings' );
				}
			} elseif ( isset( $booking_event[ $bid ] ) ) {
				$kind       = 'event';
				$item       = (string) $booking_event[ $bid ];
				$prov       = '';
				$start      = isset( $events[ $item ] ) ? $events[ $item ]['start'] : '';
				$end        = isset( $events[ $item ] ) ? $events[ $item ]['end'] : '';
				$per_person = (int) self::v( $cb, 'aggregatedPrice', 1 );
				if ( isset( $ev_rows[ (int) $item ] ) && in_array( self::v( $ev_rows[ (int) $item ], 'status' ), array( 'canceled', 'rejected' ), true ) && '' === $skip ) {
					$status = 'cancelled';
				}
			} else {
				$kind  = 'service';
				$item  = '';
				$prov  = '';
				$start = '';
				$end   = '';
				$per_person = 1;
			}

			$amount = round( $price * ( $per_person ? $persons : 1 ), 2 );
			$p      = isset( $paid[ $bid ] ) ? $paid[ $bid ] : null;
			$pay    = 'unpaid';
			if ( $p && 'refunded' === $p['status'] ) {
				$pay = 'refunded';
			} elseif ( $amount <= 0 ) {
				$pay = 'free';
			} elseif ( $p && $p['sum'] >= $amount - 0.004 ) {
				$pay = 'paid';
			} elseif ( $p && $p['sum'] > 0 ) {
				/* translators: %s amount */
				$note[] = sprintf( __( 'Part-paid in Amelia: %s.', 'osam-bookings' ), OSB_Settings::money( $p['sum'], $currency ) );
			}

			$custom = json_decode( (string) self::v( $cb, 'customFields', '' ), true );
			foreach ( is_array( $custom ) ? $custom : array() as $f ) {
				if ( is_array( $f ) && isset( $f['label'], $f['value'] ) && is_scalar( $f['value'] ) && '' !== trim( (string) $f['value'] ) ) {
					$note[] = wp_strip_all_tags( (string) $f['label'] ) . ': ' . $f['value'];
				}
			}

			$bookings[] = array(
				'id'            => (string) $bid,
				'kind'          => $kind,
				'item'          => $item,
				'staff'         => $prov,
				'start'         => $start,
				'end'           => $end,
				'seats'         => $persons,
				'name'          => $name,
				'email'         => $cust['email'],
				'phone'         => $phone,
				'status'        => $status,
				'skip'          => $skip,
				'pay'           => $pay,
				'method'        => $p ? self::gateway( $p['gateway'] ) : 'onsite',
				'amount'        => $amount,
				'currency'      => $currency,
				'note'          => implode( "\n", $note ),
				'customer_note' => '',
				'created'       => OSB_Migrate::utc_to_local( self::v( $cb, 'created', '' ) ),
			);
		}
		if ( $package ) {
			/* translators: %d bookings */
			$warn[] = sprintf( _n( '%d booking was part of an Amelia package. It comes across as a normal booking; the package itself isn’t imported.', '%d bookings were part of Amelia packages. They come across as normal bookings; the packages themselves aren’t imported.', $package, 'osam-bookings' ), $package );
		}

		return array(
			'source'    => 'amelia',
			'staff'     => $staff,
			'services'  => $services,
			'events'    => $events,
			'customers' => $customers,
			'bookings'  => $bookings,
			'emails'    => self::emails(),
			'warnings'  => $warn,
		);
	}

	/* ---------------------------------------------------------------------
	 * Email templates
	 * ------------------------------------------------------------------ */

	/**
	 * Amelia placeholder → ours.
	 *
	 * @return array
	 */
	public static function placeholder_map() {
		return array(
			'%customer_full_name%'       => '{customer_name}',
			'%customer_first_name%'      => '{customer_first_name}',
			'%customer_email%'           => '{customer_email}',
			'%customer_phone%'           => '{customer_phone}',
			'%customer_note%'            => '{field:notes}',
			'%appointment_id%'           => '{booking_id}',
			'%appointment_date%'         => '{date}',
			'%appointment_date_time%'    => '{date} {time}',
			'%appointment_start_time%'   => '{time}',
			'%appointment_end_time%'     => '{end_time}',
			'%appointment_price%'        => '{amount}',
			'%appointment_status%'       => '{status}',
			'%appointment_cancel_url%'   => '{manage_url}',
			'%booking_price%'            => '{amount}',
			'%number_of_persons%'        => '{seats}',
			'%payment_type%'             => '{payment_method}',
			'%service_name%'             => '{service_name}',
			'%service_duration%'         => '{duration}',
			'%service_price%'            => '{amount}',
			'%employee_full_name%'       => '{staff_name}',
			'%employee_name%'            => '{staff_name}',
			'%location_name%'            => '{location}',
			'%location_address%'         => '{location}',
			'%company_name%'             => '{site_name}',
			'%company_website%'          => '{site_url}',
			'%zoom_join_url%'            => '{meeting_link}',
			'%google_meet_url%'          => '{meeting_link}',
			'%microsoft_teams_url%'      => '{meeting_link}',
			'%lesson_space_url%'         => '{meeting_link}',
			'%event_name%'               => '{event_name}',
			'%event_start_date%'         => '{date}',
			'%event_start_date_time%'    => '{date} {time}',
			'%event_start_time%'         => '{time}',
			'%event_end_time%'           => '{end_time}',
			'%event_location%'           => '{location}',
			'%event_price%'              => '{amount}',
		);
	}

	/**
	 * Swap placeholders.
	 *
	 * @param string $text    Text.
	 * @param array  $unknown Placeholders with no match (added to).
	 * @return string
	 */
	public static function translate( $text, array &$unknown ) {
		$text = strtr( (string) $text, self::placeholder_map() );
		if ( preg_match_all( '/%[a-z][a-z0-9_]*%/i', $text, $m ) ) {
			foreach ( $m[0] as $u ) {
				$unknown[ $u ] = $u;
			}
		}
		return $text;
	}

	/**
	 * Amelia appointment email templates, mapped to ours.
	 *
	 * @return array Dataset emails.
	 */
	public static function emails() {
		$solo   = OSB_DB::is_solo();
		$target = array(
			'customer_appointment_approved'          => 'customer_confirmed',
			'customer_appointment_pending'           => 'customer_pending',
			'customer_appointment_canceled'          => 'customer_cancelled',
			'customer_appointment_rescheduled'       => 'customer_rescheduled',
			'customer_appointment_next_day_reminder' => 'customer_reminder',
			'customer_appointment_follow_up'         => 'customer_followup',
			'customer_appointment_rejected'          => 'custom',
			'provider_appointment_approved'          => $solo ? 'admin_new' : 'staff_new',
			'provider_appointment_canceled'          => $solo ? 'admin_cancelled' : 'staff_cancelled',
			'provider_appointment_pending'           => 'custom',
			'provider_appointment_rejected'          => 'custom',
			'provider_appointment_rescheduled'       => 'custom',
			'provider_appointment_next_day_reminder' => 'custom',
			'provider_appointment_follow_up'         => 'custom',
		);
		// What a kept-aside email would be for, when it is switched on later.
		$custom = array(
			'customer_appointment_rejected'          => array( 'trigger' => 'cancelled' ),
			'provider_appointment_pending'           => array( 'trigger' => 'booked', 'to_customer' => 0, 'to_staff' => 1, 'statuses' => array( 'pending' ) ),
			'provider_appointment_rejected'          => array( 'trigger' => 'cancelled', 'to_customer' => 0, 'to_staff' => 1 ),
			'provider_appointment_rescheduled'       => array( 'trigger' => 'rescheduled', 'to_customer' => 0, 'to_staff' => 1 ),
			'provider_appointment_next_day_reminder' => array( 'trigger' => 'before_start', 'offset' => 1, 'unit' => 'days', 'to_customer' => 0, 'to_staff' => 1 ),
			'provider_appointment_follow_up'         => array( 'trigger' => 'after_end', 'offset' => 1, 'unit' => 'days', 'to_customer' => 0, 'to_staff' => 1 ),
		);
		$labels = OSB_Settings::email_labels();
		$out    = array();
		$used   = array();
		foreach ( self::rows( 'notifications' ) as $n ) {
			$name = (string) self::v( $n, 'name' );
			if ( 'email' !== self::v( $n, 'type', 'email' ) || ! isset( $target[ $name ] ) ) {
				continue;
			}
			$to = $target[ $name ];
			// A second (custom) Amelia notification of the same kind is kept as one of the site's own emails.
			if ( 'custom' !== $to && ( isset( $used[ $to ] ) || '' !== trim( (string) self::v( $n, 'customName' ) ) ) ) {
				$to = 'custom';
			}
			$unknown = array();
			$subject = self::translate( self::v( $n, 'subject' ), $unknown );
			$body    = self::translate( self::v( $n, 'content' ), $unknown );
			if ( '' === trim( $subject . wp_strip_all_tags( $body ) ) ) {
				continue;
			}
			$label = '' !== trim( (string) self::v( $n, 'customName' ) ) ? (string) $n['customName'] : ucfirst( str_replace( '_', ' ', $name ) );
			if ( 'custom' !== $to ) {
				$used[ $to ] = 1;
			}
			$out[] = array(
				'label'   => $label,
				'target'  => $to,
				'target_label' => 'custom' === $to ? __( 'Kept as one of your own emails (switched off)', 'osam-bookings' ) : ( isset( $labels[ $to ] ) ? $labels[ $to ] : $to ),
				'enabled' => 'enabled' === self::v( $n, 'status', 'enabled' ),
				'subject' => $subject,
				'body'    => $body,
				'unknown' => array_values( $unknown ),
				'custom'  => isset( $custom[ $name ] ) ? $custom[ $name ] : array(),
			);
		}
		return $out;
	}
}
