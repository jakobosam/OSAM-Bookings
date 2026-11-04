<?php
/**
 * Front-end shortcode and assets.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * [osam_booking] shortcode.
 */
class OSB_Frontend {

	/**
	 * Has the result/manage view been claimed by an instance on this page?
	 *
	 * @var bool
	 */
	private static $result_claimed = false;

	/**
	 * Instance counter.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( 'osam_booking', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'osam_courses', array( __CLASS__, 'courses_shortcode' ) );
		add_shortcode( 'osam_course', array( __CLASS__, 'courses_shortcode' ) );
		add_shortcode( 'osam_table_booking', array( __CLASS__, 'tables_shortcode' ) );
		add_shortcode( 'osam_resources', array( __CLASS__, 'resources_shortcode' ) );
		add_shortcode( 'osam_bundles', array( __CLASS__, 'bundles_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register (enqueue happens on render).
	 */
	public static function register_assets() {
		wp_register_style( 'osb-booking', OSB_URL . 'assets/css/booking.css', array(), OSB_VERSION );
		wp_register_script( 'osb-booking', OSB_URL . 'assets/js/booking.js', array(), OSB_VERSION, true );
	}

	/**
	 * CSS variables + custom CSS.
	 *
	 * @return string
	 */
	public static function inline_css() {
		$s     = OSB_Settings::all();
		$color = static function ( $value, $fallback ) {
			$c = sanitize_hex_color( (string) $value );
			return $c ? $c : $fallback;
		};
		$fonts = array(
			'inherit' => 'inherit',
			'system'  => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
			'serif'   => 'Georgia, "Times New Roman", serif',
		);
		$css = sprintf(
			'.osb-booking{--osb-accent:%s;--osb-on-accent:%s;--osb-text:%s;--osb-bg:%s;--osb-radius:%dpx;--osb-font-size:%dpx;font-family:%s;%s}',
			$color( $s['primary_color'], '#161a40' ),
			$color( $s['on_accent_color'], '#ffffff' ),
			$color( $s['text_color'], '#1f2933' ),
			$color( $s['background_color'], '#ffffff' ),
			(int) $s['border_radius'],
			max( 12, min( 22, (int) $s['font_size'] ) ),
			isset( $fonts[ $s['font_family'] ] ) ? $fonts[ $s['font_family'] ] : 'inherit',
			(int) $s['max_width'] > 0 ? 'max-width:' . (int) $s['max_width'] . 'px;margin-left:auto;margin-right:auto;' : ''
		);
		return $css . "\n" . wp_strip_all_tags( (string) $s['custom_css'] );
	}

	/**
	 * CSS classes for the container from settings, overridable per shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string[]
	 */
	public static function display_classes( array $atts = array() ) {
		$s       = OSB_Settings::all();
		$choices = OSB_Settings::display_choices();
		$pick    = static function ( $attr, $key ) use ( $atts, $s, $choices ) {
			$v = isset( $atts[ $attr ] ) && '' !== $atts[ $attr ] ? $atts[ $attr ] : $s[ $key ];
			return isset( $choices[ $key ][ $v ] ) ? $v : key( $choices[ $key ] );
		};

		$classes   = array();
		$classes[] = 'osb-design-' . $pick( 'design', 'design' );
		$classes[] = 'osb-layout-' . $pick( 'layout', 'service_layout' );
		$classes[] = 'osb-cols-' . $pick( 'columns', 'service_columns' );
		$classes[] = 'osb-btn-' . $pick( 'buttons', 'button_shape' );
		$classes[] = 'osb-density-' . $pick( 'density', 'density' );

		foreach ( OSB_Settings::display_toggles() as $key => $def ) {
			$attr = $def[0];
			$on   = (bool) $s[ $key ];
			if ( isset( $atts[ $attr ] ) && '' !== $atts[ $attr ] ) {
				$on = ! in_array( strtolower( (string) $atts[ $attr ] ), array( 'no', 'false', '0', 'off', 'hide' ), true );
			}
			if ( ! $on ) {
				$classes[] = 'osb-hide-' . str_replace( '_', '-', $attr );
			}
		}
		return apply_filters( 'osb_display_classes', $classes, $atts );
	}

