<?php
/**
 * Courses admin: courses, their intakes, the schedule builder and the class register.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Courses screens.
 */
class OSB_Admin_Courses {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_save_course', array( __CLASS__, 'save_course' ) );
		add_action( 'admin_post_osb_delete_course', array( __CLASS__, 'delete_course' ) );
		add_action( 'admin_post_osb_save_intake', array( __CLASS__, 'save_intake' ) );
		add_action( 'admin_post_osb_delete_intake', array( __CLASS__, 'delete_intake' ) );
		add_action( 'admin_post_osb_duplicate_intake', array( __CLASS__, 'duplicate_intake' ) );
		add_action( 'admin_post_osb_save_register', array( __CLASS__, 'save_register' ) );
		add_action( 'admin_post_osb_export_register', array( __CLASS__, 'export_register' ) );
	}

	/**
	 * Router.
	 */
	public static function page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		// phpcs:enable
		if ( 'edit' === $action || 'new' === $action ) {
			self::edit_course( $id );
		} elseif ( 'intake' === $action ) {
			self::edit_intake( $id );
		} elseif ( 'register' === $action && $id ) {
			self::register( $id );
		} else {
			self::listing();
		}
	}

	/**
	 * URL helper.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=osb-courses' ) );
	}

	/**
	 * Enrolled-places meter.
	 *
	 * @param int    $taken    Taken.
	 * @param int    $capacity Capacity.
	 * @param string $color    Colour.
	 * @param string $link     Optional link.
	 */
	private static function meter( $taken, $capacity, $color, $link = '' ) {
		$pct = $capacity ? min( 100, round( $taken / $capacity * 100 ) ) : 0;
		$tag = $link ? 'a href="' . esc_url( $link ) . '"' : 'span';
		echo '<' . $tag . ' class="osb-meterlink"><span class="osb-meter osb-meter--sm"><span style="width:' . (int) $pct . '%;background:' . esc_attr( $color ) . '"></span></span><small>' . (int) $taken . ' / ' . (int) $capacity . '</small></' . ( $link ? 'a' : 'span' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Status pill for an intake.
	 *
	 * @param object $intake Intake.
	 * @param object $course Course.
	 * @return string
	 */
	private static function intake_pill( $intake, $course ) {
		$now = current_time( 'mysql' );
		if ( $intake->last_end && $intake->last_end < $now ) {
			return '<span class="osb-pill osb-pill--completed">' . esc_html__( 'Finished', 'osam-bookings' ) . '</span>';
		}
		if ( $intake->first_start && $intake->first_start <= $now ) {
			return '<span class="osb-pill osb-pill--pending_payment">' . esc_html__( 'Running', 'osam-bookings' ) . '</span>';
		}
		switch ( OSB_Courses::closed_reason( $intake, $course ) ) {
			case '':
				return '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'Open', 'osam-bookings' ) . '</span>';
			case 'full':
				return '<span class="osb-pill osb-pill--warn">' . esc_html__( 'Full', 'osam-bookings' ) . '</span>';
			case 'no_sessions':
				return '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'No dates', 'osam-bookings' ) . '</span>';
			default:
				return '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Hidden', 'osam-bookings' ) . '</span>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Course list
	 * ------------------------------------------------------------------ */

	/**
	 * List.
	 */
	private static function listing() {
		$courses = OSB_Courses::courses();
		$now     = current_time( 'mysql' );

		OSB_Admin::open(
			__( 'Courses', 'osam-bookings' ),
			array(
				'subtitle' => __( 'One fee books a place on every session — e.g. a six-week beginners’ class. Each course can run several intakes with their own dates.', 'osam-bookings' ),
				'actions'  => array_merge( OSB_Admin_Import::actions( 'courses' ), array( array( __( 'Add course', 'osam-bookings' ), self::url( array( 'action' => 'new' ) ), 'primary', 'dashicons-plus-alt2' ) ) ),
			)
		);
		if ( ! $courses ) {
			OSB_Admin::empty_state( 'dashicons-welcome-learn-more', __( 'No courses yet', 'osam-bookings' ), __( 'Create a course, then add an intake with its session dates.', 'osam-bookings' ), array( __( 'Add a course', 'osam-bookings' ), self::url( array( 'action' => 'new' ) ) ) );
			OSB_Admin::close();
			return;
		}
		?>
		<div class="osb-card osb-card--table">
			<table class="osb-table osb-table--click">
				<thead><tr>
					<th><?php esc_html_e( 'Course', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Next intake', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Places booked', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Price', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th>
					<th class="osb-col-actions"></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $courses as $c ) :
					$edit    = self::url( array( 'action' => 'edit', 'id' => $c->id ) );
					$del     = wp_nonce_url( admin_url( 'admin-post.php?action=osb_delete_course&id=' . $c->id ), 'osb_delete_course_' . $c->id );
					$intakes = OSB_Courses::intakes( $c->id );
					$next    = null;
					$future  = 0;
					foreach ( $intakes as $in ) {
						if ( $in->last_end && $in->last_end >= $now ) {
							++$future;
							$next = $next ? $next : $in;
						}
					}
					?>
					<tr data-href="<?php echo esc_url( $edit ); ?>">
						<td>
							<div class="osb-item">
								<?php echo OSB_Admin::avatar( $c->name, $c->image_id, $c->color ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span><a class="osb-rowlink" href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( $c->name ); ?></strong></a>
								<small><?php /* translators: %d intakes */ echo esc_html( sprintf( _n( '%d upcoming intake', '%d upcoming intakes', $future, 'osam-bookings' ), $future ) . ( $c->location ? ' · ' . $c->location : '' ) ); ?></small></span>
							</div>
						</td>
						<td>
							<?php if ( $next ) : ?>
								<strong><?php echo esc_html( OSB_Courses::range_label( $next ) ); ?></strong>
								<small><?php /* translators: %d sessions */ echo esc_html( sprintf( _n( '%d session', '%d sessions', count( OSB_Courses::sessions( $next->id ) ), 'osam-bookings' ), count( OSB_Courses::sessions( $next->id ) ) ) ); ?></small>
							<?php else : ?>
								<span class="osb-muted">—</span>
							<?php endif; ?>
						</td>
						<td class="osb-col-meter">
							<?php
							if ( $next ) {
								self::meter( OSB_Courses::seats_taken( $next->id ), OSB_Courses::capacity( $next, $c ), $c->color, self::url( array( 'action' => 'register', 'id' => $next->id ) ) );
							} else {
								echo '<span class="osb-muted">—</span>';
							}
							?>
						</td>
						<td><?php echo $c->price > 0 ? esc_html( OSB_Settings::money( $c->price ) ) : '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Free', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo 'active' === $c->status ? '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'On sale', 'osam-bookings' ) . '</span>' : '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Hidden', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="osb-col-actions">
							<?php if ( $next ) : ?><a class="osb-iconbtn" href="<?php echo esc_url( self::url( array( 'action' => 'register', 'id' => $next->id ) ) ); ?>" title="<?php esc_attr_e( 'Class list & register', 'osam-bookings' ); ?>"><span class="dashicons dashicons-groups"></span></a><?php endif; ?>
							<button type="button" class="osb-iconbtn osb-copy" data-copy='[osam_booking course="<?php echo (int) $c->id; ?>"]' title="<?php esc_attr_e( 'Copy shortcode', 'osam-bookings' ); ?>"><span class="dashicons dashicons-shortcode"></span></button>
							<a class="osb-iconbtn osb-iconbtn--danger osb-delete" href="<?php echo esc_url( $del ); ?>" title="<?php esc_attr_e( 'Delete', 'osam-bookings' ); ?>"><span class="dashicons dashicons-trash"></span></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		OSB_Admin::close();
	}

	/* ---------------------------------------------------------------------
	 * Course editor
	 * ------------------------------------------------------------------ */

	/**
	 * Edit a course and list its intakes.
	 *
	 * @param int $id Course id.
	 */
	private static function edit_course( $id ) {
		$c = $id ? OSB_DB::get( 'courses', $id ) : null;
		if ( ! $c ) {
			$c = (object) array(
				'id'              => 0,
				'name'            => '',
				'description'     => '',
				'location'        => '',
				'price'           => '0.00',
				'capacity'        => 12,
				'max_per_booking' => 1,
				'staff_id'        => OSB_DB::is_solo() ? OSB_DB::solo_staff_id() : 0,
				'image_id'        => 0,
				'color'           => '#2f9e44',
				'payment_methods' => '',
				'online_mode'     => '',
				'online_url'      => '',
				'status'          => 'active',
			);
		}
		$solo = OSB_DB::is_solo();
		$sym  = trim( OSB_Settings::currencies()[ OSB_Settings::get( 'currency' ) ] ?? '' );

		OSB_Admin::open(
			$c->id ? $c->name : __( 'New course', 'osam-bookings' ),
			array(
				'back'       => self::url(),
				'back_label' => __( 'Courses', 'osam-bookings' ),
				'actions'    => $c->id ? array( array( __( 'Add intake', 'osam-bookings' ), self::url( array( 'action' => 'intake', 'course' => $c->id ) ), 'secondary', 'dashicons-plus-alt2' ) ) : array(),
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form">
			<?php wp_nonce_field( 'osb_save_course' ); ?>
			<input type="hidden" name="action" value="osb_save_course">
			<input type="hidden" name="id" value="<?php echo (int) $c->id; ?>">

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Course details', 'osam-bookings' ); ?></h2></div>
				<div class="osb-card">
					<div class="osb-field"><label for="osb_name"><?php esc_html_e( 'Name', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="text" id="osb_name" name="name" value="<?php echo esc_attr( $c->name ); ?>" required placeholder="<?php esc_attr_e( 'e.g. Beginners’ pottery — 6 weeks', 'osam-bookings' ); ?>"></div>
					<div class="osb-field"><label><?php esc_html_e( 'Description', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'description', $c->description, 'editor' ); ?></div>
					<div class="osb-field"><label for="osb_location"><?php esc_html_e( 'Location', 'osam-bookings' ); ?></label><input type="text" id="osb_location" name="location" value="<?php echo esc_attr( $c->location ); ?>"><p class="osb-help"><?php esc_html_e( 'Used for every session unless a session has its own location.', 'osam-bookings' ); ?></p></div>
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label><?php esc_html_e( 'Colour', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'color', $c->color, 'color' ); ?></div>
						<div class="osb-field osb-field--half"><label><?php esc_html_e( 'Image', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'image_id', $c->image_id, 'image' ); ?></div>
					</div>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php echo $solo ? esc_html__( 'Your diary', 'osam-bookings' ) : esc_html__( 'Tutor', 'osam-bookings' ); ?></h2></div>
				<div class="osb-card">
					<?php if ( ! $solo ) : ?>
						<?php
						$opts = array( 0 => __( '— None —', 'osam-bookings' ) );
						foreach ( OSB_DB::staff() as $st ) {
							$opts[ $st->id ] = $st->name;
						}
						?>
						<div class="osb-field"><label for="osb_tutor"><?php esc_html_e( 'Tutor', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'staff_id', $c->staff_id, 'select', $opts, 'osb_tutor' ); ?><p class="osb-help"><?php esc_html_e( 'Optional. Blocks this person’s diary during every session and sends them enrolment emails. Individual sessions can have a different tutor.', 'osam-bookings' ); ?></p></div>
					<?php else : ?>
						<label class="osb-switch"><input type="checkbox" name="block_diary" value="1" <?php checked( (int) $c->staff_id === OSB_DB::solo_staff_id() ); ?>><span></span><?php esc_html_e( 'Block my appointment diary during course sessions', 'osam-bookings' ); ?></label>
					<?php endif; ?>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Places & price', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'The price is a one-off fee for the whole course. Intakes can override places and price.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--third"><label for="osb_cap"><?php esc_html_e( 'Places per intake', 'osam-bookings' ); ?></label><input type="number" id="osb_cap" name="capacity" min="1" value="<?php echo (int) $c->capacity; ?>"></div>
						<div class="osb-field osb-field--third"><label for="osb_max"><?php esc_html_e( 'Max per booking', 'osam-bookings' ); ?></label><input type="number" id="osb_max" name="max_per_booking" min="1" value="<?php echo (int) $c->max_per_booking; ?>"></div>
						<div class="osb-field osb-field--third"><label for="osb_price"><?php esc_html_e( 'Course fee per place', 'osam-bookings' ); ?></label><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" id="osb_price" name="price" min="0" step="0.01" value="<?php echo esc_attr( $c->price ); ?>"></div></div>
					</div>
					<div class="osb-field"><label><?php esc_html_e( 'Payment methods offered', 'osam-bookings' ); ?></label><?php OSB_Admin::methods_checkboxes( $c->payment_methods ); ?></div>
					<label class="osb-switch"><input type="hidden" name="status" value="hidden"><input type="checkbox" name="status" value="active" <?php checked( 'active', $c->status ); ?>><span></span><?php esc_html_e( 'On sale — customers can enrol online', 'osam-bookings' ); ?></label>
				</div>
			</div>

			<?php OSB_Meetings::editor_section( 'course', $c ); ?>

			<div class="osb-savebar">
				<?php if ( $c->id ) : ?><button type="button" class="osb-btn osb-btn--ghost osb-copy" data-copy='[osam_booking course="<?php echo (int) $c->id; ?>"]'><span class="dashicons dashicons-shortcode"></span><?php esc_html_e( 'Copy shortcode', 'osam-bookings' ); ?></button><?php endif; ?>
				<button class="osb-btn osb-btn--primary"><?php echo $c->id ? esc_html__( 'Save course', 'osam-bookings' ) : esc_html__( 'Add course & set dates', 'osam-bookings' ); ?></button>
			</div>
		</form>

		<?php if ( $c->id ) : ?>
			<?php self::intakes_card( $c ); ?>
		<?php endif; ?>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Intakes of a course.
	 *
	 * @param object $c Course.
	 */
	private static function intakes_card( $c ) {
		$intakes = OSB_Courses::intakes( $c->id );
		?>
		<div class="osb-section" id="intakes">
			<div class="osb-section__intro"><h2><?php esc_html_e( 'Intakes & dates', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Each intake is one run of the course with its own sessions and places. Enrolment closes when the first session starts.', 'osam-bookings' ); ?></p></div>
			<?php if ( ! $intakes ) : ?>
				<?php OSB_Admin::empty_state( 'dashicons-calendar-alt', __( 'No intakes yet', 'osam-bookings' ), __( 'Add an intake to set the session dates customers will be booked onto.', 'osam-bookings' ), array( __( 'Add intake', 'osam-bookings' ), self::url( array( 'action' => 'intake', 'course' => $c->id ) ) ) ); ?>
			<?php else : ?>
			<div>
			<div class="osb-card osb-card--table">
				<table class="osb-table osb-table--click">
					<thead><tr>
						<th><?php esc_html_e( 'Intake', 'osam-bookings' ); ?></th>
						<th><?php esc_html_e( 'Sessions', 'osam-bookings' ); ?></th>
						<th><?php esc_html_e( 'Places booked', 'osam-bookings' ); ?></th>
						<th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th>
						<th class="osb-col-actions"></th>
					</tr></thead>
					<tbody>
					<?php
					foreach ( $intakes as $in ) :
						$edit     = self::url( array( 'action' => 'intake', 'id' => $in->id ) );
						$reg      = self::url( array( 'action' => 'register', 'id' => $in->id ) );
						$dup      = wp_nonce_url( admin_url( 'admin-post.php?action=osb_duplicate_intake&id=' . $in->id ), 'osb_duplicate_intake_' . $in->id );
						$del      = wp_nonce_url( admin_url( 'admin-post.php?action=osb_delete_intake&id=' . $in->id ), 'osb_delete_intake_' . $in->id );
						$sessions = OSB_Courses::sessions( $in->id );
						?>
						<tr data-href="<?php echo esc_url( $edit ); ?>">
							<td>
								<div class="osb-item">
									<?php if ( $in->first_start ) : ?>
										<span class="osb-datebadge" style="background:<?php echo esc_attr( $c->color ); ?>"><b><?php echo esc_html( mysql2date( 'j', $in->first_start ) ); ?></b><?php echo esc_html( mysql2date( 'M', $in->first_start ) ); ?></span>
									<?php else : ?>
										<span class="osb-datebadge osb-datebadge--plain"><b>?</b></span>
									<?php endif; ?>
									<span><a class="osb-rowlink" href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( OSB_Courses::intake_label( $in ) ); ?></strong></a>
									<small><?php echo esc_html( OSB_Courses::range_label( $in ) ); ?></small></span>
								</div>
							</td>
							<td>
								<?php if ( $sessions ) : ?>
									<strong><?php echo (int) count( $sessions ); ?></strong>
									<small><?php echo esc_html( mysql2date( 'D ' . get_option( 'time_format' ), $sessions[0]->start_at ) ); ?></small>
								<?php else : ?>
									<span class="osb-muted">0</span>
								<?php endif; ?>
							</td>
							<td class="osb-col-meter"><?php self::meter( OSB_Courses::seats_taken( $in->id ), OSB_Courses::capacity( $in, $c ), $c->color, $reg ); ?></td>
							<td><?php echo self::intake_pill( $in, $c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="osb-col-actions">
								<a class="osb-iconbtn" href="<?php echo esc_url( $reg ); ?>" title="<?php esc_attr_e( 'Class list & register', 'osam-bookings' ); ?>"><span class="dashicons dashicons-groups"></span></a>
								<a class="osb-iconbtn" href="<?php echo esc_url( $dup ); ?>" title="<?php esc_attr_e( 'Duplicate as the next intake', 'osam-bookings' ); ?>"><span class="dashicons dashicons-admin-page"></span></a>
								<a class="osb-iconbtn osb-iconbtn--danger osb-delete" href="<?php echo esc_url( $del ); ?>" title="<?php esc_attr_e( 'Delete', 'osam-bookings' ); ?>"><span class="dashicons dashicons-trash"></span></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p><a class="osb-btn osb-btn--ghost" href="<?php echo esc_url( self::url( array( 'action' => 'intake', 'course' => $c->id ) ) ); ?>"><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add intake', 'osam-bookings' ); ?></a></p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save a course.
	 */
	public static function save_course() {
		OSB_Admin::verify( 'osb_save_course' );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id   = absint( isset( $post['id'] ) ? $post['id'] : 0 );
		$old  = $id ? OSB_DB::get( 'courses', $id ) : null;

		if ( OSB_DB::is_solo() ) {
			$staff_id = ! empty( $post['block_diary'] ) ? OSB_DB::solo_staff_id() : 0;
		} else {
			$staff_id = absint( isset( $post['staff_id'] ) ? $post['staff_id'] : 0 );
		}
		$color = sanitize_hex_color( isset( $post['color'] ) ? $post['color'] : '' );
		$data  = array(
			'name'            => sanitize_text_field( isset( $post['name'] ) ? $post['name'] : '' ),
			'description'     => wp_kses_post( isset( $post['description'] ) ? $post['description'] : '' ),
			'location'        => sanitize_text_field( isset( $post['location'] ) ? $post['location'] : '' ),
			'capacity'        => max( 1, absint( isset( $post['capacity'] ) ? $post['capacity'] : 1 ) ),
			'max_per_booking' => max( 1, absint( isset( $post['max_per_booking'] ) ? $post['max_per_booking'] : 1 ) ),
			'price'           => max( 0, (float) ( isset( $post['price'] ) ? $post['price'] : 0 ) ),
			'staff_id'        => $staff_id,
			'image_id'        => absint( isset( $post['image_id'] ) ? $post['image_id'] : 0 ),
			'color'           => $color ? $color : '#2f9e44',
			'payment_methods' => implode( ',', array_map( 'sanitize_key', (array) ( isset( $post['payment_methods'] ) ? $post['payment_methods'] : array() ) ) ),
			'status'          => isset( $post['status'] ) && 'active' === $post['status'] ? 'active' : 'hidden',
		);
		$data = array_merge( $data, OSB_Meetings::from_post( $post, 'course' ) );
		if ( '' === $data['name'] ) {
			OSB_Admin::flash_error( __( 'Please give the course a name.', 'osam-bookings' ) );
			wp_safe_redirect( wp_get_referer() );
			exit;
		}

		if ( $old ) {
			OSB_DB::update( 'courses', $id, $data );
			if ( (int) $old->staff_id !== $staff_id ) {
				// Sessions that followed the course tutor follow the new one.
				global $wpdb;
				$wpdb->update( OSB_Install::table( 'course_sessions' ), array( 'staff_id' => $staff_id ), array( 'course_id' => $id, 'staff_id' => (int) $old->staff_id ) );
				$wpdb->update( OSB_Install::table( 'bookings' ), array( 'staff_id' => $staff_id ), array( 'course_id' => $id, 'staff_id' => (int) $old->staff_id ) );
			}
			OSB_Availability::bust_cache();
			do_action( 'osb_course_saved', $id, $data );
			OSB_Admin::redirect( self::url( array( 'action' => 'edit', 'id' => $id ) ) );
		}

		$data['created_at'] = current_time( 'mysql' );
		$id                 = OSB_DB::insert( 'courses', $data );
		do_action( 'osb_course_saved', $id, $data );
		// Straight on to the first intake's dates.
		OSB_Admin::redirect( self::url( array( 'action' => 'intake', 'course' => $id ) ) );
	}

	/**
	 * Delete a course (only when nobody has ever booked it).
	 */
	public static function delete_course() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_delete_course_' . $id );
		if ( OSB_Courses::booking_count( 'course_id', $id ) ) {
			OSB_Admin::redirect( self::url(), 'has_items' );
		}
		foreach ( OSB_Courses::intakes( $id ) as $in ) {
			OSB_Courses::delete_intake( $in->id );
		}
		OSB_DB::delete( 'courses', $id );
		OSB_Admin::redirect( self::url(), 'deleted' );
	}

	/* ---------------------------------------------------------------------
	 * Intake editor (schedule builder)
	 * ------------------------------------------------------------------ */

	/**
	 * One schedule row.
	 *
	 * @param string|int $i      Row index.
	 * @param object     $s      Session (or blank).
	 * @param array      $staff  Staff options (empty in solo mode).
	 * @param object     $course Course.
	 */
	private static function session_row( $i, $s, $staff, $course ) {
		$n = 'sessions[' . $i . ']';
		?>
		<tr class="osb-session">
			<td class="osb-session__num"></td>
			<td><input type="hidden" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo (int) $s->id; ?>"><?php if ( ! $staff ) : ?><input type="hidden" name="<?php echo esc_attr( $n ); ?>[staff_id]" value="<?php echo (int) $s->staff_id; ?>" class="osb-session__staff"><?php endif; ?><input type="date" name="<?php echo esc_attr( $n ); ?>[date]" value="<?php echo esc_attr( $s->start_at ? substr( $s->start_at, 0, 10 ) : '' ); ?>" class="osb-session__date" aria-label="<?php esc_attr_e( 'Date', 'osam-bookings' ); ?>"></td>
			<td><input type="time" name="<?php echo esc_attr( $n ); ?>[start]" value="<?php echo esc_attr( $s->start_at ? substr( $s->start_at, 11, 5 ) : '' ); ?>" step="300" class="osb-session__start" aria-label="<?php esc_attr_e( 'Starts', 'osam-bookings' ); ?>"></td>
			<td><input type="time" name="<?php echo esc_attr( $n ); ?>[end]" value="<?php echo esc_attr( $s->end_at ? substr( $s->end_at, 11, 5 ) : '' ); ?>" step="300" class="osb-session__end" aria-label="<?php esc_attr_e( 'Ends', 'osam-bookings' ); ?>"></td>
			<td><input type="text" name="<?php echo esc_attr( $n ); ?>[location]" value="<?php echo esc_attr( $s->location ); ?>" placeholder="<?php echo esc_attr( $course->location ? $course->location : __( 'Course location', 'osam-bookings' ) ); ?>" aria-label="<?php esc_attr_e( 'Location', 'osam-bookings' ); ?>"></td>
			<?php if ( $staff ) : ?>
				<td><select name="<?php echo esc_attr( $n ); ?>[staff_id]" class="osb-session__staff" aria-label="<?php esc_attr_e( 'Tutor', 'osam-bookings' ); ?>">
					<?php foreach ( $staff as $sid => $sname ) : ?><option value="<?php echo (int) $sid; ?>" <?php selected( (int) $s->staff_id, (int) $sid ); ?>><?php echo esc_html( $sname ); ?></option><?php endforeach; ?>
				</select></td>
			<?php endif; ?>
			<td><input type="text" name="<?php echo esc_attr( $n ); ?>[note]" value="<?php echo esc_attr( $s->note ); ?>" placeholder="<?php esc_attr_e( 'e.g. Bring an apron', 'osam-bookings' ); ?>" aria-label="<?php esc_attr_e( 'Note', 'osam-bookings' ); ?>"></td>
			<td class="osb-col-actions"><button type="button" class="osb-iconbtn osb-iconbtn--danger osb-session__remove" title="<?php esc_attr_e( 'Remove session', 'osam-bookings' ); ?>"><span class="dashicons dashicons-no-alt"></span></button></td>
		</tr>
		<?php
	}

	/**
	 * Edit an intake.
	 *
	 * @param int $id Intake id (0 = new; course from ?course=).
	 */
	private static function edit_intake( $id ) {
		$intake = $id ? OSB_DB::get( 'course_intakes', $id ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$course_id = $intake ? (int) $intake->course_id : ( isset( $_GET['course'] ) ? absint( $_GET['course'] ) : 0 );
		$course    = OSB_DB::get( 'courses', $course_id );
		if ( ! $course ) {
			OSB_Admin::open( __( 'Course not found', 'osam-bookings' ), array( 'back' => self::url(), 'back_label' => __( 'Courses', 'osam-bookings' ) ) );
			OSB_Admin::close();
			return;
		}
		if ( ! $intake ) {
			$intake = (object) array(
				'id'          => 0,
				'course_id'   => $course->id,
				'name'        => '',
				'capacity'    => 0,
				'price'       => null,
				'status'      => 'active',
				'first_start' => null,
				'last_end'    => null,
			);
		}
		$sessions = $intake->id ? OSB_Courses::sessions( $intake->id ) : array();
		$enrolled = $intake->id ? count( OSB_Courses::enrolments( $intake->id ) ) : 0;
		$solo     = OSB_DB::is_solo();
		$staff    = array();
		if ( ! $solo ) {
			$staff = array( 0 => __( '— None —', 'osam-bookings' ) );
			foreach ( OSB_DB::staff() as $st ) {
				$staff[ (int) $st->id ] = $st->name;
			}
		}
		$sym   = trim( OSB_Settings::currencies()[ OSB_Settings::get( 'currency' ) ] ?? '' );
		$blank = (object) array(
			'id'       => 0,
			'start_at' => '',
			'end_at'   => '',
			'location' => '',
			'staff_id' => (int) $course->staff_id,
			'note'     => '',
		);
		// A new intake starts with one row on the same weekday next week, as a starting point.
		$suggest = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +7 days' ) );

		OSB_Admin::open(
			$intake->id ? OSB_Courses::intake_label( $intake ) : __( 'New intake', 'osam-bookings' ),
			array(
				'back'       => self::url( array( 'action' => 'edit', 'id' => $course->id ) ) . '#intakes',
				'back_label' => $course->name,
				'subtitle'   => $intake->id ? esc_html( $course->name . ' · ' . OSB_Courses::range_label( $intake ) ) : esc_html( $course->name ),
				'actions'    => $intake->id ? array( array( __( 'Class list & register', 'osam-bookings' ), self::url( array( 'action' => 'register', 'id' => $intake->id ) ), 'secondary', 'dashicons-groups' ) ) : array(),
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form osb-intake-form">
			<?php wp_nonce_field( 'osb_save_intake' ); ?>
			<input type="hidden" name="action" value="osb_save_intake">
			<input type="hidden" name="id" value="<?php echo (int) $intake->id; ?>">
			<input type="hidden" name="course_id" value="<?php echo (int) $course->id; ?>">

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Intake', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Give each run of the course its own name. Leave places and price blank to use the course’s.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-field"><label for="osb_iname"><?php esc_html_e( 'Intake name', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="text" id="osb_iname" name="name" value="<?php echo esc_attr( $intake->name ); ?>" required maxlength="191" placeholder="<?php esc_attr_e( 'e.g. January evenings, Spring 2027, Saturday mornings', 'osam-bookings' ); ?>" <?php echo $intake->id ? '' : 'autofocus'; ?>><p class="osb-help"><?php esc_html_e( 'Customers see this next to the course name when choosing dates, and in their emails.', 'osam-bookings' ); ?></p></div>
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_icap"><?php esc_html_e( 'Places', 'osam-bookings' ); ?></label><input type="number" id="osb_icap" name="capacity" min="1" value="<?php echo $intake->capacity > 0 ? (int) $intake->capacity : ''; ?>" placeholder="<?php echo (int) $course->capacity; ?>"></div>
						<div class="osb-field osb-field--half"><label for="osb_iprice"><?php esc_html_e( 'Course fee per place', 'osam-bookings' ); ?></label><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" id="osb_iprice" name="price" min="0" step="0.01" value="<?php echo null === $intake->price ? '' : esc_attr( $intake->price ); ?>" placeholder="<?php echo esc_attr( $course->price ); ?>"></div></div>
					</div>
					<label class="osb-switch"><input type="hidden" name="status" value="hidden"><input type="checkbox" name="status" value="active" <?php checked( 'active', $intake->status ); ?>><span></span><?php esc_html_e( 'Open for enrolment', 'osam-bookings' ); ?></label>
					<?php if ( 'active' !== $course->status ) : ?><p class="osb-help"><?php esc_html_e( 'The course itself is hidden, so customers can’t enrol until you put it on sale.', 'osam-bookings' ); ?></p><?php endif; ?>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Schedule', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Every session customers are booked onto. Use “Repeat” to fill in a weekly class, then adjust any single date.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<?php if ( $enrolled ) : ?>
						<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div>
							<?php /* translators: %d people */ echo esc_html( sprintf( _n( '%d booking is on this intake. Adding, moving or removing sessions changes their schedule.', '%d bookings are on this intake. Adding, moving or removing sessions changes their schedule.', $enrolled, 'osam-bookings' ), $enrolled ) ); ?>
							<label class="osb-switch"><input type="checkbox" name="notify" value="1" checked><span></span><?php esc_html_e( 'Email them the new dates if anything changes', 'osam-bookings' ); ?></label>
						</div></div>
					<?php endif; ?>

					<details class="osb-repeat" <?php echo $sessions ? '' : 'open'; ?>>
						<summary><span class="dashicons dashicons-update"></span><?php esc_html_e( 'Repeat — generate sessions', 'osam-bookings' ); ?></summary>
						<div class="osb-repeat__grid">
							<div class="osb-field"><label for="osb_r_date"><?php esc_html_e( 'First session', 'osam-bookings' ); ?></label><input type="date" id="osb_r_date" value="<?php echo esc_attr( $sessions ? gmdate( 'Y-m-d', strtotime( end( $sessions )->start_at . ' +7 days' ) ) : $suggest ); ?>"></div>
							<div class="osb-field"><label for="osb_r_start"><?php esc_html_e( 'From', 'osam-bookings' ); ?></label><input type="time" id="osb_r_start" step="300" value="<?php echo esc_attr( $sessions ? substr( $sessions[0]->start_at, 11, 5 ) : '18:00' ); ?>"></div>
							<div class="osb-field"><label for="osb_r_end"><?php esc_html_e( 'To', 'osam-bookings' ); ?></label><input type="time" id="osb_r_end" step="300" value="<?php echo esc_attr( $sessions ? substr( $sessions[0]->end_at, 11, 5 ) : '20:00' ); ?>"></div>
							<div class="osb-field"><label for="osb_r_every"><?php esc_html_e( 'Repeat', 'osam-bookings' ); ?></label>
								<select id="osb_r_every">
									<option value="7"><?php esc_html_e( 'Every week', 'osam-bookings' ); ?></option>
									<option value="14"><?php esc_html_e( 'Every 2 weeks', 'osam-bookings' ); ?></option>
									<option value="1"><?php esc_html_e( 'Every day', 'osam-bookings' ); ?></option>
									<option value="wd"><?php esc_html_e( 'Weekdays (Mon–Fri)', 'osam-bookings' ); ?></option>
									<option value="m"><?php esc_html_e( 'Every month (same date)', 'osam-bookings' ); ?></option>
								</select>
							</div>
							<div class="osb-field"><label for="osb_r_count"><?php esc_html_e( 'Sessions', 'osam-bookings' ); ?></label><input type="number" id="osb_r_count" min="1" max="200" value="6"></div>
							<div class="osb-field osb-repeat__go">
								<button type="button" class="osb-btn osb-btn--primary osb-btn--sm" data-osb-repeat="add"><?php esc_html_e( 'Add sessions', 'osam-bookings' ); ?></button>
								<?php if ( $sessions ) : ?><button type="button" class="osb-btn osb-btn--ghost osb-btn--sm" data-osb-repeat="replace"><?php esc_html_e( 'Replace all', 'osam-bookings' ); ?></button><?php endif; ?>
							</div>
						</div>
					</details>

					<div class="osb-sessions-wrap">
						<table class="osb-table osb-sessions">
							<thead><tr>
								<th>#</th>
								<th><?php esc_html_e( 'Date', 'osam-bookings' ); ?></th>
								<th><?php esc_html_e( 'From', 'osam-bookings' ); ?></th>
								<th><?php esc_html_e( 'To', 'osam-bookings' ); ?></th>
								<th><?php esc_html_e( 'Location', 'osam-bookings' ); ?></th>
								<?php if ( $staff ) : ?><th><?php esc_html_e( 'Tutor', 'osam-bookings' ); ?></th><?php endif; ?>
								<th><?php esc_html_e( 'Note', 'osam-bookings' ); ?></th>
								<th class="osb-col-actions"></th>
							</tr></thead>
							<tbody>
							<?php
							foreach ( $sessions as $i => $s ) {
								self::session_row( $i, $s, $staff, $course );
							}
							?>
							</tbody>
						</table>
						<p class="osb-sessions__empty osb-muted" <?php echo $sessions ? 'hidden' : ''; ?>><?php esc_html_e( 'No sessions yet — use Repeat above or add them one at a time.', 'osam-bookings' ); ?></p>
					</div>
					<template id="osb-session-template"><?php self::session_row( '__i__', $blank, $staff, $course ); ?></template>
					<div class="osb-inline osb-sessions__tools">
						<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm" data-osb-session-add><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add a session', 'osam-bookings' ); ?></button>
						<span class="osb-sessions__shift"><?php esc_html_e( 'Move every date by', 'osam-bookings' ); ?> <input type="number" id="osb_shift_days" value="7" step="1" aria-label="<?php esc_attr_e( 'Days', 'osam-bookings' ); ?>"> <?php esc_html_e( 'days', 'osam-bookings' ); ?> <button type="button" class="osb-btn osb-btn--ghost osb-btn--sm" data-osb-session-shift><?php esc_html_e( 'Move', 'osam-bookings' ); ?></button></span>
					</div>
				</div>
			</div>

			<div class="osb-savebar">
				<button class="osb-btn osb-btn--primary"><?php echo $intake->id ? esc_html__( 'Save intake', 'osam-bookings' ) : esc_html__( 'Add intake', 'osam-bookings' ); ?></button>
			</div>
		</form>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Save an intake and its schedule.
	 */
	public static function save_intake() {
		OSB_Admin::verify( 'osb_save_intake' );
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id     = absint( isset( $post['id'] ) ? $post['id'] : 0 );
		$course = OSB_DB::get( 'courses', absint( isset( $post['course_id'] ) ? $post['course_id'] : 0 ) );
		if ( ! $course ) {
			OSB_Admin::redirect( self::url(), 'error' );
		}
		$price = isset( $post['price'] ) && '' !== trim( (string) $post['price'] ) ? max( 0, (float) $post['price'] ) : null;
		$data  = array(
			'course_id' => (int) $course->id,
			'name'      => sanitize_text_field( isset( $post['name'] ) ? $post['name'] : '' ),
			'capacity'  => absint( isset( $post['capacity'] ) ? $post['capacity'] : 0 ),
			'price'     => $price,
			'status'    => isset( $post['status'] ) && 'active' === $post['status'] ? 'active' : 'hidden',
		);
		$unnamed = '' === $data['name'];
		if ( $unnamed ) {
			// Name is required, but never throw away a schedule that's just been typed in: save it under a stand-in name and ask for a real one.
			$existing     = $id ? OSB_DB::get( 'course_intakes', $id ) : null;
			$data['name'] = $existing && '' !== $existing->name ? $existing->name : __( 'Unnamed intake', 'osam-bookings' );
		}
		if ( $id && OSB_DB::get( 'course_intakes', $id ) ) {
			OSB_DB::update( 'course_intakes', $id, $data );
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$id                 = OSB_DB::insert( 'course_intakes', $data );
		}
		if ( ! $id ) {
			OSB_Admin::redirect( self::url( array( 'action' => 'edit', 'id' => $course->id ) ), 'error' );
		}

		$rows   = isset( $post['sessions'] ) && is_array( $post['sessions'] ) ? array_values( $post['sessions'] ) : array();
		$result = OSB_Courses::save_sessions( $id, $rows );

		$warnings = array();
		if ( ! $result['count'] ) {
			$warnings[] = __( 'This intake has no sessions, so customers can’t enrol on it yet.', 'osam-bookings' );
		}
		// Clashes in tutors' diaries are allowed, but worth knowing about.
		foreach ( OSB_Courses::sessions( $id ) as $s ) {
			if ( ! $s->staff_id ) {
				continue;
			}
			$clash = OSB_DB::staff_bookings_between( (int) $s->staff_id, $s->start_at, $s->end_at );
			if ( $clash ) {
				/* translators: 1: date 2: count */
				$warnings[] = sprintf( _n( '%1$s: the tutor already has %2$d appointment booked at this time.', '%1$s: the tutor already has %2$d appointments booked at this time.', count( $clash ), 'osam-bookings' ), mysql2date( 'D j M ' . get_option( 'time_format' ), $s->start_at ), count( $clash ) );
			}
		}
		$sent = 0;
		if ( $result['changed'] && ! empty( $post['notify'] ) ) {
			$sent = OSB_Courses::notify_schedule_change( $id );
		}
		if ( $unnamed ) {
			OSB_Admin::flash_error( __( 'Please give this intake a name — customers see it when choosing dates.', 'osam-bookings' ) );
		}
		OSB_Admin::flash_warnings( $warnings );
		do_action( 'osb_course_intake_saved', $id, $data );
		OSB_Admin::redirect( self::url( array( 'action' => 'intake', 'id' => $id ) ), $sent ? 'dates_emailed' : 'saved' );
	}

	/**
	 * Duplicate an intake as the next run.
	 */
	public static function duplicate_intake() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_duplicate_intake_' . $id );
		$new = OSB_Courses::duplicate_intake( $id );
		if ( ! $new ) {
			OSB_Admin::redirect( self::url(), 'error' );
		}
		OSB_Admin::flash_warnings( array( __( 'This copy is not open for enrolment yet — check the dates, then switch it on.', 'osam-bookings' ) ) );
		OSB_Admin::redirect( self::url( array( 'action' => 'intake', 'id' => $new ) ) );
	}

	/**
	 * Delete an intake (only when nobody has ever booked it).
	 */
	public static function delete_intake() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_delete_intake_' . $id );
		$intake = OSB_DB::get( 'course_intakes', $id );
		$back   = $intake ? self::url( array( 'action' => 'edit', 'id' => $intake->course_id ) ) : self::url();
		if ( OSB_Courses::booking_count( 'intake_id', $id ) ) {
			OSB_Admin::redirect( $back, 'has_items' );
		}
		OSB_Courses::delete_intake( $id );
		OSB_Admin::redirect( $back, 'deleted' );
	}

	/* ---------------------------------------------------------------------
	 * Class list & register
	 * ------------------------------------------------------------------ */

	/**
	 * Class list with an attendance register.
	 *
	 * @param int $id Intake.
	 */
	private static function register( $id ) {
		$intake = OSB_DB::get( 'course_intakes', $id );
		$course = $intake ? OSB_DB::get( 'courses', $intake->course_id ) : null;
		if ( ! $course ) {
			OSB_Admin::open( __( 'Intake not found', 'osam-bookings' ), array( 'back' => self::url(), 'back_label' => __( 'Courses', 'osam-bookings' ) ) );
			OSB_Admin::close();
			return;
		}
		$sessions = OSB_Courses::sessions( $id );
		$people   = OSB_Courses::enrolments( $id );
		$marks    = OSB_Courses::register( $id );
		$labels   = OSB_Courses::attendance_statuses();
		$now      = current_time( 'mysql' );
		$taken    = OSB_Courses::seats_taken( $id );
		$cap      = OSB_Courses::capacity( $intake, $course );
		$export   = wp_nonce_url( admin_url( 'admin-post.php?action=osb_export_register&id=' . $id ), 'osb_export_register_' . $id );
		$add      = admin_url( 'admin.php?page=osb-bookings&action=new&type=course&intake_id=' . $id );
		$others   = admin_url( 'admin.php?page=osb-bookings&intake_id=' . $id );

		OSB_Admin::open(
			/* translators: %s intake */
			sprintf( __( 'Class list — %s', 'osam-bookings' ), OSB_Courses::intake_label( $intake ) ),
			array(
				'back'       => self::url( array( 'action' => 'edit', 'id' => $course->id ) ) . '#intakes',
				'back_label' => $course->name,
				'subtitle'   => esc_html( $course->name . ' · ' . OSB_Courses::range_label( $intake ) ),
				'actions'    => array(
					array( __( 'Edit dates', 'osam-bookings' ), self::url( array( 'action' => 'intake', 'id' => $id ) ), 'secondary', 'dashicons-calendar-alt' ),
					array( __( 'Export CSV', 'osam-bookings' ), $export, 'secondary', 'dashicons-download' ),
					array( __( 'Add enrolment', 'osam-bookings' ), $add, 'primary', 'dashicons-plus-alt2' ),
				),
			)
		);
		?>
		<div class="osb-stats">
			<div class="osb-stat"><span class="osb-stat__icon dashicons dashicons-groups" aria-hidden="true"></span><span class="osb-stat__label"><?php esc_html_e( 'Places booked', 'osam-bookings' ); ?></span><span class="osb-stat__value"><?php echo (int) $taken; ?> / <?php echo (int) $cap; ?></span><span class="osb-stat__sub"><?php /* translators: %d bookings */ echo esc_html( sprintf( _n( '%d booking', '%d bookings', count( $people ), 'osam-bookings' ), count( $people ) ) ); ?></span></div>
			<div class="osb-stat"><span class="osb-stat__icon dashicons dashicons-calendar-alt" aria-hidden="true"></span><span class="osb-stat__label"><?php esc_html_e( 'Sessions', 'osam-bookings' ); ?></span><span class="osb-stat__value"><?php echo (int) count( $sessions ); ?></span><span class="osb-stat__sub"><?php /* translators: %d done */ echo esc_html( sprintf( __( '%d done', 'osam-bookings' ), count( array_filter( $sessions, static function ( $s ) use ( $now ) { return $s->start_at <= $now; } ) ) ) ); ?></span></div>
			<div class="osb-stat"><span class="osb-stat__icon dashicons dashicons-yes-alt" aria-hidden="true"></span><span class="osb-stat__label"><?php esc_html_e( 'Status', 'osam-bookings' ); ?></span><span class="osb-stat__value osb-stat__value--pill"><?php echo self::intake_pill( $intake, $course ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span class="osb-stat__sub"><a href="<?php echo esc_url( $others ); ?>"><?php esc_html_e( 'All bookings incl. cancelled', 'osam-bookings' ); ?></a></span></div>
		</div>

		<?php if ( ! $people ) : ?>
			<?php OSB_Admin::empty_state( 'dashicons-groups', __( 'Nobody enrolled yet', 'osam-bookings' ), __( 'Customers who book this intake online, or who you add here, will appear in the register.', 'osam-bookings' ), array( __( 'Add enrolment', 'osam-bookings' ), $add ) ); ?>
		<?php elseif ( ! $sessions ) : ?>
			<?php OSB_Admin::empty_state( 'dashicons-calendar-alt', __( 'No sessions', 'osam-bookings' ), __( 'Add session dates to take a register.', 'osam-bookings' ), array( __( 'Edit dates', 'osam-bookings' ), self::url( array( 'action' => 'intake', 'id' => $id ) ) ) ); ?>
		<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-card--table osb-form osb-register-form">
			<?php wp_nonce_field( 'osb_save_register_' . $id ); ?>
			<input type="hidden" name="action" value="osb_save_register">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<?php OSB_Admin::card_head( __( 'Register', 'osam-bookings' ), __( 'Click a cell to cycle Booked → Attended → Absent. Use a date heading to mark the whole class present.', 'osam-bookings' ) ); ?>
			<div class="osb-register-wrap">
				<table class="osb-table osb-register">
					<thead><tr>
						<th class="osb-register__who"><?php esc_html_e( 'Customer', 'osam-bookings' ); ?></th>
						<?php
						foreach ( $sessions as $i => $s ) :
							$state = substr( $s->start_at, 0, 10 ) === substr( $now, 0, 10 ) ? 'is-today' : ( $s->start_at < $now ? 'is-past' : '' );
							?>
							<th class="osb-register__session <?php echo esc_attr( $state ); ?>"><button type="button" class="osb-register__col" data-col="<?php echo (int) $s->id; ?>" title="<?php esc_attr_e( 'Mark everyone attended', 'osam-bookings' ); ?>"><b><?php echo (int) ( $i + 1 ); ?></b><span><?php echo esc_html( mysql2date( 'D j M', $s->start_at ) ); ?></span><small><?php echo esc_html( mysql2date( get_option( 'time_format' ), $s->start_at ) ); ?></small></button></th>
						<?php endforeach; ?>
						<th><?php esc_html_e( 'Attended', 'osam-bookings' ); ?></th>
					</tr></thead>
					<tbody>
					<?php
					foreach ( $people as $b ) :
						$row  = isset( $marks[ (int) $b->id ] ) ? $marks[ (int) $b->id ] : array();
						$done = count( array_keys( $row, 'attended', true ) );
						?>
						<tr>
							<td class="osb-register__who">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $b->id ) ); ?>"><strong><?php echo esc_html( $b->customer_name ); ?></strong></a><?php echo $b->seats > 1 ? ' <span class="osb-muted">×' . (int) $b->seats . '</span>' : ''; ?>
								<small><?php echo esc_html( $b->customer_email ); ?></small>
								<?php
								$osb_others = array_filter(
									array_map(
										static function ( $p ) {
											return $p['same'] ? '' : $p['name'];
										},
										array_slice( OSB_Bookings::attendees( $b ), 1 )
									)
								);
								if ( $osb_others ) :
									?>
									<small class="osb-register__guests"><?php /* translators: %s names */ echo esc_html( sprintf( __( 'With %s', 'osam-bookings' ), implode( ', ', $osb_others ) ) ); ?></small>
								<?php endif; ?>
								<?php echo 'confirmed' !== $b->status && 'completed' !== $b->status ? OSB_Admin::status_pill( $b->status ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php if ( 'paid' !== $b->payment_status && 'free' !== $b->payment_status ) : ?><span class="osb-pill osb-pill--warn"><?php esc_html_e( 'Unpaid', 'osam-bookings' ); ?></span><?php endif; ?>
							</td>
							<?php
							foreach ( $sessions as $s ) :
								$v = isset( $row[ (int) $s->id ] ) ? $row[ (int) $s->id ] : 'booked';
								?>
								<td class="osb-register__cell" data-col="<?php echo (int) $s->id; ?>">
									<input type="hidden" name="att[<?php echo (int) $b->id; ?>][<?php echo (int) $s->id; ?>]" value="<?php echo esc_attr( $v ); ?>">
									<button type="button" class="osb-mark osb-mark--<?php echo esc_attr( $v ); ?>" data-value="<?php echo esc_attr( $v ); ?>" aria-label="<?php echo esc_attr( $b->customer_name . ', ' . mysql2date( 'j M', $s->start_at ) . ': ' . $labels[ $v ] ); ?>"></button>
								</td>
							<?php endforeach; ?>
							<td><strong><?php echo (int) $done; ?></strong><small>/ <?php echo (int) count( $sessions ); ?></small></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="osb-tablefoot">
				<span class="osb-legend"><i class="osb-mark osb-mark--booked"></i><?php echo esc_html( $labels['booked'] ); ?> <i class="osb-mark osb-mark--attended"></i><?php echo esc_html( $labels['attended'] ); ?> <i class="osb-mark osb-mark--absent"></i><?php echo esc_html( $labels['absent'] ); ?></span>
				<button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Save register', 'osam-bookings' ); ?></button>
			</div>
		</form>
		<?php endif; ?>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Save the register.
	 */
	public static function save_register() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		OSB_Admin::verify( 'osb_save_register_' . $id );
		$att = isset( $_POST['att'] ) && is_array( $_POST['att'] ) ? wp_unslash( $_POST['att'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ok  = array_map( 'intval', wp_list_pluck( OSB_Courses::sessions( $id ), 'id' ) );
		foreach ( $att as $booking_id => $cells ) {
			$booking = OSB_DB::get( 'bookings', absint( $booking_id ) );
			if ( ! $booking || (int) $booking->intake_id !== $id || ! is_array( $cells ) ) {
				continue;
			}
			foreach ( $cells as $session_id => $status ) {
				if ( in_array( absint( $session_id ), $ok, true ) ) {
					OSB_Courses::mark( $booking->id, absint( $session_id ), sanitize_key( $status ) );
				}
			}
		}
		do_action( 'osb_course_register_saved', $id );
		OSB_Admin::redirect( self::url( array( 'action' => 'register', 'id' => $id ) ) );
	}

	/**
	 * CSV of the class list with attendance.
	 */
	public static function export_register() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_export_register_' . $id );
		$intake = OSB_DB::get( 'course_intakes', $id );
		$course = $intake ? OSB_DB::get( 'courses', $intake->course_id ) : null;
		if ( ! $course ) {
			OSB_Admin::redirect( self::url(), 'error' );
		}
		$sessions = OSB_Courses::sessions( $id );
		$marks    = OSB_Courses::register( $id );
		$labels   = OSB_Courses::attendance_statuses();
		$fields   = OSB_Settings::form_fields();
		$clean    = static function ( $v ) {
			$v = (string) $v;
			return preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v; // Neutralise spreadsheet formula injection.
		};

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $course->name . '-' . OSB_Courses::intake_label( $intake ) ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$head = array( 'Booking', 'Status', 'Places', 'Payment status', 'Amount' );
		foreach ( $fields as $field ) {
			$head[] = $field['label'];
		}
		foreach ( $sessions as $i => $s ) {
			$head[] = sprintf( '%d: %s', $i + 1, mysql2date( 'D j M Y H:i', $s->start_at ) );
		}
		$head[] = 'Attended';
		$head[] = 'Other attendees';
		fputcsv( $out, $head );

		foreach ( OSB_Courses::enrolments( $id ) as $b ) {
			$vals = OSB_Bookings::fields( $b );
			$line = array( $b->id, $b->status, $b->seats, $b->payment_status, $b->amount );
			foreach ( $fields as $field ) {
				$line[] = $clean( isset( $vals[ $field['key'] ] ) ? $vals[ $field['key'] ] : '' );
			}
			$done = 0;
			foreach ( $sessions as $s ) {
				$v      = isset( $marks[ (int) $b->id ][ (int) $s->id ] ) ? $marks[ (int) $b->id ][ (int) $s->id ] : 'booked';
				$done  += 'attended' === $v ? 1 : 0;
				$line[] = $labels[ $v ];
			}
			$line[] = $done;
			$others = array();
			foreach ( array_slice( OSB_Bookings::attendees( $b ), 1 ) as $p ) {
				$others[] = $p['same'] ? sprintf( 'Place %d: same as booker', $p['place'] ) : $clean( trim( $p['name'] . ( $p['email'] ? ' <' . $p['email'] . '>' : '' ) ) );
			}
			$line[] = implode( '; ', $others );
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
