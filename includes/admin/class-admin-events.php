<?php
/**
 * Events admin.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * List / edit / duplicate events.
 */
class OSB_Admin_Events {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_save_event', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_osb_delete_event', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_osb_duplicate_event', array( __CLASS__, 'duplicate' ) );
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
		$now       = current_time( 'mysql' );
		$show_past = ! empty( $_GET['past'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$all       = OSB_DB::events();
		$upcoming  = array_reverse( array_filter( $all, static function ( $e ) use ( $now ) { return $e->end_at >= $now; } ) );
		$past      = array_filter( $all, static function ( $e ) use ( $now ) { return $e->end_at < $now; } );
		$events    = $show_past ? $past : $upcoming;

		OSB_Admin::open(
			__( 'Events', 'osam-bookings' ),
			array(
				'subtitle' => __( 'One-off or repeating sessions with a fixed date and a number of places — workshops, classes, open evenings.', 'osam-bookings' ),
				'actions'  => array_merge( OSB_Admin_Import::actions( 'events' ), array( array( __( 'Add event', 'osam-bookings' ), admin_url( 'admin.php?page=osb-events&action=new' ), 'primary', 'dashicons-plus-alt2' ) ) ),
			)
		);
		?>
		<div class="osb-segmented">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-events' ) ); ?>" class="<?php echo $show_past ? '' : 'is-active'; ?>"><?php esc_html_e( 'Upcoming', 'osam-bookings' ); ?> <span><?php echo count( $upcoming ); ?></span></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-events&past=1' ) ); ?>" class="<?php echo $show_past ? 'is-active' : ''; ?>"><?php esc_html_e( 'Past', 'osam-bookings' ); ?> <span><?php echo count( $past ); ?></span></a>
		</div>
		<?php if ( ! $events ) : ?>
			<?php OSB_Admin::empty_state( 'dashicons-tickets-alt', $show_past ? __( 'No past events', 'osam-bookings' ) : __( 'No upcoming events', 'osam-bookings' ), __( 'Events have a set date, time and number of places.', 'osam-bookings' ), $show_past ? array() : array( __( 'Add an event', 'osam-bookings' ), admin_url( 'admin.php?page=osb-events&action=new' ) ) ); ?>
		<?php else : ?>
		<div class="osb-card osb-card--table">
			<table class="osb-table osb-table--click">
				<thead><tr>
					<th><?php esc_html_e( 'Event', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Places booked', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Price', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th>
					<th class="osb-col-actions"></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $events as $e ) :
					$edit  = admin_url( 'admin.php?page=osb-events&action=edit&id=' . $e->id );
					$del   = wp_nonce_url( admin_url( 'admin-post.php?action=osb_delete_event&id=' . $e->id ), 'osb_delete_event_' . $e->id );
					$dup   = wp_nonce_url( admin_url( 'admin-post.php?action=osb_duplicate_event&id=' . $e->id ), 'osb_duplicate_event_' . $e->id );
					$taken = OSB_DB::event_seats_taken( $e->id );
					$pct   = $e->capacity ? min( 100, round( $taken / $e->capacity * 100 ) ) : 0;
					$list  = admin_url( 'admin.php?page=osb-bookings&event_id=' . $e->id );
					?>
					<tr data-href="<?php echo esc_url( $edit ); ?>">
						<td>
							<div class="osb-item">
								<span class="osb-datebadge" style="background:<?php echo esc_attr( $e->color ); ?>"><b><?php echo esc_html( mysql2date( 'j', $e->start_at ) ); ?></b><?php echo esc_html( mysql2date( 'M', $e->start_at ) ); ?></span>
								<span><a class="osb-rowlink" href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( $e->name ); ?></strong></a>
								<small><?php echo esc_html( mysql2date( 'D ' . get_option( 'time_format' ), $e->start_at ) . ' – ' . mysql2date( get_option( 'time_format' ), $e->end_at ) . ( $e->location ? ' · ' . $e->location : '' ) ); ?></small></span>
							</div>
						</td>
						<td class="osb-col-meter">
							<a href="<?php echo esc_url( $list ); ?>" class="osb-meterlink"><span class="osb-meter osb-meter--sm"><span style="width:<?php echo (int) $pct; ?>%;background:<?php echo esc_attr( $e->color ); ?>"></span></span><small><?php echo (int) $taken; ?> / <?php echo (int) $e->capacity; ?></small></a>
						</td>
						<td><?php echo $e->price > 0 ? esc_html( OSB_Settings::money( $e->price ) ) : '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Free', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo $taken >= $e->capacity ? '<span class="osb-pill osb-pill--warn">' . esc_html__( 'Full', 'osam-bookings' ) . '</span>' : ( 'active' === $e->status ? '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'On sale', 'osam-bookings' ) . '</span>' : '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Hidden', 'osam-bookings' ) . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="osb-col-actions">
							<a class="osb-iconbtn" href="<?php echo esc_url( $list ); ?>" title="<?php esc_attr_e( 'Attendees', 'osam-bookings' ); ?>"><span class="dashicons dashicons-groups"></span></a>
							<a class="osb-iconbtn" href="<?php echo esc_url( $dup ); ?>" title="<?php esc_attr_e( 'Duplicate one week later', 'osam-bookings' ); ?>"><span class="dashicons dashicons-admin-page"></span></a>
							<button type="button" class="osb-iconbtn osb-copy" data-copy='[osam_booking event="<?php echo (int) $e->id; ?>"]' title="<?php esc_attr_e( 'Copy shortcode', 'osam-bookings' ); ?>"><span class="dashicons dashicons-shortcode"></span></button>
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
	 * @param int $id Event id.
	 */
	private static function edit( $id ) {
		$e = $id ? OSB_DB::get( 'events', $id ) : null;
		if ( ! $e ) {
			$start = gmdate( 'Y-m-d 18:00:00', strtotime( current_time( 'mysql' ) . ' +7 days' ) );
			$e     = (object) array(
				'id'              => 0,
				'name'            => '',
				'description'     => '',
				'location'        => '',
				'start_at'        => $start,
				'end_at'          => gmdate( 'Y-m-d H:i:s', strtotime( $start ) + 2 * HOUR_IN_SECONDS ),
				'capacity'        => 20,
				'max_per_booking' => 6,
				'price'           => '0.00',
				'staff_id'        => 0,
				'image_id'        => 0,
				'color'           => '#e8590c',
				'payment_methods' => '',
				'online_mode'     => '',
				'online_url'      => '',
				'status'          => 'active',
			);
		}
		$solo = OSB_DB::is_solo();
		$sym  = trim( OSB_Settings::currencies()[ OSB_Settings::get( 'currency' ) ] ?? '' );

		OSB_Admin::open(
			$e->id ? $e->name : __( 'New event', 'osam-bookings' ),
			array(
				'back'       => admin_url( 'admin.php?page=osb-events' ),
				'back_label' => __( 'Events', 'osam-bookings' ),
				'actions'    => $e->id ? array( array( __( 'View attendees', 'osam-bookings' ), admin_url( 'admin.php?page=osb-bookings&event_id=' . $e->id ), 'secondary', 'dashicons-groups' ) ) : array(),
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form">
			<?php wp_nonce_field( 'osb_save_event' ); ?>
			<input type="hidden" name="action" value="osb_save_event">
			<input type="hidden" name="id" value="<?php echo (int) $e->id; ?>">

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Event details', 'osam-bookings' ); ?></h2></div>
				<div class="osb-card">
					<div class="osb-field"><label for="osb_name"><?php esc_html_e( 'Name', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="text" id="osb_name" name="name" value="<?php echo esc_attr( $e->name ); ?>" required placeholder="<?php esc_attr_e( 'e.g. Sound bath evening', 'osam-bookings' ); ?>"></div>
					<div class="osb-field"><label><?php esc_html_e( 'Description', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'description', $e->description, 'editor' ); ?></div>
					<div class="osb-field"><label for="osb_location"><?php esc_html_e( 'Location', 'osam-bookings' ); ?></label><input type="text" id="osb_location" name="location" value="<?php echo esc_attr( $e->location ); ?>"></div>
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label><?php esc_html_e( 'Colour', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'color', $e->color, 'color' ); ?></div>
						<div class="osb-field osb-field--half"><label><?php esc_html_e( 'Image', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'image_id', $e->image_id, 'image' ); ?></div>
					</div>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'When', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Moving an event also moves everyone already booked on it.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_start"><?php esc_html_e( 'Starts', 'osam-bookings' ); ?></label><input type="datetime-local" id="osb_start" name="start_at" value="<?php echo esc_attr( str_replace( ' ', 'T', substr( $e->start_at, 0, 16 ) ) ); ?>"></div>
						<div class="osb-field osb-field--half"><label for="osb_end"><?php esc_html_e( 'Ends', 'osam-bookings' ); ?></label><input type="datetime-local" id="osb_end" name="end_at" value="<?php echo esc_attr( str_replace( ' ', 'T', substr( $e->end_at, 0, 16 ) ) ); ?>"></div>
					</div>
					<?php if ( ! $solo ) : ?>
						<?php
						$opts = array( 0 => __( '— None —', 'osam-bookings' ) );
						foreach ( OSB_DB::staff() as $st ) {
							$opts[ $st->id ] = $st->name;
						}
						?>
						<div class="osb-field"><label for="osb_host"><?php esc_html_e( 'Host', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'staff_id', $e->staff_id, 'select', $opts, 'osb_host' ); ?><p class="osb-help"><?php esc_html_e( 'Optional. Blocks this person’s diary during the event and sends them booking emails.', 'osam-bookings' ); ?></p></div>
					<?php else : ?>
						<label class="osb-switch"><input type="checkbox" name="block_diary" value="1" <?php checked( ! $e->id || (int) $e->staff_id === OSB_DB::solo_staff_id() ); ?>><span></span><?php esc_html_e( 'Block my appointment diary during this event', 'osam-bookings' ); ?></label>
					<?php endif; ?>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Places & price', 'osam-bookings' ); ?></h2></div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--third"><label for="osb_cap"><?php esc_html_e( 'Total places', 'osam-bookings' ); ?></label><input type="number" id="osb_cap" name="capacity" min="1" value="<?php echo (int) $e->capacity; ?>"></div>
						<div class="osb-field osb-field--third"><label for="osb_max"><?php esc_html_e( 'Max per booking', 'osam-bookings' ); ?></label><input type="number" id="osb_max" name="max_per_booking" min="1" value="<?php echo (int) $e->max_per_booking; ?>"></div>
						<div class="osb-field osb-field--third"><label for="osb_price"><?php esc_html_e( 'Price per place', 'osam-bookings' ); ?></label><div class="osb-affix osb-affix--pre"><span><?php echo esc_html( $sym ); ?></span><input type="number" id="osb_price" name="price" min="0" step="0.01" value="<?php echo esc_attr( $e->price ); ?>"></div></div>
					</div>
					<div class="osb-field"><label><?php esc_html_e( 'Payment methods offered', 'osam-bookings' ); ?></label><?php OSB_Admin::methods_checkboxes( $e->payment_methods ); ?></div>
					<label class="osb-switch"><input type="hidden" name="status" value="hidden"><input type="checkbox" name="status" value="active" <?php checked( 'active', $e->status ); ?>><span></span><?php esc_html_e( 'On sale — customers can book online', 'osam-bookings' ); ?></label>
				</div>
			</div>

			<?php OSB_Meetings::editor_section( 'event', $e ); ?>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Repeat', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Make copies of this event on later dates — for weekly classes, monthly meet-ups and so on. Each copy is its own event with its own places, which you can change or cancel separately.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card"><div class="osb-fieldgrid"><?php OSB_Repeat::admin_field(); ?></div><p class="osb-help"><?php esc_html_e( 'Copies are made when you save. “Dates in total” includes this one.', 'osam-bookings' ); ?></p></div>
			</div>

			<div class="osb-savebar">
				<?php if ( $e->id ) : ?><button type="button" class="osb-btn osb-btn--ghost osb-copy" data-copy='[osam_booking event="<?php echo (int) $e->id; ?>"]'><span class="dashicons dashicons-shortcode"></span><?php esc_html_e( 'Copy shortcode', 'osam-bookings' ); ?></button><?php endif; ?>
				<button class="osb-btn osb-btn--primary"><?php echo $e->id ? esc_html__( 'Save event', 'osam-bookings' ) : esc_html__( 'Add event', 'osam-bookings' ); ?></button>
			</div>
		</form>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Save.
	 */
	public static function save() {
		OSB_Admin::verify( 'osb_save_event' );
		$post  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id    = absint( isset( $post['id'] ) ? $post['id'] : 0 );
		$start = isset( $post['start_at'] ) ? str_replace( 'T', ' ', sanitize_text_field( $post['start_at'] ) ) : '';
		$end   = isset( $post['end_at'] ) ? str_replace( 'T', ' ', sanitize_text_field( $post['end_at'] ) ) : '';
		if ( ! strtotime( $start ) ) {
			OSB_Admin::flash_error( __( 'Please enter a start date and time.', 'osam-bookings' ) );
			wp_safe_redirect( wp_get_referer() );
			exit;
		}
		$start = gmdate( 'Y-m-d H:i:00', strtotime( $start ) );
		$end   = strtotime( $end ) && strtotime( $end ) > strtotime( $start ) ? gmdate( 'Y-m-d H:i:00', strtotime( $end ) ) : gmdate( 'Y-m-d H:i:00', strtotime( $start ) + HOUR_IN_SECONDS );
		$color = sanitize_hex_color( isset( $post['color'] ) ? $post['color'] : '' );

		if ( OSB_DB::is_solo() ) {
			$staff_id = ! empty( $post['block_diary'] ) ? OSB_DB::solo_staff_id() : 0;
		} else {
			$staff_id = absint( isset( $post['staff_id'] ) ? $post['staff_id'] : 0 );
		}

		$data = array(
			'name'            => sanitize_text_field( isset( $post['name'] ) ? $post['name'] : '' ),
			'description'     => wp_kses_post( isset( $post['description'] ) ? $post['description'] : '' ),
			'location'        => sanitize_text_field( isset( $post['location'] ) ? $post['location'] : '' ),
			'start_at'        => $start,
			'end_at'          => $end,
			'capacity'        => max( 1, absint( isset( $post['capacity'] ) ? $post['capacity'] : 1 ) ),
			'max_per_booking' => max( 1, absint( isset( $post['max_per_booking'] ) ? $post['max_per_booking'] : 1 ) ),
			'price'           => max( 0, (float) ( isset( $post['price'] ) ? $post['price'] : 0 ) ),
			'staff_id'        => $staff_id,
			'image_id'        => absint( isset( $post['image_id'] ) ? $post['image_id'] : 0 ),
			'color'           => $color ? $color : '#e8590c',
			'payment_methods' => implode( ',', array_map( 'sanitize_key', (array) ( isset( $post['payment_methods'] ) ? $post['payment_methods'] : array() ) ) ),
			'status'          => isset( $post['status'] ) && 'active' === $post['status'] ? 'active' : 'hidden',
		);
		$data = array_merge( $data, OSB_Meetings::from_post( $post, 'event' ) );
		if ( '' === $data['name'] ) {
			OSB_Admin::flash_error( __( 'Please give the event a name.', 'osam-bookings' ) );
			wp_safe_redirect( wp_get_referer() );
			exit;
		}

		if ( $id ) {
			OSB_DB::update( 'events', $id, $data );
			global $wpdb;
			$wpdb->update(
				OSB_Install::table( 'bookings' ),
				array(
					'start_at' => $data['start_at'],
					'end_at'   => $data['end_at'],
					'staff_id' => $data['staff_id'],
				),
				array( 'event_id' => $id )
			);
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$id                 = OSB_DB::insert( 'events', $data );
		}
		OSB_Availability::bust_cache();
		do_action( 'osb_event_saved', $id, $data );

		// Repeat: copies on later dates.
		$rep = OSB_Repeat::parse( OSB_Repeat::from_admin_post( $post ), true );
		if ( $rep ) {
			$made  = 0;
			$len   = strtotime( $data['end_at'] ) - strtotime( $data['start_at'] );
			$clock = substr( $data['start_at'], 11 );
			$dates = OSB_Repeat::dates( substr( $data['start_at'], 0, 10 ), $rep[0], $rep[1] );
			array_shift( $dates );
			foreach ( $dates as $d ) {
				$copy               = $data;
				$copy['start_at']   = $d . ' ' . $clock;
				$copy['end_at']     = gmdate( 'Y-m-d H:i:s', strtotime( $copy['start_at'] ) + $len );
				$copy['created_at'] = current_time( 'mysql' );
				$new                = OSB_DB::insert( 'events', $copy );
				if ( $new ) {
					++$made;
					do_action( 'osb_event_saved', $new, $copy );
				}
			}
			/* translators: %d number of events */
			OSB_Admin::flash_warnings( array( sprintf( _n( 'Added %d more date of this event.', 'Added %d more dates of this event.', $made, 'osam-bookings' ), $made ) ) );
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-events' ) );
		}
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-events&action=edit&id=' . $id ) );
	}

	/**
	 * Duplicate one week later.
	 */
	public static function duplicate() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_duplicate_event_' . $id );
		$e = OSB_DB::get( 'events', $id );
		if ( ! $e ) {
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-events' ), 'error' );
		}
		$row = (array) $e;
		unset( $row['id'] );
		$row['start_at']   = gmdate( 'Y-m-d H:i:s', strtotime( $e->start_at . ' +7 days' ) );
		$row['end_at']     = gmdate( 'Y-m-d H:i:s', strtotime( $e->end_at . ' +7 days' ) );
		$row['created_at'] = current_time( 'mysql' );
		$new               = OSB_DB::insert( 'events', $row );
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-events&action=edit&id=' . $new ) );
	}

	/**
	 * Delete.
	 */
	public static function delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_delete_event_' . $id );
		list( , $count ) = OSB_DB::query_bookings( array( 'event_id' => $id, 'per_page' => 1 ) );
		if ( $count ) {
			OSB_Admin::redirect( admin_url( 'admin.php?page=osb-events' ), 'has_items' );
		}
		OSB_DB::delete( 'events', $id );
		do_action( 'osb_event_deleted', $id );
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-events' ), 'deleted' );
	}
}
