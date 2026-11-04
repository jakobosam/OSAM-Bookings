<?php
/**
 * Add-ons admin: Services & events → Add-ons.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add-on screens.
 */
class OSB_Admin_Addons {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_save_addon', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_osb_addon_action', array( __CLASS__, 'action' ) );
		add_action( 'osb_admin_booking_side', array( __CLASS__, 'booking_panel' ), 6 );
	}

	/**
	 * URL.
	 *
	 * @param array $args Args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'osb-addons' ), $args ), admin_url( 'admin.php' ) );
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
		$rows = OSB_Addons::all();
		$new  = self::url( array( 'action' => 'new' ) );
		OSB_Admin::open(
			__( 'Add-ons', 'osam-bookings' ),
			array(
				'subtitle' => __( 'Extras customers can choose on the booking form — equipment hire, refreshments, towels, extra time. Choose which bookings each one is offered on.', 'osam-bookings' ),
				'actions'  => array( array( __( 'Add an add-on', 'osam-bookings' ), $new, 'primary', 'dashicons-plus-alt2' ) ),
			)
		);
		if ( ! OSB_Addons::enabled() ) {
			echo '<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div>' . wp_kses_post( sprintf( /* translators: %s link */ __( 'Add-ons are switched off, so customers won’t see them. Turn them on in %s.', 'osam-bookings' ), '<a href="' . esc_url( admin_url( 'admin.php?page=osb-settings&tab=features' ) ) . '">' . esc_html__( 'Settings → Features', 'osam-bookings' ) . '</a>' ) ) . '</div></div>';
		}
		if ( ! $rows ) {
			OSB_Admin::empty_state( 'dashicons-plus-alt', __( 'No add-ons yet', 'osam-bookings' ), __( 'For example “Dog towel £3”, “Projector hire £15 per day” or “Tea & coffee £2.50 per person”.', 'osam-bookings' ), array( __( 'Add your first add-on', 'osam-bookings' ), $new ) );
			OSB_Admin::close();
			return;
		}
		?>
		<div class="osb-card osb-card--table">
			<table class="osb-table osb-table--click">
				<thead><tr>
					<th><?php esc_html_e( 'Add-on', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Price', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Offered on', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th>
					<th class="osb-col-actions"></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $rows as $a ) :
					$edit = self::url( array( 'action' => 'edit', 'id' => $a->id ) );
					$act  = static function ( $do ) use ( $a ) {
						return wp_nonce_url( admin_url( 'admin-post.php?action=osb_addon_action&do=' . $do . '&id=' . (int) $a->id ), 'osb_addon_' . (int) $a->id );
					};
					?>
					<tr>
						<td><a class="osb-rowlink" href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( $a->name ); ?></strong></a><small><?php echo esc_html( $a->required ? __( 'Always added', 'osam-bookings' ) : ( (int) $a->max_qty > 1 ? sprintf( /* translators: %d max */ __( 'Up to %d', 'osam-bookings' ), (int) $a->max_qty ) : __( 'Tick box', 'osam-bookings' ) ) ); ?></small></td>
						<td><?php echo esc_html( OSB_Addons::price_label( $a ) ); ?></td>
						<td><?php echo esc_html( OSB_Items::describe( OSB_Items::decode( $a->applies ) ) ); ?></td>
						<td><a href="<?php echo esc_url( $act( 'active' === $a->status ? 'off' : 'on' ) ); ?>"><?php echo 'active' === $a->status ? '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'On', 'osam-bookings' ) . '</span>' : '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Off', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></td>
						<td class="osb-col-actions">
							<a class="osb-iconbtn" href="<?php echo esc_url( $edit ); ?>" title="<?php esc_attr_e( 'Edit', 'osam-bookings' ); ?>"><span class="dashicons dashicons-edit"></span></a>
							<a class="osb-iconbtn osb-iconbtn--danger osb-delete" href="<?php echo esc_url( $act( 'delete' ) ); ?>" title="<?php esc_attr_e( 'Delete', 'osam-bookings' ); ?>"><span class="dashicons dashicons-trash"></span></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="osb-help"><?php esc_html_e( 'Add-ons show at the “Your details” step, in the order listed here. Bundles pay for the booking itself; add-ons are still charged.', 'osam-bookings' ); ?></p>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Editor.
	 *
	 * @param int $id Id.
	 */
	private static function edit( $id ) {
		$a = $id ? OSB_Addons::get( $id ) : null;
		if ( ! $a ) {
			$a = (object) array(
				'id'          => 0,
				'name'        => '',
				'description' => '',
				'price'       => '0.00',
				'price_type'  => 'booking',
				'max_qty'     => 1,
				'required'    => 0,
				'applies'     => '[]',
				'image_id'    => 0,
				'status'      => 'active',
			);
		}
		$sym     = trim( OSB_Settings::currencies()[ OSB_Settings::get( 'currency' ) ] ?? '' );
		$applies = OSB_Items::decode( $a->applies );
		OSB_Admin::open(
			$a->id ? $a->name : __( 'New add-on', 'osam-bookings' ),
			array(
				'back'       => self::url(),
				'back_label' => __( 'Add-ons', 'osam-bookings' ),
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form">
			<?php wp_nonce_field( 'osb_save_addon' ); ?>
			<input type="hidden" name="action" value="osb_save_addon">
			<input type="hidden" name="id" value="<?php echo (int) $a->id; ?>">
			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Add-on', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'What customers see on the booking form.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_ao_name"><?php esc_html_e( 'Name', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="text" id="osb_ao_name" name="name" value="<?php echo esc_attr( $a->name ); ?>" required placeholder="<?php esc_attr_e( 'e.g. Dog towel', 'osam-bookings' ); ?>"></div>
						<div class="osb-field osb-field--half"><label><?php esc_html_e( 'Picture', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'image_id', (int) $a->image_id, 'image' ); ?></div>
						<div class="osb-field"><label for="osb_ao_desc"><?php esc_html_e( 'Short description', 'osam-bookings' ); ?></label><input type="text" id="osb_ao_desc" name="description" value="<?php echo esc_attr( wp_strip_all_tags( (string) $a->description ) ); ?>" maxlength="200"></div>
					</div>
				</div>
			</div>
			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Price', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Per person uses the places or guests booked. Per hour uses the booking’s length. Per day is for whole-day resource hire.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--quarter"><label for="osb_ao_price"><?php esc_html_e( 'Price', 'osam-bookings' ); ?></label><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" id="osb_ao_price" name="price" min="0" step="0.01" value="<?php echo esc_attr( $a->price ); ?>"></div></div>
						<div class="osb-field osb-field--quarter"><label for="osb_ao_per"><?php esc_html_e( 'Charged', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'price_type', $a->price_type, 'select', OSB_Addons::price_types(), 'osb_ao_per' ); ?></div>
						<div class="osb-field osb-field--quarter"><label for="osb_ao_max"><?php esc_html_e( 'Most per booking', 'osam-bookings' ); ?></label><input type="number" id="osb_ao_max" name="max_qty" min="1" max="999" value="<?php echo (int) $a->max_qty; ?>"><p class="osb-help"><?php esc_html_e( '1 = a tick box; more = a − / + counter.', 'osam-bookings' ); ?></p></div>
					</div>
					<label class="osb-switch"><input type="checkbox" name="required" value="1" <?php checked( (int) $a->required ); ?>><span></span><?php esc_html_e( 'Always add it (a compulsory charge, e.g. a cleaning fee)', 'osam-bookings' ); ?></label>
					<label class="osb-switch"><input type="checkbox" name="active" value="1" <?php checked( 'active' === $a->status ); ?>><span></span><?php esc_html_e( 'Offer it on the booking form', 'osam-bookings' ); ?></label>
				</div>
			</div>
			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Offered on', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Nothing ticked = every booking. Tick a whole group, a category or single items.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<?php foreach ( OSB_Items::groups( $applies ) as $g => $opts ) : ?>
						<div class="osb-showfor__group"><span><?php echo esc_html( $g ); ?></span><?php OSB_Admin_Custom_Emails::chips( 'applies', $opts, $applies, (string) array_key_first( $opts ) ); ?></div>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="osb-savebar"><button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Save add-on', 'osam-bookings' ); ?></button></div>
		</form>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Save.
	 */
	public static function save() {
		OSB_Admin::verify( 'osb_save_addon' );
		$p   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id  = absint( $p['id'] ?? 0 );
		$row = array(
			'name'        => sanitize_text_field( $p['name'] ?? '' ),
			'description' => sanitize_text_field( $p['description'] ?? '' ),
			'price'       => number_format( max( 0, (float) ( $p['price'] ?? 0 ) ), 2, '.', '' ),
			'price_type'  => isset( OSB_Addons::price_types()[ $p['price_type'] ?? '' ] ) ? $p['price_type'] : 'booking',
			'max_qty'     => max( 1, min( 999, absint( $p['max_qty'] ?? 1 ) ) ),
			'required'    => empty( $p['required'] ) ? 0 : 1,
			'applies'     => wp_json_encode( OSB_Items::clean( $p['applies'] ?? array() ) ),
			'image_id'    => absint( $p['image_id'] ?? 0 ),
			'status'      => empty( $p['active'] ) ? 'inactive' : 'active',
		);
		if ( '' === $row['name'] ) {
			OSB_Admin::flash_error( __( 'Please give the add-on a name.', 'osam-bookings' ) );
			OSB_Admin::redirect( self::url( array( 'action' => $id ? 'edit' : 'new', 'id' => $id ) ), 'error' );
		}
		if ( $id && OSB_Addons::get( $id ) ) {
			OSB_DB::update( 'addons', $id, $row );
		} else {
			$row['created_at'] = current_time( 'mysql' );
			$row['sort_order'] = count( OSB_Addons::all() );
			$id                = OSB_DB::insert( 'addons', $row );
		}
		OSB_Addons::flush();
		OSB_Admin::redirect( self::url() );
	}

	/**
	 * On / off / delete.
	 */
	public static function action() {
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_addon_' . $id );
		$do = sanitize_key( $_GET['do'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'on' === $do || 'off' === $do ) {
			OSB_DB::update( 'addons', $id, array( 'status' => 'on' === $do ? 'active' : 'inactive' ) );
		} elseif ( 'delete' === $do ) {
			OSB_DB::delete( 'addons', $id ); // Bookings keep their own copy of the name and price.
		}
		OSB_Addons::flush();
		OSB_Admin::redirect( self::url(), 'delete' === $do ? 'deleted' : 'saved' );
	}

	/**
	 * Add-ons on the booking screen.
	 *
	 * @param object $b Booking.
	 */
	public static function booking_panel( $b ) {
		$lines = OSB_Addons::lines( $b->id );
		if ( ! $lines ) {
			return;
		}
		?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Add-ons', 'osam-bookings' ) ); ?>
			<dl class="osb-dl">
				<?php foreach ( $lines as $l ) : ?>
					<dt><?php echo esc_html( OSB_Addons::line_label( $l ) ); ?></dt>
					<dd><?php echo esc_html( (float) $l->amount > 0 ? OSB_Settings::money( $l->amount, $b->currency ) : OSB_Settings::label( 'free' ) ); ?></dd>
				<?php endforeach; ?>
			</dl>
		</div>
		<?php
	}
}
