<?php
/**
 * Repeat bookings: the same booking every week, fortnight or month, made in one go.
 *
 * Each date is its own booking, linked by series_id (the first booking's id, which is the "lead").
 * Customers pay once: the lead booking's online payment covers every date still awaiting payment, and
 * marking the lead paid (or failed) carries over to the rest. Only the lead sends the confirmation emails,
 * which list every date. Dates that can't be booked are skipped and reported.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Repeat bookings.
 */
class OSB_Repeat {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'osb_booking_paid', array( __CLASS__, 'on_paid' ), 4, 3 );
		add_action( 'osb_booking_status_changed', array( __CLASS__, 'on_status' ), 4, 3 );
		add_filter( 'osb_booking_details', array( __CLASS__, 'details' ), 14, 2 );
		add_filter( 'osb_details_card_rows', array( __CLASS__, 'card_rows' ), 7, 3 );
		add_filter( 'osb_email_placeholders', array( __CLASS__, 'placeholders' ), 15, 2 );
		add_action( 'osb_admin_booking_side', array( __CLASS__, 'admin_panel' ), 5 );
		add_action( 'admin_post_osb_series_cancel', array( __CLASS__, 'admin_cancel' ) );
	}

	/**
	 * The series on a booking's admin page.
	 *
	 * @param object $b Booking.
	 */
	public static function admin_panel( $b ) {
		$list = self::series( $b );
		if ( count( $list ) < 2 ) {
			return;
		}
		$labels = OSB_Bookings::statuses();
		$live   = array_filter( $list, static function ( $x ) use ( $b ) { return $x->start_at >= $b->start_at && ! in_array( $x->status, array( 'cancelled', 'payment_failed', 'completed', 'no_show' ), true ); } );
		echo '<div class="osb-card">';
		OSB_Admin::card_head( __( 'Repeat booking', 'osam-bookings' ), self::rule_label( $b->repeat_rule, count( $list ) ) );
		echo '<ul class="osb-series-list">';
		foreach ( $list as $x ) {
			printf(
				'<li class="%s"><a href="%s">%s</a> %s</li>',
				(int) $x->id === (int) $b->id ? 'is-current' : '',
				esc_url( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . (int) $x->id ) ),
				esc_html( date_i18n( 'D j M Y, ' . get_option( 'time_format' ), strtotime( $x->start_at ) ) ),
				OSB_Admin::status_pill( $x->status ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
		echo '</ul>';
		if ( count( $live ) > 1 ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=osb_series_cancel&id=' . (int) $b->id ), 'osb_series_cancel_' . (int) $b->id );
			echo '<p><a class="osb-btn osb-btn--ghost osb-btn--sm osb-btn--danger" data-osb-confirm="' . esc_attr__( 'Cancel this date and every later date? The customer gets one email.', 'osam-bookings' ) . '" href="' . esc_url( $url ) . '">' . esc_html( sprintf( /* translators: %d dates */ __( 'Cancel this and later dates (%d)', 'osam-bookings' ), count( $live ) ) ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Staff: cancel the rest of a series.
	 */
	public static function admin_cancel() {
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_series_cancel_' . $id );
		$b = OSB_DB::get( 'bookings', $id );
		if ( $b ) {
			self::cancel_from( $b, false );
		}
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $id ) );
	}

	/**
	 * Types customers can repeat.
	 *
	 * @param string $type Booking type.
	 * @param bool   $admin Staff booking.
	 * @return bool
	 */
	public static function allowed( $type, $admin = false ) {
		if ( ! in_array( $type, array( 'service', 'resource', 'table' ), true ) ) {
			return false;
		}
		if ( $admin ) {
			return true;
		}
		if ( 'table' === $type && 'card_hold' === OSB_Settings::get( 'rest_payment_mode' ) ) {
			return false; // A saved card covers one reservation; regular tables are booked by staff.
		}
		return OSB_Settings::feature( 'repeat' ) && in_array( $type, (array) OSB_Settings::get( 'repeat_types', array() ), true );
	}

	/**
	 * Frequencies.
	 *
	 * @return array key => label
	 */
	public static function freqs() {
		return array(
			'weekly'      => __( 'Every week', 'osam-bookings' ),
			'fortnightly' => __( 'Every 2 weeks', 'osam-bookings' ),
			'monthly'     => __( 'Every month (same weekday)', 'osam-bookings' ),
		);
	}

	/**
	 * Frequencies offered to customers.
	 *
	 * @return array
	 */
	public static function offered() {
		$on = (array) OSB_Settings::get( 'repeat_freqs', array( 'weekly' ) );
		return array_intersect_key( self::freqs(), array_flip( $on ) );
	}

	/**
	 * Most dates in one series (including the first).
	 *
	 * @param bool $admin Staff booking.
	 * @return int
	 */
	public static function max( $admin = false ) {
		return $admin ? 52 : max( 2, min( 52, (int) OSB_Settings::get( 'repeat_max', 12 ) ) );
	}

	/**
	 * Dates for a series, first one included.
	 *
	 * @param string $date  Y-m-d.
	 * @param string $freq  weekly|fortnightly|monthly.
	 * @param int    $count Dates.
	 * @return string[]
	 */
	public static function dates( $date, $freq, $count ) {
		$ts  = strtotime( $date . ' 12:00:00' );
		$out = array( gmdate( 'Y-m-d', $ts ) );
		if ( ! $ts ) {
			return array();
		}
		$nth = (int) ceil( (int) gmdate( 'j', $ts ) / 7 ); // 1st…5th weekday of the month.
		$dow = (int) gmdate( 'w', $ts );
		for ( $i = 1; $i < $count; $i++ ) {
			if ( 'monthly' === $freq ) {
				$first = strtotime( gmdate( 'Y-m-01 12:00:00', $ts ) . " +$i month" );
				$shift = ( $dow - (int) gmdate( 'w', $first ) + 7 ) % 7;
				$day   = $first + ( $shift + 7 * ( $nth - 1 ) ) * DAY_IN_SECONDS;
				while ( gmdate( 'm', $day ) !== gmdate( 'm', $first ) ) { // No 5th Tuesday: use the last one.
					$day -= 7 * DAY_IN_SECONDS;
				}
				$out[] = gmdate( 'Y-m-d', $day );
			} else {
				$out[] = gmdate( 'Y-m-d', $ts + $i * ( 'fortnightly' === $freq ? 14 : 7 ) * DAY_IN_SECONDS );
			}
		}
		return $out;
	}

	/**
	 * Clean a submitted repeat choice.
	 *
	 * @param mixed $in    [ freq, count ].
	 * @param bool  $admin Staff booking.
	 * @return array|null [ freq, count ] or null for no repeat.
	 */
	public static function parse( $in, $admin = false ) {
		if ( ! is_array( $in ) || empty( $in['freq'] ) ) {
			return null;
		}
		$freq  = sanitize_key( $in['freq'] );
		$count = (int) ( $in['count'] ?? 0 );
		$pool  = $admin ? self::freqs() : self::offered();
		if ( ! isset( $pool[ $freq ] ) || $count < 2 ) {
			return null;
		}
		return array( $freq, min( self::max( $admin ), $count ) );
	}

	/**
	 * Which of a series' dates can be booked (dry run, nothing saved).
	 *
	 * @param array  $data  Same data as OSB_Bookings::create() for the first date.
	 * @param string $freq  Frequency.
	 * @param int    $count Dates.
	 * @return array [ [ date, label, ok, reason, amount ] ]
	 */
	public static function preview( array $data, $freq, $count ) {
		$out = array();
		foreach ( self::dates( (string) $data['date'], $freq, $count ) as $i => $date ) {
			$try = OSB_Bookings::create( array_merge( $data, array( 'date' => $date, 'dry_run' => true, 'notify' => false, 'repeat' => null ) ) );
			$out[] = array(
				'date'   => $date,
				'label'  => date_i18n( 'D j M Y', strtotime( $date . ' 12:00:00' ) ),
				'ok'     => ! is_wp_error( $try ),
				'reason' => is_wp_error( $try ) ? $try->get_error_message() : '',
				'amount' => is_wp_error( $try ) ? 0 : (float) $try->amount,
				'first'  => 0 === $i,
			);
		}
		return $out;
	}

	/**
	 * Book the rest of a series after the lead booking is saved.
	 *
	 * @param object $lead  First booking.
	 * @param array  $data  Data the lead was made with.
	 * @param string $freq  Frequency.
	 * @param int    $count Dates.
	 * @return array [ made booking ids, skipped [ date => reason ] ]
	 */
	public static function book_rest( $lead, array $data, $freq, $count ) {
		$rule = $freq . ':' . $count;
		OSB_DB::update( 'bookings', $lead->id, array( 'series_id' => (int) $lead->id, 'repeat_rule' => $rule ) );
		$made    = array();
		$skipped = array();
		$base    = array_merge(
			$data,
			array(
				'series_id'   => (int) $lead->id,
				'repeat_rule' => $rule,
				'repeat'      => null,
				'notify'      => false,
				'waitlist'    => '',
			)
		);
		if ( 'service' === $lead->type && (int) $lead->staff_id ) {
			$base['staff_id'] = (int) $lead->staff_id; // Same person each time when they're free.
		}
		if ( 'resource' === $lead->type ) {
			$base['payment_method'] = $lead->payment_method;
		}
		$dates = self::dates( substr( $lead->start_at, 0, 10 ), $freq, $count );
		array_shift( $dates );
		foreach ( $dates as $date ) {
			$try = OSB_Bookings::create( array_merge( $base, array( 'date' => $date ) ) );
			if ( is_wp_error( $try ) && 'service' === $lead->type && (int) $data['staff_id'] !== (int) $base['staff_id'] ) {
				$try = OSB_Bookings::create( array_merge( $base, array( 'date' => $date, 'staff_id' => (int) $data['staff_id'] ) ) );
			}
			if ( is_wp_error( $try ) && 'osb_coupon' === $try->get_error_code() ) {
				$try = OSB_Bookings::create( array_merge( $base, array( 'date' => $date, 'coupon' => '' ) ) );
			}
			if ( is_wp_error( $try ) ) {
				$skipped[ $date ] = $try->get_error_message();
				continue;
			}
			// Paying online: everything waits for the one payment on the lead booking.
			if ( 'pending_payment' === $lead->status && 'pending_payment' !== $try->status && 'free' !== $try->payment_status ) {
				OSB_DB::update( 'bookings', $try->id, array( 'status' => 'pending_payment' ) );
			}
			$made[] = (int) $try->id;
		}
		OSB_Availability::bust_cache();
		OSB_Resources::flush();
		return array( $made, $skipped );
	}

	/**
	 * Every booking in a series, in date order.
	 *
	 * @param object $booking Any booking in it.
	 * @return array
	 */
	public static function series( $booking ) {
		if ( ! (int) $booking->series_id ) {
			return array();
		}
		global $wpdb;
		$t = OSB_Install::table( 'bookings' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE series_id = %d ORDER BY start_at ASC, id ASC", (int) $booking->series_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Is this the series' first booking?
	 *
	 * @param object $booking Booking.
	 * @return bool
	 */
	public static function is_lead( $booking ) {
		return (int) $booking->series_id && (int) $booking->series_id === (int) $booking->id;
	}

	/**
	 * Is this a later date in a series?
	 *
	 * @param object $booking Booking.
	 * @return bool
	 */
	public static function is_follower( $booking ) {
		return (int) $booking->series_id && (int) $booking->series_id !== (int) $booking->id;
	}

	/**
	 * What the online payment for this booking should take: the lead covers every unpaid date.
	 *
	 * @param object $booking Booking.
	 * @return float
	 */
	public static function charge_total( $booking ) {
		$total = (float) $booking->amount;
		if ( self::is_lead( $booking ) ) {
			foreach ( self::series( $booking ) as $b ) {
				if ( (int) $b->id !== (int) $booking->id && 'pending_payment' === $b->status ) {
					$total += (float) $b->amount;
				}
			}
		}
		return round( $total, 2 );
	}

	/**
	 * Followers still waiting on the lead's payment.
	 *
	 * @param object $lead Lead.
	 * @return array
	 */
	private static function waiting( $lead ) {
		return array_filter(
			self::series( $lead ),
			static function ( $b ) use ( $lead ) {
				return (int) $b->id !== (int) $lead->id && 'pending_payment' === $b->status;
			}
		);
	}

	/**
	 * Lead paid: so is the rest.
	 *
	 * @param object $booking Booking.
	 * @param string $gateway Gateway.
	 * @param string $txn     Transaction.
	 */
	public static function on_paid( $booking, $gateway = '', $txn = '' ) {
		if ( ! self::is_lead( $booking ) ) {
			return;
		}
		foreach ( self::waiting( $booking ) as $b ) {
			OSB_DB::update( 'bookings', $b->id, array( 'payment_status' => 'paid' ) );
			OSB_Bookings::set_status( $b->id, (string) apply_filters( 'osb_paid_status', 'confirmed', $b ), false );
		}
	}

	/**
	 * Lead's payment failed or the hold was cancelled: the rest go with it.
	 *
	 * @param object $booking Booking.
	 * @param string $old     Old status.
	 * @param string $new     New status.
	 */
	public static function on_status( $booking, $old, $new ) {
		if ( 'pending_payment' !== $old || ! self::is_lead( $booking ) || ! in_array( $new, array( 'payment_failed', 'cancelled' ), true ) ) {
			return;
		}
		foreach ( self::waiting( $booking ) as $b ) {
			OSB_Bookings::set_status( $b->id, $new, false );
		}
	}

	/**
	 * "Every week · 6 dates".
	 *
	 * @param string $rule freq:count.
	 * @param int    $n    Dates actually booked.
	 * @return string
	 */
	public static function rule_label( $rule, $n ) {
		list( $freq ) = array_pad( explode( ':', (string) $rule ), 2, '' );
		$f            = self::freqs()[ $freq ] ?? '';
		/* translators: %d dates */
		return trim( $f . ' · ' . sprintf( _n( '%d date', '%d dates', $n, 'osam-bookings' ), $n ), ' ·' );
	}

	/**
	 * Details.
	 *
	 * @param array  $d       Details.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function details( $d, $booking ) {
		$list = self::series( $booking );
		if ( count( $list ) < 2 ) {
			return $d;
		}
		$labels       = OSB_Bookings::statuses();
		$d['series']  = array();
		$live         = 0;
		$total        = 0;
		foreach ( $list as $b ) {
			$active = ! in_array( $b->status, array( 'cancelled', 'payment_failed' ), true );
			$live  += $active ? 1 : 0;
			$total += $active ? (float) $b->amount : 0;
			$d['series'][] = array(
				'id'      => (int) $b->id,
				'label'   => date_i18n( 'D j M Y, ' . get_option( 'time_format' ), strtotime( $b->start_at ) ),
				'status'  => $labels[ $b->status ] ?? $b->status,
				'active'  => $active,
				'current' => (int) $b->id === (int) $booking->id,
				'token'   => $b->token,
			);
		}
		$d['series_label'] = self::rule_label( $booking->repeat_rule, $live );
		$d['series_count'] = $live;
		$d['series_total'] = OSB_Settings::money( $total, $booking->currency );
		return $d;
	}

	/**
	 * Email rows.
	 *
	 * @param array  $rows    Rows.
	 * @param object $booking Booking.
	 * @param array  $d       Details.
	 * @return array
	 */
	public static function card_rows( $rows, $booking, $d ) {
		if ( empty( $d['series'] ) ) {
			return $rows;
		}
		$dates = array();
		foreach ( $d['series'] as $s ) {
			$dates[] = $s['active'] ? esc_html( $s['label'] ) : '<s style="opacity:.6">' . esc_html( $s['label'] ) . '</s>';
		}
		$rows[] = array( __( 'Repeats', 'osam-bookings' ), esc_html( $d['series_label'] ) . '<div style="margin-top:4px;font-weight:400;line-height:1.6">' . implode( '<br>', $dates ) . '</div>', true );
		if ( self::is_lead( $booking ) && $d['series_count'] > 1 ) {
			$rows[] = array( __( 'All dates', 'osam-bookings' ), $d['series_total'] );
		}
		return $rows;
	}

	/**
	 * {series_dates}, {series_label}.
	 *
	 * @param array  $map     Placeholders.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function placeholders( $map, $booking ) {
		$d                      = self::details( array(), $booking );
		$map['{series_label}']  = esc_html( $d['series_label'] ?? '' );
		$map['{series_dates}']  = isset( $d['series'] ) ? implode( '<br>', array_map( static function ( $s ) { return esc_html( $s['label'] ); }, array_filter( $d['series'], static function ( $s ) { return $s['active']; } ) ) ) : '';
		return $map;
	}

	/**
	 * The "Repeat" control on staff booking forms.
	 */
	public static function admin_field() {
		$opts = array( '' => __( 'Doesn’t repeat', 'osam-bookings' ) ) + self::freqs();
		echo '<div class="osb-field osb-field--half"><label for="osb_rep_freq">' . esc_html__( 'Repeat', 'osam-bookings' ) . '</label>';
		OSB_Admin::input( 'repeat[freq]', '', 'select', $opts, 'osb_rep_freq' );
		echo '</div><div class="osb-field osb-field--quarter"><label for="osb_rep_count">' . esc_html__( 'Dates in total', 'osam-bookings' ) . '</label><input type="number" id="osb_rep_count" name="repeat[count]" min="2" max="' . (int) self::max( true ) . '" value="6"></div>';
	}

	/**
	 * Read the staff control.
	 *
	 * @param array $post Posted values.
	 * @return array|null
	 */
	public static function from_admin_post( array $post ) {
		$r = isset( $post['repeat'] ) && is_array( $post['repeat'] ) ? $post['repeat'] : array();
		return ! empty( $r['freq'] ) ? array( 'freq' => sanitize_key( $r['freq'] ), 'count' => absint( $r['count'] ?? 0 ) ) : null;
	}

	/**
	 * After a staff booking: tell them which dates were skipped, and mark them all paid if asked.
	 *
	 * @param object $booking Lead.
	 * @param bool   $paid    Mark paid.
	 */
	public static function after_admin( $booking, $paid = false ) {
		if ( ! empty( $booking->repeat_skipped ) ) {
			$list = array();
			foreach ( $booking->repeat_skipped as $date => $why ) {
				$list[] = date_i18n( 'D j M', strtotime( $date . ' 12:00:00' ) ) . ' — ' . $why;
			}
			/* translators: %s list of dates */
			OSB_Admin::flash_warnings( array( sprintf( __( 'Not booked: %s', 'osam-bookings' ), implode( '; ', $list ) ) ) );
		}
		if ( $paid && ! empty( $booking->repeat_made ) ) {
			foreach ( $booking->repeat_made as $id ) {
				OSB_DB::update( 'bookings', $id, array( 'payment_status' => 'paid' ) );
			}
		}
	}

	/**
	 * Cancel this date and every later one (customer or staff).
	 *
	 * @param object $booking  From this booking on.
	 * @param bool   $customer Only dates the customer may still cancel.
	 * @return int[] Cancelled ids.
	 */
	public static function cancel_from( $booking, $customer = true ) {
		$done = array();
		foreach ( self::series( $booking ) as $b ) {
			if ( $b->start_at < $booking->start_at || in_array( $b->status, array( 'cancelled', 'payment_failed', 'completed', 'no_show' ), true ) ) {
				continue;
			}
			if ( $customer && ! OSB_Bookings::can_customer_cancel( $b ) ) {
				continue;
			}
			if ( OSB_Bookings::set_status( $b->id, 'cancelled', false ) ) {
				$done[] = (int) $b->id;
			}
		}
		if ( $done ) {
			// One email for the lot.
			$first = OSB_DB::get( 'bookings', $done[0] );
			do_action( 'osb_booking_notify', $first, 'cancelled' );
		}
		return $done;
	}
}
