<?php
/**
 * Two-way sync with Google Calendar and Microsoft 365 / Outlook.com calendars.
 *
 * Each staff member (the owner in solo mode) can connect one external calendar:
 * - Push: their bookings, the events they host and the course sessions they teach are written
 *   into the chosen calendar and kept up to date (created, moved, removed).
 * - Pull: busy times in the calendars they pick block those times for online booking. Our own
 *   pushed entries are ignored so a booking never blocks itself.
 *
 * Sync is reconciliation-based: every run compares what *should* be in the calendar with what
 * we last wrote (calendar_events map + a hash) and only calls the API for differences.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calendar sync engine and provider API calls.
 */
class OSB_Calendar_Sync {

	const HOOK       = 'osb_calendar_sync';
	const MAX_CALLS  = 60; // API writes per connection per run; the rest catch up next run.
	const BUSY_TTL   = 600; // Seconds before busy times are re-fetched.
	const WINDOW_MAX = 365; // Days ahead that are pushed.
	const RUN_BUDGET = 40;  // Seconds one background run may spend before handing over to the next run.

	// OSAM's shared connector. Override with the OSB_CONNECTOR_URL constant or the setting.
	const DEFAULT_CONNECTOR = 'https://connect.osamweb.com';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		foreach ( array( 'osb_booking_created', 'osb_booking_status_changed', 'osb_booking_admin_updated', 'osb_booking_deleted', 'osb_booking_paid', 'osb_booking_rescheduled', 'osb_event_saved', 'osb_course_saved', 'osb_course_intake_saved' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'soon' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Providers
	 * ------------------------------------------------------------------ */

	/**
	 * Supported providers.
	 *
	 * @return array id => label
	 */
	public static function providers() {
		return array(
			'google'    => __( 'Google Calendar', 'osam-bookings' ),
			'microsoft' => __( 'Microsoft 365 / Outlook', 'osam-bookings' ),
		);
	}

	/**
	 * Has the site owner entered app credentials for a provider?
	 *
	 * @param string $provider google|microsoft.
	 * @return bool
	 */
	public static function configured( $provider ) {
		return '' !== self::mode( $provider );
	}

	/**
	 * Has this site got its own app details for a provider?
	 *
	 * @param string $provider google|microsoft.
	 * @return bool
	 */
	public static function own_app( $provider ) {
		$p = 'google' === $provider ? 'gcal' : 'mscal';
		return '' !== trim( (string) OSB_Settings::get( $p . '_client_id' ) ) && '' !== trim( (string) OSB_Settings::get( $p . '_client_secret' ) );
	}

	/**
	 * How new connections for a provider are made.
	 *
	 * @param string $provider google|microsoft.
	 * @return string own|connector|'' (not available)
	 */
	public static function mode( $provider ) {
		if ( self::own_app( $provider ) ) {
			return 'own';
		}
		return in_array( $provider, self::connector_providers(), true ) ? 'connector' : '';
	}

	/* ---------------------------------------------------------------------
	 * OSAM connector (one shared Google / Microsoft app for every site)
	 * ------------------------------------------------------------------ */

	/**
	 * Base URL of the connector ('' when switched off).
	 *
	 * Order: the setting, then the OSB_CONNECTOR_URL constant (wp-config.php), then the default.
	 *
	 * @return string
	 */
	public static function connector_url() {
		if ( ! OSB_Settings::get( 'cal_use_connector', 1 ) ) {
			return '';
		}
		$url = trim( (string) OSB_Settings::get( 'cal_connector_url' ) );
		if ( '' === $url ) {
			$url = defined( 'OSB_CONNECTOR_URL' ) ? (string) OSB_CONNECTOR_URL : self::DEFAULT_CONNECTOR;
		}
		return untrailingslashit( esc_url_raw( (string) apply_filters( 'osb_connector_url', $url ) ) );
	}

	/**
	 * Connector endpoint.
	 *
	 * @param string $path Route under osam-connect/v1.
	 * @return string
	 */
	private static function connector_endpoint( $path ) {
		return self::connector_url() . '/wp-json/osam-connect/v1/' . ltrim( $path, '/' );
	}

	/**
	 * Ask the connector what it offers (cached for an hour, or 5 minutes after a failure).
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array [ ok, providers[], name, error ]
	 */
	public static function connector_status( $fresh = false ) {
		$url = self::connector_url();
		if ( '' === $url ) {
			return array( 'ok' => false, 'providers' => array(), 'name' => '', 'error' => __( 'Switched off.', 'osam-bookings' ) );
		}
		$key    = 'osb_connector_' . md5( $url . '|' . home_url() );
		$cached = $fresh ? false : get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res  = wp_remote_get( add_query_arg( 'site', rawurlencode( home_url() ), self::connector_endpoint( 'status' ) ), array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json' ) ) );
		$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( is_array( $data ) && ! empty( $data['ok'] ) ) {
			$out = array(
				'ok'        => true,
				'providers' => array_values( array_intersect( array( 'google', 'microsoft' ), (array) ( $data['providers'] ?? array() ) ) ),
				'name'      => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'error'     => '',
			);
			set_transient( $key, $out, HOUR_IN_SECONDS );
			return $out;
		}
		if ( is_array( $data ) && isset( $data['message'] ) ) {
			$err = sanitize_text_field( (string) $data['message'] );
		} elseif ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) && false !== stripos( (string) wp_remote_retrieve_body( $res ), '<html' ) ) {
			// WordPress answered with a web page instead of the connector's REST route.
			$err = __( 'that address shows a web page instead of the connector. On the connector site, go to Settings → Permalinks, choose “Post name” and save (and check its .htaccess file was updated), and make sure the OSAM Calendar Connector plugin is active.', 'osam-bookings' );
		} else {
			$err = is_wp_error( $res ) ? $res->get_error_message() : sprintf( /* translators: %s HTTP code */ __( 'Unexpected answer (HTTP %s).', 'osam-bookings' ), wp_remote_retrieve_response_code( $res ) );
		}
		$out = array( 'ok' => false, 'providers' => array(), 'name' => '', 'error' => $err );
		set_transient( $key, $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	/**
	 * Providers available through the connector.
	 *
	 * @return string[]
	 */
	public static function connector_providers() {
		if ( '' === self::connector_url() ) {
			return array();
		}
		return self::connector_status()['providers'];
	}

	/**
	 * POST to the connector.
	 *
	 * @param string $path Route.
	 * @param array  $body Fields.
	 * @return array|WP_Error Token-endpoint-shaped array.
	 */
	private static function connector_post( $path, array $body ) {
		$res = wp_remote_post(
			self::connector_endpoint( $path ),
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array_merge( array( 'site' => home_url() ), $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$code = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : ( is_array( $data ) && ! empty( $data['code'] ) ? (string) $data['code'] : 'osb_connector' );
			$msg  = is_array( $data ) && ! empty( $data['error_description'] ) ? (string) $data['error_description'] : ( is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
			return new WP_Error( $code, $msg );
		}
		return $data;
	}

	/**
	 * OAuth redirect URI to register with Google / Microsoft.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return rest_url( OSB_Rest::NS . '/calendar/callback' );
	}

	/**
	 * Microsoft tenant (common = any work, school or personal account).
	 *
	 * @return string
	 */
	private static function ms_tenant() {
		$t = preg_replace( '/[^A-Za-z0-9.\-]/', '', (string) OSB_Settings::get( 'mscal_tenant' ) );
		return $t ? $t : 'common';
	}

	/**
	 * Where to send the user to approve access.
	 *
	 * @param string $provider Provider.
	 * @param string $state    CSRF state.
	 * @return string
	 */
	public static function auth_url( $provider, $state ) {
		if ( 'google' === $provider ) {
			return add_query_arg(
				array(
					'client_id'              => rawurlencode( trim( OSB_Settings::get( 'gcal_client_id' ) ) ),
					'redirect_uri'           => rawurlencode( self::redirect_uri() ),
					'response_type'          => 'code',
					'scope'                  => rawurlencode( 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.calendarlist.readonly' ),
					'access_type'            => 'offline',
					'prompt'                 => 'consent',
					'include_granted_scopes' => 'true',
					'state'                  => $state,
				),
				'https://accounts.google.com/o/oauth2/v2/auth'
			);
		}
		return add_query_arg(
			array(
				'client_id'     => rawurlencode( trim( OSB_Settings::get( 'mscal_client_id' ) ) ),
				'redirect_uri'  => rawurlencode( self::redirect_uri() ),
				'response_type' => 'code',
				'response_mode' => 'query',
				'scope'         => rawurlencode( 'offline_access openid email User.Read Calendars.ReadWrite' ),
				'prompt'        => 'select_account',
				'state'         => $state,
			),
			'https://login.microsoftonline.com/' . self::ms_tenant() . '/oauth2/v2.0/authorize'
		);
	}

	/**
	 * Token endpoint.
	 *
	 * @param string $provider Provider.
	 * @return string
	 */
	private static function token_url( $provider ) {
		return 'google' === $provider ? 'https://oauth2.googleapis.com/token' : 'https://login.microsoftonline.com/' . self::ms_tenant() . '/oauth2/v2.0/token';
	}

	/**
	 * Call the token endpoint.
	 *
	 * @param string $provider Provider.
	 * @param array  $params   grant params.
	 * @return array|WP_Error
	 */
	private static function token_request( $provider, array $params ) {
		$p      = 'google' === $provider ? 'gcal' : 'mscal';
		$params = array_merge(
			array(
				'client_id'     => trim( OSB_Settings::get( $p . '_client_id' ) ),
				'client_secret' => trim( OSB_Settings::get( $p . '_client_secret' ) ),
			),
			$params
		);
		if ( 'microsoft' === $provider ) {
			$params['scope'] = 'offline_access openid email User.Read Calendars.ReadWrite';
		}
		$res = wp_remote_post(
			self::token_url( $provider ),
			array(
				'timeout' => 15,
				'body'    => $params,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['access_token'] ) ) {
			$msg = isset( $data['error_description'] ) ? $data['error_description'] : ( isset( $data['error'] ) ? $data['error'] : 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
			return new WP_Error( isset( $data['error'] ) ? (string) $data['error'] : 'osb_token', (string) $msg );
		}
		return $data;
	}

	/* ---------------------------------------------------------------------
	 * Connections
	 * ------------------------------------------------------------------ */

	/**
	 * Connection for a staff member.
	 *
	 * @param int $staff_id Staff.
	 * @return object|null
	 */
	public static function connection_for( $staff_id ) {
		global $wpdb;
		$t = OSB_Install::table( 'calendar_connections' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE staff_id = %d ORDER BY id DESC LIMIT 1", $staff_id ) );
	}

	/**
	 * Every connection.
	 *
	 * @return array
	 */
	public static function connections() {
		global $wpdb;
		$t = OSB_Install::table( 'calendar_connections' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM $t ORDER BY id ASC" );
	}

	/**
	 * Encrypt a token for storage (tied to this site's salts).
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function seal( $plain ) {
		if ( '' === (string) $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return (string) $plain;
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( (string) $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false === $enc ? (string) $plain : 'enc:' . base64_encode( $iv . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored token.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function unseal( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$out = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $out ? '' : $out;
	}

	/**
	 * Finish the OAuth flow: swap the code for tokens and save the connection.
	 *
	 * @param string $provider Provider.
	 * @param int    $staff_id Staff.
	 * @param string $code     Authorisation code.
	 * @return int|WP_Error Connection id.
	 */
	public static function connect( $provider, $staff_id, $code ) {
		$tok = self::token_request(
			$provider,
			array(
				'code'         => $code,
				'redirect_uri' => self::redirect_uri(),
				'grant_type'   => 'authorization_code',
			)
		);
		if ( is_wp_error( $tok ) ) {
			return $tok;
		}
		return self::connect_tokens( $provider, $staff_id, $tok, '' );
	}

	/**
	 * Save a new connection from a token response and set it up (calendar, push, pull).
	 *
	 * @param string $provider Provider.
	 * @param int    $staff_id Staff.
	 * @param array  $tok      Token response.
	 * @param string $via      '' = this site's own app, 'connector' = OSAM connector.
	 * @return int|WP_Error Connection id.
	 */
	public static function connect_tokens( $provider, $staff_id, array $tok, $via = '' ) {
		if ( empty( $tok['refresh_token'] ) ) {
			return new WP_Error( 'osb_no_refresh', __( 'The calendar did not grant offline access. Please try connecting again.', 'osam-bookings' ) );
		}

		// Replace any existing connection for this person.
		self::disconnect( $staff_id, false );

		$id = OSB_DB::insert(
			'calendar_connections',
			array(
				'staff_id'      => (int) $staff_id,
				'provider'      => $provider,
				'via'           => 'connector' === $via ? 'connector' : '',
				'account'       => '',
				'access_token'  => self::seal( $tok['access_token'] ),
				'refresh_token' => self::seal( $tok['refresh_token'] ),
				'expires_at'    => time() + (int) ( isset( $tok['expires_in'] ) ? $tok['expires_in'] : 3600 ) - 60,
				'push'          => 1,
				'pull'          => 1,
				'status'        => 'active',
				'created_at'    => current_time( 'mysql' ),
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'osb_db', __( 'Could not save the calendar connection.', 'osam-bookings' ) );
		}
		$conn = OSB_DB::get( 'calendar_connections', $id );

		// Account name, then the calendar list (default = primary/default calendar).
		$account = '';
		if ( 'google' === $provider && ! empty( $tok['id_token'] ) ) {
			$parts = explode( '.', $tok['id_token'] );
			$claims = isset( $parts[1] ) ? json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ) : array(); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$account = isset( $claims['email'] ) ? $claims['email'] : '';
		} elseif ( 'microsoft' === $provider ) {
			$me = self::api( $conn, 'GET', 'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName' );
			if ( ! is_wp_error( $me ) ) {
				$account = ! empty( $me['mail'] ) ? $me['mail'] : ( isset( $me['userPrincipalName'] ) ? $me['userPrincipalName'] : '' );
			}
		}
		$calendars = self::fetch_calendars( $conn );
		$default   = '';
		$name      = '';
		if ( ! is_wp_error( $calendars ) ) {
			foreach ( $calendars as $cal ) {
				if ( $cal['primary'] ) {
					$default = $cal['id'];
					$name    = $cal['name'];
				}
			}
			if ( ! $default && $calendars ) {
				$default = $calendars[0]['id'];
				$name    = $calendars[0]['name'];
			}
		}
		OSB_DB::update(
			'calendar_connections',
			$id,
			array(
				'account'        => sanitize_text_field( $account ),
				'calendars'      => wp_json_encode( is_wp_error( $calendars ) ? array() : $calendars ),
				'calendar_id'    => $default,
				'calendar_name'  => $name,
				'busy_calendars' => wp_json_encode( $default ? array( $default ) : array() ),
			)
		);
		self::soon();
		do_action( 'osb_calendar_connected', $id, $staff_id, $provider );
		return $id;
	}

	/**
	 * Remove a connection (optionally taking our upcoming entries out of the calendar first).
	 *
	 * @param int  $staff_id Staff.
	 * @param bool $cleanup  Delete upcoming entries we created.
	 */
	public static function disconnect( $staff_id, $cleanup = true ) {
		global $wpdb;
		$conn = self::connection_for( $staff_id );
		if ( ! $conn ) {
			return;
		}
		$map = OSB_Install::table( 'calendar_events' );
		if ( $cleanup && 'active' === $conn->status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $map WHERE connection_id = %d AND start_at >= %s LIMIT 200", $conn->id, current_time( 'mysql' ) ) );
			foreach ( $rows as $row ) {
				self::remote_delete( $conn, $row->remote_id, $row->calendar_id );
			}
			if ( 'google' === $conn->provider ) {
				// Revoke so the app disappears from the account's "third-party access" list.
				wp_remote_post( 'https://oauth2.googleapis.com/revoke', array( 'timeout' => 8, 'body' => array( 'token' => self::unseal( $conn->refresh_token ) ) ) );
			}
		}
		$wpdb->delete( $map, array( 'connection_id' => (int) $conn->id ) );
		OSB_DB::delete( 'calendar_connections', $conn->id );
		delete_transient( 'osb_cal_busy_' . $conn->id );
		OSB_Availability::bust_cache();
	}

	/**
	 * Valid access token, refreshing when needed.
	 *
	 * @param object $conn Connection (updated in place).
	 * @return string|WP_Error
	 */
	private static function access_token( $conn ) {
		if ( (int) $conn->expires_at > time() + 30 ) {
			return self::unseal( $conn->access_token );
		}
		if ( isset( $conn->via ) && 'connector' === $conn->via ) {
			$tok = '' === self::connector_url()
				? new WP_Error( 'osb_connector_off', __( 'This calendar was connected through the OSAM connector, which is switched off. Reconnect it.', 'osam-bookings' ) )
				: self::connector_post(
					'refresh',
					array(
						'provider'      => $conn->provider,
						'refresh_token' => self::unseal( $conn->refresh_token ),
					)
				);
		} else {
			$tok = self::token_request(
				$conn->provider,
				array(
					'refresh_token' => self::unseal( $conn->refresh_token ),
					'grant_type'    => 'refresh_token',
				)
			);
		}
		if ( is_wp_error( $tok ) ) {
			// invalid_grant = the person revoked access or changed their password: needs reconnecting.
			self::fail( $conn, $tok, in_array( $tok->get_error_code(), array( 'invalid_grant', 'invalid_client', 'unauthorized_client' ), true ) );
			return $tok;
		}
		$data = array(
			'access_token' => self::seal( $tok['access_token'] ),
			'expires_at'   => time() + (int) ( isset( $tok['expires_in'] ) ? $tok['expires_in'] : 3600 ) - 60,
		);
		if ( ! empty( $tok['refresh_token'] ) ) {
			$data['refresh_token'] = self::seal( $tok['refresh_token'] ); // Microsoft rotates them.
		}
		OSB_DB::update( 'calendar_connections', $conn->id, $data );
		$conn->access_token = $data['access_token'];
		$conn->expires_at   = $data['expires_at'];
		if ( isset( $data['refresh_token'] ) ) {
			$conn->refresh_token = $data['refresh_token'];
		}
		return $tok['access_token'];
	}

	/**
	 * Record a problem on a connection.
	 *
	 * @param object          $conn  Connection.
	 * @param WP_Error|string $error Error.
	 * @param bool            $fatal Needs reconnecting.
	 */
	private static function fail( $conn, $error, $fatal = false ) {
		$msg  = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;
		$data = array( 'last_error' => substr( $msg, 0, 500 ) );
		if ( $fatal ) {
			$data['status'] = 'error';
		}
		OSB_DB::update( 'calendar_connections', $conn->id, $data );
		$conn->last_error = $data['last_error'];
		if ( $fatal ) {
			$conn->status = 'error';
		}
	}

	/**
	 * Authorised JSON request to a provider API.
	 *
	 * @param object     $conn    Connection.
	 * @param string     $method  GET|POST|PATCH|DELETE.
	 * @param string     $url     URL.
	 * @param array|null $body    JSON body.
	 * @param array      $headers Extra headers.
	 * @param bool       $retry   Internal: retried after a 401.
	 * @return array|WP_Error Decoded body (with '_status' for empty bodies).
	 */
	public static function api( $conn, $method, $url, $body = null, $headers = array(), $retry = true ) {
		$token = self::access_token( $conn );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 401 === $code && $retry ) {
			$conn->expires_at = 0; // Force a refresh and try once more.
			return self::api( $conn, $method, $url, $body, $headers, false );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$data = is_array( $data ) ? $data : array();
		if ( $code >= 400 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : ( isset( $data['error_description'] ) ? $data['error_description'] : 'HTTP ' . $code );
			return new WP_Error( 'osb_http_' . $code, (string) $msg, array( 'status' => $code ) );
		}
		$data['_status'] = $code;
		return $data;
	}

	/**
	 * The account's calendars.
	 *
	 * @param object $conn Connection.
	 * @return array|WP_Error [ [ id, name, primary, writable ] ]
	 */
	public static function fetch_calendars( $conn ) {
		$out = array();
		if ( 'google' === $conn->provider ) {
			$res = self::api( $conn, 'GET', 'https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250' );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			foreach ( isset( $res['items'] ) ? $res['items'] : array() as $c ) {
				$out[] = array(
					'id'       => (string) $c['id'],
					'name'     => isset( $c['summaryOverride'] ) ? $c['summaryOverride'] : ( isset( $c['summary'] ) ? $c['summary'] : $c['id'] ),
					'primary'  => ! empty( $c['primary'] ),
					'writable' => isset( $c['accessRole'] ) && in_array( $c['accessRole'], array( 'owner', 'writer' ), true ),
				);
			}
		} else {
			$res = self::api( $conn, 'GET', 'https://graph.microsoft.com/v1.0/me/calendars?$select=id,name,canEdit,isDefaultCalendar&$top=100' );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			foreach ( isset( $res['value'] ) ? $res['value'] : array() as $c ) {
				$out[] = array(
					'id'       => (string) $c['id'],
					'name'     => isset( $c['name'] ) ? $c['name'] : $c['id'],
					'primary'  => ! empty( $c['isDefaultCalendar'] ),
					'writable' => ! empty( $c['canEdit'] ),
				);
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Time helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Local "Y-m-d H:i:s" → UTC DateTime.
	 *
	 * @param string $local Local datetime.
	 * @return DateTime
	 */
	private static function utc( $local ) {
		$dt = new DateTime( $local, wp_timezone() );
		$dt->setTimezone( new DateTimeZone( 'UTC' ) );
		return $dt;
	}

	/**
	 * Any provider datetime → local "Y-m-d H:i:s".
	 *
	 * @param string $value Value.
	 * @param string $tz    Zone the value is in when it has no offset.
	 * @return string
	 */
	private static function to_local( $value, $tz = 'UTC' ) {
		try {
			$dt = new DateTime( preg_replace( '/\.\d+$/', '', (string) $value ), new DateTimeZone( $tz ) );
		} catch ( Exception $e ) {
			return '';
		}
		$dt->setTimezone( wp_timezone() );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/* ---------------------------------------------------------------------
	 * What should be in someone's calendar
	 * ------------------------------------------------------------------ */

	/**
	 * Items for a staff member between two local datetimes.
	 *
	 * @param int    $staff_id Staff.
	 * @param string $from     From.
	 * @param string $to       To.
	 * @return array key => [ start, end, summary, description, location ]
	 */
	public static function desired( $staff_id, $from, $to ) {
		global $wpdb;
		$items    = array();
		$b        = OSB_Install::table( 'bookings' );
		$map      = OSB_Admin_Dashboard::name_maps();

		// Appointments (course bookings appear per session below).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $b WHERE staff_id = %d AND type = 'service' AND start_at < %s AND end_at > %s AND status IN ('pending','confirmed','completed')", $staff_id, $to, $from ) );
		foreach ( $rows as $bk ) {
			$fields = OSB_Bookings::fields( $bk );
			$lines  = array_filter(
				array(
					$bk->customer_name,
					$bk->customer_email,
					$bk->customer_phone,
					isset( $fields['notes'] ) ? (string) $fields['notes'] : '',
					( $bk->seats > 1 ? sprintf( /* translators: %d places */ __( '%d places', 'osam-bookings' ), $bk->seats ) : '' ),
					( ! empty( $bk->meeting_url ) && 0 !== strpos( (string) $bk->meeting_id, 'booking-' ) ? __( 'Join online:', 'osam-bookings' ) . ' ' . $bk->meeting_url : '' ),
					sprintf( /* translators: %d booking id */ __( 'Booking #%d', 'osam-bookings' ), $bk->id ) . ' — ' . admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $bk->id ),
				)
			);
			$svc                         = isset( $map['services'][ (int) $bk->service_id ] ) ? $map['services'][ (int) $bk->service_id ]->name : __( 'Booking', 'osam-bookings' );
			$items[ 'booking-' . $bk->id ] = array(
				'start'       => $bk->start_at,
				'end'         => $bk->end_at,
				'summary'     => ( 'pending' === $bk->status ? __( 'Pending:', 'osam-bookings' ) . ' ' : '' ) . $svc . ' — ' . $bk->customer_name,
				'description' => implode( "\n", $lines ),
				'location'    => '',
			);
		}

		// Events they host.
		foreach ( OSB_DB::staff_events_between( $staff_id, $from, $to ) as $e ) {
			$ev = isset( $map['events'][ (int) $e->id ] ) ? $map['events'][ (int) $e->id ] : null;
			if ( ! $ev ) {
				continue;
			}
			$taken                     = OSB_DB::event_seats_taken( $ev->id );
			$items[ 'event-' . $ev->id ] = array(
				'start'       => $ev->start_at,
				/* translators: 1: event 2: booked 3: capacity */
				'summary'     => sprintf( __( '%1$s (%2$d/%3$d booked)', 'osam-bookings' ), $ev->name, $taken, $ev->capacity ),
				'end'         => $ev->end_at,
				'description' => admin_url( 'admin.php?page=osb-bookings&event_id=' . $ev->id ),
				'location'    => $ev->location,
			);
		}

		// Course sessions they teach.
		$numbers = array();
		foreach ( OSB_Courses::staff_sessions_between( $staff_id, $from, $to ) as $s ) {
			$co = isset( $map['courses'][ (int) $s->course_id ] ) ? $map['courses'][ (int) $s->course_id ] : null;
			if ( ! $co ) {
				continue;
			}
			if ( ! isset( $numbers[ (int) $s->intake_id ] ) ) {
				$numbers[ (int) $s->intake_id ] = array_map( 'intval', wp_list_pluck( OSB_Courses::sessions( $s->intake_id ), 'id' ) );
			}
			$ids  = $numbers[ (int) $s->intake_id ];
			$full = OSB_DB::get( 'course_sessions', $s->id );
			$in   = OSB_DB::get( 'course_intakes', $s->intake_id );
			$items[ 'session-' . $s->id ] = array(
				'start'       => $s->start_at,
				'end'         => $s->end_at,
				/* translators: 1: course 2: session number 3: total 4: enrolled */
				'summary'     => sprintf( __( '%1$s — session %2$d/%3$d (%4$d enrolled)', 'osam-bookings' ), $co->name, array_search( (int) $s->id, $ids, true ) + 1, count( $ids ), OSB_Courses::seats_taken( $s->intake_id ) ),
				'description' => trim( ( $in ? OSB_Courses::intake_label( $in ) . "\n" : '' ) . ( $full && $full->note ? $full->note . "\n" : '' ) . admin_url( 'admin.php?page=osb-courses&action=register&id=' . (int) $s->intake_id ) ),
				'location'    => $full && '' !== $full->location ? $full->location : $co->location,
			);
		}

		return apply_filters( 'osb_calendar_items', $items, $staff_id, $from, $to );
	}

	/* ---------------------------------------------------------------------
	 * Remote writes
	 * ------------------------------------------------------------------ */

	/**
	 * Provider body for an item.
	 *
	 * @param object $conn Connection.
	 * @param array  $item Item.
	 * @param string $key  Object key.
	 * @return array
	 */
	private static function body( $conn, array $item, $key ) {
		$tag = sprintf( /* translators: %s site name */ __( 'Added by %s bookings', 'osam-bookings' ), get_bloginfo( 'name' ) );
		if ( 'google' === $conn->provider ) {
			$tz   = wp_timezone_string();
			$zone = preg_match( '/^[+-]\d{2}:\d{2}$/', $tz ) ? 'UTC' : $tz;
			return array(
				'summary'            => $item['summary'],
				'description'        => trim( $item['description'] . "\n\n" . $tag ),
				'location'           => $item['location'],
				'start'              => array( 'dateTime' => self::utc( $item['start'] )->format( 'Y-m-d\TH:i:s\Z' ), 'timeZone' => $zone ),
				'end'                => array( 'dateTime' => self::utc( $item['end'] )->format( 'Y-m-d\TH:i:s\Z' ), 'timeZone' => $zone ),
				'extendedProperties' => array( 'private' => array( 'osb' => $key, 'osb_site' => home_url() ) ),
			);
		}
		return array(
			'subject'  => $item['summary'],
			'body'     => array(
				'contentType' => 'text',
				'content'     => trim( $item['description'] . "\n\n" . $tag ),
			),
			'location' => array( 'displayName' => $item['location'] ),
			'start'    => array( 'dateTime' => self::utc( $item['start'] )->format( 'Y-m-d\TH:i:s' ), 'timeZone' => 'UTC' ),
			'end'      => array( 'dateTime' => self::utc( $item['end'] )->format( 'Y-m-d\TH:i:s' ), 'timeZone' => 'UTC' ),
			'showAs'   => 'busy',
		);
	}

	/**
	 * Create an entry.
	 *
	 * @param object $conn Connection.
	 * @param array  $item Item.
	 * @param string $key  Key.
	 * @return string|WP_Error Remote id.
	 */
	private static function remote_create( $conn, array $item, $key ) {
		$cal = rawurlencode( $conn->calendar_id );
		$url = 'google' === $conn->provider
			? 'https://www.googleapis.com/calendar/v3/calendars/' . $cal . '/events'
			: 'https://graph.microsoft.com/v1.0/me/calendars/' . $cal . '/events';
		$res = self::api( $conn, 'POST', $url, self::body( $conn, $item, $key ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['id'] ) ? (string) $res['id'] : new WP_Error( 'osb_cal_create', __( 'The calendar did not return an id.', 'osam-bookings' ) );
	}

	/**
	 * Update an entry.
	 *
	 * @param object $conn      Connection.
	 * @param string $remote_id Remote id.
	 * @param string $calendar  Calendar it lives in.
	 * @param array  $item      Item.
	 * @param string $key       Key.
	 * @return true|WP_Error
	 */
	private static function remote_update( $conn, $remote_id, $calendar, array $item, $key ) {
		$url = 'google' === $conn->provider
			? 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar ) . '/events/' . rawurlencode( $remote_id )
			: 'https://graph.microsoft.com/v1.0/me/events/' . rawurlencode( $remote_id );
		$res = self::api( $conn, 'PATCH', $url, self::body( $conn, $item, $key ) );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Delete an entry (already gone counts as success).
	 *
	 * @param object $conn      Connection.
	 * @param string $remote_id Remote id.
	 * @param string $calendar  Calendar.
	 * @return true|WP_Error
	 */
	private static function remote_delete( $conn, $remote_id, $calendar ) {
		$url = 'google' === $conn->provider
			? 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar ) . '/events/' . rawurlencode( $remote_id )
			: 'https://graph.microsoft.com/v1.0/me/events/' . rawurlencode( $remote_id );
		$res = self::api( $conn, 'DELETE', $url );
		if ( is_wp_error( $res ) ) {
			$data = $res->get_error_data();
			if ( isset( $data['status'] ) && in_array( (int) $data['status'], array( 404, 410 ), true ) ) {
				return true;
			}
			return $res;
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Sync
	 * ------------------------------------------------------------------ */

	/**
	 * Ask for a sync shortly (after a booking changes).
	 */
	public static function soon() {
		if ( ! self::has_connections() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	/**
	 * Any connections at all? (Cheap check, cached per request.)
	 *
	 * @return bool
	 */
	public static function has_connections() {
		static $has = null;
		if ( null === $has ) {
			global $wpdb;
			$t   = OSB_Install::table( 'calendar_connections' );
			$has = (bool) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $has;
	}

	/**
	 * Sync every connection (cron).
	 */
	public static function run() {
		if ( ! OSB_Settings::feature( 'calendar_sync' ) ) {
			return;
		}
		// Shared hosts stop long requests (often as a 503), so each run works to a time budget
		// and books a follow-up run for whatever is left.
		$deadline = microtime( true ) + (int) apply_filters( 'osb_calendar_run_budget', self::RUN_BUDGET );
		$more     = false;
		foreach ( self::connections() as $conn ) {
			if ( 'active' !== $conn->status ) {
				continue;
			}
			$left = $deadline - microtime( true );
			if ( $left < 3 ) {
				$more = true;
				break;
			}
			if ( $conn->push ) {
				$stats = self::sync( $conn, $left );
				$more  = $more || ! empty( $stats[4] );
			}
			if ( $conn->pull && self::busy_stale( $conn ) && microtime( true ) < $deadline ) {
				self::refresh_busy( $conn );
			}
		}
		if ( $more ) {
			self::schedule_followup();
		}
	}

	/**
	 * Run the sync again shortly (used when a run stops early).
	 *
	 * @param int $delay Seconds.
	 */
	public static function schedule_followup( $delay = 30 ) {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
	}

	/**
	 * Push one connection.
	 *
	 * @param object $conn    Connection.
	 * @param float  $seconds Time budget (0 = no limit beyond MAX_CALLS).
	 * @return array [ created, updated, deleted, errors, more ] — more = 1 when it stopped before finishing.
	 */
	public static function sync( $conn, $seconds = 0 ) {
		global $wpdb;
		$stats    = array( 0, 0, 0, 0, 0 );
		$deadline = $seconds > 0 ? microtime( true ) + $seconds : 0;
		$stop     = static function ( $calls ) use ( $deadline ) {
			return $calls >= self::MAX_CALLS || ( $deadline && microtime( true ) >= $deadline );
		};
		if ( ! $conn->calendar_id ) {
			return $stats;
		}
		$now  = current_time( 'mysql' );
		$from = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - DAY_IN_SECONDS );
		$to   = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + self::WINDOW_MAX * DAY_IN_SECONDS );
		$want = self::desired( (int) $conn->staff_id, $from, $to );
		$t    = OSB_Install::table( 'calendar_events' );
		$have = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE connection_id = %d", $conn->id ) ) as $row ) {
			$have[ $row->object_key ] = $row;
		}
		$calls = 0;

		foreach ( $want as $key => $item ) {
			if ( $stop( $calls ) ) {
				$stats[4] = 1;
				break;
			}
			$hash = md5( wp_json_encode( $item ) . '|' . $conn->calendar_id );
			$row  = isset( $have[ $key ] ) ? $have[ $key ] : null;
			if ( $row && $row->hash === $hash ) {
				continue;
			}
			++$calls;
			if ( $row && $row->calendar_id === $conn->calendar_id ) {
				$ok = self::remote_update( $conn, $row->remote_id, $row->calendar_id, $item, $key );
				if ( is_wp_error( $ok ) && in_array( (int) ( $ok->get_error_data()['status'] ?? 0 ), array( 404, 410 ), true ) ) {
					$ok = null; // Deleted in the calendar app: put it back.
				}
				if ( true === $ok ) {
					$wpdb->update( $t, array( 'hash' => $hash, 'start_at' => $item['start'], 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
					++$stats[1];
					continue;
				}
				if ( is_wp_error( $ok ) ) {
					self::fail( $conn, $ok );
					++$stats[3];
					continue;
				}
			}
			if ( $row && $row->calendar_id !== $conn->calendar_id ) {
				// They picked a different calendar: move it by deleting the old copy.
				self::remote_delete( $conn, $row->remote_id, $row->calendar_id );
			}
			$remote = self::remote_create( $conn, $item, $key );
			if ( is_wp_error( $remote ) ) {
				self::fail( $conn, $remote );
				++$stats[3];
				continue;
			}
			$data = array(
				'connection_id' => (int) $conn->id,
				'object_key'    => $key,
				'remote_id'     => $remote,
				'calendar_id'   => $conn->calendar_id,
				'hash'          => $hash,
				'start_at'      => $item['start'],
				'updated_at'    => current_time( 'mysql' ),
			);
			if ( $row ) {
				$wpdb->update( $t, $data, array( 'id' => $row->id ) );
			} else {
				$wpdb->insert( $t, $data );
			}
			++$stats[0];
		}

		// Remove entries that shouldn't be there any more (cancelled, moved away, deleted).
		foreach ( $have as $key => $row ) {
			if ( isset( $want[ $key ] ) || $row->start_at < $from ) {
				continue; // Past entries stay as a record.
			}
			if ( $stop( $calls ) ) {
				$stats[4] = 1;
				break;
			}
			++$calls;
			$ok = self::remote_delete( $conn, $row->remote_id, $row->calendar_id );
			if ( is_wp_error( $ok ) ) {
				self::fail( $conn, $ok );
				++$stats[3];
				continue;
			}
			$wpdb->delete( $t, array( 'id' => (int) $row->id ) );
			++$stats[2];
		}

		$update = array( 'last_sync' => current_time( 'mysql' ) );
		if ( ! $stats[3] ) {
			$update['last_error'] = '';
		}
		OSB_DB::update( 'calendar_connections', $conn->id, $update );
		do_action( 'osb_calendar_synced', $conn, $stats );
		return $stats;
	}

	/**
	 * Make sure a synced calendar entry has a Google Meet / Teams link, creating the entry
	 * now if the regular sync hasn't yet. The entry stays under the normal sync afterwards.
	 *
	 * @param int    $staff_id Whose calendar.
	 * @param string $key      Object key (booking-12, event-3).
	 * @param string $from     Window start (local).
	 * @param string $to       Window end (local).
	 * @return string|WP_Error Join URL.
	 */
	public static function conference( $staff_id, $key, $from, $to ) {
		global $wpdb;
		$conn = self::connection_for( $staff_id );
		if ( ! OSB_Settings::feature( 'calendar_sync' ) || ! $conn || 'active' !== $conn->status || ! $conn->push || ! $conn->calendar_id ) {
			return new WP_Error( 'osb_meet_noconn', __( 'This needs the staff member’s Google or Microsoft calendar connected, with “Add bookings to my calendar” switched on.', 'osam-bookings' ) );
		}
		$want = self::desired( (int) $staff_id, gmdate( 'Y-m-d H:i:s', strtotime( $from ) - HOUR_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', strtotime( $to ) + HOUR_IN_SECONDS ) );
		if ( ! isset( $want[ $key ] ) ) {
			return new WP_Error( 'osb_meet_noitem', __( 'There is nothing to put in the calendar for this booking.', 'osam-bookings' ) );
		}
		$item = $want[ $key ];
		$body = self::body( $conn, $item, $key );
		if ( 'google' === $conn->provider ) {
			$body['conferenceData'] = array(
				'createRequest' => array(
					'requestId'             => substr( md5( $key . '|' . home_url() . '|' . $conn->id ), 0, 24 ),
					'conferenceSolutionKey' => array( 'type' => 'hangoutsMeet' ),
				),
			);
		} else {
			$body['isOnlineMeeting']       = true;
			$body['onlineMeetingProvider'] = 'teamsForBusiness';
		}
		$t   = OSB_Install::table( 'calendar_events' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE connection_id = %d AND object_key = %s", $conn->id, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row && $row->calendar_id === $conn->calendar_id ) {
			$url = 'google' === $conn->provider
				? 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $row->calendar_id ) . '/events/' . rawurlencode( $row->remote_id ) . '?conferenceDataVersion=1'
				: 'https://graph.microsoft.com/v1.0/me/events/' . rawurlencode( $row->remote_id );
			$res = self::api( $conn, 'PATCH', $url, $body );
		} else {
			$url = 'google' === $conn->provider
				? 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $conn->calendar_id ) . '/events?conferenceDataVersion=1'
				: 'https://graph.microsoft.com/v1.0/me/calendars/' . rawurlencode( $conn->calendar_id ) . '/events';
			$res = self::api( $conn, 'POST', $url, $body );
			if ( ! is_wp_error( $res ) && ! empty( $res['id'] ) ) {
				$data = array(
					'connection_id' => (int) $conn->id,
					'object_key'    => $key,
					'remote_id'     => (string) $res['id'],
					'calendar_id'   => $conn->calendar_id,
					'hash'          => md5( wp_json_encode( $item ) . '|' . $conn->calendar_id ),
					'start_at'      => $item['start'],
					'updated_at'    => current_time( 'mysql' ),
				);
				if ( $row ) {
					self::remote_delete( $conn, $row->remote_id, $row->calendar_id );
					$wpdb->update( $t, $data, array( 'id' => $row->id ) );
				} else {
					$wpdb->insert( $t, $data );
				}
			}
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$link = '';
		if ( 'google' === $conn->provider ) {
			$link = isset( $res['hangoutLink'] ) ? (string) $res['hangoutLink'] : '';
			if ( ! $link && ! empty( $res['conferenceData']['entryPoints'] ) ) {
				foreach ( $res['conferenceData']['entryPoints'] as $ep ) {
					if ( isset( $ep['entryPointType'], $ep['uri'] ) && 'video' === $ep['entryPointType'] ) {
						$link = (string) $ep['uri'];
					}
				}
			}
		} else {
			$link = isset( $res['onlineMeeting']['joinUrl'] ) ? (string) $res['onlineMeeting']['joinUrl'] : '';
			if ( ! $link && ! empty( $res['id'] ) ) {
				$again = self::api( $conn, 'GET', 'https://graph.microsoft.com/v1.0/me/events/' . rawurlencode( $res['id'] ) . '?$select=onlineMeeting' );
				$link  = ! is_wp_error( $again ) && isset( $again['onlineMeeting']['joinUrl'] ) ? (string) $again['onlineMeeting']['joinUrl'] : '';
			}
		}
		if ( ! $link ) {
			return new WP_Error(
				'osb_meet_nolink',
				'google' === $conn->provider
					? __( 'Google didn’t return a Meet link. Check that Google Meet is allowed on this account.', 'osam-bookings' )
					: __( 'Microsoft didn’t return a Teams link. Teams meetings need a work or school (Microsoft 365) account.', 'osam-bookings' )
			);
		}
		return $link;
	}

	/**
	 * Provider of a staff member's active, pushing connection.
	 *
	 * @param int $staff_id Staff.
	 * @return string google|microsoft|''
	 */
	public static function push_provider( $staff_id ) {
		$conn = self::connection_for( $staff_id );
		return $conn && 'active' === $conn->status && $conn->push && $conn->calendar_id ? (string) $conn->provider : '';
	}

	/* ---------------------------------------------------------------------
	 * Busy times
	 * ------------------------------------------------------------------ */

	/**
	 * Calendars whose busy times block bookings.
	 *
	 * @param object $conn Connection.
	 * @return string[]
	 */
	public static function busy_calendars( $conn ) {
		$ids = json_decode( (string) $conn->busy_calendars, true );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'strval', $ids ) ) ) : array();
	}

	/**
	 * Is the cached busy list old?
	 *
	 * @param object $conn Connection.
	 * @return bool
	 */
	private static function busy_stale( $conn ) {
		$cache = get_transient( 'osb_cal_busy_' . $conn->id );
		return ! is_array( $cache ) || ( time() - (int) $cache['at'] ) > self::BUSY_TTL;
	}

	/**
	 * Fetch busy times from the provider and cache them.
	 *
	 * @param object $conn Connection.
	 * @return array|WP_Error [ [ start, end ] ] local datetimes.
	 */
	public static function refresh_busy( $conn ) {
		global $wpdb;
		$now   = current_time( 'mysql' );
		$days  = min( self::WINDOW_MAX, max( 7, (int) OSB_Settings::get( 'max_advance_days' ) ) + 1 );
		$from  = self::utc( $now );
		$to    = self::utc( gmdate( 'Y-m-d H:i:s', strtotime( $now ) + $days * DAY_IN_SECONDS ) );
		$t     = OSB_Install::table( 'calendar_events' );
		$ours  = array_flip( $wpdb->get_col( $wpdb->prepare( "SELECT remote_id FROM $t WHERE connection_id = %d", $conn->id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$busy  = array();
		$error = null;

		foreach ( self::busy_calendars( $conn ) as $cal ) {
			if ( 'google' === $conn->provider ) {
				$url = add_query_arg(
					array(
						'timeMin'      => rawurlencode( $from->format( 'Y-m-d\TH:i:s\Z' ) ),
						'timeMax'      => rawurlencode( $to->format( 'Y-m-d\TH:i:s\Z' ) ),
						'singleEvents' => 'true',
						'maxResults'   => 2500,
						'fields'       => rawurlencode( 'items(id,status,transparency,start,end),nextPageToken' ),
					),
					'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $cal ) . '/events'
				);
				$pages = 0;
				while ( $url && $pages++ < 5 ) {
					$res = self::api( $conn, 'GET', $url );
					if ( is_wp_error( $res ) ) {
						$error = $res;
						break;
					}
					foreach ( isset( $res['items'] ) ? $res['items'] : array() as $e ) {
						if ( isset( $ours[ $e['id'] ] ) || ( isset( $e['status'] ) && 'cancelled' === $e['status'] ) || ( isset( $e['transparency'] ) && 'transparent' === $e['transparency'] ) ) {
							continue;
						}
						if ( isset( $e['start']['dateTime'] ) ) {
							$busy[] = array( self::to_local( $e['start']['dateTime'] ), self::to_local( $e['end']['dateTime'] ) );
						} elseif ( isset( $e['start']['date'] ) ) {
							$busy[] = array( $e['start']['date'] . ' 00:00:00', $e['end']['date'] . ' 00:00:00' ); // All-day, marked busy.
						}
					}
					$url = ! empty( $res['nextPageToken'] ) ? add_query_arg( 'pageToken', rawurlencode( $res['nextPageToken'] ), $url ) : '';
				}
			} else {
				$url   = 'https://graph.microsoft.com/v1.0/me/calendars/' . rawurlencode( $cal ) . '/calendarView?startDateTime=' . rawurlencode( $from->format( 'Y-m-d\TH:i:s\Z' ) ) . '&endDateTime=' . rawurlencode( $to->format( 'Y-m-d\TH:i:s\Z' ) ) . '&$select=id,start,end,showAs,isCancelled,isAllDay&$top=500';
				$pages = 0;
				while ( $url && $pages++ < 10 ) {
					$res = self::api( $conn, 'GET', $url, null, array( 'Prefer' => 'outlook.timezone="UTC"' ) );
					if ( is_wp_error( $res ) ) {
						$error = $res;
						break;
					}
					foreach ( isset( $res['value'] ) ? $res['value'] : array() as $e ) {
						if ( isset( $ours[ $e['id'] ] ) || ! empty( $e['isCancelled'] ) || in_array( isset( $e['showAs'] ) ? $e['showAs'] : 'busy', array( 'free', 'workingElsewhere' ), true ) ) {
							continue;
						}
						if ( ! empty( $e['isAllDay'] ) ) {
							// All-day entries are dates in the calendar's own zone; treat them as local days.
							$busy[] = array( substr( $e['start']['dateTime'], 0, 10 ) . ' 00:00:00', substr( $e['end']['dateTime'], 0, 10 ) . ' 00:00:00' );
						} else {
							$busy[] = array( self::to_local( $e['start']['dateTime'] ), self::to_local( $e['end']['dateTime'] ) );
						}
					}
					$url = isset( $res['@odata.nextLink'] ) ? $res['@odata.nextLink'] : '';
				}
			}
		}

		if ( $error && ! $busy ) {
			self::fail( $conn, $error );
			// Keep the last good list rather than suddenly opening up every slot; retry soon.
			$old = get_transient( 'osb_cal_busy_' . $conn->id );
			if ( is_array( $old ) ) {
				$old['at'] = time() - self::BUSY_TTL + 120;
				set_transient( 'osb_cal_busy_' . $conn->id, $old, DAY_IN_SECONDS );
			}
			return $error;
		}
		$busy = array_values( array_filter( $busy, static function ( $b ) { return $b[0] && $b[1] && $b[1] > $b[0]; } ) );
		set_transient(
			'osb_cal_busy_' . $conn->id,
			array(
				'at'   => time(),
				'busy' => $busy,
			),
			DAY_IN_SECONDS
		);
		OSB_Availability::bust_cache();
		return $busy;
	}

	/**
	 * Busy blocks for a staff member overlapping a window (from cache; fetched once if missing).
	 *
	 * @param int    $staff_id Staff.
	 * @param string $from     Local datetime.
	 * @param string $to       Local datetime.
	 * @return array [ [ start, end ] ]
	 */
	public static function busy_between( $staff_id, $from, $to ) {
		if ( ! OSB_Settings::feature( 'calendar_sync' ) || ! self::has_connections() ) {
			return array();
		}
		$conn = self::connection_for( $staff_id );
		if ( ! $conn || ! $conn->pull || 'active' !== $conn->status ) {
			return array();
		}
		$cache = get_transient( 'osb_cal_busy_' . $conn->id );
		if ( ! is_array( $cache ) ) {
			// First look since connecting: fetch now (once — failures are cached briefly by refresh_busy).
			set_transient( 'osb_cal_busy_' . $conn->id, array( 'at' => time() - self::BUSY_TTL + 120, 'busy' => array() ), DAY_IN_SECONDS );
			$fresh = self::refresh_busy( $conn );
			$cache = array( 'busy' => is_wp_error( $fresh ) ? array() : $fresh );
		}
		$out = array();
		foreach ( $cache['busy'] as $b ) {
			if ( $b[0] < $to && $b[1] > $from ) {
				$out[] = $b;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * OAuth callback
	 * ------------------------------------------------------------------ */

	/**
	 * REST route for the OAuth redirect.
	 */
	public static function routes() {
		register_rest_route(
			OSB_Rest::NS,
			'/calendar/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'callback' ),
				'permission_callback' => '__return_true', // Authorised by the one-time state below.
			)
		);
	}

	/**
	 * Start connecting: store a one-time state and return the provider URL.
	 *
	 * @param string $provider Provider.
	 * @param int    $staff_id Staff.
	 * @param string $return   Admin URL to come back to.
	 * @return string
	 */
	public static function begin( $provider, $staff_id, $return ) {
		$state = wp_generate_password( 40, false, false );
		set_transient(
			'osb_cal_state_' . $state,
			array(
				'provider' => $provider,
				'staff_id' => (int) $staff_id,
				'user'     => get_current_user_id(),
				'return'   => $return,
				'via'      => self::mode( $provider ),
			),
			15 * MINUTE_IN_SECONDS
		);
		if ( 'connector' === self::mode( $provider ) ) {
			return add_query_arg(
				array(
					'provider' => $provider,
					'site'     => rawurlencode( home_url() ),
					'return'   => rawurlencode( self::redirect_uri() ),
					'state'    => $state,
				),
				self::connector_endpoint( 'start' )
			);
		}
		return self::auth_url( $provider, $state );
	}

	/**
	 * GET /calendar/callback.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function callback( WP_REST_Request $request ) {
		$state = preg_replace( '/[^A-Za-z0-9]/', '', (string) $request->get_param( 'state' ) );
		$data  = $state ? get_transient( 'osb_cal_state_' . $state ) : false;
		if ( ! $data ) {
			wp_die( esc_html__( 'This calendar link has expired. Please go back to the bookings admin and click Connect again.', 'osam-bookings' ), 400 );
		}
		delete_transient( 'osb_cal_state_' . $state );
		$back = wp_validate_redirect( $data['return'], admin_url( 'admin.php?page=osb-staff' ) );

		$error = sanitize_key( (string) $request->get_param( 'error' ) );
		if ( $error ) {
			if ( 0 === strpos( $error, 'connector_' ) ) {
				/* translators: %s error code */
				set_transient( 'osb_admin_error_' . (int) $data['user'], sprintf( __( 'Calendar connection failed at the OSAM connector (%s). Please try again, or contact OSAM if it keeps happening.', 'osam-bookings' ), substr( $error, 10 ) ), 120 );
				wp_safe_redirect( $back );
				exit;
			}
			wp_safe_redirect( add_query_arg( 'osb_msg', 'cal_cancelled', $back ) );
			exit;
		}
		if ( isset( $data['via'] ) && 'connector' === $data['via'] ) {
			$handoff = preg_replace( '/[^A-Za-z0-9]/', '', (string) $request->get_param( 'handoff' ) );
			$tok     = $handoff ? self::connector_post( 'exchange', array( 'handoff' => $handoff, 'return' => self::redirect_uri() ) ) : new WP_Error( 'osb_connector', __( 'The connector did not send the sign-in back.', 'osam-bookings' ) );
			$result  = is_wp_error( $tok ) ? $tok : self::connect_tokens( $data['provider'], (int) $data['staff_id'], $tok, 'connector' );
		} else {
			$result = self::connect( $data['provider'], (int) $data['staff_id'], sanitize_text_field( (string) $request->get_param( 'code' ) ) );
		}
		if ( is_wp_error( $result ) ) {
			set_transient( 'osb_admin_error_' . (int) $data['user'], __( 'Calendar connection failed:', 'osam-bookings' ) . ' ' . $result->get_error_message(), 120 );
			wp_safe_redirect( $back );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'osb_msg', 'cal_connected', $back ) );
		exit;
	}
}
