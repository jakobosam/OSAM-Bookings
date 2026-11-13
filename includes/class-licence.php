<?php
/**
 * Subscription (WHMCS) status: switches the booking system off when OSAM's billing says the
 * site's subscription is suspended, and back on when it's paid.
 *
 * The plugin never talks to WHMCS directly and holds no WHMCS credentials. It knows two things:
 *  - a status URL to ask ("pull"), checked hourly (every 10 minutes while overdue or suspended);
 *  - a webhook on this site that billing can call the moment something changes ("push").
 * Both are signed with the site's subscription key (HMAC-SHA256), so neither side can be spoofed.
 *
 * Fail-safe: if the status URL can't be reached or answers with something it can't verify, the
 * last known status is kept. A network problem never switches a paying site off.
 *
 * Statuses: active | overdue (warning only) | suspended | terminated.
 * Modes (when suspended/terminated): "bookings" — no new bookings, everything else works;
 * "full" — no new bookings, admin screens locked, reminders and other scheduled emails paused.
 *
 * Lock the settings with constants in wp-config.php so they can't be changed from the dashboard:
 *   define( 'OSB_LICENCE_KEY', '…' );           // required to switch this on
 *   define( 'OSB_LICENCE_URL', 'https://…' );   // OSAM testing only; normally always the WHMCS addon (DEFAULT_URL)
 *   define( 'OSB_LICENCE_SERVICE', '123' );     // optional WHMCS service id
 *
 * See WHMCS-INTEGRATION.md for the exact requests and responses.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Subscription status.
 */
class OSB_Licence {

