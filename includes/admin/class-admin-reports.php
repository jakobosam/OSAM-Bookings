<?php
/**
 * Reports admin.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Revenue, bookings, occupancy, cancellations and no-shows for a date range.
 */
class OSB_Admin_Reports {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_export_report', array( __CLASS__, 'export' ) );
	}

	/**
	 * Quick ranges.
	 *
	 * @return array key => [ label, from, to ]
	 */
	public static function presets() {
		$today = current_time( 'Y-m-d' );
		$ts    = strtotime( $today );
		return array(
			'7d'         => array( __( 'Last 7 days', 'osam-bookings' ), gmdate( 'Y-m-d', strtotime( '-6 days', $ts ) ), $today ),
			'30d'        => array( __( 'Last 30 days', 'osam-bookings' ), gmdate( 'Y-m-d', strtotime( '-29 days', $ts ) ), $today ),
			'this_month' => array( __( 'This month', 'osam-bookings' ), gmdate( 'Y-m-01', $ts ), gmdate( 'Y-m-t', $ts ) ),
			'last_month' => array( __( 'Last month', 'osam-bookings' ), gmdate( 'Y-m-01', strtotime( 'first day of last month', $ts ) ), gmdate( 'Y-m-t', strtotime( 'first day of last month', $ts ) ) ),
			'next_30d'   => array( __( 'Next 30 days', 'osam-bookings' ), $today, gmdate( 'Y-m-d', strtotime( '+29 days', $ts ) ) ),
			'this_year'  => array( __( 'This year', 'osam-bookings' ), gmdate( 'Y-01-01', $ts ), gmdate( 'Y-12-31', $ts ) ),
		);
	}

	/**
	 * Range from the request.
	 *
	 * @return array [ from, to, preset, basis ]
	 */
	public static function range() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$presets = self::presets();
		$preset  = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : '30d';
		$basis   = isset( $_GET['basis'] ) && 'booked' === $_GET['basis'] ? 'booked' : 'visit';
		$from    = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to      = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:enable
		if ( 'custom' === $preset && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			if ( $from > $to ) {
				list( $from, $to ) = array( $to, $from );
			}
			// Keep it sensible: at most about five years.
			if ( strtotime( $to ) - strtotime( $from ) > 1830 * DAY_IN_SECONDS ) {
				$from = gmdate( 'Y-m-d', strtotime( $to . ' -1830 days' ) );
			}
			return array( $from, $to, 'custom', $basis );
		}
		$preset = isset( $presets[ $preset ] ) ? $preset : '30d';
		return array( $presets[ $preset ][1], $presets[ $preset ][2], $preset, $basis );
	}

	/**
	 * Change versus the previous period.
	 *
	 * @param float $now    This period.
	 * @param float $before Previous period.
	 * @param bool  $good_up Is up good?
	 * @return string HTML
	 */
	private static function delta( $now, $before, $good_up = true ) {
		if ( ! $before && ! $now ) {
			return '';
		}
		if ( ! $before ) {
			return '<span class="osb-delta osb-delta--' . ( $good_up ? 'up' : 'down' ) . '">' . esc_html__( 'new', 'osam-bookings' ) . '</span>';
		}
		$pct = round( 100 * ( $now - $before ) / abs( $before ) );
		if ( 0.0 === (float) $pct ) {
			return '<span class="osb-delta">±0%</span>';
		}
		$up   = $pct > 0;
		$good = $up === $good_up;
		return '<span class="osb-delta osb-delta--' . ( $good ? 'up' : 'down' ) . '">' . ( $up ? '▲ ' : '▼ ' ) . esc_html( abs( $pct ) . '%' ) . '</span>';
	}

	/**
	 * Horizontal bar row.
	 *
	 * @param float  $value Value.
	 * @param float  $max   Max.
	 * @param string $color Colour.
	 * @return string
	 */
	private static function bar( $value, $max, $color = '' ) {
		$w = $max > 0 ? max( $value > 0 ? 2 : 0, round( 100 * $value / $max, 1 ) ) : 0;
		return '<span class="osb-hbar"><span style="width:' . esc_attr( $w ) . '%' . ( $color ? ';background:' . esc_attr( $color ) : '' ) . '"></span></span>';
	}

	/**
	 * Page.
	 */
	public static function page() {
		list( $from, $to, $preset, $basis ) = self::range();
		$len      = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
		$pfrom    = gmdate( 'Y-m-d', strtotime( $from . ' -' . $len . ' days' ) );
		$pto      = gmdate( 'Y-m-d', strtotime( $from . ' -1 day' ) );
		$sum      = OSB_Reports::summary( $from, $to, $basis );
		$prev     = OSB_Reports::summary( $pfrom, $pto, $basis );
		$timeline = OSB_Reports::timeline( $from, $to, $basis );
		$items    = OSB_Reports::by_item( $from, $to, $basis );
		$occ      = OSB_Reports::occupancy( $from, $to );
		$pocc     = OSB_Reports::occupancy( $pfrom, $pto );
		$solo     = OSB_DB::is_solo();
		$staff    = $solo ? array() : OSB_Reports::by_staff( $from, $to, $basis );
		$weekdays = OSB_Reports::by_weekday( $from, $to );
		$hours    = OSB_Reports::by_hour( $from, $to );
		$top      = OSB_Reports::top_customers( $from, $to, $basis );
		$codes    = OSB_Settings::feature( 'coupons' ) ? OSB_Reports::codes( $from, $to, $basis ) : array();
		$args     = array( 'range' => $preset, 'basis' => $basis, 'from' => 'custom' === $preset ? $from : false, 'to' => 'custom' === $preset ? $to : false );
		$base     = admin_url( 'admin.php?page=osb-reports' );
		$export   = wp_nonce_url( add_query_arg( array_filter( $args ), admin_url( 'admin-post.php?action=osb_export_report' ) ), 'osb_export_report' );

		OSB_Admin::open(
			__( 'Reports', 'osam-bookings' ),
			array(
				/* translators: 1: from 2: to 3: previous from 4: previous to */
				'subtitle' => esc_html( sprintf( __( '%1$s – %2$s, compared with %3$s – %4$s.', 'osam-bookings' ), date_i18n( 'j M Y', strtotime( $from ) ), date_i18n( 'j M Y', strtotime( $to ) ), date_i18n( 'j M', strtotime( $pfrom ) ), date_i18n( 'j M Y', strtotime( $pto ) ) ) ),
				'actions'  => array( array( __( 'Export CSV', 'osam-bookings' ), $export, 'secondary', 'dashicons-download' ) ),
			)
		);
		?>
		<form method="get" class="osb-toolbar osb-reportbar">
			<input type="hidden" name="page" value="osb-reports">
			<div class="osb-segmented">
				<?php foreach ( self::presets() as $k => $p ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'range' => $k, 'basis' => $basis ), $base ) ); ?>" class="<?php echo $preset === $k ? 'is-active' : ''; ?>"><?php echo esc_html( $p[0] ); ?></a>
				<?php endforeach; ?>
			</div>
			<input type="hidden" name="range" value="custom">
			<input type="hidden" name="basis" value="<?php echo esc_attr( $basis ); ?>">
			<label class="osb-daterange"><input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" aria-label="<?php esc_attr_e( 'From', 'osam-bookings' ); ?>"><span>→</span><input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" aria-label="<?php esc_attr_e( 'To', 'osam-bookings' ); ?>"></label>
			<button class="osb-btn osb-btn--ghost"><?php esc_html_e( 'Show', 'osam-bookings' ); ?></button>
			<span class="osb-reportbar__basis">
				<?php esc_html_e( 'Count by', 'osam-bookings' ); ?>
				<a href="<?php echo esc_url( add_query_arg( array_merge( array_filter( $args ), array( 'basis' => 'visit' ) ), $base ) ); ?>" class="<?php echo 'visit' === $basis ? 'is-active' : ''; ?>"><?php esc_html_e( 'appointment date', 'osam-bookings' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( array_merge( array_filter( $args ), array( 'basis' => 'booked' ) ), $base ) ); ?>" class="<?php echo 'booked' === $basis ? 'is-active' : ''; ?>"><?php esc_html_e( 'date booked', 'osam-bookings' ); ?></a>
			</span>
		</form>

		<?php
		$kpis = array(
			array( __( 'Revenue (paid)', 'osam-bookings' ), OSB_Settings::money( $sum['revenue'] ), self::delta( $sum['revenue'], $prev['revenue'] ), $sum['outstanding'] > 0 ? sprintf( /* translators: %s amount */ __( '%s still to pay', 'osam-bookings' ), OSB_Settings::money( $sum['outstanding'] ) ) : '', 'dashicons-money-alt' ),
			array( __( 'Bookings', 'osam-bookings' ), number_format_i18n( $sum['bookings'] ), self::delta( $sum['bookings'], $prev['bookings'] ), $sum['places'] > $sum['bookings'] ? sprintf( /* translators: %s places */ __( '%s places', 'osam-bookings' ), number_format_i18n( $sum['places'] ) ) : sprintf( /* translators: %s amount */ __( '%s average', 'osam-bookings' ), OSB_Settings::money( $sum['avg_value'] ) ), 'dashicons-calendar-alt' ),
			array( __( 'Diary booked', 'osam-bookings' ), null === $occ['overall'] ? '—' : $occ['overall'] . '%', null === $occ['overall'] || null === $pocc['overall'] ? '' : self::delta( $occ['overall'], $pocc['overall'] ), $occ['open'] ? sprintf( /* translators: 1: booked hours 2: open hours */ __( '%1$s of %2$s working hours', 'osam-bookings' ), number_format_i18n( $occ['booked'] / 60, 0 ), number_format_i18n( $occ['open'] / 60, 0 ) ) : __( 'No working hours set', 'osam-bookings' ), 'dashicons-clock' ),
			array( __( 'Customers', 'osam-bookings' ), number_format_i18n( $sum['customers'] ), self::delta( $sum['customers'], $prev['customers'] ), sprintf( /* translators: %s count */ __( '%s new', 'osam-bookings' ), number_format_i18n( $sum['new_customers'] ) ), 'dashicons-groups' ),
		);
		?>
		<div class="osb-stats">
			<?php foreach ( $kpis as $k ) : ?>
				<div class="osb-stat">
					<span class="osb-stat__icon dashicons <?php echo esc_attr( $k[4] ); ?>" aria-hidden="true"></span>
					<span class="osb-stat__label"><?php echo esc_html( $k[0] ); ?></span>
					<span class="osb-stat__value"><?php echo esc_html( $k[1] ); ?> <?php echo wp_kses_post( $k[2] ); ?></span>
					<span class="osb-stat__sub"><?php echo esc_html( $k[3] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="osb-minirow">
			<div class="osb-mini"><span><?php esc_html_e( 'Cancellation rate', 'osam-bookings' ); ?></span><strong><?php echo esc_html( $sum['cancel_rate'] . '%' ); ?></strong><?php echo wp_kses_post( self::delta( $sum['cancel_rate'], $prev['cancel_rate'], false ) ); ?><small><?php /* translators: %d count */ echo esc_html( sprintf( _n( '%d cancelled', '%d cancelled', $sum['cancelled'], 'osam-bookings' ), $sum['cancelled'] ) ); ?></small></div>
			<div class="osb-mini"><span><?php esc_html_e( 'No-show rate', 'osam-bookings' ); ?></span><strong><?php echo esc_html( $sum['no_show_rate'] . '%' ); ?></strong><?php echo wp_kses_post( self::delta( $sum['no_show_rate'], $prev['no_show_rate'], false ) ); ?><small><?php /* translators: %d count */ echo esc_html( sprintf( _n( '%d no-show', '%d no-shows', $sum['no_shows'], 'osam-bookings' ), $sum['no_shows'] ) ); ?></small></div>
			<?php if ( null !== $occ['events'] && OSB_Settings::feature( 'events' ) ) : ?>
				<div class="osb-mini"><span><?php esc_html_e( 'Event places filled', 'osam-bookings' ); ?></span><strong><?php echo esc_html( $occ['events'] . '%' ); ?></strong></div>
			<?php endif; ?>
			<?php if ( null !== $occ['courses'] && OSB_Settings::feature( 'courses' ) ) : ?>
				<div class="osb-mini"><span><?php esc_html_e( 'Course places filled', 'osam-bookings' ); ?></span><strong><?php echo esc_html( $occ['courses'] . '%' ); ?></strong></div>
			<?php endif; ?>
			<?php if ( $sum['discounts'] > 0 ) : ?>
				<div class="osb-mini"><span><?php esc_html_e( 'Discounts given', 'osam-bookings' ); ?></span><strong><?php echo esc_html( OSB_Settings::money( $sum['discounts'] ) ); ?></strong></div>
			<?php endif; ?>
			<?php if ( $sum['refunded'] > 0 ) : ?>
				<div class="osb-mini"><span><?php esc_html_e( 'Refunded', 'osam-bookings' ); ?></span><strong><?php echo esc_html( OSB_Settings::money( $sum['refunded'] ) ); ?></strong></div>
			<?php endif; ?>
		</div>

		<?php
		$buckets = $timeline['buckets'];
		$maxb    = max( 1, max( wp_list_pluck( $buckets, 'bookings' ) ) );
		$maxr    = max( wp_list_pluck( $buckets, 'revenue' ) );
		$units   = array(
			'day'   => __( 'per day', 'osam-bookings' ),
			'week'  => __( 'per week', 'osam-bookings' ),
			'month' => __( 'per month', 'osam-bookings' ),
		);
		?>
		<div class="osb-card">
			<?php /* translators: %s per day / week / month */ OSB_Admin::card_head( sprintf( __( 'Bookings %s', 'osam-bookings' ), $units[ $timeline['unit'] ] ), '<span class="osb-rlegend"><i class="osb-legend__b"></i>' . esc_html__( 'Bookings', 'osam-bookings' ) . ( $maxr > 0 ? ' <i class="osb-legend__r"></i>' . esc_html__( 'Revenue', 'osam-bookings' ) : '' ) . '</span>' ); ?>
			<div class="osb-chart" style="--n:<?php echo (int) count( $buckets ); ?>">
				<div class="osb-chart__axis"><span><?php echo (int) $maxb; ?></span><span><?php echo (int) round( $maxb / 2 ); ?></span><span>0</span></div>
				<div class="osb-chart__plot">
					<?php
					$i     = 0;
					$every = max( 1, (int) ceil( count( $buckets ) / 12 ) );
					foreach ( $buckets as $bk ) :
						$tip = $bk['label'] . ': ' . sprintf( /* translators: %d bookings */ _n( '%d booking', '%d bookings', $bk['bookings'], 'osam-bookings' ), $bk['bookings'] ) . ( $maxr > 0 ? ' · ' . OSB_Settings::money( $bk['revenue'] ) : '' );
						?>
						<div class="osb-chart__col<?php echo $bk['weekend'] ? ' is-weekend' : ''; ?>" title="<?php echo esc_attr( $tip ); ?>">
							<span class="osb-chart__bar" style="height:<?php echo esc_attr( round( 100 * $bk['bookings'] / $maxb, 1 ) ); ?>%"><?php if ( $bk['bookings'] && count( $buckets ) <= 31 ) : ?><em><?php echo (int) $bk['bookings']; ?></em><?php endif; ?></span>
							<?php if ( $maxr > 0 ) : ?><span class="osb-chart__rev" style="bottom:<?php echo esc_attr( round( 100 * $bk['revenue'] / $maxr, 1 ) ); ?>%"></span><?php endif; ?>
							<span class="osb-chart__label"><?php echo 0 === $i % $every ? esc_html( $bk['label'] ) : ''; ?></span>
						</div>
						<?php
						++$i;
					endforeach;
					?>
				</div>
			</div>
		</div>

		<div class="osb-grid-2">
			<div class="osb-card osb-card--table">
				<?php OSB_Admin::card_head( __( 'Revenue by service, event and course', 'osam-bookings' ) ); ?>
				<?php if ( ! $items ) : ?>
					<p class="osb-muted osb-pad"><?php esc_html_e( 'No bookings in this period.', 'osam-bookings' ); ?></p>
				<?php else : ?>
					<?php $maxi = max( wp_list_pluck( $items, 'revenue' ) ); ?>
					<table class="osb-table osb-reporttable">
						<thead><tr><th><?php esc_html_e( 'Name', 'osam-bookings' ); ?></th><th class="osb-num"><?php esc_html_e( 'Bookings', 'osam-bookings' ); ?></th><th class="osb-num"><?php esc_html_e( 'Revenue', 'osam-bookings' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( array_slice( $items, 0, 15 ) as $r ) : ?>
							<tr>
								<td><span class="osb-dot" style="background:<?php echo esc_attr( $r['color'] ); ?>"></span><?php echo esc_html( $r['name'] ); ?><?php echo 'service' !== $r['type'] ? ' <span class="osb-pill osb-pill--neutral">' . esc_html( 'event' === $r['type'] ? __( 'Event', 'osam-bookings' ) : __( 'Course', 'osam-bookings' ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo self::bar( $r['revenue'], $maxi, $r['color'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td class="osb-num"><?php echo (int) $r['bookings']; ?><?php echo $r['places'] > $r['bookings'] ? '<small>' . esc_html( sprintf( /* translators: %d places */ __( '%d places', 'osam-bookings' ), $r['places'] ) ) . '</small>' : ''; ?></td>
								<td class="osb-num"><strong><?php echo esc_html( OSB_Settings::money( $r['revenue'] ) ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php if ( $staff ) : ?>
			<div class="osb-card osb-card--table">
				<?php OSB_Admin::card_head( __( 'By staff member', 'osam-bookings' ), __( 'Diary booked = appointment time ÷ working hours.', 'osam-bookings' ) ); ?>
				<table class="osb-table osb-reporttable">
					<thead><tr><th><?php esc_html_e( 'Name', 'osam-bookings' ); ?></th><th class="osb-num"><?php esc_html_e( 'Bookings', 'osam-bookings' ); ?></th><th class="osb-num"><?php esc_html_e( 'Revenue', 'osam-bookings' ); ?></th><th class="osb-num"><?php esc_html_e( 'Diary booked', 'osam-bookings' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $staff as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r['name'] ); ?></td>
							<td class="osb-num"><?php echo (int) $r['bookings']; ?></td>
							<td class="osb-num"><strong><?php echo esc_html( OSB_Settings::money( $r['revenue'] ) ); ?></strong></td>
							<td class="osb-num"><?php echo null === $r['occupancy'] ? '<span class="osb-muted">—</span>' : esc_html( $r['occupancy'] . '%' ) . self::bar( $r['occupancy'], 100 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php else : ?>
			<div class="osb-card">
				<?php OSB_Admin::card_head( __( 'Busiest times', 'osam-bookings' ) ); ?>
				<?php self::busiest( $weekdays, $hours ); ?>
			</div>
			<?php endif; ?>
		</div>

		<div class="osb-grid-2">
			<?php if ( $staff ) : ?>
			<div class="osb-card">
				<?php OSB_Admin::card_head( __( 'Busiest times', 'osam-bookings' ) ); ?>
				<?php self::busiest( $weekdays, $hours ); ?>
			</div>
			<?php endif; ?>

			<div class="osb-card osb-card--table">
				<?php OSB_Admin::card_head( __( 'Top customers', 'osam-bookings' ) ); ?>
				<?php if ( ! $top ) : ?>
					<p class="osb-muted osb-pad"><?php esc_html_e( 'No bookings in this period.', 'osam-bookings' ); ?></p>
				<?php else : ?>
				<table class="osb-table osb-reporttable">
					<thead><tr><th><?php esc_html_e( 'Customer', 'osam-bookings' ); ?></th><th class="osb-num"><?php esc_html_e( 'Bookings', 'osam-bookings' ); ?></th><th class="osb-num"><?php esc_html_e( 'Spent', 'osam-bookings' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $top as $c ) : ?>
						<tr>
							<td><?php if ( OSB_Settings::feature( 'customers' ) ) : ?><a href="<?php echo esc_url( OSB_Admin_Customers::url( $c->email ) ); ?>"><strong><?php echo esc_html( $c->name ); ?></strong></a><?php else : ?><strong><?php echo esc_html( $c->name ); ?></strong><?php endif; ?><small><?php echo esc_html( $c->email ); ?></small></td>
							<td class="osb-num"><?php echo (int) $c->bookings; ?></td>
							<td class="osb-num"><strong><?php echo esc_html( OSB_Settings::money( $c->spent ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>

			<?php if ( $codes ) : ?>
			<div class="osb-card osb-card--table">
				<?php OSB_Admin::card_head( __( 'Discount codes & vouchers used', 'osam-bookings' ) ); ?>
				<table class="osb-table osb-reporttable">
					<thead><tr><th><?php esc_html_e( 'Code', 'osam-bookings' ); ?></th><th class="osb-num"><?php esc_html_e( 'Uses', 'osam-bookings' ); ?></th><th class="osb-num"><?php esc_html_e( 'Discount', 'osam-bookings' ); ?></th><th class="osb-num"><?php esc_html_e( 'Revenue', 'osam-bookings' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $codes as $c ) : ?>
						<tr><td><code><?php echo esc_html( $c->code ); ?></code></td><td class="osb-num"><?php echo (int) $c->uses; ?></td><td class="osb-num"><?php echo esc_html( OSB_Settings::money( $c->discount ) ); ?></td><td class="osb-num"><?php echo esc_html( OSB_Settings::money( $c->revenue ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<p class="osb-help"><?php esc_html_e( 'Revenue counts bookings marked paid. Bookings awaiting online payment aren’t counted. Course bookings are dated by their first session.', 'osam-bookings' ); ?></p>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Weekday and hour bars.
	 *
	 * @param array $weekdays 1..7 => n.
	 * @param array $hours    hour => n.
	 */
	private static function busiest( $weekdays, $hours ) {
		global $wp_locale;
		$maxd = max( 1, max( $weekdays ) );
		?>
		<div class="osb-busy">
			<h3><?php esc_html_e( 'Day of the week', 'osam-bookings' ); ?></h3>
			<?php foreach ( $weekdays as $d => $n ) : ?>
				<div class="osb-busy__row"><span><?php echo esc_html( $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $d % 7 ) ) ); ?></span><?php echo self::bar( $n, $maxd ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><b><?php echo (int) $n; ?></b></div>
			<?php endforeach; ?>
			<?php if ( $hours ) : ?>
				<?php $maxh = max( 1, max( $hours ) ); ?>
				<h3><?php esc_html_e( 'Start time (appointments)', 'osam-bookings' ); ?></h3>
				<div class="osb-hours">
					<?php foreach ( $hours as $h => $n ) : ?>
						<div class="osb-hours__col" title="<?php echo esc_attr( sprintf( '%02d:00 — %d', $h, $n ) ); ?>"><span style="height:<?php echo esc_attr( round( 100 * $n / $maxh ) ); ?>%"></span><small><?php echo esc_html( sprintf( '%02d', $h ) ); ?></small></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * CSV.
	 */
	public static function export() {
		OSB_Admin::verify( 'osb_export_report' );
		list( $from, $to, , $basis ) = self::range();
		OSB_Admin_Customers::csv( 'report-' . $from . '-to-' . $to . '.csv', OSB_Reports::csv_rows( $from, $to, $basis ) );
	}
}
