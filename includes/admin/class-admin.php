<?php
/**
 * Admin bootstrap and shared helpers.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menus, assets and small UI helpers.
 */
class OSB_Admin {

	const CAP = 'osb_manage_bookings';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OSB_FILE ), array( __CLASS__, 'action_links' ) );

		OSB_Admin_Bookings::init();
		OSB_Admin_Services::init();
		OSB_Admin_Staff::init();
		OSB_Admin_Events::init();
		OSB_Admin_Courses::init();
		OSB_Admin_Restaurant::init();
		OSB_Admin_Resources::init();
		OSB_Admin_Calendars::init();
		OSB_Admin_Coupons::init();
		OSB_Admin_Addons::init();
		OSB_Admin_Bundles::init();
		OSB_Admin_Waitlist::init();
		OSB_Admin_Customers::init();
		OSB_Admin_Import::init();
		OSB_Admin_Reports::init();
		OSB_Admin_Invoices::init();
		OSB_Admin_Settings::init();
		OSB_Admin_Custom_Emails::init();
		OSB_Admin_Wizard::init();
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		$pending = self::pending_count();
		$badge   = $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '';

		add_menu_page( __( 'Bookings', 'osam-bookings' ), __( 'Bookings', 'osam-bookings' ) . $badge, self::CAP, 'osb-dashboard', array( 'OSB_Admin_Dashboard', 'page' ), 'dashicons-calendar-alt', 26 );
		$sections = self::sections();
		$order    = array();
		foreach ( self::groups() as $g ) {
			$order = array_merge( $order, $g['items'] );
		}
		$order = array_unique( array_merge( $order, array_keys( $sections ) ) );

		// The WordPress sidebar shows one link per area (plus the calendar); the rest of each area
		// is in the tabs at the top of every plugin screen. Every screen stays registered so its
		// address still works.
		$labels = array();
		$parent = array();
		foreach ( self::groups() as $g ) {
			$lead = '';
			foreach ( $g['items'] as $slug ) {
				if ( ! isset( $sections[ $slug ] ) || ! current_user_can( $sections[ $slug ]['cap'] ) ) {
					continue;
				}
				if ( '' === $lead ) {
					$lead            = $slug;
					$labels[ $slug ] = $g['title'];
				} elseif ( 'osb-calendar' !== $slug ) {
					$parent[ $slug ] = $lead;
				}
			}
		}
		foreach ( $order as $slug ) {
			if ( isset( $sections[ $slug ] ) ) {
				$section = $sections[ $slug ];
				add_submenu_page( 'osb-dashboard', $section['title'], isset( $labels[ $slug ] ) ? $labels[ $slug ] : $section['menu'], $section['cap'], $slug, $section['callback'] );
			}
		}
		self::$tucked = $parent;
		add_action(
			'admin_head',
			static function () {
				if ( ! self::$tucked ) {
					return;
				}
				$sel = array();
				foreach ( array_keys( self::$tucked ) as $slug ) {
					$sel[] = '#adminmenu a[href="admin.php?page=' . $slug . '"]';
				}
				echo '<style>' . implode( ',', $sel ) . '{display:none}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed slugs.
			}
		);
		// Keep the right sidebar link lit while on a screen that isn't listed there.
		add_filter(
			'submenu_file',
			static function ( $file ) {
				$page = self::current_section();
				return isset( self::$tucked[ $page ] ) ? self::$tucked[ $page ] : $file;
			}
		);
	}

	/**
	 * Screens left out of the WordPress sidebar: slug => the sidebar link that stands for them.
	 *
	 * @var array
	 */
	private static $tucked = array();

	/**
	 * Plugin sections, used for both the WP menu and the in-page navigation.
	 *
	 * @return array
	 */
	public static function sections() {
		$sections = array(
			'osb-dashboard'  => array(
				'title'    => __( 'Dashboard', 'osam-bookings' ),
				'menu'     => __( 'Dashboard', 'osam-bookings' ),
				'icon'     => 'dashicons-dashboard',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Dashboard', 'page' ),
			),
			'osb-bookings'   => array(
				'title'    => __( 'Bookings', 'osam-bookings' ),
				'menu'     => __( 'All bookings', 'osam-bookings' ),
				'icon'     => 'dashicons-list-view',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Bookings', 'page' ),
			),
			'osb-calendar'   => array(
				'title'    => __( 'Calendar', 'osam-bookings' ),
				'menu'     => __( 'Calendar', 'osam-bookings' ),
				'icon'     => 'dashicons-calendar-alt',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Bookings', 'calendar_page' ),
			),
			'osb-services'   => array(
				'title'    => __( 'Services', 'osam-bookings' ),
				'menu'     => __( 'Services', 'osam-bookings' ),
				'icon'     => 'dashicons-clipboard',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Services', 'page' ),
			),
			'osb-staff'      => array(
				'title'    => OSB_DB::is_solo() ? __( 'Working hours', 'osam-bookings' ) : __( 'Staff & hours', 'osam-bookings' ),
				'menu'     => OSB_DB::is_solo() ? __( 'Working hours', 'osam-bookings' ) : __( 'Staff & hours', 'osam-bookings' ),
				'icon'     => 'dashicons-groups',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Staff', 'page' ),
			),
			'osb-events'     => array(
				'title'    => __( 'Events', 'osam-bookings' ),
				'menu'     => __( 'Events', 'osam-bookings' ),
				'icon'     => 'dashicons-tickets-alt',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Events', 'page' ),
			),
			'osb-courses'    => array(
				'title'    => __( 'Courses', 'osam-bookings' ),
				'menu'     => __( 'Courses', 'osam-bookings' ),
				'icon'     => 'dashicons-welcome-learn-more',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Courses', 'page' ),
			),
			'osb-resources'  => array(
				'title'    => __( 'Resources', 'osam-bookings' ),
				'menu'     => __( 'Resources', 'osam-bookings' ),
				'icon'     => 'dashicons-building',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Resources', 'page' ),
			),
			'osb-res-schedule' => array(
				'title'    => __( 'Resource schedule', 'osam-bookings' ),
				'menu'     => __( 'Resource schedule', 'osam-bookings' ),
				'icon'     => 'dashicons-calendar-alt',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Resources', 'schedule_page' ),
			),
			'osb-restaurant' => array(
				'title'    => __( 'Live service', 'osam-bookings' ),
				'menu'     => __( 'Live service', 'osam-bookings' ),
				'icon'     => 'dashicons-food',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Restaurant', 'service_page' ),
			),
			'osb-floor'      => array(
				'title'    => __( 'Tables & floor plan', 'osam-bookings' ),
				'menu'     => __( 'Tables & floor plan', 'osam-bookings' ),
				'icon'     => 'dashicons-layout',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Restaurant', 'floor_page' ),
			),
			'osb-rest-hours' => array(
				'title'    => __( 'Opening times & rules', 'osam-bookings' ),
				'menu'     => __( 'Opening times & rules', 'osam-bookings' ),
				'icon'     => 'dashicons-clock',
				'cap'      => 'manage_options',
				'callback' => array( 'OSB_Admin_Restaurant', 'hours_page' ),
			),
			'osb-waitlist'   => array(
				'title'    => __( 'Waitlist', 'osam-bookings' ),
				'menu'     => __( 'Waitlist', 'osam-bookings' ),
				'icon'     => 'dashicons-clock',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Waitlist', 'page' ),
			),
			'osb-addons'     => array(
				'title'    => __( 'Add-ons', 'osam-bookings' ),
				'menu'     => __( 'Add-ons', 'osam-bookings' ),
				'icon'     => 'dashicons-plus-alt',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Addons', 'page' ),
			),
			'osb-bundles'    => array(
				'title'    => __( 'Bundles', 'osam-bookings' ),
				'menu'     => __( 'Bundles', 'osam-bookings' ),
				'icon'     => 'dashicons-tickets',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Bundles', 'page' ),
			),
			'osb-coupons'    => array(
				'title'    => __( 'Codes & vouchers', 'osam-bookings' ),
				'menu'     => __( 'Codes & vouchers', 'osam-bookings' ),
				'icon'     => 'dashicons-tag',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Coupons', 'page' ),
			),
			'osb-customers'  => array(
				'title'    => __( 'Customers', 'osam-bookings' ),
				'menu'     => __( 'Customers', 'osam-bookings' ),
				'icon'     => 'dashicons-id',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Customers', 'page' ),
			),
			'osb-invoices'   => array(
				'title'    => __( 'Invoices', 'osam-bookings' ),
				'menu'     => __( 'Invoices', 'osam-bookings' ),
				'icon'     => 'dashicons-media-spreadsheet',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Invoices', 'page' ),
			),
			'osb-reports'    => array(
				'title'    => __( 'Reports', 'osam-bookings' ),
				'menu'     => __( 'Reports', 'osam-bookings' ),
				'icon'     => 'dashicons-chart-bar',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Reports', 'page' ),
			),
			'osb-emails'     => array(
				'title'    => __( 'Emails', 'osam-bookings' ),
				'menu'     => __( 'Emails', 'osam-bookings' ),
				'icon'     => 'dashicons-email-alt2',
				'cap'      => 'manage_options',
				'callback' => array( 'OSB_Admin_Custom_Emails', 'page' ),
			),
			'osb-shortcodes' => array(
				'title'    => __( 'Shortcodes', 'osam-bookings' ),
				'menu'     => __( 'Shortcodes', 'osam-bookings' ),
				'icon'     => 'dashicons-shortcode',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Shortcodes', 'page' ),
			),
			'osb-settings'   => array(
				'title'    => __( 'Settings', 'osam-bookings' ),
				'menu'     => __( 'Settings', 'osam-bookings' ),
				'icon'     => 'dashicons-admin-generic',
				'cap'      => 'manage_options',
				'callback' => array( 'OSB_Admin_Settings', 'page' ),
			),
			'osb-import'     => array(
				'title'    => __( 'Import', 'osam-bookings' ),
				'menu'     => __( 'Import', 'osam-bookings' ),
				'icon'     => 'dashicons-upload',
				'cap'      => self::CAP,
				'callback' => array( 'OSB_Admin_Import', 'page' ),
			),
		);
		// Parts that are switched off in Settings → Features disappear from the menus.
		$gated = array(
			'osb-events'    => 'events',
			'osb-courses'   => 'courses',
			'osb-waitlist'  => 'waitlist',
			'osb-coupons'   => 'coupons',
			'osb-addons'    => 'addons',
			'osb-bundles'   => 'bundles',
			'osb-customers' => 'customers',
			'osb-reports'   => 'reports',
			'osb-invoices'  => 'invoices',
			'osb-restaurant' => 'restaurant',
			'osb-floor'      => 'restaurant',
			'osb-resources'  => 'resources',
			'osb-res-schedule' => 'resources',
			'osb-rest-hours' => 'restaurant',
		);
		foreach ( $gated as $slug => $feature ) {
			if ( isset( $sections[ $slug ] ) && ! OSB_Settings::feature( $feature ) ) {
				unset( $sections[ $slug ] );
			}
		}
		if ( OSB_Licence::full_off() ) {
			// Subscription suspended (full): every screen shows the suspension notice instead.
			foreach ( $sections as $slug => $section ) {
				$sections[ $slug ]['callback'] = array( 'OSB_Licence', 'locked_page' );
			}
		}
		return $sections;
	}

	/**
	 * How sections are grouped in the plugin's own navigation (and ordered in the WP menu).
	 *
	 * @return array key => [ title, icon, items[], no_subnav? ]
	 */
	public static function groups() {
		$solo   = OSB_DB::is_solo();
		$extras = OSB_Settings::feature( 'events' ) || OSB_Settings::feature( 'courses' );
		return array(
			'overview'  => array( 'title' => __( 'Dashboard', 'osam-bookings' ), 'icon' => 'dashicons-dashboard', 'items' => array( 'osb-dashboard', 'osb-reports' ) ),
			'bookings'  => array( 'title' => __( 'Bookings', 'osam-bookings' ), 'icon' => 'dashicons-calendar-alt', 'items' => array( 'osb-bookings', 'osb-calendar', 'osb-waitlist' ) ),
			'resources' => array( 'title' => __( 'Resources', 'osam-bookings' ), 'icon' => 'dashicons-building', 'items' => array( 'osb-res-schedule', 'osb-resources' ) ),
			'restaurant' => array( 'title' => __( 'Restaurant', 'osam-bookings' ), 'icon' => 'dashicons-food', 'items' => array( 'osb-restaurant', 'osb-floor', 'osb-rest-hours' ) ),
			'customers' => array( 'title' => __( 'Customers', 'osam-bookings' ), 'icon' => 'dashicons-id', 'items' => array( 'osb-customers', 'osb-bundles', 'osb-coupons' ) ),
			'invoices'  => array( 'title' => __( 'Invoices', 'osam-bookings' ), 'icon' => 'dashicons-media-spreadsheet', 'items' => array( 'osb-invoices' ) ),
			'offer'     => array( 'title' => $extras ? __( 'Services & events', 'osam-bookings' ) : __( 'Services', 'osam-bookings' ), 'icon' => 'dashicons-clipboard', 'items' => array( 'osb-services', 'osb-events', 'osb-courses', 'osb-addons' ) ),
			'team'      => array( 'title' => $solo ? __( 'Working hours', 'osam-bookings' ) : __( 'Staff & hours', 'osam-bookings' ), 'icon' => 'dashicons-groups', 'items' => array( 'osb-staff' ) ),
			'settings'  => array( 'title' => __( 'Settings', 'osam-bookings' ), 'icon' => 'dashicons-admin-generic', 'items' => array( 'osb-settings', 'osb-emails', 'osb-shortcodes', 'osb-import' ) ),
		);
	}

	/**
	 * Groups with only the sections this user can see (empty groups dropped).
	 *
	 * @return array
	 */
	public static function visible_groups() {
		$sections = self::sections();
		$out      = array();
		foreach ( self::groups() as $key => $g ) {
			$g['items'] = array_values(
				array_filter(
					$g['items'],
					static function ( $slug ) use ( $sections ) {
						return isset( $sections[ $slug ] ) && current_user_can( $sections[ $slug ]['cap'] );
					}
				)
			);
			if ( $g['items'] ) {
				$out[ $key ] = $g;
			}
		}
		return $out;
	}

	/**
	 * Group a section belongs to.
	 *
	 * @param string $slug Section.
	 * @return string
	 */
	public static function group_of( $slug ) {
		if ( 'osb-import' === $slug && isset( $_GET['type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'offer'; // Opened from the Services / Events / Courses screens.
		}
		foreach ( self::groups() as $key => $g ) {
			if ( in_array( $slug, $g['items'], true ) ) {
				return $key;
			}
		}
		return '';
	}

	/**
	 * Current section slug.
	 *
	 * @return string
	 */
	public static function current_section() {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Open a plugin admin page: branded bar, section nav and page heading.
	 *
	 * @param string $title    Page title.
	 * @param array  $args {
	 *     @type string $subtitle Short description under the title.
	 *     @type array  $actions  [ [ label, url, 'primary'|'secondary', icon ] ].
	 *     @type string $back     URL for a back link.
	 *     @type string $back_label Back link text.
	 *     @type string $after_title Raw HTML after the title (e.g. a status pill).
	 * }
	 */
	public static function open( $title, array $args = array() ) {
		$args    = wp_parse_args(
			$args,
			array(
				'subtitle'    => '',
				'actions'     => array(),
				'back'        => '',
				'back_label'  => __( 'Back', 'osam-bookings' ),
				'after_title' => '',
			)
		);
		$current = self::current_section();
		if ( 'osb-import' === $current && isset( $_GET['type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current = 'osb-' . sanitize_key( wp_unslash( $_GET['type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$page    = (int) OSB_Settings::get( 'booking_page' );
		$pending = self::pending_count();
		?>
		<div class="wrap osb-admin">
			<div class="osb-topbar">
				<div class="osb-brand">
					<img class="osb-brand__mark" src="<?php echo esc_url( OSB_URL . 'assets/img/osam-mark.png' ); ?>" alt="" width="30" height="30">
					<span class="osb-brand__name"><?php esc_html_e( 'OSAM Bookings', 'osam-bookings' ); ?></span>
					<span class="osb-brand__ver">v<?php echo esc_html( OSB_VERSION ); ?></span>
				</div>
				<?php if ( $page && get_post( $page ) ) : ?>
					<a class="osb-btn osb-btn--ghost osb-btn--sm" href="<?php echo esc_url( get_permalink( $page ) ); ?>" target="_blank"><span class="dashicons dashicons-external"></span><?php esc_html_e( 'View booking page', 'osam-bookings' ); ?></a>
				<?php endif; ?>
			</div>
			<?php
			$groups = self::visible_groups();
			$active = self::group_of( $current );
			?>
			<nav class="osb-nav" aria-label="<?php esc_attr_e( 'Bookings sections', 'osam-bookings' ); ?>">
				<?php foreach ( $groups as $gkey => $g ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $g['items'][0] ) ); ?>" class="<?php echo $active === $gkey ? 'is-active' : ''; ?>"<?php echo $active === $gkey ? ' aria-current="page"' : ''; ?>>
						<span class="dashicons <?php echo esc_attr( $g['icon'] ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $g['title'] ); ?>
						<?php if ( 'bookings' === $gkey && $pending ) : ?><span class="osb-count" title="<?php esc_attr_e( 'Pending approval', 'osam-bookings' ); ?>"><?php echo (int) $pending; ?></span><?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php if ( isset( $groups[ $active ] ) && count( $groups[ $active ]['items'] ) > 1 && empty( $groups[ $active ]['no_subnav'] ) ) : ?>
				<nav class="osb-subnav" aria-label="<?php echo esc_attr( $groups[ $active ]['title'] ); ?>">
					<?php
					$all = self::sections();
					foreach ( $groups[ $active ]['items'] as $slug ) :
						?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" class="<?php echo $current === $slug ? 'is-active' : ''; ?>"<?php echo $current === $slug ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $all[ $slug ]['menu'] ); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>
			<div class="osb-pagehead">
				<div class="osb-pagehead__text">
					<?php if ( $args['back'] ) : ?>
						<a class="osb-back" href="<?php echo esc_url( $args['back'] ); ?>"><span class="dashicons dashicons-arrow-left-alt2"></span><?php echo esc_html( $args['back_label'] ); ?></a>
					<?php endif; ?>
					<h1><?php echo esc_html( $title ); ?><?php echo $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h1>
					<?php if ( $args['subtitle'] ) : ?><p><?php echo wp_kses_post( $args['subtitle'] ); ?></p><?php endif; ?>
				</div>
				<?php if ( $args['actions'] ) : ?>
					<div class="osb-pagehead__actions">
						<?php foreach ( $args['actions'] as $a ) : ?>
							<a class="osb-btn <?php echo 'primary' === ( isset( $a[2] ) ? $a[2] : '' ) ? 'osb-btn--primary' : 'osb-btn--ghost'; ?>" href="<?php echo esc_url( $a[1] ); ?>"><?php echo ! empty( $a[3] ) ? '<span class="dashicons ' . esc_attr( $a[3] ) . '"></span>' : ''; ?><?php echo esc_html( $a[0] ); ?></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<hr class="wp-header-end">
		<?php
	}

	/**
	 * Close a plugin admin page.
	 */
	public static function close() {
		echo '</div>';
	}

	/**
	 * Empty state block.
	 *
	 * @param string $icon   Dashicon class.
	 * @param string $title  Title.
	 * @param string $text   Text.
	 * @param array  $action [ label, url ].
	 */
	public static function empty_state( $icon, $title, $text, $action = array() ) {
		?>
		<div class="osb-emptystate">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<h2><?php echo esc_html( $title ); ?></h2>
			<p><?php echo esc_html( $text ); ?></p>
			<?php if ( $action ) : ?><a class="osb-btn osb-btn--primary" href="<?php echo esc_url( $action[1] ); ?>"><?php echo esc_html( $action[0] ); ?></a><?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Card section heading with description.
	 *
	 * @param string $title Title.
	 * @param string $desc  Description.
	 */
	public static function card_head( $title, $desc = '' ) {
		echo '<div class="osb-card__head"><h2>' . esc_html( $title ) . '</h2>' . ( $desc ? '<p>' . wp_kses_post( $desc ) . '</p>' : '' ) . '</div>';
	}

	/**
	 * Avatar (image or initials).
	 *
	 * @param string $name     Name.
	 * @param int    $image_id Attachment.
	 * @param string $color    Background colour for initials.
	 * @return string
	 */
	public static function avatar( $name, $image_id = 0, $color = '' ) {
		$url = $image_id ? wp_get_attachment_image_url( (int) $image_id, 'thumbnail' ) : '';
		if ( $url ) {
			return '<img class="osb-avatar" src="' . esc_url( $url ) . '" alt="">';
		}
		$parts    = preg_split( '/\s+/', trim( (string) $name ) );
		$initials = mb_strtoupper( mb_substr( $parts[0], 0, 1 ) . ( count( $parts ) > 1 ? mb_substr( end( $parts ), 0, 1 ) : '' ) );
		$style    = $color ? ' style="background:' . esc_attr( $color ) . ';color:#fff"' : '';
		return '<span class="osb-avatar"' . $style . '>' . esc_html( $initials ) . '</span>';
	}

	/**
	 * Pending approvals count.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		$t = OSB_Install::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status = 'pending'" );
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=osb-settings' ) ) . '">' . esc_html__( 'Settings', 'osam-bookings' ) . '</a>' );
		return $links;
	}

	/**
	 * Is this one of our screens?
	 *
	 * @return bool
	 */
	public static function is_our_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) && 0 === strpos( sanitize_key( wp_unslash( $_GET['page'] ) ), 'osb-' );
	}

	/**
	 * Admin assets.
	 */
	public static function assets() {
		if ( ! self::is_our_screen() ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'osb-admin', OSB_URL . 'assets/css/admin.css', array(), OSB_VERSION );
		wp_enqueue_script( 'osb-admin', OSB_URL . 'assets/js/admin.js', array( 'jquery', 'wp-color-picker', 'jquery-ui-sortable' ), OSB_VERSION, true );
		wp_localize_script(
			'osb-admin',
			'OSB_ADMIN',
			array(
				'rest'  => esc_url_raw( rest_url( OSB_Rest::NS ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'confirmDelete' => __( 'Delete this item? This cannot be undone.', 'osam-bookings' ),
					'chooseImage'   => __( 'Choose image', 'osam-bookings' ),
					'noSlots'       => __( 'No free times that day — you can still type any time.', 'osam-bookings' ),
					'copied'        => __( 'Copied', 'osam-bookings' ),
					'allClear'      => __( 'No clashes — this time is free.', 'osam-bookings' ),
					'warnTitle'     => __( 'You can still save this, but note:', 'osam-bookings' ),
					'selected'      => __( '%d selected', 'osam-bookings' ),
					'unsaved'       => __( 'You have unsaved changes.', 'osam-bookings' ),
					'attendance'    => OSB_Courses::attendance_statuses(),
					'removeSession' => __( 'Remove this session? Anyone booked loses it from their schedule when you save.', 'osam-bookings' ),
					'replaceAll'    => __( 'Replace every session in the list with the generated dates?', 'osam-bookings' ),
				),
				'solo'  => OSB_DB::is_solo(),
			)
		);

		// Live previews use the real front-end form.
		$section = self::current_section();
		if ( 'osb-shortcodes' === $section || ( 'osb-settings' === $section && 'appearance' === OSB_Admin_Settings::current_tab() ) ) {
			OSB_Frontend::enqueue();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['page'] ) && 'osb-calendar' === $_GET['page'] ) {
			wp_enqueue_script( 'fullcalendar', OSB_URL . 'assets/vendor/fullcalendar/index.global.min.js', array(), '6.1.15', true );
			wp_enqueue_script( 'osb-calendar', OSB_URL . 'assets/js/calendar.js', array( 'jquery', 'fullcalendar' ), OSB_VERSION, true );
			wp_localize_script(
				'osb-calendar',
				'OSB_CAL',
				array(
					'rest'  => esc_url_raw( rest_url( OSB_Rest::NS ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'i18n'  => array(
						'today'      => __( 'Today', 'osam-bookings' ),
						'month'      => __( 'Month', 'osam-bookings' ),
						'week'       => __( 'Week', 'osam-bookings' ),
						'day'        => __( 'Day', 'osam-bookings' ),
						'list'       => __( 'List', 'osam-bookings' ),
						'loading'    => __( 'Loading…', 'osam-bookings' ),
						'error'      => __( 'Couldn’t load that. Please try again.', 'osam-bookings' ),
						'offline'    => __( 'The calendar couldn’t load. Try reloading the page; if it keeps happening, a security or optimisation plugin may be blocking its script.', 'osam-bookings' ),
						'confirmCancel' => __( 'Cancel this booking?', 'osam-bookings' ),
						'allDay'     => __( 'all day', 'osam-bookings' ),
						'noEvents'   => __( 'Nothing in this period.', 'osam-bookings' ),
						'more'       => __( 'more', 'osam-bookings' ),
					),
				)
			);
		}
	}

	/**
	 * Redirect back with a message.
	 *
	 * @param string $url     URL.
	 * @param string $message Message key.
	 */
	public static function redirect( $url, $message = 'saved' ) {
		wp_safe_redirect( add_query_arg( 'osb_msg', $message, $url ) );
		exit;
	}

	/**
	 * Notices.
	 */
	public static function notices() {
		if ( ! self::is_our_screen() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg      = isset( $_GET['osb_msg'] ) ? sanitize_key( wp_unslash( $_GET['osb_msg'] ) ) : '';
		$messages = array(
			'saved'     => __( 'Saved.', 'osam-bookings' ),
			'deleted'   => __( 'Deleted.', 'osam-bookings' ),
			'created'   => __( 'Booking created.', 'osam-bookings' ),
			'emailed'   => __( 'Email sent.', 'osam-bookings' ),
			'bulk'      => __( 'Bookings updated.', 'osam-bookings' ),
			'cal_connected'    => __( 'Calendar connected. Bookings will appear in it within a few minutes.', 'osam-bookings' ),
			'cal_cancelled'    => __( 'Calendar connection was cancelled.', 'osam-bookings' ),
			'cal_saved'        => __( 'Calendar settings saved.', 'osam-bookings' ),
			'cal_synced'       => __( 'Calendar synced.', 'osam-bookings' ),
			'cal_sync_started' => __( 'Sync started. There’s a lot to send, so the rest is finishing in the background — check again in a minute or two.', 'osam-bookings' ),
			'cal_disconnected' => __( 'Calendar disconnected.', 'osam-bookings' ),
			'erased'           => __( 'The customer’s personal data has been erased.', 'osam-bookings' ),
			'erased_partly'    => __( 'Past bookings were erased. Upcoming bookings were kept — cancel them and erase again to finish.', 'osam-bookings' ),
			'zoom_ok'          => __( 'Zoom is working: a test meeting was created, moved and deleted.', 'osam-bookings' ),
			'stripe_ok'        => __( 'Stripe is connected and working, and payment confirmations are set up.', 'osam-bookings' ),
			'stripe_ok_nohook' => __( 'Stripe accepted the key. Payment confirmations couldn’t be set up — see the note under Stripe.', 'osam-bookings' ),
			'stripe_off'       => __( 'Stripe disconnected.', 'osam-bookings' ),
			'ce_copied'        => __( 'Copied. The copy is switched off until you switch it on.', 'osam-bookings' ),
			'update_found'     => __( 'A new version is available — see the Updates card below, or Dashboard → Updates.', 'osam-bookings' ),
			'licence_checked'  => __( 'Subscription status checked.', 'osam-bookings' ),
			'update_none'      => __( 'You have the latest version.', 'osam-bookings' ),
			'update_required'  => __( 'OSAM requires a different version on this site. It’s being installed now — reload in a minute.', 'osam-bookings' ),
			'update_failed'    => __( 'Couldn’t check for updates — see the Updates card below.', 'osam-bookings' ),
			'dates_emailed' => __( 'Saved. Everyone booked on this intake has been emailed the new dates.', 'osam-bookings' ),
			'error'     => __( 'Something went wrong — please check and try again.', 'osam-bookings' ),
			'has_items' => __( 'This cannot be deleted because it has bookings. Hide it instead.', 'osam-bookings' ),
		);
		if ( isset( $messages[ $msg ] ) ) {
			$class = in_array( $msg, array( 'error', 'has_items', 'update_failed' ), true ) ? 'notice-error' : 'notice-success';
			printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $messages[ $msg ] ) );
		}
		$warnings = get_transient( 'osb_admin_warn_' . get_current_user_id() );
		if ( $warnings ) {
			delete_transient( 'osb_admin_warn_' . get_current_user_id() );
			echo '<div class="notice notice-warning is-dismissible osb-warnings"><p><strong>' . esc_html__( 'Saved, but please check:', 'osam-bookings' ) . '</strong></p><ul>';
			foreach ( (array) $warnings as $w ) {
				echo '<li>' . esc_html( $w ) . '</li>';
			}
			echo '</ul></div>';
		}
		$error = get_transient( 'osb_admin_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'osb_admin_error_' . get_current_user_id() );
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $error ) );
		}

		if ( in_array( self::current_section(), array( 'osb-bookings', 'osb-services', 'osb-events', 'osb-courses' ), true ) && ! osb()->payments->enabled() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'No payment methods are enabled, so only free bookings can be taken.', 'osam-bookings' ),
				esc_url( admin_url( 'admin.php?page=osb-settings&tab=payments' ) ),
				esc_html__( 'Set up payments', 'osam-bookings' )
			);
		}
	}

	/**
	 * Store an error to show after redirect.
	 *
	 * @param string $message Message.
	 */
	public static function flash_error( $message ) {
		set_transient( 'osb_admin_error_' . get_current_user_id(), $message, 60 );
	}

	/**
	 * Store warnings to show after redirect.
	 *
	 * @param string[] $warnings Warnings.
	 */
	public static function flash_warnings( array $warnings ) {
		if ( $warnings ) {
			$key = 'osb_admin_warn_' . get_current_user_id();
			$had = get_transient( $key );
			set_transient( $key, array_values( array_unique( array_merge( is_array( $had ) ? $had : array(), $warnings ) ) ), 120 );
		}
	}

	/**
	 * Check capability + nonce for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 * @param string $cap    Capability.
	 */
	public static function verify( $action, $cap = self::CAP ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'osam-bookings' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Render a form table row.
	 *
	 * @param string $name    Input name.
	 * @param string $label   Label.
	 * @param mixed  $value   Value.
	 * @param string $type    text|number|textarea|select|checkbox|color|image|password|email|editor|date|datetime|time.
	 * @param array  $options Select options or extra attrs ['help'=>, 'min'=>, 'step'=>, 'placeholder'=>].
	 */
	public static function row( $name, $label, $value, $type = 'text', $options = array() ) {
		$id   = 'osb_' . preg_replace( '/[^a-z0-9_]/i', '_', $name );
		$help = isset( $options['help'] ) ? $options['help'] : '';
		unset( $options['help'] );

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		self::input( $name, $value, $type, $options, $id );
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Render an input.
	 *
	 * @param string $name    Name.
	 * @param mixed  $value   Value.
	 * @param string $type    Type.
	 * @param array  $options Options.
	 * @param string $id      Id.
	 */
	public static function input( $name, $value, $type = 'text', $options = array(), $id = '' ) {
		$id    = $id ? $id : 'osb_' . preg_replace( '/[^a-z0-9_]/i', '_', $name );
		$attrs = '';
		foreach ( array( 'min', 'max', 'step', 'placeholder' ) as $attr ) {
			if ( isset( $options[ $attr ] ) ) {
				$attrs .= sprintf( ' %s="%s"', $attr, esc_attr( $options[ $attr ] ) );
				unset( $options[ $attr ] );
			}
		}

		switch ( $type ) {
			case 'textarea':
				printf( '<textarea name="%s" id="%s" rows="5" class="large-text"%s>%s</textarea>', esc_attr( $name ), esc_attr( $id ), $attrs, esc_textarea( (string) $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'code':
				printf( '<textarea name="%s" id="%s" rows="10" class="large-text code"%s>%s</textarea>', esc_attr( $name ), esc_attr( $id ), $attrs, esc_textarea( (string) $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'editor':
				wp_editor(
					(string) $value,
					$id,
					array(
						'textarea_name' => $name,
						'textarea_rows' => 6,
						'media_buttons' => false,
						'teeny'         => true,
						'quicktags'     => false,
					)
				);
				break;
			case 'select':
				printf( '<select name="%s" id="%s">', esc_attr( $name ), esc_attr( $id ) );
				foreach ( $options as $k => $v ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( (string) $value, (string) $k, false ), esc_html( $v ) );
				}
				echo '</select>';
				break;
			case 'checkbox':
				printf( '<input type="hidden" name="%1$s" value="0"><label><input type="checkbox" name="%1$s" id="%2$s" value="1"%3$s> %4$s</label>', esc_attr( $name ), esc_attr( $id ), checked( ! empty( $value ), true, false ), esc_html( isset( $options['label'] ) ? $options['label'] : __( 'Enabled', 'osam-bookings' ) ) );
				break;
			case 'color':
				printf( '<input type="text" name="%s" id="%s" value="%s" class="osb-color">', esc_attr( $name ), esc_attr( $id ), esc_attr( (string) $value ) );
				break;
			case 'image':
				$url = $value ? wp_get_attachment_image_url( (int) $value, 'thumbnail' ) : '';
				printf(
					'<div class="osb-image-field"><input type="hidden" name="%s" id="%s" value="%d"><img src="%s" class="osb-image-preview" %s><button type="button" class="button osb-image-pick">%s</button> <button type="button" class="button-link osb-image-clear" %s>%s</button></div>',
					esc_attr( $name ),
					esc_attr( $id ),
					(int) $value,
					esc_url( $url ? $url : '' ),
					$url ? '' : 'style="display:none"',
					esc_html__( 'Choose image', 'osam-bookings' ),
					$url ? '' : 'style="display:none"',
					esc_html__( 'Remove', 'osam-bookings' )
				);
				break;
			case 'password':
				printf( '<input type="password" name="%s" id="%s" value="%s" class="regular-text" autocomplete="new-password"%s>', esc_attr( $name ), esc_attr( $id ), esc_attr( (string) $value ), $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'number':
				printf( '<input type="number" name="%s" id="%s" value="%s" class="small-text"%s>', esc_attr( $name ), esc_attr( $id ), esc_attr( (string) $value ), $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'date':
			case 'time':
			case 'datetime-local':
			case 'email':
			case 'url':
			default:
				$input_type = in_array( $type, array( 'date', 'time', 'datetime-local', 'email', 'url' ), true ) ? $type : 'text';
				printf( '<input type="%s" name="%s" id="%s" value="%s" class="%s"%s>', esc_attr( $input_type ), esc_attr( $name ), esc_attr( $id ), esc_attr( (string) $value ), 'text' === $input_type || 'email' === $input_type || 'url' === $input_type ? 'regular-text' : '', $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Status pill.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function status_pill( $status ) {
		$labels = OSB_Bookings::statuses();
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		return '<span class="osb-pill osb-pill--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Gateway checkboxes for limiting payment methods on a service/event.
	 *
	 * @param string $current Comma list.
	 */
	public static function methods_checkboxes( $current ) {
		$current = array_filter( array_map( 'trim', explode( ',', (string) $current ) ) );
		echo '<div class="osb-chips-check">';
		foreach ( osb()->payments->all() as $id => $g ) {
			printf(
				'<label><input type="checkbox" name="payment_methods[]" value="%s"%s><span>%s%s</span></label>',
				esc_attr( $id ),
				checked( in_array( $id, $current, true ), true, false ),
				esc_html( $g->admin_name() ),
				$g->is_enabled() ? '' : ' <em>(' . esc_html__( 'off', 'osam-bookings' ) . ')</em>'
			);
		}
		echo '</div><p class="osb-help">' . esc_html__( 'Leave all unticked to offer every method that’s switched on.', 'osam-bookings' ) . '</p>';
	}

}
