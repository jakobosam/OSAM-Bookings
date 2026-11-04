<?php
/**
 * Waitlist admin.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Who's waiting for what.
 */
class OSB_Admin_Waitlist {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_waitlist_action', array( __CLASS__, 'action' ) );
	}

	/**
	 * Label for an entry's item.
	 *
	 * @param object $w Entry.
	 * @return array [ name, when, color, admin url ]
	 */
	private static function item( $w ) {
		if ( 'course' === $w->type ) {
			$in = OSB_DB::get( 'course_intakes', $w->intake_id );
			$co = $in ? OSB_DB::get( 'courses', $in->course_id ) : null;
			return array( $co ? $co->name . ' — ' . OSB_Courses::intake_label( $in ) : __( '(deleted)', 'osam-bookings' ), $in ? OSB_Courses::range_label( $in ) : '', $co ? $co->color : '#868e96', admin_url( 'admin.php?page=osb-courses&action=register&id=' . (int) $w->intake_id ) );
		}
		$e = OSB_DB::get( 'events', $w->event_id );
		return array( $e ? $e->name : __( '(deleted)', 'osam-bookings' ), $e ? mysql2date( 'D j M Y, ' . get_option( 'time_format' ), $e->start_at ) : '', $e ? $e->color : '#868e96', admin_url( 'admin.php?page=osb-bookings&event_id=' . (int) $w->event_id ) );
	}

	/**
	 * Page.
	 */
	public static function page() {
		global $wpdb;
		$t       = OSB_Install::table( 'waitlist' );
		$history = ! empty( $_GET['history'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows    = $wpdb->get_results( "SELECT * FROM $t WHERE status " . ( $history ? "NOT IN ('waiting','offered')" : "IN ('waiting','offered')" ) . ' ORDER BY type, event_id, intake_id, id ASC LIMIT 500' );
		$labels  = OSB_Waitlist::statuses();
		OSB_Admin::open(
			__( 'Waitlist', 'osam-bookings' ),
			array( 'subtitle' => __( 'People waiting for a place on a full event or course. When a place frees up, the next person is emailed and it’s held for them automatically.', 'osam-bookings' ) )
		);
		if ( ! OSB_Waitlist::enabled() ) {
			echo '<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div>' . esc_html__( 'The waitlist is switched off in Settings → Features, so customers can’t join it.', 'osam-bookings' ) . '</div></div>';
		}
		?>
		<div class="osb-segmented">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-waitlist' ) ); ?>" class="<?php echo $history ? '' : 'is-active'; ?>"><?php esc_html_e( 'Waiting now', 'osam-bookings' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-waitlist&history=1' ) ); ?>" class="<?php echo $history ? 'is-active' : ''; ?>"><?php esc_html_e( 'History', 'osam-bookings' ); ?></a>
		</div>
		<?php
		if ( ! $rows ) {
			OSB_Admin::empty_state( 'dashicons-clock', $history ? __( 'Nothing here yet', 'osam-bookings' ) : __( 'Nobody is waiting', 'osam-bookings' ), __( 'When an event or course is full, customers see a “Join the waitlist” button.', 'osam-bookings' ) );
			OSB_Admin::close();
			return;
		}
		$groups = array();
		foreach ( $rows as $w ) {
			$groups[ $w->type . '-' . ( 'course' === $w->type ? $w->intake_id : $w->event_id ) ][] = $w;
		}
		foreach ( $groups as $list ) :
			$info = self::item( $list[0] );
			$free = OSB_Waitlist::free_places( $list[0]->type, 'course' === $list[0]->type ? (int) $list[0]->intake_id : (int) $list[0]->event_id );
			?>
			<div class="osb-card osb-card--table osb-wlgroup">
				<div class="osb-card__head osb-wlgroup__head"><span class="osb-dot" style="background:<?php echo esc_attr( $info[2] ); ?>"></span><div><h2><a href="<?php echo esc_url( $info[3] ); ?>"><?php echo esc_html( $info[0] ); ?></a></h2><p><?php echo esc_html( $info[1] ); ?><?php if ( ! $history ) : ?> · <?php /* translators: %d places */ echo esc_html( sprintf( _n( '%d place free right now', '%d places free right now', max( 0, $free ), 'osam-bookings' ), max( 0, $free ) ) ); ?><?php endif; ?></p></div></div>
				<table class="osb-table">
					<thead><tr><th>#</th><th><?php esc_html_e( 'Customer', 'osam-bookings' ); ?></th><th><?php echo esc_html( OSB_Settings::label( 'seats' ) ); ?></th><th><?php esc_html_e( 'Joined', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th><th class="osb-col-actions"></th></tr></thead>
					<tbody>
					<?php
					foreach ( $list as $i => $w ) :
						$act = static function ( $do, $label, $icon, $danger = false ) use ( $w ) {
							$url = wp_nonce_url( admin_url( 'admin-post.php?action=osb_waitlist_action&do=' . $do . '&id=' . (int) $w->id ), 'osb_waitlist_' . $w->id );
							return '<a class="osb-iconbtn' . ( $danger ? ' osb-iconbtn--danger' : '' ) . '" href="' . esc_url( $url ) . '" title="' . esc_attr( $label ) . '"><span class="dashicons ' . esc_attr( $icon ) . '"></span></a>';
						};
						?>
						<tr>
							<td><?php echo (int) ( $i + 1 ); ?></td>
							<td><strong><?php echo esc_html( $w->name ); ?></strong><small><a href="mailto:<?php echo esc_attr( $w->email ); ?>"><?php echo esc_html( $w->email ); ?></a><?php echo $w->phone ? ' · ' . esc_html( $w->phone ) : ''; ?></small></td>
							<td><?php echo (int) $w->seats; ?></td>
							<td><?php echo esc_html( mysql2date( 'j M, ' . get_option( 'time_format' ), $w->created_at ) ); ?></td>
							<td>
								<?php
								$cls = array( 'waiting' => 'pending', 'offered' => 'pending_payment', 'booked' => 'confirmed', 'expired' => 'neutral', 'removed' => 'cancelled' );
								echo '<span class="osb-pill osb-pill--' . esc_attr( $cls[ $w->status ] ?? 'neutral' ) . '">' . esc_html( $labels[ $w->status ] ?? $w->status ) . '</span>';
								if ( 'offered' === $w->status && $w->expires_at ) {
									/* translators: %s time */
									echo '<small>' . esc_html( sprintf( __( 'Held until %s', 'osam-bookings' ), mysql2date( 'D j M, ' . get_option( 'time_format' ), $w->expires_at ) ) ) . '</small>';
								}
								if ( 'booked' === $w->status && $w->booking_id ) {
									echo '<small><a href="' . esc_url( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . (int) $w->booking_id ) ) . '">#' . (int) $w->booking_id . '</a></small>';
								}
								?>
							</td>
							<td class="osb-col-actions">
								<?php
								if ( 'waiting' === $w->status ) {
									echo $act( 'offer', __( 'Offer a place now (emails them and holds it)', 'osam-bookings' ), 'dashicons-email-alt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
								if ( 'offered' === $w->status ) {
									echo $act( 'offer', __( 'Send the offer again with a fresh hold', 'osam-bookings' ), 'dashicons-update' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
								if ( in_array( $w->status, array( 'waiting', 'offered' ), true ) ) {
									echo $act( 'remove', __( 'Remove from the list', 'osam-bookings' ), 'dashicons-no-alt', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
		endforeach;
		OSB_Admin::close();
	}

	/**
	 * Offer / remove.
	 */
	public static function action() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_waitlist_' . $id );
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$w  = OSB_DB::get( 'waitlist', $id );
		if ( $w && 'remove' === $do ) {
			OSB_DB::update( 'waitlist', $id, array( 'status' => 'removed' ) );
			OSB_Waitlist::process( $w->type, 'course' === $w->type ? (int) $w->intake_id : (int) $w->event_id );
		} elseif ( $w && 'offer' === $do ) {
			// An admin offer may go over the free places — the admin has decided to make room.
			OSB_Waitlist::offer( $w );
		}
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-waitlist' ) );
	}
}
