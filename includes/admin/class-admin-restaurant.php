<?php
/**
 * Restaurant admin: tonight's service (timeline + live floor plan), tables & floor plan
 * editor, and opening times & rules.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restaurant screens.
 */
class OSB_Admin_Restaurant {

	/**
	 * Hooks.
	 */
	public static function init() {
		foreach ( array( 'area', 'combo', 'period', 'closure' ) as $what ) {
			add_action( 'admin_post_osb_save_rest_' . $what, array( __CLASS__, 'save_' . $what ) );
			add_action( 'admin_post_osb_delete_rest_' . $what, array( __CLASS__, 'delete_item' ) );
		}
		add_action( 'admin_post_osb_save_rest_rules', array( __CLASS__, 'save_rules' ) );
		add_action( 'admin_post_osb_rest_quickstart', array( __CLASS__, 'quickstart' ) );
		add_action( 'osb_admin_booking_side', array( __CLASS__, 'booking_panel' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
	}

	/**
	 * Scripts for our screens.
	 */
	public static function assets() {
		$page = OSB_Admin::current_section();
		if ( ! in_array( $page, array( 'osb-restaurant', 'osb-floor' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'osb-restaurant-admin', OSB_URL . 'assets/css/restaurant-admin.css', array( 'osb-admin' ), OSB_VERSION );
		wp_enqueue_script( 'osb-restaurant-admin', OSB_URL . 'assets/js/restaurant-admin.js', array(), OSB_VERSION, true );
		$statuses = OSB_Bookings::statuses();
		wp_add_inline_script(
			'osb-restaurant-admin',
			'window.OSB_REST = ' . wp_json_encode(
				array(
					'rest'       => esc_url_raw( rest_url( OSB_Rest::NS ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'statuses'   => $statuses,
					'assignment' => OSB_Settings::get( 'rest_assignment' ),
					'turns'      => array_values( (array) OSB_Settings::get( 'rest_turn_times' ) ),
					'timeFormat' => get_option( 'time_format' ),
					'locale'     => str_replace( '_', '-', get_locale() ),
					'adminUrl'   => admin_url( 'admin.php' ),
					'cardHold'   => OSB_Restaurant::card_hold_ready(),
					'i18n'       => array(
						'unassigned'   => __( 'Waiting for a table', 'osam-bookings' ),
						'timeline'     => __( 'Timeline', 'osam-bookings' ),
						'floor'        => __( 'Floor plan', 'osam-bookings' ),
						'list'         => __( 'List', 'osam-bookings' ),
						'today'        => __( 'Today', 'osam-bookings' ),
						'newBooking'   => __( 'New booking', 'osam-bookings' ),
						'walkIn'       => __( 'Walk-in', 'osam-bookings' ),
						'covers'       => __( 'Guests', 'osam-bookings' ),
						'coversShort'  => __( 'cvr', 'osam-bookings' ),
						'bookings'     => __( 'Bookings', 'osam-bookings' ),
						'closed'       => __( 'Closed today', 'osam-bookings' ),
						'noPeriods'    => __( 'No service on this day — add service periods under Opening times & rules.', 'osam-bookings' ),
						'noTables'     => __( 'No tables yet — add them under Tables & floor plan.', 'osam-bookings' ),
						'save'         => __( 'Save', 'osam-bookings' ),
						'cancel'       => __( 'Cancel', 'osam-bookings' ),
						'close'        => __( 'Close', 'osam-bookings' ),
						'name'         => __( 'Name', 'osam-bookings' ),
						'phone'        => __( 'Phone', 'osam-bookings' ),
						'email'        => __( 'Email', 'osam-bookings' ),
						'notes'        => __( 'Customer notes', 'osam-bookings' ),
						'internal'     => __( 'Staff note', 'osam-bookings' ),
						'date'         => __( 'Date', 'osam-bookings' ),
						'time'         => __( 'Time', 'osam-bookings' ),
						'duration'     => __( 'Minutes at the table', 'osam-bookings' ),
						'tables'       => __( 'Tables', 'osam-bookings' ),
						'autoTable'    => __( 'Best free table', 'osam-bookings' ),
						'noTable'      => __( 'Leave unassigned', 'osam-bookings' ),
						'suggested'    => __( 'Suggested: %s', 'osam-bookings' ),
						'noFit'        => __( 'No single table or combination is free for that many — pick tables yourself or leave it unassigned.', 'osam-bookings' ),
						'sendEmail'    => __( 'Email the customer a confirmation', 'osam-bookings' ),
						'status'       => __( 'Status', 'osam-bookings' ),
						'seat'         => __( 'Seat', 'osam-bookings' ),
						'finish'       => __( 'Finished', 'osam-bookings' ),
						'noShow'       => __( 'No-show', 'osam-bookings' ),
						'cancelBk'     => __( 'Cancel booking', 'osam-bookings' ),
						'confirm'      => __( 'Confirm', 'osam-bookings' ),
						'open'         => __( 'Full details', 'osam-bookings' ),
						'charge'       => __( 'Charge no-show fee', 'osam-bookings' ),
						'chargeAsk'    => __( 'Charge %s to the card saved for this booking?', 'osam-bookings' ),
						'charged'      => __( 'Charged.', 'osam-bookings' ),
						'cardOnFile'   => __( 'Card on file', 'osam-bookings' ),
						'paid'         => __( 'Amount', 'osam-bookings' ),
						'saved'        => __( 'Saved', 'osam-bookings' ),
						'warn'         => __( 'Saved, but note:', 'osam-bookings' ),
						'error'        => __( 'Something went wrong — please try again.', 'osam-bookings' ),
						'cancelAsk'    => __( 'Cancel this booking?', 'osam-bookings' ),
						'notifyAsk'    => __( 'Email the customer about this change?', 'osam-bookings' ),
						'dropHint'     => __( 'Drag bookings onto a table to seat them. Drag sideways to change the time; drag the right edge to change how long.', 'osam-bookings' ),
						'floorHint'    => __( 'Tables show who’s sitting there at the time on the slider. Drag a booking from the list onto a table to seat it there.', 'osam-bookings' ),
						'free'         => __( 'Free', 'osam-bookings' ),
						'nextAt'       => __( 'Next %s', 'osam-bookings' ),
						'until'        => __( 'until %s', 'osam-bookings' ),
						'at'           => __( 'At', 'osam-bookings' ),
						'now'          => __( 'Now', 'osam-bookings' ),
						'summary'      => __( '%1$d bookings · %2$d guests', 'osam-bookings' ),
						'search'       => __( 'Search bookings…', 'osam-bookings' ),
						'addTable'     => __( 'Add table', 'osam-bookings' ),
						'tableName'    => __( 'Name', 'osam-bookings' ),
						'minCovers'    => __( 'Min guests', 'osam-bookings' ),
						'maxCovers'    => __( 'Max guests', 'osam-bookings' ),
						'shape'        => __( 'Shape', 'osam-bookings' ),
						'shapes'       => array(
							'square' => __( 'Square', 'osam-bookings' ),
							'rect'   => __( 'Rectangle', 'osam-bookings' ),
							'round'  => __( 'Round', 'osam-bookings' ),
							'booth'  => __( 'Booth', 'osam-bookings' ),
						),
						'online'       => __( 'Bookable online', 'osam-bookings' ),
						'priority'     => __( 'Priority', 'osam-bookings' ),
						'priorityHelp' => __( 'Lower numbers are filled first when two tables fit equally well.', 'osam-bookings' ),
						'rotate'       => __( 'Rotate', 'osam-bookings' ),
						'duplicate'    => __( 'Duplicate', 'osam-bookings' ),
						'remove'       => __( 'Remove', 'osam-bookings' ),
						'saveLayout'   => __( 'Save floor plan', 'osam-bookings' ),
						'unsaved'      => __( 'Unsaved changes', 'osam-bookings' ),
						'layoutSaved'  => __( 'Floor plan saved.', 'osam-bookings' ),
						'selectTable'  => __( 'Select a table to edit it, or add one.', 'osam-bookings' ),
						'canvasSize'   => __( 'Room size', 'osam-bookings' ),
						'leave'        => __( 'You have unsaved changes to the floor plan.', 'osam-bookings' ),
						'inactive'     => __( 'Switched off', 'osam-bookings' ),
						'active'       => __( 'In use', 'osam-bookings' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/* ---------------------------------------------------------------------
	 * Service screen
	 * ------------------------------------------------------------------ */

	/**
	 * Tonight's service.
	 */
	public static function service_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date  = isset( $_GET['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['date'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) );
		$focus = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::open(
			__( 'Service', 'osam-bookings' ),
			array(
				'subtitle' => __( 'Who’s in, at which table, and when. Seat guests, move bookings and take walk-ins as the service goes.', 'osam-bookings' ),
			)
		);
		if ( ! OSB_Restaurant::tables( null, true ) || ! OSB_Restaurant::periods( true ) ) {
			self::setup_prompt();
		}
		printf(
			'<div id="osb-rest-app" class="osb-rest" data-date="%s" data-focus="%d"><div class="osb-loading">%s</div></div>',
			esc_attr( $date ),
			(int) $focus,
			esc_html__( 'Loading…', 'osam-bookings' )
		);
		OSB_Admin::close();
	}

	/**
	 * First-run checklist.
	 */
	private static function setup_prompt() {
		$tables  = (bool) OSB_Restaurant::tables( null, true );
		$periods = (bool) OSB_Restaurant::periods( true );
		?>
		<div class="osb-card osb-rest-setup">
			<?php OSB_Admin::card_head( __( 'Set up table booking', 'osam-bookings' ), __( 'Two things are needed before guests can book: your tables, and when you’re open.', 'osam-bookings' ) ); ?>
			<ol class="osb-rest-steps">
				<li class="<?php echo $tables ? 'is-done' : ''; ?>"><a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-floor' ) ); ?>"><?php esc_html_e( 'Add your dining areas and tables', 'osam-bookings' ); ?></a></li>
				<li class="<?php echo $periods ? 'is-done' : ''; ?>"><a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-rest-hours' ) ); ?>"><?php esc_html_e( 'Add service periods (e.g. lunch and dinner)', 'osam-bookings' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-shortcodes' ) ); ?>"><?php esc_html_e( 'Put [osam_table_booking] on a page', 'osam-bookings' ); ?></a></li>
			</ol>
			<?php if ( ! $tables && ! $periods ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'osb_rest_quickstart' ); ?>
					<input type="hidden" name="action" value="osb_rest_quickstart">
					<p><button class="osb-btn osb-btn--ghost"><span class="dashicons dashicons-superhero"></span><?php esc_html_e( 'Start me off with an example layout', 'osam-bookings' ); ?></button> <span class="osb-help"><?php esc_html_e( '10 tables in one room, lunch and dinner Tuesday to Sunday — change anything afterwards.', 'osam-bookings' ); ?></span></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Example layout so a new site has something to work with.
	 */
	public static function quickstart() {
		OSB_Admin::verify( 'osb_rest_quickstart' );
		if ( ! OSB_Restaurant::areas() ) {
			$area = OSB_DB::insert(
				'rest_areas',
				array(
					'name'        => __( 'Restaurant', 'osam-bookings' ),
					'online'      => 1,
					'plan_width'  => 1000,
					'plan_height' => 600,
					'status'      => 'active',
					'created_at'  => current_time( 'mysql' ),
				)
			);
			$layout = array(
				array( 'T1', 1, 2, 'square', 60, 60, 70, 70 ),
				array( 'T2', 1, 2, 'square', 180, 60, 70, 70 ),
				array( 'T3', 1, 2, 'square', 300, 60, 70, 70 ),
				array( 'T4', 2, 4, 'square', 60, 220, 90, 90 ),
				array( 'T5', 2, 4, 'square', 200, 220, 90, 90 ),
				array( 'T6', 2, 4, 'round', 340, 220, 90, 90 ),
				array( 'T7', 3, 6, 'rect', 520, 70, 160, 80 ),
				array( 'T8', 3, 6, 'rect', 520, 230, 160, 80 ),
				array( 'B1', 2, 4, 'booth', 780, 60, 140, 90 ),
				array( 'B2', 2, 4, 'booth', 780, 200, 140, 90 ),
			);
			$ids    = array();
			foreach ( $layout as $i => $t ) {
				$ids[ $t[0] ] = OSB_DB::insert(
					'rest_tables',
					array(
						'area_id'    => $area,
						'name'       => $t[0],
						'min_covers' => $t[1],
						'max_covers' => $t[2],
						'online'     => 1,
						'shape'      => $t[3],
						'plan_x'     => $t[4],
						'plan_y'     => $t[5],
						'plan_w'     => $t[6],
						'plan_h'     => $t[7],
						'status'     => 'active',
						'sort_order' => $i,
						'created_at' => current_time( 'mysql' ),
					)
				);
			}
			foreach ( array( array( 'T4', 'T5', 5, 8 ), array( 'T7', 'T8', 7, 12 ) ) as $c ) {
				OSB_DB::insert(
					'rest_combos',
					array(
						'name'       => $c[0] . ' + ' . $c[1],
						'table_ids'  => $ids[ $c[0] ] . ',' . $ids[ $c[1] ],
						'min_covers' => $c[2],
						'max_covers' => $c[3],
						'online'     => 1,
						'status'     => 'active',
						'created_at' => current_time( 'mysql' ),
					)
				);
			}
		}
		if ( ! OSB_Restaurant::periods() ) {
			foreach ( array( array( __( 'Lunch', 'osam-bookings' ), '12:00:00', '14:30:00', 0 ), array( __( 'Dinner', 'osam-bookings' ), '17:30:00', '21:30:00', 1 ) ) as $p ) {
				OSB_DB::insert(
					'rest_periods',
					array(
						'name'          => $p[0],
						'weekdays'      => '2,3,4,5,6,0',
						'start_time'    => $p[1],
						'last_seating'  => $p[2],
						'slot_interval' => 15,
						'status'        => 'active',
						'sort_order'    => $p[3],
						'created_at'    => current_time( 'mysql' ),
					)
				);
			}
		}
		OSB_Restaurant::flush();
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-floor' ) );
	}

	/* ---------------------------------------------------------------------
	 * Tables & floor plan
	 * ------------------------------------------------------------------ */

	/**
	 * Areas, floor-plan editor and combinations.
	 */
	public static function floor_page() {
		$areas = OSB_Restaurant::areas();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['area'] ) ? absint( $_GET['area'] ) : ( $areas ? (int) $areas[0]->id : 0 );
		$area    = $current ? OSB_DB::get( 'rest_areas', $current ) : null;
		if ( ! $area && $areas ) {
			$area = $areas[0];
		}
		OSB_Admin::open(
			__( 'Tables & floor plan', 'osam-bookings' ),
			array(
				'subtitle' => __( 'Lay out each dining area, set how many guests each table seats, and say which tables can be pushed together for bigger parties.', 'osam-bookings' ),
			)
		);
		if ( ! OSB_Restaurant::tables() && ! OSB_Restaurant::periods() ) {
			self::setup_prompt();
		}
		?>
		<div class="osb-rest-areas">
			<nav class="osb-segmented" aria-label="<?php esc_attr_e( 'Dining areas', 'osam-bookings' ); ?>">
				<?php foreach ( $areas as $a ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-floor&area=' . (int) $a->id ) ); ?>" class="<?php echo $area && (int) $area->id === (int) $a->id ? 'is-active' : ''; ?>"><?php echo esc_html( $a->name ); ?> <span><?php echo count( OSB_Restaurant::tables( (int) $a->id ) ); ?></span><?php echo 'active' !== $a->status ? ' <em>(' . esc_html__( 'off', 'osam-bookings' ) . ')</em>' : ''; ?></a>
				<?php endforeach; ?>
			</nav>
			<details class="osb-rest-addarea" <?php echo $areas ? '' : 'open'; ?>>
				<summary class="osb-btn osb-btn--ghost osb-btn--sm"><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add area', 'osam-bookings' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-inline">
					<?php wp_nonce_field( 'osb_save_rest_area' ); ?>
					<input type="hidden" name="action" value="osb_save_rest_area">
					<input type="text" name="name" required placeholder="<?php esc_attr_e( 'e.g. Main room, Terrace, Bar', 'osam-bookings' ); ?>">
					<button class="osb-btn osb-btn--primary osb-btn--sm"><?php esc_html_e( 'Add', 'osam-bookings' ); ?></button>
				</form>
			</details>
		</div>

		<?php if ( ! $area ) : ?>
			<?php OSB_Admin::empty_state( 'dashicons-food', __( 'No dining areas yet', 'osam-bookings' ), __( 'Start with one area — your main room — and add tables to it. Add a terrace, bar or private room later if you have them.', 'osam-bookings' ) ); ?>
		<?php else : ?>
			<?php
			$tables = array();
			foreach ( OSB_Restaurant::tables( (int) $area->id ) as $t ) {
				$tables[] = OSB_Restaurant::table_json( $t );
			}
			$del = wp_nonce_url( admin_url( 'admin-post.php?action=osb_delete_rest_area&id=' . (int) $area->id ), 'osb_delete_rest_area_' . (int) $area->id );
			?>
			<div class="osb-card osb-rest-areabar">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-inline">
					<?php wp_nonce_field( 'osb_save_rest_area' ); ?>
					<input type="hidden" name="action" value="osb_save_rest_area">
					<input type="hidden" name="id" value="<?php echo (int) $area->id; ?>">
					<label><?php esc_html_e( 'Area name', 'osam-bookings' ); ?> <input type="text" name="name" value="<?php echo esc_attr( $area->name ); ?>" required></label>
					<label class="osb-switch"><input type="checkbox" name="online" value="1" <?php checked( (int) $area->online ); ?>><span></span><?php esc_html_e( 'Bookable online', 'osam-bookings' ); ?></label>
					<label class="osb-switch"><input type="checkbox" name="active" value="1" <?php checked( 'active' === $area->status ); ?>><span></span><?php esc_html_e( 'In use', 'osam-bookings' ); ?></label>
					<label><?php esc_html_e( 'Order', 'osam-bookings' ); ?> <input type="number" name="sort_order" class="small-text" value="<?php echo (int) $area->sort_order; ?>"></label>
					<button class="osb-btn osb-btn--ghost osb-btn--sm"><?php esc_html_e( 'Save area', 'osam-bookings' ); ?></button>
					<a class="osb-link osb-link--danger osb-delete" href="<?php echo esc_url( $del ); ?>"><?php esc_html_e( 'Delete area', 'osam-bookings' ); ?></a>
				</form>
			</div>

			<div id="osb-floor-editor" class="osb-floor-editor" data-area="<?php echo esc_attr( wp_json_encode( array( 'id' => (int) $area->id, 'name' => $area->name, 'width' => (int) $area->plan_width, 'height' => (int) $area->plan_height ) ) ); ?>" data-tables="<?php echo esc_attr( wp_json_encode( $tables ) ); ?>"></div>
		<?php endif; ?>

		<?php self::combos_section(); ?>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Table combinations list and form.
	 */
	private static function combos_section() {
		$tables = OSB_Restaurant::tables( null, true );
		if ( count( $tables ) < 2 ) {
			return;
		}
		$areas  = OSB_Restaurant::area_map();
		$combos = OSB_Restaurant::combos();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['combo'] ) ? absint( $_GET['combo'] ) : 0;
		$edit    = null;
		foreach ( $combos as $c ) {
			if ( (int) $c->id === $edit_id ) {
				$edit = $c;
			}
		}
		?>
		<div class="osb-section" id="combos">
			<div class="osb-section__intro">
				<h2><?php esc_html_e( 'Table combinations', 'osam-bookings' ); ?></h2>
				<p><?php esc_html_e( 'Tables you push together for bigger groups. A combination is only offered when every table in it is free, and a single table that fits is always used first.', 'osam-bookings' ); ?></p>
			</div>
			<div class="osb-stack">
				<?php if ( $combos ) : ?>
					<div class="osb-card osb-card--table">
						<table class="osb-table">
							<thead><tr><th><?php esc_html_e( 'Combination', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Tables', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Guests', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Online', 'osam-bookings' ); ?></th><th class="osb-col-actions"></th></tr></thead>
							<tbody>
							<?php foreach ( $combos as $c ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $c->name ? $c->name : OSB_Restaurant::tables_label( $c->ids ) ); ?></strong><?php echo 'active' !== $c->status ? ' <span class="osb-pill osb-pill--neutral">' . esc_html__( 'Off', 'osam-bookings' ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<td><?php echo esc_html( OSB_Restaurant::tables_label( $c->ids ) ); ?></td>
									<td><?php echo (int) $c->min_covers; ?>–<?php echo (int) $c->max_covers; ?></td>
									<td><?php echo $c->online ? '<span class="dashicons dashicons-yes"></span>' : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<td class="osb-col-actions">
										<a class="osb-iconbtn" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-floor&combo=' . (int) $c->id . '#combos' ) ); ?>" title="<?php esc_attr_e( 'Edit', 'osam-bookings' ); ?>"><span class="dashicons dashicons-edit"></span></a>
										<a class="osb-iconbtn osb-iconbtn--danger osb-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=osb_delete_rest_combo&id=' . (int) $c->id ), 'osb_delete_rest_combo_' . (int) $c->id ) ); ?>" title="<?php esc_attr_e( 'Delete', 'osam-bookings' ); ?>"><span class="dashicons dashicons-trash"></span></a>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form">
					<?php wp_nonce_field( 'osb_save_rest_combo' ); ?>
					<input type="hidden" name="action" value="osb_save_rest_combo">
					<input type="hidden" name="id" value="<?php echo $edit ? (int) $edit->id : 0; ?>">
					<?php OSB_Admin::card_head( $edit ? __( 'Edit combination', 'osam-bookings' ) : __( 'Add a combination', 'osam-bookings' ) ); ?>
					<div class="osb-field"><label><?php esc_html_e( 'Tables pushed together', 'osam-bookings' ); ?></label>
						<div class="osb-chips-check">
							<?php
							$last = null;
							foreach ( $tables as $t ) :
								if ( $last !== (int) $t->area_id && count( $areas ) > 1 ) :
									$last = (int) $t->area_id;
									?>
									<span class="osb-chips-check__group"><?php echo esc_html( isset( $areas[ $last ] ) ? $areas[ $last ]->name : '' ); ?></span>
								<?php endif; ?>
								<label><input type="checkbox" name="table_ids[]" value="<?php echo (int) $t->id; ?>" <?php checked( $edit && in_array( (int) $t->id, $edit->ids, true ) ); ?>><span><?php echo esc_html( $t->name ); ?> <small>(<?php echo (int) $t->max_covers; ?>)</small></span></label>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_combo_name"><?php esc_html_e( 'Name (optional)', 'osam-bookings' ); ?></label><input type="text" id="osb_combo_name" name="name" value="<?php echo esc_attr( $edit ? $edit->name : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Window long table', 'osam-bookings' ); ?>"></div>
						<div class="osb-field osb-field--quarter"><label for="osb_combo_min"><?php esc_html_e( 'Min guests', 'osam-bookings' ); ?></label><input type="number" id="osb_combo_min" name="min_covers" min="1" value="<?php echo $edit ? (int) $edit->min_covers : 5; ?>"></div>
						<div class="osb-field osb-field--quarter"><label for="osb_combo_max"><?php esc_html_e( 'Max guests', 'osam-bookings' ); ?></label><input type="number" id="osb_combo_max" name="max_covers" min="1" value="<?php echo $edit ? (int) $edit->max_covers : 8; ?>"></div>
					</div>
					<label class="osb-switch"><input type="checkbox" name="online" value="1" <?php checked( ! $edit || $edit->online ); ?>><span></span><?php esc_html_e( 'Can be booked online', 'osam-bookings' ); ?></label>
					<label class="osb-switch"><input type="checkbox" name="active" value="1" <?php checked( ! $edit || 'active' === $edit->status ); ?>><span></span><?php esc_html_e( 'In use', 'osam-bookings' ); ?></label>
					<div class="osb-formfoot">
						<?php if ( $edit ) : ?><a class="osb-link" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-floor#combos' ) ); ?>"><?php esc_html_e( 'Cancel', 'osam-bookings' ); ?></a><?php endif; ?>
						<button class="osb-btn osb-btn--primary"><?php echo $edit ? esc_html__( 'Save combination', 'osam-bookings' ) : esc_html__( 'Add combination', 'osam-bookings' ); ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Save an area.
	 */
	public static function save_area() {
		OSB_Admin::verify( 'osb_save_rest_area' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-floor' ), 'error' );
		}
		if ( $id && OSB_DB::get( 'rest_areas', $id ) ) {
			OSB_DB::update(
				'rest_areas',
				$id,
				array(
					'name'       => $name,
					'online'     => empty( $_POST['online'] ) ? 0 : 1,
					'status'     => empty( $_POST['active'] ) ? 'inactive' : 'active',
					'sort_order' => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
				)
			);
		} else {
			$id = OSB_DB::insert(
				'rest_areas',
				array(
					'name'        => $name,
					'online'      => 1,
					'plan_width'  => 1000,
					'plan_height' => 640,
					'status'      => 'active',
					'sort_order'  => count( OSB_Restaurant::areas() ),
					'created_at'  => current_time( 'mysql' ),
				)
			);
		}
		// phpcs:enable
		OSB_Restaurant::flush();
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-floor&area=' . (int) $id ) );
	}

	/**
	 * Save a combination.
	 */
	public static function save_combo() {
		OSB_Admin::verify( 'osb_save_rest_combo' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$ids = OSB_Restaurant::ids( isset( $_POST['table_ids'] ) ? (array) wp_unslash( $_POST['table_ids'] ) : array() );
		if ( count( $ids ) < 2 ) {
			OSB_Admin::flash_error( __( 'Pick at least two tables for a combination.', 'osam-bookings' ) );
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-floor#combos' ), 'error' );
		}
		$map  = OSB_Restaurant::table_map();
		$seat = 0;
		foreach ( $ids as $tid ) {
			$seat += isset( $map[ $tid ] ) ? (int) $map[ $tid ]->max_covers : 0;
		}
		$min = max( 1, isset( $_POST['min_covers'] ) ? absint( $_POST['min_covers'] ) : 1 );
		$max = max( $min, isset( $_POST['max_covers'] ) ? absint( $_POST['max_covers'] ) : $seat );
		$row = array(
			'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'table_ids'  => implode( ',', $ids ),
			'min_covers' => $min,
			'max_covers' => $max,
			'online'     => empty( $_POST['online'] ) ? 0 : 1,
			'status'     => empty( $_POST['active'] ) ? 'inactive' : 'active',
		);
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		// phpcs:enable
		if ( $id && OSB_DB::get( 'rest_combos', $id ) ) {
			OSB_DB::update( 'rest_combos', $id, $row );
		} else {
			$row['created_at'] = current_time( 'mysql' );
			OSB_DB::insert( 'rest_combos', $row );
		}
		if ( $max > $seat + 2 ) {
			/* translators: 1: max guests 2: seats */
			OSB_Admin::flash_warnings( array( sprintf( __( 'This combination takes up to %1$d guests but its tables seat %2$d between them — check that’s right.', 'osam-bookings' ), $max, $seat ) ) );
		}
		OSB_Restaurant::flush();
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-floor#combos' ) );
	}

	/**
	 * Delete an area, combination, period or closure.
	 */
	public static function delete_item() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$what   = str_replace( 'osb_delete_rest_', '', $action );
		$tables = array(
			'area'    => array( 'rest_areas', 'osb-floor' ),
			'combo'   => array( 'rest_combos', 'osb-floor' ),
			'period'  => array( 'rest_periods', 'osb-rest-hours' ),
			'closure' => array( 'rest_closures', 'osb-rest-hours' ),
		);
		if ( ! isset( $tables[ $what ] ) ) {
			wp_die( esc_html__( 'Unknown action.', 'osam-bookings' ) );
		}
		OSB_Admin::verify( $action . '_' . $id );
		$back = admin_url( 'admin.php?page=' . $tables[ $what ][1] );
		if ( 'area' === $what ) {
			foreach ( OSB_Restaurant::tables( $id ) as $t ) {
				global $wpdb;
				$bt = OSB_Install::table( 'booking_tables' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $wpdb->get_var( $wpdb->prepare( "SELECT booking_id FROM $bt WHERE table_id = %d LIMIT 1", $t->id ) ) ) {
					OSB_Admin::flash_error( __( 'This area has tables with bookings, so it can’t be deleted. Switch it off (untick “In use”) instead.', 'osam-bookings' ) );
					OSB_Admin::redirect( admin_url( 'admin.php?page=osb-floor&area=' . $id ), 'error' );
				}
			}
			foreach ( OSB_Restaurant::tables( $id ) as $t ) {
				OSB_DB::delete( 'rest_tables', $t->id );
			}
			$back = admin_url( 'admin.php?page=osb-floor' );
		}
		OSB_DB::delete( $tables[ $what ][0], $id );
		OSB_Restaurant::flush();
		OSB_Admin::redirect( $back, 'deleted' );
	}

	/* ---------------------------------------------------------------------
	 * Opening times & rules
	 * ------------------------------------------------------------------ */

	/**
	 * Periods, closures and booking rules.
	 */
	public static function hours_page() {
		$s       = OSB_Settings::all();
		$periods = OSB_Restaurant::periods();
		$areas   = OSB_Restaurant::areas();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['period'] ) ? absint( $_GET['period'] ) : 0;
		$edit    = $edit_id ? OSB_DB::get( 'rest_periods', $edit_id ) : null;
		$days    = self::weekday_names();
		OSB_Admin::open(
			__( 'Opening times & rules', 'osam-bookings' ),
			array(
				'subtitle' => __( 'When guests can book, how long they keep a table, and what they pay to secure it.', 'osam-bookings' ),
			)
		);
		?>
		<div class="osb-section" id="periods">
			<div class="osb-section__intro">
				<h2><?php esc_html_e( 'Service periods', 'osam-bookings' ); ?></h2>
				<p><?php esc_html_e( 'Each sitting you take bookings for. Guests can arrive any time from the start until the last seating, in steps of the interval.', 'osam-bookings' ); ?></p>
				<p><?php esc_html_e( 'Pacing stops too many guests arriving at once, so the kitchen isn’t swamped.', 'osam-bookings' ); ?></p>
			</div>
			<div class="osb-stack">
				<?php if ( $periods ) : ?>
					<div class="osb-card osb-card--table">
						<table class="osb-table">
							<thead><tr><th><?php esc_html_e( 'Period', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Days', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Seatings', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Pacing', 'osam-bookings' ); ?></th><th class="osb-col-actions"></th></tr></thead>
							<tbody>
							<?php foreach ( $periods as $p ) : ?>
								<?php
								$on = array();
								foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $d ) {
									if ( in_array( $d, array_map( 'intval', explode( ',', (string) $p->weekdays ) ), true ) ) {
										$on[] = $days[ $d ][0];
									}
								}
								?>
								<tr>
									<td><strong><?php echo esc_html( $p->name ); ?></strong><?php echo 'active' !== $p->status ? ' <span class="osb-pill osb-pill--neutral">' . esc_html__( 'Off', 'osam-bookings' ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<td><?php echo esc_html( $on ? implode( ' ', $on ) : '—' ); ?></td>
									<td><?php echo esc_html( substr( $p->start_time, 0, 5 ) . ' – ' . substr( $p->last_seating, 0, 5 ) ); ?> <small class="osb-muted"><?php /* translators: %d minutes */ echo esc_html( sprintf( __( 'every %d min', 'osam-bookings' ), (int) $p->slot_interval ) ); ?></small></td>
									<td><?php echo esc_html( self::pacing_label( $p ) ); ?></td>
									<td class="osb-col-actions">
										<a class="osb-iconbtn" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-rest-hours&period=' . (int) $p->id . '#periods' ) ); ?>" title="<?php esc_attr_e( 'Edit', 'osam-bookings' ); ?>"><span class="dashicons dashicons-edit"></span></a>
										<a class="osb-iconbtn osb-iconbtn--danger osb-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=osb_delete_rest_period&id=' . (int) $p->id ), 'osb_delete_rest_period_' . (int) $p->id ) ); ?>" title="<?php esc_attr_e( 'Delete', 'osam-bookings' ); ?>"><span class="dashicons dashicons-trash"></span></a>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form">
					<?php wp_nonce_field( 'osb_save_rest_period' ); ?>
					<input type="hidden" name="action" value="osb_save_rest_period">
					<input type="hidden" name="id" value="<?php echo $edit ? (int) $edit->id : 0; ?>">
					<?php OSB_Admin::card_head( $edit ? __( 'Edit service period', 'osam-bookings' ) : __( 'Add a service period', 'osam-bookings' ) ); ?>
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_p_name"><?php esc_html_e( 'Name', 'osam-bookings' ); ?></label><input type="text" id="osb_p_name" name="name" required value="<?php echo esc_attr( $edit ? $edit->name : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Dinner', 'osam-bookings' ); ?>"></div>
						<div class="osb-field osb-field--half"><label><?php esc_html_e( 'Days', 'osam-bookings' ); ?></label>
							<div class="osb-chips-check">
								<?php $sel = $edit ? array_map( 'intval', explode( ',', (string) $edit->weekdays ) ) : array( 1, 2, 3, 4, 5, 6, 0 ); ?>
								<?php foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $d ) : ?>
									<label><input type="checkbox" name="weekdays[]" value="<?php echo (int) $d; ?>" <?php checked( in_array( $d, $sel, true ) ); ?>><span><?php echo esc_html( $days[ $d ][0] ); ?></span></label>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="osb-field osb-field--quarter"><label for="osb_p_start"><?php esc_html_e( 'First seating', 'osam-bookings' ); ?></label><input type="time" id="osb_p_start" name="start_time" required value="<?php echo esc_attr( $edit ? substr( $edit->start_time, 0, 5 ) : '18:00' ); ?>"></div>
						<div class="osb-field osb-field--quarter"><label for="osb_p_last"><?php esc_html_e( 'Last seating', 'osam-bookings' ); ?></label><input type="time" id="osb_p_last" name="last_seating" required value="<?php echo esc_attr( $edit ? substr( $edit->last_seating, 0, 5 ) : '21:30' ); ?>"></div>
						<div class="osb-field osb-field--quarter"><label for="osb_p_int"><?php esc_html_e( 'Every (minutes)', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'slot_interval', $edit ? (int) $edit->slot_interval : 15, 'select', array( 10 => '10', 15 => '15', 20 => '20', 30 => '30', 45 => '45', 60 => '60' ), 'osb_p_int' ); ?></div>
						<div class="osb-field osb-field--quarter"></div>
						<div class="osb-field osb-field--half"><label for="osb_p_slot"><?php esc_html_e( 'Most guests arriving per seating time', 'osam-bookings' ); ?></label><input type="number" id="osb_p_slot" name="max_covers_slot" min="0" value="<?php echo $edit ? (int) $edit->max_covers_slot : 0; ?>"><p class="osb-help"><?php esc_html_e( '0 = no limit.', 'osam-bookings' ); ?></p></div>
						<div class="osb-field osb-field--half"><label for="osb_p_total"><?php esc_html_e( 'Most guests for the whole period', 'osam-bookings' ); ?></label><input type="number" id="osb_p_total" name="max_covers_total" min="0" value="<?php echo $edit ? (int) $edit->max_covers_total : 0; ?>"><p class="osb-help"><?php esc_html_e( '0 = as many as the tables allow.', 'osam-bookings' ); ?></p></div>
					</div>
					<?php if ( count( $areas ) > 1 ) : ?>
						<div class="osb-field"><label><?php esc_html_e( 'Areas open for this period', 'osam-bookings' ); ?></label>
							<div class="osb-chips-check">
								<?php $pa = $edit ? OSB_Restaurant::ids( $edit->areas ) : array(); ?>
								<?php foreach ( $areas as $a ) : ?>
									<label><input type="checkbox" name="areas[]" value="<?php echo (int) $a->id; ?>" <?php checked( in_array( (int) $a->id, $pa, true ) ); ?>><span><?php echo esc_html( $a->name ); ?></span></label>
								<?php endforeach; ?>
							</div>
							<p class="osb-help"><?php esc_html_e( 'Leave all unticked to open every area — e.g. tick only “Main room” if the terrace is closed for lunch.', 'osam-bookings' ); ?></p>
						</div>
					<?php endif; ?>
					<label class="osb-switch"><input type="checkbox" name="active" value="1" <?php checked( ! $edit || 'active' === $edit->status ); ?>><span></span><?php esc_html_e( 'Taking bookings', 'osam-bookings' ); ?></label>
					<div class="osb-formfoot">
						<?php if ( $edit ) : ?><a class="osb-link" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-rest-hours#periods' ) ); ?>"><?php esc_html_e( 'Cancel', 'osam-bookings' ); ?></a><?php endif; ?>
						<button class="osb-btn osb-btn--primary"><?php echo $edit ? esc_html__( 'Save period', 'osam-bookings' ) : esc_html__( 'Add period', 'osam-bookings' ); ?></button>
					</div>
				</form>
			</div>
		</div>

		<div class="osb-section" id="closures">
			<div class="osb-section__intro">
				<h2><?php esc_html_e( 'Closed dates', 'osam-bookings' ); ?></h2>
				<p><?php esc_html_e( 'Holidays, private hire or a lunch you’re not doing. Existing bookings aren’t touched.', 'osam-bookings' ); ?></p>
			</div>
			<div class="osb-stack">
				<?php $closures = OSB_Restaurant::closures(); ?>
				<?php if ( $closures ) : ?>
					<div class="osb-card osb-card--table">
						<table class="osb-table">
							<thead><tr><th><?php esc_html_e( 'Dates', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Closed', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Note', 'osam-bookings' ); ?></th><th class="osb-col-actions"></th></tr></thead>
							<tbody>
							<?php
							$pmap = array();
							foreach ( $periods as $p ) {
								$pmap[ (int) $p->id ] = $p->name;
							}
							foreach ( $closures as $c ) :
								?>
								<tr>
									<td><?php echo esc_html( mysql2date( 'D j M Y', $c->date_from ) . ( $c->date_to !== $c->date_from ? ' – ' . mysql2date( 'D j M Y', $c->date_to ) : '' ) ); ?></td>
									<td><?php echo esc_html( $c->period_id ? ( isset( $pmap[ (int) $c->period_id ] ) ? $pmap[ (int) $c->period_id ] : '?' ) : __( 'All day', 'osam-bookings' ) ); ?></td>
									<td><?php echo esc_html( $c->note ); ?></td>
									<td class="osb-col-actions"><a class="osb-iconbtn osb-iconbtn--danger osb-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=osb_delete_rest_closure&id=' . (int) $c->id ), 'osb_delete_rest_closure_' . (int) $c->id ) ); ?>" title="<?php esc_attr_e( 'Delete', 'osam-bookings' ); ?>"><span class="dashicons dashicons-trash"></span></a></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form">
					<?php wp_nonce_field( 'osb_save_rest_closure' ); ?>
					<input type="hidden" name="action" value="osb_save_rest_closure">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--quarter"><label for="osb_c_from"><?php esc_html_e( 'From', 'osam-bookings' ); ?></label><input type="date" id="osb_c_from" name="date_from" required></div>
						<div class="osb-field osb-field--quarter"><label for="osb_c_to"><?php esc_html_e( 'To', 'osam-bookings' ); ?></label><input type="date" id="osb_c_to" name="date_to"></div>
						<div class="osb-field osb-field--quarter"><label for="osb_c_p"><?php esc_html_e( 'Closed for', 'osam-bookings' ); ?></label>
							<?php
							$popts = array( 0 => __( 'The whole day', 'osam-bookings' ) );
							foreach ( $periods as $p ) {
								$popts[ (int) $p->id ] = $p->name;
							}
							OSB_Admin::input( 'period_id', 0, 'select', $popts, 'osb_c_p' );
							?>
						</div>
						<div class="osb-field osb-field--quarter"><label for="osb_c_note"><?php esc_html_e( 'Note', 'osam-bookings' ); ?></label><input type="text" id="osb_c_note" name="note" placeholder="<?php esc_attr_e( 'e.g. Christmas', 'osam-bookings' ); ?>"></div>
					</div>
					<div class="osb-formfoot"><button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Add closed dates', 'osam-bookings' ); ?></button></div>
				</form>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form" id="rules">
			<?php wp_nonce_field( 'osb_save_rest_rules' ); ?>
			<input type="hidden" name="action" value="osb_save_rest_rules">

			<div class="osb-section">
				<div class="osb-section__intro">
					<h2><?php esc_html_e( 'Tables & timing', 'osam-bookings' ); ?></h2>
					<p><?php esc_html_e( 'How long a party keeps its table depends on its size. The table is then free for the next booking (after any reset time).', 'osam-bookings' ); ?></p>
				</div>
				<div class="osb-card">
					<div class="osb-field"><label><?php esc_html_e( 'Choosing tables', 'osam-bookings' ); ?></label>
						<div class="osb-choicecards">
							<label class="osb-choicecard"><input type="radio" name="rest_assignment" value="auto" <?php checked( 'auto', $s['rest_assignment'] ); ?>><span><strong><?php esc_html_e( 'Automatic', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Each booking is given the best free table (the smallest that seats the party, or a combination). You can still move anyone.', 'osam-bookings' ); ?></small></span></label>
							<label class="osb-choicecard"><input type="radio" name="rest_assignment" value="manual" <?php checked( 'manual', $s['rest_assignment'] ); ?>><span><strong><?php esc_html_e( 'Staff assign them', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Bookings arrive “waiting for a table” and you drag them onto tables on the timeline. Online times are still only offered when everyone can be seated.', 'osam-bookings' ); ?></small></span></label>
						</div>
					</div>
					<div class="osb-field"><label><?php esc_html_e( 'Time at the table', 'osam-bookings' ); ?></label>
						<table class="osb-turns">
							<thead><tr><th><?php esc_html_e( 'Parties of up to', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Minutes', 'osam-bookings' ); ?></th></tr></thead>
							<tbody>
							<?php
							$turns = array_values( (array) $s['rest_turn_times'] );
							for ( $i = 0; $i < 6; $i++ ) :
								$band = isset( $turns[ $i ] ) ? $turns[ $i ] : array( '', '' );
								$big  = (int) $band[0] >= 999;
								?>
								<tr>
									<td><input type="number" name="turn_max[]" min="1" class="small-text" value="<?php echo esc_attr( $big ? '' : $band[0] ); ?>" placeholder="<?php echo $big ? esc_attr__( 'any size', 'osam-bookings' ) : ''; ?>"> <?php esc_html_e( 'guests', 'osam-bookings' ); ?></td>
									<td><input type="number" name="turn_min[]" min="15" step="5" class="small-text" value="<?php echo esc_attr( $band[1] ); ?>"></td>
								</tr>
							<?php endfor; ?>
							</tbody>
						</table>
						<p class="osb-help"><?php esc_html_e( 'Leave the guests box empty on the last row to cover any bigger party.', 'osam-bookings' ); ?></p>
					</div>
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_buffer"><?php esc_html_e( 'Reset time between parties (minutes)', 'osam-bookings' ); ?></label><input type="number" id="osb_buffer" name="rest_turn_buffer" min="0" step="5" value="<?php echo (int) $s['rest_turn_buffer']; ?>"></div>
						<div class="osb-field osb-field--half"><label><?php esc_html_e( 'Confirming bookings', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'rest_confirm', $s['rest_confirm'], 'select', array( 'auto' => __( 'Confirm straight away', 'osam-bookings' ), 'approval' => __( 'I’ll approve each one', 'osam-bookings' ) ) ); ?></div>
					</div>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro">
					<h2><?php esc_html_e( 'Who can book online', 'osam-bookings' ); ?></h2>
					<p><?php esc_html_e( 'Larger groups can send a request you confirm, or be asked to call.', 'osam-bookings' ); ?></p>
				</div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--quarter"><label for="osb_rmin"><?php esc_html_e( 'Smallest party', 'osam-bookings' ); ?></label><input type="number" id="osb_rmin" name="rest_min_party" min="1" value="<?php echo (int) $s['rest_min_party']; ?>"></div>
						<div class="osb-field osb-field--quarter"><label for="osb_rmax"><?php esc_html_e( 'Largest party online', 'osam-bookings' ); ?></label><input type="number" id="osb_rmax" name="rest_max_party_online" min="1" value="<?php echo (int) $s['rest_max_party_online']; ?>"></div>
						<div class="osb-field osb-field--quarter"><label for="osb_rlarge"><?php esc_html_e( 'Bigger groups', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'rest_large_party', $s['rest_large_party'], 'select', array( 'request' => __( 'Can send a request', 'osam-bookings' ), 'call' => __( 'Are asked to call', 'osam-bookings' ) ), 'osb_rlarge' ); ?></div>
						<div class="osb-field osb-field--quarter"><label for="osb_rreq"><?php esc_html_e( 'Largest request', 'osam-bookings' ); ?></label><input type="number" id="osb_rreq" name="rest_max_party_request" min="1" value="<?php echo (int) $s['rest_max_party_request']; ?>"></div>
						<div class="osb-field osb-field--quarter"><label for="osb_rnotice"><?php esc_html_e( 'Minimum notice (minutes)', 'osam-bookings' ); ?></label><input type="number" id="osb_rnotice" name="rest_min_notice_minutes" min="0" step="15" value="<?php echo (int) $s['rest_min_notice_minutes']; ?>"></div>
						<div class="osb-field osb-field--quarter"><label for="osb_radv"><?php esc_html_e( 'Book up to (days ahead)', 'osam-bookings' ); ?></label><input type="number" id="osb_radv" name="rest_max_advance_days" min="1" value="<?php echo (int) $s['rest_max_advance_days']; ?>"></div>
						<div class="osb-field osb-field--half"><label for="osb_rphone"><?php esc_html_e( 'Phone number for bookings', 'osam-bookings' ); ?></label><input type="tel" id="osb_rphone" name="rest_phone" value="<?php echo esc_attr( $s['rest_phone'] ); ?>"><p class="osb-help"><?php esc_html_e( 'Shown to bigger groups and when nothing is free.', 'osam-bookings' ); ?></p></div>
					</div>
					<label class="osb-switch"><input type="hidden" name="rest_area_choice" value="0"><input type="checkbox" name="rest_area_choice" value="1" <?php checked( (int) $s['rest_area_choice'] ); ?>><span></span><?php esc_html_e( 'Let guests choose a seating area (when you have more than one)', 'osam-bookings' ); ?></label>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro">
					<h2><?php esc_html_e( 'Securing tables', 'osam-bookings' ); ?></h2>
					<p><?php esc_html_e( 'Cut no-shows by taking a deposit, or by saving a card you only charge if the guests don’t turn up.', 'osam-bookings' ); ?></p>
				</div>
				<div class="osb-card">
					<?php
					$stripe_ok  = OSB_Restaurant::card_hold_ready();
					$online_gws = array();
					foreach ( osb()->payments->all() as $gid => $g ) {
						if ( 'pending_payment' === $g->initial_status() ) {
							$online_gws[ $gid ] = $g;
						}
					}
					$limit = array_filter( array_map( 'trim', explode( ',', (string) $s['rest_payment_methods'] ) ) );
					?>
					<div class="osb-choicecards osb-choicecards--3">
						<label class="osb-choicecard"><input type="radio" name="rest_payment_mode" value="none" <?php checked( 'none', $s['rest_payment_mode'] ); ?>><span><strong><?php esc_html_e( 'Nothing', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Guests just book. Simple, but no protection against no-shows.', 'osam-bookings' ); ?></small></span></label>
						<label class="osb-choicecard"><input type="radio" name="rest_payment_mode" value="deposit" <?php checked( 'deposit', $s['rest_payment_mode'] ); ?>><span><strong><?php esc_html_e( 'Deposit', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Paid online when booking, taken off the bill on the night.', 'osam-bookings' ); ?></small></span></label>
						<label class="osb-choicecard"><input type="radio" name="rest_payment_mode" value="card_hold" <?php checked( 'card_hold', $s['rest_payment_mode'] ); ?>><span><strong><?php esc_html_e( 'Card hold', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Card saved with Stripe, nothing charged. Charge a no-show fee with one click if they don’t come.', 'osam-bookings' ); ?></small></span></label>
					</div>
					<?php if ( 'card_hold' === $s['rest_payment_mode'] && ! $stripe_ok ) : ?>
						<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div><?php esc_html_e( 'Card holds need Stripe. Until Stripe is switched on and set up, tables are booked without a card.', 'osam-bookings' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-settings&tab=payments' ) ); ?>"><?php esc_html_e( 'Set up Stripe', 'osam-bookings' ); ?></a></div></div>
					<?php endif; ?>
					<?php if ( 'deposit' === $s['rest_payment_mode'] && ! OSB_Restaurant::deposit_methods() ) : ?>
						<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div><?php esc_html_e( 'Deposits need an online payment method (Stripe, PayPal or WooCommerce). Until one is switched on, tables are booked without a deposit.', 'osam-bookings' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-settings&tab=payments' ) ); ?>"><?php esc_html_e( 'Set up payments', 'osam-bookings' ); ?></a></div></div>
					<?php endif; ?>
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--third"><label for="osb_pmin"><?php esc_html_e( 'Only for parties of', 'osam-bookings' ); ?></label><div class="osb-affix"><input type="number" id="osb_pmin" name="rest_payment_min_party" min="1" value="<?php echo (int) $s['rest_payment_min_party']; ?>"><span><?php esc_html_e( 'or more', 'osam-bookings' ); ?></span></div></div>
						<div class="osb-field osb-field--third"><label for="osb_dep"><?php esc_html_e( 'Deposit', 'osam-bookings' ); ?></label><div class="osb-inline"><input type="number" id="osb_dep" name="rest_deposit_amount" min="0" step="0.01" class="small-text" value="<?php echo esc_attr( $s['rest_deposit_amount'] ); ?>"><?php OSB_Admin::input( 'rest_deposit_type', $s['rest_deposit_type'], 'select', array( 'per_cover' => __( 'per guest', 'osam-bookings' ), 'per_booking' => __( 'per booking', 'osam-bookings' ) ) ); ?></div></div>
						<div class="osb-field osb-field--third"><label for="osb_fee"><?php esc_html_e( 'No-show fee per guest', 'osam-bookings' ); ?></label><input type="number" id="osb_fee" name="rest_noshow_fee" min="0" step="0.01" value="<?php echo esc_attr( $s['rest_noshow_fee'] ); ?>"><p class="osb-help"><?php esc_html_e( 'Card hold only.', 'osam-bookings' ); ?></p></div>
					</div>
					<?php if ( $online_gws ) : ?>
						<div class="osb-field"><label><?php esc_html_e( 'Deposit payment methods', 'osam-bookings' ); ?></label>
							<div class="osb-chips-check">
								<?php foreach ( $online_gws as $gid => $g ) : ?>
									<label><input type="checkbox" name="rest_payment_methods[]" value="<?php echo esc_attr( $gid ); ?>" <?php checked( in_array( $gid, $limit, true ) ); ?>><span><?php echo esc_html( $g->admin_name() ); ?><?php echo $g->is_enabled() ? '' : ' <em>(' . esc_html__( 'off', 'osam-bookings' ) . ')</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></label>
								<?php endforeach; ?>
							</div>
							<p class="osb-help"><?php esc_html_e( 'Leave all unticked to offer every online method that’s switched on.', 'osam-bookings' ); ?></p>
						</div>
					<?php endif; ?>
					<div class="osb-field"><label for="osb_policy"><?php esc_html_e( 'Booking policy shown to guests', 'osam-bookings' ); ?></label><textarea id="osb_policy" name="rest_policy_text" rows="3" placeholder="<?php esc_attr_e( 'e.g. Please let us know 24 hours ahead if you can’t make it. Tables are held for 15 minutes.', 'osam-bookings' ); ?>"><?php echo esc_textarea( (string) $s['rest_policy_text'] ); ?></textarea><p class="osb-help"><?php esc_html_e( 'Cancellation cut-off and customer self-cancelling follow your general settings.', 'osam-bookings' ); ?></p></div>
				</div>
			</div>
			<div class="osb-savebar"><button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Save rules', 'osam-bookings' ); ?></button></div>
		</form>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Weekday names indexed 0 (Sun) – 6 (Sat): [ short, long ].
	 *
	 * @return array
	 */
	private static function weekday_names() {
		global $wp_locale;
		$out = array();
		for ( $d = 0; $d < 7; $d++ ) {
			$out[ $d ] = array( $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $d ) ), $wp_locale->get_weekday( $d ) );
		}
		return $out;
	}

	/**
	 * "40 per slot · 120 total".
	 *
	 * @param object $p Period.
	 * @return string
	 */
	private static function pacing_label( $p ) {
		$bits = array();
		if ( (int) $p->max_covers_slot > 0 ) {
			/* translators: %d guests */
			$bits[] = sprintf( __( '%d per seating', 'osam-bookings' ), (int) $p->max_covers_slot );
		}
		if ( (int) $p->max_covers_total > 0 ) {
			/* translators: %d guests */
			$bits[] = sprintf( __( '%d in total', 'osam-bookings' ), (int) $p->max_covers_total );
		}
		return $bits ? implode( ' · ', $bits ) : __( 'Tables only', 'osam-bookings' );
	}

	/**
	 * Save a period.
	 */
	public static function save_period() {
		OSB_Admin::verify( 'osb_save_rest_period' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$time = static function ( $key, $fallback ) {
			$v = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			return preg_match( '/^\d{2}:\d{2}$/', $v ) ? $v . ':00' : $fallback;
		};
		$start = $time( 'start_time', '18:00:00' );
		$last  = $time( 'last_seating', '21:00:00' );
		if ( $last < $start ) {
			$last = $start;
		}
		$days = array_values( array_intersect( array_map( 'intval', isset( $_POST['weekdays'] ) ? (array) wp_unslash( $_POST['weekdays'] ) : array() ), array( 0, 1, 2, 3, 4, 5, 6 ) ) );
		$row  = array(
			'name'             => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'weekdays'         => implode( ',', $days ),
			'start_time'       => $start,
			'last_seating'     => $last,
			'slot_interval'    => max( 5, min( 120, isset( $_POST['slot_interval'] ) ? absint( $_POST['slot_interval'] ) : 15 ) ),
			'max_covers_slot'  => isset( $_POST['max_covers_slot'] ) ? absint( $_POST['max_covers_slot'] ) : 0,
			'max_covers_total' => isset( $_POST['max_covers_total'] ) ? absint( $_POST['max_covers_total'] ) : 0,
			'areas'            => implode( ',', OSB_Restaurant::ids( isset( $_POST['areas'] ) ? (array) wp_unslash( $_POST['areas'] ) : array() ) ),
			'status'           => empty( $_POST['active'] ) ? 'inactive' : 'active',
		);
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		// phpcs:enable
		if ( '' === $row['name'] ) {
			$row['name'] = __( 'Service', 'osam-bookings' );
		}
		if ( $id && OSB_DB::get( 'rest_periods', $id ) ) {
			OSB_DB::update( 'rest_periods', $id, $row );
		} else {
			$row['created_at'] = current_time( 'mysql' );
			$row['sort_order'] = count( OSB_Restaurant::periods() );
			OSB_DB::insert( 'rest_periods', $row );
		}
		if ( ! $days ) {
			OSB_Admin::flash_warnings( array( __( 'No days are ticked, so this period never runs.', 'osam-bookings' ) ) );
		}
		OSB_Restaurant::flush();
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-rest-hours#periods' ) );
	}

	/**
	 * Save a closure.
	 */
	public static function save_closure() {
		OSB_Admin::verify( 'osb_save_rest_closure' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$from = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
		$to   = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-rest-hours#closures' ), 'error' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $to < $from ) {
			$to = $from;
		}
		OSB_DB::insert(
			'rest_closures',
			array(
				'date_from' => $from,
				'date_to'   => $to,
				'period_id' => isset( $_POST['period_id'] ) ? absint( $_POST['period_id'] ) : 0,
				'note'      => isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '',
			)
		);
		// phpcs:enable
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-rest-hours#closures' ) );
	}

	/**
	 * Save the rules.
	 */
	public static function save_rules() {
		OSB_Admin::verify( 'osb_save_rest_rules', 'manage_options' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$post  = wp_unslash( $_POST );
		$int   = static function ( $k, $min = 0 ) use ( $post ) {
			return max( $min, isset( $post[ $k ] ) ? absint( $post[ $k ] ) : 0 );
		};
		$pick  = static function ( $k, array $allowed ) use ( $post ) {
			return isset( $post[ $k ] ) && in_array( $post[ $k ], $allowed, true ) ? $post[ $k ] : $allowed[0];
		};
		$turns = array();
		$maxes = isset( $post['turn_max'] ) ? (array) $post['turn_max'] : array();
		$mins  = isset( $post['turn_min'] ) ? (array) $post['turn_min'] : array();
		foreach ( $mins as $i => $m ) {
			$m = absint( $m );
			if ( ! $m ) {
				continue;
			}
			$x       = isset( $maxes[ $i ] ) && '' !== trim( (string) $maxes[ $i ] ) ? max( 1, absint( $maxes[ $i ] ) ) : 999;
			$turns[] = array( $x, max( 15, $m ) );
		}
		usort(
			$turns,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		if ( ! $turns ) {
			$turns = OSB_Settings::defaults()['rest_turn_times'];
		}
		$min_party = $int( 'rest_min_party', 1 );
		$max_party = max( $min_party, $int( 'rest_max_party_online', 1 ) );
		OSB_Settings::update(
			array(
				'rest_assignment'         => $pick( 'rest_assignment', array( 'auto', 'manual' ) ),
				'rest_confirm'            => $pick( 'rest_confirm', array( 'auto', 'approval' ) ),
				'rest_turn_times'         => $turns,
				'rest_turn_buffer'        => $int( 'rest_turn_buffer' ),
				'rest_min_party'          => $min_party,
				'rest_max_party_online'   => $max_party,
				'rest_large_party'        => $pick( 'rest_large_party', array( 'request', 'call' ) ),
				'rest_max_party_request'  => max( $max_party, $int( 'rest_max_party_request', 1 ) ),
				'rest_min_notice_minutes' => $int( 'rest_min_notice_minutes' ),
				'rest_max_advance_days'   => $int( 'rest_max_advance_days', 1 ),
				'rest_phone'              => isset( $post['rest_phone'] ) ? sanitize_text_field( $post['rest_phone'] ) : '',
				'rest_area_choice'        => empty( $post['rest_area_choice'] ) ? 0 : 1,
				'rest_payment_mode'       => $pick( 'rest_payment_mode', array( 'none', 'deposit', 'card_hold' ) ),
				'rest_payment_min_party'  => $int( 'rest_payment_min_party', 1 ),
				'rest_deposit_type'       => $pick( 'rest_deposit_type', array( 'per_cover', 'per_booking' ) ),
				'rest_deposit_amount'     => max( 0, round( isset( $post['rest_deposit_amount'] ) ? (float) $post['rest_deposit_amount'] : 0, 2 ) ),
				'rest_noshow_fee'         => max( 0, round( isset( $post['rest_noshow_fee'] ) ? (float) $post['rest_noshow_fee'] : 0, 2 ) ),
				'rest_payment_methods'    => implode( ',', array_map( 'sanitize_key', isset( $post['rest_payment_methods'] ) ? (array) $post['rest_payment_methods'] : array() ) ),
				'rest_policy_text'        => isset( $post['rest_policy_text'] ) ? wp_kses_post( $post['rest_policy_text'] ) : '',
			)
		);
		// phpcs:enable
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-rest-hours#rules' ) );
	}

	/* ---------------------------------------------------------------------
	 * Booking screen panel
	 * ------------------------------------------------------------------ */

	/**
	 * Table details on a table booking's admin page.
	 *
	 * @param object $b Booking.
	 */
	public static function booking_panel( $b ) {
		if ( 'table' !== $b->type ) {
			return;
		}
		$tables = OSB_Restaurant::booking_table_ids( $b->id );
		$areas  = OSB_Restaurant::area_map();
		$card   = 'card_held' === $b->payment_status ? OSB_Restaurant::held_card( $b->id ) : null;
		$link   = admin_url( 'admin.php?page=osb-restaurant&date=' . substr( $b->start_at, 0, 10 ) . '&booking=' . (int) $b->id );
		?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Table', 'osam-bookings' ) ); ?>
			<dl class="osb-dl">
				<dt><?php esc_html_e( 'Guests', 'osam-bookings' ); ?></dt><dd><?php echo (int) $b->seats; ?></dd>
				<dt><?php esc_html_e( 'Tables', 'osam-bookings' ); ?></dt><dd><?php echo $tables ? esc_html( OSB_Restaurant::tables_label( $tables ) ) : '<span class="osb-pill osb-pill--warn">' . esc_html__( 'Waiting for a table', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
				<?php if ( $b->area_id && isset( $areas[ (int) $b->area_id ] ) ) : ?><dt><?php esc_html_e( 'Area', 'osam-bookings' ); ?></dt><dd><?php echo esc_html( $areas[ (int) $b->area_id ]->name ); ?></dd><?php endif; ?>
				<?php if ( $card ) : ?><dt><?php esc_html_e( 'Card on file', 'osam-bookings' ); ?></dt><dd><?php /* translators: %s fee */ echo esc_html( sprintf( __( 'No-show fee up to %s', 'osam-bookings' ), OSB_Settings::money( $card->amount, $b->currency ) ) ); ?></dd><?php endif; ?>
			</dl>
			<p><a class="osb-btn osb-btn--ghost osb-btn--sm" href="<?php echo esc_url( $link ); ?>"><span class="dashicons dashicons-food"></span><?php esc_html_e( 'Move or reseat on the timeline', 'osam-bookings' ); ?></a></p>
			<?php if ( $card && 'paid' !== $b->payment_status ) : ?>
				<p><button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-rest-charge" data-id="<?php echo (int) $b->id; ?>" data-amount="<?php echo esc_attr( OSB_Settings::money( $card->amount, $b->currency ) ); ?>"><?php esc_html_e( 'Charge no-show fee', 'osam-bookings' ); ?></button></p>
				<script>
				document.querySelectorAll('.osb-rest-charge').forEach(function (btn) {
					btn.addEventListener('click', function () {
						if (!window.confirm(<?php echo wp_json_encode( __( 'Charge %s to the card saved for this booking?', 'osam-bookings' ) ); ?>.replace('%s', btn.dataset.amount))) { return; }
						btn.disabled = true;
						fetch(<?php echo wp_json_encode( esc_url_raw( rest_url( OSB_Rest::NS . '/admin/tables/charge/' ) ) ); ?> + btn.dataset.id, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>, 'Content-Type': 'application/json' }, body: '{}' })
							.then(function (r) { return r.json().then(function (d) { if (!r.ok) { throw new Error(d.message || 'Error'); } return d; }); })
							.then(function () { window.location.reload(); })
							.catch(function (e) { btn.disabled = false; window.alert(e.message); });
					});
				});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}
}
