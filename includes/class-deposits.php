<?php
/**
 * Deposits & part payments (optional — Settings → Features, off by default).
 *
 * How it works:
 * - Services, events, courses and resources can ask for a deposit: a fixed amount per booking or per
 *   person, or a percentage of the price. Each one uses the site-wide default (Settings → Payments)
 *   unless it sets its own, and can let customers choose to pay everything up front instead.
 * - A deposit only applies when the customer pays online. The amount asked for is saved on the booking
 *   (`deposit`) and the online checkout takes just that (OSB_Repeat::charge_total() → due_now()).
 *   Paying on arrival, staff bookings, bundles and free bookings work exactly as before.
 * - Once the deposit is in, the booking's payment status is `part_paid` ("Deposit paid") and
 *   `amount_paid` holds what has been received. The balance (amount − amount_paid) is paid with the
 *   existing "Pay now" link (OSB_Payments), or recorded by staff as paid in person. Then it's `paid`.
 * - Repeat bookings: each date has its own deposit. The first payment takes the deposits for every date;
 *   after that each date shows "Deposit paid" with its own balance, paid date by date (online or in person).
 * - Restaurant tables keep their own deposit settings (Restaurant → Settings), which were always a deposit
 *   off the bill. With this feature on, a paid table deposit shows as "Deposit paid" too, and staff
 *   record the rest of the bill as paid on the night (optionally with its amount, so reports include it).
 * - Cancelling keeps the deposit, as cancelling a paid booking always has: nothing is refunded
 *   automatically and the balance simply stops being due. To give money back, set Payment to "Refunded"
 *   (or credit the invoice): the credit note covers what was actually paid — the deposit, plus any
 *   balance paid since.
 *
 * With the feature switched off nothing here runs for new bookings; bookings already part paid keep
 * showing their balance and can still be settled.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deposits, part-paid bookings and balances.
 */
class OSB_Deposits {

