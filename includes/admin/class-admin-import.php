<?php
/**
 * Import / export services, events and courses as CSV.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Three steps: upload → check what will happen → import.
 */
class OSB_Admin_Import {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_import_preview', array( __CLASS__, 'preview' ) );
		add_action( 'admin_post_osb_import_run', array( __CLASS__, 'run' ) );
		add_action( 'admin_post_osb_import_template', array( __CLASS__, 'template' ) );
		add_action( 'admin_post_osb_export_items', array( __CLASS__, 'export' ) );
	}

	/**
	 * Import page URL.
	 *
	 * @param string $type Type.
	 * @param array  $args More args.
	 * @return string
	 */
	public static function url( $type, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'osb-import', 'type' => $type ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Export link.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	public static function export_url( $type ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=osb_export_items&type=' . $type ), 'osb_export_items' );
	}

	/**
	 * Header buttons for a list page.
	 *
	 * @param string $type Type.
	 * @return array
	 */
	public static function actions( $type ) {
		return array(
			array( __( 'Import', 'osam-bookings' ), self::url( $type ), 'secondary', 'dashicons-upload' ),
			array( __( 'Export', 'osam-bookings' ), self::export_url( $type ), 'secondary', 'dashicons-download' ),
		);
	}

	/**
	 * Requested type.
	 *
	 * @return string
	 */
	private static function type() {
		$type = isset( $_REQUEST['type'] ) ? sanitize_key( wp_unslash( $_REQUEST['type'] ) ) : 'services'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( OSB_Import::types()[ $type ] ) ? $type : 'services';
	}

	/**
	 * List page for a type.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	private static function list_url( $type ) {
		return admin_url( 'admin.php?page=osb-' . $type );
	}

	/**
	 * Transient key for the user's pending plan.
	 *
	 * @return string
	 */
	private static function key() {
		return 'osb_import_plan_' . get_current_user_id();
	}

	/**
	 * Page.
	 */
	public static function page() {
		$type  = self::type();
		$types = OSB_Import::types();
		$step  = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$plan  = 'preview' === $step ? get_transient( self::key() ) : false;
		if ( 'preview' === $step && ( ! $plan || $plan['type'] !== $type ) ) {
			$step = '';
		}
		/* translators: %s services/events/courses */
		OSB_Admin::open(
			sprintf( __( 'Import %s', 'osam-bookings' ), strtolower( $types[ $type ] ) ),
			array(
				'back'       => self::list_url( $type ),
				'back_label' => $types[ $type ],
				'subtitle'   => __( 'Add or update lots at once from a spreadsheet. Save it from Excel, Numbers or Google Sheets as CSV.', 'osam-bookings' ),
			)
		);
		if ( 'done' === $step ) {
			self::done( $type );
		} elseif ( $plan ) {
			self::preview_view( $plan );
		} else {
			self::upload_view( $type, $types );
		}
		OSB_Admin::close();
	}

	/**
	 * Step 1: upload.
	 *
	 * @param string $type  Type.
	 * @param array  $types Types.
	 */
	private static function upload_view( $type, $types ) {
		$cols = OSB_Import::columns( $type );
		?>
		<?php if ( count( $types ) > 1 ) : ?>
			<div class="osb-segmented">
				<?php foreach ( $types as $k => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $k ) ); ?>" class="<?php echo $k === $type ? 'is-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<div class="osb-grid-main">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="osb-card osb-form">
				<?php wp_nonce_field( 'osb_import_preview' ); ?>
				<input type="hidden" name="action" value="osb_import_preview">
				<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
				<?php OSB_Admin::card_head( __( 'Upload a CSV file', 'osam-bookings' ), __( 'Nothing is changed yet — you’ll see exactly what will be added and updated first.', 'osam-bookings' ) ); ?>
				<label class="osb-dropfile">
					<input type="file" name="file" accept=".csv,text/csv,.txt" required>
					<span class="dashicons dashicons-media-spreadsheet"></span>
					<strong><?php esc_html_e( 'Choose a CSV file', 'osam-bookings' ); ?></strong>
					<small><?php esc_html_e( 'or drag it here', 'osam-bookings' ); ?></small>
				</label>
				<label class="osb-switch"><input type="hidden" name="update" value="0"><input type="checkbox" name="update" value="1" checked><span></span>
					<?php
					if ( 'courses' === $type ) {
						esc_html_e( 'Update courses and intakes that already exist (matched by name)', 'osam-bookings' );
					} elseif ( 'events' === $type ) {
						esc_html_e( 'Update events that already exist (matched by ID, or by name and date)', 'osam-bookings' );
					} else {
						esc_html_e( 'Update services that already exist (matched by ID, or by name)', 'osam-bookings' );
					}
					?>
				</label>
				<div class="osb-formfoot">
					<a class="osb-btn osb-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=osb_import_template&type=' . $type ), 'osb_import_template' ) ); ?>"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Download template', 'osam-bookings' ); ?></a>
					<button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Check file', 'osam-bookings' ); ?></button>
				</div>
			</form>

			<div class="osb-card">
				<?php OSB_Admin::card_head( __( 'Tips', 'osam-bookings' ) ); ?>
				<ul class="osb-ticks">
					<li><?php esc_html_e( 'Start from the template, or Export what you have, edit it and import it back.', 'osam-bookings' ); ?></li>
					<li><?php esc_html_e( 'Column order doesn’t matter, and you can leave out columns you don’t need.', 'osam-bookings' ); ?></li>
					<li><?php esc_html_e( 'When updating, a blank cell keeps what’s there now.', 'osam-bookings' ); ?></li>
					<li><?php esc_html_e( 'Dates as 2026-11-14 or 14/11/2026; times as 18:00 or 6pm.', 'osam-bookings' ); ?></li>
					<?php if ( 'courses' === $type ) : ?>
						<li><?php esc_html_e( 'One row per session. Repeat the course and intake names on every row; course details only need filling in once.', 'osam-bookings' ); ?></li>
						<li><?php esc_html_e( 'An intake that already has bookings keeps its dates — change those on the intake page so everyone is emailed.', 'osam-bookings' ); ?></li>
					<?php endif; ?>
				</ul>
			</div>
		</div>

		<div class="osb-card osb-card--table">
			<?php OSB_Admin::card_head( __( 'Columns', 'osam-bookings' ), __( 'Headings can be written as shown, in any case.', 'osam-bookings' ) ); ?>
			<table class="osb-table">
				<thead><tr><th><?php esc_html_e( 'Column', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Example', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Notes', 'osam-bookings' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $cols as $c ) : ?>
					<tr><td><strong><?php echo esc_html( $c[0] ); ?></strong><?php echo $c[1] ? ' <span class="osb-req">*</span>' : ''; ?></td><td><?php echo '' !== $c[2] ? '<code>' . esc_html( $c[2] ) . '</code>' : '<span class="osb-muted">—</span>'; ?></td><td class="osb-muted"><?php echo esc_html( $c[3] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Step 2: what will happen.
	 *
	 * @param array $plan Plan.
	 */
	private static function preview_view( array $plan ) {
		$c     = $plan['counts'];
		$ready = $c['create'] + $c['update'];
		$pills = array(
			'create' => array( 'confirmed', __( 'New', 'osam-bookings' ) ),
			'update' => array( 'pending_payment', __( 'Update', 'osam-bookings' ) ),
			'skip'   => array( 'neutral', __( 'Skip', 'osam-bookings' ) ),
		);
		?>
		<div class="osb-minirow">
			<div class="osb-mini"><span><?php esc_html_e( 'New', 'osam-bookings' ); ?></span><strong><?php echo (int) $c['create']; ?></strong></div>
			<div class="osb-mini"><span><?php esc_html_e( 'Updated', 'osam-bookings' ); ?></span><strong><?php echo (int) $c['update']; ?></strong></div>
			<div class="osb-mini"><span><?php esc_html_e( 'Skipped', 'osam-bookings' ); ?></span><strong><?php echo (int) $c['skip']; ?></strong></div>
			<div class="osb-mini"><span><?php esc_html_e( 'Need fixing', 'osam-bookings' ); ?></span><strong<?php echo $c['error'] ? ' class="osb-bad"' : ''; ?>><?php echo (int) $c['error']; ?></strong></div>
		</div>
		<?php if ( ! empty( $plan['unknown'] ) ) : ?>
			<div class="osb-alert osb-alert--info"><span class="dashicons dashicons-info-outline"></span><div><?php /* translators: %s columns */ echo esc_html( sprintf( __( 'These columns aren’t used and will be ignored: %s', 'osam-bookings' ), implode( ', ', $plan['unknown'] ) ) ); ?></div></div>
		<?php endif; ?>
		<?php if ( $c['error'] ) : ?>
			<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div><?php esc_html_e( 'Rows that need fixing won’t be imported. Fix them in your spreadsheet and check the file again, or import the rest now.', 'osam-bookings' ); ?></div></div>
		<?php endif; ?>

		<div class="osb-card osb-card--table">
			<table class="osb-table osb-importplan">
				<thead><tr><th><?php esc_html_e( 'Line', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Item', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'What happens', 'osam-bookings' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $plan['items'] as $it ) : ?>
					<tr class="<?php echo $it['errors'] ? 'is-error' : ''; ?>">
						<td class="osb-muted"><?php echo esc_html( count( $it['lines'] ) > 1 ? min( $it['lines'] ) . '–' . max( $it['lines'] ) : (string) $it['line'] ); ?></td>
						<td>
							<strong><?php echo esc_html( '' !== $it['label'] ? $it['label'] : '—' ); ?></strong>
							<?php if ( ! empty( $it['detail'] ) ) : ?><small><?php echo esc_html( $it['detail'] ); ?></small><?php endif; ?>
							<?php foreach ( $it['errors'] as $e ) : ?><span class="osb-importplan__msg is-error"><?php echo esc_html( $e ); ?></span><?php endforeach; ?>
							<?php foreach ( $it['warnings'] as $w ) : ?><span class="osb-importplan__msg"><?php echo esc_html( $w ); ?></span><?php endforeach; ?>
						</td>
						<td>
							<?php if ( $it['errors'] ) : ?>
								<span class="osb-pill osb-pill--cancelled"><?php esc_html_e( 'Needs fixing', 'osam-bookings' ); ?></span>
							<?php else : ?>
								<span class="osb-pill osb-pill--<?php echo esc_attr( $pills[ $it['action'] ][0] ); ?>"><?php echo esc_html( $pills[ $it['action'] ][1] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-savebar">
			<?php wp_nonce_field( 'osb_import_run' ); ?>
			<input type="hidden" name="action" value="osb_import_run">
			<input type="hidden" name="type" value="<?php echo esc_attr( $plan['type'] ); ?>">
			<a class="osb-btn osb-btn--ghost" href="<?php echo esc_url( self::url( $plan['type'] ) ); ?>"><?php esc_html_e( 'Choose another file', 'osam-bookings' ); ?></a>
			<button class="osb-btn osb-btn--primary" <?php disabled( ! $ready ); ?>>
				<?php
				/* translators: %d items */
				echo esc_html( $ready ? sprintf( _n( 'Import %d item', 'Import %d items', $ready, 'osam-bookings' ), $ready ) : __( 'Nothing to import', 'osam-bookings' ) );
				?>
			</button>
		</form>
		<?php
	}

	/**
	 * Step 3: summary.
	 *
	 * @param string $type Type.
	 */
	private static function done( $type ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$created = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0;
		$updated = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0;
		$skipped = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
		// phpcs:enable
		?>
		<div class="osb-card osb-importdone">
			<span class="dashicons dashicons-yes-alt"></span>
			<h2><?php esc_html_e( 'Import finished', 'osam-bookings' ); ?></h2>
			<p>
				<?php
				/* translators: 1: created 2: updated 3: skipped */
				echo esc_html( sprintf( __( '%1$d added, %2$d updated, %3$d skipped.', 'osam-bookings' ), $created, $updated, $skipped ) );
				?>
			</p>
			<p>
				<a class="osb-btn osb-btn--primary" href="<?php echo esc_url( self::list_url( $type ) ); ?>"><?php esc_html_e( 'View them', 'osam-bookings' ); ?></a>
				<a class="osb-btn osb-btn--ghost" href="<?php echo esc_url( self::url( $type ) ); ?>"><?php esc_html_e( 'Import another file', 'osam-bookings' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the upload: read, plan, show.
	 */
	public static function preview() {
		OSB_Admin::verify( 'osb_import_preview' );
		$type = self::type();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			OSB_Admin::flash_error( __( 'Please choose a CSV file to upload.', 'osam-bookings' ) );
			OSB_Admin::redirect( self::url( $type ), 'none' );
		}
		if ( ! preg_match( '/\.(csv|txt)$/i', (string) $file['name'] ) ) {
			OSB_Admin::flash_error( __( 'That isn’t a CSV file. In Excel use File → Save As → “CSV UTF-8”.', 'osam-bookings' ) );
			OSB_Admin::redirect( self::url( $type ), 'none' );
		}
		$read = OSB_Import::read( $file['tmp_name'], $type );
		if ( is_wp_error( $read ) ) {
			OSB_Admin::flash_error( $read->get_error_message() );
			OSB_Admin::redirect( self::url( $type ), 'none' );
		}
		$plan            = OSB_Import::plan( $type, $read['rows'], ! empty( $_POST['update'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$plan['unknown'] = $read['unknown'];
		set_transient( self::key(), $plan, HOUR_IN_SECONDS );
		OSB_Admin::redirect( self::url( $type, array( 'step' => 'preview' ) ), 'none' );
	}

	/**
	 * Carry out the checked plan.
	 */
	public static function run() {
		OSB_Admin::verify( 'osb_import_run' );
		$type = self::type();
		$plan = get_transient( self::key() );
		if ( ! $plan || $plan['type'] !== $type ) {
			OSB_Admin::flash_error( __( 'That import has expired. Please upload the file again.', 'osam-bookings' ) );
			OSB_Admin::redirect( self::url( $type ), 'none' );
		}
		delete_transient( self::key() );
		$res = OSB_Import::run( $plan );
		OSB_Admin::redirect( self::url( $type, array( 'step' => 'done', 'created' => $res['created'], 'updated' => $res['updated'], 'skipped' => $res['skipped'] ) ), 'none' );
	}

	/**
	 * Download a template.
	 */
	public static function template() {
		OSB_Admin::verify( 'osb_import_template' );
		$type = self::type();
		OSB_Admin_Customers::csv( $type . '-import-template.csv', OSB_Import::template_rows( $type ) );
	}

	/**
	 * Export in the import format.
	 */
	public static function export() {
		OSB_Admin::verify( 'osb_export_items' );
		$type = self::type();
		OSB_Admin_Customers::csv( $type . '-' . current_time( 'Y-m-d' ) . '.csv', OSB_Import::export_rows( $type ) );
	}
}