	const OPTION = 'osb_licence_state';
	const SKEW   = 300; // Seconds a signed message stays valid.

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( OSB_Cron::HOOK, array( __CLASS__, 'maybe_check' ), 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'gate_rest' ), 5, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_osb_licence_check', array( __CLASS__, 'check_now' ) );
	}

	/* ---------------------------------------------------------------------
	 * Configuration
	 * ------------------------------------------------------------------ */

	/**
	 * Subscription key (constant wins over the setting).
	 *
	 * @return string
	 */
	public static function key() {
		if ( defined( 'OSB_LICENCE_KEY' ) && OSB_LICENCE_KEY ) {
			return trim( (string) OSB_LICENCE_KEY );
		}
		return trim( (string) OSB_Settings::get( 'licence_key' ) );
	}

	/**
	 * Status URL.
	 *
	 * @return string
	 */
	public static function url() {
		// Always OSAM's billing. Only OSB_LICENCE_URL in wp-config.php can point elsewhere (for OSAM's own
		// testing); the old dashboard setting is ignored so a site can't be pointed away from billing.
		$url = defined( 'OSB_LICENCE_URL' ) && OSB_LICENCE_URL ? (string) OSB_LICENCE_URL : self::DEFAULT_URL;
		return esc_url_raw( (string) apply_filters( 'osb_licence_url', $url ) );
	}

	/**
	 * OSAM's billing status address (WHMCS addon).
	 */
	const DEFAULT_URL = 'https://osamweb.com/portal/modules/addons/osam_client_bookings/status.php';

	/**
	 * The status address used unless wp-config.php says otherwise.
	 *
	 * @return string
	 */
	public static function default_url() {
		return self::DEFAULT_URL;
	}

	/**
	 * Is the status address overridden in wp-config.php?
	 *
	 * @return bool
	 */
	public static function url_overridden() {
		return defined( 'OSB_LICENCE_URL' ) && OSB_LICENCE_URL;
	}

	/**
	 * WHMCS service id (optional — helps billing find the service quickly).
	 *
	 * @return string
	 */
	public static function service() {
		if ( defined( 'OSB_LICENCE_SERVICE' ) && OSB_LICENCE_SERVICE ) {
			return (string) OSB_LICENCE_SERVICE;
		}
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) OSB_Settings::get( 'licence_service' ) );
	}

	/**
	 * Are the settings fixed in wp-config.php?
	 *
	 * @return bool
	 */
	public static function locked() {
		return defined( 'OSB_LICENCE_KEY' ) && OSB_LICENCE_KEY;
	}

	/**
	 * Is subscription checking switched on for this site?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '' !== self::key();
	}

	/**
	 * Webhook address to give to whoever runs WHMCS.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( OSB_Rest::NS . '/licence/notify' );
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------ */

	/**
	 * Last known state.
	 *
	 * @return array status, mode, message, due_date, grace_until, invoice_url, checked, contacted, source, error
	 */
	public static function state() {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'status'      => 'active',
				'mode'        => '',
				'message'     => '',
				'due_date'    => '',
				'grace_until' => '',
				'invoice_url' => '',
				'checked'     => 0,
				'contacted'   => 0,
				'changed'     => 0,
				'source'      => '',
				'error'       => '',
			)
		);
	}

	/**
	 * Current status (always "active" when checking is off).
	 *
	 * @return string
	 */
	public static function status() {
		return self::enabled() ? self::state()['status'] : 'active';
	}

	/**
	 * What switching off covers right now: '' (nothing), 'bookings' or 'full'.
	 *
	 * @return string
	 */
	public static function off_mode() {
		if ( ! self::enabled() ) {
			return '';
		}
		$s = self::state();
		if ( ! in_array( $s['status'], array( 'suspended', 'terminated' ), true ) ) {
			return '';
		}
		$mode = in_array( $s['mode'], array( 'bookings', 'full' ), true ) ? $s['mode'] : ( 'terminated' === $s['status'] ? 'full' : 'bookings' );
		return (string) apply_filters( 'osb_licence_off_mode', $mode, $s );
	}

	/**
	 * Are new bookings switched off?
	 *
	 * @return bool
	 */
	public static function bookings_off() {
		return '' !== self::off_mode();
	}

	/**
	 * Is the whole system locked (admin screens and scheduled emails too)?
	 *
	 * @return bool
	 */
	public static function full_off() {
		return 'full' === self::off_mode();
	}

	/**
	 * Message shown to customers instead of the booking form.
	 *
	 * @return string
	 */
	public static function customer_message() {
		$m = trim( (string) OSB_Settings::get( 'licence_customer_message' ) );
		return '' !== $m ? $m : __( 'Online booking is temporarily unavailable. Please contact us to book.', 'osam-bookings' );
	}

	/**
	 * Save a new state from a verified message.
	 *
	 * @param array  $data   Decoded message.
	 * @param string $source pull|push.
	 * @return array|WP_Error New state.
	 */
	private static function apply( array $data, $source ) {
		$status = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : '';
		if ( ! in_array( $status, array( 'active', 'overdue', 'suspended', 'terminated' ), true ) ) {
			return new WP_Error( 'osb_licence_status', __( 'The billing system sent a status this version doesn’t understand.', 'osam-bookings' ) );
		}
		$date = static function ( $v ) {
			$v = sanitize_text_field( (string) $v );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		$old  = self::state();
		$new  = array(
			'status'      => $status,
			'mode'        => isset( $data['mode'] ) && in_array( $data['mode'], array( 'bookings', 'full' ), true ) ? $data['mode'] : '',
			'message'     => isset( $data['message'] ) ? mb_substr( sanitize_text_field( (string) $data['message'] ), 0, 500 ) : '',
			'due_date'    => $date( $data['due_date'] ?? '' ),
			'grace_until' => $date( $data['grace_until'] ?? '' ),
			'invoice_url' => isset( $data['invoice_url'] ) && wp_http_validate_url( (string) $data['invoice_url'] ) ? esc_url_raw( (string) $data['invoice_url'] ) : '',
			'checked'     => time(),
			'contacted'   => time(),
			'changed'     => $old['status'] !== $status || $old['mode'] !== ( $data['mode'] ?? '' ) ? time() : (int) $old['changed'],
			'source'      => $source,
			'error'       => '',
		);
		update_option( self::OPTION, $new, true );
		if ( $old['status'] !== $new['status'] ) {
			/**
			 * Fires when billing changes this site's subscription status.
			 *
			 * @param string $new Status.
			 * @param string $old Previous status.
			 * @param array  $state Full state.
			 */
			do_action( 'osb_licence_status_changed', $new['status'], $old['status'], $new );
		}
		return $new;
	}

	/**
	 * Record a failed check without touching the status.
	 *
	 * @param string $error Message.
	 */
	private static function failed( $error ) {
		$s            = self::state();
		$s['checked'] = time();
		$s['error']   = $error;
		update_option( self::OPTION, $s, true );
	}

	/* ---------------------------------------------------------------------
	 * Signing
	 * ------------------------------------------------------------------ */

	/**
	 * HMAC-SHA256 of "timestamp.payload" with the subscription key (hex).
	 *
	 * @param string $timestamp Unix time.
	 * @param string $payload   Payload.
	 * @return string
	 */
	public static function sign( $timestamp, $payload ) {
		return hash_hmac( 'sha256', $timestamp . '.' . $payload, self::key() );
	}

	/**
	 * Check a signature and its timestamp.
	 *
	 * @param string $timestamp Timestamp header.
	 * @param string $payload   Payload.
	 * @param string $signature Signature header.
	 * @return bool
	 */
	public static function verify( $timestamp, $payload, $signature ) {
		if ( ! self::enabled() || ! ctype_digit( (string) $timestamp ) || abs( time() - (int) $timestamp ) > self::SKEW ) {
			return false;
		}
		return hash_equals( self::sign( $timestamp, $payload ), strtolower( trim( (string) $signature ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Pull
	 * ------------------------------------------------------------------ */

	/**
	 * From the 5-minute job: check hourly, or every 10 minutes when not active (so paying
	 * switches the site back on quickly).
	 */
	public static function maybe_check() {
		if ( ! self::enabled() ) {
			return;
		}
		$s     = self::state();
		$every = 'active' === $s['status'] ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS;
		if ( time() - (int) $s['checked'] >= $every - 30 ) {
			self::check();
		}
	}

	/**
	 * Ask the status URL now.
	 *
	 * @return array|WP_Error New state.
	 */
	public static function check() {
		if ( ! self::enabled() ) {
			return new WP_Error( 'osb_licence_off', __( 'No subscription key is set.', 'osam-bookings' ) );
		}
		$site = home_url( '/' );
		$ts   = (string) time();
		$res  = wp_remote_get(
			add_query_arg(
				array(
					'site'    => rawurlencode( $site ),
					'service' => rawurlencode( self::service() ),
					'plugin'  => 'osam-bookings',
					'version' => rawurlencode( OSB_VERSION ),
				),
				self::url()
			),
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'          => 'application/json',
					'X-OSB-Site'      => $site,
					'X-OSB-Timestamp' => $ts,
					'X-OSB-Signature' => self::sign( $ts, $site ),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			/* translators: %s error */
			$msg = sprintf( __( 'Couldn’t reach the billing status address: %s', 'osam-bookings' ), $res->get_error_message() );
			self::failed( $msg );
			return new WP_Error( 'osb_licence_unreachable', $msg );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( 200 !== $code ) {
			$data = json_decode( $body, true );
			/* translators: 1: HTTP code 2: message */
			$msg = sprintf( __( 'The billing status address answered HTTP %1$d. %2$s', 'osam-bookings' ), $code, is_array( $data ) && ! empty( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '' );
			self::failed( trim( $msg ) );
			return new WP_Error( 'osb_licence_http', trim( $msg ) );
		}
		if ( ! self::verify( (string) wp_remote_retrieve_header( $res, 'x-osb-timestamp' ), $body, (string) wp_remote_retrieve_header( $res, 'x-osb-signature' ) ) ) {
			$msg = __( 'The billing status answer wasn’t signed with this site’s key (or the server clock is out), so it was ignored.', 'osam-bookings' );
			self::failed( $msg );
			return new WP_Error( 'osb_licence_signature', $msg );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			self::failed( __( 'The billing status answer wasn’t valid JSON.', 'osam-bookings' ) );
			return new WP_Error( 'osb_licence_json', __( 'The billing status answer wasn’t valid JSON.', 'osam-bookings' ) );
		}
		if ( isset( $data['site'] ) && untrailingslashit( (string) $data['site'] ) !== untrailingslashit( $site ) ) {
			$msg = __( 'The billing status answer was for a different site, so it was ignored.', 'osam-bookings' );
			self::failed( $msg );
			return new WP_Error( 'osb_licence_site', $msg );
		}
		$state = self::apply( $data, 'pull' );
		if ( is_wp_error( $state ) ) {
			self::failed( $state->get_error_message() );
		}
		return $state;
	}

	/**
	 * "Check now" button.
	 */
	public static function check_now() {
		OSB_Admin::verify( 'osb_licence_check', 'manage_options' );
		$r = self::check();
		if ( is_wp_error( $r ) ) {
			OSB_Admin::flash_error( $r->get_error_message() );
		}
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-settings&tab=subscription' ), is_wp_error( $r ) ? 'none' : 'licence_checked' );
	}

	/* ---------------------------------------------------------------------
	 * Push
	 * ------------------------------------------------------------------ */

	/**
	 * Routes.
	 */
	public static function routes() {
		register_rest_route(
			OSB_Rest::NS,
			'/licence/notify',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'notify' ),
				'permission_callback' => '__return_true', // Authenticated by signature.
			)
		);
	}

	/**
	 * POST /licence/notify — billing tells the site its status changed.
	 * Body: { "status": "suspended", "mode": "bookings", ... } or { "action": "check" }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function notify( WP_REST_Request $request ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'osb_licence_off', 'Subscription checking is not set up on this site.', array( 'status' => 404 ) );
		}
		$body = (string) $request->get_body();
		if ( ! self::verify( (string) $request->get_header( 'x_osb_timestamp' ), $body, (string) $request->get_header( 'x_osb_signature' ) ) ) {
			return new WP_Error( 'osb_licence_signature', 'Bad or expired signature.', array( 'status' => 401 ) );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'osb_licence_json', 'Body must be JSON.', array( 'status' => 400 ) );
		}
		if ( isset( $data['action'] ) && 'check' === $data['action'] ) {
			$state = self::check();
		} else {
			$state = self::apply( $data, 'push' );
		}
		if ( is_wp_error( $state ) ) {
			$state->add_data( array( 'status' => 400 ) );
			return $state;
		}
		return rest_ensure_response(
			array(
				'ok'      => true,
				'site'    => home_url( '/' ),
				'status'  => $state['status'],
				'mode'    => self::off_mode(),
				'version' => OSB_VERSION,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Switching off
	 * ------------------------------------------------------------------ */

	/**
	 * Stop public booking requests while suspended. Customers can still view or cancel the
	 * bookings they already have, and payments already under way can still complete.
	 *
	 * @param mixed           $result  Response so far.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function gate_rest( $result, $server, $request ) {
		$route = (string) $request->get_route();
		if ( null !== $result || 0 !== strpos( $route, '/' . OSB_Rest::NS . '/' ) || ! self::bookings_off() ) {
			return $result;
		}
		$path = substr( $route, strlen( '/' . OSB_Rest::NS ) );
		// Always allowed: billing, payment webhooks, and a customer's own booking (view/cancel).
		if ( 0 === strpos( $path, '/licence' ) || 0 === strpos( $path, '/webhooks/' ) ) {
			return $result;
		}
		if ( preg_match( '#^/bookings/[A-Za-z0-9]{20,64}(/cancel)?$#', $path ) ) {
			return $result;
		}
		if ( 0 === strpos( $path, '/admin/' ) ) {
			// Staff can still look things up and manage existing bookings; only new ones are stopped.
			$creates = (bool) preg_match( '#^/admin/tables/(reservations|walkin)$#', $path );
			if ( ! $creates && ! self::full_off() ) {
				return $result;
			}
		}
		return new WP_Error( 'osb_suspended', self::customer_message(), array( 'status' => 503 ) );
	}

	/**
	 * The error new bookings get while switched off.
	 *
	 * @return WP_Error
	 */
	public static function booking_error() {
		return new WP_Error( 'osb_suspended', is_admin() ? __( 'New bookings are switched off because this site’s OSAM subscription is suspended.', 'osam-bookings' ) : self::customer_message() );
	}

	/**
	 * What customers see instead of the booking form.
	 *
	 * @return string
	 */
	public static function placeholder_html() {
		OSB_Frontend::prepare();
		wp_enqueue_style( 'osb-booking' );
		return '<div class="osb-booking osb-booking--off"><div class="osb-alert osb-alert--info" role="status">' . esc_html( self::customer_message() ) . '</div></div>';
	}

	/**
	 * Locked admin screen (full mode).
	 */
	public static function locked_page() {
		$s = self::state();
		OSB_Admin::open( __( 'Bookings are switched off', 'osam-bookings' ) );
		?>
		<div class="osb-card osb-licence-locked">
			<span class="dashicons dashicons-lock" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'This site’s OSAM subscription is suspended', 'osam-bookings' ); ?></h2>
			<p><?php echo esc_html( $s['message'] ? $s['message'] : __( 'Online booking, these screens and booking emails are paused until the account is brought up to date. Nothing has been deleted — everything comes back as it was once it’s paid.', 'osam-bookings' ) ); ?></p>
			<p>
				<?php if ( $s['invoice_url'] ) : ?><a class="osb-btn osb-btn--primary" href="<?php echo esc_url( $s['invoice_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View invoice', 'osam-bookings' ); ?></a><?php endif; ?>
				<?php if ( current_user_can( 'manage_options' ) ) : ?><a class="osb-btn osb-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=osb_licence_check' ), 'osb_licence_check' ) ); ?>"><?php esc_html_e( 'I’ve paid — check again', 'osam-bookings' ); ?></a><?php endif; ?>
			</p>
		</div>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Admin banner: overdue warning, or "switched off" notice.
	 */
	public static function notice() {
		if ( ! self::enabled() || ! current_user_can( 'osb_manage_bookings' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ours   = OSB_Admin::is_our_screen() || ( $screen && 'dashboard' === $screen->id );
		$s      = self::state();
		if ( ! $ours || 'active' === $s['status'] || self::full_off() ) {
			return; // Full mode has its own page.
		}
		$link = $s['invoice_url'] ? ' <a href="' . esc_url( $s['invoice_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View invoice', 'osam-bookings' ) . '</a>' : '';
		if ( 'overdue' === $s['status'] ) {
			$when = $s['grace_until'] ? date_i18n( get_option( 'date_format' ), strtotime( $s['grace_until'] . ' 12:00:00' ) ) : '';
			/* translators: %s date */
			$text = $s['message'] ? $s['message'] : ( $when ? sprintf( __( 'Your OSAM subscription payment is overdue. Online booking will be switched off on %s unless it’s paid.', 'osam-bookings' ), $when ) : __( 'Your OSAM subscription payment is overdue. Online booking will be switched off if it isn’t paid.', 'osam-bookings' ) );
			printf( '<div class="notice notice-warning"><p><strong>%s</strong> %s%s</p></div>', esc_html__( 'Payment overdue.', 'osam-bookings' ), esc_html( $text ), $link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		$text = $s['message'] ? $s['message'] : __( 'New bookings are switched off because this site’s OSAM subscription is suspended. Existing bookings are still here and their customers can still view or cancel them.', 'osam-bookings' );
		printf( '<div class="notice notice-error"><p><strong>%s</strong> %s%s</p></div>', esc_html__( 'Bookings switched off.', 'osam-bookings' ), esc_html( $text ), $link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
