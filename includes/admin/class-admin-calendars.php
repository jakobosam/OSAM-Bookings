<?php
/**
 * Calendar sync admin: app credentials (Settings → Calendar sync) and per-person connections.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calendar sync screens and handlers.
 */
class OSB_Admin_Calendars {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_cal_connect', array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_osb_cal_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_osb_cal_disconnect', array( __CLASS__, 'disconnect' ) );
		add_action( 'admin_post_osb_cal_sync_now', array( __CLASS__, 'sync_now' ) );
		add_action( 'admin_notices', array( __CLASS__, 'problems_notice' ) );
	}

	/**
	 * Where a staff member's settings live.
	 *
	 * @param int $staff_id Staff.
	 * @return string
	 */
	public static function staff_url( $staff_id ) {
		return OSB_DB::is_solo() ? admin_url( 'admin.php?page=osb-staff' ) : admin_url( 'admin.php?page=osb-staff&action=edit&id=' . (int) $staff_id );
	}

	/**
	 * May the current user manage this person's calendar? (Admins, or the linked WP user themselves.)
	 *
	 * @param int $staff_id Staff.
	 * @return bool
	 */
	private static function can( $staff_id ) {
		if ( current_user_can( OSB_Admin::CAP ) ) {
			return true;
		}
		$st = OSB_DB::get( 'staff', $staff_id );
		return $st && (int) $st->wp_user_id && (int) $st->wp_user_id === get_current_user_id();
	}

	/**
	 * Warn on our screens when a connection needs attention.
	 */
	public static function problems_notice() {
		if ( ! OSB_Admin::is_our_screen() || ! OSB_Settings::feature( 'calendar_sync' ) || ! OSB_Calendar_Sync::has_connections() ) {
			return;
		}
		foreach ( OSB_Calendar_Sync::connections() as $c ) {
			if ( 'error' !== $c->status ) {
				continue;
			}
			$st = OSB_DB::get( 'staff', $c->staff_id );
			printf(
				'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
				/* translators: 1: person 2: provider */
				esc_html( sprintf( __( 'The %2$s connection for %1$s has stopped working, so bookings are not being synced.', 'osam-bookings' ), $st ? $st->name : '#' . $c->staff_id, OSB_Calendar_Sync::providers()[ $c->provider ] ?? $c->provider ) ),
				esc_url( self::staff_url( $c->staff_id ) . '#calendar-sync' ),
				esc_html__( 'Reconnect', 'osam-bookings' )
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Staff / Working hours page section
	 * ------------------------------------------------------------------ */

	/**
	 * Calendar sync section for one person.
	 *
	 * @param object $st   Staff row.
	 * @param bool   $solo Solo mode.
	 */
	public static function staff_section( $st, $solo ) {
		if ( ! OSB_Settings::feature( 'calendar_sync' ) ) {
			return;
		}
		$conn       = OSB_Calendar_Sync::connection_for( $st->id );
		$providers  = OSB_Calendar_Sync::providers();
		$configured = array_filter(
			array_keys( $providers ),
			array( 'OSB_Calendar_Sync', 'configured' )
		);
		?>
		<div class="osb-section osb-calsync" id="calendar-sync">
			<div class="osb-section__intro"><h2><?php esc_html_e( 'Calendar sync', 'osam-bookings' ); ?></h2><p><?php echo $solo ? esc_html__( 'Connect your Google or Outlook calendar: bookings appear there, and anything you put in it blocks those times for online booking.', 'osam-bookings' ) : esc_html__( 'Connect this person’s Google or Outlook calendar: their bookings appear there, and their own appointments block those times for online booking.', 'osam-bookings' ); ?></p></div>
			<div class="osb-card">
			<?php if ( ! $conn ) : ?>
				<?php if ( ! $configured ) : ?>
					<p class="osb-muted"><?php esc_html_e( 'Calendar sync needs a one-off setup of the Google and/or Microsoft app details first.', 'osam-bookings' ); ?>
					<?php if ( current_user_can( 'manage_options' ) ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-settings&tab=calendars' ) ); ?>"><?php esc_html_e( 'Set it up', 'osam-bookings' ); ?> →</a><?php endif; ?></p>
				<?php else : ?>
					<div class="osb-calsync__connect">
						<?php
						foreach ( $configured as $p ) :
							$url = wp_nonce_url( admin_url( 'admin-post.php?action=osb_cal_connect&provider=' . $p . '&staff=' . (int) $st->id ), 'osb_cal_connect_' . $st->id );
							?>
							<a class="osb-btn osb-btn--ghost osb-calsync__btn osb-calsync__btn--<?php echo esc_attr( $p ); ?>" href="<?php echo esc_url( $url ); ?>"><span class="osb-calsync__logo osb-calsync__logo--<?php echo esc_attr( $p ); ?>" aria-hidden="true"></span><?php /* translators: %s provider */ echo esc_html( sprintf( __( 'Connect %s', 'osam-bookings' ), $providers[ $p ] ) ); ?></a>
						<?php endforeach; ?>
					</div>
					<p class="osb-help"><?php esc_html_e( 'You’ll be asked to sign in and allow access. Only bookings are written; nothing in the calendar is changed except the entries this plugin adds.', 'osam-bookings' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<?php
				$cals     = json_decode( (string) $conn->calendars, true );
				$cals     = is_array( $cals ) ? $cals : array();
				$busy     = OSB_Calendar_Sync::busy_calendars( $conn );
				$writable = array();
				foreach ( $cals as $c ) {
					if ( ! empty( $c['writable'] ) ) {
						$writable[ $c['id'] ] = $c['name'] . ( ! empty( $c['primary'] ) ? ' (' . __( 'main', 'osam-bookings' ) . ')' : '' );
					}
				}
				$sync = wp_nonce_url( admin_url( 'admin-post.php?action=osb_cal_sync_now&staff=' . (int) $st->id ), 'osb_cal_sync_' . $st->id );
				$drop = wp_nonce_url( admin_url( 'admin-post.php?action=osb_cal_disconnect&staff=' . (int) $st->id ), 'osb_cal_disconnect_' . $st->id );
				$re   = wp_nonce_url( admin_url( 'admin-post.php?action=osb_cal_connect&provider=' . $conn->provider . '&staff=' . (int) $st->id ), 'osb_cal_connect_' . $st->id );
				?>
				<div class="osb-calsync__head">
					<span class="osb-calsync__logo osb-calsync__logo--<?php echo esc_attr( $conn->provider ); ?>" aria-hidden="true"></span>
					<div>
						<strong><?php echo esc_html( $providers[ $conn->provider ] ?? $conn->provider ); ?></strong>
						<small><?php echo esc_html( $conn->account ? $conn->account : __( 'Connected account', 'osam-bookings' ) ); ?></small>
					</div>
					<?php if ( 'active' === $conn->status ) : ?>
						<span class="osb-pill osb-pill--confirmed"><?php esc_html_e( 'Connected', 'osam-bookings' ); ?></span>
					<?php else : ?>
						<span class="osb-pill osb-pill--cancelled"><?php esc_html_e( 'Needs reconnecting', 'osam-bookings' ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( 'active' !== $conn->status ) : ?>
					<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div><?php esc_html_e( 'Access to this calendar was refused (the password may have changed or access was removed). Bookings aren’t being synced until you reconnect.', 'osam-bookings' ); ?><?php echo $conn->last_error ? '<br><small>' . esc_html( $conn->last_error ) . '</small>' : ''; ?><p><a class="osb-btn osb-btn--primary osb-btn--sm" href="<?php echo esc_url( $re ); ?>"><?php esc_html_e( 'Reconnect', 'osam-bookings' ); ?></a></p></div></div>
				<?php elseif ( $conn->last_error ) : ?>
					<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div><?php esc_html_e( 'The last sync had a problem — it will retry automatically.', 'osam-bookings' ); ?><br><small><?php echo esc_html( $conn->last_error ); ?></small></div></div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form">
					<?php wp_nonce_field( 'osb_cal_save_' . $st->id ); ?>
					<input type="hidden" name="action" value="osb_cal_save">
					<input type="hidden" name="staff" value="<?php echo (int) $st->id; ?>">
					<label class="osb-switch"><input type="hidden" name="push" value="0"><input type="checkbox" name="push" value="1" <?php checked( (int) $conn->push, 1 ); ?>><span></span><?php esc_html_e( 'Add bookings to my calendar', 'osam-bookings' ); ?></label>
					<div class="osb-field osb-calsync__target">
						<label for="osb_cal_target_<?php echo (int) $st->id; ?>"><?php esc_html_e( 'Calendar to add them to', 'osam-bookings' ); ?></label>
						<?php if ( $writable ) : ?>
							<?php OSB_Admin::input( 'calendar_id', $conn->calendar_id, 'select', $writable, 'osb_cal_target_' . $st->id ); ?>
						<?php else : ?>
							<p class="osb-muted"><?php esc_html_e( 'No calendars found — use “Sync now” to reload the list.', 'osam-bookings' ); ?></p>
						<?php endif; ?>
						<p class="osb-help"><?php esc_html_e( 'Appointments, events you host and course sessions you teach. Cancelled or moved bookings are updated automatically.', 'osam-bookings' ); ?></p>
					</div>
					<label class="osb-switch"><input type="hidden" name="pull" value="0"><input type="checkbox" name="pull" value="1" <?php checked( (int) $conn->pull, 1 ); ?>><span></span><?php esc_html_e( 'Block online booking when I’m busy in:', 'osam-bookings' ); ?></label>
					<?php if ( $cals ) : ?>
						<div class="osb-chips-check osb-calsync__busy">
							<?php foreach ( $cals as $c ) : ?>
								<label><input type="checkbox" name="busy[]" value="<?php echo esc_attr( $c['id'] ); ?>" <?php checked( in_array( (string) $c['id'], $busy, true ) ); ?>><span><?php echo esc_html( $c['name'] ); ?></span></label>
							<?php endforeach; ?>
						</div>
						<p class="osb-help"><?php esc_html_e( 'Entries marked “free” and all-day entries set to free are ignored. The bookings this plugin adds never block themselves.', 'osam-bookings' ); ?></p>
					<?php endif; ?>
					<div class="osb-formfoot osb-calsync__foot">
						<span class="osb-muted">
							<?php
							echo $conn->last_sync
								/* translators: %s time ago */
								? esc_html( sprintf( __( 'Last synced %s ago', 'osam-bookings' ), human_time_diff( strtotime( $conn->last_sync ), strtotime( current_time( 'mysql' ) ) ) ) )
								: esc_html__( 'Not synced yet', 'osam-bookings' );
							?>
							· <a href="<?php echo esc_url( $sync ); ?>"><?php esc_html_e( 'Sync now', 'osam-bookings' ); ?></a>
							· <a href="<?php echo esc_url( $drop ); ?>" class="osb-link--danger" data-osb-confirm="<?php esc_attr_e( 'Disconnect this calendar? Upcoming bookings added by this plugin will be removed from it.', 'osam-bookings' ); ?>"><?php esc_html_e( 'Disconnect', 'osam-bookings' ); ?></a>
						</span>
						<button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Save calendar settings', 'osam-bookings' ); ?></button>
					</div>
				</form>
			<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Start the OAuth flow.
	 */
	public static function connect() {
		$staff = isset( $_GET['staff'] ) ? absint( $_GET['staff'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'osb_cal_connect_' . $staff );
		$provider = isset( $_GET['provider'] ) ? sanitize_key( $_GET['provider'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! self::can( $staff ) || ! isset( OSB_Calendar_Sync::providers()[ $provider ] ) || ! OSB_Calendar_Sync::configured( $provider ) || ! OSB_DB::get( 'staff', $staff ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'osam-bookings' ), 403 );
		}
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external provider URL.
		wp_redirect( OSB_Calendar_Sync::begin( $provider, $staff, self::staff_url( $staff ) . '#calendar-sync' ) );
		exit;
	}

	/**
	 * Save a person's sync options.
	 */
	public static function save() {
		$staff = isset( $_POST['staff'] ) ? absint( $_POST['staff'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'osb_cal_save_' . $staff );
		if ( ! self::can( $staff ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'osam-bookings' ), 403 );
		}
		$conn = OSB_Calendar_Sync::connection_for( $staff );
		if ( ! $conn ) {
			OSB_Admin::redirect( self::staff_url( $staff ), 'error' );
		}
		$post  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$cals  = json_decode( (string) $conn->calendars, true );
		$valid = array();
		$names = array();
		foreach ( is_array( $cals ) ? $cals : array() as $c ) {
			$valid[] = (string) $c['id'];
			$names[ (string) $c['id'] ] = $c['name'];
		}
		$target = isset( $post['calendar_id'] ) && in_array( (string) $post['calendar_id'], $valid, true ) ? (string) $post['calendar_id'] : $conn->calendar_id;
		$busy   = array_values( array_intersect( array_map( 'strval', (array) ( isset( $post['busy'] ) ? $post['busy'] : array() ) ), $valid ) );
		OSB_DB::update(
			'calendar_connections',
			$conn->id,
			array(
				'push'           => empty( $post['push'] ) ? 0 : 1,
				'pull'           => empty( $post['pull'] ) ? 0 : 1,
				'calendar_id'    => $target,
				'calendar_name'  => isset( $names[ $target ] ) ? $names[ $target ] : $conn->calendar_name,
				'busy_calendars' => wp_json_encode( $busy ),
			)
		);
		delete_transient( 'osb_cal_busy_' . $conn->id );
		OSB_Availability::bust_cache();
		OSB_Calendar_Sync::soon();
		OSB_Admin::redirect( self::staff_url( $staff ) . '#calendar-sync', 'cal_saved' );
	}

	/**
	 * Push and pull now, and reload the calendar list.
	 */
	public static function sync_now() {
		$staff = isset( $_GET['staff'] ) ? absint( $_GET['staff'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'osb_cal_sync_' . $staff );
		if ( ! self::can( $staff ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'osam-bookings' ), 403 );
		}
		$conn = OSB_Calendar_Sync::connection_for( $staff );
		$msg  = 'cal_synced';
		if ( $conn && 'active' === $conn->status ) {
			// Keep this click short: shared hosts cut off long requests (often as a 503).
			$start = microtime( true );
			$cals  = OSB_Calendar_Sync::fetch_calendars( $conn );
			if ( ! is_wp_error( $cals ) ) {
				OSB_DB::update( 'calendar_connections', $conn->id, array( 'calendars' => wp_json_encode( $cals ) ) );
			}
			$more = false;
			if ( $conn->push ) {
				$stats = OSB_Calendar_Sync::sync( $conn, (float) apply_filters( 'osb_sync_now_budget', 12 ) );
				$more  = ! empty( $stats[4] );
				if ( $stats[3] ) {
					OSB_Admin::flash_error( __( 'Some entries could not be synced — see the message on the calendar card.', 'osam-bookings' ) );
				}
			}
			if ( $conn->pull ) {
				if ( microtime( true ) - $start < 18 ) {
					OSB_Calendar_Sync::refresh_busy( $conn );
				} else {
					$more = true;
				}
			}
			if ( $more ) {
				OSB_Calendar_Sync::schedule_followup( 5 );
				spawn_cron();
				$msg = 'cal_sync_started';
			}
		}
		OSB_Admin::redirect( self::staff_url( $staff ) . '#calendar-sync', $msg );
	}

	/**
	 * Disconnect.
	 */
	public static function disconnect() {
		$staff = isset( $_GET['staff'] ) ? absint( $_GET['staff'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'osb_cal_disconnect_' . $staff );
		if ( ! self::can( $staff ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'osam-bookings' ), 403 );
		}
		OSB_Calendar_Sync::disconnect( $staff, true );
		OSB_Admin::redirect( self::staff_url( $staff ) . '#calendar-sync', 'cal_disconnected' );
	}

	/* ---------------------------------------------------------------------
	 * Settings → Calendar sync
	 * ------------------------------------------------------------------ */

	/**
	 * A copyable value.
	 *
	 * @param string $value Value.
	 */
	private static function copy_field( $value ) {
		echo '<div class="osb-inline osb-copyfield"><code>' . esc_html( $value ) . '</code><button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-copy" data-copy="' . esc_attr( $value ) . '"><span class="dashicons dashicons-admin-page"></span>' . esc_html__( 'Copy', 'osam-bookings' ) . '</button></div>';
	}

	/**
	 * Settings tab body (inside the settings form).
	 *
	 * @param array    $s     Settings.
	 * @param callable $field Settings field renderer.
	 */
	public static function settings_tab( $s, $field ) {
		$uri   = OSB_Calendar_Sync::redirect_uri();
		$plain = false !== strpos( $uri, '?' );
		$site  = wp_parse_url( home_url(), PHP_URL_HOST );
		?>
		<?php
		$cs         = OSB_Calendar_Sync::connector_status( isset( $_GET['recheck'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$own        = OSB_Calendar_Sync::own_app( 'google' ) || OSB_Calendar_Sync::own_app( 'microsoft' );
		$providers  = OSB_Calendar_Sync::providers();
		?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Easy setup — sign in with Google or Microsoft', 'osam-bookings' ), $cs['ok'] ? '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'Ready', 'osam-bookings' ) . '</span>' : '' ); ?>
			<p class="osb-muted"><?php esc_html_e( 'Uses OSAM’s shared, verified calendar app, so there’s nothing to set up here. Sign-ins go through the OSAM connector; calendar tokens are stored encrypted on this site only.', 'osam-bookings' ); ?></p>
			<?php call_user_func( $field, 'cal_use_connector', __( 'Use the OSAM connector', 'osam-bookings' ), $s['cal_use_connector'], 'checkbox' ); ?>
			<?php if ( OSB_Calendar_Sync::connector_url() ) : ?>
				<?php if ( $cs['ok'] ) : ?>
					<p><?php /* translators: %s providers */ echo esc_html( sprintf( __( 'Connected to %1$s — available: %2$s.', 'osam-bookings' ), $cs['name'] ? $cs['name'] : OSB_Calendar_Sync::connector_url(), implode( ', ', array_map( static function ( $p ) use ( $providers ) { return $providers[ $p ]; }, $cs['providers'] ) ) ) ); ?></p>
					<?php if ( ! OSB_Calendar_Sync::connections() ) : ?>
						<div class="osb-alert osb-alert--info"><span class="dashicons dashicons-info-outline"></span><div><strong><?php esc_html_e( 'No calendars connected yet.', 'osam-bookings' ); ?></strong> <?php echo OSB_DB::is_solo() ? esc_html__( 'This page only links the site to the connector. To connect your own calendar, go to Working hours → Calendar sync and click Connect.', 'osam-bookings' ) : esc_html__( 'This page only links the site to the connector. Each person connects their own calendar under Staff & hours → their name → Calendar sync.', 'osam-bookings' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-staff' ) . ( OSB_DB::is_solo() ? '#calendar-sync' : '' ) ); ?>"><?php echo OSB_DB::is_solo() ? esc_html__( 'Connect my calendar', 'osam-bookings' ) : esc_html__( 'Go to Staff & hours', 'osam-bookings' ); ?> →</a></div></div>
					<?php endif; ?>
				<?php else : ?>
					<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div><?php /* translators: %s error */ echo esc_html( sprintf( __( 'Can’t reach the connector: %s', 'osam-bookings' ), $cs['error'] ) ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-settings&tab=calendars&recheck=1' ) ); ?>"><?php esc_html_e( 'Check again', 'osam-bookings' ); ?></a></div></div>
				<?php endif; ?>
			<?php endif; ?>
			<details class="osb-steps">
				<summary><?php esc_html_e( 'Connector address', 'osam-bookings' ); ?></summary>
				<?php call_user_func( $field, 'cal_connector_url', __( 'Connector address', 'osam-bookings' ), $s['cal_connector_url'], 'url', array( 'placeholder' => defined( 'OSB_CONNECTOR_URL' ) ? OSB_CONNECTOR_URL : OSB_Calendar_Sync::DEFAULT_CONNECTOR, 'help' => __( 'Leave blank to use the default.', 'osam-bookings' ) ) ); ?>
			</details>
		</div>

		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'How calendar sync works', 'osam-bookings' ), __( 'Each person connects their own calendar from Staff & hours (or Working hours in solo mode) with a sign-in button. Their main calendar is picked and both directions are switched on automatically — they can change it afterwards.', 'osam-bookings' ) ); ?>
			<ul class="osb-ticks">
				<li><?php esc_html_e( 'Bookings, hosted events and course sessions are added to their calendar within a few minutes, and updated or removed when they change.', 'osam-bookings' ); ?></li>
				<li><?php esc_html_e( 'Busy times in the calendars they choose block those times for online booking (refreshed about every 10 minutes).', 'osam-bookings' ); ?></li>
				<li><?php esc_html_e( 'Admin bookings are never blocked — you’ll just see a “busy in their calendar” warning.', 'osam-bookings' ); ?></li>
				<li><?php esc_html_e( 'Sync runs on WP-Cron every 5 minutes. On quiet sites add a real cron job (see the readme) so it runs on time.', 'osam-bookings' ); ?></li>
			</ul>
		</div>

		<details class="osb-card osb-advanced" <?php echo $own ? 'open' : ''; ?>>
			<summary><strong><?php esc_html_e( 'Advanced: use this site’s own Google / Microsoft app instead', 'osam-bookings' ); ?></strong><span class="osb-muted"> — <?php esc_html_e( 'only if a client needs their own app. When filled in, these are used instead of the connector.', 'osam-bookings' ); ?></span></summary>
			<?php if ( $plain ) : ?>
				<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div><?php esc_html_e( 'This site uses plain permalinks, so the redirect address contains a “?”. Microsoft won’t accept that. Switch Settings → Permalinks to “Post name” first.', 'osam-bookings' ); ?></div></div>
			<?php endif; ?>
			<div class="osb-field"><label><?php esc_html_e( 'Redirect URI (use this in both Google and Microsoft)', 'osam-bookings' ); ?></label><?php self::copy_field( $uri ); ?></div>

		<div class="osb-subsection">
			<?php OSB_Admin::card_head( __( 'Google Calendar', 'osam-bookings' ), OSB_Calendar_Sync::own_app( 'google' ) ? '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'Ready', 'osam-bookings' ) . '</span>' : '' ); ?>
			<details class="osb-steps">
				<summary><?php esc_html_e( 'Setup steps (about 10 minutes)', 'osam-bookings' ); ?></summary>
				<ol>
					<li><?php echo wp_kses_post( __( 'Open <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">Google Cloud Console</a> and create a project (e.g. “Bookings — your site”).', 'osam-bookings' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'APIs &amp; Services → Library → enable <strong>Google Calendar API</strong>.', 'osam-bookings' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'OAuth consent screen (Google Auth Platform): user type <strong>External</strong>, add your app name, support email and the site’s domain. Under Data access add the scopes <code>…/auth/calendar.events</code> and <code>…/auth/calendar.calendarlist.readonly</code>.', 'osam-bookings' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Audience: click <strong>Publish app</strong>. While it stays in “Testing”, Google disconnects everyone after 7 days. An unverified app shows a warning screen on connect (click Advanced → continue); that’s fine for your own staff.', 'osam-bookings' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Clients → Create client → <strong>Web application</strong>. Add the redirect URI above under Authorised redirect URIs.', 'osam-bookings' ) ); ?></li>
					<li><?php esc_html_e( 'Copy the Client ID and Client secret into the boxes below and save.', 'osam-bookings' ); ?></li>
				</ol>
			</details>
			<div class="osb-fieldgrid">
				<?php
				call_user_func( $field, 'gcal_client_id', __( 'Client ID', 'osam-bookings' ), $s['gcal_client_id'], 'text', array( 'class' => 'osb-field--half', 'placeholder' => '123456789-abc.apps.googleusercontent.com' ) );
				call_user_func( $field, 'gcal_client_secret', __( 'Client secret', 'osam-bookings' ), $s['gcal_client_secret'], 'password', array( 'class' => 'osb-field--half' ) );
				?>
			</div>
		</div>

		<div class="osb-subsection">
			<?php OSB_Admin::card_head( __( 'Microsoft 365 / Outlook', 'osam-bookings' ), OSB_Calendar_Sync::own_app( 'microsoft' ) ? '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'Ready', 'osam-bookings' ) . '</span>' : '' ); ?>
			<details class="osb-steps">
				<summary><?php esc_html_e( 'Setup steps (about 10 minutes)', 'osam-bookings' ); ?></summary>
				<ol>
					<li><?php echo wp_kses_post( __( 'Open <a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener">Microsoft Entra → App registrations</a> → <strong>New registration</strong>.', 'osam-bookings' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Supported account types: <strong>Accounts in any organizational directory and personal Microsoft accounts</strong> (so both Microsoft 365 and Outlook.com/Hotmail work).', 'osam-bookings' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Redirect URI: platform <strong>Web</strong>, paste the redirect URI above. Register.', 'osam-bookings' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'API permissions → Add → Microsoft Graph → <strong>Delegated</strong>: <code>Calendars.ReadWrite</code>, <code>User.Read</code>, <code>offline_access</code>.', 'osam-bookings' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Certificates &amp; secrets → New client secret. Copy the <strong>Value</strong> (not the Secret ID). Note the expiry date — put a reminder in to renew it before then.', 'osam-bookings' ) ); ?></li>
					<li><?php esc_html_e( 'Copy the Application (client) ID from Overview and the secret into the boxes below and save.', 'osam-bookings' ); ?></li>
				</ol>
			</details>
			<div class="osb-fieldgrid">
				<?php
				call_user_func( $field, 'mscal_client_id', __( 'Application (client) ID', 'osam-bookings' ), $s['mscal_client_id'], 'text', array( 'class' => 'osb-field--half', 'placeholder' => '00000000-0000-0000-0000-000000000000' ) );
				call_user_func( $field, 'mscal_client_secret', __( 'Client secret value', 'osam-bookings' ), $s['mscal_client_secret'], 'password', array( 'class' => 'osb-field--half' ) );
				call_user_func( $field, 'mscal_tenant', __( 'Tenant', 'osam-bookings' ), $s['mscal_tenant'], 'text', array( 'class' => 'osb-field--half', 'placeholder' => 'common', 'help' => __( 'Leave as “common”. Enter a tenant ID only if the app is restricted to one organisation.', 'osam-bookings' ) ) );
				?>
			</div>
		</div>

		</details>

		<?php
		$conns = OSB_Calendar_Sync::connections();
		if ( $conns ) :
			?>
		<div class="osb-card osb-card--table">
			<?php OSB_Admin::card_head( __( 'Connected calendars', 'osam-bookings' ) ); ?>
			<table class="osb-table">
				<thead><tr><th><?php esc_html_e( 'Person', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Calendar', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Last sync', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th></tr></thead>
				<tbody>
				<?php
				foreach ( $conns as $c ) :
					$st = OSB_DB::get( 'staff', $c->staff_id );
					?>
					<tr>
						<td><a href="<?php echo esc_url( self::staff_url( $c->staff_id ) . '#calendar-sync' ); ?>"><strong><?php echo esc_html( $st ? $st->name : '#' . $c->staff_id ); ?></strong></a></td>
						<td><?php echo esc_html( OSB_Calendar_Sync::providers()[ $c->provider ] ?? $c->provider ); ?><small><?php echo esc_html( $c->account . ( $c->calendar_name ? ' · ' . $c->calendar_name : '' ) ); ?></small></td>
						<td><?php echo $c->last_sync ? esc_html( mysql2date( 'j M H:i', $c->last_sync ) ) : '—'; ?></td>
						<td><?php echo 'active' === $c->status ? ( $c->last_error ? '<span class="osb-pill osb-pill--warn">' . esc_html__( 'Retrying', 'osam-bookings' ) . '</span>' : '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'Connected', 'osam-bookings' ) . '</span>' ) : '<span class="osb-pill osb-pill--cancelled">' . esc_html__( 'Needs reconnecting', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
		<p class="osb-help"><?php /* translators: %s domain */ echo esc_html( sprintf( __( 'Tokens are stored encrypted in this site’s database (%s) and are only used to read and write calendars for the people who connected them.', 'osam-bookings' ), $site ) ); ?></p>
		<?php
	}
}
