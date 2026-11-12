<?php
/**
 * Settings screen.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tabbed settings: general, payments, emails, booking form, appearance (with live preview).
 */
class OSB_Admin_Settings {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_osb_test_email', array( __CLASS__, 'test_email' ) );
		add_action( 'admin_post_osb_zoom_test', array( __CLASS__, 'zoom_test' ) );
		add_action( 'admin_post_osb_stripe_check', array( __CLASS__, 'stripe_check' ) );
		add_action( 'admin_post_osb_stripe_disconnect', array( __CLASS__, 'stripe_disconnect' ) );
	}

	/**
	 * The Stripe gateway, if it's registered.
	 *
	 * @return OSB_Gateway_Stripe|null
	 */
	private static function stripe() {
		$all = osb()->payments->all();
		return isset( $all['stripe'] ) && $all['stripe'] instanceof OSB_Gateway_Stripe ? $all['stripe'] : null;
	}

	/**
	 * "Check connection": re-check the saved Stripe key and repair the webhook.
	 */
	public static function stripe_check() {
		OSB_Admin::verify( 'osb_stripe_check', 'manage_options' );
		$url    = admin_url( 'admin.php?page=osb-settings&tab=payments' );
		$stripe = self::stripe();
		$mode   = isset( $_GET['mode'] ) && 'live' === $_GET['mode'] ? 'live' : 'test';
		$key    = $stripe ? trim( (string) OSB_Settings::get( 'stripe_' . $mode . '_secret' ) ) : '';
		if ( '' === $key ) {
			OSB_Admin::redirect( $url, 'error' );
		}
		$r = $stripe->connect( $key );
		if ( is_wp_error( $r ) ) {
			OSB_Admin::flash_error( $r->get_error_message() );
			OSB_Admin::redirect( $url, 'none' );
		}
		if ( $r['warning'] ) {
			OSB_Admin::flash_warnings( array( $r['warning'] ) );
		}
		$problem = (string) OSB_Settings::get( 'stripe_' . $mode . '_webhook_error' );
		OSB_Admin::redirect( $url, '' === $problem ? 'stripe_ok' : 'stripe_ok_nohook' );
	}

	/**
	 * "Disconnect": forget the Stripe key for a mode.
	 */
	public static function stripe_disconnect() {
		OSB_Admin::verify( 'osb_stripe_disconnect', 'manage_options' );
		$stripe = self::stripe();
		if ( $stripe ) {
			$stripe->disconnect( isset( $_GET['mode'] ) && 'live' === $_GET['mode'] ? 'live' : 'test' );
		}
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-settings&tab=payments' ), 'stripe_off' );
	}

	/**
	 * Check the Zoom app details work.
	 */
	public static function zoom_test() {
		OSB_Admin::verify( 'osb_zoom_test', 'manage_options' );
		// Do what a real booking does — make a meeting, move it, delete it — so every permission
		// the app needs is checked, not just the sign-in.
		$start = gmdate( 'Y-m-d\TH:i:s\Z', time() + WEEK_IN_SECONDS );
		$res   = OSB_Meetings::zoom(
			'POST',
			'/users/me/meetings',
			array(
				'topic'      => 'OSAM Bookings connection test (safe to delete)',
				'type'       => 2,
				'start_time' => $start,
				'duration'   => 15,
			)
		);
		if ( ! is_wp_error( $res ) && ! empty( $res['id'] ) ) {
			$path = '/meetings/' . rawurlencode( (string) $res['id'] );
			$res  = OSB_Meetings::zoom( 'PATCH', $path, array( 'start_time' => gmdate( 'Y-m-d\TH:i:s\Z', time() + WEEK_IN_SECONDS + HOUR_IN_SECONDS ) ) );
			$gone = OSB_Meetings::zoom( 'DELETE', $path );
			if ( ! is_wp_error( $res ) ) {
				$res = $gone;
			}
		} elseif ( ! is_wp_error( $res ) ) {
			$res = new WP_Error( 'osb_zoom_test', __( 'Zoom accepted the details but didn’t create a test meeting.', 'osam-bookings' ) );
		}
		if ( is_wp_error( $res ) ) {
			set_transient( 'osb_admin_error_' . get_current_user_id(), $res->get_error_message(), 60 );
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-settings&tab=meetings' ), 'none' );
		}
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-settings&tab=meetings' ), 'zoom_ok' );
	}

	/**
	 * Tabs.
	 *
	 * @return array
	 */
	public static function tabs() {
		// [ label, icon, group ] — grouped under headings in the side menu, most used first.
		$tabs = array(
			'general'      => array( __( 'General', 'osam-bookings' ), 'dashicons-admin-settings', 'business' ),
			'payments'     => array( __( 'Payments', 'osam-bookings' ), 'dashicons-money-alt', 'business' ),
			'emails'       => array( __( 'Email sending & design', 'osam-bookings' ), 'dashicons-email-alt', 'business' ),
			'invoices'     => array( __( 'Invoices', 'osam-bookings' ), 'dashicons-media-spreadsheet', 'business' ),
			'appearance'   => array( __( 'Look & wording', 'osam-bookings' ), 'dashicons-art', 'form' ),
			'form'         => array( __( 'Questions to ask', 'osam-bookings' ), 'dashicons-feedback', 'form' ),
			'calendars'    => array( __( 'Calendar sync', 'osam-bookings' ), 'dashicons-update', 'connect' ),
			'meetings'     => array( __( 'Zoom, Meet & Teams', 'osam-bookings' ), 'dashicons-video-alt2', 'connect' ),
			'features'     => array( __( 'Features on/off', 'osam-bookings' ), 'dashicons-screenoptions', 'advanced' ),
			'subscription' => array( __( 'OSAM subscription', 'osam-bookings' ), 'dashicons-shield', 'advanced' ),
		);
		if ( ! OSB_Settings::feature( 'calendar_sync' ) ) {
			unset( $tabs['calendars'] );
		}
		if ( ! OSB_Settings::feature( 'meetings' ) ) {
			unset( $tabs['meetings'] );
		}
		if ( ! OSB_Settings::feature( 'invoices' ) ) {
			unset( $tabs['invoices'] );
		}
		return $tabs;
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( self::tabs()[ $tab ] ) ? $tab : 'general';
	}

	/**
	 * Headings the settings tabs are grouped under.
	 *
	 * @return array
	 */
	public static function tab_groups() {
		return array(
			'business' => __( 'Your business', 'osam-bookings' ),
			'form'     => __( 'Booking form', 'osam-bookings' ),
			'connect'  => __( 'Connections', 'osam-bookings' ),
			'advanced' => __( 'Advanced', 'osam-bookings' ),
		);
	}

	/**
	 * One field.
	 *
	 * @param string $name  Name.
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 * @param string $type  Type.
	 * @param array  $opts  Options (+ help, class).
	 */
	private static function field( $name, $label, $value, $type = 'text', $opts = array() ) {
		$help  = isset( $opts['help'] ) ? $opts['help'] : '';
		$class = isset( $opts['class'] ) ? $opts['class'] : '';
		unset( $opts['help'], $opts['class'] );
		$id = 'osb_' . preg_replace( '/[^a-z0-9_]/i', '_', $name );
		if ( 'checkbox' === $type ) {
			echo '<div class="osb-field ' . esc_attr( $class ) . '"><label class="osb-switch"><input type="hidden" name="' . esc_attr( $name ) . '" value="0"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( ! empty( $value ), true, false ) . '><span></span>' . esc_html( $label ) . '</label>' . ( $help ? '<p class="osb-help">' . wp_kses_post( $help ) . '</p>' : '' ) . '</div>';
			return;
		}
		echo '<div class="osb-field ' . esc_attr( $class ) . '"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		OSB_Admin::input( $name, $value, $type, $opts, $id );
		if ( $help ) {
			echo '<p class="osb-help">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Page.
	 */
	public static function page() {
		$tab = self::current_tab();
		$s   = OSB_Settings::all();
		OSB_Admin::open( __( 'Settings', 'osam-bookings' ), array( 'actions' => array( array( __( 'Run setup wizard', 'osam-bookings' ), admin_url( 'admin.php?page=osb-setup' ), 'secondary', 'dashicons-admin-tools' ) ) ) );
		?>
		<div class="osb-settings">
			<nav class="osb-settings__nav">
				<?php
				$heads = self::tab_groups();
				$last  = '';
				foreach ( self::tabs() as $key => $t ) :
					$g = isset( $t[2] ) ? $t[2] : '';
					if ( $g !== $last && isset( $heads[ $g ] ) ) :
						$last = $g;
						?>
						<span class="osb-settings__navhead"><?php echo esc_html( $heads[ $g ] ); ?></span>
					<?php endif; ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-settings&tab=' . $key ) ); ?>" class="<?php echo $tab === $key ? 'is-active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><span class="dashicons <?php echo esc_attr( $t[1] ); ?>"></span><?php echo esc_html( $t[0] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="osb-settings__body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form" id="osb-settings-form">
					<?php wp_nonce_field( 'osb_save_settings' ); ?>
					<input type="hidden" name="action" value="osb_save_settings">
					<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
					<?php call_user_func( array( __CLASS__, 'tab_' . $tab ), $s ); ?>
					<div class="osb-savebar"><span class="osb-savebar__dirty" hidden><?php esc_html_e( 'Unsaved changes', 'osam-bookings' ); ?></span><button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Save settings', 'osam-bookings' ); ?></button></div>
				</form>
				<?php if ( 'emails' === $tab ) : ?>
					<?php self::test_email_form(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Business type picker (also used by the wizard).
	 *
	 * @param string $current solo|team.
	 */
	public static function mode_picker( $current ) {
		?>
		<div class="osb-bigchoice">
			<label>
				<input type="radio" name="staff_mode" value="solo" <?php checked( 'solo', $current ); ?>>
				<span>
					<span class="dashicons dashicons-admin-users"></span>
					<strong><?php esc_html_e( 'Just me', 'osam-bookings' ); ?></strong>
					<small><?php esc_html_e( 'I work on my own. One diary, no “choose staff” step, and no staff to manage.', 'osam-bookings' ); ?></small>
				</span>
			</label>
			<label>
				<input type="radio" name="staff_mode" value="team" <?php checked( 'team', $current ); ?>>
				<span>
					<span class="dashicons dashicons-groups"></span>
					<strong><?php esc_html_e( 'A team', 'osam-bookings' ); ?></strong>
					<small><?php esc_html_e( 'Several people, rooms or resources, each with their own hours. Customers can pick who they see.', 'osam-bookings' ); ?></small>
				</span>
			</label>
		</div>
		<?php
	}

	/**
	 * Features tab — one switch per optional part of the plugin.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_features( $s ) {
		$links = array(
			'events'        => array( admin_url( 'admin.php?page=osb-events' ), __( 'Manage events', 'osam-bookings' ) ),
			'courses'       => array( admin_url( 'admin.php?page=osb-courses' ), __( 'Manage courses', 'osam-bookings' ) ),
			'waitlist'      => array( admin_url( 'admin.php?page=osb-waitlist' ), __( 'View waitlist', 'osam-bookings' ) ),
			'coupons'       => array( admin_url( 'admin.php?page=osb-coupons' ), __( 'Manage codes', 'osam-bookings' ) ),
			'reschedule'    => array( admin_url( 'admin.php?page=osb-settings&tab=general' ), __( 'Set the cut-off', 'osam-bookings' ) ),
			'followup'      => array( admin_url( 'admin.php?page=osb-settings&tab=emails#osb-followup' ), __( 'Edit the email', 'osam-bookings' ) ),
			'calendar_sync' => array( admin_url( 'admin.php?page=osb-settings&tab=calendars' ), __( 'Set up calendar sync', 'osam-bookings' ) ),
			'meetings'      => array( admin_url( 'admin.php?page=osb-settings&tab=meetings' ), __( 'Set up Zoom, Meet & Teams', 'osam-bookings' ) ),
			'customers'     => array( admin_url( 'admin.php?page=osb-customers' ), __( 'View customers', 'osam-bookings' ) ),
			'reports'       => array( admin_url( 'admin.php?page=osb-reports' ), __( 'View reports', 'osam-bookings' ) ),
			'invoices'      => array( admin_url( 'admin.php?page=osb-settings&tab=invoices' ), __( 'Set up invoices', 'osam-bookings' ) ),
			'restaurant'    => array( admin_url( 'admin.php?page=osb-floor' ), __( 'Set up tables & floor plan', 'osam-bookings' ) ),
			'resources'     => array( admin_url( 'admin.php?page=osb-resources' ), __( 'Add resources', 'osam-bookings' ) ),
		);
		?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Features', 'osam-bookings' ), __( 'Switch off anything this site doesn’t use. It disappears from the menus, settings, booking form, shortcodes and Elementor. Nothing is deleted — switch it back on and everything is where you left it.', 'osam-bookings' ) ); ?>
			<div class="osb-features">
				<?php
				foreach ( OSB_Settings::features() as $key => $f ) :
					$on   = OSB_Settings::feature( $key );
					$raw  = 'followup' === $f[3] ? ! empty( $s['emails']['customer_followup']['enabled'] ) : ! empty( $s[ $f[3] ] );
					$note = '';
					if ( 'waitlist' === $key && $raw && ! $on ) {
						$note = __( 'Needs events or courses switched on.', 'osam-bookings' );
					}
					if ( 'calendar_sync' === $key && $on && ! array_filter( array_keys( OSB_Calendar_Sync::providers() ), array( 'OSB_Calendar_Sync', 'configured' ) ) ) {
						$note = __( 'Not set up yet — add your Google or Microsoft app details.', 'osam-bookings' );
					}
					?>
					<label class="osb-feature<?php echo $raw ? ' is-on' : ''; ?>">
						<span class="osb-feature__icon dashicons <?php echo esc_attr( $f[2] ); ?>"></span>
						<span class="osb-feature__body">
							<strong><?php echo esc_html( $f[0] ); ?></strong>
							<small><?php echo esc_html( $f[1] ); ?></small>
							<?php if ( $note ) : ?><em class="osb-feature__note"><?php echo esc_html( $note ); ?></em><?php endif; ?>
							<?php if ( $on && isset( $links[ $key ] ) ) : ?><a class="osb-feature__link" href="<?php echo esc_url( $links[ $key ][0] ); ?>"><?php echo esc_html( $links[ $key ][1] ); ?> →</a><?php endif; ?>
						</span>
						<span class="osb-switch osb-feature__switch"><input type="hidden" name="feature_<?php echo esc_attr( $key ); ?>" value="0"><input type="checkbox" name="feature_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $raw ); ?> data-osb-feature><span></span></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Subscription tab — WHMCS billing status.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_subscription( $s ) {
		$on     = OSB_Licence::enabled();
		$state  = OSB_Licence::state();
		$locked = OSB_Licence::locked();
		$labels = array(
			'active'     => array( __( 'Active', 'osam-bookings' ), 'confirmed' ),
			'overdue'    => array( __( 'Payment overdue', 'osam-bookings' ), 'pending' ),
			'suspended'  => array( __( 'Suspended — bookings switched off', 'osam-bookings' ), 'cancelled' ),
			'terminated' => array( __( 'Terminated — bookings switched off', 'osam-bookings' ), 'cancelled' ),
		);
		$l      = isset( $labels[ $state['status'] ] ) ? $labels[ $state['status'] ] : array( $state['status'], 'neutral' );
		$ago    = static function ( $t ) {
			/* translators: %s time ago */
			return $t ? sprintf( __( '%s ago', 'osam-bookings' ), human_time_diff( (int) $t ) ) : __( 'never', 'osam-bookings' );
		};
		?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'OSAM subscription', 'osam-bookings' ), __( 'Links this site to OSAM’s billing (WHMCS). If the subscription is suspended for non-payment, new bookings are switched off; they come back on by themselves once it’s paid. Nothing is ever deleted.', 'osam-bookings' ) ); ?>
			<?php if ( ! $on ) : ?>
				<p><span class="osb-pill osb-pill--neutral"><?php esc_html_e( 'Not linked', 'osam-bookings' ); ?></span> <span class="osb-muted"><?php esc_html_e( 'Add the subscription key OSAM gave you to link this site. Without one, bookings always stay on.', 'osam-bookings' ); ?></span></p>
			<?php else : ?>
				<dl class="osb-dl">
					<dt><?php esc_html_e( 'Status', 'osam-bookings' ); ?></dt><dd><span class="osb-pill osb-pill--<?php echo esc_attr( $l[1] ); ?>"><?php echo esc_html( $l[0] ); ?></span><?php echo OSB_Licence::full_off() ? ' <span class="osb-muted">' . esc_html__( '(admin screens and booking emails paused too)', 'osam-bookings' ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
					<?php if ( $state['message'] ) : ?><dt><?php esc_html_e( 'Message from billing', 'osam-bookings' ); ?></dt><dd><?php echo esc_html( $state['message'] ); ?></dd><?php endif; ?>
					<?php if ( $state['grace_until'] ) : ?><dt><?php esc_html_e( 'Switches off on', 'osam-bookings' ); ?></dt><dd><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $state['grace_until'] . ' 12:00:00' ) ) ); ?></dd><?php endif; ?>
					<?php if ( $state['invoice_url'] ) : ?><dt><?php esc_html_e( 'Invoice', 'osam-bookings' ); ?></dt><dd><a href="<?php echo esc_url( $state['invoice_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View invoice', 'osam-bookings' ); ?> ↗</a></dd><?php endif; ?>
					<dt><?php esc_html_e( 'Last checked', 'osam-bookings' ); ?></dt><dd><?php echo esc_html( $ago( $state['checked'] ) ); ?><?php echo $state['contacted'] && $state['contacted'] !== $state['checked'] ? ' · ' . esc_html( sprintf( /* translators: %s time */ __( 'last answer %s', 'osam-bookings' ), $ago( $state['contacted'] ) ) ) : ''; ?></dd>
					<?php if ( $state['error'] ) : ?><dt><?php esc_html_e( 'Last problem', 'osam-bookings' ); ?></dt><dd class="osb-muted"><?php echo esc_html( $state['error'] ); ?> <?php esc_html_e( '(the status above is kept until billing can be reached)', 'osam-bookings' ); ?></dd><?php endif; ?>
				</dl>
				<p><a class="osb-btn osb-btn--ghost osb-btn--sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=osb_licence_check' ), 'osb_licence_check' ) ); ?>"><span class="dashicons dashicons-update"></span><?php esc_html_e( 'Check now', 'osam-bookings' ); ?></a></p>
			<?php endif; ?>
		</div>

		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Connection', 'osam-bookings' ), $locked ? __( 'Set in wp-config.php, so it can’t be changed here.', 'osam-bookings' ) : __( 'OSAM fills these in — you don’t need to change anything here.', 'osam-bookings' ) ); ?>
			<details class="osb-advanced" <?php echo $on ? '' : 'open'; ?>>
			<summary><?php esc_html_e( 'Connection details (for OSAM)', 'osam-bookings' ); ?></summary>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="osb_lk"><?php esc_html_e( 'Subscription key', 'osam-bookings' ); ?></label></th><td>
					<?php if ( $locked ) : ?><code>OSB_LICENCE_KEY</code><?php else : ?><input type="password" id="osb_lk" name="licence_key" class="regular-text" autocomplete="new-password" value="<?php echo $on ? '••••••••' : ''; ?>"><?php endif; ?>
					<p class="description"><?php esc_html_e( 'A long random secret, unique to this site, that OSAM’s billing also holds. It signs every message both ways.', 'osam-bookings' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="osb_ls"><?php esc_html_e( 'WHMCS service ID', 'osam-bookings' ); ?></label></th><td>
					<?php if ( $locked ) : ?><code><?php echo esc_html( OSB_Licence::service() ? OSB_Licence::service() : '—' ); ?></code><?php else : ?><input type="text" id="osb_ls" name="licence_service" class="regular-text" value="<?php echo esc_attr( $s['licence_service'] ); ?>"><?php endif; ?>
					<p class="description"><?php esc_html_e( 'Optional. Sent with each check to help billing find the right service.', 'osam-bookings' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="osb_lu"><?php esc_html_e( 'Status address', 'osam-bookings' ); ?></label></th><td>
					<code id="osb_lu"><?php echo esc_html( OSB_Licence::url() ); ?></code>
					<p class="description"><?php echo OSB_Licence::url_overridden() ? esc_html__( 'Set by OSB_LICENCE_URL in wp-config.php (for testing). Remove it to use OSAM’s billing again.', 'osam-bookings' ) : esc_html__( 'OSAM’s billing. This site asks it for its status hourly (every 10 minutes while overdue or suspended). It’s fixed and can’t be changed here.', 'osam-bookings' ); ?></p>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Webhook for billing', 'osam-bookings' ); ?></th><td>
					<code><?php echo esc_html( OSB_Licence::webhook_url() ); ?></code> <button type="button" class="osb-iconbtn osb-copy" data-copy="<?php echo esc_attr( OSB_Licence::webhook_url() ); ?>" title="<?php esc_attr_e( 'Copy', 'osam-bookings' ); ?>"><span class="dashicons dashicons-admin-page"></span></button>
					<p class="description"><?php esc_html_e( 'Give this to whoever runs WHMCS. Billing can call it the moment a service is suspended or paid, instead of waiting for the next check.', 'osam-bookings' ); ?></p>
				</td></tr>
			</table>
			<details class="osb-advanced"><summary><?php esc_html_e( 'Lock these settings in wp-config.php', 'osam-bookings' ); ?></summary>
				<pre class="osb-code"><code>define( 'OSB_LICENCE_KEY', '<?php esc_html_e( 'the-key-from-OSAM', 'osam-bookings' ); ?>' );
define( 'OSB_LICENCE_SERVICE', '<?php echo esc_html( $s['licence_service'] ? $s['licence_service'] : '123' ); ?>' );          // optional</code></pre>
			</details>
			</details>
		</div>

		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'While bookings are switched off', 'osam-bookings' ), __( 'Booking forms are replaced by this message. Customers can still open the link in their booking emails to view or cancel what they already have. Bookings already made stay in the system.', 'osam-bookings' ) ); ?>
			<textarea name="licence_customer_message" rows="2" class="large-text" placeholder="<?php echo esc_attr( __( 'Online booking is temporarily unavailable. Please contact us to book.', 'osam-bookings' ) ); ?>"><?php echo esc_textarea( (string) $s['licence_customer_message'] ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * Online meetings tab.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_meetings( $s ) {
		$ready  = OSB_Meetings::zoom_ready();
		$errors = OSB_Meetings::errors();
		?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'How online bookings work', 'osam-bookings' ), __( 'Choose “Online” on any service, event or course and pick how the link is made. Customers get the link in their confirmation and reminder emails, their calendar invite and their booking page.', 'osam-bookings' ) ); ?>
			<ul class="osb-ticks">
				<li><strong><?php esc_html_e( 'The same link for everyone', 'osam-bookings' ); ?></strong> — <?php esc_html_e( 'paste your personal meeting room or webinar link on the service. No setup needed.', 'osam-bookings' ); ?></li>
				<li><strong>Zoom</strong> — <?php esc_html_e( 'a new meeting for each appointment, one per event, and one recurring meeting per course intake. Connect Zoom below.', 'osam-bookings' ); ?></li>
				<li><strong><?php esc_html_e( 'Google Meet / Microsoft Teams', 'osam-bookings' ); ?></strong> — <?php esc_html_e( 'added to the entry in the staff member’s own calendar, so they need their calendar connected (Staff → Calendar sync) with “Add bookings to my calendar” on. Teams needs a work or school Microsoft 365 account.', 'osam-bookings' ); ?></li>
			</ul>
		</div>

		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Zoom', 'osam-bookings' ), $ready ? __( 'Connected. Use “Test” to check the details still work.', 'osam-bookings' ) : __( 'Create a Server-to-Server OAuth app in the Zoom App Marketplace and paste its details here.', 'osam-bookings' ) ); ?>
			<details class="osb-steps" <?php echo $ready ? '' : 'open'; ?>>
				<summary><?php esc_html_e( 'How to get these', 'osam-bookings' ); ?></summary>
				<ol>
					<li><?php echo wp_kses_post( __( 'Go to <a href="https://marketplace.zoom.us/develop/create" target="_blank" rel="noopener">marketplace.zoom.us → Develop → Build App</a> and choose <strong>Server-to-Server OAuth</strong>.', 'osam-bookings' ) ); ?></li>
					<li><?php echo wp_kses_post( __( 'Under <strong>Scopes</strong>, add <code>meeting:write:meeting:admin</code>, <code>meeting:update:meeting:admin</code> and <code>meeting:delete:meeting:admin</code> (older apps: <code>meeting:write:admin</code>).', 'osam-bookings' ) ); ?></li>
					<li><?php echo wp_kses_post( __( '<strong>Activate</strong> the app, then copy the Account ID, Client ID and Client secret below. Meetings are created on the account owner’s Zoom.', 'osam-bookings' ) ); ?></li>
				</ol>
			</details>
			<div class="osb-fieldgrid">
				<?php
				self::field( 'zoom_account_id', __( 'Account ID', 'osam-bookings' ), $s['zoom_account_id'], 'text', array( 'class' => 'osb-field--third', 'autocomplete' => 'off' ) );
				self::field( 'zoom_client_id', __( 'Client ID', 'osam-bookings' ), $s['zoom_client_id'], 'text', array( 'class' => 'osb-field--third', 'autocomplete' => 'off' ) );
				self::field( 'zoom_client_secret', __( 'Client secret', 'osam-bookings' ), $s['zoom_client_secret'] ? '••••••••' : '', 'password', array( 'class' => 'osb-field--third', 'help' => $s['zoom_client_secret'] ? __( 'Saved. Leave as is to keep it.', 'osam-bookings' ) : '' ) );
				?>
			</div>
			<?php if ( $ready ) : ?>
				<p><a class="osb-btn osb-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=osb_zoom_test' ), 'osb_zoom_test' ) ); ?>"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e( 'Test Zoom connection', 'osam-bookings' ); ?></a></p>
			<?php endif; ?>
		</div>

		<?php if ( $errors ) : ?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Recent problems', 'osam-bookings' ), __( 'Bookings that should have had a link but didn’t get one. You can add a link by hand on the booking.', 'osam-bookings' ) ); ?>
			<table class="osb-table">
				<tbody>
				<?php foreach ( array_slice( $errors, 0, 8 ) as $e ) : ?>
					<tr><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . (int) $e['booking'] ) ); ?>">#<?php echo (int) $e['booking']; ?></a></td><td><?php echo esc_html( mysql2date( 'j M, ' . get_option( 'time_format' ), $e['time'] ) ); ?></td><td><?php echo esc_html( $e['message'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * General tab.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_general( $s ) {
		$pages = array( 0 => __( '— Select a page —', 'osam-bookings' ) );
		foreach ( get_pages( array( 'post_status' => 'publish' ) ) as $p ) {
			$pages[ $p->ID ] = $p->post_title;
		}
		$currencies = array();
		foreach ( OSB_Settings::currencies() as $code => $sym ) {
			$currencies[ $code ] = $code . ' (' . trim( $sym ) . ')';
		}
		?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Business type', 'osam-bookings' ), __( 'You can change this later — your hours and services are kept either way.', 'osam-bookings' ) ); ?>
			<?php self::mode_picker( $s['staff_mode'] ); ?>
		</div>

		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Booking rules', 'osam-bookings' ), __( 'These apply to online bookings only. From the admin you can book anything at any time.', 'osam-bookings' ) ); ?>
			<div class="osb-fieldgrid">
				<?php
				self::field( 'slot_interval', __( 'Offer a start time every', 'osam-bookings' ), $s['slot_interval'], 'select', array( 'class' => 'osb-field--half', 0 => __( 'Length of the service', 'osam-bookings' ), 10 => '10 min', 15 => '15 min', 20 => '20 min', 30 => '30 min', 60 => '60 min' ) );
				self::field( 'min_notice_hours', __( 'Minimum notice (hours)', 'osam-bookings' ), $s['min_notice_hours'], 'number', array( 'class' => 'osb-field--half', 'min' => 0, 'step' => '0.5', 'help' => __( 'How far ahead customers must book.', 'osam-bookings' ) ) );
				self::field( 'max_advance_days', __( 'Bookable up to (days ahead)', 'osam-bookings' ), $s['max_advance_days'], 'number', array( 'class' => 'osb-field--half', 'min' => 1 ) );
				self::field( 'pending_payment_minutes', __( 'Hold unpaid online bookings for (minutes)', 'osam-bookings' ), $s['pending_payment_minutes'], 'number', array( 'class' => 'osb-field--half', 'min' => 15, 'help' => __( 'Released automatically if not paid (minimum 35).', 'osam-bookings' ) ) );
				self::field(
					'free_status',
					__( 'Free bookings are', 'osam-bookings' ),
					$s['free_status'],
					'select',
					array(
						'class'     => 'osb-field--half',
						'confirmed' => __( 'Confirmed automatically', 'osam-bookings' ),
						'pending'   => __( 'Held until I approve them', 'osam-bookings' ),
					)
				);
				self::field( 'cancel_cutoff_hours', __( 'Customers can cancel up to (hours before)', 'osam-bookings' ), $s['cancel_cutoff_hours'], 'number', array( 'class' => 'osb-field--half', 'min' => 0 ) );
				if ( OSB_Settings::feature( 'waitlist' ) ) {
					self::field( 'waitlist_hold_hours', __( 'Hold waitlist places for (hours)', 'osam-bookings' ), $s['waitlist_hold_hours'], 'number', array( 'class' => 'osb-field--half', 'min' => 1 ) );
				}
				if ( OSB_Settings::feature( 'reschedule' ) ) {
					self::field( 'reschedule_cutoff_hours', __( 'Customers can reschedule up to (hours before)', 'osam-bookings' ), $s['reschedule_cutoff_hours'], 'number', array( 'class' => 'osb-field--half', 'min' => 0 ) );
				}
				?>
			</div>
			<?php
			self::field( 'allow_customer_cancel', __( 'Let customers cancel from the link in their email', 'osam-bookings' ), $s['allow_customer_cancel'], 'checkbox' );
			if ( ! OSB_DB::is_solo() ) {
				self::field( 'show_staff_step', __( 'Let customers choose who they book with', 'osam-bookings' ), $s['show_staff_step'], 'checkbox' );
				self::field( 'any_staff_option', __( 'Offer an “Any available” option', 'osam-bookings' ), $s['any_staff_option'], 'checkbox' );
			}
			self::field( 'terms_text', __( 'Terms checkbox', 'osam-bookings' ), $s['terms_text'], 'textarea', array( 'placeholder' => 'I agree to the <a href="/terms">cancellation policy</a>', 'help' => __( 'Optional. If set, customers must tick it to book. Links allowed.', 'osam-bookings' ) ) );
			?>
		</div>

		<?php if ( OSB_Settings::feature( 'repeat' ) ) : ?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Repeat bookings', 'osam-bookings' ), __( 'What customers can make regular. Staff can always repeat a booking, and events can be copied to later dates from the event.', 'osam-bookings' ) ); ?>
			<div class="osb-field"><label><?php esc_html_e( 'Customers can repeat', 'osam-bookings' ); ?></label>
				<?php
				$types = array( 'service' => __( 'Appointments', 'osam-bookings' ) );
				if ( OSB_Settings::feature( 'resources' ) ) {
					$types['resource'] = __( 'Resources', 'osam-bookings' );
				}
				if ( OSB_Settings::feature( 'restaurant' ) ) {
					$types['table'] = __( 'Restaurant tables', 'osam-bookings' );
				}
				OSB_Admin_Custom_Emails::chips( 'repeat_types', $types, (array) $s['repeat_types'] );
				?>
			</div>
			<div class="osb-field"><label><?php esc_html_e( 'How often', 'osam-bookings' ); ?></label><?php OSB_Admin_Custom_Emails::chips( 'repeat_freqs', OSB_Repeat::freqs(), (array) $s['repeat_freqs'] ); ?></div>
			<div class="osb-fieldgrid"><?php self::field( 'repeat_max', __( 'Most dates in one go', 'osam-bookings' ), $s['repeat_max'], 'number', array( 'class' => 'osb-field--quarter', 'min' => 2, 'max' => 52 ) ); ?></div>
			<p class="osb-help"><?php esc_html_e( 'Customers pay for every date at once. Dates that aren’t free are skipped and shown before they pay. Tables can’t be repeated online while table bookings need a card on file.', 'osam-bookings' ); ?></p>
		</div>
		<?php endif; ?>

		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Site', 'osam-bookings' ) ); ?>
			<div class="osb-fieldgrid">
				<?php
				self::field( 'booking_page', __( 'Booking page', 'osam-bookings' ), $s['booking_page'], 'select', $pages + array( 'class' => 'osb-field--half', 'help' => __( 'Where “view or cancel your booking” links open. It needs the booking form on it.', 'osam-bookings' ) ) );
				self::field( 'portal_page', __( 'My bookings page', 'osam-bookings' ), $s['portal_page'], 'select', $pages + array( 'class' => 'osb-field--half', 'help' => __( 'Where customers sign in to see and manage their bookings. It needs [osam_my_bookings] on it.', 'osam-bookings' ) ) );
				self::field( 'currency', __( 'Currency', 'osam-bookings' ), $s['currency'], 'select', $currencies + array( 'class' => 'osb-field--quarter' ) );
				self::field(
					'currency_position',
					__( 'Symbol', 'osam-bookings' ),
					$s['currency_position'],
					'select',
					array(
						'class'  => 'osb-field--quarter',
						'before' => '£25.00',
						'after'  => '25.00 £',
					)
				);
				?>
			</div>
			<?php self::field( 'delete_data_on_uninstall', __( 'Delete all booking data if the plugin is deleted', 'osam-bookings' ), $s['delete_data_on_uninstall'], 'checkbox' ); ?>
		</div>

		<?php
		list( $tone, $status ) = OSB_Updater::status();
		$icons                 = array(
			'ok'    => 'yes-alt',
			'warn'  => 'update',
			'error' => 'warning',
			'muted' => 'info-outline',
		);
		$check                 = wp_nonce_url( admin_url( 'admin-post.php?action=osb_check_updates' ), 'osb_check_updates' );
		?>
		<div class="osb-card" id="osb-updates">
			<?php OSB_Admin::card_head( __( 'Updates', 'osam-bookings' ), __( 'New versions come straight from OSAM and appear under Dashboard → Updates like any other plugin.', 'osam-bookings' ) ); ?>
			<div class="osb-alert osb-alert--<?php echo esc_attr( 'error' === $tone ? 'warn' : ( 'muted' === $tone ? 'info' : $tone ) ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $icons[ $tone ] ); ?>"></span>
				<div>
					<?php /* translators: %s version */ echo '<strong>' . esc_html( sprintf( __( 'Installed: %s', 'osam-bookings' ), OSB_VERSION ) ) . '</strong> · ' . esc_html( $status ); ?>
					<?php if ( current_user_can( 'update_plugins' ) && ( ! defined( 'OSB_UPDATES' ) || OSB_UPDATES ) ) : ?>
						<p>
							<a class="osb-btn osb-btn--sm" href="<?php echo esc_url( $check ); ?>"><?php esc_html_e( 'Check for updates now', 'osam-bookings' ); ?></a>
							<?php if ( 'warn' === $tone ) : ?>
								<a class="osb-btn osb-btn--primary osb-btn--sm" href="<?php echo esc_url( self_admin_url( 'update-core.php' ) ); ?>"><?php esc_html_e( 'Go to updates', 'osam-bookings' ); ?></a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>
			</div>
			<?php self::field( 'auto_update', __( 'Install updates automatically', 'osam-bookings' ), $s['auto_update'], 'checkbox', array( 'help' => __( 'WordPress installs new versions by itself, usually within 12 hours of release. Turn off to update by hand from Dashboard → Updates.', 'osam-bookings' ) ) ); ?>
		</div>
		<?php
	}

	/**
	 * Calendar sync tab.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_calendars( $s ) {
		OSB_Admin_Calendars::settings_tab(
			$s,
			static function ( ...$args ) {
				self::field( ...$args );
			}
		);
	}

	/**
	 * Payments tab.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_payments( $s ) {
		$icons = array(
			'onsite' => 'dashicons-store',
			'stripe' => 'dashicons-bank',
			'paypal' => 'dashicons-money',
			'woo'    => 'dashicons-cart',
		);
		foreach ( osb()->payments->all() as $id => $gateway ) {
			$on = ! empty( $s[ $id . '_enabled' ] );
			?>
			<div class="osb-card osb-gateway <?php echo $on ? 'is-on' : ''; ?>">
				<div class="osb-gateway__head">
					<span class="osb-gateway__icon dashicons <?php echo esc_attr( isset( $icons[ $id ] ) ? $icons[ $id ] : 'dashicons-money-alt' ); ?>"></span>
					<div>
						<h2><?php echo esc_html( $gateway->admin_name() ); ?></h2>
						<p>
						<?php
						if ( 'woo' === $id && ! $gateway->is_configured() ) {
							esc_html_e( 'Install and activate WooCommerce to use this.', 'osam-bookings' );
						} elseif ( $on && ! $gateway->is_configured() ) {
							echo '<span class="osb-pill osb-pill--warn">' . esc_html__( 'Needs credentials', 'osam-bookings' ) . '</span>';
						} elseif ( $gateway->is_enabled() ) {
							echo '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'Active', 'osam-bookings' ) . '</span>';
						} else {
							esc_html_e( 'Off', 'osam-bookings' );
						}
						?>
						</p>
					</div>
					<label class="osb-switch osb-switch--lg" title="<?php esc_attr_e( 'Enable', 'osam-bookings' ); ?>"><input type="hidden" name="<?php echo esc_attr( $id ); ?>_enabled" value="0"><input type="checkbox" class="osb-gateway__toggle" name="<?php echo esc_attr( $id ); ?>_enabled" value="1" <?php checked( $on ); ?> <?php disabled( 'woo' === $id && ! $gateway->is_configured() ); ?>><span></span></label>
				</div>
				<div class="osb-gateway__body">
					<?php
					if ( method_exists( $gateway, 'settings_panel' ) ) {
						$gateway->settings_panel( $s );
					}
					?>
					<div class="osb-fieldgrid">
					<?php
					foreach ( $gateway->settings_fields() as $key => $def ) {
						$opts = isset( $def[2] ) && is_array( $def[2] ) ? $def[2] : array();
						if ( ! empty( $def[3] ) ) {
							$opts['help'] = $def[3];
						}
						$opts['class'] = in_array( $def[1], array( 'select' ), true ) ? 'osb-field--half' : '';
						self::field( $key, $def[0], isset( $s[ $key ] ) ? $s[ $key ] : '', $def[1], $opts );
					}
					?>
					</div>
				</div>
			</div>
			<?php
		}
		echo '<p class="osb-help">' . esc_html__( 'Each service, event and course can limit which of these are offered.', 'osam-bookings' ) . '</p>';
	}

	/**
	 * Invoices tab.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_invoices( $s ) {
		OSB_Admin_Invoices::settings_tab(
			$s,
			static function ( ...$args ) {
				self::field( ...$args );
			}
		);
	}

	/**
	 * Emails tab.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_emails( $s ) {
		$n_custom = count( OSB_Custom_Emails::all() );
		echo '<div class="osb-alert osb-alert--info"><span class="dashicons dashicons-email-alt2"></span><div>' . esc_html__( 'See every email at a glance, switch them on or off, and add your own for particular services, events or courses on the Emails page.', 'osam-bookings' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=osb-emails' ) ) . '">' . esc_html( $n_custom ? sprintf( /* translators: %d count */ _n( 'Emails (%d of your own) →', 'Emails (%d of your own) →', $n_custom, 'osam-bookings' ), $n_custom ) : __( 'Emails →', 'osam-bookings' ) ) . '</a></div></div>';
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var h=location.hash;if(h.indexOf("#osb-tpl-")===0){var d=document.querySelector(h);if(d){d.open=true;setTimeout(function(){d.scrollIntoView({block:"start"});},50);}}});</script>';
		$labels   = OSB_Settings::email_labels();
		$groups   = OSB_Settings::email_groups();
		$defaults = OSB_Settings::default_emails();
		$colors   = array(
			'email_accent'      => array( __( 'Buttons & links', 'osam-bookings' ), __( 'Also the accent stripe', 'osam-bookings' ) ),
			'email_button_text' => array( __( 'Button text', 'osam-bookings' ), '' ),
			'email_header_bg'   => array( __( 'Header background', 'osam-bookings' ), __( '“Colour band” header', 'osam-bookings' ) ),
			'email_header_text' => array( __( 'Header text', 'osam-bookings' ), __( 'When there’s no logo', 'osam-bookings' ) ),
			'email_page_bg'     => array( __( 'Page background', 'osam-bookings' ), '' ),
			'email_card_bg'     => array( __( 'Message background', 'osam-bookings' ), '' ),
			'email_panel_bg'    => array( __( 'Booking details box', 'osam-bookings' ), '' ),
			'email_text'        => array( __( 'Text', 'osam-bookings' ), '' ),
			'email_muted'       => array( __( 'Labels & secondary text', 'osam-bookings' ), '' ),
			'email_footer_text' => array( __( 'Footer text', 'osam-bookings' ), '' ),
		);
		$ds = OSB_Emails::design();
		?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Sender', 'osam-bookings' ) ); ?>
			<div class="osb-fieldgrid">
				<?php
				self::field( 'email_from_name', __( 'From name', 'osam-bookings' ), $s['email_from_name'], 'text', array( 'class' => 'osb-field--half' ) );
				self::field( 'email_from_address', __( 'From address', 'osam-bookings' ), $s['email_from_address'], 'email', array( 'class' => 'osb-field--half', 'help' => __( 'Use an address on this site’s domain with SPF/DKIM so emails aren’t marked as spam.', 'osam-bookings' ) ) );
				self::field( 'email_admin_recipients', __( 'Send admin emails to', 'osam-bookings' ), $s['email_admin_recipients'], 'text', array( 'class' => 'osb-field--half', 'help' => __( 'Separate several addresses with commas.', 'osam-bookings' ) ) );
				self::field( 'reminder_hours', __( 'Send reminders (hours before)', 'osam-bookings' ), $s['reminder_hours'], 'number', array( 'class' => 'osb-field--half', 'min' => 0, 'help' => __( '0 turns reminders off.', 'osam-bookings' ) ) );
				?>
			</div>
			<?php self::field( 'email_attach_ics', __( 'Attach a calendar file (.ics) to confirmations and reminders', 'osam-bookings' ), $s['email_attach_ics'], 'checkbox' ); ?>
		</div>

		<div class="osb-card" id="osb-followup">
			<?php OSB_Admin::card_head( __( 'After the visit', 'osam-bookings' ), __( 'A thank-you email with a button to leave a review. Switch it on in Settings → Features, or under “Customer — after-visit thank you” below.', 'osam-bookings' ) ); ?>
			<div class="osb-fieldgrid">
				<?php
				self::field( 'review_url', __( 'Review link', 'osam-bookings' ), $s['review_url'], 'url', array( 'class' => 'osb-field--half', 'placeholder' => 'https://g.page/r/…/review', 'help' => __( 'In Google Business Profile: “Ask for reviews” → copy the link. Facebook or Trustpilot links work too.', 'osam-bookings' ) ) );
				self::field( 'review_button_label', __( 'Button text', 'osam-bookings' ), $s['review_button_label'], 'text', array( 'class' => 'osb-field--quarter', 'placeholder' => __( 'Leave a review', 'osam-bookings' ) ) );
				self::field( 'followup_hours', __( 'Send (hours after it ends)', 'osam-bookings' ), $s['followup_hours'], 'number', array( 'class' => 'osb-field--quarter', 'min' => 0, 'step' => '0.5' ) );
				?>
			</div>
		</div>

		<div class="osb-emaildesigner" id="osb-email-design">
			<div class="osb-emaildesigner__controls">
				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Logo & header', 'osam-bookings' ) ); ?>
					<div class="osb-field"><label><?php esc_html_e( 'Logo', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'email_logo_id', (int) $s['email_logo_id'], 'image' ); ?>
						<p class="osb-help"><?php esc_html_e( 'PNG or JPG works best (some email apps don’t show SVG). Upload it at twice the display width so it stays sharp.', 'osam-bookings' ); ?></p>
						<?php if ( ! $s['email_logo_id'] && $s['email_logo'] ) : ?><input type="hidden" name="email_logo" value="<?php echo esc_attr( $s['email_logo'] ); ?>"><p class="osb-help"><?php esc_html_e( 'Currently using your old logo URL. Choose an image above to replace it.', 'osam-bookings' ); ?></p><?php endif; ?>
					</div>
					<div class="osb-field"><label for="osb_email_logo_width"><?php esc_html_e( 'Logo width', 'osam-bookings' ); ?> <output class="osb-range-out" for="osb_email_logo_width"><?php echo (int) $ds['logo_width']; ?>px</output></label><input type="range" id="osb_email_logo_width" name="email_logo_width" min="60" max="320" step="10" value="<?php echo (int) $ds['logo_width']; ?>" class="osb-range" data-unit="px"></div>
					<div class="osb-field"><label><?php esc_html_e( 'Header style', 'osam-bookings' ); ?></label>
						<div class="osb-designpick osb-headerpick">
							<?php
							$styles = array(
								'band'     => array( __( 'Colour band', 'osam-bookings' ), __( 'Logo on a coloured bar', 'osam-bookings' ) ),
								'clean'    => array( __( 'Clean', 'osam-bookings' ), __( 'White, with an accent line', 'osam-bookings' ) ),
								'centered' => array( __( 'Floating logo', 'osam-bookings' ), __( 'Logo above the message', 'osam-bookings' ) ),
							);
							foreach ( $styles as $key => $st ) :
								?>
								<label><input type="radio" name="email_header_style" value="<?php echo esc_attr( $key ); ?>" <?php checked( $ds['header_style'], $key ); ?>><span><i class="osb-headerpick__art osb-headerpick__art--<?php echo esc_attr( $key ); ?>"></i><strong><?php echo esc_html( $st[0] ); ?></strong><small><?php echo esc_html( $st[1] ); ?></small></span></label>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Colours', 'osam-bookings' ), __( 'Start from a theme, then fine-tune any colour.', 'osam-bookings' ) ); ?>
					<div class="osb-emailthemes">
						<?php
						foreach ( OSB_Emails::themes() as $key => $theme ) :
							$vals = array();
							foreach ( $theme[1] as $k => $v ) {
								$vals[ 'email_' . ( 'page_bg' === $k ? 'page_bg' : $k ) ] = $v;
							}
							?>
							<button type="button" class="osb-emailtheme" data-theme="<?php echo esc_attr( wp_json_encode( $vals ) ); ?>">
								<span class="osb-emailtheme__art" style="background:<?php echo esc_attr( $theme[1]['page_bg'] ); ?>"><i style="background:<?php echo esc_attr( $theme[1]['header_bg'] ); ?>"></i><b style="background:<?php echo esc_attr( $theme[1]['card_bg'] ); ?>"><em style="background:<?php echo esc_attr( $theme[1]['accent'] ); ?>"></em></b></span>
								<span><?php echo esc_html( $theme[0] ); ?></span>
							</button>
						<?php endforeach; ?>
						<button type="button" class="osb-emailtheme" data-theme-site="1" title="<?php esc_attr_e( 'Use the colours from Booking form design', 'osam-bookings' ); ?>">
							<span class="osb-emailtheme__art" style="background:#f4f5f7"><i style="background:<?php echo esc_attr( $s['primary_color'] ); ?>"></i><b style="background:#fff"><em style="background:<?php echo esc_attr( $s['primary_color'] ); ?>"></em></b></span>
							<span><?php esc_html_e( 'Match my form', 'osam-bookings' ); ?></span>
						</button>
					</div>
					<div class="osb-emailcolors" data-site-accent="<?php echo esc_attr( $s['primary_color'] ); ?>" data-site-text="<?php echo esc_attr( $s['text_color'] ); ?>">
						<?php foreach ( $colors as $key => $c ) : $short = substr( $key, 6 ); ?>
							<div class="osb-emailcolor">
								<input type="text" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $ds[ $short ] ); ?>" class="osb-color" data-default-color="<?php echo esc_attr( $ds[ $short ] ); ?>">
								<span><strong><?php echo esc_html( $c[0] ); ?></strong><?php echo $c[1] ? '<small>' . esc_html( $c[1] ) . '</small>' : ''; ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Text & footer', 'osam-bookings' ) ); ?>
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_email_font"><?php esc_html_e( 'Font', 'osam-bookings' ); ?></label>
							<select name="email_font" id="osb_email_font">
								<?php foreach ( OSB_Emails::fonts() as $key => $f ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['email_font'], $key ); ?> style="font-family:<?php echo esc_attr( $f[1] ); ?>"><?php echo esc_html( $f[0] ); ?></option><?php endforeach; ?>
							</select>
						</div>
						<div class="osb-field osb-field--half"><label for="osb_email_radius"><?php esc_html_e( 'Corner roundness', 'osam-bookings' ); ?> <output class="osb-range-out" for="osb_email_radius"><?php echo (int) $ds['radius']; ?>px</output></label><input type="range" id="osb_email_radius" name="email_radius" min="0" max="24" step="1" value="<?php echo (int) $ds['radius']; ?>" class="osb-range" data-unit="px"></div>
					</div>
					<?php
					self::field( 'email_footer', __( 'Footer', 'osam-bookings' ), $s['email_footer'], 'textarea', array( 'placeholder' => __( "Your business name\n12 High Street, Town, AB1 2CD · 01234 567890", 'osam-bookings' ), 'help' => __( 'Address, phone, opening hours… Links allowed.', 'osam-bookings' ) ) );
					self::field( 'email_footer_site_link', __( 'Show a link to the website in the footer', 'osam-bookings' ), $s['email_footer_site_link'], 'checkbox' );
					?>
				</div>
			</div>

			<div class="osb-emaildesigner__preview">
				<div class="osb-preview">
					<div class="osb-preview__bar">
						<span class="osb-preview__dots"><i></i><i></i><i></i></span>
						<select class="osb-emailpreview__pick" aria-label="<?php esc_attr_e( 'Email to preview', 'osam-bookings' ); ?>">
							<?php foreach ( $groups as $keys ) : foreach ( $keys as $key ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( 'customer_confirmed', $key ); ?>><?php echo esc_html( $labels[ $key ] ); ?></option><?php endforeach; endforeach; ?>
						</select>
						<span class="osb-preview__sizes"><button type="button" data-w="100%" class="is-active" aria-label="<?php esc_attr_e( 'Desktop', 'osam-bookings' ); ?>"><span class="dashicons dashicons-desktop"></span></button><button type="button" data-w="380px" aria-label="<?php esc_attr_e( 'Mobile', 'osam-bookings' ); ?>"><span class="dashicons dashicons-smartphone"></span></button></span>
					</div>
					<div class="osb-emailpreview__subject"><span><?php esc_html_e( 'Subject', 'osam-bookings' ); ?></span><strong></strong></div>
					<div class="osb-preview__stage osb-emailpreview__stage"><iframe class="osb-emailpreview__frame" title="<?php esc_attr_e( 'Email preview', 'osam-bookings' ); ?>" sandbox="allow-same-origin"></iframe></div>
					<p class="osb-help"><?php esc_html_e( 'Live preview using your latest booking (or sample details). Changes show here straight away — remember to save.', 'osam-bookings' ); ?></p>
				</div>
			</div>
		</div>

		<?php foreach ( $groups as $group => $keys ) : ?>
			<h3 class="osb-grouptitle"><?php echo esc_html( $group ); ?></h3>
			<?php
			foreach ( $keys as $key ) :
				$tpl = $s['emails'][ $key ];
				$def = isset( $defaults[ $key ] ) ? $defaults[ $key ] : $tpl;
				?>
				<details class="osb-card osb-email-tpl" id="osb-tpl-<?php echo esc_attr( $key ); ?>" data-key="<?php echo esc_attr( $key ); ?>" data-default-subject="<?php echo esc_attr( $def['subject'] ); ?>" data-default-body="<?php echo esc_attr( $def['body'] ); ?>">
					<summary>
						<span class="osb-email-tpl__title"><span class="dashicons dashicons-email"></span><?php $osb_t = preg_replace( '/^[^—]+—\s*/', '', $labels[ $key ] ); echo esc_html( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $osb_t, 0, 1 ) ) . mb_substr( $osb_t, 1 ) : ucfirst( $osb_t ) ); ?></span>
						<span class="osb-email-tpl__subject"><?php echo esc_html( $tpl['subject'] ); ?></span>
						<?php echo empty( $tpl['enabled'] ) ? '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Off', 'osam-bookings' ) . '</span>' : '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'On', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</summary>
					<div class="osb-email-tpl__body">
						<?php
						self::field( 'emails[' . $key . '][enabled]', __( 'Send this email', 'osam-bookings' ), $tpl['enabled'], 'checkbox' );
						self::field( 'emails[' . $key . '][subject]', __( 'Subject', 'osam-bookings' ), $tpl['subject'] );
						self::field( 'emails[' . $key . '][body]', __( 'Message (HTML)', 'osam-bookings' ), $tpl['body'], 'code' );
						?>
						<div class="osb-inline osb-email-tpl__tools">
							<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm" data-osb-email-preview="<?php echo esc_attr( $key ); ?>"><span class="dashicons dashicons-visibility"></span><?php esc_html_e( 'Preview', 'osam-bookings' ); ?></button>
							<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm" data-osb-email-reset><span class="dashicons dashicons-image-rotate"></span><?php esc_html_e( 'Restore the default wording', 'osam-bookings' ); ?></button>
						</div>
					</div>
				</details>
			<?php endforeach; ?>
		<?php endforeach; ?>

		<div class="osb-card osb-placeholders">
			<?php OSB_Admin::card_head( __( 'Placeholders', 'osam-bookings' ), __( 'Click to copy. Use them in subjects and messages. The “blocks” add ready-styled sections.', 'osam-bookings' ) ); ?>
			<p class="osb-tags__label"><?php esc_html_e( 'Blocks', 'osam-bookings' ); ?></p>
			<div class="osb-tags">
			<?php
			foreach ( array( '{details_card}', '{manage_button}', '{admin_button}', '{review_button}', '{meeting_button}', '{waitlist_button}', '{status_badge}', '{schedule}', '{fields_table}', '{access_details}', '{book_again_button}', '{invoice_button}', '{pay_button}' ) as $tag ) {
				echo '<button type="button" class="osb-tag osb-tag--block osb-copy" data-copy="' . esc_attr( $tag ) . '">' . esc_html( $tag ) . '</button>';
			}
			?>
			</div>
			<p class="osb-tags__label"><?php esc_html_e( 'Values', 'osam-bookings' ); ?></p>
			<div class="osb-tags">
			<?php
			$tags = array( '{customer_name}', '{customer_first_name}', '{customer_email}', '{customer_phone}', '{item_name}', '{staff_name}', '{staff_line}', '{location}', '{location_line}', '{date}', '{time}', '{end_time}', '{duration}', '{seats}', '{attendees}', '{amount}', '{discount}', '{payment_method}', '{payment_status}', '{status}', '{booking_id}', '{manage_url}', '{admin_url}', '{booking_url}', '{review_url}', '{meeting_link}', '{site_name}', '{site_url}', '{field:your_field_key}', '{intake_name}', '{session_count}', '{session_number}', '{session_date}', '{session_time}', '{session_end_time}', '{session_location}', '{session_note}', '{previous_when}', '{waitlist_expires}', '{covers}', '{tables}', '{noshow_fee}', '{resources}', '{booked_out}', '{hire}', '{access_code}', '{access_note}', '{invoice_number}', '{invoice_total}', '{invoice_date}', '{invoice_link}', '{pay_link}', '{amount_due}', '{credit_note_number}', '{credit_note_total}', '{credit_note_reason}' );
			foreach ( $tags as $tag ) {
				echo '<button type="button" class="osb-tag osb-copy" data-copy="' . esc_attr( $tag ) . '">' . esc_html( $tag ) . '</button>';
			}
			?>
			</div>
		</div>
		<?php
	}

	/**
	 * Test email form.
	 */
	private static function test_email_form() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form">
			<?php wp_nonce_field( 'osb_test_email' ); ?>
			<input type="hidden" name="action" value="osb_test_email">
			<?php OSB_Admin::card_head( __( 'Send a test email', 'osam-bookings' ), __( 'Uses your latest booking, or sample data. Save changes first.', 'osam-bookings' ) ); ?>
			<div class="osb-inline">
				<?php
				$labels = OSB_Settings::email_labels();
				if ( ! OSB_Settings::feature( 'courses' ) ) {
					unset( $labels['customer_session_reminder'], $labels['customer_schedule_changed'] );
				}
				OSB_Admin::input( 'template', 'customer_confirmed', 'select', $labels );
				?>
				<input type="email" name="to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
				<button class="osb-btn osb-btn--ghost"><span class="dashicons dashicons-email-alt"></span><?php esc_html_e( 'Send test', 'osam-bookings' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * "Ask this for…" picker on a form field.
	 *
	 * @param string $n   Input name prefix.
	 * @param array  $for Selected tokens.
	 */
	private static function show_for_picker( $n, array $for ) {
		static $opts = null;
		if ( null === $opts ) {
			$opts = array();
			$svc  = array( 'services' => __( 'All appointments', 'osam-bookings' ) );
			foreach ( OSB_DB::services() as $sv ) {
				$svc[ 's:' . $sv->id ] = $sv->name;
			}
			$opts[ __( 'Appointments', 'osam-bookings' ) ] = $svc;
			if ( OSB_Settings::feature( 'events' ) ) {
				$ev = array( 'events' => __( 'All events', 'osam-bookings' ) );
				foreach ( OSB_DB::upcoming_events( 50 ) as $e ) {
					$ev[ 'e:' . $e->id ] = $e->name . ' · ' . mysql2date( 'j M', $e->start_at );
				}
				$opts[ __( 'Events', 'osam-bookings' ) ] = $ev;
			}
			if ( OSB_Settings::feature( 'courses' ) ) {
				$co = array( 'courses' => __( 'All courses', 'osam-bookings' ) );
				foreach ( OSB_Courses::courses() as $c ) {
					$co[ 'c:' . $c->id ] = $c->name;
				}
				$opts[ __( 'Courses', 'osam-bookings' ) ] = $co;
			}
			if ( OSB_Settings::feature( 'restaurant' ) ) {
				$opts[ __( 'Restaurant', 'osam-bookings' ) ] = array( 'tables' => __( 'All table bookings', 'osam-bookings' ) );
			}
			if ( OSB_Settings::feature( 'resources' ) ) {
				$rs = array( 'resources' => __( 'All resources', 'osam-bookings' ) );
				foreach ( OSB_Resources::all() as $rr ) {
					$rs[ 'r:' . $rr->id ] = $rr->name;
				}
				$opts[ __( 'Resources', 'osam-bookings' ) ] = $rs;
			}
		}
		$names = array();
		foreach ( $opts as $group ) {
			foreach ( $group as $k => $label ) {
				if ( in_array( (string) $k, $for, true ) ) {
					$names[] = $label;
				}
			}
		}
		?>
		<details class="osb-field osb-showfor" <?php echo $for ? 'open' : ''; ?>>
			<summary><?php esc_html_e( 'Ask this for', 'osam-bookings' ); ?>: <strong class="osb-showfor__sum" data-all="<?php esc_attr_e( 'Every booking', 'osam-bookings' ); ?>"><?php echo esc_html( $names ? implode( ', ', $names ) : __( 'Every booking', 'osam-bookings' ) ); ?></strong></summary>
			<p class="osb-help"><?php esc_html_e( 'Leave everything unticked to ask it on every booking. Tick a group or individual items to only ask it for those.', 'osam-bookings' ); ?></p>
			<?php foreach ( $opts as $group => $items ) : ?>
				<div class="osb-showfor__group"><span><?php echo esc_html( $group ); ?></span>
					<div class="osb-chips-check">
						<?php foreach ( $items as $k => $label ) : ?><label class="<?php echo false === strpos( (string) $k, ':' ) ? 'is-all' : ''; ?>"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[show_for][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( (string) $k, $for, true ) ); ?>><span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</details>
		<?php
	}

	/**
	 * Form builder tab.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_form( $s ) {
		$types  = self::field_types();
		$fields = OSB_Settings::form_fields();
		?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Customer details', 'osam-bookings' ), __( 'Drag to reorder. Name and email are always required. Use a field’s key in emails as {field:key}.', 'osam-bookings' ) ); ?>
			<div class="osb-fieldlist osb-repeater" data-template="osb-field-tpl">
				<?php
				foreach ( $fields as $i => $f ) {
					self::field_row( $i, $f, $types, in_array( $f['key'], array( 'name', 'email' ), true ) );
				}
				?>
			</div>
			<button type="button" class="osb-btn osb-btn--ghost osb-row-add"><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add a field', 'osam-bookings' ); ?></button>
			<script type="text/template" id="osb-field-tpl">
				<?php self::field_row( '__i__', array( 'key' => '', 'label' => '', 'type' => 'text', 'required' => 0, 'options' => '', 'placeholder' => '', 'width' => 'full' ), $types, false, true ); ?>
			</script>
		</div>
		<?php
	}

	/**
	 * Field types.
	 *
	 * @return array
	 */
	private static function field_types() {
		return array(
			'text'     => __( 'Short text', 'osam-bookings' ),
			'email'    => __( 'Email', 'osam-bookings' ),
			'tel'      => __( 'Phone', 'osam-bookings' ),
			'textarea' => __( 'Paragraph', 'osam-bookings' ),
			'number'   => __( 'Number', 'osam-bookings' ),
			'date'     => __( 'Date', 'osam-bookings' ),
			'select'   => __( 'Dropdown', 'osam-bookings' ),
			'radio'    => __( 'Multiple choice', 'osam-bookings' ),
			'checkbox' => __( 'Tick box', 'osam-bookings' ),
		);
	}

	/**
	 * One form-builder row.
	 *
	 * @param int|string $i     Index.
	 * @param array      $f     Field.
	 * @param array      $types Types.
	 * @param bool       $core  Locked core field.
	 * @param bool       $open  Start expanded.
	 */
	private static function field_row( $i, $f, $types, $core, $open = false ) {
		$n = 'form_fields[' . $i . ']';
		$f = wp_parse_args( $f, array( 'width' => 'full', 'placeholder' => '', 'options' => '' ) );
		?>
		<div class="osb-fieldrow <?php echo $open ? 'is-open' : ''; ?>" data-type="<?php echo esc_attr( $f['type'] ); ?>">
			<div class="osb-fieldrow__head">
				<span class="osb-handle dashicons dashicons-menu" title="<?php esc_attr_e( 'Drag to reorder', 'osam-bookings' ); ?>"></span>
				<span class="osb-fieldrow__label"><?php echo esc_html( $f['label'] ? $f['label'] : __( 'New field', 'osam-bookings' ) ); ?></span>
				<span class="osb-fieldrow__type"><?php echo esc_html( isset( $types[ $f['type'] ] ) ? $types[ $f['type'] ] : $f['type'] ); ?></span>
				<?php if ( ! empty( $f['required'] ) || $core ) : ?><span class="osb-pill osb-pill--neutral"><?php esc_html_e( 'Required', 'osam-bookings' ); ?></span><?php endif; ?>
				<button type="button" class="osb-iconbtn osb-fieldrow__toggle" aria-label="<?php esc_attr_e( 'Edit field', 'osam-bookings' ); ?>"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
				<?php if ( ! $core ) : ?><button type="button" class="osb-iconbtn osb-iconbtn--danger osb-row-remove" aria-label="<?php esc_attr_e( 'Remove', 'osam-bookings' ); ?>"><span class="dashicons dashicons-trash"></span></button><?php endif; ?>
			</div>
			<div class="osb-fieldrow__body osb-fieldgrid">
				<div class="osb-field osb-field--half"><label><?php esc_html_e( 'Label', 'osam-bookings' ); ?></label><input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $f['label'] ); ?>" class="osb-field-label"></div>
				<div class="osb-field osb-field--quarter"><label><?php esc_html_e( 'Key', 'osam-bookings' ); ?></label><input type="text" name="<?php echo esc_attr( $n ); ?>[key]" value="<?php echo esc_attr( $f['key'] ); ?>" class="osb-field-key code" <?php echo $core ? 'readonly' : ''; ?>></div>
				<div class="osb-field osb-field--quarter"><label><?php esc_html_e( 'Type', 'osam-bookings' ); ?></label>
					<?php if ( $core ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $n ); ?>[type]" value="<?php echo esc_attr( $f['type'] ); ?>"><input type="text" value="<?php echo esc_attr( $types[ $f['type'] ] ); ?>" readonly>
					<?php else : ?>
						<select name="<?php echo esc_attr( $n ); ?>[type]" class="osb-field-type"><?php foreach ( $types as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['type'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
					<?php endif; ?>
				</div>
				<div class="osb-field osb-field--half"><label><?php esc_html_e( 'Placeholder', 'osam-bookings' ); ?></label><input type="text" name="<?php echo esc_attr( $n ); ?>[placeholder]" value="<?php echo esc_attr( $f['placeholder'] ); ?>"></div>
				<div class="osb-field osb-field--quarter"><label><?php esc_html_e( 'Width', 'osam-bookings' ); ?></label><select name="<?php echo esc_attr( $n ); ?>[width]"><option value="full" <?php selected( $f['width'], 'full' ); ?>><?php esc_html_e( 'Full', 'osam-bookings' ); ?></option><option value="half" <?php selected( $f['width'], 'half' ); ?>><?php esc_html_e( 'Half', 'osam-bookings' ); ?></option></select></div>
				<div class="osb-field osb-field--quarter osb-field--end"><label class="osb-switch"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[required]" value="1" <?php checked( ! empty( $f['required'] ) || $core ); ?> <?php disabled( $core ); ?>><span></span><?php esc_html_e( 'Required', 'osam-bookings' ); ?></label></div>
				<?php $osb_pp = 'name' === $f['key'] ? 1 : OSB_Settings::field_per_place( $f ); ?>
				<div class="osb-field osb-field--half osb-field--end"><label class="osb-switch" title="<?php esc_attr_e( 'When someone books more than one place, ask this for each person too.', 'osam-bookings' ); ?>"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[per_place]" value="1" <?php checked( $osb_pp ); ?> <?php disabled( 'name' === $f['key'] ); ?>><span></span><?php esc_html_e( 'Ask for each place', 'osam-bookings' ); ?></label></div>
				<div class="osb-field osb-field--options"><label><?php esc_html_e( 'Choices (one per line)', 'osam-bookings' ); ?></label><textarea name="<?php echo esc_attr( $n ); ?>[options]" rows="3"><?php echo esc_textarea( $f['options'] ); ?></textarea></div>
				<?php if ( ! $core ) : ?>
					<?php self::show_for_picker( $n, isset( $f['show_for'] ) ? (array) $f['show_for'] : array() ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Appearance tab with live preview.
	 *
	 * @param array $s Settings.
	 */
	private static function tab_appearance( $s ) {
		$choices  = OSB_Settings::display_choices();
		$defaults = OSB_Settings::default_labels();
		?>
		<div class="osb-designer">
			<div class="osb-designer__controls">
				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Style', 'osam-bookings' ) ); ?>
					<div class="osb-field"><label><?php esc_html_e( 'Design', 'osam-bookings' ); ?></label>
						<div class="osb-designpick">
							<?php foreach ( $choices['design'] as $key => $label ) : $parts = explode( ' — ', $label ); ?>
								<label><input type="radio" name="design" value="<?php echo esc_attr( $key ); ?>" <?php checked( $s['design'], $key ); ?> data-preview="class:osb-design-"><span><i class="osb-designpick__art osb-designpick__art--<?php echo esc_attr( $key ); ?>"></i><strong><?php echo esc_html( $parts[0] ); ?></strong><small><?php echo esc_html( isset( $parts[1] ) ? $parts[1] : '' ); ?></small></span></label>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="osb-fieldgrid">
						<?php
						self::field( 'button_shape', __( 'Buttons', 'osam-bookings' ), $s['button_shape'], 'select', $choices['button_shape'] + array( 'class' => 'osb-field--half' ) );
						self::field( 'density', __( 'Spacing', 'osam-bookings' ), $s['density'], 'select', $choices['density'] + array( 'class' => 'osb-field--half' ) );
						self::field( 'font_family', __( 'Font', 'osam-bookings' ), $s['font_family'], 'select', $choices['font_family'] + array( 'class' => 'osb-field--half' ) );
						self::field( 'font_size', __( 'Text size (px)', 'osam-bookings' ), $s['font_size'], 'number', array( 'class' => 'osb-field--half', 'min' => 12, 'max' => 22 ) );
						self::field( 'border_radius', __( 'Corner roundness (px)', 'osam-bookings' ), $s['border_radius'], 'number', array( 'class' => 'osb-field--half', 'min' => 0, 'max' => 32 ) );
						self::field( 'max_width', __( 'Max width (px, 0 = full)', 'osam-bookings' ), $s['max_width'], 'number', array( 'class' => 'osb-field--half', 'min' => 0, 'step' => 10 ) );
						?>
					</div>
				</div>

				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Colours', 'osam-bookings' ) ); ?>
					<div class="osb-swatches">
						<?php foreach ( array( '#161a40', '#0273b0', '#0c8599', '#2f9e44', '#e8590c', '#c2255c', '#7048e8', '#b08968' ) as $sw ) : ?>
							<button type="button" class="osb-swatchbtn" data-color="<?php echo esc_attr( $sw ); ?>" style="background:<?php echo esc_attr( $sw ); ?>" aria-label="<?php echo esc_attr( $sw ); ?>"></button>
						<?php endforeach; ?>
					</div>
					<div class="osb-fieldgrid">
						<?php
						self::field( 'primary_color', __( 'Accent', 'osam-bookings' ), $s['primary_color'], 'color', array( 'class' => 'osb-field--half' ) );
						self::field( 'on_accent_color', __( 'Text on accent', 'osam-bookings' ), $s['on_accent_color'], 'color', array( 'class' => 'osb-field--half' ) );
						self::field( 'text_color', __( 'Text', 'osam-bookings' ), $s['text_color'], 'color', array( 'class' => 'osb-field--half' ) );
						self::field( 'background_color', __( 'Background', 'osam-bookings' ), $s['background_color'], 'color', array( 'class' => 'osb-field--half' ) );
						?>
					</div>
				</div>

				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Service list', 'osam-bookings' ) ); ?>
					<div class="osb-fieldgrid">
						<?php
						self::field( 'service_layout', __( 'Layout', 'osam-bookings' ), $s['service_layout'], 'select', $choices['service_layout'] + array( 'class' => 'osb-field--half' ) );
						self::field( 'service_columns', __( 'Grid columns', 'osam-bookings' ), $s['service_columns'], 'select', $choices['service_columns'] + array( 'class' => 'osb-field--half' ) );
						?>
					</div>
				</div>

				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Show on the form', 'osam-bookings' ) ); ?>
					<?php
					foreach ( OSB_Settings::display_toggles() as $key => $def ) {
						self::field( $key, $def[1], $s[ $key ], 'checkbox' );
					}
					?>
				</div>

				<details class="osb-card">
					<summary class="osb-card__head"><h2><?php esc_html_e( 'Wording', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Change any text on the booking form.', 'osam-bookings' ); ?></p></summary>
					<?php
					foreach ( $defaults as $key => $default ) {
						self::field( 'labels[' . $key . ']', $default, $s['labels'][ $key ], 'text', array( 'placeholder' => $default ) );
					}
					?>
				</details>

				<details class="osb-card">
					<summary class="osb-card__head"><h2><?php esc_html_e( 'Custom CSS', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'For anything the options above don’t cover.', 'osam-bookings' ); ?></p></summary>
					<?php self::field( 'custom_css', __( 'CSS', 'osam-bookings' ), $s['custom_css'], 'code', array( 'help' => __( 'Scope rules under .osb-booking. Variables: --osb-accent, --osb-on-accent, --osb-text, --osb-bg, --osb-radius, --osb-border, --osb-muted, --osb-soft.', 'osam-bookings' ) ) ); ?>
				</details>
			</div>

			<div class="osb-designer__preview">
				<div class="osb-preview">
					<div class="osb-preview__bar">
						<span class="osb-preview__dots"><i></i><i></i><i></i></span>
						<span><?php esc_html_e( 'Live preview', 'osam-bookings' ); ?></span>
						<span class="osb-preview__sizes"><button type="button" data-w="100%" class="is-active" aria-label="<?php esc_attr_e( 'Desktop', 'osam-bookings' ); ?>"><span class="dashicons dashicons-desktop"></span></button><button type="button" data-w="400px" aria-label="<?php esc_attr_e( 'Mobile', 'osam-bookings' ); ?>"><span class="dashicons dashicons-smartphone"></span></button></span>
					</div>
					<div class="osb-preview__stage">
						<div class="osb-preview__frame">
							<?php echo OSB_Frontend::shortcode( array( 'preview' => '1' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</div>
					<p class="osb-help"><?php esc_html_e( 'Uses your real services and events. Click through the steps — nothing is booked from here. Your theme’s fonts aren’t loaded in the admin, so “Use the theme font” will look different on the site.', 'osam-bookings' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save.
	 */
	public static function save() {
		OSB_Admin::verify( 'osb_save_settings', 'manage_options' );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tab  = isset( $post['tab'] ) ? sanitize_key( $post['tab'] ) : 'general';
		$v    = array();

		$text = static function ( $k ) use ( $post ) {
			return isset( $post[ $k ] ) ? sanitize_text_field( $post[ $k ] ) : '';
		};
		$int  = static function ( $k ) use ( $post ) {
			return isset( $post[ $k ] ) ? max( 0, (int) $post[ $k ] ) : 0;
		};
		$bool = static function ( $k ) use ( $post ) {
			return ! empty( $post[ $k ] ) ? 1 : 0;
		};

		$was = OSB_Settings::all();
		switch ( $tab ) {
			case 'general':
				$v = array(
					'staff_mode'               => 'team' === $text( 'staff_mode' ) ? 'team' : 'solo',
					'booking_page'             => $int( 'booking_page' ),
					'portal_page'              => $int( 'portal_page' ),
					'currency'                 => array_key_exists( $text( 'currency' ), OSB_Settings::currencies() ) ? $text( 'currency' ) : 'GBP',
					'currency_position'        => 'after' === $text( 'currency_position' ) ? 'after' : 'before',
					'slot_interval'            => $int( 'slot_interval' ),
					'min_notice_hours'         => max( 0, (float) ( isset( $post['min_notice_hours'] ) ? $post['min_notice_hours'] : 0 ) ),
					'max_advance_days'         => max( 1, $int( 'max_advance_days' ) ),
					'pending_payment_minutes'  => max( 15, $int( 'pending_payment_minutes' ) ),
					'free_status'              => 'pending' === $text( 'free_status' ) ? 'pending' : 'confirmed',
					'allow_customer_cancel'    => $bool( 'allow_customer_cancel' ),
					'cancel_cutoff_hours'      => $int( 'cancel_cutoff_hours' ),
					'terms_text'               => wp_kses( isset( $post['terms_text'] ) ? $post['terms_text'] : '', array( 'a' => array( 'href' => array(), 'target' => array() ), 'strong' => array(), 'em' => array() ) ),
					'delete_data_on_uninstall' => $bool( 'delete_data_on_uninstall' ),
					'auto_update'              => $bool( 'auto_update' ),
				);
				if ( isset( $post['waitlist_hold_hours'] ) ) {
					$v['waitlist_hold_hours'] = max( 1, $int( 'waitlist_hold_hours' ) );
				}
				if ( isset( $post['repeat_max'] ) ) {
					$v['repeat_types'] = array_values( array_intersect( (array) ( $post['repeat_types'] ?? array() ), array( 'service', 'resource', 'table' ) ) );
					$v['repeat_freqs'] = array_values( array_intersect( (array) ( $post['repeat_freqs'] ?? array() ), array_keys( OSB_Repeat::freqs() ) ) );
					$v['repeat_max']   = max( 2, min( 52, $int( 'repeat_max' ) ) );
				}
				if ( isset( $post['reschedule_cutoff_hours'] ) ) {
					$v['reschedule_cutoff_hours'] = $int( 'reschedule_cutoff_hours' );
				}
				if ( isset( $post['show_staff_step'] ) ) {
					$v['show_staff_step']  = $bool( 'show_staff_step' );
					$v['any_staff_option'] = $bool( 'any_staff_option' );
				}
				$changed_mode = $v['staff_mode'] !== OSB_Settings::get( 'staff_mode' );
				break;

			case 'meetings':
				$v = array(
					'zoom_account_id' => trim( $text( 'zoom_account_id' ) ),
					'zoom_client_id'  => trim( $text( 'zoom_client_id' ) ),
				);
				$secret = isset( $post['zoom_client_secret'] ) ? trim( (string) $post['zoom_client_secret'] ) : '';
				if ( '••••••••' !== $secret ) {
					$v['zoom_client_secret'] = '' === $secret ? '' : OSB_Calendar_Sync::seal( sanitize_text_field( $secret ) );
				}
				delete_transient( 'osb_zoom_token' );
				break;

			case 'subscription':
				$v = array( 'licence_customer_message' => isset( $post['licence_customer_message'] ) ? sanitize_textarea_field( $post['licence_customer_message'] ) : '' );
				if ( ! OSB_Licence::locked() ) {
					$v['licence_url']     = ''; // No longer settable; the status address is fixed (see OSB_Licence::url()).
					$v['licence_service'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', $text( 'licence_service' ) );
					$key                  = isset( $post['licence_key'] ) ? trim( (string) $post['licence_key'] ) : '';
					if ( '••••••••' !== $key ) {
						if ( '' === $key && OSB_Licence::bookings_off() ) {
							// Clearing the key isn't a way round a suspension.
							OSB_Admin::flash_error( __( 'The subscription key can’t be removed while bookings are switched off.', 'osam-bookings' ) );
						} else {
							$v['licence_key'] = preg_replace( '/[^\x21-\x7e]/', '', $key );
							if ( $v['licence_key'] !== OSB_Licence::key() ) {
								delete_option( OSB_Licence::OPTION ); // New key: start from a clean status.
							}
						}
					}
				}
				break;

			case 'features':
				foreach ( OSB_Settings::features() as $key => $f ) {
					if ( 'followup' === $f[3] ) {
						$emails = OSB_Settings::get( 'emails', array() );
						$emails['customer_followup']['enabled'] = $bool( 'feature_followup' );
						$v['emails'] = $emails;
						continue;
					}
					$v[ $f[3] ] = $bool( 'feature_' . $key );
				}
				break;

			case 'calendars':
				$v = array(
					'cal_use_connector'   => $bool( 'cal_use_connector' ),
					'cal_connector_url'   => esc_url_raw( trim( isset( $post['cal_connector_url'] ) ? (string) $post['cal_connector_url'] : '' ) ),
					'gcal_client_id'      => trim( $text( 'gcal_client_id' ) ),
					'gcal_client_secret'  => trim( $text( 'gcal_client_secret' ) ),
					'mscal_client_id'     => trim( $text( 'mscal_client_id' ) ),
					'mscal_client_secret' => trim( $text( 'mscal_client_secret' ) ),
					'mscal_tenant'        => preg_replace( '/[^A-Za-z0-9.\-]/', '', $text( 'mscal_tenant' ) ) ? preg_replace( '/[^A-Za-z0-9.\-]/', '', $text( 'mscal_tenant' ) ) : 'common',
				);
				break;

			case 'invoices':
				$v = OSB_Admin_Invoices::settings_values( $post );
				break;

			case 'payments':
				foreach ( osb()->payments->all() as $id => $gateway ) {
					$v[ $id . '_enabled' ] = $bool( $id . '_enabled' );
					foreach ( $gateway->settings_fields() as $key => $def ) {
						$v[ $key ] = 'select' === $def[1] ? ( isset( $def[2][ $text( $key ) ] ) ? $text( $key ) : key( $def[2] ) ) : trim( $text( $key ) );
					}
					if ( method_exists( $gateway, 'save_settings' ) ) {
						$v = array_merge( $v, $gateway->save_settings( $post ) );
					}
				}
				break;

			case 'emails':
				$v = array(
					'email_from_name'        => $text( 'email_from_name' ),
					'email_from_address'     => sanitize_email( isset( $post['email_from_address'] ) ? $post['email_from_address'] : '' ),
					'email_admin_recipients' => implode( ', ', array_filter( array_map( 'sanitize_email', explode( ',', $text( 'email_admin_recipients' ) ) ) ) ),
					'email_logo'             => esc_url_raw( isset( $post['email_logo'] ) ? $post['email_logo'] : '' ),
					'email_logo_id'          => $int( 'email_logo_id' ),
					'email_logo_width'       => max( 40, min( 400, $int( 'email_logo_width' ) ) ),
					'email_header_style'     => in_array( $text( 'email_header_style' ), array( 'band', 'clean', 'centered' ), true ) ? $text( 'email_header_style' ) : 'band',
					'email_font'             => array_key_exists( $text( 'email_font' ), OSB_Emails::fonts() ) ? $text( 'email_font' ) : 'system',
					'email_radius'           => min( 24, $int( 'email_radius' ) ),
					'email_footer_site_link' => $bool( 'email_footer_site_link' ),
					'email_footer'           => wp_kses_post( isset( $post['email_footer'] ) ? $post['email_footer'] : '' ),
					'email_attach_ics'       => $bool( 'email_attach_ics' ),
					'reminder_hours'         => max( 0, (float) ( isset( $post['reminder_hours'] ) ? $post['reminder_hours'] : 0 ) ),
					'followup_hours'         => max( 0, (float) ( isset( $post['followup_hours'] ) ? $post['followup_hours'] : 0 ) ),
					'review_url'             => esc_url_raw( isset( $post['review_url'] ) ? trim( $post['review_url'] ) : '' ),
					'review_button_label'    => $text( 'review_button_label' ),
					'emails'                 => OSB_Settings::get( 'emails' ),
				);
				foreach ( array( 'email_accent', 'email_button_text', 'email_header_bg', 'email_header_text', 'email_page_bg', 'email_card_bg', 'email_panel_bg', 'email_text', 'email_muted', 'email_footer_text' ) as $ck ) {
					$v[ $ck ] = (string) sanitize_hex_color( $text( $ck ) );
				}
				if ( ! $v['email_accent'] ) {
					$v['email_accent'] = '#161a40';
				}
				foreach ( array_keys( OSB_Settings::email_labels() ) as $key ) {
					if ( ! isset( $post['emails'][ $key ] ) ) {
						continue; // Staff templates are hidden in solo mode — keep them as they were.
					}
					$tpl                 = $post['emails'][ $key ];
					$v['emails'][ $key ] = array(
						'enabled' => ! empty( $tpl['enabled'] ) ? 1 : 0,
						'subject' => sanitize_text_field( isset( $tpl['subject'] ) ? $tpl['subject'] : '' ),
						'body'    => wp_kses_post( isset( $tpl['body'] ) ? $tpl['body'] : '' ),
					);
				}
				break;

			case 'form':
				$fields = array();
				$seen   = array();
				foreach ( (array) ( isset( $post['form_fields'] ) ? $post['form_fields'] : array() ) as $f ) {
					$label = sanitize_text_field( isset( $f['label'] ) ? $f['label'] : '' );
					$key   = str_replace( '-', '_', sanitize_key( isset( $f['key'] ) && '' !== $f['key'] ? $f['key'] : $label ) );
					if ( '' === $label || '' === $key || isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;
					$type         = sanitize_key( isset( $f['type'] ) ? $f['type'] : 'text' );
					$fields[]     = array(
						'key'         => $key,
						'label'       => $label,
						'type'        => isset( self::field_types()[ $type ] ) ? $type : 'text',
						'required'    => ! empty( $f['required'] ) || in_array( $key, array( 'name', 'email' ), true ) ? 1 : 0,
						'options'     => sanitize_textarea_field( isset( $f['options'] ) ? $f['options'] : '' ),
						'placeholder' => sanitize_text_field( isset( $f['placeholder'] ) ? $f['placeholder'] : '' ),
						'width'       => isset( $f['width'] ) && 'half' === $f['width'] ? 'half' : 'full',
						'per_place'   => 'name' === $key || ! empty( $f['per_place'] ) ? 1 : 0,
						'show_for'    => in_array( $key, array( 'name', 'email' ), true ) ? array() : array_values( array_filter( array_map( 'sanitize_text_field', (array) ( isset( $f['show_for'] ) ? $f['show_for'] : array() ) ), static function ( $t ) { return (bool) preg_match( '/^(services|events|courses|tables|resources|[secr]:\d+)$/', $t ); } ) ),
					);
				}
				$v['form_fields'] = $fields;
				break;

			case 'appearance':
				$choices = OSB_Settings::display_choices();
				foreach ( array( 'primary_color' => '#161a40', 'on_accent_color' => '#ffffff', 'text_color' => '#1f2933', 'background_color' => '#ffffff' ) as $c => $fallback ) {
					$v[ $c ] = sanitize_hex_color( $text( $c ) ) ? sanitize_hex_color( $text( $c ) ) : $fallback;
				}
				foreach ( array( 'design' => 'design', 'service_layout' => 'service_layout', 'button_shape' => 'button_shape', 'density' => 'density', 'font_family' => 'font_family' ) as $k => $ck ) {
					$v[ $k ] = isset( $choices[ $ck ][ $text( $k ) ] ) ? $text( $k ) : key( $choices[ $ck ] );
				}
				$v['service_columns'] = isset( $choices['service_columns'][ $int( 'service_columns' ) ] ) ? $int( 'service_columns' ) : 2;
				$v['border_radius']   = min( 32, $int( 'border_radius' ) );
				$v['font_size']       = max( 12, min( 22, $int( 'font_size' ) ) );
				$v['max_width']       = min( 2000, $int( 'max_width' ) );
				foreach ( array_keys( OSB_Settings::display_toggles() ) as $k ) {
					$v[ $k ] = $bool( $k );
				}
				$v['custom_css'] = wp_strip_all_tags( isset( $post['custom_css'] ) ? $post['custom_css'] : '' );
				$v['labels']     = array();
				foreach ( OSB_Settings::default_labels() as $key => $default ) {
					$val                 = isset( $post['labels'][ $key ] ) ? sanitize_text_field( $post['labels'][ $key ] ) : '';
					$v['labels'][ $key ] = '' !== $val ? $val : $default;
				}
				break;
		}

		OSB_Settings::update( $v );
		if ( ! empty( $changed_mode ) ) {
			OSB_DB::apply_staff_mode( $v['staff_mode'] );
		}
		OSB_Availability::bust_cache();
		do_action( 'osb_settings_saved', $tab, $v );
		$go = $tab;
		// Switching on something that needs setting up takes you straight to its setup.
		if ( 'features' === $tab ) {
			if ( ! empty( $v['enable_calendar_sync'] ) && empty( $was['enable_calendar_sync'] ) && ! array_filter( array_keys( OSB_Calendar_Sync::providers() ), array( 'OSB_Calendar_Sync', 'configured' ) ) ) {
				$go = 'calendars';
			} elseif ( ! empty( $v['enable_meetings'] ) && empty( $was['enable_meetings'] ) ) {
				$go = 'meetings';
			}
		}
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-settings&tab=' . $go ) );
	}

	/**
	 * Send a test email.
	 */
	public static function test_email() {
		OSB_Admin::verify( 'osb_test_email', 'manage_options' );
		$key = isset( $_POST['template'] ) ? sanitize_key( $_POST['template'] ) : 'customer_confirmed'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$to  = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$booking = OSB_Emails::sample_booking( $key, $to );
		$extra   = OSB_Emails::sample_extra( $key, $booking );
		$sent = is_email( $to ) && OSB_Emails::send( $key, $booking, $to, true, $extra );
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-settings&tab=emails' ), $sent ? 'emailed' : 'error' );
	}
}
