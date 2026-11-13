<?php
/**
 * Tiny self-contained PDF writer (A4, Helvetica, lines, boxes, JPEG/PNG images).
 *
 * Invoices must render on any shared host, so there's no Dompdf/TCPDF dependency: this writes
 * PDF 1.4 by hand using the built-in Helvetica fonts (WinAnsi / Windows-1252 text).
 *
 * Co-ordinates are in points from the TOP-left of the page (converted internally).
 *
 * @package OSAM_Bookings
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimal PDF document.
 */
class OSB_Pdf {

	const W = 595.28;
	const H = 841.89;

	/**
	 * Helvetica widths for bytes 32–255 (Windows-1252), 1/1000 em.
	 *
	 * @var array
	 */
	private static $widths = array(
		'F1' => '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500',
		'F2' => '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556',
	);

	/**
	 * Parsed width tables.
	 *
	 * @var array
	 */
	private static $w = array();

	/**
	 * Page content streams.
	 *
	 * @var string[]
	 */
	private $pages = array();

	/**
	 * Images: [ name => [ w, h, dict, data, smask? ] ].
	 *
	 * @var array
	 */
	private $images = array();

	/**
	 * Clickable areas: [ page index => [ [ x1, y1, x2, y2, url ] ] ] (PDF co-ordinates).
	 *
	 * @var array
	 */
	private $links = array();

	/**
	 * Document title.
	 *
	 * @var string
	 */
	private $title = '';

	/**
	 * Start.
	 *
	 * @param string $title Document title (metadata).
	 */
	public function __construct( $title = '' ) {
		$this->title = (string) $title;
		$this->add_page();
	}

	/**
	 * New page.
	 */
	public function add_page() {
		$this->pages[] = '';
	}

	/**
	 * Current page index.
	 *
	 * @return int
	 */
	public function page_count() {
		return count( $this->pages );
	}

	/**
	 * Append raw operators to the current page.
	 *
	 * @param string $ops Operators.
	 */
	private function out( $ops ) {
		$this->pages[ count( $this->pages ) - 1 ] .= $ops . "\n";
	}

