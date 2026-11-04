<?php
/**
 * My bookings: a customer panel with password-free sign-in.
 *
 * Sign-in: the customer types their email → we email a one-time link (and a 6-digit code for when the
 * email is opened on another device) → a signed cookie keeps them signed in for 30 days. There are no
 * passwords or WordPress accounts to forget, and nothing to break when a theme changes.
 *
 * The panel itself is drawn by assets/js/portal.js from REST calls, so page caching can't show one
 * customer's bookings to another: the page HTML is the same for everybody.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer panel.
 */
class OSB_Portal {

	const COOKIE = 'osb_customer';
	const DAYS   = 30;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( 'osam_my_bookings', array( __CLASS__, 'shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'login_link' ), 1 );
		add_filter( 'osb_email_placeholders', array( __CLASS__, 'placeholders' ), 30, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	/**
	 * The My bookings page URL ('' when there's none).
	 *
	 * @return string
	 */
	public static function url() {
		$id = (int) OSB_Settings::get( 'portal_page' );
		return $id && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
	}

	/**
	 * Make the page if the site hasn't got one.
	 */
	public static function maybe_create_page() {
		$id = (int) OSB_Settings::get( 'portal_page' );
		if ( $id && get_post( $id ) ) {
			return;
		}
		global $wpdb;
		$found = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[osam_my_bookings%' LIMIT 1" );
		if ( ! $found ) {
			$found = (int) wp_insert_post(
				array(
					'post_title'   => __( 'My bookings', 'osam-bookings' ),
					'post_name'    => 'my-bookings',
					'post_content' => '<!-- wp:shortcode -->[osam_my_bookings]<!-- /wp:shortcode -->',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
		}
		if ( $found ) {
			OSB_Settings::update( array( 'portal_page' => $found ) );
		}
	}

	/**
	 * [osam_my_bookings].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		OSB_Frontend::prepare();
		wp_enqueue_style( 'osb-booking' );
		wp_enqueue_script( 'osb-portal', OSB_URL . 'assets/js/portal.js', array(), OSB_VERSION, true );
		$cfg = array(
			'rest'       => esc_url_raw( rest_url( OSB_Rest::NS . '/portal' ) ),
			'nonce'      => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'bookUrl'    => OSB_Settings::get( 'booking_page' ) ? get_permalink( (int) OSB_Settings::get( 'booking_page' ) ) : home_url( '/' ),
			'bundles'    => OSB_Bundles::enabled(),
			'i18n'       => self::strings(),
		);
		// Arriving from a sign-in link that had a problem.
		if ( isset( $_GET['osb_login_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$cfg['notice'] = __( 'That sign-in link has expired or was already used. Enter your email to get a new one.', 'osam-bookings' );
		}
		$classes = trim( 'osb-booking osb-portal ' . implode( ' ', OSB_Frontend::display_classes( array() ) ) );
		return '<div class="' . esc_attr( $classes ) . '" data-portal="' . esc_attr( wp_json_encode( $cfg ) ) . '"><div class="osb-loading">' . esc_html__( 'Loading…', 'osam-bookings' ) . '</div><noscript>' . esc_html__( 'Please turn on JavaScript to see your bookings.', 'osam-bookings' ) . '</noscript></div>';
	}

	/**
	 * Words for the panel.
	 *
	 * @return array
	 */
	public static function strings() {
		return array(
			'title'        => __( 'My bookings', 'osam-bookings' ),
			'signInTitle'  => __( 'See and manage your bookings', 'osam-bookings' ),
			'signInText'   => __( 'Enter the email address you booked with. We’ll send you a link to sign in — no password needed.', 'osam-bookings' ),
			'email'        => __( 'Email address', 'osam-bookings' ),
			'sendLink'     => __( 'Email me a sign-in link', 'osam-bookings' ),
			'sentTitle'    => __( 'Check your email', 'osam-bookings' ),
			/* translators: %s email */
			'sentText'     => __( 'If %s has bookings with us, we’ve sent a sign-in link. It works for 30 minutes.', 'osam-bookings' ),
			'codeLabel'    => __( 'Or type the 6-digit code from the email', 'osam-bookings' ),
			'signIn'       => __( 'Sign in', 'osam-bookings' ),
			'otherEmail'   => __( 'Use a different email', 'osam-bookings' ),
			'signOut'      => __( 'Sign out', 'osam-bookings' ),
			'upcoming'     => __( 'Coming up', 'osam-bookings' ),
			'past'         => __( 'Past bookings', 'osam-bookings' ),
			'noUpcoming'   => __( 'You have nothing booked at the moment.', 'osam-bookings' ),
			'bundles'      => __( 'Your bundles', 'osam-bookings' ),
			'left'         => __( 'left', 'osam-bookings' ),
			'useBy'        => __( 'Use by', 'osam-bookings' ),
			'bookNow'      => __( 'Make a booking', 'osam-bookings' ),
			'bookAgain'    => __( 'Book again', 'osam-bookings' ),
			'manage'       => __( 'Change time', 'osam-bookings' ),
			'view'         => __( 'View', 'osam-bookings' ),
			'invoice'      => __( 'Invoice', 'osam-bookings' ),
			'cancel'       => __( 'Cancel', 'osam-bookings' ),
			'cancelAll'    => __( 'Cancel this and later dates', 'osam-bookings' ),
			'confirmCancel' => __( 'Cancel this booking?', 'osam-bookings' ),
			'confirmAll'   => __( 'Cancel this date and every later date in the series?', 'osam-bookings' ),
			'keep'         => __( 'Keep it', 'osam-bookings' ),
			'yesCancel'    => __( 'Yes, cancel', 'osam-bookings' ),
			'cancelled'    => __( 'Booking cancelled.', 'osam-bookings' ),
			'cantCancel'   => __( 'To change this booking, please contact us.', 'osam-bookings' ),
			'loading'      => __( 'Loading…', 'osam-bookings' ),
			'error'        => __( 'Something went wrong. Please try again.', 'osam-bookings' ),
			'showMore'     => __( 'Show more', 'osam-bookings' ),
			'signedInAs'   => __( 'Signed in as', 'osam-bookings' ),
			'repeats'      => __( 'Repeats', 'osam-bookings' ),
			'extras'       => __( 'Extras', 'osam-bookings' ),
			'paidWith'     => __( 'Paid with', 'osam-bookings' ),
			'total'        => __( 'Total', 'osam-bookings' ),
			'reference'    => __( 'Ref', 'osam-bookings' ),
			'code'         => __( 'Code', 'osam-bookings' ),
		);
	}

	/**
	 * {portal_url}, {portal_link}, {login_button}/{login_code} (sign-in email only).
	 *
	 * @param array  $map     Placeholders.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function placeholders( $map, $booking ) {
		$url                  = self::url();
		$map['{portal_url}']  = esc_url( $url );
		$map['{portal_link}'] = $url ? '<p style="font-size:14px"><a href="' . esc_url( $url ) . '">' . esc_html__( 'See all your bookings', 'osam-bookings' ) . '</a></p>' : '';
		if ( ! isset( $map['{login_button}'] ) ) {
			$map['{login_button}'] = '';
			$map['{login_code}']   = '';
		}
		// Every "manage" button also offers the panel.
		if ( $url && ! empty( $map['{manage_button}'] ) && false === strpos( $map['{manage_button}'], 'osb-portal-link' ) ) {
			$map['{manage_button}'] .= '<p class="osb-portal-link" style="margin:-14px 0 24px;font-size:14px"><a href="' . esc_url( $url ) . '">' . esc_html__( 'See all your bookings', 'osam-bookings' ) . '</a></p>';
		}
		return $map;
	}

	/* ---------------------------------------------------------------------
	 * Sessions
	 * ------------------------------------------------------------------ */

	/**
	 * Signing key (rotate it to sign everyone out).
	 *
	 * @return string
	 */
	private static function key() {
		$k = (string) get_option( 'osb_portal_key' );
		if ( strlen( $k ) < 32 ) {
			$k = wp_generate_password( 64, false, false );
			update_option( 'osb_portal_key', $k, true );
		}
		return $k . wp_salt( 'auth' );
	}

	/**
	 * Normalise an email.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function norm( $email ) {
		$email = strtolower( trim( sanitize_email( (string) $email ) ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Cookie value for an email.
	 *
	 * @param string $email   Email.
	 * @param int    $expires Unix time.
	 * @return string
	 */
	private static function sign( $email, $expires ) {
		$payload = rtrim( strtr( base64_encode( $email ), '+/', '-_' ), '=' ) . '.' . (int) $expires; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return $payload . '.' . hash_hmac( 'sha256', $payload, self::key() );
	}

	/**
	 * Start a session (sets the cookie).
	 *
	 * @param string $email Email.
	 */
	public static function sign_in( $email ) {
		$exp = time() + self::DAYS * DAY_IN_SECONDS;
		$val = self::sign( $email, $exp );
		$_COOKIE[ self::COOKIE ] = $val;
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$val,
				array(
					'expires'  => $exp,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
	}

	/**
	 * End the session.
	 */
	public static function sign_out() {
		unset( $_COOKIE[ self::COOKIE ] );
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', array( 'expires' => time() - 3600, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN ? COOKIE_DOMAIN : '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		}
	}

	/**
	 * Signed-in customer's email ('' when signed out).
	 *
	 * @return string
	 */
	public static function email() {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $raw && 3 === count( explode( '.', $raw ) ) ) {
			list( $b64, $exp, $sig ) = explode( '.', $raw );
			$payload                 = $b64 . '.' . $exp;
			if ( (int) $exp > time() && hash_equals( hash_hmac( 'sha256', $payload, self::key() ), $sig ) ) {
				$email = self::norm( (string) base64_decode( strtr( $b64, '-_', '+/' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				if ( $email ) {
					return $email;
				}
			}
		}
		// Signed in to WordPress with the same email: no need for a link.
		if ( is_user_logged_in() ) {
			$u = wp_get_current_user();
			return self::norm( $u->user_email );
		}
		return '';
	}

	/**
	 * Has this email ever booked (or bought a bundle)?
	 *
	 * @param string $email Email.
	 * @return object|null Latest booking.
	 */
	public static function latest_booking( $email ) {
		global $wpdb;
		$t = OSB_Install::table( 'bookings' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE LOWER(customer_email) = %s ORDER BY id DESC LIMIT 1", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count an attempt; false when over the limit.
	 *
	 * @param string $what  Bucket.
	 * @param int    $limit Allowed per 15 minutes.
	 * @return bool
	 */
	private static function allow( $what, $limit ) {
		$key = 'osb_pl_' . md5( $what );
		$n   = (int) get_transient( $key );
		if ( $n >= $limit ) {
			return false;
		}
		set_transient( $key, $n + 1, 15 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Client IP for rate limits.
	 *
	 * @return string
	 */
	private static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'x';
	}

	/**
	 * Email a sign-in link and code.
	 *
	 * @param string $email Email.
	 * @return bool Sent (false is never shown to the customer, to keep emails private).
	 */
	public static function send_link( $email ) {
		$email = self::norm( $email );
		if ( ! $email || ! self::allow( 'e' . $email, 3 ) ) {
			return false;
		}
		$latest = self::latest_booking( $email );
		if ( ! $latest ) {
			return false;
		}
		$token = wp_generate_password( 40, false, false );
		$code  = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		set_transient( 'osb_login_' . hash( 'sha256', $token ), $email, 30 * MINUTE_IN_SECONDS );
		set_transient( 'osb_logincode_' . md5( $email ), array( hash( 'sha256', $code ), 0 ), 30 * MINUTE_IN_SECONDS );
		$base = self::url() ? self::url() : home_url( '/' );
		$link = add_query_arg( 'osb_login', $token, $base );
		$ds   = OSB_Emails::design();
		return OSB_Emails::send(
			'customer_login',
			$latest,
			$email,
			true,
			array(
				'{login_button}' => OSB_Emails::button( $link, __( 'Sign in to My bookings', 'osam-bookings' ), $ds ),
				'{login_code}'   => esc_html( $code ),
				'{login_url}'    => esc_url( $link ),
			)
		);
	}

	/**
	 * ?osb_login=… from the email: sign in and tidy the address bar.
	 */
	public static function login_link() {
		if ( empty( $_GET['osb_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['osb_login'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$key   = 'osb_login_' . hash( 'sha256', $token );
		$email = get_transient( $key );
		$back  = remove_query_arg( 'osb_login' );
		nocache_headers();
		if ( $email ) {
			delete_transient( $key );
			delete_transient( 'osb_logincode_' . md5( $email ) );
			self::sign_in( $email );
			wp_safe_redirect( $back );
		} else {
			wp_safe_redirect( add_query_arg( 'osb_login_error', 1, $back ) );
		}
		exit;
	}

	/**
	 * Sign in with the emailed code.
	 *
	 * @param string $email Email.
	 * @param string $code  Code.
	 * @return bool
	 */
	public static function check_code( $email, $code ) {
		$email = self::norm( $email );
		$code  = preg_replace( '/\D/', '', (string) $code );
		$key   = 'osb_logincode_' . md5( $email );
		$saved = get_transient( $key );
		if ( ! $email || 6 !== strlen( $code ) || ! is_array( $saved ) ) {
			return false;
		}
		if ( (int) $saved[1] >= 5 ) {
			delete_transient( $key ); // Too many tries: they need a new code.
			return false;
		}
		if ( ! hash_equals( (string) $saved[0], hash( 'sha256', $code ) ) ) {
			set_transient( $key, array( $saved[0], (int) $saved[1] + 1 ), 30 * MINUTE_IN_SECONDS );
			return false;
		}
		delete_transient( $key );
		self::sign_in( $email );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------ */

	/**
	 * A booking as the panel shows it.
	 *
	 * @param object $b Booking.
	 * @return array
	 */
	public static function card( $b ) {
		$d    = OSB_Bookings::details( $b );
		$page = OSB_Bookings::manage_url( $b );
		$rows = array();
		if ( ! empty( $d['addons'] ) ) {
			$rows[] = array( 'extras', implode( ', ', wp_list_pluck( $d['addons'], 'name' ) ) );
		}
		if ( ! empty( $d['pass_label'] ) ) {
			$rows[] = array( 'paidWith', $d['pass_label'] );
		}
		return array(
			'id'          => (int) $b->id,
			'token'       => $b->token,
			'type'        => $b->type,
			'title'       => $d['item_name'],
			'when'        => 'bundle' === $b->type ? '' : $d['date'] . ', ' . $d['time'] . ( $d['end_time'] && $d['end_time'] !== $d['time'] ? ' – ' . $d['end_time'] : '' ),
			'day'         => date_i18n( 'j', strtotime( $b->start_at ) ),
			'month'       => date_i18n( 'M', strtotime( $b->start_at ) ),
			'where'       => $d['location'],
			'with'        => $d['staff_name'],
			'status'      => $d['status'],
			'status_key'  => $b->status,
			'amount'      => (float) $b->amount > 0 ? $d['amount'] : '',
			'access'      => $d['access'] ?? array(),
			'access_note' => ( $d['access_later'] ?? '' ) ? $d['access_later'] : '',
			'series'      => ! empty( $d['series_label'] ) ? $d['series_label'] : '',
			'series_lead' => ! empty( $d['series_label'] ),
			'rows'        => $rows,
			'can_cancel'  => (bool) $d['can_cancel'],
			'can_move'    => (bool) $d['can_reschedule'],
			'manage_url'  => $page,
			'invoices'    => isset( $d['invoices'] ) ? $d['invoices'] : array(),
			'color'       => '',
		);
	}

	/**
	 * Everything for the signed-in customer.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function data( $email ) {
		global $wpdb;
		$t      = OSB_Install::table( 'bookings' );
		$now    = current_time( 'mysql' );
		$hidden = "'pending_payment','payment_failed'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$up   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE LOWER(customer_email) = %s AND type <> 'bundle' AND end_at >= %s AND status NOT IN ($hidden,'cancelled') ORDER BY start_at ASC LIMIT 100", $email, $now ) );
		$past = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE LOWER(customer_email) = %s AND type <> 'bundle' AND ( end_at < %s OR status = 'cancelled' ) AND status NOT IN ($hidden) ORDER BY start_at DESC LIMIT 30", $email, $now ) );
		$p    = OSB_Install::table( 'passes' );
		$pass = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $p WHERE LOWER(customer_email) = %s AND status IN ('active','pending') ORDER BY id DESC LIMIT 20", $email ) );
		// phpcs:enable
		$latest = self::latest_booking( $email );
		$out    = array(
			'email'    => $email,
			'name'     => $latest ? trim( strtok( (string) $latest->customer_name, ' ' ) ) : '',
			'upcoming' => array_map( array( __CLASS__, 'card' ), $up ),
			'past'     => array_map( array( __CLASS__, 'card' ), $past ),
			'bundles'  => array(),
		);
		foreach ( $pass as $ps ) {
			$expired = $ps->expires_at && $ps->expires_at < current_time( 'Y-m-d' );
			if ( $expired ) {
				continue;
			}
			$out['bundles'][] = array(
				'name'    => $ps->name,
				'code'    => 'active' === $ps->status ? $ps->code : '',
				'pending' => 'pending' === $ps->status,
				'left'    => OSB_Bundles::left( $ps ),
				'of'      => (int) $ps->credits,
				'unit'    => $ps->unit,
				'expires' => $ps->expires_at ? date_i18n( get_option( 'date_format' ), strtotime( $ps->expires_at ) ) : '',
				'covers'  => OSB_Items::describe( OSB_Items::decode( $ps->applies ) ),
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------ */

	/**
	 * Routes.
	 */
	public static function routes() {
		$ns   = OSB_Rest::NS;
		$open = '__return_true';
		register_rest_route( $ns, '/portal/start', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_start' ), 'permission_callback' => $open ) );
		register_rest_route( $ns, '/portal/code', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_code' ), 'permission_callback' => $open ) );
		register_rest_route( $ns, '/portal/me', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_me' ), 'permission_callback' => $open ) );
		register_rest_route( $ns, '/portal/logout', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_logout' ), 'permission_callback' => $open ) );
		register_rest_route( $ns, '/portal/cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_cancel' ), 'permission_callback' => $open ) );
	}

	/**
	 * Uncacheable JSON.
	 *
	 * @param mixed $data Data.
	 * @param int   $code HTTP code.
	 * @return WP_REST_Response
	 */
	private static function out( $data, $code = 200 ) {
		$r = new WP_REST_Response( $data, $code );
		$r->header( 'Cache-Control', 'no-store, private' );
		return $r;
	}

	/**
	 * POST /portal/start { email }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_start( WP_REST_Request $request ) {
		$email = self::norm( (string) $request->get_param( 'email' ) );
		if ( ! $email ) {
			return self::out( array( 'message' => __( 'Please enter a valid email address.', 'osam-bookings' ) ), 400 );
		}
		if ( ! self::allow( 'i' . self::ip(), 10 ) ) {
			return self::out( array( 'message' => __( 'Too many attempts. Please wait a few minutes.', 'osam-bookings' ) ), 429 );
		}
		self::send_link( $email );
		// Same answer whether or not the email has bookings, so nobody can test addresses.
		return self::out( array( 'ok' => true ) );
	}

	/**
	 * POST /portal/code { email, code }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_code( WP_REST_Request $request ) {
		if ( ! self::allow( 'c' . self::ip(), 15 ) ) {
			return self::out( array( 'message' => __( 'Too many attempts. Please wait a few minutes.', 'osam-bookings' ) ), 429 );
		}
		$email = self::norm( (string) $request->get_param( 'email' ) );
		if ( ! self::check_code( $email, (string) $request->get_param( 'code' ) ) ) {
			return self::out( array( 'message' => __( 'That code isn’t right or has expired. Check the latest email, or ask for a new one.', 'osam-bookings' ) ), 400 );
		}
		return self::out( self::data( $email ) );
	}

	/**
	 * GET /portal/me.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_me() {
		$email = self::email();
		if ( ! $email ) {
			return self::out( array( 'signed_in' => false ) );
		}
		return self::out( array_merge( array( 'signed_in' => true ), self::data( $email ) ) );
	}

	/**
	 * POST /portal/logout.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_logout() {
		self::sign_out();
		return self::out( array( 'ok' => true ) );
	}

	/**
	 * POST /portal/cancel { token, all }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_cancel( WP_REST_Request $request ) {
		$email   = self::email();
		$booking = OSB_DB::booking_by_token( (string) $request->get_param( 'token' ) );
		if ( ! $email || ! $booking || self::norm( $booking->customer_email ) !== $email ) {
			return self::out( array( 'message' => __( 'Please sign in again.', 'osam-bookings' ) ), 403 );
		}
		if ( ! OSB_Bookings::can_customer_cancel( $booking ) ) {
			return self::out( array( 'message' => __( 'This booking can no longer be cancelled online. Please contact us.', 'osam-bookings' ) ), 400 );
		}
		if ( $request->get_param( 'all' ) && (int) $booking->series_id ) {
			OSB_Repeat::cancel_from( $booking, true );
		} else {
			OSB_Bookings::set_status( $booking->id, 'cancelled' );
		}
		return self::out( self::data( $email ) );
	}
}
