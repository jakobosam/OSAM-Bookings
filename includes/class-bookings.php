<?php
/**
 * Booking lifecycle: create, status changes, payments.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Central place that writes bookings so hooks and emails always fire.
 */
class OSB_Bookings {

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	public static function statuses() {
		return apply_filters(
			'osb_booking_statuses',
			array(
				'pending_payment' => __( 'Awaiting payment', 'osam-bookings' ),
				'pending'         => __( 'Pending approval', 'osam-bookings' ),
				'confirmed'       => __( 'Confirmed', 'osam-bookings' ),
				'completed'       => __( 'Completed', 'osam-bookings' ),
				'cancelled'       => __( 'Cancelled', 'osam-bookings' ),
				'no_show'         => __( 'No-show', 'osam-bookings' ),
				'payment_failed'  => __( 'Payment not completed', 'osam-bookings' ),
			)
		);
	}

	/**
	 * Payment status labels.
	 *
	 * @return array
	 */
	public static function payment_statuses() {
		return array(
			'unpaid'   => __( 'Unpaid', 'osam-bookings' ),
			'paid'     => __( 'Paid', 'osam-bookings' ),
			'refunded' => __( 'Refunded', 'osam-bookings' ),
			'free'     => __( 'Free', 'osam-bookings' ),
			'card_held' => __( 'Card on file', 'osam-bookings' ),
		);
	}

	/**
	 * Sanitise and validate submitted form fields.
	 *
	 * @param array      $input Raw values keyed by field key.
	 * @param array|null $ctx   [ type, item_id ] — only the questions for that booking (all when null).
	 * @return array|WP_Error Clean values.
	 */
	public static function validate_fields( array $input, $ctx = null, $list = null ) {
		$clean  = array();
		$errors = new WP_Error();
		$fields = null !== $list ? $list : ( $ctx ? OSB_Settings::form_fields_for( $ctx[0], $ctx[1] ) : OSB_Settings::form_fields() );

		foreach ( $fields as $field ) {
			$key   = $field['key'];
			$raw   = isset( $input[ $key ] ) ? $input[ $key ] : '';
			$value = '';

			switch ( $field['type'] ) {
				case 'email':
					$value = sanitize_email( $raw );
					if ( '' !== $raw && ! is_email( $value ) ) {
						/* translators: %s field label */
						$errors->add( $key, sprintf( __( '%s must be a valid email address.', 'osam-bookings' ), $field['label'] ) );
					}
					break;
				case 'textarea':
					$value = sanitize_textarea_field( $raw );
					break;
				case 'checkbox':
					$value = ! empty( $raw ) ? 1 : 0;
					break;
				case 'select':
				case 'radio':
					$options = array_filter( array_map( 'trim', explode( "\n", (string) $field['options'] ) ), 'strlen' );
					$value   = sanitize_text_field( $raw );
					if ( '' !== $value && ! in_array( $value, $options, true ) ) {
						$value = '';
					}
					break;
				case 'number':
					$value = '' === $raw ? '' : (float) $raw;
					break;
				case 'date':
					$value = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $raw ) ? $raw : '';
					break;
				default:
					$value = sanitize_text_field( $raw );
			}

			if ( ! empty( $field['required'] ) && ( '' === $value || 0 === $value ) ) {
				/* translators: %s field label */
				$errors->add( $key, sprintf( __( '%s is required.', 'osam-bookings' ), $field['label'] ) );
			}
			$clean[ $key ] = $value;
		}

