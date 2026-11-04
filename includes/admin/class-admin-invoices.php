<?php
/**
 * Invoices admin: list with search and filters, one invoice, credit notes, exports and settings.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bookings → Invoices.
 */
class OSB_Admin_Invoices {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_invoice_email', array( __CLASS__, 'email' ) );
		add_action( 'admin_post_osb_invoice_credit', array( __CLASS__, 'credit' ) );
		add_action( 'admin_post_osb_invoice_billto', array( __CLASS__, 'save_billto' ) );
		add_action( 'admin_post_osb_invoice_create', array( __CLASS__, 'create' ) );
		add_action( 'admin_post_osb_invoices_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_osb_invoices_zip', array( __CLASS__, 'zip' ) );
		add_action( 'osb_admin_booking_side', array( __CLASS__, 'booking_card' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * Router.
	 */
	public static function page() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id ) {
			self::view( $id );
			return;
		}
		self::listing();
	}

	/**
	 * URL for one document.
	 *
	 * @param int $id Id.
	 * @return string
	 */
	public static function url( $id ) {
		return admin_url( 'admin.php?page=osb-invoices&id=' . (int) $id );
	}

	/**
	 * Messages after redirects.
	 */
	public static function notices() {
		if ( ! OSB_Admin::is_our_screen() ) {
			return;
		}
		$msg = isset( $_GET['osb_msg'] ) ? sanitize_key( wp_unslash( $_GET['osb_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map = array(
			'inv_emailed'  => __( 'Emailed to the customer.', 'osam-bookings' ),
			'inv_credited' => __( 'Credit note issued.', 'osam-bookings' ),
			'inv_created'  => __( 'Invoice created.', 'osam-bookings' ),
			'inv_none'     => __( 'There’s no completed payment on this booking to invoice.', 'osam-bookings' ),
			'inv_nozip'    => __( 'This server can’t make .zip files (the PHP Zip extension is missing). Download PDFs one at a time or ask your host to enable it.', 'osam-bookings' ),
		);
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', in_array( $msg, array( 'inv_none', 'inv_nozip' ), true ) ? 'notice-warning' : 'notice-success', esc_html( $map[ $msg ] ) );
		}
	}

	/**
	 * Filters from the query string.
	 *
	 * @return array
	 */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$g      = wp_unslash( $_GET );
		$f      = array(
			'search'  => isset( $g['s'] ) ? sanitize_text_field( $g['s'] ) : '',
			'kind'    => isset( $g['kind'] ) ? sanitize_key( $g['kind'] ) : '',
			'status'  => isset( $g['status'] ) ? sanitize_key( $g['status'] ) : '',
			'method'  => isset( $g['method'] ) ? sanitize_key( $g['method'] ) : '',
			'period'  => isset( $g['period'] ) ? sanitize_key( $g['period'] ) : '',
			'from'    => isset( $g['from'] ) ? sanitize_text_field( $g['from'] ) : '',
			'to'      => isset( $g['to'] ) ? sanitize_text_field( $g['to'] ) : '',
			'orderby' => isset( $g['orderby'] ) ? sanitize_key( $g['orderby'] ) : 'issued',
			'order'   => isset( $g['order'] ) && 'asc' === $g['order'] ? 'asc' : 'desc',
			'page'    => isset( $g['paged'] ) ? max( 1, absint( $g['paged'] ) ) : 1,
		);
		// phpcs:enable
		$range = self::period_range( $f['period'] );
		if ( $range ) {
			list( $f['from'], $f['to'] ) = $range;
		}
		return $f;
	}

	/**
	 * Quick date ranges.
	 *
	 * @return array key => label
	 */
	private static function periods() {
		return array(
			''           => __( 'Any date', 'osam-bookings' ),
			'this_month' => __( 'This month', 'osam-bookings' ),
			'last_month' => __( 'Last month', 'osam-bookings' ),
			'last_30'    => __( 'Last 30 days', 'osam-bookings' ),
			'this_q'     => __( 'This quarter', 'osam-bookings' ),
			'last_q'     => __( 'Last quarter', 'osam-bookings' ),
			'this_year'  => __( 'This year', 'osam-bookings' ),
			'last_year'  => __( 'Last year', 'osam-bookings' ),
			'tax_year'   => __( 'This tax year (from 6 April)', 'osam-bookings' ),
			'custom'     => __( 'Custom dates…', 'osam-bookings' ),
		);
	}

	/**
	 * Dates for a quick range.
	 *
	 * @param string $p Period.
	 * @return array|null [ from, to ]
	 */
	private static function period_range( $p ) {
		$now = current_datetime();
		$y   = (int) $now->format( 'Y' );
		$m   = (int) $now->format( 'n' );
		$q   = (int) ceil( $m / 3 );
		switch ( $p ) {
			case 'this_month':
				return array( $now->format( 'Y-m-01' ), $now->format( 'Y-m-t' ) );
			case 'last_month':
				$l = $now->modify( 'first day of last month' );
				return array( $l->format( 'Y-m-01' ), $l->format( 'Y-m-t' ) );
			case 'last_30':
				return array( $now->modify( '-29 days' )->format( 'Y-m-d' ), $now->format( 'Y-m-d' ) );
			case 'this_q':
				$s = $now->setDate( $y, ( $q - 1 ) * 3 + 1, 1 );
				return array( $s->format( 'Y-m-d' ), $s->modify( '+2 months' )->format( 'Y-m-t' ) );
			case 'last_q':
				$s = $now->setDate( $y, ( $q - 1 ) * 3 + 1, 1 )->modify( '-3 months' );
				return array( $s->format( 'Y-m-d' ), $s->modify( '+2 months' )->format( 'Y-m-t' ) );
			case 'this_year':
				return array( $y . '-01-01', $y . '-12-31' );
			case 'last_year':
				return array( ( $y - 1 ) . '-01-01', ( $y - 1 ) . '-12-31' );
			case 'tax_year':
				$start = ( $now->format( 'm-d' ) >= '04-06' ) ? $y : $y - 1;
				return array( $start . '-04-06', ( $start + 1 ) . '-04-05' );
		}
		return null;
	}

