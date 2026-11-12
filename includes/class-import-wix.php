<?php
/**
 * Reads Wix Bookings data — from Wix's CSV exports or from the Wix REST API — into an import
 * dataset (see OSB_Migrate).
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Two routes:
 *
 * CSV: the "Booking list" export (Bookings → Booking List → Download CSV) and, optionally, the
 * Contacts export. Wix has no services export, so services are made from the service names found
 * in the bookings (length from start/end, price from the bookings that show one). Column headings
 * have changed over the years and are translated, so they are matched through an alias list.
 *
 * API: an API key and site ID (Wix: Settings → API Keys). Endpoints used:
 *   POST https://www.wixapis.com/bookings/v2/services/query                           (offset paging)
 *   POST https://www.wixapis.com/bookings/bookings-reader/v2/extended-bookings/query  (cursor paging)
 *   POST https://www.wixapis.com/contacts/v4/contacts/query                           (offset paging)
 * Wix's reference pages also list "/_api/…" forms of the first two; those are tried when the first
 * address answers 404. The key is kept (encrypted) only while the data is being fetched, then deleted.
 */
class OSB_Import_Wix {

	const API      = 'https://www.wixapis.com';
	const MAX_ROWS = 20000;

	/* ---------------------------------------------------------------------
	 * CSV
	 * ------------------------------------------------------------------ */

	/**
	 * Booking-list columns: key => [ label, aliases ].
	 *
	 * @return array
	 */
	public static function booking_columns() {
		return array(
			'id'           => array( __( 'Booking ID', 'osam-bookings' ), array( 'booking_id', 'booking_number', 'id', 'order_number', 'buchungs_id', 'buchungsnummer', 'id_de_reservation', 'numero_de_reservation', 'id_de_reserva' ) ),
			'service'      => array( __( 'Service', 'osam-bookings' ), array( 'service', 'service_name', 'services', 'session', 'class', 'appointment', 'dienstleistung', 'leistung', 'servicio', 'prestation', 'servizio', 'dienst' ) ),
			'staff'        => array( __( 'Staff member', 'osam-bookings' ), array( 'staff', 'staff_member', 'staff_name', 'provider', 'employee', 'instructor', 'mitarbeiter', 'mitarbeiter_in', 'personal', 'empleado', 'employe', 'membre_du_personnel', 'medewerker' ) ),
			'start'        => array( __( 'Start', 'osam-bookings' ), array( 'start', 'start_time', 'start_date', 'start_date_time', 'booking_start_time', 'booking_start', 'session_date_time', 'session_date_and_time', 'session_start', 'date_time', 'date_and_time', 'booking_date', 'session_date', 'appointment_date', 'startzeit', 'beginn', 'termin', 'datum_uhrzeit', 'hora_de_inicio', 'fecha_y_hora', 'heure_de_debut', 'date_et_heure', 'begintijd' ) ),
			'date'         => array( __( 'Date', 'osam-bookings' ), array( 'date', 'day', 'datum', 'fecha' ) ),
			'time'         => array( __( 'Time', 'osam-bookings' ), array( 'time', 'uhrzeit', 'hora', 'heure', 'tijd' ) ),
			'end'          => array( __( 'End', 'osam-bookings' ), array( 'end', 'end_time', 'end_date', 'end_date_time', 'booking_end_time', 'booking_end', 'session_end', 'endzeit', 'ende', 'hora_de_finalizacion', 'hora_de_fin', 'heure_de_fin', 'eindtijd' ) ),
			'duration'     => array( __( 'Duration', 'osam-bookings' ), array( 'duration', 'length', 'dauer', 'duracion', 'duree', 'duur' ) ),
			'name'         => array( __( 'Client name', 'osam-bookings' ), array( 'client_name', 'customer_name', 'client', 'customer', 'name', 'full_name', 'contact_name', 'participant', 'participant_name', 'booked_by', 'kunde', 'kundenname', 'name_des_kunden', 'cliente', 'nombre', 'nombre_del_cliente', 'nom_du_client', 'klant', 'naam' ) ),
			'first_name'   => array( __( 'First name', 'osam-bookings' ), array( 'first_name', 'client_first_name', 'vorname', 'prenom', 'nombre_de_pila', 'voornaam' ) ),
			'last_name'    => array( __( 'Last name', 'osam-bookings' ), array( 'last_name', 'client_last_name', 'surname', 'nachname', 'nom', 'apellido', 'apellidos', 'achternaam' ) ),
			'email'        => array( __( 'Email', 'osam-bookings' ), array( 'email', 'e_mail', 'email_address', 'client_email', 'customer_email', 'correo', 'correo_electronico', 'adresse_e_mail', 'courriel', 'e_mailadres' ) ),
			'phone'        => array( __( 'Phone', 'osam-bookings' ), array( 'phone', 'phone_number', 'client_phone', 'customer_phone', 'mobile', 'telefon', 'telefonnummer', 'telefono', 'telephone', 'telefoon' ) ),
			'status'       => array( __( 'Booking status', 'osam-bookings' ), array( 'status', 'booking_status', 'buchungsstatus', 'estado', 'estado_de_la_reserva', 'statut', 'statut_de_la_reservation' ) ),
			'payment'      => array( __( 'Payment status', 'osam-bookings' ), array( 'payment_status', 'payment', 'paid', 'zahlungsstatus', 'zahlung', 'estado_del_pago', 'pago', 'statut_du_paiement', 'paiement', 'betaalstatus' ) ),
			'price'        => array( __( 'Price', 'osam-bookings' ), array( 'price', 'amount', 'total', 'total_price', 'order_total', 'cost', 'preis', 'betrag', 'gesamt', 'precio', 'importe', 'prix', 'montant', 'prijs', 'bedrag' ) ),
			'currency'     => array( __( 'Currency', 'osam-bookings' ), array( 'currency', 'wahrung', 'moneda', 'devise', 'valuta' ) ),
			'participants' => array( __( 'Participants', 'osam-bookings' ), array( 'participants', 'number_of_participants', 'no_of_participants', 'attendees', 'guests', 'quantity', 'qty', 'spots', 'teilnehmer', 'anzahl_der_teilnehmer', 'participantes', 'nombre_de_participants', 'deelnemers' ) ),
			'created'      => array( __( 'Booked on', 'osam-bookings' ), array( 'created', 'created_date', 'created_at', 'booking_created', 'booked_on', 'booking_date_created', 'date_created', 'erstellt', 'erstellt_am', 'fecha_de_creacion', 'date_de_creation' ) ),
			'location'     => array( __( 'Location', 'osam-bookings' ), array( 'location', 'ort', 'standort', 'ubicacion', 'lieu', 'locatie' ) ),
			'notes'        => array( __( 'Notes', 'osam-bookings' ), array( 'notes', 'note', 'message', 'comments', 'client_notes', 'additional_info', 'notizen', 'nachricht', 'notas', 'mensaje', 'remarques', 'opmerkingen' ) ),
		);
	}

