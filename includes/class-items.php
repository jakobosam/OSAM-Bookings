<?php
/**
 * "Which bookings" tokens shared by add-ons, bundles and custom emails.
 *
 * services · s:ID · cat:Name · events · e:ID · courses · c:ID · tables · resources · r:ID · rcat:Name
 * An empty list means "everything".
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Item tokens.
 */
class OSB_Items {

	/**
	 * Tokens for what's being booked.
	 *
	 * @param string $type    service|event|course|table|resource.
	 * @param int    $item_id Service, event or course id.
	 * @param int[]  $res_ids Resource ids.
	 * @return string[]
	 */
	public static function tokens( $type, $item_id = 0, array $res_ids = array() ) {
		switch ( $type ) {
			case 'event':
				return array( 'events', 'e:' . (int) $item_id );
			case 'course':
				return array( 'courses', 'c:' . (int) $item_id );
			case 'table':
				return array( 'tables' );
			case 'resource':
				$out = array( 'resources' );
				foreach ( $res_ids as $rid ) {
					$out[] = 'r:' . (int) $rid;
					$r     = OSB_Resources::get( (int) $rid );
					if ( $r && '' !== (string) $r->category ) {
						$out[] = 'rcat:' . $r->category;
					}
				}
				return array_values( array_unique( $out ) );
			case 'bundle':
				return array( 'bundles' );
			default:
				$svc = OSB_DB::get( 'services', (int) $item_id );
				$out = array( 'services', 's:' . (int) $item_id );
				if ( $svc && '' !== (string) $svc->category ) {
					$out[] = 'cat:' . $svc->category;
				}
				return $out;
		}
	}

	/**
	 * Tokens for a saved booking.
	 *
	 * @param object $booking Booking.
	 * @return string[]
	 */
	public static function booking_tokens( $booking ) {
		$res = 'resource' === $booking->type ? array_map( 'intval', wp_list_pluck( OSB_Resources::lines( $booking->id ), 'resource_id' ) ) : array();
		$id  = 'event' === $booking->type ? $booking->event_id : ( 'course' === $booking->type ? $booking->course_id : $booking->service_id );
		return self::tokens( $booking->type, (int) $id, $res );
	}

	/**
	 * Does a list of tokens cover this booking? Empty = everything. A resource booking of several
	 * resources is covered when any of them is.
	 *
	 * @param string[] $applies Chosen tokens.
	 * @param string[] $tokens  The booking's tokens.
	 * @return bool
	 */
	public static function covers( array $applies, array $tokens ) {
		return ! $applies || (bool) array_intersect( $applies, $tokens );
	}

	/**
	 * Picker groups for the admin: group label => [ token => label ].
	 *
	 * @param string[] $keep Tokens already chosen (kept even if the item has gone or is in the past).
	 * @param bool     $all  Include every group even when the feature is off.
	 * @return array
	 */
	public static function groups( array $keep = array(), $all = false ) {
		$groups = array();
		$svc    = array( 'services' => __( 'All appointments', 'osam-bookings' ) );
		$cats   = array_unique( array_filter( wp_list_pluck( OSB_DB::services(), 'category' ) ) );
		sort( $cats );
		foreach ( $cats as $c ) {
			/* translators: %s category */
			$svc[ 'cat:' . $c ] = sprintf( __( 'Category: %s', 'osam-bookings' ), $c );
		}
		foreach ( OSB_DB::services() as $sv ) {
			$svc[ 's:' . $sv->id ] = $sv->name;
		}
		$groups[ __( 'Appointments', 'osam-bookings' ) ] = $svc;
		if ( $all || OSB_Settings::feature( 'events' ) ) {
			$ev = array( 'events' => __( 'All events', 'osam-bookings' ) );
			foreach ( OSB_DB::upcoming_events( 60 ) as $e ) {
				$ev[ 'e:' . $e->id ] = $e->name . ' · ' . mysql2date( 'j M', $e->start_at );
			}
			$groups[ __( 'Events', 'osam-bookings' ) ] = $ev;
		}
		if ( $all || OSB_Settings::feature( 'courses' ) ) {
			$co = array( 'courses' => __( 'All courses', 'osam-bookings' ) );
			foreach ( OSB_Courses::courses() as $c ) {
				$co[ 'c:' . $c->id ] = $c->name;
			}
			$groups[ __( 'Courses', 'osam-bookings' ) ] = $co;
		}
		if ( $all || OSB_Settings::feature( 'resources' ) ) {
			$re   = array( 'resources' => __( 'All resources', 'osam-bookings' ) );
			$list = OSB_Resources::all();
			$rc   = array_unique( array_filter( wp_list_pluck( $list, 'category' ) ) );
			sort( $rc );
			foreach ( $rc as $c ) {
				/* translators: %s category */
				$re[ 'rcat:' . $c ] = sprintf( __( 'Category: %s', 'osam-bookings' ), $c );
			}
			foreach ( $list as $r ) {
				$re[ 'r:' . $r->id ] = $r->name;
			}
			$groups[ __( 'Resources', 'osam-bookings' ) ] = $re;
		}
		if ( $all || OSB_Settings::feature( 'restaurant' ) ) {
			$groups[ __( 'Restaurant', 'osam-bookings' ) ] = array( 'tables' => __( 'Table bookings', 'osam-bookings' ) );
		}
		foreach ( $keep as $tok ) {
			$found = false;
			foreach ( $groups as $opts ) {
				$found = $found || isset( $opts[ $tok ] );
			}
			if ( ! $found && '' !== self::label( $tok ) ) {
				$groups[ __( 'Other', 'osam-bookings' ) ][ $tok ] = self::label( $tok );
			}
		}
		return $groups;
	}