	/**
	 * Shared JS config.
	 *
	 * @return array
	 */
	public static function global_config() {
		global $wp_locale;
		$months   = array();
		$weekdays = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$months[] = $wp_locale->get_month( $m );
		}
		for ( $d = 0; $d < 7; $d++ ) {
			$weekdays[] = $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $d ) );
		}
		$fields = array();
		foreach ( OSB_Settings::form_fields() as $f ) {
			$f['options'] = array_values( array_filter( array_map( 'trim', explode( "\n", (string) $f['options'] ) ), 'strlen' ) );
			$fields[]     = $f;
		}

		return array(
			'rest'        => esc_url_raw( rest_url( OSB_Rest::NS ) ),
			'labels'      => OSB_Settings::get( 'labels' ),
			'fields'      => $fields,
			'terms'       => wp_kses( (string) OSB_Settings::get( 'terms_text' ), array( 'a' => array( 'href' => array(), 'target' => array() ), 'strong' => array(), 'em' => array() ) ),
			'anyStaff'    => (bool) OSB_Settings::get( 'any_staff_option' ),
			'showStaff'   => (bool) OSB_Settings::get( 'show_staff_step' ),
			'solo'        => OSB_DB::is_solo(),
			'coupons'     => OSB_Coupons::any_active(),
			'addons'      => OSB_Addons::json_all(),
			'bundles'     => OSB_Bundles::enabled() && OSB_Bundles::all( true ),
			'repeat'      => array(
				'types' => array_values( array_filter( array( 'service', 'resource', 'table' ), array( 'OSB_Repeat', 'allowed' ) ) ),
				'freqs' => OSB_Repeat::offered(),
				'max'   => OSB_Repeat::max(),
			),
			'portalUrl'   => OSB_Portal::url(),
			'features'    => array(
				'events'  => OSB_Settings::feature( 'events' ),
				'courses' => OSB_Settings::feature( 'courses' ),
				'tables'  => OSB_Settings::feature( 'restaurant' ),
				'resources' => OSB_Settings::feature( 'resources' ),
			),
			'locale'      => str_replace( '_', '-', get_locale() ),
			'startOfWeek' => (int) get_option( 'start_of_week', 1 ),
			'months'      => $months,
			'weekdays'    => $weekdays,
			'currency'    => array(
				'code'     => OSB_Settings::get( 'currency' ),
				'symbol'   => isset( OSB_Settings::currencies()[ OSB_Settings::get( 'currency' ) ] ) ? OSB_Settings::currencies()[ OSB_Settings::get( 'currency' ) ] : OSB_Settings::get( 'currency' ) . ' ',
				'position' => OSB_Settings::get( 'currency_position' ),
			),
			'i18n'        => array(
				'loading'       => __( 'Loading…', 'osam-bookings' ),
				'error'         => __( 'Something went wrong. Please try again.', 'osam-bookings' ),
				'required'      => __( 'Please fill in the required fields.', 'osam-bookings' ),
				'terms'         => __( 'Please accept the terms to continue.', 'osam-bookings' ),
				'chooseMethod'  => __( 'Please choose how you would like to pay.', 'osam-bookings' ),
				'minutes'       => __( 'min', 'osam-bookings' ),
				'from'          => __( 'from', 'osam-bookings' ),
				'total'         => __( 'Total', 'osam-bookings' ),
				'with'          => __( 'With', 'osam-bookings' ),
				'when'          => __( 'When', 'osam-bookings' ),
				'reference'     => __( 'Reference', 'osam-bookings' ),
				'status'        => __( 'Status', 'osam-bookings' ),
				'payment'       => __( 'Payment', 'osam-bookings' ),
				'prevMonth'     => __( 'Previous month', 'osam-bookings' ),
				'nextMonth'     => __( 'Next month', 'osam-bookings' ),
				'all'           => __( 'All', 'osam-bookings' ),
				'bookAnother'   => __( 'Make another booking', 'osam-bookings' ),
				'cancelBooking' => __( 'Cancel this booking', 'osam-bookings' ),
				'cancelConfirm' => __( 'Are you sure you want to cancel this booking?', 'osam-bookings' ),
				'cancelledNow'  => __( 'Your booking has been cancelled.', 'osam-bookings' ),
				'yourBooking'   => __( 'Your booking', 'osam-bookings' ),
				'paymentCheck'  => __( 'We are still confirming your payment — you will receive an email shortly.', 'osam-bookings' ),
				'redirecting'   => __( 'Taking you to payment…', 'osam-bookings' ),
				'noServices'    => __( 'There are no services available to book right now.', 'osam-bookings' ),
				'pickDate'      => __( 'Choose a date to see available times.', 'osam-bookings' ),
				'location'      => __( 'Location', 'osam-bookings' ),
				'starts'        => __( 'Starts', 'osam-bookings' ),
				'reschedule'        => __( 'Change date or time', 'osam-bookings' ),
				'rescheduleTitle'   => __( 'Choose a new time', 'osam-bookings' ),
				'rescheduleCheck'   => __( 'Move your booking?', 'osam-bookings' ),
				'rescheduleFrom'    => __( 'Was', 'osam-bookings' ),
				'rescheduleTo'      => __( 'Now', 'osam-bookings' ),
				'rescheduleConfirm' => __( 'Move my booking', 'osam-bookings' ),
				'keepBooking'       => __( 'Keep my booking as it is', 'osam-bookings' ),
				'rescheduledNow'    => __( 'Done — your booking has been moved and we’ve emailed you the new details.', 'osam-bookings' ),
				'joinOnline'        => __( 'Join online', 'osam-bookings' ),
				/* translators: %s e.g. "Invoice INV-0001" */
				'downloadInvoice'   => __( 'Download %s', 'osam-bookings' ),
				'wlHeld'            => __( 'We’re holding a place for you until %s.', 'osam-bookings' ),
				'wlExpired'         => __( 'Sorry — the place we held for you is no longer available. You can still book anything that’s free below.', 'osam-bookings' ),
				/* translators: %d number of people */
				'wlAhead'           => __( '%d people are ahead of you.', 'osam-bookings' ),
				'wlAheadOne'        => __( 'One person is ahead of you.', 'osam-bookings' ),
				'yourName'          => __( 'Your name', 'osam-bookings' ),
				'email'             => __( 'Email', 'osam-bookings' ),
				'phone'             => __( 'Phone', 'osam-bookings' ),
				'haveCode'          => __( 'Have a discount code or gift voucher?', 'osam-bookings' ),
				'online'            => __( 'Online', 'osam-bookings' ),
				'whoComing'         => __( 'Who’s coming?', 'osam-bookings' ),
				'guestsSame'        => __( 'Use my details for every place', 'osam-bookings' ),
				/* translators: %d place number */
				'place'             => __( 'Place %d', 'osam-bookings' ),
				'placeYou'          => __( 'Your details (place 1)', 'osam-bookings' ),
				'attendees'         => __( 'Attendees', 'osam-bookings' ),
				'couponLabel'       => __( 'Discount code or gift voucher', 'osam-bookings' ),
				'apply'             => __( 'Apply', 'osam-bookings' ),
				'remove'            => __( 'Remove', 'osam-bookings' ),
				'subtotal'          => __( 'Subtotal', 'osam-bookings' ),
				'discount'          => __( 'Discount', 'osam-bookings' ),
				'giftVoucher'       => __( 'Gift voucher', 'osam-bookings' ),
				'sessionsLabel' => __( 'Sessions', 'osam-bookings' ),
				'moreGuests'    => __( 'More', 'osam-bookings' ),
				'resPickHint'   => __( 'Pick one, or several to book together.', 'osam-bookings' ),
				/* translators: %d places */
				'resUpTo'       => __( 'Up to %d', 'osam-bookings' ),
				'resSoleUse'    => __( 'Sole use', 'osam-bookings' ),
				'resHow'        => __( 'Booking', 'osam-bookings' ),
				'resUse'        => __( 'Use', 'osam-bookings' ),
				'hour'          => __( 'hour', 'osam-bookings' ),
				'hours'         => __( 'hours', 'osam-bookings' ),
				/* translators: %s phone number */
				'callUs'        => __( 'Call us on %s.', 'osam-bookings' ),
				'cardHold'      => __( 'No-show fee', 'osam-bookings' ),
				'depositNow'    => __( 'Deposit to pay now', 'osam-bookings' ),
				'sendRequest'   => __( 'Send booking request', 'osam-bookings' ),
				'saveCard'      => __( 'Continue to secure with card', 'osam-bookings' ),
				'extras'        => __( 'Extras', 'osam-bookings' ),
				'extrasHint'    => __( 'Add anything you’d like with your booking.', 'osam-bookings' ),
				'included'      => __( 'Included', 'osam-bookings' ),
				'repeatAsk'     => __( 'Make this a regular booking', 'osam-bookings' ),
				'repeatHow'     => __( 'How often', 'osam-bookings' ),
				'repeatTimes'   => __( 'Number of dates', 'osam-bookings' ),
				'repeatChecking' => __( 'Checking the dates…', 'osam-bookings' ),
				'repeatFree'    => __( 'Free', 'osam-bookings' ),
				'repeatTaken'   => __( 'Not available — will be skipped', 'osam-bookings' ),
				/* translators: 1: dates that can be booked 2: dates asked for */
				'repeatSummary' => __( '%1$d of %2$d dates available', 'osam-bookings' ),
				'repeats'       => __( 'Repeats', 'osam-bookings' ),
				'dates'         => __( 'Dates', 'osam-bookings' ),
				'allDates'      => __( 'All dates', 'osam-bookings' ),
				/* translators: %s dates */
				'skippedNote'   => __( 'These dates weren’t available and weren’t booked: %s.', 'osam-bookings' ),
				'cancelAll'     => __( 'Cancel this and later dates', 'osam-bookings' ),
				'cancelAllConfirm' => __( 'Cancel this date and every later date in the series?', 'osam-bookings' ),
				'haveBundle'    => __( 'Paying with a bundle?', 'osam-bookings' ),
				'bundleLabel'   => __( 'Bundle code', 'osam-bookings' ),
				'paidWith'      => __( 'Paid with', 'osam-bookings' ),
				'tabBundles'    => __( 'Bundles', 'osam-bookings' ),
				'stepBundle'    => __( 'Choose a bundle', 'osam-bookings' ),
				'buyBundle'     => __( 'Buy bundle', 'osam-bookings' ),
				'covers'        => __( 'Use for', 'osam-bookings' ),
				'includes'      => __( 'Includes', 'osam-bookings' ),
				'bundleCode'    => __( 'Your bundle code', 'osam-bookings' ),
				'bundleUse'     => __( 'Enter this code when you book. We’ve emailed it to you too.', 'osam-bookings' ),
				'useBy'         => __( 'Use by', 'osam-bookings' ),
				'left'          => __( 'Left', 'osam-bookings' ),
				'myBookings'    => __( 'See all your bookings', 'osam-bookings' ),
				'noBundles'     => __( 'There are no bundles on sale right now.', 'osam-bookings' ),
				'previewOnly'   => __( 'Preview only — bookings are not saved from the settings screen.', 'osam-bookings' ),
			),
		);
	}

	/**
	 * Enqueue assets once.
	 */
	public static function enqueue() {
		self::prepare();
		wp_enqueue_style( 'osb-booking' );
		wp_enqueue_script( 'osb-booking' );
	}

	/**
	 * Register assets and attach config/inline CSS exactly once (also used by Elementor dependencies).
	 */
	public static function prepare() {
		static $done = false;
		if ( ! wp_script_is( 'osb-booking', 'registered' ) ) {
			self::register_assets();
		}
		if ( $done ) {
			return;
		}
		$done = true;
		wp_add_inline_style( 'osb-booking', self::inline_css() );
		// JSON (not wp_localize_script) so numbers and booleans keep their types.
		wp_add_inline_script( 'osb-booking', 'window.OSB_BOOKING = ' . wp_json_encode( self::global_config() ) . ';', 'before' );
	}

	/**
	 * [osam_courses] and [osam_course id="3"] — shorthand for [osam_booking type="courses" course="…"].
	 *
	 * @param array $atts Attributes (any [osam_booking] display attribute also works).
	 * @return string
	 */
	public static function courses_shortcode( $atts ) {
		$atts = is_array( $atts ) ? $atts : array();
		if ( isset( $atts['id'] ) && ! isset( $atts['course'] ) ) {
			$atts['course'] = $atts['id'];
		}
		unset( $atts['id'] );
		$atts['type'] = 'courses';
		return self::shortcode( $atts );
	}

	/**
	 * [osam_resources] — shorthand for [osam_booking type="resources"]; id="3,4" limits the list.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function resources_shortcode( $atts ) {
		$atts = is_array( $atts ) ? $atts : array();
		if ( isset( $atts['id'] ) && ! isset( $atts['resource'] ) ) {
			$atts['resource'] = $atts['id'];
		}
		unset( $atts['id'] );
		$atts['type'] = 'resources';
		return self::shortcode( $atts );
	}

	/**
	 * [osam_bundles] — bundles for sale; id="1,2" limits the list.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function bundles_shortcode( $atts ) {
		$atts         = is_array( $atts ) ? $atts : array();
		$atts['type'] = 'bundles';
		if ( isset( $atts['id'] ) ) {
			$atts['bundle'] = $atts['id'];
			unset( $atts['id'] );
		}
		return self::shortcode( $atts );
	}

	/**
	 * [osam_table_booking] — shorthand for [osam_booking type="tables"].
	 *
	 * @param array $atts Attributes (any [osam_booking] display attribute also works, plus covers="2").
	 * @return string
	 */
	public static function tables_shortcode( $atts ) {
		$atts         = is_array( $atts ) ? $atts : array();
		$atts['type'] = 'tables';
		return self::shortcode( $atts );
	}

	/**
	 * Shortcode output.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'type'     => 'all',
				'service'  => '',
				'staff'    => '',
				'category' => '',
				'event'    => '',
				'course'   => '',
				'covers'   => '',
				'resource' => '',
				'bundle'   => '',
				'class'        => '',
				'style'        => '',
				'accent'       => '',
				'design'       => '',
				'layout'       => '',
				'columns'      => '',
				'buttons'      => '',
				'density'      => '',
				'images'       => '',
				'descriptions' => '',
				'durations'    => '',
				'prices'       => '',
				'filter'       => '',
				'progress'     => '',
				'summary'      => '',
				'seats_left'   => '',
				'preview'      => '',
			),
			$atts,
			'osam_booking'
		);
		// Subscription suspended: no booking form, but people following a "manage your booking"
		// link can still see and cancel what they have.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$off = OSB_Licence::bookings_off() && ! $atts['preview'];
		if ( $off && empty( $_GET['osb_booking'] ) ) {
			return OSB_Licence::placeholder_html();
		}
		self::enqueue();
		self::$instance++;

		$type = in_array( $atts['type'], array( 'all', 'services', 'events', 'courses', 'tables', 'resources', 'bundles' ), true ) ? $atts['type'] : 'all';
		if ( 'all' === $type && '' !== $atts['resource'] && '' === $atts['service'] && '' === $atts['event'] && '' === $atts['course'] ) {
			$type = 'resources';
		}
		if ( 'all' === $type ) {
			// Limiting to one kind of item implies that kind.
			$given = array_filter(
				array(
					'services' => '' !== $atts['service'],
					'events'   => '' !== $atts['event'],
					'courses'  => '' !== $atts['course'],
				)
			);
			if ( 1 === count( $given ) ) {
				$type = key( $given );
			}
		}

		$config = array(
			'mode'     => $type,
			'services' => array_values( array_filter( array_map( 'absint', explode( ',', (string) $atts['service'] ) ) ) ),
			'events'   => array_values( array_filter( array_map( 'absint', explode( ',', (string) $atts['event'] ) ) ) ),
			'courses'  => array_values( array_filter( array_map( 'absint', explode( ',', (string) $atts['course'] ) ) ) ),
			'staff'    => absint( $atts['staff'] ),
			'covers'   => absint( $atts['covers'] ),
			'resources' => array_values( array_filter( array_map( 'absint', explode( ',', (string) $atts['resource'] ) ) ) ),
			'bundles'  => array_values( array_filter( array_map( 'absint', explode( ',', (string) $atts['bundle'] ) ) ) ),
			'category' => sanitize_text_field( $atts['category'] ),
			'returnUrl' => esc_url_raw( get_permalink() ? get_permalink() : home_url( add_query_arg( array() ) ) ),
			'result'   => null,
			'preview'  => (bool) $atts['preview'],
			'off'      => $off,
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! $config['preview'] && ! self::$result_claimed && ! empty( $_GET['osb_booking'] ) ) {
			self::$result_claimed = true;
			$config['result']     = array(
				'token'   => sanitize_text_field( wp_unslash( $_GET['osb_booking'] ) ),
				'outcome' => isset( $_GET['osb_outcome'] ) ? sanitize_key( wp_unslash( $_GET['osb_outcome'] ) ) : '',
			);
		}
		// phpcs:enable

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $config['preview'] && ! empty( $_GET['osb_waitlist'] ) && ! $config['result'] ) {
			$config['waitlist'] = preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_GET['osb_waitlist'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$config = apply_filters( 'osb_shortcode_config', $config, $atts );

		$style  = (string) $atts['style'];
		$accent = sanitize_hex_color( (string) $atts['accent'] );
		if ( $accent ) {
			$style = '--osb-accent:' . $accent . ';' . $style;
		}
		$style = $style ? ' style="' . esc_attr( $style ) . '"' : '';

		$classes = array_merge( self::display_classes( $atts ), array_filter( explode( ' ', (string) $atts['class'] ) ) );

		return sprintf(
			'<div class="osb-booking %s" id="osb-booking-%d" data-config="%s"%s><div class="osb-loading">%s</div><noscript>%s</noscript></div>',
			esc_attr( implode( ' ', array_map( 'sanitize_html_class', $classes ) ) ),
			self::$instance,
			esc_attr( wp_json_encode( $config ) ),
			$style,
			esc_html__( 'Loading…', 'osam-bookings' ),
			esc_html__( 'Please enable JavaScript to make a booking.', 'osam-bookings' )
		);
	}
}
