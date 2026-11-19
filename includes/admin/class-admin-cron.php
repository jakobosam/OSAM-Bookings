<?php
/**
 * "Keep reminders on time": status of the scheduled jobs and step-by-step instructions for a
 * server cron job in cPanel. Shown on the dashboard and at the end of the setup wizard.
 *
 * WordPress only runs scheduled jobs when someone visits the site. A server cron job runs them
 * every 5 minutes regardless, so reminders, payment time-outs, calendar sync, subscription checks
 * and updates happen on time on quiet sites.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cron setup panel.
 */
class OSB_Admin_Cron {

	/**
	 * Recommended cPanel command (runs WordPress's scheduled jobs from the command line).
	 *
	 * @return string
	 */
	public static function command() {
		return 'cd ' . self::shell_path( untrailingslashit( ABSPATH ) ) . ' && /usr/local/bin/php -q wp-cron.php >/dev/null 2>&1';
	}

	/**
	 * Fallback command for hosts where the PHP command doesn't work (calls the site over the web).
	 *
	 * @return string
	 */
	public static function web_command() {
		return 'wget -q -O - "' . site_url( 'wp-cron.php?doing_wp_cron' ) . '" >/dev/null 2>&1';
	}

	/**
	 * The wp-config.php line that stops visitors triggering the jobs (once the cron job works).
	 *
	 * @return string
	 */
	public static function config_line() {
		return "define( 'DISABLE_WP_CRON', true );";
	}

	/**
	 * Quote a path for the shell if it needs it.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function shell_path( $path ) {
		return preg_match( '#^[A-Za-z0-9/._-]+$#', $path ) ? $path : "'" . str_replace( "'", "'\\''", $path ) . "'";
	}

	/**
	 * Is everything set up and running on time?
	 *
	 * @return bool
	 */
	public static function done() {
		return 'ok' === OSB_Cron::health()['state'];
	}

	/**
	 * Status line: [ tone ok|warn|error|info, headline, detail ].
	 *
	 * @return array
	 */
	public static function status() {
		$h   = OSB_Cron::health();
		$ago = $h['last_run'] ? human_time_diff( $h['last_run'] ) : '';
		switch ( $h['state'] ) {
			case 'ok':
				/* translators: %s time ago */
				return array( 'ok', __( 'Server cron job is working', 'osam-bookings' ), sprintf( __( 'Booking jobs last ran %s ago. Reminders and payment time-outs go out on time.', 'osam-bookings' ), $ago ) );
			case 'server':
				/* translators: %s time ago */
				return array( 'info', __( 'Cron job is running — one step left', 'osam-bookings' ), sprintf( __( 'The server cron job ran %s ago. Now add the line to wp-config.php (step 6) so visitors no longer trigger the jobs as well.', 'osam-bookings' ), $ago ) );
			case 'late':
				if ( $h['real_cron'] ) {
					/* translators: %s time ago */
					return array( 'error', __( 'Booking jobs have stopped', 'osam-bookings' ), $ago ? sprintf( __( 'They last ran %s ago. WordPress’s own scheduler is switched off in wp-config.php, so a server cron job must run them — check it’s still set up in cPanel (steps 1–5).', 'osam-bookings' ), $ago ) : __( 'WordPress’s own scheduler is switched off in wp-config.php, but no server cron job has run them yet. Set one up in cPanel (steps 1–5).', 'osam-bookings' ) );
				}
				/* translators: %s time ago */
				return array( 'warn', __( 'Booking jobs are running late', 'osam-bookings' ), sprintf( __( 'They last ran %s ago, because they only run when someone visits the site. A server cron job fixes this.', 'osam-bookings' ), $ago ) );
			case 'never':
				return array( 'warn', __( 'Booking jobs haven’t run yet', 'osam-bookings' ), __( 'Set up a server cron job so reminders, payment time-outs and calendar sync run every 5 minutes.', 'osam-bookings' ) );
			default:
				return array( 'warn', __( 'Set up a server cron job', 'osam-bookings' ), __( 'Right now reminders, payment time-outs and calendar sync only run when someone visits the site, so on quiet days they can be late.', 'osam-bookings' ) );
		}
	}