	/**
	 * Number for the content stream.
	 *
	 * @param float $n Number.
	 * @return string
	 */
	private static function n( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 3, '.', '' ), '0' ), '.' );
	}

	/**
	 * Hex colour → "r g b".
	 *
	 * @param string $hex #rrggbb.
	 * @return string
	 */
	private static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			$hex = '000000';
		}
		return self::n( hexdec( substr( $hex, 0, 2 ) ) / 255 ) . ' ' . self::n( hexdec( substr( $hex, 2, 2 ) ) / 255 ) . ' ' . self::n( hexdec( substr( $hex, 4, 2 ) ) / 255 );
	}

	/**
	 * UTF-8 → Windows-1252 bytes (unknown characters approximated).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function encode( $s ) {
		$s = (string) $s;
		$s = str_replace( array( "\r\n", "\r", "\t" ), array( "\n", "\n", ' ' ), $s );
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $out ) {
				return $out;
			}
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' );
		}
		return preg_replace( '/[^\x20-\x7e\n]/', '?', $s );
	}

	/**
	 * Can this text be shown exactly (no approximations)?
	 *
	 * @param string $s Text.
	 * @return bool
	 */
	public static function encodable( $s ) {
		if ( ! function_exists( 'iconv' ) ) {
			return (bool) preg_match( '/^[\x20-\x7e£€]*$/u', (string) $s );
		}
		$out = @iconv( 'UTF-8', 'Windows-1252', (string) $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false !== $out;
	}

	/**
	 * Width of (already encoded) text.
	 *
	 * @param string $bytes Windows-1252 text.
	 * @param float  $size  Font size.
	 * @param bool   $bold  Bold.
	 * @return float
	 */
	private static function bytes_width( $bytes, $size, $bold = false ) {
		$f = $bold ? 'F2' : 'F1';
		if ( ! isset( self::$w[ $f ] ) ) {
			self::$w[ $f ] = array_map( 'intval', explode( ',', self::$widths[ $f ] ) );
		}
		$total = 0;
		$len   = strlen( $bytes );
		for ( $i = 0; $i < $len; $i++ ) {
			$o      = ord( $bytes[ $i ] );
			$total += $o >= 32 ? self::$w[ $f ][ $o - 32 ] : 0;
		}
		return $total * $size / 1000;
	}

	/**
	 * Width of UTF-8 text.
	 *
	 * @param string $s    Text.
	 * @param float  $size Size.
	 * @param bool   $bold Bold.
	 * @return float
	 */
	public static function width( $s, $size, $bold = false ) {
		return self::bytes_width( self::encode( $s ), $size, $bold );
	}

	/**
	 * Escape bytes for a PDF string.
	 *
	 * @param string $bytes Bytes.
	 * @return string
	 */
	private static function esc( $bytes ) {
		return str_replace( array( '\\', '(', ')', "\n" ), array( '\\\\', '\\(', '\\)', '' ), $bytes );
	}

	/**
	 * One line of text.
	 *
	 * @param float  $x     Left (or right/centre edge, see $align).
	 * @param float  $y     Baseline from top.
	 * @param string $s     Text (UTF-8).
	 * @param array  $o     size, bold, color, align (left|right|center).
	 */
	public function text( $x, $y, $s, array $o = array() ) {
		$o     = array_merge( array( 'size' => 10, 'bold' => false, 'color' => '#1f2933', 'align' => 'left' ), $o );
		$bytes = self::encode( $s );
		if ( '' === $bytes ) {
			return;
		}
		$w = self::bytes_width( $bytes, $o['size'], $o['bold'] );
		if ( 'right' === $o['align'] ) {
			$x -= $w;
		} elseif ( 'center' === $o['align'] ) {
			$x -= $w / 2;
		}
		$this->out( sprintf( 'BT /%s %s Tf %s rg %s %s Td (%s) Tj ET', $o['bold'] ? 'F2' : 'F1', self::n( $o['size'] ), self::rgb( $o['color'] ), self::n( $x ), self::n( self::H - $y ), self::esc( $bytes ) ) );
	}

	/**
	 * Break text into lines that fit a width.
	 *
	 * @param string $s     Text (may contain newlines).
	 * @param float  $width Max width.
	 * @param float  $size  Size.
	 * @param bool   $bold  Bold.
	 * @return string[]
	 */
	public static function wrap( $s, $width, $size, $bold = false ) {
		$lines = array();
		foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $s ) ) as $para ) {
			$words = preg_split( '/ +/', trim( $para ) );
			$line  = '';
			foreach ( $words as $word ) {
				$try = '' === $line ? $word : $line . ' ' . $word;
				if ( '' === $line || self::width( $try, $size, $bold ) <= $width ) {
					$line = $try;
					// A single word longer than the line: hard-break it.
					while ( self::width( $line, $size, $bold ) > $width && mb_strlen( $line ) > 1 ) {
						$cut = mb_strlen( $line );
						while ( $cut > 1 && self::width( mb_substr( $line, 0, $cut ), $size, $bold ) > $width ) {
							--$cut;
						}
						$lines[] = mb_substr( $line, 0, $cut );
						$line    = mb_substr( $line, $cut );
					}
				} else {
					$lines[] = $line;
					$line    = $word;
				}
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Wrapped paragraph.
	 *
	 * @param float  $x     Left.
	 * @param float  $y     First baseline from top.
	 * @param float  $width Width.
	 * @param string $s     Text.
	 * @param array  $o     As text(), plus leading.
	 * @return float Baseline after the last line (where the next line would go).
	 */
	public function paragraph( $x, $y, $width, $s, array $o = array() ) {
		$o       = array_merge( array( 'size' => 10, 'bold' => false, 'leading' => 0 ), $o );
		$leading = $o['leading'] ? $o['leading'] : $o['size'] * 1.4;
		foreach ( self::wrap( $s, $width, $o['size'], $o['bold'] ) as $line ) {
			$this->text( 'right' === ( $o['align'] ?? '' ) ? $x + $width : $x, $y, $line, $o );
			$y += $leading;
		}
		return $y;
	}

	/**
	 * Filled rectangle.
	 *
	 * @param float  $x     Left.
	 * @param float  $y     Top.
	 * @param float  $w     Width.
	 * @param float  $h     Height.
	 * @param string $color Fill.
	 */
	public function rect( $x, $y, $w, $h, $color ) {
		$this->out( sprintf( '%s rg %s %s %s %s re f', self::rgb( $color ), self::n( $x ), self::n( self::H - $y - $h ), self::n( $w ), self::n( $h ) ) );
	}

	/**
	 * Make an area of the current page open a web address when clicked.
	 *
	 * @param float  $x   Left.
	 * @param float  $y   Top.
	 * @param float  $w   Width.
	 * @param float  $h   Height.
	 * @param string $url Address (http/https).
	 */
	public function link( $x, $y, $w, $h, $url ) {
		$url = preg_replace( '/[^\x21-\x7e]/', '', (string) $url );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return;
		}
		$this->links[ count( $this->pages ) - 1 ][] = array( $x, self::H - $y - $h, $x + $w, self::H - $y, $url );
	}

	/**
	 * Straight line.
	 *
	 * @param float  $x1    From x.
	 * @param float  $y1    From y.
	 * @param float  $x2    To x.
	 * @param float  $y2    To y.
	 * @param string $color Colour.
	 * @param float  $width Line width.
	 */
	public function line( $x1, $y1, $x2, $y2, $color = '#e4e7ec', $width = 0.75 ) {
		$this->out( sprintf( '%s RG %s w %s %s m %s %s l S', self::rgb( $color ), self::n( $width ), self::n( $x1 ), self::n( self::H - $y1 ), self::n( $x2 ), self::n( self::H - $y2 ) ) );
	}

	/**
	 * Draw a JPEG or PNG file, fitted inside a box (keeps proportions).
	 *
	 * @param string $file  Path.
	 * @param float  $x     Left.
	 * @param float  $y     Top.
	 * @param float  $max_w Max width.
	 * @param float  $max_h Max height.
	 * @return float[]|false [ drawn width, drawn height ].
	 */
	public function image( $file, $x, $y, $max_w, $max_h ) {
		$img = self::load_image( $file );
		if ( ! $img ) {
			return false;
		}
		$name                  = 'Im' . ( count( $this->images ) + 1 );
		$this->images[ $name ] = $img;
		$scale                 = min( $max_w / $img['w'], $max_h / $img['h'] );
		$w                     = $img['w'] * $scale;
		$h                     = $img['h'] * $scale;
		$this->out( sprintf( 'q %s 0 0 %s %s %s cm /%s Do Q', self::n( $w ), self::n( $h ), self::n( $x ), self::n( self::H - $y - $h ), $name ) );
		return array( $w, $h );
	}

	/**
	 * Read an image into PDF image parts.
	 *
	 * @param string $file Path.
	 * @return array|false
	 */
	private static function load_image( $file ) {
		if ( ! $file || ! is_readable( $file ) ) {
			return false;
		}
		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info ) {
			return false;
		}
		$data = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( IMAGETYPE_JPEG === $info[2] ) {
			$cs = ( isset( $info['channels'] ) && 4 === (int) $info['channels'] ) ? '/DeviceCMYK' : ( ( isset( $info['channels'] ) && 1 === (int) $info['channels'] ) ? '/DeviceGray' : '/DeviceRGB' );
			return array(
				'w'    => $info[0],
				'h'    => $info[1],
				'dict' => '/ColorSpace ' . $cs . ' /BitsPerComponent 8 /Filter /DCTDecode' . ( '/DeviceCMYK' === $cs ? ' /Decode [1 0 1 0 1 0 1 0]' : '' ),
				'data' => $data,
			);
		}
		if ( IMAGETYPE_PNG === $info[2] ) {
			$png = self::parse_png( $data );
			if ( $png ) {
				return $png;
			}
		}
		// Anything else (GIF, WebP, odd PNGs): convert with GD if it's there.
		if ( function_exists( 'imagecreatefromstring' ) && function_exists( 'imagejpeg' ) ) {
			$src = @imagecreatefromstring( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $src ) {
				$w  = imagesx( $src );
				$h  = imagesy( $src );
				$bg = imagecreatetruecolor( $w, $h );
				imagefill( $bg, 0, 0, imagecolorallocate( $bg, 255, 255, 255 ) );
				imagecopy( $bg, $src, 0, 0, 0, 0, $w, $h );
				ob_start();
				imagejpeg( $bg, null, 90 );
				$jpg = ob_get_clean();
				imagedestroy( $src );
				imagedestroy( $bg );
				return array(
					'w'    => $w,
					'h'    => $h,
					'dict' => '/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode',
					'data' => $jpg,
				);
			}
		}
		return false;
	}

	/**
	 * PNG → PDF image (8-bit, non-interlaced; alpha becomes a soft mask).
	 *
	 * @param string $data File bytes.
	 * @return array|false
	 */
	private static function parse_png( $data ) {
		if ( "\x89PNG\r\n\x1a\n" !== substr( $data, 0, 8 ) ) {
			return false;
		}
		$pos  = 8;
		$len  = strlen( $data );
		$idat = '';
		$hdr  = null;
		$pal  = '';
		$trns = '';
		while ( $pos + 8 <= $len ) {
			$n    = unpack( 'N', substr( $data, $pos, 4 ) )[1];
			$type = substr( $data, $pos + 4, 4 );
			$body = substr( $data, $pos + 8, $n );
			if ( 'IHDR' === $type ) {
				$hdr = unpack( 'Nw/Nh/Cbits/Cct/Ccomp/Cfilter/Cinterlace', $body );
			} elseif ( 'PLTE' === $type ) {
				$pal = $body;
			} elseif ( 'tRNS' === $type ) {
				$trns = $body;
			} elseif ( 'IDAT' === $type ) {
				$idat .= $body;
			} elseif ( 'IEND' === $type ) {
				break;
			}
			$pos += 12 + $n;
		}
		if ( ! $hdr || 8 !== $hdr['bits'] || $hdr['interlace'] || '' === $idat ) {
			return false;
		}
		$w = $hdr['w'];
		$h = $hdr['h'];
		switch ( $hdr['ct'] ) {
			case 0:
				$colors = 1;
				$cs     = '/DeviceGray';
				break;
			case 2:
				$colors = 3;
				$cs     = '/DeviceRGB';
				break;
			case 3:
				if ( '' !== $trns || '' === $pal ) {
					return false; // Transparent palette images go through GD.
				}
				$colors = 1;
				$cs     = '[/Indexed /DeviceRGB ' . ( strlen( $pal ) / 3 - 1 ) . ' <' . bin2hex( $pal ) . '>]';
				break;
			case 4:
			case 6:
				return self::png_alpha( $idat, $w, $h, 4 === $hdr['ct'] ? 1 : 3 );
			default:
				return false;
		}
		return array(
			'w'    => $w,
			'h'    => $h,
			'dict' => '/ColorSpace ' . $cs . ' /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors ' . $colors . ' /BitsPerComponent 8 /Columns ' . $w . ' >>',
			'data' => $idat,
		);
	}

	/**
	 * PNG with alpha: undo the row filters, split colour and alpha, re-compress both.
	 *
	 * @param string $idat   Compressed data.
	 * @param int    $w      Width.
	 * @param int    $h      Height.
	 * @param int    $colors Colour channels (1 or 3).
	 * @return array|false
	 */
	private static function png_alpha( $idat, $w, $h, $colors ) {
		if ( ! function_exists( 'gzuncompress' ) || $w * $h > 4000000 ) {
			return false;
		}
		$raw = @gzuncompress( $idat ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $raw ) {
			return false;
		}
		$bpp    = $colors + 1;
		$stride = $w * $bpp;
		$color  = '';
		$alpha  = '';
		$prev   = str_repeat( "\0", $stride );
		$p      = 0;
		for ( $y = 0; $y < $h; $y++ ) {
			$filter = ord( $raw[ $p ] );
			$line   = substr( $raw, $p + 1, $stride );
			$p     += $stride + 1;
			$out    = '';
			for ( $i = 0; $i < $stride; $i++ ) {
				$x = ord( $line[ $i ] );
				$a = $i >= $bpp ? ord( $out[ $i - $bpp ] ) : 0;
				$b = ord( $prev[ $i ] );
				$c = $i >= $bpp ? ord( $prev[ $i - $bpp ] ) : 0;
				switch ( $filter ) {
					case 1:
						$x += $a;
						break;
					case 2:
						$x += $b;
						break;
					case 3:
						$x += ( $a + $b ) >> 1;
						break;
					case 4:
						$pp = $a + $b - $c;
						$pa = abs( $pp - $a );
						$pb = abs( $pp - $b );
						$pc = abs( $pp - $c );
						$x += ( $pa <= $pb && $pa <= $pc ) ? $a : ( $pb <= $pc ? $b : $c );
						break;
				}
				$out .= chr( $x & 0xff );
			}
			$prev = $out;
			for ( $i = 0; $i < $stride; $i += $bpp ) {
				$color .= substr( $out, $i, $colors );
				$alpha .= $out[ $i + $colors ];
			}
		}
		return array(
			'w'     => $w,
			'h'     => $h,
			'dict'  => '/ColorSpace ' . ( 3 === $colors ? '/DeviceRGB' : '/DeviceGray' ) . ' /BitsPerComponent 8 /Filter /FlateDecode',
			'data'  => gzcompress( $color ),
			'smask' => gzcompress( $alpha ),
		);
	}

	/**
	 * The finished file.
	 *
	 * @return string PDF bytes.
	 */
	public function output() {
		$objs = array();
		// 1 catalog, 2 pages, 3 F1, 4 F2, 5 info; then images, then page/content pairs.
		$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$objs[5] = '<< /Title (' . self::esc( self::encode( $this->title ) ) . ') /Producer (OSAM Bookings) /CreationDate (D:' . gmdate( 'YmdHis' ) . 'Z) >>';
		$next    = 6;
		$xobj    = '';
		foreach ( $this->images as $name => $img ) {
			$smask = '';
			if ( ! empty( $img['smask'] ) ) {
				$objs[ $next ] = array( '<< /Type /XObject /Subtype /Image /Width ' . $img['w'] . ' /Height ' . $img['h'] . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode', $img['smask'] );
				$smask         = ' /SMask ' . $next . ' 0 R';
				++$next;
			}
			$objs[ $next ] = array( '<< /Type /XObject /Subtype /Image /Width ' . $img['w'] . ' /Height ' . $img['h'] . ' ' . $img['dict'] . $smask, $img['data'] );
			$xobj         .= '/' . $name . ' ' . $next . ' 0 R ';
			++$next;
		}
		$res  = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ( $xobj ? ' /XObject << ' . $xobj . '>>' : '' ) . ' >>';
		$kids = array();
		foreach ( $this->pages as $i => $content ) {
			$stream        = function_exists( 'gzcompress' ) ? gzcompress( $content ) : $content;
			$objs[ $next ] = array( '<<' . ( function_exists( 'gzcompress' ) ? ' /Filter /FlateDecode' : '' ), $stream );
			$page          = $next + 1;
			$kids[]        = $page . ' 0 R';
			$next         += 2;
			$annots        = '';
			foreach ( isset( $this->links[ $i ] ) ? $this->links[ $i ] : array() as $l ) {
				$objs[ $next ] = '<< /Type /Annot /Subtype /Link /Rect [' . self::n( $l[0] ) . ' ' . self::n( $l[1] ) . ' ' . self::n( $l[2] ) . ' ' . self::n( $l[3] ) . '] /Border [0 0 0] /A << /Type /Action /S /URI /URI (' . self::esc( $l[4] ) . ') >> >>';
				$annots       .= $next . ' 0 R ';
				++$next;
			}
			$objs[ $page ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n( self::W ) . ' ' . self::n( self::H ) . '] /Resources ' . $res . ' /Contents ' . ( $page - 1 ) . ' 0 R' . ( $annots ? ' /Annots [' . trim( $annots ) . ']' : '' ) . ' >>';
		}
		$objs[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>';
		ksort( $objs );

		$pdf     = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
		$offsets = array();
		foreach ( $objs as $num => $obj ) {
			$offsets[ $num ] = strlen( $pdf );
			if ( is_array( $obj ) ) {
				$pdf .= $num . " 0 obj\n" . $obj[0] . ' /Length ' . strlen( $obj[1] ) . " >>\nstream\n" . $obj[1] . "\nendstream\nendobj\n";
			} else {
				$pdf .= $num . " 0 obj\n" . $obj . "\nendobj\n";
			}
		}
		$xref = strlen( $pdf );
		$max  = max( array_keys( $objs ) );
		$pdf .= "xref\n0 " . ( $max + 1 ) . "\n0000000000 65535 f \n";
		for ( $i = 1; $i <= $max; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", isset( $offsets[ $i ] ) ? $offsets[ $i ] : 0 );
		}
		$pdf .= "trailer\n<< /Size " . ( $max + 1 ) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		return $pdf;
	}
}
