<?php
/**
 * Bundles admin: Customers → Bundles (what's for sale, and the bundles customers hold).
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bundle screens.
 */
class OSB_Admin_Bundles {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_save_bundle', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_osb_bundle_action', array( __CLASS__, 'action' ) );
		add_action( 'admin_post_osb_issue_pass', array( __CLASS__, 'issue' ) );
		add_action( 'admin_post_osb_pass_action', array( __CLASS__, 'pass_action' ) );
		add_action( 'osb_admin_booking_side', array( __CLASS__, 'booking_panel' ), 7 );
	}

	/**
	 * URL.
	 *
	 * @param array $args Args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'osb-bundles' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Router.
	 */
	public static function page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$tab    = isset( $_GET['tab'] ) && 'sold' === $_GET['tab'] ? 'sold' : 'sale';
		// phpcs:enable
		if ( 'edit' === $action || 'new' === $action ) {
			self::edit( $id );
		} elseif ( 'pass' === $action ) {
			self::pass_page( $id );
		} elseif ( 'issue' === $action ) {
			self::issue_page();
		} else {
			self::listing( $tab );
		}
	}

	/**
	 * Pass status pill.
	 *
	 * @param object $p Pass.
	 * @return string
	 */
	private static function pill( $p ) {
		if ( 'active' === $p->status && $p->expires_at && $p->expires_at < current_time( 'Y-m-d' ) ) {
			return '<span class="osb-pill osb-pill--cancelled">' . esc_html__( 'Expired', 'osam-bookings' ) . '</span>';
		}
		if ( 'active' === $p->status && OSB_Bundles::left( $p ) < 1 ) {
			return '<span class="osb-pill osb-pill--completed">' . esc_html__( 'Used up', 'osam-bookings' ) . '</span>';
		}
		$map = array(
			'active'    => array( 'confirmed', __( 'Active', 'osam-bookings' ) ),
			'pending'   => array( 'pending_payment', __( 'Awaiting payment', 'osam-bookings' ) ),
			'cancelled' => array( 'neutral', __( 'Cancelled', 'osam-bookings' ) ),
		);
		$m = $map[ $p->status ] ?? array( 'neutral', $p->status );
		return '<span class="osb-pill osb-pill--' . esc_attr( $m[0] ) . '">' . esc_html( $m[1] ) . '</span>';
	}

	/**
	 * List.
	 *
	 * @param string $tab sale|sold.
	 */
	private static function listing( $tab ) {
		global $wpdb;
		$t      = OSB_Install::table( 'passes' );
		$sold_n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status <> 'cancelled'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = OSB_Bundles::all();
		OSB_Admin::open(
			__( 'Bundles', 'osam-bookings' ),
			array(
				'subtitle' => __( 'Packs of sessions sold in advance. Customers get a code to pay for bookings with; each booking uses one credit (or one per place).', 'osam-bookings' ),
				'actions'  => array(
					array( __( 'Give a bundle', 'osam-bookings' ), self::url( array( 'action' => 'issue' ) ), 'secondary', 'dashicons-tickets' ),
					array( __( 'Add a bundle', 'osam-bookings' ), self::url( array( 'action' => 'new' ) ), 'primary', 'dashicons-plus-alt2' ),
				),
			)
		);
		if ( ! OSB_Bundles::enabled() ) {
			echo '<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div>' . wp_kses_post( sprintf( /* translators: %s link */ __( 'Bundles are switched off, so they aren’t on sale and codes can’t be used. Turn them on in %s.', 'osam-bookings' ), '<a href="' . esc_url( admin_url( 'admin.php?page=osb-settings&tab=features' ) ) . '">' . esc_html__( 'Settings → Features', 'osam-bookings' ) . '</a>' ) ) . '</div></div>';
		}
		?>
		<div class="osb-segmented">
			<a href="<?php echo esc_url( self::url() ); ?>" class="<?php echo 'sale' === $tab ? 'is-active' : ''; ?>"><?php esc_html_e( 'For sale', 'osam-bookings' ); ?> <span><?php echo count( $rows ); ?></span></a>
			<a href="<?php echo esc_url( self::url( array( 'tab' => 'sold' ) ) ); ?>" class="<?php echo 'sold' === $tab ? 'is-active' : ''; ?>"><?php esc_html_e( 'Customers’ bundles', 'osam-bookings' ); ?> <span><?php echo (int) $sold_n; ?></span></a>
		</div>
		<?php
		if ( 'sold' === $tab ) {
			self::sold_table();
			OSB_Admin::close();
			return;
		}
		if ( ! $rows ) {
			OSB_Admin::empty_state( 'dashicons-tickets', __( 'No bundles yet', 'osam-bookings' ), __( 'For example “10 field sessions for £80” or “5 classes, valid 3 months”. Put them on sale with [osam_bundles], or they show as a “Bundles” tab on the booking form.', 'osam-bookings' ), array( __( 'Add your first bundle', 'osam-bookings' ), self::url( array( 'action' => 'new' ) ) ) );
			OSB_Admin::close();
			return;
		}
		?>
		<div class="osb-card osb-card--table">
			<table class="osb-table osb-table--click">
				<thead><tr>
					<th><?php esc_html_e( 'Bundle', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Price', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Use for', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Sold', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th>
					<th class="osb-col-actions"></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $rows as $b ) :
					$edit = self::url( array( 'action' => 'edit', 'id' => $b->id ) );
					$act  = static function ( $do ) use ( $b ) {
						return wp_nonce_url( admin_url( 'admin-post.php?action=osb_bundle_action&do=' . $do . '&id=' . (int) $b->id ), 'osb_bundle_' . (int) $b->id );
					};
					$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE bundle_id = %d AND status <> 'cancelled'", $b->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					?>
					<tr>
						<td><a class="osb-rowlink" href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( $b->name ); ?></strong></a><small><?php echo esc_html( OSB_Bundles::credits_label( (int) $b->credits, $b->unit ) . ' · ' . OSB_Bundles::validity_label( (int) $b->valid_days ) ); ?></small></td>
						<td><?php echo esc_html( (float) $b->price > 0 ? OSB_Settings::money( $b->price ) : OSB_Settings::label( 'free' ) ); ?></td>
						<td><?php echo esc_html( OSB_Items::describe( OSB_Items::decode( $b->applies ) ) ); ?></td>
						<td><?php echo (int) $n; ?></td>
						<td><a href="<?php echo esc_url( $act( 'active' === $b->status ? 'off' : 'on' ) ); ?>"><?php echo 'active' === $b->status ? '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'On sale', 'osam-bookings' ) . '</span>' : '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Not on sale', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></td>
						<td class="osb-col-actions">
							<a class="osb-iconbtn" href="<?php echo esc_url( $edit ); ?>" title="<?php esc_attr_e( 'Edit', 'osam-bookings' ); ?>"><span class="dashicons dashicons-edit"></span></a>
							<a class="osb-iconbtn osb-iconbtn--danger osb-delete" href="<?php echo esc_url( $act( 'delete' ) ); ?>" title="<?php esc_attr_e( 'Delete', 'osam-bookings' ); ?>"><span class="dashicons dashicons-trash"></span></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="osb-help"><?php echo wp_kses_post( __( 'Sell them with the shortcode <code>[osam_bundles]</code>, or they appear as a “Bundles” tab on the main booking form.', 'osam-bookings' ) ); ?></p>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Customers' passes.
	 */
	private static function sold_table() {
		global $wpdb;
		$t = OSB_Install::table( 'passes' );
		$q = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $q
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE code LIKE %s OR customer_email LIKE %s OR customer_name LIKE %s ORDER BY id DESC LIMIT 200", '%' . $wpdb->esc_like( $q ) . '%', '%' . $wpdb->esc_like( $q ) . '%', '%' . $wpdb->esc_like( $q ) . '%' ) )
			: $wpdb->get_results( "SELECT * FROM $t ORDER BY id DESC LIMIT 200" );
		// phpcs:enable
		?>
		<form method="get" class="osb-toolbar"><input type="hidden" name="page" value="osb-bundles"><input type="hidden" name="tab" value="sold">
			<div class="osb-search"><span class="dashicons dashicons-search"></span><input type="search" name="s" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Search code, name or email', 'osam-bookings' ); ?>"></div>
		</form>
		<?php if ( ! $rows ) : ?>
			<?php OSB_Admin::empty_state( 'dashicons-tickets', __( 'No bundles sold yet', 'osam-bookings' ), __( 'When customers buy a bundle — or you give one — it shows here with what’s left.', 'osam-bookings' ) ); ?>
		<?php else : ?>
		<div class="osb-card osb-card--table">
			<table class="osb-table osb-table--click">
				<thead><tr>
					<th><?php esc_html_e( 'Code', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Bundle', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Left', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Use by', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $p ) : $link = self::url( array( 'action' => 'pass', 'id' => $p->id ) ); $pct = (int) $p->credits ? round( OSB_Bundles::left( $p ) / (int) $p->credits * 100 ) : 0; ?>
					<tr>
						<td><a class="osb-rowlink" href="<?php echo esc_url( $link ); ?>"><code class="osb-code"><?php echo esc_html( $p->code ); ?></code></a></td>
						<td><?php echo esc_html( $p->customer_name ); ?><small><?php echo esc_html( $p->customer_email ); ?></small></td>
						<td><?php echo esc_html( $p->name ); ?></td>
						<td><strong><?php echo (int) OSB_Bundles::left( $p ); ?></strong> / <?php echo (int) $p->credits; ?><span class="osb-meter osb-meter--sm"><span style="width:<?php echo (int) $pct; ?>%"></span></span></td>
						<td><?php echo $p->expires_at ? esc_html( mysql2date( 'j M Y', $p->expires_at ) ) : '<span class="osb-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo self::pill( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		endif;
	}

	/**
	 * Bundle editor.
	 *
	 * @param int $id Id.
	 */
	private static function edit( $id ) {
		$b = $id ? OSB_Bundles::get( $id ) : null;
		if ( ! $b ) {
			$b = (object) array(
				'id'              => 0,
				'name'            => '',
				'description'     => '',
				'credits'         => 10,
				'unit'            => 'booking',
				'price'           => '0.00',
				'valid_days'      => 365,
				'applies'         => '[]',
				'payment_methods' => '',
				'image_id'        => 0,
				'color'           => '#0273b0',
				'status'          => 'active',
			);
		}
		$sym     = trim( OSB_Settings::currencies()[ OSB_Settings::get( 'currency' ) ] ?? '' );
		$applies = OSB_Items::decode( $b->applies );
		OSB_Admin::open(
			$b->id ? $b->name : __( 'New bundle', 'osam-bookings' ),
			array(
				'back'       => self::url(),
				'back_label' => __( 'Bundles', 'osam-bookings' ),
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form">
			<?php wp_nonce_field( 'osb_save_bundle' ); ?>
			<input type="hidden" name="action" value="osb_save_bundle">
			<input type="hidden" name="id" value="<?php echo (int) $b->id; ?>">
			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Bundle', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'How it appears to customers.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_bn_name"><?php esc_html_e( 'Name', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="text" id="osb_bn_name" name="name" value="<?php echo esc_attr( $b->name ); ?>" required placeholder="<?php esc_attr_e( 'e.g. 10 field sessions', 'osam-bookings' ); ?>"></div>
						<div class="osb-field osb-field--quarter"><label><?php esc_html_e( 'Picture', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'image_id', (int) $b->image_id, 'image' ); ?></div>
						<div class="osb-field osb-field--quarter"><label for="osb_bn_color"><?php esc_html_e( 'Colour', 'osam-bookings' ); ?></label><input type="text" id="osb_bn_color" name="color" class="osb-color" value="<?php echo esc_attr( $b->color ); ?>"></div>
						<div class="osb-field"><label for="osb_bn_desc"><?php esc_html_e( 'Description', 'osam-bookings' ); ?></label><textarea id="osb_bn_desc" name="description" rows="3"><?php echo esc_textarea( (string) $b->description ); ?></textarea></div>
					</div>
				</div>
			</div>
			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'What it includes', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Per place suits classes where people book several places at once. Validity starts when it’s paid for.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--quarter"><label for="osb_bn_cr"><?php esc_html_e( 'Credits', 'osam-bookings' ); ?></label><input type="number" id="osb_bn_cr" name="credits" min="1" max="1000" value="<?php echo (int) $b->credits; ?>"></div>
						<div class="osb-field osb-field--quarter"><label for="osb_bn_unit"><?php esc_html_e( 'One credit is', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'unit', $b->unit, 'select', array( 'booking' => __( 'one booking', 'osam-bookings' ), 'place' => __( 'one place / person', 'osam-bookings' ) ), 'osb_bn_unit' ); ?></div>
						<div class="osb-field osb-field--quarter"><label for="osb_bn_price"><?php esc_html_e( 'Price', 'osam-bookings' ); ?></label><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" id="osb_bn_price" name="price" min="0" step="0.01" value="<?php echo esc_attr( $b->price ); ?>"></div></div>
						<div class="osb-field osb-field--quarter"><label for="osb_bn_valid"><?php esc_html_e( 'Valid for', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'valid_days', (int) $b->valid_days, 'select', array( 30 => __( '1 month', 'osam-bookings' ), 60 => __( '2 months', 'osam-bookings' ), 90 => __( '3 months', 'osam-bookings' ), 180 => __( '6 months', 'osam-bookings' ), 365 => __( '1 year', 'osam-bookings' ), 730 => __( '2 years', 'osam-bookings' ), 0 => __( 'No expiry', 'osam-bookings' ) ), 'osb_bn_valid' ); ?></div>
					</div>
					<div class="osb-field"><label><?php esc_html_e( 'Payment methods offered', 'osam-bookings' ); ?></label><?php OSB_Admin::methods_checkboxes( $b->payment_methods ); ?><p class="osb-help"><?php esc_html_e( 'With pay on site, the code is only sent once you mark the purchase paid.', 'osam-bookings' ); ?></p></div>
					<label class="osb-switch"><input type="checkbox" name="active" value="1" <?php checked( 'active' === $b->status ); ?>><span></span><?php esc_html_e( 'On sale', 'osam-bookings' ); ?></label>
				</div>
			</div>
			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Can be used for', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Nothing ticked = any booking. Bundles bought earlier keep the rules they were sold with.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<?php foreach ( OSB_Items::groups( $applies ) as $g => $opts ) : ?>
						<div class="osb-showfor__group"><span><?php echo esc_html( $g ); ?></span><?php OSB_Admin_Custom_Emails::chips( 'applies', $opts, $applies, (string) array_key_first( $opts ) ); ?></div>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="osb-savebar"><button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Save bundle', 'osam-bookings' ); ?></button></div>
		</form>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Save a bundle.
	 */
	public static function save() {
		OSB_Admin::verify( 'osb_save_bundle' );
		$p   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id  = absint( $p['id'] ?? 0 );
		$row = array(
			'name'            => sanitize_text_field( $p['name'] ?? '' ),
			'description'     => wp_kses_post( $p['description'] ?? '' ),
			'credits'         => max( 1, min( 1000, absint( $p['credits'] ?? 10 ) ) ),
			'unit'            => 'place' === ( $p['unit'] ?? '' ) ? 'place' : 'booking',
			'price'           => number_format( max( 0, (float) ( $p['price'] ?? 0 ) ), 2, '.', '' ),
			'valid_days'      => min( 3650, absint( $p['valid_days'] ?? 365 ) ),
			'applies'         => wp_json_encode( OSB_Items::clean( $p['applies'] ?? array() ) ),
			'payment_methods' => implode( ',', array_map( 'sanitize_key', (array) ( $p['payment_methods'] ?? array() ) ) ),
			'image_id'        => absint( $p['image_id'] ?? 0 ),
			'color'           => sanitize_hex_color( $p['color'] ?? '' ) ? sanitize_hex_color( $p['color'] ) : '#0273b0',
			'status'          => empty( $p['active'] ) ? 'inactive' : 'active',
		);
		if ( '' === $row['name'] ) {
			OSB_Admin::flash_error( __( 'Please give the bundle a name.', 'osam-bookings' ) );
			OSB_Admin::redirect( self::url( array( 'action' => $id ? 'edit' : 'new', 'id' => $id ) ), 'error' );
		}
		if ( $id && OSB_Bundles::get( $id ) ) {
			OSB_DB::update( 'bundles', $id, $row );
		} else {
			$row['created_at'] = current_time( 'mysql' );
			$row['sort_order'] = count( OSB_Bundles::all() );
			OSB_DB::insert( 'bundles', $row );
		}
		OSB_Bundles::flush();
		OSB_Admin::redirect( self::url() );
	}

	/**
	 * On sale / off / delete.
	 */
	public static function action() {
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_bundle_' . $id );
		$do = sanitize_key( $_GET['do'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'on' === $do || 'off' === $do ) {
			OSB_DB::update( 'bundles', $id, array( 'status' => 'on' === $do ? 'active' : 'inactive' ) );
		} elseif ( 'delete' === $do ) {
			global $wpdb;
			$sold = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . OSB_Install::table( 'passes' ) . ' WHERE bundle_id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $sold ) {
				OSB_DB::update( 'bundles', $id, array( 'status' => 'inactive' ) );
				OSB_Admin::flash_warnings( array( __( 'Customers hold this bundle, so it was taken off sale instead of deleted. Their codes still work.', 'osam-bookings' ) ) );
			} else {
				OSB_DB::delete( 'bundles', $id );
			}
		}
		OSB_Bundles::flush();
		OSB_Admin::redirect( self::url() );
	}

	/**
	 * "Give a bundle" form (sold in person, or a gift).
	 */
	private static function issue_page() {
		OSB_Admin::open( __( 'Give a bundle', 'osam-bookings' ), array( 'back' => self::url( array( 'tab' => 'sold' ) ), 'back_label' => __( 'Bundles', 'osam-bookings' ), 'subtitle' => __( 'For bundles sold in person or given as a gift. The customer is emailed their code straight away.', 'osam-bookings' ) ) );
		$list = OSB_Bundles::all();
		if ( ! $list ) {
			OSB_Admin::empty_state( 'dashicons-tickets', __( 'Make a bundle first', 'osam-bookings' ), '', array( __( 'Add a bundle', 'osam-bookings' ), self::url( array( 'action' => 'new' ) ) ) );
			OSB_Admin::close();
			return;
		}
		$opts = array();
		foreach ( $list as $b ) {
			$opts[ $b->id ] = $b->name . ' — ' . OSB_Settings::money( $b->price );
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form">
			<?php wp_nonce_field( 'osb_issue_pass' ); ?>
			<input type="hidden" name="action" value="osb_issue_pass">
			<div class="osb-card">
				<div class="osb-fieldgrid">
					<div class="osb-field osb-field--half"><label for="osb_ip_b"><?php esc_html_e( 'Bundle', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'bundle_id', 0, 'select', $opts, 'osb_ip_b' ); ?></div>
					<div class="osb-field osb-field--half"><label for="osb_ip_paid"><?php esc_html_e( 'Payment', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'paid', 'paid', 'select', array( 'paid' => __( 'Paid (in person)', 'osam-bookings' ), 'free' => __( 'Free / gift', 'osam-bookings' ) ), 'osb_ip_paid' ); ?></div>
					<div class="osb-field osb-field--half"><label for="osb_ip_n"><?php esc_html_e( 'Customer name', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="text" id="osb_ip_n" name="name" required></div>
					<div class="osb-field osb-field--half"><label for="osb_ip_e"><?php esc_html_e( 'Customer email', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="email" id="osb_ip_e" name="email" required></div>
					<div class="osb-field"><label for="osb_ip_note"><?php esc_html_e( 'Note', 'osam-bookings' ); ?></label><input type="text" id="osb_ip_note" name="note" placeholder="<?php esc_attr_e( 'Only you see this', 'osam-bookings' ); ?>"></div>
				</div>
			</div>
			<div class="osb-savebar"><button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Give bundle and email the code', 'osam-bookings' ); ?></button></div>
		</form>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Give a bundle.
	 */
	public static function issue() {
		OSB_Admin::verify( 'osb_issue_pass' );
		$p      = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$bundle = OSB_Bundles::get( absint( $p['bundle_id'] ?? 0 ) );
		$email  = sanitize_email( $p['email'] ?? '' );
		$name   = sanitize_text_field( $p['name'] ?? '' );
		if ( ! $bundle || ! is_email( $email ) || '' === $name ) {
			OSB_Admin::flash_error( __( 'Please choose a bundle and enter the customer’s name and email.', 'osam-bookings' ) );
			OSB_Admin::redirect( self::url( array( 'action' => 'issue' ) ), 'error' );
		}
		$free    = 'free' === ( $p['paid'] ?? '' );
		$booking = OSB_Bookings::create(
			array(
				'type'           => 'bundle',
				'bundle_id'      => $bundle->id,
				'admin'          => true,
				'lenient'        => true,
				'notify'         => false,
				'payment_method' => 'onsite',
				'status'         => 'confirmed',
				'fields'         => array( 'name' => $name, 'email' => $email ),
			)
		);
		if ( is_wp_error( $booking ) ) {
			OSB_Admin::flash_error( $booking->get_error_message() );
			OSB_Admin::redirect( self::url( array( 'action' => 'issue' ) ), 'error' );
		}
		OSB_DB::update( 'bookings', $booking->id, array_merge( array( 'payment_status' => $free ? 'free' : 'paid' ), $free ? array( 'amount' => 0, 'payment_method' => 'free' ) : array(), array( 'internal_note' => sanitize_text_field( $p['note'] ?? '' ) ) ) );
		$pass = OSB_Bundles::pass_for_purchase( $booking->id );
		if ( $pass ) {
			if ( ! empty( $p['note'] ) ) {
				OSB_DB::update( 'passes', $pass->id, array( 'note' => sanitize_text_field( $p['note'] ) ) );
			}
			OSB_Bundles::activate( $pass->id );
			OSB_Admin::redirect( self::url( array( 'action' => 'pass', 'id' => $pass->id ) ), 'emailed' );
		}
		OSB_Admin::redirect( self::url( array( 'tab' => 'sold' ) ) );
	}

	/**
	 * One customer's bundle.
	 *
	 * @param int $id Pass.
	 */
	private static function pass_page( $id ) {
		$p = OSB_Bundles::pass( $id );
		if ( ! $p ) {
			wp_safe_redirect( self::url( array( 'tab' => 'sold' ) ) );
			exit;
		}
		$act = static function ( $do ) use ( $p ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=osb_pass_action&do=' . $do . '&id=' . (int) $p->id ), 'osb_pass_' . (int) $p->id );
		};
		OSB_Admin::open( $p->code, array( 'back' => self::url( array( 'tab' => 'sold' ) ), 'back_label' => __( 'Customers’ bundles', 'osam-bookings' ), 'after_title' => ' ' . self::pill( $p ) ) );
		?>
		<div class="osb-grid-2">
			<div class="osb-card">
				<?php OSB_Admin::card_head( $p->name ); ?>
				<dl class="osb-dl">
					<dt><?php esc_html_e( 'Customer', 'osam-bookings' ); ?></dt><dd><?php echo esc_html( $p->customer_name ); ?><br><a href="mailto:<?php echo esc_attr( $p->customer_email ); ?>"><?php echo esc_html( $p->customer_email ); ?></a></dd>
					<dt><?php esc_html_e( 'Left', 'osam-bookings' ); ?></dt><dd><strong><?php echo (int) OSB_Bundles::left( $p ); ?></strong> / <?php echo (int) $p->credits; ?> (<?php echo esc_html( 'place' === $p->unit ? __( 'places', 'osam-bookings' ) : __( 'bookings', 'osam-bookings' ) ); ?>)</dd>
					<dt><?php esc_html_e( 'Use by', 'osam-bookings' ); ?></dt><dd><?php echo $p->expires_at ? esc_html( mysql2date( get_option( 'date_format' ), $p->expires_at ) ) : esc_html__( 'No expiry', 'osam-bookings' ); ?></dd>
					<dt><?php esc_html_e( 'Use for', 'osam-bookings' ); ?></dt><dd><?php echo esc_html( OSB_Items::describe( OSB_Items::decode( $p->applies ) ) ); ?></dd>
					<?php if ( $p->booking_id ) : ?><dt><?php esc_html_e( 'Bought', 'osam-bookings' ); ?></dt><dd><a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . (int) $p->booking_id ) ); ?>">#<?php echo (int) $p->booking_id; ?></a> · <?php echo esc_html( mysql2date( 'j M Y', $p->created_at ) . ' · ' . OSB_Settings::money( $p->amount ) ); ?></dd><?php endif; ?>
					<?php if ( $p->note ) : ?><dt><?php esc_html_e( 'Note', 'osam-bookings' ); ?></dt><dd><?php echo esc_html( $p->note ); ?></dd><?php endif; ?>
				</dl>
				<p class="osb-inline">
					<?php if ( 'active' === $p->status ) : ?><a class="osb-btn osb-btn--ghost osb-btn--sm" href="<?php echo esc_url( $act( 'resend' ) ); ?>"><span class="dashicons dashicons-email"></span><?php esc_html_e( 'Email the code again', 'osam-bookings' ); ?></a><?php endif; ?>
					<?php if ( 'pending' === $p->status ) : ?><a class="osb-btn osb-btn--primary osb-btn--sm" href="<?php echo esc_url( $act( 'activate' ) ); ?>"><?php esc_html_e( 'Mark paid and send the code', 'osam-bookings' ); ?></a><?php endif; ?>
					<?php if ( 'cancelled' !== $p->status ) : ?><a class="osb-btn osb-btn--ghost osb-btn--sm osb-btn--danger" data-osb-confirm="<?php esc_attr_e( 'Cancel this bundle? The code will stop working. Bookings already made are kept.', 'osam-bookings' ); ?>" href="<?php echo esc_url( $act( 'cancel' ) ); ?>"><?php esc_html_e( 'Cancel bundle', 'osam-bookings' ); ?></a><?php endif; ?>
				</p>
			</div>
			<div class="osb-card">
				<?php OSB_Admin::card_head( __( 'Adjust credits', 'osam-bookings' ), __( 'Add credits as a goodwill gesture, or take some off for sessions used in person.', 'osam-bookings' ) ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-inline">
					<?php wp_nonce_field( 'osb_pass_' . (int) $p->id ); ?>
					<input type="hidden" name="action" value="osb_pass_action"><input type="hidden" name="do" value="adjust"><input type="hidden" name="id" value="<?php echo (int) $p->id; ?>">
					<input type="number" name="delta" value="1" step="1" style="max-width:90px" aria-label="<?php esc_attr_e( 'Credits (+/-)', 'osam-bookings' ); ?>">
					<input type="text" name="note" placeholder="<?php esc_attr_e( 'Reason', 'osam-bookings' ); ?>">
					<button class="osb-btn osb-btn--ghost"><?php esc_html_e( 'Apply', 'osam-bookings' ); ?></button>
				</form>
				<p class="osb-help"><?php esc_html_e( 'Use a minus number to take credits off (e.g. -1).', 'osam-bookings' ); ?></p>
			</div>
		</div>
		<h3 class="osb-grouptitle"><?php esc_html_e( 'History', 'osam-bookings' ); ?></h3>
		<div class="osb-card osb-card--table">
			<table class="osb-table">
				<thead><tr><th><?php esc_html_e( 'When', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'What', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Credits', 'osam-bookings' ); ?></th></tr></thead>
				<tbody>
				<?php
				$uses = OSB_Bundles::uses( $p->id );
				if ( ! $uses ) {
					echo '<tr><td colspan="3" class="osb-muted">' . esc_html__( 'Not used yet.', 'osam-bookings' ) . '</td></tr>';
				}
				foreach ( $uses as $u ) :
					$bk = $u->booking_id ? OSB_DB::get( 'bookings', $u->booking_id ) : null;
					?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'j M Y H:i', $u->created_at ) ); ?></td>
						<td><?php if ( $bk ) : $d = OSB_Bookings::details( $bk ); ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . (int) $bk->id ) ); ?>">#<?php echo (int) $bk->id; ?> <?php echo esc_html( $d['item_name'] ); ?></a><small><?php echo esc_html( $d['date'] . ', ' . $d['time'] ); ?></small><?php else : ?><?php echo esc_html( $u->note ); ?><?php endif; ?>
							<?php echo $u->booking_id && $u->refunded ? ' <span class="osb-pill osb-pill--neutral">' . esc_html__( 'Given back — booking cancelled', 'osam-bookings' ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo (int) $u->credits > 0 ? '−' . (int) $u->credits : '+' . abs( (int) $u->credits ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Pass actions.
	 */
	public static function pass_action() {
		$req = wp_unslash( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id  = absint( $req['id'] ?? 0 );
		OSB_Admin::verify( 'osb_pass_' . $id );
		$p    = OSB_Bundles::pass( $id );
		$back = self::url( array( 'action' => 'pass', 'id' => $id ) );
		if ( ! $p ) {
			OSB_Admin::redirect( self::url( array( 'tab' => 'sold' ) ), 'error' );
		}
		switch ( sanitize_key( $req['do'] ?? '' ) ) {
			case 'resend':
				$b = $p->booking_id ? OSB_DB::get( 'bookings', $p->booking_id ) : null;
				OSB_Admin::redirect( $back, $b && OSB_Emails::send( 'customer_bundle', $b, $p->customer_email, true ) ? 'emailed' : 'error' );
				break;
			case 'activate':
				if ( $p->booking_id ) {
					OSB_DB::update( 'bookings', $p->booking_id, array( 'payment_status' => 'paid' ) );
				}
				OSB_Bundles::activate( $p->id );
				OSB_Admin::redirect( $back, 'emailed' );
				break;
			case 'cancel':
				OSB_DB::update( 'passes', $p->id, array( 'status' => 'cancelled' ) );
				OSB_Admin::redirect( $back );
				break;
			case 'adjust':
				$delta = (int) ( $req['delta'] ?? 0 );
				if ( $delta ) {
					OSB_Bundles::adjust( $p->id, $delta, sanitize_text_field( $req['note'] ?? '' ) );
				}
				OSB_Admin::redirect( $back );
				break;
		}
		OSB_Admin::redirect( $back, 'error' );
	}

	/**
	 * On the booking screen: the bundle bought, or the one that paid.
	 *
	 * @param object $b Booking.
	 */
	public static function booking_panel( $b ) {
		$p = 'bundle' === $b->type ? OSB_Bundles::pass_for_purchase( $b->id ) : ( (int) $b->pass_id ? OSB_Bundles::pass( $b->pass_id ) : null );
		if ( ! $p ) {
			return;
		}
		?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( 'bundle' === $b->type ? __( 'Bundle bought', 'osam-bookings' ) : __( 'Paid with a bundle', 'osam-bookings' ) ); ?>
			<p><a href="<?php echo esc_url( self::url( array( 'action' => 'pass', 'id' => $p->id ) ) ); ?>"><code class="osb-code"><?php echo esc_html( $p->code ); ?></code></a> <?php echo self::pill( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<p class="osb-muted"><?php echo esc_html( $p->name . ' · ' . sprintf( /* translators: 1 left 2 total */ __( '%1$d of %2$d left', 'osam-bookings' ), OSB_Bundles::left( $p ), (int) $p->credits ) ); ?></p>
		</div>
		<?php
	}
}
