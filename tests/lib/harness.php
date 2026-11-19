<?php
/**
 * Shared harness for the tests/e2e-*.php scripts (run by tests/run.mjs inside WordPress Playground).
 *
 * Require it BEFORE wp-load.php, so PHP warnings/notices/deprecations and WordPress
 * "doing it wrong"/deprecated calls are caught from the very first line WordPress runs.
 *
 * Output convention: one "PASS ..." / "FAIL ..." line per check, then exactly "ALL PASSED" (exit 0)
 * or "<n> FAILED" (exit 1). A fatal error or a script that stops early also exits 1 without "ALL PASSED".
 *
 * Use:
 *   require __DIR__ . '/lib/harness.php';
 *   require '/wordpress/wp-load.php';
 *   osb_t_after_load();
 *   ok( $cond, 'what was checked' );
 *   osb_t_done();
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

$GLOBALS['osb_t'] = array(
	'fail' => 0,
	'pass' => 0,
	'php'  => array(),
	'done' => false,
);

/**
 * Record one check.
 *
 * @param bool   $cond Passed?
 * @param string $msg  What was checked.
 * @return bool
 */
function ok( $cond, $msg ) {
	$cond = (bool) $cond;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . PHP_EOL;
	++$GLOBALS['osb_t'][ $cond ? 'pass' : 'fail' ];
	return $cond;
}

/**
 * Record a PHP / WordPress problem (printed straight away, failed at the end).
 *
 * @param string $kind What sort.
 * @param string $msg  Message.
 */
function osb_t_problem( $kind, $msg ) {
	$line = $kind . ': ' . $msg;
	if ( ! in_array( $line, $GLOBALS['osb_t']['php'], true ) ) {
		$GLOBALS['osb_t']['php'][] = $line;
		echo 'PHP ' . $line . PHP_EOL;
	}
}

set_error_handler(
	static function ( $no, $str, $file, $line ) {
		$rep = error_reporting();
		// Silenced with @ on purpose (PHP 7: 0, PHP 8: only fatal types left on).
		if ( 0 === $rep || 0 === ( $rep & ~( E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE ) ) ) {
			return false;
		}
		// Playground's own wp-config.php re-defines constants it also sets at runtime (e.g. WP_DEBUG):
		// a sandbox quirk, not something the plugin or a real site does.
		if ( '/wordpress/wp-config.php' === $file && 0 === strpos( $str, 'Constant ' ) && false !== strpos( $str, 'already defined' ) ) {
			return true;
		}
		$names = array(
			E_WARNING           => 'Warning',
			E_NOTICE            => 'Notice',
			E_DEPRECATED        => 'Deprecated',
			E_USER_WARNING      => 'User warning',
			E_USER_NOTICE       => 'User notice',
			E_USER_DEPRECATED   => 'User deprecated',
			E_RECOVERABLE_ERROR => 'Recoverable error',
			E_USER_ERROR        => 'User error',
			2048                => 'Strict standards', // E_STRICT (the constant itself is deprecated in PHP 8.4).
		);
		osb_t_problem( isset( $names[ $no ] ) ? $names[ $no ] : 'Error ' . $no, $str . ' in ' . $file . ':' . $line );
		return true;
	}
);

// WordPress fires these even when WP_DEBUG is off. Pre-registered hooks (WP_Hook::build_preinitialized_hooks).
$osb_t_wp_hooks = array(
	'doing_it_wrong_run'       => static function ( $fn, $message = '', $version = '' ) {
		osb_t_problem( 'WordPress doing_it_wrong', $fn . ': ' . wp_strip_all_tags( (string) $message ) );
	},
	'deprecated_function_run'  => static function ( $fn, $replacement = '', $version = '' ) {
		osb_t_problem( 'WordPress deprecated function', $fn . ' (since ' . $version . ', use ' . $replacement . ')' );
	},
	'deprecated_argument_run'  => static function ( $fn, $message = '', $version = '' ) {
		osb_t_problem( 'WordPress deprecated argument', $fn . ': ' . $message );
	},
	'deprecated_hook_run'      => static function ( $hook, $replacement = '', $version = '', $message = '' ) {
		osb_t_problem( 'WordPress deprecated hook', $hook . ' (use ' . $replacement . ')' );
	},
	'deprecated_class_run'     => static function ( $class, $replacement = '', $version = '' ) {
		osb_t_problem( 'WordPress deprecated class', $class . ' (use ' . $replacement . ')' );
	},
	'deprecated_file_included' => static function ( $file, $replacement = '', $version = '', $message = '' ) {
		osb_t_problem( 'WordPress deprecated file', $file . ' (use ' . $replacement . ')' );
	},
);
foreach ( $osb_t_wp_hooks as $osb_t_tag => $osb_t_cb ) {
	$GLOBALS['wp_filter'][ $osb_t_tag ][10][] = array(
		'function'      => $osb_t_cb,
		'accepted_args' => 4,
	);
}
unset( $osb_t_wp_hooks, $osb_t_tag, $osb_t_cb );

register_shutdown_function(
	static function () {
		$e = error_get_last();
		if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			echo PHP_EOL . 'FAIL PHP fatal error: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] . PHP_EOL;
			exit( 1 );
		}
		if ( empty( $GLOBALS['osb_t']['done'] ) ) {
			echo PHP_EOL . 'FAIL test script stopped before the end (exit/wp_die/uncaught exception?)' . PHP_EOL;
			exit( 1 );
		}
	}
);

/**
 * Call straight after wp-load.php: WordPress lowers error_reporting when WP_DEBUG is off.
 */
function osb_t_after_load() {
	error_reporting( E_ALL );
}

/**
 * Last line of every test: the PHP-problems check, the summary line and the exit code.
 */
function osb_t_done() {
	$problems = $GLOBALS['osb_t']['php'];
	ok( ! $problems, 'no PHP warnings, notices, deprecations or WordPress doing_it_wrong/deprecated calls' . ( $problems ? ' (' . count( $problems ) . ' found, see "PHP ..." lines above)' : '' ) );
	$GLOBALS['osb_t']['done'] = true;
	$fail                     = $GLOBALS['osb_t']['fail'];
	echo PHP_EOL . ( $fail ? $fail . ' FAILED' : 'ALL PASSED' ) . PHP_EOL;
	exit( $fail ? 1 : 0 );
}
