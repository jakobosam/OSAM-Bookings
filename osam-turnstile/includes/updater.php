<?php
/**
 * Private, signed updates from the OSAM update server (updates.osamweb.com), the same way OSAM Bookings
 * gets them.
 *
 * - Dashboard → Updates shows new versions released on GitHub (checked hourly, or straight away with
 *   "Check again"). Automatic updates are on for this plugin unless OSAM_TS_AUTO_UPDATE is false.
 * - Every release zip is signed in GitHub Actions with OSAM's private Ed25519 key. This file holds the
 *   matching public key and refuses any zip whose signature doesn't check out, so a hacked update server
 *   can't push code to client sites.
 * - Forced versions (minimum_version on the update server) and signed rollbacks are installed within
 *   about an hour, automatic updates or not. A withdrawn version is never installed again.
 *
 * wp-config.php options:
 *   define( 'OSAM_TS_AUTO_UPDATE', false );  no automatic updates (forced ones and rollbacks still happen)
 *   define( 'OSAM_TS_UPDATES', false );      no updates from OSAM at all
 *   define( 'OSB_UPDATE_KEY', '…' );         if the update server has an update key (shared with OSAM Bookings)
 *   define( 'OSB_UPDATE_SERVER', 'https://…' ); another update server (testing; shared with OSAM Bookings)
 *
 * @package OSAM_Turnstile
 */

defined( 'ABSPATH' ) || exit;

final class OSAM_TS_Updater {

	const SLUG           = 'osam-turnstile';
	const DEFAULT_SERVER = 'https://updates.osamweb.com';
	const CACHE          = 'osam_ts_signed_update'; // not 1.2's osam_ts_update_info, which held a different shape
	const CRON           = 'osam_ts_enforce_update';
	const BLOCKED        = 'osam_ts_update_blocked';
	const STATUS         = 'osam_ts_forced_status';

	/**
	 * OSAM release signing public key (base64 Ed25519). Safe to publish. Filled in by the GitHub release
	 * workflow; a copy that still says __OSAM_PUBLIC_KEY__ accepts no updates.
	 */
	const PUBLIC_KEY = '__OSAM_PUBLIC_KEY__';

	/** @var string Plugin basename, e.g. osam-turnstile/osam-turnstile.php */
	private static $file = '';

	/** @var array|null Info fetched during this request. */
	private static $mem = null;

