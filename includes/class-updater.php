<?php
/**
 * Private updates from the OSAM Connector.
 *
 * Asks the connector (connect.osamweb.com by default) for the latest release and feeds it into
 * WordPress's normal plugin update system, so it shows under Dashboard → Updates, can be updated
 * with one click, and works with WordPress auto-updates.
 *
 * Turn off with define( 'OSB_UPDATES', false ); in wp-config.php.
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
	 * Connector address — the same one calendar sign-in uses.
	 *
	 * @return string
	 */
	public static function server() {
		$url = trim( (string) OSB_Settings::get( 'cal_connector_url' ) );
		if ( '' === $url ) {
			$url = defined( 'OSB_CONNECTOR_URL' ) ? (string) OSB_CONNECTOR_URL : OSB_Calendar_Sync::DEFAULT_CONNECTOR;
		}
		return untrailingslashit( esc_url_raw( (string) apply_filters( 'osb_update_server', $url ) ) );
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
			'caps'    => 'force,rollback',
		);
		if ( defined( 'OSB_UPDATE_KEY' ) && OSB_UPDATE_KEY ) {
			$args['key'] = (string) OSB_UPDATE_KEY;
		}
		$url = add_query_arg( array_map( 'rawurlencode', $args ), self::server() . '/wp-json/osam-connect/v1/update' );
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
				'blocked'      => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $body['blocked'] ?? array() ) ) ) ),
			);
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
		$blocked = in_array( OSB_VERSION, (array) ( $i['blocked'] ?? array() ), true );
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
		);
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