	/**
	 * Booking types that can take a deposit set on the item (tables have their own rules).
	 *
	 * @var string[]
	 */
	const TYPES = array( 'service', 'event', 'course', 'resource' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'osb_booking_insert_data', array( __CLASS__, 'insert_data' ), 20, 2 );
		add_filter( 'osb_booking_details', array( __CLASS__, 'details' ), 16, 2 );
		add_filter( 'osb_details_card_rows', array( __CLASS__, 'card_rows' ), 20, 3 );
		add_filter( 'osb_email_placeholders', array( __CLASS__, 'placeholders' ), 20, 2 );
		add_action( 'osb_admin_booking_side', array( __CLASS__, 'admin_panel' ), 4 );
		add_action( 'admin_post_osb_deposit_balance', array( __CLASS__, 'admin_record' ) );
		add_filter( 'osb_rest_services', array( __CLASS__, 'rest_services' ) );
		add_filter( 'osb_rest_events', array( __CLASS__, 'rest_events' ) );
		add_filter( 'osb_rest_courses', array( __CLASS__, 'rest_courses' ) );
		add_filter( 'osb_booking_js_config', array( __CLASS__, 'js_config' ) );
	}

	/**
	 * Switched on (Settings → Features)?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return OSB_Settings::feature( 'deposits' );
	}

	/**
	 * Have the deposit columns been added yet (first request after an update)?
	 *
	 * @return bool
	 */
	public static function ready() {
		return version_compare( (string) get_option( 'osb_db_version' ), '2.17.0', '>=' );
	}

	/**
	 * Ways to ask for a deposit.
	 *
	 * @param bool $with_default Include "use the site default" (for items).
	 * @return array key => label
	 */
	public static function modes( $with_default = false ) {
		$out = $with_default ? array( '' => __( 'Same as the site default', 'osam-bookings' ) ) : array();
		return $out + array(
			'none'        => __( 'No deposit — the full price is paid when booking', 'osam-bookings' ),
			'per_booking' => __( 'A fixed deposit per booking', 'osam-bookings' ),
			'per_person'  => __( 'A fixed deposit per person', 'osam-bookings' ),
			'percent'     => __( 'A percentage of the price', 'osam-bookings' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Amounts
	 * ------------------------------------------------------------------ */

	/**
	 * A money column on a booking (or sample object), 0 when it isn't there.
	 *
	 * @param object $b   Booking.
	 * @param string $key Column.
	 * @return float
	 */
	private static function num( $b, $key ) {
		return isset( $b->$key ) ? (float) $b->$key : 0.0;
	}

	/**
	 * Is this booking on the deposit path (a deposit was asked for, or it's part paid)?
	 *
	 * @param object $b Booking.
	 * @return bool
	 */
	public static function tracks( $b ) {
		return $b && ( self::num( $b, 'deposit' ) > 0 || ( isset( $b->payment_status ) && 'part_paid' === $b->payment_status ) );
	}

	/**
	 * Money received so far on a part-paid booking.
	 *
	 * @param object $b Booking.
	 * @return float
	 */
	public static function paid( $b ) {
		return round( self::num( $b, 'amount_paid' ), 2 );
	}

	/**
	 * Still to pay on a part-paid booking (0 for anything else, and for tables: the rest of their bill
	 * isn't known until the night).
	 *
	 * @param object $b Booking.
	 * @return float
	 */
	public static function balance( $b ) {
		if ( ! $b || ! isset( $b->payment_status ) || 'part_paid' !== $b->payment_status ) {
			return 0.0;
		}
		return max( 0.0, round( (float) $b->amount - self::paid( $b ), 2 ) );
	}

	/**
	 * Is the balance still owed (the booking hasn't been cancelled or given up on)?
	 *
	 * @param object $b Booking.
	 * @return bool
	 */
	public static function balance_owed( $b ) {
		return self::balance( $b ) > 0 && ! in_array( $b->status, array( 'cancelled', 'payment_failed' ), true );
	}

	/**
	 * A table whose deposit is paid and whose bill is settled on the night.
	 *
	 * @param object $b Booking.
	 * @return bool
	 */
	public static function bill_on_the_night( $b ) {
		return $b && 'table' === $b->type && isset( $b->payment_status ) && 'part_paid' === $b->payment_status && self::balance( $b ) <= 0;
	}

	/**
	 * What the first online payment takes for one booking: its deposit, or the whole amount.
	 * (Used by OSB_Repeat::charge_total(), which every gateway charges.)
	 *
	 * @param object $b Booking.
	 * @return float
	 */
	public static function due_now( $b ) {
		$deposit = self::num( $b, 'deposit' );
		if ( $deposit > 0 && isset( $b->payment_status ) && 'unpaid' === $b->payment_status && self::paid( $b ) <= 0 ) {
			return round( min( $deposit, (float) $b->amount ), 2 );
		}
		return (float) $b->amount;
	}

	/**
	 * The item a booking (or a row about to be saved) is for.
	 *
	 * @param string       $type Booking type.
	 * @param array|object $row  Row about to be saved.
	 * @param array        $data Booking request (for resources).
	 * @return object|null
	 */
	public static function item( $type, $row, array $data = array() ) {
		$row = (array) $row;
		switch ( $type ) {
			case 'service':
				return ! empty( $row['service_id'] ) ? OSB_DB::get( 'services', (int) $row['service_id'] ) : null;
			case 'event':
				return ! empty( $row['event_id'] ) ? OSB_DB::get( 'events', (int) $row['event_id'] ) : null;
			case 'course':
				return ! empty( $row['course_id'] ) ? OSB_DB::get( 'courses', (int) $row['course_id'] ) : null;
			case 'resource':
				// Several resources booked together use the first one's deposit (as they share its payment methods).
				$ids = array_values( array_filter( array_map( 'absint', (array) ( $data['resource_ids'] ?? array() ) ) ) );
				return $ids ? OSB_DB::get( 'resources', $ids[0] ) : null;
		}
		return null;
	}

	/**
	 * The deposit rule for an item: its own, or the site default.
	 *
	 * @param object|null $item Service, event, course or resource.
	 * @return array [ mode none|per_booking|per_person|percent, amount, full (customers may pay in full) ]
	 */
	public static function rule( $item ) {
		$own = $item && isset( $item->deposit_type ) ? (string) $item->deposit_type : '';
		if ( '' === $own ) {
			$mode   = (string) OSB_Settings::get( 'deposit_type', 'none' );
			$amount = (float) OSB_Settings::get( 'deposit_amount', 0 );
			$full   = (bool) OSB_Settings::get( 'deposit_full', 1 );
		} else {
			$mode   = $own;
			$amount = (float) $item->deposit_amount;
			$full   = ! empty( $item->deposit_full );
		}
		if ( ! in_array( $mode, array( 'per_booking', 'per_person', 'percent' ), true ) || $amount <= 0 ) {
			$mode = 'none';
		}
		if ( 'percent' === $mode ) {
			$amount = min( 100, $amount );
		}
		return (array) apply_filters(
			'osb_deposit_rule',
			array(
				'mode'   => $mode,
				'amount' => round( $amount, 2 ),
				'full'   => $full,
			),
			$item
		);
	}

	/**
	 * Deposit for a price under a rule (0 = no deposit: the price is paid in full).
	 *
	 * @param array $rule  From rule().
	 * @param float $total Price of the booking.
	 * @param int   $seats Places.
	 * @return float
	 */
	public static function amount_for( array $rule, $total, $seats = 1 ) {
		$total = round( (float) $total, 2 );
		switch ( $rule['mode'] ) {
			case 'percent':
				$dep = round( $total * (float) $rule['amount'] / 100, 2 );
				break;
			case 'per_person':
				$dep = round( (float) $rule['amount'] * max( 1, (int) $seats ), 2 );
				break;
			case 'per_booking':
				$dep = round( (float) $rule['amount'], 2 );
				break;
			default:
				$dep = 0.0;
		}
		// A deposit as big as the price is just the price.
		return ( $dep > 0 && $dep < $total - 0.004 ) ? $dep : 0.0;
	}

	/**
	 * "20% deposit" / "£10 deposit per person".
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	public static function rule_label( array $rule ) {
		switch ( $rule['mode'] ) {
			case 'percent':
				/* translators: %s percentage */
				return sprintf( __( '%s%% deposit', 'osam-bookings' ), rtrim( rtrim( number_format( (float) $rule['amount'], 2, '.', '' ), '0' ), '.' ) );
			case 'per_person':
				/* translators: %s amount */
				return sprintf( __( '%s deposit per person', 'osam-bookings' ), OSB_Settings::money( $rule['amount'] ) );
			case 'per_booking':
				/* translators: %s amount */
				return sprintf( __( '%s deposit', 'osam-bookings' ), OSB_Settings::money( $rule['amount'] ) );
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Booking lifecycle
	 * ------------------------------------------------------------------ */

	/**
	 * New booking: work out the deposit (customers paying online only).
	 *
	 * @param array $row  Row about to be saved.
	 * @param array $data Booking request.
	 * @return array
	 */
	public static function insert_data( $row, $data ) {
		if ( ! self::enabled() || ! self::ready() || ! empty( $data['admin'] ) || ! empty( $row['pass_id'] ) || (float) ( $row['amount'] ?? 0 ) <= 0 ) {
			return $row;
		}
		$type   = (string) ( $row['type'] ?? '' );
		$online = 'pending_payment' === ( $row['status'] ?? '' ) || ( ! empty( $data['dry_run'] ) && '' === (string) ( $data['payment_method'] ?? '' ) );
		if ( ! $online ) {
			return $row; // Paying on arrival: nothing is taken now, so there's no deposit.
		}
		if ( 'table' === $type ) {
			// Table deposits (Restaurant → Settings) are already a deposit off the bill.
			$row['deposit'] = round( (float) $row['amount'], 2 );
			return $row;
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return $row;
		}
		$rule = self::rule( self::item( $type, $row, $data ) );
		if ( 'none' === $rule['mode'] || ( ! empty( $data['pay_in_full'] ) && $rule['full'] ) ) {
			return $row;
		}
		$deposit = self::amount_for( $rule, (float) $row['amount'], (int) ( $row['seats'] ?? 1 ) );
		if ( $deposit > 0 ) {
			$row['deposit'] = $deposit;
		}
		return $row;
	}

	/**
	 * A payment has come in for a booking on the deposit path (OSB_Bookings::mark_paid()).
	 *
	 * @param object $booking Booking (before the payment).
	 * @param float  $amount  Amount received.
	 * @return array|null Booking fields to save, or null when the booking isn't on the deposit path.
	 */
	public static function after_payment( $booking, $amount ) {
		if ( ! self::tracks( $booking ) ) {
			return null;
		}
		$amount = (float) $amount;
		if ( OSB_Repeat::is_lead( $booking ) && 'unpaid' === $booking->payment_status ) {
			// The first payment of a repeat booking also paid the later dates' deposits (OSB_Repeat::on_paid()).
			foreach ( OSB_Repeat::series( $booking ) as $b ) {
				if ( (int) $b->id !== (int) $booking->id && 'pending_payment' === $b->status ) {
					$amount -= self::due_now( $b );
				}
			}
		}
		$expect = 'unpaid' === $booking->payment_status ? self::due_now( $booking ) : self::balance( $booking );
		if ( $amount + 0.009 < $expect ) {
			OSB_Log::error(
				'payments',
				/* translators: 1: amount received 2: amount expected 3: booking id */
				sprintf( __( 'Received %1$s but expected %2$s for booking #%3$d. It shows the difference as still to pay.', 'osam-bookings' ), OSB_Settings::money( max( 0, $amount ), $booking->currency ), OSB_Settings::money( $expect, $booking->currency ), (int) $booking->id ),
				array( 'booking' => (int) $booking->id )
			);
		}
		$paid = round( self::paid( $booking ) + max( 0, $amount ), 2 );
		return array(
			'amount_paid'    => $paid,
			'payment_status' => self::status_for( $booking, $paid ),
		);
	}

	/**
	 * Payment status once this much has been received.
	 *
	 * @param object $b    Booking.
	 * @param float  $paid Received.
	 * @return string part_paid|paid
	 */
	public static function status_for( $b, $paid ) {
		if ( 'table' === $b->type ) {
			return 'part_paid'; // Paid online = the deposit. The bill is settled on the night.
		}
		return $paid + 0.005 >= (float) $b->amount ? 'paid' : 'part_paid';
	}

	/**
	 * A later date of a repeat booking whose deposit was taken with the first date's payment.
	 *
	 * @param object $b Follower booking (still waiting for that payment).
	 * @return array Fields to save.
	 */
	public static function follower_paid( $b ) {
		$paid = self::due_now( $b );
		return array(
			'amount_paid'    => $paid,
			'payment_status' => self::status_for( $b, $paid ),
		);
	}

	/**
	 * Staff record a payment taken in person (the balance, or part of it; for a table, the rest of the bill).
	 *
	 * @param object $booking Booking.
	 * @param float  $amount  Amount (0 = the whole balance; for tables, the rest of the bill if known).
	 * @param bool   $email   Email the invoice (if invoices are set to be emailed).
	 * @return object|WP_Error Updated booking.
	 */
	public static function record_payment( $booking, $amount = 0.0, $email = true ) {
		$b = $booking ? OSB_DB::get( 'bookings', (int) $booking->id ) : null;
		if ( ! $b || 'part_paid' !== $b->payment_status ) {
			return new WP_Error( 'osb_not_part_paid', __( 'This booking has no balance to record.', 'osam-bookings' ) );
		}
		$amount  = round( max( 0, (float) $amount ), 2 );
		$night   = self::bill_on_the_night( $b );
		$balance = self::balance( $b );
		if ( ! $night ) {
			$amount = $amount > 0 ? min( $amount, $balance ) : $balance;
		}
		$total  = $night ? round( (float) $b->amount + $amount, 2 ) : (float) $b->amount;
		$paid   = round( self::paid( $b ) + $amount, 2 );
		$fields = array(
			'amount_paid'    => $paid,
			'payment_status' => $night || $paid + 0.005 >= $total ? 'paid' : 'part_paid',
			'updated_at'     => current_time( 'mysql' ),
		);
		if ( $night ) {
			$fields['amount'] = $total; // The rest of the bill is added, so reports and invoices show what was spent.
		}
		$pid = 0;
		if ( $amount > 0 ) {
			$pid = OSB_DB::add_payment( $b->id, 'manual', 'admin-' . get_current_user_id() . '-' . time(), $amount, $b->currency, 'completed', array( 'by' => get_current_user_id(), 'balance' => true ) );
		}
		if ( ! OSB_DB::update( 'bookings', $b->id, $fields ) ) {
			OSB_Log::error( 'payments', __( 'Could not save the balance payment.', 'osam-bookings' ), array( 'booking' => (int) $b->id ) );
			return new WP_Error( 'osb_db', __( 'Could not save the payment. Please try again.', 'osam-bookings' ) );
		}
		$fresh = OSB_DB::get( 'bookings', $b->id );
		if ( $pid && class_exists( 'OSB_Invoices' ) ) {
			OSB_Invoices::on_manual_payment( $fresh, (int) $pid, $email );
		}
		do_action( 'osb_booking_balance_recorded', $fresh, $amount );
		do_action( 'osb_booking_admin_updated', $fresh, $b );
		return $fresh;
	}

	/* ---------------------------------------------------------------------
	 * What customers and staff see
	 * ------------------------------------------------------------------ */

	/**
	 * Payment statuses for a staff "Payment" dropdown: "Deposit paid" only on a booking that already is.
	 *
	 * @param string $current Current status.
	 * @return array
	 */
	public static function status_choices( $current = '' ) {
		$all = OSB_Bookings::payment_statuses();
		if ( 'part_paid' !== $current ) {
			unset( $all['part_paid'] );
		}
		return $all;
	}

	/**
	 * " · £70 to pay" after the payment status in admin lists.
	 *
	 * @param object $b Booking.
	 * @return string
	 */
	public static function list_suffix( $b ) {
		if ( ! isset( $b->payment_status ) || 'part_paid' !== $b->payment_status ) {
			return '';
		}
		if ( self::bill_on_the_night( $b ) ) {
			return ' · ' . __( 'rest of the bill on the night', 'osam-bookings' );
		}
		/* translators: %s amount */
		return self::balance_owed( $b ) ? ' · ' . sprintf( __( '%s to pay', 'osam-bookings' ), OSB_Settings::money( self::balance( $b ), $b->currency ) ) : '';
	}

	/**
	 * Booking details: deposit, paid so far, balance.
	 *
	 * @param array  $d       Details.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function details( $d, $booking ) {
		if ( ! self::tracks( $booking ) ) {
			return $d;
		}
		$cur = $booking->currency;
		if ( 'part_paid' === $booking->payment_status ) {
			$d['deposit_paid'] = OSB_Settings::money( self::paid( $booking ), $cur );
			if ( self::bill_on_the_night( $booking ) ) {
				$d['balance_note'] = __( 'The rest of the bill is paid on the night.', 'osam-bookings' );
			} elseif ( self::balance_owed( $booking ) ) {
				$d['balance_due'] = OSB_Settings::money( self::balance( $booking ), $cur );
			}
		} elseif ( 'unpaid' === $booking->payment_status && self::num( $booking, 'deposit' ) > 0 && ! in_array( $booking->status, array( 'cancelled', 'payment_failed' ), true ) ) {
			$d['deposit_due'] = OSB_Settings::money( self::due_now( $booking ), $cur );
		}
		return $d;
	}

	/**
	 * Email details card: what has been paid and what's left.
	 *
	 * @param array  $rows    Rows.
	 * @param object $booking Booking.
	 * @param array  $d       Details.
	 * @return array
	 */
	public static function card_rows( $rows, $booking, $d ) {
		if ( ! empty( $d['deposit_paid'] ) ) {
			$rows[] = array( __( 'Deposit paid', 'osam-bookings' ), $d['deposit_paid'] );
		}
		if ( ! empty( $d['balance_due'] ) ) {
			$due  = OSB_Payments::payable( $booking );
			$link = $due > 0 ? ' · <a href="' . esc_url( OSB_Payments::pay_url( $booking ) ) . '" style="color:inherit;text-decoration:underline">' . esc_html__( 'Pay online', 'osam-bookings' ) . '</a>' : '';
			$rows[] = array( __( 'Balance to pay', 'osam-bookings' ), esc_html( $d['balance_due'] ) . $link, true );
		} elseif ( ! empty( $d['balance_note'] ) ) {
			$rows[] = array( __( 'Rest of the bill', 'osam-bookings' ), __( 'Paid on the night', 'osam-bookings' ) );
		} elseif ( ! empty( $d['deposit_due'] ) ) {
			$rows[] = array( __( 'Deposit to pay now', 'osam-bookings' ), $d['deposit_due'] );
		}
		return $rows;
	}

	/**
	 * {deposit_paid} and {balance_due}.
	 *
	 * @param array  $map     Placeholders.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function placeholders( $map, $booking ) {
		$part                  = self::tracks( $booking ) && 'part_paid' === $booking->payment_status;
		$map['{deposit_paid}'] = $part ? esc_html( OSB_Settings::money( self::paid( $booking ), $booking->currency ) ) : '';
		$map['{balance_due}']  = $part && self::balance_owed( $booking ) ? esc_html( OSB_Settings::money( self::balance( $booking ), $booking->currency ) ) : '';
		return $map;
	}

	/**
	 * Placeholders to list in the email editors (only while the feature is on).
	 *
	 * @return string[]
	 */
	public static function tags() {
		return self::enabled() ? array( '{deposit_paid}', '{balance_due}' ) : array();
	}

	/**
	 * Admin booking screen: the deposit, what's been paid, and recording the balance.
	 *
	 * @param object $b Booking.
	 */
	public static function admin_panel( $b ) {
		if ( ! self::tracks( $b ) ) {
			return;
		}
		$cur   = $b->currency;
		$night = self::bill_on_the_night( $b );
		$rows  = array(
			array( 'table' === $b->type ? __( 'Deposit asked for', 'osam-bookings' ) : __( 'Price', 'osam-bookings' ), OSB_Settings::money( 'table' === $b->type ? self::num( $b, 'deposit' ) : $b->amount, $cur ) ),
		);
		if ( 'table' !== $b->type && self::num( $b, 'deposit' ) > 0 ) {
			$rows[] = array( __( 'Deposit asked for', 'osam-bookings' ), OSB_Settings::money( self::num( $b, 'deposit' ), $cur ) );
		}
		if ( in_array( $b->payment_status, array( 'part_paid', 'paid' ), true ) ) {
			$rows[] = array( __( 'Paid so far', 'osam-bookings' ), OSB_Settings::money( 'paid' === $b->payment_status && self::paid( $b ) <= 0 ? $b->amount : self::paid( $b ), $cur ) );
		}
		if ( $night ) {
			$rows[] = array( __( 'Rest of the bill', 'osam-bookings' ), __( 'Paid on the night', 'osam-bookings' ) );
		} elseif ( 'part_paid' === $b->payment_status ) {
			$rows[] = array( __( 'Balance to pay', 'osam-bookings' ), self::balance_owed( $b ) ? OSB_Settings::money( self::balance( $b ), $cur ) : __( 'Not due — booking cancelled', 'osam-bookings' ) );
		}
		echo '<div class="osb-card osb-deposit-card">';
		OSB_Admin::card_head( __( 'Deposit', 'osam-bookings' ) );
		echo '<dl class="osb-dl">';
		foreach ( $rows as $r ) {
			echo '<dt>' . esc_html( $r[0] ) . '</dt><dd>' . esc_html( $r[1] ) . '</dd>';
		}
		echo '</dl>';
		if ( 'part_paid' === $b->payment_status && ( self::balance_owed( $b ) || $night ) ) {
			$due = self::balance( $b );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form osb-deposit-form">
				<?php wp_nonce_field( 'osb_deposit_balance_' . (int) $b->id ); ?>
				<input type="hidden" name="action" value="osb_deposit_balance">
				<input type="hidden" name="id" value="<?php echo (int) $b->id; ?>">
				<div class="osb-field">
					<label for="osb_dep_amount"><?php echo $night ? esc_html__( 'Rest of the bill paid (optional)', 'osam-bookings' ) : esc_html__( 'Amount paid in person', 'osam-bookings' ); ?></label>
					<input type="number" id="osb_dep_amount" name="amount" min="0" step="0.01" class="small-text" value="<?php echo $night ? '' : esc_attr( number_format( $due, 2, '.', '' ) ); ?>"<?php echo $night ? '' : ' max="' . esc_attr( number_format( $due, 2, '.', '' ) ) . '"'; ?>>
					<p class="osb-help"><?php echo $night ? esc_html__( 'Add what the rest of the bill came to if you’d like it in your reports and on the invoice. Leave it empty to just mark it settled.', 'osam-bookings' ) : esc_html__( 'Less than the balance is fine — the rest stays to pay.', 'osam-bookings' ); ?></p>
				</div>
				<label class="osb-switch"><input type="checkbox" name="notify" value="1" checked><span></span><?php esc_html_e( 'Email the customer a receipt', 'osam-bookings' ); ?></label>
				<div class="osb-formfoot"><button class="osb-btn osb-btn--primary osb-btn--sm"><span class="dashicons dashicons-money-alt"></span><?php echo $night ? esc_html__( 'Mark the bill as paid', 'osam-bookings' ) : esc_html__( 'Record payment', 'osam-bookings' ); ?></button></div>
			</form>
			<?php
		}
		if ( in_array( $b->status, array( 'cancelled', 'payment_failed' ), true ) && 'part_paid' === $b->payment_status ) {
			/* translators: %s amount */
			echo '<p class="osb-help">' . esc_html( sprintf( __( 'The deposit of %s has been kept. To give it back, refund it with your payment provider, then set Payment to “Refunded” — a credit note is issued for what was paid.', 'osam-bookings' ), OSB_Settings::money( self::paid( $b ), $cur ) ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Staff: record the balance (admin-post).
	 */
	public static function admin_record() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		OSB_Admin::verify( 'osb_deposit_balance_' . $id );
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$amount = isset( $post['amount'] ) ? round( (float) str_replace( ',', '.', (string) $post['amount'] ), 2 ) : 0;
		$done   = self::record_payment( OSB_DB::get( 'bookings', $id ), $amount, ! empty( $post['notify'] ) );
		if ( is_wp_error( $done ) ) {
			OSB_Admin::flash_error( $done->get_error_message() );
		}
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $id ), is_wp_error( $done ) ? 'none' : 'saved' );
	}

	/* ---------------------------------------------------------------------
	 * Balance reminder (from the 5-minute job)
	 * ------------------------------------------------------------------ */

	/**
	 * "Balance due" emails a few days before bookings that only have their deposit paid.
	 */
	public static function send_balance_reminders() {
		if ( ! self::enabled() || ! self::ready() ) {
			return;
		}
		$days = (float) OSB_Settings::get( 'deposit_reminder_days' );
		$tpls = OSB_Settings::get( 'emails', array() );
		if ( $days <= 0 || empty( $tpls['customer_balance_due']['enabled'] ) ) {
			return;
		}
		global $wpdb;
		$t     = OSB_Install::table( 'bookings' );
		$now   = current_time( 'mysql' );
		$until = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + (int) ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE payment_status = 'part_paid' AND status IN ('confirmed','pending') AND type <> 'table' AND balance_reminder_sent = 0 AND amount > amount_paid AND start_at > %s AND start_at <= %s LIMIT 100", $now, $until ) );
		foreach ( (array) $rows as $b ) {
			OSB_DB::update( 'bookings', $b->id, array( 'balance_reminder_sent' => 1 ) );
			if ( ! is_email( $b->customer_email ) || ! self::balance_owed( $b ) ) {
				continue;
			}
			// Booked inside the window: their confirmation has just told them what's left to pay.
			if ( strtotime( $b->start_at ) - strtotime( $b->created_at ) < $days * DAY_IN_SECONDS ) {
				continue;
			}
			if ( ! OSB_Emails::send( 'customer_balance_due', $b, $b->customer_email ) ) {
				OSB_Log::error( 'payments', __( 'The “Balance due” email could not be sent.', 'osam-bookings' ), array( 'booking' => (int) $b->id ) );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Settings and item screens
	 * ------------------------------------------------------------------ */

	/**
	 * Site default card (Settings → Payments).
	 *
	 * @param array $s Settings.
	 */
	public static function settings_card( $s ) {
		if ( ! self::enabled() ) {
			return;
		}
		$mode = isset( $s['deposit_type'] ) ? (string) $s['deposit_type'] : 'none';
		?>
		<div class="osb-card osb-deposit-settings" id="osb-deposits">
			<?php OSB_Admin::card_head( __( 'Deposits & part payments', 'osam-bookings' ), __( 'When customers pay online, take a deposit now and the rest later. Each service, event, course and resource uses this unless you set its own deposit there. Restaurant tables have their own deposit settings.', 'osam-bookings' ) ); ?>
			<div class="osb-fieldgrid">
				<div class="osb-field osb-field--half"><label for="osb_deposit_type"><?php esc_html_e( 'Deposit', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'deposit_type', $mode, 'select', self::modes(), 'osb_deposit_type' ); ?></div>
				<div class="osb-field osb-field--quarter"><label for="osb_deposit_amount"><?php esc_html_e( 'Amount (or %)', 'osam-bookings' ); ?></label><input type="number" id="osb_deposit_amount" name="deposit_amount" min="0" step="0.01" class="small-text" value="<?php echo esc_attr( $s['deposit_amount'] ?? 0 ); ?>"></div>
				<div class="osb-field osb-field--quarter"><label for="osb_deposit_reminder_days"><?php esc_html_e( 'Balance reminder (days before)', 'osam-bookings' ); ?></label><input type="number" id="osb_deposit_reminder_days" name="deposit_reminder_days" min="0" max="60" step="1" class="small-text" value="<?php echo esc_attr( $s['deposit_reminder_days'] ?? 3 ); ?>"></div>
			</div>
			<div class="osb-field"><label class="osb-switch"><input type="hidden" name="deposit_full" value="0"><input type="checkbox" name="deposit_full" value="1" <?php checked( ! empty( $s['deposit_full'] ) ); ?>><span></span><?php esc_html_e( 'Let customers choose to pay in full instead', 'osam-bookings' ); ?></label></div>
			<p class="osb-help"><?php esc_html_e( 'Deposits are only taken when someone pays online — paying on arrival works as before. The balance can be paid with the “Pay now” link in their emails, invoice and My bookings, or you can record it as paid in person on the booking. Set the reminder to 0 to switch the “Balance due” email off.', 'osam-bookings' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Values from the Payments tab.
	 *
	 * @param array $post Posted values.
	 * @return array
	 */
	public static function settings_values( array $post ) {
		if ( ! isset( $post['deposit_type'] ) ) {
			return array(); // The card wasn't on the page (feature off): keep what's saved.
		}
		$mode = sanitize_key( (string) $post['deposit_type'] );
		$amt  = max( 0, round( (float) str_replace( ',', '.', (string) ( $post['deposit_amount'] ?? 0 ) ), 2 ) );
		return array(
			'deposit_type'          => isset( self::modes()[ $mode ] ) ? $mode : 'none',
			'deposit_amount'        => 'percent' === $mode ? min( 100, $amt ) : $amt,
			'deposit_full'          => ! empty( $post['deposit_full'] ) ? 1 : 0,
			'deposit_reminder_days' => max( 0, min( 60, (int) ( $post['deposit_reminder_days'] ?? 0 ) ) ),
		);
	}

	/**
	 * Deposit fields on a service, event, course or resource screen (only while the feature is on).
	 *
	 * @param object $item Item being edited.
	 */
	public static function item_fields( $item ) {
		if ( ! self::enabled() ) {
			return;
		}
		$mode    = isset( $item->deposit_type ) ? (string) $item->deposit_type : '';
		$default = self::rule( null );
		$modes   = self::modes( true );
		/* translators: %s e.g. "20% deposit" */
		$modes[''] = sprintf( __( 'Same as the site default (%s)', 'osam-bookings' ), 'none' === $default['mode'] ? __( 'no deposit', 'osam-bookings' ) : self::rule_label( $default ) );
		?>
		<div class="osb-subsection osb-deposit-fields">
			<h3><?php esc_html_e( 'Deposit', 'osam-bookings' ); ?></h3>
			<div class="osb-fieldgrid">
				<div class="osb-field osb-field--half"><label for="osb_item_deposit_type"><?php esc_html_e( 'When paying online', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'deposit_type', $mode, 'select', $modes, 'osb_item_deposit_type' ); ?></div>
				<div class="osb-field osb-field--quarter"><label for="osb_item_deposit_amount"><?php esc_html_e( 'Amount (or %)', 'osam-bookings' ); ?></label><input type="number" id="osb_item_deposit_amount" name="deposit_amount" min="0" step="0.01" class="small-text" value="<?php echo esc_attr( isset( $item->deposit_amount ) ? (float) $item->deposit_amount : 0 ); ?>"></div>
			</div>
			<label class="osb-switch"><input type="hidden" name="deposit_full" value="0"><input type="checkbox" name="deposit_full" value="1" <?php checked( ! isset( $item->deposit_full ) || ! empty( $item->deposit_full ) ); ?>><span></span><?php esc_html_e( 'Let customers choose to pay in full instead', 'osam-bookings' ); ?></label>
			<p class="osb-help"><?php esc_html_e( 'The amount and the “pay in full” choice are used when this has its own deposit. Settings → Payments has the site default.', 'osam-bookings' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Values from an item screen (nothing when the feature is off, so saved values are kept).
	 *
	 * @param array $post Posted values.
	 * @return array
	 */
	public static function from_post( array $post ) {
		if ( ! self::enabled() || ! self::ready() || ! isset( $post['deposit_type'] ) ) {
			return array();
		}
		$mode = sanitize_key( (string) $post['deposit_type'] );
		$mode = isset( self::modes( true )[ $mode ] ) ? $mode : '';
		$amt  = max( 0, round( (float) str_replace( ',', '.', (string) ( $post['deposit_amount'] ?? 0 ) ), 2 ) );
		return array(
			'deposit_type'   => $mode,
			'deposit_amount' => 'percent' === $mode ? min( 100, $amt ) : $amt,
			'deposit_full'   => ! empty( $post['deposit_full'] ) ? 1 : 0,
		);
	}

	/* ---------------------------------------------------------------------
	 * Booking form
	 * ------------------------------------------------------------------ */

	/**
	 * Deposit rule for the booking form (null = none).
	 *
	 * @param object|null $item Item.
	 * @return array|null
	 */
	public static function rule_json( $item ) {
		if ( ! self::enabled() || ! $item ) {
			return null;
		}
		$rule = self::rule( $item );
		if ( 'none' === $rule['mode'] ) {
			return null;
		}
		return array(
			'mode'   => $rule['mode'],
			'amount' => (float) $rule['amount'],
			'full'   => (bool) $rule['full'],
			'label'  => self::rule_label( $rule ),
		);
	}

	/**
	 * Add each item's deposit to a REST list.
	 *
	 * @param array  $list  Items.
	 * @param string $table Table.
	 * @param string $key   Id key in each entry.
	 * @return array
	 */
	private static function add_rules( $list, $table, $key ) {
		if ( ! self::enabled() || ! is_array( $list ) ) {
			return $list;
		}
		foreach ( $list as $i => $row ) {
			if ( is_array( $row ) && ! empty( $row[ $key ] ) ) {
				$list[ $i ]['deposit'] = self::rule_json( OSB_DB::get( $table, (int) $row[ $key ] ) );
			}
		}
		return $list;
	}

	/**
	 * GET /services.
	 *
	 * @param array $list Services.
	 * @return array
	 */
	public static function rest_services( $list ) {
		return self::add_rules( $list, 'services', 'id' );
	}

	/**
	 * GET /events.
	 *
	 * @param array $list Events.
	 * @return array
	 */
	public static function rest_events( $list ) {
		return self::add_rules( $list, 'events', 'id' );
	}

	/**
	 * GET /courses.
	 *
	 * @param array $list Intakes.
	 * @return array
	 */
	public static function rest_courses( $list ) {
		return self::add_rules( $list, 'courses', 'course_id' );
	}

	/**
	 * Booking form wording (only while the feature is on).
	 *
	 * @param array $cfg Config.
	 * @return array
	 */
	public static function js_config( $cfg ) {
		if ( ! self::enabled() ) {
			return $cfg;
		}
		$cfg['deposits'] = true;
		$cfg['i18n']     = array_merge(
			(array) ( $cfg['i18n'] ?? array() ),
			array(
				/* translators: %s amount */
				'depPayDeposit' => __( 'Pay a deposit of %s now', 'osam-bookings' ),
				/* translators: %s amount */
				'depRestLater'  => __( 'The rest (%s) is paid later — online or when you arrive.', 'osam-bookings' ),
				/* translators: %s amount */
				'depPayFull'    => __( 'Pay the full %s now', 'osam-bookings' ),
				'depHowMuch'    => __( 'How much to pay now', 'osam-bookings' ),
				'depositNow'    => __( 'Deposit to pay now', 'osam-bookings' ),
				'depLater'      => __( 'Left to pay later', 'osam-bookings' ),
				'depositPaid'   => __( 'Deposit paid', 'osam-bookings' ),
				'balanceDue'    => __( 'Balance to pay', 'osam-bookings' ),
				'restOfBill'    => __( 'Rest of the bill', 'osam-bookings' ),
				'onTheNight'    => __( 'Paid on the night', 'osam-bookings' ),
			)
		);
		return $cfg;
	}

	/**
	 * Money received, as SQL (reports and customer totals): everything for paid bookings, what's come in
	 * for part-paid ones.
	 *
	 * @return string
	 */
	public static function sql_received() {
		return self::ready()
			? "CASE WHEN payment_status = 'paid' THEN amount WHEN payment_status = 'part_paid' THEN amount_paid ELSE 0 END"
			: "CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END";
	}
}
