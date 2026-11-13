<?php
/**
 * Bookings admin: list, view/edit, manual booking (with override warnings), calendar, CSV export.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bookings screens.
 */
class OSB_Admin_Bookings {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_update_booking', array( __CLASS__, 'update' ) );
		add_action( 'admin_post_osb_create_booking', array( __CLASS__, 'create' ) );
		add_action( 'admin_post_osb_bulk_bookings', array( __CLASS__, 'bulk' ) );
		add_action( 'admin_post_osb_export_bookings', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_osb_resend_email', array( __CLASS__, 'resend' ) );
		add_action( 'admin_post_osb_delete_booking', array( __CLASS__, 'delete' ) );
	}

	/**
	 * Router.
	 */
	public static function page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		// phpcs:enable
		if ( 'view' === $action && $id ) {
			self::view( $id );
		} elseif ( 'new' === $action ) {
			self::create_form();
		} else {
			self::listing();
		}
	}

	/**
	 * Current list filters from the query string.
	 *
	 * @return array
	 */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$g = wp_unslash( $_GET );
		return array(
			'status'     => isset( $g['status'] ) ? sanitize_key( $g['status'] ) : '',
			'staff_id'   => isset( $g['staff_id'] ) ? absint( $g['staff_id'] ) : 0,
			'service_id' => isset( $g['service_id'] ) ? absint( $g['service_id'] ) : 0,
			'event_id'   => isset( $g['event_id'] ) ? absint( $g['event_id'] ) : 0,
			'course_id'  => isset( $g['course_id'] ) ? absint( $g['course_id'] ) : 0,
			'intake_id'  => isset( $g['intake_id'] ) ? absint( $g['intake_id'] ) : 0,
			'from'       => isset( $g['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $g['from'] ) ? $g['from'] : '',
			'to'         => isset( $g['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $g['to'] ) ? $g['to'] : '',
			'search'     => isset( $g['s'] ) ? sanitize_text_field( $g['s'] ) : '',
			'orderby'    => isset( $g['orderby'] ) ? sanitize_key( $g['orderby'] ) : 'start_at',
			'order'      => isset( $g['order'] ) ? sanitize_key( $g['order'] ) : 'desc',
			'page'       => isset( $g['paged'] ) ? absint( $g['paged'] ) : 1,
			'per_page'   => 25,
		);
		// phpcs:enable
	}

	/**
	 * Status counts for the filter tabs.
	 *
	 * @return array status => count
	 */
	private static function status_counts() {
		global $wpdb;
		$t   = OSB_Install::table( 'bookings' );
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) c FROM $t GROUP BY status" ) as $r ) {
			$out[ $r->status ] = (int) $r->c;
		}
		return $out;
	}

	/**
	 * List screen.
	 */
	private static function listing() {
		$f                    = self::filters();
		list( $rows, $total ) = OSB_DB::query_bookings( $f );
		$names                = OSB_Admin_Dashboard::name_maps();
		$pages                = max( 1, (int) ceil( $total / $f['per_page'] ) );
		$base                 = admin_url( 'admin.php?page=osb-bookings' );
		$statuses             = OSB_Bookings::statuses();
		$counts               = self::status_counts();
		$export               = wp_nonce_url( add_query_arg( array_filter( $f ), admin_url( 'admin-post.php?action=osb_export_bookings' ) ), 'osb_export_bookings' );
		$solo                 = OSB_DB::is_solo();
		$filtered             = $f['service_id'] || $f['event_id'] || $f['course_id'] || $f['intake_id'] || $f['staff_id'] || $f['from'] || $f['to'] || $f['search'];

		OSB_Admin::open(
			__( 'Bookings', 'osam-bookings' ),
			array(
				'subtitle' => __( 'Every appointment, event and course booking. Click a row to manage it.', 'osam-bookings' ),
				'actions'  => array(
					array( __( 'Export CSV', 'osam-bookings' ), $export, 'secondary', 'dashicons-download' ),
					array( __( 'Add booking', 'osam-bookings' ), admin_url( 'admin.php?page=osb-bookings&action=new' ), 'primary', 'dashicons-plus-alt2' ),
				),
			)
		);
		?>
		<div class="osb-segmented" role="tablist">
			<a href="<?php echo esc_url( $base ); ?>" class="<?php echo $f['status'] ? '' : 'is-active'; ?>"><?php esc_html_e( 'All', 'osam-bookings' ); ?> <span><?php echo (int) array_sum( array_diff_key( $counts, array_flip( OSB_Bookings::hidden_statuses() ) ) ); ?></span></a>
			<?php foreach ( $statuses as $key => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'status', $key, $base ) ); ?>" class="<?php echo $f['status'] === $key ? 'is-active' : ''; ?>"><?php echo esc_html( $label ); ?> <span><?php echo isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0; ?></span></a>
			<?php endforeach; ?>
		</div>

		<form method="get" class="osb-toolbar">
			<input type="hidden" name="page" value="osb-bookings">
			<?php if ( $f['status'] ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $f['status'] ); ?>"><?php endif; ?>
			<div class="osb-search"><span class="dashicons dashicons-search"></span><input type="search" name="s" value="<?php echo esc_attr( $f['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, phone or #', 'osam-bookings' ); ?>"></div>
			<select name="service_id"><option value=""><?php esc_html_e( 'All services', 'osam-bookings' ); ?></option>
				<?php foreach ( $names['services'] as $s ) : ?><option value="<?php echo (int) $s->id; ?>" <?php selected( $f['service_id'], (int) $s->id ); ?>><?php echo esc_html( $s->name ); ?></option><?php endforeach; ?>
			</select>
			<?php if ( $names['events'] && OSB_Settings::feature( 'events' ) ) : ?>
			<select name="event_id"><option value=""><?php esc_html_e( 'All events', 'osam-bookings' ); ?></option>
				<?php foreach ( $names['events'] as $e ) : ?><option value="<?php echo (int) $e->id; ?>" <?php selected( $f['event_id'], (int) $e->id ); ?>><?php echo esc_html( $e->name . ' — ' . mysql2date( 'j M', $e->start_at ) ); ?></option><?php endforeach; ?>
			</select>
			<?php endif; ?>
			<?php if ( $names['courses'] && OSB_Settings::feature( 'courses' ) ) : ?>
			<select name="course_id"><option value=""><?php esc_html_e( 'All courses', 'osam-bookings' ); ?></option>
				<?php foreach ( $names['courses'] as $co ) : ?><option value="<?php echo (int) $co->id; ?>" <?php selected( $f['course_id'], (int) $co->id ); ?>><?php echo esc_html( $co->name ); ?></option><?php endforeach; ?>
			</select>
			<?php endif; ?>
			<?php if ( $f['intake_id'] ) : ?><input type="hidden" name="intake_id" value="<?php echo (int) $f['intake_id']; ?>"><?php endif; ?>
			<?php if ( ! $solo ) : ?>
			<select name="staff_id"><option value=""><?php esc_html_e( 'All staff', 'osam-bookings' ); ?></option>
				<?php foreach ( $names['staff'] as $sid => $sname ) : ?><option value="<?php echo (int) $sid; ?>" <?php selected( $f['staff_id'], (int) $sid ); ?>><?php echo esc_html( $sname ); ?></option><?php endforeach; ?>
			</select>
			<?php endif; ?>
			<label class="osb-daterange"><input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>" aria-label="<?php esc_attr_e( 'From', 'osam-bookings' ); ?>"><span>→</span><input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>" aria-label="<?php esc_attr_e( 'To', 'osam-bookings' ); ?>"></label>
			<button class="osb-btn osb-btn--ghost"><?php esc_html_e( 'Filter', 'osam-bookings' ); ?></button>
			<?php if ( $filtered ) : ?><a class="osb-link" href="<?php echo esc_url( $f['status'] ? add_query_arg( 'status', $f['status'], $base ) : $base ); ?>"><?php esc_html_e( 'Clear', 'osam-bookings' ); ?></a><?php endif; ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-card--table">
			<?php wp_nonce_field( 'osb_bulk_bookings' ); ?>
			<input type="hidden" name="action" value="osb_bulk_bookings">
			<input type="hidden" name="_redirect" value="<?php echo esc_url( remove_query_arg( 'osb_msg' ) ); ?>">
			<div class="osb-bulkbar" hidden>
				<span class="osb-bulkbar__count"></span>
				<select name="bulk_status">
					<option value=""><?php esc_html_e( 'Change status to…', 'osam-bookings' ); ?></option>
					<?php foreach ( $statuses as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select>
				<label><input type="checkbox" name="notify" value="1" checked> <?php esc_html_e( 'Email customers', 'osam-bookings' ); ?></label>
				<button class="osb-btn osb-btn--primary osb-btn--sm"><?php esc_html_e( 'Apply', 'osam-bookings' ); ?></button>
			</div>

			<?php if ( ! $rows ) : ?>
				<?php
				OSB_Admin::empty_state(
					'dashicons-calendar',
					$filtered || $f['status'] ? __( 'No bookings match', 'osam-bookings' ) : __( 'No bookings yet', 'osam-bookings' ),
					$filtered || $f['status'] ? __( 'Try clearing the filters.', 'osam-bookings' ) : __( 'Bookings made on your site, or added here, will appear in this list.', 'osam-bookings' ),
					array( __( 'Add a booking', 'osam-bookings' ), admin_url( 'admin.php?page=osb-bookings&action=new' ) )
				);
				?>
			<?php else : ?>
			<table class="osb-table osb-table--click">
				<thead><tr>
					<th class="osb-col-check"><input type="checkbox" class="osb-check-all" aria-label="<?php esc_attr_e( 'Select all', 'osam-bookings' ); ?>"></th>
					<th><?php self::sort_link( 'start_at', __( 'When', 'osam-bookings' ), $f ); ?></th>
					<th><?php esc_html_e( 'Customer', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Booked for', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Payment', 'osam-bookings' ); ?></th>
					<th><?php self::sort_link( 'status', __( 'Status', 'osam-bookings' ), $f ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $rows as $b ) :
					$view = admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $b->id );
					?>
					<tr data-href="<?php echo esc_url( $view ); ?>">
						<td class="osb-col-check"><input type="checkbox" name="ids[]" value="<?php echo (int) $b->id; ?>" aria-label="<?php echo esc_attr( '#' . $b->id ); ?>"></td>
						<td class="osb-when">
							<span class="osb-datebadge osb-datebadge--plain"><b><?php echo esc_html( mysql2date( 'j', $b->start_at ) ); ?></b><?php echo esc_html( mysql2date( 'M', $b->start_at ) ); ?></span>
							<span><strong><?php echo esc_html( mysql2date( 'D', $b->start_at ) . ' ' . mysql2date( get_option( 'time_format' ), $b->start_at ) ); ?></strong><small>#<?php echo (int) $b->id; ?> · <?php echo esc_html( mysql2date( 'Y', $b->start_at ) ); ?></small></span>
						</td>
						<td><a class="osb-rowlink" href="<?php echo esc_url( $view ); ?>"><strong><?php echo esc_html( $b->customer_name ); ?></strong></a><small><?php echo esc_html( $b->customer_email ); ?></small></td>
						<td><span class="osb-dot" style="background:<?php echo esc_attr( OSB_Admin_Dashboard::item_color( $b, $names ) ); ?>"></span><?php echo esc_html( OSB_Admin_Dashboard::item_name( $b, $names ) ); ?><?php echo $b->seats > 1 ? ' <span class="osb-muted">×' . (int) $b->seats . '</span>' : ''; ?><?php echo 'course' === $b->type ? ' <span class="osb-pill osb-pill--neutral">' . esc_html__( 'Course', 'osam-bookings' ) . '</span>' : ( 'table' === $b->type ? ' <span class="osb-pill osb-pill--neutral">' . esc_html__( 'Table', 'osam-bookings' ) . '</span>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php if ( ! $solo && isset( $names['staff'][ (int) $b->staff_id ] ) ) : ?><small><?php echo esc_html( $names['staff'][ (int) $b->staff_id ] ); ?></small><?php endif; ?></td>
						<td><strong><?php echo esc_html( OSB_Settings::money( $b->amount, $b->currency ) ); ?></strong><small><?php echo esc_html( self::payment_label( $b ) ); ?></small></td>
						<td><?php echo OSB_Admin::status_pill( $b->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="osb-tablefoot">
				<span><?php /* translators: %d count */ echo esc_html( sprintf( _n( '%d booking', '%d bookings', $total, 'osam-bookings' ), $total ) ); ?></span>
				<span class="osb-pager">
				<?php
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $f['page'],
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				?>
				</span>
			</div>
			<?php endif; ?>
		</form>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Sortable header link.
	 *
	 * @param string $col   Column.
	 * @param string $label Label.
	 * @param array  $f     Filters.
	 */
	private static function sort_link( $col, $label, $f ) {
		$order = ( $f['orderby'] === $col && 'asc' === $f['order'] ) ? 'desc' : 'asc';
		$arrow = $f['orderby'] === $col ? ( 'asc' === $f['order'] ? ' ↑' : ' ↓' ) : '';
		printf( '<a href="%s">%s%s</a>', esc_url( add_query_arg( array( 'orderby' => $col, 'order' => $order ) ) ), esc_html( $label ), esc_html( $arrow ) );
	}

	/**
	 * "Stripe · Paid".
	 *
	 * @param object $b Booking.
	 * @return string
	 */
	private static function payment_label( $b ) {
		$g      = osb()->payments->get( $b->payment_method );
		$method = 'free' === $b->payment_method ? __( 'Free', 'osam-bookings' ) : ( $g ? $g->admin_name() : ( 'manual' === $b->payment_method ? __( 'Manual', 'osam-bookings' ) : ( 'bundle' === $b->payment_method ? __( 'Bundle', 'osam-bookings' ) : $b->payment_method ) ) );
		$ps     = OSB_Bookings::payment_statuses();
		return $method . ' · ' . ( isset( $ps[ $b->payment_status ] ) ? $ps[ $b->payment_status ] : $b->payment_status );
	}

	/**
	 * Single booking.
	 *
	 * @param int $id Booking.
	 */
	private static function view( $id ) {
		$b = OSB_DB::get( 'bookings', $id );
		if ( ! $b ) {
			OSB_Admin::open( __( 'Booking not found', 'osam-bookings' ), array( 'back' => admin_url( 'admin.php?page=osb-bookings' ), 'back_label' => __( 'All bookings', 'osam-bookings' ) ) );
			OSB_Admin::close();
			return;
		}
		$d        = OSB_Bookings::details( $b );
		$fields   = OSB_Bookings::fields( $b );
		$payments = OSB_DB::payments( $b->id );
		$staff    = OSB_DB::staff();
		$solo     = OSB_DB::is_solo();
		$del      = wp_nonce_url( admin_url( 'admin-post.php?action=osb_delete_booking&id=' . $b->id ), 'osb_delete_booking_' . $b->id );
		$warnings = in_array( $b->status, array( 'cancelled', 'completed', 'no_show' ), true ) ? array() : OSB_Availability::conflicts(
			array(
				'type'       => $b->type,
				'service_id' => $b->service_id,
				'staff_id'   => $b->staff_id,
				'event_id'   => $b->event_id,
				'intake_id'  => $b->intake_id,
				'date'       => substr( $b->start_at, 0, 10 ),
				'time'       => substr( $b->start_at, 11, 5 ),
				'seats'      => $b->seats,
				'exclude'    => $b->id,
			)
		);
		$warnings = array_values(
			array_filter(
				$warnings,
				static function ( $w ) {
					return false === strpos( $w, __( 'This time is in the past.', 'osam-bookings' ) ) && false === strpos( $w, __( 'This event has already started or finished.', 'osam-bookings' ) ) && false === strpos( $w, __( 'This course has already started — the customer will have missed earlier sessions.', 'osam-bookings' ) );
				}
			)
		);

		OSB_Admin::open(
			/* translators: %s customer */
			sprintf( __( 'Booking for %s', 'osam-bookings' ), $b->customer_name ),
			array(
				'back'        => admin_url( 'admin.php?page=osb-bookings' ),
				'back_label'  => __( 'All bookings', 'osam-bookings' ),
				'after_title' => ' ' . OSB_Admin::status_pill( $b->status ),
				/* translators: 1: id 2: date */
				'subtitle'    => esc_html( sprintf( __( 'Reference #%1$d · booked %2$s', 'osam-bookings' ), $b->id, mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $b->created_at ) ) ),
			)
		);
		?>
		<?php if ( $warnings ) : ?>
			<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div><strong><?php esc_html_e( 'Heads up', 'osam-bookings' ); ?></strong><ul><?php foreach ( $warnings as $w ) : ?><li><?php echo esc_html( $w ); ?></li><?php endforeach; ?></ul></div></div>
		<?php endif; ?>

		<div class="osb-grid-main">
			<div class="osb-stack">
				<div class="osb-card osb-booking-hero">
					<span class="osb-datebadge" style="background:<?php echo esc_attr( OSB_Admin_Dashboard::item_color( $b, OSB_Admin_Dashboard::name_maps() ) ); ?>"><b><?php echo esc_html( mysql2date( 'j', $b->start_at ) ); ?></b><?php echo esc_html( mysql2date( 'M', $b->start_at ) ); ?></span>
					<div>
						<h2><?php echo esc_html( $d['item_name'] ); ?><?php echo $b->seats > 1 ? ' <span class="osb-muted">× ' . (int) $b->seats . '</span>' : ''; ?></h2>
						<?php if ( 'course' === $b->type ) : ?>
							<p><?php echo esc_html( $d['intake_name'] . ' · ' . sprintf( /* translators: %d sessions */ _n( '%d session', '%d sessions', $d['session_count'], 'osam-bookings' ), $d['session_count'] ) ); ?></p>
							<p class="osb-muted"><?php /* translators: %s date */ echo esc_html( sprintf( __( 'Starts %s', 'osam-bookings' ), mysql2date( 'l', $b->start_at ) . ', ' . $d['date'] . ' · ' . $d['time'] ) ); ?></p>
						<?php else : ?>
							<p><?php echo esc_html( mysql2date( 'l', $b->start_at ) . ', ' . $d['date'] . ' · ' . $d['time'] . ' – ' . $d['end_time'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! $solo && $b->staff_id && isset( OSB_Admin_Dashboard::name_maps()['staff'][ (int) $b->staff_id ] ) ) : ?><p class="osb-muted"><?php /* translators: %s staff */ echo esc_html( sprintf( __( 'With %s', 'osam-bookings' ), OSB_Admin_Dashboard::name_maps()['staff'][ (int) $b->staff_id ] ) ); ?></p><?php endif; ?>
						<?php if ( $d['location'] ) : ?><p class="osb-muted"><?php echo esc_html( $d['location'] ); ?></p><?php endif; ?>
					</div>
					<div class="osb-booking-hero__amount">
						<strong><?php echo esc_html( $d['amount'] ); ?></strong>
						<small><?php echo esc_html( self::payment_label( $b ) ); ?></small>
						<?php if ( (float) $b->discount > 0 ) : ?><small><?php /* translators: 1: amount 2: code */ echo esc_html( sprintf( __( '−%1$s with %2$s', 'osam-bookings' ), OSB_Settings::money( $b->discount, $b->currency ), $b->coupon_code ) ); ?></small><?php endif; ?>
						<?php if ( $b->meeting_url ) : ?><a href="<?php echo esc_url( $b->meeting_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Online meeting link', 'osam-bookings' ); ?> ↗</a><?php endif; ?>
						<?php if ( $b->wc_order_id ) : ?><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $b->wc_order_id . '&action=edit' ) ); ?>"><?php /* translators: %d order */ echo esc_html( sprintf( __( 'Order #%d', 'osam-bookings' ), $b->wc_order_id ) ); ?></a><?php endif; ?>
					</div>
				</div>

				<?php
				$meet_errors = OSB_Settings::feature( 'meetings' ) ? OSB_Meetings::errors( $b->id ) : array();
				if ( $meet_errors ) :
					?>
					<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-video-alt2"></span><div><strong><?php echo $b->meeting_url ? esc_html__( 'The online meeting may be out of date', 'osam-bookings' ) : esc_html__( 'No online meeting link', 'osam-bookings' ); ?></strong><p><?php echo esc_html( $meet_errors[0]['message'] ); ?></p></div></div>
				<?php endif; ?>

				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Customer', 'osam-bookings' ) ); ?>
					<dl class="osb-dl">
						<?php
						$item_id = 'event' === $b->type ? (int) $b->event_id : ( 'course' === $b->type ? (int) $b->course_id : (int) $b->service_id );
						foreach ( OSB_Settings::form_fields() as $field ) :
							$v = isset( $fields[ $field['key'] ] ) ? $fields[ $field['key'] ] : '';
							if ( '' === (string) $v && ! OSB_Settings::field_applies( $field, $b->type, $item_id ) ) {
								continue; // Not asked for this kind of booking.
							}
							if ( 'checkbox' === $field['type'] ) {
								$v = $v ? __( 'Yes', 'osam-bookings' ) : __( 'No', 'osam-bookings' );
							}
							?>
							<dt><?php echo esc_html( $field['label'] ); ?></dt>
							<dd>
								<?php
								if ( '' === (string) $v ) {
									echo '<span class="osb-muted">—</span>';
								} elseif ( 'email' === $field['type'] ) {
									echo '<a href="mailto:' . esc_attr( $v ) . '">' . esc_html( $v ) . '</a>';
								} elseif ( 'tel' === $field['type'] ) {
									echo '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $v ) ) . '">' . esc_html( $v ) . '</a>';
								} else {
									echo nl2br( esc_html( (string) $v ) );
								}
								?>
							</dd>
						<?php endforeach; ?>
					</dl>
				</div>

				<?php if ( (int) $b->seats > 1 ) : ?>
					<?php
					$osb_people = OSB_Bookings::attendees( $b );
					$osb_gf     = array();
					foreach ( OSB_Settings::guest_fields( $b->type, $item_id ) as $gf ) {
						$osb_gf[ $gf['key'] ] = $gf;
					}
					?>
					<div class="osb-card osb-card--table">
						<?php /* translators: %d places */ OSB_Admin::card_head( sprintf( __( 'Attendees (%d places)', 'osam-bookings' ), (int) $b->seats ) ); ?>
						<table class="osb-table">
							<thead><tr><th>#</th><th><?php esc_html_e( 'Name', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Details', 'osam-bookings' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $osb_people as $p ) : ?>
								<tr>
									<td class="osb-muted"><?php echo (int) $p['place']; ?></td>
									<td>
										<?php if ( $p['same'] ) : ?>
											<span class="osb-muted"><?php /* translators: %s booker name */ echo esc_html( sprintf( __( 'Same as %s', 'osam-bookings' ), $b->customer_name ) ); ?></span>
										<?php else : ?>
											<strong><?php echo esc_html( $p['name'] ); ?></strong><?php echo 1 === $p['place'] ? ' <span class="osb-pill osb-pill--neutral">' . esc_html__( 'Booked by', 'osam-bookings' ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<?php if ( $p['email'] ) : ?><small><a href="mailto:<?php echo esc_attr( $p['email'] ); ?>"><?php echo esc_html( $p['email'] ); ?></a></small><?php endif; ?>
										<?php endif; ?>
									</td>
									<td>
										<?php
										$bits = array();
										foreach ( $osb_gf as $k => $gf ) {
											if ( in_array( $k, array( 'name', 'email' ), true ) || ! isset( $p['fields'][ $k ] ) || '' === (string) $p['fields'][ $k ] ) {
												continue;
											}
											$val    = 'checkbox' === $gf['type'] ? ( $p['fields'][ $k ] ? __( 'Yes', 'osam-bookings' ) : __( 'No', 'osam-bookings' ) ) : (string) $p['fields'][ $k ];
											$bits[] = '<span class="osb-muted">' . esc_html( $gf['label'] ) . ':</span> ' . esc_html( $val );
										}
										echo $bits ? implode( '<br>', $bits ) : '<span class="osb-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<?php if ( 'course' === $b->type ) : ?>
					<?php
					$att    = OSB_Courses::attendance_for_booking( $b->id );
					$alabel = OSB_Courses::attendance_statuses();
					?>
					<div class="osb-card osb-card--table">
						<?php OSB_Admin::card_head( __( 'Course dates', 'osam-bookings' ), '<a href="' . esc_url( admin_url( 'admin.php?page=osb-courses&action=register&id=' . (int) $b->intake_id ) ) . '">' . esc_html__( 'Open the class register', 'osam-bookings' ) . ' →</a>' ); ?>
						<?php if ( ! $d['schedule'] ) : ?>
							<p class="osb-muted osb-pad"><?php esc_html_e( 'This intake has no sessions.', 'osam-bookings' ); ?></p>
						<?php else : ?>
						<table class="osb-table">
							<thead><tr><th>#</th><th><?php esc_html_e( 'Date', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Time', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Location', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Attendance', 'osam-bookings' ); ?></th></tr></thead>
							<?php
							foreach ( $d['schedule'] as $sess ) :
								$st = isset( $att[ $sess['id'] ] ) ? $att[ $sess['id'] ]->status : 'booked';
								?>
								<tr><td><?php echo (int) $sess['number']; ?></td><td><?php echo esc_html( $sess['date_label'] ); ?></td><td><?php echo esc_html( $sess['time_label'] ); ?></td><td><?php echo esc_html( $sess['location'] ); ?></td><td><span class="osb-mark osb-mark--<?php echo esc_attr( $st ); ?> osb-mark--static"></span> <?php echo esc_html( isset( $alabel[ $st ] ) ? $alabel[ $st ] : $st ); ?></td></tr>
							<?php endforeach; ?>
						</table>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $payments ) : ?>
				<div class="osb-card osb-card--table">
					<?php OSB_Admin::card_head( __( 'Payments', 'osam-bookings' ) ); ?>
					<table class="osb-table">
						<thead><tr><th><?php esc_html_e( 'Date', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Method', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Reference', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Amount', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th></tr></thead>
						<?php foreach ( $payments as $p ) : ?>
							<tr><td><?php echo esc_html( mysql2date( 'j M Y H:i', $p->created_at ) ); ?></td><td><?php echo esc_html( ucfirst( $p->gateway ) ); ?></td><td><code><?php echo esc_html( $p->transaction_id ); ?></code></td><td><?php echo esc_html( OSB_Settings::money( $p->amount, $p->currency ) ); ?></td><td><?php echo esc_html( ucfirst( $p->status ) ); ?></td></tr>
						<?php endforeach; ?>
					</table>
				</div>
				<?php endif; ?>
			</div>

			<div class="osb-stack">
				<?php do_action( 'osb_admin_booking_side', $b ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form osb-checkable" data-type="<?php echo esc_attr( $b->type ); ?>" data-exclude="<?php echo (int) $b->id; ?>">
					<?php wp_nonce_field( 'osb_update_booking_' . $b->id ); ?>
					<input type="hidden" name="action" value="osb_update_booking">
					<input type="hidden" name="id" value="<?php echo (int) $b->id; ?>">
					<input type="hidden" name="service_id" value="<?php echo (int) $b->service_id; ?>">
					<input type="hidden" name="event_id" value="<?php echo (int) $b->event_id; ?>">
					<input type="hidden" name="intake_id" value="<?php echo (int) $b->intake_id; ?>">
					<input type="hidden" name="seats" value="<?php echo (int) $b->seats; ?>">
					<?php OSB_Admin::card_head( __( 'Manage', 'osam-bookings' ) ); ?>
					<div class="osb-field"><label><?php esc_html_e( 'Status', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'status', $b->status, 'select', OSB_Bookings::statuses() ); ?></div>
					<label class="osb-switch"><input type="checkbox" name="notify" value="1" checked><span></span><?php esc_html_e( 'Email the customer about status changes', 'osam-bookings' ); ?></label>
					<div class="osb-field"><label><?php esc_html_e( 'Payment', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'payment_status', $b->payment_status, 'select', OSB_Bookings::payment_statuses() ); ?></div>
					<?php if ( 'service' === $b->type ) : ?>
						<div class="osb-subsection">
							<h3><?php esc_html_e( 'Reschedule', 'osam-bookings' ); ?></h3>
							<div class="osb-inline">
								<input type="date" name="date" value="<?php echo esc_attr( substr( $b->start_at, 0, 10 ) ); ?>" data-check>
								<input type="time" name="time" value="<?php echo esc_attr( substr( $b->start_at, 11, 5 ) ); ?>" data-check>
							</div>
							<?php if ( ! $solo ) : ?>
								<select name="staff_id" data-check>
									<?php foreach ( $staff as $st ) : ?><option value="<?php echo (int) $st->id; ?>" <?php selected( (int) $b->staff_id, (int) $st->id ); ?>><?php echo esc_html( $st->name ); ?></option><?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="hidden" name="staff_id" value="<?php echo (int) $b->staff_id; ?>">
							<?php endif; ?>
							<div class="osb-live-warnings" aria-live="polite"></div>
							<p class="osb-help"><?php esc_html_e( 'As an admin you can choose any time — you’ll be warned about clashes but not stopped.', 'osam-bookings' ); ?></p>
						</div>
					<?php endif; ?>
					<?php if ( OSB_Settings::feature( 'meetings' ) && ( $b->meeting_url || OSB_Meetings::is_online( OSB_Meetings::item_for( $b ) ) ) ) : ?>
						<div class="osb-field"><label for="osb_meeting_url"><?php esc_html_e( 'Online meeting link', 'osam-bookings' ); ?></label><input type="url" id="osb_meeting_url" name="meeting_url" value="<?php echo esc_attr( (string) $b->meeting_url ); ?>" placeholder="https://"><p class="osb-help"><?php esc_html_e( 'Made automatically. Change it to send the customer somewhere else.', 'osam-bookings' ); ?></p></div>
					<?php endif; ?>
					<div class="osb-field"><label><?php esc_html_e( 'Internal note', 'osam-bookings' ); ?></label><textarea name="internal_note" rows="3" placeholder="<?php esc_attr_e( 'Only visible to staff', 'osam-bookings' ); ?>"><?php echo esc_textarea( (string) $b->internal_note ); ?></textarea></div>
					<div class="osb-formfoot">
						<a href="<?php echo esc_url( $del ); ?>" class="osb-link osb-link--danger osb-delete"><?php esc_html_e( 'Delete', 'osam-bookings' ); ?></a>
						<button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Save changes', 'osam-bookings' ); ?></button>
					</div>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form">
					<?php wp_nonce_field( 'osb_resend_email_' . $b->id ); ?>
					<input type="hidden" name="action" value="osb_resend_email">
					<input type="hidden" name="id" value="<?php echo (int) $b->id; ?>">
					<?php OSB_Admin::card_head( __( 'Send an email', 'osam-bookings' ) ); ?>
					<?php
					$labels = OSB_Settings::email_labels();
					if ( $solo ) {
						unset( $labels['staff_new'], $labels['staff_cancelled'] );
					}
					if ( 'course' !== $b->type ) {
						unset( $labels['customer_session_reminder'], $labels['customer_schedule_changed'] );
					}
					foreach ( array_keys( $labels ) as $lk ) {
						if ( ! OSB_Settings::email_available( $lk ) ) {
							unset( $labels[ $lk ] ); // Belongs to a part of the plugin that's switched off.
						}
					}
					?>
					<?php $customs = OSB_Custom_Emails::all(); ?>
					<div class="osb-inline">
						<select name="template" aria-label="<?php esc_attr_e( 'Email to send', 'osam-bookings' ); ?>">
							<?php if ( $customs ) : ?><optgroup label="<?php esc_attr_e( 'Standard emails', 'osam-bookings' ); ?>"><?php endif; ?>
							<?php foreach ( $labels as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( 'customer_confirmed', $k ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
							<?php if ( $customs ) : ?>
								</optgroup><optgroup label="<?php esc_attr_e( 'Custom emails', 'osam-bookings' ); ?>">
								<?php foreach ( $customs as $cid => $cdef ) : ?><option value="custom:<?php echo esc_attr( $cid ); ?>"><?php echo esc_html( $cdef['name'] . ( $cdef['enabled'] ? '' : ' (' . __( 'off', 'osam-bookings' ) . ')' ) ); ?></option><?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
						</select>
						<button class="osb-btn osb-btn--ghost"><?php esc_html_e( 'Send', 'osam-bookings' ); ?></button>
					</div>
					<p class="osb-help"><a href="<?php echo esc_url( $d['manage_url'] ); ?>" target="_blank"><?php esc_html_e( 'Open the customer’s manage-booking page', 'osam-bookings' ); ?> ↗</a></p>
				</form>

				<?php $history = OSB_Emails::history( (int) $b->id ); ?>
				<div class="osb-card osb-emailhistory">
					<?php OSB_Admin::card_head( __( 'Emails sent', 'osam-bookings' ) ); ?>
					<?php if ( ! $history ) : ?>
						<p class="osb-muted"><?php esc_html_e( 'None recorded yet.', 'osam-bookings' ); ?></p>
					<?php else : ?>
						<ul class="osb-emailhistory__list">
							<?php
							$elabels = OSB_Settings::email_labels();
							foreach ( $history as $h ) :
								if ( 0 === strpos( $h->email_key, 'custom_' ) ) {
									$cdef = OSB_Custom_Emails::get( substr( $h->email_key, 7 ) );
									$name = $cdef ? $cdef['name'] : __( 'Custom email (deleted)', 'osam-bookings' );
								} else {
									$name = isset( $elabels[ $h->email_key ] ) ? preg_replace( '/^[^—]+—\s*/u', '', $elabels[ $h->email_key ] ) : $h->email_key;
								}
								?>
								<li class="<?php echo $h->sent ? '' : 'is-failed'; ?>">
									<span class="dashicons <?php echo $h->sent ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
									<span><strong><?php echo esc_html( $name ); ?></strong><small><?php echo esc_html( $h->recipient ); ?> · <?php echo esc_html( mysql2date( 'j M, ' . get_option( 'time_format' ), $h->created_at ) ); ?><?php echo $h->sent ? '' : ' · ' . esc_html__( 'not sent — check the site’s mail setup', 'osam-bookings' ); ?></small></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Manual booking form.
	 */
	private static function create_form() {
		$services = OSB_DB::services();
		$events   = OSB_Settings::feature( 'events' ) ? OSB_DB::events() : array();
		$staff    = OSB_DB::staff();
		$solo     = OSB_DB::is_solo();
		$intakes  = array();
		foreach ( OSB_Settings::feature( 'courses' ) ? OSB_Courses::courses() : array() as $co ) {
			foreach ( OSB_Courses::intakes( $co->id ) as $in ) {
				if ( $in->first_start ) {
					$intakes[] = array( $in, $co );
				}
			}
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$pre_type   = isset( $_GET['type'] ) && 'course' === $_GET['type'] && $intakes ? 'course' : ( isset( $_GET['type'] ) && 'event' === $_GET['type'] && $events ? 'event' : 'service' );
		$pre_intake = isset( $_GET['intake_id'] ) ? absint( $_GET['intake_id'] ) : 0;
		$pre_event  = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
		// phpcs:enable
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$pre_date = isset( $_GET['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['date'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : current_time( 'Y-m-d' );
		$pre_time = isset( $_GET['time'] ) && preg_match( '/^\d{2}:\d{2}$/', sanitize_text_field( wp_unslash( $_GET['time'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['time'] ) ) : gmdate( 'H:00', strtotime( current_time( 'mysql' ) ) + HOUR_IN_SECONDS );
		// phpcs:enable

		OSB_Admin::open(
			__( 'Add a booking', 'osam-bookings' ),
			array(
				'back'       => admin_url( 'admin.php?page=osb-bookings' ),
				'back_label' => __( 'All bookings', 'osam-bookings' ),
				'subtitle'   => __( 'Book any service at any time. Outside-hours times, days off and double bookings are allowed here — you’ll just see a warning.', 'osam-bookings' ),
			)
		);
		if ( ! $services && ! $events && ! $intakes ) {
			OSB_Admin::empty_state( 'dashicons-clipboard', __( 'Nothing to book yet', 'osam-bookings' ), __( 'Create a service, event or course first.', 'osam-bookings' ), array( __( 'Add a service', 'osam-bookings' ), admin_url( 'admin.php?page=osb-services&action=new' ) ) );
			OSB_Admin::close();
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-manual osb-checkable" data-exclude="0">
			<?php wp_nonce_field( 'osb_create_booking' ); ?>
			<input type="hidden" name="action" value="osb_create_booking">
			<div class="osb-grid-main">
				<div class="osb-stack">
					<div class="osb-card osb-form">
						<?php OSB_Admin::card_head( __( 'What & when', 'osam-bookings' ) ); ?>
						<div class="osb-choice">
							<label><input type="radio" name="type" value="service" <?php checked( 'service', $pre_type ); ?> data-check><span><span class="dashicons dashicons-clipboard"></span><?php esc_html_e( 'Service', 'osam-bookings' ); ?></span></label>
							<?php if ( OSB_Settings::feature( 'events' ) ) : ?><label><input type="radio" name="type" value="event" data-check <?php checked( 'event', $pre_type ); ?> <?php disabled( ! $events ); ?>><span><span class="dashicons dashicons-tickets-alt"></span><?php esc_html_e( 'Event', 'osam-bookings' ); ?></span></label><?php endif; ?>
							<?php if ( OSB_Settings::feature( 'courses' ) ) : ?><label><input type="radio" name="type" value="course" <?php checked( 'course', $pre_type ); ?> data-check <?php disabled( ! $intakes ); ?>><span><span class="dashicons dashicons-welcome-learn-more"></span><?php esc_html_e( 'Course', 'osam-bookings' ); ?></span></label><?php endif; ?>
						</div>
						<div class="osb-if-service">
							<div class="osb-field"><label for="osb_m_service"><?php esc_html_e( 'Service', 'osam-bookings' ); ?></label><select name="service_id" id="osb_m_service" data-check>
								<?php foreach ( $services as $s ) : ?><option value="<?php echo (int) $s->id; ?>"><?php echo esc_html( $s->name . ' (' . $s->duration . ' min' . ( 'active' !== $s->status ? ', hidden' : '' ) . ')' ); ?></option><?php endforeach; ?>
							</select></div>
							<?php if ( ! $solo ) : ?>
							<div class="osb-field"><label for="osb_m_staff"><?php esc_html_e( 'Staff member', 'osam-bookings' ); ?></label><select name="staff_id" id="osb_m_staff" data-check>
								<?php foreach ( $staff as $st ) : ?><option value="<?php echo (int) $st->id; ?>"><?php echo esc_html( $st->name ); ?></option><?php endforeach; ?>
							</select></div>
							<?php else : ?>
								<input type="hidden" name="staff_id" id="osb_m_staff" value="<?php echo (int) OSB_DB::solo_staff_id(); ?>">
							<?php endif; ?>
							<div class="osb-field"><label><?php esc_html_e( 'Date & time', 'osam-bookings' ); ?></label>
								<div class="osb-inline"><input type="date" name="date" id="osb_m_date" value="<?php echo esc_attr( $pre_date ); ?>" data-check><input type="time" name="time" id="osb_m_time" value="<?php echo esc_attr( $pre_time ); ?>" step="300" data-check></div>
							</div>
							<div class="osb-field"><label><?php esc_html_e( 'Free times that day', 'osam-bookings' ); ?></label><div id="osb_m_slots" class="osb-slot-hints"></div></div>
						</div>
						<div class="osb-if-event" hidden>
							<div class="osb-field"><label for="osb_m_event"><?php esc_html_e( 'Event', 'osam-bookings' ); ?></label><select name="event_id" id="osb_m_event" data-check>
								<?php foreach ( $events as $e ) : ?><option value="<?php echo (int) $e->id; ?>" <?php selected( $pre_event, (int) $e->id ); ?>><?php echo esc_html( $e->name . ' — ' . mysql2date( 'D j M Y H:i', $e->start_at ) ); ?></option><?php endforeach; ?>
							</select></div>
						</div>
						<div class="osb-if-course" hidden>
							<div class="osb-field"><label for="osb_m_intake"><?php esc_html_e( 'Course intake', 'osam-bookings' ); ?></label><select name="intake_id" id="osb_m_intake" data-check>
								<?php foreach ( $intakes as $pair ) : ?><option value="<?php echo (int) $pair[0]->id; ?>" <?php selected( $pre_intake, (int) $pair[0]->id ); ?>><?php echo esc_html( $pair[1]->name . ' — ' . OSB_Courses::intake_label( $pair[0] ) . ' (' . OSB_Courses::range_label( $pair[0] ) . ')' ); ?></option><?php endforeach; ?>
							</select></div>
							<p class="osb-help"><?php esc_html_e( 'The customer is booked onto every session of the intake.', 'osam-bookings' ); ?></p>
						</div>
						<div class="osb-field osb-field--narrow"><label for="osb_m_seats"><?php echo esc_html( OSB_Settings::label( 'seats' ) ); ?></label><input type="number" name="seats" id="osb_m_seats" value="1" min="1" data-check></div>
						<div class="osb-if-service osb-fieldgrid"><?php OSB_Repeat::admin_field(); ?></div>
						<?php if ( OSB_Addons::all( true ) ) : ?>
							<div class="osb-field"><label><?php esc_html_e( 'Add-ons', 'osam-bookings' ); ?></label><div class="osb-addon-picks">
								<?php foreach ( OSB_Addons::all( true ) as $ao ) : ?>
									<label class="osb-addon-pick"><input type="number" name="addons[<?php echo (int) $ao->id; ?>]" min="0" max="999" value="0"><span><?php echo esc_html( $ao->name ); ?> <small><?php echo esc_html( OSB_Addons::price_label( $ao ) . ( $ao->required ? ' · ' . __( 'added automatically where it applies', 'osam-bookings' ) : '' ) ); ?></small></span></label>
								<?php endforeach; ?>
							</div></div>
						<?php endif; ?>
						<div class="osb-live-warnings" aria-live="polite"></div>
					</div>

					<div class="osb-card osb-form">
						<?php OSB_Admin::card_head( __( 'Customer', 'osam-bookings' ) ); ?>
						<div class="osb-fieldgrid">
						<?php foreach ( OSB_Settings::form_fields() as $field ) : ?>
							<div class="osb-field <?php echo 'half' === ( isset( $field['width'] ) ? $field['width'] : 'full' ) ? 'osb-field--half' : ''; ?>"><label><?php echo esc_html( $field['label'] ); ?><?php echo $field['required'] ? ' <span class="osb-req">*</span>' : ''; ?></label><?php self::field_input( $field ); ?></div>
						<?php endforeach; ?>
						</div>
					</div>
				</div>
				<div class="osb-stack">
					<div class="osb-card osb-form osb-sticky">
						<?php OSB_Admin::card_head( __( 'Status & notifications', 'osam-bookings' ) ); ?>
						<div class="osb-field"><label><?php esc_html_e( 'Status', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'status', 'confirmed', 'select', OSB_Bookings::statuses() ); ?></div>
						<div class="osb-field"><label><?php esc_html_e( 'Payment', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'payment_status', 'unpaid', 'select', OSB_Bookings::payment_statuses() ); ?></div>
						<label class="osb-switch"><input type="checkbox" name="notify" value="1" checked><span></span><?php esc_html_e( 'Send the usual confirmation emails', 'osam-bookings' ); ?></label>
						<button class="osb-btn osb-btn--primary osb-btn--block"><?php esc_html_e( 'Create booking', 'osam-bookings' ); ?></button>
					</div>
				</div>
			</div>
		</form>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Customer details to start a new booking with (from “New booking” on a customer).
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private static function prefill( $key ) {
		static $c = null;
		if ( null === $c ) {
			$email = isset( $_GET['customer'] ) ? sanitize_email( wp_unslash( $_GET['customer'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$c     = $email && class_exists( 'OSB_Customers' ) ? OSB_Customers::get( $email ) : array();
		}
		$map = array( 'name' => 'name', 'email' => 'email', 'phone' => 'phone' );
		return isset( $map[ $key ], $c[ $map[ $key ] ] ) ? (string) $c[ $map[ $key ] ] : '';
	}

	/**
	 * Render a custom field input in admin.
	 *
	 * @param array $field Field.
	 */
	private static function field_input( $field ) {
		$name = 'fields[' . $field['key'] . ']';
		switch ( $field['type'] ) {
			case 'textarea':
				echo '<textarea name="' . esc_attr( $name ) . '" rows="3"></textarea>';
				break;
			case 'select':
			case 'radio':
				$opts = array( '' => '—' );
				foreach ( array_filter( array_map( 'trim', explode( "\n", (string) $field['options'] ) ), 'strlen' ) as $o ) {
					$opts[ $o ] = $o;
				}
				OSB_Admin::input( $name, '', 'select', $opts );
				break;
			case 'checkbox':
				echo '<label class="osb-switch"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"><span></span>' . esc_html__( 'Yes', 'osam-bookings' ) . '</label>';
				break;
			default:
				$type = in_array( $field['type'], array( 'email', 'tel', 'number', 'date' ), true ) ? $field['type'] : 'text';
				echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( self::prefill( $field['key'] ) ) . '">';
		}
	}

	/**
	 * Calendar page.
	 */
	public static function calendar_page() {
		$staff     = OSB_DB::is_solo() ? array() : OSB_DB::staff();
		$resources = OSB_Settings::feature( 'resources' ) ? OSB_Resources::all() : array();
		$types     = OSB_Calendar::types();
		$layers    = OSB_Calendar::layers();
		OSB_Admin::open(
			__( 'Calendar', 'osam-bookings' ),
			array(
				'subtitle' => __( 'Everything in one place — appointments, events, courses, resources and tables, with maintenance, closures and days off. Click anything for the details; click an empty time to add a booking.', 'osam-bookings' ),
				'actions'  => array( array( __( 'Add booking', 'osam-bookings' ), admin_url( 'admin.php?page=osb-bookings&action=new' ), 'primary', 'dashicons-plus-alt2' ) ),
			)
		);
		?>
		<div class="osb-ccal" id="osb-ccal-wrap">
			<div class="osb-card osb-ccal__filters">
				<div class="osb-ccal__row">
					<div class="osb-search"><span class="dashicons dashicons-search"></span><input type="search" id="osb-ccal-q" placeholder="<?php esc_attr_e( 'Find a customer — name, email, phone or booking ref', 'osam-bookings' ); ?>"></div>
					<?php if ( count( $staff ) > 1 || $resources ) : ?>
						<select id="osb-ccal-who" aria-label="<?php esc_attr_e( 'Whose diary', 'osam-bookings' ); ?>">
							<option value=""><?php esc_html_e( 'Everyone and everything', 'osam-bookings' ); ?></option>
							<?php if ( count( $staff ) > 1 ) : ?>
								<optgroup label="<?php esc_attr_e( 'Staff', 'osam-bookings' ); ?>">
									<?php foreach ( $staff as $st ) : ?><option value="staff:<?php echo (int) $st->id; ?>"><?php echo esc_html( $st->name ); ?></option><?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
							<?php if ( $resources ) : ?>
								<optgroup label="<?php esc_attr_e( 'Resources', 'osam-bookings' ); ?>">
									<?php foreach ( $resources as $r ) : ?><option value="resource:<?php echo (int) $r->id; ?>"><?php echo esc_html( $r->name ); ?></option><?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
						</select>
					<?php endif; ?>
				</div>
				<div class="osb-ccal__row osb-ccal__chips">
					<span class="osb-ccal__label"><?php esc_html_e( 'Show', 'osam-bookings' ); ?></span>
					<?php foreach ( $types as $k => $t ) : ?>
						<label class="osb-ccal__chip"><input type="checkbox" data-ccal-type="<?php echo esc_attr( $k ); ?>" checked><span><i style="background:<?php echo esc_attr( $t[1] ); ?>"></i><?php echo esc_html( $t[0] ); ?></span></label>
					<?php endforeach; ?>
					<?php if ( $layers ) : ?><span class="osb-ccal__sep"></span><?php endif; ?>
					<?php foreach ( $layers as $k => $l ) : ?>
						<label class="osb-ccal__chip osb-ccal__chip--layer" title="<?php echo esc_attr( $l[1] ); ?>"><input type="checkbox" data-ccal-layer="<?php echo esc_attr( $k ); ?>" checked><span><i class="osb-ccal__swatch osb-ccal__swatch--<?php echo esc_attr( $k ); ?>"></i><?php echo esc_html( $l[0] ); ?></span></label>
					<?php endforeach; ?>
					<span class="osb-ccal__sep"></span>
					<label class="osb-ccal__chip osb-ccal__chip--plain"><input type="checkbox" id="osb-ccal-cancelled"><span><?php esc_html_e( 'Cancelled', 'osam-bookings' ); ?></span></label>
				</div>
				<p class="osb-ccal__key"><span><i class="osb-ccal__k osb-ccal__k--pending"></i><?php esc_html_e( 'Striped = pending or awaiting payment', 'osam-bookings' ); ?></span><span><i class="osb-ccal__k osb-ccal__k--unpaid"></i><?php esc_html_e( 'Dot = not paid yet', 'osam-bookings' ); ?></span><span class="osb-ccal__search-note" hidden><?php esc_html_e( 'Showing only bookings that match your search.', 'osam-bookings' ); ?></span></p>
			</div>
			<div class="osb-card osb-card--calendar">
				<div id="osb-ccal" data-first-day="<?php echo (int) get_option( 'start_of_week', 1 ); ?>" data-locale="<?php echo esc_attr( strtolower( str_replace( '_', '-', get_locale() ) ) ); ?>" data-new="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings&action=new' ) ); ?>" data-res-new="<?php echo esc_url( admin_url( 'admin.php?page=osb-res-schedule' ) ); ?>"></div>
			</div>
		</div>
		<aside class="osb-cpanel" id="osb-cpanel" aria-hidden="true" aria-label="<?php esc_attr_e( 'Details', 'osam-bookings' ); ?>">
			<div class="osb-cpanel__bar">
				<button type="button" class="osb-iconbtn osb-cpanel__back" hidden aria-label="<?php esc_attr_e( 'Back', 'osam-bookings' ); ?>"><span class="dashicons dashicons-arrow-left-alt2"></span></button>
				<span class="osb-cpanel__msg" role="status" aria-live="polite"></span>
				<button type="button" class="osb-iconbtn osb-cpanel__close" aria-label="<?php esc_attr_e( 'Close', 'osam-bookings' ); ?>"><span class="dashicons dashicons-no-alt"></span></button>
			</div>
			<div class="osb-cpanel__body"></div>
		</aside>
		<?php
		OSB_Admin::close();
	}


	/**
	 * Warnings for a just-saved admin booking.
	 *
	 * @param object $b Booking.
	 * @return string[]
	 */
	private static function warnings_for( $b ) {
		return OSB_Availability::conflicts(
			array(
				'type'       => $b->type,
				'service_id' => $b->service_id,
				'staff_id'   => $b->staff_id,
				'event_id'   => $b->event_id,
				'intake_id'  => $b->intake_id,
				'date'       => substr( $b->start_at, 0, 10 ),
				'time'       => substr( $b->start_at, 11, 5 ),
				'seats'      => $b->seats,
				'exclude'    => $b->id,
			)
		);
	}

	/**
	 * Save edits.
	 */
	public static function update() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		OSB_Admin::verify( 'osb_update_booking_' . $id );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$b    = OSB_DB::get( 'bookings', $id );
		if ( ! $b ) {
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-bookings' ), 'error' );
		}

		$data = array(
			'internal_note' => sanitize_textarea_field( isset( $post['internal_note'] ) ? $post['internal_note'] : '' ),
			'updated_at'    => current_time( 'mysql' ),
		);

		$pay = isset( $post['payment_status'] ) ? sanitize_key( $post['payment_status'] ) : $b->payment_status;
		if ( isset( OSB_Bookings::payment_statuses()[ $pay ] ) && $pay !== $b->payment_status ) {
			$data['payment_status'] = $pay;
			if ( 'paid' === $pay ) {
				$pid = OSB_DB::add_payment( $b->id, 'manual', 'admin-' . get_current_user_id() . '-' . time(), $b->amount, $b->currency, 'completed', array( 'by' => get_current_user_id() ) );
				$invoice_payment = $pid;
			} elseif ( 'refunded' === $pay ) {
				$refunded = true;
			}
		}

		$moved = false;
		if ( 'service' === $b->type && ! empty( $post['date'] ) && ! empty( $post['time'] ) ) {
			$date = sanitize_text_field( $post['date'] );
			$time = substr( sanitize_text_field( $post['time'] ), 0, 5 );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
				$duration         = strtotime( $b->end_at ) - strtotime( $b->start_at );
				$start            = strtotime( $date . ' ' . $time . ':00' );
				$data['start_at'] = gmdate( 'Y-m-d H:i:s', $start );
				$data['end_at']   = gmdate( 'Y-m-d H:i:s', $start + $duration );
				if ( $data['start_at'] !== $b->start_at ) {
					$data['reminder_sent'] = 0;
					$moved                 = true;
				}
			}
			if ( ! empty( $post['staff_id'] ) && absint( $post['staff_id'] ) !== (int) $b->staff_id ) {
				$data['staff_id'] = absint( $post['staff_id'] );
				$moved            = true;
			}
		}

		if ( isset( $post['meeting_url'] ) ) {
			$link = esc_url_raw( trim( (string) $post['meeting_url'] ) );
			if ( $link !== (string) $b->meeting_url ) {
				$data['meeting_url'] = $link;
				$data['meeting_id']  = $link ? 'manual' : '';
			}
		}
		// A Meet/Teams link lives in the staff member's own calendar, so a new person needs a new link.
		if ( isset( $data['staff_id'] ) && ! isset( $data['meeting_url'] ) && 0 === strpos( (string) $b->meeting_id, 'booking-' ) ) {
			$data['meeting_url'] = '';
			$data['meeting_id']  = '';
		}

		OSB_DB::update( 'bookings', $id, $data );
		OSB_Availability::bust_cache();
		if ( ! empty( $invoice_payment ) ) {
			OSB_Invoices::on_manual_payment( OSB_DB::get( 'bookings', $id ), (int) $invoice_payment, ! empty( $post['notify'] ) );
		} elseif ( ! empty( $refunded ) ) {
			OSB_Invoices::on_refunded( $id );
		}

		$status = isset( $post['status'] ) ? sanitize_key( $post['status'] ) : $b->status;
		if ( $status !== $b->status ) {
			OSB_Bookings::set_status( $id, $status, ! empty( $post['notify'] ) );
		}
		$fresh = OSB_DB::get( 'bookings', $id );
		if ( $moved && ! in_array( $fresh->status, array( 'cancelled' ), true ) ) {
			OSB_Admin::flash_warnings( self::warnings_for( $fresh ) );
			if ( isset( $data['staff_id'] ) ) {
				OSB_Meetings::ensure( $fresh );
			}
			if ( ! empty( $post['notify'] ) && $fresh->start_at !== $b->start_at ) {
				OSB_Bookings::notify_moved( $fresh, $b->start_at, false );
			}
			do_action( 'osb_booking_rescheduled', $fresh, $b->start_at, 'admin' );
		}
		do_action( 'osb_booking_admin_updated', $fresh, $b );

		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $id ) );
	}

	/**
	 * Create manual booking (admin override: never blocked by availability).
	 */
	public static function create() {
		OSB_Admin::verify( 'osb_create_booking' );
		$post    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type    = isset( $post['type'] ) && in_array( $post['type'], array( 'event', 'course' ), true ) ? $post['type'] : 'service';
		$staff   = absint( isset( $post['staff_id'] ) ? $post['staff_id'] : 0 );
		if ( 'service' === $type && ( OSB_DB::is_solo() || ! $staff ) ) {
			$staff = OSB_DB::is_solo() ? OSB_DB::solo_staff_id() : $staff;
		}
		$booking = OSB_Bookings::create(
			array(
				'type'           => $type,
				'service_id'     => absint( isset( $post['service_id'] ) ? $post['service_id'] : 0 ),
				'staff_id'       => $staff,
				'event_id'       => absint( isset( $post['event_id'] ) ? $post['event_id'] : 0 ),
				'intake_id'      => absint( isset( $post['intake_id'] ) ? $post['intake_id'] : 0 ),
				'date'           => sanitize_text_field( isset( $post['date'] ) ? $post['date'] : '' ),
				'time'           => substr( sanitize_text_field( isset( $post['time'] ) ? $post['time'] : '' ), 0, 5 ),
				'seats'          => absint( isset( $post['seats'] ) ? $post['seats'] : 1 ),
				'fields'         => isset( $post['fields'] ) ? (array) $post['fields'] : array(),
				'payment_method' => 'onsite',
				'admin'          => true,
				'status'         => sanitize_key( isset( $post['status'] ) ? $post['status'] : 'confirmed' ),
				'notify'         => ! empty( $post['notify'] ),
				'repeat'         => 'service' === $type ? OSB_Repeat::from_admin_post( $post ) : null,
				'addons'         => array_filter( array_map( 'absint', (array) ( $post['addons'] ?? array() ) ) ),
			)
		);
		if ( is_wp_error( $booking ) ) {
			OSB_Admin::flash_error( implode( ' ', $booking->get_error_messages() ) );
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=osb-bookings&action=new' ) );
			exit;
		}
		$pay = sanitize_key( isset( $post['payment_status'] ) ? $post['payment_status'] : 'unpaid' );
		if ( 'paid' === $pay && 'free' !== $booking->payment_status ) {
			OSB_DB::update( 'bookings', $booking->id, array( 'payment_status' => 'paid' ) );
			$pid = OSB_DB::add_payment( $booking->id, 'manual', 'admin-' . get_current_user_id() . '-' . time(), $booking->amount, $booking->currency, 'completed' );
			if ( $pid ) {
				// The booking emails have gone already, so the invoice follows on its own (if notifying).
				OSB_Invoices::on_manual_payment( OSB_DB::get( 'bookings', $booking->id ), (int) $pid, ! empty( $post['notify'] ) );
			}
		}
		OSB_Repeat::after_admin( $booking, 'paid' === $pay );
		OSB_Admin::flash_warnings( self::warnings_for( $booking ) );
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $booking->id ), 'created' );
	}

	/**
	 * Bulk status change.
	 */
	public static function bulk() {
		OSB_Admin::verify( 'osb_bulk_bookings' );
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$status = sanitize_key( isset( $post['bulk_status'] ) ? $post['bulk_status'] : '' );
		$ids    = array_map( 'absint', (array) ( isset( $post['ids'] ) ? $post['ids'] : array() ) );
		$back   = ! empty( $post['_redirect'] ) ? esc_url_raw( $post['_redirect'] ) : admin_url( 'admin.php?page=osb-bookings' );
		if ( $status && isset( OSB_Bookings::statuses()[ $status ] ) ) {
			foreach ( $ids as $id ) {
				OSB_Bookings::set_status( $id, $status, ! empty( $post['notify'] ) );
			}
		}
		OSB_Admin::redirect( $back, 'bulk' );
	}

	/**
	 * Resend a template.
	 */
	public static function resend() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		OSB_Admin::verify( 'osb_resend_email_' . $id );
		$b   = OSB_DB::get( 'bookings', $id );
		$raw = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $b && 0 === strpos( $raw, 'custom:' ) ) {
			$cdef = OSB_Custom_Emails::get( sanitize_key( substr( $raw, 7 ) ) );
			$sent = $cdef ? OSB_Custom_Emails::send( $cdef, $b, 'manual|' . time() ) : false;
			if ( $cdef && ! $sent && ! OSB_Custom_Emails::recipients( $cdef, $b ) ) {
				set_transient( 'osb_admin_error_' . get_current_user_id(), __( 'That custom email has nobody to send to for this booking — check “Who gets it”.', 'osam-bookings' ), 60 );
				OSB_Admin::redirect( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $id ), 'none' );
			}
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $id ), $sent ? 'emailed' : 'error' );
		}
		$key = sanitize_key( $raw );
		if ( $b && isset( OSB_Settings::email_labels()[ $key ] ) ) {
			$d  = OSB_Bookings::details( $b );
			$to = 0 === strpos( $key, 'admin_' ) ? OSB_Emails::admin_recipients() : ( 0 === strpos( $key, 'staff_' ) ? $d['staff_email'] : $b->customer_email );
			// Force-send even if the template is switched off for automatic sending.
			$sent = OSB_Emails::send( $key, $b, $to, true );
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $id ), $sent ? 'emailed' : 'error' );
		}
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $id ), 'error' );
	}

	/**
	 * Delete a booking permanently.
	 */
	public static function delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_delete_booking_' . $id );
		global $wpdb;
		$row = OSB_DB::get( 'bookings', $id );
		if ( $row ) {
			// While the row still exists, so its Zoom meeting can be removed too.
			do_action( 'osb_booking_before_delete', $row );
		}
		OSB_DB::delete( 'bookings', $id );
		$wpdb->delete( OSB_Install::table( 'payments' ), array( 'booking_id' => $id ) );
		OSB_Availability::bust_cache();
		do_action( 'osb_booking_deleted', $id );
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-bookings' ), 'deleted' );
	}

	/**
	 * CSV export of the current filter.
	 */
	public static function export() {
		OSB_Admin::verify( 'osb_export_bookings' );
		$f             = self::filters();
		$f['per_page'] = 100000;
		$f['page']     = 1;
		list( $rows )  = OSB_DB::query_bookings( $f );
		$names         = OSB_Admin_Dashboard::name_maps();
		$fields        = OSB_Settings::form_fields();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=bookings-' . current_time( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$head = array( 'ID', 'Type', 'Service/Event/Course', 'Staff', 'Start', 'End', 'Places', 'Status', 'Payment method', 'Payment status', 'Amount', 'Currency' );
		foreach ( $fields as $field ) {
			$head[] = $field['label'];
		}
		$head[] = 'Other attendees';
		$head[] = 'Internal note';
		$head[] = 'Created';
		fputcsv( $out, $head );

		foreach ( $rows as $b ) {
			$vals = OSB_Bookings::fields( $b );
			$line = array( $b->id, $b->type, OSB_Admin_Dashboard::item_name( $b, $names ), isset( $names['staff'][ (int) $b->staff_id ] ) ? $names['staff'][ (int) $b->staff_id ] : '', $b->start_at, $b->end_at, $b->seats, $b->status, $b->payment_method, $b->payment_status, $b->amount, $b->currency );
			foreach ( $fields as $field ) {
				$v = isset( $vals[ $field['key'] ] ) ? (string) $vals[ $field['key'] ] : '';
				// Neutralise spreadsheet formula injection.
				$line[] = preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
			}
			$others = array();
			foreach ( array_slice( OSB_Bookings::attendees( $b ), 1 ) as $p ) {
				$others[] = $p['same'] ? sprintf( 'Place %d: same as booker', $p['place'] ) : trim( $p['name'] . ( $p['email'] ? ' <' . $p['email'] . '>' : '' ) );
			}
			$line[] = implode( '; ', $others );
			$line[] = $b->internal_note;
			$line[] = $b->created_at;
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
