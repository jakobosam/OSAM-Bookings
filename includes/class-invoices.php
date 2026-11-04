<?php
/**
 * Invoices and credit notes: created when a booking is paid, attached to the confirmation email,
 * downloadable by the customer and listed under Bookings → Invoices.
 *
 * Issued documents never change their amounts. Refunds are recorded as credit notes against the
 * original invoice. Business details are copied onto each document when it's issued, so changing
 * the settings later doesn't alter invoices already sent.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Invoice lifecycle, numbering, PDF and delivery.
 */
class OSB_Invoices {

	/**
	 * Invoice made in this request for a booking: [ booking_id => invoice ] (so the confirmation sent
	 * straight after the payment carries it and no separate invoice email goes out).
	 *
	 * @var array
	 */
	private static $fresh = array();

	/**
	 * Document to attach to the email being sent right now (credit note / invoice emails).
	 *
	 * @var object|null
	 */
	public static $attach = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'osb_booking_paid', array( __CLASS__, 'on_paid' ), 5, 3 );
		add_action( 'osb_table_noshow_charged', array( __CLASS__, 'on_noshow_charged' ), 10, 2 );
		add_filter( 'osb_email_args', array( __CLASS__, 'attach_to_email' ), 10, 3 );
		add_filter( 'osb_email_placeholders', array( __CLASS__, 'placeholders' ), 10, 2 );
		add_filter( 'osb_booking_details', array( __CLASS__, 'details' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'public_download' ), 1 );
		add_action( 'admin_post_osb_invoice_pdf', array( __CLASS__, 'admin_download' ) );
	}

	/**
	 * Switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return OSB_Settings::feature( 'invoices' );
	}

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'paid'          => __( 'Paid', 'osam-bookings' ),
			'part_credited' => __( 'Part credited', 'osam-bookings' ),
			'credited'      => __( 'Credited', 'osam-bookings' ),
			'issued'        => __( 'Issued', 'osam-bookings' ), // Credit notes.
		);
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function t() {
		return OSB_Install::table( 'invoices' );
	}

	/**
	 * One document, decoded.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * By public token.
	 *
	 * @param string $token Token.
	 * @return object|null
	 */
	public static function by_token( $token ) {
		global $wpdb;
		if ( strlen( (string) $token ) < 20 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE token = %s', $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Decode JSON columns.
	 *
	 * @param object $row Row.
	 * @return object
	 */
	private static function decode( $row ) {
		$row->lines       = json_decode( (string) $row->items, true );
		$row->lines       = is_array( $row->lines ) ? $row->lines : array();
		$row->seller      = json_decode( (string) $row->seller, true );
		$row->seller      = is_array( $row->seller ) ? $row->seller : array();
		$row->booking_ids = array_filter( array_map( 'intval', explode( ',', (string) $row->booking_ids ) ) );
		return $row;
	}

	/**
	 * Documents for a booking (its own, or the series invoice it's part of), oldest first.
	 *
	 * @param object|int $booking Booking or id.
	 * @param string     $kind    invoice|credit_note|'' (both).
	 * @return array
	 */
	public static function for_booking( $booking, $kind = '' ) {
		global $wpdb;
		$booking = is_object( $booking ) ? $booking : OSB_DB::get( 'bookings', (int) $booking );
		if ( ! $booking || ! self::table_ready() ) {
			return array();
		}
		$ids = array( (int) $booking->id );
		if ( ! empty( $booking->series_id ) ) {
			$ids[] = (int) $booking->series_id;
		}
		$ids  = array_unique( $ids );
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$like = '%,' . (int) $booking->id . ',%';
		$sql  = 'SELECT * FROM ' . self::t() . " WHERE ( booking_id IN ($in) AND ( booking_id = %d OR CONCAT(',', booking_ids, ',') LIKE %s ) )";
		$args = array( (int) $booking->id, $like );
		if ( $kind ) {
			$sql   .= ' AND kind = %s';
			$args[] = $kind;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id ASC', $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'decode' ), (array) $rows );
	}

	/**
	 * Credit notes against an invoice.
	 *
	 * @param int $invoice_id Invoice.
	 * @return array
	 */
	public static function credit_notes( $invoice_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . " WHERE kind = 'credit_note' AND parent_id = %d ORDER BY id ASC", $invoice_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'decode' ), (array) $rows );
	}

	/**
	 * How much of an invoice hasn't been credited yet.
	 *
	 * @param object $invoice Invoice.
	 * @return float
	 */
	public static function remaining( $invoice ) {
		$credited = 0;
		foreach ( self::credit_notes( $invoice->id ) as $cn ) {
			$credited += (float) $cn->total;
		}
		return max( 0, round( (float) $invoice->total - $credited, 2 ) );
	}

	/**
	 * Has the table been created yet (first request after an update)?
	 *
	 * @return bool
	 */
	public static function table_ready() {
		return version_compare( (string) get_option( 'osb_db_version' ), '2.13.0', '>=' );
	}

	/**
	 * List with filters (admin screen and exports).
	 *
	 * @param array $f search, kind, status, method, from, to, orderby, order, page, per_page (0 = all).
	 * @return array [ rows, total count, sums ]
	 */
	public static function query( array $f ) {
		global $wpdb;
		$f     = wp_parse_args( $f, array( 'search' => '', 'kind' => '', 'status' => '', 'method' => '', 'from' => '', 'to' => '', 'orderby' => 'issued', 'order' => 'desc', 'page' => 1, 'per_page' => 50, 'ids' => array() ) );
		$where = array( '1=1' );
		$args  = array();
		if ( '' !== $f['search'] ) {
			$like    = '%' . $wpdb->esc_like( $f['search'] ) . '%';
			$where[] = '( number LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR bill_to LIKE %s OR payment_ref LIKE %s OR booking_id = %d OR CONCAT(\',\', booking_ids, \',\') LIKE %s )';
			array_push( $args, $like, $like, $like, $like, $like, (int) preg_replace( '/\D/', '', $f['search'] ), '%,' . (int) preg_replace( '/\D/', '', $f['search'] ) . ',%' );
		}
		if ( in_array( $f['kind'], array( 'invoice', 'credit_note' ), true ) ) {
			$where[] = 'kind = %s';
			$args[]  = $f['kind'];
		}
		if ( isset( self::statuses()[ $f['status'] ] ) ) {
			$where[] = 'status = %s';
			$args[]  = $f['status'];
		}
		if ( '' !== $f['method'] ) {
			$where[] = 'payment_method = %s';
			$args[]  = $f['method'];
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['from'] ) ) {
			$where[] = 'issued_at >= %s';
			$args[]  = $f['from'] . ' 00:00:00';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['to'] ) ) {
			$where[] = 'issued_at <= %s';
			$args[]  = $f['to'] . ' 23:59:59';
		}
		if ( $f['ids'] ) {
			$where[] = 'id IN (' . implode( ',', array_map( 'intval', (array) $f['ids'] ) ) . ')';
		}
		$cols  = array(
			'number'   => 'kind, seq',
			'issued'   => 'issued_at',
			'customer' => 'customer_name',
			'total'    => 'total',
		);
		$order = 'asc' === $f['order'] ? 'ASC' : 'DESC';
		$by    = isset( $cols[ $f['orderby'] ] ) ? $cols[ $f['orderby'] ] : 'issued_at';
		$sqlw  = implode( ' AND ', $where );
		$prep  = static function ( $sql ) use ( $wpdb, $args ) {
			return $args ? $wpdb->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		};
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sums  = $wpdb->get_row( $prep( "SELECT COUNT(*) AS n, SUM(CASE WHEN kind = 'invoice' THEN total ELSE 0 END) AS invoiced, SUM(CASE WHEN kind = 'credit_note' THEN total ELSE 0 END) AS credited, SUM(CASE WHEN kind = 'invoice' THEN vat_amount ELSE -vat_amount END) AS vat FROM " . self::t() . " WHERE $sqlw" ) );
		$limit = $f['per_page'] ? sprintf( ' LIMIT %d OFFSET %d', (int) $f['per_page'], ( max( 1, (int) $f['page'] ) - 1 ) * (int) $f['per_page'] ) : '';
		$rows  = $wpdb->get_results( $prep( 'SELECT * FROM ' . self::t() . " WHERE $sqlw ORDER BY $by $order, id $order" . $limit ) );
		// phpcs:enable
		return array(
			array_map( array( __CLASS__, 'decode' ), (array) $rows ),
			$sums ? (int) $sums->n : 0,
			array(
				'invoiced' => $sums ? (float) $sums->invoiced : 0,
				'credited' => $sums ? (float) $sums->credited : 0,
				'vat'      => $sums ? (float) $sums->vat : 0,
			),
		);
	}

	/**
	 * Payment methods used on invoices (for the filter).
	 *
	 * @return array id => label
	 */
	public static function methods_used() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT DISTINCT payment_method, payment_label FROM ' . self::t() . " WHERE payment_method <> '' ORDER BY payment_label" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[ $r->payment_method ] = $r->payment_label ? $r->payment_label : $r->payment_method;
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Creating
	 * ------------------------------------------------------------------ */

	/**
	 * Online payment recorded (OSB_Bookings::mark_paid calls this before the confirmation goes out).
	 *
	 * @param object $booking    Booking (before its status changes).
	 * @param int    $payment_id Payment row.
	 * @return object|null Invoice.
	 */
	public static function on_payment( $booking, $payment_id ) {
		$inv = self::create_for_payment( $booking, (int) $payment_id );
		if ( $inv ) {
			self::$fresh[ (int) $booking->id ] = $inv;
		}
		return $inv;
	}

	/**
	 * After any payment (safety net — e.g. a third-party gateway that only fires the action).
	 *
	 * @param object $booking Booking.
	 * @param string $gateway Gateway.
	 * @param string $txn     Transaction.
	 */
	public static function on_paid( $booking, $gateway = '', $txn = '' ) {
		if ( ! $booking || isset( self::$fresh[ (int) $booking->id ] ) || ! self::enabled() ) {
			return;
		}
		$payment = self::find_payment( $booking->id, $gateway, $txn );
		if ( $payment && ! self::for_payment( $payment->id ) ) {
			$inv = self::create_for_payment( $booking, (int) $payment->id );
			if ( $inv ) {
				self::maybe_email_later( $inv );
			}
		}
	}

	/**
	 * Staff marked a booking paid (manual payment row just added).
	 *
	 * @param object $booking    Booking.
	 * @param int    $payment_id Payment row.
	 * @param bool   $email      Email it to the customer (if that setting is on).
	 * @return object|null
	 */
	public static function on_manual_payment( $booking, $payment_id, $email = true ) {
		$inv = self::create_for_payment( $booking, (int) $payment_id );
		if ( $inv && $email ) {
			self::maybe_email_later( $inv );
		}
		return $inv;
	}

	/**
	 * Restaurant no-show fee charged to the saved card.
	 *
	 * @param object $booking Booking.
	 * @param float  $amount  Amount.
	 */
	public static function on_noshow_charged( $booking, $amount ) {
		if ( ! self::enabled() ) {
			return;
		}
		$payments = OSB_DB::payments( $booking->id );
		$last     = $payments ? end( $payments ) : null;
		if ( ! $last || self::for_payment( $last->id ) ) {
			return;
		}
		$inv = self::create_for_payment(
			$booking,
			(int) $last->id,
			array(
				array(
					'desc'   => __( 'No-show fee', 'osam-bookings' ),
					'detail' => self::booking_line_detail( $booking ),
					'qty'    => 1,
					'unit'   => (float) $amount,
					'amount' => (float) $amount,
				),
			)
		);
		if ( $inv ) {
			self::maybe_email_later( $inv );
		}
	}

	/**
	 * Find the payment row a gateway/transaction refers to.
	 *
	 * @param int    $booking_id Booking.
	 * @param string $gateway    Gateway.
	 * @param string $txn        Transaction.
	 * @return object|null
	 */
	private static function find_payment( $booking_id, $gateway, $txn ) {
		$found = null;
		foreach ( (array) OSB_DB::payments( $booking_id ) as $p ) {
			if ( 'completed' !== $p->status || (float) $p->amount <= 0 ) {
				continue;
			}
			if ( '' === (string) $txn || ( $p->gateway === $gateway && $p->transaction_id === $txn ) ) {
				$found = $p;
			}
		}
		return $found;
	}

	/**
	 * Invoice already made for a payment?
	 *
	 * @param int $payment_id Payment.
	 * @return int Invoice id or 0.
	 */
	public static function for_payment( $payment_id ) {
		global $wpdb;
		if ( ! self::table_ready() ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t() . " WHERE kind = 'invoice' AND payment_id = %d", $payment_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Create the invoice for a completed payment (once per payment).
	 *
	 * @param object     $booking    Booking.
	 * @param int        $payment_id Payment row.
	 * @param array|null $lines      Lines to use instead of the booking's (e.g. a no-show fee).
	 * @return object|null
	 */
	public static function create_for_payment( $booking, $payment_id, $lines = null ) {
		if ( ! self::enabled() || ! self::table_ready() || ! $booking ) {
			return null;
		}
		$payment = OSB_DB::get( 'payments', (int) $payment_id );
		if ( ! $payment || (float) $payment->amount <= 0 ) {
			return null;
		}
		$existing = self::for_payment( $payment->id );
		if ( $existing ) {
			return self::get( $existing );
		}
		if ( ! apply_filters( 'osb_create_invoice', true, $booking, $payment ) ) {
			return null;
		}

		$total       = round( (float) $payment->amount, 2 );
		$booking_ids = array( (int) $booking->id );
		if ( null === $lines ) {
			list( $lines, $booking_ids ) = self::lines_for_payment( $booking, $total );
		}
		$gateway = osb()->payments->get( $payment->gateway );
		$label   = 'manual' === $payment->gateway ? __( 'Paid in person', 'osam-bookings' ) : ( $gateway ? $gateway->title() : ucfirst( $payment->gateway ) );
		$label   = 'manual' === $payment->gateway && $booking->payment_method && 'onsite' !== $booking->payment_method && osb()->payments->get( $booking->payment_method ) ? osb()->payments->get( $booking->payment_method )->title() : $label;
		$ref     = 0 === strpos( (string) $payment->transaction_id, 'admin-' ) ? '' : (string) $payment->transaction_id;

		return self::insert(
			array(
				'kind'           => 'invoice',
				'booking_id'     => (int) $booking->id,
				'booking_ids'    => $booking_ids,
				'payment_id'     => (int) $payment->id,
				'customer_name'  => (string) $booking->customer_name,
				'customer_email' => (string) $booking->customer_email,
				'bill_to'        => self::bill_to( $booking ),
				'currency'       => $payment->currency ? $payment->currency : $booking->currency,
				'total'          => $total,
				'lines'          => $lines,
				'status'         => 'paid',
				'payment_method' => $payment->gateway,
				'payment_label'  => $label,
				'payment_ref'    => $ref,
				'paid_at'        => $payment->created_at,
			)
		);
	}

	/**
	 * Credit note against an invoice.
	 *
	 * @param object $invoice Invoice.
	 * @param float  $amount  Amount (0 = whatever is left).
	 * @param string $reason  Reason shown on the note.
	 * @param bool   $email   Email it to the customer (if that setting is on).
	 * @return object|WP_Error
	 */
	public static function credit( $invoice, $amount = 0.0, $reason = '', $email = true ) {
		if ( ! $invoice || 'invoice' !== $invoice->kind ) {
			return new WP_Error( 'osb_invoice', __( 'Invoice not found.', 'osam-bookings' ) );
		}
		$left   = self::remaining( $invoice );
		$amount = $amount > 0 ? round( (float) $amount, 2 ) : $left;
		if ( $left <= 0 ) {
			return new WP_Error( 'osb_credited', __( 'This invoice has already been fully credited.', 'osam-bookings' ) );
		}
		if ( $amount > $left + 0.001 ) {
			/* translators: %s amount */
			return new WP_Error( 'osb_too_much', sprintf( __( 'You can credit at most %s on this invoice.', 'osam-bookings' ), OSB_Settings::money( $left, $invoice->currency ) ) );
		}
		$full = abs( $amount - (float) $invoice->total ) < 0.005;
		if ( $full ) {
			$lines = $invoice->lines;
		} else {
			$lines = array(
				array(
					/* translators: %s invoice number */
					'desc'   => sprintf( __( 'Partial refund — invoice %s', 'osam-bookings' ), $invoice->number ),
					'detail' => '',
					'qty'    => 1,
					'unit'   => $amount,
					'amount' => $amount,
				),
			);
		}
		$cn = self::insert(
			array(
				'kind'           => 'credit_note',
				'parent_id'      => (int) $invoice->id,
				'booking_id'     => (int) $invoice->booking_id,
				'booking_ids'    => $invoice->booking_ids,
				'payment_id'     => 0,
				'customer_name'  => $invoice->customer_name,
				'customer_email' => $invoice->customer_email,
				'bill_to'        => $invoice->bill_to,
				'currency'       => $invoice->currency,
				'total'          => $amount,
				'lines'          => $lines,
				'status'         => 'issued',
				'payment_method' => $invoice->payment_method,
				'payment_label'  => $invoice->payment_label,
				'payment_ref'    => '',
				'notes'          => sanitize_textarea_field( (string) $reason ),
				'vat_rate'       => (float) $invoice->vat_rate, // A credit note follows the VAT of the invoice it corrects.
				'seller'         => $invoice->seller,
			)
		);
		if ( ! $cn ) {
			return new WP_Error( 'osb_db', __( 'Could not save the credit note.', 'osam-bookings' ) );
		}
		self::refresh_status( $invoice->id );
		if ( $email && OSB_Settings::get( 'inv_email_credit' ) ) {
			self::email( $cn );
		}
		do_action( 'osb_credit_note_created', $cn, $invoice );
		return $cn;
	}

	/**
	 * A booking's payment was marked refunded: credit whatever is left on its invoices.
	 *
	 * @param int $booking_id Booking.
	 */
	public static function on_refunded( $booking_id ) {
		if ( ! self::enabled() ) {
			return;
		}
		foreach ( self::for_booking( (int) $booking_id, 'invoice' ) as $inv ) {
			if ( (int) $inv->booking_id === (int) $booking_id && self::remaining( $inv ) > 0 ) {
				self::credit( $inv, 0, __( 'Booking refunded', 'osam-bookings' ) );
			}
		}
	}

	/**
	 * Recalculate an invoice's status from its credit notes.
	 *
	 * @param int $invoice_id Invoice.
	 */
	public static function refresh_status( $invoice_id ) {
		$inv = self::get( $invoice_id );
		if ( ! $inv ) {
			return;
		}
		$left   = self::remaining( $inv );
		$status = $left <= 0 ? 'credited' : ( $left < (float) $inv->total ? 'part_credited' : 'paid' );
		self::update( $inv->id, array( 'status' => $status ) );
	}

	/**
	 * Write a document: number, VAT split and business snapshot.
	 *
	 * @param array $d Data.
	 * @return object|null
	 */
	private static function insert( array $d ) {
		global $wpdb;
		$kind  = 'credit_note' === $d['kind'] ? 'credit_note' : 'invoice';
		$rate  = array_key_exists( 'vat_rate', $d ) ? (float) $d['vat_rate'] : ( 'inclusive' === OSB_Settings::get( 'inv_vat_mode' ) ? max( 0, (float) OSB_Settings::get( 'inv_vat_rate' ) ) : 0 );
		$lines = self::with_vat( (array) $d['lines'], $rate );
		$net   = 0;
		foreach ( $lines as $l ) {
			$net += $l['net'];
		}
		$net = round( $net, 2 );
		$vat = $rate > 0 ? round( (float) $d['total'] - $net, 2 ) : 0;

		if ( ! self::lock() ) {
			return null;
		}
		try {
			$seq    = self::next_seq( $kind );
			$prefix = 'credit_note' === $kind ? (string) OSB_Settings::get( 'cn_prefix' ) : (string) OSB_Settings::get( 'inv_prefix' );
			$number = $prefix . str_pad( (string) $seq, max( 1, min( 10, (int) OSB_Settings::get( 'inv_padding' ) ) ), '0', STR_PAD_LEFT );
			$now    = current_time( 'mysql' );
			$row    = array(
				'kind'           => $kind,
				'number'         => substr( $number, 0, 40 ),
				'seq'            => $seq,
				'parent_id'      => (int) ( $d['parent_id'] ?? 0 ),
				'booking_id'     => (int) $d['booking_id'],
				'booking_ids'    => implode( ',', array_map( 'intval', (array) $d['booking_ids'] ) ),
				'payment_id'     => (int) $d['payment_id'],
				'status'         => $d['status'],
				'customer_name'  => substr( (string) $d['customer_name'], 0, 191 ),
				'customer_email' => substr( (string) $d['customer_email'], 0, 191 ),
				'bill_to'        => (string) $d['bill_to'],
				'currency'       => substr( (string) $d['currency'], 0, 3 ),
				'subtotal'       => $rate > 0 ? $net : round( (float) $d['total'], 2 ),
				'vat_rate'       => $rate,
				'vat_amount'     => $vat,
				'total'          => round( (float) $d['total'], 2 ),
				'items'          => wp_json_encode( $lines ),
				'seller'         => wp_json_encode( isset( $d['seller'] ) ? $d['seller'] : self::seller() ),
				'notes'          => (string) ( $d['notes'] ?? '' ),
				'payment_method' => substr( (string) $d['payment_method'], 0, 30 ),
				'payment_label'  => substr( (string) $d['payment_label'], 0, 100 ),
				'payment_ref'    => substr( (string) $d['payment_ref'], 0, 191 ),
				'paid_at'        => ! empty( $d['paid_at'] ) ? $d['paid_at'] : null,
				'issued_at'      => $now,
				'token'          => wp_generate_password( 32, false, false ),
				'created_at'     => $now,
			);
			$row = apply_filters( 'osb_invoice_insert_data', $row, $d );
			$ok  = $wpdb->insert( self::t(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$id  = $ok ? (int) $wpdb->insert_id : 0;
		} finally {
			self::unlock();
		}
		if ( ! $id ) {
			return null;
		}
		$doc = self::get( $id );
		do_action( 'credit_note' === $kind ? 'osb_credit_note_saved' : 'osb_invoice_created', $doc );
		return $doc;
	}

	/**
	 * Update columns.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$wpdb->update( self::t(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Next number in a sequence (never below the "start from" setting).
	 *
	 * @param string $kind invoice|credit_note.
	 * @return int
	 */
	private static function next_seq( $kind ) {
		global $wpdb;
		$max   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(seq) FROM ' . self::t() . ' WHERE kind = %s', $kind ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$start = 'credit_note' === $kind ? 1 : max( 1, (int) OSB_Settings::get( 'inv_start' ) );
		return max( $max + 1, $start );
	}

	/**
	 * Numbering lock (two payments landing at once must not share a number).
	 *
	 * @return bool
	 */
	private static function lock() {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$got = $wpdb->get_var( "SELECT GET_LOCK('osb_invoice_number', 10)" );
		$wpdb->suppress_errors( false );
		return null === $got || '1' === (string) $got;
	}

	/**
	 * Release the lock.
	 */
	private static function unlock() {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$wpdb->query( "SELECT RELEASE_LOCK('osb_invoice_number')" );
		$wpdb->suppress_errors( false );
	}

	/**
	 * Business details as they are now (copied onto each document).
	 *
	 * @return array
	 */
	public static function seller() {
		$logo = (int) OSB_Settings::get( 'inv_logo_id' );
		$logo = $logo ? $logo : (int) OSB_Settings::get( 'email_logo_id' );
		return array(
			'name'       => (string) OSB_Settings::get( 'inv_business_name' ),
			'address'    => (string) OSB_Settings::get( 'inv_address' ),
			'email'      => (string) OSB_Settings::get( 'inv_email' ),
			'phone'      => (string) OSB_Settings::get( 'inv_phone' ),
			'website'    => (string) OSB_Settings::get( 'inv_website' ),
			'company_no' => (string) OSB_Settings::get( 'inv_company_no' ),
			'vat_no'     => 'inclusive' === OSB_Settings::get( 'inv_vat_mode' ) ? (string) OSB_Settings::get( 'inv_vat_number' ) : '',
			'logo_id'    => $logo,
			'accent'     => (string) ( OSB_Settings::get( 'inv_accent' ) ? OSB_Settings::get( 'inv_accent' ) : OSB_Settings::get( 'email_accent' ) ),
			'footer'     => (string) OSB_Settings::get( 'inv_footer' ),
		);
	}

	/**
	 * "Bill to" text from the booking: name, then company / address answers if the form asks for them.
	 *
	 * @param object $booking Booking.
	 * @return string
	 */
	public static function bill_to( $booking ) {
		$fields = OSB_Bookings::fields( $booking );
		$lines  = array( (string) $booking->customer_name );
		$pick   = trim( (string) OSB_Settings::get( 'inv_bill_to_fields' ) );
		$keys   = $pick ? array_filter( array_map( 'trim', explode( ',', $pick ) ) ) : array();
		if ( ! $keys ) {
			foreach ( OSB_Settings::form_fields() as $f ) {
				if ( preg_match( '/compan|business|organi[sz]ation|address|street|town|city|county|post ?code|postal|zip/i', $f['key'] . ' ' . $f['label'] ) ) {
					$keys[] = $f['key'];
				}
			}
		}
		foreach ( $keys as $k ) {
			if ( isset( $fields[ $k ] ) && is_scalar( $fields[ $k ] ) && '' !== trim( (string) $fields[ $k ] ) ) {
				$lines[] = trim( (string) $fields[ $k ] );
			}
		}
		return implode( "\n", array_unique( array_filter( $lines ) ) );
	}

	/**
	 * Date/time/staff line under an item.
	 *
	 * @param object $b Booking.
	 * @return string
	 */
	public static function booking_line_detail( $b ) {
		$d = OSB_Bookings::details( $b );
		if ( 'bundle' === $b->type ) {
			return '';
		}
		if ( 'course' === $b->type ) {
			/* translators: 1: intake 2: sessions 3: start date */
			return trim( sprintf( __( '%1$s · %2$d sessions from %3$s', 'osam-bookings' ), $d['intake_name'], $d['session_count'], $d['date'] ), ' ·' );
		}
		$parts = array( mysql2date( 'D', $b->start_at ) . ' ' . $d['date'] . ', ' . $d['time'] . ( $d['end_time'] && $d['end_time'] !== $d['time'] ? '–' . $d['end_time'] : '' ) );
		if ( $d['staff_name'] ) {
			/* translators: %s staff */
			$parts[] = sprintf( __( 'with %s', 'osam-bookings' ), $d['staff_name'] );
		}
		if ( $d['location'] ) {
			$parts[] = $d['location'];
		}
		/* translators: %d booking id */
		$parts[] = sprintf( __( 'Booking #%d', 'osam-bookings' ), $b->id );
		return implode( ' · ', $parts );
	}

	/**
	 * Lines for one booking: the item, each add-on, then any discount. Adds up to the booking amount.
	 *
	 * @param object $b Booking.
	 * @return array
	 */
	public static function booking_lines( $b ) {
		$d      = OSB_Bookings::details( $b );
		$seats  = max( 1, (int) $b->seats );
		$addons = class_exists( 'OSB_Addons' ) ? OSB_Addons::lines( $b->id ) : array();
		$extras = 0;
		foreach ( $addons as $a ) {
			$extras += (float) $a->amount;
		}
		$discount = (float) $b->discount;
		$base     = round( (float) $b->amount + $discount - $extras, 2 );
		$name     = $d['item_name'];
		$detail   = self::booking_line_detail( $b );
		if ( ! empty( $b->pass_id ) ) {
			$base    = 0;
			$detail .= ( $detail ? ' · ' : '' ) . __( 'paid with a bundle', 'osam-bookings' );
		}
		$base  = max( 0, $base );
		$out   = array();
		$qty   = 'table' === $b->type ? 1 : $seats; // Restaurant deposits are per booking, the party size is in the name.
		if ( 'table' === $b->type ) {
			/* translators: %d people */
			$name .= ' — ' . sprintf( _n( '%d person', '%d people', $seats, 'osam-bookings' ), $seats );
		}
		$out[] = array(
			'desc'   => $name,
			'detail' => $detail,
			'qty'    => $qty,
			'unit'   => round( $base / $qty, 2 ),
			'amount' => $base,
		);
		foreach ( $addons as $a ) {
			$out[] = array(
				'desc'   => (string) $a->name,
				'detail' => __( 'Add-on', 'osam-bookings' ),
				'qty'    => max( 1, (int) $a->qty ),
				'unit'   => (float) $a->unit_price > 0 ? (float) $a->unit_price : round( (float) $a->amount / max( 1, (int) $a->qty ), 2 ),
				'amount' => (float) $a->amount,
			);
		}
		if ( $discount > 0 ) {
			$out[] = array(
				/* translators: %s code */
				'desc'   => $b->coupon_code ? sprintf( __( 'Discount (%s)', 'osam-bookings' ), $b->coupon_code ) : __( 'Discount', 'osam-bookings' ),
				'detail' => '',
				'qty'    => 1,
				'unit'   => -$discount,
				'amount' => -$discount,
			);
		}
		return apply_filters( 'osb_invoice_booking_lines', $out, $b );
	}

	/**
	 * Lines for a payment: the booking, plus the later dates of a repeat series it paid for.
	 * If they don't add up to what was paid (deposits, part payments), one line for the payment is used.
	 *
	 * @param object $booking Booking paid.
	 * @param float  $total   Amount paid.
	 * @return array [ lines, booking ids ]
	 */
	public static function lines_for_payment( $booking, $total ) {
		$bookings = array( $booking );
		if ( class_exists( 'OSB_Repeat' ) && OSB_Repeat::is_lead( $booking ) ) {
			foreach ( OSB_Repeat::series( $booking ) as $s ) {
				if ( (int) $s->id !== (int) $booking->id && in_array( $s->status, array( 'pending_payment' ), true ) ) {
					$bookings[] = $s;
				}
			}
		}
		$lines = array();
		$sum   = 0;
		foreach ( $bookings as $b ) {
			foreach ( self::booking_lines( $b ) as $l ) {
				$lines[] = $l;
				$sum    += (float) $l['amount'];
			}
		}
		$ids = array_map(
			static function ( $b ) {
				return (int) $b->id;
			},
			$bookings
		);
		if ( abs( $sum - $total ) > 0.009 ) {
			// Lines sum to a different figure: invoice what was actually paid, against the booking.
			$d     = OSB_Bookings::details( $booking );
			$ids   = array( (int) $booking->id );
			$lines = array(
				array(
					/* translators: %s item */
					'desc'   => abs( (float) $booking->amount - $total ) > 0.009 && $total < (float) $booking->amount ? sprintf( __( 'Payment towards %s', 'osam-bookings' ), $d['item_name'] ) : $d['item_name'],
					'detail' => self::booking_line_detail( $booking ),
					'qty'    => 1,
					'unit'   => $total,
					'amount' => $total,
				),
			);
		}
		return array( $lines, $ids );
	}

	/**
	 * Add net figures to lines (prices include VAT).
	 *
	 * @param array $lines Lines.
	 * @param float $rate  VAT %.
	 * @return array
	 */
	private static function with_vat( array $lines, $rate ) {
		foreach ( $lines as $i => $l ) {
			$l['qty']    = max( 1, (int) $l['qty'] );
			$l['amount'] = round( (float) $l['amount'], 2 );
			$l['unit']   = round( (float) $l['unit'], 2 );
			$l['net']    = $rate > 0 ? round( $l['amount'] / ( 1 + $rate / 100 ), 2 ) : $l['amount'];
			$l['unit_net'] = $rate > 0 ? round( $l['unit'] / ( 1 + $rate / 100 ), 2 ) : $l['unit'];
			$lines[ $i ] = $l;
		}
		return $lines;
	}

	/* ---------------------------------------------------------------------
	 * Emails
	 * ------------------------------------------------------------------ */

	/**
	 * Emails that carry the invoice when one exists.
	 *
	 * @return string[]
	 */
	public static function attach_keys() {
		$keys = array( 'customer_confirmed', 'customer_pending', 'customer_bundle' );
		if ( OSB_Settings::get( 'inv_attach_admin' ) ) {
			$keys[] = 'admin_new';
		}
		return (array) apply_filters( 'osb_invoice_email_keys', $keys );
	}

	/**
	 * Add the PDF to outgoing emails.
	 *
	 * @param array  $args    wp_mail args.
	 * @param string $key     Email key.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function attach_to_email( $args, $key, $booking ) {
		if ( ! self::enabled() || empty( $booking->id ) ) {
			return $args;
		}
		$doc = null;
		if ( self::$attach ) {
			$doc = self::$attach;
		} elseif ( OSB_Settings::get( 'inv_attach' ) ) {
			$effective = 0 === strpos( (string) $key, 'custom_' ) && OSB_Emails::$replacing ? OSB_Emails::$replacing : $key;
			if ( in_array( $effective, self::attach_keys(), true ) ) {
				$doc = self::latest_for( $booking );
			}
		}
		if ( $doc ) {
			$file = self::temp_file( $doc );
			if ( $file ) {
				$args['attachments'][] = $file;
				self::$temp[]          = $file;
				if ( ! self::$cleanup ) {
					self::$cleanup = true;
					add_action( 'osb_email_sent', array( __CLASS__, 'cleanup' ) );
					add_action( 'shutdown', array( __CLASS__, 'cleanup' ) );
				}
				if ( 0 === strpos( (string) $key, 'customer' ) || 0 === strpos( (string) $key, 'custom_' ) ) {
					self::update( $doc->id, array( 'emailed_at' => current_time( 'mysql' ) ) );
				}
			}
		}
		return $args;
	}

	/**
	 * Temp files made for this request.
	 *
	 * @var string[]
	 */
	private static $temp = array();

	/**
	 * Cleanup hooked?
	 *
	 * @var bool
	 */
	private static $cleanup = false;

	/**
	 * Delete temp PDFs.
	 */
	public static function cleanup() {
		foreach ( self::$temp as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
				@rmdir( dirname( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
		self::$temp = array();
	}

	/**
	 * Newest invoice for a booking (the one made just now, if any).
	 *
	 * @param object $booking Booking.
	 * @return object|null
	 */
	public static function latest_for( $booking ) {
		if ( isset( self::$fresh[ (int) $booking->id ] ) ) {
			return self::$fresh[ (int) $booking->id ];
		}
		$list = self::for_booking( $booking, 'invoice' );
		return $list ? end( $list ) : null;
	}

	/**
	 * Email a document to the customer on its own ("Customer — invoice" / "— credit note" templates).
	 *
	 * @param object $doc   Invoice or credit note.
	 * @param bool   $force Send even if the template is switched off (staff pressed "Email").
	 * @param string $to    Recipient (default: the customer).
	 * @return bool
	 */
	public static function email( $doc, $force = false, $to = '' ) {
		$booking = OSB_DB::get( 'bookings', (int) $doc->booking_id );
		$to      = $to ? $to : ( $doc->customer_email ? $doc->customer_email : ( $booking ? $booking->customer_email : '' ) );
		if ( ! $booking || ! is_email( $to ) ) {
			return false;
		}
		$key          = 'credit_note' === $doc->kind ? 'customer_credit_note' : 'customer_invoice';
		self::$attach = $doc;
		try {
			$sent = OSB_Emails::send( $key, $booking, $to, $force, self::doc_placeholders( $doc ) );
		} finally {
			self::$attach = null;
		}
		return $sent;
	}

	/**
	 * Invoice issued after the booking emails went out (staff marked it paid, no-show fee): send it on its own.
	 *
	 * @param object $inv Invoice.
	 */
	private static function maybe_email_later( $inv ) {
		if ( OSB_Settings::get( 'inv_email_later' ) ) {
			self::email( $inv );
		}
	}

	/**
	 * Placeholders for one document.
	 *
	 * @param object $doc Document.
	 * @return array
	 */
	public static function doc_placeholders( $doc ) {
		$ds    = OSB_Emails::design();
		$label = 'credit_note' === $doc->kind ? __( 'Download credit note', 'osam-bookings' ) : __( 'Download invoice', 'osam-bookings' );
		$map   = array(
			'{invoice_number}' => esc_html( $doc->number ),
			'{invoice_total}'  => esc_html( OSB_Settings::money( $doc->total, $doc->currency ) ),
			'{invoice_date}'   => esc_html( mysql2date( get_option( 'date_format' ), $doc->issued_at ) ),
			'{invoice_link}'   => esc_url( self::public_url( $doc ) ),
			'{invoice_button}' => OSB_Emails::button( self::public_url( $doc ), $label, $ds ),
		);
		if ( 'credit_note' === $doc->kind ) {
			$parent = self::get( $doc->parent_id );
			$map   += array(
				'{credit_note_number}' => esc_html( $doc->number ),
				'{credit_note_total}'  => esc_html( OSB_Settings::money( $doc->total, $doc->currency ) ),
				'{credit_note_reason}' => esc_html( (string) $doc->notes ),
			);
			$map['{invoice_number}'] = esc_html( $parent ? $parent->number : '' );
		}
		return $map;
	}

	/**
	 * Booking placeholders: the latest invoice, if there is one.
	 *
	 * @param array  $map     Placeholders.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function placeholders( $map, $booking ) {
		$empty = array(
			'{invoice_number}' => '',
			'{invoice_total}'  => '',
			'{invoice_date}'   => '',
			'{invoice_link}'   => '',
			'{invoice_button}' => '',
		);
		if ( ! self::enabled() || empty( $booking->id ) ) {
			return array_merge( $map, $empty );
		}
		$inv = self::latest_for( $booking );
		return array_merge( $map, $inv ? self::doc_placeholders( $inv ) : $empty );
	}

	/**
	 * Booking details: download links for the customer's manage page and portal.
	 *
	 * @param array  $d       Details.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function details( $d, $booking ) {
		$d['invoices'] = array();
		// Only the customer-facing screens (manage page, portal — both REST) show these; skip the lookup in wp-admin.
		if ( ! self::enabled() || empty( $booking->id ) || ! OSB_Settings::get( 'inv_customer_download' ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $d;
		}
		foreach ( self::for_booking( $booking ) as $doc ) {
			$d['invoices'][] = array(
				/* translators: %s number */
				'label' => sprintf( 'credit_note' === $doc->kind ? __( 'Credit note %s', 'osam-bookings' ) : __( 'Invoice %s', 'osam-bookings' ), $doc->number ),
				'url'   => self::public_url( $doc ),
			);
		}
		return $d;
	}

	/* ---------------------------------------------------------------------
	 * Downloads
	 * ------------------------------------------------------------------ */

	/**
	 * Customer link (private token).
	 *
	 * @param object $doc Document.
	 * @return string
	 */
	public static function public_url( $doc ) {
		return add_query_arg( 'osb_invoice', $doc->token, home_url( '/' ) );
	}

	/**
	 * Staff link.
	 *
	 * @param object $doc      Document.
	 * @param bool   $download Force download (else open in the browser).
	 * @return string
	 */
	public static function admin_url( $doc, $download = false ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=osb_invoice_pdf&id=' . (int) $doc->id . ( $download ? '&dl=1' : '' ) ), 'osb_invoice_pdf_' . (int) $doc->id );
	}

	/**
	 * File name.
	 *
	 * @param object $doc Document.
	 * @return string
	 */
	public static function filename( $doc ) {
		return sanitize_file_name( ( 'credit_note' === $doc->kind ? 'credit-note-' : 'invoice-' ) . $doc->number . '.pdf' );
	}

	/**
	 * Customer download (?osb_invoice=token).
	 */
	public static function public_download() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['osb_invoice'] ) ) {
			return;
		}
		$doc = self::by_token( sanitize_text_field( wp_unslash( $_GET['osb_invoice'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $doc ) {
			status_header( 404 );
			wp_die( esc_html__( 'This invoice link isn’t valid.', 'osam-bookings' ), '', array( 'response' => 404 ) );
		}
		self::stream( $doc, false );
	}

	/**
	 * Staff download.
	 */
	public static function admin_download() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_invoice_pdf_' . $id );
		if ( 0 === $id && isset( $_GET['sample'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$doc = self::sample();
		} else {
			$doc = self::get( $id );
		}
		if ( ! $doc ) {
			wp_die( esc_html__( 'Invoice not found.', 'osam-bookings' ) );
		}
		self::stream( $doc, ! empty( $_GET['dl'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Send the PDF to the browser.
	 *
	 * @param object $doc      Document.
	 * @param bool   $download Attachment rather than inline.
	 */
	private static function stream( $doc, $download ) {
		$pdf = self::pdf( $doc );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . self::filename( $doc ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Write the PDF to a temp file (email attachments).
	 *
	 * @param object $doc Document.
	 * @return string|false
	 */
	public static function temp_file( $doc ) {
		$dir = trailingslashit( get_temp_dir() ) . 'osb-' . wp_generate_password( 8, false, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$file = $dir . '/' . self::filename( $doc );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $file, self::pdf( $doc ) ) ? $file : false;
	}

	/**
	 * An unsaved example (Settings → Invoices → Preview).
	 *
	 * @return object
	 */
	public static function sample() {
		$rate  = 'inclusive' === OSB_Settings::get( 'inv_vat_mode' ) ? (float) OSB_Settings::get( 'inv_vat_rate' ) : 0;
		$lines = self::with_vat(
			array(
				array( 'desc' => __( 'Deep tissue massage (60 min)', 'osam-bookings' ), 'detail' => date_i18n( 'D ' . get_option( 'date_format' ) ) . ', 10:00–11:00 · ' . __( 'Booking #1234', 'osam-bookings' ), 'qty' => 1, 'unit' => 60, 'amount' => 60 ),
				array( 'desc' => __( 'Aromatherapy oils', 'osam-bookings' ), 'detail' => __( 'Add-on', 'osam-bookings' ), 'qty' => 2, 'unit' => 7.5, 'amount' => 15 ),
				array( 'desc' => __( 'Discount (WELCOME10)', 'osam-bookings' ), 'detail' => '', 'qty' => 1, 'unit' => -7.5, 'amount' => -7.5 ),
			),
			$rate
		);
		$net = 0;
		foreach ( $lines as $l ) {
			$net += $l['net'];
		}
		$prefix = (string) OSB_Settings::get( 'inv_prefix' );
		return (object) array(
			'id'             => 0,
			'kind'           => 'invoice',
			'number'         => $prefix . str_pad( (string) max( 1, (int) OSB_Settings::get( 'inv_start' ) ), max( 1, (int) OSB_Settings::get( 'inv_padding' ) ), '0', STR_PAD_LEFT ),
			'parent_id'      => 0,
			'booking_id'     => 1234,
			'booking_ids'    => array( 1234 ),
			'status'         => 'paid',
			'customer_name'  => 'Alex Example',
			'customer_email' => 'alex@example.com',
			'bill_to'        => "Alex Example\nExample Studio Ltd\n1 High Street\nBristol BS1 1AA",
			'currency'       => OSB_Settings::get( 'currency' ),
			'subtotal'       => $rate > 0 ? round( $net, 2 ) : 67.5,
			'vat_rate'       => $rate,
			'vat_amount'     => $rate > 0 ? round( 67.5 - $net, 2 ) : 0,
			'total'          => 67.5,
			'lines'          => $lines,
			'seller'         => self::seller(),
			'notes'          => '',
			'payment_method' => 'stripe',
			'payment_label'  => __( 'Card (Stripe)', 'osam-bookings' ),
			'payment_ref'    => 'pi_3Q8example',
			'paid_at'        => current_time( 'mysql' ),
			'issued_at'      => current_time( 'mysql' ),
			'token'          => '',
		);
	}

	/* ---------------------------------------------------------------------
	 * PDF
	 * ------------------------------------------------------------------ */

	/**
	 * Money for the PDF (falls back to the currency code where the symbol can't be shown).
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return string
	 */
	private static function money( $amount, $currency ) {
		$neg = (float) $amount < 0;
		$s   = OSB_Settings::money( abs( (float) $amount ), $currency );
		if ( ! OSB_Pdf::encodable( $s ) ) {
			$s = strtoupper( $currency ) . ' ' . number_format( abs( (float) $amount ), 2 );
		}
		return ( $neg ? '-' : '' ) . $s;
	}

	/**
	 * Local file for the logo (a mid-size version so the PDF stays small).
	 *
	 * @param int $id Attachment.
	 * @return string
	 */
	private static function logo_file( $id ) {
		if ( ! $id ) {
			return '';
		}
		$file = get_attached_file( $id );
		$meta = wp_get_attachment_metadata( $id );
		if ( $file && is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( array( 'medium', 'medium_large', 'large' ) as $size ) {
				if ( ! empty( $meta['sizes'][ $size ]['file'] ) ) {
					$cand = path_join( dirname( $file ), $meta['sizes'][ $size ]['file'] );
					if ( file_exists( $cand ) && ! empty( $meta['sizes'][ $size ]['width'] ) && $meta['sizes'][ $size ]['width'] >= 300 ) {
						return $cand;
					}
				}
			}
		}
		return $file && file_exists( $file ) ? $file : '';
	}

	/**
	 * Build the PDF.
	 *
	 * @param object $doc Invoice or credit note.
	 * @return string
	 */
	public static function pdf( $doc ) {
		$credit = 'credit_note' === $doc->kind;
		$seller = wp_parse_args( (array) $doc->seller, array( 'name' => get_bloginfo( 'name' ), 'address' => '', 'email' => '', 'phone' => '', 'website' => '', 'company_no' => '', 'vat_no' => '', 'logo_id' => 0, 'accent' => '#161a40', 'footer' => '' ) );
		$accent = preg_match( '/^#[0-9a-f]{6}$/i', (string) $seller['accent'] ) ? $seller['accent'] : '#161a40';
		$ink    = '#1f2933';
		$muted  = '#667085';
		$rule   = '#e4e7ec';
		$panel  = '#f5f7fa';
		$cur    = $doc->currency;
		$vat    = (float) $doc->vat_rate > 0;
		$title  = $credit ? __( 'CREDIT NOTE', 'osam-bookings' ) : __( 'INVOICE', 'osam-bookings' );
		$pdf    = new OSB_Pdf( ( $credit ? __( 'Credit note', 'osam-bookings' ) : __( 'Invoice', 'osam-bookings' ) ) . ' ' . $doc->number );
		$L      = 48;
		$R      = OSB_Pdf::W - 48;
		$dfmt   = get_option( 'date_format' );

		// Header: accent strip, logo or business name, document title.
		$pdf->rect( 0, 0, OSB_Pdf::W, 6, $accent );
		$y    = 44;
		$logo = self::logo_file( (int) $seller['logo_id'] );
		$drew = $logo ? $pdf->image( $logo, $L, $y, 170, 56 ) : false;
		if ( $drew ) {
			$y += $drew[1] + 14;
		} else {
			$y = $pdf->paragraph( $L, $y + 14, 280, $seller['name'], array( 'size' => 17, 'bold' => true, 'color' => $ink, 'leading' => 21 ) ) - 4;
		}
		$pdf->text( $R, 64, $title, array( 'size' => 22, 'bold' => true, 'color' => $accent, 'align' => 'right' ) );

		// Seller details (left) and document facts (right).
		$sy = $y;
		if ( $drew ) {
			$pdf->text( $L, $sy, $seller['name'], array( 'size' => 9.5, 'bold' => true, 'color' => $ink ) );
			$sy += 13;
		}
		$info = array_filter( array_merge( explode( "\n", (string) $seller['address'] ), array( $seller['email'], $seller['phone'], $seller['website'] ) ), static function ( $v ) { return '' !== trim( (string) $v ); } );
		foreach ( $info as $line ) {
			$sy = $pdf->paragraph( $L, $sy, 250, trim( $line ), array( 'size' => 8.5, 'color' => $muted, 'leading' => 12 ) );
		}
		if ( $seller['vat_no'] ) {
			/* translators: %s VAT number */
			$pdf->text( $L, $sy, sprintf( __( 'VAT reg. no. %s', 'osam-bookings' ), $seller['vat_no'] ), array( 'size' => 8.5, 'color' => $muted ) );
			$sy += 12;
		}

		$facts = array(
			array( $credit ? __( 'Credit note no.', 'osam-bookings' ) : __( 'Invoice no.', 'osam-bookings' ), $doc->number ),
			array( __( 'Date', 'osam-bookings' ), mysql2date( $dfmt, $doc->issued_at ) ),
		);
		if ( $credit ) {
			$parent  = $doc->parent_id ? self::get( $doc->parent_id ) : null;
			$facts[] = array( __( 'Against invoice', 'osam-bookings' ), $parent ? $parent->number . ' (' . mysql2date( $dfmt, $parent->issued_at ) . ')' : '—' );
		} elseif ( $doc->paid_at ) {
			$facts[] = array( __( 'Date paid', 'osam-bookings' ), mysql2date( $dfmt, $doc->paid_at ) );
		}
		$refs = $doc->booking_ids ? $doc->booking_ids : array( (int) $doc->booking_id );
		$facts[] = array( _n( 'Booking ref.', 'Booking refs.', count( $refs ), 'osam-bookings' ), '#' . implode( ', #', $refs ) );
		$fy      = 92;
		foreach ( $facts as $f ) {
			$pdf->text( $R - 150, $fy, $f[0], array( 'size' => 8.5, 'color' => $muted, 'align' => 'right' ) );
			$pdf->text( $R, $fy, $f[1], array( 'size' => 9, 'bold' => true, 'color' => $ink, 'align' => 'right' ) );
			$fy += 14;
		}
		// Status stamp.
		$stamp = $credit ? '' : ( 'credited' === $doc->status ? __( 'CREDITED', 'osam-bookings' ) : __( 'PAID', 'osam-bookings' ) );
		if ( $stamp ) {
			$sw = OSB_Pdf::width( $stamp, 9, true ) + 18;
			$pdf->rect( $R - $sw, $fy - 2, $sw, 17, 'credited' === $doc->status ? '#fdecea' : '#e7f6ec' );
			$pdf->text( $R - $sw / 2, $fy + 10, $stamp, array( 'size' => 9, 'bold' => true, 'color' => 'credited' === $doc->status ? '#b42318' : '#1a7f37', 'align' => 'center' ) );
			$fy += 24;
		}

		// Bill to / payment.
		$y = max( $sy, $fy ) + 22;
		$pdf->line( $L, $y - 12, $R, $y - 12, $rule );
		$pdf->text( $L, $y + 4, $credit ? __( 'CREDIT TO', 'osam-bookings' ) : __( 'BILL TO', 'osam-bookings' ), array( 'size' => 7.5, 'bold' => true, 'color' => $muted ) );
		$by    = $y + 18;
		$bill  = trim( (string) $doc->bill_to ) ? explode( "\n", (string) $doc->bill_to ) : array( $doc->customer_name );
		$first = true;
		foreach ( $bill as $line ) {
			$by    = $pdf->paragraph( $L, $by, 240, trim( $line ), array( 'size' => $first ? 10 : 9, 'bold' => $first, 'color' => $ink, 'leading' => 13 ) );
			$first = false;
		}
		if ( $doc->customer_email ) {
			$by = $pdf->paragraph( $L, $by, 240, $doc->customer_email, array( 'size' => 9, 'color' => $muted, 'leading' => 13 ) );
		}
		$px = $L + 280;
		$pdf->text( $px, $y + 4, $credit ? __( 'REFUND', 'osam-bookings' ) : __( 'PAYMENT', 'osam-bookings' ), array( 'size' => 7.5, 'bold' => true, 'color' => $muted ) );
		$py = $y + 18;
		if ( $doc->payment_label ) {
			$py = $pdf->paragraph( $px, $py, $R - $px, $credit ? sprintf( /* translators: %s method */ __( 'To original payment method (%s)', 'osam-bookings' ), $doc->payment_label ) : $doc->payment_label, array( 'size' => 9.5, 'bold' => ! $credit, 'color' => $ink, 'leading' => 13 ) );
		}
		if ( ! $credit && $doc->payment_ref ) {
			/* translators: %s reference */
			$py = $pdf->paragraph( $px, $py, $R - $px, sprintf( __( 'Ref: %s', 'osam-bookings' ), $doc->payment_ref ), array( 'size' => 8.5, 'color' => $muted, 'leading' => 12 ) );
		}
		$y = max( $by, $py ) + 18;

		// Line items.
		$cq   = $R - 205;
		$cu   = $R - 105;
		$head = function ( $y ) use ( $pdf, $L, $R, $cq, $cu, $panel, $muted, $vat ) {
			$pdf->rect( $L, $y, $R - $L, 22, $panel );
			$o = array( 'size' => 8, 'bold' => true, 'color' => $muted );
			$pdf->text( $L + 10, $y + 14.5, __( 'DESCRIPTION', 'osam-bookings' ), $o );
			$pdf->text( $cq, $y + 14.5, __( 'QTY', 'osam-bookings' ), array_merge( $o, array( 'align' => 'right' ) ) );
			$pdf->text( $cu, $y + 14.5, $vat ? __( 'UNIT (EX VAT)', 'osam-bookings' ) : __( 'UNIT PRICE', 'osam-bookings' ), array_merge( $o, array( 'align' => 'right' ) ) );
			$pdf->text( $R - 10, $y + 14.5, $vat ? __( 'AMOUNT (EX VAT)', 'osam-bookings' ) : __( 'AMOUNT', 'osam-bookings' ), array_merge( $o, array( 'align' => 'right' ) ) );
			return $y + 22;
		};
		$y = $head( $y );
		foreach ( $doc->lines as $l ) {
			$desc   = OSB_Pdf::wrap( (string) $l['desc'], $cq - $L - 60, 9.5, true );
			$detail = '' !== (string) ( $l['detail'] ?? '' ) ? OSB_Pdf::wrap( (string) $l['detail'], $cq - $L - 60, 8, false ) : array();
			$h      = 12 + count( $desc ) * 13 + count( $detail ) * 11 + 6;
			if ( $y + $h > OSB_Pdf::H - 150 ) {
				$pdf->add_page();
				$y = $head( 48 );
			}
			$ty = $y + 18;
			foreach ( $desc as $d ) {
				$pdf->text( $L + 10, $ty, $d, array( 'size' => 9.5, 'bold' => true, 'color' => $ink ) );
				$ty += 13;
			}
			foreach ( $detail as $d ) {
				$pdf->text( $L + 10, $ty - 1, $d, array( 'size' => 8, 'color' => $muted ) );
				$ty += 11;
			}
			$unit   = $vat ? $l['unit_net'] : $l['unit'];
			$amount = $vat ? $l['net'] : $l['amount'];
			$pdf->text( $cq, $y + 18, (string) (int) $l['qty'], array( 'size' => 9.5, 'color' => $ink, 'align' => 'right' ) );
			$pdf->text( $cu, $y + 18, self::money( $unit, $cur ), array( 'size' => 9.5, 'color' => $ink, 'align' => 'right' ) );
			$pdf->text( $R - 10, $y + 18, self::money( $amount, $cur ), array( 'size' => 9.5, 'color' => $ink, 'align' => 'right' ) );
			$y += $h;
			$pdf->line( $L, $y, $R, $y, $rule );
		}

		// Totals.
		$y     += 18;
		$totals = array();
		if ( $vat ) {
			$totals[] = array( __( 'Subtotal (ex VAT)', 'osam-bookings' ), self::money( $doc->subtotal, $cur ), false );
			/* translators: %s rate */
			$totals[] = array( sprintf( __( 'VAT @ %s%%', 'osam-bookings' ), rtrim( rtrim( number_format( (float) $doc->vat_rate, 2, '.', '' ), '0' ), '.' ) ), self::money( $doc->vat_amount, $cur ), false );
		}
		$totals[] = array( $credit ? __( 'Total credited', 'osam-bookings' ) : __( 'Total', 'osam-bookings' ), self::money( $doc->total, $cur ), true );
		if ( ! $credit ) {
			$totals[] = array( __( 'Amount paid', 'osam-bookings' ), self::money( $doc->total, $cur ), false );
			$totals[] = array( __( 'Balance due', 'osam-bookings' ), self::money( 0, $cur ), false );
		}
		if ( $y + count( $totals ) * 20 > OSB_Pdf::H - 110 ) {
			$pdf->add_page();
			$y = 60;
		}
		$tx = $R - 230;
		foreach ( $totals as $t ) {
			if ( $t[2] ) {
				$pdf->rect( $tx, $y - 4, $R - $tx, 26, $accent );
				$pdf->text( $tx + 10, $y + 13, $t[0], array( 'size' => 10.5, 'bold' => true, 'color' => '#ffffff' ) );
				$pdf->text( $R - 10, $y + 13, $t[1], array( 'size' => 11.5, 'bold' => true, 'color' => '#ffffff', 'align' => 'right' ) );
				$y += 34;
			} else {
				$pdf->text( $tx + 10, $y + 9, $t[0], array( 'size' => 9, 'color' => $muted ) );
				$pdf->text( $R - 10, $y + 9, $t[1], array( 'size' => 9.5, 'color' => $ink, 'align' => 'right' ) );
				$y += 18;
			}
		}

		// Notes and footer.
		$y += 16;
		$notes = array();
		if ( $credit && $doc->notes ) {
			/* translators: %s reason */
			$notes[] = sprintf( __( 'Reason: %s', 'osam-bookings' ), $doc->notes );
		} elseif ( ! $credit && $doc->notes ) {
			$notes[] = $doc->notes;
		}
		if ( $seller['footer'] ) {
			$notes[] = $seller['footer'];
		}
		foreach ( $notes as $n ) {
			if ( $y > OSB_Pdf::H - 90 ) {
				$pdf->add_page();
				$y = 60;
			}
			$y = $pdf->paragraph( $L, $y, $R - $L, $n, array( 'size' => 9, 'color' => $ink, 'leading' => 13 ) ) + 6;
		}
		$foot = array_filter( array( $seller['name'], $seller['company_no'] ? sprintf( /* translators: %s number */ __( 'Company no. %s', 'osam-bookings' ), $seller['company_no'] ) : '', $seller['vat_no'] ? sprintf( /* translators: %s number */ __( 'VAT no. %s', 'osam-bookings' ), $seller['vat_no'] ) : '' ) );
		$pdf->line( $L, OSB_Pdf::H - 52, $R, OSB_Pdf::H - 52, $rule );
		$pdf->text( OSB_Pdf::W / 2, OSB_Pdf::H - 36, implode( '  ·  ', $foot ), array( 'size' => 7.5, 'color' => $muted, 'align' => 'center' ) );

		return (string) apply_filters( 'osb_invoice_pdf', $pdf->output(), $doc );
	}
}
