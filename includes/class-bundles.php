<?php
/**
 * Bundles: packs of sessions or credits sold in advance (e.g. "10 dog-field sessions for £80").
 *
 * - A bundle (osb_bundles) is the product: credits, price, how long it lasts, what it can be used for.
 * - Buying one is a booking of type "bundle", so it goes through the normal payment methods. When that
 *   booking is paid (or free), a pass (osb_passes) is made active and its code emailed to the customer.
 * - Customers enter the code at checkout. Each booking takes one credit (or one per place, if the bundle
 *   counts places); add-ons are still charged. Cancelled bookings and failed payments give the credit back.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bundles and passes.
 */
class OSB_Bundles {

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $all = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'osb_booking_details', array( __CLASS__, 'details' ), 12, 2 );
		add_filter( 'osb_details_card_rows', array( __CLASS__, 'card_rows' ), 8, 3 );
		add_filter( 'osb_email_placeholders', array( __CLASS__, 'placeholders' ), 15, 2 );
		add_action( 'osb_booking_paid', array( __CLASS__, 'on_paid' ), 5 );
		add_action( 'osb_booking_admin_updated', array( __CLASS__, 'on_admin_updated' ), 5 );
		add_action( 'osb_booking_status_changed', array( __CLASS__, 'on_status' ), 5, 3 );
		add_action( 'osb_booking_created', array( __CLASS__, 'on_created' ), 5, 2 );
	}

	/**
	 * Feature on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return OSB_Settings::feature( 'bundles' );
	}

	/* ---------------------------------------------------------------------
	 * Bundles (products)
	 * ------------------------------------------------------------------ */

	/**
	 * Every bundle.
	 *
	 * @param bool $active_only On sale only.
	 * @return array
	 */
	public static function all( $active_only = false ) {
		if ( null === self::$all ) {
			global $wpdb;
			$t         = OSB_Install::table( 'bundles' );
			self::$all = (array) $wpdb->get_results( "SELECT * FROM $t ORDER BY sort_order ASC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $active_only ? array_values( array_filter( self::$all, static function ( $b ) { return 'active' === $b->status; } ) ) : self::$all;
	}

	/**
	 * Forget cached rows.
	 */
	public static function flush() {
		self::$all = null;
	}

	/**
	 * One.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $b ) {
			if ( (int) $b->id === (int) $id ) {
				return $b;
			}
		}
		return null;
	}

	/**
	 * "10 sessions", "10 places".
	 *
	 * @param int    $n    Credits.
	 * @param string $unit booking|place.
	 * @return string
	 */
	public static function credits_label( $n, $unit = 'booking' ) {
		/* translators: %d count */
		return 'place' === $unit ? sprintf( _n( '%d place', '%d places', $n, 'osam-bookings' ), $n ) : sprintf( _n( '%d session', '%d sessions', $n, 'osam-bookings' ), $n );
	}

	/**
	 * "Valid for 6 months".
	 *
	 * @param int $days Days.
	 * @return string
	 */
	public static function validity_label( $days ) {
		$days = (int) $days;
		if ( $days <= 0 ) {
			return __( 'No expiry', 'osam-bookings' );
		}
		if ( 0 === $days % 365 ) {
			/* translators: %d years */
			return sprintf( _n( 'Valid for %d year', 'Valid for %d years', $days / 365, 'osam-bookings' ), $days / 365 );
		}
		if ( 0 === $days % 30 ) {
			/* translators: %d months */
			return sprintf( _n( 'Valid for %d month', 'Valid for %d months', $days / 30, 'osam-bookings' ), $days / 30 );
		}
		/* translators: %d days */
		return sprintf( _n( 'Valid for %d day', 'Valid for %d days', $days, 'osam-bookings' ), $days );
	}

	/**
	 * Shaped for the booking form.
	 *
	 * @param object $b Bundle.
	 * @return array
	 */
	public static function json( $b ) {
		$methods = array();
		foreach ( OSB_Bookings::allowed_methods( $b ) as $id ) {
			$g = osb()->payments->get( $id );
			if ( $g ) {
				$methods[] = array(
					'id'          => $id,
					'title'       => $g->title(),
					'description' => $g->description(),
					'online'      => 'pending_payment' === $g->initial_status(),
				);
			}
		}
		$per = (int) $b->credits > 0 ? (float) $b->price / (int) $b->credits : 0;
		return array(
			'id'          => (int) $b->id,
			'name'        => $b->name,
			'description' => wp_kses_post( wpautop( (string) $b->description ) ),
			'credits'     => (int) $b->credits,
			'unit'        => $b->unit,
			'credits_label' => self::credits_label( (int) $b->credits, $b->unit ),
			'price'       => (float) $b->price,
			'price_label' => (float) $b->price > 0 ? OSB_Settings::money( $b->price ) : OSB_Settings::label( 'free' ),
			/* translators: %s price */
			'each_label'  => $per > 0 ? sprintf( __( '%s each', 'osam-bookings' ), OSB_Settings::money( $per ) ) : '',
			'validity'    => self::validity_label( (int) $b->valid_days ),
			'covers'      => OSB_Items::describe( OSB_Items::decode( $b->applies ) ),
			'image'       => $b->image_id ? wp_get_attachment_image_url( $b->image_id, 'medium' ) : '',
			'color'       => $b->color,
			'methods'     => $methods,
		);
	}

	/* ---------------------------------------------------------------------
	 * Passes (what customers hold)
	 * ------------------------------------------------------------------ */

	/**
	 * A new unguessable code: OSB-7KQ2-M9XD.
	 *
	 * @return string
	 */
	public static function new_code() {
		global $wpdb;
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$t     = OSB_Install::table( 'passes' );
		do {
			$code = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$code .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
			}
			$code = (string) apply_filters( 'osb_bundle_code', 'BND-' . substr( $code, 0, 4 ) . '-' . substr( $code, 4 ) );
		} while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE code = %s", $code ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $code;
	}

	/**
	 * Pass by code (case and dash insensitive).
	 *
	 * @param string $code Code.
	 * @return object|null
	 */
	public static function find( $code ) {
		global $wpdb;
		$norm = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
		if ( strlen( $norm ) < 6 ) {
			return null;
		}
		$t = OSB_Install::table( 'passes' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE REPLACE(UPPER(code), '-', '') = %s LIMIT 1", $norm ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Pass by id.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public static function pass( $id ) {
		return $id ? OSB_DB::get( 'passes', (int) $id ) : null;
	}

	/**
	 * Credits left.
	 *
	 * @param object $p Pass.
	 * @return int
	 */
	public static function left( $p ) {
		return max( 0, (int) $p->credits - (int) $p->used );
	}

	/**
	 * Credits a booking of this many places takes.
	 *
	 * @param object $p     Pass.
	 * @param int    $seats Places.
	 * @return int
	 */
	public static function needed( $p, $seats ) {
		return 'place' === $p->unit ? max( 1, (int) $seats ) : 1;
	}

	/**
	 * Can this pass pay for this booking?
	 *
	 * @param object|null $p      Pass.
	 * @param string[]    $tokens Item tokens.
	 * @param int         $needed Credits needed.
	 * @return true|WP_Error
	 */
	public static function usable( $p, array $tokens, $needed ) {
		if ( ! $p ) {
			return new WP_Error( 'osb_pass', __( 'We couldn’t find that bundle code.', 'osam-bookings' ) );
		}
		if ( 'pending' === $p->status ) {
			return new WP_Error( 'osb_pass', __( 'That bundle hasn’t been paid for yet.', 'osam-bookings' ) );
		}
		if ( 'active' !== $p->status ) {
			return new WP_Error( 'osb_pass', __( 'That bundle is no longer active.', 'osam-bookings' ) );
		}
		if ( $p->expires_at && $p->expires_at < current_time( 'Y-m-d' ) ) {
			/* translators: %s date */
			return new WP_Error( 'osb_pass', sprintf( __( 'That bundle expired on %s.', 'osam-bookings' ), date_i18n( get_option( 'date_format' ), strtotime( $p->expires_at ) ) ) );
		}
		if ( ! OSB_Items::covers( OSB_Items::decode( $p->applies ), $tokens ) ) {
			return new WP_Error( 'osb_pass', __( 'That bundle can’t be used for this booking.', 'osam-bookings' ) );
		}
		if ( self::left( $p ) < $needed ) {
			/* translators: 1: credits left 2: needed */
			return new WP_Error( 'osb_pass', sprintf( __( 'That bundle has %1$d left — this booking needs %2$d.', 'osam-bookings' ), self::left( $p ), $needed ) );
		}
		return true;
	}

	/**
	 * Make a pass.
	 *
	 * @param object $bundle  Bundle.
	 * @param array  $args    name, email, booking_id, amount, status, note.
	 * @return int Pass id.
	 */
	public static function issue( $bundle, array $args ) {
		$args = wp_parse_args( $args, array( 'name' => '', 'email' => '', 'booking_id' => 0, 'amount' => 0, 'status' => 'pending', 'note' => '' ) );
		$id   = OSB_DB::insert(
			'passes',
			array(
				'bundle_id'      => (int) $bundle->id,
				'code'           => self::new_code(),
				'name'           => $bundle->name,
				'customer_name'  => $args['name'],
				'customer_email' => $args['email'],
				'credits'        => (int) $bundle->credits,
				'used'           => 0,
				'unit'           => $bundle->unit,
				'applies'        => $bundle->applies,
				'status'         => 'pending',
				'booking_id'     => (int) $args['booking_id'],
				'amount'         => (float) $args['amount'],
				'note'           => $args['note'],
				'created_at'     => current_time( 'mysql' ),
			)
		);
		if ( $id && 'active' === $args['status'] ) {
			self::activate( $id );
		}
		return (int) $id;
	}

	/**
	 * Switch a pass on (start its validity) and email the code.
	 *
	 * @param int  $pass_id Pass.
	 * @param bool $email   Email the customer.
	 * @return bool
	 */
	public static function activate( $pass_id, $email = true ) {
		$p = self::pass( $pass_id );
		if ( ! $p || 'pending' !== $p->status ) {
			return false;
		}
		$bundle = self::get( $p->bundle_id );
		$days   = $bundle ? (int) $bundle->valid_days : 0;
		OSB_DB::update(
			'passes',
			$p->id,
			array(
				'status'     => 'active',
				'expires_at' => $days > 0 ? gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) + $days * DAY_IN_SECONDS ) : null,
			)
		);
		$booking = $p->booking_id ? OSB_DB::get( 'bookings', $p->booking_id ) : null;
		if ( $email && $booking && is_email( $booking->customer_email ) ) {
			OSB_Emails::send( 'customer_bundle', $booking, $booking->customer_email );
		}
		do_action( 'osb_bundle_activated', self::pass( $p->id ) );
		return true;
	}

	/**
	 * Take credits for a booking.
	 *
	 * @param object $p          Pass.
	 * @param int    $booking_id Booking.
	 * @param int    $credits    Credits.
	 */
	public static function spend( $p, $booking_id, $credits ) {
		global $wpdb;
		$t = OSB_Install::table( 'passes' );
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET used = used + %d WHERE id = %d", (int) $credits, (int) $p->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		OSB_DB::insert(
			'pass_uses',
			array(
				'pass_id'    => (int) $p->id,
				'booking_id' => (int) $booking_id,
				'credits'    => (int) $credits,
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Give back the credits a booking took.
	 *
	 * @param int $booking_id Booking.
	 * @return int Credits returned.
	 */
	public static function refund_booking( $booking_id ) {
		global $wpdb;
		$u    = OSB_Install::table( 'pass_uses' );
		$t    = OSB_Install::table( 'passes' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $u WHERE booking_id = %d AND refunded = 0", $booking_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$back = 0;
		foreach ( $rows as $r ) {
			$wpdb->update( $u, array( 'refunded' => 1 ), array( 'id' => $r->id ) );
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET used = GREATEST(0, used - %d) WHERE id = %d", (int) $r->credits, (int) $r->pass_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$back += (int) $r->credits;
		}
		return $back;
	}

	/**
	 * Hand adjustment by staff (+/-).
	 *
	 * @param int    $pass_id Pass.
	 * @param int    $delta   Credits to add (negative to take).
	 * @param string $note    Why.
	 */
	public static function adjust( $pass_id, $delta, $note = '' ) {
		global $wpdb;
		$t = OSB_Install::table( 'passes' );
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET credits = GREATEST(used, credits + %d) WHERE id = %d", (int) $delta, (int) $pass_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		OSB_DB::insert(
			'pass_uses',
			array(
				'pass_id'    => (int) $pass_id,
				'booking_id' => 0,
				'credits'    => -1 * (int) $delta,
				'note'       => substr( sanitize_text_field( $note ? $note : __( 'Adjusted by staff', 'osam-bookings' ) ), 0, 191 ),
				'refunded'   => 1,
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * History of a pass.
	 *
	 * @param int $pass_id Pass.
	 * @return array
	 */
	public static function uses( $pass_id ) {
		global $wpdb;
		$u = OSB_Install::table( 'pass_uses' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $u WHERE pass_id = %d ORDER BY id DESC", $pass_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Pass bought by a booking.
	 *
	 * @param int $booking_id Purchase booking.
	 * @return object|null
	 */
	public static function pass_for_purchase( $booking_id ) {
		global $wpdb;
		$t = OSB_Install::table( 'passes' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE booking_id = %d ORDER BY id DESC LIMIT 1", $booking_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ---------------------------------------------------------------------
	 * Booking hooks
	 * ------------------------------------------------------------------ */

	/**
	 * Row for buying a bundle (OSB_Bookings::create()).
	 *
	 * @param array $data Data.
	 * @return array|WP_Error [ row, bundle ]
	 */
	public static function booking_row( array $data ) {
		$b = self::get( (int) ( $data['bundle_id'] ?? 0 ) );
		if ( ! $b || ( 'active' !== $b->status && empty( $data['admin'] ) ) ) {
			return new WP_Error( 'osb_bundle', __( 'That bundle isn’t available.', 'osam-bookings' ) );
		}
		$now = current_time( 'mysql' );
		return array(
			array(
				'bundle_id' => (int) $b->id,
				'start_at'  => $now,
				'end_at'    => $now,
				'seats'     => 1,
				'amount'    => round( (float) $b->price, 2 ),
			),
			$b,
		);
	}

	/**
	 * After a booking exists: make the pass for a purchase.
	 *
	 * @param object $booking Booking.
	 * @param array  $data    Data.
	 */
	public static function on_created( $booking, $data ) {
		if ( 'bundle' !== $booking->type ) {
			return;
		}
		$b = self::get( $booking->bundle_id );
		if ( ! $b ) {
			return;
		}
		$id = self::issue(
			$b,
			array(
				'name'       => $booking->customer_name,
				'email'      => $booking->customer_email,
				'booking_id' => $booking->id,
				'amount'     => $booking->amount,
			)
		);
		if ( in_array( $booking->payment_status, array( 'paid', 'free' ), true ) && in_array( $booking->status, array( 'confirmed', 'completed' ), true ) ) {
			self::activate( $id, ! empty( $data['notify'] ) );
		}
	}

	/**
	 * Paid online.
	 *
	 * @param object $booking Booking.
	 */
	public static function on_paid( $booking ) {
		if ( 'bundle' !== $booking->type ) {
			return;
		}
		$p = self::pass_for_purchase( $booking->id );
		if ( $p ) {
			self::activate( $p->id );
		}
	}

	/**
	 * Staff marked a pay-on-site purchase as paid.
	 *
	 * @param object $booking Booking.
	 */
	public static function on_admin_updated( $booking ) {
		$booking = is_object( $booking ) ? OSB_DB::get( 'bookings', $booking->id ) : OSB_DB::get( 'bookings', (int) $booking );
		if ( $booking && 'bundle' === $booking->type && in_array( $booking->payment_status, array( 'paid', 'free' ), true ) && ! in_array( $booking->status, array( 'cancelled', 'payment_failed' ), true ) ) {
			$p = self::pass_for_purchase( $booking->id );
			if ( $p ) {
				self::activate( $p->id );
			}
		}
	}

	/**
	 * Cancelled / payment failed: give credits back, or cancel an unused pass.
	 *
	 * @param object $booking Booking.
	 * @param string $old     Old status.
	 * @param string $new     New status.
	 */
	public static function on_status( $booking, $old, $new ) {
		if ( in_array( $new, array( 'cancelled', 'payment_failed' ), true ) ) {
			if ( (int) $booking->pass_id ) {
				self::refund_booking( $booking->id );
			}
			if ( 'bundle' === $booking->type ) {
				$p = self::pass_for_purchase( $booking->id );
				if ( $p && in_array( $p->status, array( 'pending', 'active' ), true ) && 0 === (int) $p->used ) {
					OSB_DB::update( 'passes', $p->id, array( 'status' => 'cancelled' ) );
				}
			}
		} elseif ( in_array( $old, array( 'cancelled', 'payment_failed' ), true ) && (int) $booking->pass_id ) {
			// Brought back (e.g. a late payment): take the credit again if there's one left.
			$p = self::pass( $booking->pass_id );
			if ( $p && self::left( $p ) >= self::needed( $p, $booking->seats ) ) {
				self::spend( $p, $booking->id, self::needed( $p, $booking->seats ) );
			}
		}
		if ( 'bundle' === $booking->type && 'confirmed' === $new && in_array( $booking->payment_status, array( 'paid', 'free' ), true ) ) {
			$p = self::pass_for_purchase( $booking->id );
			if ( $p ) {
				self::activate( $p->id );
			}
		}
	}

	/**
	 * Details.
	 *
	 * @param array  $d       Details.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function details( $d, $booking ) {
		if ( 'bundle' === $booking->type ) {
			$b                = self::get( $booking->bundle_id );
			$p                = self::pass_for_purchase( $booking->id );
			$d['item_name']   = $b ? $b->name : __( 'Bundle', 'osam-bookings' );
			$d['date']        = date_i18n( get_option( 'date_format' ), strtotime( $booking->created_at ) );
			$d['time']        = '';
			$d['end_time']    = '';
			$d['bundle']      = $p ? array(
				'code'    => 'active' === $p->status ? $p->code : '',
				'status'  => $p->status,
				'credits' => self::credits_label( (int) $p->credits, $p->unit ),
				'left'    => self::left( $p ),
				'expires' => $p->expires_at ? date_i18n( get_option( 'date_format' ), strtotime( $p->expires_at ) ) : '',
				'covers'  => OSB_Items::describe( OSB_Items::decode( $p->applies ) ),
				'pending' => 'pending' === $p->status ? __( 'Your bundle code will be emailed as soon as payment is received.', 'osam-bookings' ) : '',
			) : null;
			$d['can_cancel']     = false;
			$d['can_reschedule'] = false;
		} elseif ( (int) $booking->pass_id ) {
			$p = self::pass( $booking->pass_id );
			if ( $p ) {
				/* translators: 1: bundle name 2: code 3: left */
				$d['pass_label'] = sprintf( __( '%1$s (%2$s) — %3$d left', 'osam-bookings' ), $p->name, $p->code, self::left( $p ) );
			}
		}
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
		if ( 'bundle' === $booking->type && ! empty( $d['bundle'] ) ) {
			$bd   = $d['bundle'];
			$keep = array();
			foreach ( $rows as $r ) {
				if ( __( 'When', 'osam-bookings' ) !== $r[0] ) {
					$keep[] = $r;
				}
			}
			$rows = $keep;
			array_unshift( $rows, array( __( 'Bought', 'osam-bookings' ), $d['date'] ) );
			$rows[] = array( __( 'Includes', 'osam-bookings' ), $bd['credits'] . ' · ' . $bd['covers'] );
			if ( $bd['code'] ) {
				$rows[] = array( __( 'Your code', 'osam-bookings' ), '<strong style="font-size:18px;letter-spacing:.08em;font-family:Menlo,Consolas,monospace">' . esc_html( $bd['code'] ) . '</strong>', true );
				/* translators: %d credits left */
				$rows[] = array( __( 'Left', 'osam-bookings' ), (string) $bd['left'] . ( $bd['expires'] ? ' · ' . sprintf( __( 'use by %s', 'osam-bookings' ), $bd['expires'] ) : '' ) );
			}
		} elseif ( ! empty( $d['pass_label'] ) ) {
			$rows[] = array( __( 'Paid with', 'osam-bookings' ), $d['pass_label'] );
		}
		return $rows;
	}

	/**
	 * {bundle_code}, {bundle_left}, {bundle_expires}, {bundle_name}, {bundle_button}.
	 *
	 * @param array  $map     Placeholders.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function placeholders( $map, $booking ) {
		$p = 'bundle' === $booking->type ? self::pass_for_purchase( $booking->id ) : self::pass( (int) $booking->pass_id );
		$map['{bundle_code}']    = $p && 'active' === $p->status ? esc_html( $p->code ) : '';
		$map['{bundle_left}']    = $p ? (string) self::left( $p ) : '';
		$map['{bundle_name}']    = $p ? esc_html( $p->name ) : '';
		$map['{bundle_expires}'] = $p && $p->expires_at ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $p->expires_at ) ) ) : '';
		$map['{bundle_covers}']  = $p ? esc_html( OSB_Items::describe( OSB_Items::decode( $p->applies ) ) ) : '';
		$ds                      = OSB_Emails::design();
		$map['{bundle_code_box}'] = $p && 'active' === $p->status ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 24px;background:' . $ds['panel_bg'] . ';border-radius:' . $ds['radius'] . 'px;border:2px dashed ' . $ds['accent'] . '"><tr><td style="padding:18px 22px;text-align:center"><div style="font-size:13px;color:' . $ds['muted'] . '">' . esc_html__( 'Your bundle code', 'osam-bookings' ) . '</div><div style="font-size:26px;font-weight:700;letter-spacing:.12em;font-family:Menlo,Consolas,monospace;color:' . $ds['text'] . '">' . esc_html( $p->code ) . '</div><div style="font-size:14px;margin-top:6px">' . esc_html( self::credits_label( self::left( $p ), $p->unit ) ) . ( $p->expires_at ? ' · ' . esc_html( sprintf( /* translators: %s date */ __( 'use by %s', 'osam-bookings' ), date_i18n( get_option( 'date_format' ), strtotime( $p->expires_at ) ) ) ) : '' ) . '</div></td></tr></table>' : '';
		return $map;
	}
}
