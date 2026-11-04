<?php
/**
 * Resources admin: list, edit, day schedule and staff bookings.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resource screens.
 */
class OSB_Admin_Resources {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_save_resource', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_osb_delete_resource', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_osb_duplicate_resource', array( __CLASS__, 'duplicate' ) );
		add_action( 'admin_post_osb_res_admin_book', array( __CLASS__, 'admin_book' ) );
		add_action( 'osb_admin_booking_side', array( __CLASS__, 'booking_panel' ) );
		add_action( 'admin_post_osb_access_code', array( __CLASS__, 'access_action' ) );
	}

	/**
	 * Defaults for a new resource.
	 *
	 * @return object
	 */
	private static function blank() {
		return (object) array(
			'id'                   => 0,
			'name'                 => '',
			'description'          => '',
			'category'             => '',
			'location'             => '',
			'image_id'             => 0,
			'color'                => '#0c8599',
			'capacity'             => 1,
			'share_mode'           => 'exclusive',
			'max_per_booking'      => 0,
			'time_mode'            => 'slots',
			'slot_length'          => 60,
			'max_slots'            => 1,
			'slot_options'         => '[{"minutes":60,"place":0,"exclusive":0}]',
			'slot_step'            => 0,
			'step'                 => 30,
			'min_minutes'          => 60,
			'max_minutes'          => 240,
			'buffer_before'        => 0,
			'buffer_after'         => 0,
			'hourly'               => 1,
			'half_day'             => 0,
			'half_split'           => '13:00:00',
			'full_day'             => 0,
			'max_days'             => 1,
			'price_place'          => '0.00',
			'price_exclusive'      => '0.00',
			'price_half_place'     => '0.00',
			'price_half_exclusive' => '0.00',
			'price_day_place'      => '0.00',
			'price_day_exclusive'  => '0.00',
			'min_notice_hours'     => null,
			'max_advance_days'     => 0,
			'payment_methods'      => '',
			'access_mode'          => '',
			'access_code'          => '',
			'access_length'        => 4,
			'access_label'         => '',
			'access_note'          => '',
			'access_release'       => 0,
			'status'               => 'active',
			'sort_order'           => 0,
		);
	}

	/**
	 * Router for Resources.
	 */
	public static function page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		// phpcs:enable
		if ( 'edit' === $action || 'new' === $action ) {
			self::edit( $id );
		} else {
			self::listing();
		}
	}

	/**
	 * "Shared · up to 6" / "Sole use".
	 *
	 * @param object $r Resource.
	 * @return string
	 */
	public static function sharing_label( $r ) {
		list( $shared, $excl ) = OSB_Resources::sharing( $r );
		if ( $shared && $excl ) {
			/* translators: %d places */
			return sprintf( __( '%d places, or book it out', 'osam-bookings' ), (int) $r->capacity );
		}
		/* translators: %d places */
		return $shared ? sprintf( __( '%d places (shared)', 'osam-bookings' ), (int) $r->capacity ) : __( 'Sole use only', 'osam-bookings' );
	}

	/**
	 * "1-hour slots · half day · full day".
	 *
	 * @param object $r Resource.
	 * @return string
	 */
	public static function timing_label( $r ) {
		$bits = array();
		if ( $r->hourly ) {
			if ( 'slots' === $r->time_mode ) {
				/* translators: %s durations */
				$bits[] = sprintf( __( '%s slots', 'osam-bookings' ), implode( ' / ', array_map( array( 'OSB_Admin_Resources_Helper', 'duration' ), OSB_Resources::durations( $r ) ) ) );
			} else {
				/* translators: 1: min 2: max */
				$bits[] = sprintf( __( '%1$s – %2$s', 'osam-bookings' ), OSB_Admin_Resources_Helper::duration( (int) $r->min_minutes ), OSB_Admin_Resources_Helper::duration( (int) $r->max_minutes ) );
			}
		}
		if ( $r->half_day ) {
			$bits[] = __( 'half day', 'osam-bookings' );
		}
		if ( $r->full_day ) {
			$bits[] = (int) $r->max_days > 1 ? sprintf( /* translators: %d days */ __( 'up to %d days', 'osam-bookings' ), (int) $r->max_days ) : __( 'full day', 'osam-bookings' );
		}
		return implode( ' · ', $bits );
	}

	/**
	 * List.
	 */
	private static function listing() {
		$all = OSB_Resources::all();
		OSB_Admin::open(
			__( 'Resources', 'osam-bookings' ),
			array(
				'subtitle' => __( 'Rooms, fields, halls, pitches or equipment people can book — a number of places at a time, or the whole thing to themselves.', 'osam-bookings' ),
				'actions'  => array(
					array( __( 'Schedule', 'osam-bookings' ), admin_url( 'admin.php?page=osb-res-schedule' ), 'secondary', 'dashicons-calendar-alt' ),
					array( __( 'Add resource', 'osam-bookings' ), admin_url( 'admin.php?page=osb-resources&action=new' ), 'primary', 'dashicons-plus-alt2' ),
				),
			)
		);
		if ( ! $all ) {
			OSB_Admin::empty_state( 'dashicons-building', __( 'No resources yet', 'osam-bookings' ), __( 'Add your first one — for example “Meeting room 1” or “Top field”.', 'osam-bookings' ), array( __( 'Add resource', 'osam-bookings' ), admin_url( 'admin.php?page=osb-resources&action=new' ) ) );
			OSB_Admin::close();
			return;
		}
		?>
		<div class="osb-card osb-card--table">
			<table class="osb-table osb-table--click">
				<thead><tr>
					<th><?php esc_html_e( 'Resource', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Places', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Booked by', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Price', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th>
					<th class="osb-col-actions"></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $all as $r ) :
					$edit = admin_url( 'admin.php?page=osb-resources&action=edit&id=' . (int) $r->id );
					$del  = wp_nonce_url( admin_url( 'admin-post.php?action=osb_delete_resource&id=' . (int) $r->id ), 'osb_delete_resource_' . (int) $r->id );
					$dup  = wp_nonce_url( admin_url( 'admin-post.php?action=osb_duplicate_resource&id=' . (int) $r->id ), 'osb_duplicate_resource_' . (int) $r->id );
					$hrs  = OSB_Resources::hours( $r->id );
					$open = array_filter( $hrs );
					?>
					<tr data-href="<?php echo esc_url( $edit ); ?>">
						<td><div class="osb-item"><?php echo OSB_Admin::avatar( $r->name, (int) $r->image_id, $r->color ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><a class="osb-rowlink" href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( $r->name ); ?></strong></a><small><?php echo esc_html( implode( ' · ', array_filter( array( $r->category, $r->location, $open ? '' : __( 'No opening hours set', 'osam-bookings' ) ) ) ) ); ?></small></span></div></td>
						<td><?php echo esc_html( self::sharing_label( $r ) ); ?></td>
						<td><?php echo esc_html( self::timing_label( $r ) ); ?></td>
						<td><?php echo esc_html( OSB_Resources::price_label( $r ) ); ?></td>
						<td><?php echo 'active' === $r->status ? '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'Bookable', 'osam-bookings' ) . '</span>' : '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Hidden', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="osb-col-actions">
							<a class="osb-iconbtn" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-res-schedule&resource=' . (int) $r->id ) ); ?>" title="<?php esc_attr_e( 'Schedule', 'osam-bookings' ); ?>"><span class="dashicons dashicons-calendar-alt"></span></a>
							<a class="osb-iconbtn" href="<?php echo esc_url( $dup ); ?>" title="<?php esc_attr_e( 'Duplicate', 'osam-bookings' ); ?>"><span class="dashicons dashicons-admin-page"></span></a>
							<button type="button" class="osb-iconbtn osb-copy" data-copy='[osam_resources id="<?php echo (int) $r->id; ?>"]' title="<?php esc_attr_e( 'Copy shortcode', 'osam-bookings' ); ?>"><span class="dashicons dashicons-shortcode"></span></button>
							<a class="osb-iconbtn osb-iconbtn--danger osb-delete" href="<?php echo esc_url( $del ); ?>" title="<?php esc_attr_e( 'Delete', 'osam-bookings' ); ?>"><span class="dashicons dashicons-trash"></span></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form osb-res-multi">
			<?php wp_nonce_field( 'osb_save_resource' ); ?>
			<input type="hidden" name="action" value="osb_save_resource">
			<input type="hidden" name="settings_only" value="1">
			<label class="osb-switch"><input type="checkbox" name="res_multi" value="1" <?php checked( (int) OSB_Settings::get( 'res_multi', 1 ) ); ?> onchange="this.form.submit()"><span></span><?php esc_html_e( 'Customers can book several resources in one booking (when they’re booked the same way)', 'osam-bookings' ); ?></label>
		</form>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Money input.
	 *
	 * @param string $name  Field.
	 * @param mixed  $value Value.
	 * @param string $label Label.
	 * @param string $class Extra class.
	 */
	private static function money_field( $name, $value, $label, $class = '' ) {
		$sym = trim( OSB_Settings::currencies()[ OSB_Settings::get( 'currency' ) ] ?? '' );
		printf(
			'<div class="osb-field osb-field--quarter %4$s"><label for="osb_%1$s">%3$s</label><div class="osb-affix osb-affix--pre"><span>%5$s</span><input type="number" id="osb_%1$s" name="%1$s" min="0" step="0.01" value="%2$s"></div></div>',
			esc_attr( $name ),
			esc_attr( $value ),
			esc_html( $label ),
			esc_attr( $class ),
			esc_html( $sym )
		);
	}

	/**
	 * Edit screen.
	 *
	 * @param int $id Resource.
	 */
	private static function edit( $id ) {
		$r = $id ? OSB_DB::get( 'resources', $id ) : null;
		$r = $r ? $r : self::blank();
		$hours = $r->id ? OSB_Resources::hours( $r->id ) : array();
		if ( ! $r->id ) {
			for ( $d = 1; $d <= 7; $d++ ) {
				$hours[ $d ] = array( array( '09:00', '17:00' ) );
			}
		}
		OSB_Admin::open(
			$r->id ? $r->name : __( 'New resource', 'osam-bookings' ),
			array(
				'back'       => admin_url( 'admin.php?page=osb-resources' ),
				'back_label' => __( 'Resources', 'osam-bookings' ),
				'actions'    => $r->id ? array( array( __( 'Schedule', 'osam-bookings' ), admin_url( 'admin.php?page=osb-res-schedule&resource=' . (int) $r->id ), 'secondary', 'dashicons-calendar-alt' ) ) : array(),
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form osb-res-form">
			<?php wp_nonce_field( 'osb_save_resource' ); ?>
			<input type="hidden" name="action" value="osb_save_resource">
			<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Details', 'osam-bookings' ); ?></h2></div>
				<div class="osb-card">
					<div class="osb-field"><label for="osb_name"><?php esc_html_e( 'Name', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="text" id="osb_name" name="name" required value="<?php echo esc_attr( $r->name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Meeting room 1, Top field', 'osam-bookings' ); ?>"></div>
					<div class="osb-field"><label><?php esc_html_e( 'Description', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'description', $r->description, 'editor' ); ?></div>
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_cat"><?php esc_html_e( 'Category', 'osam-bookings' ); ?></label><input type="text" id="osb_cat" name="category" value="<?php echo esc_attr( $r->category ); ?>" placeholder="<?php esc_attr_e( 'e.g. Rooms, Fields', 'osam-bookings' ); ?>"></div>
						<div class="osb-field osb-field--half"><label for="osb_loc"><?php esc_html_e( 'Location', 'osam-bookings' ); ?></label><input type="text" id="osb_loc" name="location" value="<?php echo esc_attr( $r->location ); ?>" placeholder="<?php esc_attr_e( 'Shown in emails', 'osam-bookings' ); ?>"></div>
						<div class="osb-field osb-field--half"><label><?php esc_html_e( 'Colour', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'color', $r->color, 'color' ); ?></div>
						<div class="osb-field osb-field--half"><label><?php esc_html_e( 'Image', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'image_id', $r->image_id, 'image' ); ?></div>
					</div>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Places & sharing', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'A field might take 6 dogs at once from different owners, or be booked out by one. A meeting room is usually sole use.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-choicecards osb-choicecards--3">
						<label class="osb-choicecard"><input type="radio" name="share_mode" value="exclusive" <?php checked( 'exclusive', $r->share_mode ); ?>><span><strong><?php esc_html_e( 'Sole use', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Every booking takes the whole thing.', 'osam-bookings' ); ?></small></span></label>
						<label class="osb-choicecard"><input type="radio" name="share_mode" value="shared" <?php checked( 'shared', $r->share_mode ); ?>><span><strong><?php esc_html_e( 'Shared places', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'People book places until it’s full.', 'osam-bookings' ); ?></small></span></label>
						<label class="osb-choicecard"><input type="radio" name="share_mode" value="both" <?php checked( 'both', $r->share_mode ); ?>><span><strong><?php esc_html_e( 'Either', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Book places, or book it out — only when nobody else is booked in.', 'osam-bookings' ); ?></small></span></label>
					</div>
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--third"><label for="osb_cap"><?php esc_html_e( 'Places at once', 'osam-bookings' ); ?></label><input type="number" id="osb_cap" name="capacity" min="1" value="<?php echo (int) $r->capacity; ?>"><p class="osb-help"><?php esc_html_e( 'People, dogs, desks… For sole use, the most people allowed.', 'osam-bookings' ); ?></p></div>
						<div class="osb-field osb-field--third"><label for="osb_mpb"><?php esc_html_e( 'Most places in one booking', 'osam-bookings' ); ?></label><input type="number" id="osb_mpb" name="max_per_booking" min="0" value="<?php echo (int) $r->max_per_booking; ?>"><p class="osb-help"><?php esc_html_e( '0 = up to all of them.', 'osam-bookings' ); ?></p></div>
					</div>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'How it’s booked', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Fixed slots suit fields and courts; a flexible length suits rooms. Add half days or whole days for longer hires.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<label class="osb-switch"><input type="checkbox" name="hourly" value="1" <?php checked( (int) $r->hourly ); ?>><span></span><strong><?php esc_html_e( 'By time', 'osam-bookings' ); ?></strong></label>
					<div class="osb-subsection osb-res-timing">
						<div class="osb-choicecards">
							<label class="osb-choicecard"><input type="radio" name="time_mode" value="slots" <?php checked( 'slots', $r->time_mode ); ?>><span><strong><?php esc_html_e( 'Fixed slots', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'e.g. 1-hour sessions on the hour from opening.', 'osam-bookings' ); ?></small></span></label>
							<label class="osb-choicecard"><input type="radio" name="time_mode" value="flexible" <?php checked( 'flexible', $r->time_mode ); ?>><span><strong><?php esc_html_e( 'Flexible length', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Customers choose how long, between a minimum and maximum.', 'osam-bookings' ); ?></small></span></label>
						</div>
						<div class="osb-field" data-mode="slots">
							<label><?php esc_html_e( 'Slot lengths & prices', 'osam-bookings' ); ?></label>
							<?php
							$sym  = trim( OSB_Settings::currencies()[ OSB_Settings::get( 'currency' ) ] ?? '' );
							$opts = OSB_Resources::slot_options( $r );
							?>
							<div class="osb-repeater-wrap">
							<table class="osb-table osb-table--compact osb-repeater osb-res-slots" data-template="osb-res-slot-tpl">
								<thead><tr><th><?php esc_html_e( 'Length (minutes)', 'osam-bookings' ); ?></th><th class="osb-res-col-place"><?php esc_html_e( 'Price per place', 'osam-bookings' ); ?></th><th class="osb-res-col-excl"><?php esc_html_e( 'Price to book it out', 'osam-bookings' ); ?></th><th class="osb-col-actions"></th></tr></thead>
								<tbody>
								<?php foreach ( $opts as $i => $o ) : ?>
									<tr>
										<td><input type="number" name="slot_options[<?php echo (int) $i; ?>][minutes]" min="5" step="5" value="<?php echo (int) $o['minutes']; ?>" required></td>
										<td class="osb-res-col-place"><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" name="slot_options[<?php echo (int) $i; ?>][place]" min="0" step="0.01" value="<?php echo esc_attr( number_format( $o['place'], 2, '.', '' ) ); ?>"></div></td>
										<td class="osb-res-col-excl"><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" name="slot_options[<?php echo (int) $i; ?>][exclusive]" min="0" step="0.01" value="<?php echo esc_attr( number_format( $o['exclusive'], 2, '.', '' ) ); ?>"></div></td>
										<td class="osb-col-actions"><button type="button" class="osb-iconbtn osb-iconbtn--danger osb-row-remove" aria-label="<?php esc_attr_e( 'Remove', 'osam-bookings' ); ?>"><span class="dashicons dashicons-no-alt"></span></button></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
							<p class="osb-empty-row" hidden></p>
							<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-row-add"><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add a length', 'osam-bookings' ); ?></button>
							<script type="text/template" id="osb-res-slot-tpl">
								<tr>
									<td><input type="number" name="slot_options[__i__][minutes]" min="5" step="5" value="60" required></td>
									<td class="osb-res-col-place"><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" name="slot_options[__i__][place]" min="0" step="0.01" value="0.00"></div></td>
									<td class="osb-res-col-excl"><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" name="slot_options[__i__][exclusive]" min="0" step="0.01" value="0.00"></div></td>
									<td class="osb-col-actions"><button type="button" class="osb-iconbtn osb-iconbtn--danger osb-row-remove"><span class="dashicons dashicons-no-alt"></span></button></td>
								</tr>
							</script>
							</div>
							<p class="osb-help"><?php esc_html_e( 'e.g. 30 minutes, 1 hour and 2 hours — customers pick one. Each length has its own price.', 'osam-bookings' ); ?></p>
						</div>
						<div class="osb-fieldgrid">
							<div class="osb-field osb-field--quarter" data-mode="slots"><label for="osb_sstep"><?php esc_html_e( 'Slots start every', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'slot_step', (int) $r->slot_step, 'select', array( 0 => __( 'Shortest length', 'osam-bookings' ), 15 => '15 min', 30 => '30 min', 60 => '1 hour', 120 => '2 hours' ), 'osb_sstep' ); ?><p class="osb-help"><?php esc_html_e( 'Counted from opening time.', 'osam-bookings' ); ?></p></div>
							<div class="osb-field osb-field--quarter" data-mode="flexible"><label for="osb_min"><?php esc_html_e( 'Shortest (minutes)', 'osam-bookings' ); ?></label><input type="number" id="osb_min" name="min_minutes" min="5" step="5" value="<?php echo (int) $r->min_minutes; ?>"></div>
							<div class="osb-field osb-field--quarter" data-mode="flexible"><label for="osb_max"><?php esc_html_e( 'Longest (minutes)', 'osam-bookings' ); ?></label><input type="number" id="osb_max" name="max_minutes" min="5" step="5" value="<?php echo (int) $r->max_minutes; ?>"></div>
							<div class="osb-field osb-field--quarter" data-mode="flexible"><label for="osb_step"><?php esc_html_e( 'In steps of', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'step', (int) $r->step, 'select', array( 15 => '15 min', 30 => '30 min', 60 => '1 hour' ), 'osb_step' ); ?></div>
							<div class="osb-field osb-field--quarter"><label for="osb_bb"><?php esc_html_e( 'Gap before (minutes)', 'osam-bookings' ); ?></label><input type="number" id="osb_bb" name="buffer_before" min="0" step="5" value="<?php echo (int) $r->buffer_before; ?>"></div>
							<div class="osb-field osb-field--quarter"><label for="osb_ba"><?php esc_html_e( 'Gap after (minutes)', 'osam-bookings' ); ?></label><input type="number" id="osb_ba" name="buffer_after" min="0" step="5" value="<?php echo (int) $r->buffer_after; ?>"><p class="osb-help"><?php esc_html_e( 'Cleaning or changeover time.', 'osam-bookings' ); ?></p></div>
						</div>
					</div>
					<label class="osb-switch"><input type="checkbox" name="half_day" value="1" <?php checked( (int) $r->half_day ); ?>><span></span><strong><?php esc_html_e( 'Half days', 'osam-bookings' ); ?></strong></label>
					<div class="osb-subsection"><div class="osb-field osb-field--quarter"><label for="osb_split"><?php esc_html_e( 'Morning ends / afternoon starts', 'osam-bookings' ); ?></label><input type="time" id="osb_split" name="half_split" value="<?php echo esc_attr( substr( $r->half_split, 0, 5 ) ); ?>"></div></div>
					<label class="osb-switch"><input type="checkbox" name="full_day" value="1" <?php checked( (int) $r->full_day ); ?>><span></span><strong><?php esc_html_e( 'Whole days', 'osam-bookings' ); ?></strong></label>
					<div class="osb-subsection"><div class="osb-field osb-field--quarter"><label for="osb_md"><?php esc_html_e( 'Up to (days in a row)', 'osam-bookings' ); ?></label><input type="number" id="osb_md" name="max_days" min="1" max="60" value="<?php echo (int) $r->max_days; ?>"></div><p class="osb-help"><?php esc_html_e( 'A day runs from opening to closing. Several days hold it overnight too, and every day must be open.', 'osam-bookings' ); ?></p></div>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Prices', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Per place is charged for each place booked. Book-out is the price for having it to yourself. By time is per slot (fixed slots) or per hour (flexible). Leave at 0 for free.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<table class="osb-res-prices">
						<thead><tr><th></th><th class="osb-res-col-place"><?php esc_html_e( 'Per place', 'osam-bookings' ); ?></th><th class="osb-res-col-excl"><?php esc_html_e( 'Book it out', 'osam-bookings' ); ?></th></tr></thead>
						<tbody>
							<?php
							$sym  = trim( OSB_Settings::currencies()[ OSB_Settings::get( 'currency' ) ] ?? '' );
							$rows = array(
								array( __( 'By time', 'osam-bookings' ) . ' <small class="osb-res-unit">' . esc_html__( '(per hour)', 'osam-bookings' ) . '</small>', 'price_place', 'price_exclusive' ),
								array( __( 'Half day', 'osam-bookings' ), 'price_half_place', 'price_half_exclusive' ),
								array( __( 'Per day', 'osam-bookings' ), 'price_day_place', 'price_day_exclusive' ),
							);
							foreach ( $rows as $row ) :
								?>
								<tr>
									<th scope="row"><?php echo wp_kses_post( $row[0] ); ?></th>
									<td class="osb-res-col-place"><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" name="<?php echo esc_attr( $row[1] ); ?>" min="0" step="0.01" value="<?php echo esc_attr( $r->{$row[1]} ); ?>"></div></td>
									<td class="osb-res-col-excl"><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" name="<?php echo esc_attr( $row[2] ); ?>" min="0" step="0.01" value="<?php echo esc_attr( $r->{$row[2]} ); ?>"></div></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<div class="osb-field"><label><?php esc_html_e( 'Payment methods offered', 'osam-bookings' ); ?></label><?php OSB_Admin::methods_checkboxes( $r->payment_methods ); ?></div>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Access code', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'A door, gate or key-safe code for the customer. It’s only shown once the booking is confirmed — never while it’s waiting for payment or approval.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-choicecards osb-choicecards--3">
						<label class="osb-choicecard"><input type="radio" name="access_mode" value="" <?php checked( '', (string) $r->access_mode ); ?>><span><strong><?php esc_html_e( 'No code', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'Nothing to give out.', 'osam-bookings' ); ?></small></span></label>
						<label class="osb-choicecard"><input type="radio" name="access_mode" value="fixed" <?php checked( 'fixed', (string) $r->access_mode ); ?>><span><strong><?php esc_html_e( 'The same code', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'e.g. a key safe. Change it here and future bookings get the new one.', 'osam-bookings' ); ?></small></span></label>
						<label class="osb-choicecard"><input type="radio" name="access_mode" value="unique" <?php checked( 'unique', (string) $r->access_mode ); ?>><span><strong><?php esc_html_e( 'A new code each booking', 'osam-bookings' ); ?></strong><small><?php esc_html_e( 'A random code per booking — enter it into your smart lock or keypad.', 'osam-bookings' ); ?></small></span></label>
					</div>
					<div class="osb-fieldgrid" data-access="on">
						<div class="osb-field osb-field--quarter" data-access="fixed"><label for="osb_acode"><?php esc_html_e( 'Code', 'osam-bookings' ); ?></label><input type="text" id="osb_acode" name="access_code" maxlength="64" value="<?php echo esc_attr( $r->access_code ); ?>" autocomplete="off"></div>
						<div class="osb-field osb-field--quarter" data-access="unique"><label for="osb_alen"><?php esc_html_e( 'Digits', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'access_length', (int) $r->access_length, 'select', array( 4 => '4', 5 => '5', 6 => '6', 8 => '8' ), 'osb_alen' ); ?></div>
						<div class="osb-field osb-field--quarter"><label for="osb_alabel"><?php esc_html_e( 'Call it', 'osam-bookings' ); ?></label><input type="text" id="osb_alabel" name="access_label" maxlength="60" value="<?php echo esc_attr( $r->access_label ); ?>" placeholder="<?php esc_attr_e( 'Access code', 'osam-bookings' ); ?>"><p class="osb-help"><?php esc_html_e( 'e.g. Door code, Gate PIN.', 'osam-bookings' ); ?></p></div>
						<div class="osb-field osb-field--quarter"><label for="osb_arel"><?php esc_html_e( 'Give it out', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'access_release', (int) $r->access_release, 'select', array( 0 => __( 'In the confirmation', 'osam-bookings' ), 1 => __( '1 hour before', 'osam-bookings' ), 2 => __( '2 hours before', 'osam-bookings' ), 6 => __( '6 hours before', 'osam-bookings' ), 12 => __( '12 hours before', 'osam-bookings' ), 24 => __( '24 hours before', 'osam-bookings' ), 48 => __( '2 days before', 'osam-bookings' ) ), 'osb_arel' ); ?><p class="osb-help"><?php esc_html_e( 'Later = emailed then, and shown on their booking page from then.', 'osam-bookings' ); ?></p></div>
						<div class="osb-field"><label for="osb_anote"><?php esc_html_e( 'Instructions shown with the code', 'osam-bookings' ); ?></label><textarea id="osb_anote" name="access_note" rows="2" placeholder="<?php esc_attr_e( 'e.g. The key safe is on the left of the gate. Please lock up and scramble the code when you leave.', 'osam-bookings' ); ?>"><?php echo esc_textarea( (string) $r->access_note ); ?></textarea></div>
					</div>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Opening hours', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Your usual week. Add seasonal hours for times of year when you open longer or shorter — for example light evenings once the clocks go forward.', 'osam-bookings' ); ?></p></div>
				<div class="osb-stack">
					<div class="osb-card"><?php OSB_Admin::card_head( __( 'Standard hours', 'osam-bookings' ), __( 'Used whenever no seasonal hours apply.', 'osam-bookings' ) ); ?><?php OSB_Admin_Staff::schedule_editor( $hours ); ?></div>
					<?php self::seasons_editor( $r ); ?>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Maintenance', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Times set aside for upkeep — mowing, cleaning, repairs. Nobody can book across them online. You’re warned if a booking already clashes.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card"><?php self::maintenance_editor( $r->id ? OSB_Resources::maintenance( $r->id ) : array() ); ?></div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Closed dates', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Whole days closed — holidays or private use. Existing bookings aren’t touched.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card"><?php OSB_Admin_Staff::days_off_table( $r->id ? OSB_Resources::closures( $r->id ) : array(), 'osb-resclosure-tpl' ); ?></div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Booking window', 'osam-bookings' ); ?></h2></div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_notice"><?php esc_html_e( 'Minimum notice (hours)', 'osam-bookings' ); ?></label><input type="number" id="osb_notice" name="min_notice_hours" min="0" step="0.5" value="<?php echo null === $r->min_notice_hours ? '' : esc_attr( $r->min_notice_hours ); ?>" placeholder="<?php echo esc_attr( OSB_Settings::get( 'min_notice_hours' ) ); ?>"><p class="osb-help"><?php esc_html_e( 'Empty = your general setting.', 'osam-bookings' ); ?></p></div>
						<div class="osb-field osb-field--half"><label for="osb_adv"><?php esc_html_e( 'Book up to (days ahead)', 'osam-bookings' ); ?></label><input type="number" id="osb_adv" name="max_advance_days" min="0" value="<?php echo (int) $r->max_advance_days ? (int) $r->max_advance_days : ''; ?>" placeholder="<?php echo esc_attr( OSB_Settings::get( 'max_advance_days' ) ); ?>"></div>
					</div>
					<label class="osb-switch"><input type="checkbox" name="active" value="1" <?php checked( 'active' === $r->status ); ?>><span></span><?php esc_html_e( 'Bookable online', 'osam-bookings' ); ?></label>
				</div>
			</div>

			<div class="osb-savebar"><span class="osb-savebar__dirty" hidden><?php esc_html_e( 'Unsaved changes', 'osam-bookings' ); ?></span><button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Save resource', 'osam-bookings' ); ?></button></div>
		</form>
		<script>
		(function () {
			var f = document.querySelector('.osb-res-form');
			if (!f) { return; }
			function sync() {
				var mode = (f.querySelector('[name="time_mode"]:checked') || {}).value;
				f.querySelectorAll('[data-mode]').forEach(function (el) { el.hidden = el.getAttribute('data-mode') !== mode; });
				var am = (f.querySelector('[name="access_mode"]:checked') || {}).value || '';
				f.querySelectorAll('[data-access]').forEach(function (el) { var w = el.getAttribute('data-access'); el.hidden = w === 'on' ? !am : w !== am; });
				var share = (f.querySelector('[name="share_mode"]:checked') || {}).value;
				var cap = parseInt(f.capacity.value, 10) || 1;
				var place = share !== 'exclusive' && cap > 1, excl = share !== 'shared' || cap <= 1;
				f.querySelectorAll('.osb-res-col-place').forEach(function (el) { el.style.display = place ? '' : 'none'; });
				f.querySelectorAll('.osb-res-col-excl').forEach(function (el) { el.style.display = excl ? '' : 'none'; });
				// Fixed slots are priced per length above, so the per-hour row is only for flexible.
				f.querySelector('.osb-res-unit').closest('tr').hidden = mode === 'slots';
				[['hourly', 0], ['half_day', 1], ['full_day', 2]].forEach(function (p) {
					var on = f[p[0]].checked;
					var sub = f[p[0]].closest('.osb-switch').nextElementSibling;
					if (sub && sub.classList.contains('osb-subsection')) { sub.hidden = !on; }
					var tr = f.querySelectorAll('.osb-res-prices tbody tr')[p[1]];
					if (tr) { tr.style.opacity = on ? '' : '.4'; }
				});
			}
			f.addEventListener('change', sync);
			f.addEventListener('input', sync);
			f.addEventListener('click', function () { setTimeout(sync, 0); }); // New slot rows.
			sync();
		})();
		</script>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Save (also the "book several together" switch on the list).
	 */
	public static function save() {
		OSB_Admin::verify( 'osb_save_resource' );
		$p = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $p['settings_only'] ) ) {
			OSB_Settings::update( array( 'res_multi' => empty( $p['res_multi'] ) ? 0 : 1 ) );
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-resources' ) );
		}
		$id    = absint( $p['id'] ?? 0 );
		$int   = static function ( $k, $min = 0, $max = 100000 ) use ( $p ) {
			return max( $min, min( $max, isset( $p[ $k ] ) ? (int) $p[ $k ] : 0 ) );
		};
		$money = static function ( $k ) use ( $p ) {
			return number_format( max( 0, (float) ( $p[ $k ] ?? 0 ) ), 2, '.', '' );
		};
		$split = isset( $p['half_split'] ) && preg_match( '/^\d{2}:\d{2}$/', $p['half_split'] ) ? $p['half_split'] . ':00' : '13:00:00';
		$min   = $int( 'min_minutes', 5, 1440 );
		$row   = array(
			'name'                 => sanitize_text_field( $p['name'] ?? '' ),
			'description'          => wp_kses_post( $p['description'] ?? '' ),
			'category'             => sanitize_text_field( $p['category'] ?? '' ),
			'location'             => sanitize_text_field( $p['location'] ?? '' ),
			'image_id'             => absint( $p['image_id'] ?? 0 ),
			'color'                => sanitize_hex_color( $p['color'] ?? '' ) ? sanitize_hex_color( $p['color'] ) : '#0c8599',
			'capacity'             => $int( 'capacity', 1, 100000 ),
			'share_mode'           => in_array( $p['share_mode'] ?? '', array( 'shared', 'exclusive', 'both' ), true ) ? $p['share_mode'] : 'exclusive',
			'max_per_booking'      => $int( 'max_per_booking' ),
			'time_mode'            => 'flexible' === ( $p['time_mode'] ?? '' ) ? 'flexible' : 'slots',
			'slot_step'            => in_array( (int) ( $p['slot_step'] ?? 0 ), array( 0, 15, 30, 60, 120 ), true ) ? (int) $p['slot_step'] : 0,
			'slot_options'         => self::parse_slots( $p['slot_options'] ?? array() ),
			'step'                 => in_array( (int) ( $p['step'] ?? 30 ), array( 15, 30, 60 ), true ) ? (int) $p['step'] : 30,
			'min_minutes'          => $min,
			'max_minutes'          => max( $min, $int( 'max_minutes', 5, 1440 ) ),
			'buffer_before'        => $int( 'buffer_before', 0, 600 ),
			'buffer_after'         => $int( 'buffer_after', 0, 600 ),
			'hourly'               => empty( $p['hourly'] ) ? 0 : 1,
			'half_day'             => empty( $p['half_day'] ) ? 0 : 1,
			'half_split'           => $split,
			'full_day'             => empty( $p['full_day'] ) ? 0 : 1,
			'max_days'             => $int( 'max_days', 1, 60 ),
			'price_place'          => $money( 'price_place' ),
			'price_exclusive'      => $money( 'price_exclusive' ),
			'price_half_place'     => $money( 'price_half_place' ),
			'price_half_exclusive' => $money( 'price_half_exclusive' ),
			'price_day_place'      => $money( 'price_day_place' ),
			'price_day_exclusive'  => $money( 'price_day_exclusive' ),
			'min_notice_hours'     => isset( $p['min_notice_hours'] ) && '' !== trim( (string) $p['min_notice_hours'] ) ? max( 0, (float) $p['min_notice_hours'] ) : null,
			'max_advance_days'     => $int( 'max_advance_days' ),
			'payment_methods'      => implode( ',', array_map( 'sanitize_key', (array) ( $p['payment_methods'] ?? array() ) ) ),
			'access_mode'          => in_array( $p['access_mode'] ?? '', array( 'fixed', 'unique' ), true ) ? $p['access_mode'] : '',
			'access_code'          => sanitize_text_field( $p['access_code'] ?? '' ),
			'access_length'        => in_array( (int) ( $p['access_length'] ?? 4 ), array( 4, 5, 6, 8 ), true ) ? (int) $p['access_length'] : 4,
			'access_label'         => sanitize_text_field( $p['access_label'] ?? '' ),
			'access_note'          => sanitize_textarea_field( $p['access_note'] ?? '' ),
			'access_release'       => $int( 'access_release', 0, 168 ),
			'status'               => empty( $p['active'] ) ? 'inactive' : 'active',
		);
		if ( '' === $row['name'] ) {
			OSB_Admin::flash_error( __( 'Please give the resource a name.', 'osam-bookings' ) );
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-resources&action=' . ( $id ? 'edit&id=' . $id : 'new' ) ), 'error' );
		}
		if ( 'fixed' === $row['access_mode'] && '' === $row['access_code'] ) {
			$row['access_mode'] = '';
		}
		if ( ! $row['hourly'] && ! $row['half_day'] && ! $row['full_day'] ) {
			$row['hourly'] = 1;
		}
		if ( $id && OSB_DB::get( 'resources', $id ) ) {
			OSB_DB::update( 'resources', $id, $row );
		} else {
			$row['created_at'] = current_time( 'mysql' );
			$row['sort_order'] = count( OSB_Resources::all() );
			$id                = OSB_DB::insert( 'resources', $row );
		}
		$schedule = OSB_Admin_Staff::parse_schedule( $p['schedule'] ?? array() );
		OSB_Resources::set_hours( $id, $schedule );
		OSB_Resources::set_closures( $id, array_values( (array) ( $p['days_off'] ?? array() ) ) );
		list( $seasons, $warn ) = self::parse_seasons( $p['seasons'] ?? array() );
		OSB_Resources::set_seasons( $id, $seasons );
		list( $maint, $merr ) = OSB_Resources::parse_maintenance( $p['maint'] ?? array() );
		OSB_Resources::set_maintenance( $id, $maint );
		$warn = array_merge( $warn, $merr );
		$res  = OSB_Resources::get( $id );
		if ( $res ) {
			$tf = get_option( 'time_format' );
			foreach ( array_slice( OSB_Resources::maintenance_clashes( $res ), 0, 5 ) as $c ) {
				/* translators: 1: booking ref 2: date and time */
				$warn[] = sprintf( __( 'Booking #%1$d (%2$s) overlaps maintenance. It hasn’t been changed — move or cancel it if needed.', 'osam-bookings' ), $c[0][4], date_i18n( 'D j M, ' . $tf, $c[0][0] + (int) $res->buffer_before * 60 ) );
			}
		}
		if ( ! array_filter( $schedule ) ) {
			$warn[] = __( 'No opening hours are set, so this can’t be booked yet.', 'osam-bookings' );
		}
		if ( $row['half_day'] ) {
			foreach ( $schedule as $ranges ) {
				$open  = $ranges ? $ranges[0][0] : '';
				$close = $ranges ? end( $ranges )[1] : '';
				if ( $open && ( substr( $split, 0, 5 ) <= $open || substr( $split, 0, 5 ) >= $close ) ) {
					$warn[] = __( 'On some open days the half-day split isn’t between opening and closing, so half days can’t be booked those days.', 'osam-bookings' );
					break;
				}
			}
		}
		OSB_Admin::flash_warnings( $warn );
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-resources&action=edit&id=' . (int) $id ) );
	}

	/**
	 * Clean the posted slot lengths: one row per length, shortest first.
	 *
	 * @param mixed $rows Posted rows.
	 * @return string JSON.
	 */
	private static function parse_slots( $rows ) {
		$out = array();
		foreach ( (array) $rows as $o ) {
			$m = isset( $o['minutes'] ) ? (int) $o['minutes'] : 0;
			if ( $m < 5 || $m > 1440 ) {
				continue;
			}
			$out[ $m ] = array(
				'minutes'   => $m,
				'place'     => round( max( 0, (float) ( $o['place'] ?? 0 ) ), 2 ),
				'exclusive' => round( max( 0, (float) ( $o['exclusive'] ?? 0 ) ), 2 ),
			);
		}
		if ( ! $out ) {
			$out[60] = array( 'minutes' => 60, 'place' => 0, 'exclusive' => 0 );
		}
		ksort( $out );
		return wp_json_encode( array_values( $out ) );
	}

	/**
	 * Duplicate.
	 */
	public static function duplicate() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_duplicate_resource_' . $id );
		$r = OSB_DB::get( 'resources', $id );
		if ( ! $r ) {
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-resources' ), 'error' );
		}
		$row = (array) $r;
		unset( $row['id'] );
		/* translators: %s name */
		$row['name']       = sprintf( __( '%s (copy)', 'osam-bookings' ), $r->name );
		$row['status']     = 'inactive';
		$row['created_at'] = current_time( 'mysql' );
		$new               = OSB_DB::insert( 'resources', $row );
		OSB_Resources::set_hours( $new, OSB_Resources::hours( $id ) );
		$copy = array();
		foreach ( OSB_Resources::seasons( $id ) as $se ) {
			$copy[] = array( 'name' => $se->name, 'mode' => $se->mode, 'date_from' => $se->date_from, 'date_to' => $se->date_to, 'schedule' => OSB_Resources::hours( $id, (int) $se->id ) );
		}
		OSB_Resources::set_seasons( $new, $copy );
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-resources&action=edit&id=' . (int) $new ) );
	}

	/**
	 * Delete (only when it has no bookings).
	 */
	public static function delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_delete_resource_' . $id );
		global $wpdb;
		$t = OSB_Install::table( 'booking_resources' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT booking_id FROM $t WHERE resource_id = %d LIMIT 1", $id ) ) ) {
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-resources' ), 'has_items' );
		}
		OSB_DB::delete( 'resources', $id );
		$wpdb->delete( OSB_Install::table( 'res_hours' ), array( 'resource_id' => $id ) );
		$wpdb->delete( OSB_Install::table( 'res_closures' ), array( 'resource_id' => $id ) );
		$wpdb->delete( OSB_Install::table( 'res_seasons' ), array( 'resource_id' => $id ) );
		$wpdb->delete( OSB_Install::table( 'res_maintenance' ), array( 'resource_id' => $id ) );
		OSB_Resources::flush();
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-resources' ), 'deleted' );
	}

	/* ---------------------------------------------------------------------
	 * Seasonal hours & maintenance editors
	 * ------------------------------------------------------------------ */

	/**
	 * Seasonal hours: one card per season, plus "what applies when".
	 *
	 * @param object $r Resource.
	 */
	private static function seasons_editor( $r ) {
		$seasons = $r->id ? OSB_Resources::seasons( $r->id ) : array();
		?>
		<div class="osb-seasons" data-next="<?php echo count( $seasons ); ?>">
			<?php foreach ( $seasons as $i => $se ) : ?>
				<?php self::season_block( (string) $i, $se, OSB_Resources::hours( $r->id, (int) $se->id ) ); ?>
			<?php endforeach; ?>
		</div>
		<div class="osb-card osb-seasons__add">
			<?php OSB_Admin::card_head( __( 'Seasonal hours', 'osam-bookings' ), __( 'Different hours for part of the year. New ones start as a copy of your standard hours. Clock-change dates are worked out for each year automatically.', 'osam-bookings' ) ); ?>
			<div class="osb-inline osb-seasons__btns">
				<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-season-add" data-season="bst" data-name="<?php esc_attr_e( 'Summer hours', 'osam-bookings' ); ?>"><span class="dashicons dashicons-palmtree"></span><?php esc_html_e( 'Summer (clocks forward)', 'osam-bookings' ); ?></button>
				<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-season-add" data-season="gmt" data-name="<?php esc_attr_e( 'Winter hours', 'osam-bookings' ); ?>"><span class="dashicons dashicons-cloud"></span><?php esc_html_e( 'Winter (clocks back)', 'osam-bookings' ); ?></button>
				<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-season-add" data-season="yearly" data-name=""><span class="dashicons dashicons-update"></span><?php esc_html_e( 'Same dates every year', 'osam-bookings' ); ?></button>
				<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-season-add" data-season="dates" data-name=""><span class="dashicons dashicons-calendar-alt"></span><?php esc_html_e( 'Specific dates', 'osam-bookings' ); ?></button>
			</div>
			<?php if ( $seasons && $r->id ) : ?>
				<div class="osb-season-timeline">
					<p class="osb-tags__label"><?php esc_html_e( 'Which hours apply over the next 12 months', 'osam-bookings' ); ?></p>
					<ul>
						<?php foreach ( array_slice( OSB_Resources::season_timeline( $r->id ), 0, 8 ) as $k => $t ) : ?>
							<li><span><?php echo 0 === $k ? esc_html__( 'Now', 'osam-bookings' ) : esc_html( sprintf( /* translators: %s date */ __( 'From %s', 'osam-bookings' ), date_i18n( 'D j M Y', strtotime( $t[0] . ' 12:00:00' ) ) ) ); ?></span><strong><?php echo esc_html( $t[1] ? $t[1]->name : __( 'Standard hours', 'osam-bookings' ) ); ?></strong></li>
						<?php endforeach; ?>
					</ul>
					<p class="osb-help"><?php esc_html_e( 'If two overlap, specific dates win, then yearly dates, then summer/winter. This list updates when you save.', 'osam-bookings' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<template id="osb-season-tpl"><?php self::season_block( '__s__', null, array() ); ?></template>
		<script>
		jQuery(function ($) {
			var $wrap = $('.osb-seasons'), next = parseInt($wrap.data('next'), 10) || 0;
			function syncMode($b) {
				var m = $b.find('.osb-season-mode').val();
				$b.find('[data-season-mode]').each(function () { this.hidden = this.getAttribute('data-season-mode') !== m; });
			}
			$wrap.find('.osb-season').each(function () { syncMode($(this)); });
			$(document).on('change', '.osb-season-mode', function () { syncMode($(this).closest('.osb-season')); });
			$(document).on('input', '.osb-season-name', function () { $(this).closest('.osb-season').find('.osb-season__title').text(this.value || this.placeholder); });
			$(document).on('click', '.osb-season-remove', function (e) {
				e.preventDefault();
				if (window.confirm(<?php echo wp_json_encode( __( 'Remove these seasonal hours?', 'osam-bookings' ) ); ?>)) { var $f = $(this).closest('form'); $(this).closest('.osb-season').remove(); $f.trigger('osb:dirty'); }
			});
			$(document).on('click', '.osb-season-add', function (e) {
				e.preventDefault();
				var key = 'n' + (next++);
				var $b = $($.trim($('#osb-season-tpl').html().replace(/__s__/g, key)));
				$b.find('.osb-season-mode').val($(this).data("season"));
				var nm = $(this).data('name') || '';
				$b.find('.osb-season-name').val(nm);
				$b.find('.osb-season__title').text(nm || $b.find('.osb-season-name').attr('placeholder'));
				// Start from the standard week as it is in the form (unsaved edits included).
				var $std = $('.osb-week[data-name="schedule"]');
				for (var d = 1; d <= 7; d++) {
					var $day = $b.find('.osb-week__day[data-day="' + d + '"]'), $btn = $day.find('.osb-range-add');
					var ranges = $std.find('.osb-week__day[data-day="' + d + '"] .osb-range').map(function () { var $i = $(this).find('input'); return [[$i.eq(0).val(), $i.eq(1).val()]]; }).get();
					ranges.forEach(function (r, i) {
						$btn.before('<span class="osb-range"><input type="time" name="seasons[' + key + '][schedule][' + d + '][' + i + '][0]" value="' + r[0] + '"><i>–</i><input type="time" name="seasons[' + key + '][schedule][' + d + '][' + i + '][1]" value="' + r[1] + '"><button type="button" class="osb-iconbtn osb-range-remove"><span class="dashicons dashicons-no-alt"></span></button></span>');
					});
					$day.toggleClass('is-off', !ranges.length).find('.osb-day-toggle').prop('checked', !!ranges.length);
				}
				$wrap.append($b);
				syncMode($b);
				$b.find('.osb-season-name').trigger('focus');
				$b.closest('form').trigger('osb:dirty');
			});
		});
		</script>
		<?php
	}

	/**
	 * One season's card.
	 *
	 * @param string      $key      Form key.
	 * @param object|null $se       Season.
	 * @param array       $schedule Weekly hours.
	 */
	private static function season_block( $key, $se, array $schedule ) {
		$n      = 'seasons[' . $key . ']';
		$mode   = $se ? $se->mode : 'bst';
		$months = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$months[ $m ] = date_i18n( 'F', gmmktime( 12, 0, 0, $m, 1, 2001 ) );
		}
		$part = static function ( $date, $which, $fallback ) {
			return $date ? (int) ( 'm' === $which ? substr( $date, 5, 2 ) : substr( $date, 8, 2 ) ) : $fallback;
		};
		$yf   = $se && 'yearly' === $se->mode ? $se->date_from : '';
		$yt   = $se && 'yearly' === $se->mode ? $se->date_to : '';
		$dsel = static function ( $name, $val ) {
			echo '<select name="' . esc_attr( $name ) . '" aria-label="' . esc_attr__( 'Day', 'osam-bookings' ) . '">';
			for ( $d = 1; $d <= 31; $d++ ) {
				echo '<option value="' . (int) $d . '"' . selected( $val, $d, false ) . '>' . (int) $d . '</option>';
			}
			echo '</select>';
		};
		$msel = static function ( $name, $val ) use ( $months ) {
			echo '<select name="' . esc_attr( $name ) . '" aria-label="' . esc_attr__( 'Month', 'osam-bookings' ) . '">';
			foreach ( $months as $m => $label ) {
				echo '<option value="' . (int) $m . '"' . selected( $val, $m, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
		};
		?>
		<details class="osb-card osb-season" <?php echo $se ? '' : 'open'; ?>>
			<summary class="osb-season__head">
				<span class="dashicons dashicons-arrow-right-alt2 osb-season__chev"></span>
				<strong class="osb-season__title"><?php echo esc_html( $se && $se->name ? $se->name : __( 'Seasonal hours', 'osam-bookings' ) ); ?></strong>
				<?php if ( $se ) : ?><span class="osb-season__when"><?php echo esc_html( OSB_Resources::season_label( $se ) ); ?></span><?php endif; ?>
				<button type="button" class="osb-link osb-link--danger osb-season-remove"><?php esc_html_e( 'Remove', 'osam-bookings' ); ?></button>
			</summary>
			<div class="osb-fieldgrid">
				<div class="osb-field osb-field--third"><label><?php esc_html_e( 'Name', 'osam-bookings' ); ?></label><input type="text" class="osb-season-name" name="<?php echo esc_attr( $n ); ?>[name]" maxlength="100" value="<?php echo esc_attr( $se ? $se->name : '' ); ?>" placeholder="<?php esc_attr_e( 'Seasonal hours', 'osam-bookings' ); ?>"></div>
				<div class="osb-field osb-field--two-thirds"><label><?php esc_html_e( 'When', 'osam-bookings' ); ?></label>
					<select class="osb-season-mode" name="<?php echo esc_attr( $n ); ?>[mode]">
						<?php foreach ( OSB_Resources::season_modes() as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $mode, $k ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
					</select>
				</div>
				<div class="osb-field osb-field--half" data-season-mode="yearly"><label><?php esc_html_e( 'Every year from', 'osam-bookings' ); ?></label>
					<div class="osb-inline"><?php $dsel( $n . '[from_d]', $part( $yf, 'd', 1 ) ); ?><?php $msel( $n . '[from_m]', $part( $yf, 'm', 4 ) ); ?></div>
				</div>
				<div class="osb-field osb-field--half" data-season-mode="yearly"><label><?php esc_html_e( 'Until (and including)', 'osam-bookings' ); ?></label>
					<div class="osb-inline"><?php $dsel( $n . '[to_d]', $part( $yt, 'd', 30 ) ); ?><?php $msel( $n . '[to_m]', $part( $yt, 'm', 9 ) ); ?></div>
				</div>
				<div class="osb-field osb-field--half" data-season-mode="dates"><label><?php esc_html_e( 'From', 'osam-bookings' ); ?></label><input type="date" name="<?php echo esc_attr( $n ); ?>[date_from]" value="<?php echo esc_attr( $se && 'dates' === $se->mode ? (string) $se->date_from : '' ); ?>"></div>
				<div class="osb-field osb-field--half" data-season-mode="dates"><label><?php esc_html_e( 'To (and including)', 'osam-bookings' ); ?></label><input type="date" name="<?php echo esc_attr( $n ); ?>[date_to]" value="<?php echo esc_attr( $se && 'dates' === $se->mode ? (string) $se->date_to : '' ); ?>"></div>
			</div>
			<?php OSB_Admin_Staff::schedule_editor( $schedule, $n . '[schedule]' ); ?>
		</details>
		<?php
	}

	/**
	 * Clean posted seasons.
	 *
	 * @param mixed $posted Posted.
	 * @return array [ seasons, warnings ]
	 */
	private static function parse_seasons( $posted ) {
		$out  = array();
		$warn = array();
		$md   = static function ( $m, $d ) {
			$m = max( 1, min( 12, (int) $m ) );
			$d = max( 1, min( (int) gmdate( 't', gmmktime( 12, 0, 0, $m, 1, 2000 ) ), (int) $d ) ); // 2000 is a leap year, so 29 Feb is allowed.
			return sprintf( '2000-%02d-%02d', $m, $d );
		};
		foreach ( (array) $posted as $p ) {
			$mode = isset( OSB_Resources::season_modes()[ $p['mode'] ?? '' ] ) ? $p['mode'] : 'dates';
			$name = sanitize_text_field( $p['name'] ?? '' );
			$from = null;
			$to   = null;
			if ( 'yearly' === $mode ) {
				$from = $md( $p['from_m'] ?? 1, $p['from_d'] ?? 1 );
				$to   = $md( $p['to_m'] ?? 12, $p['to_d'] ?? 31 );
			} elseif ( 'dates' === $mode ) {
				$from = sanitize_text_field( $p['date_from'] ?? '' );
				$to   = sanitize_text_field( $p['date_to'] ?? '' );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
					/* translators: %s name */
					$warn[] = sprintf( __( '“%s” needs a start date, so it wasn’t saved.', 'osam-bookings' ), $name ? $name : __( 'Seasonal hours', 'osam-bookings' ) );
					continue;
				}
				$to = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) && $to >= $from ? $to : $from;
			}
			if ( '' === $name ) {
				$name = 'bst' === $mode ? __( 'Summer hours', 'osam-bookings' ) : ( 'gmt' === $mode ? __( 'Winter hours', 'osam-bookings' ) : __( 'Seasonal hours', 'osam-bookings' ) );
			}
			$sched = OSB_Admin_Staff::parse_schedule( $p['schedule'] ?? array() );
			if ( ! array_filter( $sched ) ) {
				/* translators: %s name */
				$warn[] = sprintf( __( '“%s” has no open days, so it’s closed for the whole of that time.', 'osam-bookings' ), $name );
			}
			$out[] = array(
				'name'      => $name,
				'mode'      => $mode,
				'date_from' => $from,
				'date_to'   => $to,
				'schedule'  => $sched,
			);
		}
		return array( $out, $warn );
	}

	/**
	 * One maintenance row.
	 *
	 * @param string|int  $i Row key.
	 * @param object|null $m Saved row.
	 * @return string
	 */
	private static function maintenance_row( $i, $m ) {
		$weekly = $m && 'weekly' === $m->repeat_mode;
		$on     = $m ? array_map( 'intval', explode( ',', (string) $m->weekdays ) ) : array();
		$n      = 'maint[' . $i . ']';
		ob_start();
		?>
		<div class="osb-maint osb-repeat-row<?php echo $weekly ? ' is-weekly' : ''; ?>">
			<label class="osb-maint__f"><span><?php esc_html_e( 'Repeat', 'osam-bookings' ); ?></span><select name="<?php echo esc_attr( $n ); ?>[repeat]" class="osb-maint-repeat"><option value="once"><?php esc_html_e( 'Once', 'osam-bookings' ); ?></option><option value="weekly" <?php selected( $weekly ); ?>><?php esc_html_e( 'Every week', 'osam-bookings' ); ?></option></select></label>
			<label class="osb-maint__f"><span class="osb-maint__l-once"><?php esc_html_e( 'From', 'osam-bookings' ); ?></span><span class="osb-maint__l-weekly"><?php esc_html_e( 'Starting', 'osam-bookings' ); ?></span><input type="date" name="<?php echo esc_attr( $n ); ?>[from]" value="<?php echo esc_attr( $m ? $m->date_from : '' ); ?>"></label>
			<label class="osb-maint__f"><span class="osb-maint__l-once"><?php esc_html_e( 'Until (optional)', 'osam-bookings' ); ?></span><span class="osb-maint__l-weekly"><?php esc_html_e( 'Ending (optional)', 'osam-bookings' ); ?></span><input type="date" name="<?php echo esc_attr( $n ); ?>[to]" value="<?php echo esc_attr( $m && $m->date_to && ( $weekly || $m->date_to !== $m->date_from ) ? $m->date_to : '' ); ?>"></label>
			<div class="osb-maint__f osb-maint__days"><span><?php esc_html_e( 'On', 'osam-bookings' ); ?></span><span class="osb-maint__daylist"><?php foreach ( OSB_Admin_Staff::weekdays() as $d => $label ) : ?><label title="<?php echo esc_attr( $label ); ?>"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[days][]" value="<?php echo (int) $d; ?>" <?php checked( in_array( (int) $d, $on, true ) ); ?>><span><?php echo esc_html( mb_substr( $label, 0, 2 ) ); ?></span></label><?php endforeach; ?></span></div>
			<div class="osb-maint__f"><span><?php esc_html_e( 'Time (empty = all day)', 'osam-bookings' ); ?></span><span class="osb-maint__times"><input type="time" name="<?php echo esc_attr( $n ); ?>[start]" value="<?php echo esc_attr( $m && $m->start_time ? substr( $m->start_time, 0, 5 ) : '' ); ?>" aria-label="<?php esc_attr_e( 'Start time', 'osam-bookings' ); ?>"><i>–</i><input type="time" name="<?php echo esc_attr( $n ); ?>[end]" value="<?php echo esc_attr( $m && $m->end_time ? substr( $m->end_time, 0, 5 ) : '' ); ?>" aria-label="<?php esc_attr_e( 'End time', 'osam-bookings' ); ?>"></span></div>
			<label class="osb-maint__f osb-maint__note"><span><?php esc_html_e( 'Note (staff only)', 'osam-bookings' ); ?></span><input type="text" name="<?php echo esc_attr( $n ); ?>[note]" value="<?php echo esc_attr( $m ? $m->note : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Grass cutting', 'osam-bookings' ); ?>"></label>
			<button type="button" class="osb-iconbtn osb-iconbtn--danger osb-row-remove" aria-label="<?php esc_attr_e( 'Remove', 'osam-bookings' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Maintenance rows.
	 *
	 * @param array $rows Saved rows.
	 */
	private static function maintenance_editor( array $rows ) {
		?>
		<div class="osb-repeater-wrap osb-maint-wrap">
			<div class="osb-repeater osb-maint-list" data-template="osb-maint-tpl">
				<?php
				foreach ( $rows as $i => $m ) {
					echo self::maintenance_row( (int) $i, $m ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
			<p class="osb-empty-row" <?php echo $rows ? 'hidden' : ''; ?>><?php esc_html_e( 'No maintenance set.', 'osam-bookings' ); ?></p>
			<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-row-add"><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add maintenance', 'osam-bookings' ); ?></button>
			<p class="osb-help"><?php esc_html_e( 'Once: from the start time on the first date to the end time on the “Until” date (leave “Until” empty for the same day). Every week: the times on the ticked days, from the starting date — and until the ending date, if set.', 'osam-bookings' ); ?></p>
			<script type="text/template" id="osb-maint-tpl"><?php echo self::maintenance_row( '__i__', null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
			<script>
			jQuery(function ($) {
				$(document).on('change', '.osb-maint-repeat', function () { $(this).closest('.osb-maint').toggleClass('is-weekly', this.value === 'weekly'); });
			});
			</script>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Schedule
	 * ------------------------------------------------------------------ */

	/**
	 * Day schedule: one row per resource, bookings as bars, and a staff booking form.
	 */
	public static function schedule_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$date  = isset( $_GET['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['date'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) );
		$only  = isset( $_GET['resource'] ) ? absint( $_GET['resource'] ) : 0;
		// phpcs:enable
		$list  = array_values( array_filter( OSB_Resources::all(), static function ( $r ) use ( $only ) { return ! $only || (int) $r->id === $only; } ) );
		$base  = admin_url( 'admin.php?page=osb-res-schedule' . ( $only ? '&resource=' . $only : '' ) );
		$day0  = strtotime( $date . ' 00:00:00' );
		$from  = 24 * 60;
		$to    = 0;
		foreach ( $list as $r ) {
			foreach ( OSB_Resources::ranges( $r, $date ) as $rg ) {
				$from = min( $from, (int) ( ( $rg[0] - $day0 ) / 60 ) );
				$to   = max( $to, (int) ( ( $rg[1] - $day0 ) / 60 ) );
			}
		}
		if ( $from >= $to ) {
			$from = 8 * 60;
			$to   = 20 * 60;
		}
		$from = (int) floor( $from / 60 ) * 60;
		$to   = (int) ceil( $to / 60 ) * 60;
		$span = max( 60, $to - $from );
		$pct  = static function ( $min ) use ( $from, $span ) {
			return max( 0, min( 100, ( $min - $from ) / $span * 100 ) );
		};

		global $wpdb;
		$br       = OSB_Install::table( 'booking_resources' );
		$bk       = OSB_Install::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT b.*, r.resource_id, r.places, r.exclusive, r.hire FROM $br r JOIN $bk b ON b.id = r.booking_id WHERE b.start_at <= %s AND b.end_at > %s AND b.status NOT IN ('cancelled','pending_payment','payment_failed') ORDER BY b.start_at ASC", $date . ' 23:59:59', $date . ' 00:00:00' ) );
		$by_res   = array();
		foreach ( $rows as $b ) {
			$by_res[ (int) $b->resource_id ][] = $b;
		}
		OSB_Admin::open(
			__( 'Resource schedule', 'osam-bookings' ),
			array(
				'subtitle' => __( 'Who has what, when. Bars show places used, or a lock when it’s booked out.', 'osam-bookings' ),
				'actions'  => array( array( __( 'Resources', 'osam-bookings' ), admin_url( 'admin.php?page=osb-resources' ), 'secondary', 'dashicons-building' ) ),
			)
		);
		?>
		<div class="osb-res-bar">
			<a class="osb-iconbtn" href="<?php echo esc_url( add_query_arg( 'date', gmdate( 'Y-m-d', $day0 - DAY_IN_SECONDS ), $base ) ); ?>" aria-label="<?php esc_attr_e( 'Previous day', 'osam-bookings' ); ?>"><span class="dashicons dashicons-arrow-left-alt2"></span></a>
			<form method="get" class="osb-res-date"><input type="hidden" name="page" value="osb-res-schedule"><?php if ( $only ) : ?><input type="hidden" name="resource" value="<?php echo (int) $only; ?>"><?php endif; ?><strong><?php echo esc_html( date_i18n( 'l j F Y', $day0 + 43200 ) ); ?></strong> <input type="date" name="date" value="<?php echo esc_attr( $date ); ?>" onchange="this.form.submit()"></form>
			<a class="osb-iconbtn" href="<?php echo esc_url( add_query_arg( 'date', gmdate( 'Y-m-d', $day0 + DAY_IN_SECONDS ), $base ) ); ?>" aria-label="<?php esc_attr_e( 'Next day', 'osam-bookings' ); ?>"><span class="dashicons dashicons-arrow-right-alt2"></span></a>
			<?php if ( $only ) : ?><a class="osb-btn osb-btn--ghost osb-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-res-schedule&date=' . $date ) ); ?>"><?php esc_html_e( 'Show all resources', 'osam-bookings' ); ?></a><?php endif; ?>
			<a class="osb-btn osb-btn--primary osb-btn--sm osb-res-newbtn" href="#osb-res-new"><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'New booking', 'osam-bookings' ); ?></a>
		</div>
		<?php if ( ! $list ) : ?>
			<?php OSB_Admin::empty_state( 'dashicons-building', __( 'No resources yet', 'osam-bookings' ), __( 'Add a resource first.', 'osam-bookings' ), array( __( 'Add resource', 'osam-bookings' ), admin_url( 'admin.php?page=osb-resources&action=new' ) ) ); ?>
		<?php else : ?>
		<div class="osb-card osb-res-sched">
			<div class="osb-res-sched__row osb-res-sched__head">
				<div class="osb-res-sched__name"></div>
				<div class="osb-res-sched__track">
					<?php for ( $m = $from; $m <= $to; $m += 60 ) : ?>
						<span class="osb-res-sched__tick" style="left:<?php echo esc_attr( $pct( $m ) ); ?>%"><?php echo esc_html( date_i18n( get_option( 'time_format' ), $day0 + $m * 60 ) ); ?></span>
					<?php endfor; ?>
				</div>
			</div>
			<?php
			foreach ( $list as $r ) :
				$items = isset( $by_res[ (int) $r->id ] ) ? $by_res[ (int) $r->id ] : array();
				// Lanes so overlapping shared bookings stack.
				$ends  = array();
				foreach ( $items as $b ) {
					$s = max( $from, (int) ( ( strtotime( $b->start_at ) - $day0 ) / 60 ) );
					$lane = 0;
					while ( isset( $ends[ $lane ] ) && $ends[ $lane ] > $s ) {
						$lane++;
					}
					$b->lane       = $lane;
					$ends[ $lane ] = min( $to, (int) ( ( strtotime( $b->end_at ) - $day0 ) / 60 ) );
				}
				$lanes = max( 1, count( $ends ) );
				?>
				<div class="osb-res-sched__row">
					<div class="osb-res-sched__name"><span class="osb-dot" style="background:<?php echo esc_attr( $r->color ); ?>"></span><span><strong><?php echo esc_html( $r->name ); ?></strong><small><?php echo esc_html( self::sharing_label( $r ) ); ?><?php $sea_n = OSB_Resources::season_for( $r->id, $date ); echo $sea_n ? ' · ' . esc_html( $sea_n->name ) : ''; ?></small></span></div>
					<div class="osb-res-sched__track" style="height:<?php echo (int) ( $lanes * 34 + 10 ); ?>px">
						<?php foreach ( OSB_Resources::ranges( $r, $date ) as $rg ) : ?>
							<span class="osb-res-sched__open" style="left:<?php echo esc_attr( $pct( ( $rg[0] - $day0 ) / 60 ) ); ?>%;width:<?php echo esc_attr( $pct( ( $rg[1] - $day0 ) / 60 ) - $pct( ( $rg[0] - $day0 ) / 60 ) ); ?>%"></span>
						<?php endforeach; ?>
						<?php if ( OSB_Resources::is_closed( $r->id, $date ) ) : ?><span class="osb-res-sched__closed"><?php esc_html_e( 'Closed', 'osam-bookings' ); ?></span><?php endif; ?>
						<?php
						foreach ( OSB_Resources::blocks( $r->id, $day0, $day0 + DAY_IN_SECONDS ) as $blk ) :
							$ml = $pct( ( max( $blk[0], $day0 ) - $day0 ) / 60 );
							$mw = max( 1.5, $pct( ( min( $blk[1], $day0 + DAY_IN_SECONDS ) - $day0 ) / 60 ) - $ml );
							?>
							<span class="osb-res-sched__maint" style="left:<?php echo esc_attr( $ml ); ?>%;width:<?php echo esc_attr( $mw ); ?>%" title="<?php echo esc_attr( __( 'Maintenance', 'osam-bookings' ) . ( '' !== $blk[2] ? ': ' . $blk[2] : '' ) . ' · ' . date_i18n( get_option( 'time_format' ), $blk[0] ) . '–' . date_i18n( get_option( 'time_format' ), $blk[1] ) ); ?>"><span class="dashicons dashicons-admin-tools"></span><?php echo esc_html( '' !== $blk[2] ? $blk[2] : __( 'Maintenance', 'osam-bookings' ) ); ?></span>
						<?php endforeach; ?>
						<?php
						foreach ( $items as $b ) :
							$s = ( strtotime( $b->start_at ) - $day0 ) / 60;
							$e = ( strtotime( $b->end_at ) - $day0 ) / 60;
							$l = $pct( $s );
							$w = max( 1.5, $pct( $e ) - $l );
							?>
							<a class="osb-res-sched__bk is-<?php echo esc_attr( $b->status ); ?><?php echo $b->exclusive ? ' is-excl' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . (int) $b->id ) ); ?>" style="left:<?php echo esc_attr( $l ); ?>%;width:<?php echo esc_attr( $w ); ?>%;top:<?php echo (int) ( 5 + $b->lane * 34 ); ?>px;--c:<?php echo esc_attr( $r->color ); ?>" title="<?php echo esc_attr( $b->customer_name . ' · ' . mysql2date( get_option( 'time_format' ), $b->start_at ) . '–' . mysql2date( get_option( 'time_format' ), $b->end_at ) ); ?>">
								<span class="osb-res-sched__n"><?php echo $b->exclusive ? '<span class="dashicons dashicons-lock"></span>' : (int) $b->places; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><?php echo esc_html( $b->customer_name ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php self::admin_book_form( $list, $date ); ?>
		<?php endif; ?>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Staff booking form (phone or walk-in bookings; clashes are warned about, not blocked).
	 *
	 * @param array  $list Resources.
	 * @param string $date Date.
	 */
	private static function admin_book_form( array $list, $date ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form" id="osb-res-new">
			<?php wp_nonce_field( 'osb_res_admin_book' ); ?>
			<input type="hidden" name="action" value="osb_res_admin_book">
			<?php OSB_Admin::card_head( __( 'New booking', 'osam-bookings' ), __( 'For bookings taken by phone or in person. You can book any time — you’ll be warned about clashes but not stopped.', 'osam-bookings' ) ); ?>
			<div class="osb-field"><label><?php esc_html_e( 'Resources', 'osam-bookings' ); ?></label>
				<div class="osb-chips-check"><?php foreach ( $list as $r ) : ?><label><input type="checkbox" name="resource_ids[]" value="<?php echo (int) $r->id; ?>" <?php checked( 1 === count( $list ) ); ?>><span><i style="background:<?php echo esc_attr( $r->color ); ?>"></i><?php echo esc_html( $r->name ); ?></span></label><?php endforeach; ?></div>
			</div>
			<div class="osb-fieldgrid">
				<div class="osb-field osb-field--quarter"><label for="osb_rb_hire"><?php esc_html_e( 'Booking', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'hire', 'time', 'select', array( 'time' => __( 'By time', 'osam-bookings' ), 'half' => __( 'Half day', 'osam-bookings' ), 'day' => __( 'Whole days', 'osam-bookings' ) ), 'osb_rb_hire' ); ?></div>
				<div class="osb-field osb-field--quarter"><label for="osb_rb_date"><?php esc_html_e( 'Date', 'osam-bookings' ); ?></label><input type="date" id="osb_rb_date" name="date" required value="<?php echo esc_attr( $date ); ?>"></div>
				<div class="osb-field osb-field--quarter" data-hire="time"><label for="osb_rb_from"><?php esc_html_e( 'From', 'osam-bookings' ); ?></label><input type="time" id="osb_rb_from" name="time" value="10:00"></div>
				<div class="osb-field osb-field--quarter" data-hire="time"><label for="osb_rb_to"><?php esc_html_e( 'To', 'osam-bookings' ); ?></label><input type="time" id="osb_rb_to" name="end_time" value="11:00"></div>
				<div class="osb-field osb-field--quarter" data-hire="half"><label for="osb_rb_half"><?php esc_html_e( 'Which half', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'half', 'am', 'select', array( 'am' => OSB_Settings::label( 'res_morning' ), 'pm' => OSB_Settings::label( 'res_afternoon' ) ), 'osb_rb_half' ); ?></div>
				<div class="osb-field osb-field--quarter" data-hire="day"><label for="osb_rb_days"><?php esc_html_e( 'Days', 'osam-bookings' ); ?></label><input type="number" id="osb_rb_days" name="days" min="1" value="1"></div>
				<div class="osb-field osb-field--quarter"><label for="osb_rb_pl"><?php esc_html_e( 'Places', 'osam-bookings' ); ?></label><input type="number" id="osb_rb_pl" name="places" min="1" value="1"></div>
				<div class="osb-field osb-field--quarter"><label>&nbsp;</label><label class="osb-switch"><input type="checkbox" name="exclusive" value="1"><span></span><?php esc_html_e( 'Book it out', 'osam-bookings' ); ?></label></div>
				<div class="osb-field osb-field--third"><label for="osb_rb_name"><?php esc_html_e( 'Name', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="text" id="osb_rb_name" name="name" required></div>
				<div class="osb-field osb-field--third"><label for="osb_rb_email"><?php esc_html_e( 'Email', 'osam-bookings' ); ?></label><input type="email" id="osb_rb_email" name="email"></div>
				<div class="osb-field osb-field--third"><label for="osb_rb_phone"><?php esc_html_e( 'Phone', 'osam-bookings' ); ?></label><input type="tel" id="osb_rb_phone" name="phone"></div>
				<div class="osb-field osb-field--quarter"><label for="osb_rb_status"><?php esc_html_e( 'Status', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'status', 'confirmed', 'select', array( 'confirmed' => OSB_Bookings::statuses()['confirmed'], 'pending' => OSB_Bookings::statuses()['pending'] ), 'osb_rb_status' ); ?></div>
				<div class="osb-field osb-field--quarter"><label>&nbsp;</label><label class="osb-switch"><input type="checkbox" name="notify" value="1" checked><span></span><?php esc_html_e( 'Email the customer', 'osam-bookings' ); ?></label></div>
			</div>
			<div class="osb-fieldgrid"><?php OSB_Repeat::admin_field(); ?></div>
			<div class="osb-field"><label for="osb_rb_note"><?php esc_html_e( 'Staff note', 'osam-bookings' ); ?></label><textarea id="osb_rb_note" name="internal_note" rows="2"></textarea></div>
			<div class="osb-formfoot"><button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Add booking', 'osam-bookings' ); ?></button></div>
		</form>
		<script>
		(function () {
			var f = document.getElementById('osb-res-new');
			if (!f) { return; }
			function sync() { var h = f.hire.value; f.querySelectorAll('[data-hire]').forEach(function (el) { el.hidden = el.getAttribute('data-hire') !== h; }); }
			f.hire.addEventListener('change', sync); sync();
		})();
		</script>
		<?php
	}

	/**
	 * Save a staff booking.
	 */
	public static function admin_book() {
		OSB_Admin::verify( 'osb_res_admin_book' );
		$p     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$date  = sanitize_text_field( $p['date'] ?? '' );
		$back  = admin_url( 'admin.php?page=osb-res-schedule&date=' . rawurlencode( $date ) );
		$email = sanitize_email( $p['email'] ?? '' );
		$b     = OSB_Bookings::create(
			array(
				'type'         => 'resource',
				'resource_ids' => array_map( 'absint', (array) ( $p['resource_ids'] ?? array() ) ),
				'hire'         => sanitize_key( $p['hire'] ?? 'time' ),
				'date'         => $date,
				'time'         => sanitize_text_field( $p['time'] ?? '' ),
				'end_time'     => sanitize_text_field( $p['end_time'] ?? '' ),
				'half'         => sanitize_key( $p['half'] ?? 'am' ),
				'days'         => absint( $p['days'] ?? 1 ),
				'seats'        => max( 1, absint( $p['places'] ?? 1 ) ),
				'exclusive'    => ! empty( $p['exclusive'] ),
				'fields'       => array(
					'name'  => sanitize_text_field( $p['name'] ?? '' ),
					'email' => $email,
					'phone' => sanitize_text_field( $p['phone'] ?? '' ),
				),
				'admin'        => true,
				'lenient'      => true,
				'status'       => 'pending' === ( $p['status'] ?? '' ) ? 'pending' : 'confirmed',
				'notify'       => ! empty( $p['notify'] ) && '' !== $email,
				'repeat'       => OSB_Repeat::from_admin_post( $p ),
			)
		);
		if ( is_wp_error( $b ) ) {
			OSB_Admin::flash_error( $b->get_error_message() );
			OSB_Admin::redirect( $back . '#osb-res-new', 'error' );
		}
		if ( ! empty( $p['internal_note'] ) ) {
			OSB_DB::update( 'bookings', $b->id, array( 'internal_note' => sanitize_textarea_field( $p['internal_note'] ) ) );
		}
		OSB_Repeat::after_admin( $b );
		$warn = OSB_Resources::conflicts( $b );
		foreach ( (array) ( $b->repeat_made ?? array() ) as $fid ) {
			$f = OSB_DB::get( 'bookings', $fid );
			foreach ( $f ? OSB_Resources::conflicts( $f ) : array() as $w ) {
				$warn[] = mysql2date( 'D j M', $f->start_at ) . ': ' . $w;
			}
		}
		OSB_Admin::flash_warnings( $warn );
		OSB_Admin::redirect( $back, 'created' );
	}

	/**
	 * Resource details on a booking's admin page.
	 *
	 * @param object $b Booking.
	 */
	public static function booking_panel( $b ) {
		if ( 'resource' !== $b->type ) {
			return;
		}
		?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Resources', 'osam-bookings' ) ); ?>
			<dl class="osb-dl">
				<?php
				foreach ( OSB_Resources::lines( $b->id ) as $l ) :
					$r = OSB_Resources::get( $l->resource_id );
					?>
					<dt><?php echo esc_html( $r ? $r->name : __( '(deleted)', 'osam-bookings' ) ); ?></dt>
					<dd><?php echo esc_html( OSB_Resources::hire_label( $b, $l->hire ) . ' · ' . ( $l->exclusive ? __( 'booked out', 'osam-bookings' ) : sprintf( /* translators: %d places */ _n( '%d place', '%d places', (int) $l->places, 'osam-bookings' ), (int) $l->places ) ) . ( (float) $l->amount > 0 ? ' · ' . OSB_Settings::money( $l->amount, $b->currency ) : '' ) ); ?></dd>
				<?php endforeach; ?>
			</dl>
			<?php
			$codes = OSB_Access::codes( $b, true );
			if ( $codes['shown'] ) :
				$cust   = OSB_Access::codes( $b );
				$unique = false;
				$sent   = true;
				foreach ( OSB_Resources::lines( $b->id ) as $l ) {
					$r      = OSB_Resources::get( $l->resource_id );
					$unique = $unique || ( $r && 'unique' === $r->access_mode );
					$sent   = $sent && (int) $l->access_sent;
				}
				if ( $cust['shown'] ) {
					$state = $sent ? __( 'The customer has been given it.', 'osam-bookings' ) : __( 'Showing on the customer’s booking page.', 'osam-bookings' );
				} elseif ( $cust['later'] ) {
					$state = OSB_Access::later_text( $cust['later'] );
				} else {
					$state = __( 'Hidden from the customer until the booking is confirmed.', 'osam-bookings' );
				}
				$act = static function ( $do ) use ( $b ) {
					return wp_nonce_url( admin_url( 'admin-post.php?action=osb_access_code&do=' . $do . '&id=' . (int) $b->id ), 'osb_access_' . (int) $b->id );
				};
				?>
				<div class="osb-access-admin">
					<?php foreach ( $codes['shown'] as $c ) : ?>
						<div class="osb-access-admin__row"><span><?php echo esc_html( $c[1] . ( count( $codes['shown'] ) > 1 ? ' — ' . $c[0] : '' ) ); ?></span><code><?php echo esc_html( $c[2] ); ?></code></div>
					<?php endforeach; ?>
					<small><?php echo esc_html( $state ); ?></small>
					<p class="osb-inline">
						<?php if ( OSB_Access::booking_ok( $b ) ) : ?><a class="osb-btn osb-btn--ghost osb-btn--sm" href="<?php echo esc_url( $act( 'send' ) ); ?>"><span class="dashicons dashicons-email"></span><?php esc_html_e( 'Email it now', 'osam-bookings' ); ?></a><?php endif; ?>
						<?php if ( $unique ) : ?><a class="osb-btn osb-btn--ghost osb-btn--sm" data-osb-confirm="<?php esc_attr_e( 'Give this booking a new code? The old one should be removed from the lock.', 'osam-bookings' ); ?>" href="<?php echo esc_url( $act( 'new' ) ); ?>"><span class="dashicons dashicons-update"></span><?php esc_html_e( 'New code', 'osam-bookings' ); ?></a><?php endif; ?>
					</p>
				</div>
			<?php endif; ?>
			<p><a class="osb-btn osb-btn--ghost osb-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-res-schedule&date=' . substr( $b->start_at, 0, 10 ) ) ); ?>"><span class="dashicons dashicons-calendar-alt"></span><?php esc_html_e( 'View on the schedule', 'osam-bookings' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * "Email it now" / "New code" from the booking screen.
	 */
	public static function access_action() {
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_access_' . $id );
		$b  = OSB_DB::get( 'bookings', $id );
		$do = sanitize_key( $_GET['do'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$back = admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $id );
		if ( ! $b || 'resource' !== $b->type ) {
			OSB_Admin::redirect( $back, 'error' );
		}
		if ( 'new' === $do ) {
			OSB_Access::regenerate( $id );
			OSB_Access::mark_sent( $id, false );
			OSB_Admin::redirect( $back, 'saved' );
		}
		if ( 'send' === $do && OSB_Access::booking_ok( $b ) ) {
			OSB_Access::$force = true;
			$ok                = OSB_Emails::send( 'customer_access_code', $b, $b->customer_email, true );
			OSB_Access::$force = false;
			if ( $ok ) {
				OSB_Access::mark_sent( $id );
			}
			OSB_Admin::redirect( $back, $ok ? 'emailed' : 'error' );
		}
		OSB_Admin::redirect( $back, 'error' );
	}
}