	/**
	 * The panel: status plus cPanel steps.
	 *
	 * @param bool $open Show the steps expanded.
	 */
	public static function panel( $open = true ) {
		list( $tone, $head, $detail ) = self::status();
		$h     = OSB_Cron::health();
		$icons = array(
			'ok'    => 'yes-alt',
			'info'  => 'info',
			'warn'  => 'clock',
			'error' => 'warning',
		);
		$copy  = static function ( $text ) {
			return '<div class="osb-cronline"><code>' . esc_html( $text ) . '</code><button type="button" class="osb-iconbtn osb-copy" data-copy="' . esc_attr( $text ) . '" title="' . esc_attr__( 'Copy', 'osam-bookings' ) . '"><span class="dashicons dashicons-admin-page"></span></button></div>';
		};
		?>
		<div class="osb-alert osb-alert--<?php echo esc_attr( 'error' === $tone ? 'warn' : $tone ); ?>">
			<span class="dashicons dashicons-<?php echo esc_attr( $icons[ $tone ] ); ?>"></span>
			<div><strong><?php echo esc_html( $head ); ?></strong><br><?php echo esc_html( $detail ); ?></div>
		</div>
		<details class="osb-steps osb-cronsteps" <?php echo $open && 'ok' !== $h['state'] ? 'open' : ''; ?>>
			<summary><?php esc_html_e( 'How to set up the cron job in cPanel', 'osam-bookings' ); ?></summary>
			<p class="osb-help"><?php esc_html_e( 'Takes about two minutes. No cPanel login? Send these steps to whoever looks after the hosting.', 'osam-bookings' ); ?></p>
			<ol>
				<li><?php echo wp_kses_post( __( 'Log in to <strong>cPanel</strong> for this website.', 'osam-bookings' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'Under <strong>Advanced</strong>, open <strong>Cron Jobs</strong>. (Type “cron” in cPanel’s search box if you can’t see it.)', 'osam-bookings' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'In <strong>Add New Cron Job</strong> → <strong>Common Settings</strong>, choose <strong>Once Per Five Minutes (*/5 * * * *)</strong>. The minute, hour, day, month and weekday boxes fill in by themselves.', 'osam-bookings' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'Copy this into the <strong>Command</strong> box exactly as it is:', 'osam-bookings' ) ); ?>
					<?php echo $copy( self::command() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $copy. ?>
					<small class="osb-help"><?php esc_html_e( 'The end of the line stops cPanel emailing you every 5 minutes.', 'osam-bookings' ); ?></small>
				</li>
				<li><?php echo wp_kses_post( __( 'Click <strong>Add New Cron Job</strong>. Come back here in 10 minutes — the box above should say the cron job is running.', 'osam-bookings' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'Once it says that, stop visitors triggering the jobs too: in cPanel’s <strong>File Manager</strong>, open the website’s folder, right-click <strong>wp-config.php</strong> → <strong>Edit</strong>, and add this line just above the line that says <em>That’s all, stop editing!</em> Then save.', 'osam-bookings' ) ); ?>
					<?php echo $copy( self::config_line() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $copy. ?>
					<small class="osb-help"><?php esc_html_e( 'Only do this after step 5 shows the cron job running — with this line and no cron job, reminders stop completely.', 'osam-bookings' ); ?></small>
				</li>
			</ol>
			<details class="osb-advanced">
				<summary><?php esc_html_e( 'Still not running after 10 minutes?', 'osam-bookings' ); ?></summary>
				<p class="osb-help"><?php esc_html_e( 'Some hosts keep PHP somewhere else. Edit the cron job and use this command instead, which asks the website to run the jobs:', 'osam-bookings' ); ?></p>
				<?php echo $copy( self::web_command() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $copy. ?>
				<p class="osb-help"><?php esc_html_e( 'If that doesn’t work either, a security plugin or firewall (e.g. Cloudflare) may be blocking wp-cron.php — ask your host.', 'osam-bookings' ); ?></p>
			</details>
		</details>
		<?php
	}
}
