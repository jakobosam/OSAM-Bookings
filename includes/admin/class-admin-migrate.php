<?php
/**
 * Admin screens for moving in from Amelia or Wix Bookings.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Steps: choose where from → check (preview, nothing written) → import in batches → summary.
 * Lives on the Import screen (admin.php?page=osb-import&from=amelia|wix). Every action goes through
 * admin-post.php with a nonce and the bookings capability. Long jobs run as a series of short
 * requests: each does a few seconds' work and the page submits itself again until it is finished
 * (with a Continue button for browsers without JavaScript).
 */
class OSB_Admin_Migrate {

	/**
	 * Hooks.
	 */
	public static function init() {
		foreach ( array( 'check', 'fetch', 'run', 'step', 'cancel' ) as $a ) {
			add_action( 'admin_post_osb_migrate_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/**
	 * Screen URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'osb-import' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Requested source.
	 *
	 * @return string
	 */
	private static function from() {
		$f = isset( $_REQUEST['from'] ) ? sanitize_key( wp_unslash( $_REQUEST['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( OSB_Migrate::sources()[ $f ] ) ? $f : '';
	}

	/* ---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------ */

	/**
	 * Start page of the Import screen: the choices.
	 */
	public static function hub() {
		OSB_Admin::open(
			__( 'Import', 'osam-bookings' ),
			array( 'subtitle' => __( 'Bring your services, customers and bookings in from another booking system, or add services, events and courses from a spreadsheet.', 'osam-bookings' ) )
		);
		$job = OSB_Migrate::job();
		if ( $job ) {
			echo '<div class="osb-alert osb-alert--info"><span class="dashicons dashicons-info-outline"></span><div>';
			/* translators: %s system */
			echo esc_html( sprintf( __( 'You have an import from %s waiting.', 'osam-bookings' ), OSB_Migrate::sources()[ $job['source'] ] ) ) . ' ';
			echo '<a href="' . esc_url( self::url( array( 'from' => $job['source'], 'step' => empty( $job['state'] ) ? 'preview' : 'running' ) ) ) . '">' . esc_html__( 'Carry on', 'osam-bookings' ) . ' →</a>';
			echo '</div></div>';
		}
		?>
		<div class="osb-grid-2">
			<div class="osb-card">
				<?php OSB_Admin::card_head( __( 'Move from another booking system', 'osam-bookings' ), __( 'Services, customers and your past and upcoming bookings. Nobody is emailed and nothing is changed until you’ve seen what will happen.', 'osam-bookings' ) ); ?>
				<p>
					<a class="osb-btn osb-btn--primary" href="<?php echo esc_url( self::url( array( 'from' => 'amelia' ) ) ); ?>"><?php esc_html_e( 'From Amelia', 'osam-bookings' ); ?></a>
					<a class="osb-btn osb-btn--primary" href="<?php echo esc_url( self::url( array( 'from' => 'wix' ) ) ); ?>"><?php esc_html_e( 'From Wix Bookings', 'osam-bookings' ); ?></a>
				</p>
			</div>
			<div class="osb-card">
				<?php OSB_Admin::card_head( __( 'From a spreadsheet', 'osam-bookings' ), __( 'Add or update lots of services, events or courses at once from a CSV file.', 'osam-bookings' ) ); ?>
				<p>
					<?php foreach ( OSB_Import::types() as $k => $label ) : ?>
						<a class="osb-btn osb-btn--ghost" href="<?php echo esc_url( OSB_Admin_Import::url( $k ) ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</p>
			</div>
		</div>
		<?php
		OSB_Admin::close();
	}

	/**
	 * The moving-in screens.
	 */
	public static function page() {
		$from = self::from();
		$step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$job  = OSB_Migrate::job();
		if ( in_array( $step, array( 'preview', 'running' ), true ) ) {
			if ( ! $job ) {
				$step = '';
			} else {
				$from = $job['source'];
				$step = empty( $job['state'] ) ? 'preview' : 'running';
			}
		}
		if ( 'fetch' === $step && ! OSB_Import_Wix::fetch_state() ) {
			$step = '';
		}
		$name = OSB_Migrate::sources()[ $from ];
		OSB_Admin::open(
			/* translators: %s system */
			sprintf( __( 'Move from %s', 'osam-bookings' ), $name ),
			array(
				'back'       => self::url(),
				'back_label' => __( 'Import', 'osam-bookings' ),
				'subtitle'   => __( 'Nothing is written until you’ve checked what will happen. Nobody is emailed and nothing is added to Zoom or connected calendars.', 'osam-bookings' ),
			)
		);
		if ( 'preview' === $step ) {
			self::preview_view( $job );
		} elseif ( 'running' === $step ) {
			self::progress_view( 'osb_migrate_step', __( 'Importing…', 'osam-bookings' ), OSB_Migrate::progress( $job ), __( 'Keep this page open. Each batch takes a few seconds; if you close it, come back to Import to carry on.', 'osam-bookings' ) );
		} elseif ( 'fetch' === $step ) {
			$msg = get_transient( 'osb_migrate_fetchmsg_' . get_current_user_id() );
			self::progress_view( 'osb_migrate_fetch', __( 'Reading from Wix…', 'osam-bookings' ), null, $msg ? $msg : __( 'Connecting to Wix…', 'osam-bookings' ) );
		} elseif ( 'done' === $step ) {
			self::done_view();
		} elseif ( 'amelia' === $from ) {
			self::amelia_view();
		} else {
			self::wix_view();
		}
		OSB_Admin::close();
	}

	/**
	 * Hidden fields every form carries.
	 *
	 * @param string $action Action.
	 * @param string $from   Source.
	 */
	private static function fields( $action, $from ) {
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="from" value="' . esc_attr( $from ) . '">';
	}

	/**
	 * Amelia start.
	 */
	private static function amelia_view() {
		if ( ! OSB_Import_Amelia::available() ) {
			OSB_Admin::empty_state( 'dashicons-database', __( 'We can’t find Amelia’s data on this site', 'osam-bookings' ), __( 'Amelia must have been used on this same WordPress site. It can be switched off, but its data must still be there (don’t use Amelia’s “delete all data on uninstall”).', 'osam-bookings' ), array( __( 'Back to Import', 'osam-bookings' ), self::url() ) );
			return;
		}
		?>
		<div class="osb-grid-main">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form">
				<?php self::fields( 'osb_migrate_check', 'amelia' ); ?>
				<?php OSB_Admin::card_head( __( 'Amelia’s data on this site', 'osam-bookings' ) ); ?>
				<div class="osb-minirow">
					<?php foreach ( OSB_Import_Amelia::summary() as $label => $n ) : ?>
						<div class="osb-mini"><span><?php echo esc_html( $label ); ?></span><strong><?php echo (int) $n; ?></strong></div>
					<?php endforeach; ?>
				</div>
				<div class="osb-formfoot">
					<button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Check what would be imported', 'osam-bookings' ); ?></button>
				</div>
			</form>
			<div class="osb-card">
				<?php OSB_Admin::card_head( __( 'Good to know', 'osam-bookings' ) ); ?>
				<ul class="osb-ticks">
					<li><?php esc_html_e( 'Amelia is only read from — nothing in Amelia is changed.', 'osam-bookings' ); ?></li>
					<li><?php esc_html_e( 'Times are converted from Amelia’s stored UTC to your site’s timezone (Settings → General), so check that is right first.', 'osam-bookings' ); ?></li>
					<li><?php esc_html_e( 'Employees become staff members if this site is set up for a team. In solo mode everything goes into your own diary.', 'osam-bookings' ); ?></li>
					<li><?php esc_html_e( 'You can run it again later to pick up new bookings — nothing is added twice.', 'osam-bookings' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Wix start.
	 */
	private static function wix_view() {
		?>
		<div class="osb-grid-2">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="osb-card osb-form">
				<?php self::fields( 'osb_migrate_check', 'wix' ); ?>
				<input type="hidden" name="route" value="csv">
				<?php OSB_Admin::card_head( __( 'Upload Wix’s CSV files', 'osam-bookings' ), __( 'In Wix: Bookings → Booking List → set the dates to cover everything → scroll to the bottom so every booking loads → Download CSV. For customers: Contacts → More actions → Export.', 'osam-bookings' ) ); ?>
				<div class="osb-field">
					<label for="osb_wix_bookings"><?php esc_html_e( 'Booking list (CSV)', 'osam-bookings' ); ?></label>
					<input type="file" id="osb_wix_bookings" name="bookings" accept=".csv,text/csv,.txt" required>
				</div>
				<div class="osb-field">
					<label for="osb_wix_contacts"><?php esc_html_e( 'Contacts (CSV, optional)', 'osam-bookings' ); ?></label>
					<input type="file" id="osb_wix_contacts" name="contacts" accept=".csv,text/csv,.txt">
				</div>
				<div class="osb-field">
					<label for="osb_wix_order"><?php esc_html_e( 'Dates like 03/04/2026 mean', 'osam-bookings' ); ?></label>
					<select id="osb_wix_order" name="order">
						<option value="auto"><?php esc_html_e( 'Work it out from the file', 'osam-bookings' ); ?></option>
						<option value="dmy"><?php esc_html_e( '3 April (day/month)', 'osam-bookings' ); ?></option>
						<option value="mdy"><?php esc_html_e( 'March 4 (month/day)', 'osam-bookings' ); ?></option>
					</select>
				</div>
				<p class="osb-help"><?php esc_html_e( 'Wix has no services export, so a service is made for each service name in your bookings, with its length and price taken from those bookings. The files are read and then deleted.', 'osam-bookings' ); ?></p>
				<div class="osb-formfoot"><button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Check files', 'osam-bookings' ); ?></button></div>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form" autocomplete="off">
				<?php self::fields( 'osb_migrate_check', 'wix' ); ?>
				<input type="hidden" name="route" value="api">
				<?php OSB_Admin::card_head( __( 'Or connect to Wix directly', 'osam-bookings' ), __( 'Reads your real services (with prices and lengths), bookings and contacts. In Wix, go to your account’s API Keys page, generate a key with permission for Wix Bookings and Contacts, and copy it here.', 'osam-bookings' ) ); ?>
				<div class="osb-field">
					<label for="osb_wix_key"><?php esc_html_e( 'API key', 'osam-bookings' ); ?></label>
					<input type="password" id="osb_wix_key" name="api_key" required autocomplete="new-password">
				</div>
				<div class="osb-field">
					<label for="osb_wix_site"><?php esc_html_e( 'Site ID', 'osam-bookings' ); ?></label>
					<input type="text" id="osb_wix_site" name="site_id" required placeholder="1a2b3c4d-…">
					<p class="osb-help"><?php esc_html_e( 'In your Wix dashboard the web address contains /dashboard/ followed by the site ID.', 'osam-bookings' ); ?></p>
				</div>
				<p class="osb-help"><?php esc_html_e( 'The key is only kept while your data is being read, then deleted. You can delete the key in Wix afterwards too.', 'osam-bookings' ); ?></p>
				<div class="osb-formfoot"><button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Connect and check', 'osam-bookings' ); ?></button></div>
			</form>
		</div>
		<?php
	}

	/**
	 * Action word and pill class.
	 *
	 * @param string $act create|match|skip.
	 * @return array [ pill class, label ]
	 */
	private static function pill( $act ) {
		$p = array(
			'create' => array( 'confirmed', __( 'New', 'osam-bookings' ) ),
			'match'  => array( 'neutral', __( 'Already here', 'osam-bookings' ) ),
			'skip'   => array( 'cancelled', __( 'Left out', 'osam-bookings' ) ),
		);
		return isset( $p[ $act ] ) ? $p[ $act ] : $p['skip'];
	}

	/**
	 * Step 2: what will happen.
	 *
	 * @param array $job Job.
	 */
	private static function preview_view( array $job ) {
		$plan   = $job['plan'];
		$c      = $plan['counts'];
		$labels = array(
			'services'  => __( 'Services', 'osam-bookings' ),
			'staff'     => __( 'Staff', 'osam-bookings' ),
			'events'    => __( 'Events', 'osam-bookings' ),
			'customers' => __( 'Customer records', 'osam-bookings' ),
			'bookings'  => __( 'Bookings', 'osam-bookings' ),
		);
		$ready  = 0;
		foreach ( $c as $row ) {
			$ready += $row['create'];
		}
		?>
		<div class="osb-card osb-card--table">
			<?php OSB_Admin::card_head( __( 'What will happen', 'osam-bookings' ), __( '“Already here” items are matched to what you have and left as they are, so running this again never makes duplicates.', 'osam-bookings' ) ); ?>
			<table class="osb-table">
				<thead><tr><th></th><th><?php esc_html_e( 'New', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Already here', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Left out', 'osam-bookings' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $labels as $k => $label ) : ?>
					<?php
					if ( ! array_sum( $c[ $k ] ) ) {
						continue;
					}
					?>
					<tr><td><strong><?php echo esc_html( $label ); ?></strong></td><td><?php echo (int) $c[ $k ]['create']; ?></td><td><?php echo (int) $c[ $k ]['match']; ?></td><td<?php echo $c[ $k ]['skip'] ? ' class="osb-bad"' : ''; ?>><?php echo (int) $c[ $k ]['skip']; ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( $plan['upcoming'] || $plan['past_done'] ) : ?>
			<div class="osb-alert osb-alert--info"><span class="dashicons dashicons-info-outline"></span><div>
				<?php
				/* translators: 1: upcoming bookings 2: past confirmed bookings */
				echo esc_html( sprintf( __( 'Still to come: %1$d (they keep their status and paid/unpaid state). Confirmed and already past: %2$d (they come in as “Completed”).', 'osam-bookings' ), $plan['upcoming'], $plan['past_done'] ) );
				?>
			</div></div>
		<?php endif; ?>

		<?php if ( $plan['warnings'] ) : ?>
			<div class="osb-alert osb-alert--warn"><span class="dashicons dashicons-warning"></span><div><strong><?php esc_html_e( 'Please check', 'osam-bookings' ); ?></strong><ul>
				<?php foreach ( $plan['warnings'] as $w ) : ?><li><?php echo esc_html( $w ); ?></li><?php endforeach; ?>
			</ul></div></div>
		<?php endif; ?>

		<?php if ( $plan['reasons'] ) : ?>
			<div class="osb-card osb-card--table">
				<?php OSB_Admin::card_head( __( 'Bookings left out, and why', 'osam-bookings' ) ); ?>
				<table class="osb-table"><tbody>
				<?php
				$why = OSB_Migrate::reasons();
				foreach ( $plan['reasons'] as $code => $n ) :
					?>
					<tr><td><?php echo esc_html( isset( $why[ $code ] ) ? $why[ $code ] : $code ); ?></td><td><strong><?php echo (int) $n; ?></strong></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $job['columns'] ) ) : ?>
			<div class="osb-card osb-card--table">
				<?php OSB_Admin::card_head( __( 'Columns we recognised', 'osam-bookings' ), __( 'If something important is in the “not used” list, rename that heading in the file to one of ours and check again.', 'osam-bookings' ) ); ?>
				<table class="osb-table">
					<thead><tr><th><?php esc_html_e( 'File', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Your heading → what we read it as', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Not used', 'osam-bookings' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $job['columns'] as $f ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $f['file'] ); ?></strong></td>
							<td>
								<?php
								$bits = array();
								foreach ( $f['recognised'] as $h => $as ) {
									$bits[] = $h . ' → ' . $as;
								}
								echo esc_html( $bits ? implode( ' · ', $bits ) : __( 'None', 'osam-bookings' ) );
								?>
							</td>
							<td class="osb-muted"><?php echo esc_html( $f['ignored'] ? implode( ', ', $f['ignored'] ) : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<?php
		foreach ( array( 'services' => __( 'Services', 'osam-bookings' ), 'staff' => __( 'Staff', 'osam-bookings' ), 'events' => __( 'Events', 'osam-bookings' ) ) as $k => $title ) :
			if ( empty( $plan['lists'][ $k ] ) ) {
				continue;
			}
			?>
			<div class="osb-card osb-card--table">
				<?php OSB_Admin::card_head( $title ); ?>
				<table class="osb-table osb-importplan"><tbody>
				<?php foreach ( $plan['lists'][ $k ] as $it ) : ?>
					<?php $p = self::pill( $it[1] ); ?>
					<tr><td><strong><?php echo esc_html( $it[0] ); ?></strong><?php echo ! empty( $it[2] ) ? '<small>' . esc_html( $it[2] ) . '</small>' : ''; ?></td><td><span class="osb-pill osb-pill--<?php echo esc_attr( $p[0] ); ?>"><?php echo esc_html( $p[1] ); ?></span></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>
		<?php endforeach; ?>

		<?php if ( $plan['emails'] ) : ?>
			<div class="osb-card osb-card--table">
				<?php OSB_Admin::card_head( __( 'Email wording', 'osam-bookings' ), __( 'Only if you tick “Bring the email wording across” below. The wording you have now is kept aside and can be restored.', 'osam-bookings' ) ); ?>
				<table class="osb-table osb-importplan">
					<thead><tr><th><?php esc_html_e( 'Their email', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Becomes', 'osam-bookings' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $plan['emails'] as $em ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $em['label'] ); ?></strong><?php echo $em['enabled'] ? '' : '<small>' . esc_html__( 'Switched off in the old system', 'osam-bookings' ) . '</small>'; ?>
								<?php if ( $em['unknown'] ) : ?>
									<span class="osb-importplan__msg">
										<?php
										/* translators: %s placeholders */
										echo esc_html( sprintf( __( 'No match for: %s — left in the text as written.', 'osam-bookings' ), implode( ', ', $em['unknown'] ) ) );
										?>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $em['target_label'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-card osb-form">
			<?php self::fields( 'osb_migrate_run', $job['source'] ); ?>
			<?php OSB_Admin::card_head( __( 'Options', 'osam-bookings' ) ); ?>
			<?php if ( $plan['emails'] ) : ?>
				<label class="osb-switch"><input type="checkbox" name="emails" value="1"><span></span><?php esc_html_e( 'Bring the email wording across', 'osam-bookings' ); ?></label>
			<?php endif; ?>
			<?php if ( $plan['upcoming'] ) : ?>
				<label class="osb-switch"><input type="checkbox" name="reminders" value="1"><span></span><?php esc_html_e( 'Send the usual reminder emails before upcoming imported bookings', 'osam-bookings' ); ?></label>
				<p class="osb-help"><?php esc_html_e( 'Off: customers already had reminders from your old system, so they won’t get another from here.', 'osam-bookings' ); ?></p>
				<?php if ( ! empty( $plan['calendars'] ) ) : ?>
					<label class="osb-switch"><input type="checkbox" name="calendars" value="1"><span></span><?php esc_html_e( 'Add upcoming imported bookings to the connected Google / Outlook calendars', 'osam-bookings' ); ?></label>
					<p class="osb-help"><?php esc_html_e( 'Off: they stay out of those calendars (handy when your old system already put them there). Bookings made from now on are added as normal.', 'osam-bookings' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<div class="osb-formfoot">
				<button class="osb-btn osb-btn--ghost" form="osb-migrate-cancel"><?php esc_html_e( 'Start again', 'osam-bookings' ); ?></button>
				<button class="osb-btn osb-btn--primary" <?php disabled( ! $ready ); ?>>
					<?php echo esc_html( $ready ? __( 'Import now', 'osam-bookings' ) : __( 'Nothing new to import', 'osam-bookings' ) ); ?>
				</button>
			</div>
		</form>
		<form id="osb-migrate-cancel" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php self::fields( 'osb_migrate_cancel', $job['source'] ); ?></form>
		<?php
	}

	/**
	 * A page that keeps going by itself.
	 *
	 * @param string     $action   Next admin-post action.
	 * @param string     $title    Title.
	 * @param array|null $progress [ done, total ] or null.
	 * @param string     $note     Text.
	 */
	private static function progress_view( $action, $title, $progress, $note ) {
		$pct = $progress && $progress[1] ? min( 100, (int) round( 100 * $progress[0] / $progress[1] ) ) : 0;
		?>
		<div class="osb-card osb-importdone">
			<span class="dashicons dashicons-update"></span>
			<h2><?php echo esc_html( $title ); ?></h2>
			<?php if ( $progress ) : ?>
				<p><span class="osb-meter"><span style="width:<?php echo (int) $pct; ?>%"></span></span></p>
				<p><?php echo esc_html( $pct . '%' ); ?></p>
			<?php endif; ?>
			<p class="osb-muted"><?php echo esc_html( $note ); ?></p>
			<form id="osb-migrate-next" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php self::fields( $action, self::from() ); ?>
				<button class="osb-btn osb-btn--primary"><?php esc_html_e( 'Continue', 'osam-bookings' ); ?></button>
			</form>
		</div>
		<script>setTimeout(function(){var f=document.getElementById('osb-migrate-next');if(f){f.querySelector('button').disabled=true;f.submit();}},400);</script>
		<?php
	}

	/**
	 * Step 4: summary.
	 */
	private static function done_view() {
		$r = OSB_Migrate::last_result();
		if ( ! $r ) {
			OSB_Admin::empty_state( 'dashicons-yes-alt', __( 'Import finished', 'osam-bookings' ), '' );
			return;
		}
		$res    = $r['res'];
		$labels = array(
			'services'  => __( 'Services', 'osam-bookings' ),
			'staff'     => __( 'Staff', 'osam-bookings' ),
			'events'    => __( 'Events', 'osam-bookings' ),
			'customers' => __( 'Customer records', 'osam-bookings' ),
			'bookings'  => __( 'Bookings', 'osam-bookings' ),
		);
		?>
		<div class="osb-card osb-importdone">
			<span class="dashicons dashicons-yes-alt"></span>
			<h2><?php esc_html_e( 'Import finished', 'osam-bookings' ); ?></h2>
			<?php foreach ( $labels as $k => $label ) : ?>
				<?php
				if ( ! array_sum( $res[ $k ] ) ) {
					continue;
				}
				?>
				<p>
					<?php
					/* translators: 1: what 2: added 3: already there 4: left out */
					echo esc_html( sprintf( __( '%1$s: %2$d added, %3$d already here, %4$d left out.', 'osam-bookings' ), $label, $res[ $k ]['create'], $res[ $k ]['match'], $res[ $k ]['skip'] ) );
					?>
				</p>
			<?php endforeach; ?>
			<?php if ( $res['emails'] ) : ?>
				<p>
					<?php
					/* translators: %d emails */
					echo esc_html( sprintf( _n( '%d email brought across. Ones with no standard match are under Emails, switched off.', '%d emails brought across. Ones with no standard match are under Emails, switched off.', $res['emails'], 'osam-bookings' ), $res['emails'] ) );
					?>
				</p>
			<?php endif; ?>
			<p class="osb-muted"><?php esc_html_e( 'Each imported booking has a note saying where it came from. New staff need their working hours set before they can be booked online.', 'osam-bookings' ); ?></p>
			<p>
				<a class="osb-btn osb-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-bookings' ) ); ?>"><?php esc_html_e( 'View bookings', 'osam-bookings' ); ?></a>
				<a class="osb-btn osb-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=osb-services' ) ); ?>"><?php esc_html_e( 'View services', 'osam-bookings' ); ?></a>
			</p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * An uploaded file, or null.
	 *
	 * @param string $field Field.
	 * @return string|null|WP_Error Temporary path.
	 */
	private static function upload( $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$f = isset( $_FILES[ $field ] ) && is_array( $_FILES[ $field ] ) ? $_FILES[ $field ] : null;
		if ( ! $f || UPLOAD_ERR_NO_FILE === (int) $f['error'] ) {
			return null;
		}
		if ( UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
			return new WP_Error( 'osb_upload', __( 'The file didn’t upload. Please try again.', 'osam-bookings' ) );
		}
		if ( ! preg_match( '/\.(csv|txt)$/i', (string) $f['name'] ) ) {
			wp_delete_file( $f['tmp_name'] );
			return new WP_Error( 'osb_upload_type', __( 'That isn’t a CSV file. Please choose the .csv file Wix gave you.', 'osam-bookings' ) );
		}
		return $f['tmp_name'];
	}

	/**
	 * Check (preview).
	 */
	public static function handle_check() {
		OSB_Admin::verify( 'osb_migrate_check' );
		$from = self::from();
		$back = self::url( array( 'from' => $from ) );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		if ( 'amelia' === $from ) {
			$data = OSB_Import_Amelia::dataset();
		} elseif ( 'wix' === $from && isset( $_POST['route'] ) && 'api' === $_POST['route'] ) {
			$ok = OSB_Import_Wix::fetch_start( isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '', isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( $_POST['site_id'] ) ) : '' );
			if ( is_wp_error( $ok ) ) {
				OSB_Admin::flash_error( $ok->get_error_message() );
				OSB_Admin::redirect( $back, 'none' );
			}
			OSB_Admin::redirect( self::url( array( 'from' => 'wix', 'step' => 'fetch' ) ), 'none' );
		} elseif ( 'wix' === $from ) {
			$bookings = self::upload( 'bookings' );
			$contacts = self::upload( 'contacts' );
			$order    = isset( $_POST['order'] ) && in_array( $_POST['order'], array( 'dmy', 'mdy' ), true ) ? sanitize_key( $_POST['order'] ) : 'auto';
			if ( ! $bookings || is_wp_error( $bookings ) ) {
				$data = is_wp_error( $bookings ) ? $bookings : new WP_Error( 'osb_upload', __( 'Please choose the booking list CSV file.', 'osam-bookings' ) );
			} elseif ( is_wp_error( $contacts ) ) {
				$data = $contacts;
			} else {
				$data = OSB_Import_Wix::dataset_from_csv( $bookings, $contacts, $order );
			}
			// The uploads have been read: delete them.
			foreach ( array( $bookings, $contacts ) as $p ) {
				if ( is_string( $p ) && file_exists( $p ) ) {
					wp_delete_file( $p );
				}
			}
		} else {
			OSB_Admin::redirect( self::url(), 'none' );
		}
		// phpcs:enable
		if ( is_wp_error( $data ) ) {
			OSB_Admin::flash_error( $data->get_error_message() );
			OSB_Admin::redirect( $back, 'none' );
		}
		if ( ! $data['bookings'] && ! $data['services'] && ! $data['customers'] ) {
			OSB_Admin::flash_error( __( 'We didn’t find anything to import.', 'osam-bookings' ) );
			OSB_Admin::redirect( $back, 'none' );
		}
		OSB_Migrate::store( $data );
		OSB_Admin::redirect( self::url( array( 'from' => $from, 'step' => 'preview' ) ), 'none' );
	}

	/**
	 * Read more from Wix.
	 */
	public static function handle_fetch() {
		OSB_Admin::verify( 'osb_migrate_fetch' );
		$r = OSB_Import_Wix::fetch_step( 8 );
		if ( is_wp_error( $r ) ) {
			OSB_Admin::flash_error( $r->get_error_message() );
			OSB_Admin::redirect( self::url( array( 'from' => 'wix' ) ), 'none' );
		}
		if ( $r[0] ) {
			delete_transient( 'osb_migrate_fetchmsg_' . get_current_user_id() );
			if ( ! $r[1]['bookings'] && ! $r[1]['services'] && ! $r[1]['customers'] ) {
				OSB_Admin::flash_error( __( 'Wix answered, but there were no services, bookings or contacts to import.', 'osam-bookings' ) );
				OSB_Admin::redirect( self::url( array( 'from' => 'wix' ) ), 'none' );
			}
			OSB_Migrate::store( $r[1] );
			OSB_Admin::redirect( self::url( array( 'from' => 'wix', 'step' => 'preview' ) ), 'none' );
		}
		set_transient( 'osb_migrate_fetchmsg_' . get_current_user_id(), $r[1], HOUR_IN_SECONDS );
		OSB_Admin::redirect( self::url( array( 'from' => 'wix', 'step' => 'fetch' ) ), 'none' );
	}

	/**
	 * Start the import.
	 */
	public static function handle_run() {
		OSB_Admin::verify( 'osb_migrate_run' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$ok = OSB_Migrate::start(
			array(
				'emails'    => ! empty( $_POST['emails'] ),
				'reminders' => ! empty( $_POST['reminders'] ),
				'calendars' => ! empty( $_POST['calendars'] ),
			)
		);
		// phpcs:enable
		if ( ! $ok ) {
			OSB_Admin::flash_error( __( 'That import has expired. Please check your data again.', 'osam-bookings' ) );
			OSB_Admin::redirect( self::url( array( 'from' => self::from() ) ), 'none' );
		}
		OSB_Admin::redirect( self::url( array( 'from' => self::from(), 'step' => 'running' ) ), 'none' );
	}

	/**
	 * One batch.
	 */
	public static function handle_step() {
		OSB_Admin::verify( 'osb_migrate_step' );
		$from = self::from();
		$r    = OSB_Migrate::step( 8 );
		if ( is_wp_error( $r ) ) {
			OSB_Admin::flash_error( $r->get_error_message() );
			OSB_Admin::redirect( self::url( array( 'from' => $from ) ), 'none' );
		}
		OSB_Admin::redirect( self::url( array( 'from' => $from, 'step' => $r[0] ? 'done' : 'running' ) ), 'none' );
	}

	/**
	 * Throw away a checked import (and any Wix connection in progress).
	 */
	public static function handle_cancel() {
		OSB_Admin::verify( 'osb_migrate_cancel' );
		OSB_Migrate::discard();
		OSB_Import_Wix::fetch_discard();
		OSB_Admin::redirect( self::url( array( 'from' => self::from() ) ), 'none' );
	}
}
