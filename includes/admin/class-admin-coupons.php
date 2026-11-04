<?php
/**
 * Discount codes & gift vouchers admin.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Codes screens.
 */
class OSB_Admin_Coupons {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_save_coupon', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_osb_delete_coupon', array( __CLASS__, 'delete' ) );
	}

	/**
	 * URL helper.
	 *
	 * @param array $args Args.
	 * @return string
	 */
	private static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=osb-coupons' ) );
	}

	/**
	 * Router.
	 */
	public static function page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$kind   = isset( $_GET['kind'] ) && 'voucher' === $_GET['kind'] ? 'voucher' : 'discount';
		// phpcs:enable
		if ( 'edit' === $action || 'new' === $action ) {
			self::edit( $id, $kind );
		} else {
			self::listing( $kind );
		}
	}

	/**
	 * Status pill.
	 *
	 * @param object $c Code.
	 * @return string
	 */
	private static function pill( $c ) {
		$today = current_time( 'Y-m-d' );
		if ( 'active' !== $c->status ) {
			return '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Off', 'osam-bookings' ) . '</span>';
		}
		if ( $c->expires_at && $today > $c->expires_at ) {
			return '<span class="osb-pill osb-pill--cancelled">' . esc_html__( 'Expired', 'osam-bookings' ) . '</span>';
		}
		if ( 'voucher' === $c->kind && (float) $c->balance <= 0 ) {
			return '<span class="osb-pill osb-pill--completed">' . esc_html__( 'Used up', 'osam-bookings' ) . '</span>';
		}
		if ( 'voucher' !== $c->kind && $c->max_uses && (int) $c->uses >= (int) $c->max_uses ) {
			return '<span class="osb-pill osb-pill--completed">' . esc_html__( 'Limit reached', 'osam-bookings' ) . '</span>';
		}
		if ( $c->starts_at && $today < $c->starts_at ) {
			return '<span class="osb-pill osb-pill--pending">' . esc_html__( 'Scheduled', 'osam-bookings' ) . '</span>';
		}
		return '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'Active', 'osam-bookings' ) . '</span>';
	}

	/**
	 * List.
	 *
	 * @param string $kind discount|voucher.
	 */
	private static function listing( $kind ) {
		$rows    = OSB_Coupons::all( $kind );
		$counts  = array(
			'discount' => count( OSB_Coupons::all( 'discount' ) ),
			'voucher'  => count( OSB_Coupons::all( 'voucher' ) ),
		);
		$new_url = self::url( array( 'action' => 'new', 'kind' => $kind ) );
		OSB_Admin::open(
			__( 'Codes & vouchers', 'osam-bookings' ),
			array(
				'subtitle' => __( 'Discount codes take money or a percentage off. Gift vouchers hold a balance that customers spend across bookings. Customers enter either at the “Your details” step.', 'osam-bookings' ),
				'actions'  => array( array( 'voucher' === $kind ? __( 'Issue a gift voucher', 'osam-bookings' ) : __( 'Add discount code', 'osam-bookings' ), $new_url, 'primary', 'dashicons-plus-alt2' ) ),
			)
		);
		?>
		<div class="osb-segmented">
			<a href="<?php echo esc_url( self::url() ); ?>" class="<?php echo 'discount' === $kind ? 'is-active' : ''; ?>"><?php esc_html_e( 'Discount codes', 'osam-bookings' ); ?> <span><?php echo (int) $counts['discount']; ?></span></a>
			<a href="<?php echo esc_url( self::url( array( 'kind' => 'voucher' ) ) ); ?>" class="<?php echo 'voucher' === $kind ? 'is-active' : ''; ?>"><?php esc_html_e( 'Gift vouchers', 'osam-bookings' ); ?> <span><?php echo (int) $counts['voucher']; ?></span></a>
		</div>
		<?php if ( ! $rows ) : ?>
			<?php
			OSB_Admin::empty_state(
				'voucher' === $kind ? 'dashicons-heart' : 'dashicons-tag',
				'voucher' === $kind ? __( 'No gift vouchers yet', 'osam-bookings' ) : __( 'No discount codes yet', 'osam-bookings' ),
				'voucher' === $kind ? __( 'Issue a voucher with a value — e.g. one you’ve sold in person — and give the customer the code.', 'osam-bookings' ) : __( 'For example 10% off first visits, or £5 off in January.', 'osam-bookings' ),
				array( 'voucher' === $kind ? __( 'Issue a gift voucher', 'osam-bookings' ) : __( 'Add discount code', 'osam-bookings' ), $new_url )
			);
			?>
		<?php else : ?>
		<div class="osb-card osb-card--table">
			<table class="osb-table osb-table--click">
				<thead><tr>
					<th><?php esc_html_e( 'Code', 'osam-bookings' ); ?></th>
					<th><?php echo 'voucher' === $kind ? esc_html__( 'Balance', 'osam-bookings' ) : esc_html__( 'Gives', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Used', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th>
					<th class="osb-col-actions"></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $rows as $c ) :
					$edit = self::url( array( 'action' => 'edit', 'id' => $c->id ) );
					$del  = wp_nonce_url( admin_url( 'admin-post.php?action=osb_delete_coupon&id=' . $c->id ), 'osb_delete_coupon_' . $c->id );
					?>
					<tr data-href="<?php echo esc_url( $edit ); ?>">
						<td><a class="osb-rowlink" href="<?php echo esc_url( $edit ); ?>"><code class="osb-code"><?php echo esc_html( $c->code ); ?></code></a><small><?php echo esc_html( $c->recipient ? $c->recipient : $c->note ); ?></small></td>
						<td><strong><?php echo esc_html( OSB_Coupons::value_label( $c ) ); ?></strong>
							<?php if ( 'voucher' === $kind ) : ?>
								<?php $pct = (float) $c->amount > 0 ? round( (float) $c->balance / (float) $c->amount * 100 ) : 0; ?>
								<span class="osb-meter osb-meter--sm"><span style="width:<?php echo (int) $pct; ?>%"></span></span>
							<?php elseif ( (float) $c->min_spend > 0 ) : ?>
								<?php /* translators: %s amount */ ?><small><?php echo esc_html( sprintf( __( 'Min. spend %s', 'osam-bookings' ), OSB_Settings::money( $c->min_spend ) ) ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php echo (int) $c->uses; ?><?php echo $c->max_uses ? ' / ' . (int) $c->max_uses : ''; ?></td>
						<td><?php echo $c->expires_at ? esc_html( mysql2date( 'j M Y', $c->expires_at ) ) : '<span class="osb-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo self::pill( $c ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="osb-col-actions">
							<button type="button" class="osb-iconbtn osb-copy" data-copy="<?php echo esc_attr( $c->code ); ?>" title="<?php esc_attr_e( 'Copy code', 'osam-bookings' ); ?>"><span class="dashicons dashicons-admin-page"></span></button>
							<a class="osb-iconbtn osb-iconbtn--danger osb-delete" href="<?php echo esc_url( $del ); ?>" title="<?php esc_attr_e( 'Delete', 'osam-bookings' ); ?>"><span class="dashicons dashicons-trash"></span></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Edit.
	 *
	 * @param int    $id   Id.
	 * @param string $kind Kind for new codes.
	 */
	private static function edit( $id, $kind ) {
		$c = $id ? OSB_DB::get( 'coupons', $id ) : null;
		if ( ! $c ) {
			$c = (object) array(
				'id'                => 0,
				'code'              => OSB_Coupons::generate( 'voucher' === $kind ? 'GIFT' : '' ),
				'kind'              => $kind,
				'type'              => 'voucher' === $kind ? 'fixed' : 'percent',
				'amount'            => 'voucher' === $kind ? '50.00' : '10',
				'balance'           => 0,
				'applies_to'        => 'all',
				'item_ids'          => '',
				'min_spend'         => '0.00',
				'max_uses'          => 0,
				'uses'              => 0,
				'once_per_customer' => 0,
				'first_visit'       => 0,
				'starts_at'         => '',
				'expires_at'        => 'voucher' === $kind ? gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +1 year' ) ) : '',
				'status'            => 'active',
				'recipient'         => '',
				'note'              => '',
			);
		}
		$voucher = 'voucher' === $c->kind;
		$sym     = trim( OSB_Settings::currencies()[ OSB_Settings::get( 'currency' ) ] ?? '' );
		$items   = array_filter( array_map( 'absint', explode( ',', (string) $c->item_ids ) ) );
		$uses    = $c->id ? OSB_Coupons::uses( $c->id ) : array();

		OSB_Admin::open(
			$c->id ? $c->code : ( $voucher ? __( 'New gift voucher', 'osam-bookings' ) : __( 'New discount code', 'osam-bookings' ) ),
			array(
				'back'        => self::url( $voucher ? array( 'kind' => 'voucher' ) : array() ),
				'back_label'  => __( 'Codes & vouchers', 'osam-bookings' ),
				'after_title' => $c->id ? ' ' . self::pill( $c ) : '',
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form osb-coupon-form">
			<?php wp_nonce_field( 'osb_save_coupon' ); ?>
			<input type="hidden" name="action" value="osb_save_coupon">
			<input type="hidden" name="id" value="<?php echo (int) $c->id; ?>">
			<input type="hidden" name="kind" value="<?php echo esc_attr( $c->kind ); ?>">

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php echo $voucher ? esc_html__( 'Voucher', 'osam-bookings' ) : esc_html__( 'Code', 'osam-bookings' ); ?></h2><p><?php echo $voucher ? esc_html__( 'Give the customer this code. It can be used across several bookings until the balance runs out.', 'osam-bookings' ) : esc_html__( 'Customers type this at the details step. Letters, numbers and dashes; not case sensitive.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_code"><?php esc_html_e( 'Code', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="text" id="osb_code" name="code" value="<?php echo esc_attr( $c->code ); ?>" required maxlength="64" class="osb-code-input" style="text-transform:uppercase"></div>
						<?php if ( $voucher ) : ?>
							<div class="osb-field osb-field--half"><label for="osb_amount"><?php esc_html_e( 'Voucher value', 'osam-bookings' ); ?></label><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" id="osb_amount" name="amount" min="0.01" step="0.01" value="<?php echo esc_attr( $c->amount ); ?>"></div></div>
							<?php if ( $c->id ) : ?>
								<div class="osb-field osb-field--half"><label for="osb_balance"><?php esc_html_e( 'Balance left', 'osam-bookings' ); ?></label><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" id="osb_balance" name="balance" min="0" step="0.01" value="<?php echo esc_attr( $c->balance ); ?>"></div><p class="osb-help"><?php esc_html_e( 'Adjust if some was spent in person.', 'osam-bookings' ); ?></p></div>
							<?php endif; ?>
							<div class="osb-field osb-field--half"><label for="osb_recipient"><?php esc_html_e( 'For', 'osam-bookings' ); ?></label><input type="text" id="osb_recipient" name="recipient" value="<?php echo esc_attr( $c->recipient ); ?>" placeholder="<?php esc_attr_e( 'Name or email (for your records)', 'osam-bookings' ); ?>"></div>
						<?php else : ?>
							<div class="osb-field osb-field--half"><label><?php esc_html_e( 'Discount', 'osam-bookings' ); ?></label>
								<div class="osb-inline">
									<select name="type" class="osb-coupon-type"><option value="percent" <?php selected( $c->type, 'percent' ); ?>>% <?php esc_html_e( 'off', 'osam-bookings' ); ?></option><option value="fixed" <?php selected( $c->type, 'fixed' ); ?>><?php echo esc_html( $sym ); ?> <?php esc_html_e( 'off', 'osam-bookings' ); ?></option></select>
									<input type="number" name="amount" min="0" step="0.01" value="<?php echo esc_attr( rtrim( rtrim( (string) $c->amount, '0' ), '.' ) ); ?>" style="max-width:120px">
								</div>
							</div>
						<?php endif; ?>
						<div class="osb-field osb-field--half"><label for="osb_note"><?php esc_html_e( 'Note', 'osam-bookings' ); ?></label><input type="text" id="osb_note" name="note" value="<?php echo esc_attr( $c->note ); ?>" placeholder="<?php esc_attr_e( 'Only you see this', 'osam-bookings' ); ?>"></div>
					</div>
					<label class="osb-switch"><input type="hidden" name="status" value="disabled"><input type="checkbox" name="status" value="active" <?php checked( 'active', $c->status ); ?>><span></span><?php esc_html_e( 'Can be used', 'osam-bookings' ); ?></label>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Limits', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'All optional.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_starts"><?php esc_html_e( 'Valid from', 'osam-bookings' ); ?></label><input type="date" id="osb_starts" name="starts_at" value="<?php echo esc_attr( (string) $c->starts_at ); ?>"></div>
						<div class="osb-field osb-field--half"><label for="osb_expires"><?php esc_html_e( 'Expires after', 'osam-bookings' ); ?></label><input type="date" id="osb_expires" name="expires_at" value="<?php echo esc_attr( (string) $c->expires_at ); ?>"></div>
						<?php if ( ! $voucher ) : ?>
							<div class="osb-field osb-field--half"><label for="osb_max"><?php esc_html_e( 'Total uses allowed', 'osam-bookings' ); ?></label><input type="number" id="osb_max" name="max_uses" min="0" value="<?php echo (int) $c->max_uses; ?>"><p class="osb-help"><?php esc_html_e( '0 = unlimited.', 'osam-bookings' ); ?></p></div>
						<?php endif; ?>
						<div class="osb-field osb-field--half"><label for="osb_min"><?php esc_html_e( 'Minimum spend', 'osam-bookings' ); ?></label><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" id="osb_min" name="min_spend" min="0" step="0.01" value="<?php echo esc_attr( $c->min_spend ); ?>"></div></div>
					</div>
					<?php if ( ! $voucher ) : ?>
						<label class="osb-switch"><input type="hidden" name="once_per_customer" value="0"><input type="checkbox" name="once_per_customer" value="1" <?php checked( (int) $c->once_per_customer, 1 ); ?>><span></span><?php esc_html_e( 'Once per customer (by email address)', 'osam-bookings' ); ?></label>
						<label class="osb-switch"><input type="hidden" name="first_visit" value="0"><input type="checkbox" name="first_visit" value="1" <?php checked( (int) $c->first_visit, 1 ); ?>><span></span><?php esc_html_e( 'First visit only — customers who have never booked before', 'osam-bookings' ); ?></label>
					<?php endif; ?>
					<div class="osb-field" style="margin-top:12px"><label for="osb_applies"><?php esc_html_e( 'Can be used for', 'osam-bookings' ); ?></label>
						<select name="applies_to" id="osb_applies" class="osb-applies">
							<option value="all" <?php selected( $c->applies_to, 'all' ); ?>><?php esc_html_e( 'Anything', 'osam-bookings' ); ?></option>
							<option value="services" <?php selected( $c->applies_to, 'services' ); ?>><?php esc_html_e( 'Appointments', 'osam-bookings' ); ?></option>
							<?php if ( OSB_Settings::feature( 'events' ) || 'events' === $c->applies_to ) : ?><option value="events" <?php selected( $c->applies_to, 'events' ); ?>><?php esc_html_e( 'Events', 'osam-bookings' ); ?></option><?php endif; ?>
							<?php if ( OSB_Settings::feature( 'courses' ) || 'courses' === $c->applies_to ) : ?><option value="courses" <?php selected( $c->applies_to, 'courses' ); ?>><?php esc_html_e( 'Courses', 'osam-bookings' ); ?></option><?php endif; ?>
						</select>
					</div>
					<?php
					$lists = array(
						'services' => array( OSB_DB::services(), __( 'Only these appointments (leave empty for all)', 'osam-bookings' ) ),
						'events'   => array( OSB_DB::upcoming_events( 100 ), __( 'Only these events (leave empty for all)', 'osam-bookings' ) ),
						'courses'  => array( OSB_Courses::courses(), __( 'Only these courses (leave empty for all)', 'osam-bookings' ) ),
					);
					foreach ( $lists as $k => $l ) :
						if ( ! $l[0] ) {
							continue;
						}
						?>
						<div class="osb-field osb-applies__items" data-for="<?php echo esc_attr( $k ); ?>" <?php echo $c->applies_to === $k ? '' : 'hidden'; ?>><label><?php echo esc_html( $l[1] ); ?></label>
							<div class="osb-chips-check">
								<?php foreach ( $l[0] as $it ) : ?><label><input type="checkbox" name="items_<?php echo esc_attr( $k ); ?>[]" value="<?php echo (int) $it->id; ?>" <?php checked( $c->applies_to === $k && in_array( (int) $it->id, $items, true ) ); ?>><span><i style="background:<?php echo esc_attr( $it->color ); ?>"></i><?php echo esc_html( $it->name ); ?></span></label><?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<?php if ( $uses ) : ?>
			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Used on', 'osam-bookings' ); ?></h2></div>
				<div class="osb-card osb-card--table">
					<table class="osb-table">
						<thead><tr><th><?php esc_html_e( 'Booking', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Customer', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Amount', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Date', 'osam-bookings' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $uses as $u ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . (int) $u->booking_id ) ); ?>">#<?php echo (int) $u->booking_id; ?></a></td>
								<td><?php echo esc_html( $u->email ); ?></td>
								<td><?php echo esc_html( OSB_Settings::money( $u->amount ) ); ?><?php echo 'released' === $u->status ? ' <span class="osb-pill osb-pill--neutral">' . esc_html__( 'Given back — booking cancelled', 'osam-bookings' ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td><?php echo esc_html( mysql2date( 'j M Y H:i', $u->created_at ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif; ?>

			<div class="osb-savebar">
				<?php if ( $c->id ) : ?><button type="button" class="osb-btn osb-btn--ghost osb-copy" data-copy="<?php echo esc_attr( $c->code ); ?>"><span class="dashicons dashicons-admin-page"></span><?php esc_html_e( 'Copy code', 'osam-bookings' ); ?></button><?php endif; ?>
				<button class="osb-btn osb-btn--primary"><?php echo $c->id ? esc_html__( 'Save', 'osam-bookings' ) : ( $voucher ? esc_html__( 'Issue voucher', 'osam-bookings' ) : esc_html__( 'Add code', 'osam-bookings' ) ); ?></button>
			</div>
		</form>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Save.
	 */
	public static function save() {
		OSB_Admin::verify( 'osb_save_coupon' );
		$post    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id      = absint( isset( $post['id'] ) ? $post['id'] : 0 );
		$old     = $id ? OSB_DB::get( 'coupons', $id ) : null;
		$kind    = $old ? $old->kind : ( isset( $post['kind'] ) && 'voucher' === $post['kind'] ? 'voucher' : 'discount' );
		$code    = OSB_Coupons::normalise( isset( $post['code'] ) ? $post['code'] : '' );
		$back    = $id ? self::url( array( 'action' => 'edit', 'id' => $id ) ) : self::url( array( 'action' => 'new', 'kind' => $kind ) );
		$dupe    = OSB_Coupons::find( $code );
		$date    = static function ( $k ) use ( $post ) {
			return isset( $post[ $k ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $post[ $k ] ) ? $post[ $k ] : null;
		};
		$applies = isset( $post['applies_to'] ) && in_array( $post['applies_to'], array( 'all', 'services', 'events', 'courses' ), true ) ? $post['applies_to'] : 'all';
		$items   = 'all' === $applies ? array() : array_map( 'absint', (array) ( isset( $post[ 'items_' . $applies ] ) ? $post[ 'items_' . $applies ] : array() ) );

		if ( '' === $code ) {
			OSB_Admin::flash_error( __( 'Please enter a code.', 'osam-bookings' ) );
			OSB_Admin::redirect( $back, 'error' );
		}
		if ( $dupe && (int) $dupe->id !== $id ) {
			OSB_Admin::flash_error( __( 'That code is already in use — please choose another.', 'osam-bookings' ) );
			OSB_Admin::redirect( $back, 'error' );
		}
		$amount = max( 0, (float) ( isset( $post['amount'] ) ? $post['amount'] : 0 ) );
		$type   = 'voucher' === $kind ? 'fixed' : ( isset( $post['type'] ) && 'fixed' === $post['type'] ? 'fixed' : 'percent' );
		if ( 'percent' === $type ) {
			$amount = min( 100, $amount );
		}
		$data = array(
			'code'              => $code,
			'kind'              => $kind,
			'type'              => $type,
			'amount'            => $amount,
			'applies_to'        => $applies,
			'item_ids'          => implode( ',', array_filter( $items ) ),
			'min_spend'         => max( 0, (float) ( isset( $post['min_spend'] ) ? $post['min_spend'] : 0 ) ),
			'max_uses'          => 'voucher' === $kind ? 0 : absint( isset( $post['max_uses'] ) ? $post['max_uses'] : 0 ),
			'once_per_customer' => empty( $post['once_per_customer'] ) ? 0 : 1,
			'first_visit'       => empty( $post['first_visit'] ) ? 0 : 1,
			'starts_at'         => $date( 'starts_at' ),
			'expires_at'        => $date( 'expires_at' ),
			'status'            => isset( $post['status'] ) && 'active' === $post['status'] ? 'active' : 'disabled',
			'recipient'         => sanitize_text_field( isset( $post['recipient'] ) ? $post['recipient'] : '' ),
			'note'              => sanitize_text_field( isset( $post['note'] ) ? $post['note'] : '' ),
		);
		if ( 'voucher' === $kind ) {
			if ( $old ) {
				$data['balance'] = isset( $post['balance'] ) ? min( $amount, max( 0, (float) $post['balance'] ) ) : (float) $old->balance;
			} else {
				$data['balance'] = $amount;
			}
		}
		if ( $old ) {
			OSB_DB::update( 'coupons', $id, $data );
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$id                 = OSB_DB::insert( 'coupons', $data );
		}
		do_action( 'osb_coupon_saved', $id, $data );
		OSB_Admin::redirect( self::url( array( 'action' => 'edit', 'id' => $id ) ) );
	}

	/**
	 * Delete (codes that have been used are switched off instead, to keep the history).
	 */
	public static function delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_delete_coupon_' . $id );
		$c = OSB_DB::get( 'coupons', $id );
		if ( ! $c ) {
			OSB_Admin::redirect( self::url(), 'error' );
		}
		$back = self::url( 'voucher' === $c->kind ? array( 'kind' => 'voucher' ) : array() );
		if ( OSB_Coupons::uses( $id ) ) {
			OSB_DB::update( 'coupons', $id, array( 'status' => 'disabled' ) );
			OSB_Admin::flash_warnings( array( __( 'This code has been used, so it was switched off instead of deleted (bookings still show it).', 'osam-bookings' ) ) );
			OSB_Admin::redirect( $back );
		}
		OSB_DB::delete( 'coupons', $id );
		OSB_Admin::redirect( $back, 'deleted' );
	}
}
