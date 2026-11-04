<?php
/**
 * Dashboard: today at a glance, stats and setup checklist.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Landing screen for the Bookings menu.
 */
class OSB_Admin_Dashboard {

	/**
	 * Setup checklist items.
	 *
	 * @return array [ [ done(bool), label, help, url, link label ] ]
	 */
	public static function checklist() {
		$staff_with_hours = 0;
		foreach ( OSB_DB::staff( true ) as $st ) {
			if ( array_filter( OSB_DB::schedule( $st->id ) ) ) {
				$staff_with_hours++;
			}
		}
		$bookable = 0;
		foreach ( OSB_DB::services( true ) as $s ) {
			if ( OSB_DB::service_staff_ids( $s->id ) ) {
				$bookable++;
			}
		}
		$events  = ( OSB_Settings::feature( 'events' ) ? count( OSB_DB::upcoming_events( 1 ) ) : 0 ) + ( OSB_Settings::feature( 'courses' ) ? count( OSB_Courses::courses( true ) ) : 0 );
		$page_id = (int) OSB_Settings::get( 'booking_page' );
		$page    = $page_id ? get_post( $page_id ) : null;
		$has_sc  = $page && ( has_shortcode( $page->post_content, 'osam_booking' ) || false !== strpos( (string) get_post_meta( $page_id, '_elementor_data', true ), 'osam_booking_form' ) );
		$from    = (string) OSB_Settings::get( 'email_from_address' );
		$host    = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$from_ok = is_email( $from ) && $host && substr( strtolower( $from ), -strlen( $host ) ) === strtolower( $host );

		return array(
			OSB_DB::is_solo()
				? array( $staff_with_hours > 0, __( 'Set your working hours', 'osam-bookings' ), __( 'When customers can book you online.', 'osam-bookings' ), admin_url( 'admin.php?page=osb-staff' ), __( 'Working hours', 'osam-bookings' ) )
				: array( $staff_with_hours > 0, __( 'Add staff and their working hours', 'osam-bookings' ), __( 'People, rooms or equipment that can be booked.', 'osam-bookings' ), admin_url( 'admin.php?page=osb-staff&action=new' ), __( 'Add staff', 'osam-bookings' ) ),
			array( $bookable > 0 || $events > 0, OSB_Settings::feature( 'events' ) || OSB_Settings::feature( 'courses' ) ? __( 'Create a service, event or course', 'osam-bookings' ) : __( 'Create a service', 'osam-bookings' ), __( 'Services need at least one staff member assigned.', 'osam-bookings' ), admin_url( 'admin.php?page=osb-services&action=new' ), __( 'Add service', 'osam-bookings' ) ),
			array( (bool) osb()->payments->enabled(), __( 'Turn on a payment method', 'osam-bookings' ), __( 'Pay on site, Stripe, PayPal or WooCommerce.', 'osam-bookings' ), admin_url( 'admin.php?page=osb-settings&tab=payments' ), __( 'Payments', 'osam-bookings' ) ),
			array( $from_ok, __( 'Send emails from your domain', 'osam-bookings' ), __( 'A From address on this site’s domain keeps emails out of spam.', 'osam-bookings' ), admin_url( 'admin.php?page=osb-settings&tab=emails' ), __( 'Email settings', 'osam-bookings' ) ),
			array( $has_sc, __( 'Put the booking form on a page', 'osam-bookings' ), __( 'Use a shortcode or the Elementor widget, and set it as the booking page.', 'osam-bookings' ), admin_url( 'admin.php?page=osb-shortcodes' ), __( 'Get shortcodes', 'osam-bookings' ) ),
		);
	}

