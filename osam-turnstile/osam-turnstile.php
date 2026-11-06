<?php
/**
 * Plugin Name: OSAM Turnstile
 * Description: Cloudflare Turnstile spam protection for Elementor Pro forms, Divi contact forms and the WordPress login, registration and lost-password pages, using OSAM's shared Turnstile frame (built-form-worker). No settings: activate it and remove any reCAPTCHA from the forms. To switch off the login check, add define( 'OSAM_TS_LOGIN', false ); to wp-config.php.
 * Version: 1.3.0
 * Author: OSAM
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Update URI: https://updates.osamweb.com/osam-turnstile
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Signed updates from updates.osamweb.com, like OSAM Bookings (see includes/updater.php)
require_once __DIR__ . '/includes/updater.php';
OSAM_TS_Updater::init( __FILE__ );

if ( ! defined( 'OSAM_TS_WORKER' ) ) {
	define( 'OSAM_TS_WORKER', 'https://built-form-worker.cloudflare-fe2.workers.dev' );
}
if ( ! defined( 'OSAM_TS_FIELD' ) ) {
	define( 'OSAM_TS_FIELD', 'osam_turnstile' );
}
// Escape hatch if a site's login ever misbehaves: define( 'OSAM_TS_LOGIN', false ); in wp-config.php
if ( ! defined( 'OSAM_TS_LOGIN' ) ) {
	define( 'OSAM_TS_LOGIN', true );
}

/**
 * Browser side. Kept tiny: the worker's /captcha.js does the Turnstile work (the same script the AI sites use).
 * - The first time a visitor clicks into a protected form, /captcha.js loads and starts solving the
 *   check in the background (invisible unless Cloudflare wants a click, which shows above the send button).
 * - When the form is sent, a fresh token is put in a hidden field, then the form is sent as usual.
 * Protected forms: Elementor Pro forms (including its Login widget), Divi contact forms and the
 * wp-login.php forms.
 */
function osam_ts_footer_script() {
	if ( is_admin() ) {
		return;
	}
	$selector = 'form.elementor-form, form.et_pb_contact_form';
	if ( OSAM_TS_LOGIN ) {
		$selector .= ', form#loginform, form#registerform, form#lostpasswordform, form[action*="wp-login.php"]';
	}
	$config = wp_json_encode(
		array(
			'worker'   => OSAM_TS_WORKER,
			'field'    => OSAM_TS_FIELD,
			'selector' => $selector,
		)
	);
	?>
<script id="osam-turnstile">
(function (cfg) {
  var SEL = cfg.selector;
  var loading = null;

  function load() {
    if (window.osamCaptcha) return Promise.resolve(window.osamCaptcha);
    if (loading) return loading;
    loading = new Promise(function (resolve, reject) {
      var s = document.createElement("script");
      s.src = cfg.worker + "/captcha.js";
      s.async = true;
      s.onload = function () { window.osamCaptcha ? resolve(window.osamCaptcha) : reject(new Error("captcha.js loaded without its API")); };
      s.onerror = function () { reject(new Error("captcha.js failed to load")); };
      document.head.appendChild(s);
    });
    loading.catch(function () { loading = null; });
    return loading;
  }

  function prepare(form) {
    if (form.__osamTs) return;
    form.__osamTs = true;
    // /captcha.js puts the (normally invisible) check in the form marked data-osam-form
    if (!document.querySelector("form[data-osam-form]")) form.setAttribute("data-osam-form", "");
    load().then(function (c) { c.ready(); }).catch(function (e) { console.warn("OSAM Turnstile:", e.message); });
  }

  function field(form) {
    var f = form.querySelector('input[name="' + cfg.field + '"]');
    if (!f) {
      f = document.createElement("input");
      f.type = "hidden";
      f.name = cfg.field;
      form.appendChild(f);
    }
    return f;
  }

  document.addEventListener("focusin", function (e) {
    var form = e.target && e.target.closest ? e.target.closest(SEL) : null;
    if (form) prepare(form);
  }, true);

  // Capture phase, so this runs before Elementor's own submit handler on the form
  document.addEventListener("submit", function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches(SEL)) return;
    if (form.__osamPass) { form.__osamPass = false; return; } // token added: let Elementor send it
    e.preventDefault();
    e.stopImmediatePropagation();
    if (form.__osamBusy) return;
    form.__osamBusy = true;
    var submitter = e.submitter;
    prepare(form);
    load()
      .then(function (c) { return c.execute(); })
      .then(function (token) { field(form).value = token; },
            // "unavailable": WordPress still asks the worker, and only lets it through if the worker is down
            function (err) { field(form).value = "unavailable"; console.warn("OSAM Turnstile:", err && err.message); })
      .then(function () {
        // Next tick: a token that's already waiting arrives while the browser is still handling the
        // visitor's submit, and browsers ignore a second submit at that point
        setTimeout(function () {
          form.__osamBusy = false;
          form.__osamPass = true;
          if (form.requestSubmit) {
            try {
              submitter && submitter.form === form ? form.requestSubmit(submitter) : form.requestSubmit();
              form.__osamPass = false; // in case the browser stopped it (e.g. a field became invalid)
              return;
            } catch (x) {}
          }
          form.__osamPass = false; // older browsers: run Elementor's jQuery handler directly (no second submit event)
          if (window.jQuery) window.jQuery(form).trigger("submit");
        }, 0);
      });
  }, true);
})(<?php echo $config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode ?>);
</script>
	<?php
}
add_action( 'wp_footer', 'osam_ts_footer_script', 99 );
add_action( 'login_footer', 'osam_ts_footer_script', 99 );