	/**
	 * Boot.
	 *
	 * @param string $main_file The plugin's main file (__FILE__).
	 */
	public static function init( $main_file ) {
		self::$file = plugin_basename( $main_file );
		register_deactivation_hook( $main_file, array( __CLASS__, 'unschedule' ) );
		if ( defined( 'OSAM_TS_UPDATES' ) && ! OSAM_TS_UPDATES ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		// Also on read: WordPress skips saving its update list when wordpress.org can't be reached.
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject_read' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto' ), 20, 2 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 5, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_install' ), 10, 2 );
		add_action( self::CRON, array( __CLASS__, 'enforce' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/** Update server address. */
	private static function server() {
		$url = defined( 'OSB_UPDATE_SERVER' ) && OSB_UPDATE_SERVER ? (string) OSB_UPDATE_SERVER : self::DEFAULT_SERVER;
		return untrailingslashit( esc_url_raw( $url ) );
	}

	/** Installed version, read from the file (so it's right straight after an update). */
	private static function installed() {
		$data = get_file_data( WP_PLUGIN_DIR . '/' . self::$file, array( 'Version' => 'Version' ) );
		return (string) $data['Version'];
	}

	/* ---------------------------------------------------------------------
	 * Signatures
	 * ------------------------------------------------------------------ */

	/** The public key bytes, or '' when the plugin wasn't built with one. */
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
	private static function verify( $message, $signature ) {
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

	/** Is this release (version + zip hash) signed by OSAM? */
	private static function release_signed( $version, $sha256, $signature ) {
		return (bool) preg_match( '/^[0-9a-f]{64}$/', (string) $sha256 ) && self::verify( "OSAMSIG1\n" . self::SLUG . "\n" . $version . "\n" . $sha256, $signature );
	}

	/**
	 * Read a signed rollback instruction that applies to this site and its installed version.
	 *
	 * @param mixed  $instruction [ payload => base64 JSON, signature => base64 ].
	 * @param string $target      Version the server says to move to.
	 * @return array|null The payload, or null if it isn't valid for this site right now.
	 */
	private static function rollback_payload( $instruction, $target ) {
		if ( ! is_array( $instruction ) || empty( $instruction['payload'] ) || empty( $instruction['signature'] ) ) {
			return null;
		}
		$b64 = (string) $instruction['payload'];
		if ( ! self::verify( "OSAMROLL1\n" . $b64, (string) $instruction['signature'] ) ) {
			return null;
		}
		$p = json_decode( (string) base64_decode( $b64, true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_array( $p ) || 'rollback' !== ( isset( $p['type'] ) ? $p['type'] : '' ) || self::SLUG !== ( isset( $p['slug'] ) ? $p['slug'] : '' ) ) {
			return null;
		}
		$host  = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) );
		$sites = array_map( 'strval', (array) ( isset( $p['sites'] ) ? $p['sites'] : array() ) );
		$block = array_map( 'strval', (array) ( isset( $p['block_versions'] ) ? $p['block_versions'] : array() ) );
		if ( ! in_array( '*', $sites, true ) && ! in_array( $host, $sites, true ) ) {
			return null;
		}
		if ( (int) ( isset( $p['expires_at'] ) ? $p['expires_at'] : 0 ) < time() ) {
			return null;
		}
		// Only a site running a withdrawn version is moved, and only to the signed target.
		if ( ! in_array( self::installed(), $block, true ) || (string) ( isset( $p['target_version'] ) ? $p['target_version'] : '' ) !== (string) $target ) {
			return null;
		}
		$p['block_versions'] = $block;
		return $p;
	}

	/** Versions this site must never install (from signed rollbacks it has seen). */
	private static function blocked() {
		$b = get_option( self::BLOCKED, array() );
		return is_array( $b ) ? array_values( array_map( 'strval', $b ) ) : array();
	}

	/* ---------------------------------------------------------------------
	 * Release info
	 * ------------------------------------------------------------------ */

	/**
	 * Check what the server sent before trusting any of it.
	 *
	 * @param array $out  Parsed info.
	 * @param array $body Raw response.
	 * @return array Info, or [ 'error' => … ].
	 */
	private static function vet( array $out, array $body ) {
		if ( '' === self::public_key() ) {
			return array( 'error' => 'this copy of OSAM Turnstile has no update signing key, so updates are switched off; install a release built by OSAM' );
		}
		$out['sha256']    = strtolower( (string) ( isset( $body['sha256'] ) ? $body['sha256'] : '' ) );
		$out['signature'] = (string) ( isset( $body['signature'] ) ? $body['signature'] : '' );
		if ( ! self::release_signed( $out['version'], $out['sha256'], $out['signature'] ) ) {
			return array( 'error' => 'the update server offered version ' . $out['version'] . ' without a valid OSAM signature, so it was ignored' );
		}
		$p = self::rollback_payload( isset( $body['instruction'] ) ? $body['instruction'] : null, $out['version'] );
		if ( $p ) {
			// Remember withdrawn versions for good, so a hacked server can't offer them again.
			$keep = array_values( array_unique( array_merge( self::blocked(), $p['block_versions'] ) ) );
			update_option( self::BLOCKED, array_slice( $keep, -200 ), false );
			$out['instruction'] = $body['instruction'];
			$out['blocked']     = $p['block_versions'];
			$out['rollback']    = version_compare( $out['version'], self::installed(), '<' );
			$out['forced']      = true;
		} else {
			$out['instruction'] = null;
			$out['blocked']     = array(); // Unsigned lists are ignored.
			$out['rollback']    = false;
		}
		if ( in_array( $out['version'], self::blocked(), true ) ) {
			return array( 'error' => 'the update server offered version ' . $out['version'] . ', which OSAM has withdrawn, so it was ignored' );
		}
		if ( version_compare( $out['version'], self::installed(), '<' ) && ! $p ) {
			$out['forced'] = false; // An older version without a signed rollback: nothing to do.
		}
		return $out;
	}

	/**
	 * Latest release info from the update server, cached for an hour.
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
		$args = array(
			'slug'    => self::SLUG,
			'site'    => home_url( '/' ),
			'version' => self::installed(),
			'wp'      => get_bloginfo( 'version' ),
			'php'     => PHP_VERSION,
			'caps'    => 'force,rollback,signed',
		);
		if ( defined( 'OSB_UPDATE_KEY' ) && OSB_UPDATE_KEY ) {
			$args['key'] = (string) OSB_UPDATE_KEY;
		}
		$res  = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', $args ), self::server() . '/v1/update' ),
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		$body = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( is_wp_error( $res ) ) {
			$out = array( 'error' => 'couldn\'t reach the OSAM update server: ' . $res->get_error_message() );
		} elseif ( ! is_array( $body ) ) {
			$out = array( 'error' => 'unexpected answer from the OSAM update server (HTTP ' . wp_remote_retrieve_response_code( $res ) . ')' );
		} elseif ( empty( $body['ok'] ) || empty( $body['version'] ) ) {
			$out = array( 'error' => ! empty( $body['message'] ) ? sanitize_text_field( $body['message'] ) : 'no update information available' );
		} else {
			$out = array(
				'version'      => sanitize_text_field( $body['version'] ),
				'package'      => esc_url_raw( (string) ( isset( $body['package'] ) ? $body['package'] : '' ) ),
				'requires'     => sanitize_text_field( (string) ( isset( $body['requires'] ) ? $body['requires'] : '' ) ),
				'requires_php' => sanitize_text_field( (string) ( isset( $body['requires_php'] ) ? $body['requires_php'] : '' ) ),
				'tested'       => sanitize_text_field( (string) ( isset( $body['tested'] ) ? $body['tested'] : '' ) ),
				'last_updated' => sanitize_text_field( (string) ( isset( $body['last_updated'] ) ? $body['last_updated'] : '' ) ),
				'sections'     => array_map( 'wp_kses_post', isset( $body['sections'] ) && is_array( $body['sections'] ) ? $body['sections'] : array() ),
				'forced'       => ! empty( $body['forced'] ),
				'rollback'     => ! empty( $body['rollback'] ),
			);
			$out = self::vet( $out, $body );
		}
		if ( isset( $out['error'] ) ) {
			error_log( 'OSAM Turnstile: update check: ' . $out['error'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		$out['checked'] = time();
		set_site_transient( self::CACHE, $out, HOUR_IN_SECONDS );
		self::$mem = $out;
		return $out;
	}

	/**
	 * The version OSAM requires this site to move to now (forced update, rollback, or replacing a withdrawn
	 * version), or '' when nothing is required.
	 *
	 * @param array|null $i Info (cached info when null).
	 * @return string
	 */
	public static function required( $i = null ) {
		$i       = null === $i ? get_site_transient( self::CACHE ) : $i;
		$current = self::installed();
		if ( ! is_array( $i ) || isset( $i['error'] ) || empty( $i['version'] ) || empty( $i['package'] ) || $current === $i['version'] ) {
			return '';
		}
		$blocked = in_array( $current, (array) ( isset( $i['blocked'] ) ? $i['blocked'] : array() ), true ) && ! empty( $i['instruction'] );
		$newer   = version_compare( $i['version'], $current, '>' );
		if ( $blocked || ( ! empty( $i['forced'] ) && ( $newer || ! empty( $i['rollback'] ) ) ) ) {
			return $i['version'];
		}
		return '';
	}

	/** Is there something to install (a normal update or a required version)? */
	private static function pending( array $i ) {
		if ( isset( $i['error'] ) || empty( $i['package'] ) ) {
			return false;
		}
		return version_compare( $i['version'], self::installed(), '>' ) || '' !== self::required( $i );
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
			'plugin'        => self::$file,
			'new_version'   => $version,
			'url'           => 'https://osamweb.com/',
			'package'       => $version === self::installed() ? '' : $i['package'],
			'tested'        => $i['tested'],
			'requires'      => $i['requires'],
			'requires_php'  => $i['requires_php'],
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new stdClass(),
			'osam_sha256'   => isset( $i['sha256'] ) ? $i['sha256'] : '',
			'osam_sig'      => isset( $i['signature'] ) ? $i['signature'] : '',
			'osam_rollback' => isset( $i['instruction'] ) ? $i['instruction'] : null,
		);
	}

	/* ---------------------------------------------------------------------
	 * WordPress hooks
	 * ------------------------------------------------------------------ */

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
		$i = self::info();
		if ( isset( $i['error'] ) ) {
			return $transient;
		}
		if ( self::pending( $i ) ) {
			$transient->response[ self::$file ] = self::item( $i, $i['version'] );
			unset( $transient->no_update[ self::$file ] );
		} else {
			// Listed as up to date so the Plugins screen offers the auto-updates toggle.
			$transient->no_update[ self::$file ] = self::item( $i, self::installed() );
			unset( $transient->response[ self::$file ] );
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
		if ( ! is_array( get_site_transient( self::CACHE ) ) ) {
			return $transient; // Not fetched yet; the next scheduled check will fetch it.
		}
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$transient->response          = isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
		$transient->no_update         = isset( $transient->no_update ) && is_array( $transient->no_update ) ? $transient->no_update : array();
		$checked                      = isset( $transient->checked ) && is_array( $transient->checked ) ? $transient->checked : array();
		$checked[ self::$file ]       = self::installed();
		$transient->checked           = $checked;
		return self::inject( $transient );
	}

	/**
	 * "View details" on the Plugins and Updates screens.
	 *
	 * @param false|object $result Result so far.
	 * @param string       $action API action.
	 * @param object       $args   Request.
	 * @return false|object
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
			'name'          => 'OSAM Turnstile',
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
	 * Download our update and check its signature before WordPress unpacks it.
	 *
	 * @param bool|string|WP_Error $reply      Earlier filter result.
	 * @param string               $package    Package URL.
	 * @param WP_Upgrader          $upgrader   Upgrader.
	 * @param array                $hook_extra Details (which plugin).
	 * @return bool|string|WP_Error Local file path, an error, or $reply when it isn't ours.
	 */
	public static function pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		$info = get_site_transient( self::CACHE );
		$ours = ( isset( $hook_extra['plugin'] ) && self::$file === $hook_extra['plugin'] )
			|| ( is_array( $info ) && ! empty( $info['package'] ) && $package === $info['package'] );
		if ( false !== $reply || ! $ours || ! preg_match( '#^https?://#i', (string) $package ) ) {
			return $reply;
		}
		$fail = static function ( $msg ) {
			return new WP_Error( 'osam_ts_unsigned', $msg );
		};

		// What WordPress was told to install (falls back to the cached info).
		$tr  = get_site_transient( 'update_plugins' );
		$row = is_object( $tr ) && isset( $tr->response[ self::$file ] ) ? $tr->response[ self::$file ] : null;
		$ver = $row ? (string) $row->new_version : (string) ( isset( $info['version'] ) ? $info['version'] : '' );
		$sha = $row && ! empty( $row->osam_sha256 ) ? (string) $row->osam_sha256 : (string) ( isset( $info['sha256'] ) ? $info['sha256'] : '' );
		$sig = $row && ! empty( $row->osam_sig ) ? (string) $row->osam_sig : (string) ( isset( $info['signature'] ) ? $info['signature'] : '' );
		$rb  = $row && isset( $row->osam_rollback ) ? $row->osam_rollback : ( isset( $info['instruction'] ) ? $info['instruction'] : null );

		if ( ! self::release_signed( $ver, $sha, $sig ) ) {
			return $fail( 'Update stopped: this OSAM Turnstile package isn’t signed by OSAM. Check for updates again; if it keeps happening, tell OSAM.' );
		}
		if ( in_array( $ver, self::blocked(), true ) ) {
			return $fail( 'Update stopped: OSAM has withdrawn that version.' );
		}
		if ( version_compare( $ver, self::installed(), '<' ) && ! self::rollback_payload( is_object( $rb ) ? (array) $rb : $rb, $ver ) ) {
			return $fail( 'Update stopped: moving to an older version needs a signed rollback from OSAM.' );
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
		if ( ! hash_equals( $sha, (string) hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );
			return $fail( 'Update stopped: the downloaded OSAM Turnstile zip doesn’t match OSAM’s signed release, so it wasn’t installed.' );
		}
		return $file;
	}

	/**
	 * Automatic updates on for this plugin (define OSAM_TS_AUTO_UPDATE as false to switch them off), and
	 * always on for a version OSAM requires.
	 *
	 * @param bool|null $update Whether to update.
	 * @param object    $item   The update.
	 * @return bool|null
	 */
	public static function auto( $update, $item ) {
		if ( isset( $item->plugin ) && self::$file === $item->plugin && ( ! defined( 'OSAM_TS_AUTO_UPDATE' ) || OSAM_TS_AUTO_UPDATE || '' !== self::required() ) ) {
			return true;
		}
		return $update;
	}

	/** Hourly check for forced updates and rollbacks. */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	/** On deactivation. */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
		delete_site_transient( self::CACHE );
	}

	/**
	 * Install a version OSAM requires (forced update or rollback) without waiting for anyone.
	 */
	public static function enforce() {
		if ( ! wp_doing_cron() || ( defined( 'OSAM_TS_UPDATES' ) && ! OSAM_TS_UPDATES ) ) {
			return;
		}
		$info = self::info( true );
		$want = self::required( $info );
		if ( '' === $want || get_transient( 'osam_ts_forced_lock' ) || get_transient( 'osam_ts_forced_wait' ) ) {
			return;
		}
		if ( ! empty( $info['requires_php'] ) && version_compare( PHP_VERSION, $info['requires_php'], '<' ) ) {
			return;
		}
		if ( ! empty( $info['requires'] ) && version_compare( get_bloginfo( 'version' ), $info['requires'], '<' ) ) {
			return;
		}
		set_transient( 'osam_ts_forced_lock', 1, 10 * MINUTE_IN_SECONDS );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$base   = self::$file;
		$from   = self::installed();
		$active = is_plugin_active( $base );
		$status = array(
			'time' => time(),
			'from' => $from,
			'to'   => $want,
			'ok'   => false,
			'msg'  => '',
		);
		if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
			$status['msg'] = 'WordPress can\'t write to the plugins folder without FTP details; update it under Dashboard → Updates';
		} else {
			// Make sure WordPress's update list holds the required version for the upgrader to read.
			$tr = get_site_transient( 'update_plugins' );
			if ( ! is_object( $tr ) || empty( $tr->response[ $base ] ) || $tr->response[ $base ]->new_version !== $want ) {
				$tr                    = is_object( $tr ) ? $tr : new stdClass();
				$tr->response          = isset( $tr->response ) && is_array( $tr->response ) ? $tr->response : array();
				$tr->checked           = ( isset( $tr->checked ) && is_array( $tr->checked ) ? $tr->checked : array() ) + array( $base => $from );
				$tr->response[ $base ] = self::item( $info, $want );
				$tr->last_checked      = time();
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
		update_option( self::STATUS, $status, false );
		error_log( 'OSAM Turnstile: ' . ( $status['ok'] ? 'installed' : 'failed to install' ) . " $want over $from" . ( $status['msg'] ? ': ' . $status['msg'] : '' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		if ( ! $status['ok'] ) {
			set_transient( 'osam_ts_forced_wait', 1, 30 * MINUTE_IN_SECONDS ); // Try again in half an hour.
		}
		delete_transient( 'osam_ts_forced_lock' );
	}

	/**
	 * After any plugin install or update: forget the cached info. When it was this plugin (by any route,
	 * including by hand), check again in a couple of minutes, so a withdrawn version put back is replaced.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $extra    Details.
	 */
	public static function after_install( $upgrader, $extra ) {
		if ( empty( $extra['type'] ) || 'plugin' !== $extra['type'] ) {
			return;
		}
		delete_site_transient( self::CACHE );
		self::$mem = null;
		$ours = ( isset( $extra['plugins'] ) && in_array( self::$file, (array) $extra['plugins'], true ) )
			|| ( isset( $extra['plugin'] ) && self::$file === $extra['plugin'] )
			|| ( isset( $upgrader->result['destination_name'] ) && self::SLUG === $upgrader->result['destination_name'] );
		if ( $ours ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::CRON );
		}
	}
}
