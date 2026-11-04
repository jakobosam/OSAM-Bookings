<?php
/**
 * CSV import and export of services, events and courses.
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads a spreadsheet into a plan (what would be created / updated, with problems per line),
 * then carries the plan out. Exports use the same columns, so a file can be exported, edited
 * in Excel and imported back.
 *
 * Rules:
 * - Rows are matched to existing items by the id column, otherwise by name (events: name + date,
 *   courses: course name, intakes: intake name within the course).
 * - On an update, a blank cell keeps what's there now.
 * - Dates may be YYYY-MM-DD or DD/MM/YYYY (UK order); times 14:00, 2pm or 2:30 pm.
 * - Courses: one row per session. Course and intake details are read from the first row that
 *   has them. An existing intake that already has bookings keeps its sessions.
 */
class OSB_Import {

	const MAX_ROWS = 2000;

	/**
	 * Item types.
	 *
	 * @return array type => label
	 */
	public static function types() {
		$t = array( 'services' => __( 'Services', 'osam-bookings' ) );
		if ( OSB_Settings::feature( 'events' ) ) {
			$t['events'] = __( 'Events', 'osam-bookings' );
		}
		if ( OSB_Settings::feature( 'courses' ) ) {
			$t['courses'] = __( 'Courses', 'osam-bookings' );
		}
		return $t;
	}

	/**
	 * Columns per type.
	 *
	 * @param string $type Type.
	 * @return array key => [ label, required, example, help ]
	 */
	public static function columns( $type ) {
		$common_end = array(
			'description'     => array( __( 'Description', 'osam-bookings' ), false, '', __( 'Text or simple HTML.', 'osam-bookings' ) ),
			'color'           => array( __( 'Colour', 'osam-bookings' ), false, '#0273b0', __( 'Hex colour used in the calendar.', 'osam-bookings' ) ),
			'status'          => array( __( 'Status', 'osam-bookings' ), false, 'active', __( '“active” (bookable online) or “hidden”. Blank = active.', 'osam-bookings' ) ),
			'payment_methods' => array( __( 'Payment methods', 'osam-bookings' ), false, '', sprintf( /* translators: %s ids */ __( 'Blank = every method that’s switched on, or any of: %s (separate with |).', 'osam-bookings' ), implode( ', ', array_keys( osb()->payments->all() ) ) ) ),
		);
		if ( OSB_Settings::feature( 'meetings' ) ) {
			$common_end['online_mode'] = array( __( 'Online', 'osam-bookings' ), false, '', 'courses' === $type ? __( 'Blank (in person), link or zoom.', 'osam-bookings' ) : __( 'Blank (in person), link, zoom, google or teams.', 'osam-bookings' ) );
			$common_end['online_url']  = array( __( 'Meeting link', 'osam-bookings' ), false, '', __( 'Used when Online is “link”.', 'osam-bookings' ) );
		}
		$staff_help = OSB_DB::is_solo() ? __( 'Leave blank in solo mode.', 'osam-bookings' ) : __( 'Staff member’s name, exactly as in Staff & hours.', 'osam-bookings' );
		if ( 'events' === $type ) {
			return array_merge(
				array(
					'id'              => array( 'ID', false, '', __( 'Leave blank for new events. Filled in by Export.', 'osam-bookings' ) ),
					'name'            => array( __( 'Name', 'osam-bookings' ), true, 'Candle making workshop', '' ),
					'date'            => array( __( 'Date', 'osam-bookings' ), true, '2026-11-14', __( 'YYYY-MM-DD or DD/MM/YYYY.', 'osam-bookings' ) ),
					'start_time'      => array( __( 'Start time', 'osam-bookings' ), true, '18:00', '' ),
					'end_time'        => array( __( 'End time', 'osam-bookings' ), false, '20:00', __( 'Blank = one hour.', 'osam-bookings' ) ),
					'end_date'        => array( __( 'End date', 'osam-bookings' ), false, '', __( 'Only for events that run past midnight or over several days.', 'osam-bookings' ) ),
					'location'        => array( __( 'Location', 'osam-bookings' ), false, 'The Studio, High St', '' ),
					'capacity'        => array( __( 'Places', 'osam-bookings' ), false, '12', __( 'Blank = 10.', 'osam-bookings' ) ),
					'max_per_booking' => array( __( 'Max per booking', 'osam-bookings' ), false, '4', '' ),
					'price'           => array( __( 'Price per place', 'osam-bookings' ), false, '35.00', __( '0 or blank = free.', 'osam-bookings' ) ),
					'staff'           => array( __( 'Host', 'osam-bookings' ), false, '', $staff_help ),
				),
				$common_end
			);
		}
		if ( 'courses' === $type ) {
			return array_merge(
				array(
					'course'           => array( __( 'Course', 'osam-bookings' ), true, 'Beginners pottery', __( 'Same name on every row of the course.', 'osam-bookings' ) ),
					'intake'           => array( __( 'Intake', 'osam-bookings' ), true, 'January evenings', __( 'One run of the course. Same name on each of its sessions.', 'osam-bookings' ) ),
					'date'             => array( __( 'Session date', 'osam-bookings' ), true, '2027-01-12', __( 'One row per session.', 'osam-bookings' ) ),
					'start_time'       => array( __( 'Start time', 'osam-bookings' ), true, '19:00', '' ),
					'end_time'         => array( __( 'End time', 'osam-bookings' ), false, '21:00', __( 'Blank = one hour.', 'osam-bookings' ) ),
					'session_location' => array( __( 'Session location', 'osam-bookings' ), false, '', __( 'Only if this session is somewhere other than the course location.', 'osam-bookings' ) ),
					'session_note'     => array( __( 'Session note', 'osam-bookings' ), false, '', '' ),
					'location'         => array( __( 'Course location', 'osam-bookings' ), false, 'The Studio, High St', '' ),
					'price'            => array( __( 'Course fee', 'osam-bookings' ), false, '120.00', __( 'One fee for every session.', 'osam-bookings' ) ),
					'capacity'         => array( __( 'Places', 'osam-bookings' ), false, '8', __( 'Per intake. Blank = 10.', 'osam-bookings' ) ),
					'max_per_booking'  => array( __( 'Max per booking', 'osam-bookings' ), false, '1', '' ),
					'intake_capacity'  => array( __( 'Intake places', 'osam-bookings' ), false, '', __( 'Only if this intake has a different number of places.', 'osam-bookings' ) ),
					'intake_price'     => array( __( 'Intake fee', 'osam-bookings' ), false, '', __( 'Only if this intake has a different fee.', 'osam-bookings' ) ),
					'staff'            => array( __( 'Tutor', 'osam-bookings' ), false, '', $staff_help ),
				),
				$common_end
			);
		}
		return array_merge(
			array(
				'id'            => array( 'ID', false, '', __( 'Leave blank for new services. Filled in by Export.', 'osam-bookings' ) ),
				'name'          => array( __( 'Name', 'osam-bookings' ), true, 'Full body massage', '' ),
				'category'      => array( __( 'Category', 'osam-bookings' ), false, 'Massage', '' ),
				'duration'      => array( __( 'Duration (minutes)', 'osam-bookings' ), true, '60', '' ),
				'price'         => array( __( 'Price', 'osam-bookings' ), false, '45.00', __( '0 or blank = free.', 'osam-bookings' ) ),
				'capacity'      => array( __( 'Places per slot', 'osam-bookings' ), false, '1', __( '1 = one-to-one. More = group class.', 'osam-bookings' ) ),
				'buffer_before' => array( __( 'Buffer before (minutes)', 'osam-bookings' ), false, '0', '' ),
				'buffer_after'  => array( __( 'Buffer after (minutes)', 'osam-bookings' ), false, '15', '' ),
				'staff'         => array( __( 'Staff', 'osam-bookings' ), false, '', OSB_DB::is_solo() ? $staff_help : __( 'Who provides it — names separated by |. Blank = everyone (new) or unchanged (update).', 'osam-bookings' ) ),
			),
			$common_end
		);
	}