		if ( null === $list ) {
			$errors = apply_filters( 'osb_validate_fields', $errors, $clean, $input );
		}
		return $errors->has_errors() ? $errors : $clean;
	}

	/**
	 * Check the details given for each extra place.
	 *
	 * @param array $guests Raw: one array of values per extra place (place 2 onwards).
	 * @param int   $seats  Places booked.
	 * @param array $ctx    [ type, item_id ].
	 * @return array|WP_Error Clean list (empty when the same details are used for everyone).
	 */
	public static function validate_guests( array $guests, $seats, array $ctx ) {
		if ( $seats < 2 || ! $guests ) {
			return array();
		}
		$list   = OSB_Settings::guest_fields( $ctx[0], $ctx[1] );
		$out    = array();
		$errors = new WP_Error();
		for ( $i = 0; $i < $seats - 1; $i++ ) {
			$raw   = isset( $guests[ $i ] ) && is_array( $guests[ $i ] ) ? $guests[ $i ] : array();
			$clean = self::validate_fields( $raw, null, $list );
			if ( is_wp_error( $clean ) ) {
				foreach ( $clean->get_error_messages() as $m ) {
					/* translators: 1: place number 2: message */
					$errors->add( 'osb_guest', sprintf( __( 'Place %1$d: %2$s', 'osam-bookings' ), $i + 2, $m ) );
				}
				continue;
			}
			$out[] = $clean;
		}
		$errors = apply_filters( 'osb_validate_guests', $errors, $out, $guests );
		return $errors->has_errors() ? $errors : $out;
	}

	/**
	 * Details given for the extra places on a booking.
	 *
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function guests( $booking ) {
		$g = isset( $booking->guests ) ? json_decode( (string) $booking->guests, true ) : null;
		return is_array( $g ) ? $g : array();
	}

	/**
	 * Everyone on a booking: the booker first, then each extra place.
	 *
	 * @param object $booking Booking.
	 * @return array [ [ place, name, email, fields, same ] ]
	 */
	public static function attendees( $booking ) {
		$fields = self::fields( $booking );
		$out    = array(
			array(
				'place'  => 1,
				'name'   => $booking->customer_name,
				'email'  => $booking->customer_email,
				'fields' => $fields,
				'same'   => false,
			),
		);
		$guests = self::guests( $booking );
		for ( $i = 2; $i <= max( 1, (int) $booking->seats ); $i++ ) {
			$g     = isset( $guests[ $i - 2 ] ) ? $guests[ $i - 2 ] : null;
			$out[] = array(
				'place'  => $i,
				'name'   => $g && '' !== (string) $g['name'] ? $g['name'] : '',
				'email'  => $g && ! empty( $g['email'] ) ? $g['email'] : '',
				'fields' => $g ? $g : array(),
				'same'   => ! $g,
			);
		}
		return $out;
	}

	/**
	 * Allowed payment methods for an item.
	 *
	 * @param object $item Service or event row.
	 * @return string[] gateway ids
	 */
	public static function allowed_methods( $item ) {
		$enabled = array_keys( osb()->payments->enabled() );
		$limit   = array_filter( array_map( 'trim', explode( ',', (string) $item->payment_methods ) ) );
		return $limit ? array_values( array_intersect( $enabled, $limit ) ) : $enabled;
	}

	/**
	 * Acquire a short DB lock to stop double-booking a slot.
	 *
	 * @return bool
	 */
	private static function lock() {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$got = $wpdb->get_var( "SELECT GET_LOCK('osb_booking_write', 10)" );
		$wpdb->suppress_errors( false );
		return null === $got || '1' === (string) $got;
	}

	/**
	 * Release the lock.
	 */
	private static function unlock() {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$wpdb->query( "SELECT RELEASE_LOCK('osb_booking_write')" );
		$wpdb->suppress_errors( false );
	}

	/**
	 * Create a booking.
	 *
	 * @param array $data {
	 *     @type string $type           service|event|course
	 *     @type int    $service_id
	 *     @type int    $staff_id       0 = any
	 *     @type int    $event_id
	 *     @type int    $intake_id      Course intake (courses)
	 *     @type string $date           Y-m-d (services)
	 *     @type string $time           H:i (services)
	 *     @type int    $seats
	 *     @type array  $fields         Raw form values
	 *     @type string $payment_method Gateway id
	 *     @type bool   $admin          Admin-created: skip availability and gateway checks
	 *     @type string $status         Admin override status
	 * }
	 * @return object|WP_Error Booking row.
	 */
	public static function create( array $data ) {
		$data = wp_parse_args(
			$data,
			array(
				'type'           => 'service',
				'service_id'     => 0,
				'staff_id'       => 0,
				'event_id'       => 0,
				'intake_id'      => 0,
				'date'           => '',
				'time'           => '',
				'seats'          => 1,
				'fields'         => array(),
				'guests'         => array(),
				'payment_method' => '',
				'admin'          => false,
				'status'         => '',
				'notify'         => true,
				'coupon'         => '',
				'waitlist'       => '',
				'area_id'        => 0,
				'table_ids'      => null,
				'duration'       => 0,
				'lenient'        => false,
				'resource_ids'   => array(),
				'hire'           => 'time',
				'minutes'        => 0,
				'half'           => '',
				'days'           => 1,
				'exclusive'      => false,
				'end_time'       => '',
				'addons'         => array(),
				'pass'           => '',
				'bundle_id'      => 0,
				'repeat'         => null,
				'series_id'      => 0,
				'repeat_rule'    => '',
				'dry_run'        => false,
			)
		);

		if ( OSB_Licence::bookings_off() ) {
			return OSB_Licence::booking_error();
		}

		// Which questions apply depends on what is being booked.
		$type    = in_array( $data['type'], array( 'event', 'course', 'table', 'resource', 'bundle' ), true ) ? $data['type'] : 'service';
		$item_id = 'event' === $type ? (int) $data['event_id'] : ( 'service' === $type ? (int) $data['service_id'] : 0 );
		if ( 'course' === $type ) {
			$intake  = OSB_DB::get( 'course_intakes', (int) $data['intake_id'] );
			$item_id = $intake ? (int) $intake->course_id : 0;
		}
		if ( 'resource' === $type ) {
			$item_id = (int) current( array_filter( array_map( 'absint', (array) $data['resource_ids'] ) ) );
		}
		$list = null;
		if ( $data['admin'] && $data['lenient'] ) {
			// Staff taking a booking by phone may not have an email address (or every answer).
			$list = array();
			foreach ( OSB_Settings::form_fields_for( $type, $item_id ) as $f ) {
				$f['required'] = 'name' === $f['key'] ? 1 : 0;
				$list[]        = $f;
			}
		}
		if ( $data['dry_run'] ) {
			// Checking whether a date can be booked (repeat bookings): the answers don't matter yet.
			$fields = array();
			$guests = array();
		} else {
			$fields = self::validate_fields( (array) $data['fields'], array( $type, $item_id ), $list );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			$guests = self::validate_guests( (array) $data['guests'], max( 1, (int) $data['seats'] ), array( $type, $item_id ) );
			if ( is_wp_error( $guests ) ) {
				return $guests;
			}
		}

		if ( ! $data['admin'] && in_array( $data['type'], array( 'event', 'course', 'table', 'resource', 'bundle' ), true ) && ! OSB_Settings::feature( array( 'event' => 'events', 'course' => 'courses', 'table' => 'restaurant', 'resource' => 'resources', 'bundle' => 'bundles' )[ $data['type'] ] ) ) {
			return new WP_Error( 'osb_disabled', __( 'Online booking for this is not available.', 'osam-bookings' ) );
		}

		$seats = max( 1, (int) $data['seats'] );
		$row   = array(
			'type'           => $type,
			'seats'          => $seats,
			'customer_name'  => isset( $fields['name'] ) ? $fields['name'] : '',
			'customer_email' => isset( $fields['email'] ) ? $fields['email'] : '',
			'customer_phone' => isset( $fields['phone'] ) ? $fields['phone'] : '',
			'fields'         => wp_json_encode( $fields ),
			'guests'         => $guests ? wp_json_encode( $guests ) : null,
			'currency'       => OSB_Settings::get( 'currency' ),
			'token'          => wp_generate_password( 32, false, false ),
			'series_id'      => (int) $data['series_id'],
			'repeat_rule'    => substr( sanitize_text_field( (string) $data['repeat_rule'] ), 0, 40 ),
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		);

		if ( ! self::lock() ) {
			return new WP_Error( 'osb_busy', __( 'The booking system is busy, please try again.', 'osam-bookings' ) );
		}

		if ( '' !== (string) $data['waitlist'] && 'service' !== $row['type'] && ! $data['dry_run'] ) {
			// Booking from a waitlist offer: the place held for them is theirs to take.
			OSB_Waitlist::use_token( $data['waitlist'], $row['type'], 'course' === $row['type'] ? (int) $data['intake_id'] : (int) $data['event_id'] );
		}

		$table_meta = null;
		$res_lines  = null;
		try {
			if ( 'bundle' === $row['type'] ) {
				$bun = OSB_Bundles::booking_row( $data );
				if ( is_wp_error( $bun ) ) {
					return $bun;
				}
				$row  = array_merge( $row, $bun[0] );
				$item = $bun[1];
			} elseif ( 'resource' === $row['type'] ) {
				$res = OSB_Resources::booking_row( $data, $seats );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$row       = array_merge( $row, $res[0] );
				$item      = $res[1];
				$res_lines = $res[2];
			} elseif ( 'table' === $row['type'] ) {
				$table = OSB_Restaurant::booking_row( $data, $seats );
				if ( is_wp_error( $table ) ) {
					return $table;
				}
				$row        = array_merge( $row, $table[0] );
				$item       = $table[1];
				$table_meta = $table[2];
			} elseif ( 'service' === $row['type'] ) {
				$service = OSB_DB::get( 'services', (int) $data['service_id'] );
				if ( ! $service || ( 'active' !== $service->status && ! $data['admin'] ) ) {
					return new WP_Error( 'osb_service', __( 'Please choose a valid service.', 'osam-bookings' ) );
				}
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['date'] ) || ! preg_match( '/^\d{2}:\d{2}$/', $data['time'] ) ) {
					return new WP_Error( 'osb_datetime', __( 'Please choose a date and time.', 'osam-bookings' ) );
				}
				if ( $seats > max( 1, (int) $service->capacity ) ) {
					return new WP_Error( 'osb_seats', __( 'Too many places requested.', 'osam-bookings' ) );
				}
				$start = $data['date'] . ' ' . $data['time'];

				if ( $data['admin'] ) {
					$staff_id = (int) $data['staff_id'];
				} else {
					$staff_id = OSB_Availability::resolve_staff( $service->id, (int) $data['staff_id'], $start, $seats );
					if ( ! $staff_id ) {
						return new WP_Error( 'osb_unavailable', __( 'Sorry, that time has just been taken. Please choose another.', 'osam-bookings' ) );
					}
				}

				$start_ts            = strtotime( $start . ':00' );
				$row['service_id']   = (int) $service->id;
				$row['staff_id']     = $staff_id;
				$row['start_at']     = gmdate( 'Y-m-d H:i:s', $start_ts );
				$row['end_at']       = gmdate( 'Y-m-d H:i:s', $start_ts + (int) $service->duration * 60 );
				$row['amount']       = round( OSB_DB::service_price( $service, $staff_id ) * $seats, 2 );
				$item                = $service;
			} elseif ( 'course' === $row['type'] ) {
				$course = OSB_Courses::booking_row( $data, $seats );
				if ( is_wp_error( $course ) ) {
					return $course;
				}
				$row  = array_merge( $row, $course[0] );
				$item = $course[1];
			} else {
				$event = OSB_DB::get( 'events', (int) $data['event_id'] );
				if ( ! $event || ( ! $data['admin'] && ( 'active' !== $event->status || $event->start_at <= current_time( 'mysql' ) ) ) ) {
					return new WP_Error( 'osb_event', __( 'This event is no longer available.', 'osam-bookings' ) );
				}
				if ( ! $data['admin'] ) {
					if ( $seats > max( 1, (int) $event->max_per_booking ) ) {
						return new WP_Error( 'osb_seats', __( 'Too many places requested.', 'osam-bookings' ) );
					}
					$left = (int) $event->capacity - OSB_DB::event_seats_taken( $event->id, true );
					if ( $seats > $left ) {
						return new WP_Error( 'osb_full', $left > 0 ? sprintf( /* translators: %d seats */ __( 'Only %d places are left.', 'osam-bookings' ), $left ) : __( 'Sorry, this event is fully booked.', 'osam-bookings' ) );
					}
				}
				$row['event_id'] = (int) $event->id;
				$row['staff_id'] = (int) $event->staff_id;
				$row['start_at'] = $event->start_at;
				$row['end_at']   = $event->end_at;
				$row['amount']   = round( (float) $event->price * $seats, 2 );
				$item            = $event;
			}

			$row['amount'] = (float) apply_filters( 'osb_booking_amount', $row['amount'], $row, $data );

			// What's being booked, for bundles and add-ons.
			$tok_id = 'course' === $row['type'] ? (int) ( $row['course_id'] ?? 0 ) : ( 'event' === $row['type'] ? (int) ( $row['event_id'] ?? 0 ) : (int) ( $row['service_id'] ?? 0 ) );
			$tokens = OSB_Items::tokens( $row['type'], $tok_id, $res_lines ? array_map( 'intval', wp_list_pluck( $res_lines, 0 ) ) : array() );

			// Paid with a bundle: the booking itself is covered; extras are still charged.
			$pass       = null;
			$pass_spend = 0;
			if ( 'bundle' !== $row['type'] && '' !== trim( (string) $data['pass'] ) && ( $data['admin'] || OSB_Bundles::enabled() ) ) {
				$pass       = OSB_Bundles::find( $data['pass'] );
				$pass_spend = $pass ? OSB_Bundles::needed( $pass, $seats ) : 1;
				$ok         = OSB_Bundles::usable( $pass, $tokens, $pass_spend );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
				$row['pass_id'] = (int) $pass->id;
				$row['amount']  = 0;
			}

			// Add-ons.
			$addon_lines = array();
			if ( 'bundle' !== $row['type'] && ( $data['addons'] || OSB_Addons::for_tokens( $tokens ) ) ) {
				$start_ts = strtotime( $row['start_at'] );
				$end_ts   = strtotime( $row['end_at'] );
				$days     = 'resource' === $row['type'] && 'day' === $data['hire'] ? max( 1, (int) $data['days'] ) : 1;
				$built    = OSB_Addons::build( $data['addons'], $tokens, array( 'seats' => $seats, 'minutes' => max( 1, (int) round( ( $end_ts - $start_ts ) / 60 ) ), 'days' => $days ), (bool) $data['admin'] );
				if ( is_wp_error( $built ) ) {
					return $built;
				}
				list( $addon_lines, $addon_total ) = $built;
				$row['amount'] = round( (float) $row['amount'] + $addon_total, 2 );
			}

			// Discount code or gift voucher.
			if ( '' !== trim( (string) $data['coupon'] ) && $row['amount'] > 0 && ( $data['admin'] || OSB_Settings::feature( 'coupons' ) ) ) {
				$with = OSB_Coupons::apply_to_row( $row, $data['coupon'] );
				if ( is_wp_error( $with ) ) {
					return $with;
				}
				$row = $with;
			}

			// Payment method & starting status.
			if ( $row['amount'] <= 0 ) {
				$row['payment_method'] = $pass ? 'bundle' : 'free';
				$row['payment_status'] = $pass ? 'paid' : 'free';
				$row['status']         = OSB_Settings::get( 'free_status' );
			} else {
				$method  = sanitize_key( $data['payment_method'] );
				$allowed = self::allowed_methods( $item );
				if ( ! $data['admin'] && ! $data['dry_run'] && ! in_array( $method, $allowed, true ) ) {
					return new WP_Error( 'osb_payment', __( 'Please choose a payment method.', 'osam-bookings' ) );
				}
				$gateway               = osb()->payments->get( $method );
				$row['payment_method'] = $method ? $method : 'onsite';
				$row['payment_status'] = 'unpaid';
				$row['status']         = $gateway ? $gateway->initial_status() : 'pending';
			}

			if ( $table_meta ) {
				$row = OSB_Restaurant::finalise_row( $table_meta, $row, (bool) $data['admin'] );
			}

			if ( $data['admin'] && $data['status'] && isset( self::statuses()[ $data['status'] ] ) ) {
				$row['status'] = $data['status'];
			}

			$row = apply_filters( 'osb_booking_insert_data', $row, $data );
			if ( $data['dry_run'] ) {
				return (object) $row;
			}
			$id  = OSB_DB::insert( 'bookings', $row );
			if ( $id && $res_lines ) {
				OSB_Resources::attach( $id, $res_lines );
			}
			if ( $id && $addon_lines ) {
				OSB_Addons::attach( $id, $addon_lines );
			}
			if ( $id && $pass ) {
				OSB_Bundles::spend( $pass, $id, $pass_spend );
			}
			if ( $id && $table_meta ) {
				OSB_Restaurant::attach( $id, $table_meta );
				if ( $table_meta['hold'] && $table_meta['fee'] > 0 ) {
					// The no-show fee agreed now is what can be charged later.
					OSB_DB::add_payment( $id, 'stripe', 'hold-' . $id, $table_meta['fee'], $row['currency'], 'hold_pending' );
				}
			}
		} finally {
			OSB_Waitlist::$using = 0;
			self::unlock();
		}

		if ( ! $id ) {
			return new WP_Error( 'osb_db', __( 'Could not save the booking. Please try again.', 'osam-bookings' ) );
		}

		OSB_Availability::bust_cache();
		$booking = OSB_DB::get( 'bookings', $id );

		/**
		 * Fires after a booking row is created.
		 *
		 * @param object $booking Booking.
		 * @param array  $data    Submitted data.
		 */
		do_action( 'osb_booking_created', $booking, $data );

		// Repeat booking: make the other dates before anyone is emailed, so the email lists them all.
		$repeat = OSB_Repeat::parse( $data['repeat'], (bool) $data['admin'] );
		if ( $repeat && ! (int) $data['series_id'] && OSB_Repeat::allowed( $booking->type, (bool) $data['admin'] ) ) {
			list( $made, $skipped ) = OSB_Repeat::book_rest( $booking, $data, $repeat[0], $repeat[1] );
			$booking                = OSB_DB::get( 'bookings', $id );
			$booking->repeat_made    = $made;
			$booking->repeat_skipped = $skipped;
		}

		if ( $data['notify'] && 'pending_payment' !== $booking->status ) {
			do_action( 'osb_booking_notify', $booking, 'new' );
		}

		return $booking;
	}

	/**
	 * Change status and fire notifications.
	 *
	 * @param int    $booking_id Booking.
	 * @param string $status     New status.
	 * @param bool   $notify     Send emails.
	 * @return bool
	 */
	public static function set_status( $booking_id, $status, $notify = true ) {
		$booking = OSB_DB::get( 'bookings', $booking_id );
		if ( ! $booking || ! isset( self::statuses()[ $status ] ) || $booking->status === $status ) {
			return false;
		}
		$old = $booking->status;
		OSB_DB::update(
			'bookings',
			$booking_id,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			)
		);
		OSB_Availability::bust_cache();
		$booking = OSB_DB::get( 'bookings', $booking_id );

		do_action( 'osb_booking_status_changed', $booking, $old, $status );

		if ( $notify ) {
			if ( 'cancelled' === $status && 'pending_payment' !== $old ) {
				do_action( 'osb_booking_notify', $booking, 'cancelled' );
			} elseif ( 'pending_payment' === $old && in_array( $status, array( 'confirmed', 'pending' ), true ) ) {
				// Paid online: this is the first notification anyone gets.
				do_action( 'osb_booking_notify', $booking, 'new' );
			} elseif ( 'payment_failed' === $status && 'pending_payment' === $old ) {
				do_action( 'osb_booking_notify', $booking, 'payment_failed' );
			} elseif ( 'pending' === $old && 'confirmed' === $status ) {
				do_action( 'osb_booking_notify', $booking, 'approved' );
			}
		}
		return true;
	}

	/**
	 * An online payment didn't go through (declined, abandoned or timed out): release the time, keep the
	 * booking off every calendar and tell the customer. Only acts on bookings still awaiting payment.
	 *
	 * @param int  $booking_id Booking.
	 * @param bool $notify     Email the customer (and admin, if that email is on).
	 * @return bool
	 */
	public static function payment_failed( $booking_id, $notify = true ) {
		$booking = OSB_DB::get( 'bookings', $booking_id );
		if ( ! $booking || 'pending_payment' !== $booking->status ) {
			return false;
		}
		if ( OSB_Repeat::is_follower( $booking ) ) {
			$notify = false; // The first date of the series tells them.
		}
		return self::set_status( $booking_id, 'payment_failed', $notify );
	}

	/**
	 * Statuses that never appear on calendars, timelines or schedules (unpaid or failed online payments).
	 *
	 * @return string[]
	 */
	public static function hidden_statuses() {
		return (array) apply_filters( 'osb_calendar_hidden_statuses', array( 'pending_payment', 'payment_failed' ) );
	}

	/**
	 * Record a successful payment (idempotent per transaction).
	 *
	 * @param object $booking Booking.
	 * @param string $gateway Gateway id.
	 * @param string $txn     Transaction id.
	 * @param float  $amount  Amount paid.
	 * @param mixed  $raw     Raw gateway data.
	 * @return bool True if newly recorded.
	 */
	public static function mark_paid( $booking, $gateway, $txn, $amount, $raw = null ) {
		if ( $txn && OSB_DB::payment_exists( $gateway, $txn ) ) {
			return false;
		}
		$payment_id = OSB_DB::add_payment( $booking->id, $gateway, $txn, $amount, $booking->currency, 'completed', $raw );
		OSB_DB::update(
			'bookings',
			$booking->id,
			array(
				'payment_status' => 'paid',
				'updated_at'     => current_time( 'mysql' ),
			)
		);
		$invoice = null;
		if ( $payment_id && class_exists( 'OSB_Invoices' ) ) {
			// Made now so the confirmation email that follows can carry it.
			$invoice = OSB_Invoices::on_payment( OSB_DB::get( 'bookings', $booking->id ), $payment_id );
		}

		if ( in_array( $booking->status, array( 'pending_payment', 'cancelled', 'payment_failed' ), true ) ) {
			// A late payment for an expired hold is still accepted; staff can review it.
			$target = apply_filters( 'osb_paid_status', 'confirmed', $booking );
			self::set_status( $booking->id, $target );
		}
		if ( $invoice && 'pending_payment' !== $booking->status ) {
			// Paid online after the booking emails went out (a "Pay now" link on a confirmed booking):
			// no confirmation follows to carry the invoice, so it goes on its own.
			OSB_Invoices::email_receipt( $invoice );
		}

		do_action( 'osb_booking_paid', OSB_DB::get( 'bookings', $booking->id ), $gateway, $txn );
		return true;
	}

	/**
	 * Decode custom fields.
	 *
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function fields( $booking ) {
		$fields = json_decode( (string) $booking->fields, true );
		return is_array( $fields ) ? $fields : array();
	}

	/**
	 * Human-readable details for emails, admin and the front end.
	 *
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function details( $booking ) {
		$service = $booking->service_id ? OSB_DB::get( 'services', $booking->service_id ) : null;
		$event   = $booking->event_id ? OSB_DB::get( 'events', $booking->event_id ) : null;
		$course  = ( 'course' === $booking->type && $booking->course_id ) ? OSB_DB::get( 'courses', $booking->course_id ) : null;
		$intake  = $course ? OSB_DB::get( 'course_intakes', $booking->intake_id ) : null;
		// In solo mode there is only the owner, so "with …" lines and staff emails are dropped.
		$staff   = ( $booking->staff_id && ! OSB_DB::is_solo() ) ? OSB_DB::get( 'staff', $booking->staff_id ) : null;
		$gateway = osb()->payments->get( $booking->payment_method );

		$start = strtotime( $booking->start_at );
		$end   = strtotime( $booking->end_at );

		$details = array(
			'id'             => (int) $booking->id,
			'type'           => $booking->type,
			'item_name'      => $course ? $course->name : ( $event ? $event->name : ( $service ? $service->name : __( '(deleted)', 'osam-bookings' ) ) ),
			'staff_name'     => $staff ? $staff->name : '',
			'staff_email'    => $staff ? $staff->email : '',
			'location'       => $event ? $event->location : ( $course ? $course->location : '' ),
			'date'           => date_i18n( get_option( 'date_format' ), $start ),
			'time'           => date_i18n( get_option( 'time_format' ), $start ),
			'end_time'       => date_i18n( get_option( 'time_format' ), $end ),
			'duration'       => (int) round( ( $end - $start ) / 60 ),
			'seats'          => (int) $booking->seats,
			'amount'         => OSB_Settings::money( $booking->amount, $booking->currency ),
			'payment_method' => 'free' === $booking->payment_method ? OSB_Settings::label( 'free' ) : ( 'bundle' === $booking->payment_method ? __( 'Bundle', 'osam-bookings' ) : ( $gateway ? $gateway->title() : $booking->payment_method ) ),
			'payment_status' => isset( self::payment_statuses()[ $booking->payment_status ] ) ? self::payment_statuses()[ $booking->payment_status ] : $booking->payment_status,
			'status'         => isset( self::statuses()[ $booking->status ] ) ? self::statuses()[ $booking->status ] : $booking->status,
			'status_key'     => $booking->status,
			'customer_name'  => $booking->customer_name,
			'customer_email' => $booking->customer_email,
			'customer_phone' => $booking->customer_phone,
			'manage_url'     => self::manage_url( $booking ),
			'can_cancel'     => self::can_customer_cancel( $booking ),
			'can_reschedule' => self::can_customer_reschedule( $booking ),
			'discount'       => isset( $booking->discount ) && (float) $booking->discount > 0 ? OSB_Settings::money( $booking->discount, $booking->currency ) : '',
			'coupon_code'    => isset( $booking->coupon_code ) ? (string) $booking->coupon_code : '',
			'attendees'      => (int) $booking->seats > 1 ? array_map(
				static function ( $a ) {
					return array(
						'place' => $a['place'],
						'name'  => $a['name'],
						'same'  => $a['same'],
					);
				},
				self::attendees( $booking )
			) : array(),
			'service_id'     => (int) $booking->service_id,
			'staff_id'       => (int) $booking->staff_id,
			'meeting_url'    => isset( $booking->meeting_url ) ? (string) $booking->meeting_url : '',
			'intake_name'    => $intake ? OSB_Courses::intake_label( $intake ) : '',
			'schedule'       => $intake ? OSB_Courses::schedule( $intake->id, $course ) : array(),
		);
		$details['session_count'] = count( $details['schedule'] );
		// "Pay now": only when money is owed and it can be paid online (see OSB_Payments::payable()).
		// Customer-facing screens only (booking page, My bookings — both REST); wp-admin lists skip the lookup.
		$due                   = is_admin() && ! wp_doing_ajax() ? 0 : OSB_Payments::payable( $booking );
		$details['amount_due'] = $due > 0 ? OSB_Settings::money( $due, $booking->currency ) : '';
		$details['pay_url']    = $due > 0 ? OSB_Payments::pay_url( $booking ) : '';
		$details['pay_label']  = $due > 0 ? OSB_Payments::pay_label( $booking ) : '';
		return apply_filters( 'osb_booking_details', $details, $booking );
	}

	/**
	 * Public manage URL (view/cancel).
	 *
	 * @param object $booking Booking.
	 * @return string
	 */
	public static function manage_url( $booking ) {
		$page = (int) OSB_Settings::get( 'booking_page' );
		$base = $page ? get_permalink( $page ) : home_url( '/' );
		return add_query_arg( 'osb_booking', $booking->token, $base );
	}

	/**
	 * Can the customer move this appointment themselves?
	 *
	 * @param object $booking Booking.
	 * @return bool
	 */
	public static function can_customer_reschedule( $booking ) {
		if ( ! OSB_Settings::feature( 'reschedule' ) || 'service' !== $booking->type || ! in_array( $booking->status, array( 'pending', 'confirmed' ), true ) ) {
			return false;
		}
		$cutoff = strtotime( current_time( 'mysql' ) ) + (float) OSB_Settings::get( 'reschedule_cutoff_hours' ) * HOUR_IN_SECONDS;
		return (bool) apply_filters( 'osb_customer_can_reschedule', strtotime( $booking->start_at ) > $cutoff, $booking );
	}

	/**
	 * Move an appointment (customer self-service). Same service, same staff member.
	 *
	 * @param object $booking Booking.
	 * @param string $date    Y-m-d.
	 * @param string $time    H:i.
	 * @return object|WP_Error Updated booking.
	 */
	public static function reschedule( $booking, $date, $time ) {
		if ( ! self::can_customer_reschedule( $booking ) ) {
			return new WP_Error( 'osb_cannot_reschedule', __( 'This booking can no longer be changed online. Please contact us.', 'osam-bookings' ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return new WP_Error( 'osb_datetime', __( 'Please choose a date and time.', 'osam-bookings' ) );
		}
		if ( ! self::lock() ) {
			return new WP_Error( 'osb_busy', __( 'The booking system is busy, please try again.', 'osam-bookings' ) );
		}
		try {
			OSB_Availability::$exclude_booking = (int) $booking->id;
			OSB_Availability::flush();
			$ok = false;
			foreach ( OSB_Availability::day_slots( (int) $booking->service_id, (int) $booking->staff_id, $date, max( 1, (int) $booking->seats ) ) as $slot ) {
				if ( $slot['time'] === $time ) {
					$ok = true;
				}
			}
			OSB_Availability::$exclude_booking = 0;
			if ( ! $ok ) {
				return new WP_Error( 'osb_unavailable', __( 'Sorry, that time has just been taken. Please choose another.', 'osam-bookings' ) );
			}
			$length   = strtotime( $booking->end_at ) - strtotime( $booking->start_at );
			$start    = strtotime( $date . ' ' . $time . ':00' );
			$previous = $booking->start_at;
			OSB_DB::update(
				'bookings',
				$booking->id,
				array(
					'start_at'      => gmdate( 'Y-m-d H:i:s', $start ),
					'end_at'        => gmdate( 'Y-m-d H:i:s', $start + $length ),
					'reminder_sent' => 0,
					'updated_at'    => current_time( 'mysql' ),
				)
			);
		} finally {
			OSB_Availability::$exclude_booking = 0;
			self::unlock();
		}
		OSB_Availability::bust_cache();
		$fresh = OSB_DB::get( 'bookings', $booking->id );
		self::notify_moved( $fresh, $previous, true );
		do_action( 'osb_booking_rescheduled', $fresh, $previous, 'customer' );
		return $fresh;
	}

	/**
	 * Tell people a booking has moved.
	 *
	 * @param object $booking     Booking (new time).
	 * @param string $previous    Old start.
	 * @param bool   $by_customer Customer moved it (admin gets told).
	 */
	public static function notify_moved( $booking, $previous, $by_customer ) {
		$extra = array( '{previous_when}' => esc_html( date_i18n( 'l ' . get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), strtotime( $previous ) ) ) );
		OSB_Emails::send( 'customer_rescheduled', $booking, $booking->customer_email, false, $extra );
		if ( $by_customer ) {
			OSB_Emails::send( 'admin_rescheduled', $booking, OSB_Emails::admin_recipients(), false, $extra );
			$d = self::details( $booking );
			if ( $d['staff_email'] ) {
				OSB_Emails::send( 'admin_rescheduled', $booking, $d['staff_email'], false, $extra );
			}
		}
	}

	/**
	 * Can the customer cancel this booking themselves?
	 *
	 * @param object $booking Booking.
	 * @return bool
	 */
	public static function can_customer_cancel( $booking ) {
		if ( ! OSB_Settings::get( 'allow_customer_cancel' ) ) {
			return false;
		}
		if ( ! in_array( $booking->status, array( 'pending', 'confirmed', 'pending_payment' ), true ) ) {
			return false;
		}
		$cutoff = strtotime( current_time( 'mysql' ) ) + (float) OSB_Settings::get( 'cancel_cutoff_hours' ) * HOUR_IN_SECONDS;
		return (bool) apply_filters( 'osb_customer_can_cancel', strtotime( $booking->start_at ) > $cutoff, $booking );
	}
}
