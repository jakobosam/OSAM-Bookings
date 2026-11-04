<?php
/**
 * First-run setup wizard.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Six short steps; each step saves before moving on, so it can be left and resumed.
 */
class OSB_Admin_Wizard {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'hide_menu' ), 999 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_post_osb_wizard', array( __CLASS__, 'save' ) );
	}

	/**
	 * Steps.
	 *
	 * @return array
	 */
	public static function steps() {
		return array(
			'business' => __( 'Your business', 'osam-bookings' ),
			'hours'    => OSB_DB::is_solo() ? __( 'Your hours', 'osam-bookings' ) : __( 'Your team', 'osam-bookings' ),
			'services' => __( 'Services', 'osam-bookings' ),
			'payments' => __( 'Payments', 'osam-bookings' ),
			'emails'   => __( 'Emails', 'osam-bookings' ),
			'page'     => __( 'Go live', 'osam-bookings' ),
		);
	}

	/**
	 * Register (hidden) page.
	 */
	public static function menu() {
		add_submenu_page( 'osb-dashboard', __( 'Setup wizard', 'osam-bookings' ), __( 'Setup wizard', 'osam-bookings' ), 'manage_options', 'osb-setup', array( __CLASS__, 'page' ) );
	}

	/**
	 * Keep it out of the sidebar; it is reached from the dashboard and settings.
	 */
	public static function hide_menu() {
		// Removing the submenu entry would also make the page unreachable, so hide the link instead.
		add_action(
			'admin_head',
			static function () {
				echo '<style>#adminmenu li:has(> a[href$="page=osb-setup"]){display:none}</style>';
			}
		);
	}

	/**
	 * Send the admin to the wizard straight after first activation.
	 */
	public static function maybe_redirect() {
		if ( ! get_transient( 'osb_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'osb_activation_redirect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) || get_option( 'osb_setup_done' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=osb-setup' ) );
		exit;
	}

	/**
	 * Current step.
	 *
	 * @return string
	 */
	private static function step() {
		$step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : 'business'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( self::steps()[ $step ] ) || 'done' === $step ? $step : 'business';
	}

	/**
	 * Step URL.
	 *
	 * @param string $step Step.
	 * @return string
	 */
	private static function url( $step ) {
		return admin_url( 'admin.php?page=osb-setup&step=' . $step );
	}

	/**
	 * Page.
	 */
	public static function page() {
		$step  = self::step();
		$steps = self::steps();
		$keys  = array_keys( $steps );
		$index = 'done' === $step ? count( $keys ) : array_search( $step, $keys, true );
		$s     = OSB_Settings::all();
		?>
		<div class="wrap osb-admin osb-wizard">
			<div class="osb-wizard__top">
				<div class="osb-brand"><img class="osb-brand__mark" src="<?php echo esc_url( OSB_URL . 'assets/img/osam-mark.png' ); ?>" alt="" width="30" height="30"><span class="osb-brand__name"><?php esc_html_e( 'OSAM Bookings setup', 'osam-bookings' ); ?></span></div>
				<a class="osb-link" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-dashboard' ) ); ?>"><?php esc_html_e( 'Skip for now', 'osam-bookings' ); ?></a>
			</div>
			<ol class="osb-wizard__steps">
				<?php
				$i = 0;
				foreach ( $steps as $key => $label ) :
					$cls = $i < $index ? 'is-done' : ( $i === $index ? 'is-current' : '' );
					?>
					<li class="<?php echo esc_attr( $cls ); ?>">
						<?php if ( $i < $index ) : ?><a href="<?php echo esc_url( self::url( $key ) ); ?>"><?php endif; ?>
						<b><?php echo $i < $index ? '<span class="dashicons dashicons-yes"></span>' : (int) ( $i + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></b><span><?php echo esc_html( $label ); ?></span>
						<?php if ( $i < $index ) : ?></a><?php endif; ?>
					</li>
					<?php
					$i++;
				endforeach;
				?>
			</ol>
			<hr class="wp-header-end">

			<?php if ( 'done' === $step ) : ?>
				<?php self::done(); ?>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-wizard__card osb-form">
				<?php wp_nonce_field( 'osb_wizard' ); ?>
				<input type="hidden" name="action" value="osb_wizard">
				<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>">
				<?php call_user_func( array( __CLASS__, 'step_' . $step ), $s ); ?>
				<div class="osb-wizard__foot">
					<?php if ( $index > 0 ) : ?>
						<a class="osb-btn osb-btn--ghost" href="<?php echo esc_url( self::url( $keys[ $index - 1 ] ) ); ?>"><?php esc_html_e( '← Back', 'osam-bookings' ); ?></a>
					<?php else : ?><span></span><?php endif; ?>
					<button class="osb-btn osb-btn--primary osb-btn--lg"><?php echo 'page' === $step ? esc_html__( 'Finish setup', 'osam-bookings' ) : esc_html__( 'Save & continue', 'osam-bookings' ); ?></button>
				</div>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Step 1.
	 *
	 * @param array $s Settings.
	 */
	private static function step_business( $s ) {
		$currencies = array();
		foreach ( OSB_Settings::currencies() as $code => $sym ) {
			$currencies[ $code ] = $code . ' (' . trim( $sym ) . ')';
		}
		$tz = wp_timezone_string();
		?>
		<h1><?php esc_html_e( 'Let’s get you taking bookings', 'osam-bookings' ); ?></h1>
		<p class="osb-lead"><?php esc_html_e( 'A few quick questions. You can change everything later in Settings.', 'osam-bookings' ); ?></p>
		<div class="osb-field"><label><?php esc_html_e( 'Who takes the bookings?', 'osam-bookings' ); ?></label><?php OSB_Admin_Settings::mode_picker( $s['staff_mode'] ); ?></div>
		<div class="osb-fieldgrid">
			<div class="osb-field osb-field--half"><label for="w_name"><?php esc_html_e( 'Business name', 'osam-bookings' ); ?></label><input type="text" id="w_name" name="email_from_name" value="<?php echo esc_attr( $s['email_from_name'] ); ?>"><p class="osb-help"><?php esc_html_e( 'Shown on emails and payment pages.', 'osam-bookings' ); ?></p></div>
			<div class="osb-field osb-field--half"><label for="w_currency"><?php esc_html_e( 'Currency', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'currency', $s['currency'], 'select', $currencies, 'w_currency' ); ?></div>
		</div>
		<p class="osb-help">
			<?php
			/* translators: 1: timezone 2: link */
			printf( wp_kses_post( __( 'Times use the site timezone: <strong>%1$s</strong>. <a href="%2$s">Change it</a> if that’s not right (choose a city such as London so clock changes are handled).', 'osam-bookings' ) ), esc_html( $tz ), esc_url( admin_url( 'options-general.php#timezone_string' ) ) );
			?>
		</p>
		<?php
	}

	/**
	 * Step 2.
	 *
	 * @param array $s Settings.
	 */
	private static function step_hours( $s ) {
		if ( OSB_DB::is_solo() ) {
			$owner = OSB_DB::solo_staff_id();
			?>
			<h1><?php esc_html_e( 'When can people book you?', 'osam-bookings' ); ?></h1>
			<p class="osb-lead"><?php esc_html_e( 'Set your normal week. Add a second range to a day for a lunch break. Holidays can be added later under Working hours.', 'osam-bookings' ); ?></p>
			<?php
			OSB_Admin_Staff::schedule_editor( OSB_DB::schedule( $owner ) );
			return;
		}
		$staff    = OSB_DB::staff();
		$schedule = $staff ? OSB_DB::schedule( $staff[0]->id ) : array();
		if ( ! array_filter( $schedule ) ) {
			for ( $d = 1; $d <= 5; $d++ ) {
				$schedule[ $d ] = array( array( '09:00', '17:00' ) );
			}
		}
		?>
		<h1><?php esc_html_e( 'Who’s in your team?', 'osam-bookings' ); ?></h1>
		<p class="osb-lead"><?php esc_html_e( 'Add the people (or rooms) customers can book. They’ll all start with the hours below — you can fine-tune each person later.', 'osam-bookings' ); ?></p>
		<div class="osb-repeater-list osb-repeater" data-template="osb-wteam-tpl">
			<?php foreach ( $staff as $i => $st ) : ?>
				<div class="osb-repeat-row">
					<input type="hidden" name="team[<?php echo (int) $i; ?>][id]" value="<?php echo (int) $st->id; ?>">
					<input type="text" name="team[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $st->name ); ?>" placeholder="<?php esc_attr_e( 'Name', 'osam-bookings' ); ?>">
					<input type="email" name="team[<?php echo (int) $i; ?>][email]" value="<?php echo esc_attr( $st->email ); ?>" placeholder="<?php esc_attr_e( 'Email (gets their bookings)', 'osam-bookings' ); ?>">
					<span class="osb-muted osb-repeat-row__note"><?php esc_html_e( 'existing', 'osam-bookings' ); ?></span>
				</div>
			<?php endforeach; ?>
			<?php if ( ! $staff ) : ?>
				<div class="osb-repeat-row">
					<input type="text" name="team[n0][name]" placeholder="<?php esc_attr_e( 'Name', 'osam-bookings' ); ?>">
					<input type="email" name="team[n0][email]" placeholder="<?php esc_attr_e( 'Email (gets their bookings)', 'osam-bookings' ); ?>">
					<button type="button" class="osb-iconbtn osb-iconbtn--danger osb-row-remove"><span class="dashicons dashicons-no-alt"></span></button>
				</div>
			<?php endif; ?>
		</div>
		<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-row-add"><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add person', 'osam-bookings' ); ?></button>
		<script type="text/template" id="osb-wteam-tpl">
			<div class="osb-repeat-row">
				<input type="text" name="team[__i__][name]" placeholder="<?php esc_attr_e( 'Name', 'osam-bookings' ); ?>">
				<input type="email" name="team[__i__][email]" placeholder="<?php esc_attr_e( 'Email (gets their bookings)', 'osam-bookings' ); ?>">
				<button type="button" class="osb-iconbtn osb-iconbtn--danger osb-row-remove"><span class="dashicons dashicons-no-alt"></span></button>
			</div>
		</script>
		<h2 class="osb-wizard__sub"><?php esc_html_e( 'Starting hours for new people', 'osam-bookings' ); ?></h2>
		<?php
		OSB_Admin_Staff::schedule_editor( $schedule );
	}

	/**
	 * Step 3.
	 *
	 * @param array $s Settings.
	 */
	private static function step_services( $s ) {
		$existing = OSB_DB::services();
		$sym      = trim( OSB_Settings::currencies()[ $s['currency'] ] ?? $s['currency'] );
		?>
		<h1><?php esc_html_e( 'What can people book?', 'osam-bookings' ); ?></h1>
		<p class="osb-lead"><?php esc_html_e( 'Add your main services now — name, length and price. Descriptions, images and group classes can be added later.', 'osam-bookings' ); ?></p>
		<?php if ( $existing ) : ?>
			<div class="osb-existing">
				<strong><?php esc_html_e( 'Already added:', 'osam-bookings' ); ?></strong>
				<?php foreach ( $existing as $sv ) : ?><span class="osb-tag"><i style="background:<?php echo esc_attr( $sv->color ); ?>"></i><?php echo esc_html( $sv->name ); ?></span><?php endforeach; ?>
			</div>
		<?php endif; ?>
		<div class="osb-repeater-list osb-repeater" data-template="osb-wsvc-tpl">
			<div class="osb-repeat-row osb-repeat-row--head"><span><?php esc_html_e( 'Service name', 'osam-bookings' ); ?></span><span><?php esc_html_e( 'Length', 'osam-bookings' ); ?></span><span><?php esc_html_e( 'Price', 'osam-bookings' ); ?></span><span></span></div>
			<?php for ( $i = 0; $i < ( $existing ? 1 : 2 ); $i++ ) : ?>
				<?php self::service_row( 'n' . $i, $sym ); ?>
			<?php endfor; ?>
		</div>
		<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-row-add"><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add another', 'osam-bookings' ); ?></button>
		<script type="text/template" id="osb-wsvc-tpl"><?php self::service_row( '__i__', $sym ); ?></script>
		<p class="osb-help"><?php esc_html_e( 'Leave rows empty to skip. Price 0 makes a service free.', 'osam-bookings' ); ?></p>
		<?php
	}

	/**
	 * Service quick-add row.
	 *
	 * @param string $i   Index.
	 * @param string $sym Currency symbol.
	 */
	private static function service_row( $i, $sym ) {
		?>
		<div class="osb-repeat-row">
			<input type="text" name="services[<?php echo esc_attr( $i ); ?>][name]" placeholder="<?php esc_attr_e( 'e.g. Full body massage', 'osam-bookings' ); ?>">
			<select name="services[<?php echo esc_attr( $i ); ?>][duration]">
				<?php foreach ( array( 15, 30, 45, 60, 75, 90, 120, 180 ) as $m ) : ?><option value="<?php echo (int) $m; ?>" <?php selected( 60, $m ); ?>><?php echo esc_html( OSB_Admin_Services::duration_label( $m ) ); ?></option><?php endforeach; ?>
			</select>
			<span class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" name="services[<?php echo esc_attr( $i ); ?>][price]" min="0" step="0.01" placeholder="0.00"></span>
			<button type="button" class="osb-iconbtn osb-iconbtn--danger osb-row-remove"><span class="dashicons dashicons-no-alt"></span></button>
		</div>
		<?php
	}

	/**
	 * Step 4.
	 *
	 * @param array $s Settings.
	 */
	private static function step_payments( $s ) {
		$woo = class_exists( 'WooCommerce' );
		?>
		<h1><?php esc_html_e( 'How will customers pay?', 'osam-bookings' ); ?></h1>
		<p class="osb-lead"><?php esc_html_e( 'Turn on as many as you like — customers choose at checkout. Free services skip payment.', 'osam-bookings' ); ?></p>
		<div class="osb-paygrid">
			<div class="osb-paycard <?php echo $s['onsite_enabled'] ? 'is-on' : ''; ?>">
				<label class="osb-paycard__head"><span class="dashicons dashicons-store"></span><span><strong><?php esc_html_e( 'Pay in person', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Cash or card on the day', 'osam-bookings' ); ?></small></span><span class="osb-switch"><input type="hidden" name="onsite_enabled" value="0"><input type="checkbox" name="onsite_enabled" value="1" <?php checked( $s['onsite_enabled'] ); ?>><span></span></span></label>
				<div class="osb-paycard__body">
					<div class="osb-field"><label><?php esc_html_e( 'These bookings are', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'onsite_status', $s['onsite_status'], 'select', array( 'confirmed' => __( 'Confirmed straight away', 'osam-bookings' ), 'pending' => __( 'Held until I approve them', 'osam-bookings' ) ) ); ?></div>
				</div>
			</div>
			<div class="osb-paycard <?php echo $s['stripe_enabled'] ? 'is-on' : ''; ?>">
				<label class="osb-paycard__head"><span class="dashicons dashicons-credit-card"></span><span><strong>Stripe</strong><small><?php esc_html_e( 'Cards, Apple Pay, Google Pay', 'osam-bookings' ); ?></small></span><span class="osb-switch"><input type="hidden" name="stripe_enabled" value="0"><input type="checkbox" name="stripe_enabled" value="1" <?php checked( $s['stripe_enabled'] ); ?>><span></span></span></label>
				<div class="osb-paycard__body">
					<div class="osb-field"><label><?php esc_html_e( 'Mode', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'stripe_mode', $s['stripe_mode'], 'select', array( 'test' => __( 'Test', 'osam-bookings' ), 'live' => __( 'Live', 'osam-bookings' ) ) ); ?></div>
					<div class="osb-field"><label><?php esc_html_e( 'Test secret key', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'stripe_test_secret', $s['stripe_test_secret'], 'password', array( 'placeholder' => 'sk_test_…' ) ); ?></div>
					<div class="osb-field"><label><?php esc_html_e( 'Live secret key', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'stripe_live_secret', $s['stripe_live_secret'], 'password', array( 'placeholder' => 'sk_live_…' ) ); ?></div>
					<p class="osb-help"><?php esc_html_e( 'Add the webhook later in Settings → Payments for extra reliability.', 'osam-bookings' ); ?></p>
				</div>
			</div>
			<div class="osb-paycard <?php echo $s['paypal_enabled'] ? 'is-on' : ''; ?>">
				<label class="osb-paycard__head"><span class="dashicons dashicons-money"></span><span><strong>PayPal</strong><small><?php esc_html_e( 'PayPal balance, cards, Pay Later', 'osam-bookings' ); ?></small></span><span class="osb-switch"><input type="hidden" name="paypal_enabled" value="0"><input type="checkbox" name="paypal_enabled" value="1" <?php checked( $s['paypal_enabled'] ); ?>><span></span></span></label>
				<div class="osb-paycard__body">
					<div class="osb-field"><label><?php esc_html_e( 'Mode', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'paypal_mode', $s['paypal_mode'], 'select', array( 'sandbox' => __( 'Sandbox', 'osam-bookings' ), 'live' => __( 'Live', 'osam-bookings' ) ) ); ?></div>
					<div class="osb-field"><label><?php esc_html_e( 'Client ID', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'paypal_client_id', $s['paypal_client_id'] ); ?></div>
					<div class="osb-field"><label><?php esc_html_e( 'Secret', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'paypal_secret', $s['paypal_secret'], 'password' ); ?></div>
				</div>
			</div>
			<div class="osb-paycard <?php echo $s['woo_enabled'] && $woo ? 'is-on' : ''; ?> <?php echo $woo ? '' : 'is-disabled'; ?>">
				<label class="osb-paycard__head"><span class="dashicons dashicons-cart"></span><span><strong>WooCommerce</strong><small><?php echo $woo ? esc_html__( 'Use your shop’s checkout & gateways', 'osam-bookings' ) : esc_html__( 'WooCommerce isn’t active on this site', 'osam-bookings' ); ?></small></span><span class="osb-switch"><input type="hidden" name="woo_enabled" value="0"><input type="checkbox" name="woo_enabled" value="1" <?php checked( $s['woo_enabled'] && $woo ); ?> <?php disabled( ! $woo ); ?>><span></span></span></label>
			</div>
		</div>
		<?php
	}

	/**
	 * Step 5.
	 *
	 * @param array $s Settings.
	 */
	private static function step_emails( $s ) {
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		?>
		<h1><?php esc_html_e( 'Booking emails', 'osam-bookings' ); ?></h1>
		<p class="osb-lead"><?php esc_html_e( 'Customers get a confirmation (with a calendar file and a link to cancel) and a reminder. You get a copy of every booking.', 'osam-bookings' ); ?></p>
		<div class="osb-fieldgrid">
			<div class="osb-field osb-field--half"><label for="w_from"><?php esc_html_e( 'Send emails from', 'osam-bookings' ); ?></label><input type="email" id="w_from" name="email_from_address" value="<?php echo esc_attr( $s['email_from_address'] ); ?>" placeholder="bookings@<?php echo esc_attr( $host ); ?>">
				<p class="osb-help">
				<?php
				/* translators: %s domain */
				printf( esc_html__( 'Best as an address @%s with SPF and DKIM set up, so emails don’t land in spam.', 'osam-bookings' ), esc_html( $host ) );
				?>
				</p></div>
			<div class="osb-field osb-field--half"><label for="w_admin"><?php esc_html_e( 'Send me new bookings at', 'osam-bookings' ); ?></label><input type="text" id="w_admin" name="email_admin_recipients" value="<?php echo esc_attr( $s['email_admin_recipients'] ); ?>"><p class="osb-help"><?php esc_html_e( 'Separate several with commas.', 'osam-bookings' ); ?></p></div>
			<div class="osb-field osb-field--half"><label for="w_rem"><?php esc_html_e( 'Reminder', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'reminder_hours', (string) (int) $s['reminder_hours'], 'select', array( '0' => __( 'Don’t send reminders', 'osam-bookings' ), '2' => __( '2 hours before', 'osam-bookings' ), '24' => __( '1 day before', 'osam-bookings' ), '48' => __( '2 days before', 'osam-bookings' ) ), 'w_rem' ); ?></div>
			<div class="osb-field osb-field--half"><label for="w_accent"><?php esc_html_e( 'Brand colour', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'brand_color', $s['primary_color'], 'color', array(), 'w_accent' ); ?><p class="osb-help"><?php esc_html_e( 'Used for emails and the booking form.', 'osam-bookings' ); ?></p></div>
		</div>
		<?php
	}

	/**
	 * Step 6.
	 *
	 * @param array $s Settings.
	 */
	private static function step_page( $s ) {
		$current = (int) $s['booking_page'];
		$pages   = array( 0 => __( '— Choose a page —', 'osam-bookings' ) );
		foreach ( get_pages( array( 'post_status' => 'publish' ) ) as $p ) {
			$pages[ $p->ID ] = $p->post_title;
		}
		?>
		<h1><?php esc_html_e( 'Where should the booking form go?', 'osam-bookings' ); ?></h1>
		<p class="osb-lead"><?php esc_html_e( 'Pick how you want to add it to the site.', 'osam-bookings' ); ?></p>
		<div class="osb-bigchoice osb-bigchoice--stack">
			<label>
				<input type="radio" name="page_mode" value="existing" <?php checked( $current && get_post( $current ) ); ?>>
				<span><span class="dashicons dashicons-media-document"></span><strong><?php esc_html_e( 'Use a page I already have', 'osam-bookings' ); ?></strong>
					<small><?php esc_html_e( 'We’ll add the booking form to the bottom of it.', 'osam-bookings' ); ?></small>
					<span class="osb-bigchoice__extra"><?php OSB_Admin::input( 'page_id', $current, 'select', $pages ); ?></span></span>
			</label>
			<label>
				<input type="radio" name="page_mode" value="new" <?php checked( ! $current || ! get_post( $current ) ); ?>>
				<span><span class="dashicons dashicons-plus-alt"></span><strong><?php esc_html_e( 'Create a new “Book Online” page', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Published straight away. Add it to your menu afterwards.', 'osam-bookings' ); ?></small></span>
			</label>
			<label>
				<input type="radio" name="page_mode" value="manual">
				<span><span class="dashicons dashicons-shortcode"></span><strong><?php esc_html_e( 'I’ll place it myself', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'With Elementor or a shortcode — the Shortcodes page lists them all.', 'osam-bookings' ); ?></small></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Done screen.
	 */
	private static function done() {
		$page = (int) OSB_Settings::get( 'booking_page' );
		?>
		<div class="osb-card osb-wizard__card osb-wizard__done">
			<span class="osb-done-icon dashicons dashicons-yes-alt"></span>
			<h1><?php esc_html_e( 'You’re ready to take bookings', 'osam-bookings' ); ?></h1>
			<p class="osb-lead"><?php esc_html_e( 'Make a test booking to see what your customers will see — then tweak the look and wording to match your site.', 'osam-bookings' ); ?></p>
			<div class="osb-nextsteps">
				<?php if ( $page && get_post( $page ) ) : ?>
					<a href="<?php echo esc_url( get_permalink( $page ) ); ?>" target="_blank"><span class="dashicons dashicons-visibility"></span><strong><?php esc_html_e( 'View your booking page', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Try a test booking', 'osam-bookings' ); ?></small></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-settings&tab=appearance' ) ); ?>"><span class="dashicons dashicons-art"></span><strong><?php esc_html_e( 'Design the form', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Colours, layout, wording', 'osam-bookings' ); ?></small></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-shortcodes' ) ); ?>"><span class="dashicons dashicons-shortcode"></span><strong><?php esc_html_e( 'Get shortcodes', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Per service, event or style', 'osam-bookings' ); ?></small></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-dashboard' ) ); ?>"><span class="dashicons dashicons-dashboard"></span><strong><?php esc_html_e( 'Go to the dashboard', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Bookings, calendar & more', 'osam-bookings' ); ?></small></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Save a step.
	 */
	public static function save() {
		OSB_Admin::verify( 'osb_wizard', 'manage_options' );
		$post  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$step  = isset( $post['step'] ) ? sanitize_key( $post['step'] ) : '';
		$keys  = array_keys( self::steps() );
		$index = array_search( $step, $keys, true );
		if ( false === $index ) {
			OSB_Admin::redirect( self::url( 'business' ), 'error' );
		}

		switch ( $step ) {
			case 'business':
				$mode = isset( $post['staff_mode'] ) && 'team' === $post['staff_mode'] ? 'team' : 'solo';
				$name = sanitize_text_field( isset( $post['email_from_name'] ) ? $post['email_from_name'] : '' );
				$cur  = sanitize_text_field( isset( $post['currency'] ) ? $post['currency'] : 'GBP' );
				OSB_Settings::update(
					array(
						'staff_mode'      => $mode,
						'email_from_name' => $name ? $name : get_bloginfo( 'name' ),
						'email_footer'    => $name ? $name : get_bloginfo( 'name' ),
						'currency'        => array_key_exists( $cur, OSB_Settings::currencies() ) ? $cur : 'GBP',
					)
				);
				OSB_DB::apply_staff_mode( $mode );
				if ( 'solo' === $mode && $name ) {
					OSB_DB::update( 'staff', OSB_DB::solo_staff_id(), array( 'name' => $name ) );
				}
				break;

			case 'hours':
				$schedule = OSB_Admin_Staff::parse_schedule( isset( $post['schedule'] ) ? $post['schedule'] : array() );
				if ( OSB_DB::is_solo() ) {
					OSB_DB::set_schedule( OSB_DB::solo_staff_id(), $schedule );
				} else {
					foreach ( (array) ( isset( $post['team'] ) ? $post['team'] : array() ) as $row ) {
						$name  = sanitize_text_field( isset( $row['name'] ) ? $row['name'] : '' );
						$email = sanitize_email( isset( $row['email'] ) ? $row['email'] : '' );
						$id    = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
						if ( '' === $name ) {
							continue;
						}
						if ( $id ) {
							OSB_DB::update( 'staff', $id, array( 'name' => $name, 'email' => $email ) );
							continue; // Keep existing people's own hours.
						}
						$id = OSB_DB::insert(
							'staff',
							array(
								'name'       => $name,
								'email'      => $email,
								'status'     => 'active',
								'created_at' => current_time( 'mysql' ),
							)
						);
						OSB_DB::set_schedule( $id, $schedule );
						// New people can deliver every existing service by default.
						foreach ( OSB_DB::services() as $sv ) {
							$prices        = array_map( static function ( $p ) { return null === $p ? '' : $p; }, OSB_DB::service_staff_prices( $sv->id ) );
							$prices[ $id ] = '';
							OSB_DB::set_service_staff( $sv->id, $prices );
						}
					}
				}
				break;

			case 'services':
				$palette = array( '#0273b0', '#0ca678', '#e8590c', '#ae3ec9', '#1098ad', '#f08c00', '#d6336c' );
				$n       = count( OSB_DB::services() );
				$all     = OSB_DB::is_solo() ? array( OSB_DB::solo_staff_id() => '' ) : array_fill_keys( array_map( 'intval', wp_list_pluck( OSB_DB::staff( true ), 'id' ) ), '' );
				foreach ( (array) ( isset( $post['services'] ) ? $post['services'] : array() ) as $row ) {
					$name = sanitize_text_field( isset( $row['name'] ) ? $row['name'] : '' );
					if ( '' === $name ) {
						continue;
					}
					$id = OSB_DB::insert(
						'services',
						array(
							'name'       => $name,
							'duration'   => max( 5, absint( isset( $row['duration'] ) ? $row['duration'] : 60 ) ),
							'price'      => max( 0, (float) ( isset( $row['price'] ) ? $row['price'] : 0 ) ),
							'color'      => $palette[ $n % count( $palette ) ],
							'status'     => 'active',
							'sort_order' => $n,
							'created_at' => current_time( 'mysql' ),
						)
					);
					OSB_DB::set_service_staff( $id, $all );
					$n++;
				}
				break;

			case 'payments':
				$v = array();
				foreach ( array( 'onsite_enabled', 'stripe_enabled', 'paypal_enabled', 'woo_enabled' ) as $k ) {
					$v[ $k ] = ! empty( $post[ $k ] ) ? 1 : 0;
				}
				foreach ( array( 'stripe_test_secret', 'stripe_live_secret', 'paypal_client_id', 'paypal_secret' ) as $k ) {
					$v[ $k ] = trim( sanitize_text_field( isset( $post[ $k ] ) ? $post[ $k ] : '' ) );
				}
				$v['onsite_status'] = isset( $post['onsite_status'] ) && 'pending' === $post['onsite_status'] ? 'pending' : 'confirmed';
				$v['stripe_mode']   = isset( $post['stripe_mode'] ) && 'live' === $post['stripe_mode'] ? 'live' : 'test';
				$v['paypal_mode']   = isset( $post['paypal_mode'] ) && 'live' === $post['paypal_mode'] ? 'live' : 'sandbox';
				OSB_Settings::update( $v );
				break;

			case 'emails':
				$color = sanitize_hex_color( isset( $post['brand_color'] ) ? $post['brand_color'] : '' );
				$v     = array(
					'email_from_address'     => sanitize_email( isset( $post['email_from_address'] ) ? $post['email_from_address'] : '' ),
					'email_admin_recipients' => implode( ', ', array_filter( array_map( 'sanitize_email', explode( ',', sanitize_text_field( isset( $post['email_admin_recipients'] ) ? $post['email_admin_recipients'] : '' ) ) ) ) ),
					'reminder_hours'         => absint( isset( $post['reminder_hours'] ) ? $post['reminder_hours'] : 24 ),
				);
				if ( $color ) {
					$v['email_accent']  = $color;
					$v['primary_color'] = $color;
				}
				OSB_Settings::update( $v );
				break;

			case 'page':
				$mode = isset( $post['page_mode'] ) ? sanitize_key( $post['page_mode'] ) : 'new';
				if ( 'existing' === $mode && ! empty( $post['page_id'] ) ) {
					$page = get_post( absint( $post['page_id'] ) );
					if ( $page && 'page' === $page->post_type ) {
						if ( ! has_shortcode( $page->post_content, 'osam_booking' ) && false === strpos( (string) get_post_meta( $page->ID, '_elementor_data', true ), 'osam_booking' ) ) {
							wp_update_post(
								array(
									'ID'           => $page->ID,
									'post_content' => $page->post_content . "\n\n<!-- wp:shortcode -->[osam_booking]<!-- /wp:shortcode -->",
								)
							);
						}
						OSB_Settings::update( array( 'booking_page' => $page->ID ) );
					}
				} elseif ( 'new' === $mode ) {
					$current = (int) OSB_Settings::get( 'booking_page' );
					$cpost   = $current ? get_post( $current ) : null;
					if ( ! $cpost || 'trash' === $cpost->post_status || ! has_shortcode( $cpost->post_content, 'osam_booking' ) ) {
						$id = wp_insert_post(
							array(
								'post_title'   => __( 'Book Online', 'osam-bookings' ),
								'post_content' => '<!-- wp:shortcode -->[osam_booking]<!-- /wp:shortcode -->',
								'post_status'  => 'publish',
								'post_type'    => 'page',
							)
						);
						if ( $id && ! is_wp_error( $id ) ) {
							OSB_Settings::update( array( 'booking_page' => (int) $id ) );
						}
					}
				}
				update_option( 'osb_setup_done', 1 );
				OSB_Availability::bust_cache();
				wp_safe_redirect( self::url( 'done' ) );
				exit;
		}

		OSB_Availability::bust_cache();
		wp_safe_redirect( self::url( $keys[ $index + 1 ] ) );
		exit;
	}
}