	/**
	 * Header aliases → column key.
	 *
	 * @return array
	 */
	private static function aliases() {
		return array(
			'service'        => 'name',
			'event'          => 'name',
			'title'          => 'name',
			'course_name'    => 'course',
			'intake_name'    => 'intake',
			'session_date'   => 'date',
			'start'          => 'start_time',
			'end'            => 'end_time',
			'time'           => 'start_time',
			'length'         => 'duration',
			'minutes'        => 'duration',
			'places'         => 'capacity',
			'spaces'         => 'capacity',
			'places_per_slot' => 'capacity',
			'cost'           => 'price',
			'fee'            => 'price',
			'course_fee'     => 'price',
			'price_per_place' => 'price',
			'colour'         => 'color',
			'host'           => 'staff',
			'tutor'          => 'staff',
			'staff_member'   => 'staff',
			'course_location' => 'location',
			'online'         => 'online_mode',
			'meeting_link'   => 'online_url',
			'intake_places'  => 'intake_capacity',
			'intake_fee'     => 'intake_price',
			'max'            => 'max_per_booking',
		);
	}

	/**
	 * Normalise a header cell to a column key.
	 *
	 * @param string $h Header.
	 * @return string
	 */
	private static function key_for( $h ) {
		$h = strtolower( trim( preg_replace( '/\([^)]*\)/', '', (string) $h ) ) );
		$h = trim( preg_replace( '/[^a-z0-9]+/', '_', $h ), '_' );
		$a = self::aliases();
		return isset( $a[ $h ] ) ? $a[ $h ] : $h;
	}