	/**
	 * Human label for a token.
	 *
	 * @param string $tok Token.
	 * @return string
	 */
	public static function label( $tok ) {
		$fixed = array(
			'services'  => __( 'All appointments', 'osam-bookings' ),
			'events'    => __( 'All events', 'osam-bookings' ),
			'courses'   => __( 'All courses', 'osam-bookings' ),
			'tables'    => __( 'Table bookings', 'osam-bookings' ),
			'resources' => __( 'All resources', 'osam-bookings' ),
			'bundles'   => __( 'Bundle purchases', 'osam-bookings' ),
		);
		if ( isset( $fixed[ $tok ] ) ) {
			return $fixed[ $tok ];
		}
		list( $kind, $val ) = array_pad( explode( ':', $tok, 2 ), 2, '' );
		if ( 'cat' === $kind || 'rcat' === $kind ) {
			/* translators: %s category */
			return sprintf( __( 'Category: %s', 'osam-bookings' ), $val );
		}
		if ( 'r' === $kind ) {
			$r = OSB_Resources::get( (int) $val );
			return $r ? $r->name : '';
		}
		$tables = array( 's' => 'services', 'e' => 'events', 'c' => 'courses' );
		if ( isset( $tables[ $kind ] ) ) {
			$row = OSB_DB::get( $tables[ $kind ], (int) $val );
			return $row ? $row->name : '';
		}
		return '';
	}

	/**
	 * "Appointments, Top Field and 2 more" / "Everything".
	 *
	 * @param string[] $applies Tokens.
	 * @return string
	 */
	public static function describe( array $applies ) {
		if ( ! $applies ) {
			return __( 'Everything', 'osam-bookings' );
		}
		$names = array_values( array_filter( array_map( array( __CLASS__, 'label' ), $applies ) ) );
		return count( $names ) > 3 ? sprintf( /* translators: 1 first names 2 count */ __( '%1$s and %2$d more', 'osam-bookings' ), implode( ', ', array_slice( $names, 0, 3 ) ), count( $names ) - 3 ) : implode( ', ', $names );
	}

	/**
	 * Clean a submitted token list.
	 *
	 * @param mixed $in Raw.
	 * @return string[]
	 */
	public static function clean( $in ) {
		$out = array();
		foreach ( (array) $in as $tok ) {
			$tok = sanitize_text_field( (string) $tok );
			if ( preg_match( '/^(services|events|courses|tables|resources|bundles|[secr]:\d+|r:\d+|cat:.+|rcat:.+)$/', $tok ) ) {
				$out[] = $tok;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Decode a stored list.
	 *
	 * @param string|null $json Stored JSON.
	 * @return string[]
	 */
	public static function decode( $json ) {
		$v = json_decode( (string) $json, true );
		return is_array( $v ) ? self::clean( $v ) : array();
	}
}
