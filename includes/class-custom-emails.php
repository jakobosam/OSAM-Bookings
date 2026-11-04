<?php
/**
 * Custom emails: extra emails the site owner writes, sent on a chosen trigger to chosen people,
 * for chosen services, categories, events, courses, staff and conditions.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Custom email definitions and sending.
 */
class OSB_Custom_Emails {

	const OPTION = 'osb_custom_emails';

	/**
	 * Most bookings one scheduled email handles per run (the rest go next run).
	 */
	const BATCH = 100;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'osb_booking_notify', array( __CLASS__, 'on_notify' ), 20, 2 );
		add_action( 'osb_booking_status_changed', array( __CLASS__, 'on_status' ), 20, 3 );
		add_action( 'osb_booking_rescheduled', array( __CLASS__, 'on_rescheduled' ), 20, 3 );
		add_action( 'osb_booking_paid', array( __CLASS__, 'on_paid' ), 20, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Definitions
	 * ------------------------------------------------------------------ */

	/**
	 * When an email can go out.
	 *
	 * @return array group => [ key => label ]
	 */
	public static function triggers() {
		$replace = array();
		foreach ( OSB_Settings::email_labels() as $key => $label ) {
			$replace[ 'replace:' . $key ] = sprintf( /* translators: %s standard email name */ __( 'Instead of: %s', 'osam-bookings' ), $label );
		}
		$out = array(
			__( 'When something happens', 'osam-bookings' ) => array(
				'booked'      => __( 'A booking is made', 'osam-bookings' ),
				'confirmed'   => __( 'A booking is confirmed', 'osam-bookings' ),
				'paid'        => __( 'An online payment is received', 'osam-bookings' ),
				'payment_failed' => __( 'An online payment doesn’t go through', 'osam-bookings' ),
				'rescheduled' => __( 'A booking is moved to a new time', 'osam-bookings' ),
				'cancelled'   => __( 'A booking is cancelled', 'osam-bookings' ),
				'completed'   => __( 'A booking is marked completed', 'osam-bookings' ),
				'no_show'     => __( 'A booking is marked no-show', 'osam-bookings' ),
			),
			__( 'At a set time', 'osam-bookings' )         => array(
				'before_start' => __( 'Before the booking starts', 'osam-bookings' ),
				'after_end'    => __( 'After the booking ends', 'osam-bookings' ),
				'after_booked' => __( 'After the booking was made', 'osam-bookings' ),
			),
			__( 'Replace a standard email', 'osam-bookings' ) => $replace,
			__( 'By hand', 'osam-bookings' )               => array(
				'manual' => __( 'Only when I send it from a booking', 'osam-bookings' ),
			),
		);
		return apply_filters( 'osb_custom_email_triggers', $out );
	}

	/**
	 * Flat trigger labels.
	 *
	 * @return array
	 */
	public static function trigger_labels() {
		$out = array();
		foreach ( self::triggers() as $group ) {
			$out += $group;
		}
		return $out;
	}

	/**
	 * Triggers that run on a schedule.
	 *
	 * @param string $trigger Trigger.
	 * @return bool
	 */
	public static function is_timed( $trigger ) {
		return in_array( $trigger, array( 'before_start', 'after_end', 'after_booked' ), true );
	}

	/**
	 * Blank definition with defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'id'           => '',
			'name'         => '',
			'enabled'      => 1,
			'since'        => '',
			'trigger'      => 'booked',
			'offset'       => 1,
			'unit'         => 'days',
			'late'         => 0,
			'to_customer'  => 1,
			'to_attendees' => 0,
			'to_staff'     => 0,
			'to_admin'     => 0,
			'to_extra'     => '',
			'bcc'          => '',
			'reply_to'     => 'default',
			'from_name'    => '',
			'items'        => array(),
			'staff'        => array(),
			'statuses'     => array( 'confirmed', 'completed' ),
			'methods'      => array(),
			'pay'          => 'any',
			'customer'     => 'any',
			'min_seats'    => 0,
			'coupon'       => 'any',
			'subject'      => '',
			'body'         => '',
			'ics'          => 0,
			'note'         => '',
		);
	}

	/**
	 * All definitions, in the order they were added.
	 *
	 * @return array id => definition
	 */
	public static function all() {
		$all = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $id => $def ) {
			if ( is_array( $def ) ) {
				$out[ (string) $id ] = wp_parse_args( $def, self::defaults() );
			}
		}
		return $out;
	}

	/**
	 * One definition.
	 *
	 * @param string $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ (string) $id ] ) ? $all[ (string) $id ] : null;
	}

	/**
	 * Clean a definition from user input.
	 *
	 * @param array $in Raw.
	 * @return array
	 */
	public static function sanitize( array $in ) {
		$d    = self::defaults();
		$list = static function ( $v ) {
			return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) $v ) ), 'strlen' ) ) );
		};
		$emails = static function ( $v ) {
			return implode( ', ', array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) $v ) ), 'is_email' ) );
		};
		$trigger = sanitize_text_field( (string) ( $in['trigger'] ?? 'booked' ) );
		$out     = array(
			'name'         => sanitize_text_field( (string) ( $in['name'] ?? '' ) ),
			'enabled'      => empty( $in['enabled'] ) ? 0 : 1,
			'trigger'      => isset( self::trigger_labels()[ $trigger ] ) ? $trigger : 'booked',
			'offset'       => max( 0, min( 9999, (int) ( $in['offset'] ?? 1 ) ) ),
			'unit'         => in_array( $in['unit'] ?? '', array( 'minutes', 'hours', 'days', 'weeks' ), true ) ? $in['unit'] : 'days',
			'late'         => empty( $in['late'] ) ? 0 : 1,
			'to_customer'  => empty( $in['to_customer'] ) ? 0 : 1,
			'to_attendees' => empty( $in['to_attendees'] ) ? 0 : 1,
			'to_staff'     => empty( $in['to_staff'] ) ? 0 : 1,
			'to_admin'     => empty( $in['to_admin'] ) ? 0 : 1,
			'to_extra'     => $emails( $in['to_extra'] ?? '' ),
			'bcc'          => $emails( $in['bcc'] ?? '' ),
			'from_name'    => sanitize_text_field( (string) ( $in['from_name'] ?? '' ) ),
			'items'        => $list( $in['items'] ?? array() ),
			'staff'        => array_values( array_filter( array_map( 'absint', (array) ( $in['staff'] ?? array() ) ) ) ),
			'statuses'     => array_values( array_intersect( $list( $in['statuses'] ?? array() ), array_keys( OSB_Bookings::statuses() ) ) ),
			'methods'      => $list( $in['methods'] ?? array() ),
			'pay'          => in_array( $in['pay'] ?? '', array( 'paid', 'unpaid' ), true ) ? $in['pay'] : 'any',
			'customer'     => in_array( $in['customer'] ?? '', array( 'new', 'returning' ), true ) ? $in['customer'] : 'any',
			'min_seats'    => max( 0, (int) ( $in['min_seats'] ?? 0 ) ),
			'coupon'       => in_array( $in['coupon'] ?? '', array( 'used', 'none' ), true ) ? $in['coupon'] : 'any',
			'subject'      => sanitize_text_field( (string) ( $in['subject'] ?? '' ) ),
			'body'         => wp_kses_post( (string) ( $in['body'] ?? '' ) ),
			'ics'          => empty( $in['ics'] ) ? 0 : 1,
			'note'         => sanitize_textarea_field( (string) ( $in['note'] ?? '' ) ),
		);
		$reply = sanitize_text_field( (string) ( $in['reply_to'] ?? 'default' ) );
		if ( 'other' === $reply ) {
			$reply = is_email( trim( (string) ( $in['reply_to_email'] ?? '' ) ) ) ? trim( (string) $in['reply_to_email'] ) : 'default';
		}
		$out['reply_to'] = in_array( $reply, array( 'default', 'customer', 'staff' ), true ) || is_email( $reply ) ? $reply : 'default';
		return wp_parse_args( $out, $d );
	}

	/**
	 * Save a definition (new when id is empty).
	 *
	 * @param array  $def Clean definition.
	 * @param string $id  Existing id or ''.
	 * @return string Id.
	 */
	public static function save( array $def, $id = '' ) {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$id  = (string) $id;
		$old = $id && isset( $all[ $id ] ) ? wp_parse_args( $all[ $id ], self::defaults() ) : null;
		if ( ! $old ) {
			$n  = (int) get_option( 'osb_custom_email_seq', 0 ) + 1;
			$id = 'ce' . $n;
			update_option( 'osb_custom_email_seq', $n, false );
		}
		$def['id'] = $id;
		// Scheduled emails only go to bookings from the moment they're switched on — no backlog of old visits.
		$def['since'] = ( $old && $old['enabled'] && $old['since'] && $old['trigger'] === $def['trigger'] ) ? $old['since'] : current_time( 'mysql' );
		$all[ $id ]   = $def;
		update_option( self::OPTION, $all, false );
		do_action( 'osb_custom_email_saved', $id, $def );
		return $id;
	}

	/**
	 * Switch one on or off.
	 *
	 * @param string $id Id.
	 * @param bool   $on On.
	 */
	public static function toggle( $id, $on ) {
		$def = self::get( $id );
		if ( $def ) {
			$def['enabled'] = $on ? 1 : 0;
			self::save( $def, $id );
		}
	}

	/**
	 * Delete one.
	 *
	 * @param string $id Id.
	 */
	public static function delete( $id ) {
		$all = get_option( self::OPTION, array() );
		if ( is_array( $all ) && isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * Copy one (switched off, so it doesn't start sending twice).
	 *
	 * @param string $id Id.
	 * @return string New id.
	 */
	public static function duplicate( $id ) {
		$def = self::get( $id );
		if ( ! $def ) {
			return '';
		}
		/* translators: %s email name */
		$def['name']    = sprintf( __( '%s (copy)', 'osam-bookings' ), $def['name'] );
		$def['enabled'] = 0;
		return self::save( $def );
	}

	/**
	 * Ready-made starting points.
	 *
	 * @return array key => [ label, description, definition overrides ]
	 */
	public static function presets() {
		$p = array(
			'aftercare'   => array(
				__( 'Aftercare instructions', 'osam-bookings' ),
				__( 'A few hours after the appointment, with care tips. Pick the services it applies to.', 'osam-bookings' ),
				array(
					'name'    => __( 'Aftercare instructions', 'osam-bookings' ),
					'trigger' => 'after_end',
					'offset'  => 2,
					'unit'    => 'hours',
					'subject' => __( 'Looking after yourself after your {item_name}', 'osam-bookings' ),
					'body'    => __( "<p>Hi {customer_first_name},</p>\n<p>Thanks for coming in for your {item_name} today. Here are a few tips for the next few days:</p>\n<ul>\n<li>Tip one</li>\n<li>Tip two</li>\n<li>Tip three</li>\n</ul>\n<p>If you have any questions, just reply to this email.</p>\n<p><a href=\"{booking_url}\">Book your next visit</a></p>", 'osam-bookings' ),
				),
			),
			'prep'        => array(
				__( 'What to bring / how to prepare', 'osam-bookings' ),
				__( 'Two days before, with everything they need to know.', 'osam-bookings' ),
				array(
					'name'    => __( 'How to prepare', 'osam-bookings' ),
					'trigger' => 'before_start',
					'offset'  => 2,
					'unit'    => 'days',
					'subject' => __( 'Getting ready for {item_name} on {date}', 'osam-bookings' ),
					'body'    => __( "<p>Hi {customer_first_name},</p>\n<p>Your {item_name} is coming up on <strong>{date} at {time}</strong>. To get the most from it:</p>\n<ul>\n<li>Please arrive 10 minutes early</li>\n<li>Wear something comfortable</li>\n<li>Bring anything you'd like to show us</li>\n</ul>\n{details_card}\n{manage_button}", 'osam-bookings' ),
				),
			),
			'welcome'     => array(
				__( 'Welcome for first-time customers', 'osam-bookings' ),
				__( 'Sent only to people booking for the first time.', 'osam-bookings' ),
				array(
					'name'     => __( 'Welcome — first visit', 'osam-bookings' ),
					'trigger'  => 'confirmed',
					'customer' => 'new',
					'subject'  => __( 'Welcome to {site_name}, {customer_first_name}!', 'osam-bookings' ),
					'body'     => __( "<p>Hi {customer_first_name},</p>\n<p>We're so pleased you've booked with us for the first time. Here's what to expect when you arrive…</p>\n<p>See you on {date} at {time}.</p>\n{location_line}", 'osam-bookings' ),
				),
			),
			'unpaid'      => array(
				__( 'Payment reminder', 'osam-bookings' ),
				__( 'Three days before, only for bookings that haven’t been paid.', 'osam-bookings' ),
				array(
					'name'     => __( 'Payment reminder', 'osam-bookings' ),
					'trigger'  => 'before_start',
					'offset'   => 3,
					'unit'     => 'days',
					'pay'      => 'unpaid',
					'statuses' => array( 'confirmed', 'pending' ),
					'subject'  => __( 'Payment due for {item_name} on {date}', 'osam-bookings' ),
					'body'     => __( "<p>Hi {customer_first_name},</p>\n<p>Just a reminder that {amount} is still to pay for your {item_name} on {date}.</p>\n{details_card}", 'osam-bookings' ),
				),
			),
			'rebook'      => array(
				__( 'Time to book again', 'osam-bookings' ),
				__( 'Four weeks after the visit, inviting them back.', 'osam-bookings' ),
				array(
					'name'    => __( 'Time to book again', 'osam-bookings' ),
					'trigger' => 'after_end',
					'offset'  => 4,
					'unit'    => 'weeks',
					'subject' => __( 'Ready for your next {item_name}?', 'osam-bookings' ),
					'body'    => __( "<p>Hi {customer_first_name},</p>\n<p>It's been a few weeks since your last {item_name}. Book your next one whenever suits you:</p>\n<p><a href=\"{booking_url}\">Book now</a></p>", 'osam-bookings' ),
				),
			),
			'staff_brief' => array(
				__( 'Brief for the staff member', 'osam-bookings' ),
				__( 'The morning before, to whoever is taking the booking, with the customer’s answers.', 'osam-bookings' ),
				array(
					'name'        => __( 'Staff brief', 'osam-bookings' ),
					'trigger'     => 'before_start',
					'offset'      => 1,
					'unit'        => 'days',
					'to_customer' => 0,
					'to_staff'    => 1,
					'reply_to'    => 'customer',
					'subject'     => __( 'Tomorrow: {item_name} with {customer_name} at {time}', 'osam-bookings' ),
					'body'        => __( "<p>Hi {recipient_first_name},</p>\n<p>You have {item_name} with <strong>{customer_name}</strong> on {date} at {time}.</p>\n{details_card}\n{fields_table}\n{admin_button}", 'osam-bookings' ),
				),
			),
			'blank'       => array(
				__( 'Start from scratch', 'osam-bookings' ),
				__( 'An empty email with every option.', 'osam-bookings' ),
				array(),
			),
		);
		return apply_filters( 'osb_custom_email_presets', $p );
	}

	/* ---------------------------------------------------------------------
	 * Matching
	 * ------------------------------------------------------------------ */

	/**
	 * Which-bookings tokens for a booking: services, s:ID, cat:Name, events, e:ID, courses, c:ID.
	 *
	 * @param object $booking Booking.
	 * @return string[]
	 */
	public static function tokens( $booking ) {
		return OSB_Items::booking_tokens( $booking );
	}

	/**
	 * Does this booking qualify for this email?
	 *
	 * @param array  $def           Definition.
	 * @param object $booking       Booking.
	 * @param bool   $check_status  Check the status list (scheduled emails).
	 * @return bool
	 */
	public static function matches( array $def, $booking, $check_status = false ) {
		$ok = true;
		if ( 'bundle' === $booking->type && ! in_array( 'bundles', (array) $def['items'], true ) ) {
			return false; // Buying a bundle isn't a visit: only emails aimed at bundle purchases.
		}
		if ( $def['items'] && ! array_intersect( $def['items'], self::tokens( $booking ) ) ) {
			$ok = false;
		}
		if ( $ok && $def['staff'] && ! in_array( (int) $booking->staff_id, array_map( 'intval', $def['staff'] ), true ) ) {
			$ok = false;
		}
		if ( $ok && $check_status && $def['statuses'] && ! in_array( $booking->status, $def['statuses'], true ) ) {
			$ok = false;
		}
		if ( $ok && $def['methods'] && ! in_array( (string) $booking->payment_method, $def['methods'], true ) ) {
			$ok = false;
		}
		if ( $ok && 'paid' === $def['pay'] && 'paid' !== $booking->payment_status ) {
			$ok = false;
		}
		if ( $ok && 'unpaid' === $def['pay'] && 'unpaid' !== $booking->payment_status ) {
			$ok = false;
		}
		if ( $ok && $def['min_seats'] && (int) $booking->seats < (int) $def['min_seats'] ) {
			$ok = false;
		}
		if ( $ok && 'any' !== $def['coupon'] ) {
			$used = '' !== (string) ( $booking->coupon_code ?? '' );
			$ok   = 'used' === $def['coupon'] ? $used : ! $used;
		}
		if ( $ok && 'any' !== $def['customer'] ) {
			$first = self::is_first_booking( $booking );
			$ok    = 'new' === $def['customer'] ? $first : ! $first;
		}
		return (bool) apply_filters( 'osb_custom_email_matches', $ok, $def, $booking );
	}

	/**
	 * Is this the customer's first booking (ignoring cancelled ones and unpaid holds)?
	 *
	 * @param object $booking Booking.
	 * @return bool
	 */
	public static function is_first_booking( $booking ) {
		global $wpdb;
		$t = OSB_Install::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE LOWER(customer_email) = %s AND id <> %d AND id < %d AND status NOT IN ('cancelled','pending_payment','payment_failed')", strtolower( (string) $booking->customer_email ), (int) $booking->id, (int) $booking->id ) );
		return 0 === $n;
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------ */

	/**
	 * People an email goes to.
	 *
	 * @param array  $def     Definition.
	 * @param object $booking Booking.
	 * @return array [ [ email, name ], … ] unique by email
	 */
	public static function recipients( array $def, $booking ) {
		$out = array();
		$add = static function ( $email, $name ) use ( &$out ) {
			$email = trim( (string) $email );
			if ( is_email( $email ) && ! isset( $out[ strtolower( $email ) ] ) ) {
				$out[ strtolower( $email ) ] = array( $email, (string) $name );
			}
		};
		if ( $def['to_customer'] ) {
			$add( $booking->customer_email, $booking->customer_name );
		}
		if ( $def['to_attendees'] ) {
			foreach ( OSB_Bookings::attendees( $booking ) as $a ) {
				if ( empty( $a['same'] ) && ! empty( $a['email'] ) ) {
					$add( $a['email'], $a['name'] );
				}
			}
		}
		if ( $def['to_staff'] ) {
			list( $se, $sn ) = self::staff_contact( $booking );
			$add( $se, $sn );
		}
		if ( $def['to_admin'] ) {
			foreach ( OSB_Emails::admin_recipients() as $a ) {
				$add( $a, '' );
			}
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $def['to_extra'] ) ) ) as $a ) {
			$add( $a, '' );
		}
		return array_values( apply_filters( 'osb_custom_email_recipients', $out, $def, $booking ) );
	}

	/**
	 * The staff member's email and name for a booking (in solo mode: the owner's).
	 *
	 * @param object $booking Booking.
	 * @return array [ email, name ]
	 */
	public static function staff_contact( $booking ) {
		$sid = (int) ( $booking->staff_id ?? 0 );
		if ( ! $sid && OSB_DB::is_solo() ) {
			$sid = (int) OSB_DB::solo_staff_id();
		}
		$st = $sid ? OSB_DB::get( 'staff', $sid ) : null;
		return $st && is_email( $st->email ) ? array( $st->email, $st->name ) : array( '', '' );
	}

	/**
	 * Has this already gone out for this booking?
	 *
	 * @param string $id         Email id.
	 * @param int    $booking_id Booking.
	 * @param string $dedupe     Dedupe key.
	 * @return bool
	 */
	public static function already_sent( $id, $booking_id, $dedupe ) {
		global $wpdb;
		$t = OSB_Install::table( 'email_log' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE email_key = %s AND booking_id = %d AND dedupe = %s LIMIT 1", 'custom_' . $id, $booking_id, $dedupe ) );
	}

	/**
	 * Build and send one custom email for a booking.
	 *
	 * @param array      $def     Definition.
	 * @param object     $booking Booking.
	 * @param string     $dedupe  Dedupe key ('' = none).
	 * @param array      $extra   Extra placeholders.
	 * @param array|null $to      Override recipients [ [email, name] ].
	 * @return bool Whether at least one email was accepted.
	 */
	public static function send( array $def, $booking, $dedupe = '', array $extra = array(), $to = null ) {
		$list = null === $to ? self::recipients( $def, $booking ) : $to;
		if ( ! $list || '' === trim( $def['subject'] . $def['body'] ) ) {
			return false;
		}
		if ( ! apply_filters( 'osb_send_custom_email', true, $def, $booking, $list ) ) {
			return false;
		}
		$key = 'custom_' . $def['id'];
		switch ( $def['reply_to'] ) {
			case 'default':
				$reply = null;
				break;
			case 'customer':
				$reply = array( $booking->customer_email, $booking->customer_name );
				break;
			case 'staff':
				list( $se, $sn ) = self::staff_contact( $booking );
				$reply           = $se ? array( $se, $sn ) : null;
				break;
			default:
				$reply = array( $def['reply_to'], '' );
		}
		$bcc  = array_filter( array_map( 'trim', explode( ',', (string) $def['bcc'] ) ), 'is_email' );
		$any  = false;
		$tpl  = array( 'subject' => $def['subject'], 'body' => $def['body'] );
		foreach ( $list as $i => $r ) {
			$name  = '' !== (string) $r[1] ? (string) $r[1] : '';
			$first = $name ? trim( strtok( $name, ' ' ) ) : '';
			$map   = array_merge(
				$extra,
				array(
					'{recipient_name}'       => esc_html( $name ? $name : __( 'there', 'osam-bookings' ) ),
					'{recipient_first_name}' => esc_html( $first ? $first : __( 'there', 'osam-bookings' ) ),
				)
			);
			list( $subject, $html ) = OSB_Emails::build( $key, $booking, $tpl, $map );
			$sent = OSB_Emails::deliver(
				$key,
				$booking,
				$r[0],
				$subject,
				$html,
				array(
					'reply_to'  => $reply,
					'ics'       => ! empty( $def['ics'] ),
					'bcc'       => 0 === $i ? $bcc : array(), // BCC copy once, not once per recipient.
					'from_name' => $def['from_name'],
					'dedupe'    => $dedupe,
				)
			);
			$any = $any || $sent;
		}
		return $any;
	}

	/**
	 * Enabled definitions with a trigger.
	 *
	 * @param string $trigger Trigger.
	 * @return array
	 */
	public static function for_trigger( $trigger ) {
		return array_filter(
			self::all(),
			static function ( $d ) use ( $trigger ) {
				return $d['enabled'] && $d['trigger'] === $trigger;
			}
		);
	}

	/**
	 * Fire event-based emails.
	 *
	 * @param string $trigger Trigger.
	 * @param object $booking Booking.
	 * @param string $dedupe  Dedupe key.
	 */
	public static function fire( $trigger, $booking, $dedupe = '' ) {
		if ( ! $booking || empty( $booking->id ) ) {
			return;
		}
		$dedupe = '' !== $dedupe ? $dedupe : $trigger;
		foreach ( self::for_trigger( $trigger ) as $def ) {
			if ( self::matches( $def, $booking ) && ! self::already_sent( $def['id'], (int) $booking->id, $dedupe ) ) {
				self::send( $def, $booking, $dedupe );
			}
		}
	}

	/**
	 * Booking lifecycle (same moments as the standard emails).
	 *
	 * @param object $booking Booking.
	 * @param string $context new|approved|cancelled.
	 */
	public static function on_notify( $booking, $context ) {
		$booking = OSB_DB::get( 'bookings', (int) $booking->id ); // Fresh: the meeting link may just have been added.
		if ( ! $booking ) {
			return;
		}
		if ( 'new' === $context ) {
			self::fire( 'booked', $booking );
			if ( 'confirmed' === $booking->status ) {
				self::fire( 'confirmed', $booking );
			}
		} elseif ( 'approved' === $context ) {
			self::fire( 'confirmed', $booking );
		} elseif ( 'cancelled' === $context ) {
			self::fire( 'cancelled', $booking );
		} elseif ( 'payment_failed' === $context ) {
			self::fire( 'payment_failed', $booking );
		}
	}

	/**
	 * Completed / no-show.
	 *
	 * @param object $booking Booking.
	 * @param string $old     Old status.
	 * @param string $new     New status.
	 */
	public static function on_status( $booking, $old, $new ) {
		if ( in_array( $new, array( 'completed', 'no_show' ), true ) ) {
			self::fire( $new, $booking );
		}
	}

	/**
	 * Moved.
	 *
	 * @param object $booking   Booking (new time).
	 * @param string $old_start Old start.
	 * @param string $by        admin|customer.
	 */
	public static function on_rescheduled( $booking, $old_start, $by = '' ) {
		self::fire( 'rescheduled', $booking, 'rescheduled|' . $booking->start_at );
	}

	/**
	 * Paid online.
	 *
	 * @param object $booking Booking.
	 * @param string $gateway Gateway.
	 * @param string $txn     Transaction.
	 */
	public static function on_paid( $booking, $gateway = '', $txn = '' ) {
		self::fire( 'paid', $booking, 'paid|' . $txn );
	}

	/**
	 * Stand in for a standard email when a "replace" custom email matches.
	 *
	 * @param string          $key     Standard template key.
	 * @param object          $booking Booking.
	 * @param string|string[] $to      Who the standard email was going to.
	 * @param array           $extra   Extra placeholders.
	 * @return bool|null Null when nothing replaces it.
	 */
	public static function replace( $key, $booking, $to, array $extra = array() ) {
		$hit = null;
		foreach ( self::for_trigger( 'replace:' . $key ) as $def ) {
			if ( ! self::matches( $def, $booking ) ) {
				continue;
			}
			$list = self::recipients( $def, $booking );
			if ( ! $list ) {
				// No recipients ticked: send to whoever the standard email was for.
				foreach ( (array) $to as $addr ) {
					$list[] = array( $addr, $addr === $booking->customer_email ? $booking->customer_name : '' );
				}
			}
			$sent = self::send( $def, $booking, '', $extra, $list );
			$hit  = (bool) $hit || $sent;
		}
		return $hit;
	}

	/**
	 * Seconds in an offset.
	 *
	 * @param array $def Definition.
	 * @return int
	 */
	public static function offset_seconds( array $def ) {
		$mult = array(
			'minutes' => MINUTE_IN_SECONDS,
			'hours'   => HOUR_IN_SECONDS,
			'days'    => DAY_IN_SECONDS,
			'weeks'   => WEEK_IN_SECONDS,
		);
		return (int) $def['offset'] * ( $mult[ $def['unit'] ] ?? DAY_IN_SECONDS );
	}

	/**
	 * Scheduled emails (runs from the 5-minute job).
	 */
	public static function run() {
		global $wpdb;
		$t   = OSB_Install::table( 'bookings' );
		$now = strtotime( current_time( 'mysql' ) );
		$fmt = static function ( $ts ) {
			return gmdate( 'Y-m-d H:i:s', $ts );
		};
		$stop = microtime( true ) + 30;
		foreach ( self::all() as $def ) {
			if ( ! $def['enabled'] || ! self::is_timed( $def['trigger'] ) || microtime( true ) > $stop ) {
				continue;
			}
			$sec   = self::offset_seconds( $def );
			$since = max( strtotime( (string) $def['since'] ) ?: $now, $now - 14 * DAY_IN_SECONDS );
			$st    = $def['statuses'] ? $def['statuses'] : array_keys( OSB_Bookings::statuses() );
			$in    = implode( ',', array_fill( 0, count( $st ), '%s' ) );
			switch ( $def['trigger'] ) {
				case 'before_start':
					$sql  = "SELECT * FROM $t WHERE start_at > %s AND start_at <= %s AND type <> 'bundle' AND status IN ($in) ORDER BY start_at LIMIT %d";
					$args = array_merge( array( $fmt( $now ), $fmt( $now + $sec ) ), $st, array( self::BATCH ) );
					break;
				case 'after_end':
					$sql  = "SELECT * FROM $t WHERE end_at <= %s AND end_at >= %s AND type <> 'bundle' AND status IN ($in) ORDER BY end_at LIMIT %d";
					$args = array_merge( array( $fmt( $now - $sec ), $fmt( $since - $sec ) ), $st, array( self::BATCH ) );
					break;
				default: // after_booked.
					$sql  = "SELECT * FROM $t WHERE created_at <= %s AND created_at >= %s AND type <> 'bundle' AND status IN ($in) ORDER BY created_at LIMIT %d";
					$args = array_merge( array( $fmt( $now - $sec ), $fmt( $since - $sec ) ), $st, array( self::BATCH ) );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) as $b ) {
				if ( 'before_start' === $def['trigger'] ) {
					// Booked inside the window? They've only just had their confirmation.
					if ( ! $def['late'] && strtotime( $b->created_at ) > strtotime( $b->start_at ) - $sec ) {
						continue;
					}
					$dedupe = 'before|' . $b->start_at;
				} elseif ( 'after_end' === $def['trigger'] ) {
					$dedupe = 'after_end';
				} else {
					$dedupe = 'after_booked';
				}
				if ( self::already_sent( $def['id'], (int) $b->id, $dedupe ) || ! self::matches( $def, $b, true ) ) {
					continue;
				}
				self::send( $def, $b, $dedupe );
			}
		}
	}

	/**
	 * Times each custom email has gone out.
	 *
	 * @return array id => count
	 */
	public static function sent_counts() {
		global $wpdb;
		$t   = OSB_Install::table( 'email_log' );
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( "SELECT email_key, COUNT(*) AS n, MAX(created_at) AS last FROM $t WHERE email_key LIKE 'custom\\_%' AND sent = 1 GROUP BY email_key" ) as $r ) {
			$out[ substr( $r->email_key, 7 ) ] = array( (int) $r->n, $r->last );
		}
		return $out;
	}

	/**
	 * A booking to preview or test with: the latest one this email would apply to, else any, else sample.
	 *
	 * @param array $def Definition.
	 * @return object
	 */
	public static function sample_booking( array $def ) {
		list( $rows ) = OSB_DB::query_bookings( array( 'per_page' => 40, 'orderby' => 'id' ) );
		foreach ( (array) $rows as $b ) {
			if ( self::matches( $def, $b ) ) {
				return $b;
			}
		}
		return OSB_Emails::sample_booking( 'customer_confirmed' );
	}

	/**
	 * Preview HTML.
	 *
	 * @param array $def    Definition (unsaved).
	 * @param array $design Unsaved design values (optional).
	 * @return array [ subject, html ]
	 */
	public static function preview( array $def, array $design = array() ) {
		$booking = self::sample_booking( $def );
		if ( $design ) {
			OSB_Emails::preview_with( $design );
		}
		$first = trim( strtok( (string) $booking->customer_name, ' ' ) );
		$out   = OSB_Emails::build(
			'custom_preview',
			$booking,
			array( 'subject' => $def['subject'], 'body' => $def['body'] ),
			array_merge(
				OSB_Emails::sample_extra( 'custom', $booking ),
				array(
					'{recipient_name}'       => esc_html( $booking->customer_name ),
					'{recipient_first_name}' => esc_html( $first ),
				)
			)
		);
		OSB_Emails::preview_with( null );
		return $out;
	}

	/**
	 * Plain-English summary of when it sends.
	 *
	 * @param array $def Definition.
	 * @return string
	 */
	public static function describe_when( array $def ) {
		$labels = self::trigger_labels();
		if ( self::is_timed( $def['trigger'] ) ) {
			$units = array(
				'minutes' => _n_noop( '%d minute', '%d minutes', 'osam-bookings' ),
				'hours'   => _n_noop( '%d hour', '%d hours', 'osam-bookings' ),
				'days'    => _n_noop( '%d day', '%d days', 'osam-bookings' ),
				'weeks'   => _n_noop( '%d week', '%d weeks', 'osam-bookings' ),
			);
			$amount = sprintf( translate_nooped_plural( $units[ $def['unit'] ], (int) $def['offset'], 'osam-bookings' ), (int) $def['offset'] );
			$map    = array(
				/* translators: %s amount of time */
				'before_start' => __( '%s before it starts', 'osam-bookings' ),
				/* translators: %s amount of time */
				'after_end'    => __( '%s after it ends', 'osam-bookings' ),
				/* translators: %s amount of time */
				'after_booked' => __( '%s after it was booked', 'osam-bookings' ),
			);
			return sprintf( $map[ $def['trigger'] ], $amount );
		}
		return isset( $labels[ $def['trigger'] ] ) ? $labels[ $def['trigger'] ] : $def['trigger'];
	}

	/**
	 * Plain-English summary of which bookings.
	 *
	 * @param array $def Definition.
	 * @return string
	 */
	public static function describe_items( array $def ) {
		if ( ! $def['items'] && ! $def['staff'] ) {
			return __( 'All bookings', 'osam-bookings' );
		}
		$names = array();
		foreach ( $def['items'] as $tok ) {
			$names[] = self::token_label( $tok );
		}
		foreach ( $def['staff'] as $sid ) {
			$st = OSB_DB::get( 'staff', (int) $sid );
			if ( $st ) {
				/* translators: %s staff name */
				$names[] = sprintf( __( 'with %s', 'osam-bookings' ), $st->name );
			}
		}
		$names = array_filter( $names );
		return count( $names ) > 3 ? sprintf( /* translators: 1 first names 2 count */ __( '%1$s and %2$d more', 'osam-bookings' ), implode( ', ', array_slice( $names, 0, 3 ) ), count( $names ) - 3 ) : implode( ', ', $names );
	}

	/**
	 * Human label for a which-bookings token.
	 *
	 * @param string $tok Token.
	 * @return string
	 */
	public static function token_label( $tok ) {
		return OSB_Items::label( $tok );
	}
}