	/**
	 * Contacts columns.
	 *
	 * @return array
	 */
	public static function contact_columns() {
		return array(
			'first_name' => array( __( 'First name', 'osam-bookings' ), array( 'first_name', 'vorname', 'prenom', 'nombre', 'voornaam' ) ),
			'last_name'  => array( __( 'Last name', 'osam-bookings' ), array( 'last_name', 'surname', 'nachname', 'nom', 'apellido', 'apellidos', 'achternaam' ) ),
			'name'       => array( __( 'Name', 'osam-bookings' ), array( 'name', 'full_name', 'contact_name', 'display_name' ) ),
			'email'      => array( __( 'Email', 'osam-bookings' ), array( 'email', 'email_1', 'e_mail', 'e_mail_1', 'email_address', 'primary_email', 'correo', 'correo_electronico', 'courriel' ) ),
			'phone'      => array( __( 'Phone', 'osam-bookings' ), array( 'phone', 'phone_1', 'telefon', 'telefon_1', 'telefono', 'telephone', 'mobile', 'primary_phone' ) ),
			'labels'     => array( __( 'Labels', 'osam-bookings' ), array( 'labels', 'label', 'tags', 'etiketten', 'etiquetas', 'etiquettes' ) ),
			'notes'      => array( __( 'Notes', 'osam-bookings' ), array( 'notes', 'note', 'notizen', 'notas', 'remarques' ) ),
		);
	}

	/**
	 * Header cell → comparable key.
	 *
	 * @param string $h Header.
	 * @return string
	 */
	public static function header_key( $h ) {
		$h = remove_accents( (string) $h );
		$h = preg_replace( '/\([^)]*\)/', '', $h );
		$h = str_replace( '&', ' and ', $h );
		$h = strtolower( trim( $h ) );
		return trim( preg_replace( '/[^a-z0-9]+/', '_', $h ), '_' );
	}