	/**
	 * Sort link.
	 *
	 * @param string $col   Column.
	 * @param string $label Label.
	 * @param array  $f     Filters.
	 */
	private static function sort_link( $col, $label, $f ) {
		$order = ( $f['orderby'] === $col && 'desc' === $f['order'] ) ? 'asc' : 'desc';
		$arrow = $f['orderby'] === $col ? ( 'asc' === $f['order'] ? ' ↑' : ' ↓' ) : '';
		printf( '<a href="%s">%s%s</a>', esc_url( add_query_arg( array( 'orderby' => $col, 'order' => $order, 'paged' => false ) ) ), esc_html( $label ), esc_html( $arrow ) );
	}

	/**
	 * Status pill.
	 *
	 * @param object $doc Document.
	 * @return string
	 */
	public static function pill( $doc ) {
		$labels = OSB_Invoices::statuses();
		$class  = array(
			'paid'          => 'confirmed',
			'part_credited' => 'warn',
			'credited'      => 'cancelled',
			'issued'        => 'neutral',
		);
		$label = 'credit_note' === $doc->kind ? __( 'Credit note', 'osam-bookings' ) : ( $labels[ $doc->status ] ?? $doc->status );
		return '<span class="osb-pill osb-pill--' . esc_attr( $class[ $doc->status ] ?? 'neutral' ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * The list.
	 */
	private static function listing() {
		$f                            = self::filters();
		$per                          = 50;
		list( $rows, $total, $sums ) = OSB_Invoices::query( array_merge( $f, array( 'per_page' => $per ) ) );
		$base                         = admin_url( 'admin.php?page=osb-invoices' );
		$keep                         = array_filter( array( 's' => $f['search'], 'kind' => $f['kind'], 'status' => $f['status'], 'method' => $f['method'], 'from' => $f['from'], 'to' => $f['to'] ) );
		$export                       = wp_nonce_url( add_query_arg( $keep, admin_url( 'admin-post.php?action=osb_invoices_export' ) ), 'osb_invoices_export' );
		$zip                          = wp_nonce_url( add_query_arg( $keep, admin_url( 'admin-post.php?action=osb_invoices_zip' ) ), 'osb_invoices_zip' );
		$filtered                     = $f['search'] || $f['status'] || $f['method'] || $f['from'] || $f['to'];
		$methods                      = OSB_Invoices::methods_used();
		$cur                          = OSB_Settings::get( 'currency' );
		$vat_on                       = 'inclusive' === OSB_Settings::get( 'inv_vat_mode' ) || abs( $sums['vat'] ) > 0.001;

		OSB_Admin::open(
			__( 'Invoices', 'osam-bookings' ),
			array(
				'subtitle' => __( 'Every payment gets a numbered PDF invoice, attached to the customer’s confirmation email. Refunds are recorded as credit notes.', 'osam-bookings' ),
				'actions'  => array(
					array( __( 'Download PDFs (.zip)', 'osam-bookings' ), $zip, 'secondary', 'dashicons-media-archive' ),
					array( __( 'Export CSV', 'osam-bookings' ), $export, 'secondary', 'dashicons-download' ),
					array( __( 'Invoice settings', 'osam-bookings' ), admin_url( 'admin.php?page=osb-settings&tab=invoices' ), 'secondary', 'dashicons-admin-generic' ),
				),
			)
		);
		?>
		<nav class="osb-tabs osb-inv-tabs">
			<?php
			foreach ( array( '' => __( 'All', 'osam-bookings' ), 'invoice' => __( 'Invoices', 'osam-bookings' ), 'credit_note' => __( 'Credit notes', 'osam-bookings' ) ) as $k => $label ) :
				$u = add_query_arg( array_merge( $keep, array( 'kind' => $k ) ), $base );
				?>
				<a href="<?php echo esc_url( $k ? $u : remove_query_arg( 'kind', $u ) ); ?>" class="<?php echo $f['kind'] === $k ? 'is-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<form method="get" class="osb-toolbar">
			<input type="hidden" name="page" value="osb-invoices">
			<?php if ( $f['kind'] ) : ?><input type="hidden" name="kind" value="<?php echo esc_attr( $f['kind'] ); ?>"><?php endif; ?>
			<div class="osb-search"><span class="dashicons dashicons-search"></span><input type="search" name="s" value="<?php echo esc_attr( $f['search'] ); ?>" placeholder="<?php esc_attr_e( 'Invoice no., name, email, booking ref or payment ref', 'osam-bookings' ); ?>"></div>
			<?php if ( 'credit_note' !== $f['kind'] ) : ?>
			<select name="status" aria-label="<?php esc_attr_e( 'Status', 'osam-bookings' ); ?>">
				<option value=""><?php esc_html_e( 'Any status', 'osam-bookings' ); ?></option>
				<?php foreach ( array( 'paid', 'part_credited', 'credited' ) as $st ) : ?><option value="<?php echo esc_attr( $st ); ?>" <?php selected( $f['status'], $st ); ?>><?php echo esc_html( OSB_Invoices::statuses()[ $st ] ); ?></option><?php endforeach; ?>
			</select>
			<?php endif; ?>
			<?php if ( count( $methods ) > 1 ) : ?>
			<select name="method" aria-label="<?php esc_attr_e( 'Payment method', 'osam-bookings' ); ?>">
				<option value=""><?php esc_html_e( 'Any payment method', 'osam-bookings' ); ?></option>
				<?php foreach ( $methods as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['method'], $k ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
			</select>
			<?php endif; ?>
			<select name="period" class="osb-inv-period" aria-label="<?php esc_attr_e( 'Date', 'osam-bookings' ); ?>">
				<?php
				$period = $f['period'] ? $f['period'] : ( $f['from'] || $f['to'] ? 'custom' : '' );
				foreach ( self::periods() as $k => $label ) :
					?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $period, $k ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="osb-daterange osb-inv-dates" <?php echo 'custom' === $period ? '' : 'hidden'; ?>><input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>" aria-label="<?php esc_attr_e( 'From', 'osam-bookings' ); ?>"><span>→</span><input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>" aria-label="<?php esc_attr_e( 'To', 'osam-bookings' ); ?>"></label>
			<button class="osb-btn osb-btn--ghost"><?php esc_html_e( 'Filter', 'osam-bookings' ); ?></button>
			<?php if ( $filtered ) : ?><a class="osb-link" href="<?php echo esc_url( $f['kind'] ? add_query_arg( 'kind', $f['kind'], $base ) : $base ); ?>"><?php esc_html_e( 'Clear', 'osam-bookings' ); ?></a><?php endif; ?>
		</form>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			var s = document.querySelector('.osb-inv-period'), d = document.querySelector('.osb-inv-dates');
			if (!s || !d) { return; }
			s.addEventListener('change', function () {
				d.hidden = s.value !== 'custom';
				if (s.value !== 'custom') { d.querySelectorAll('input').forEach(function (i) { i.value = ''; }); s.form.submit(); }
			});
		});
		</script>

		<div class="osb-minirow osb-inv-sums">
			<div class="osb-mini"><span><?php echo esc_html( $filtered ? __( 'Matching', 'osam-bookings' ) : __( 'Documents', 'osam-bookings' ) ); ?></span><strong><?php echo (int) $total; ?></strong></div>
			<div class="osb-mini"><span><?php esc_html_e( 'Invoiced', 'osam-bookings' ); ?></span><strong><?php echo esc_html( OSB_Settings::money( $sums['invoiced'], $cur ) ); ?></strong></div>
			<div class="osb-mini"><span><?php esc_html_e( 'Credited', 'osam-bookings' ); ?></span><strong><?php echo esc_html( OSB_Settings::money( $sums['credited'], $cur ) ); ?></strong></div>
			<div class="osb-mini"><span><?php esc_html_e( 'Net', 'osam-bookings' ); ?></span><strong><?php echo esc_html( OSB_Settings::money( $sums['invoiced'] - $sums['credited'], $cur ) ); ?></strong></div>
			<?php if ( $vat_on ) : ?><div class="osb-mini"><span><?php esc_html_e( 'VAT (net of credits)', 'osam-bookings' ); ?></span><strong><?php echo esc_html( OSB_Settings::money( $sums['vat'], $cur ) ); ?></strong></div><?php endif; ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-card--table">
			<?php wp_nonce_field( 'osb_invoices_zip' ); ?>
			<input type="hidden" name="action" value="osb_invoices_zip">
			<div class="osb-bulkbar" hidden>
				<span class="osb-bulkbar__count"></span>
				<button class="osb-btn osb-btn--primary osb-btn--sm" name="export" value="zip"><span class="dashicons dashicons-media-archive"></span><?php esc_html_e( 'Download PDFs (.zip)', 'osam-bookings' ); ?></button>
				<button class="osb-btn osb-btn--ghost osb-btn--sm" name="export" value="csv"><?php esc_html_e( 'Export CSV', 'osam-bookings' ); ?></button>
			</div>
			<?php if ( ! $rows ) : ?>
				<?php
				OSB_Admin::empty_state(
					'dashicons-media-spreadsheet',
					$filtered ? __( 'Nothing matches', 'osam-bookings' ) : __( 'No invoices yet', 'osam-bookings' ),
					$filtered ? __( 'Try a different search or date range.', 'osam-bookings' ) : __( 'An invoice is created automatically whenever a booking is paid — online, or when you mark it paid.', 'osam-bookings' )
				);
				?>
			<?php else : ?>
			<table class="osb-table osb-table--click osb-invoices">
				<thead><tr>
					<th class="osb-col-check"><input type="checkbox" class="osb-check-all" aria-label="<?php esc_attr_e( 'Select all', 'osam-bookings' ); ?>"></th>
					<th><?php self::sort_link( 'number', __( 'Number', 'osam-bookings' ), $f ); ?></th>
					<th><?php self::sort_link( 'issued', __( 'Date', 'osam-bookings' ), $f ); ?></th>
					<th><?php self::sort_link( 'customer', __( 'Customer', 'osam-bookings' ), $f ); ?></th>
					<th><?php esc_html_e( 'Booking', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Paid by', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th>
					<th class="osb-num"><?php self::sort_link( 'total', __( 'Total', 'osam-bookings' ), $f ); ?></th>
					<th class="osb-num"><span class="screen-reader-text"><?php esc_html_e( 'PDF', 'osam-bookings' ); ?></span></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $rows as $r ) :
					$url    = self::url( $r->id );
					$credit = 'credit_note' === $r->kind;
					?>
					<tr data-href="<?php echo esc_url( $url ); ?>">
						<td class="osb-col-check"><input type="checkbox" name="ids[]" value="<?php echo (int) $r->id; ?>" aria-label="<?php echo esc_attr( $r->number ); ?>"></td>
						<td><a class="osb-rowlink" href="<?php echo esc_url( $url ); ?>"><strong><?php echo esc_html( $r->number ); ?></strong></a><?php if ( $credit ) : ?><small><?php esc_html_e( 'Credit note', 'osam-bookings' ); ?></small><?php endif; ?></td>
						<td><?php echo esc_html( mysql2date( 'j M Y', $r->issued_at ) ); ?></td>
						<td><strong><?php echo esc_html( $r->customer_name ); ?></strong><small><?php echo esc_html( $r->customer_email ); ?></small></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . (int) $r->booking_id ) ); ?>">#<?php echo (int) $r->booking_id; ?></a><?php echo count( $r->booking_ids ) > 1 ? ' <span class="osb-muted">+' . ( count( $r->booking_ids ) - 1 ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo esc_html( $r->payment_label ); ?></td>
						<td><?php echo self::pill( $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="osb-num"><strong><?php echo esc_html( ( $credit ? '−' : '' ) . OSB_Settings::money( $r->total, $r->currency ) ); ?></strong><?php if ( (float) $r->vat_amount > 0 ) : ?><small><?php /* translators: %s VAT */ echo esc_html( sprintf( __( 'inc. %s VAT', 'osam-bookings' ), OSB_Settings::money( $r->vat_amount, $r->currency ) ) ); ?></small><?php endif; ?></td>
						<td class="osb-num"><a class="osb-btn osb-btn--ghost osb-btn--sm" href="<?php echo esc_url( OSB_Invoices::admin_url( $r ) ); ?>" target="_blank" title="<?php esc_attr_e( 'Open PDF', 'osam-bookings' ); ?>"><span class="dashicons dashicons-pdf"></span></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="osb-tablefoot">
				<span><?php /* translators: %d count */ echo esc_html( sprintf( _n( '%d document', '%d documents', $total, 'osam-bookings' ), $total ) ); ?></span>
				<span class="osb-pager">
				<?php
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $f['page'],
							'total'     => (int) ceil( $total / $per ),
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
	 * One invoice or credit note.
	 *
	 * @param int $id Id.
	 */
	private static function view( $id ) {
		$doc = OSB_Invoices::get( $id );
		if ( ! $doc ) {
			OSB_Admin::open( __( 'Invoice not found', 'osam-bookings' ), array( 'back' => admin_url( 'admin.php?page=osb-invoices' ), 'back_label' => __( 'All invoices', 'osam-bookings' ) ) );
			OSB_Admin::close();
			return;
		}
		$credit  = 'credit_note' === $doc->kind;
		$parent  = $credit ? OSB_Invoices::get( $doc->parent_id ) : null;
		$notes   = $credit ? array() : OSB_Invoices::credit_notes( $doc->id );
		$left    = $credit ? 0 : OSB_Invoices::remaining( $doc );
		$booking = OSB_DB::get( 'bookings', (int) $doc->booking_id );
		$title   = $credit ? sprintf( /* translators: %s number */ __( 'Credit note %s', 'osam-bookings' ), $doc->number ) : sprintf( /* translators: %s number */ __( 'Invoice %s', 'osam-bookings' ), $doc->number );

		OSB_Admin::open(
			$title,
			array(
				'back'        => admin_url( 'admin.php?page=osb-invoices' ),
				'back_label'  => __( 'All invoices', 'osam-bookings' ),
				'after_title' => ' ' . self::pill( $doc ),
				/* translators: 1: date 2: customer 3: total */
				'subtitle'    => esc_html( sprintf( __( 'Issued %1$s · %2$s · %3$s', 'osam-bookings' ), mysql2date( get_option( 'date_format' ), $doc->issued_at ), $doc->customer_name, OSB_Settings::money( $doc->total, $doc->currency ) ) ),
				'actions'     => array(
					array( __( 'Download PDF', 'osam-bookings' ), OSB_Invoices::admin_url( $doc, true ), 'primary', 'dashicons-download' ),
				),
			)
		);
		?>
		<div class="osb-grid-main">
			<div class="osb-stack">
				<div class="osb-card osb-inv-preview">
					<iframe src="<?php echo esc_url( OSB_Invoices::admin_url( $doc ) ); ?>#toolbar=0&amp;navpanes=0&amp;view=FitH" title="<?php echo esc_attr( $title ); ?>"></iframe>
				</div>
			</div>
			<div class="osb-stack">
				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Details', 'osam-bookings' ) ); ?>
					<dl class="osb-dl">
						<dt><?php esc_html_e( 'Customer', 'osam-bookings' ); ?></dt><dd><?php echo esc_html( $doc->customer_name ); ?><br><a href="mailto:<?php echo esc_attr( $doc->customer_email ); ?>"><?php echo esc_html( $doc->customer_email ); ?></a></dd>
						<dt><?php esc_html_e( 'Booking', 'osam-bookings' ); ?></dt><dd>
							<?php foreach ( ( $doc->booking_ids ? $doc->booking_ids : array( (int) $doc->booking_id ) ) as $bid ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings&action=view&id=' . (int) $bid ) ); ?>">#<?php echo (int) $bid; ?></a>
							<?php endforeach; ?>
							<?php if ( ! $booking ) : ?><span class="osb-muted"><?php esc_html_e( '(deleted)', 'osam-bookings' ); ?></span><?php endif; ?>
						</dd>
						<?php if ( $parent ) : ?>
							<dt><?php esc_html_e( 'Against invoice', 'osam-bookings' ); ?></dt><dd><a href="<?php echo esc_url( self::url( $parent->id ) ); ?>"><?php echo esc_html( $parent->number ); ?></a></dd>
						<?php endif; ?>
						<dt><?php echo $credit ? esc_html__( 'Original payment', 'osam-bookings' ) : esc_html__( 'Paid by', 'osam-bookings' ); ?></dt><dd><?php echo esc_html( $doc->payment_label ? $doc->payment_label : '—' ); ?><?php echo $doc->payment_ref ? '<br><code>' . esc_html( $doc->payment_ref ) . '</code>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
						<?php if ( (float) $doc->vat_rate > 0 ) : ?>
							<dt><?php esc_html_e( 'VAT', 'osam-bookings' ); ?></dt><dd><?php echo esc_html( OSB_Settings::money( $doc->vat_amount, $doc->currency ) . ' @ ' . (float) $doc->vat_rate . '%' ); ?></dd>
						<?php endif; ?>
						<dt><?php esc_html_e( 'Emailed', 'osam-bookings' ); ?></dt><dd><?php echo $doc->emailed_at ? esc_html( mysql2date( 'j M Y, ' . get_option( 'time_format' ), $doc->emailed_at ) ) : '<span class="osb-muted">' . esc_html__( 'Not yet', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
						<?php if ( ! $credit && $notes ) : ?>
							<dt><?php esc_html_e( 'Left to credit', 'osam-bookings' ); ?></dt><dd><?php echo esc_html( OSB_Settings::money( $left, $doc->currency ) ); ?></dd>
						<?php endif; ?>
					</dl>
					<p class="osb-help"><a href="<?php echo esc_url( OSB_Invoices::public_url( $doc ) ); ?>" target="_blank"><?php esc_html_e( 'Customer’s download link', 'osam-bookings' ); ?> ↗</a></p>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form">
					<?php wp_nonce_field( 'osb_invoice_email_' . $doc->id ); ?>
					<input type="hidden" name="action" value="osb_invoice_email">
					<input type="hidden" name="id" value="<?php echo (int) $doc->id; ?>">
					<?php OSB_Admin::card_head( __( 'Email', 'osam-bookings' ), $credit ? __( 'Sends the “credit note” email with the PDF attached.', 'osam-bookings' ) : __( 'Sends the “invoice” email with the PDF attached.', 'osam-bookings' ) ); ?>
					<div class="osb-inline">
						<input type="email" name="to" value="<?php echo esc_attr( $doc->customer_email ); ?>" aria-label="<?php esc_attr_e( 'Send to', 'osam-bookings' ); ?>">
						<button class="osb-btn osb-btn--ghost"><span class="dashicons dashicons-email"></span><?php esc_html_e( 'Send', 'osam-bookings' ); ?></button>
					</div>
				</form>

				<?php if ( ! $credit ) : ?>
					<div class="osb-card">
						<?php OSB_Admin::card_head( __( 'Credit notes', 'osam-bookings' ), __( 'Issued invoices are never changed. To refund some or all of this invoice, issue a credit note against it. (Refund the money itself in Stripe, PayPal or WooCommerce.)', 'osam-bookings' ) ); ?>
						<?php if ( $notes ) : ?>
							<ul class="osb-inv-cnlist">
								<?php foreach ( $notes as $cn ) : ?>
									<li><a href="<?php echo esc_url( self::url( $cn->id ) ); ?>"><strong><?php echo esc_html( $cn->number ); ?></strong></a> <span class="osb-muted"><?php echo esc_html( mysql2date( 'j M Y', $cn->issued_at ) ); ?></span><span>−<?php echo esc_html( OSB_Settings::money( $cn->total, $cn->currency ) ); ?></span><?php echo $cn->notes ? '<small>' . esc_html( $cn->notes ) . '</small>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php if ( $left > 0 ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form osb-inv-creditform">
								<?php wp_nonce_field( 'osb_invoice_credit_' . $doc->id ); ?>
								<input type="hidden" name="action" value="osb_invoice_credit">
								<input type="hidden" name="id" value="<?php echo (int) $doc->id; ?>">
								<div class="osb-field"><label for="osb_cn_amount"><?php esc_html_e( 'Amount to credit', 'osam-bookings' ); ?></label><input type="number" id="osb_cn_amount" name="amount" min="0.01" max="<?php echo esc_attr( $left ); ?>" step="0.01" value="<?php echo esc_attr( number_format( $left, 2, '.', '' ) ); ?>" class="small-text"> <span class="osb-muted"><?php /* translators: %s amount */ echo esc_html( sprintf( __( 'up to %s', 'osam-bookings' ), OSB_Settings::money( $left, $doc->currency ) ) ); ?></span></div>
								<div class="osb-field"><label for="osb_cn_reason"><?php esc_html_e( 'Reason (shown on the credit note)', 'osam-bookings' ); ?></label><input type="text" id="osb_cn_reason" name="reason" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Cancelled with more than 24 hours’ notice', 'osam-bookings' ); ?>"></div>
								<label class="osb-switch"><input type="checkbox" name="email" value="1" <?php checked( (bool) OSB_Settings::get( 'inv_email_credit' ) ); ?>><span></span><?php esc_html_e( 'Email it to the customer', 'osam-bookings' ); ?></label>
								<?php if ( $booking && 'refunded' !== $booking->payment_status ) : ?>
									<label class="osb-switch"><input type="checkbox" name="mark_refunded" value="1"><span></span><?php esc_html_e( 'Mark the booking’s payment as refunded (full credit only)', 'osam-bookings' ); ?></label>
								<?php endif; ?>
								<div class="osb-formfoot"><button class="osb-btn osb-btn--primary" onclick="return confirm('<?php echo esc_js( __( 'Issue this credit note? It can’t be deleted afterwards.', 'osam-bookings' ) ); ?>');"><?php esc_html_e( 'Issue credit note', 'osam-bookings' ); ?></button></div>
							</form>
						<?php else : ?>
							<p class="osb-muted"><?php esc_html_e( 'Fully credited.', 'osam-bookings' ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form">
					<?php wp_nonce_field( 'osb_invoice_billto_' . $doc->id ); ?>
					<input type="hidden" name="action" value="osb_invoice_billto">
					<input type="hidden" name="id" value="<?php echo (int) $doc->id; ?>">
					<?php OSB_Admin::card_head( $credit ? __( 'Credit to', 'osam-bookings' ) : __( 'Bill to', 'osam-bookings' ), __( 'Add a company name or address if the customer asks. Amounts can’t be changed.', 'osam-bookings' ) ); ?>
					<div class="osb-field"><textarea name="bill_to" rows="4"><?php echo esc_textarea( (string) $doc->bill_to ); ?></textarea></div>
					<?php if ( ! $credit ) : ?>
						<div class="osb-field"><label for="osb_inv_note"><?php esc_html_e( 'Note on the invoice', 'osam-bookings' ); ?></label><textarea id="osb_inv_note" name="notes" rows="2" placeholder="<?php esc_attr_e( 'e.g. PO number 4471', 'osam-bookings' ); ?>"><?php echo esc_textarea( (string) $doc->notes ); ?></textarea></div>
					<?php endif; ?>
					<div class="osb-formfoot"><button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Save', 'osam-bookings' ); ?></button></div>
				</form>
			</div>
		</div>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Invoices card on a booking.
	 *
	 * @param object $b Booking.
	 */
	public static function booking_card( $b ) {
		if ( ! OSB_Invoices::enabled() ) {
			return;
		}
		$docs = OSB_Invoices::for_booking( $b );
		$paid = 'paid' === $b->payment_status && (float) $b->amount > 0;
		if ( ! $docs && ! $paid ) {
			return;
		}
		?>
		<div class="osb-card osb-inv-bookingcard">
			<?php OSB_Admin::card_head( __( 'Invoices', 'osam-bookings' ) ); ?>
			<?php if ( $docs ) : ?>
				<ul class="osb-inv-cnlist">
					<?php foreach ( $docs as $doc ) : ?>
						<li>
							<a href="<?php echo esc_url( self::url( $doc->id ) ); ?>"><strong><?php echo esc_html( $doc->number ); ?></strong></a>
							<?php echo self::pill( $doc ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php echo esc_html( ( 'credit_note' === $doc->kind ? '−' : '' ) . OSB_Settings::money( $doc->total, $doc->currency ) ); ?></span>
							<a class="osb-inv-pdf" href="<?php echo esc_url( OSB_Invoices::admin_url( $doc ) ); ?>" target="_blank" title="<?php esc_attr_e( 'Open PDF', 'osam-bookings' ); ?>"><span class="dashicons dashicons-pdf"></span></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $paid && ! OSB_Invoices::for_booking( $b, 'invoice' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'osb_invoice_create_' . $b->id ); ?>
					<input type="hidden" name="action" value="osb_invoice_create">
					<input type="hidden" name="id" value="<?php echo (int) $b->id; ?>">
					<p class="osb-help"><?php esc_html_e( 'This booking is paid but has no invoice (it was probably paid before invoices were switched on).', 'osam-bookings' ); ?></p>
					<label class="osb-switch"><input type="checkbox" name="email" value="1"><span></span><?php esc_html_e( 'Email it to the customer', 'osam-bookings' ); ?></label>
					<p><button class="osb-btn osb-btn--ghost osb-btn--sm"><?php esc_html_e( 'Create invoice', 'osam-bookings' ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	/**
	 * Email a document.
	 */
	public static function email() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		OSB_Admin::verify( 'osb_invoice_email_' . $id );
		$doc = OSB_Invoices::get( $id );
		$to  = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ok  = $doc && is_email( $to ) && OSB_Invoices::email( $doc, true, $to );
		OSB_Admin::redirect( self::url( $id ), $ok ? 'inv_emailed' : 'error' );
	}

	/**
	 * Issue a credit note.
	 */
	public static function credit() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		OSB_Admin::verify( 'osb_invoice_credit_' . $id );
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$inv    = OSB_Invoices::get( $id );
		$amount = isset( $post['amount'] ) ? round( (float) str_replace( ',', '.', (string) $post['amount'] ), 2 ) : 0;
		if ( ! $inv || $amount <= 0 ) {
			OSB_Admin::flash_error( __( 'Enter an amount to credit.', 'osam-bookings' ) );
			OSB_Admin::redirect( self::url( $id ), 'none' );
		}
		$full = $inv && abs( $amount - OSB_Invoices::remaining( $inv ) ) < 0.005 && abs( OSB_Invoices::remaining( $inv ) - (float) $inv->total ) < 0.005;
		$cn   = OSB_Invoices::credit( $inv, $amount, isset( $post['reason'] ) ? (string) $post['reason'] : '', ! empty( $post['email'] ) );
		if ( is_wp_error( $cn ) ) {
			OSB_Admin::flash_error( $cn->get_error_message() );
			OSB_Admin::redirect( self::url( $id ), 'none' );
		}
		if ( $full && ! empty( $post['mark_refunded'] ) ) {
			OSB_DB::update( 'bookings', (int) $inv->booking_id, array( 'payment_status' => 'refunded', 'updated_at' => current_time( 'mysql' ) ) );
		}
		OSB_Admin::redirect( self::url( $cn->id ), 'inv_credited' );
	}

	/**
	 * Save bill-to and note.
	 */
	public static function save_billto() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		OSB_Admin::verify( 'osb_invoice_billto_' . $id );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$doc  = OSB_Invoices::get( $id );
		if ( $doc ) {
			$data = array( 'bill_to' => sanitize_textarea_field( isset( $post['bill_to'] ) ? $post['bill_to'] : '' ) );
			if ( 'invoice' === $doc->kind && isset( $post['notes'] ) ) {
				$data['notes'] = sanitize_textarea_field( $post['notes'] );
			}
			OSB_Invoices::update( $id, $data );
		}
		OSB_Admin::redirect( self::url( $id ) );
	}

	/**
	 * Invoice a paid booking that has none (paid before invoices existed).
	 */
	public static function create() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		OSB_Admin::verify( 'osb_invoice_create_' . $id );
		$b    = OSB_DB::get( 'bookings', $id );
		$back = admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $id );
		$pay  = null;
		foreach ( $b ? (array) OSB_DB::payments( $b->id ) : array() as $p ) {
			if ( 'completed' === $p->status && (float) $p->amount > 0 && ! OSB_Invoices::for_payment( $p->id ) ) {
				$pay = $p;
			}
		}
		if ( ! $pay && $b && 'paid' === $b->payment_status && (float) $b->amount > 0 ) {
			// Marked paid without a payment record (older versions): record it now so the invoice has one.
			$pid = OSB_DB::add_payment( $b->id, 'manual', 'admin-' . get_current_user_id() . '-' . time(), $b->amount, $b->currency, 'completed', array( 'by' => get_current_user_id(), 'backfill' => true ) );
			$pay = OSB_DB::get( 'payments', $pid );
		}
		if ( ! $pay ) {
			OSB_Admin::redirect( $back, 'inv_none' );
		}
		$inv = OSB_Invoices::create_for_payment( $b, (int) $pay->id );
		if ( $inv && ! empty( $_POST['email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			OSB_Invoices::email( $inv, true );
		}
		OSB_Admin::redirect( $inv ? self::url( $inv->id ) : $back, $inv ? 'inv_created' : 'error' );
	}

	/**
	 * Selected ids (bulk) or the current filters.
	 *
	 * @return array Rows.
	 */
	private static function export_rows() {
		// phpcs:disable WordPress.Security.NonceVerification
		$ids = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
		// phpcs:enable
		$f = $ids ? array( 'ids' => $ids ) : self::filters();
		list( $rows ) = OSB_Invoices::query( array_merge( $f, array( 'per_page' => 0, 'orderby' => 'number', 'order' => 'asc' ) ) );
		return $rows;
	}

	/**
	 * CSV export (for the accountant).
	 */
	public static function export() {
		OSB_Admin::verify( 'osb_invoices_export' );
		self::csv_out();
	}

	/**
	 * Stream the CSV for the selected rows / current filters.
	 */
	private static function csv_out() {
		$out = array(
			array( 'Type', 'Number', 'Date', 'Against invoice', 'Status', 'Customer', 'Email', 'Bill to', 'Booking refs', 'Description', 'Net', 'VAT rate', 'VAT', 'Total', 'Currency', 'Paid by', 'Payment ref', 'Date paid' ),
		);
		$numbers = array();
		foreach ( self::export_rows() as $r ) {
			$numbers[ $r->id ] = $r->number;
			$sign              = 'credit_note' === $r->kind ? -1 : 1;
			$parent            = '';
			if ( $r->parent_id ) {
				$parent = isset( $numbers[ $r->parent_id ] ) ? $numbers[ $r->parent_id ] : ( OSB_Invoices::get( $r->parent_id ) ? OSB_Invoices::get( $r->parent_id )->number : '' );
			}
			$out[] = array(
				'credit_note' === $r->kind ? 'Credit note' : 'Invoice',
				$r->number,
				mysql2date( 'Y-m-d', $r->issued_at ),
				$parent,
				OSB_Invoices::statuses()[ $r->status ] ?? $r->status,
				$r->customer_name,
				$r->customer_email,
				str_replace( "\n", ', ', (string) $r->bill_to ),
				'#' . implode( ' #', $r->booking_ids ? $r->booking_ids : array( $r->booking_id ) ),
				implode( '; ', wp_list_pluck( $r->lines, 'desc' ) ),
				number_format( $sign * (float) $r->subtotal, 2, '.', '' ),
				(float) $r->vat_rate > 0 ? (float) $r->vat_rate . '%' : '',
				number_format( $sign * (float) $r->vat_amount, 2, '.', '' ),
				number_format( $sign * (float) $r->total, 2, '.', '' ),
				$r->currency,
				$r->payment_label,
				$r->payment_ref,
				$r->paid_at ? mysql2date( 'Y-m-d', $r->paid_at ) : '',
			);
		}
		OSB_Admin_Customers::csv( 'invoices-' . gmdate( 'Y-m-d' ) . '.csv', $out );
	}

	/**
	 * Zip of PDFs.
	 */
	public static function zip() {
		if ( ! current_user_can( OSB_Admin::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'osam-bookings' ), 403 );
		}
		check_admin_referer( 'osb_invoices_zip' );
		if ( isset( $_POST['export'] ) && 'csv' === $_POST['export'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::csv_out();
		}
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=osb-invoices' );
		if ( ! class_exists( 'ZipArchive' ) ) {
			OSB_Admin::redirect( $back, 'inv_nozip' );
		}
		$rows = array_slice( self::export_rows(), 0, (int) apply_filters( 'osb_invoice_zip_limit', 1000 ) );
		if ( ! $rows ) {
			OSB_Admin::redirect( $back, 'error' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$file = wp_tempnam( 'osb-invoices.zip' );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $file, ZipArchive::OVERWRITE ) ) {
			OSB_Admin::redirect( $back, 'error' );
		}
		foreach ( $rows as $r ) {
			$zip->addFromString( ( 'credit_note' === $r->kind ? 'credit-notes/' : '' ) . OSB_Invoices::filename( $r ), OSB_Invoices::pdf( $r ) );
		}
		$zip->close();
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="invoices-' . gmdate( 'Y-m-d' ) . '.zip"' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $file );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Settings tab
	 * ------------------------------------------------------------------ */

	/**
	 * Settings → Invoices.
	 *
	 * @param array    $s     Settings.
	 * @param callable $field Settings field renderer.
	 */
	public static function settings_tab( $s, $field ) {
		$preview = wp_nonce_url( admin_url( 'admin-post.php?action=osb_invoice_pdf&id=0&sample=1' ), 'osb_invoice_pdf_0' );
		$has     = OSB_Invoices::table_ready() && OSB_Invoices::query( array( 'per_page' => 1 ) )[1] > 0;
		?>
		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Your business details', 'osam-bookings' ), __( 'Shown at the top of every invoice. Each invoice keeps the details it was issued with, so changes here only affect new ones.', 'osam-bookings' ) . ' <a href="' . esc_url( $preview ) . '" target="_blank">' . esc_html__( 'Preview a sample invoice', 'osam-bookings' ) . ' ↗</a>' ); ?>
			<div class="osb-fieldgrid">
				<?php
				$field( 'inv_business_name', __( 'Business name', 'osam-bookings' ), $s['inv_business_name'], 'text', array( 'class' => 'osb-field--half' ) );
				$field( 'inv_email', __( 'Email', 'osam-bookings' ), $s['inv_email'], 'email', array( 'class' => 'osb-field--half' ) );
				$field( 'inv_address', __( 'Address', 'osam-bookings' ), $s['inv_address'], 'textarea', array( 'class' => 'osb-field--half', 'placeholder' => "1 High Street\nBristol\nBS1 1AA" ) );
				echo '<div class="osb-field--half osb-fieldstack">';
				$field( 'inv_phone', __( 'Phone', 'osam-bookings' ), $s['inv_phone'], 'text' );
				$field( 'inv_website', __( 'Website', 'osam-bookings' ), $s['inv_website'], 'text' );
				echo '</div>';
				$field( 'inv_company_no', __( 'Company registration number', 'osam-bookings' ), $s['inv_company_no'], 'text', array( 'class' => 'osb-field--half', 'help' => __( 'Limited companies: shown in the footer. Leave blank if you’re a sole trader.', 'osam-bookings' ) ) );
				$field( 'inv_logo_id', __( 'Logo', 'osam-bookings' ), $s['inv_logo_id'], 'image', array( 'class' => 'osb-field--half', 'help' => __( 'Leave empty to use the email logo. JPG or PNG works best.', 'osam-bookings' ) ) );
				$field( 'inv_accent', __( 'Accent colour', 'osam-bookings' ), $s['inv_accent'], 'color', array( 'class' => 'osb-field--half', 'help' => __( 'Leave empty to match your emails.', 'osam-bookings' ) ) );
				$field( 'inv_footer', __( 'Message at the bottom', 'osam-bookings' ), $s['inv_footer'], 'textarea', array( 'class' => 'osb-field--half', 'help' => __( 'e.g. thanks, refund policy or bank details.', 'osam-bookings' ) ) );
				?>
			</div>
		</div>

		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'VAT', 'osam-bookings' ), __( 'This only changes what invoices show — the prices customers pay stay exactly as you set them.', 'osam-bookings' ) ); ?>
			<div class="osb-fieldgrid">
				<?php
				$field(
					'inv_vat_mode',
					__( 'VAT', 'osam-bookings' ),
					$s['inv_vat_mode'],
					'select',
					array(
						'none'      => __( 'Not VAT registered — no VAT on invoices', 'osam-bookings' ),
						'inclusive' => __( 'VAT registered — my prices include VAT', 'osam-bookings' ),
						'class'     => 'osb-field--half',
					)
				);
				$field( 'inv_vat_rate', __( 'VAT rate (%)', 'osam-bookings' ), $s['inv_vat_rate'], 'number', array( 'class' => 'osb-field--quarter', 'min' => 0, 'max' => 100, 'step' => '0.01' ) );
				$field( 'inv_vat_number', __( 'VAT registration number', 'osam-bookings' ), $s['inv_vat_number'], 'text', array( 'class' => 'osb-field--quarter', 'placeholder' => 'GB123456789' ) );
				?>
			</div>
			<p class="osb-help"><?php esc_html_e( 'With VAT on, invoices list prices without VAT, then the VAT and the total — the format HMRC expects for a full VAT invoice.', 'osam-bookings' ); ?></p>
		</div>

		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Numbering', 'osam-bookings' ), __( 'Numbers run on without gaps. Credit notes have their own sequence.', 'osam-bookings' ) ); ?>
			<div class="osb-fieldgrid">
				<?php
				$field( 'inv_prefix', __( 'Invoice prefix', 'osam-bookings' ), $s['inv_prefix'], 'text', array( 'class' => 'osb-field--quarter' ) );
				$field( 'inv_start', __( 'Start from', 'osam-bookings' ), $s['inv_start'], 'number', array( 'class' => 'osb-field--quarter', 'min' => 1, 'help' => $has ? __( 'Only used if it’s higher than the last number issued.', 'osam-bookings' ) : __( 'Moving from another system? Start after your last invoice number.', 'osam-bookings' ) ) );
				$field( 'inv_padding', __( 'Digits', 'osam-bookings' ), $s['inv_padding'], 'number', array( 'class' => 'osb-field--quarter', 'min' => 1, 'max' => 10, 'help' => __( '4 gives INV-0001.', 'osam-bookings' ) ) );
				$field( 'cn_prefix', __( 'Credit note prefix', 'osam-bookings' ), $s['cn_prefix'], 'text', array( 'class' => 'osb-field--quarter' ) );
				?>
			</div>
		</div>

		<div class="osb-card">
			<?php OSB_Admin::card_head( __( 'Sending', 'osam-bookings' ) ); ?>
			<?php
			$field( 'inv_attach', __( 'Attach the invoice to the confirmation email when someone pays online', 'osam-bookings' ), $s['inv_attach'], 'checkbox', array( 'help' => __( 'Also goes with the “booking received” email if bookings need approving, and with the bundle code email.', 'osam-bookings' ) ) );
			$field( 'inv_email_later', __( 'Email the invoice when a booking is marked paid later', 'osam-bookings' ), $s['inv_email_later'], 'checkbox', array( 'help' => __( 'e.g. pay-on-arrival bookings you mark as paid. Uses the “Customer — invoice” email (Settings → Emails). Only sent when “Email the customer” is ticked on the booking.', 'osam-bookings' ) ) );
			$field( 'inv_email_credit', __( 'Email credit notes to the customer', 'osam-bookings' ), $s['inv_email_credit'], 'checkbox' );
			$field( 'inv_attach_admin', __( 'Send me a copy (attach the invoice to the admin “new booking” email)', 'osam-bookings' ), $s['inv_attach_admin'], 'checkbox' );
			$field( 'inv_customer_download', __( 'Let customers download their invoices from their booking page and My bookings', 'osam-bookings' ), $s['inv_customer_download'], 'checkbox' );
			?>
			<div class="osb-fieldgrid">
				<?php
				$keys = array();
				foreach ( OSB_Settings::form_fields() as $ff ) {
					if ( ! in_array( $ff['key'], array( 'name', 'email', 'phone' ), true ) ) {
						$keys[] = $ff['key'];
					}
				}
				/* translators: %s field keys */
				$field( 'inv_bill_to_fields', __( '“Bill to” form fields', 'osam-bookings' ), $s['inv_bill_to_fields'], 'text', array( 'class' => 'osb-field--half', 'placeholder' => __( 'Automatic', 'osam-bookings' ), 'help' => sprintf( __( 'Field keys (comma-separated) whose answers go under the customer’s name, e.g. a company or address question. Leave blank to pick up any company or address fields automatically. Your fields: %s', 'osam-bookings' ), $keys ? '<code>' . implode( '</code>, <code>', array_map( 'esc_html', $keys ) ) . '</code>' : '—' ) ) );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Save Settings → Invoices.
	 *
	 * @param array $post Posted values (unslashed).
	 * @return array Settings.
	 */
	public static function settings_values( array $post ) {
		$t = static function ( $k ) use ( $post ) {
			return isset( $post[ $k ] ) ? sanitize_text_field( $post[ $k ] ) : '';
		};
		$b = static function ( $k ) use ( $post ) {
			return ! empty( $post[ $k ] ) ? 1 : 0;
		};
		$accent = isset( $post['inv_accent'] ) ? sanitize_hex_color( $post['inv_accent'] ) : '';
		return array(
			'inv_business_name'     => $t( 'inv_business_name' ),
			'inv_email'             => sanitize_email( isset( $post['inv_email'] ) ? $post['inv_email'] : '' ),
			'inv_address'           => sanitize_textarea_field( isset( $post['inv_address'] ) ? $post['inv_address'] : '' ),
			'inv_phone'             => $t( 'inv_phone' ),
			'inv_website'           => $t( 'inv_website' ),
			'inv_company_no'        => $t( 'inv_company_no' ),
			'inv_logo_id'           => absint( isset( $post['inv_logo_id'] ) ? $post['inv_logo_id'] : 0 ),
			'inv_accent'            => $accent ? $accent : '',
			'inv_footer'            => sanitize_textarea_field( isset( $post['inv_footer'] ) ? $post['inv_footer'] : '' ),
			'inv_vat_mode'          => 'inclusive' === $t( 'inv_vat_mode' ) ? 'inclusive' : 'none',
			'inv_vat_rate'          => max( 0, min( 100, round( (float) ( isset( $post['inv_vat_rate'] ) ? $post['inv_vat_rate'] : 20 ), 2 ) ) ),
			'inv_vat_number'        => strtoupper( preg_replace( '/\s+/', '', $t( 'inv_vat_number' ) ) ),
			'inv_prefix'            => substr( preg_replace( '/[^A-Za-z0-9\-\/_.]/', '', $t( 'inv_prefix' ) ), 0, 20 ),
			'cn_prefix'             => substr( preg_replace( '/[^A-Za-z0-9\-\/_.]/', '', $t( 'cn_prefix' ) ), 0, 20 ) ? substr( preg_replace( '/[^A-Za-z0-9\-\/_.]/', '', $t( 'cn_prefix' ) ), 0, 20 ) : 'CN-',
			'inv_start'             => max( 1, absint( isset( $post['inv_start'] ) ? $post['inv_start'] : 1 ) ),
			'inv_padding'           => max( 1, min( 10, absint( isset( $post['inv_padding'] ) ? $post['inv_padding'] : 4 ) ) ),
			'inv_attach'            => $b( 'inv_attach' ),
			'inv_email_later'       => $b( 'inv_email_later' ),
			'inv_email_credit'      => $b( 'inv_email_credit' ),
			'inv_attach_admin'      => $b( 'inv_attach_admin' ),
			'inv_customer_download' => $b( 'inv_customer_download' ),
			'inv_bill_to_fields'    => implode( ',', array_filter( array_map( 'sanitize_key', explode( ',', $t( 'inv_bill_to_fields' ) ) ) ) ),
		);
	}
}
