<?php
/**
 * Staff admin: details, weekly hours, days off, business closures.
 * In solo mode this becomes a single "Working hours" screen for the owner.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Staff screens.
 */
class OSB_Admin_Staff {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_save_staff', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_osb_delete_staff', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_osb_save_closures', array( __CLASS__, 'save_closures' ) );
	}

	/**
	 * Weekday names (ISO 1-7).
	 *
	 * @return array
	 */
	public static function weekdays() {
		global $wp_locale;
		$out = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$out[ $i ] = $wp_locale->get_weekday( $i % 7 );
		}
		return $out;
	}

	/**
	 * Router.
	 */
	public static function page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		// phpcs:enable
		if ( OSB_DB::is_solo() ) {
			self::edit( OSB_DB::solo_staff_id(), true );
		} elseif ( 'edit' === $action || 'new' === $action ) {
			self::edit( $id );
		} else {
			self::listing();
		}
	}

	/**
	 * One-line hours summary.
	 *
	 * @param int $staff_id Staff.
	 * @return string
	 */
	public static function hours_summary( $staff_id ) {
		$days    = self::weekdays();
		$summary = array();
		foreach ( OSB_DB::schedule( $staff_id ) as $d => $ranges ) {
			if ( $ranges ) {
				$summary[] = mb_substr( $days[ $d ], 0, 3 ) . ' ' . implode( ', ', array_map( static function ( $r ) { return $r[0] . '–' . $r[1]; }, $ranges ) );
			}
		}
		return implode( ' · ', $summary );
	}

	/**
	 * Days-off repeater.
	 *
	 * @param array  $rows Rows.
	 * @param string $tpl  Template id.
	 */
	public static function days_off_table( $rows, $tpl = 'osb-dayoff-tpl' ) {
		?>
		<div class="osb-repeater-wrap">
		<table class="osb-table osb-table--compact osb-repeater" data-template="<?php echo esc_attr( $tpl ); ?>">
			<thead><tr><th><?php esc_html_e( 'From', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'To', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Note', 'osam-bookings' ); ?></th><th class="osb-col-actions"></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $i => $r ) : ?>
				<tr>
					<td><input type="date" name="days_off[<?php echo (int) $i; ?>][from]" value="<?php echo esc_attr( $r->date_from ); ?>"></td>
					<td><input type="date" name="days_off[<?php echo (int) $i; ?>][to]" value="<?php echo esc_attr( $r->date_to ); ?>"></td>
					<td><input type="text" name="days_off[<?php echo (int) $i; ?>][note]" value="<?php echo esc_attr( $r->note ); ?>" placeholder="<?php esc_attr_e( 'e.g. Holiday', 'osam-bookings' ); ?>"></td>
					<td class="osb-col-actions"><button type="button" class="osb-iconbtn osb-iconbtn--danger osb-row-remove" aria-label="<?php esc_attr_e( 'Remove', 'osam-bookings' ); ?>"><span class="dashicons dashicons-no-alt"></span></button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="osb-empty-row" <?php echo $rows ? 'hidden' : ''; ?>><?php esc_html_e( 'No dates added.', 'osam-bookings' ); ?></p>
		<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-row-add"><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add dates', 'osam-bookings' ); ?></button>
		<script type="text/template" id="<?php echo esc_attr( $tpl ); ?>">
			<tr>
				<td><input type="date" name="days_off[__i__][from]"></td>
				<td><input type="date" name="days_off[__i__][to]"></td>
				<td><input type="text" name="days_off[__i__][note]" placeholder="<?php esc_attr_e( 'e.g. Holiday', 'osam-bookings' ); ?>"></td>
				<td class="osb-col-actions"><button type="button" class="osb-iconbtn osb-iconbtn--danger osb-row-remove"><span class="dashicons dashicons-no-alt"></span></button></td>
			</tr>
		</script>
		</div>
		<?php
	}

	/**
	 * Weekly schedule editor.
	 *
	 * @param array  $schedule weekday => ranges.
	 * @param string $name     Input name (e.g. seasons[2][schedule]).
	 */
	public static function schedule_editor( $schedule, $name = 'schedule' ) {
		?>
		<div class="osb-week-wrap">
		<div class="osb-week" data-name="<?php echo esc_attr( $name ); ?>">
		<?php foreach ( self::weekdays() as $d => $label ) : $ranges = isset( $schedule[ $d ] ) ? $schedule[ $d ] : array(); ?>
			<div class="osb-week__day <?php echo $ranges ? '' : 'is-off'; ?>" data-day="<?php echo (int) $d; ?>">
				<label class="osb-switch osb-week__toggle"><input type="checkbox" class="osb-day-toggle" <?php checked( (bool) $ranges ); ?>><span></span><b><?php echo esc_html( $label ); ?></b></label>
				<div class="osb-ranges">
					<?php foreach ( $ranges as $i => $r ) : ?>
						<span class="osb-range"><input type="time" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $d; ?>][<?php echo (int) $i; ?>][0]" value="<?php echo esc_attr( $r[0] ); ?>"><i>–</i><input type="time" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $d; ?>][<?php echo (int) $i; ?>][1]" value="<?php echo esc_attr( $r[1] ); ?>"><button type="button" class="osb-iconbtn osb-range-remove" aria-label="<?php esc_attr_e( 'Remove', 'osam-bookings' ); ?>"><span class="dashicons dashicons-no-alt"></span></button></span>
					<?php endforeach; ?>
					<button type="button" class="osb-link osb-range-add"><?php esc_html_e( '+ Add break / hours', 'osam-bookings' ); ?></button>
					<span class="osb-week__closed"><?php esc_html_e( 'Closed', 'osam-bookings' ); ?></span>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<p><button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-copy-monday"><span class="dashicons dashicons-admin-page"></span><?php esc_html_e( 'Copy Monday to Tue–Fri', 'osam-bookings' ); ?></button></p>
		</div>
		<?php
	}

	/**
	 * List + closures (team mode).
	 */
	private static function listing() {
		$staff = OSB_DB::staff();
		OSB_Admin::open(
			__( 'Staff & hours', 'osam-bookings' ),
			array(
				'subtitle' => __( 'People, rooms or equipment with their own diary. Working on your own? Switch to solo mode in Settings → General.', 'osam-bookings' ),
				'actions'  => array( array( __( 'Add staff member', 'osam-bookings' ), admin_url( 'admin.php?page=osb-staff&action=new' ), 'primary', 'dashicons-plus-alt2' ) ),
			)
		);
		if ( ! $staff ) {
			OSB_Admin::empty_state( 'dashicons-groups', __( 'No staff yet', 'osam-bookings' ), __( 'Add at least one person so services can be booked.', 'osam-bookings' ), array( __( 'Add staff member', 'osam-bookings' ), admin_url( 'admin.php?page=osb-staff&action=new' ) ) );
		} else {
			?>
			<div class="osb-people osb-sortable-grid" data-table="staff">
				<?php
				foreach ( $staff as $st ) :
					$edit     = admin_url( 'admin.php?page=osb-staff&action=edit&id=' . $st->id );
					$del      = wp_nonce_url( admin_url( 'admin-post.php?action=osb_delete_staff&id=' . $st->id ), 'osb_delete_staff_' . $st->id );
					$summary  = self::hours_summary( $st->id );
					$services = 0;
					foreach ( OSB_DB::services() as $s ) {
						$services += in_array( (int) $st->id, OSB_DB::raw_service_staff_ids( $s->id ), true ) ? 1 : 0;
					}
					?>
					<div class="osb-person <?php echo 'active' !== $st->status ? 'is-inactive' : ''; ?>" data-id="<?php echo (int) $st->id; ?>">
						<span class="osb-handle dashicons dashicons-move" title="<?php esc_attr_e( 'Drag to reorder', 'osam-bookings' ); ?>"></span>
						<?php echo OSB_Admin::avatar( $st->name, $st->image_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<h3><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $st->name ); ?></a></h3>
						<p class="osb-muted"><?php echo esc_html( $st->email ? $st->email : __( 'No email set', 'osam-bookings' ) ); ?></p>
						<p class="osb-person__meta">
							<span><span class="dashicons dashicons-clipboard"></span><?php /* translators: %d count */ echo esc_html( sprintf( _n( '%d service', '%d services', $services, 'osam-bookings' ), $services ) ); ?></span>
							<?php if ( 'active' !== $st->status ) : ?><span class="osb-pill osb-pill--neutral"><?php esc_html_e( 'Inactive', 'osam-bookings' ); ?></span><?php endif; ?>
						</p>
						<p class="osb-person__hours"><?php echo $summary ? esc_html( $summary ) : '<span class="osb-pill osb-pill--warn">' . esc_html__( 'No working hours', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
						<div class="osb-person__actions">
							<a class="osb-btn osb-btn--ghost osb-btn--sm" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit hours & details', 'osam-bookings' ); ?></a>
							<a class="osb-iconbtn osb-iconbtn--danger osb-delete" href="<?php echo esc_url( $del ); ?>" title="<?php esc_attr_e( 'Delete', 'osam-bookings' ); ?>"><span class="dashicons dashicons-trash"></span></a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-section osb-form">
			<?php wp_nonce_field( 'osb_save_closures' ); ?>
			<input type="hidden" name="action" value="osb_save_closures">
			<div class="osb-section__intro"><h2><?php esc_html_e( 'Business closures', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Bank holidays, Christmas and other dates when nobody can be booked online.', 'osam-bookings' ); ?></p></div>
			<div class="osb-card">
				<?php self::days_off_table( OSB_DB::days_off( 0 ), 'osb-closure-tpl' ); ?>
				<div class="osb-formfoot"><button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Save closures', 'osam-bookings' ); ?></button></div>
			</div>
		</form>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Edit form (also the solo "Working hours" screen).
	 *
	 * @param int  $id   Staff id.
	 * @param bool $solo Solo layout.
	 */
	private static function edit( $id, $solo = false ) {
		$st = $id ? OSB_DB::get( 'staff', $id ) : null;
		if ( ! $st ) {
			$st = (object) array(
				'id'          => 0,
				'name'        => '',
				'email'       => '',
				'phone'       => '',
				'description' => '',
				'image_id'    => 0,
				'wp_user_id'  => 0,
				'status'      => 'active',
			);
		}
		$schedule = $st->id ? OSB_DB::schedule( $st->id ) : array();
		if ( ! $st->id ) {
			for ( $d = 1; $d <= 7; $d++ ) {
				$schedule[ $d ] = $d <= 5 ? array( array( '09:00', '17:00' ) ) : array();
			}
		}
		$services = OSB_DB::services();

		if ( $solo ) {
			OSB_Admin::open(
				__( 'Working hours', 'osam-bookings' ),
				array( 'subtitle' => __( 'When customers can book you, plus holidays and days off. You can still book anything at any time from the admin.', 'osam-bookings' ) )
			);
		} else {
			OSB_Admin::open(
				$st->id ? $st->name : __( 'New staff member', 'osam-bookings' ),
				array(
					'back'       => admin_url( 'admin.php?page=osb-staff' ),
					'back_label' => __( 'Staff & hours', 'osam-bookings' ),
				)
			);
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form">
			<?php wp_nonce_field( 'osb_save_staff' ); ?>
			<input type="hidden" name="action" value="osb_save_staff">
			<input type="hidden" name="id" value="<?php echo (int) $st->id; ?>">
			<input type="hidden" name="solo" value="<?php echo $solo ? 1 : 0; ?>">

			<?php if ( ! $solo ) : ?>
			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Details', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Shown to customers when they choose who to book with. The email receives their bookings.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_name"><?php esc_html_e( 'Name', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="text" id="osb_name" name="name" value="<?php echo esc_attr( $st->name ); ?>" required></div>
						<div class="osb-field osb-field--half"><label for="osb_email"><?php esc_html_e( 'Email', 'osam-bookings' ); ?></label><input type="email" id="osb_email" name="email" value="<?php echo esc_attr( $st->email ); ?>"></div>
						<div class="osb-field osb-field--half"><label for="osb_phone"><?php esc_html_e( 'Phone', 'osam-bookings' ); ?></label><input type="text" id="osb_phone" name="phone" value="<?php echo esc_attr( $st->phone ); ?>"></div>
						<div class="osb-field osb-field--half"><label for="osb_user"><?php esc_html_e( 'Linked WordPress user', 'osam-bookings' ); ?></label>
							<?php
							$opts = array( 0 => __( '— None —', 'osam-bookings' ) );
							foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ), 'number' => 500 ) ) as $u ) {
								$opts[ $u->ID ] = $u->display_name;
							}
							OSB_Admin::input( 'wp_user_id', $st->wp_user_id, 'select', $opts, 'osb_user' );
							?>
						</div>
					</div>
					<div class="osb-field"><label for="osb_desc"><?php esc_html_e( 'Short bio', 'osam-bookings' ); ?></label><textarea id="osb_desc" name="description" rows="3"><?php echo esc_textarea( (string) $st->description ); ?></textarea></div>
					<div class="osb-field"><label><?php esc_html_e( 'Photo', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'image_id', $st->image_id, 'image' ); ?></div>
					<label class="osb-switch"><input type="hidden" name="status" value="inactive"><input type="checkbox" name="status" value="active" <?php checked( 'active', $st->status ); ?>><span></span><?php esc_html_e( 'Available for online bookings', 'osam-bookings' ); ?></label>
				</div>
			</div>
			<?php endif; ?>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Weekly hours', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Add a second range to a day to create a lunch break.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card"><?php self::schedule_editor( $schedule ); ?></div>
			</div>

			<?php if ( ! $solo && $services ) : ?>
			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Services', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'What this person can be booked for.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-chips-check">
					<?php foreach ( $services as $s ) : $on = $st->id && in_array( (int) $st->id, OSB_DB::raw_service_staff_ids( $s->id ), true ); ?>
						<label><input type="checkbox" name="services[]" value="<?php echo (int) $s->id; ?>" <?php checked( $on ); ?>><span><i style="background:<?php echo esc_attr( $s->color ); ?>"></i><?php echo esc_html( $s->name ); ?></span></label>
					<?php endforeach; ?>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php echo $solo ? esc_html__( 'Holidays & days off', 'osam-bookings' ) : esc_html__( 'Days off', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Single days or date ranges when online booking is closed.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card"><?php self::days_off_table( $st->id ? OSB_DB::days_off( $st->id ) : array() ); ?></div>
			</div>

			<div class="osb-savebar">
				<button class="osb-btn osb-btn--primary"><?php echo $solo ? esc_html__( 'Save hours', 'osam-bookings' ) : ( $st->id ? esc_html__( 'Save staff member', 'osam-bookings' ) : esc_html__( 'Add staff member', 'osam-bookings' ) ); ?></button>
			</div>
		</form>
		<?php
		if ( $st->id ) {
			OSB_Admin_Calendars::staff_section( $st, $solo );
		}
		OSB_Admin::close();
	}

	/**
	 * Parse and save days off.
	 *
	 * @param int   $staff_id Staff (0 = business).
	 * @param array $rows     Posted rows.
	 */
	public static function save_days_off( $staff_id, $rows ) {
		global $wpdb;
		$wpdb->delete( OSB_Install::table( 'days_off' ), array( 'staff_id' => (int) $staff_id ) );
		foreach ( (array) $rows as $row ) {
			$from = isset( $row['from'] ) ? sanitize_text_field( $row['from'] ) : '';
			$to   = isset( $row['to'] ) && $row['to'] ? sanitize_text_field( $row['to'] ) : $from;
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
				continue;
			}
			if ( $to < $from ) {
				list( $from, $to ) = array( $to, $from );
			}
			OSB_DB::insert(
				'days_off',
				array(
					'staff_id'  => (int) $staff_id,
					'date_from' => $from,
					'date_to'   => $to,
					'note'      => sanitize_text_field( isset( $row['note'] ) ? $row['note'] : '' ),
				)
			);
		}
		OSB_Availability::bust_cache();
	}

	/**
	 * Parse posted schedule.
	 *
	 * @param array $posted Posted schedule.
	 * @return array
	 */
	public static function parse_schedule( $posted ) {
		$schedule = array();
		foreach ( (array) $posted as $day => $ranges ) {
			$day = absint( $day );
			if ( $day < 1 || $day > 7 ) {
				continue;
			}
			foreach ( (array) $ranges as $r ) {
				$a = isset( $r[0] ) ? substr( sanitize_text_field( $r[0] ), 0, 5 ) : '';
				$b = isset( $r[1] ) ? substr( sanitize_text_field( $r[1] ), 0, 5 ) : '';
				if ( preg_match( '/^\d{2}:\d{2}$/', $a ) && preg_match( '/^\d{2}:\d{2}$/', $b ) && $a < $b ) {
					$schedule[ $day ][] = array( $a, $b );
				}
			}
		}
		return $schedule;
	}

	/**
	 * Save staff.
	 */
	public static function save() {
		OSB_Admin::verify( 'osb_save_staff' );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id   = absint( isset( $post['id'] ) ? $post['id'] : 0 );
		$solo = ! empty( $post['solo'] );

		if ( $solo ) {
			$id = OSB_DB::solo_staff_id();
		} else {
			$data = array(
				'name'        => sanitize_text_field( isset( $post['name'] ) ? $post['name'] : '' ),
				'email'       => sanitize_email( isset( $post['email'] ) ? $post['email'] : '' ),
				'phone'       => sanitize_text_field( isset( $post['phone'] ) ? $post['phone'] : '' ),
				'description' => sanitize_textarea_field( isset( $post['description'] ) ? $post['description'] : '' ),
				'image_id'    => absint( isset( $post['image_id'] ) ? $post['image_id'] : 0 ),
				'wp_user_id'  => absint( isset( $post['wp_user_id'] ) ? $post['wp_user_id'] : 0 ),
				'status'      => isset( $post['status'] ) && 'active' === $post['status'] ? 'active' : 'inactive',
			);
			if ( '' === $data['name'] ) {
				OSB_Admin::flash_error( __( 'Please enter a name.', 'osam-bookings' ) );
				wp_safe_redirect( wp_get_referer() );
				exit;
			}
			if ( $id ) {
				OSB_DB::update( 'staff', $id, $data );
			} else {
				$data['created_at'] = current_time( 'mysql' );
				$id                 = OSB_DB::insert( 'staff', $data );
			}

			// Service assignments — keep existing price overrides.
			$wanted = array_map( 'absint', (array) ( isset( $post['services'] ) ? $post['services'] : array() ) );
			foreach ( OSB_DB::services() as $s ) {
				$prices  = OSB_DB::service_staff_prices( $s->id );
				$has     = array_key_exists( $id, $prices );
				$want_it = in_array( (int) $s->id, $wanted, true );
				if ( $has === $want_it ) {
					continue;
				}
				if ( $want_it ) {
					$prices[ $id ] = '';
				} else {
					unset( $prices[ $id ] );
				}
				OSB_DB::set_service_staff( $s->id, array_map( static function ( $p ) { return null === $p ? '' : $p; }, $prices ) );
			}
		}

		OSB_DB::set_schedule( $id, self::parse_schedule( isset( $post['schedule'] ) ? $post['schedule'] : array() ) );
		self::save_days_off( $id, isset( $post['days_off'] ) ? $post['days_off'] : array() );
		do_action( 'osb_staff_saved', $id );

		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-staff' . ( $solo ? '' : '&action=edit&id=' . $id ) ) );
	}

	/**
	 * Save closures.
	 */
	public static function save_closures() {
		OSB_Admin::verify( 'osb_save_closures' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::save_days_off( 0, isset( $_POST['days_off'] ) ? wp_unslash( $_POST['days_off'] ) : array() );
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-staff' ) );
	}

	/**
	 * Delete.
	 */
	public static function delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_delete_staff_' . $id );
		list( , $count ) = OSB_DB::query_bookings( array( 'staff_id' => $id, 'per_page' => 1 ) );
		if ( $count || (int) get_option( 'osb_solo_staff_id' ) === $id ) {
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-staff' ), 'has_items' );
		}
		global $wpdb;
		OSB_DB::delete( 'staff', $id );
		$wpdb->delete( OSB_Install::table( 'schedule' ), array( 'staff_id' => $id ) );
		$wpdb->delete( OSB_Install::table( 'days_off' ), array( 'staff_id' => $id ) );
		$wpdb->delete( OSB_Install::table( 'service_staff' ), array( 'staff_id' => $id ) );
		OSB_Availability::bust_cache();
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-staff' ), 'deleted' );
	}
}