	/**
	 * Read a CSV file into rows keyed by column.
	 *
	 * @param string $path File.
	 * @param string $type Type.
	 * @return array|WP_Error [ rows: [ line => [ key => value ] ], unknown: [] ]
	 */
	public static function read( $path, $type ) {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw || '' === trim( $raw ) ) {
			return new WP_Error( 'osb_import_empty', __( 'The file is empty.', 'osam-bookings' ) );
		}
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		if ( ! preg_match( '//u', $raw ) ) {
			// Excel on Windows often saves CSV as Windows-1252.
			$raw = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' ) : utf8_encode( $raw ); // phpcs:ignore
		}
		$first = strtok( $raw, "\n" );
		$delim = substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ? ';' : ( substr_count( (string) $first, "\t" ) > substr_count( (string) $first, ',' ) ? "\t" : ',' );
		$fh    = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $fh, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $fh );
		$header = fgetcsv( $fh, 0, $delim, '"', '\\' );
		if ( ! $header ) {
			return new WP_Error( 'osb_import_header', __( 'Couldn’t read the first row of the file.', 'osam-bookings' ) );
		}
		$cols    = self::columns( $type );
		$keys    = array();
		$unknown = array();
		foreach ( $header as $i => $h ) {
			$k = self::key_for( $h );
			if ( isset( $cols[ $k ] ) ) {
				$keys[ $i ] = $k;
			} elseif ( '' !== trim( (string) $h ) ) {
				$unknown[] = trim( (string) $h );
			}
		}
		foreach ( $cols as $k => $c ) {
			if ( $c[1] && ! in_array( $k, $keys, true ) ) {
				/* translators: %s column name */
				return new WP_Error( 'osb_import_missing', sprintf( __( 'The file needs a “%s” column. Download the template to see the expected columns.', 'osam-bookings' ), $c[0] ) );
			}
		}
		$rows = array();
		$line = 1;
		while ( ( $cells = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;
			if ( ! array_filter( array_map( 'trim', array_map( 'strval', $cells ) ), 'strlen' ) ) {
				continue; // Blank line.
			}
			$row = array();
			foreach ( $keys as $i => $k ) {
				$v         = isset( $cells[ $i ] ) ? trim( (string) $cells[ $i ] ) : '';
				$row[ $k ] = preg_match( "/^'[=+\\-@]/", $v ) ? substr( $v, 1 ) : $v; // Undo the formula guard added by Export.
			}
			$rows[ $line ] = $row;
			if ( count( $rows ) > self::MAX_ROWS ) {
				/* translators: %d rows */
				return new WP_Error( 'osb_import_big', sprintf( __( 'The file has more than %d rows. Split it into smaller files.', 'osam-bookings' ), self::MAX_ROWS ) );
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! $rows ) {
			return new WP_Error( 'osb_import_norows', __( 'The file has headings but no rows.', 'osam-bookings' ) );
		}
		return array(
			'rows'    => $rows,
			'unknown' => $unknown,
		);
	}

	/* ---------------------------------------------------------------------
	 * Value parsing
	 * ------------------------------------------------------------------ */

	/**
	 * Date → Y-m-d.
	 *
	 * @param string $v Value.
	 * @return string '' if invalid.
	 */
	public static function date( $v ) {
		$v = trim( $v );
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m ) ) {
			list( , $y, $mo, $d ) = $m;
		} elseif ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})#', $v, $m ) ) {
			list( , $d, $mo, $y ) = $m; // UK order.
			$y = strlen( $y ) === 2 ? '20' . $y : $y;
		} elseif ( strtotime( $v ) ) {
			return gmdate( 'Y-m-d', strtotime( $v ) );
		} else {
			return '';
		}
		return checkdate( (int) $mo, (int) $d, (int) $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : '';
	}

	/**
	 * Time → H:i.
	 *
	 * @param string $v Value.
	 * @return string '' if invalid.
	 */
	public static function time( $v ) {
		$v = strtolower( str_replace( ' ', '', trim( $v ) ) );
		if ( ! preg_match( '/^(\d{1,2})(?:[:.](\d{2}))?(?::\d{2})?(am|pm)?$/', $v, $m ) ) {
			return '';
		}
		$h   = (int) $m[1];
		$min = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
		if ( ! empty( $m[3] ) ) {
			if ( $h < 1 || $h > 12 ) {
				return '';
			}
			$h = 'pm' === $m[3] ? ( 12 === $h ? 12 : $h + 12 ) : ( 12 === $h ? 0 : $h );
		} elseif ( ! isset( $m[2] ) || '' === $m[2] ) {
			return ''; // A bare "14" is too ambiguous.
		}
		return $h <= 23 && $min <= 59 ? sprintf( '%02d:%02d', $h, $min ) : '';
	}

	/**
	 * Money → float (null if invalid).
	 *
	 * @param string $v Value.
	 * @return float|null
	 */
	public static function money( $v ) {
		$v = preg_replace( '/[^0-9.,\-]/', '', (string) $v );
		if ( preg_match( '/^\d{1,3}(\.\d{3})+,\d{1,2}$/', $v ) ) {
			$v = str_replace( array( '.', ',' ), array( '', '.' ), $v ); // 1.234,50
		} else {
			$v = str_replace( ',', '', $v );
		}
		return is_numeric( $v ) && (float) $v >= 0 ? round( (float) $v, 2 ) : null;
	}

	/**
	 * Status word → active|hidden.
	 *
	 * @param string $v Value.
	 * @return string|null
	 */
	private static function status( $v ) {
		$v = strtolower( trim( $v ) );
		if ( in_array( $v, array( 'active', 'yes', 'y', '1', 'on', 'on sale', 'visible', 'live', 'published', 'true' ), true ) ) {
			return 'active';
		}
		if ( in_array( $v, array( 'hidden', 'no', 'n', '0', 'off', 'draft', 'inactive', 'false' ), true ) ) {
			return 'hidden';
		}
		return null;
	}

	/**
	 * Staff name → id.
	 *
	 * @param string $name Name.
	 * @return int 0 if not found.
	 */
	private static function staff_id( $name ) {
		static $map = null;
		if ( null === $map ) {
			$map = array();
			foreach ( OSB_DB::staff() as $st ) {
				$map[ strtolower( trim( $st->name ) ) ] = (int) $st->id;
			}
		}
		$k = strtolower( trim( $name ) );
		return isset( $map[ $k ] ) ? $map[ $k ] : 0;
	}

	/**
	 * Shared optional fields (description, colour, status, payment, online).
	 *
	 * @param array  $row     Row.
	 * @param string $type    Type.
	 * @param array  $data    Data (filled in).
	 * @param array  $errors  Errors (added to).
	 * @param array  $warn    Warnings (added to).
	 */
	private static function common( array $row, $type, array &$data, array &$errors, array &$warn ) {
		if ( isset( $row['description'] ) && '' !== $row['description'] ) {
			$data['description'] = wp_kses_post( $row['description'] );
		}
		if ( isset( $row['color'] ) && '' !== $row['color'] ) {
			$c = sanitize_hex_color( '#' === $row['color'][0] ? $row['color'] : '#' . $row['color'] );
			if ( $c ) {
				$data['color'] = $c;
			} else {
				/* translators: %s value */
				$warn[] = sprintf( __( 'Colour “%s” isn’t a hex colour — ignored.', 'osam-bookings' ), $row['color'] );
			}
		}
		if ( isset( $row['status'] ) && '' !== $row['status'] ) {
			$s = self::status( $row['status'] );
			if ( $s ) {
				$data['status'] = $s;
			} else {
				/* translators: %s value */
				$errors[] = sprintf( __( 'Status “%s” should be active or hidden.', 'osam-bookings' ), $row['status'] );
			}
		}
		if ( isset( $row['payment_methods'] ) && '' !== $row['payment_methods'] ) {
			$ok  = array_keys( osb()->payments->all() );
			$ids = array_filter( array_map( 'sanitize_key', preg_split( '/[|;,]+/', strtolower( $row['payment_methods'] ) ) ) );
			$bad = array_diff( $ids, $ok );
			if ( $bad ) {
				/* translators: 1: values 2: allowed */
				$errors[] = sprintf( __( 'Unknown payment method “%1$s”. Use: %2$s.', 'osam-bookings' ), implode( ', ', $bad ), implode( ', ', $ok ) );
			}
			$data['payment_methods'] = implode( ',', array_intersect( $ids, $ok ) );
		}
		if ( OSB_Settings::feature( 'meetings' ) && ( ( isset( $row['online_mode'] ) && '' !== $row['online_mode'] ) || ( isset( $row['online_url'] ) && '' !== $row['online_url'] ) ) ) {
			$mode = strtolower( trim( isset( $row['online_mode'] ) ? $row['online_mode'] : '' ) );
			$map  = array(
				'in person' => '',
				'none'      => '',
				'no'        => '',
				'fixed'     => 'link',
				'url'       => 'link',
				'meet'      => 'google',
				'google meet' => 'google',
				'microsoft teams' => 'teams',
			);
			$mode = isset( $map[ $mode ] ) ? $map[ $mode ] : $mode;
			if ( '' === $mode && ! empty( $row['online_url'] ) ) {
				$mode = 'link';
			}
			$out = OSB_Meetings::from_post(
				array(
					'online_mode' => $mode,
					'online_url'  => isset( $row['online_url'] ) ? $row['online_url'] : '',
				),
				'courses' === $type ? 'course' : rtrim( $type, 's' )
			);
			if ( '' !== $mode && '' === $out['online_mode'] ) {
				/* translators: %s value */
				$errors[] = sprintf( __( 'Online “%s” isn’t one of the options.', 'osam-bookings' ), $row['online_mode'] );
			}
			if ( 'link' === $out['online_mode'] && '' === $out['online_url'] ) {
				$errors[] = __( 'Online is “link” but there’s no meeting link.', 'osam-bookings' );
			}
			$data = array_merge( $data, $out );
		}
	}

	/**
	 * Number cell.
	 *
	 * @param array  $row    Row.
	 * @param string $key    Column.
	 * @param int    $min    Minimum.
	 * @param array  $data   Data.
	 * @param array  $errors Errors.
	 * @param string $field  Data key (defaults to column).
	 */
	private static function int( array $row, $key, $min, array &$data, array &$errors, $field = '' ) {
		if ( ! isset( $row[ $key ] ) || '' === $row[ $key ] ) {
			return;
		}
		$v = str_replace( ',', '', $row[ $key ] );
		if ( ! preg_match( '/^\d+$/', $v ) || (int) $v < $min ) {
			$cols = self::columns( 'services' ) + self::columns( 'events' ) + self::columns( 'courses' );
			/* translators: 1: column 2: value */
			$errors[] = sprintf( __( '%1$s “%2$s” should be a whole number.', 'osam-bookings' ), isset( $cols[ $key ] ) ? $cols[ $key ][0] : $key, $row[ $key ] );
			return;
		}
		$data[ $field ? $field : $key ] = (int) $v;
	}

	/* ---------------------------------------------------------------------
	 * Planning
	 * ------------------------------------------------------------------ */

	/**
	 * Work out what an import would do.
	 *
	 * @param string $type   Type.
	 * @param array  $rows   Rows from read().
	 * @param bool   $update Update existing items that match.
	 * @return array [ items: [], counts: [ create, update, skip, error ] ]
	 */
	public static function plan( $type, array $rows, $update = true ) {
		if ( 'courses' === $type ) {
			$items = self::plan_courses( $rows, $update );
		} elseif ( 'events' === $type ) {
			$items = self::plan_simple( 'events', $rows, $update );
		} else {
			$items = self::plan_simple( 'services', $rows, $update );
		}
		$counts = array(
			'create' => 0,
			'update' => 0,
			'skip'   => 0,
			'error'  => 0,
		);
		foreach ( $items as $it ) {
			++$counts[ $it['errors'] ? 'error' : $it['action'] ];
		}
		return array(
			'type'   => $type,
			'items'  => $items,
			'counts' => $counts,
		);
	}

	/**
	 * Services and events: one row = one item.
	 *
	 * @param string $type   services|events.
	 * @param array  $rows   Rows.
	 * @param bool   $update Update matches.
	 * @return array
	 */
	private static function plan_simple( $type, array $rows, $update ) {
		global $wpdb;
		$table = OSB_Install::table( $type );
		$items = array();
		$seen  = array();
		foreach ( $rows as $line => $row ) {
			$errors = array();
			$warn   = array();
			$data   = array();
			$name   = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			if ( '' === $name ) {
				$errors[] = __( 'Name is empty.', 'osam-bookings' );
			}
			$data['name'] = $name;
			$existing     = null;
			if ( ! empty( $row['id'] ) ) {
				$existing = OSB_DB::get( $type, absint( $row['id'] ) );
				if ( ! $existing ) {
					/* translators: %s id */
					$errors[] = sprintf( __( 'There’s no item with ID %s. Clear the ID to add it as new.', 'osam-bookings' ), $row['id'] );
				}
			}

			if ( 'events' === $type ) {
				$date  = self::date( isset( $row['date'] ) ? $row['date'] : '' );
				$start = self::time( isset( $row['start_time'] ) ? $row['start_time'] : '' );
				$end   = isset( $row['end_time'] ) && '' !== $row['end_time'] ? self::time( $row['end_time'] ) : '';
				$edate = isset( $row['end_date'] ) && '' !== $row['end_date'] ? self::date( $row['end_date'] ) : $date;
				if ( ! $date ) {
					/* translators: %s value */
					$errors[] = sprintf( __( 'Date “%s” isn’t a date (use YYYY-MM-DD or DD/MM/YYYY).', 'osam-bookings' ), isset( $row['date'] ) ? $row['date'] : '' );
				}
				if ( ! $start ) {
					/* translators: %s value */
					$errors[] = sprintf( __( 'Start time “%s” isn’t a time (e.g. 18:00 or 6pm).', 'osam-bookings' ), isset( $row['start_time'] ) ? $row['start_time'] : '' );
				}
				if ( isset( $row['end_time'] ) && '' !== $row['end_time'] && ! $end ) {
					/* translators: %s value */
					$errors[] = sprintf( __( 'End time “%s” isn’t a time.', 'osam-bookings' ), $row['end_time'] );
				}
				if ( $date && $start ) {
					$data['start_at'] = $date . ' ' . $start . ':00';
					$data['end_at']   = $end ? ( $edate ? $edate : $date ) . ' ' . $end . ':00' : gmdate( 'Y-m-d H:i:00', strtotime( $data['start_at'] ) + HOUR_IN_SECONDS );
					if ( strtotime( $data['end_at'] ) <= strtotime( $data['start_at'] ) ) {
						$errors[] = __( 'The event ends before it starts.', 'osam-bookings' );
					}
					if ( ! $existing && $data['start_at'] < current_time( 'mysql' ) ) {
						$warn[] = __( 'This date has passed — the event will be added but can’t be booked online.', 'osam-bookings' );
					}
				}
				if ( isset( $row['location'] ) && '' !== $row['location'] ) {
					$data['location'] = sanitize_text_field( $row['location'] );
				}
				self::int( $row, 'capacity', 1, $data, $errors );
				self::int( $row, 'max_per_booking', 1, $data, $errors );
				if ( ! $existing && $name && $date ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE name = %s AND DATE(start_at) = %s LIMIT 1", $name, $date ) );
				}
				$key = strtolower( $name ) . '|' . $date;
			} else {
				if ( isset( $row['category'] ) && '' !== $row['category'] ) {
					$data['category'] = sanitize_text_field( $row['category'] );
				}
				self::int( $row, 'duration', 5, $data, $errors );
				self::int( $row, 'capacity', 1, $data, $errors );
				self::int( $row, 'buffer_before', 0, $data, $errors );
				self::int( $row, 'buffer_after', 0, $data, $errors );
				if ( ! $existing && $name ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE name = %s LIMIT 1", $name ) );
				}
				if ( ! $existing && ( ! isset( $row['duration'] ) || '' === $row['duration'] ) ) {
					$errors[] = __( 'Duration is empty.', 'osam-bookings' );
				}
				$key = strtolower( $name );
			}

			if ( isset( $row['price'] ) && '' !== $row['price'] ) {
				$p = self::money( $row['price'] );
				if ( null === $p ) {
					/* translators: %s value */
					$errors[] = sprintf( __( 'Price “%s” isn’t an amount.', 'osam-bookings' ), $row['price'] );
				} else {
					$data['price'] = $p;
				}
			}
			$staff = null;
			if ( isset( $row['staff'] ) && '' !== $row['staff'] && ! OSB_DB::is_solo() ) {
				$staff = array();
				foreach ( array_filter( array_map( 'trim', preg_split( '/[|;]+/', $row['staff'] ) ) ) as $sn ) {
					$sid = self::staff_id( $sn );
					if ( $sid ) {
						$staff[] = $sid;
					} else {
						/* translators: %s name */
						$errors[] = sprintf( __( 'No staff member called “%s”.', 'osam-bookings' ), $sn );
					}
				}
				if ( 'events' === $type ) {
					$data['staff_id'] = $staff ? $staff[0] : 0;
					if ( count( $staff ) > 1 ) {
						$warn[] = __( 'An event has one host — only the first name is used.', 'osam-bookings' );
					}
					$staff = null;
				}
			}
			self::common( $row, $type, $data, $errors, $warn );

			if ( isset( $seen[ $key ] ) && $key ) {
				/* translators: %d line */
				$warn[] = sprintf( __( 'Same as line %d — this row will update it again.', 'osam-bookings' ), $seen[ $key ] );
			}
			$seen[ $key ] = $line;

			$action = $existing ? ( $update || ! empty( $row['id'] ) ? 'update' : 'skip' ) : 'create';
			if ( 'skip' === $action ) {
				$warn[] = __( 'Already exists — skipped (updates are switched off).', 'osam-bookings' );
			}
			$items[] = array(
				'line'     => $line,
				'lines'    => array( $line ),
				'action'   => $action,
				'id'       => $existing ? (int) $existing->id : 0,
				'label'    => $name . ( 'events' === $type && ! empty( $data['start_at'] ) ? ' — ' . mysql2date( 'D j M Y, ' . get_option( 'time_format' ), $data['start_at'] ) : '' ),
				'data'     => $data,
				'staff'    => $staff,
				'errors'   => $errors,
				'warnings' => $warn,
			);
		}
		return $items;
	}

	/**
	 * Courses: rows grouped into course → intakes → sessions.
	 *
	 * @param array $rows   Rows.
	 * @param bool  $update Update matches.
	 * @return array
	 */
	private static function plan_courses( array $rows, $update ) {
		global $wpdb;
		$ct     = OSB_Install::table( 'courses' );
		$it     = OSB_Install::table( 'course_intakes' );
		$groups = array();
		foreach ( $rows as $line => $row ) {
			$cname = sanitize_text_field( isset( $row['course'] ) ? $row['course'] : '' );
			$key   = strtolower( $cname );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'name'    => $cname,
					'lines'   => array(),
					'rows'    => array(),
				);
			}
			$groups[ $key ]['lines'][]      = $line;
			$groups[ $key ]['rows'][ $line ] = $row;
		}
		$items = array();
		foreach ( $groups as $g ) {
			$errors = array();
			$warn   = array();
			$data   = array( 'name' => $g['name'] );
			if ( '' === $g['name'] ) {
				$errors[] = __( 'Course name is empty.', 'osam-bookings' );
			}
			// Course-level fields: first non-empty value wins; later different values are flagged.
			$merged = array();
			foreach ( $g['rows'] as $line => $row ) {
				foreach ( array( 'description', 'location', 'price', 'capacity', 'max_per_booking', 'staff', 'color', 'status', 'payment_methods', 'online_mode', 'online_url' ) as $k ) {
					if ( isset( $row[ $k ] ) && '' !== $row[ $k ] ) {
						if ( ! isset( $merged[ $k ] ) ) {
							$merged[ $k ] = $row[ $k ];
						} elseif ( $merged[ $k ] !== $row[ $k ] ) {
							$cols = self::columns( 'courses' );
							/* translators: 1: column 2: line */
							$warn[ 'diff-' . $k ] = sprintf( __( '%1$s differs on line %2$d — the first value is used for the whole course.', 'osam-bookings' ), $cols[ $k ][0], $line );
						}
					}
				}
			}
			if ( isset( $merged['location'] ) ) {
				$data['location'] = sanitize_text_field( $merged['location'] );
			}
			if ( isset( $merged['price'] ) ) {
				$p = self::money( $merged['price'] );
				if ( null === $p ) {
					/* translators: %s value */
					$errors[] = sprintf( __( 'Course fee “%s” isn’t an amount.', 'osam-bookings' ), $merged['price'] );
				} else {
					$data['price'] = $p;
				}
			}
			self::int( $merged, 'capacity', 1, $data, $errors );
			self::int( $merged, 'max_per_booking', 1, $data, $errors );
			if ( isset( $merged['staff'] ) && ! OSB_DB::is_solo() ) {
				$sid = self::staff_id( $merged['staff'] );
				if ( $sid ) {
					$data['staff_id'] = $sid;
				} else {
					/* translators: %s name */
					$errors[] = sprintf( __( 'No staff member called “%s”.', 'osam-bookings' ), $merged['staff'] );
				}
			}
			self::common( $merged, 'courses', $data, $errors, $warn );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $g['name'] ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ct WHERE name = %s LIMIT 1", $g['name'] ) ) : null;

			// Intakes and their sessions.
			$intakes = array();
			foreach ( $g['rows'] as $line => $row ) {
				$iname = sanitize_text_field( isset( $row['intake'] ) ? $row['intake'] : '' );
				if ( '' === $iname ) {
					/* translators: %d line */
					$errors[] = sprintf( __( 'Line %d: intake name is empty.', 'osam-bookings' ), $line );
					continue;
				}
				$ik = strtolower( $iname );
				if ( ! isset( $intakes[ $ik ] ) ) {
					$ex = $existing ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $it WHERE course_id = %d AND name = %s LIMIT 1", $existing->id, $iname ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( ! $ex && $existing ) {
						// Older intakes may have no name: Export writes their display label instead.
						foreach ( OSB_Courses::intakes( $existing->id ) as $cand ) {
							if ( '' === trim( (string) $cand->name ) && strtolower( OSB_Courses::intake_label( $cand ) ) === $ik ) {
								$ex = $cand;
							}
						}
					}
					$intakes[ $ik ] = array(
						'name'     => $iname,
						'id'       => $ex ? (int) $ex->id : 0,
						'booked'   => $ex ? OSB_Courses::booking_count( 'intake_id', $ex->id ) : 0,
						'data'     => array(),
						'sessions' => array(),
					);
				}
				if ( isset( $row['intake_capacity'] ) && '' !== $row['intake_capacity'] && ! isset( $intakes[ $ik ]['data']['capacity'] ) ) {
					$tmp = array();
					self::int( $row, 'intake_capacity', 1, $tmp, $errors, 'capacity' );
					$intakes[ $ik ]['data'] += $tmp;
				}
				if ( isset( $row['intake_price'] ) && '' !== $row['intake_price'] && ! array_key_exists( 'price', $intakes[ $ik ]['data'] ) ) {
					$p = self::money( $row['intake_price'] );
					if ( null === $p ) {
						/* translators: 1: line 2: value */
						$errors[] = sprintf( __( 'Line %1$d: intake fee “%2$s” isn’t an amount.', 'osam-bookings' ), $line, $row['intake_price'] );
					} else {
						$intakes[ $ik ]['data']['price'] = $p;
					}
				}
				$date  = self::date( isset( $row['date'] ) ? $row['date'] : '' );
				$start = self::time( isset( $row['start_time'] ) ? $row['start_time'] : '' );
				$end   = isset( $row['end_time'] ) && '' !== $row['end_time'] ? self::time( $row['end_time'] ) : '';
				if ( ! $date || ! $start ) {
					/* translators: %d line */
					$errors[] = sprintf( __( 'Line %d: the session needs a date and a start time (e.g. 2027-01-12 and 19:00).', 'osam-bookings' ), $line );
					continue;
				}
				if ( isset( $row['end_time'] ) && '' !== $row['end_time'] && ( ! $end || $end <= $start ) ) {
					/* translators: %d line */
					$errors[] = sprintf( __( 'Line %d: the end time must be a time after the start.', 'osam-bookings' ), $line );
					continue;
				}
				$intakes[ $ik ]['sessions'][] = array(
					'date'     => $date,
					'start'    => $start,
					'end'      => $end,
					'location' => isset( $row['session_location'] ) ? $row['session_location'] : '',
					'note'     => isset( $row['session_note'] ) ? $row['session_note'] : '',
					'staff_id' => isset( $data['staff_id'] ) ? $data['staff_id'] : ( $existing ? (int) $existing->staff_id : ( OSB_DB::is_solo() ? 0 : 0 ) ),
				);
			}
			$summary = array();
			foreach ( $intakes as &$in ) {
				usort(
					$in['sessions'],
					static function ( $a, $b ) {
						return strcmp( $a['date'] . $a['start'], $b['date'] . $b['start'] );
					}
				);
				if ( $in['id'] && $in['booked'] ) {
					/* translators: 1: intake 2: count */
					$warn[]          = sprintf( __( 'Intake “%1$s” already has %2$d booking(s), so its dates are left as they are. Change them on the intake page so everyone is emailed.', 'osam-bookings' ), $in['name'], $in['booked'] );
					$in['sessions_skip'] = true;
				}
				if ( $in['sessions'] && $in['sessions'][0]['date'] < current_time( 'Y-m-d' ) && ! $in['id'] ) {
					/* translators: %s intake */
					$warn[] = sprintf( __( 'Intake “%s” has already started, so it can’t be booked online.', 'osam-bookings' ), $in['name'] );
				}
				/* translators: 1: intake 2: count */
				$summary[] = sprintf( _n( '%1$s (%2$d session)', '%1$s (%2$d sessions)', count( $in['sessions'] ), 'osam-bookings' ), $in['name'], count( $in['sessions'] ) ) . ( $in['id'] ? '' : ' · ' . __( 'new', 'osam-bookings' ) );
			}
			unset( $in );

			$action = $existing ? ( $update ? 'update' : 'skip' ) : 'create';
			if ( 'skip' === $action ) {
				$warn[] = __( 'Course already exists — skipped (updates are switched off).', 'osam-bookings' );
			}
			$items[] = array(
				'line'     => $g['lines'][0],
				'lines'    => $g['lines'],
				'action'   => $action,
				'id'       => $existing ? (int) $existing->id : 0,
				'label'    => $g['name'],
				'detail'   => implode( '; ', $summary ),
				'data'     => $data,
				'intakes'  => array_values( $intakes ),
				'errors'   => $errors,
				'warnings' => array_values( $warn ),
			);
		}
		return $items;
	}

	/* ---------------------------------------------------------------------
	 * Running
	 * ------------------------------------------------------------------ */

	/**
	 * Carry out a plan (items with errors are left out).
	 *
	 * @param array $plan Plan from plan().
	 * @return array [ created, updated, skipped ]
	 */
	public static function run( array $plan ) {
		$out = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
		);
		foreach ( $plan['items'] as $item ) {
			if ( $item['errors'] || 'skip' === $item['action'] ) {
				++$out['skipped'];
				continue;
			}
			if ( 'courses' === $plan['type'] ) {
				self::run_course( $item );
			} elseif ( 'events' === $plan['type'] ) {
				self::run_event( $item );
			} else {
				self::run_service( $item );
			}
			++$out[ 'create' === $item['action'] ? 'created' : 'updated' ];
		}
		OSB_Availability::bust_cache();
		do_action( 'osb_items_imported', $plan['type'], $out );
		return $out;
	}

	/**
	 * Save a service.
	 *
	 * @param array $item Item.
	 */
	private static function run_service( array $item ) {
		$data = $item['data'];
		if ( 'create' === $item['action'] ) {
			$data = array_merge(
				array(
					'description'     => '',
					'category'        => '',
					'duration'        => 60,
					'price'           => 0,
					'capacity'        => 1,
					'buffer_before'   => 0,
					'buffer_after'    => 0,
					'color'           => '#0273b0',
					'payment_methods' => '',
					'status'          => 'active',
					'created_at'      => current_time( 'mysql' ),
				),
				$data
			);
			$id = OSB_DB::insert( 'services', $data );
		} else {
			$id = $item['id'];
			OSB_DB::update( 'services', $id, $data );
		}
		if ( OSB_DB::is_solo() ) {
			if ( ! OSB_DB::raw_service_staff_ids( $id ) ) {
				OSB_DB::set_service_staff( $id, array( OSB_DB::solo_staff_id() => '' ) );
			}
		} elseif ( null !== $item['staff'] || 'create' === $item['action'] ) {
			$ids = null !== $item['staff'] ? $item['staff'] : wp_list_pluck( OSB_DB::staff( true ), 'id' );
			if ( null === $item['staff'] || array_diff( $ids, OSB_DB::raw_service_staff_ids( $id ) ) || array_diff( OSB_DB::raw_service_staff_ids( $id ), $ids ) ) {
				$prices = OSB_DB::service_staff_prices( $id );
				$staff  = array();
				foreach ( $ids as $sid ) {
					$staff[ (int) $sid ] = isset( $prices[ (int) $sid ] ) ? $prices[ (int) $sid ] : '';
				}
				OSB_DB::set_service_staff( $id, $staff );
			}
		}
		do_action( 'osb_service_saved', $id, $data );
	}

	/**
	 * Save an event.
	 *
	 * @param array $item Item.
	 */
	private static function run_event( array $item ) {
		$data = $item['data'];
		if ( 'create' === $item['action'] ) {
			$data = array_merge(
				array(
					'description'     => '',
					'location'        => '',
					'capacity'        => 10,
					'price'           => 0,
					'staff_id'        => OSB_DB::is_solo() ? 0 : 0,
					'color'           => '#e8590c',
					'payment_methods' => '',
					'status'          => 'active',
					'created_at'      => current_time( 'mysql' ),
				),
				$data
			);
			if ( ! isset( $data['max_per_booking'] ) ) {
				$data['max_per_booking'] = min( 10, (int) $data['capacity'] );
			}
			$id = OSB_DB::insert( 'events', $data );
		} else {
			$id = $item['id'];
			OSB_DB::update( 'events', $id, $data );
		}
		do_action( 'osb_event_saved', $id, $data );
	}

	/**
	 * Save a course with its intakes and sessions.
	 *
	 * @param array $item Item.
	 */
	private static function run_course( array $item ) {
		$data = $item['data'];
		if ( 'create' === $item['action'] ) {
			$data = array_merge(
				array(
					'description'     => '',
					'location'        => '',
					'price'           => 0,
					'capacity'        => 10,
					'max_per_booking' => 1,
					'staff_id'        => OSB_DB::is_solo() ? OSB_DB::solo_staff_id() : 0,
					'color'           => '#2f9e44',
					'payment_methods' => '',
					'status'          => 'active',
					'created_at'      => current_time( 'mysql' ),
				),
				$data
			);
			$id = OSB_DB::insert( 'courses', $data );
		} else {
			$id = $item['id'];
			OSB_DB::update( 'courses', $id, $data );
		}
		$course = OSB_DB::get( 'courses', $id );
		do_action( 'osb_course_saved', $id, $data );
		foreach ( $item['intakes'] as $in ) {
			$idata = $in['data'];
			if ( $in['id'] ) {
				$iid = $in['id'];
				if ( $idata ) {
					OSB_DB::update( 'course_intakes', $iid, $idata );
				}
			} else {
				$iid = OSB_DB::insert(
					'course_intakes',
					array_merge(
						array(
							'course_id'  => $id,
							'name'       => $in['name'],
							'capacity'   => 0,
							'price'      => null,
							'status'     => 'active',
							'created_at' => current_time( 'mysql' ),
						),
						$idata
					)
				);
			}
			if ( empty( $in['sessions_skip'] ) && $in['sessions'] ) {
				$rows = array();
				foreach ( $in['sessions'] as $s ) {
					$s['staff_id'] = $s['staff_id'] ? $s['staff_id'] : (int) $course->staff_id;
					$rows[]        = $s;
				}
				OSB_Courses::save_sessions( $iid, $rows );
			}
			do_action( 'osb_course_intake_saved', $iid, $idata );
		}
	}

	/* ---------------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------------ */

	/**
	 * Rows for a CSV export (first row = headings), in the import format.
	 *
	 * @param string $type Type.
	 * @return array
	 */
	public static function export_rows( $type ) {
		$cols  = self::columns( $type );
		$out   = array( array_map( static function ( $c ) { return $c[0]; }, $cols ) );
		$names = array();
		foreach ( OSB_DB::staff() as $st ) {
			$names[ (int) $st->id ] = $st->name;
		}
		$row = static function ( array $values ) use ( $cols ) {
			$r = array();
			foreach ( array_keys( $cols ) as $k ) {
				$r[] = isset( $values[ $k ] ) ? $values[ $k ] : '';
			}
			return $r;
		};
		$shared = static function ( $item ) {
			return array(
				'description'     => (string) $item->description,
				'color'           => $item->color,
				'status'          => $item->status,
				'payment_methods' => str_replace( ',', '|', (string) $item->payment_methods ),
				'online_mode'     => isset( $item->online_mode ) ? $item->online_mode : '',
				'online_url'      => isset( $item->online_url ) ? $item->online_url : '',
			);
		};
		if ( 'events' === $type ) {
			foreach ( OSB_DB::events() as $e ) {
				$out[] = $row(
					array(
						'id'              => $e->id,
						'name'            => $e->name,
						'date'            => substr( $e->start_at, 0, 10 ),
						'start_time'      => substr( $e->start_at, 11, 5 ),
						'end_time'        => substr( $e->end_at, 11, 5 ),
						'end_date'        => substr( $e->end_at, 0, 10 ) !== substr( $e->start_at, 0, 10 ) ? substr( $e->end_at, 0, 10 ) : '',
						'location'        => $e->location,
						'capacity'        => $e->capacity,
						'max_per_booking' => $e->max_per_booking,
						'price'           => number_format( (float) $e->price, 2, '.', '' ),
						'staff'           => isset( $names[ (int) $e->staff_id ] ) && ! OSB_DB::is_solo() ? $names[ (int) $e->staff_id ] : '',
					) + $shared( $e )
				);
			}
		} elseif ( 'courses' === $type ) {
			foreach ( OSB_Courses::courses() as $c ) {
				$base = array(
					'course'          => $c->name,
					'location'        => $c->location,
					'price'           => number_format( (float) $c->price, 2, '.', '' ),
					'capacity'        => $c->capacity,
					'max_per_booking' => $c->max_per_booking,
					'staff'           => isset( $names[ (int) $c->staff_id ] ) && ! OSB_DB::is_solo() ? $names[ (int) $c->staff_id ] : '',
				) + $shared( $c );
				foreach ( OSB_Courses::intakes( $c->id ) as $in ) {
					$first = true;
					$extra = array(
						'intake'          => '' !== trim( (string) $in->name ) ? $in->name : OSB_Courses::intake_label( $in ),
						'intake_capacity' => (int) $in->capacity ? $in->capacity : '',
						'intake_price'    => null !== $in->price ? number_format( (float) $in->price, 2, '.', '' ) : '',
					);
					$sessions = OSB_Courses::sessions( $in->id );
					foreach ( $sessions as $s ) {
						$out[] = $row(
							$extra + array(
								'date'             => substr( $s->start_at, 0, 10 ),
								'start_time'       => substr( $s->start_at, 11, 5 ),
								'end_time'         => substr( $s->end_at, 11, 5 ),
								'session_location' => $s->location,
								'session_note'     => $s->note,
							) + ( $first ? $base : array( 'course' => $c->name ) )
						);
						$first = false;
					}
				}
			}
		} else {
			foreach ( OSB_DB::services() as $s ) {
				$staff = array();
				foreach ( OSB_DB::raw_service_staff_ids( $s->id ) as $sid ) {
					if ( isset( $names[ (int) $sid ] ) ) {
						$staff[] = $names[ (int) $sid ];
					}
				}
				$out[] = $row(
					array(
						'id'            => $s->id,
						'name'          => $s->name,
						'category'      => $s->category,
						'duration'      => $s->duration,
						'price'         => number_format( (float) $s->price, 2, '.', '' ),
						'capacity'      => $s->capacity,
						'buffer_before' => $s->buffer_before,
						'buffer_after'  => $s->buffer_after,
						'staff'         => OSB_DB::is_solo() ? '' : implode( '|', $staff ),
					) + $shared( $s )
				);
			}
		}
		return $out;
	}

	/**
	 * Template rows: headings plus one or two example rows.
	 *
	 * @param string $type Type.
	 * @return array
	 */
	public static function template_rows( $type ) {
		$cols = self::columns( $type );
		$out  = array( array_map( static function ( $c ) { return $c[0]; }, $cols ) );
		$ex   = array();
		foreach ( $cols as $c ) {
			$ex[] = $c[2];
		}
		$out[] = $ex;
		if ( 'courses' === $type ) {
			// A second session of the same intake: only the session columns are needed.
			$keys  = array_keys( $cols );
			$two   = array();
			foreach ( $keys as $k ) {
				$v     = array(
					'course'     => 'Beginners pottery',
					'intake'     => 'January evenings',
					'date'       => '2027-01-19',
					'start_time' => '19:00',
					'end_time'   => '21:00',
				);
				$two[] = isset( $v[ $k ] ) ? $v[ $k ] : '';
			}
			$out[] = $two;
		}
		return $out;
	}
}
