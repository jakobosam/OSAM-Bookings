<?php
/**
 * Elementor "OSAM Booking Form" widget.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

/**
 * Booking form with full style controls.
 */
class OSB_Elementor_Widget extends Widget_Base {

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'osam_booking_form';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'OSAM Booking Form', 'osam-bookings' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-calendar';
	}

	/**
	 * Categories.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Keywords.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'booking', 'appointment', 'calendar', 'event', 'course', 'class', 'reservation', 'osam' );
	}

	/**
	 * Script deps.
	 *
	 * @return array
	 */
	public function get_script_depends() {
		return array( 'osb-booking' );
	}

	/**
	 * Style deps.
	 *
	 * @return array
	 */
	public function get_style_depends() {
		return array( 'osb-booking' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$services = array();
		foreach ( OSB_DB::services() as $s ) {
			$services[ $s->id ] = $s->name;
		}
		$staff = array( '' => __( 'Customer chooses', 'osam-bookings' ) );
		foreach ( OSB_DB::staff() as $st ) {
			$staff[ $st->id ] = $st->name;
		}
		$events = array();
		foreach ( OSB_DB::upcoming_events( 100 ) as $e ) {
			$events[ $e->id ] = $e->name . ' — ' . mysql2date( 'j M Y', $e->start_at );
		}
		$courses = array();
		foreach ( OSB_Courses::courses() as $co ) {
			$courses[ $co->id ] = $co->name;
		}

		/* ---------- Content ---------- */
		$this->start_controls_section( 'section_content', array( 'label' => __( 'Booking form', 'osam-bookings' ) ) );

		$this->add_control(
			'type',
			array(
				'label'   => __( 'Show', 'osam-bookings' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'all',
				'options' => self::kinds( array(
					'all'      => __( 'Everything (tabs)', 'osam-bookings' ),
					'services' => __( 'Services only', 'osam-bookings' ),
					'events'   => __( 'Events only', 'osam-bookings' ),
					'courses'  => __( 'Courses only', 'osam-bookings' ),
					'tables'   => __( 'Restaurant table booking', 'osam-bookings' ),
					'resources' => __( 'Resources (rooms, fields…)', 'osam-bookings' ),
				) ),
			)
		);
		$this->add_control(
			'services',
			array(
				'label'       => __( 'Limit to services', 'osam-bookings' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => $services,
				'description' => __( 'Pick one to skip the service step.', 'osam-bookings' ),
				'condition'   => array( 'type' => array( 'all', 'services' ) ),
			)
		);
		$this->add_control(
			'category',
			array(
				'label'     => __( 'Limit to category', 'osam-bookings' ),
				'type'      => Controls_Manager::TEXT,
				'condition' => array( 'type' => array( 'all', 'services' ) ),
			)
		);
		$this->add_control(
			'staff',
			array(
				'label'     => __( 'Staff', 'osam-bookings' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => $staff,
				'default'   => '',
				'condition' => array( 'type' => array( 'all', 'services' ) ),
			)
		);
		if ( OSB_Settings::feature( 'events' ) ) {
			$this->add_control(
				'events',
				array(
					'label'     => __( 'Limit to events', 'osam-bookings' ),
					'type'      => Controls_Manager::SELECT2,
					'multiple'  => true,
					'options'   => $events,
					'condition' => array( 'type' => array( 'all', 'events' ) ),
				)
			);
		}
		if ( OSB_Settings::feature( 'courses' ) ) {
			$this->add_control(
				'courses',
				array(
					'label'       => __( 'Limit to courses', 'osam-bookings' ),
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => $courses,
					'description' => __( 'Customers see each course’s open intakes.', 'osam-bookings' ),
					'condition'   => array( 'type' => array( 'all', 'courses' ) ),
				)
			);
		}
		$this->end_controls_section();

		/* ---------- Content: display ---------- */
		$this->start_controls_section( 'section_display', array( 'label' => __( 'Display', 'osam-bookings' ) ) );
		$default = array( '' => __( 'Plugin default', 'osam-bookings' ) );
		$map     = array(
			'design'  => array( 'design', __( 'Design', 'osam-bookings' ) ),
			'layout'  => array( 'service_layout', __( 'Service layout', 'osam-bookings' ) ),
			'columns' => array( 'service_columns', __( 'Grid columns', 'osam-bookings' ) ),
			'buttons' => array( 'button_shape', __( 'Button shape', 'osam-bookings' ) ),
			'density' => array( 'density', __( 'Spacing', 'osam-bookings' ) ),
		);
		$choices = OSB_Settings::display_choices();
		foreach ( $map as $attr => $def ) {
			$this->add_control(
				'display_' . $attr,
				array(
					'label'   => $def[1],
					'type'    => Controls_Manager::SELECT,
					'default' => '',
					'options' => $default + $choices[ $def[0] ],
				)
			);
		}
		foreach ( OSB_Settings::display_toggles() as $def ) {
			$this->add_control(
				'show_' . $def[0],
				array(
					'label'   => $def[1],
					'type'    => Controls_Manager::SELECT,
					'default' => '',
					'options' => $default + array(
						'yes' => __( 'Show', 'osam-bookings' ),
						'no'  => __( 'Hide', 'osam-bookings' ),
					),
				)
			);
		}
		$this->end_controls_section();

		/* ---------- Style: colours ---------- */
		$this->start_controls_section(
			'section_colors',
			array(
				'label' => __( 'Colours', 'osam-bookings' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$vars = array(
			'accent'    => array( __( 'Accent', 'osam-bookings' ), '--osb-accent' ),
			'on_accent' => array( __( 'Text on accent', 'osam-bookings' ), '--osb-on-accent' ),
			'text'      => array( __( 'Text', 'osam-bookings' ), '--osb-text' ),
			'muted'     => array( __( 'Secondary text', 'osam-bookings' ), '--osb-muted' ),
			'bg'        => array( __( 'Background', 'osam-bookings' ), '--osb-bg' ),
			'border_c'  => array( __( 'Borders', 'osam-bookings' ), '--osb-border' ),
			'soft'      => array( __( 'Highlight / summary background', 'osam-bookings' ), '--osb-soft' ),
		);
		foreach ( $vars as $key => $def ) {
			$this->add_control(
				'color_' . $key,
				array(
					'label'     => $def[0],
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .osb-booking' => $def[1] . ': {{VALUE}};' ),
				)
			);
		}
		$this->end_controls_section();

		/* ---------- Style: container ---------- */
		$this->start_controls_section(
			'section_box',
			array(
				'label' => __( 'Container', 'osam-bookings' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_responsive_control(
			'box_padding',
			array(
				'label'      => __( 'Padding', 'osam-bookings' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array( '{{WRAPPER}} .osb-booking' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'box_border',
				'selector' => '{{WRAPPER}} .osb-booking',
			)
		);
		$this->add_control(
			'box_radius',
			array(
				'label'      => __( 'Container radius', 'osam-bookings' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 48 ) ),
				'selectors'  => array( '{{WRAPPER}} .osb-booking' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_control(
			'radius',
			array(
				'label'       => __( 'Element radius', 'osam-bookings' ),
				'description' => __( 'Cards, buttons, inputs and calendar days.', 'osam-bookings' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'   => array( '{{WRAPPER}} .osb-booking' => '--osb-radius: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'box_shadow',
				'selector' => '{{WRAPPER}} .osb-booking',
			)
		);
		$this->add_responsive_control(
			'max_width',
			array(
				'label'      => __( 'Max width', 'osam-bookings' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array( 'px' => array( 'min' => 300, 'max' => 1400 ) ),
				'selectors'  => array( '{{WRAPPER}} .osb-booking' => 'max-width: {{SIZE}}{{UNIT}}; margin-left: auto; margin-right: auto;' ),
			)
		);
		$this->end_controls_section();

		/* ---------- Style: typography ---------- */
		$this->start_controls_section(
			'section_type',
			array(
				'label' => __( 'Typography', 'osam-bookings' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'typo_body',
				'label'    => __( 'Body', 'osam-bookings' ),
				'selector' => '{{WRAPPER}} .osb-booking',
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'typo_title',
				'label'    => __( 'Step titles', 'osam-bookings' ),
				'selector' => '{{WRAPPER}} .osb-booking .osb-title',
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'typo_card',
				'label'    => __( 'Service / event names', 'osam-bookings' ),
				'selector' => '{{WRAPPER}} .osb-booking .osb-card__name',
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'typo_button',
				'label'    => __( 'Buttons', 'osam-bookings' ),
				'selector' => '{{WRAPPER}} .osb-booking .osb-btn',
			)
		);
		$this->end_controls_section();

		/* ---------- Style: buttons ---------- */
		$this->start_controls_section(
			'section_buttons',
			array(
				'label' => __( 'Buttons', 'osam-bookings' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_responsive_control(
			'btn_padding',
			array(
				'label'      => __( 'Padding', 'osam-bookings' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .osb-booking .osb-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_control(
			'btn_radius',
			array(
				'label'      => __( 'Radius', 'osam-bookings' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .osb-booking .osb-btn' => 'border-radius: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_control(
			'btn_full',
			array(
				'label'        => __( 'Full-width primary button', 'osam-bookings' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'selectors'    => array( '{{WRAPPER}} .osb-booking .osb-btn--primary' => 'flex: 1 1 100%;' ),
			)
		);
		$this->end_controls_section();

		/* ---------- Style: cards ---------- */
		$this->start_controls_section(
			'section_cards',
			array(
				'label' => __( 'Cards & time slots', 'osam-bookings' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_responsive_control(
			'cards_columns',
			array(
				'label'     => __( 'Service columns', 'osam-bookings' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => array(
					''  => __( 'Default (1)', 'osam-bookings' ),
					'1' => '1',
					'2' => '2',
					'3' => '3',
				),
				'selectors' => array( '{{WRAPPER}} .osb-booking .osb-cards:not(.osb-cards--staff)' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
			)
		);
		$this->add_control(
			'card_bg',
			array(
				'label'     => __( 'Card background', 'osam-bookings' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .osb-booking .osb-card, {{WRAPPER}} .osb-booking .osb-event, {{WRAPPER}} .osb-booking .osb-slot' => 'background-color: {{VALUE}};' ),
			)
		);
		$this->add_responsive_control(
			'card_padding',
			array(
				'label'      => __( 'Card padding', 'osam-bookings' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .osb-booking .osb-card, {{WRAPPER}} .osb-booking .osb-event' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Drop the kinds that are switched off in Settings → Features.
	 *
	 * @param array $options Options.
	 * @return array
	 */
	private static function kinds( array $options ) {
		foreach ( array( 'events', 'courses', 'tables', 'resources' ) as $kind ) {
			if ( ! OSB_Settings::feature( 'tables' === $kind ? 'restaurant' : $kind ) ) {
				unset( $options[ $kind ] );
			}
		}
		return $options;
	}

	/**
	 * Render.
	 */
	protected function render() {
		$s    = $this->get_settings_for_display();
		$atts = array( 'type' => $s['type'] );
		if ( in_array( $s['type'], array( 'all', 'services' ), true ) ) {
			if ( ! empty( $s['services'] ) ) {
				$atts['service'] = implode( ',', array_map( 'absint', (array) $s['services'] ) );
			}
			if ( ! empty( $s['staff'] ) ) {
				$atts['staff'] = absint( $s['staff'] );
			}
			if ( ! empty( $s['category'] ) ) {
				$atts['category'] = $s['category'];
			}
		}
		if ( in_array( $s['type'], array( 'all', 'events' ), true ) && ! empty( $s['events'] ) ) {
			$atts['event'] = implode( ',', array_map( 'absint', (array) $s['events'] ) );
		}
		if ( in_array( $s['type'], array( 'all', 'courses' ), true ) && ! empty( $s['courses'] ) ) {
			$atts['course'] = implode( ',', array_map( 'absint', (array) $s['courses'] ) );
		}
		foreach ( array( 'design', 'layout', 'columns', 'buttons', 'density' ) as $attr ) {
			if ( ! empty( $s[ 'display_' . $attr ] ) ) {
				$atts[ $attr ] = $s[ 'display_' . $attr ];
			}
		}
		foreach ( OSB_Settings::display_toggles() as $def ) {
			if ( ! empty( $s[ 'show_' . $def[0] ] ) ) {
				$atts[ $def[0] ] = $s[ 'show_' . $def[0] ];
			}
		}
		// Shortcode output is escaped internally.
		echo OSB_Frontend::shortcode( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
