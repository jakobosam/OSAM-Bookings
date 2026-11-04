<?php
/**
 * Customers: everyone who has booked, grouped by email, plus GDPR export / erase.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer records are built from bookings. The customers table only holds what the
 * business adds itself (private notes and tags).
 */
class OSB_Customers {

	const ERASED_DOMAIN = 'anonymised.invalid';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy' ) );
	}

	/**
	 * Normalise an email for matching.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function key( $email ) {
		return strtolower( trim( (string) $email ) );
	}

	/**
	 * Aggregate SQL columns.
	 *
	 * @return string
	 */
	private static function aggregate_sql() {
		global $wpdb;
		$now = current_time( 'mysql' );
		return $wpdb->prepare(
			"LOWER(customer_email) AS email,
			MAX(id) AS last_id,
			COUNT(*) AS bookings,
			SUM(CASE WHEN status = 'completed' OR ( status = 'confirmed' AND end_at < %s ) THEN 1 ELSE 0 END) AS visits,
			SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS no_shows,
			SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
			SUM(CASE WHEN payment_status = 'paid' THEN amount ELSE 0 END) AS spent,
			MIN(created_at) AS first_at,
			MAX(CASE WHEN start_at <= %s AND status IN ('confirmed','completed') THEN start_at END) AS last_visit,
			MIN(CASE WHEN start_at > %s AND status IN ('pending','confirmed') THEN start_at END) AS next_at",
			$now,
			$now,
			$now
		);
	}

	/**
	 * Base WHERE: everyone except erased records.
	 *
	 * @return string
	 */
	private static function base_where() {
		global $wpdb;
		return $wpdb->prepare( "customer_email <> '' AND customer_email NOT LIKE %s", '%@' . $wpdb->esc_like( self::ERASED_DOMAIN ) );
	}

	/**
	 * List customers.
	 *
	 * @param array $args search, tag, orderby, order, page, per_page.
	 * @return array [ rows, total ]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'tag'      => '',
				'orderby'  => 'recent',
				'order'    => 'desc',
				'page'     => 1,
				'per_page' => 50,
			)
		);
		$b     = OSB_Install::table( 'bookings' );
		$c     = OSB_Install::table( 'customers' );
		$where = self::base_where();
		if ( '' !== $args['search'] ) {
			$like   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where .= $wpdb->prepare( ' AND ( customer_email LIKE %s OR customer_name LIKE %s OR customer_phone LIKE %s )', $like, $like, $like );
		}
		if ( '' !== $args['tag'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where .= $wpdb->prepare( " AND LOWER(customer_email) IN ( SELECT LOWER(email) FROM $c WHERE FIND_IN_SET( %s, REPLACE( tags, ', ', ',' ) ) )", $args['tag'] );
		}
		$orders = array(
			'recent'   => 'MAX(created_at)',
			'last'     => 'last_visit',
			'name'     => 'MAX(customer_name)',
			'bookings' => 'bookings',
			'spent'    => 'spent',
			'first'    => 'first_at',
			'no_shows' => 'no_shows',
		);
		$orderby = isset( $orders[ $args['orderby'] ] ) ? $orders[ $args['orderby'] ] : $orders['recent'];
		$order   = 'asc' === strtolower( $args['order'] ) ? 'ASC' : 'DESC';
		$per     = max( 1, (int) $args['per_page'] );
		$offset  = max( 0, ( (int) $args['page'] - 1 ) * $per );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT LOWER(customer_email)) FROM $b WHERE $where" );
		$rows  = $wpdb->get_results( 'SELECT ' . self::aggregate_sql() . " FROM $b WHERE $where GROUP BY LOWER(customer_email) ORDER BY $orderby $order, last_id DESC LIMIT $offset, $per" );
		// phpcs:enable
		return array( self::decorate( $rows ), $total );
	}

	/**
	 * Add name/phone (from the latest booking) and notes/tags.
	 *
	 * @param array $rows Aggregate rows.
	 * @return array
	 */
	private static function decorate( $rows ) {
		global $wpdb;
		if ( ! $rows ) {
			return array();
		}
		$b      = OSB_Install::table( 'bookings' );
		$c      = OSB_Install::table( 'customers' );
		$ids    = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'last_id' ) ) );
		$latest = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( "SELECT id, customer_name, customer_phone FROM $b WHERE id IN ($ids)" ) as $r ) {
			$latest[ (int) $r->id ] = $r;
		}
		$emails = array_map( 'strval', wp_list_pluck( $rows, 'email' ) );
		$extra  = array();
		$in     = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $c WHERE LOWER(email) IN ($in)", $emails ) ) as $r ) {
			$extra[ self::key( $r->email ) ] = $r;
		}
		foreach ( $rows as $r ) {
			$l        = isset( $latest[ (int) $r->last_id ] ) ? $latest[ (int) $r->last_id ] : null;
			$e        = isset( $extra[ $r->email ] ) ? $extra[ $r->email ] : null;
			$r->name  = $e && '' !== $e->name ? $e->name : ( $l ? $l->customer_name : '' );
			$r->phone = $e && '' !== $e->phone ? $e->phone : ( $l ? $l->customer_phone : '' );
			$r->notes = $e ? (string) $e->notes : '';
			$r->tags  = $e ? self::tags( $e->tags ) : array();
		}
		return $rows;
	}

	/**
	 * Split a tag string.
	 *
	 * @param string $tags Comma list.
	 * @return string[]
	 */
	public static function tags( $tags ) {
		return array_values( array_unique( array_filter( array_map( 'trim', explode( ',', (string) $tags ) ), 'strlen' ) ) );
	}

	/**
	 * Every tag in use.
	 *
	 * @return string[]
	 */
	public static function all_tags() {
		global $wpdb;
		$c   = OSB_Install::table( 'customers' );
		$all = array();
		foreach ( $wpdb->get_col( "SELECT tags FROM $c WHERE tags <> ''" ) as $t ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$all = array_merge( $all, self::tags( $t ) );
		}
		$all = array_unique( $all );
		natcasesort( $all );
		return array_values( $all );
	}

	/**
	 * One customer, as an array.
	 *
	 * @param string $email Email.
	 * @return array Empty if nobody has booked with that email.
	 */
	public static function get( $email ) {
		global $wpdb;
		$email = self::key( $email );
		if ( ! is_email( $email ) ) {
			return array();
		}
		$b = OSB_Install::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SELECT ' . self::aggregate_sql() . " FROM $b WHERE " . self::base_where() . $wpdb->prepare( ' AND LOWER(customer_email) = %s', $email ) . ' GROUP BY LOWER(customer_email)' );
		$rows = self::decorate( $rows );
		if ( ! $rows ) {
			$c   = OSB_Install::table( 'customers' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $c WHERE LOWER(email) = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $row ) {
				return array();
			}
			return array(
				'email'    => $email,
				'name'     => $row->name,
				'phone'    => $row->phone,
				'notes'    => (string) $row->notes,
				'tags'     => self::tags( $row->tags ),
				'bookings' => 0,
				'visits'   => 0,
				'no_shows' => 0,
				'cancelled' => 0,
				'spent'    => 0,
				'first_at' => $row->created_at,
				'last_visit' => null,
				'next_at'  => null,
			);
		}
		$r = (array) $rows[0];
		unset( $r['last_id'] );
		return $r;
	}

	/**
	 * A customer's bookings, newest first.
	 *
	 * @param string $email Email.
	 * @param int    $limit Max.
	 * @return array
	 */
	public static function bookings( $email, $limit = 200 ) {
		global $wpdb;
		$b = OSB_Install::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $b WHERE LOWER(customer_email) = %s ORDER BY start_at DESC LIMIT %d", self::key( $email ), $limit ) );
	}

	/**
	 * Save private notes and tags.
	 *
	 * @param string $email Email.
	 * @param string $notes Notes.
	 * @param string $tags  Comma-separated tags.
	 * @return bool
	 */
	public static function save_profile( $email, $notes, $tags ) {
		global $wpdb;
		$email = self::key( $email );
		if ( ! is_email( $email ) ) {
			return false;
		}
		$c    = OSB_Install::table( 'customers' );
		$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $c WHERE LOWER(email) = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data = array(
			'notes'      => sanitize_textarea_field( $notes ),
			'tags'       => implode( ', ', array_slice( self::tags( sanitize_text_field( $tags ) ), 0, 20 ) ),
			'updated_at' => current_time( 'mysql' ),
		);
		if ( $id ) {
			return false !== $wpdb->update( $c, $data, array( 'id' => $id ) );
		}
		$data['email']      = $email;
		$data['created_at'] = current_time( 'mysql' );
		return (bool) $wpdb->insert( $c, $data );
	}

	/* ---------------------------------------------------------------------
	 * GDPR
	 * ------------------------------------------------------------------ */

	/**
	 * Register with Tools → Export Personal Data.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['osam-bookings'] = array(
			'exporter_friendly_name' => __( 'OSAM Bookings', 'osam-bookings' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register with Tools → Erase Personal Data.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['osam-bookings'] = array(
			'eraser_friendly_name' => __( 'OSAM Bookings', 'osam-bookings' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'OSAM Bookings', 'osam-bookings' ),
			wp_kses_post(
				wpautop(
					__( 'When you book with us we store your name, email address, phone number, the answers you give on the booking form and the details of your booking and payment, so we can provide the service and contact you about it. Card payments are handled by our payment provider; we never see or store your card number. If you join a waitlist we keep your details until a place is offered or the date passes. You can ask us for a copy of your data, or for it to be erased, at any time. We may keep anonymised booking records for our accounts.', 'osam-bookings' )
				)
			)
		);
	}

	/**
	 * Personal data export (paged, 50 bookings at a time).
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		global $wpdb;
		$email = self::key( $email );
		$per   = 50;
		$b     = OSB_Install::table( 'bookings' );
		$items = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $b WHERE LOWER(customer_email) = %s ORDER BY id ASC LIMIT %d, %d", $email, ( max( 1, (int) $page ) - 1 ) * $per, $per ) );
		$form = array();
		foreach ( OSB_Settings::form_fields() as $f ) {
			$form[ $f['key'] ] = $f['label'];
		}
		foreach ( $rows as $bk ) {
			$d    = OSB_Bookings::details( $bk );
			$data = array(
				array( 'name' => __( 'Booking', 'osam-bookings' ), 'value' => '#' . $bk->id ),
				array( 'name' => __( 'For', 'osam-bookings' ), 'value' => $d['item_name'] ),
				array( 'name' => __( 'When', 'osam-bookings' ), 'value' => $d['date'] . ' ' . $d['time'] ),
				array( 'name' => __( 'Status', 'osam-bookings' ), 'value' => $d['status'] ),
				array( 'name' => __( 'Name', 'osam-bookings' ), 'value' => $bk->customer_name ),
				array( 'name' => __( 'Email', 'osam-bookings' ), 'value' => $bk->customer_email ),
				array( 'name' => __( 'Phone', 'osam-bookings' ), 'value' => $bk->customer_phone ),
				array( 'name' => __( 'Amount', 'osam-bookings' ), 'value' => $d['amount'] . ' (' . $d['payment_status'] . ')' ),
				array( 'name' => __( 'Booked on', 'osam-bookings' ), 'value' => $bk->created_at ),
			);
			foreach ( array_slice( OSB_Bookings::attendees( $bk ), 1 ) as $g ) {
				if ( ! $g['same'] ) {
					/* translators: %d place */
					$data[] = array( 'name' => sprintf( __( 'Place %d', 'osam-bookings' ), $g['place'] ), 'value' => trim( $g['name'] . ( $g['email'] ? ' <' . $g['email'] . '>' : '' ) ) );
				}
			}
			foreach ( OSB_Bookings::fields( $bk ) as $k => $v ) {
				if ( in_array( $k, array( 'name', 'email', 'phone' ), true ) || '' === (string) $v || is_array( $v ) ) {
					continue;
				}
				$data[] = array( 'name' => isset( $form[ $k ] ) ? $form[ $k ] : $k, 'value' => (string) $v );
			}
			$items[] = array(
				'group_id'    => 'osb-bookings',
				'group_label' => __( 'Bookings', 'osam-bookings' ),
				'item_id'     => 'osb-booking-' . $bk->id,
				'data'        => $data,
			);
		}
		if ( 1 === (int) $page ) {
			$w = OSB_Install::table( 'waitlist' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $w WHERE LOWER(email) = %s", $email ) ) as $wl ) {
				$items[] = array(
					'group_id'    => 'osb-waitlist',
					'group_label' => __( 'Waitlist', 'osam-bookings' ),
					'item_id'     => 'osb-waitlist-' . $wl->id,
					'data'        => array(
						array( 'name' => __( 'Name', 'osam-bookings' ), 'value' => $wl->name ),
						array( 'name' => __( 'Email', 'osam-bookings' ), 'value' => $wl->email ),
						array( 'name' => __( 'Phone', 'osam-bookings' ), 'value' => $wl->phone ),
						array( 'name' => __( 'Joined', 'osam-bookings' ), 'value' => $wl->created_at ),
						array( 'name' => __( 'Status', 'osam-bookings' ), 'value' => $wl->status ),
					),
				);
			}
			$c   = OSB_Install::table( 'customers' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $c WHERE LOWER(email) = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $row && ( $row->notes || $row->tags ) ) {
				$items[] = array(
					'group_id'    => 'osb-customer',
					'group_label' => __( 'Customer record', 'osam-bookings' ),
					'item_id'     => 'osb-customer-' . $row->id,
					'data'        => array(
						array( 'name' => __( 'Notes', 'osam-bookings' ), 'value' => (string) $row->notes ),
						array( 'name' => __( 'Tags', 'osam-bookings' ), 'value' => (string) $row->tags ),
					),
				);
			}
		}
		return array(
			'data' => $items,
			'done' => count( $rows ) < $per,
		);
	}

	/**
	 * Erase (anonymise) a person's data. Upcoming bookings are kept so they aren't lost
	 * by accident — cancel them first. Past bookings keep their date, item and amount for
	 * the accounts, with every personal detail removed.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (all done in one pass).
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$email    = self::key( $email );
		$removed  = false;
		$retained = false;
		$messages = array();
		if ( ! is_email( $email ) ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		$b    = OSB_Install::table( 'bookings' );
		$now  = current_time( 'mysql' );
		$anon = 'erased-' . strtolower( wp_generate_password( 12, false, false ) ) . '@' . self::ERASED_DOMAIN;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $b WHERE LOWER(customer_email) = %s", $email ) ) as $bk ) {
			if ( in_array( $bk->status, array( 'pending', 'confirmed', 'pending_payment' ), true ) && $bk->end_at > $now ) {
				$retained = true;
				/* translators: 1: booking id 2: date */
				$messages[] = sprintf( __( 'Booking #%1$d on %2$s is still upcoming, so it was kept. Cancel it and erase again to remove it.', 'osam-bookings' ), $bk->id, mysql2date( get_option( 'date_format' ), $bk->start_at ) );
				continue;
			}
			$wpdb->update(
				$b,
				array(
					'customer_name'  => __( 'Erased customer', 'osam-bookings' ),
					'customer_email' => $anon,
					'customer_phone' => '',
					'fields'         => wp_json_encode( array() ),
					'guests'         => null,
					'internal_note'  => '',
					'token'          => wp_generate_password( 32, false, false ),
					'meeting_url'    => '',
					'updated_at'     => $now,
				),
				array( 'id' => $bk->id )
			);
			// Gateway data can hold names and addresses; the amount and reference stay for the accounts.
			$wpdb->update( OSB_Install::table( 'payments' ), array( 'data' => '' ), array( 'booking_id' => $bk->id ) );
			$wpdb->update( OSB_Install::table( 'email_log' ), array( 'recipient' => $anon, 'subject' => '' ), array( 'booking_id' => $bk->id ) );
			$removed = true;
		}
		$w = OSB_Install::table( 'waitlist' );
		if ( $wpdb->query( $wpdb->prepare( "DELETE FROM $w WHERE LOWER(email) = %s", $email ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$removed = true;
		}
		$u = OSB_Install::table( 'coupon_uses' );
		if ( $wpdb->query( $wpdb->prepare( "UPDATE $u SET email = %s WHERE LOWER(email) = %s", $anon, $email ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$removed = true;
		}
		if ( ! $retained ) {
			$c = OSB_Install::table( 'customers' );
			if ( $wpdb->query( $wpdb->prepare( "DELETE FROM $c WHERE LOWER(email) = %s", $email ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$removed = true;
			}
		}
		OSB_Availability::bust_cache();
		do_action( 'osb_customer_erased', $email, $removed, $retained );
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * CSV rows of every customer.
	 *
	 * @param array $args Filters (search, tag).
	 * @return array
	 */
	public static function csv_rows( array $args = array() ) {
		$out  = array(
			array(
				__( 'Name', 'osam-bookings' ),
				__( 'Email', 'osam-bookings' ),
				__( 'Phone', 'osam-bookings' ),
				__( 'Bookings', 'osam-bookings' ),
				__( 'Visits', 'osam-bookings' ),
				__( 'No-shows', 'osam-bookings' ),
				__( 'Cancelled', 'osam-bookings' ),
				__( 'Total spent', 'osam-bookings' ),
				__( 'First booked', 'osam-bookings' ),
				__( 'Last visit', 'osam-bookings' ),
				__( 'Next booking', 'osam-bookings' ),
				__( 'Tags', 'osam-bookings' ),
				__( 'Notes', 'osam-bookings' ),
			),
		);
		$page = 1;
		do {
			list( $rows ) = self::query( array_merge( $args, array( 'page' => $page, 'per_page' => 500, 'orderby' => 'name', 'order' => 'asc' ) ) );
			foreach ( $rows as $r ) {
				$out[] = array( $r->name, $r->email, $r->phone, (int) $r->bookings, (int) $r->visits, (int) $r->no_shows, (int) $r->cancelled, number_format( (float) $r->spent, 2, '.', '' ), $r->first_at, (string) $r->last_visit, (string) $r->next_at, implode( ', ', $r->tags ), $r->notes );
			}
			++$page;
		} while ( count( $rows ) === 500 );
		return $out;
	}
}