	/**
	 * Read a CSV file into rows.
	 *
	 * @param string $path Path.
	 * @param array  $cols Column definitions.
	 * @return array|WP_Error [ rows, recognised [ heading => label ], ignored [] ]
	 */
	public static function read_csv( $path, array $cols ) {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw || '' === trim( $raw ) ) {
			return new WP_Error( 'osb_wix_empty', __( 'The file is empty.', 'osam-bookings' ) );
		}
		if ( 0 === strpos( $raw, "\xFF\xFE" ) || 0 === strpos( $raw, "\xFE\xFF" ) ) {
			$raw = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $raw, 'UTF-8', 'UTF-16' ) : $raw;
		}
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		if ( ! preg_match( '//u', $raw ) ) {
			$raw = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' ) : $raw;
		}
		$first = (string) strtok( $raw, "\n" );
		$delim = ',';
		foreach ( array( ';', "\t" ) as $d ) {
			if ( substr_count( $first, $d ) > substr_count( $first, $delim ) ) {
				$delim = $d;
			}
		}
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $fh, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $fh );
		$header = fgetcsv( $fh, 0, $delim, '"', '\\' );
		if ( ! $header ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'osb_wix_header', __( 'Couldn’t read the first row of the file.', 'osam-bookings' ) );
		}
		$alias = array();
		foreach ( $cols as $k => $c ) {
			foreach ( $c[1] as $a ) {
				if ( ! isset( $alias[ $a ] ) ) {
					$alias[ $a ] = $k;
				}
			}
		}
		$keys       = array();
		$recognised = array();
		$ignored    = array();
		foreach ( $header as $i => $h ) {
			$k = self::header_key( $h );
			if ( isset( $alias[ $k ] ) && ! in_array( $alias[ $k ], $keys, true ) ) {
				$keys[ $i ]                     = $alias[ $k ];
				$recognised[ trim( (string) $h ) ] = $cols[ $alias[ $k ] ][0];
			} elseif ( '' !== trim( (string) $h ) ) {
				$ignored[] = trim( (string) $h );
			}
		}
		$rows = array();
		$line = 1;
		while ( ( $cells = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;
			if ( ! array_filter( array_map( 'trim', array_map( 'strval', $cells ) ), 'strlen' ) ) {
				continue;
			}
			$row = array( '_line' => $line );
			foreach ( $keys as $i => $k ) {
				$row[ $k ] = isset( $cells[ $i ] ) ? trim( (string) $cells[ $i ] ) : '';
			}
			$rows[] = $row;
			if ( count( $rows ) > self::MAX_ROWS ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				/* translators: %d rows */
				return new WP_Error( 'osb_wix_big', sprintf( __( 'The file has more than %d rows. Export a shorter date range and import it in parts — running the import again never makes duplicates.', 'osam-bookings' ), self::MAX_ROWS ) );
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array(
			'rows'       => $rows,
			'recognised' => $recognised,
			'ignored'    => $ignored,
		);
	}

	/**
	 * Read a date + time written in any of the usual ways.
	 *
	 * @param string $v     Value.
	 * @param string $order dmy|mdy for dates like 03/04/2026.
	 * @return string Local "Y-m-d H:i:s", "Y-m-d" when there is no time, or ''.
	 */
	public static function parse_when( $v, $order = 'dmy' ) {
		$v = trim( preg_replace( '/\s+/u', ' ', (string) $v ) );
		if ( '' === $v ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $v ) ) {
			return OSB_Migrate::iso_to_local( str_replace( ' ', 'T', substr( $v, 0, 10 ) ) . substr( $v, 10 ) );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
			return $v;
		}
		$time = '';
		if ( preg_match( '#(?<![\d./-])(\d{1,2})[:.](\d{2})(?![\d./-])(?::\d{2})?\s*([ap]\.?\s?m\.?)?#i', $v, $tm, PREG_OFFSET_CAPTURE ) ) {
			$time = OSB_Import::time( $tm[1][0] . ':' . $tm[2][0] . ( ! empty( $tm[3][0] ) ? str_replace( array( '.', ' ' ), '', $tm[3][0] ) : '' ) );
			$v    = trim( substr( $v, 0, $tm[0][1] ), " ,-@" );
		} elseif ( preg_match( '/\b(\d{1,2})\s*([ap]\.?m\.?)/i', $v, $tm, PREG_OFFSET_CAPTURE ) ) {
			$time = OSB_Import::time( $tm[1][0] . str_replace( '.', '', $tm[2][0] ) );
			$v    = trim( substr( $v, 0, $tm[0][1] ), " ,-@" );
		}
		$date = '';
		if ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})#', $v, $m ) ) {
			list( , $a, $b, $y ) = $m;
			$y                   = 2 === strlen( $y ) ? '20' . $y : $y;
			list( $d, $mo )      = 'mdy' === $order ? array( $b, $a ) : array( $a, $b );
			$date                = checkdate( (int) $mo, (int) $d, (int) $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : '';
		} elseif ( '' !== $v ) {
			$clean = preg_replace( '/^[A-Za-z]+,\s*/', '', $v ); // "Tuesday, …".
			$ts    = strtotime( $clean . ' 12:00:00 UTC' );
			$date  = $ts ? gmdate( 'Y-m-d', $ts ) : '';
		}
		if ( '' === $date ) {
			return '';
		}
		return '' !== $time ? $date . ' ' . $time . ':00' : $date;
	}

	/**
	 * Guess whether 03/04/2026 means 3 April or 4 March, from every date in the file.
	 *
	 * @param array $values Date strings.
	 * @return string dmy|mdy|'' (can't tell)
	 */
	private static function guess_order( array $values ) {
		$dmy = false;
		$mdy = false;
		foreach ( $values as $v ) {
			if ( preg_match( '#(?:^|\D)(\d{1,2})[/.\-](\d{1,2})[/.\-]\d{2,4}#', (string) $v, $m ) ) {
				if ( (int) $m[1] > 12 ) {
					$dmy = true;
				}
				if ( (int) $m[2] > 12 ) {
					$mdy = true;
				}
			}
		}
		if ( $dmy && ! $mdy ) {
			return 'dmy';
		}
		if ( $mdy && ! $dmy ) {
			return 'mdy';
		}
		return '';
	}

	/**
	 * Booking status words → ours.
	 *
	 * @param string $s Text.
	 * @return array [ status, skip reason ]
	 */
	private static function status_word( $s ) {
		$s = strtolower( remove_accents( trim( (string) $s ) ) );
		if ( '' === $s ) {
			return array( 'confirmed', '' );
		}
		if ( preg_match( '/wait|warte|espera|attente|wacht/', $s ) ) {
			return array( '', 'waiting' );
		}
		if ( preg_match( '/cancel|declin|reject|storn|abgesagt|cancelad|annul|geannuleerd/', $s ) ) {
			return array( 'cancelled', '' );
		}
		if ( preg_match( '/no.?show|absent|nicht erschienen/', $s ) ) {
			return array( 'no_show', '' );
		}
		if ( preg_match( '/pend|await|request|ausstehend|pendiente|en attente|in afwachting/', $s ) ) {
			return array( 'pending', '' );
		}
		return array( 'confirmed', '' );
	}

	/**
	 * Payment status words → ours ('' = unknown).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function pay_word( $s ) {
		$s = strtolower( remove_accents( trim( (string) $s ) ) );
		if ( '' === $s ) {
			return '';
		}
		if ( preg_match( '/refund|erstattet|reembols|rembours|terugbetaald/', $s ) ) {
			return 'refunded';
		}
		if ( preg_match( '/not|unpaid|partial|teil|nicht|offen|pendiente|no pagado|non paye|partiel|onbetaald|due/', $s ) ) {
			return 'unpaid';
		}
		if ( preg_match( '/free|exempt|kostenlos|gratis|gratuit/', $s ) ) {
			return 'free';
		}
		if ( preg_match( '/paid|bezahlt|pagado|paye|betaald|yes|ja|si|oui/', $s ) ) {
			return 'paid';
		}
		return '';
	}

	/**
	 * Build the dataset from the CSV exports.
	 *
	 * @param string      $bookings_path Booking-list CSV.
	 * @param string|null $contacts_path Contacts CSV.
	 * @param string      $order         auto|dmy|mdy.
	 * @return array|WP_Error
	 */
	public static function dataset_from_csv( $bookings_path, $contacts_path, $order = 'auto' ) {
		$read = self::read_csv( $bookings_path, self::booking_columns() );
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		$has = array();
		foreach ( $read['rows'] as $r ) {
			$has += array_fill_keys( array_keys( $r ), 1 );
		}
		if ( ! isset( $has['service'] ) || ( ! isset( $has['start'] ) && ! isset( $has['date'] ) ) ) {
			return new WP_Error( 'osb_wix_columns', __( 'This doesn’t look like a Wix booking list: we need at least a column for the service and one for the date. Check that you chose Bookings → Booking List → Download CSV.', 'osam-bookings' ) );
		}
		$warn = array();

		$dates = array();
		foreach ( $read['rows'] as $r ) {
			foreach ( array( 'start', 'date', 'end', 'created' ) as $k ) {
				if ( isset( $r[ $k ] ) ) {
					$dates[] = $r[ $k ];
				}
			}
		}
		if ( 'auto' === $order ) {
			$order = self::guess_order( $dates );
			if ( '' === $order ) {
				$order = 0 === strpos( get_locale(), 'en_US' ) ? 'mdy' : 'dmy';
				if ( preg_grep( '#\d{1,2}/\d{1,2}/\d{2,4}#', $dates ) ) {
					$warn[] = 'mdy' === $order
						? __( 'Dates like 03/04 could be read either way; we read them as month/day. If that’s wrong, choose “day/month” and check the file again.', 'osam-bookings' )
						: __( 'Dates like 03/04 could be read either way; we read them as day/month. If that’s wrong, choose “month/day” and check the file again.', 'osam-bookings' );
				}
			}
		}

		$bookings = array();
		$lengths  = array();
		$prices   = array();
		$staff    = array();
		$svc_name = array();
		$svc_who  = array();
		foreach ( $read['rows'] as $r ) {
			$service = isset( $r['service'] ) ? trim( $r['service'] ) : '';
			$when    = self::parse_when( isset( $r['start'] ) ? $r['start'] : '', $order );
			$end     = '';
			// "10:00 AM - 11:00 AM" in one cell.
			if ( isset( $r['start'] ) && preg_match( '/^(.*?)\s+[-–]\s+(\d{1,2}[:.]\d{2}\s*(?:[ap]\.?m\.?)?)\s*$/iu', $r['start'], $m ) ) {
				$when = self::parse_when( $m[1], $order );
				$et   = OSB_Import::time( str_replace( array( '.', ' ' ), array( ':', '' ), preg_replace( '/([ap])\.?m\.?/i', '$1m', $m[2] ) ) );
				if ( $et && 19 === strlen( $when ) ) {
					$end = substr( $when, 0, 11 ) . $et . ':00';
				}
			}
			if ( ( '' === $when || 10 === strlen( $when ) ) && ! empty( $r['date'] ) ) {
				$d    = self::parse_when( $r['date'], $order );
				$t    = ! empty( $r['time'] ) ? OSB_Import::time( preg_replace( '/\s*[-–].*$/', '', $r['time'] ) ) : ( '' !== $when && 10 !== strlen( $when ) ? substr( $when, 11, 5 ) : '' );
				$when = '' !== $d ? substr( $d, 0, 10 ) . ( $t ? ' ' . $t . ':00' : ( 19 === strlen( $d ) ? substr( $d, 10 ) : '' ) ) : $when;
			}
			if ( 10 === strlen( $when ) && ! empty( $r['time'] ) ) {
				$t    = OSB_Import::time( preg_replace( '/\s*[-–].*$/', '', $r['time'] ) );
				$when = $t ? $when . ' ' . $t . ':00' : $when;
			}
			if ( '' === $end && ! empty( $r['end'] ) ) {
				$e = self::parse_when( $r['end'], $order );
				if ( 19 === strlen( $e ) ) {
					$end = $e;
				} elseif ( 19 === strlen( $when ) ) {
					$et  = OSB_Import::time( $r['end'] );
					$end = $et ? substr( $when, 0, 11 ) . $et . ':00' : '';
				}
			}
			if ( '' === $end && 19 === strlen( $when ) && ! empty( $r['duration'] ) && preg_match( '/(\d+)\s*(h|std|hr|hour)?\D*(\d+)?/i', $r['duration'], $dm ) ) {
				$mins = ! empty( $dm[2] ) ? (int) $dm[1] * 60 + ( isset( $dm[3] ) ? (int) $dm[3] : 0 ) : (int) $dm[1];
				$end  = $mins > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( $when ) + $mins * 60 ) : '';
			}
			$start = 19 === strlen( $when ) ? $when : '';
			$seats = ! empty( $r['participants'] ) ? max( 1, (int) $r['participants'] ) : 1;
			$price = isset( $r['price'] ) && '' !== $r['price'] ? OSB_Import::money( $r['price'] ) : null;
			$key   = '' !== $service ? 'name:' . self::header_key( $service ) : '';
			if ( '' !== $key ) {
				$svc_name[ $key ] = $service;
				if ( $start && $end > $start ) {
					$len               = (int) round( ( strtotime( $end ) - strtotime( $start ) ) / 60 );
					$lengths[ $key ][] = $len;
				}
				if ( null !== $price && $price > 0 ) {
					$prices[ $key ][] = round( $price / $seats, 2 );
				}
			}
			$staff_key = '';
			if ( ! empty( $r['staff'] ) ) {
				$staff_key           = 'name:' . self::header_key( $r['staff'] );
				$staff[ $staff_key ] = array(
					'name'        => $r['staff'],
					'email'       => '',
					'phone'       => '',
					'description' => '',
				);
				if ( '' !== $key ) {
					$svc_who[ $key ][ $staff_key ] = '';
				}
			}
			$name = isset( $r['name'] ) ? $r['name'] : '';
			if ( '' === $name ) {
				$name = trim( ( isset( $r['first_name'] ) ? $r['first_name'] : '' ) . ' ' . ( isset( $r['last_name'] ) ? $r['last_name'] : '' ) );
			}
			list( $status, $skip ) = self::status_word( isset( $r['status'] ) ? $r['status'] : '' );
			if ( '' === $key ) {
				$skip = $skip ? $skip : 'item';
			}
			$pay   = self::pay_word( isset( $r['payment'] ) ? $r['payment'] : '' );
			$email = isset( $r['email'] ) ? $r['email'] : '';
			$id    = ! empty( $r['id'] ) ? (string) $r['id'] : 'row:' . md5( strtolower( $service . '|' . $start . '|' . $email . '|' . $name ) );
			$note  = array();
			if ( ! empty( $r['location'] ) ) {
				/* translators: %s location */
				$note[] = sprintf( __( 'Location: %s', 'osam-bookings' ), $r['location'] );
			}
			$bookings[] = array(
				'id'            => $id,
				'kind'          => 'service',
				'item'          => $key,
				'staff'         => $staff_key,
				'start'         => $start,
				'end'           => $end,
				'seats'         => $seats,
				'name'          => $name,
				'email'         => $email,
				'phone'         => isset( $r['phone'] ) ? $r['phone'] : '',
				'status'        => $status,
				'skip'          => $skip,
				'pay'           => '' !== $pay ? $pay : 'unpaid',
				'method'        => 'onsite',
				'amount'        => null !== $price ? $price : -1,
				'currency'      => isset( $r['currency'] ) && preg_match( '/^[A-Za-z]{3}$/', $r['currency'] ) ? $r['currency'] : '',
				'note'          => implode( "\n", $note ),
				'customer_note' => isset( $r['notes'] ) ? $r['notes'] : '',
				'created'       => ! empty( $r['created'] ) ? self::parse_when( $r['created'], $order ) : '',
			);
		}

		$services = array();
		foreach ( $svc_name as $key => $name ) {
			$services[ $key ] = array(
				'name'          => $name,
				'description'   => '',
				'category'      => '',
				'duration'      => ! empty( $lengths[ $key ] ) ? self::most_common( $lengths[ $key ] ) : 60,
				'price'         => ! empty( $prices[ $key ] ) ? self::most_common( $prices[ $key ] ) : 0,
				'capacity'      => 1,
				'buffer_before' => 0,
				'buffer_after'  => 0,
				'color'         => '#0273b0',
				'status'        => 'active',
				'staff'         => isset( $svc_who[ $key ] ) ? $svc_who[ $key ] : array(),
			);
			if ( empty( $lengths[ $key ] ) ) {
				/* translators: %s service */
				$warn[] = sprintf( __( 'We couldn’t tell how long “%s” takes, so it is set to 60 minutes. Change it afterwards if needed.', 'osam-bookings' ), $name );
			}
		}
		// Bookings with no price column keep the service price; with a blank price, the price of the service.
		foreach ( $bookings as &$b ) {
			if ( $b['amount'] < 0 ) {
				$b['amount'] = isset( $services[ $b['item'] ] ) ? round( $services[ $b['item'] ]['price'] * $b['seats'], 2 ) : 0;
			}
			if ( '' === $b['end'] && '' !== $b['start'] && isset( $services[ $b['item'] ] ) ) {
				$b['end'] = gmdate( 'Y-m-d H:i:s', strtotime( $b['start'] ) + $services[ $b['item'] ]['duration'] * 60 );
			}
		}
		unset( $b );
		if ( ! isset( $has['payment'] ) ) {
			$warn[] = __( 'The file has no payment column, so every booking with a price comes across as unpaid.', 'osam-bookings' );
		}

		$columns = array(
			array(
				'file'       => __( 'Booking list', 'osam-bookings' ),
				'recognised' => $read['recognised'],
				'ignored'    => $read['ignored'],
			),
		);
		$customers = array();
		if ( $contacts_path ) {
			$c = self::read_csv( $contacts_path, self::contact_columns() );
			if ( is_wp_error( $c ) ) {
				/* translators: %s problem */
				$warn[] = sprintf( __( 'The contacts file couldn’t be read (%s), so only the booking list was used.', 'osam-bookings' ), $c->get_error_message() );
			} else {
				foreach ( $c['rows'] as $r ) {
					$name        = ! empty( $r['name'] ) ? $r['name'] : trim( ( isset( $r['first_name'] ) ? $r['first_name'] : '' ) . ' ' . ( isset( $r['last_name'] ) ? $r['last_name'] : '' ) );
					$customers[] = array(
						'name'  => $name,
						'email' => isset( $r['email'] ) ? $r['email'] : '',
						'phone' => isset( $r['phone'] ) ? $r['phone'] : '',
						'notes' => isset( $r['notes'] ) ? $r['notes'] : '',
						'tags'  => trim( 'Wix, ' . ( isset( $r['labels'] ) ? str_replace( ';', ',', $r['labels'] ) : '' ), ', ' ),
					);
				}
				$columns[] = array(
					'file'       => __( 'Contacts', 'osam-bookings' ),
					'recognised' => $c['recognised'],
					'ignored'    => $c['ignored'],
				);
			}
		}

		return array(
			'source'    => 'wix',
			'route'     => 'csv',
			'staff'     => $staff,
			'services'  => $services,
			'events'    => array(),
			'customers' => $customers,
			'bookings'  => $bookings,
			'emails'    => array(),
			'warnings'  => $warn,
			'columns'   => $columns,
		);
	}

	/**
	 * Most frequent value.
	 *
	 * @param array $values Numbers.
	 * @return float|int
	 */
	private static function most_common( array $values ) {
		$n = array();
		foreach ( $values as $v ) {
			$k       = (string) $v;
			$n[ $k ] = isset( $n[ $k ] ) ? $n[ $k ] + 1 : 1;
		}
		arsort( $n );
		$k = (string) key( $n );
		return false !== strpos( $k, '.' ) ? (float) $k : (int) $k;
	}

	/* ---------------------------------------------------------------------
	 * REST API
	 * ------------------------------------------------------------------ */

	/**
	 * Option holding the current user's fetch.
	 *
	 * @return string
	 */
	private static function fetch_key() {
		return 'osb_migrate_wixfetch_' . get_current_user_id();
	}

	/**
	 * Encrypt the key for the few minutes it is kept.
	 *
	 * @param string $plain Plain.
	 * @return string
	 */
	private static function seal( $plain ) {
		return method_exists( 'OSB_Calendar_Sync', 'seal' ) ? OSB_Calendar_Sync::seal( $plain ) : base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt.
	 *
	 * @param string $stored Stored.
	 * @return string
	 */
	private static function unseal( $stored ) {
		return method_exists( 'OSB_Calendar_Sync', 'unseal' ) ? (string) OSB_Calendar_Sync::unseal( $stored ) : (string) base64_decode( $stored ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Start fetching from Wix.
	 *
	 * @param string $key     API key.
	 * @param string $site_id Site ID.
	 * @return true|WP_Error
	 */
	public static function fetch_start( $key, $site_id ) {
		$key     = trim( (string) $key );
		$site_id = trim( (string) $site_id );
		if ( '' === $key || ! preg_match( '/^[0-9a-f-]{20,40}$/i', $site_id ) ) {
			return new WP_Error( 'osb_wix_creds', __( 'Please enter both the API key and the site ID. The site ID looks like 1a2b3c4d-…; you’ll find it in your Wix dashboard’s web address, after /dashboard/.', 'osam-bookings' ) );
		}
		self::fetch_discard();
		update_option(
			self::fetch_key(),
			array(
				'key'      => self::seal( $key ),
				'site'     => $site_id,
				'time'     => time(),
				'phase'    => 'services',
				'offset'   => 0,
				'cursor'   => '',
				'services' => array(),
				'raw'      => array(),
				'contacts' => array(),
				'paths'    => array(),
			),
			false
		);
		return true;
	}

	/**
	 * Throw a fetch (and its key) away.
	 */
	public static function fetch_discard() {
		delete_option( self::fetch_key() );
	}

	/**
	 * Is a fetch running?
	 *
	 * @return array|null
	 */
	public static function fetch_state() {
		$s = get_option( self::fetch_key(), null );
		if ( ! is_array( $s ) ) {
			return null;
		}
		if ( (int) $s['time'] < time() - HOUR_IN_SECONDS ) {
			self::fetch_discard();
			return null;
		}
		return $s;
	}

	/**
	 * One API call.
	 *
	 * @param array  $s     Fetch state (paths remembered).
	 * @param string $which services|bookings|contacts.
	 * @param array  $body  JSON body.
	 * @return array|WP_Error
	 */
	private static function call( array &$s, $which, array $body ) {
		$paths = array(
			'services' => array( '/bookings/v2/services/query', '/_api/bookings/v2/services/query' ),
			'bookings' => array( '/bookings/bookings-reader/v2/extended-bookings/query', '/_api/bookings-reader/v2/extended-bookings/query' ),
			'contacts' => array( '/contacts/v4/contacts/query' ),
		);
		$try   = isset( $s['paths'][ $which ] ) ? array( $s['paths'][ $which ] ) : $paths[ $which ];
		$last  = null;
		foreach ( $try as $path ) {
			$res = wp_remote_post(
				self::API . $path,
				array(
					'timeout' => 25,
					'headers' => array(
						'Authorization' => self::unseal( $s['key'] ),
						'wix-site-id'   => $s['site'],
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
				)
			);
			if ( is_wp_error( $res ) ) {
				/* translators: %s error */
				return new WP_Error( 'osb_wix_http', sprintf( __( 'Couldn’t reach Wix: %s', 'osam-bookings' ), $res->get_error_message() ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( 404 === $code ) {
				$last = $code;
				continue;
			}
			if ( 401 === $code || 403 === $code ) {
				return new WP_Error( 'osb_wix_auth', __( 'Wix didn’t accept the API key for this site. Check the key and site ID, and that the key has permission for Wix Bookings and Contacts.', 'osam-bookings' ) );
			}
			if ( $code >= 400 || ! is_array( $data ) ) {
				$msg = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'HTTP ' . $code;
				/* translators: 1: what 2: message */
				return new WP_Error( 'osb_wix_api', sprintf( __( 'Wix returned an error while reading %1$s: %2$s', 'osam-bookings' ), $which, $msg ) );
			}
			$s['paths'][ $which ] = $path;
			return $data;
		}
		/* translators: %s what */
		return new WP_Error( 'osb_wix_404', sprintf( __( 'Wix didn’t recognise the request for %s (HTTP %d). Wix may have changed its API — please let us know.', 'osam-bookings' ), $which, (int) $last ) );
	}

	/**
	 * Fetch for a few seconds.
	 *
	 * @param int $seconds Budget.
	 * @return array|WP_Error [ finished, message ]
	 */
	public static function fetch_step( $seconds = 8 ) {
		$s = self::fetch_state();
		if ( ! $s ) {
			return new WP_Error( 'osb_wix_gone', __( 'That connection has expired. Please enter the API key again.', 'osam-bookings' ) );
		}
		$stop = microtime( true ) + $seconds;
		while ( microtime( true ) < $stop && 'done' !== $s['phase'] ) {
			if ( 'services' === $s['phase'] ) {
				$data = self::call( $s, 'services', array( 'query' => array( 'paging' => array( 'limit' => 100, 'offset' => (int) $s['offset'] ) ) ) );
				if ( is_wp_error( $data ) ) {
					self::fetch_discard();
					return $data;
				}
				$list = isset( $data['services'] ) && is_array( $data['services'] ) ? $data['services'] : array();
				foreach ( $list as $sv ) {
					$s['services'][] = self::slim_service( $sv );
				}
				$s['offset'] += count( $list );
				$total        = isset( $data['pagingMetadata']['total'] ) ? (int) $data['pagingMetadata']['total'] : 0;
				if ( count( $list ) < 100 || ( $total && $s['offset'] >= $total ) || $s['offset'] >= 5000 ) {
					$s['phase']  = 'bookings';
					$s['offset'] = 0;
				}
			} elseif ( 'bookings' === $s['phase'] ) {
				$q    = '' !== $s['cursor'] ? array( 'cursorPaging' => array( 'limit' => 100, 'cursor' => $s['cursor'] ) ) : array( 'cursorPaging' => array( 'limit' => 100 ) );
				$data = self::call( $s, 'bookings', array( 'query' => $q ) );
				if ( is_wp_error( $data ) ) {
					self::fetch_discard();
					return $data;
				}
				$list = isset( $data['extendedBookings'] ) && is_array( $data['extendedBookings'] ) ? $data['extendedBookings'] : array();
				foreach ( $list as $eb ) {
					if ( isset( $eb['booking'] ) && is_array( $eb['booking'] ) ) {
						$s['raw'][] = self::slim_booking( $eb['booking'] );
					}
				}
				$next        = isset( $data['pagingMetadata']['cursors']['next'] ) ? (string) $data['pagingMetadata']['cursors']['next'] : '';
				$s['cursor'] = $next;
				if ( '' === $next || ! $list || count( $s['raw'] ) >= self::MAX_ROWS ) {
					$s['phase']  = 'contacts';
					$s['offset'] = 0;
				}
			} else {
				$data = self::call( $s, 'contacts', array( 'query' => array( 'paging' => array( 'limit' => 500, 'offset' => (int) $s['offset'] ), 'fieldsets' => array( 'FULL' ) ) ) );
				if ( is_wp_error( $data ) ) {
					// Contacts are a bonus: keep going without them.
					$s['contacts_error'] = $data->get_error_message();
					$s['phase']          = 'done';
					break;
				}
				$list = isset( $data['contacts'] ) && is_array( $data['contacts'] ) ? $data['contacts'] : array();
				foreach ( $list as $c ) {
					$s['contacts'][] = self::slim_contact( $c );
				}
				$s['offset'] += count( $list );
				$total        = isset( $data['pagingMetadata']['total'] ) ? (int) $data['pagingMetadata']['total'] : 0;
				if ( count( $list ) < 500 || ( $total && $s['offset'] >= $total ) || $s['offset'] >= self::MAX_ROWS ) {
					$s['phase'] = 'done';
				}
			}
		}
		if ( 'done' === $s['phase'] ) {
			self::fetch_discard(); // The key is gone from here on.
			return array( true, self::dataset_from_api( $s ) );
		}
		$s['time'] = time();
		update_option( self::fetch_key(), $s, false );
		/* translators: 1: services 2: bookings 3: contacts */
		return array( false, sprintf( __( 'Read so far: %1$d services, %2$d bookings, %3$d contacts…', 'osam-bookings' ), count( $s['services'] ), count( $s['raw'] ), count( $s['contacts'] ) ) );
	}

	/**
	 * Keep only what is needed of a service.
	 *
	 * @param array $sv Service.
	 * @return array
	 */
	private static function slim_service( array $sv ) {
		$dur = 0;
		if ( ! empty( $sv['schedule']['availabilityConstraints']['sessionDurations'][0] ) ) {
			$dur = (int) $sv['schedule']['availabilityConstraints']['sessionDurations'][0];
		} elseif ( ! empty( $sv['schedule']['availabilityConstraints']['durations'][0]['minutes'] ) ) {
			$dur = (int) $sv['schedule']['availabilityConstraints']['durations'][0]['minutes'];
		}
		$pay   = isset( $sv['payment'] ) && is_array( $sv['payment'] ) ? $sv['payment'] : array();
		$price = 0;
		$cur   = '';
		foreach ( array( array( 'fixed', 'price' ), array( 'varied', 'defaultPrice' ) ) as $p ) {
			if ( isset( $pay[ $p[0] ][ $p[1] ]['value'] ) ) {
				$price = (float) $pay[ $p[0] ][ $p[1] ]['value'];
				$cur   = isset( $pay[ $p[0] ][ $p[1] ]['currency'] ) ? (string) $pay[ $p[0] ][ $p[1] ]['currency'] : '';
				break;
			}
		}
		return array(
			'id'       => (string) ( isset( $sv['id'] ) ? $sv['id'] : '' ),
			'name'     => (string) ( isset( $sv['name'] ) ? $sv['name'] : '' ),
			'type'     => (string) ( isset( $sv['type'] ) ? $sv['type'] : 'APPOINTMENT' ),
			'desc'     => (string) ( isset( $sv['description'] ) ? $sv['description'] : ( isset( $sv['tagLine'] ) ? $sv['tagLine'] : '' ) ),
			'category' => (string) ( isset( $sv['category']['name'] ) ? $sv['category']['name'] : '' ),
			'capacity' => (int) ( isset( $sv['defaultCapacity'] ) ? $sv['defaultCapacity'] : 1 ),
			'hidden'   => ! empty( $sv['hidden'] ),
			'duration' => $dur,
			'buffer'   => (int) ( isset( $sv['schedule']['availabilityConstraints']['timeBetweenSessions'] ) ? $sv['schedule']['availabilityConstraints']['timeBetweenSessions'] : 0 ),
			'price'    => $price,
			'currency' => $cur,
			'rate'     => (string) ( isset( $pay['rateType'] ) ? $pay['rateType'] : '' ),
			'staff'    => isset( $sv['staffMemberIds'] ) && is_array( $sv['staffMemberIds'] ) ? array_map( 'strval', $sv['staffMemberIds'] ) : array(),
		);
	}

	/**
	 * Keep only what is needed of a booking.
	 *
	 * @param array $b Booking.
	 * @return array
	 */
	private static function slim_booking( array $b ) {
		$ent  = isset( $b['bookedEntity'] ) && is_array( $b['bookedEntity'] ) ? $b['bookedEntity'] : array();
		$slot = isset( $ent['slot'] ) && is_array( $ent['slot'] ) ? $ent['slot'] : array();
		$sch  = isset( $ent['schedule'] ) && is_array( $ent['schedule'] ) ? $ent['schedule'] : array();
		$cd   = isset( $b['contactDetails'] ) && is_array( $b['contactDetails'] ) ? $b['contactDetails'] : array();
		$n    = 0;
		if ( isset( $b['totalParticipants'] ) ) {
			$n = (int) $b['totalParticipants'];
		} elseif ( ! empty( $b['participantsChoices']['serviceChoices'] ) ) {
			foreach ( (array) $b['participantsChoices']['serviceChoices'] as $ch ) {
				$n += (int) ( isset( $ch['numberOfParticipants'] ) ? $ch['numberOfParticipants'] : 0 );
			}
		}
		$tags = isset( $ent['tags'] ) ? (array) $ent['tags'] : array();
		return array(
			'id'       => (string) ( isset( $b['id'] ) ? $b['id'] : '' ),
			'service'  => (string) ( isset( $slot['serviceId'] ) ? $slot['serviceId'] : ( isset( $sch['serviceId'] ) ? $sch['serviceId'] : '' ) ),
			'course'   => ! $slot || in_array( 'COURSE', $tags, true ),
			'title'    => (string) ( isset( $ent['title'] ) ? $ent['title'] : '' ),
			'start'    => (string) ( isset( $b['startDate'] ) ? $b['startDate'] : ( isset( $slot['startDate'] ) ? $slot['startDate'] : '' ) ),
			'end'      => (string) ( isset( $b['endDate'] ) ? $b['endDate'] : ( isset( $slot['endDate'] ) ? $slot['endDate'] : '' ) ),
			'tz'       => (string) ( isset( $slot['timezone'] ) ? $slot['timezone'] : '' ),
			'res'      => isset( $slot['resource'] ) && is_array( $slot['resource'] ) ? array(
				'id'    => (string) ( isset( $slot['resource']['id'] ) ? $slot['resource']['id'] : '' ),
				'name'  => (string) ( isset( $slot['resource']['name'] ) ? $slot['resource']['name'] : '' ),
				'email' => (string) ( isset( $slot['resource']['email'] ) ? $slot['resource']['email'] : '' ),
			) : null,
			'name'     => trim( ( isset( $cd['firstName'] ) ? $cd['firstName'] : '' ) . ' ' . ( isset( $cd['lastName'] ) ? $cd['lastName'] : '' ) ),
			'email'    => (string) ( isset( $cd['email'] ) ? $cd['email'] : '' ),
			'phone'    => (string) ( isset( $cd['phone'] ) ? $cd['phone'] : '' ),
			'status'   => (string) ( isset( $b['status'] ) ? $b['status'] : '' ),
			'pay'      => (string) ( isset( $b['paymentStatus'] ) ? $b['paymentStatus'] : '' ),
			'seats'    => max( 1, $n ),
			'created'  => (string) ( isset( $b['createdDate'] ) ? $b['createdDate'] : '' ),
		);
	}

	/**
	 * Keep only what is needed of a contact.
	 *
	 * @param array $c Contact.
	 * @return array
	 */
	private static function slim_contact( array $c ) {
		$info  = isset( $c['info'] ) && is_array( $c['info'] ) ? $c['info'] : array();
		$email = isset( $c['primaryInfo']['email'] ) ? (string) $c['primaryInfo']['email'] : ( isset( $info['emails']['items'][0]['email'] ) ? (string) $info['emails']['items'][0]['email'] : '' );
		$phone = isset( $c['primaryInfo']['phone'] ) ? (string) $c['primaryInfo']['phone'] : ( isset( $info['phones']['items'][0]['phone'] ) ? (string) $info['phones']['items'][0]['phone'] : '' );
		return array(
			'name'  => trim( ( isset( $info['name']['first'] ) ? $info['name']['first'] : '' ) . ' ' . ( isset( $info['name']['last'] ) ? $info['name']['last'] : '' ) ),
			'email' => $email,
			'phone' => $phone,
		);
	}

	/**
	 * Build the dataset from what was fetched.
	 *
	 * @param array $s Fetch state.
	 * @return array
	 */
	public static function dataset_from_api( array $s ) {
		$warn     = array();
		$services = array();
		$types    = array();
		$currency = '';
		$rates    = 0;
		foreach ( $s['services'] as $sv ) {
			$types[ $sv['id'] ] = $sv['type'];
			if ( 'COURSE' === $sv['type'] ) {
				continue; // Courses run over several dates: see the reasons list.
			}
			$currency = $currency ? $currency : $sv['currency'];
			if ( in_array( $sv['rate'], array( 'CUSTOM', 'VARIED' ), true ) ) {
				++$rates;
			}
			$services[ $sv['id'] ] = array(
				'name'          => '' !== $sv['name'] ? $sv['name'] : __( 'Service', 'osam-bookings' ),
				'description'   => $sv['desc'],
				'category'      => $sv['category'],
				'duration'      => $sv['duration'] > 0 ? $sv['duration'] : 60,
				'price'         => $sv['price'],
				'capacity'      => 'CLASS' === $sv['type'] ? max( 1, $sv['capacity'] ) : 1,
				'buffer_before' => 0,
				'buffer_after'  => $sv['buffer'],
				'color'         => '#0273b0',
				'status'        => $sv['hidden'] ? 'hidden' : 'active',
				'staff'         => array_fill_keys( $sv['staff'], '' ),
			);
		}
		if ( $rates ) {
			/* translators: %d services */
			$warn[] = sprintf( _n( '%d Wix service has a custom or varied price. It comes across with its default price (or free) — check it afterwards.', '%d Wix services have custom or varied prices. They come across with their default price (or free) — check them afterwards.', $rates, 'osam-bookings' ), $rates );
		}
		if ( $currency && strtoupper( $currency ) !== strtoupper( (string) OSB_Settings::get( 'currency' ) ) ) {
			/* translators: 1: Wix currency 2: this site's currency */
			$warn[] = sprintf( __( 'Your Wix prices are in %1$s but this site is set to %2$s. Check the currency in Settings → Payments.', 'osam-bookings' ), $currency, OSB_Settings::get( 'currency' ) );
		}

		$staff    = array();
		$bookings = array();
		foreach ( $s['raw'] as $b ) {
			$skip   = '';
			$status = 'confirmed';
			switch ( strtoupper( $b['status'] ) ) {
				case 'PENDING':
					$status = 'pending';
					break;
				case 'CANCELED':
				case 'CANCELLED':
				case 'DECLINED':
					$status = 'cancelled';
					break;
				case 'WAITING_LIST':
					$skip = 'waiting';
					break;
				case 'CREATED':
					$skip = 'incomplete';
					break;
			}
			if ( $b['course'] || ( isset( $types[ $b['service'] ] ) && 'COURSE' === $types[ $b['service'] ] ) ) {
				$skip = $skip ? $skip : 'course';
			}
			$pay = array(
				'PAID'     => 'paid',
				'REFUNDED' => 'refunded',
				'EXEMPT'   => 'free',
			);
			$pay = isset( $pay[ strtoupper( $b['pay'] ) ] ) ? $pay[ strtoupper( $b['pay'] ) ] : 'unpaid';
			$sid = '';
			if ( $b['res'] && '' !== $b['res']['id'] ) {
				$sid           = $b['res']['id'];
				$staff[ $sid ] = array(
					'name'        => '' !== $b['res']['name'] ? $b['res']['name'] : __( 'Staff member', 'osam-bookings' ),
					'email'       => $b['res']['email'],
					'phone'       => '',
					'description' => '',
				);
			}
			$svc        = isset( $services[ $b['service'] ] ) ? $services[ $b['service'] ] : null;
			$bookings[] = array(
				'id'            => $b['id'],
				'kind'          => 'service',
				'item'          => $b['service'],
				'staff'         => $sid,
				'start'         => OSB_Migrate::iso_to_local( $b['start'], $b['tz'] ),
				'end'           => OSB_Migrate::iso_to_local( $b['end'], $b['tz'] ),
				'seats'         => $b['seats'],
				'name'          => $b['name'],
				'email'         => $b['email'],
				'phone'         => $b['phone'],
				'status'        => $status,
				'skip'          => $skip,
				'pay'           => $pay,
				'method'        => 'onsite',
				'amount'        => $svc ? round( $svc['price'] * $b['seats'], 2 ) : 0,
				'currency'      => $currency,
				'note'          => '',
				'customer_note' => '',
				'created'       => OSB_Migrate::iso_to_local( $b['created'] ),
			);
		}
		if ( $bookings ) {
			$warn[] = __( 'Wix doesn’t give the price paid for each booking through its API, so each booking’s amount is the service price × number of people. Paid / unpaid comes from Wix.', 'osam-bookings' );
		}
		// staffMemberIds on services are resource ids — the same ids the bookings name, so they only link up when a booking shows that person.
		foreach ( $services as &$sv ) {
			$sv['staff'] = array_intersect_key( $sv['staff'], $staff );
		}
		unset( $sv );

		$customers = array();
		foreach ( $s['contacts'] as $c ) {
			$customers[] = array(
				'name'  => $c['name'],
				'email' => $c['email'],
				'phone' => $c['phone'],
				'notes' => '',
				'tags'  => 'Wix',
			);
		}
		if ( ! empty( $s['contacts_error'] ) ) {
			/* translators: %s problem */
			$warn[] = sprintf( __( 'Your Wix contacts couldn’t be read (%s). Bookings still come across with each customer’s details.', 'osam-bookings' ), $s['contacts_error'] );
		}
		return array(
			'source'    => 'wix',
			'route'     => 'api',
			'staff'     => $staff,
			'services'  => $services,
			'events'    => array(),
			'customers' => $customers,
			'bookings'  => $bookings,
			'emails'    => array(),
			'warnings'  => $warn,
		);
	}
}
