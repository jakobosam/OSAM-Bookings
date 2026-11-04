<?php
/**
 * Online meeting links: fixed links, Zoom, Google Meet and Microsoft Teams.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gives online bookings a join link before the confirmation email goes out.
 *
 * - Fixed link: the same URL for everyone (copied onto each booking).
 * - Zoom: Server-to-Server OAuth app. One meeting per appointment, one per event, and one
 *   recurring meeting per course intake so the link is the same for every session.
 * - Google Meet / Teams: added to the entry the calendar sync puts in the staff member's
 *   calendar, so it needs that staff member connected with "Add bookings" on.
 */
class OSB_Meetings {

	const REFS = 'osb_meeting_refs';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'osb_booking_created', array( __CLASS__, 'on_created' ), 5 );
		add_action( 'osb_booking_notify', array( __CLASS__, 'on_notify' ), 5, 2 );
		add_action( 'osb_booking_status_changed', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'osb_booking_rescheduled', array( __CLASS__, 'on_moved' ), 10, 1 );
		add_action( 'osb_event_saved', array( __CLASS__, 'on_event_saved' ), 20, 1 );
	}

	/**
	 * Ways an item can be run online.
	 *
	 * @param string $type service|event|course.
	 * @return array mode => label
	 */
	public static function modes( $type = 'service' ) {
		$modes = array(
			''       => __( 'In person — no online link', 'osam-bookings' ),
			'link'   => __( 'Online — the same link for everyone', 'osam-bookings' ),
			'zoom'   => __( 'Online — a Zoom meeting is created automatically', 'osam-bookings' ),
			'google' => __( 'Online — Google Meet (from the staff member’s Google Calendar)', 'osam-bookings' ),
			'teams'  => __( 'Online — Microsoft Teams (from the staff member’s Microsoft calendar)', 'osam-bookings' ),
		);
		if ( 'course' === $type ) {
			// Each session is its own calendar entry, so Meet/Teams would give a different link every week.
			unset( $modes['google'], $modes['teams'] );
		}
		if ( ! OSB_Settings::feature( 'calendar_sync' ) ) {
			unset( $modes['google'], $modes['teams'] );
		}
		return $modes;
	}

	/**
	 * Is Zoom set up?
	 *
	 * @return bool
	 */
	public static function zoom_ready() {
		return '' !== (string) OSB_Settings::get( 'zoom_account_id' ) && '' !== (string) OSB_Settings::get( 'zoom_client_id' ) && '' !== (string) OSB_Settings::get( 'zoom_client_secret' );
	}

	/**
	 * Settings section on a service / event / course editor.
	 *
	 * @param string $type service|event|course.
	 * @param object $item Item row.
	 */
	public static function editor_section( $type, $item ) {
		if ( ! OSB_Settings::feature( 'meetings' ) ) {
			return;
		}
		$mode  = isset( $item->online_mode ) ? (string) $item->online_mode : '';
		$url   = isset( $item->online_url ) ? (string) $item->online_url : '';
		$modes = self::modes( $type );
		if ( '' !== $mode && ! isset( $modes[ $mode ] ) ) {
			$modes[ $mode ] = self::label( $mode ) . ' — ' . __( 'not available', 'osam-bookings' );
		}
		$warn = '';
		if ( 'zoom' === $mode && ! self::zoom_ready() ) {
			$warn = __( 'Zoom isn’t connected yet, so no links will be made.', 'osam-bookings' );
		}
		?>
		<div class="osb-section" data-osb-online>
			<div class="osb-section__intro"><h2><?php esc_html_e( 'Online', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'The join link goes in the confirmation and reminder emails, the calendar invite and the customer’s booking page.', 'osam-bookings' ); ?></p></div>
			<div class="osb-card">
				<div class="osb-field">
					<label for="osb_online_mode"><?php esc_html_e( 'How is it delivered?', 'osam-bookings' ); ?></label>
					<select name="online_mode" id="osb_online_mode" data-osb-online-mode>
						<?php foreach ( $modes as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $mode, $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( $warn ) : ?>
						<p class="osb-help osb-help--warn"><?php echo esc_html( $warn ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-settings&tab=meetings' ) ); ?>"><?php esc_html_e( 'Connect Zoom', 'osam-bookings' ); ?> →</a></p>
					<?php endif; ?>
					<p class="osb-help" data-osb-online-help="google teams" <?php echo in_array( $mode, array( 'google', 'teams' ), true ) ? '' : 'hidden'; ?>><?php echo 'event' === $type ? esc_html__( 'One link for the whole event, made in the host’s calendar when the first person books.', 'osam-bookings' ) : esc_html__( 'Each appointment gets its own link, made in the staff member’s calendar. Staff without a connected calendar won’t get one.', 'osam-bookings' ); ?></p>
					<p class="osb-help" data-osb-online-help="zoom" <?php echo 'zoom' === $mode ? '' : 'hidden'; ?>>
						<?php
						if ( 'event' === $type ) {
							esc_html_e( 'One Zoom meeting for the whole event. Moving the event moves the meeting.', 'osam-bookings' );
						} elseif ( 'course' === $type ) {
							esc_html_e( 'One recurring Zoom meeting per intake, so the link is the same every session.', 'osam-bookings' );
						} else {
							esc_html_e( 'Each appointment gets its own Zoom meeting. Rescheduling moves it and cancelling deletes it.', 'osam-bookings' );
						}
						?>
					</p>
				</div>
				<div class="osb-field" data-osb-online-help="link" <?php echo 'link' === $mode ? '' : 'hidden'; ?>>
					<label for="osb_online_url"><?php esc_html_e( 'Meeting link', 'osam-bookings' ); ?></label>
					<input type="url" name="online_url" id="osb_online_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://">
					<p class="osb-help"><?php esc_html_e( 'Your personal meeting room, a webinar link or a livestream page.', 'osam-bookings' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Values to save from an editor form (empty when the section wasn't shown).
	 *
	 * @param array  $post Posted data.
	 * @param string $type Item type.
	 * @return array
	 */
	public static function from_post( array $post, $type = 'service' ) {
		if ( ! isset( $post['online_mode'] ) ) {
			return array();
		}
		$mode    = sanitize_key( $post['online_mode'] );
		$allowed = 'course' === $type ? array( 'link', 'zoom' ) : array( 'link', 'zoom', 'google', 'teams' );
		$mode    = in_array( $mode, $allowed, true ) ? $mode : '';
		return array(
			'online_mode' => $mode,
			'online_url'  => isset( $post['online_url'] ) ? esc_url_raw( trim( (string) $post['online_url'] ) ) : '',
		);
	}

	/**
	 * Short name of a mode.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	public static function label( $mode ) {
		$labels = array(
			'link'   => __( 'Online link', 'osam-bookings' ),
			'zoom'   => 'Zoom',
			'google' => 'Google Meet',
			'teams'  => 'Microsoft Teams',
		);
		return isset( $labels[ $mode ] ) ? $labels[ $mode ] : '';
	}

	/**
	 * The service/event/course a booking is for.
	 *
	 * @param object $booking Booking.
	 * @return object|null
	 */
	public static function item_for( $booking ) {
		if ( 'event' === $booking->type ) {
			return OSB_DB::get( 'events', $booking->event_id );
		}
		if ( 'course' === $booking->type ) {
			return OSB_DB::get( 'courses', $booking->course_id );
		}
		return OSB_DB::get( 'services', $booking->service_id );
	}

	/**
	 * Is an item delivered online?
	 *
	 * @param object $item Item row.
	 * @return bool
	 */
	public static function is_online( $item ) {
		return $item && OSB_Settings::feature( 'meetings' ) && ! empty( $item->online_mode );
	}

	/* ---------------------------------------------------------------------
	 * Hooks
	 * ------------------------------------------------------------------ */

	/**
	 * New booking (not waiting for payment): make the link now.
	 *
	 * @param object $booking Booking.
	 */
	public static function on_created( $booking ) {
		if ( 'pending_payment' !== $booking->status ) {
			self::ensure( $booking );
		}
	}

	/**
	 * Right before the confirmation email (also covers paid-online bookings).
	 *
	 * @param object $booking Booking (updated in place so the email sees the link).
	 * @param string $what    new|approved|cancelled.
	 */
	public static function on_notify( $booking, $what ) {
		if ( in_array( $what, array( 'new', 'approved' ), true ) ) {
			self::ensure( $booking );
		}
	}

	/**
	 * Cancelled appointments lose their own Zoom meeting.
	 *
	 * @param object $booking Booking.
	 * @param string $old     Old status.
	 * @param string $status  New status.
	 */
	public static function on_status( $booking, $old, $status ) {
		if ( 'cancelled' === $status && 'service' === $booking->type && 0 === strpos( (string) $booking->meeting_id, 'zoom:' ) ) {
			self::zoom( 'DELETE', '/meetings/' . rawurlencode( substr( $booking->meeting_id, 5 ) ) );
		}
	}

	/**
	 * Appointment moved: move its Zoom meeting.
	 *
	 * @param object $booking Booking.
	 */
	public static function on_moved( $booking ) {
		if ( is_numeric( $booking ) ) {
			$booking = OSB_DB::get( 'bookings', $booking );
		}
		if ( ! $booking || 'service' !== $booking->type || 0 !== strpos( (string) $booking->meeting_id, 'zoom:' ) ) {
			return;
		}
		$body = self::zoom_time( $booking->start_at, $booking->end_at );
		self::zoom( 'PATCH', '/meetings/' . rawurlencode( substr( $booking->meeting_id, 5 ) ), $body );
	}

	/**
	 * Event moved: move its shared Zoom meeting.
	 *
	 * @param int $event_id Event.
	 */
	public static function on_event_saved( $event_id ) {
		$refs = self::refs();
		$key  = 'event-' . (int) $event_id;
		$e    = OSB_DB::get( 'events', $event_id );
		if ( ! $e || empty( $refs[ $key ] ) || 'zoom' !== $refs[ $key ]['provider'] ) {
			return;
		}
		self::zoom( 'PATCH', '/meetings/' . rawurlencode( $refs[ $key ]['id'] ), array_merge( array( 'topic' => $e->name ), self::zoom_time( $e->start_at, $e->end_at ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Making links
	 * ------------------------------------------------------------------ */

	/**
	 * Give a booking its join link if it should have one. Safe to call repeatedly.
	 *
	 * @param object $booking Booking (meeting_url / meeting_id set on it).
	 * @return string Link ('' if none).
	 */
	public static function ensure( $booking ) {
		if ( ! empty( $booking->meeting_url ) ) {
			return $booking->meeting_url;
		}
		$item = self::item_for( $booking );
		if ( ! self::is_online( $item ) || ! in_array( $booking->status, array( 'pending', 'confirmed', 'completed' ), true ) ) {
			return '';
		}
		$made = self::make( $booking, $item );
		if ( is_wp_error( $made ) ) {
			self::log_error( $booking, $made );
			return '';
		}
		if ( ! $made || empty( $made['url'] ) ) {
			return '';
		}
		$booking->meeting_url = $made['url'];
		$booking->meeting_id  = $made['id'];
		OSB_DB::update(
			'bookings',
			$booking->id,
			array(
				'meeting_url' => $made['url'],
				'meeting_id'  => $made['id'],
			)
		);
		do_action( 'osb_meeting_created', $booking, $made );
		return $made['url'];
	}

	/**
	 * Work out / create the link.
	 *
	 * @param object $booking Booking.
	 * @param object $item    Service, event or course.
	 * @return array|WP_Error|null [ url, id ]
	 */
	private static function make( $booking, $item ) {
		$mode = (string) $item->online_mode;
		$pre  = apply_filters( 'osb_meeting_pre_create', null, $booking, $item, $mode );
		if ( null !== $pre ) {
			return $pre;
		}
		if ( 'link' === $mode ) {
			return $item->online_url ? array( 'url' => $item->online_url, 'id' => 'link' ) : null;
		}

		// Events and course intakes share one meeting between everyone booked.
		$shared = 'event' === $booking->type ? 'event-' . (int) $booking->event_id : ( 'course' === $booking->type ? 'intake-' . (int) $booking->intake_id : '' );
		if ( $shared ) {
			$refs = self::refs();
			if ( ! empty( $refs[ $shared ]['url'] ) && $refs[ $shared ]['provider'] === $mode ) {
				return array( 'url' => $refs[ $shared ]['url'], 'id' => $mode . ':' . $refs[ $shared ]['id'] );
			}
		}

		if ( 'zoom' === $mode ) {
			$made = self::zoom_create( $booking, $item );
		} elseif ( in_array( $mode, array( 'google', 'teams' ), true ) && 'course' !== $booking->type ) {
			$staff    = 'event' === $booking->type ? (int) $item->staff_id : (int) $booking->staff_id;
			$provider = OSB_Calendar_Sync::push_provider( $staff );
			if ( ( 'google' === $mode && 'google' !== $provider ) || ( 'teams' === $mode && 'microsoft' !== $provider ) ) {
				return new WP_Error(
					'osb_meet_noconn',
					'google' === $mode
						? __( 'Google Meet links need the staff member’s Google Calendar connected, with “Add bookings to my calendar” on.', 'osam-bookings' )
						: __( 'Teams links need the staff member’s Microsoft calendar connected, with “Add bookings to my calendar” on.', 'osam-bookings' )
				);
			}
			$key  = 'event' === $booking->type ? 'event-' . (int) $booking->event_id : 'booking-' . (int) $booking->id;
			$from = 'event' === $booking->type ? $item->start_at : $booking->start_at;
			$to   = 'event' === $booking->type ? $item->end_at : $booking->end_at;
			$url  = OSB_Calendar_Sync::conference( $staff, $key, $from, $to );
			$made = is_wp_error( $url ) ? $url : array( 'url' => $url, 'id' => $key );
		} else {
			return null;
		}
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		if ( $shared ) {
			$refs            = self::refs();
			$refs[ $shared ] = array(
				'provider' => $mode,
				'id'       => (string) preg_replace( '/^[a-z]+:/', '', $made['id'] ),
				'url'      => $made['url'],
			);
			update_option( self::REFS, $refs, false );
			if ( 'zoom' === $mode ) {
				$made['id'] = 'zoom-shared:' . $refs[ $shared ]['id'];
			}
		}
		return $made;
	}

	/**
	 * Shared meeting references.
	 *
	 * @return array
	 */
	public static function refs() {
		$refs = get_option( self::REFS, array() );
		return is_array( $refs ) ? $refs : array();
	}

	/**
	 * Remember why a link couldn't be made (shown on the booking and the Meetings tab).
	 *
	 * @param object   $booking Booking.
	 * @param WP_Error $err     Error.
	 */
	private static function log_error( $booking, $err ) {
		$log = get_option( 'osb_meeting_errors', array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'booking' => (int) $booking->id,
				'time'    => current_time( 'mysql' ),
				'message' => $err->get_error_message(),
			)
		);
		update_option( 'osb_meeting_errors', array_slice( $log, 0, 20 ), false );
	}

	/**
	 * Recent problems.
	 *
	 * @param int $booking_id Only this booking (0 for all).
	 * @return array
	 */
	public static function errors( $booking_id = 0 ) {
		$log = get_option( 'osb_meeting_errors', array() );
		$log = is_array( $log ) ? $log : array();
		if ( $booking_id ) {
			$log = array_values(
				array_filter(
					$log,
					static function ( $e ) use ( $booking_id ) {
						return (int) $e['booking'] === (int) $booking_id;
					}
				)
			);
		}
		return $log;
	}

	/* ---------------------------------------------------------------------
	 * Zoom (Server-to-Server OAuth)
	 * ------------------------------------------------------------------ */

	/**
	 * Access token (cached for just under its lifetime).
	 *
	 * @param bool $fresh Ignore the cache.
	 * @return string|WP_Error
	 */
	public static function zoom_token( $fresh = false ) {
		if ( ! self::zoom_ready() ) {
			return new WP_Error( 'osb_zoom_setup', __( 'Zoom isn’t connected. Add your Server-to-Server OAuth app details in Settings → Online meetings.', 'osam-bookings' ) );
		}
		$cached = get_transient( 'osb_zoom_token' );
		if ( $cached && ! $fresh ) {
			return $cached;
		}
		$secret = OSB_Calendar_Sync::unseal( OSB_Settings::get( 'zoom_client_secret' ) );
		$res    = wp_remote_post(
			'https://zoom.us/oauth/token',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Basic ' . base64_encode( OSB_Settings::get( 'zoom_client_id' ) . ':' . $secret ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'body'    => array(
					'grant_type' => 'account_credentials',
					'account_id' => OSB_Settings::get( 'zoom_account_id' ),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['access_token'] ) ) {
			$msg = isset( $data['reason'] ) ? $data['reason'] : ( isset( $data['error'] ) ? $data['error'] : 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
			/* translators: %s error */
			return new WP_Error( 'osb_zoom_auth', sprintf( __( 'Zoom refused the app details: %s', 'osam-bookings' ), $msg ) );
		}
		set_transient( 'osb_zoom_token', $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 ) );
		return $data['access_token'];
	}

	/**
	 * Call the Zoom API.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path under /v2.
	 * @param array|null $body   JSON body.
	 * @param bool       $retry  Retry once on 401.
	 * @return array|WP_Error
	 */
	public static function zoom( $method, $path, $body = null, $retry = true ) {
		$token = self::zoom_token( ! $retry );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res = wp_remote_request( 'https://api.zoom.us/v2' . $path, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 401 === $code && $retry ) {
			delete_transient( 'osb_zoom_token' );
			return self::zoom( $method, $path, $body, false );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$data = is_array( $data ) ? $data : array();
		if ( $code >= 400 ) {
			if ( 'DELETE' === $method && 404 === $code ) {
				return array();
			}
			/* translators: %s error */
			return new WP_Error( 'osb_zoom_' . $code, sprintf( __( 'Zoom: %s', 'osam-bookings' ), isset( $data['message'] ) ? $data['message'] : 'HTTP ' . $code ), array( 'status' => $code ) );
		}
		return $data;
	}

	/**
	 * Start time / duration fields.
	 *
	 * @param string $start Local start.
	 * @param string $end   Local end.
	 * @return array
	 */
	private static function zoom_time( $start, $end ) {
		$tz   = wp_timezone_string();
		$zone = preg_match( '/^[+-]\d{2}:\d{2}$/', $tz ) ? '' : $tz;
		$dt   = new DateTime( $start, wp_timezone() );
		$out  = array( 'duration' => max( 15, (int) round( ( strtotime( $end ) - strtotime( $start ) ) / 60 ) ) );
		if ( $zone ) {
			$out['start_time'] = $dt->format( 'Y-m-d\TH:i:s' );
			$out['timezone']   = $zone;
		} else {
			$dt->setTimezone( new DateTimeZone( 'UTC' ) );
			$out['start_time'] = $dt->format( 'Y-m-d\TH:i:s\Z' );
		}
		return $out;
	}

	/**
	 * Create a Zoom meeting for a booking (or its event / intake).
	 *
	 * @param object $booking Booking.
	 * @param object $item    Item.
	 * @return array|WP_Error [ url, id ]
	 */
	private static function zoom_create( $booking, $item ) {
		$body = array(
			'agenda'   => sprintf( /* translators: %s site */ __( 'Booked through %s', 'osam-bookings' ), get_bloginfo( 'name' ) ),
			'settings' => array(
				'join_before_host' => false,
				'waiting_room'     => true,
				'host_video'       => true,
				'participant_video' => true,
			),
		);
		if ( 'course' === $booking->type ) {
			$intake        = OSB_DB::get( 'course_intakes', $booking->intake_id );
			$body['topic'] = $item->name . ( $intake ? ' — ' . OSB_Courses::intake_label( $intake ) : '' );
			$body['type']  = 3; // Recurring, no fixed time: one link for every session.
		} elseif ( 'event' === $booking->type ) {
			$body          = array_merge( $body, self::zoom_time( $item->start_at, $item->end_at ) );
			$body['topic'] = $item->name;
			$body['type']  = 2;
		} else {
			$body          = array_merge( $body, self::zoom_time( $booking->start_at, $booking->end_at ) );
			$body['topic'] = $item->name . ' — ' . $booking->customer_name;
			$body['type']  = 2;
		}
		$body = apply_filters( 'osb_zoom_meeting_body', $body, $booking, $item );
		$res  = self::zoom( 'POST', '/users/me/meetings', $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['join_url'] ) || empty( $res['id'] ) ) {
			return new WP_Error( 'osb_zoom_nolink', __( 'Zoom didn’t return a meeting link.', 'osam-bookings' ) );
		}
		return array(
			'url' => (string) $res['join_url'],
			'id'  => 'zoom:' . $res['id'],
		);
	}
}
