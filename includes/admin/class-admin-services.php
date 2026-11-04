<?php
/**
 * Services admin.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * List / edit services.
 */
class OSB_Admin_Services {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_save_service', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_osb_delete_service', array( __CLASS__, 'delete' ) );
		add_action( 'wp_ajax_osb_sort', array( __CLASS__, 'sort' ) );
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
			self::edit( $id );
		} else {
			self::listing();
		}
	}

	/**
	 * List.
	 */
	private static function listing() {
		$services = OSB_DB::services();
		$solo     = OSB_DB::is_solo();
		OSB_Admin::open(
			__( 'Services', 'osam-bookings' ),
			array(
				'subtitle' => __( 'Appointments and classes customers can book. Drag to change the order they appear in.', 'osam-bookings' ),
				'actions'  => array_merge( OSB_Admin_Import::actions( 'services' ), array( array( __( 'Add service', 'osam-bookings' ), admin_url( 'admin.php?page=osb-services&action=new' ), 'primary', 'dashicons-plus-alt2' ) ) ),
			)
		);
		if ( ! $services ) {
			OSB_Admin::empty_state( 'dashicons-clipboard', __( 'No services yet', 'osam-bookings' ), __( 'Add what you offer — e.g. “60 minute massage” or “Group pilates class”.', 'osam-bookings' ), array( __( 'Add your first service', 'osam-bookings' ), admin_url( 'admin.php?page=osb-services&action=new' ) ) );
			OSB_Admin::close();
			return;
		}
		?>
		<div class="osb-card osb-card--table">
			<table class="osb-table osb-table--click osb-sortable" data-table="services">
				<thead><tr>
					<th class="osb-col-handle"></th>
					<th><?php esc_html_e( 'Service', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Price', 'osam-bookings' ); ?></th>
					<?php if ( ! $solo ) : ?><th><?php esc_html_e( 'Provided by', 'osam-bookings' ); ?></th><?php endif; ?>
					<th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th>
					<th class="osb-col-actions"></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $services as $s ) :
					$edit  = admin_url( 'admin.php?page=osb-services&action=edit&id=' . $s->id );
					$del   = wp_nonce_url( admin_url( 'admin-post.php?action=osb_delete_service&id=' . $s->id ), 'osb_delete_service_' . $s->id );
					$staff = OSB_DB::raw_service_staff_ids( $s->id );
					$img   = $s->image_id ? wp_get_attachment_image_url( $s->image_id, 'thumbnail' ) : '';
					?>
					<tr data-id="<?php echo (int) $s->id; ?>" data-href="<?php echo esc_url( $edit ); ?>">
						<td class="osb-col-handle osb-handle" title="<?php esc_attr_e( 'Drag to reorder', 'osam-bookings' ); ?>"><span class="dashicons dashicons-menu"></span></td>
						<td>
							<div class="osb-item">
								<?php if ( $img ) : ?><img class="osb-thumb" src="<?php echo esc_url( $img ); ?>" alt=""><?php else : ?><span class="osb-thumb" style="background:<?php echo esc_attr( $s->color ); ?>"></span><?php endif; ?>
								<span><a class="osb-rowlink" href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( $s->name ); ?></strong></a>
								<small><?php echo esc_html( $s->category ? $s->category : '' ); ?><?php echo $s->capacity > 1 ? esc_html( ( $s->category ? ' · ' : '' ) . sprintf( /* translators: %d places */ __( 'Group · %d places', 'osam-bookings' ), $s->capacity ) ) : ''; ?></small></span>
							</div>
						</td>
						<td><?php echo esc_html( self::duration_label( (int) $s->duration ) ); ?></td>
						<td><?php echo $s->price > 0 ? esc_html( OSB_Settings::money( $s->price ) ) : '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Free', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<?php if ( ! $solo ) : ?>
						<td>
							<?php if ( ! $staff ) : ?>
								<span class="osb-pill osb-pill--warn"><?php esc_html_e( 'Nobody — not bookable', 'osam-bookings' ); ?></span>
							<?php else : ?>
								<span class="osb-avatars">
								<?php
								foreach ( array_slice( $staff, 0, 4 ) as $sid ) {
									$st = OSB_DB::get( 'staff', $sid );
									if ( $st ) {
										echo OSB_Admin::avatar( $st->name, $st->image_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									}
								}
								echo count( $staff ) > 4 ? '<span class="osb-avatar osb-avatar--more">+' . ( count( $staff ) - 4 ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
								</span>
							<?php endif; ?>
						</td>
						<?php endif; ?>
						<td><?php echo 'active' === $s->status ? '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'Bookable', 'osam-bookings' ) . '</span>' : '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Hidden', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="osb-col-actions">
							<button type="button" class="osb-iconbtn osb-copy" data-copy='[osam_booking service="<?php echo (int) $s->id; ?>"]' title="<?php esc_attr_e( 'Copy shortcode', 'osam-bookings' ); ?>"><span class="dashicons dashicons-shortcode"></span></button>
							<a class="osb-iconbtn" href="<?php echo esc_url( $edit ); ?>" title="<?php esc_attr_e( 'Edit', 'osam-bookings' ); ?>"><span class="dashicons dashicons-edit"></span></a>
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

	/**
	 * "1 hr 30 min".
	 *
	 * @param int $min Minutes.
	 * @return string
	 */
	public static function duration_label( $min ) {
		$h = intdiv( $min, 60 );
		$m = $min % 60;
		/* translators: 1: hours 2: minutes */
		return $h ? ( $m ? sprintf( __( '%1$d hr %2$d min', 'osam-bookings' ), $h, $m ) : sprintf( _n( '%d hr', '%d hrs', $h, 'osam-bookings' ), $h ) ) : sprintf( __( '%d min', 'osam-bookings' ), $m );
	}

	/**
	 * Edit form.
	 *
	 * @param int $id Service id.
	 */
	private static function edit( $id ) {
		$s = $id ? OSB_DB::get( 'services', $id ) : null;
		if ( ! $s ) {
			$s = (object) array(
				'id'              => 0,
				'name'            => '',
				'description'     => '',
				'category'        => '',
				'duration'        => 60,
				'price'           => '0.00',
				'capacity'        => 1,
				'buffer_before'   => 0,
				'buffer_after'    => 0,
				'color'           => '#0273b0',
				'image_id'        => 0,
				'payment_methods' => '',
				'online_mode'     => '',
				'online_url'      => '',
				'status'          => 'active',
			);
		}
		$solo     = OSB_DB::is_solo();
		$assigned = $s->id ? OSB_DB::service_staff_prices( $s->id ) : array();
		$staff    = OSB_DB::staff();
		$cats     = array_unique( array_filter( wp_list_pluck( OSB_DB::services(), 'category' ) ) );

		OSB_Admin::open(
			$s->id ? $s->name : __( 'New service', 'osam-bookings' ),
			array(
				'back'       => admin_url( 'admin.php?page=osb-services' ),
				'back_label' => __( 'Services', 'osam-bookings' ),
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form">
			<?php wp_nonce_field( 'osb_save_service' ); ?>
			<input type="hidden" name="action" value="osb_save_service">
			<input type="hidden" name="id" value="<?php echo (int) $s->id; ?>">

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Basics', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'What customers see when they choose a service.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-field"><label for="osb_name"><?php esc_html_e( 'Name', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="text" id="osb_name" name="name" value="<?php echo esc_attr( $s->name ); ?>" required placeholder="<?php esc_attr_e( 'e.g. Deep tissue massage', 'osam-bookings' ); ?>"></div>
					<div class="osb-field"><label><?php esc_html_e( 'Description', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'description', $s->description, 'editor' ); ?></div>
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_category"><?php esc_html_e( 'Category', 'osam-bookings' ); ?></label><input type="text" id="osb_category" name="category" value="<?php echo esc_attr( $s->category ); ?>" list="osb-cats" placeholder="<?php esc_attr_e( 'Optional', 'osam-bookings' ); ?>"><datalist id="osb-cats"><?php foreach ( $cats as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>"><?php endforeach; ?></datalist></div>
						<div class="osb-field osb-field--half"><label><?php esc_html_e( 'Colour', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'color', $s->color, 'color' ); ?></div>
					</div>
					<div class="osb-field"><label><?php esc_html_e( 'Image', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'image_id', $s->image_id, 'image' ); ?></div>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Time & price', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Buffers block time either side for setup, cleaning or travel — customers don’t see them.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--third"><label for="osb_duration"><?php esc_html_e( 'Duration', 'osam-bookings' ); ?></label><div class="osb-affix"><input type="number" id="osb_duration" name="duration" min="5" step="5" value="<?php echo (int) $s->duration; ?>"><span><?php esc_html_e( 'min', 'osam-bookings' ); ?></span></div>
							<div class="osb-quickpick" data-target="osb_duration"><button type="button" data-v="30">30m</button><button type="button" data-v="45">45m</button><button type="button" data-v="60">1h</button><button type="button" data-v="90">1½h</button><button type="button" data-v="120">2h</button></div></div>
						<div class="osb-field osb-field--third"><label for="osb_price"><?php esc_html_e( 'Price', 'osam-bookings' ); ?></label><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( trim( OSB_Settings::currencies()[ OSB_Settings::get( 'currency' ) ] ?? OSB_Settings::get( 'currency' ) ) ); ?></span><input type="number" id="osb_price" name="price" min="0" step="0.01" value="<?php echo esc_attr( $s->price ); ?>"></div><p class="osb-help"><?php esc_html_e( '0 = free, no payment step.', 'osam-bookings' ); ?></p></div>
						<div class="osb-field osb-field--third"><label for="osb_capacity"><?php esc_html_e( 'Places per slot', 'osam-bookings' ); ?></label><input type="number" id="osb_capacity" name="capacity" min="1" value="<?php echo (int) $s->capacity; ?>"><p class="osb-help"><?php esc_html_e( '1 = one-to-one. More = group class.', 'osam-bookings' ); ?></p></div>
						<div class="osb-field osb-field--third"><label for="osb_bb"><?php esc_html_e( 'Buffer before', 'osam-bookings' ); ?></label><div class="osb-affix"><input type="number" id="osb_bb" name="buffer_before" min="0" step="5" value="<?php echo (int) $s->buffer_before; ?>"><span><?php esc_html_e( 'min', 'osam-bookings' ); ?></span></div></div>
						<div class="osb-field osb-field--third"><label for="osb_ba"><?php esc_html_e( 'Buffer after', 'osam-bookings' ); ?></label><div class="osb-affix"><input type="number" id="osb_ba" name="buffer_after" min="0" step="5" value="<?php echo (int) $s->buffer_after; ?>"><span><?php esc_html_e( 'min', 'osam-bookings' ); ?></span></div></div>
					</div>
				</div>
			</div>

			<?php if ( ! $solo ) : ?>
			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Who provides it', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Tick everyone who can deliver this service. Set a different price per person if needed (e.g. senior stylist).', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<?php if ( ! $staff ) : ?>
						<p><?php printf( wp_kses_post( __( 'Add <a href="%s">staff members</a> first.', 'osam-bookings' ) ), esc_url( admin_url( 'admin.php?page=osb-staff&action=new' ) ) ); ?></p>
					<?php else : ?>
						<div class="osb-assign">
						<?php foreach ( $staff as $st ) : ?>
							<label class="osb-assign__row">
								<input type="checkbox" name="staff[<?php echo (int) $st->id; ?>][on]" value="1" <?php checked( array_key_exists( (int) $st->id, $assigned ) ); ?>>
								<?php echo OSB_Admin::avatar( $st->name, $st->image_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span class="osb-assign__name"><?php echo esc_html( $st->name ); ?><?php echo 'active' !== $st->status ? ' <em>(' . esc_html__( 'inactive', 'osam-bookings' ) . ')</em>' : ''; ?></span>
								<span class="osb-affix osb-affix--pre osb-affix--sm"><span><?php echo esc_html( trim( OSB_Settings::currencies()[ OSB_Settings::get( 'currency' ) ] ?? '' ) ); ?></span><input type="number" step="0.01" min="0" name="staff[<?php echo (int) $st->id; ?>][price]" value="<?php echo isset( $assigned[ (int) $st->id ] ) && null !== $assigned[ (int) $st->id ] ? esc_attr( $assigned[ (int) $st->id ] ) : ''; ?>" placeholder="<?php echo esc_attr( $s->price ); ?>" aria-label="<?php esc_attr_e( 'Price override', 'osam-bookings' ); ?>"></span>
							</label>
						<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Availability & payment', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Hidden services can still be booked by you from the admin.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<label class="osb-switch"><input type="hidden" name="status" value="hidden"><input type="checkbox" name="status" value="active" <?php checked( 'active', $s->status ); ?>><span></span><?php esc_html_e( 'Customers can book this online', 'osam-bookings' ); ?></label>
					<div class="osb-field"><label><?php esc_html_e( 'Payment methods offered', 'osam-bookings' ); ?></label><?php OSB_Admin::methods_checkboxes( $s->payment_methods ); ?></div>
				</div>
			</div>

			<?php OSB_Meetings::editor_section( 'service', $s ); ?>

			<div class="osb-savebar">
				<?php if ( $s->id ) : ?><button type="button" class="osb-btn osb-btn--ghost osb-copy" data-copy='[osam_booking service="<?php echo (int) $s->id; ?>"]'><span class="dashicons dashicons-shortcode"></span><?php esc_html_e( 'Copy shortcode', 'osam-bookings' ); ?></button><?php endif; ?>
				<button class="osb-btn osb-btn--primary"><?php echo $s->id ? esc_html__( 'Save service', 'osam-bookings' ) : esc_html__( 'Add service', 'osam-bookings' ); ?></button>
			</div>
		</form>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Save handler.
	 */
	public static function save() {
		OSB_Admin::verify( 'osb_save_service' );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id   = isset( $post['id'] ) ? absint( $post['id'] ) : 0;
		$col  = sanitize_hex_color( isset( $post['color'] ) ? $post['color'] : '' );
		$data = array(
			'name'            => sanitize_text_field( isset( $post['name'] ) ? $post['name'] : '' ),
			'description'     => wp_kses_post( isset( $post['description'] ) ? $post['description'] : '' ),
			'category'        => sanitize_text_field( isset( $post['category'] ) ? $post['category'] : '' ),
			'duration'        => max( 5, absint( isset( $post['duration'] ) ? $post['duration'] : 60 ) ),
			'price'           => max( 0, (float) ( isset( $post['price'] ) ? $post['price'] : 0 ) ),
			'capacity'        => max( 1, absint( isset( $post['capacity'] ) ? $post['capacity'] : 1 ) ),
			'buffer_before'   => absint( isset( $post['buffer_before'] ) ? $post['buffer_before'] : 0 ),
			'buffer_after'    => absint( isset( $post['buffer_after'] ) ? $post['buffer_after'] : 0 ),
			'color'           => $col ? $col : '#0273b0',
			'image_id'        => absint( isset( $post['image_id'] ) ? $post['image_id'] : 0 ),
			'payment_methods' => implode( ',', array_map( 'sanitize_key', (array) ( isset( $post['payment_methods'] ) ? $post['payment_methods'] : array() ) ) ),
			'status'          => isset( $post['status'] ) && 'active' === $post['status'] ? 'active' : 'hidden',
		);
		$data = array_merge( $data, OSB_Meetings::from_post( $post, 'service' ) );

		if ( '' === $data['name'] ) {
			OSB_Admin::flash_error( __( 'Please give the service a name.', 'osam-bookings' ) );
			wp_safe_redirect( wp_get_referer() );
			exit;
		}

		if ( $id ) {
			OSB_DB::update( 'services', $id, $data );
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$id                 = OSB_DB::insert( 'services', $data );
		}

		if ( OSB_DB::is_solo() ) {
			// Keep the owner assigned so switching to team mode later just works.
			if ( ! OSB_DB::raw_service_staff_ids( $id ) ) {
				OSB_DB::set_service_staff( $id, array( OSB_DB::solo_staff_id() => '' ) );
			}
		} else {
			$staff = array();
			foreach ( (array) ( isset( $post['staff'] ) ? $post['staff'] : array() ) as $staff_id => $row ) {
				if ( ! empty( $row['on'] ) ) {
					$staff[ absint( $staff_id ) ] = isset( $row['price'] ) && '' !== $row['price'] ? (float) $row['price'] : '';
				}
			}
			OSB_DB::set_service_staff( $id, $staff );
		}
		OSB_Availability::bust_cache();
		do_action( 'osb_service_saved', $id, $data );

		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-services&action=edit&id=' . $id ) );
	}

	/**
	 * Delete handler.
	 */
	public static function delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_delete_service_' . $id );
		list( , $count ) = OSB_DB::query_bookings( array( 'service_id' => $id, 'per_page' => 1 ) );
		if ( $count ) {
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-services' ), 'has_items' );
		}
		OSB_DB::delete( 'services', $id );
		OSB_DB::set_service_staff( $id, array() );
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-services' ), 'deleted' );
	}

	/**
	 * AJAX drag sort for services/staff.
	 */
	public static function sort() {
		check_ajax_referer( 'wp_rest', 'nonce' );
		if ( ! current_user_can( OSB_Admin::CAP ) ) {
			wp_send_json_error();
		}
		$table = isset( $_POST['table'] ) ? sanitize_key( $_POST['table'] ) : '';
		if ( ! in_array( $table, array( 'services', 'staff' ), true ) ) {
			wp_send_json_error();
		}
		$ids = array_map( 'absint', (array) ( isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array() ) );
		foreach ( $ids as $i => $id ) {
			OSB_DB::update( $table, $id, array( 'sort_order' => $i ) );
		}
		wp_send_json_success();
	}
}
