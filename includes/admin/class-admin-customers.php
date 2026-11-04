<?php
/**
 * Customers admin.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer list, profiles, notes, CSV export and erase.
 */
class OSB_Admin_Customers {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_save_customer', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_osb_export_customers', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_osb_erase_customer', array( __CLASS__, 'erase' ) );
		add_action( 'admin_post_osb_export_customer_data', array( __CLASS__, 'export_one' ) );
	}

	/**
	 * Profile URL.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function url( $email ) {
		return add_query_arg(
			array(
				'page'     => 'osb-customers',
				'customer' => rawurlencode( OSB_Customers::key( $email ) ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Router.
	 */
	public static function page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$email = isset( $_GET['customer'] ) ? sanitize_email( rawurldecode( wp_unslash( $_GET['customer'] ) ) ) : '';
		if ( $email ) {
			self::profile( $email );
			return;
		}
		self::listing();
	}

	/**
	 * Current list filters.
	 *
	 * @return array
	 */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		return array(
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'tag'     => isset( $_GET['tag'] ) ? sanitize_text_field( wp_unslash( $_GET['tag'] ) ) : '',
			'orderby' => isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'recent',
			'order'   => isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc',
			'page'    => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
		);
		// phpcs:enable
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
		if ( 'name' === $col && $f['orderby'] !== $col ) {
			$order = 'asc';
		}
		$arrow = $f['orderby'] === $col ? ( 'asc' === $f['order'] ? ' ↑' : ' ↓' ) : '';
		printf( '<a href="%s">%s%s</a>', esc_url( add_query_arg( array( 'orderby' => $col, 'order' => $order, 'paged' => false ) ) ), esc_html( $label ), esc_html( $arrow ) );
	}

	/**
	 * Relative-ish date.
	 *
	 * @param string|null $when Local datetime.
	 * @return string
	 */
	private static function when( $when ) {
		if ( ! $when ) {
			return '—';
		}
		$ts   = strtotime( $when );
		$year = mysql2date( 'Y', $when ) === current_time( 'Y' ) ? '' : ' Y';
		return date_i18n( 'j M' . $year, $ts );
	}

	/**
	 * The list.
	 */
	private static function listing() {
		$f             = self::filters();
		$per           = 50;
		list( $rows, $total ) = OSB_Customers::query( array_merge( $f, array( 'per_page' => $per ) ) );
		$tags          = OSB_Customers::all_tags();
		$export        = wp_nonce_url( add_query_arg( array_filter( array( 's' => $f['search'], 'tag' => $f['tag'] ) ), admin_url( 'admin-post.php?action=osb_export_customers' ) ), 'osb_export_customers' );
		$base          = admin_url( 'admin.php?page=osb-customers' );
		OSB_Admin::open(
			__( 'Customers', 'osam-bookings' ),
			array(
				'subtitle' => __( 'Everyone who has booked, matched by email address. Open someone to see their history and add private notes.', 'osam-bookings' ),
				'actions'  => array(
					array( __( 'Export CSV', 'osam-bookings' ), $export, 'secondary', 'dashicons-download' ),
				),
			)
		);
		?>
		<form method="get" class="osb-toolbar">
			<input type="hidden" name="page" value="osb-customers">
			<div class="osb-search"><span class="dashicons dashicons-search"></span><input type="search" name="s" value="<?php echo esc_attr( $f['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search name, email or phone', 'osam-bookings' ); ?>"></div>
			<?php if ( $tags ) : ?>
				<select name="tag"><option value=""><?php esc_html_e( 'All tags', 'osam-bookings' ); ?></option>
					<?php foreach ( $tags as $t ) : ?><option value="<?php echo esc_attr( $t ); ?>" <?php selected( $f['tag'], $t ); ?>><?php echo esc_html( $t ); ?></option><?php endforeach; ?>
				</select>
			<?php endif; ?>
			<button class="osb-btn osb-btn--ghost"><?php esc_html_e( 'Filter', 'osam-bookings' ); ?></button>
			<?php if ( $f['search'] || $f['tag'] ) : ?><a class="osb-link" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Clear', 'osam-bookings' ); ?></a><?php endif; ?>
		</form>

		<div class="osb-card osb-card--table">
			<?php if ( ! $rows ) : ?>
				<?php
				OSB_Admin::empty_state(
					'dashicons-id',
					$f['search'] || $f['tag'] ? __( 'Nobody matches', 'osam-bookings' ) : __( 'No customers yet', 'osam-bookings' ),
					$f['search'] || $f['tag'] ? __( 'Try a different search.', 'osam-bookings' ) : __( 'Customers appear here as soon as someone books.', 'osam-bookings' )
				);
				?>
			<?php else : ?>
			<table class="osb-table osb-table--click osb-customers">
				<thead><tr>
					<th><?php self::sort_link( 'name', __( 'Customer', 'osam-bookings' ), $f ); ?></th>
					<th class="osb-num"><?php self::sort_link( 'bookings', __( 'Bookings', 'osam-bookings' ), $f ); ?></th>
					<th class="osb-num"><?php esc_html_e( 'Visits', 'osam-bookings' ); ?></th>
					<th class="osb-num"><?php self::sort_link( 'no_shows', __( 'No-shows', 'osam-bookings' ), $f ); ?></th>
					<th class="osb-num"><?php esc_html_e( 'Cancelled', 'osam-bookings' ); ?></th>
					<th class="osb-num"><?php self::sort_link( 'spent', __( 'Spent', 'osam-bookings' ), $f ); ?></th>
					<th><?php self::sort_link( 'last', __( 'Last visit', 'osam-bookings' ), $f ); ?></th>
					<th><?php esc_html_e( 'Next', 'osam-bookings' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $rows as $r ) :
					$url = self::url( $r->email );
					?>
					<tr data-href="<?php echo esc_url( $url ); ?>">
						<td>
							<a class="osb-rowlink" href="<?php echo esc_url( $url ); ?>"><strong><?php echo esc_html( $r->name ? $r->name : $r->email ); ?></strong></a>
							<small><?php echo esc_html( $r->email ); ?><?php echo $r->phone ? ' · ' . esc_html( $r->phone ) : ''; ?></small>
							<?php if ( $r->tags || $r->notes ) : ?>
								<span class="osb-custtags">
									<?php foreach ( array_slice( $r->tags, 0, 3 ) as $t ) : ?><span class="osb-ctag"><?php echo esc_html( $t ); ?></span><?php endforeach; ?>
									<?php if ( $r->notes ) : ?><span class="dashicons dashicons-edit-page osb-muted" title="<?php esc_attr_e( 'Has notes', 'osam-bookings' ); ?>"></span><?php endif; ?>
								</span>
							<?php endif; ?>
						</td>
						<td class="osb-num"><?php echo (int) $r->bookings; ?></td>
						<td class="osb-num"><?php echo (int) $r->visits; ?></td>
						<td class="osb-num"><?php echo $r->no_shows ? '<span class="osb-bad">' . (int) $r->no_shows . '</span>' : '0'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="osb-num"><?php echo (int) $r->cancelled; ?></td>
						<td class="osb-num"><strong><?php echo esc_html( OSB_Settings::money( $r->spent ) ); ?></strong></td>
						<td><?php echo esc_html( self::when( $r->last_visit ) ); ?></td>
						<td><?php echo $r->next_at ? '<span class="osb-pill osb-pill--confirmed">' . esc_html( self::when( $r->next_at ) ) . '</span>' : '<span class="osb-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="osb-tablefoot">
				<span><?php /* translators: %d count */ echo esc_html( sprintf( _n( '%d customer', '%d customers', $total, 'osam-bookings' ), $total ) ); ?></span>
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
		</div>
		<?php
		OSB_Admin::close();
	}

	/**
	 * One customer.
	 *
	 * @param string $email Email.
	 */
	private static function profile( $email ) {
		$c = OSB_Customers::get( $email );
		if ( ! $c ) {
			OSB_Admin::open( __( 'Customer not found', 'osam-bookings' ), array( 'back' => admin_url( 'admin.php?page=osb-customers' ), 'back_label' => __( 'Customers', 'osam-bookings' ) ) );
			OSB_Admin::empty_state( 'dashicons-id', __( 'Nobody with that email', 'osam-bookings' ), __( 'They may have been erased.', 'osam-bookings' ) );
			OSB_Admin::close();
			return;
		}
		$bookings = OSB_Customers::bookings( $email );
		$names    = OSB_Admin_Dashboard::name_maps();
		$new      = add_query_arg( array( 'customer' => rawurlencode( $c['email'] ) ), admin_url( 'admin.php?page=osb-bookings&action=new' ) );
		$rate     = $c['bookings'] ? round( 100 * ( (int) $c['no_shows'] ) / max( 1, (int) $c['visits'] + (int) $c['no_shows'] ) ) : 0;
		OSB_Admin::open(
			$c['name'] ? $c['name'] : $c['email'],
			array(
				'back'       => admin_url( 'admin.php?page=osb-customers' ),
				'back_label' => __( 'Customers', 'osam-bookings' ),
				/* translators: %s date */
				'subtitle'   => esc_html( $c['first_at'] ? sprintf( __( 'Customer since %s', 'osam-bookings' ), mysql2date( get_option( 'date_format' ), $c['first_at'] ) ) : '' ),
				'actions'    => array(
					array( __( 'New booking', 'osam-bookings' ), $new, 'primary', 'dashicons-plus-alt2' ),
				),
			)
		);
		$stats = array(
			array( __( 'Bookings', 'osam-bookings' ), (int) $c['bookings'], 'dashicons-calendar-alt', '' ),
			array( __( 'Visits', 'osam-bookings' ), (int) $c['visits'], 'dashicons-yes-alt', $c['last_visit'] ? sprintf( /* translators: %s date */ __( 'Last %s', 'osam-bookings' ), self::when( $c['last_visit'] ) ) : '' ),
			array( __( 'No-shows', 'osam-bookings' ), (int) $c['no_shows'], 'dashicons-dismiss', $c['no_shows'] ? sprintf( /* translators: %d percent */ __( '%d%% of visits', 'osam-bookings' ), $rate ) : '' ),
			array( __( 'Total spent', 'osam-bookings' ), OSB_Settings::money( $c['spent'] ), 'dashicons-money-alt', (int) $c['cancelled'] ? sprintf( /* translators: %d cancellations */ _n( '%d cancellation', '%d cancellations', (int) $c['cancelled'], 'osam-bookings' ), (int) $c['cancelled'] ) : '' ),
		);
		?>
		<div class="osb-stats">
			<?php foreach ( $stats as $s ) : ?>
				<div class="osb-stat">
					<span class="osb-stat__icon dashicons <?php echo esc_attr( $s[2] ); ?>" aria-hidden="true"></span>
					<span class="osb-stat__label"><?php echo esc_html( $s[0] ); ?></span>
					<span class="osb-stat__value"><?php echo esc_html( (string) $s[1] ); ?></span>
					<span class="osb-stat__sub"><?php echo esc_html( $s[3] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="osb-grid-main">
			<div class="osb-stack">
				<div class="osb-card osb-card--table">
					<?php OSB_Admin::card_head( __( 'Booking history', 'osam-bookings' ) ); ?>
					<?php if ( ! $bookings ) : ?>
						<p class="osb-muted osb-pad"><?php esc_html_e( 'No bookings.', 'osam-bookings' ); ?></p>
					<?php else : ?>
					<table class="osb-table osb-table--click">
						<thead><tr><th><?php esc_html_e( 'When', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Booked for', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Amount', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th></tr></thead>
						<tbody>
						<?php
						foreach ( $bookings as $b ) :
							$view = admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $b->id );
							?>
							<tr data-href="<?php echo esc_url( $view ); ?>">
								<td><a class="osb-rowlink" href="<?php echo esc_url( $view ); ?>"><strong><?php echo esc_html( mysql2date( 'D j M Y', $b->start_at ) ); ?></strong></a><small><?php echo esc_html( mysql2date( get_option( 'time_format' ), $b->start_at ) ); ?> · #<?php echo (int) $b->id; ?></small></td>
								<td><span class="osb-dot" style="background:<?php echo esc_attr( OSB_Admin_Dashboard::item_color( $b, $names ) ); ?>"></span><?php echo esc_html( OSB_Admin_Dashboard::item_name( $b, $names ) ); ?><?php echo $b->seats > 1 ? ' <span class="osb-muted">×' . (int) $b->seats . '</span>' : ''; ?></td>
								<td><?php echo esc_html( OSB_Settings::money( $b->amount, $b->currency ) ); ?><small><?php $ps = OSB_Bookings::payment_statuses(); echo esc_html( isset( $ps[ $b->payment_status ] ) ? $ps[ $b->payment_status ] : $b->payment_status ); ?></small></td>
								<td><?php echo OSB_Admin::status_pill( $b->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>
				</div>
			</div>

			<div class="osb-stack">
				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Contact', 'osam-bookings' ) ); ?>
					<dl class="osb-dl">
						<dt><?php esc_html_e( 'Email', 'osam-bookings' ); ?></dt><dd><a href="mailto:<?php echo esc_attr( $c['email'] ); ?>"><?php echo esc_html( $c['email'] ); ?></a></dd>
						<dt><?php esc_html_e( 'Phone', 'osam-bookings' ); ?></dt><dd><?php echo $c['phone'] ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $c['phone'] ) ) . '">' . esc_html( $c['phone'] ) . '</a>' : '<span class="osb-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
						<dt><?php esc_html_e( 'Next booking', 'osam-bookings' ); ?></dt><dd><?php echo esc_html( $c['next_at'] ? mysql2date( 'D j M Y, ' . get_option( 'time_format' ), $c['next_at'] ) : '—' ); ?></dd>
					</dl>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form">
					<?php wp_nonce_field( 'osb_save_customer' ); ?>
					<input type="hidden" name="action" value="osb_save_customer">
					<input type="hidden" name="email" value="<?php echo esc_attr( $c['email'] ); ?>">
					<?php OSB_Admin::card_head( __( 'Private notes', 'osam-bookings' ), __( 'Only you and your staff see these.', 'osam-bookings' ) ); ?>
					<div class="osb-field"><textarea name="notes" rows="5" placeholder="<?php esc_attr_e( 'Preferences, how they like to be contacted, anything to remember next time…', 'osam-bookings' ); ?>"><?php echo esc_textarea( $c['notes'] ); ?></textarea></div>
					<div class="osb-field"><label for="osb_tags"><?php esc_html_e( 'Tags', 'osam-bookings' ); ?></label><input type="text" id="osb_tags" name="tags" value="<?php echo esc_attr( implode( ', ', $c['tags'] ) ); ?>" placeholder="<?php esc_attr_e( 'VIP, regular, student', 'osam-bookings' ); ?>" list="osb-taglist"><p class="osb-help"><?php esc_html_e( 'Separate with commas. Filter the customer list by tag.', 'osam-bookings' ); ?></p>
						<datalist id="osb-taglist"><?php foreach ( OSB_Customers::all_tags() as $t ) : ?><option value="<?php echo esc_attr( $t ); ?>"><?php endforeach; ?></datalist>
					</div>
					<button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Save notes', 'osam-bookings' ); ?></button>
				</form>

				<div class="osb-card">
					<?php OSB_Admin::card_head( __( 'Their data (GDPR)', 'osam-bookings' ), __( 'If they ask for a copy of their data, or to be forgotten.', 'osam-bookings' ) ); ?>
					<p><a class="osb-btn osb-btn--ghost" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'email', rawurlencode( $c['email'] ), admin_url( 'admin-post.php?action=osb_export_customer_data' ) ), 'osb_export_customer_data' ) ); ?>"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Download their data', 'osam-bookings' ); ?></a></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-erase">
						<?php $osb_confirm = __( 'Erase this customer? Their name, contact details, form answers and notes are removed from past bookings for good. Upcoming bookings are kept until you cancel them. This can’t be undone.', 'osam-bookings' ); ?>
						<?php wp_nonce_field( 'osb_erase_customer' ); ?>
						<input type="hidden" name="action" value="osb_erase_customer">
						<input type="hidden" name="email" value="<?php echo esc_attr( $c['email'] ); ?>">
						<button class="osb-btn osb-btn--danger" data-osb-confirm="<?php echo esc_attr( $osb_confirm ); ?>"><span class="dashicons dashicons-trash"></span><?php esc_html_e( 'Erase their personal data', 'osam-bookings' ); ?></button>
					</form>
					<p class="osb-help"><?php echo wp_kses_post( sprintf( /* translators: %s url */ __( 'Dates and amounts are kept, without names, for your accounts. Requests can also be handled from <a href="%s">Tools → Erase Personal Data</a>.', 'osam-bookings' ), esc_url( admin_url( 'erase-personal-data.php' ) ) ) ); ?></p>
				</div>
			</div>
		</div>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Save notes.
	 */
	public static function save() {
		OSB_Admin::verify( 'osb_save_customer' );
		$post  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$email = isset( $post['email'] ) ? sanitize_email( $post['email'] ) : '';
		OSB_Customers::save_profile( $email, isset( $post['notes'] ) ? $post['notes'] : '', isset( $post['tags'] ) ? $post['tags'] : '' );
		OSB_Admin::redirect( self::url( $email ) );
	}

	/**
	 * Erase.
	 */
	public static function erase() {
		OSB_Admin::verify( 'osb_erase_customer', 'manage_options' );
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$res   = OSB_Customers::erase( $email );
		if ( $res['items_retained'] ) {
			OSB_Admin::flash_warnings( $res['messages'] );
			OSB_Admin::redirect( self::url( $email ), 'erased_partly' );
		}
		OSB_Admin::redirect( admin_url( 'admin.php?page=osb-customers' ), 'erased' );
	}

	/**
	 * CSV of everyone.
	 */
	public static function export() {
		OSB_Admin::verify( 'osb_export_customers' );
		$f = self::filters();
		self::csv( 'customers-' . current_time( 'Y-m-d' ) . '.csv', OSB_Customers::csv_rows( array( 'search' => $f['search'], 'tag' => $f['tag'] ) ) );
	}

	/**
	 * One person's data as JSON (their GDPR access request).
	 */
	public static function export_one() {
		OSB_Admin::verify( 'osb_export_customer_data' );
		$email = isset( $_GET['email'] ) ? sanitize_email( rawurldecode( wp_unslash( $_GET['email'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$out   = array();
		$page  = 1;
		do {
			$res = OSB_Customers::export( $email, $page );
			foreach ( $res['data'] as $item ) {
				$row = array();
				foreach ( $item['data'] as $pair ) {
					$row[ $pair['name'] ] = $pair['value'];
				}
				$out[ $item['group_label'] ][] = $row;
			}
			++$page;
		} while ( ! $res['done'] && $page < 100 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=customer-data-' . sanitize_file_name( $email ) . '.json' );
		echo wp_json_encode(
			array(
				'email'    => $email,
				'exported' => current_time( 'c' ),
				'site'     => home_url(),
				'data'     => $out,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		exit;
	}

	/**
	 * Stream a CSV.
	 *
	 * @param string $name File name.
	 * @param array  $rows Rows.
	 */
	public static function csv( $name, array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $name );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		foreach ( $rows as $r ) {
			// Stop spreadsheet apps treating values as formulas.
			$r = array_map(
				static function ( $v ) {
					$v = (string) $v;
					return preg_match( '/^[=+\-@\t\r]/', $v ) && ! is_numeric( $v ) ? "'" . $v : $v;
				},
				$r
			);
			fputcsv( $out, $r );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
