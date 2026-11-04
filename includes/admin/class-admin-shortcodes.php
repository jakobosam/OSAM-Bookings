<?php
/**
 * Shortcodes reference and builder.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists every shortcode for this site and builds custom ones with a live preview.
 */
class OSB_Admin_Shortcodes {

	/**
	 * Attribute reference.
	 *
	 * @return array [ attr, values, description ]
	 */
	public static function attributes() {
		$choices = OSB_Settings::display_choices();
		$kinds   = array_merge( array( 'all', 'services' ), OSB_Settings::feature( 'events' ) ? array( 'events' ) : array(), OSB_Settings::feature( 'courses' ) ? array( 'courses' ) : array() );
		$rows    = array(
			array( 'type', implode( ' · ', $kinds ), __( 'What to show. “all” shows tabs when you offer more than one kind.', 'osam-bookings' ) ),
			array( 'service', '3  ·  3,5,8', __( 'Limit to these service IDs. One ID skips the “choose a service” step.', 'osam-bookings' ) ),
			array( 'category', __( 'Category name', 'osam-bookings' ), __( 'Only services in this category.', 'osam-bookings' ) ),
		);
		if ( OSB_Settings::feature( 'events' ) ) {
			$rows[] = array( 'event', '7  ·  7,9', __( 'Limit to these event IDs.', 'osam-bookings' ) );
		}
		if ( OSB_Settings::feature( 'courses' ) ) {
			$rows[] = array( 'course', '4  ·  4,6', __( 'Limit to these course IDs. One course with one open intake goes straight to the details step.', 'osam-bookings' ) );
		}
		if ( ! OSB_DB::is_solo() ) {
			$rows[] = array( 'staff', '2', __( 'Book with this staff member only (skips the staff step).', 'osam-bookings' ) );
		}
		$rows[] = array( 'design', implode( ' · ', array_keys( $choices['design'] ) ), __( 'Overall look.', 'osam-bookings' ) );
		$rows[] = array( 'layout', implode( ' · ', array_keys( $choices['service_layout'] ) ), __( 'How services are listed.', 'osam-bookings' ) );
		$rows[] = array( 'columns', implode( ' · ', array_keys( $choices['service_columns'] ) ), __( 'Columns when layout is grid.', 'osam-bookings' ) );
		$rows[] = array( 'buttons', implode( ' · ', array_keys( $choices['button_shape'] ) ), __( 'Button and time-slot shape.', 'osam-bookings' ) );
		$rows[] = array( 'density', implode( ' · ', array_keys( $choices['density'] ) ), __( 'Spacing.', 'osam-bookings' ) );
		$rows[] = array( 'accent', '#e64980', __( 'Accent colour for this form only.', 'osam-bookings' ) );
		foreach ( OSB_Settings::display_toggles() as $def ) {
			$rows[] = array( $def[0], 'yes · no', $def[1] );
		}
		$rows[] = array( 'class', 'my-class', __( 'Extra CSS class on the form.', 'osam-bookings' ) );
		return $rows;
	}

	/**
	 * A copyable code line.
	 *
	 * @param string $code  Shortcode.
	 * @param string $label Label.
	 * @param string $meta  Secondary text.
	 */
	private static function code_row( $code, $label, $meta = '' ) {
		?>
		<li class="osb-coderow">
			<span class="osb-coderow__label"><strong><?php echo esc_html( $label ); ?></strong><?php echo $meta ? '<small>' . esc_html( $meta ) . '</small>' : ''; ?></span>
			<code><?php echo esc_html( $code ); ?></code>
			<button type="button" class="osb-btn osb-btn--ghost osb-btn--sm osb-copy" data-copy="<?php echo esc_attr( $code ); ?>"><span class="dashicons dashicons-admin-page"></span><?php esc_html_e( 'Copy', 'osam-bookings' ); ?></button>
		</li>
		<?php
	}

