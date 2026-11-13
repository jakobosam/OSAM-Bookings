<?php
/**
 * Bookings → Emails: the standard emails and the site's own.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * List, presets and editor for custom emails.
 */
class OSB_Admin_Custom_Emails {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_osb_save_custom_email', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_osb_custom_email_action', array( __CLASS__, 'action' ) );
		add_action( 'admin_post_osb_std_email_toggle', array( __CLASS__, 'std_toggle' ) );
	}

	/**
	 * Page URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'osb-emails' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Router.
	 */
	public static function page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['id'] ) ? sanitize_key( $_GET['id'] ) : '';
		$preset = isset( $_GET['preset'] ) ? sanitize_key( $_GET['preset'] ) : '';
		$replace = isset( $_GET['replace'] ) ? sanitize_key( $_GET['replace'] ) : '';
		// phpcs:enable
		if ( 'edit' === $action && OSB_Custom_Emails::get( $id ) ) {
			self::editor( OSB_Custom_Emails::get( $id ) );
		} elseif ( 'new' === $action && $replace && isset( OSB_Settings::email_labels()[ $replace ] ) ) {
			$tpl = OSB_Settings::get( 'emails' )[ $replace ];
			$lab = OSB_Settings::email_labels()[ $replace ];
			self::editor(
				wp_parse_args(
					array(
						/* translators: %s standard email */
						'name'        => sprintf( __( '%s (for some bookings)', 'osam-bookings' ), preg_replace( '/^[^—]+—\s*/', '', $lab ) ),
						'trigger'     => 'replace:' . $replace,
						'to_customer' => 0,
						'subject'     => $tpl['subject'],
						'body'        => $tpl['body'],
					),
					OSB_Custom_Emails::defaults()
				)
			);
		} elseif ( 'new' === $action && $preset && isset( OSB_Custom_Emails::presets()[ $preset ] ) ) {
			self::editor( wp_parse_args( OSB_Custom_Emails::presets()[ $preset ][2], OSB_Custom_Emails::defaults() ) );
		} elseif ( 'new' === $action ) {
			self::chooser();
		} else {
			self::listing();
		}
	}

	/**
	 * Who it goes to, in words.
	 *
	 * @param array $def Definition.
	 * @return string
	 */
	private static function describe_to( array $def ) {
		$who = array();
		if ( $def['to_customer'] ) {
			$who[] = __( 'Customer', 'osam-bookings' );
		}
		if ( $def['to_attendees'] ) {
			$who[] = __( 'Other attendees', 'osam-bookings' );
		}
		if ( $def['to_staff'] ) {
			$who[] = __( 'Staff member', 'osam-bookings' );
		}
		if ( $def['to_admin'] ) {
			$who[] = __( 'Admin', 'osam-bookings' );
		}
		if ( $def['to_extra'] ) {
			$who[] = $def['to_extra'];
		}
		if ( ! $who && 0 === strpos( $def['trigger'], 'replace:' ) ) {
			return __( 'Same as the standard email', 'osam-bookings' );
		}
		return $who ? implode( ', ', $who ) : __( 'Nobody yet', 'osam-bookings' );
	}

	/**
	 * When each standard email goes out, in words.
	 *
	 * @return array key => text
	 */
	public static function standard_when() {
		$rem = (float) OSB_Settings::get( 'reminder_hours' );
		$fol = (float) OSB_Settings::get( 'followup_hours' );
		return array(
			'customer_pending'          => __( 'A booking needs your approval', 'osam-bookings' ),
			'customer_confirmed'        => __( 'A booking is confirmed (after payment, when paid online)', 'osam-bookings' ),
			'customer_payment_failed'   => __( 'An online payment fails or isn’t finished in time', 'osam-bookings' ),
			'customer_rescheduled'      => __( 'A booking is moved to a new time', 'osam-bookings' ),
			'customer_cancelled'        => __( 'A booking is cancelled', 'osam-bookings' ),
			/* translators: %s hours */
			'customer_reminder'         => $rem > 0 ? sprintf( __( '%s hours before it starts', 'osam-bookings' ), OSB_Admin_Custom_Emails::num( $rem ) ) : __( 'Off — set the hours in Settings → Emails', 'osam-bookings' ),
			/* translators: %s hours */
			'customer_followup'         => sprintf( __( '%s hours after it ends', 'osam-bookings' ), OSB_Admin_Custom_Emails::num( $fol ) ),
			'customer_bundle'           => __( 'A bundle is paid for', 'osam-bookings' ),
			'customer_login'            => __( 'A customer asks to sign in to My bookings', 'osam-bookings' ),
			'customer_access_code'      => __( 'When a resource’s access code is released (if it’s not in the confirmation)', 'osam-bookings' ),
			'customer_session_reminder' => __( 'Before each course session', 'osam-bookings' ),
			'customer_schedule_changed' => __( 'A course’s dates change', 'osam-bookings' ),
			'customer_waitlist_offer'   => __( 'A place opens up for someone on the waiting list', 'osam-bookings' ),
			'customer_invoice'          => __( 'You email an invoice, or a booking is paid later (if that’s switched on)', 'osam-bookings' ),
			'customer_credit_note'      => __( 'A refund creates a credit note', 'osam-bookings' ),
			'admin_new'                 => __( 'Any new booking', 'osam-bookings' ),
			'admin_rescheduled'         => __( 'A customer moves their booking', 'osam-bookings' ),
			'admin_cancelled'           => __( 'A booking is cancelled', 'osam-bookings' ),
			'admin_payment_failed'      => __( 'An online payment fails or isn’t finished in time', 'osam-bookings' ),
			'staff_new'                 => __( 'A new booking with that staff member', 'osam-bookings' ),
			'staff_cancelled'           => __( 'A booking with that staff member is cancelled', 'osam-bookings' ),
		);
	}

	/**
	 * 24 / 1.5.
	 *
	 * @param float $n Number.
	 * @return string
	 */
	public static function num( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 1, '.', '' ), '0' ), '.' );
	}

	/**
	 * List: the standard emails, then the site's own.
	 */
	private static function listing() {
		$all      = OSB_Custom_Emails::all();
		$counts   = OSB_Custom_Emails::sent_counts();
		$labels   = OSB_Settings::email_labels();
		$tpls     = OSB_Settings::get( 'emails' );
		$when     = self::standard_when();
		$replaced = array();
		foreach ( $all as $def ) {
			if ( $def['enabled'] && 0 === strpos( $def['trigger'], 'replace:' ) ) {
				$replaced[ substr( $def['trigger'], 8 ) ] = true;
			}
		}
		OSB_Admin::open(
			__( 'Emails', 'osam-bookings' ),
			array(
				'subtitle' => __( 'Every email the booking system sends. The standard ones are ready to go — change the wording, switch any off, or add your own for particular services, events or courses.', 'osam-bookings' ),
				'actions'  => array(
					array( __( 'Email design', 'osam-bookings' ), admin_url( 'admin.php?page=osb-settings&tab=emails#osb-email-design' ), 'secondary', 'dashicons-art' ),
					array( __( 'Add an email', 'osam-bookings' ), self::url( array( 'action' => 'new' ) ), 'primary', 'dashicons-plus-alt2' ),
				),
			)
		);
		$hidden = array();
		foreach ( OSB_Settings::email_sections() as $section ) :
			$keys = array_values( array_filter( $section['keys'], static function ( $k ) use ( $labels ) { return isset( $labels[ $k ] ); } ) );
			if ( ! $section['on'] ) {
				if ( $keys ) {
					$hidden[] = $section;
				}
				continue;
			}
			if ( ! $keys ) {
				continue;
			}
			?>
			<h3 class="osb-grouptitle"><?php echo esc_html( $section['title'] ); ?><?php if ( $section['desc'] ) : ?> <small class="osb-grouptitle__desc"><?php echo esc_html( $section['desc'] ); ?></small><?php endif; ?></h3>
			<div class="osb-card osb-card--table">
				<table class="osb-table osb-table--click osb-table--emails">
					<thead><tr>
						<th><?php esc_html_e( 'Email', 'osam-bookings' ); ?></th>
						<th><?php esc_html_e( 'To', 'osam-bookings' ); ?></th>
						<th><?php esc_html_e( 'Sent when', 'osam-bookings' ); ?></th>
						<th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th>
						<th class="osb-col-actions"></th>
					</tr></thead>
					<tbody>
					<?php
					foreach ( $keys as $key ) :
						$tpl  = isset( $tpls[ $key ] ) ? $tpls[ $key ] : array( 'enabled' => 0, 'subject' => '' );
						$edit = admin_url( 'admin.php?page=osb-settings&tab=emails#osb-tpl-' . $key );
						$tog  = wp_nonce_url( admin_url( 'admin-post.php?action=osb_std_email_toggle&key=' . $key . '&on=' . ( empty( $tpl['enabled'] ) ? 1 : 0 ) ), 'osb_std_email_' . $key );
						$name = preg_replace( '/^[^—]+—\s*/', '', $labels[ $key ] );
						?>
						<tr>
							<td><a class="osb-rowlink" href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 ) : ucfirst( $name ) ); ?></strong></a><small><?php echo esc_html( $tpl['subject'] ); ?></small></td>
							<td><?php echo esc_html( OSB_Settings::email_recipient( $key ) ); ?></td>
							<td><?php echo esc_html( $when[ $key ] ?? '' ); ?><?php echo isset( $replaced[ $key ] ) ? '<small>' . esc_html__( 'Some bookings get one of your own emails instead', 'osam-bookings' ) . '</small>' : ''; ?></td>
							<td><a href="<?php echo esc_url( $tog ); ?>" title="<?php echo esc_attr( empty( $tpl['enabled'] ) ? __( 'Click to switch on', 'osam-bookings' ) : __( 'Click to switch off', 'osam-bookings' ) ); ?>"><?php echo empty( $tpl['enabled'] ) ? '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Off', 'osam-bookings' ) . '</span>' : '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'On', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></td>
							<td class="osb-col-actions">
								<a class="osb-iconbtn" href="<?php echo esc_url( $edit ); ?>" title="<?php esc_attr_e( 'Edit the wording', 'osam-bookings' ); ?>"><span class="dashicons dashicons-edit"></span></a>
								<a class="osb-iconbtn" href="<?php echo esc_url( self::url( array( 'action' => 'new', 'replace' => $key ) ) ); ?>" title="<?php esc_attr_e( 'Use a different version for some bookings', 'osam-bookings' ); ?>"><span class="dashicons dashicons-randomize"></span></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
		endforeach;
		if ( $hidden ) :
			?>
			<p class="osb-help osb-emails-hidden"><span class="dashicons dashicons-hidden" aria-hidden="true"></span>
				<?php
				$names = array();
				foreach ( $hidden as $h ) {
					$names[] = $h['title'] . ( $h['off_hint'] ? ' (' . $h['off_hint'] . ')' : '' );
				}
				/* translators: %s list of sections */
				echo esc_html( sprintf( __( 'Hidden and not sent because that part is switched off: %s.', 'osam-bookings' ), implode( '; ', $names ) ) );
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=osb-settings&tab=features' ) ); ?>"><?php esc_html_e( 'Features on/off', 'osam-bookings' ); ?> →</a>
			</p>
			<?php
		endif;
		?>
		<h3 class="osb-grouptitle"><?php esc_html_e( 'Your own emails', 'osam-bookings' ); ?></h3>
		<?php if ( ! $all ) : ?>
			<div class="osb-card osb-emptyline">
				<p><?php esc_html_e( 'Aftercare, how to prepare, a welcome for first-timers, payment reminders, staff briefs — sent for just the services, events or courses you choose.', 'osam-bookings' ); ?></p>
				<a class="osb-btn osb-btn--primary osb-btn--sm" href="<?php echo esc_url( self::url( array( 'action' => 'new' ) ) ); ?>"><span class="dashicons dashicons-plus-alt2"></span><?php esc_html_e( 'Add an email', 'osam-bookings' ); ?></a>
			</div>
		<?php else : ?>
		<div class="osb-card osb-card--table">
			<table class="osb-table osb-table--click osb-table--emails">
				<thead><tr>
					<th><?php esc_html_e( 'Email', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Sent', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'To', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'For', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'So far', 'osam-bookings' ); ?></th>
					<th><?php esc_html_e( 'Status', 'osam-bookings' ); ?></th>
					<th class="osb-col-actions"></th>
				</tr></thead>
				<tbody>
				<?php
				foreach ( $all as $id => $def ) :
					$edit = self::url( array( 'action' => 'edit', 'id' => $id ) );
					$act  = static function ( $do ) use ( $id ) {
						return wp_nonce_url( admin_url( 'admin-post.php?action=osb_custom_email_action&do=' . $do . '&id=' . rawurlencode( $id ) ), 'osb_custom_email_' . $id );
					};
					$c = $counts[ $id ] ?? array( 0, '' );
					?>
					<tr>
						<td><a class="osb-rowlink" href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( $def['name'] ? $def['name'] : __( '(no name)', 'osam-bookings' ) ); ?></strong></a><small><?php echo esc_html( $def['subject'] ); ?></small></td>
						<td><?php echo esc_html( OSB_Custom_Emails::describe_when( $def ) ); ?></td>
						<td><?php echo esc_html( self::describe_to( $def ) ); ?></td>
						<td><?php echo esc_html( OSB_Custom_Emails::describe_items( $def ) ); ?></td>
						<td><?php echo (int) $c[0]; ?><?php echo $c[1] ? '<small>' . esc_html( sprintf( /* translators: %s time ago */ __( 'last %s ago', 'osam-bookings' ), human_time_diff( strtotime( $c[1] ), strtotime( current_time( 'mysql' ) ) ) ) ) . '</small>' : ''; ?></td>
						<td><a href="<?php echo esc_url( $act( $def['enabled'] ? 'off' : 'on' ) ); ?>" title="<?php echo esc_attr( $def['enabled'] ? __( 'Click to switch off', 'osam-bookings' ) : __( 'Click to switch on', 'osam-bookings' ) ); ?>"><?php echo $def['enabled'] ? '<span class="osb-pill osb-pill--confirmed">' . esc_html__( 'On', 'osam-bookings' ) . '</span>' : '<span class="osb-pill osb-pill--neutral">' . esc_html__( 'Off', 'osam-bookings' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></td>
						<td class="osb-col-actions">
							<a class="osb-iconbtn" href="<?php echo esc_url( $edit ); ?>" title="<?php esc_attr_e( 'Edit', 'osam-bookings' ); ?>"><span class="dashicons dashicons-edit"></span></a>
							<a class="osb-iconbtn" href="<?php echo esc_url( $act( 'duplicate' ) ); ?>" title="<?php esc_attr_e( 'Duplicate', 'osam-bookings' ); ?>"><span class="dashicons dashicons-admin-page"></span></a>
							<a class="osb-iconbtn osb-iconbtn--danger osb-delete" href="<?php echo esc_url( $act( 'delete' ) ); ?>" title="<?php esc_attr_e( 'Delete', 'osam-bookings' ); ?>"><span class="dashicons dashicons-trash"></span></a>
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
	 * Switch a standard email on or off.
	 */
	public static function std_toggle() {
		$key = isset( $_GET['key'] ) ? sanitize_key( $_GET['key'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_std_email_' . $key, 'manage_options' );
		$emails = OSB_Settings::get( 'emails', array() );
		if ( isset( $emails[ $key ] ) ) {
			$emails[ $key ]['enabled'] = empty( $_GET['on'] ) ? 0 : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			OSB_Settings::update( array( 'emails' => $emails ) );
		}
		OSB_Admin::redirect( self::url(), 'saved' );
	}

	/**
	 * Pick a starting point.
	 */
	private static function chooser() {
		OSB_Admin::open(
			__( 'Add a custom email', 'osam-bookings' ),
			array(
				'subtitle'   => __( 'Pick a starting point. Everything can be changed on the next screen.', 'osam-bookings' ),
				'back'       => self::url(),
				'back_label' => __( 'Emails', 'osam-bookings' ),
			)
		);
		echo '<div class="osb-ce-presets">';
		foreach ( OSB_Custom_Emails::presets() as $key => $p ) {
			printf(
				'<a class="osb-card osb-ce-preset" href="%s"><span class="dashicons %s"></span><strong>%s</strong><small>%s</small></a>',
				esc_url( self::url( array( 'action' => 'new', 'preset' => $key ) ) ),
				'blank' === $key ? 'dashicons-plus-alt2' : 'dashicons-email-alt',
				esc_html( $p[0] ),
				esc_html( $p[1] )
			);
		}
		echo '</div>';
		OSB_Admin::close();
	}

	/**
	 * Chip checkboxes.
	 *
	 * @param string $name     Field name (without []).
	 * @param array  $options  value => label.
	 * @param array  $selected Selected values.
	 * @param string $all_key  Value that means "all of this group" (styled differently).
	 */
	public static function chips( $name, array $options, array $selected, $all_key = '' ) {
		echo '<div class="osb-chips-check">';
		foreach ( $options as $v => $label ) {
			printf(
				'<label class="%s"><input type="checkbox" name="%s[]" value="%s"%s><span>%s</span></label>',
				( (string) $v === $all_key || 0 === strpos( (string) $v, '*' ) ) ? 'is-all' : '',
				esc_attr( $name ),
				esc_attr( $v ),
				checked( in_array( (string) $v, array_map( 'strval', $selected ), true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</div>';
	}

	/**
	 * Switch.
	 *
	 * @param string $name  Name.
	 * @param string $label Label.
	 * @param mixed  $on    Value.
	 * @param string $help  Help.
	 */
	private static function toggle( $name, $label, $on, $help = '' ) {
		printf(
			'<div class="osb-field"><label class="osb-switch"><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s><span></span>%3$s</label>%4$s</div>',
			esc_attr( $name ),
			checked( ! empty( $on ), true, false ),
			esc_html( $label ),
			$help ? '<p class="osb-help">' . esc_html( $help ) . '</p>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Editor.
	 *
	 * @param array $def Definition.
	 */
	private static function editor( array $def ) {
		$is_new = '' === $def['id'];
		$solo   = OSB_DB::is_solo();
		OSB_Admin::open(
			$is_new ? __( 'New custom email', 'osam-bookings' ) : ( $def['name'] ? $def['name'] : __( 'Custom email', 'osam-bookings' ) ),
			array(
				'back'       => self::url(),
				'back_label' => __( 'Emails', 'osam-bookings' ),
			)
		);

		// Which-bookings options.
		$groups = OSB_Items::groups( $def['items'] );
		if ( OSB_Bundles::enabled() ) {
			$groups[ __( 'Bundles', 'osam-bookings' ) ] = array( 'bundles' => __( 'Bundle purchases', 'osam-bookings' ) );
		}
		$staff = array();
		foreach ( OSB_DB::staff() as $st ) {
			$staff[ $st->id ] = $st->name;
		}
		$methods = array();
		foreach ( osb()->payments->all() as $gid => $g ) {
			$methods[ $gid ] = $g->title();
		}
		$methods['free'] = __( 'Free (no payment)', 'osam-bookings' );
		$reply_other     = ! in_array( $def['reply_to'], array( 'default', 'customer', 'staff' ), true );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="osb-form osb-ce-form">
			<?php wp_nonce_field( 'osb_save_custom_email' ); ?>
			<input type="hidden" name="action" value="osb_save_custom_email">
			<input type="hidden" name="id" value="<?php echo esc_attr( $def['id'] ); ?>">

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Email', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'The name is only for you, so you can tell your emails apart.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-field"><label for="osb_ce_name"><?php esc_html_e( 'Name', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="text" id="osb_ce_name" name="name" value="<?php echo esc_attr( $def['name'] ); ?>" required placeholder="<?php esc_attr_e( 'e.g. Massage aftercare', 'osam-bookings' ); ?>"></div>
					<?php self::toggle( 'enabled', __( 'Send this email', 'osam-bookings' ), $def['enabled'], __( 'Switch off to pause it without losing the settings.', 'osam-bookings' ) ); ?>
					<div class="osb-field"><label for="osb_ce_note"><?php esc_html_e( 'Notes for your team', 'osam-bookings' ); ?></label><textarea id="osb_ce_note" name="note" rows="2" placeholder="<?php esc_attr_e( 'Optional. Not sent to anyone.', 'osam-bookings' ); ?>"><?php echo esc_textarea( $def['note'] ); ?></textarea></div>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'When it’s sent', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Straight after something happens, at a set time before or after the booking, instead of one of the standard emails, or only when you send it yourself.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-field"><label for="osb_ce_trigger"><?php esc_html_e( 'Send it when', 'osam-bookings' ); ?></label>
						<select id="osb_ce_trigger" name="trigger" class="osb-ce-trigger">
							<?php foreach ( OSB_Custom_Emails::triggers() as $group => $opts ) : ?>
								<optgroup label="<?php echo esc_attr( $group ); ?>">
									<?php foreach ( $opts as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $def['trigger'], $k ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="osb-ce-timed" data-osb-show="timed">
						<div class="osb-fieldgrid">
							<div class="osb-field osb-field--quarter"><label for="osb_ce_offset"><?php esc_html_e( 'How long', 'osam-bookings' ); ?></label><input type="number" id="osb_ce_offset" name="offset" min="0" max="9999" value="<?php echo (int) $def['offset']; ?>"></div>
							<div class="osb-field osb-field--quarter"><label for="osb_ce_unit">&nbsp;</label><?php OSB_Admin::input( 'unit', $def['unit'], 'select', array( 'minutes' => __( 'minutes', 'osam-bookings' ), 'hours' => __( 'hours', 'osam-bookings' ), 'days' => __( 'days', 'osam-bookings' ), 'weeks' => __( 'weeks', 'osam-bookings' ) ), 'osb_ce_unit' ); ?></div>
							<div class="osb-field osb-field--half"><label>&nbsp;</label><p class="osb-ce-when-text osb-muted"></p></div>
						</div>
						<div data-osb-show="before_start"><?php self::toggle( 'late', __( 'Also send to bookings made after that point', 'osam-bookings' ), $def['late'], __( 'Off: someone who books 1 day ahead won’t get a “2 days before” email. On: they get it straight away.', 'osam-bookings' ) ); ?></div>
						<p class="osb-help"><?php esc_html_e( 'Checked every 5 minutes. It only goes to bookings from when you switch it on, never to old ones, and each booking gets it once.', 'osam-bookings' ); ?></p>
					</div>
					<p class="osb-help" data-osb-show="replace"><?php esc_html_e( 'For bookings that match the rules below, this email is sent instead of the standard one. Everyone else still gets the standard email. Leave “Who gets it” empty to send it to the same people as the standard email.', 'osam-bookings' ); ?></p>
					<p class="osb-help" data-osb-show="manual"><?php esc_html_e( 'Never sent automatically. Open a booking and choose it under “Send an email”.', 'osam-bookings' ); ?></p>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Who gets it', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Each person gets their own copy, so attendees never see each other’s addresses.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><?php self::toggle( 'to_customer', __( 'The customer', 'osam-bookings' ), $def['to_customer'] ); ?></div>
						<div class="osb-field osb-field--half"><?php self::toggle( 'to_attendees', __( 'Everyone else on the booking', 'osam-bookings' ), $def['to_attendees'], __( 'Places 2, 3… that have their own email address.', 'osam-bookings' ) ); ?></div>
						<div class="osb-field osb-field--half"><?php self::toggle( 'to_staff', $solo ? __( 'Me (the staff email)', 'osam-bookings' ) : __( 'The staff member, host or tutor', 'osam-bookings' ), $def['to_staff'] ); ?></div>
						<div class="osb-field osb-field--half"><?php self::toggle( 'to_admin', __( 'Admin addresses', 'osam-bookings' ), $def['to_admin'], __( 'The “Send admin emails to” addresses in Settings → Emails.', 'osam-bookings' ) ); ?></div>
					</div>
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_ce_extra"><?php esc_html_e( 'Also send to', 'osam-bookings' ); ?></label><input type="text" id="osb_ce_extra" name="to_extra" value="<?php echo esc_attr( $def['to_extra'] ); ?>" placeholder="name@example.com, …"></div>
						<div class="osb-field osb-field--half"><label for="osb_ce_bcc"><?php esc_html_e( 'BCC a copy to', 'osam-bookings' ); ?></label><input type="text" id="osb_ce_bcc" name="bcc" value="<?php echo esc_attr( $def['bcc'] ); ?>" placeholder="records@example.com"></div>
					</div>
					<div class="osb-fieldgrid">
						<div class="osb-field osb-field--half"><label for="osb_ce_reply"><?php esc_html_e( 'Replies go to', 'osam-bookings' ); ?></label>
							<select id="osb_ce_reply" name="reply_to" class="osb-ce-reply">
								<option value="default" <?php selected( 'default', $def['reply_to'] ); ?>><?php esc_html_e( 'The sending address (Settings → Emails)', 'osam-bookings' ); ?></option>
								<option value="customer" <?php selected( 'customer', $def['reply_to'] ); ?>><?php esc_html_e( 'The customer', 'osam-bookings' ); ?></option>
								<option value="staff" <?php selected( 'staff', $def['reply_to'] ); ?>><?php esc_html_e( 'The staff member', 'osam-bookings' ); ?></option>
								<option value="other" <?php selected( $reply_other ); ?>><?php esc_html_e( 'Another address…', 'osam-bookings' ); ?></option>
							</select>
							<input type="email" name="reply_to_email" class="osb-ce-reply-email" value="<?php echo esc_attr( $reply_other ? $def['reply_to'] : '' ); ?>" placeholder="hello@example.com" <?php echo $reply_other ? '' : 'hidden'; ?>>
						</div>
						<div class="osb-field osb-field--half"><label for="osb_ce_from"><?php esc_html_e( 'From name', 'osam-bookings' ); ?></label><input type="text" id="osb_ce_from" name="from_name" value="<?php echo esc_attr( $def['from_name'] ); ?>" placeholder="<?php echo esc_attr( OSB_Settings::get( 'email_from_name' ) ); ?>"><p class="osb-help"><?php esc_html_e( 'Leave blank to use the usual name.', 'osam-bookings' ); ?></p></div>
					</div>
				</div>
			</div>

			<div class="osb-section">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Which bookings', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Leave a group empty to include everything. A booking has to match every rule you set.', 'osam-bookings' ); ?></p></div>
				<div class="osb-card">
					<div class="osb-field"><label><?php esc_html_e( 'Services, events, courses, resources and tables', 'osam-bookings' ); ?></label>
						<p class="osb-help"><?php esc_html_e( 'Nothing ticked = every booking. Tick a whole group, a category or single items.', 'osam-bookings' ); ?></p>
						<?php foreach ( $groups as $g => $opts ) : ?>
							<div class="osb-showfor__group"><span><?php echo esc_html( $g ); ?></span><?php self::chips( 'items', $opts, $def['items'], (string) array_key_first( $opts ) ); ?></div>
						<?php endforeach; ?>
					</div>
					<?php if ( ! $solo && $staff ) : ?>
						<div class="osb-field"><label><?php esc_html_e( 'With these staff members', 'osam-bookings' ); ?></label><?php self::chips( 'staff', $staff, $def['staff'] ); ?></div>
					<?php endif; ?>
					<div class="osb-field" data-osb-show="timed manual"><label><?php esc_html_e( 'Booking status', 'osam-bookings' ); ?></label><?php self::chips( 'statuses', OSB_Bookings::statuses(), $def['statuses'] ); ?><p class="osb-help"><?php esc_html_e( 'Only bookings with one of these statuses when the time comes. Nothing ticked = any status.', 'osam-bookings' ); ?></p></div>
					<details class="osb-ce-more" <?php echo ( $def['methods'] || 'any' !== $def['pay'] || 'any' !== $def['customer'] || $def['min_seats'] || 'any' !== $def['coupon'] ) ? 'open' : ''; ?>>
						<summary><?php esc_html_e( 'More conditions', 'osam-bookings' ); ?></summary>
						<div class="osb-field"><label><?php esc_html_e( 'Payment method', 'osam-bookings' ); ?></label><?php self::chips( 'methods', $methods, $def['methods'] ); ?></div>
						<div class="osb-fieldgrid">
							<div class="osb-field osb-field--half"><label for="osb_ce_pay"><?php esc_html_e( 'Payment', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'pay', $def['pay'], 'select', array( 'any' => __( 'Paid or unpaid', 'osam-bookings' ), 'paid' => __( 'Only paid bookings', 'osam-bookings' ), 'unpaid' => __( 'Only unpaid bookings', 'osam-bookings' ) ), 'osb_ce_pay' ); ?></div>
							<div class="osb-field osb-field--half"><label for="osb_ce_customer"><?php esc_html_e( 'Customer', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'customer', $def['customer'], 'select', array( 'any' => __( 'Anyone', 'osam-bookings' ), 'new' => __( 'First booking only', 'osam-bookings' ), 'returning' => __( 'Returning customers only', 'osam-bookings' ) ), 'osb_ce_customer' ); ?></div>
							<div class="osb-field osb-field--half"><label for="osb_ce_seats"><?php esc_html_e( 'At least this many places', 'osam-bookings' ); ?></label><input type="number" id="osb_ce_seats" name="min_seats" min="0" value="<?php echo (int) $def['min_seats']; ?>"><p class="osb-help"><?php esc_html_e( '0 = any. E.g. 4 for a group-booking email.', 'osam-bookings' ); ?></p></div>
							<?php if ( OSB_Settings::feature( 'coupons' ) ) : ?>
								<div class="osb-field osb-field--half"><label for="osb_ce_coupon"><?php esc_html_e( 'Discount code or voucher', 'osam-bookings' ); ?></label><?php OSB_Admin::input( 'coupon', $def['coupon'], 'select', array( 'any' => __( 'With or without', 'osam-bookings' ), 'used' => __( 'Only when one was used', 'osam-bookings' ), 'none' => __( 'Only when none was used', 'osam-bookings' ) ), 'osb_ce_coupon' ); ?></div>
							<?php endif; ?>
						</div>
					</details>
				</div>
			</div>

			<div class="osb-section osb-section--wide">
				<div class="osb-section__intro"><h2><?php esc_html_e( 'Message', 'osam-bookings' ); ?></h2><p><?php esc_html_e( 'Uses the same design as your other emails. Click a placeholder to copy it.', 'osam-bookings' ); ?></p></div>
				<div class="osb-ce-message">
					<div class="osb-card">
						<div class="osb-field"><label for="osb_ce_subject"><?php esc_html_e( 'Subject', 'osam-bookings' ); ?> <span class="osb-req">*</span></label><input type="text" id="osb_ce_subject" name="subject" value="<?php echo esc_attr( $def['subject'] ); ?>" required></div>
						<div class="osb-field"><label for="osb_ce_body"><?php esc_html_e( 'Message (HTML)', 'osam-bookings' ); ?></label><textarea id="osb_ce_body" name="body" rows="14" class="large-text code"><?php echo esc_textarea( $def['body'] ); ?></textarea></div>
						<?php self::toggle( 'ics', __( 'Attach a calendar file (.ics)', 'osam-bookings' ), $def['ics'] ); ?>
						<div class="osb-ce-tags">
							<p class="osb-tags__label"><?php esc_html_e( 'Blocks', 'osam-bookings' ); ?></p>
							<div class="osb-tags">
								<?php foreach ( array( '{details_card}', '{manage_button}', '{meeting_button}', '{review_button}', '{admin_button}', '{status_badge}', '{schedule}', '{fields_table}', '{invoice_button}' ) as $tag ) : ?><button type="button" class="osb-tag osb-tag--block osb-copy" data-copy="<?php echo esc_attr( $tag ); ?>"><?php echo esc_html( $tag ); ?></button><?php endforeach; ?>
							</div>
							<p class="osb-tags__label"><?php esc_html_e( 'Values', 'osam-bookings' ); ?></p>
							<div class="osb-tags">
								<?php foreach ( array( '{recipient_first_name}', '{recipient_name}', '{customer_first_name}', '{customer_name}', '{customer_email}', '{customer_phone}', '{item_name}', '{staff_name}', '{location}', '{date}', '{time}', '{end_time}', '{duration}', '{seats}', '{attendees}', '{amount}', '{discount}', '{payment_method}', '{payment_status}', '{status}', '{booking_id}', '{manage_url}', '{booking_url}', '{meeting_link}', '{review_url}', '{intake_name}', '{session_count}', '{covers}', '{tables}', '{site_name}', '{site_url}', '{field:your_field_key}' ) as $tag ) : ?><button type="button" class="osb-tag osb-copy" data-copy="<?php echo esc_attr( $tag ); ?>"><?php echo esc_html( $tag ); ?></button><?php endforeach; ?>
							</div>
						</div>
					</div>
					<div class="osb-preview osb-ce-preview">
						<div class="osb-preview__bar">
							<span class="osb-preview__dots"><i></i><i></i><i></i></span>
							<span class="osb-ce-preview__sample osb-muted"></span>
							<span class="osb-preview__sizes"><button type="button" data-w="100%" class="is-active" aria-label="<?php esc_attr_e( 'Desktop', 'osam-bookings' ); ?>"><span class="dashicons dashicons-desktop"></span></button><button type="button" data-w="380px" aria-label="<?php esc_attr_e( 'Mobile', 'osam-bookings' ); ?>"><span class="dashicons dashicons-smartphone"></span></button></span>
						</div>
						<div class="osb-emailpreview__subject"><span><?php esc_html_e( 'Subject', 'osam-bookings' ); ?></span><strong></strong></div>
						<div class="osb-preview__stage"><iframe class="osb-ce-preview__frame" title="<?php esc_attr_e( 'Email preview', 'osam-bookings' ); ?>" sandbox="allow-same-origin"></iframe></div>
						<div class="osb-ce-test">
							<input type="email" class="osb-ce-test__to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" aria-label="<?php esc_attr_e( 'Send a test to', 'osam-bookings' ); ?>">
							<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm" data-osb-ce-test><span class="dashicons dashicons-email-alt"></span><?php esc_html_e( 'Send me a test', 'osam-bookings' ); ?></button>
							<span class="osb-ce-test__msg" aria-live="polite"></span>
						</div>
						<p class="osb-help"><?php esc_html_e( 'The preview and test use the latest booking these rules match. Tests go only to the address above, and nothing is saved.', 'osam-bookings' ); ?></p>
					</div>
				</div>
			</div>

			<div class="osb-savebar"><span class="osb-savebar__dirty" hidden><?php esc_html_e( 'Unsaved changes', 'osam-bookings' ); ?></span><button class="osb-btn osb-btn--primary"><?php echo $is_new ? esc_html__( 'Add email', 'osam-bookings' ) : esc_html__( 'Save email', 'osam-bookings' ); ?></button></div>
		</form>
		<?php
		OSB_Admin::close();
	}

	/**
	 * Save.
	 */
	public static function save() {
		OSB_Admin::verify( 'osb_save_custom_email', 'manage_options' );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$id   = isset( $post['id'] ) ? sanitize_key( $post['id'] ) : '';
		$def  = OSB_Custom_Emails::sanitize( (array) $post );
		$warn = array();
		if ( '' === $def['name'] ) {
			$def['name'] = $def['subject'] ? $def['subject'] : __( 'Custom email', 'osam-bookings' );
		}
		if ( ! $def['to_customer'] && ! $def['to_attendees'] && ! $def['to_staff'] && ! $def['to_admin'] && '' === $def['to_extra'] && 0 !== strpos( $def['trigger'], 'replace:' ) ) {
			$warn[] = __( 'Nobody is ticked under “Who gets it”, so this email won’t go to anyone.', 'osam-bookings' );
		}
		if ( '' === trim( wp_strip_all_tags( $def['body'] ) ) && false === strpos( $def['body'], '{' ) ) {
			$warn[] = __( 'The message is empty.', 'osam-bookings' );
		}
		$new_id = OSB_Custom_Emails::save( $def, $id );
		if ( $warn ) {
			set_transient( 'osb_admin_warn_' . get_current_user_id(), $warn, 60 );
		}
		OSB_Admin::redirect( self::url( array( 'action' => 'edit', 'id' => $new_id ) ), 'saved' );
	}

	/**
	 * On / off / duplicate / delete.
	 */
	public static function action() {
		$id = isset( $_GET['id'] ) ? sanitize_key( $_GET['id'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		OSB_Admin::verify( 'osb_custom_email_' . $id, 'manage_options' );
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		switch ( $do ) {
			case 'on':
			case 'off':
				OSB_Custom_Emails::toggle( $id, 'on' === $do );
				OSB_Admin::redirect( self::url(), 'saved' );
				break;
			case 'duplicate':
				$new = OSB_Custom_Emails::duplicate( $id );
				OSB_Admin::redirect( $new ? self::url( array( 'action' => 'edit', 'id' => $new ) ) : self::url(), $new ? 'ce_copied' : 'error' );
				break;
			case 'delete':
				OSB_Custom_Emails::delete( $id );
				OSB_Admin::redirect( self::url(), 'deleted' );
				break;
		}
		OSB_Admin::redirect( self::url(), 'error' );
	}
}
