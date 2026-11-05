<?php
/**
 * Private, signed updates from the OSAM update server.
 *
 * Asks the update server (updates.osamweb.com by default) for the latest release and feeds it into
 * WordPress's normal plugin update system, so it shows under Dashboard → Updates, can be updated
 * with one click, and works with WordPress auto-updates.
 *
 * Every release zip is signed in GitHub Actions with OSAM's private Ed25519 key. This plugin holds
 * the matching public key and refuses to install any zip whose signature doesn't check out, so a
 * hacked update server can't push code to client sites. Rollbacks (installing an older version)
 * need a signed rollback instruction too, and only apply to sites on a version it withdraws.
 *
 * Turn off with define( 'OSB_UPDATES', false ); in wp-config.php.
 * Point elsewhere with define( 'OSB_UPDATE_SERVER', 'https://…' ); (for testing).
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Updater.
 */
class OSB_Updater {

	const SLUG  = 'osam-bookings';
	const CACHE = 'osb_update_info';

	/**
	 * Default update server.
	 */
	const DEFAULT_SERVER = 'https://updates.osamweb.com';

	/**
	 * OSAM release signing public key (base64 Ed25519). Safe to publish.
	 * Must match the private key in GitHub → Settings → Environments → release-signing.
	 */
	const PUBLIC_KEY = '__OSAM_PUBLIC_KEY__';

	/**
	 * Versions withdrawn by signed rollbacks (never installed again on this site).
	 */
	const BLOCKED = 'osb_update_blocked';

	/**
	 * One-off event that runs a forced update straight away.
	 */
	const FORCE_HOOK = 'osb_forced_update';

	/**
	 * Result of the last forced install attempt.
	 */
	const FORCE_STATUS = 'osb_forced_status';

	/**
	 * Info fetched during this request.
	 *
	 * @var array|null
	 */
	private static $mem = null;