	/**
	 * Page.
	 */
	public static function page() {
		$services = OSB_DB::services( true );
		$events   = OSB_Settings::feature( 'events' ) ? OSB_DB::upcoming_events( 50 ) : array();
		$courses  = OSB_Settings::feature( 'courses' ) ? OSB_Courses::courses( true ) : array();
		$staff    = OSB_DB::is_solo() ? array() : OSB_DB::staff( true );
		$cats     = array_values( array_unique( array_filter( wp_list_pluck( $services, 'category' ) ) ) );
		$choices  = OSB_Settings::display_choices();
		$s        = OSB_Settings::all();
		$defaults = array(
			'design'  => $s['design'],
			'layout'  => $s['service_layout'],
			'columns' => (string) $s['service_columns'],
			'buttons' => $s['button_shape'],
			'density' => $s['density'],
		);
		foreach ( OSB_Settings::display_toggles() as $key => $def ) {
			$defaults[ $def[0] ] = $s[ $key ] ? 'yes' : 'no';
		}

		OSB_Admin::open(
			__( 'Shortcodes', 'osam-bookings' ),
			array( 'subtitle' => __( 'Paste these into any page, post, widget or Elementor “Shortcode” widget. In Elementor you can also use the “OSAM Booking Form” widget.', 'osam-bookings' ) )
		);
		?>
		<div class="osb-card osb-hero-code">
			<div>
				<h2><?php esc_html_e( 'The booking form', 'osam-bookings' ); ?></h2>
				<p><?php esc_html_e( 'Everything you offer, with tabs when there’s more than one kind (appointments, events, courses). Uses your design settings.', 'osam-bookings' ); ?></p>
			</div>
			<div class="osb-hero-code__code"><code>[osam_booking]</code><button type="button" class="osb-btn osb-btn--primary osb-copy" data-copy="[osam_booking]"><span class="dashicons dashicons-admin-page"></span><?php esc_html_e( 'Copy', 'osam-bookings' ); ?></button></div>
		</div>

		<div class="osb-card osb-builder" data-defaults="<?php echo esc_attr( wp_json_encode( $defaults ) ); ?>">
			<?php OSB_Admin::card_head( __( 'Build a custom shortcode', 'osam-bookings' ), __( 'Pick options — the shortcode and preview update as you go. Leave anything on “Default” to follow your settings.', 'osam-bookings' ) ); ?>
			<div class="osb-builder__grid">
				<div class="osb-builder__controls osb-form">
					<div class="osb-field"><label><?php esc_html_e( 'Show', 'osam-bookings' ); ?></label>
						<div class="osb-choice osb-choice--3">
							<label><input type="radio" name="b_type" value="all" checked><span><?php esc_html_e( 'Everything', 'osam-bookings' ); ?></span></label>
							<label><input type="radio" name="b_type" value="services"><span><?php esc_html_e( 'Services', 'osam-bookings' ); ?></span></label>
							<?php if ( OSB_Settings::feature( 'events' ) ) : ?><label><input type="radio" name="b_type" value="events"><span><?php esc_html_e( 'Events', 'osam-bookings' ); ?></span></label><?php endif; ?>
							<?php if ( OSB_Settings::feature( 'courses' ) ) : ?><label><input type="radio" name="b_type" value="courses"><span><?php esc_html_e( 'Courses', 'osam-bookings' ); ?></span></label><?php endif; ?>
						</div>
					</div>
					<?php if ( $services ) : ?>
					<div class="osb-field osb-b-services"><label><?php esc_html_e( 'Only these services', 'osam-bookings' ); ?></label>
						<div class="osb-chips-check">
							<?php foreach ( $services as $sv ) : ?><label><input type="checkbox" name="b_service" value="<?php echo (int) $sv->id; ?>"><span><i style="background:<?php echo esc_attr( $sv->color ); ?>"></i><?php echo esc_html( $sv->name ); ?></span></label><?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>
					<?php if ( $cats ) : ?>
					<div class="osb-field osb-b-services"><label for="b_category"><?php esc_html_e( 'Or one category', 'osam-bookings' ); ?></label>
						<select id="b_category" name="b_category"><option value=""><?php esc_html_e( 'Any', 'osam-bookings' ); ?></option><?php foreach ( $cats as $c ) : ?><option><?php echo esc_html( $c ); ?></option><?php endforeach; ?></select>
					</div>
					<?php endif; ?>
					<?php if ( $staff ) : ?>
					<div class="osb-field osb-b-services"><label for="b_staff"><?php esc_html_e( 'With', 'osam-bookings' ); ?></label>
						<select id="b_staff" name="b_staff"><option value=""><?php esc_html_e( 'Customer chooses', 'osam-bookings' ); ?></option><?php foreach ( $staff as $st ) : ?><option value="<?php echo (int) $st->id; ?>"><?php echo esc_html( $st->name ); ?></option><?php endforeach; ?></select>
					</div>
					<?php endif; ?>
					<?php if ( $events ) : ?>
					<div class="osb-field osb-b-events"><label><?php esc_html_e( 'Only these events', 'osam-bookings' ); ?></label>
						<div class="osb-chips-check">
							<?php foreach ( $events as $ev ) : ?><label><input type="checkbox" name="b_event" value="<?php echo (int) $ev->id; ?>"><span><i style="background:<?php echo esc_attr( $ev->color ); ?>"></i><?php echo esc_html( $ev->name . ' · ' . mysql2date( 'j M', $ev->start_at ) ); ?></span></label><?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>
					<?php if ( $courses ) : ?>
					<div class="osb-field osb-b-courses"><label><?php esc_html_e( 'Only these courses', 'osam-bookings' ); ?></label>
						<div class="osb-chips-check">
							<?php foreach ( $courses as $co ) : ?><label><input type="checkbox" name="b_course" value="<?php echo (int) $co->id; ?>"><span><i style="background:<?php echo esc_attr( $co->color ); ?>"></i><?php echo esc_html( $co->name ); ?></span></label><?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<div class="osb-fieldgrid">
						<?php
						$selects = array(
							'design'  => array( __( 'Design', 'osam-bookings' ), $choices['design'] ),
							'layout'  => array( __( 'Service layout', 'osam-bookings' ), $choices['service_layout'] ),
							'columns' => array( __( 'Grid columns', 'osam-bookings' ), $choices['service_columns'] ),
							'buttons' => array( __( 'Buttons', 'osam-bookings' ), $choices['button_shape'] ),
							'density' => array( __( 'Spacing', 'osam-bookings' ), $choices['density'] ),
						);
						foreach ( $selects as $attr => $def ) :
							?>
							<div class="osb-field osb-field--half"><label for="b_<?php echo esc_attr( $attr ); ?>"><?php echo esc_html( $def[0] ); ?></label>
								<select id="b_<?php echo esc_attr( $attr ); ?>" name="b_<?php echo esc_attr( $attr ); ?>" data-attr="<?php echo esc_attr( $attr ); ?>"><option value=""><?php esc_html_e( 'Default', 'osam-bookings' ); ?></option><?php foreach ( $def[1] as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( preg_replace( '/\s—.*$/', '', $l ) ); ?></option><?php endforeach; ?></select>
							</div>
						<?php endforeach; ?>
						<div class="osb-field osb-field--half"><label for="b_accent"><?php esc_html_e( 'Accent colour', 'osam-bookings' ); ?></label><div class="osb-inline"><input type="color" id="b_accent" value="<?php echo esc_attr( $s['primary_color'] ); ?>"><label class="osb-switch"><input type="checkbox" id="b_accent_on"><span></span><?php esc_html_e( 'Override', 'osam-bookings' ); ?></label></div></div>
					</div>

					<div class="osb-field"><label><?php esc_html_e( 'Show / hide', 'osam-bookings' ); ?></label>
						<div class="osb-toggles">
							<?php foreach ( OSB_Settings::display_toggles() as $def ) : ?>
								<div class="osb-toggle3"><span><?php echo esc_html( $def[1] ); ?></span>
									<select data-attr="<?php echo esc_attr( $def[0] ); ?>"><option value=""><?php esc_html_e( 'Default', 'osam-bookings' ); ?></option><option value="yes"><?php esc_html_e( 'Show', 'osam-bookings' ); ?></option><option value="no"><?php esc_html_e( 'Hide', 'osam-bookings' ); ?></option></select>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div class="osb-builder__out">
					<div class="osb-builder__code">
						<code id="osb-built">[osam_booking]</code>
						<button type="button" class="osb-btn osb-btn--primary osb-copy" data-copy-from="#osb-built"><span class="dashicons dashicons-admin-page"></span><?php esc_html_e( 'Copy', 'osam-bookings' ); ?></button>
					</div>
					<div class="osb-preview">
						<div class="osb-preview__bar"><span class="osb-preview__dots"><i></i><i></i><i></i></span><span><?php esc_html_e( 'Preview', 'osam-bookings' ); ?></span></div>
						<div class="osb-preview__stage"><div class="osb-preview__frame" id="osb-builder-preview" data-base-config="<?php echo esc_attr( wp_json_encode( array( 'returnUrl' => home_url( '/' ), 'preview' => true, 'result' => null ) ) ); ?>"></div></div>
					</div>
				</div>
			</div>
		</div>

		<div class="osb-grid-2">
			<div class="osb-card">
				<?php OSB_Admin::card_head( __( 'Ready-made', 'osam-bookings' ) ); ?>
				<ul class="osb-codelist">
					<?php
					self::code_row( '[osam_booking type="services"]', __( 'Appointments only', 'osam-bookings' ) );
					if ( OSB_Settings::feature( 'events' ) ) {
						self::code_row( '[osam_booking type="events"]', __( 'Events only', 'osam-bookings' ) );
					}
					if ( OSB_Settings::feature( 'courses' ) ) {
						self::code_row( '[osam_courses]', __( 'Courses only', 'osam-bookings' ), __( 'Same as [osam_booking type="courses"]', 'osam-bookings' ) );
					}
					if ( OSB_Settings::feature( 'resources' ) ) {
						self::code_row( '[osam_resources]', __( 'Resources', 'osam-bookings' ), __( 'Same as [osam_booking type="resources"]. Add id="3,4" to show only some.', 'osam-bookings' ) );
					}
					if ( OSB_Settings::feature( 'restaurant' ) ) {
						self::code_row( '[osam_table_booking]', __( 'Restaurant table booking', 'osam-bookings' ), __( 'Same as [osam_booking type="tables"]. Add covers="4" to start at 4 guests.', 'osam-bookings' ) );
					}
					if ( OSB_Settings::feature( 'bundles' ) ) {
						self::code_row( '[osam_bundles]', __( 'Bundles for sale', 'osam-bookings' ), __( 'Same as [osam_booking type="bundles"]. Add id="2" to sell just one.', 'osam-bookings' ) );
					}
					self::code_row( '[osam_my_bookings]', __( 'My bookings (customer sign-in)', 'osam-bookings' ), __( 'Customers sign in with an emailed link to see, cancel and change their bookings and bundles.', 'osam-bookings' ) );
					self::code_row( '[osam_booking layout="grid" columns="3"]', __( 'Services as a 3-column grid', 'osam-bookings' ) );
					self::code_row( '[osam_booking design="minimal" progress="no"]', __( 'Minimal, no step bar', 'osam-bookings' ), __( 'Blends into page builders', 'osam-bookings' ) );
					self::code_row( '[osam_booking density="compact" summary="no"]', __( 'Compact, for sidebars', 'osam-bookings' ) );
					?>
				</ul>
			</div>
			<div class="osb-card">
				<?php OSB_Admin::card_head( __( 'Per service', 'osam-bookings' ), __( 'Jumps straight to picking a date — ideal on each service’s own page.', 'osam-bookings' ) ); ?>
				<?php if ( ! $services ) : ?>
					<p class="osb-muted"><?php esc_html_e( 'No services yet.', 'osam-bookings' ); ?></p>
				<?php else : ?>
				<ul class="osb-codelist">
					<?php
					foreach ( $services as $sv ) {
						self::code_row( '[osam_booking service="' . (int) $sv->id . '"]', $sv->name, OSB_Admin_Services::duration_label( (int) $sv->duration ) . ' · ' . ( $sv->price > 0 ? OSB_Settings::money( $sv->price ) : __( 'Free', 'osam-bookings' ) ) );
					}
					foreach ( $cats as $c ) {
						/* translators: %s category */
						self::code_row( '[osam_booking category="' . $c . '"]', sprintf( __( 'Category: %s', 'osam-bookings' ), $c ) );
					}
					?>
				</ul>
				<?php endif; ?>
			</div>
			<?php if ( $events ) : ?>
			<div class="osb-card">
				<?php OSB_Admin::card_head( __( 'Per event', 'osam-bookings' ) ); ?>
				<ul class="osb-codelist">
					<?php
					foreach ( $events as $ev ) {
						self::code_row( '[osam_booking event="' . (int) $ev->id . '"]', $ev->name, mysql2date( 'D j M Y, ' . get_option( 'time_format' ), $ev->start_at ) );
					}
					?>
				</ul>
			</div>
			<?php endif; ?>
			<?php if ( $courses ) : ?>
			<div class="osb-card">
				<?php OSB_Admin::card_head( __( 'Per course', 'osam-bookings' ), __( 'Lists that course’s open intakes. [osam_course id="…"] does the same.', 'osam-bookings' ) ); ?>
				<ul class="osb-codelist">
					<?php
					foreach ( $courses as $co ) {
						self::code_row( '[osam_booking course="' . (int) $co->id . '"]', $co->name, $co->price > 0 ? OSB_Settings::money( $co->price ) : __( 'Free', 'osam-bookings' ) );
					}
					?>
				</ul>
			</div>
			<?php endif; ?>
			<?php if ( $staff ) : ?>
			<div class="osb-card">
				<?php OSB_Admin::card_head( __( 'Per staff member', 'osam-bookings' ), __( 'For individual profile pages.', 'osam-bookings' ) ); ?>
				<ul class="osb-codelist">
					<?php
					foreach ( $staff as $st ) {
						self::code_row( '[osam_booking staff="' . (int) $st->id . '"]', $st->name );
					}
					?>
				</ul>
			</div>
			<?php endif; ?>
		</div>

		<details class="osb-card osb-card--table osb-advanced osb-attrref">
			<summary><strong><?php esc_html_e( 'All attributes', 'osam-bookings' ); ?></strong><span class="osb-muted"> — <?php esc_html_e( 'for writing your own. Combine any of these; anything left out uses your settings.', 'osam-bookings' ); ?></span></summary>
			<table class="osb-table">
				<thead><tr><th><?php esc_html_e( 'Attribute', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'Values', 'osam-bookings' ); ?></th><th><?php esc_html_e( 'What it does', 'osam-bookings' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( self::attributes() as $row ) : ?>
					<tr><td><code><?php echo esc_html( $row[0] ); ?></code></td><td class="osb-muted"><?php echo esc_html( $row[1] ); ?></td><td><?php echo esc_html( $row[2] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
		OSB_Admin::close();
	}
}