	/**
	 * Count bookings between two local datetimes.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @return int
	 */
	private static function count_between( $from, $to ) {
		global $wpdb;
		$t = OSB_Install::table( 'bookings' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE start_at >= %s AND start_at < %s AND type <> 'bundle' AND status IN ('pending','confirmed','completed')", $from, $to ) );
	}

	/**
	 * Page.
	 */
	public static function page() {
		global $wpdb;
		$today    = current_time( 'Y-m-d' );
		$tomorrow = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );
		$week_end = gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) );
		$month    = current_time( 'Y-m-01' );
		$p        = OSB_Install::table( 'payments' );

		$stats = array(
			array( __( 'Today', 'osam-bookings' ), self::count_between( $today . ' 00:00:00', $tomorrow . ' 00:00:00' ), __( 'bookings', 'osam-bookings' ), admin_url( 'admin.php?page=osb-bookings&from=' . $today . '&to=' . $today . '&order=asc' ), 'dashicons-clock' ),
			array( __( 'Next 7 days', 'osam-bookings' ), self::count_between( $today . ' 00:00:00', $week_end . ' 00:00:00' ), __( 'bookings', 'osam-bookings' ), admin_url( 'admin.php?page=osb-calendar' ), 'dashicons-calendar' ),
			array( __( 'Awaiting approval', 'osam-bookings' ), OSB_Admin::pending_count(), __( 'to review', 'osam-bookings' ), admin_url( 'admin.php?page=osb-bookings&status=pending' ), 'dashicons-flag' ),
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array( __( 'Taken online this month', 'osam-bookings' ), OSB_Settings::money( (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $p WHERE status = 'completed' AND created_at >= %s", $month . ' 00:00:00' ) ) ), __( 'recorded payments', 'osam-bookings' ), admin_url( 'admin.php?page=osb-bookings' ), 'dashicons-chart-area' ),
		);

		list( $todays ) = OSB_DB::query_bookings(
			array(
				'from'     => $today,
				'to'       => $today,
				'order'    => 'asc',
				'per_page' => 50,
			)
		);
		$todays = array_filter(
			$todays,
			static function ( $b ) {
				// Courses appear per session below rather than as one long booking.
				return ! in_array( $b->status, array( 'cancelled' ), true ) && ! in_array( $b->type, array( 'course', 'bundle' ), true );
			}
		);
		$today_sessions = OSB_Courses::sessions_between( $today, $today );
		$intakes        = ! OSB_Settings::feature( 'courses' ) ? array() : array_slice(
			array_filter(
				OSB_Courses::upcoming_intakes( 20 ),
				static function ( $in ) {
					return (bool) OSB_DB::get( 'courses', $in->course_id );
				}
			),
			0,
			4
		);
		list( $recent ) = OSB_DB::query_bookings(
			array(
				'orderby'  => 'created_at',
				'order'    => 'desc',
				'per_page' => 6,
			)
		);
		$events    = OSB_Settings::feature( 'events' ) ? OSB_DB::upcoming_events( 4 ) : null;
		$checklist = self::checklist();
		$done      = count( array_filter( wp_list_pluck( $checklist, 0 ) ) );
		$names     = self::name_maps();

		OSB_Admin::open(
			__( 'Dashboard', 'osam-bookings' ),
			array(
				'subtitle' => esc_html( date_i18n( 'l j F Y' ) ),
				'actions'  => array(
					array( __( 'Calendar', 'osam-bookings' ), admin_url( 'admin.php?page=osb-calendar' ), 'secondary', 'dashicons-calendar-alt' ),
					array( __( 'Add booking', 'osam-bookings' ), admin_url( 'admin.php?page=osb-bookings&action=new' ), 'primary', 'dashicons-plus-alt2' ),
				),
			)
		);
		?>
		<?php if ( $done < count( $checklist ) ) : ?>
			<div class="osb-card osb-setup">
				<div class="osb-setup__head">
					<div>
						<h2><?php esc_html_e( 'Finish setting up', 'osam-bookings' ); ?></h2>
						<p><?php /* translators: 1: done 2: total */ echo esc_html( sprintf( __( '%1$d of %2$d steps complete', 'osam-bookings' ), $done, count( $checklist ) ) ); ?></p>
					</div>
					<div class="osb-setup__side">
					<?php if ( current_user_can( 'manage_options' ) ) : ?><a class="osb-btn osb-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-setup' ) ); ?>"><span class="dashicons dashicons-admin-tools"></span><?php esc_html_e( 'Run the setup wizard', 'osam-bookings' ); ?></a><?php endif; ?>
					<div class="osb-meter" role="progressbar" aria-valuenow="<?php echo (int) $done; ?>" aria-valuemin="0" aria-valuemax="<?php echo count( $checklist ); ?>"><span style="width:<?php echo (int) round( $done / count( $checklist ) * 100 ); ?>%"></span></div>
					</div>
				</div>
				<ol class="osb-checklist">
					<?php foreach ( $checklist as $item ) : ?>
						<li class="<?php echo $item[0] ? 'is-done' : ''; ?>">
							<span class="osb-check dashicons <?php echo $item[0] ? 'dashicons-yes' : ''; ?>" aria-hidden="true"></span>
							<span class="osb-checklist__text"><strong><?php echo esc_html( $item[1] ); ?></strong><small><?php echo esc_html( $item[2] ); ?></small></span>
							<?php if ( ! $item[0] ) : ?><a class="osb-btn osb-btn--ghost osb-btn--sm" href="<?php echo esc_url( $item[3] ); ?>"><?php echo esc_html( $item[4] ); ?></a><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>
		<?php endif; ?>

		<div class="osb-stats">
			<?php foreach ( $stats as $s ) : ?>
				<a class="osb-stat" href="<?php echo esc_url( $s[3] ); ?>">
					<span class="osb-stat__icon dashicons <?php echo esc_attr( $s[4] ); ?>" aria-hidden="true"></span>
					<span class="osb-stat__label"><?php echo esc_html( $s[0] ); ?></span>
					<span class="osb-stat__value"><?php echo esc_html( $s[1] ); ?></span>
					<span class="osb-stat__sub"><?php echo esc_html( $s[2] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<div class="osb-grid-2">
			<div class="osb-card">
				<?php OSB_Admin::card_head( __( 'Today’s schedule', 'osam-bookings' ) ); ?>
				<?php if ( ! $todays && ! $today_sessions ) : ?>
					<p class="osb-muted osb-pad"><?php esc_html_e( 'Nothing booked for today.', 'osam-bookings' ); ?></p>
				<?php else : ?>
					<ul class="osb-agenda">
						<?php
						// Course sessions and bookings in one list, in time order.
						$agenda = array();
						foreach ( $today_sessions as $cs ) :
							$co = isset( $names['courses'][ (int) $cs->course_id ] ) ? $names['courses'][ (int) $cs->course_id ] : null;
							if ( ! $co ) {
								continue;
							}
							ob_start();
							?>
							<li>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-courses&action=register&id=' . (int) $cs->intake_id ) ); ?>">
									<span class="osb-agenda__time"><?php echo esc_html( mysql2date( get_option( 'time_format' ), $cs->start_at ) ); ?></span>
									<span class="osb-agenda__bar" style="background:<?php echo esc_attr( $co->color ); ?>"></span>
									<span class="osb-agenda__body"><strong><?php echo esc_html( $co->name ); ?></strong><small><?php /* translators: %d people */ echo esc_html( sprintf( _n( '%d enrolled', '%d enrolled', OSB_Courses::seats_taken( $cs->intake_id ), 'osam-bookings' ), OSB_Courses::seats_taken( $cs->intake_id ) ) . ( isset( $names['staff'][ (int) $cs->staff_id ] ) && ! OSB_DB::is_solo() ? ' · ' . $names['staff'][ (int) $cs->staff_id ] : '' ) ); ?></small></span>
									<span class="osb-pill osb-pill--neutral"><?php esc_html_e( 'Course', 'osam-bookings' ); ?></span>
								</a>
							</li>
							<?php
							$agenda[] = array( $cs->start_at, ob_get_clean() );
						endforeach;
						foreach ( $todays as $b ) :
							ob_start();
							?>
							<li>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $b->id ) ); ?>">
									<span class="osb-agenda__time"><?php echo esc_html( mysql2date( get_option( 'time_format' ), $b->start_at ) ); ?></span>
									<span class="osb-agenda__bar" style="background:<?php echo esc_attr( self::item_color( $b, $names ) ); ?>"></span>
									<span class="osb-agenda__body"><strong><?php echo esc_html( $b->customer_name ); ?></strong><small><?php echo esc_html( self::item_name( $b, $names ) . ( isset( $names['staff'][ (int) $b->staff_id ] ) ? ' · ' . $names['staff'][ (int) $b->staff_id ] : '' ) ); ?></small></span>
									<?php echo OSB_Admin::status_pill( $b->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</a>
							</li>
							<?php
							$agenda[] = array( $b->start_at, ob_get_clean() );
						endforeach;
						usort(
							$agenda,
							static function ( $x, $y ) {
								return strcmp( $x[0], $y[0] );
							}
						);
						foreach ( $agenda as $row ) {
							echo $row[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
						}
						?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="osb-stack">
				<?php if ( null !== $events ) : ?>
				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Upcoming events', 'osam-bookings' ) ); ?>
					<?php if ( ! $events ) : ?>
						<p class="osb-muted osb-pad"><?php esc_html_e( 'No upcoming events.', 'osam-bookings' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-events&action=new' ) ); ?>"><?php esc_html_e( 'Add one', 'osam-bookings' ); ?></a></p>
					<?php else : ?>
						<ul class="osb-eventlist">
							<?php
							foreach ( $events as $e ) :
								$taken = OSB_DB::event_seats_taken( $e->id );
								$pct   = $e->capacity ? min( 100, round( $taken / $e->capacity * 100 ) ) : 0;
								?>
								<li>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings&event_id=' . $e->id ) ); ?>">
										<span class="osb-datebadge" style="background:<?php echo esc_attr( $e->color ); ?>"><b><?php echo esc_html( mysql2date( 'j', $e->start_at ) ); ?></b><?php echo esc_html( mysql2date( 'M', $e->start_at ) ); ?></span>
										<span class="osb-eventlist__body"><strong><?php echo esc_html( $e->name ); ?></strong>
											<span class="osb-meter osb-meter--sm"><span style="width:<?php echo (int) $pct; ?>%;background:<?php echo esc_attr( $e->color ); ?>"></span></span>
											<small><?php /* translators: 1: taken 2: capacity */ echo esc_html( sprintf( __( '%1$d of %2$d places booked', 'osam-bookings' ), $taken, $e->capacity ) ); ?></small>
										</span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<?php if ( $intakes ) : ?>
				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Upcoming courses', 'osam-bookings' ) ); ?>
					<ul class="osb-eventlist">
						<?php
						foreach ( $intakes as $in ) :
							$co    = $names['courses'][ (int) $in->course_id ];
							$taken = OSB_Courses::seats_taken( $in->id );
							$cap   = OSB_Courses::capacity( $in, $co );
							$pct   = $cap ? min( 100, round( $taken / $cap * 100 ) ) : 0;
							?>
							<li>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-courses&action=register&id=' . $in->id ) ); ?>">
									<span class="osb-datebadge" style="background:<?php echo esc_attr( $co->color ); ?>"><b><?php echo esc_html( mysql2date( 'j', $in->first_start ) ); ?></b><?php echo esc_html( mysql2date( 'M', $in->first_start ) ); ?></span>
									<span class="osb-eventlist__body"><strong><?php echo esc_html( $co->name ); ?></strong>
										<span class="osb-meter osb-meter--sm"><span style="width:<?php echo (int) $pct; ?>%;background:<?php echo esc_attr( $co->color ); ?>"></span></span>
										<small><?php /* translators: 1: taken 2: capacity 3: dates */ echo esc_html( sprintf( __( '%1$d of %2$d places · %3$s', 'osam-bookings' ), $taken, $cap, OSB_Courses::range_label( $in ) ) ); ?></small>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>

				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Latest bookings', 'osam-bookings' ) ); ?>
					<?php if ( ! $recent ) : ?>
						<p class="osb-muted osb-pad"><?php esc_html_e( 'No bookings yet.', 'osam-bookings' ); ?></p>
					<?php else : ?>
						<ul class="osb-recent">
							<?php foreach ( $recent as $b ) : ?>
								<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $b->id ) ); ?>">
									<?php echo OSB_Admin::avatar( $b->customer_name, 0, self::item_color( $b, $names ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span><strong><?php echo esc_html( $b->customer_name ); ?></strong><small><?php echo esc_html( self::item_name( $b, $names ) . ' · ' . mysql2date( 'D j M, ' . get_option( 'time_format' ), $b->start_at ) ); ?></small></span>
									<em><?php /* translators: %s human time */ echo esc_html( sprintf( __( '%s ago', 'osam-bookings' ), human_time_diff( strtotime( $b->created_at ), strtotime( current_time( 'mysql' ) ) ) ) ); ?></em>
								</a></li>
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
	 * Lookup maps.
	 *
	 * @return array
	 */
	public static function name_maps() {
		$out = array(
			'services' => array(),
			'events'   => array(),
			'courses'  => array(),
			'staff'    => array(),
		);
		foreach ( OSB_Courses::courses() as $co ) {
			$out['courses'][ (int) $co->id ] = $co;
		}
		foreach ( OSB_DB::services() as $s ) {
			$out['services'][ (int) $s->id ] = $s;
		}
		foreach ( OSB_DB::events() as $e ) {
			$out['events'][ (int) $e->id ] = $e;
		}
		foreach ( OSB_DB::staff() as $st ) {
			$out['staff'][ (int) $st->id ] = $st->name;
		}
		return $out;
	}

	/**
	 * Name for a booking's service/event.
	 *
	 * @param object $b     Booking.
	 * @param array  $names Maps.
	 * @return string
	 */
	public static function item_name( $b, $names ) {
		if ( 'table' === $b->type ) {
			return sprintf( OSB_Settings::label( 'table_item' ), (int) $b->seats );
		}
		if ( 'resource' === $b->type ) {
			$names = array();
			foreach ( OSB_Resources::lines( $b->id ) as $l ) {
				$r       = OSB_Resources::get( $l->resource_id );
				$names[] = $r ? $r->name : '—';
			}
			return $names ? implode( ' + ', $names ) : '—';
		}
		if ( 'bundle' === $b->type ) {
			$bun = OSB_Bundles::get( $b->bundle_id );
			/* translators: %s bundle name */
			return sprintf( __( 'Bundle: %s', 'osam-bookings' ), $bun ? $bun->name : '—' );
		}
		if ( 'course' === $b->type ) {
			return isset( $names['courses'][ (int) $b->course_id ] ) ? $names['courses'][ (int) $b->course_id ]->name : '—';
		}
		if ( 'event' === $b->type ) {
			return isset( $names['events'][ (int) $b->event_id ] ) ? $names['events'][ (int) $b->event_id ]->name : '—';
		}
		return isset( $names['services'][ (int) $b->service_id ] ) ? $names['services'][ (int) $b->service_id ]->name : '—';
	}

	/**
	 * Colour for a booking's service/event.
	 *
	 * @param object $b     Booking.
	 * @param array  $names Maps.
	 * @return string
	 */
	public static function item_color( $b, $names ) {
		if ( 'table' === $b->type ) {
			return '#c2255c';
		}
		if ( 'resource' === $b->type ) {
			$l = current( OSB_Resources::lines( $b->id ) );
			$r = $l ? OSB_Resources::get( $l->resource_id ) : null;
			return $r ? $r->color : '#0c8599';
		}
		if ( 'course' === $b->type ) {
			return isset( $names['courses'][ (int) $b->course_id ] ) ? $names['courses'][ (int) $b->course_id ]->color : '#868e96';
		}
		if ( 'event' === $b->type ) {
			return isset( $names['events'][ (int) $b->event_id ] ) ? $names['events'][ (int) $b->event_id ]->color : '#868e96';
		}
		return isset( $names['services'][ (int) $b->service_id ] ) ? $names['services'][ (int) $b->service_id ]->color : '#868e96';
	}
}