	/**
	 * Boot.
	 */
	public static function init() {
		if ( defined( 'OSB_UPDATES' ) && ! OSB_UPDATES ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		// Also on read: WordPress skips saving its update list when wordpress.org can't be reached.
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject_read' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto' ), 20, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 5, 4 );
		add_action( 'admin_post_osb_check_updates', array( __CLASS__, 'check_now' ) );
		add_action( 'osb_cron', array( __CLASS__, 'enforce' ), 99 );
		add_action( self::FORCE_HOOK, array( __CLASS__, 'enforce' ) );
	}

	/**
	 * Plugin basename (osam-bookings/osam-bookings.php).
	 *
	 * @return string
	 */
	public static function basename() {
		return plugin_basename( OSB_FILE );
	}

	/**
	 * Update server address.
	 *
	 * @return string
	 */
	public static function server() {
		$url = defined( 'OSB_UPDATE_SERVER' ) && OSB_UPDATE_SERVER ? (string) OSB_UPDATE_SERVER : self::DEFAULT_SERVER;
		return untrailingslashit( esc_url_raw( (string) apply_filters( 'osb_update_server', $url ) ) );
	}

	/**
	 * Connector address (connect.osamweb.com) — calendar sign-in and the subscription check.
	 *
	 * @return string
	 */
	public static function connector() {
		$url = trim( (string) OSB_Settings::get( 'cal_connector_url' ) );
		if ( '' === $url ) {
			$url = defined( 'OSB_CONNECTOR_URL' ) ? (string) OSB_CONNECTOR_URL : OSB_Calendar_Sync::DEFAULT_CONNECTOR;
		}
		return untrailingslashit( esc_url_raw( $url ) );
	}

	/* ---------------------------------------------------------------------
	 * Signatures
	 * ------------------------------------------------------------------ */

	/**
	 * The public key bytes, or '' when the plugin wasn't built with one.
	 *
	 * @return string
	 */
	private static function public_key() {
		$raw = base64_decode( self::PUBLIC_KEY, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return ( is_string( $raw ) && 32 === strlen( $raw ) ) ? $raw : '';
	}

	/**
	 * Check an Ed25519 signature.
	 *
	 * @param string $message   Signed text.
	 * @param string $signature Base64 signature.
	 * @return bool
	 */
	public static function verify( $message, $signature ) {
		$key = self::public_key();
		$sig = base64_decode( (string) $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( '' === $key || ! is_string( $sig ) || 64 !== strlen( $sig ) ) {
			return false;
		}
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) && file_exists( ABSPATH . WPINC . '/sodium_compat/autoload.php' ) ) {
			require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
		}
		try {
			return function_exists( 'sodium_crypto_sign_verify_detached' ) && sodium_crypto_sign_verify_detached( $sig, (string) $message, $key );
		} catch ( Exception $e ) {
			return false;
		} catch ( Error $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.errorFound
			return false;
		}
	}

	/**
	 * Is this release (version + zip hash) signed by OSAM?
	 *
	 * @param string $version   Version.
	 * @param string $sha256    Hex SHA-256 of the zip.
	 * @param string $signature Base64 signature.
	 * @return bool
	 */
	public static function release_signed( $version, $sha256, $signature ) {
		return (bool) preg_match( '/^[0-9a-f]{64}$/', (string) $sha256 ) && self::verify( "OSAMSIG1\n" . self::SLUG . "\n" . $version . "\n" . $sha256, $signature );
	}

	/**
	 * Read a signed rollback instruction that applies to this site and its installed version.
	 *
	 * @param mixed  $instruction [ payload => base64 JSON, signature => base64 ].
	 * @param string $target      Version the server says to move to.
	 * @return array|null The payload, or null if it isn't valid for this site right now.
	 */
	public static function rollback_payload( $instruction, $target ) {
		if ( ! is_array( $instruction ) || empty( $instruction['payload'] ) || empty( $instruction['signature'] ) ) {
			return null;
		}
		$b64 = (string) $instruction['payload'];
		if ( ! self::verify( "OSAMROLL1\n" . $b64, (string) $instruction['signature'] ) ) {
			return null;
		}
		$p = json_decode( (string) base64_decode( $b64, true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_array( $p ) || 'rollback' !== ( $p['type'] ?? '' ) || self::SLUG !== ( $p['slug'] ?? '' ) ) {
			return null;
		}
		$host  = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) );
		$sites = array_map( 'strval', (array) ( $p['sites'] ?? array() ) );
		$block = array_map( 'strval', (array) ( $p['block_versions'] ?? array() ) );
		if ( ! in_array( '*', $sites, true ) && ! in_array( $host, $sites, true ) ) {
			return null;
		}
		if ( (int) ( $p['expires_at'] ?? 0 ) < time() ) {
			return null;
		}
		// Only a site running a withdrawn version is moved, and only to the signed target.
		if ( ! in_array( OSB_VERSION, $block, true ) || (string) ( $p['target_version'] ?? '' ) !== (string) $target ) {
			return null;
		}
		$p['block_versions'] = $block;
		return $p;
	}

	/**
	 * Versions this site must never install (from signed rollbacks it has seen).
	 *
	 * @return string[]
	 */
	public static function blocked() {
		$b = get_option( self::BLOCKED, array() );
		return is_array( $b ) ? array_values( array_map( 'strval', $b ) ) : array();
	}

	/**
	 * Check what the server sent before trusting any of it.
	 *
	 * @param array $out  Parsed info.
	 * @param array $body Raw response.
	 * @return array Info, or [ 'error' => … ].
	 */
	private static function vet( array $out, array $body ) {
		if ( '' === self::public_key() ) {
			return array( 'error' => __( 'This copy of OSAM Bookings has no update signing key, so updates are switched off. Install a release built by OSAM.', 'osam-bookings' ) );
		}
		$out['sha256']    = strtolower( (string) ( $body['sha256'] ?? '' ) );
		$out['signature'] = (string) ( $body['signature'] ?? '' );
		if ( ! self::release_signed( $out['version'], $out['sha256'], $out['signature'] ) ) {
			/* translators: %s version */
			return array( 'error' => sprintf( __( 'The update server offered version %s without a valid OSAM signature, so it was ignored. Please tell OSAM.', 'osam-bookings' ), $out['version'] ) );
		}
		$p = self::rollback_payload( $body['instruction'] ?? null, $out['version'] );
		if ( $p ) {
			// Remember withdrawn versions for good, so a hacked server can't offer them again.
			$keep = array_values( array_unique( array_merge( self::blocked(), $p['block_versions'] ) ) );
			update_option( self::BLOCKED, array_slice( $keep, -200 ), false );
			$out['instruction'] = $body['instruction'];
			$out['blocked']     = $p['block_versions'];
			$out['rollback']    = version_compare( $out['version'], OSB_VERSION, '<' );
			$out['forced']      = true;
		} else {
			$out['instruction'] = null;
			$out['blocked']     = array(); // Unsigned lists are ignored.
			$out['rollback']    = false;
		}
		if ( in_array( $out['version'], self::blocked(), true ) ) {
			/* translators: %s version */
			return array( 'error' => sprintf( __( 'The update server offered version %s, which OSAM has withdrawn, so it was ignored.', 'osam-bookings' ), $out['version'] ) );
		}
		if ( version_compare( $out['version'], OSB_VERSION, '<' ) && ! $p ) {
			// An older version without a signed rollback: treat as "nothing to do".
			$out['forced'] = false;
		}
		return $out;
	}

	/**
	 * Latest release info from the connector (cached).
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array Either release info or [ 'error' => message ]; always has 'checked'.
	 */
	public static function info( $fresh = false ) {
		if ( ! $fresh && null !== self::$mem ) {
			return self::$mem;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress's own "Check again" link.
		if ( ! $fresh && ! isset( $_GET['force-check'] ) ) {
			$c = get_site_transient( self::CACHE );
			if ( is_array( $c ) ) {
				return $c;
			}
		}
		global $wp_version;
		$args = array(
			'slug'    => self::SLUG,
			'site'    => home_url( '/' ),
			'version' => OSB_VERSION,
			'wp'      => $wp_version,
			'php'     => PHP_VERSION,
			'caps'    => 'force,rollback,signed',
		);
		if ( defined( 'OSB_UPDATE_KEY' ) && OSB_UPDATE_KEY ) {
			$args['key'] = (string) OSB_UPDATE_KEY;
		}
		$url = add_query_arg( array_map( 'rawurlencode', $args ), self::server() . '/v1/update' );
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		$out  = array();
		$body = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( is_wp_error( $res ) ) {
			/* translators: %s error */
			$out = array( 'error' => sprintf( __( 'Couldn’t reach the OSAM update server: %s', 'osam-bookings' ), $res->get_error_message() ) );
		} elseif ( ! is_array( $body ) ) {
			/* translators: %d HTTP code */
			$out = array( 'error' => sprintf( __( 'The OSAM update server gave an unexpected answer (HTTP %d).', 'osam-bookings' ), wp_remote_retrieve_response_code( $res ) ) );
		} elseif ( empty( $body['ok'] ) || empty( $body['version'] ) ) {
			$out = array(
				'error' => ! empty( $body['message'] ) ? sanitize_text_field( $body['message'] ) : __( 'No update information available.', 'osam-bookings' ),
				'code'  => isset( $body['code'] ) ? sanitize_key( $body['code'] ) : '',
			);
		} else {
			$out = array(
				'version'      => sanitize_text_field( $body['version'] ),
				'package'      => esc_url_raw( (string) ( $body['package'] ?? '' ) ),
				'requires'     => sanitize_text_field( (string) ( $body['requires'] ?? '' ) ),
				'requires_php' => sanitize_text_field( (string) ( $body['requires_php'] ?? '' ) ),
				'tested'       => sanitize_text_field( (string) ( $body['tested'] ?? '' ) ),
				'author'       => sanitize_text_field( (string) ( $body['author'] ?? '' ) ),
				'last_updated' => sanitize_text_field( (string) ( $body['last_updated'] ?? '' ) ),
				'size'         => (int) ( $body['size'] ?? 0 ),
				'sections'     => array_map( 'wp_kses_post', is_array( $body['sections'] ?? null ) ? $body['sections'] : array() ),
				'forced'       => ! empty( $body['forced'] ),
				'rollback'     => ! empty( $body['rollback'] ),
			);
			$out = self::vet( $out, $body );
		}
		$out['checked'] = time();
		// Checked hourly so forced updates and rollbacks reach every site within about an hour.
		set_site_transient( self::CACHE, $out, HOUR_IN_SECONDS );
		self::$mem = $out;
		return $out;
	}

	/**
	 * The version OSAM requires this site to move to now (forced update, rollback, or replacing a
	 * withdrawn version), or '' when nothing is required.
	 *
	 * @param array|null $i Info (cached info when null).
	 * @return string
	 */
	public static function required( $i = null ) {
		$i = null === $i ? get_site_transient( self::CACHE ) : $i;
		if ( ! is_array( $i ) || isset( $i['error'] ) || empty( $i['version'] ) || empty( $i['package'] ) || OSB_VERSION === $i['version'] ) {
			return '';
		}
		$blocked = in_array( OSB_VERSION, (array) ( $i['blocked'] ?? array() ), true ) && ! empty( $i['instruction'] );
		$newer   = version_compare( $i['version'], OSB_VERSION, '>' );
		if ( $blocked || ( ! empty( $i['forced'] ) && ( $newer || ! empty( $i['rollback'] ) ) ) ) {
			return $i['version'];
		}
		return '';
	}

	/**
	 * Is there something to install (a normal update or a required version)?
	 *
	 * @param array $i Info.
	 * @return bool
	 */
	private static function pending( array $i ) {
		if ( isset( $i['error'] ) || empty( $i['package'] ) ) {
			return false;
		}
		return version_compare( $i['version'], OSB_VERSION, '>' ) || '' !== self::required( $i );
	}

	/**
	 * The update row WordPress expects.
	 *
	 * @param array  $i       Info.
	 * @param string $version Version to report.
	 * @return object
	 */
	private static function item( array $i, $version ) {
		return (object) array(
			'id'            => self::server() . '/' . self::SLUG,
			'slug'          => self::SLUG,
			'plugin'        => self::basename(),
			'new_version'   => $version,
			'url'           => 'https://osamweb.com/',
			'package'       => $version === OSB_VERSION ? '' : $i['package'],
			'tested'        => $i['tested'],
			'requires'      => $i['requires'],
			'requires_php'  => $i['requires_php'],
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new stdClass(),
			'osb_sha256'    => $i['sha256'] ?? '',
			'osb_signature' => $i['signature'] ?? '',
			'osb_rollback'  => $i['instruction'] ?? null,
		);
	}

	/**
	 * Download our update and check its signature before WordPress unpacks it.
	 *
	 * @param bool|string|WP_Error $reply      Earlier filter result.
	 * @param string               $package    Package URL.
	 * @param WP_Upgrader          $upgrader   Upgrader.
	 * @param array                $hook_extra Details (which plugin).
	 * @return bool|string|WP_Error Local file path, an error, or $reply when it isn't ours.
	 */
	public static function pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		$base = self::basename();
		$info = get_site_transient( self::CACHE );
		$ours = ( isset( $hook_extra['plugin'] ) && $base === $hook_extra['plugin'] )
			|| ( is_array( $info ) && ! empty( $info['package'] ) && $package === $info['package'] );
		if ( false !== $reply || ! $ours || ! preg_match( '#^https?://#i', (string) $package ) ) {
			return $reply;
		}
		$fail = static function ( $msg ) {
			return new WP_Error( 'osb_unsigned', $msg );
		};

		// What WordPress was told to install (falls back to the cached info).
		$tr  = get_site_transient( 'update_plugins' );
		$row = is_object( $tr ) && isset( $tr->response[ $base ] ) ? $tr->response[ $base ] : null;
		$ver = $row ? (string) $row->new_version : (string) ( $info['version'] ?? '' );
		$sha = $row && ! empty( $row->osb_sha256 ) ? (string) $row->osb_sha256 : (string) ( $info['sha256'] ?? '' );
		$sig = $row && ! empty( $row->osb_signature ) ? (string) $row->osb_signature : (string) ( $info['signature'] ?? '' );
		$rb  = $row && isset( $row->osb_rollback ) ? $row->osb_rollback : ( $info['instruction'] ?? null );

		if ( ! self::release_signed( $ver, $sha, $sig ) ) {
			return $fail( __( 'Update stopped: this OSAM Bookings package isn’t signed by OSAM. Check for updates again; if it keeps happening, tell OSAM.', 'osam-bookings' ) );
		}
		if ( in_array( $ver, self::blocked(), true ) ) {
			return $fail( __( 'Update stopped: OSAM has withdrawn that version.', 'osam-bookings' ) );
		}
		if ( version_compare( $ver, OSB_VERSION, '<' ) && ! self::rollback_payload( is_object( $rb ) ? (array) $rb : $rb, $ver ) ) {
			return $fail( __( 'Update stopped: moving to an older version needs a signed rollback from OSAM.', 'osam-bookings' ) );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( $upgrader && isset( $upgrader->skin ) ) {
			$upgrader->skin->feedback( 'downloading_package', $package );
		}
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$got = hash_file( 'sha256', $file );
		if ( ! hash_equals( $sha, (string) $got ) ) {
			wp_delete_file( $file );
			return $fail( __( 'Update stopped: the downloaded OSAM Bookings zip doesn’t match OSAM’s signed release, so it wasn’t installed.', 'osam-bookings' ) );
		}
		return $file;
	}

	/**
	 * Add our release to WordPress's update list.
	 *
	 * @param object $transient update_plugins transient.
	 * @return object
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		$i    = self::info();
		$base = self::basename();
		if ( isset( $i['error'] ) ) {
			return $transient;
		}
		if ( self::pending( $i ) ) {
			$transient->response[ $base ] = self::item( $i, $i['version'] );
			unset( $transient->no_update[ $base ] );
		} else {
			// Listed as up to date so the Plugins screen offers the auto-updates toggle.
			$transient->no_update[ $base ] = self::item( $i, OSB_VERSION );
			unset( $transient->response[ $base ] );
		}
		return $transient;
	}

	/**
	 * Make sure our entry is there whenever the update list is read (cached info only, no request).
	 *
	 * @param mixed $transient update_plugins transient.
	 * @return mixed
	 */
	public static function inject_read( $transient ) {
		$base = self::basename();
		if ( ! is_array( get_site_transient( self::CACHE ) ) ) {
			return $transient; // Not fetched yet; the next scheduled check will fetch it.
		}
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$transient->response  = isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
		$transient->no_update = isset( $transient->no_update ) && is_array( $transient->no_update ) ? $transient->no_update : array();
		$checked              = isset( $transient->checked ) && is_array( $transient->checked ) ? $transient->checked : array();
		$checked[ $base ]     = OSB_VERSION; // Stale after a forced install or rollback.
		$transient->checked   = $checked;
		return self::inject( $transient );
	}

	/**
	 * "View details" popup.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Args.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$i = self::info();
		if ( isset( $i['error'] ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'OSAM Bookings',
			'slug'          => self::SLUG,
			'version'       => $i['version'],
			'author'        => '<a href="https://osamweb.com/">OSAM</a>',
			'homepage'      => 'https://osamweb.com/',
			'requires'      => $i['requires'],
			'requires_php'  => $i['requires_php'],
			'tested'        => $i['tested'],
			'last_updated'  => $i['last_updated'],
			'download_link' => $i['package'],
			'sections'      => $i['sections'] ? $i['sections'] : array( 'changelog' => '' ),
			'banners'       => array(),
		);
	}

	/**
	 * Force auto-updates on when the setting says so.
	 *
	 * @param bool|null $update Current decision.
	 * @param object    $item   Plugin.
	 * @return bool|null
	 */
	public static function auto( $update, $item ) {
		if ( isset( $item->plugin ) && self::basename() === $item->plugin && ( OSB_Settings::get( 'auto_update', 1 ) || '' !== self::required() ) ) {
			return true;
		}
		return $update;
	}

	/**
	 * Install a version OSAM requires (forced update or rollback) without waiting for anyone.
	 * Runs from the 5-minute job and a one-off event after "Check for updates now".
	 */
	public static function enforce() {
		if ( ! wp_doing_cron() || ( defined( 'OSB_UPDATES' ) && ! OSB_UPDATES ) ) {
			return;
		}
		$info = self::info();
		$want = self::required( $info );
		if ( '' === $want || get_transient( 'osb_forced_lock' ) || get_transient( 'osb_forced_wait' ) ) {
			return;
		}
		set_transient( 'osb_forced_lock', 1, 10 * MINUTE_IN_SECONDS );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$base   = self::basename();
		$from   = OSB_VERSION;
		$active = is_plugin_active( $base );
		$status = array(
			'time' => time(),
			'from' => $from,
			'to'   => $want,
			'ok'   => false,
			'msg'  => '',
		);
		if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
			$status['msg'] = __( 'WordPress can’t write to the plugins folder without FTP details, so the required version can’t be installed automatically. Update it under Dashboard → Updates.', 'osam-bookings' );
		} else {
			// Make sure WordPress's update list holds the required version for the upgrader to read.
			$tr = get_site_transient( 'update_plugins' );
			if ( ! is_object( $tr ) || empty( $tr->response[ $base ] ) || $tr->response[ $base ]->new_version !== $want ) {
				$tr = is_object( $tr ) ? $tr : new stdClass();
				$tr->response            = isset( $tr->response ) && is_array( $tr->response ) ? $tr->response : array();
				$tr->checked             = ( isset( $tr->checked ) && is_array( $tr->checked ) ? $tr->checked : array() ) + array( $base => $from );
				$tr->response[ $base ]   = self::item( $info, $want );
				$tr->last_checked        = time();
				set_site_transient( 'update_plugins', $tr );
			}
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->upgrade( $base, array( 'clear_update_cache' => false ) );
			if ( true === $result ) {
				if ( $active && ! is_plugin_active( $base ) ) {
					activate_plugin( $base, '', is_multisite() && is_plugin_active_for_network( $base ), true );
				}
				$status['ok'] = true;
				delete_site_transient( self::CACHE );
			} else {
				$msgs          = method_exists( $upgrader->skin, 'get_upgrade_messages' ) ? (array) $upgrader->skin->get_upgrade_messages() : array();
				$status['msg'] = is_wp_error( $result ) ? $result->get_error_message() : wp_strip_all_tags( (string) end( $msgs ) );
			}
		}
		update_option( self::FORCE_STATUS, $status, false );
		if ( ! $status['ok'] ) {
			set_transient( 'osb_forced_wait', 1, 30 * MINUTE_IN_SECONDS ); // Try again in half an hour.
		}
		delete_transient( 'osb_forced_lock' );
	}

	/**
	 * Clear the cache after an update.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $extra    Details.
	 */
	public static function flush( $upgrader, $extra ) {
		if ( isset( $extra['type'] ) && 'plugin' === $extra['type'] ) {
			delete_site_transient( self::CACHE );
		}
	}

	/**
	 * "Check for updates now".
	 */
	public static function check_now() {
		OSB_Admin::verify( 'osb_check_updates', 'update_plugins' );
		$i = self::info( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		if ( '' !== self::required( $i ) ) {
			delete_transient( 'osb_forced_wait' );
			if ( ! wp_next_scheduled( self::FORCE_HOOK ) ) {
				wp_schedule_single_event( time(), self::FORCE_HOOK );
			}
			spawn_cron();
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-settings&tab=general#osb-updates' ), 'update_required' );
		}
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-settings&tab=general#osb-updates' ), isset( $i['error'] ) ? 'update_failed' : ( version_compare( $i['version'], OSB_VERSION, '>' ) ? 'update_found' : 'update_none' ) );
	}

	/**
	 * Status for the settings card.
	 *
	 * @return array [ tone, message ]
	 */
	public static function status() {
		if ( defined( 'OSB_UPDATES' ) && ! OSB_UPDATES ) {
			return array( 'muted', __( 'Updates from OSAM are turned off on this site (OSB_UPDATES in wp-config.php).', 'osam-bookings' ) );
		}
		$i = get_site_transient( self::CACHE );
		if ( ! is_array( $i ) ) {
			return array( 'muted', __( 'Not checked yet.', 'osam-bookings' ) );
		}
		/* translators: %s time ago */
		$when = sprintf( __( 'checked %s ago', 'osam-bookings' ), human_time_diff( (int) $i['checked'] ) );
		if ( isset( $i['error'] ) ) {
			return array( 'error', $i['error'] . ' (' . $when . ')' );
		}
		$need = self::required( $i );
		if ( '' !== $need ) {
			$last = get_option( self::FORCE_STATUS );
			$msg  = version_compare( $need, OSB_VERSION, '<' )
				/* translators: %s version */
				? sprintf( __( 'OSAM has rolled this plugin back to version %s. It’s installed automatically within a few minutes.', 'osam-bookings' ), $need )
				/* translators: %s version */
				: sprintf( __( 'OSAM requires version %s on this site. It’s installed automatically within a few minutes.', 'osam-bookings' ), $need );
			if ( is_array( $last ) && empty( $last['ok'] ) && $last['to'] === $need && $last['msg'] ) {
				/* translators: %s error */
				$msg .= ' ' . sprintf( __( 'Last attempt failed: %s', 'osam-bookings' ), $last['msg'] );
			}
			return array( 'warn', $msg );
		}
		if ( ! empty( $i['forced'] ) && OSB_VERSION === $i['version'] ) {
			/* translators: 1 version 2 when */
			return array( 'ok', sprintf( __( 'You have version %1$s, which OSAM requires on this site (%2$s).', 'osam-bookings' ), OSB_VERSION, $when ) );
		}
		if ( version_compare( $i['version'], OSB_VERSION, '>' ) ) {
			/* translators: 1 version, 2 when */
			return array( 'warn', sprintf( __( 'Version %1$s is available (%2$s).', 'osam-bookings' ), $i['version'], $when ) );
		}
		/* translators: %s when */
		return array( 'ok', sprintf( __( 'You have the latest version (%s).', 'osam-bookings' ), $when ) );
	}
}
