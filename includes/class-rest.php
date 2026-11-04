<?php
/**
 * REST API used by the booking form, webhooks and the admin calendar.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /wp-json/osb/v1.
 */
class OSB_Rest {

	const NS = 'osb/v1';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function routes() {
		$public = '__return_true';
		$admin  = static function () {
			return current_user_can( 'osb_manage_bookings' );
		};

		register_rest_route( self::NS, '/services', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'services' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/staff', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'staff' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/availability/month', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'month' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/availability/day', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'day' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/events', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'events' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/waitlist', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'join_waitlist' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/waitlist/(?P<token>[A-Za-z0-9]{20,64})', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_waitlist' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/coupons/check', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check_coupon' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/courses', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'courses' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/bookings', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_booking' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/repeat/preview', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'repeat_preview' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/bundles', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'bundles' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/bundles/check', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'check_pass' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/bookings/(?P<token>[A-Za-z0-9]{20,64})', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_booking' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/bookings/(?P<token>[A-Za-z0-9]{20,64})/cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'cancel_booking' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/bookings/(?P<token>[A-Za-z0-9]{20,64})/month', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'reschedule_month' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/bookings/(?P<token>[A-Za-z0-9]{20,64})/day', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'reschedule_day' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/bookings/(?P<token>[A-Za-z0-9]{20,64})/reschedule', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'reschedule' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/webhooks/stripe', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'stripe_webhook' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/admin/calendar', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'admin_calendar' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/admin/slots', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'admin_slots' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/admin/check', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'admin_check' ), 'permission_callback' => $admin ) );
		register_rest_route( self::NS, '/admin/email-preview', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'email_preview' ), 'permission_callback' => $admin ) );
		register_rest_route(
			self::NS,
			'/admin/custom-email-preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'custom_email_preview' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/admin/custom-email-test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'custom_email_test' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Gateways allowed for an item, shaped for JS.
	 *
	 * @param object $item Service/event.
	 * @return array
	 */
	private static function methods_for( $item ) {
		$out = array();
		foreach ( OSB_Bookings::allowed_methods( $item ) as $id ) {
			$g     = osb()->payments->get( $id );
			$out[] = array(
				'id'          => $id,
				'title'       => $g->title(),
				'description' => $g->description(),
				'online'      => 'pending_payment' === $g->initial_status(),
			);
		}
		return $out;
	}

	/**
	 * Price label.
	 *
	 * @param float $price Price.
	 * @return string
	 */
	private static function price_label( $price ) {
		return $price > 0 ? OSB_Settings::money( $price ) : OSB_Settings::label( 'free' );
	}

	/**
	 * GET /services.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function services( WP_REST_Request $request ) {
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) );
		$cat = sanitize_text_field( (string) $request->get_param( 'category' ) );
		$out = array();
		foreach ( OSB_DB::services( true ) as $s ) {
			if ( $ids && ! in_array( (int) $s->id, $ids, true ) ) {
				continue;
			}
			if ( $cat && 0 !== strcasecmp( $cat, $s->category ) ) {
				continue;
			}
			$staff = OSB_DB::service_staff_ids( $s->id );
			if ( ! $staff ) {
				continue; // Nobody can deliver it.
			}
			$out[] = array(
				'id'          => (int) $s->id,
				'name'        => $s->name,
				'description' => wp_kses_post( wpautop( (string) $s->description ) ),
				'category'    => $s->category,
				'duration'    => (int) $s->duration,
				'online'      => OSB_Meetings::is_online( $s ),
				'price'       => (float) $s->price,
				'price_label' => self::price_label( (float) $s->price ),
				'capacity'    => (int) $s->capacity,
				'color'       => $s->color,
				'image'       => $s->image_id ? wp_get_attachment_image_url( $s->image_id, 'medium' ) : '',
				'staff'       => $staff,
				'methods'     => self::methods_for( $s ),
			);
		}
		return rest_ensure_response( apply_filters( 'osb_rest_services', $out ) );
	}

	/**
	 * GET /staff?service_id=.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function staff( WP_REST_Request $request ) {
		$service = OSB_DB::get( 'services', absint( $request->get_param( 'service_id' ) ) );
		if ( ! $service ) {
			return rest_ensure_response( array() );
		}
		$out = array();
		foreach ( OSB_DB::staff_for_service( $service->id ) as $st ) {
			$price = OSB_DB::service_price( $service, $st->id );
			$out[] = array(
				'id'          => (int) $st->id,
				'name'        => $st->name,
				'description' => wp_kses_post( (string) $st->description ),
				'image'       => $st->image_id ? wp_get_attachment_image_url( $st->image_id, 'thumbnail' ) : '',
				'price'       => $price,
				'price_label' => self::price_label( $price ),
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * GET /availability/month.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function month( WP_REST_Request $request ) {
		$dates = OSB_Availability::month_dates(
			absint( $request->get_param( 'service_id' ) ),
			absint( $request->get_param( 'staff_id' ) ),
			sanitize_text_field( (string) $request->get_param( 'month' ) )
		);
		list( $earliest, $latest ) = OSB_Availability::window();
		return rest_ensure_response(
			array(
				'dates' => $dates,
				'min'   => $earliest->format( 'Y-m-d' ),
				'max'   => $latest->format( 'Y-m-d' ),
			)
		);
	}

	/**
	 * GET /availability/day.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function day( WP_REST_Request $request ) {
		$slots = OSB_Availability::day_slots(
			absint( $request->get_param( 'service_id' ) ),
			absint( $request->get_param( 'staff_id' ) ),
			sanitize_text_field( (string) $request->get_param( 'date' ) ),
			max( 1, absint( $request->get_param( 'seats' ) ) )
		);
		$fmt = get_option( 'time_format' );
		$out = array();
		foreach ( $slots as $slot ) {
			$out[] = array(
				'time'       => $slot['time'],
				'label'      => date_i18n( $fmt, strtotime( '2000-01-01 ' . $slot['time'] ) ),
				'seats_left' => $slot['seats_left'],
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * GET /events.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function events( WP_REST_Request $request ) {
		if ( ! OSB_Settings::feature( 'events' ) ) {
			return rest_ensure_response( array() );
		}
		self::waitlist_holder( $request );
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) );
		$out = array();
		foreach ( OSB_DB::upcoming_events( 200 ) as $e ) {
			if ( $ids && ! in_array( (int) $e->id, $ids, true ) ) {
				continue;
			}
			$start = strtotime( $e->start_at );
			$end   = strtotime( $e->end_at );
			$left  = max( 0, (int) $e->capacity - OSB_DB::event_seats_taken( $e->id, true ) );
			$out[] = array(
				'id'              => (int) $e->id,
				'name'            => $e->name,
				'description'     => wp_kses_post( wpautop( (string) $e->description ) ),
				'location'        => '' === (string) $e->location && OSB_Meetings::is_online( $e ) ? __( 'Online', 'osam-bookings' ) : $e->location,
				'start'           => $e->start_at,
				'day'             => date_i18n( 'j', $start ),
				'month'           => date_i18n( 'M', $start ),
				'date_label'      => date_i18n( get_option( 'date_format' ), $start ),
				'time_label'      => date_i18n( get_option( 'time_format' ), $start ) . ' – ' . date_i18n( get_option( 'time_format' ), $end ),
				'price'           => (float) $e->price,
				'price_label'     => self::price_label( (float) $e->price ),
				'seats_left'      => $left,
				'max_per_booking' => max( 1, min( (int) $e->max_per_booking, $left ) ),
				'color'           => $e->color,
				'image'           => $e->image_id ? wp_get_attachment_image_url( $e->image_id, 'medium' ) : '',
				'methods'         => self::methods_for( $e ),
				'waitlist'        => $left < 1 && OSB_Waitlist::enabled(),
			);
		}
		return rest_ensure_response( apply_filters( 'osb_rest_events', $out ) );
	}

	/**
	 * Someone arriving from a waitlist offer sees their held place as free.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function waitlist_holder( WP_REST_Request $request ) {
		$w = OSB_Waitlist::by_token( (string) $request->get_param( 'waitlist' ) );
		if ( $w && 'offered' === $w->status && $w->expires_at > current_time( 'mysql' ) ) {
			OSB_Waitlist::$using = (int) $w->id;
		}
	}

	/**
	 * POST /waitlist — join.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function join_waitlist( WP_REST_Request $request ) {
		if ( ! self::throttle() ) {
			return new WP_Error( 'osb_rate', __( 'Too many attempts. Please wait a few minutes.', 'osam-bookings' ), array( 'status' => 429 ) );
		}
		$p = $request->get_json_params();
		$p = is_array( $p ) ? $p : $request->get_body_params();
		if ( ! empty( $p['website'] ) ) {
			return new WP_Error( 'osb_spam', __( 'Unable to process.', 'osam-bookings' ), array( 'status' => 400 ) );
		}
		$w = OSB_Waitlist::join( $p );
		if ( is_wp_error( $w ) ) {
			$w->add_data( array( 'status' => 400 ) );
			return $w;
		}
		$ahead = 0;
		foreach ( OSB_Waitlist::entries( $w->type, 'course' === $w->type ? (int) $w->intake_id : (int) $w->event_id ) as $e ) {
			if ( (int) $e->id === (int) $w->id ) {
				break;
			}
			++$ahead;
		}
		return rest_ensure_response(
			array(
				'status' => $w->status,
				'ahead'  => $ahead,
			)
		);
	}

	/**
	 * GET /waitlist/{token} — an offer, for the booking form to open on.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_waitlist( WP_REST_Request $request ) {
		$w = OSB_Waitlist::by_token( $request['token'] );
		if ( ! $w ) {
			return new WP_Error( 'osb_not_found', __( 'This waitlist link isn’t valid.', 'osam-bookings' ), array( 'status' => 404 ) );
		}
		$live = 'offered' === $w->status && $w->expires_at > current_time( 'mysql' );
		return rest_ensure_response(
			array(
				'type'    => $w->type,
				'id'      => (int) ( 'course' === $w->type ? $w->intake_id : $w->event_id ),
				'seats'   => (int) $w->seats,
				'name'    => $w->name,
				'email'   => $w->email,
				'phone'   => $w->phone,
				'live'    => $live,
				'status'  => $w->status,
				'expires' => $w->expires_at ? date_i18n( 'l j F, ' . get_option( 'time_format' ), strtotime( $w->expires_at ) ) : '',
			)
		);
	}

	/**
	 * GET /courses — every intake open for enrolment (or full), soonest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function courses( WP_REST_Request $request ) {
		if ( ! OSB_Settings::feature( 'courses' ) ) {
			return rest_ensure_response( array() );
		}
		self::waitlist_holder( $request );
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) );
		$out = array();
		foreach ( OSB_Courses::courses( true ) as $c ) {
			if ( $ids && ! in_array( (int) $c->id, $ids, true ) ) {
				continue;
			}
			foreach ( OSB_Courses::intakes( $c->id ) as $in ) {
				$reason = OSB_Courses::closed_reason( $in, $c );
				if ( '' !== $reason && 'full' !== $reason ) {
					continue; // Started, hidden or without dates: not shown.
				}
				$schedule = OSB_Courses::schedule( $in->id, $c );
				$start    = strtotime( $in->first_start );
				$left     = max( 0, OSB_Courses::capacity( $in, $c ) - OSB_Courses::seats_taken( $in->id, 0, true ) );
				$price    = OSB_Courses::price( $in, $c );
				$times    = array_unique( wp_list_pluck( $schedule, 'time_label' ) );
				$days     = array_unique(
					array_map(
						static function ( $s ) {
							return date_i18n( 'l', strtotime( $s['start'] ) );
						},
						$schedule
					)
				);
				$out[]    = array(
					'id'              => (int) $in->id,
					'course_id'       => (int) $c->id,
					'name'            => $c->name,
					'intake_name'     => '' !== trim( (string) $in->name ) ? $in->name : '',
					'description'     => wp_kses_post( wpautop( (string) $c->description ) ),
					'location'        => '' === (string) $c->location && OSB_Meetings::is_online( $c ) ? __( 'Online', 'osam-bookings' ) : $c->location,
					'start'           => $in->first_start,
					'day'             => date_i18n( 'j', $start ),
					'month'           => date_i18n( 'M', $start ),
					'date_label'      => date_i18n( get_option( 'date_format' ), $start ),
					'range_label'     => OSB_Courses::range_label( $in ),
					// "Tuesdays, 7:00 pm – 9:00 pm" when the pattern is regular.
					'pattern'         => ( 1 === count( $days ) && count( $schedule ) > 1 ? sprintf( /* translators: %s weekday */ __( '%ss', 'osam-bookings' ), reset( $days ) ) . ', ' : '' ) . ( 1 === count( $times ) ? reset( $times ) : '' ),
					'sessions'        => $schedule,
					'session_count'   => count( $schedule ),
					'price'           => $price,
					'price_label'     => self::price_label( $price ),
					'seats_left'      => $left,
					'max_per_booking' => max( 1, min( (int) $c->max_per_booking, $left ) ),
					'color'           => $c->color,
					'image'           => $c->image_id ? wp_get_attachment_image_url( $c->image_id, 'medium' ) : '',
					'methods'         => self::methods_for( $c ),
					'waitlist'        => $left < 1 && OSB_Waitlist::enabled(),
				);
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['start'], $b['start'] );
			}
		);
		return rest_ensure_response( apply_filters( 'osb_rest_courses', $out ) );
	}

	/**
	 * POST /coupons/check — what a code would take off.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function check_coupon( WP_REST_Request $request ) {
		if ( ! OSB_Settings::feature( 'coupons' ) ) {
			return new WP_Error( 'osb_off', __( 'Discount codes aren’t available.', 'osam-bookings' ), array( 'status' => 404 ) );
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'x';
		$key = 'osb_cc_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= (int) apply_filters( 'osb_coupon_check_limit', 25 ) ) {
			return new WP_Error( 'osb_rate', __( 'Too many attempts. Please wait a few minutes.', 'osam-bookings' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );

		$p     = $request->get_json_params();
		$p     = is_array( $p ) ? $p : $request->get_body_params();
		$type  = isset( $p['type'] ) && in_array( $p['type'], array( 'event', 'course' ), true ) ? $p['type'] : 'service';
		$seats = max( 1, isset( $p['seats'] ) ? absint( $p['seats'] ) : 1 );
		$sub   = 0;
		$id    = 0;
		if ( 'event' === $type ) {
			$e   = OSB_DB::get( 'events', isset( $p['event_id'] ) ? absint( $p['event_id'] ) : 0 );
			$sub = $e ? (float) $e->price * $seats : 0;
			$id  = $e ? (int) $e->id : 0;
		} elseif ( 'course' === $type ) {
			$in  = OSB_DB::get( 'course_intakes', isset( $p['intake_id'] ) ? absint( $p['intake_id'] ) : 0 );
			$co  = $in ? OSB_DB::get( 'courses', $in->course_id ) : null;
			$sub = $co ? OSB_Courses::price( $in, $co ) * $seats : 0;
			$id  = $co ? (int) $co->id : 0;
		} else {
			$sv  = OSB_DB::get( 'services', isset( $p['service_id'] ) ? absint( $p['service_id'] ) : 0 );
			$sub = $sv ? OSB_DB::service_price( $sv, isset( $p['staff_id'] ) ? absint( $p['staff_id'] ) : 0 ) * $seats : 0;
			$id  = $sv ? (int) $sv->id : 0;
		}
		$c   = OSB_Coupons::find( isset( $p['code'] ) ? (string) $p['code'] : '' );
		$off = OSB_Coupons::evaluate(
			$c,
			array(
				'type'     => $type,
				'item_id'  => $id,
				'subtotal' => $sub,
				'email'    => isset( $p['email'] ) ? sanitize_email( $p['email'] ) : '',
			)
		);
		if ( is_wp_error( $off ) ) {
			$off->add_data( array( 'status' => 400 ) );
			return $off;
		}
		return rest_ensure_response(
			array(
				'code'     => $c->code,
				'kind'     => $c->kind,
				'discount' => $off,
				'label'    => 'voucher' === $c->kind ? __( 'Gift voucher', 'osam-bookings' ) : OSB_Coupons::value_label( $c ),
				'total'    => max( 0, round( $sub - $off, 2 ) ),
			)
		);
	}

	/**
	 * Simple per-IP throttle.
	 *
	 * @return bool Allowed.
	 */
	private static function throttle() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'x';
		$key = 'osb_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= (int) apply_filters( 'osb_rate_limit', 15 ) ) {
			return false;
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * POST /bookings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_booking( WP_REST_Request $request ) {
		$p = $request->get_json_params();
		$p = is_array( $p ) ? $p : $request->get_body_params();

		if ( ! empty( $p['website'] ) ) { // Honeypot.
			return new WP_Error( 'osb_spam', __( 'Unable to process booking.', 'osam-bookings' ), array( 'status' => 400 ) );
		}
		if ( ! self::throttle() ) {
			return new WP_Error( 'osb_rate', __( 'Too many attempts. Please wait a few minutes.', 'osam-bookings' ), array( 'status' => 429 ) );
		}

		$return_url = isset( $p['return_url'] ) ? esc_url_raw( $p['return_url'] ) : '';
		$return_url = wp_validate_redirect( $return_url, home_url( '/' ) );
		$return_url = remove_query_arg( array( 'osb_booking', 'osb_outcome', 'osb_return', 'osb_result' ), $return_url );

		$booking = OSB_Bookings::create( self::booking_params( $p ) );
		if ( is_wp_error( $booking ) ) {
			$booking->add_data( array( 'status' => 400 ) );
			return $booking;
		}

		$response = array(
			'token'   => $booking->token,
			'status'  => $booking->status,
			'details' => OSB_Bookings::details( $booking ),
		);
		if ( ! empty( $booking->repeat_skipped ) ) {
			$response['skipped'] = array();
			foreach ( $booking->repeat_skipped as $date => $why ) {
				$response['skipped'][] = date_i18n( 'D j M', strtotime( $date . ' 12:00:00' ) );
			}
		}

		if ( 'pending_payment' === $booking->status ) {
			$gateway = osb()->payments->get( $booking->payment_method );
			$result  = $gateway ? $gateway->process( $booking, $return_url ) : new WP_Error( 'osb_gateway', __( 'Payment method unavailable.', 'osam-bookings' ) );
			if ( is_wp_error( $result ) ) {
				OSB_Bookings::set_status( $booking->id, 'cancelled', false );
				$result->add_data( array( 'status' => 400 ) );
				return $result;
			}
			if ( ! empty( $result['redirect'] ) ) {
				$response['redirect'] = $result['redirect'];
			}
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Booking data from a form post (POST /bookings and POST /repeat/preview).
	 *
	 * @param array $p Posted values.
	 * @return array
	 */
	private static function booking_params( array $p ) {
		$addons = array();
		foreach ( (array) ( $p['addons'] ?? array() ) as $id => $qty ) {
			if ( absint( $id ) && absint( $qty ) ) {
				$addons[ absint( $id ) ] = min( 999, absint( $qty ) );
			}
		}
		return array(
				'type'           => isset( $p['type'] ) ? sanitize_key( $p['type'] ) : 'service',
				'addons'         => $addons,
				'pass'           => isset( $p['pass'] ) ? sanitize_text_field( $p['pass'] ) : '',
				'bundle_id'      => isset( $p['bundle_id'] ) ? absint( $p['bundle_id'] ) : 0,
				'repeat'         => isset( $p['repeat'] ) && is_array( $p['repeat'] ) ? array( 'freq' => sanitize_key( $p['repeat']['freq'] ?? '' ), 'count' => absint( $p['repeat']['count'] ?? 0 ) ) : null,
				'service_id'     => isset( $p['service_id'] ) ? absint( $p['service_id'] ) : 0,
				'staff_id'       => isset( $p['staff_id'] ) ? absint( $p['staff_id'] ) : 0,
				'event_id'       => isset( $p['event_id'] ) ? absint( $p['event_id'] ) : 0,
				'intake_id'      => isset( $p['intake_id'] ) ? absint( $p['intake_id'] ) : 0,
				'date'           => isset( $p['date'] ) ? sanitize_text_field( $p['date'] ) : '',
				'time'           => isset( $p['time'] ) ? sanitize_text_field( $p['time'] ) : '',
				'seats'          => isset( $p['seats'] ) ? absint( $p['seats'] ) : 1,
				'fields'         => isset( $p['fields'] ) && is_array( $p['fields'] ) ? $p['fields'] : array(),
				'guests'         => isset( $p['guests'] ) && is_array( $p['guests'] ) ? array_slice( array_values( $p['guests'] ), 0, 200 ) : array(),
				'payment_method' => isset( $p['payment_method'] ) ? sanitize_key( $p['payment_method'] ) : '',
				'coupon'         => isset( $p['coupon'] ) ? sanitize_text_field( $p['coupon'] ) : '',
				'waitlist'       => isset( $p['waitlist'] ) ? sanitize_text_field( $p['waitlist'] ) : '',
				'area_id'        => isset( $p['area_id'] ) ? absint( $p['area_id'] ) : 0,
				'resource_ids'   => isset( $p['resource_ids'] ) && is_array( $p['resource_ids'] ) ? array_slice( array_map( 'absint', $p['resource_ids'] ), 0, 20 ) : array(),
				'hire'           => isset( $p['hire'] ) ? sanitize_key( $p['hire'] ) : 'time',
				'minutes'        => isset( $p['minutes'] ) ? absint( $p['minutes'] ) : 0,
				'half'           => isset( $p['half'] ) ? sanitize_key( $p['half'] ) : '',
				'days'           => isset( $p['days'] ) ? absint( $p['days'] ) : 1,
				'exclusive'      => ! empty( $p['exclusive'] ),
		);
	}

	/**
	 * POST /repeat/preview — which dates of a repeat booking are free (nothing is saved).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function repeat_preview( WP_REST_Request $request ) {
		$p = $request->get_json_params();
		$p = is_array( $p ) ? $p : $request->get_body_params();
		if ( ! self::throttle_soft( 'rp', 120 ) ) {
			return new WP_Error( 'osb_rate', __( 'Too many attempts. Please wait a few minutes.', 'osam-bookings' ), array( 'status' => 429 ) );
		}
		$data   = self::booking_params( $p );
		$repeat = OSB_Repeat::parse( $data['repeat'] );
		if ( ! $repeat || ! OSB_Repeat::allowed( $data['type'] ) ) {
			return new WP_Error( 'osb_repeat', __( 'Repeat bookings aren’t available for this.', 'osam-bookings' ), array( 'status' => 400 ) );
		}
		$data['repeat'] = null;
		$dates          = OSB_Repeat::preview( $data, $repeat[0], $repeat[1] );
		return rest_ensure_response(
			array(
				'dates' => $dates,
				'ok'    => count( array_filter( wp_list_pluck( $dates, 'ok' ) ) ),
				'total' => round( array_sum( wp_list_pluck( $dates, 'amount' ) ), 2 ),
			)
		);
	}

	/**
	 * GET /bundles — bundles on sale.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function bundles( WP_REST_Request $request ) {
		if ( ! OSB_Bundles::enabled() ) {
			return rest_ensure_response( array() );
		}
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) );
		$out = array();
		foreach ( OSB_Bundles::all( true ) as $b ) {
			if ( ! $ids || in_array( (int) $b->id, $ids, true ) ) {
				$out[] = OSB_Bundles::json( $b );
			}
		}
		return rest_ensure_response( $out );
	}

	/**
	 * POST /bundles/check { code, type, service_id, event_id, intake_id, resource_ids, seats }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function check_pass( WP_REST_Request $request ) {
		if ( ! self::throttle_soft( 'pc', 30 ) ) {
			return new WP_Error( 'osb_rate', __( 'Too many attempts. Please wait a few minutes.', 'osam-bookings' ), array( 'status' => 429 ) );
		}
		$p      = OSB_Bundles::find( (string) $request->get_param( 'code' ) );
		$type   = sanitize_key( (string) $request->get_param( 'type' ) );
		$item   = 'event' === $type ? absint( $request->get_param( 'event_id' ) ) : ( 'service' === $type ? absint( $request->get_param( 'service_id' ) ) : 0 );
		if ( 'course' === $type ) {
			$in   = OSB_DB::get( 'course_intakes', absint( $request->get_param( 'intake_id' ) ) );
			$item = $in ? (int) $in->course_id : 0;
		}
		$res    = array_map( 'absint', (array) $request->get_param( 'resource_ids' ) );
		$seats  = max( 1, absint( $request->get_param( 'seats' ) ) );
		$dates  = max( 1, absint( $request->get_param( 'dates' ) ) );
		$need   = $p ? OSB_Bundles::needed( $p, $seats ) * $dates : 1;
		$ok     = OSB_Bundles::usable( $p, OSB_Items::tokens( $type, $item, $res ), $need );
		if ( is_wp_error( $ok ) ) {
			$ok->add_data( array( 'status' => 400 ) );
			return $ok;
		}
		return rest_ensure_response(
			array(
				'code'   => $p->code,
				'name'   => $p->name,
				'left'   => OSB_Bundles::left( $p ),
				'needed' => $need,
				/* translators: 1: bundle 2: credits used 3: left after */
				'label'  => sprintf( __( '%1$s — uses %2$d, %3$d left after this', 'osam-bookings' ), $p->name, $need, OSB_Bundles::left( $p ) - $need ),
			)
		);
	}

	/**
	 * A gentler per-IP limit for lookups.
	 *
	 * @param string $bucket Bucket.
	 * @param int    $limit  Per 10 minutes.
	 * @return bool
	 */
	private static function throttle_soft( $bucket, $limit ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'x';
		$key = 'osb_rl' . $bucket . '_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= $limit ) {
			return false;
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * GET /bookings/{token}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_booking( WP_REST_Request $request ) {
		$booking = OSB_DB::booking_by_token( $request['token'] );
		if ( ! $booking ) {
			return new WP_Error( 'osb_not_found', __( 'Booking not found.', 'osam-bookings' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( OSB_Bookings::details( $booking ) );
	}

	/**
	 * POST /bookings/{token}/cancel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel_booking( WP_REST_Request $request ) {
		$booking = OSB_DB::booking_by_token( $request['token'] );
		if ( ! $booking ) {
			return new WP_Error( 'osb_not_found', __( 'Booking not found.', 'osam-bookings' ), array( 'status' => 404 ) );
		}
		if ( ! OSB_Bookings::can_customer_cancel( $booking ) ) {
			return new WP_Error( 'osb_cannot_cancel', __( 'This booking can no longer be cancelled online. Please contact us.', 'osam-bookings' ), array( 'status' => 400 ) );
		}
		if ( $request->get_param( 'all' ) && (int) $booking->series_id ) {
			OSB_Repeat::cancel_from( $booking, true );
		} else {
			OSB_Bookings::set_status( $booking->id, 'cancelled' );
		}
		do_action( 'osb_booking_cancelled_by_customer', OSB_DB::get( 'bookings', $booking->id ) );
		return rest_ensure_response( OSB_Bookings::details( OSB_DB::get( 'bookings', $booking->id ) ) );
	}

	/**
	 * Booking the customer may move, or an error.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return object|WP_Error
	 */
	private static function movable( WP_REST_Request $request ) {
		$booking = OSB_DB::booking_by_token( $request['token'] );
		if ( ! $booking ) {
			return new WP_Error( 'osb_not_found', __( 'Booking not found.', 'osam-bookings' ), array( 'status' => 404 ) );
		}
		if ( ! OSB_Bookings::can_customer_reschedule( $booking ) ) {
			return new WP_Error( 'osb_cannot_reschedule', __( 'This booking can no longer be changed online. Please contact us.', 'osam-bookings' ), array( 'status' => 400 ) );
		}
		return $booking;
	}

	/**
	 * GET /bookings/{token}/month — dates the booking could move to.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reschedule_month( WP_REST_Request $request ) {
		$b = self::movable( $request );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		OSB_Availability::$exclude_booking = (int) $b->id;
		$request->set_param( 'service_id', $b->service_id );
		$request->set_param( 'staff_id', $b->staff_id );
		$res                               = self::month( $request );
		OSB_Availability::$exclude_booking = 0;
		return $res;
	}

	/**
	 * GET /bookings/{token}/day — times the booking could move to.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reschedule_day( WP_REST_Request $request ) {
		$b = self::movable( $request );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		OSB_Availability::$exclude_booking = (int) $b->id;
		$request->set_param( 'service_id', $b->service_id );
		$request->set_param( 'staff_id', $b->staff_id );
		$request->set_param( 'seats', $b->seats );
		$res                               = self::day( $request );
		OSB_Availability::$exclude_booking = 0;
		return $res;
	}

	/**
	 * POST /bookings/{token}/reschedule.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reschedule( WP_REST_Request $request ) {
		$b = self::movable( $request );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		if ( ! self::throttle() ) {
			return new WP_Error( 'osb_rate', __( 'Too many attempts. Please wait a few minutes.', 'osam-bookings' ), array( 'status' => 429 ) );
		}
		$p   = $request->get_json_params();
		$p   = is_array( $p ) ? $p : $request->get_body_params();
		$res = OSB_Bookings::reschedule( $b, isset( $p['date'] ) ? sanitize_text_field( $p['date'] ) : '', isset( $p['time'] ) ? substr( sanitize_text_field( $p['time'] ), 0, 5 ) : '' );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 400 ) );
			return $res;
		}
		return rest_ensure_response( OSB_Bookings::details( $res ) );
	}

	/**
	 * POST /webhooks/stripe.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function stripe_webhook( WP_REST_Request $request ) {
		$gateway = osb()->payments->get( 'stripe' );
		return $gateway ? $gateway->webhook( $request ) : new WP_REST_Response( null, 404 );
	}

	/**
	 * GET /admin/calendar — FullCalendar feed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function admin_calendar( WP_REST_Request $request ) {
		$from = substr( sanitize_text_field( (string) $request->get_param( 'start' ) ), 0, 10 );
		$to   = substr( sanitize_text_field( (string) $request->get_param( 'end' ) ), 0, 10 );
		list( $rows ) = OSB_DB::query_bookings(
			array(
				'from'     => $from,
				'to'       => $to,
				'staff_id' => absint( $request->get_param( 'staff_id' ) ),
				'per_page' => 2000,
				'order'    => 'asc',
			)
		);
		$services = array();
		foreach ( OSB_DB::services() as $s ) {
			$services[ (int) $s->id ] = $s;
		}
		$events_map = array();
		foreach ( OSB_DB::events() as $e ) {
			$events_map[ (int) $e->id ] = $e;
		}
		$staff_map = array();
		foreach ( OSB_DB::staff() as $st ) {
			$staff_map[ (int) $st->id ] = $st->name;
		}

		$out = array();
		foreach ( $rows as $b ) {
			if ( in_array( $b->status, OSB_Bookings::hidden_statuses(), true ) || ( 'cancelled' === $b->status && ! $request->get_param( 'show_cancelled' ) ) ) {
				continue;
			}
			if ( 'course' === $b->type || 'bundle' === $b->type ) {
				continue; // Courses are shown per session below; bundle purchases aren't appointments.
			}
			if ( 'resource' === $b->type ) {
				$out[] = array(
					'id'              => (int) $b->id,
					'title'           => $b->customer_name . ' — ' . OSB_Admin_Dashboard::item_name( $b, array() ),
					'start'           => str_replace( ' ', 'T', $b->start_at ),
					'end'             => str_replace( ' ', 'T', $b->end_at ),
					'backgroundColor' => OSB_Admin_Dashboard::item_color( $b, array() ),
					'borderColor'     => OSB_Admin_Dashboard::item_color( $b, array() ),
					'url'             => admin_url( 'admin.php?page=osb-bookings&action=view&id=' . (int) $b->id ),
					'extendedProps'   => array(
						'status' => $b->status,
						'staff'  => '',
					),
				);
				continue;
			}
			if ( 'table' === $b->type ) {
				$tcolor = in_array( $b->status, array( 'pending', 'pending_payment' ), true ) ? '#adb5bd' : '#c2255c';
				$out[]  = array(
					'id'              => (int) $b->id,
					/* translators: 1: customer 2: guests */
					'title'           => sprintf( __( '%1$s — table for %2$d', 'osam-bookings' ), $b->customer_name, (int) $b->seats ),
					'start'           => str_replace( ' ', 'T', $b->start_at ),
					'end'             => str_replace( ' ', 'T', $b->end_at ),
					'backgroundColor' => $tcolor,
					'borderColor'     => $tcolor,
					'url'             => admin_url( 'admin.php?page=osb-restaurant&date=' . substr( $b->start_at, 0, 10 ) . '&booking=' . (int) $b->id ),
					'extendedProps'   => array(
						'status' => $b->status,
						'staff'  => '',
					),
				);
				continue;
			}
			$item  = 'event' === $b->type ? ( isset( $events_map[ (int) $b->event_id ] ) ? $events_map[ (int) $b->event_id ] : null ) : ( isset( $services[ (int) $b->service_id ] ) ? $services[ (int) $b->service_id ] : null );
			$color = $item ? $item->color : '#868e96';
			if ( in_array( $b->status, array( 'pending', 'pending_payment' ), true ) ) {
				$color = '#adb5bd';
			}
			$out[] = array(
				'id'              => (int) $b->id,
				'title'           => $b->customer_name . ' — ' . ( $item ? $item->name : '?' ) . ( $b->seats > 1 ? ' ×' . $b->seats : '' ),
				'start'           => str_replace( ' ', 'T', $b->start_at ),
				'end'             => str_replace( ' ', 'T', $b->end_at ),
				'backgroundColor' => $color,
				'borderColor'     => $color,
				'url'             => admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $b->id ),
				'extendedProps'   => array(
					'status' => $b->status,
					'staff'  => isset( $staff_map[ (int) $b->staff_id ] ) ? $staff_map[ (int) $b->staff_id ] : '',
				),
			);
		}

		// Course sessions, one entry each, linking to the class register.
		$courses = array();
		foreach ( OSB_Courses::courses() as $c ) {
			$courses[ (int) $c->id ] = $c;
		}
		$counts = array();
		foreach ( OSB_Courses::sessions_between( $from, $to, absint( $request->get_param( 'staff_id' ) ) ) as $s ) {
			$c = isset( $courses[ (int) $s->course_id ] ) ? $courses[ (int) $s->course_id ] : null;
			if ( ! $c ) {
				continue;
			}
			if ( ! isset( $counts[ (int) $s->intake_id ] ) ) {
				$counts[ (int) $s->intake_id ] = OSB_Courses::seats_taken( (int) $s->intake_id );
			}
			$out[] = array(
				'id'              => 'session-' . (int) $s->id,
				/* translators: 1: course 2: enrolled count */
				'title'           => sprintf( __( '%1$s (%2$d enrolled)', 'osam-bookings' ), $c->name, $counts[ (int) $s->intake_id ] ),
				'start'           => str_replace( ' ', 'T', $s->start_at ),
				'end'             => str_replace( ' ', 'T', $s->end_at ),
				'backgroundColor' => $c->color,
				'borderColor'     => $c->color,
				'url'             => admin_url( 'admin.php?page=osb-courses&action=register&id=' . (int) $s->intake_id ),
				'extendedProps'   => array(
					'status' => 'confirmed',
					'staff'  => isset( $staff_map[ (int) $s->staff_id ] ) ? $staff_map[ (int) $s->staff_id ] : '',
				),
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * GET /admin/slots — helper for the manual booking form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function admin_slots( WP_REST_Request $request ) {
		return self::day( $request );
	}

	/**
	 * POST /admin/email-preview — render a template with unsaved design values.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function custom_email_preview( WP_REST_Request $request ) {
		$def = OSB_Custom_Emails::sanitize( (array) $request->get_json_params() );
		list( $subject, $html ) = OSB_Custom_Emails::preview( $def );
		$b = OSB_Custom_Emails::sample_booking( $def );
		return rest_ensure_response(
			array(
				'subject' => $subject,
				'html'    => $html,
				/* translators: 1 booking ref 2 customer */
				'sample'  => ! empty( $b->id ) && 1024 !== (int) $b->id ? sprintf( __( 'Showing booking #%1$d (%2$s)', 'osam-bookings' ), $b->id, $b->customer_name ) : __( 'Showing sample details — no matching booking yet', 'osam-bookings' ),
			)
		);
	}

	/**
	 * Send a test of an unsaved custom email to one address (not logged against the booking).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function custom_email_test( WP_REST_Request $request ) {
		$p   = (array) $request->get_json_params();
		$to  = sanitize_email( (string) ( $p['test_to'] ?? '' ) );
		$def = OSB_Custom_Emails::sanitize( $p );
		if ( ! is_email( $to ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => __( 'Enter an email address to send the test to.', 'osam-bookings' ) ), 400 );
		}
		$def['id']      = 'test';
		$booking        = OSB_Custom_Emails::sample_booking( $def );
		$def['subject'] = '[' . __( 'Test', 'osam-bookings' ) . '] ' . $def['subject'];
		$sent           = OSB_Custom_Emails::send( $def, $booking, OSB_Emails::NO_LOG, OSB_Emails::sample_extra( 'custom', $booking ), array( array( $to, wp_get_current_user()->display_name ) ) );
		return rest_ensure_response(
			array(
				'ok'      => (bool) $sent,
				/* translators: %s email */
				'message' => $sent ? sprintf( __( 'Test sent to %s.', 'osam-bookings' ), $to ) : __( 'The test couldn’t be sent — check the site can send email (Settings → Emails → Send a test email).', 'osam-bookings' ),
			)
		);
	}

	/**
	 * Live preview of a standard email template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function email_preview( WP_REST_Request $request ) {
		$key = sanitize_key( (string) $request->get_param( 'template' ) );
		if ( ! isset( OSB_Settings::email_labels()[ $key ] ) ) {
			$key = 'customer_confirmed';
		}
		$design = array();
		foreach ( (array) $request->get_param( 'design' ) as $k => $v ) {
			if ( 0 === strpos( (string) $k, 'email_' ) && is_scalar( $v ) ) {
				$design[ sanitize_key( $k ) ] = 'email_footer' === $k ? wp_kses_post( (string) $v ) : sanitize_text_field( (string) $v );
			}
		}
		$tpl = array();
		if ( null !== $request->get_param( 'subject' ) ) {
			$tpl['subject'] = sanitize_text_field( (string) $request->get_param( 'subject' ) );
		}
		if ( null !== $request->get_param( 'body' ) ) {
			$tpl['body'] = (string) $request->get_param( 'body' );
		}
		list( $subject, $html ) = OSB_Emails::preview( $key, $design, $tpl );
		return rest_ensure_response(
			array(
				'subject' => $subject,
				'html'    => $html,
			)
		);
	}

	/**
	 * GET /admin/check — warnings for an admin override booking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function admin_check( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'warnings' => OSB_Availability::conflicts(
					array(
						'type'       => in_array( $request->get_param( 'type' ), array( 'event', 'course' ), true ) ? $request->get_param( 'type' ) : 'service',
						'service_id' => absint( $request->get_param( 'service_id' ) ),
						'staff_id'   => absint( $request->get_param( 'staff_id' ) ),
						'event_id'   => absint( $request->get_param( 'event_id' ) ),
						'intake_id'  => absint( $request->get_param( 'intake_id' ) ),
						'date'       => sanitize_text_field( (string) $request->get_param( 'date' ) ),
						'time'       => substr( sanitize_text_field( (string) $request->get_param( 'time' ) ), 0, 5 ),
						'seats'      => max( 1, absint( $request->get_param( 'seats' ) ) ),
						'exclude'    => absint( $request->get_param( 'exclude' ) ),
					)
				),
			)
		);
	}
}
