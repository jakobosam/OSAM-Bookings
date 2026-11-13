<?php
/**
 * Email notifications.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends templated HTML emails to customers, admins and staff.
 */
class OSB_Emails {

	/**
	 * Dedupe value that keeps a send out of the booking's history (tests).
	 */
	const NO_LOG = '__test__';

	/**
	 * Standard email a custom email is standing in for right now (so attachments such as the
	 * invoice still go with it).
	 *
	 * @var string
	 */
	public static $replacing = '';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'osb_booking_notify', array( __CLASS__, 'notify' ), 10, 2 );
	}

	/**
	 * Route a lifecycle moment to the right templates.
	 *
	 * @param object $booking Booking.
	 * @param string $context new|approved|cancelled.
	 */
	public static function notify( $booking, $context ) {
		$details = OSB_Bookings::details( $booking );

		switch ( $context ) {
			case 'new':
				if ( 'bundle' === $booking->type ) {
					// The code goes out in its own email once the bundle is paid (OSB_Bundles::activate()).
					$pass = OSB_Bundles::pass_for_purchase( $booking->id );
					if ( $pass && 'pending' === $pass->status ) {
						self::send( 'customer_pending', $booking, $booking->customer_email );
					}
					self::send( 'admin_new', $booking, self::admin_recipients() );
					break;
				}
				self::send( 'confirmed' === $booking->status ? 'customer_confirmed' : 'customer_pending', $booking, $booking->customer_email );
				self::send( 'admin_new', $booking, self::admin_recipients() );
				if ( $details['staff_email'] ) {
					self::send( 'staff_new', $booking, $details['staff_email'] );
				}
				break;
			case 'approved':
				self::send( 'customer_confirmed', $booking, $booking->customer_email );
				break;
			case 'payment_failed':
				self::send( 'customer_payment_failed', $booking, $booking->customer_email );
				self::send( 'admin_payment_failed', $booking, self::admin_recipients() );
				break;
			case 'cancelled':
				self::send( 'customer_cancelled', $booking, $booking->customer_email );
				self::send( 'admin_cancelled', $booking, self::admin_recipients() );
				if ( $details['staff_email'] ) {
					self::send( 'staff_cancelled', $booking, $details['staff_email'] );
				}
				break;
		}
	}

	/**
	 * Admin recipients.
	 *
	 * @return string[]
	 */
	public static function admin_recipients() {
		$list = array_filter( array_map( 'trim', explode( ',', (string) OSB_Settings::get( 'email_admin_recipients' ) ) ), 'is_email' );
		return $list ? $list : array( get_option( 'admin_email' ) );
	}

	/**
	 * Placeholder values for a booking.
	 *
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function placeholders( $booking ) {
		$d      = OSB_Bookings::details( $booking );
		$fields = OSB_Bookings::fields( $booking );
		$first  = trim( strtok( (string) $booking->customer_name, ' ' ) );
		$ds     = self::design();
		$admin  = admin_url( 'admin.php?page=osb-bookings&action=view&id=' . $booking->id );

		$rows = '';
		foreach ( OSB_Settings::form_fields() as $field ) {
			if ( in_array( $field['key'], array( 'name', 'email', 'phone' ), true ) ) {
				continue;
			}
			$value = isset( $fields[ $field['key'] ] ) ? $fields[ $field['key'] ] : '';
			if ( 'checkbox' === $field['type'] ) {
				$value = $value ? __( 'Yes', 'osam-bookings' ) : __( 'No', 'osam-bookings' );
			}
			if ( '' === $value ) {
				continue;
			}
			$rows .= '<tr><td style="padding:6px 16px 6px 0;color:' . $ds['muted'] . ';vertical-align:top;width:38%">' . esc_html( $field['label'] ) . '</td><td style="padding:6px 0;vertical-align:top">' . nl2br( esc_html( $value ) ) . '</td></tr>';
		}

		$map = array(
			'{booking_id}'          => $d['id'],
			'{item_name}'           => esc_html( $d['item_name'] ),
			'{service_name}'        => esc_html( $d['item_name'] ),
			'{event_name}'          => esc_html( $d['item_name'] ),
			'{staff_name}'          => esc_html( $d['staff_name'] ),
			'{staff_line}'          => $d['staff_name'] ? '<br>' . esc_html( sprintf( /* translators: %s staff name */ __( 'with %s', 'osam-bookings' ), $d['staff_name'] ) ) : '',
			'{location}'            => esc_html( $d['location'] ),
			'{location_line}'       => $d['location'] ? '<br>' . esc_html( $d['location'] ) : '',
			'{date}'                => esc_html( $d['date'] ),
			'{time}'                => esc_html( $d['time'] ),
			'{end_time}'            => esc_html( $d['end_time'] ),
			'{duration}'            => $d['duration'],
			'{seats}'               => $d['seats'],
			'{attendees}'           => esc_html( self::attendee_names( $booking ) ),
			'{amount}'              => esc_html( $d['amount'] ),
			'{payment_method}'      => esc_html( $d['payment_method'] ),
			'{payment_status}'      => esc_html( $d['payment_status'] ),
			'{status}'              => esc_html( $d['status'] ),
			'{customer_name}'       => esc_html( $booking->customer_name ),
			'{customer_first_name}' => esc_html( $first ),
			'{customer_email}'      => esc_html( $booking->customer_email ),
			'{customer_phone}'      => esc_html( $booking->customer_phone ),
			'{fields_table}'        => $rows ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;font-size:14px">' . $rows . '</table>' : '',
			'{details_card}'        => self::details_card( $booking, $d, $ds ),
			'{manage_button}'       => self::button( $d['manage_url'], ( 'cancelled' === $booking->status ? __( 'View booking', 'osam-bookings' ) : ( $d['can_cancel'] ? __( 'View or cancel booking', 'osam-bookings' ) : __( 'View your booking', 'osam-bookings' ) ) ), $ds ),
			'{admin_button}'        => self::button( $admin, __( 'Open in admin', 'osam-bookings' ), $ds ),
			'{status_badge}'        => self::badge( $booking->status, $d['status'], $ds ),
			'{review_url}'          => esc_url( (string) OSB_Settings::get( 'review_url' ) ),
			'{review_button}'       => OSB_Settings::get( 'review_url' ) ? self::button( OSB_Settings::get( 'review_url' ), OSB_Settings::get( 'review_button_label' ) ? OSB_Settings::get( 'review_button_label' ) : __( 'Leave a review', 'osam-bookings' ), $ds ) : '',
			'{book_again_button}'   => self::button( OSB_Settings::get( 'booking_page' ) ? get_permalink( (int) OSB_Settings::get( 'booking_page' ) ) : home_url( '/' ), __( 'Book again', 'osam-bookings' ), $ds ),
			'{booking_url}'         => esc_url( OSB_Settings::get( 'booking_page' ) ? get_permalink( (int) OSB_Settings::get( 'booking_page' ) ) : home_url( '/' ) ),
			'{discount}'            => isset( $booking->discount ) && (float) $booking->discount > 0 ? esc_html( OSB_Settings::money( $booking->discount, $booking->currency ) ) : '',
			'{meeting_link}'        => ! empty( $booking->meeting_url ) ? esc_url( $booking->meeting_url ) : '',
			'{meeting_button}'      => ! empty( $booking->meeting_url ) ? self::button( $booking->meeting_url, __( 'Join online', 'osam-bookings' ), $ds ) : '',
			'{manage_url}'          => esc_url( $d['manage_url'] ),
			'{admin_url}'           => esc_url( $admin ),
			'{site_name}'           => esc_html( get_bloginfo( 'name' ) ),
			'{site_url}'            => esc_url( home_url( '/' ) ),
			'{schedule}'            => OSB_Courses::schedule_html( $booking ),
			'{intake_name}'         => esc_html( $d['intake_name'] ),
			'{session_count}'       => $d['session_count'],
		);
		foreach ( $fields as $key => $value ) {
			$map[ '{field:' . $key . '}' ] = esc_html( is_scalar( $value ) ? (string) $value : '' );
		}
		// "Pay now": empty unless money is still owed and can be paid online.
		$due     = OSB_Payments::payable( $booking );
		$pay_url = $due > 0 ? OSB_Payments::pay_url( $booking ) : '';
		$map['{amount_due}'] = $due > 0 ? esc_html( OSB_Settings::money( $due, $booking->currency ) ) : '';
		$map['{pay_link}']   = esc_url( $pay_url );
		/* translators: 1: "Pay now" 2: amount */
		$map['{pay_button}'] = $pay_url ? self::button( $pay_url, sprintf( __( '%1$s — %2$s', 'osam-bookings' ), OSB_Payments::pay_label( $booking ), OSB_Settings::money( $due, $booking->currency ) ), $ds ) : '';
		return apply_filters( 'osb_email_placeholders', $map, $booking );
	}

	/**
	 * Send a template.
	 *
	 * @param string          $key     Template key.
	 * @param object          $booking Booking.
	 * @param string|string[] $to      Recipient(s).
	 * @param bool            $force   Send even if the template is disabled (manual resend).
	 * @param array           $extra   Extra placeholder values (e.g. the session for a session reminder).
	 * @return bool
	 */
	public static function send( $key, $booking, $to, $force = false, array $extra = array() ) {
		$templates = OSB_Settings::get( 'emails' );
		$tpl       = isset( $templates[ $key ] ) ? $templates[ $key ] : null;
		if ( ! $tpl || ( empty( $tpl['enabled'] ) && ! $force ) || empty( $to ) ) {
			return false;
		}
		// Emails for a part of the plugin that's switched off (e.g. course reminders with Courses off) aren't
		// sent automatically. Sending one by hand from a booking still works.
		if ( ! $force && ! OSB_Settings::email_available( $key ) ) {
			return false;
		}
		if ( ! apply_filters( 'osb_send_email', true, $key, $booking, $to ) ) {
			return false;
		}
		// A custom email can stand in for this one on matching bookings (e.g. a different confirmation for one service).
		if ( ! $force && class_exists( 'OSB_Custom_Emails' ) ) {
			self::$replacing = $key;
			try {
				$replaced = OSB_Custom_Emails::replace( $key, $booking, $to, $extra );
			} finally {
				self::$replacing = '';
			}
			if ( null !== $replaced ) {
				return $replaced;
			}
		}

		list( $subject, $body ) = self::build( $key, $booking, $tpl, $extra );

		$staff_or_admin = 0 === strpos( $key, 'admin_' ) || 0 === strpos( $key, 'staff_' );
		return self::deliver(
			$key,
			$booking,
			$to,
			$subject,
			$body,
			array(
				'reply_to' => $staff_or_admin && is_email( $booking->customer_email ) ? array( $booking->customer_email, $booking->customer_name ) : null,
				'ics'      => OSB_Settings::get( 'email_attach_ics' ) && in_array( $key, array( 'customer_confirmed', 'customer_pending', 'customer_reminder', 'customer_schedule_changed', 'customer_rescheduled', 'staff_new' ), true ),
			)
		);
	}

	/**
	 * Send a built email: sender, reply-to, BCC, calendar file, filters, logging.
	 *
	 * @param string          $key     Email key (template key or custom_{id}).
	 * @param object          $booking Booking.
	 * @param string|string[] $to      Recipient(s).
	 * @param string          $subject Subject.
	 * @param string          $body    Full HTML.
	 * @param array           $opts    reply_to [email, name] | null, ics bool, bcc string[], from_name string, dedupe string.
	 * @return bool
	 */
	public static function deliver( $key, $booking, $to, $subject, $body, array $opts = array() ) {
		$opts      = wp_parse_args( $opts, array( 'reply_to' => null, 'ics' => false, 'bcc' => array(), 'from_name' => '', 'dedupe' => '' ) );
		$headers   = array( 'Content-Type: text/html; charset=UTF-8' );
		$from_name = '' !== (string) $opts['from_name'] ? $opts['from_name'] : OSB_Settings::get( 'email_from_name' );
		$from_addr = OSB_Settings::get( 'email_from_address' );
		if ( is_email( $from_addr ) ) {
			$headers[] = sprintf( 'From: %s <%s>', wp_specialchars_decode( $from_name, ENT_QUOTES ), $from_addr );
		}
		if ( is_array( $opts['reply_to'] ) && is_email( $opts['reply_to'][0] ) ) {
			$headers[] = '' !== (string) ( $opts['reply_to'][1] ?? '' ) ? sprintf( 'Reply-To: %s <%s>', str_replace( array( "\r", "\n", '<', '>', ',' ), '', $opts['reply_to'][1] ), $opts['reply_to'][0] ) : 'Reply-To: ' . $opts['reply_to'][0];
		}
		foreach ( array_filter( (array) $opts['bcc'], 'is_email' ) as $bcc ) {
			$headers[] = 'Bcc: ' . $bcc;
		}

		$attachments = array();
		if ( $opts['ics'] ) {
			$ics = self::ics_file( $booking );
			if ( $ics ) {
				$attachments[] = $ics;
			}
		}

		$args = apply_filters(
			'osb_email_args',
			compact( 'to', 'subject', 'body', 'headers', 'attachments' ),
			$key,
			$booking
		);

		$sent = wp_mail( $args['to'], $args['subject'], $args['body'], $args['headers'], $args['attachments'] );

		foreach ( $attachments as $file ) {
			wp_delete_file( $file );
			@rmdir( dirname( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		self::log( $key, $booking, $args['to'], $args['subject'], $sent, (string) $opts['dedupe'] );
		do_action( 'osb_email_sent', $key, $booking, $sent );
		return $sent;
	}

	/**
	 * Record a send in the booking's email history.
	 *
	 * @param string          $key     Email key.
	 * @param object          $booking Booking.
	 * @param string|string[] $to      Recipient(s).
	 * @param string          $subject Subject.
	 * @param bool            $sent    Accepted by wp_mail.
	 * @param string          $dedupe  Key used to send scheduled emails once.
	 */
	public static function log( $key, $booking, $to, $subject, $sent, $dedupe = '' ) {
		global $wpdb;
		if ( self::NO_LOG === $dedupe || empty( $booking->id ) || ! get_option( 'osb_db_version' ) || version_compare( (string) get_option( 'osb_db_version' ), '2.6.0', '<' ) ) {
			return;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			OSB_Install::table( 'email_log' ),
			array(
				'booking_id' => (int) $booking->id,
				'email_key'  => substr( (string) $key, 0, 64 ),
				'dedupe'     => substr( (string) $dedupe, 0, 191 ),
				'recipient'  => substr( implode( ', ', (array) $to ), 0, 255 ),
				'subject'    => substr( wp_strip_all_tags( (string) $subject ), 0, 255 ),
				'sent'       => $sent ? 1 : 0,
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Emails sent about a booking, newest first.
	 *
	 * @param int $booking_id Booking.
	 * @return array
	 */
	public static function history( $booking_id ) {
		global $wpdb;
		$t = OSB_Install::table( 'email_log' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE booking_id = %d ORDER BY id DESC LIMIT 100", $booking_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Subject and full HTML for a template.
	 *
	 * @param string $key     Template key.
	 * @param object $booking Booking.
	 * @param array  $tpl     [ subject, body ].
	 * @param array  $extra   Extra placeholders.
	 * @return array [ subject, html ]
	 */
	public static function build( $key, $booking, array $tpl, array $extra = array() ) {
		$map  = array_merge( self::placeholders( $booking ), $extra );
		$body = (string) $tpl['body'];
		// Templates saved before courses existed have no {schedule}; course customers still need their dates.
		if ( 'course' === $booking->type && 0 === strpos( $key, 'customer_' ) && false === strpos( $body, '{schedule}' ) && 'customer_session_reminder' !== $key ) {
			$body .= "\n{schedule}";
		}
		// The invoice email always offers "Pay now" when something is still owed, even on templates
		// saved before the button existed.
		$invoice_email = 'customer_invoice' === $key || ( 0 === strpos( (string) $key, 'custom_' ) && 'customer_invoice' === self::$replacing );
		if ( $invoice_email && ! empty( $map['{pay_button}'] ) && false === strpos( $body, '{pay_button}' ) && false === strpos( $body, '{pay_link}' ) ) {
			$at   = strpos( $body, '{invoice_button}' );
			$body = false === $at ? $body . "\n{pay_button}" : substr_replace( $body, "{pay_button}\n", $at, 0 );
		}
		$subject = wp_specialchars_decode( strtr( (string) $tpl['subject'], $map ), ENT_QUOTES );
		// Block-level placeholders must not end up wrapped in a paragraph: give each its own line first.
		$blocks = 'schedule|fields_table|details_card|manage_button|admin_button|review_button|meeting_button|waitlist_button|invoice_button|pay_button';
		$body   = preg_replace( '#\{(' . $blocks . ')\}#', "\n\n{\$1}\n\n", $body );
		$body   = preg_replace( '#<p>\s*(\{(?:' . $blocks . ')\})\s*(?:<br\s*/?>)?\s*</p>#', '$1', wpautop( $body ) );
		$body = strtr( $body, $map );
		// Tidy what empty placeholders leave behind (a blank phone line, an empty note).
		$body = preg_replace( '#(?:<br\s*/?>\s*)+</p>#i', '</p>', $body );
		$body = preg_replace( '#<p>\s*(?:<br\s*/?>\s*)*</p>#i', '', $body );
		$body = self::style_content( $body, self::design() );
		return array( $subject, self::wrap( $body, $subject ) );
	}

	/**
	 * A booking to preview or test a template with: the latest real one of the right kind, or sample data.
	 *
	 * @param string $key Template key.
	 * @param string $to  Email for sample data.
	 * @return object
	 */
	public static function sample_booking( $key, $to = '' ) {
		$course_tpl   = in_array( $key, array( 'customer_session_reminder', 'customer_schedule_changed' ), true );
		list( $rows ) = OSB_DB::query_bookings( array( 'per_page' => 1, 'orderby' => 'id', 'type' => $course_tpl ? 'course' : 'service' ) );
		if ( ! $rows ) {
			list( $rows ) = OSB_DB::query_bookings( array( 'per_page' => 1, 'orderby' => 'id' ) );
		}
		if ( $rows ) {
			return $rows[0];
		}
		$services = OSB_DB::services();
		$start    = strtotime( current_time( 'mysql' ) . ' +2 days 10:00' );
		return (object) array(
			'id'             => 1024,
			'type'           => 'service',
			'service_id'     => $services ? $services[0]->id : 0,
			'staff_id'       => 0,
			'event_id'       => 0,
			'course_id'      => 0,
			'intake_id'      => 0,
			'start_at'       => gmdate( 'Y-m-d H:i:s', $start ),
			'end_at'         => gmdate( 'Y-m-d H:i:s', $start + HOUR_IN_SECONDS ),
			'seats'          => 1,
			'customer_name'  => 'Sam Sample',
			'customer_email' => $to ? $to : 'sam@example.com',
			'customer_phone' => '07700 900123',
			'fields'         => wp_json_encode( array( 'notes' => 'This is sample data.' ) ),
			'internal_note'  => '',
			'status'         => 'confirmed',
			'payment_method' => 'onsite',
			'payment_status' => 'unpaid',
			'amount'         => 45,
			'discount'       => 0,
			'currency'       => OSB_Settings::get( 'currency' ),
			'token'          => 'sampletoken0000000000000000000000',
			'wc_order_id'    => 0,
			'meeting_url'    => '',
			'created_at'     => current_time( 'mysql' ),
		);
	}

	/**
	 * Placeholders that only exist for some sends, filled with believable examples for previews/tests.
	 *
	 * @param string $key     Template key.
	 * @param object $booking Booking.
	 * @return array
	 */
	public static function sample_extra( $key, $booking ) {
		$ds    = self::design();
		$extra = array(
			'{previous_when}'    => esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $booking->start_at ) - DAY_IN_SECONDS ) ),
			'{waitlist_expires}' => esc_html( date_i18n( 'l ' . get_option( 'time_format' ), strtotime( current_time( 'mysql' ) ) + DAY_IN_SECONDS ) ),
			'{waitlist_button}'  => self::button( home_url( '/' ), __( 'Book my place', 'osam-bookings' ), $ds ),
		);
		if ( 'customer_session_reminder' === $key ) {
			$sessions = 'course' === $booking->type ? OSB_Courses::sessions( (int) $booking->intake_id ) : array();
			$extra    = $sessions ? array_merge( $extra, OSB_Courses::session_placeholders( $booking, $sessions[0] ) ) : array_merge(
				$extra,
				array(
					'{session_number}'   => 1,
					'{session_count}'    => 6,
					'{session_date}'     => esc_html( date_i18n( 'D ' . get_option( 'date_format' ), strtotime( $booking->start_at ) ) ),
					'{session_time}'     => esc_html( date_i18n( get_option( 'time_format' ), strtotime( $booking->start_at ) ) ),
					'{session_end_time}' => esc_html( date_i18n( get_option( 'time_format' ), strtotime( $booking->end_at ) ) ),
					'{session_location}' => '',
					'{session_note}'     => '',
				)
			);
		}
		return $extra;
	}

	/**
	 * Render a template for the live preview, using unsaved design values and wording.
	 *
	 * @param string $key    Template key.
	 * @param array  $design email_* values.
	 * @param array  $tpl    subject/body (optional).
	 * @return array [ subject, html ]
	 */
	public static function preview( $key, array $design, array $tpl = array() ) {
		$templates = OSB_Settings::get( 'emails' );
		$saved     = isset( $templates[ $key ] ) ? $templates[ $key ] : array( 'subject' => '', 'body' => '' );
		$tpl       = array(
			'subject' => isset( $tpl['subject'] ) ? (string) $tpl['subject'] : $saved['subject'],
			'body'    => isset( $tpl['body'] ) ? wp_kses_post( (string) $tpl['body'] ) : $saved['body'],
		);
		self::preview_with( $design );
		$booking = self::sample_booking( $key );
		$out     = self::build( $key, $booking, $tpl, self::sample_extra( $key, $booking ) );
		self::preview_with( null );
		return $out;
	}

	/**
	 * Design used while rendering a preview with unsaved values.
	 *
	 * @var array|null
	 */
	private static $design_override = null;

	/**
	 * Render with these (unsaved) design values until reset.
	 *
	 * @param array|null $values Values keyed like the email_* settings, or null to reset.
	 */
	public static function preview_with( $values ) {
		self::$design_override = is_array( $values ) ? $values : null;
	}

	/**
	 * Colour themes for the one-click picker.
	 *
	 * @return array key => [ label, values ]
	 */
	public static function themes() {
		return apply_filters(
			'osb_email_themes',
			array(
				'classic'  => array( __( 'Classic', 'osam-bookings' ), array( 'header_bg' => '#161a40', 'header_text' => '#ffffff', 'accent' => '#0273b0', 'button_text' => '#ffffff', 'page_bg' => '#f1f3f9', 'card_bg' => '#ffffff', 'panel_bg' => '#f5f7ff', 'text' => '#1f2933', 'muted' => '#667085', 'footer_text' => '#8a94a6' ) ),
				'fresh'    => array( __( 'Fresh', 'osam-bookings' ), array( 'header_bg' => '#0f766e', 'header_text' => '#ffffff', 'accent' => '#0d9488', 'button_text' => '#ffffff', 'page_bg' => '#eef7f5', 'card_bg' => '#ffffff', 'panel_bg' => '#f0faf8', 'text' => '#13312d', 'muted' => '#5b746f', 'footer_text' => '#7c928e' ) ),
				'blush'    => array( __( 'Blush', 'osam-bookings' ), array( 'header_bg' => '#fbe4e6', 'header_text' => '#8a2c43', 'accent' => '#c2255c', 'button_text' => '#ffffff', 'page_bg' => '#fdf4f5', 'card_bg' => '#ffffff', 'panel_bg' => '#fdf1f3', 'text' => '#3d1f27', 'muted' => '#8b6a72', 'footer_text' => '#a58a91' ) ),
				'sand'     => array( __( 'Sand', 'osam-bookings' ), array( 'header_bg' => '#efe6da', 'header_text' => '#3e3328', 'accent' => '#9a6b3f', 'button_text' => '#ffffff', 'page_bg' => '#f7f3ee', 'card_bg' => '#fffdfa', 'panel_bg' => '#f6efe6', 'text' => '#2f2822', 'muted' => '#7a6d61', 'footer_text' => '#9a8d80' ) ),
				'midnight' => array( __( 'Midnight', 'osam-bookings' ), array( 'header_bg' => '#111827', 'header_text' => '#ffffff', 'accent' => '#6366f1', 'button_text' => '#ffffff', 'page_bg' => '#e5e7eb', 'card_bg' => '#ffffff', 'panel_bg' => '#f3f4f6', 'text' => '#111827', 'muted' => '#6b7280', 'footer_text' => '#6b7280' ) ),
				'mono'     => array( __( 'Minimal', 'osam-bookings' ), array( 'header_bg' => '#ffffff', 'header_text' => '#111111', 'accent' => '#111111', 'button_text' => '#ffffff', 'page_bg' => '#f5f5f5', 'card_bg' => '#ffffff', 'panel_bg' => '#f7f7f7', 'text' => '#1a1a1a', 'muted' => '#737373', 'footer_text' => '#8c8c8c' ) ),
			)
		);
	}

	/**
	 * Font stacks.
	 *
	 * @return array key => [ label, css ]
	 */
	public static function fonts() {
		return array(
			'system'   => array( __( 'Modern (system)', 'osam-bookings' ), '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif' ),
			'helvetica' => array( __( 'Clean (Helvetica / Arial)', 'osam-bookings' ), '"Helvetica Neue",Helvetica,Arial,sans-serif' ),
			'georgia'  => array( __( 'Classic serif (Georgia)', 'osam-bookings' ), 'Georgia,"Times New Roman",Times,serif' ),
			'trebuchet' => array( __( 'Friendly (Trebuchet)', 'osam-bookings' ), '"Trebuchet MS","Lucida Grande",Tahoma,sans-serif' ),
			'verdana'  => array( __( 'Wide (Verdana)', 'osam-bookings' ), 'Verdana,Geneva,Tahoma,sans-serif' ),
		);
	}

	/**
	 * Resolved email design (saved settings, or the preview override).
	 *
	 * @return array
	 */
	public static function design() {
		$s   = OSB_Settings::all();
		$src = null !== self::$design_override ? array_merge( $s, self::$design_override ) : $s;
		$hex = static function ( $key, $fallback ) use ( $src ) {
			$c = isset( $src[ $key ] ) ? sanitize_hex_color( (string) $src[ $key ] ) : '';
			return $c ? $c : $fallback;
		};
		$accent = $hex( 'email_accent', '#161a40' );
		$fonts  = self::fonts();
		$font   = isset( $src['email_font'], $fonts[ $src['email_font'] ] ) ? $src['email_font'] : 'system';

		$logo_url = '';
		if ( ! empty( $src['email_logo_id'] ) ) {
			$logo_url = (string) wp_get_attachment_image_url( (int) $src['email_logo_id'], 'full' );
		}
		if ( ! $logo_url && ! empty( $src['email_logo'] ) ) {
			$logo_url = (string) $src['email_logo']; // Older sites stored a URL.
		}
		$header = isset( $src['email_header_style'] ) && in_array( $src['email_header_style'], array( 'band', 'clean', 'centered' ), true ) ? $src['email_header_style'] : 'band';

		return apply_filters(
			'osb_email_design',
			array(
				'header_style' => $header,
				'logo'         => $logo_url,
				'logo_width'   => max( 40, min( 400, (int) ( isset( $src['email_logo_width'] ) ? $src['email_logo_width'] : 160 ) ) ),
				'header_bg'    => $hex( 'email_header_bg', $accent ),
				'header_text'  => $hex( 'email_header_text', '#ffffff' ),
				'accent'       => $accent,
				'button_text'  => $hex( 'email_button_text', '#ffffff' ),
				'page_bg'      => $hex( 'email_page_bg', '#f1f3f9' ),
				'card_bg'      => $hex( 'email_card_bg', '#ffffff' ),
				'panel_bg'     => $hex( 'email_panel_bg', '#f5f7ff' ),
				'text'         => $hex( 'email_text', '#1f2933' ),
				'muted'        => $hex( 'email_muted', '#667085' ),
				'footer_text'  => $hex( 'email_footer_text', '#8a94a6' ),
				'font'         => $fonts[ $font ][1],
				'radius'       => max( 0, min( 24, (int) ( isset( $src['email_radius'] ) ? $src['email_radius'] : 12 ) ) ),
				'footer'       => isset( $src['email_footer'] ) ? (string) $src['email_footer'] : '',
				'site_link'    => ! isset( $src['email_footer_site_link'] ) || ! empty( $src['email_footer_site_link'] ),
			)
		);
	}

	/**
	 * Call-to-action button (bulletproof table markup so Outlook keeps the colour).
	 *
	 * @param string $url   URL.
	 * @param string $label Label.
	 * @param array  $ds    Design.
	 * @return string
	 */
	public static function button( $url, $label, array $ds ) {
		$r = min( $ds['radius'], 10 );
		return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px"><tr><td style="border-radius:' . $r . 'px;background:' . $ds['accent'] . '" bgcolor="' . $ds['accent'] . '">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:13px 26px;font-family:' . esc_attr( $ds['font'] ) . ';font-size:15px;font-weight:600;line-height:1.2;color:' . $ds['button_text'] . ';text-decoration:none;border-radius:' . $r . 'px">' . esc_html( $label ) . '</a></td></tr></table>';
	}

	/**
	 * Status pill.
	 *
	 * @param string $status Status key.
	 * @param string $label  Label.
	 * @param array  $ds     Design.
	 * @return string
	 */
	public static function badge( $status, $label, array $ds ) {
		$colors = array(
			'confirmed'       => array( '#e7f6ec', '#146c2e' ),
			'completed'       => array( '#efeafd', '#5925dc' ),
			'pending'         => array( '#fff4e0', '#93500a' ),
			'pending_payment' => array( '#e8f1fd', '#175cd3' ),
			'cancelled'       => array( '#fdecea', '#b42318' ),
			'payment_failed'  => array( '#fffaeb', '#b54708' ),
			'no_show'         => array( '#fdecf5', '#a1155a' ),
		);
		$c = isset( $colors[ $status ] ) ? $colors[ $status ] : array( $ds['panel_bg'], $ds['muted'] );
		return '<span style="display:inline-block;padding:3px 10px;border-radius:99px;background:' . $c[0] . ';color:' . $c[1] . ';font-size:12px;font-weight:600;line-height:1.5">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Booking summary panel.
	 *
	 * @param object $booking Booking.
	 * @param array  $d       Details.
	 * @param array  $ds      Design.
	 * @return string
	 */
	public static function details_card( $booking, array $d, array $ds ) {
		$rows = array();
		if ( 'course' === $booking->type ) {
			$rows[] = array( __( 'Starts', 'osam-bookings' ), $d['date'] . ', ' . $d['time'] );
			if ( $d['session_count'] ) {
				/* translators: %d sessions */
				$rows[] = array( __( 'Sessions', 'osam-bookings' ), sprintf( _n( '%d session', '%d sessions', $d['session_count'], 'osam-bookings' ), $d['session_count'] ) );
			}
		} else {
			$rows[] = array( __( 'When', 'osam-bookings' ), $d['date'] . ', ' . $d['time'] . ( $d['end_time'] && $d['end_time'] !== $d['time'] ? ' – ' . $d['end_time'] : '' ) );
		}
		if ( $d['staff_name'] ) {
			$rows[] = array( __( 'With', 'osam-bookings' ), $d['staff_name'] );
		}
		if ( $d['location'] ) {
			$rows[] = array( __( 'Where', 'osam-bookings' ), $d['location'] );
		}
		if ( 'resource' === $booking->type ) {
			$rows[] = array( __( 'Booking', 'osam-bookings' ), $d['hire_label'] . ( ! empty( $d['exclusive'] ) ? ' · ' . __( 'sole use', 'osam-bookings' ) : '' ) );
			if ( (int) $d['seats'] > 1 ) {
				$rows[] = array( OSB_Settings::label( 'res_places' ), (string) $d['seats'] );
			}
		} elseif ( 'table' === $booking->type ) {
			$rows[] = array( OSB_Settings::label( 'covers' ), (string) $d['seats'] );
		} elseif ( (int) $d['seats'] > 1 ) {
			$rows[] = array( OSB_Settings::label( 'seats' ), (string) $d['seats'] );
		}
		$names = self::attendee_names( $booking );
		if ( '' !== $names ) {
			$rows[] = array( __( 'Attendees', 'osam-bookings' ), $names );
		}
		if ( $d['discount'] ) {
			/* translators: %s code */
			$rows[] = array( sprintf( __( 'Discount (%s)', 'osam-bookings' ), $d['coupon_code'] ), '−' . $d['discount'] );
		}
		$rows[] = array( __( 'Total', 'osam-bookings' ), $d['amount'] . ' · ' . $d['payment_status'] );
		$join = ! empty( $d['meeting_url'] ) && 'cancelled' !== $booking->status ? $d['meeting_url'] : '';
		if ( $join ) {
			$host   = (string) wp_parse_url( $join, PHP_URL_HOST );
			$rows[] = array( __( 'Online', 'osam-bookings' ), '<a href="' . esc_url( $join ) . '" style="color:' . $ds['accent'] . ';text-decoration:underline;word-break:break-all">' . esc_html( $host ? preg_replace( '/^www\./', '', $host ) : __( 'Join link', 'osam-bookings' ) ) . '</a>', true );
		}
		$rows[] = array( __( 'Reference', 'osam-bookings' ), '#' . $d['id'] );
		$rows   = (array) apply_filters( 'osb_details_card_rows', $rows, $booking, $d );

		$sub = 'course' === $booking->type && $d['intake_name'] ? '<div style="font-size:14px;color:' . $ds['muted'] . ';margin-top:2px">' . esc_html( $d['intake_name'] ) . '</div>' : '';
		$tr  = '';
		foreach ( $rows as $row ) {
			$tr .= '<tr><td style="padding:7px 16px 7px 0;color:' . $ds['muted'] . ';font-size:14px;vertical-align:top;white-space:nowrap;width:1%">' . esc_html( $row[0] ) . '</td><td style="padding:7px 0;font-size:14px;color:' . $ds['text'] . ';vertical-align:top;font-weight:500">' . ( ! empty( $row[2] ) ? $row[1] : esc_html( $row[1] ) ) . '</td></tr>';
		}
		$button = $join ? '<div style="margin-top:14px">' . str_replace( 'margin:8px 0 24px', 'margin:0', self::button( $join, __( 'Join online', 'osam-bookings' ), $ds ) ) . '</div>' : '';
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 24px;background:' . $ds['panel_bg'] . ';border-radius:' . $ds['radius'] . 'px;border-left:4px solid ' . $ds['accent'] . '"><tr><td style="padding:18px 22px">'
			. '<div style="font-size:17px;font-weight:700;color:' . $ds['text'] . ';line-height:1.3">' . esc_html( $d['item_name'] ) . '</div>' . $sub
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px">' . $tr . '</table>' . $button . '</td></tr></table>';
	}

	/**
	 * "Sam Smith, Alex Jones, + 1 more" — '' when there's one place or no names were given for the others.
	 *
	 * @param object $booking Booking.
	 * @return string
	 */
	public static function attendee_names( $booking ) {
		if ( (int) $booking->seats < 2 || ! isset( $booking->guests ) || ! OSB_Bookings::guests( $booking ) ) {
			return '';
		}
		$names = array();
		$more  = 0;
		foreach ( OSB_Bookings::attendees( $booking ) as $a ) {
			if ( $a['same'] || '' === $a['name'] ) {
				++$more;
			} else {
				$names[] = $a['name'];
			}
		}
		/* translators: %d people */
		return implode( ', ', $names ) . ( $more ? ', ' . sprintf( _n( '+ %d more', '+ %d more', $more, 'osam-bookings' ), $more ) : '' );
	}

	/**
	 * Inline styles for the HTML people write in templates (email clients ignore <style>).
	 *
	 * @param string $html HTML.
	 * @param array  $ds   Design.
	 * @return string
	 */
	public static function style_content( $html, array $ds ) {
		$map = array(
			'p'          => 'margin:0 0 16px;font-size:15px;line-height:1.6;color:' . $ds['text'],
			'h1'         => 'margin:0 0 12px;font-size:24px;line-height:1.25;font-weight:700;color:' . $ds['text'],
			'h2'         => 'margin:0 0 10px;font-size:19px;line-height:1.3;font-weight:700;color:' . $ds['text'],
			'h3'         => 'margin:0 0 8px;font-size:16px;line-height:1.35;font-weight:700;color:' . $ds['text'],
			'ul'         => 'margin:0 0 16px;padding-left:20px;color:' . $ds['text'],
			'ol'         => 'margin:0 0 16px;padding-left:20px;color:' . $ds['text'],
			'li'         => 'margin:0 0 6px;line-height:1.55',
			'hr'         => 'border:0;border-top:1px solid ' . $ds['panel_bg'] . ';margin:24px 0',
			'blockquote' => 'margin:0 0 16px;padding:12px 16px;background:' . $ds['panel_bg'] . ';border-radius:' . $ds['radius'] . 'px;color:' . $ds['text'],
		);
		foreach ( $map as $tag => $style ) {
			// Only tags without their own style attribute.
			$html = preg_replace( '#<' . $tag . '(?=[\s>/])(?![^>]*\sstyle=)#i', '<' . $tag . ' style="' . $style . '"', $html );
		}
		$html = preg_replace( '#<a (?![^>]*\sstyle=)#i', '<a style="color:' . $ds['accent'] . ';text-decoration:underline" ', $html );
		return $html;
	}

	/**
	 * HTML wrapper.
	 *
	 * @param string $content   Inner HTML.
	 * @param string $preheader Inbox preview text.
	 * @return string
	 */
	public static function wrap( $content, $preheader = '' ) {
		$ds    = self::design();
		$site  = get_bloginfo( 'name' );
		$r     = $ds['radius'];
		$logo  = $ds['logo'] ? '<img src="' . esc_url( $ds['logo'] ) . '" width="' . (int) $ds['logo_width'] . '" alt="' . esc_attr( $site ) . '" style="display:block;width:' . (int) $ds['logo_width'] . 'px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none{osb_logo_style}">' : '';
		$title = static function ( $color, $align ) use ( $site, $ds ) {
			return '<div style="font-family:' . esc_attr( $ds['font'] ) . ';font-size:22px;font-weight:700;letter-spacing:-0.01em;color:' . $color . ';text-align:' . $align . '">' . esc_html( $site ) . '</div>';
		};

		// Header: coloured band, clean white with an accent rule, or logo above the card.
		$above  = '';
		$header = '';
		switch ( $ds['header_style'] ) {
			case 'centered':
				$above = '<tr><td align="center" style="padding:8px 0 22px">' . ( $logo ? str_replace( '{osb_logo_style}', ';margin:0 auto', $logo ) : $title( $ds['text'], 'center' ) ) . '</td></tr>';
				$header = '<tr><td style="height:6px;line-height:6px;font-size:0;background:' . $ds['accent'] . ';border-radius:' . $r . 'px ' . $r . 'px 0 0">&nbsp;</td></tr>';
				break;
			case 'clean':
				$header = '<tr><td style="padding:26px 36px 22px;border-bottom:3px solid ' . $ds['accent'] . ';background:' . $ds['card_bg'] . ';border-radius:' . $r . 'px ' . $r . 'px 0 0">' . ( $logo ? str_replace( '{osb_logo_style}', '', $logo ) : $title( $ds['text'], 'left' ) ) . '</td></tr>';
				break;
			default:
				$header = '<tr><td align="center" style="padding:28px 36px;background:' . $ds['header_bg'] . ';border-radius:' . $r . 'px ' . $r . 'px 0 0" bgcolor="' . $ds['header_bg'] . '">' . ( $logo ? str_replace( '{osb_logo_style}', ';margin:0 auto', $logo ) : $title( $ds['header_text'], 'center' ) ) . '</td></tr>';
		}

		$footer = '';
		if ( '' !== trim( $ds['footer'] ) ) {
			$footer .= '<div style="margin:0 0 6px">' . nl2br( wp_kses_post( $ds['footer'] ) ) . '</div>';
		}
		if ( $ds['site_link'] ) {
			$footer .= '<a href="' . esc_url( home_url( '/' ) ) . '" style="color:' . $ds['footer_text'] . ';text-decoration:underline">' . esc_html( preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( home_url() ) ) ) . '</a>';
		}

		$html  = '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>' . esc_html( $preheader ) . '</title>';
		$html .= '<style>@media only screen and (max-width:620px){.osb-e-pad{padding:26px 22px !important}.osb-e-outer{padding:16px 8px !important}}</style></head>';
		$html .= '<body style="margin:0;padding:0;background:' . $ds['page_bg'] . ';-webkit-text-size-adjust:100%">';
		if ( $preheader ) {
			$html .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . esc_html( $preheader ) . str_repeat( '&#847;&zwnj;&nbsp;', 40 ) . '</div>';
		}
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="' . $ds['page_bg'] . '" style="background:' . $ds['page_bg'] . '"><tr><td align="center" class="osb-e-outer" style="padding:32px 12px">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;font-family:' . esc_attr( $ds['font'] ) . ';color:' . $ds['text'] . '">';
		$html .= $above;
		$html .= '<tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $ds['card_bg'] . ';border-radius:' . $r . 'px;box-shadow:0 1px 3px rgba(16,24,40,.06)" bgcolor="' . $ds['card_bg'] . '">';
		$html .= $header;
		$html .= '<tr><td class="osb-e-pad" style="padding:34px 36px 18px;font-family:' . esc_attr( $ds['font'] ) . ';font-size:15px;line-height:1.6;color:' . $ds['text'] . '">' . $content . '</td></tr>';
		$html .= '</table></td></tr>';
		if ( $footer ) {
			$html .= '<tr><td align="center" style="padding:22px 24px 0;font-family:' . esc_attr( $ds['font'] ) . ';font-size:12px;line-height:1.6;color:' . $ds['footer_text'] . '">' . $footer . '</td></tr>';
		}
		$html .= '</table></td></tr></table></body></html>';

		return apply_filters( 'osb_email_html', $html, $content );
	}

	/**
	 * Build an .ics attachment.
	 *
	 * @param object $booking Booking.
	 * @return string|false File path.
	 */
	public static function ics_file( $booking ) {
		$ics = self::ics( $booking );
		$dir = trailingslashit( get_temp_dir() ) . 'osb-' . wp_generate_password( 8, false, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$file = $dir . '/booking-' . $booking->id . '.ics';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $file, $ics ) ? $file : false;
	}

	/**
	 * iCalendar text.
	 *
	 * @param object $booking Booking.
	 * @return string
	 */
	public static function ics( $booking ) {
		$d   = OSB_Bookings::details( $booking );
		$tz  = wp_timezone();
		$fmt = static function ( $local ) use ( $tz ) {
			$dt = new DateTime( $local, $tz );
			$dt->setTimezone( new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Ymd\THis\Z' );
		};
		$esc = static function ( $s ) {
			return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\;', '\,', '\n' ), wp_strip_all_tags( (string) $s ) );
		};
		$summary = $d['item_name'] . ( $d['staff_name'] ? ' — ' . $d['staff_name'] : '' );
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$uid     = 'osb-' . $booking->id . '-' . substr( $booking->token, 0, 8 );
		$lines   = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//OSAM Bookings//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
		);
		// One entry per course session; everything else is a single entry.
		$entries = array();
		if ( 'course' === $booking->type && $d['schedule'] ) {
			foreach ( $d['schedule'] as $s ) {
				$entries[] = array(
					'uid'      => $uid . '-s' . $s['id'],
					'start'    => $s['start'],
					'end'      => $s['end'],
					'summary'  => $summary . ' ' . sprintf( '(%d/%d)', $s['number'], count( $d['schedule'] ) ),
					'location' => $s['location'],
				);
			}
		} else {
			$entries[] = array(
				'uid'      => $uid,
				'start'    => $booking->start_at,
				'end'      => $booking->end_at,
				'summary'  => $summary,
				'location' => $d['location'],
			);
		}
		$stamp = gmdate( 'Ymd\THis\Z' );
		foreach ( $entries as $e ) {
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $e['uid'] . '@' . $host;
			$lines[] = 'DTSTAMP:' . $stamp;
			// Rising sequence lets calendar apps replace earlier copies when dates change.
			$lines[] = 'SEQUENCE:' . ( time() - 1700000000 );
			$lines[] = 'DTSTART:' . $fmt( $e['start'] );
			$lines[] = 'DTEND:' . $fmt( $e['end'] );
			$lines[] = 'SUMMARY:' . $esc( $e['summary'] . ' (' . get_bloginfo( 'name' ) . ')' );
			$online  = ! empty( $d['meeting_url'] ) ? $d['meeting_url'] : '';
			$lines[] = 'DESCRIPTION:' . $esc( ( $online ? sprintf( 'Join online: %s', $online ) . "\n\n" : '' ) . sprintf( 'Booking #%d — %s', $booking->id, $d['manage_url'] ) );
			if ( $e['location'] || $online ) {
				$lines[] = 'LOCATION:' . $esc( $e['location'] ? $e['location'] : $online );
			}
			$lines[] = 'URL:' . ( $online ? $online : $d['manage_url'] );
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", apply_filters( 'osb_ics_lines', $lines, $booking ) ) . "\r\n";
	}
}