/** The token from the hidden field (empty if the visitor's browser never added one). */
function osam_ts_posted_token() {
	return isset( $_POST[ OSAM_TS_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ OSAM_TS_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by the form's own handler
}

function osam_ts_message() {
	return __( 'Sorry, we couldn\'t confirm you\'re not a robot. Please refresh the page and try again.', 'osam-turnstile' );
}

/**
 * True for a login, registration or lost-password request sent from wp-login.php (or a renamed login URL,
 * or a form that posts to it, such as Elementor's Login widget). Logins made elsewhere - WooCommerce
 * My Account, XML-RPC, application passwords, WP-CLI - are left alone: they don't send a token.
 */
function osam_ts_is_login_request() {
	return OSAM_TS_LOGIN
		&& did_action( 'login_init' )
		&& isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
}

/**
 * Login: runs after WordPress has checked the password. A failed Turnstile check replaces the result with
 * an error, so the visitor isn't logged in even with the right password.
 *
 * @param WP_User|WP_Error|null $user Result so far.
 * @return WP_User|WP_Error|null
 */
function osam_ts_authenticate( $user ) {
	if ( ! osam_ts_is_login_request() || empty( $_POST['log'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return $user;
	}
	if ( is_wp_error( $user ) && array_intersect( $user->get_error_codes(), array( 'empty_username', 'empty_password' ) ) ) {
		return $user; // let WordPress say what's missing
	}
	$result = osam_ts_verify( osam_ts_posted_token() );
	if ( $result['ok'] ) {
		return $user;
	}
	error_log( 'OSAM Turnstile: login refused (' . $result['status'] . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	return new WP_Error( 'osam_turnstile', '<strong>' . __( 'Error:', 'osam-turnstile' ) . '</strong> ' . osam_ts_message() );
}
add_filter( 'authenticate', 'osam_ts_authenticate', 30 );

/**
 * Registration (if the site allows it) and lost password: add an error, which stops WordPress going on.
 *
 * @param WP_Error $errors Errors so far.
 * @param string   $what   'registration' or 'lost password', for the log.
 */
function osam_ts_check_into( $errors, $what ) {
	if ( ! osam_ts_is_login_request() || ! is_wp_error( $errors ) ) {
		return $errors;
	}
	$result = osam_ts_verify( osam_ts_posted_token() );
	if ( ! $result['ok'] ) {
		error_log( 'OSAM Turnstile: ' . $what . ' refused (' . $result['status'] . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		$errors->add( 'osam_turnstile', '<strong>' . __( 'Error:', 'osam-turnstile' ) . '</strong> ' . osam_ts_message() );
	}
	return $errors;
}
add_filter( 'registration_errors', function ( $errors ) { return osam_ts_check_into( $errors, 'registration' ); }, 30 );
add_action( 'lostpassword_post', function ( $errors ) { osam_ts_check_into( $errors, 'lost password' ); }, 30 );

/**
 * Asks the worker whether the token is good. Fails open (lets the enquiry through) if the worker can't be
 * reached, so an outage never loses real enquiries.
 *
 * @param string $token Token from the hidden field.
 * @return array{ok: bool, status: string}
 */
function osam_ts_verify( $token ) {
	if ( '' === $token ) {
		return array( 'ok' => false, 'status' => 'no token' );
	}
	$ip = '';
	foreach ( array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ) as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) && filter_var( wp_unslash( $_SERVER[ $key ] ), FILTER_VALIDATE_IP ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$ip = wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			break;
		}
	}
	$response = wp_remote_post(
		OSAM_TS_WORKER . '/verify',
		array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'token' => $token,
					'site'  => home_url( '/' ), // the site's own address, never taken from the visitor
					'ip'    => $ip,
				)
			),
		)
	);
	$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	$body = $code ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
	if ( ! $code || $code >= 500 || ! is_array( $body ) || ! isset( $body['success'] ) ) {
		error_log( 'OSAM Turnstile: worker unreachable (' . ( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code ) . '), enquiry let through' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		return array( 'ok' => true, 'status' => 'not verified (worker unreachable)' );
	}
	return array(
		'ok'     => (bool) $body['success'],
		'status' => isset( $body['status'] ) ? (string) $body['status'] : '',
	);
}

/**
 * Elementor Pro forms: refuse the submission unless the Turnstile token checks out.
 *
 * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record       The submission.
 * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler Collects errors for the response.
 */
function osam_ts_elementor_validation( $record, $ajax_handler ) {
	$result = osam_ts_verify( osam_ts_posted_token() );
	if ( $result['ok'] ) {
		return;
	}
	error_log( 'OSAM Turnstile: Elementor form submission refused (' . $result['status'] . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	$message = osam_ts_message();
	// Elementor shows field errors under the field, so attach it to the form's first field as well
	$fields   = (array) $record->get( 'fields' );
	$field_id = $fields ? (string) array_key_first( $fields ) : OSAM_TS_FIELD;
	$ajax_handler->add_error( $field_id, $message );
	$ajax_handler->add_error_message( $message );
}
add_action( 'elementor_pro/forms/validation', 'osam_ts_elementor_validation', 5, 2 );

/**
 * Divi Contact Form module. Divi has no "before sending" hook: it processes the form while rendering the
 * page (ET_Builder_Module_Contact_Form::render) and only sends the email when the form's nonce is valid.
 * So, before the page renders, a failed check removes that nonce. Divi then sends nothing, doesn't fire
 * et_pb_contact_form_submit, and shows its "Please refresh the page and try again." error, which is
 * reworded to the Turnstile message for this request.
 */
function osam_ts_divi_check() {
	$forms = array();
	foreach ( array_keys( $_POST ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification -- Divi checks its own nonce
		if ( preg_match( '/^et_pb_contactform_submit_(\d+)$/', (string) $key, $m )
			&& empty( $_POST[ 'et_pb_contact_et_number_' . $m[1] ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- honeypot filled: Divi drops it anyway
			$forms[] = $m[1];
		}
	}
	if ( ! $forms ) {
		return;
	}
	$result = osam_ts_verify( osam_ts_posted_token() );
	if ( $result['ok'] ) {
		return;
	}
	error_log( 'OSAM Turnstile: Divi contact form submission refused (' . $result['status'] . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	foreach ( $forms as $n ) {
		unset( $_POST[ '_wpnonce-et-pb-contact-form-submitted-' . $n ], $_REQUEST[ '_wpnonce-et-pb-contact-form-submitted-' . $n ] );
	}
	add_filter(
		'gettext',
		function ( $translation, $text, $domain ) {
			return 'et_builder' === $domain && 'Please refresh the page and try again.' === $text ? osam_ts_message() : $translation;
		},
		10,
		3
	);
}
add_action( 'wp', 'osam_ts_divi_check', 1 );
