<?php
/**
 * Access codes for resource bookings: a door / key-safe / gate code shown to the customer once the
 * booking is confirmed (and paid, when it's paid online).
 *
 * Each resource chooses:
 *  - access_mode     '' (none) | 'fixed' (the same code every time, e.g. a key safe) | 'unique' (a new code per booking)
 *  - access_code     the fixed code
 *  - access_length   digits for unique codes (4–8)
 *  - access_label    what to call it ("Door code", "Gate PIN"…)
 *  - access_note     instructions shown with the code
 *  - access_release  0 = straight away in the confirmation; N = only N hours before the start (sent by email then)
 *
 * Unique codes are stored on the booking line (booking_resources.access_code), so they survive edits.
 * Fixed codes are read from the resource when shown, so changing a key-safe code updates every future booking.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Access codes.
 */
class OSB_Access {

	/**
	 * Show codes regardless of release time (staff sending one early).
	 *
	 * @var bool
	 */
	public static $force = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'osb_booking_details', array( __CLASS__, 'details' ), 20, 2 );
		add_filter( 'osb_details_card_rows', array( __CLASS__, 'card_rows' ), 10, 3 );
		add_filter( 'osb_email_placeholders', array( __CLASS__, 'placeholders' ), 20, 2 );
		add_action( 'osb_booking_notify', array( __CLASS__, 'after_notify' ), 30, 2 );
		add_action( 'osb_booking_rescheduled', array( __CLASS__, 'on_rescheduled' ), 10, 1 );
	}

	/**
	 * Does this resource give out a code?
	 *
	 * @param object|null $r Resource.
	 * @return bool
	 */
	public static function uses_code( $r ) {
		return $r && in_array( (string) $r->access_mode, array( 'fixed', 'unique' ), true );
	}

	/**
	 * A new random numeric code.
	 *
	 * @param object $r Resource.
	 * @return string
	 */
	public static function generate( $r ) {
		$len  = min( 8, max( 4, (int) $r->access_length ) );
		$code = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$code .= (string) random_int( 0, 9 );
		}
		// Avoid codes that are easy to guess (1111, 1234…).
		if ( 1 === count( array_unique( str_split( $code ) ) ) || false !== strpos( '0123456789', $code ) || false !== strpos( '9876543210', $code ) ) {
			return self::generate( $r );
		}
		return (string) apply_filters( 'osb_access_code', $code, $r );
	}

	/**
	 * Unique code to store for a new line (keeps the old one when the line is re-saved).
	 *
	 * @param object $r        Resource.
	 * @param string $existing Code already on the line.
	 * @return string
	 */
	public static function code_for_line( $r, $existing = '' ) {
		if ( ! $r || 'unique' !== $r->access_mode ) {
			return (string) $existing;
		}
		return '' !== (string) $existing ? (string) $existing : self::generate( $r );
	}

	/**
	 * When the code for a booking becomes visible (Unix time, site-local clock), 0 = straight away.
	 *
	 * @param object $booking Booking.
	 * @param object $r       Resource.
	 * @return int
	 */
	public static function release_at( $booking, $r ) {
		$hours = (int) $r->access_release;
		return $hours > 0 ? strtotime( $booking->start_at ) - $hours * HOUR_IN_SECONDS : 0;
	}

	/**
	 * Is the booking in a state where the code may be shown? Confirmed (or completed) only — never while
	 * waiting for approval or payment.
	 *
	 * @param object $booking Booking.
	 * @return bool
	 */
	public static function booking_ok( $booking ) {
		return 'resource' === $booking->type && in_array( $booking->status, array( 'confirmed', 'completed' ), true );
	}

	/**
	 * Codes for a booking.
	 *
	 * @param object $booking Booking.
	 * @param bool   $force   Ignore the release time (admin views).
	 * @return array [ 'shown' => [ [resource, label, code, note] ], 'later' => [ [resource, label, release_ts] ], 'held' => bool ]
	 */
	public static function codes( $booking, $force = false ) {
		$out = array(
			'shown' => array(),
			'later' => array(),
			'held'  => false,
		);
		if ( 'resource' !== $booking->type ) {
			return $out;
		}
		$force = $force || self::$force;
		$now   = strtotime( current_time( 'mysql' ) );
		$ok    = self::booking_ok( $booking );
		foreach ( OSB_Resources::lines( $booking->id ) as $l ) {
			$r = OSB_Resources::get( $l->resource_id );
			if ( ! self::uses_code( $r ) ) {
				continue;
			}
			$code  = 'fixed' === $r->access_mode ? (string) $r->access_code : (string) $l->access_code;
			$label = '' !== trim( (string) $r->access_label ) ? $r->access_label : __( 'Access code', 'osam-bookings' );
			if ( '' === $code ) {
				continue;
			}
			if ( ! $ok && ! $force ) {
				$out['held'] = true;
				continue;
			}
			$at = self::release_at( $booking, $r );
			if ( ! $force && $at && $now < $at ) {
				$out['later'][] = array( $r->name, $label, $at );
				continue;
			}
			$out['shown'][] = array( $r->name, $label, $code, (string) $r->access_note );
		}
		return $out;
	}

	/**
	 * "We'll email your door code on Fri 3 Nov at 9:00".
	 *
	 * @param array $later Later entries.
	 * @return string
	 */
	public static function later_text( array $later ) {
		if ( ! $later ) {
			return '';
		}
		$at = min( wp_list_pluck( $later, 2 ) );
		/* translators: 1: what the code is called 2: date and time */
		return sprintf( __( 'We’ll email your %1$s on %2$s.', 'osam-bookings' ), function_exists( 'mb_strtolower' ) ? mb_strtolower( $later[0][1] ) : strtolower( $later[0][1] ), date_i18n( 'D j M, ' . get_option( 'time_format' ), $at ) );
	}

	/**
	 * Add codes to the booking details (booking form confirmation, manage page).
	 *
	 * @param array  $d       Details.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function details( $d, $booking ) {
		if ( 'resource' !== $booking->type ) {
			return $d;
		}
		$c              = self::codes( $booking );
		$multi          = count( OSB_Resources::lines( $booking->id ) ) > 1;
		$d['access']    = array();
		foreach ( $c['shown'] as $row ) {
			$d['access'][] = array(
				'label' => $multi ? $row[1] . ' — ' . $row[0] : $row[1],
				'code'  => $row[2],
				'note'  => $row[3],
			);
		}
		$d['access_later'] = self::later_text( $c['later'] );
		$d['access_held']  = $c['held'] ? __( 'Your access code will be sent once your booking is confirmed.', 'osam-bookings' ) : '';
		return $d;
	}

	/**
	 * Rows in the email booking-details box.
	 *
	 * @param array  $rows    [label, value, raw?].
	 * @param object $booking Booking.
	 * @param array  $d       Details.
	 * @return array
	 */
	public static function card_rows( $rows, $booking, $d ) {
		if ( empty( $d['access'] ) && empty( $d['access_later'] ) && empty( $d['access_held'] ) ) {
			return $rows;
		}
		foreach ( (array) $d['access'] as $a ) {
			$val    = '<strong style="font-size:18px;letter-spacing:.12em;font-family:Menlo,Consolas,monospace">' . esc_html( $a['code'] ) . '</strong>' . ( '' !== trim( $a['note'] ) ? '<div style="margin-top:4px;font-weight:400">' . nl2br( esc_html( $a['note'] ) ) . '</div>' : '' );
			$rows[] = array( $a['label'], $val, true );
		}
		if ( ! empty( $d['access_later'] ) ) {
			$rows[] = array( __( 'Access', 'osam-bookings' ), $d['access_later'] );
		} elseif ( ! empty( $d['access_held'] ) && empty( $d['access'] ) ) {
			$rows[] = array( __( 'Access', 'osam-bookings' ), $d['access_held'] );
		}
		return $rows;
	}

	/**
	 * {access_code}, {access_details}, {access_note}.
	 *
	 * @param array  $map     Placeholders.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function placeholders( $map, $booking ) {
		$d     = 'resource' === $booking->type ? self::details( array(), $booking ) : array();
		$codes = isset( $d['access'] ) ? $d['access'] : array();
		$map['{access_code}']  = esc_html( implode( ', ', wp_list_pluck( $codes, 'code' ) ) );
		$map['{access_note}']  = $codes ? nl2br( esc_html( $codes[0]['note'] ) ) : '';
		$ds                    = OSB_Emails::design();
		$html                  = '';
		foreach ( $codes as $a ) {
			$html .= '<div style="margin:0 0 12px"><div style="font-size:13px;color:' . $ds['muted'] . '">' . esc_html( $a['label'] ) . '</div><div style="font-size:26px;font-weight:700;letter-spacing:.15em;font-family:Menlo,Consolas,monospace;color:' . $ds['text'] . '">' . esc_html( $a['code'] ) . '</div>' . ( '' !== trim( $a['note'] ) ? '<div style="font-size:14px;margin-top:4px">' . nl2br( esc_html( $a['note'] ) ) . '</div>' : '' ) . '</div>';
		}
		$map['{access_details}'] = $html ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 24px;background:' . $ds['panel_bg'] . ';border-radius:' . $ds['radius'] . 'px;border:2px dashed ' . $ds['accent'] . '"><tr><td style="padding:18px 22px">' . $html . '</td></tr></table>' : '';
		return $map;
	}

	/**
	 * After the confirmation goes out, a code it already showed doesn't need its own email.
	 *
	 * @param object $booking Booking.
	 * @param string $context Context.
	 */
	public static function after_notify( $booking, $context ) {
		if ( ! in_array( $context, array( 'new', 'approved' ), true ) || ! self::booking_ok( $booking ) ) {
			return;
		}
		$c = self::codes( $booking );
		if ( $c['shown'] && ! $c['later'] ) {
			self::mark_sent( $booking->id );
		}
	}

	/**
	 * Mark a booking's codes as sent.
	 *
	 * @param int  $booking_id Booking.
	 * @param bool $sent       Sent or not.
	 */
	public static function mark_sent( $booking_id, $sent = true ) {
		global $wpdb;
		$wpdb->update( OSB_Install::table( 'booking_resources' ), array( 'access_sent' => $sent ? 1 : 0 ), array( 'booking_id' => (int) $booking_id ) );
		OSB_Resources::flush();
	}

	/**
	 * Moved bookings get their code again at the new release time.
	 *
	 * @param object $booking Booking.
	 */
	public static function on_rescheduled( $booking ) {
		if ( 'resource' !== $booking->type ) {
			return;
		}
		$c = self::codes( $booking );
		self::mark_sent( $booking->id, (bool) ( $c['shown'] && ! $c['later'] ) );
	}

	/**
	 * Give a booking new unique codes (admin "new code" button).
	 *
	 * @param int $booking_id Booking.
	 * @return bool
	 */
	public static function regenerate( $booking_id ) {
		global $wpdb;
		$t   = OSB_Install::table( 'booking_resources' );
		$any = false;
		foreach ( OSB_Resources::lines( $booking_id ) as $l ) {
			$r = OSB_Resources::get( $l->resource_id );
			if ( $r && 'unique' === $r->access_mode ) {
				$wpdb->update( $t, array( 'access_code' => self::generate( $r ) ), array( 'booking_id' => (int) $booking_id, 'resource_id' => (int) $l->resource_id ) );
				$any = true;
			}
		}
		OSB_Resources::flush();
		return $any;
	}

	/**
	 * Cron: email codes whose release time has come. Codes given "in the confirmation" that never went
	 * out (later dates of a repeat booking, staff bookings made without emails) go 24 hours before.
	 */
	public static function send_due() {
		if ( ! OSB_Resources::enabled() ) {
			return;
		}
		global $wpdb;
		$b   = OSB_Install::table( 'bookings' );
		$br  = OSB_Install::table( 'booking_resources' );
		$r   = OSB_Install::table( 'resources' );
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT b.id FROM $br l JOIN $b b ON b.id = l.booking_id JOIN $r r ON r.id = l.resource_id WHERE l.access_sent = 0 AND r.access_mode IN ('fixed','unique') AND b.status = 'confirmed' AND b.type = 'resource' AND b.end_at > %s AND DATE_SUB(b.start_at, INTERVAL IF(r.access_release = 0, 24, r.access_release) HOUR) <= %s LIMIT 100", $now, $now ) );
		foreach ( $ids as $id ) {
			$booking = OSB_DB::get( 'bookings', (int) $id );
			if ( ! $booking ) {
				continue;
			}
			$c = self::codes( $booking );
			if ( ! $c['shown'] ) {
				continue;
			}
			self::mark_sent( $booking->id );
			OSB_Emails::send( 'customer_access_code', $booking, $booking->customer_email );
		}
	}
}
