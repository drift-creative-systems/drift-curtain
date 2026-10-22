<?php
/**
 * Plugin Name:       Drift: Curtain
 * Plugin URI:        https://driftcreativesystems.co.uk/
 * Description:       Show visitors a customisable maintenance page with a proper 503 status while you keep working. Scheduling, client preview links, an IP allowlist and an optional site password.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Drift Creative Systems
 * Author URI:        https://driftcreativesystems.co.uk/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       drift-curtain
 *
 * @package Drift_Curtain
 */

defined( 'ABSPATH' ) || exit;

/*
|--------------------------------------------------------------------------
| Duplicate install guard
|--------------------------------------------------------------------------
| A second copy (e.g. a GitHub "Source code" zip unpacked alongside the real
| folder) would fatal on redeclared functions. Bail and tell the admin instead.
*/
if ( defined( 'DRIFT_CURTAIN_VERSION' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Drift: Curtain is installed more than once. Please delete the duplicate plugin folder.', 'drift-curtain' ) . '</p></div>';
		}
	);
	return;
}

define( 'DRIFT_CURTAIN_VERSION', '1.0.0' );
define( 'DRIFT_CURTAIN_FILE', __FILE__ );
define( 'DRIFT_CURTAIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'DRIFT_CURTAIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DRIFT_CURTAIN_PAGE_SLUG', 'drift-curtain' );
define( 'DRIFT_CURTAIN_PREVIEW_PARAM', 'drift_curtain_preview' );
define( 'DRIFT_CURTAIN_PREVIEW_COOKIE', 'drift_curtain_preview' );
define( 'DRIFT_CURTAIN_PASSWORD_COOKIE', 'drift_curtain_access' );
define( 'DRIFT_CURTAIN_PASSWORD_MAX_ATTEMPTS', 5 );
define( 'DRIFT_CURTAIN_PASSWORD_LOCKOUT', 15 * MINUTE_IN_SECONDS );
define( 'DRIFT_CURTAIN_SNAPSHOT_FILE', 'drift-curtain.html' );
define( 'DRIFT_CURTAIN_DROPIN_MARKER', 'Drift: Curtain drop-in' );

/**
 * NOTE ON ESCAPING (read before "fixing" this):
 * The "Embed code / HTML" field exists so a trusted administrator can add
 * embeds (a countdown, a sign-up form, a video) to the maintenance page, so
 * it is printed unescaped. wp_kses_post() would strip the <iframe> and
 * <script> tags it exists to output.
 *
 * The trade-off is contained by:
 *  - Changing it needs unfiltered_html, the core capability that already
 *    allows raw scripts in posts. On multisite only super admins have it;
 *    DISALLOW_UNFILTERED_HTML removes it for everyone. Without it the field
 *    is read-only and a save keeps the stored value unchanged.
 *  - Nonce and capability checks through the Settings API.
 *  - The textarea in wp-admin uses esc_textarea(), so code can't break out.
 *
 * Every other value is escaped on output.
 */

/*
|--------------------------------------------------------------------------
| Options
|--------------------------------------------------------------------------
*/

/**
 * Option defaults, keyed without the drift_curtain_ prefix. Settings are only
 * registered in wp-admin, so front-end reads go through drift_curtain_option()
 * to get these defaults.
 *
 * @return array<string, mixed>
 */
function drift_curtain_defaults() {
	return array(
		'enabled'                 => 0,
		'override_wp_maintenance' => 0,
		'show_main_content'       => 1,
		'schedule_enabled'        => 0,
		'schedule_start'          => '',
		'schedule_end'            => '',
		'preview_token'           => '',
		'ip_allowlist'            => '',
		'site_password'           => '',
		'password_days'           => 7,
		'logo'                    => '',
		'background_image'        => '',
		'bg_colour'               => '#ffffff',
		'font_colour'             => '#111111',
		'badge_text'              => '',
		'header_text'             => '',
		'main_content'            => '',
		'embed_content'           => '',
		'footer_text'             => '',
		'linkedin'                => '',
		'facebook'                => '',
		'instagram'               => '',
		'seo_title'               => '',
		'seo_description'         => '',
	);
}

/**
 * Reads a plugin option, falling back to its default.
 *
 * @param string $key Option key without the drift_curtain_ prefix.
 * @return mixed
 */
function drift_curtain_option( $key ) {
	$defaults = drift_curtain_defaults();
	$default  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';

	return get_option( 'drift_curtain_' . $key, $default );
}

/**
 * Parses a datetime-local value ("2026-10-22T14:30") in the site's timezone.
 *
 * @param string $value Stored datetime string.
 * @return int|false Unix timestamp, or false if blank or invalid.
 */
function drift_curtain_parse_datetime( $value ) {
	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		return false;
	}

	try {
		$date = new DateTimeImmutable( $value, wp_timezone() );
	} catch ( Exception $e ) {
		return false;
	}

	return $date->getTimestamp();
}

/*
|--------------------------------------------------------------------------
| Front-end gate
|--------------------------------------------------------------------------
*/

add_action( 'template_redirect', 'drift_curtain_maybe_show_page', 1 );

/**
 * Intercepts front-end requests and serves the maintenance page.
 * Passes through admin, REST, AJAX, cron, feeds, previews and anyone allowed
 * to bypass it.
 *
 * @return void
 */
function drift_curtain_maybe_show_page() {
	if ( ! drift_curtain_is_active() ) {
		return;
	}
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( is_preview() || is_feed() ) {
		return;
	}
	if ( drift_curtain_user_can_bypass() ) {
		return;
	}
	if ( drift_curtain_check_preview_bypass() || drift_curtain_check_ip_allowlist() || drift_curtain_has_password_access() ) {
		return;
	}

	/**
	 * Filters whether the current visitor sees the live site instead of the
	 * maintenance page, after the built-in checks have said no.
	 *
	 * @param bool $bypass Default false.
	 */
	if ( apply_filters( 'drift_curtain_can_bypass', false ) ) {
		return;
	}

	// Redirects and exits on a correct password; otherwise returns an error message (or '').
	$password_error = drift_curtain_handle_password_submission();

	status_header( 503 );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Retry-After: ' . drift_curtain_get_retry_after() );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside drift_curtain_render_page(); see the escaping note at the top of this file.
	echo drift_curtain_render_page(
		array(
			'password_form'  => true,
			'password_error' => $password_error,
		)
	);
	exit;
}

/**
 * Whether maintenance mode is currently on, accounting for the manual toggle
 * and the optional start/end schedule.
 *
 * @return bool True if visitors should see the maintenance page.
 */
function drift_curtain_is_active() {
	if ( ! drift_curtain_option( 'schedule_enabled' ) ) {
		$active = (bool) drift_curtain_option( 'enabled' );
	} else {
		$now      = time();
		$start_ts = drift_curtain_parse_datetime( drift_curtain_option( 'schedule_start' ) );
		$end_ts   = drift_curtain_parse_datetime( drift_curtain_option( 'schedule_end' ) );

		if ( ! $start_ts && ! $end_ts ) {
			// Schedule on but no dates set: fall back to the manual toggle.
			$active = (bool) drift_curtain_option( 'enabled' );
		} else {
			$active = ! ( $start_ts && $now < $start_ts ) && ! ( $end_ts && $now > $end_ts );
		}
	}

	/**
	 * Filters whether maintenance mode is on.
	 *
	 * @param bool $active Whether visitors should see the maintenance page.
	 */
	return (bool) apply_filters( 'drift_curtain_is_active', $active );
}

/**
 * Whether the logged-in user can see the live site.
 *
 * @return bool
 */
function drift_curtain_user_can_bypass() {
	/**
	 * Filters the capability that sees the live site while maintenance mode is on.
	 *
	 * @param string $capability Default manage_options.
	 */
	$capability = (string) apply_filters( 'drift_curtain_bypass_capability', 'manage_options' );

	return is_user_logged_in() && current_user_can( $capability );
}

/**
 * Retry-After value in seconds: the time until the scheduled end, or one hour.
 *
 * @return int
 */
function drift_curtain_get_retry_after() {
	$seconds = HOUR_IN_SECONDS;

	if ( drift_curtain_option( 'schedule_enabled' ) ) {
		$end_ts = drift_curtain_parse_datetime( drift_curtain_option( 'schedule_end' ) );
		if ( $end_ts && $end_ts > time() ) {
			$seconds = max( MINUTE_IN_SECONDS, $end_ts - time() );
		}
	}

	/**
	 * Filters the Retry-After header value.
	 *
	 * @param int $seconds Seconds until crawlers should try again.
	 */
	return absint( apply_filters( 'drift_curtain_retry_after', $seconds ) );
}

/**
 * The visitor's IP address, sanitised.
 *
 * @return string IP address, or an empty string if unavailable.
 */
function drift_curtain_visitor_ip() {
	if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
		return '';
	}

	$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * Checks the visitor's IP against the allowlist.
 *
 * @return bool True if the visitor's IP is on the allowlist.
 */
function drift_curtain_check_ip_allowlist() {
	$list = (string) drift_curtain_option( 'ip_allowlist' );
	if ( '' === trim( $list ) ) {
		return false;
	}

	$visitor_ip = drift_curtain_visitor_ip();
	if ( '' === $visitor_ip ) {
		return false;
	}

	$allowed = preg_split( '/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY );

	return in_array( $visitor_ip, $allowed, true );
}

/**
 * Checks for a valid preview token, in the query string or in a cookie set
 * by an earlier valid request, so the client doesn't need to keep the
 * parameter on every page.
 *
 * @return bool True if the visitor should bypass maintenance mode.
 */
function drift_curtain_check_preview_bypass() {
	$token = (string) drift_curtain_option( 'preview_token' );
	if ( '' === $token ) {
		return false;
	}

	$expected = hash( 'sha256', $token );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token is the credential; a nonce can't apply to a shared link.
	if ( isset( $_GET[ DRIFT_CURTAIN_PREVIEW_PARAM ] ) ) {
		$supplied = sanitize_text_field( wp_unslash( $_GET[ DRIFT_CURTAIN_PREVIEW_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( hash_equals( $token, $supplied ) ) {
			drift_curtain_set_cookie( DRIFT_CURTAIN_PREVIEW_COOKIE, $expected, time() + DAY_IN_SECONDS );
			return true;
		}
	}

	if ( isset( $_COOKIE[ DRIFT_CURTAIN_PREVIEW_COOKIE ] ) ) {
		$cookie = sanitize_text_field( wp_unslash( $_COOKIE[ DRIFT_CURTAIN_PREVIEW_COOKIE ] ) );
		if ( hash_equals( $expected, $cookie ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Sets an HTTP-only, SameSite=Lax cookie on the site's cookie path.
 *
 * @param string $name    Cookie name.
 * @param string $value   Cookie value.
 * @param int    $expires Unix timestamp, or 0 for a browser-session cookie.
 * @return void
 */
function drift_curtain_set_cookie( $name, $value, $expires ) {
	if ( headers_sent() ) {
		return;
	}

	setcookie(
		$name,
		$value,
		array(
			'expires'  => $expires,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => (string) COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
}

/*
|--------------------------------------------------------------------------
| Password access
|--------------------------------------------------------------------------
*/

/**
 * HMAC signature for a password access cookie. The stored password hash is
 * part of the signed data, so changing or removing the password invalidates
 * every cookie issued before.
 *
 * @param int    $expiry Unix timestamp the cookie is valid until.
 * @param string $hash   Stored password hash.
 * @return string Hex HMAC signature.
 */
function drift_curtain_password_cookie_signature( $expiry, $hash ) {
	return hash_hmac( 'sha256', $expiry . '|' . $hash, wp_salt( 'auth' ) );
}

/**
 * Whether the visitor holds a valid, unexpired password access cookie.
 *
 * @return bool True if the visitor has already entered the correct password.
 */
function drift_curtain_has_password_access() {
	$hash = (string) drift_curtain_option( 'site_password' );
	if ( '' === $hash || empty( $_COOKIE[ DRIFT_CURTAIN_PASSWORD_COOKIE ] ) ) {
		return false;
	}

	$raw   = sanitize_text_field( wp_unslash( $_COOKIE[ DRIFT_CURTAIN_PASSWORD_COOKIE ] ) );
	$parts = explode( '|', $raw );
	if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
		return false;
	}

	$expiry = (int) $parts[0];
	if ( $expiry < time() ) {
		return false;
	}

	return hash_equals( drift_curtain_password_cookie_signature( $expiry, $hash ), $parts[1] );
}

/**
 * Issues the password access cookie. "Remember for 0 days" gives a
 * browser-session cookie, still capped server-side at 24 hours.
 *
 * @param string $hash Stored password hash.
 * @return void
 */
function drift_curtain_set_password_cookie( $hash ) {
	$days   = (int) drift_curtain_option( 'password_days' );
	$expiry = time() + ( $days > 0 ? $days * DAY_IN_SECONDS : DAY_IN_SECONDS );

	drift_curtain_set_cookie(
		DRIFT_CURTAIN_PASSWORD_COOKIE,
		$expiry . '|' . drift_curtain_password_cookie_signature( $expiry, $hash ),
		$days > 0 ? $expiry : 0
	);
}

/**
 * Processes a password submitted on the maintenance page. On success it sets
 * the access cookie and redirects back to the requested URL, so a refresh
 * doesn't resubmit the form. Failed attempts are throttled per IP.
 *
 * @return string Error message for the form, or '' if nothing was submitted.
 */
function drift_curtain_handle_password_submission() {
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['drift_curtain_password'] ) ) {
		return '';
	}

	$hash = (string) drift_curtain_option( 'site_password' );
	if ( '' === $hash ) {
		return '';
	}

	if ( ! isset( $_POST['drift_curtain_access_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['drift_curtain_access_nonce'] ) ), 'drift_curtain_access' ) ) {
		return __( 'Your session expired. Please try again.', 'drift-curtain' );
	}

	$lock_key = 'drift_curtain_pw_fail_' . md5( drift_curtain_visitor_ip() );
	$failures = (int) get_transient( $lock_key );
	if ( $failures >= DRIFT_CURTAIN_PASSWORD_MAX_ATTEMPTS ) {
		return __( 'Too many attempts. Please try again in 15 minutes.', 'drift-curtain' );
	}

	// Not sanitised: any character is valid in a password, and it is only compared against the hash.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$supplied = wp_unslash( $_POST['drift_curtain_password'] );

	if ( is_string( $supplied ) && '' !== $supplied && wp_check_password( $supplied, $hash ) ) {
		delete_transient( $lock_key );
		drift_curtain_set_password_cookie( $hash );

		$redirect = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		wp_safe_redirect( $redirect, 303 );
		exit;
	}

	set_transient( $lock_key, $failures + 1, DRIFT_CURTAIN_PASSWORD_LOCKOUT );

	return __( 'Incorrect password. Please try again.', 'drift-curtain' );
}

/**
 * The "Have a password?" disclosure and form. A native <details> element, so
 * no JavaScript is needed.
 *
 * @param string $error Error message from a failed attempt, or ''.
 * @return string Escaped HTML.
 */
function drift_curtain_render_password_form( $error ) {
	ob_start();
	?>
	<details class="curtain-access"<?php echo $error ? ' open' : ''; ?>>
		<summary><?php esc_html_e( 'Have a password?', 'drift-curtain' ); ?></summary>
		<form method="post" action="" class="curtain-access__form">
			<label for="drift_curtain_password"><?php esc_html_e( 'Site password', 'drift-curtain' ); ?></label>
			<div class="curtain-access__row">
				<input type="password" name="drift_curtain_password" id="drift_curtain_password" autocomplete="current-password" required<?php echo $error ? ' aria-invalid="true" aria-describedby="drift_curtain_password_error" autofocus' : ''; ?>>
				<button type="submit"><?php esc_html_e( 'Enter site', 'drift-curtain' ); ?></button>
			</div>
			<?php if ( $error ) : ?>
			<p class="curtain-access__error" id="drift_curtain_password_error" role="alert"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<?php wp_nonce_field( 'drift_curtain_access', 'drift_curtain_access_nonce', false ); ?>
		</form>
	</details>
	<?php
	return (string) ob_get_clean();
}

/*
|--------------------------------------------------------------------------
| Maintenance page template
|--------------------------------------------------------------------------
*/

/**
 * Renders the full HTML maintenance page.
 *
 * @param array $args {
 *     Optional. Render options.
 *
 *     @type bool   $password_form  Whether to include the password form (if a password is set).
 *                                  False for the static snapshot, which can't process a POST.
 *     @type string $password_error Error message from a failed password attempt.
 * }
 * @return string Complete HTML document.
 */
function drift_curtain_render_page( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'password_form'  => false,
			'password_error' => '',
		)
	);

	$password_html = ( $args['password_form'] && '' !== drift_curtain_option( 'site_password' ) )
		? drift_curtain_render_password_form( $args['password_error'] )
		: '';

	$site_name    = get_bloginfo( 'name' );
	$logo         = (string) drift_curtain_option( 'logo' );
	$bg_image     = (string) drift_curtain_option( 'background_image' );
	$bg_colour    = sanitize_hex_color( drift_curtain_option( 'bg_colour' ) );
	$font_colour  = sanitize_hex_color( drift_curtain_option( 'font_colour' ) );
	$badge_text   = trim( (string) drift_curtain_option( 'badge_text' ) );
	$header       = trim( (string) drift_curtain_option( 'header_text' ) );
	$main_safe    = wp_kses_post( (string) drift_curtain_option( 'main_content' ) );
	$embed_code   = trim( (string) drift_curtain_option( 'embed_content' ) );
	$footer_text  = (string) drift_curtain_option( 'footer_text' );
	$show_content = (int) drift_curtain_option( 'show_main_content' );
	$seo_title    = trim( (string) drift_curtain_option( 'seo_title' ) );
	$seo_desc     = trim( (string) drift_curtain_option( 'seo_description' ) );

	if ( ! $bg_colour ) {
		$bg_colour = '#ffffff';
	}
	if ( ! $font_colour ) {
		$font_colour = '#111111';
	}
	if ( '' === $badge_text ) {
		$badge_text = __( 'Scheduled maintenance', 'drift-curtain' );
	}
	if ( '' === $header ) {
		$header = __( "We'll be back soon!", 'drift-curtain' );
	}

	$page_title = '' !== $seo_title
		? $seo_title
		/* translators: %s: site name. */
		: sprintf( __( '%s – Scheduled maintenance', 'drift-curtain' ), $site_name );

	$socials = array(
		'linkedin'  => __( 'LinkedIn', 'drift-curtain' ),
		'facebook'  => __( 'Facebook', 'drift-curtain' ),
		'instagram' => __( 'Instagram', 'drift-curtain' ),
	);
	$links   = array();
	foreach ( $socials as $key => $label ) {
		$url = (string) drift_curtain_option( $key );
		if ( '' !== $url ) {
			$links[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>';
		}
	}

	ob_start();
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="UTF-8">
	<meta name="robots" content="noindex, nofollow">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo esc_html( $page_title ); ?></title>
	<?php if ( '' !== $seo_desc ) : ?>
	<meta name="description" content="<?php echo esc_attr( $seo_desc ); ?>">
	<?php endif; ?>
	<style>
		:root {
			--curtain-bg: <?php echo esc_attr( $bg_colour ); ?>;
			--curtain-color: <?php echo esc_attr( $font_colour ); ?>;
		}
		* { box-sizing: border-box; }
		body {
			text-align: center;
			padding: 50px;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Helvetica Neue", Arial, sans-serif;
			font-size: 16px;
			color: var(--curtain-color);
			background: var(--curtain-bg);
			min-height: 100vh;
			margin: 0;
		}
		.curtain-bg {
			position: fixed;
			inset: 0;
			background-size: cover;
			background-position: center;
			background-repeat: no-repeat;
			z-index: -1;
		}
		h1 { font-size: 26px; line-height: 1.2; padding-bottom: 10px; margin: 0 0 10px; }
		article {
			display: block;
			text-align: left;
			max-width: 720px;
			margin: 0 auto;
			background: rgba(255, 255, 255, .85);
			border-radius: 16px;
			padding: 28px;
			box-shadow: 0 6px 24px rgba(0, 0, 0, .06);
			color: var(--curtain-color);
		}
		article p { margin: 0 0 16px; }
		.logo img { max-width: 220px; margin: 0 0 20px; height: auto; }
		.socials { margin-top: 16px; }
		.socials a { text-decoration: none; color: var(--curtain-color); }
		.socials a:hover { text-decoration: underline; }
		footer { margin-top: 40px; font-size: 13px; opacity: .8; }
		.badge {
			display: inline-block;
			padding: 4px 8px;
			background: var(--curtain-color);
			color: var(--curtain-bg);
			border-radius: 999px;
			font-size: 12px;
			margin-bottom: 16px;
		}
		.curtain-empty { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
		.curtain-access { margin-top: 24px; font-size: 14px; }
		.curtain-access summary { cursor: pointer; text-decoration: underline; display: inline-block; }
		.curtain-access summary:focus-visible,
		.curtain-access input:focus-visible,
		.curtain-access button:focus-visible { outline: 2px solid var(--curtain-color); outline-offset: 2px; }
		.curtain-access__form { margin-top: 12px; }
		.curtain-access__form label { display: block; margin-bottom: 6px; font-weight: 600; }
		.curtain-access__row { display: flex; flex-wrap: wrap; gap: 8px; }
		.curtain-access__row input { flex: 1 1 180px; padding: 8px 10px; font-size: 16px; border: 1px solid #767676; border-radius: 6px; }
		.curtain-access__row button { padding: 8px 16px; font-size: 16px; border: 1px solid var(--curtain-color); border-radius: 6px; background: transparent; color: var(--curtain-color); cursor: pointer; }
		.curtain-access__error { margin: 8px 0 0; color: #b00020; }
		.curtain-access-card {
			position: fixed;
			left: 50%;
			bottom: 24px;
			transform: translateX(-50%);
			width: calc(100% - 32px);
			max-width: 400px;
			text-align: left;
			background: rgba(255, 255, 255, .9);
			border-radius: 12px;
			padding: 4px 20px 20px;
			box-shadow: 0 6px 24px rgba(0, 0, 0, .08);
			color: var(--curtain-color);
		}
	</style>
</head>
<body>

	<?php if ( '' !== $bg_image ) : ?>
	<div class="curtain-bg" style="background-image:url('<?php echo esc_url( $bg_image ); ?>');" role="presentation" aria-hidden="true"></div>
	<?php endif; ?>

	<?php if ( $show_content ) : ?>
	<article>

		<?php if ( '' !== $logo ) : ?>
		<div class="logo">
			<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $site_name ); ?>">
		</div>
		<?php endif; ?>

		<span class="badge"><?php echo esc_html( $badge_text ); ?></span>

		<h1><?php echo esc_html( $header ); ?></h1>

		<?php if ( '' !== $main_safe ) : ?>
		<div class="curtain-main">
			<?php echo $main_safe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitised with wp_kses_post() above. ?>
		</div>
		<?php endif; ?>

		<?php if ( '' !== $embed_code ) : ?>
		<div class="curtain-embed">
			<?php echo $embed_code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted admin embed code, saved only with unfiltered_html; see the escaping note at the top of this file. ?>
		</div>
		<?php endif; ?>

		<?php if ( $links ) : ?>
		<div class="socials">
			<?php echo implode( ' &nbsp;&bull;&nbsp; ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link is escaped where it is built above. ?>
		</div>
		<?php endif; ?>

		<?php echo $password_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside drift_curtain_render_password_form(). ?>

		<footer>
			<p>
				&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?>
				<?php if ( '' !== $footer_text ) : ?>
					<?php echo esc_html( $footer_text ); ?>
				<?php endif; ?>
			</p>
		</footer>

	</article>
	<?php else : ?>
	<div class="curtain-empty" aria-hidden="true"></div>
		<?php if ( $password_html ) : ?>
	<div class="curtain-access-card">
			<?php echo $password_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside drift_curtain_render_password_form(). ?>
	</div>
		<?php endif; ?>
	<?php endif; ?>

</body>
</html>
	<?php
	$html = (string) ob_get_clean();

	/**
	 * Filters the maintenance page HTML, for the live page and the static snapshot.
	 *
	 * @param string $html Complete HTML document.
	 * @param array  $args Render options.
	 */
	return (string) apply_filters( 'drift_curtain_page_html', $html, $args );
}

/*
|--------------------------------------------------------------------------
| Settings (Settings → Drift: Curtain)
|--------------------------------------------------------------------------
*/

/**
 * Settings tabs, sections and fields, in display order. Each tab saves
 * through its own option group: options.php blanks every option in a group
 * that isn't in the submitted form, so tabs can't share one.
 *
 * Field keys are option keys without the drift_curtain_ prefix. `type` picks
 * the renderer in drift_curtain_render_field().
 *
 * @return array<string, array>
 */
function drift_curtain_settings_schema() {
	return array(
		'status'  => array(
			'label'    => __( 'Status', 'drift-curtain' ),
			'sections' => array(
				'status'   => array(
					'title'  => __( 'Status', 'drift-curtain' ),
					'fields' => array(
						'enabled'                 => array(
							'label'    => __( 'Maintenance mode', 'drift-curtain' ),
							'type'     => 'checkbox',
							'text'     => __( 'Show the maintenance page to visitors', 'drift-curtain' ),
							'sanitize' => 'drift_curtain_sanitize_checkbox',
						),
						'show_main_content'       => array(
							'label'       => __( 'Show content', 'drift-curtain' ),
							'type'        => 'checkbox',
							'text'        => __( 'Show the logo, badge, heading, content, embeds, social links and footer', 'drift-curtain' ),
							'description' => __( 'Untick to show only the background image or colour.', 'drift-curtain' ),
							'sanitize'    => 'drift_curtain_sanitize_checkbox',
						),
						'override_wp_maintenance' => array(
							'label'    => __( 'WordPress update screen', 'drift-curtain' ),
							'type'     => 'override',
							'text'     => __( 'Also show this page while WordPress installs updates', 'drift-curtain' ),
							'sanitize' => 'drift_curtain_sanitize_checkbox',
						),
					),
				),
				'schedule' => array(
					'title'  => __( 'Schedule', 'drift-curtain' ),
					'fields' => array(
						'schedule_enabled' => array(
							'label'       => __( 'Scheduled maintenance', 'drift-curtain' ),
							'type'        => 'checkbox',
							'text'        => __( 'Turn on and off automatically using the dates below', 'drift-curtain' ),
							'description' => __( 'When ticked, the dates below control maintenance mode instead of the switch above.', 'drift-curtain' ),
							'sanitize'    => 'drift_curtain_sanitize_checkbox',
						),
						'schedule_start'   => array(
							'label'       => __( 'Start', 'drift-curtain' ),
							'type'        => 'datetime',
							'description' => __( 'Leave blank to start straight away. Uses the site timezone (Settings → General).', 'drift-curtain' ),
							'sanitize'    => 'drift_curtain_sanitize_datetime',
						),
						'schedule_end'     => array(
							'label'       => __( 'End', 'drift-curtain' ),
							'type'        => 'datetime',
							'description' => __( 'Leave blank to turn it off yourself. Also sets the Retry-After header for search engines.', 'drift-curtain' ),
							'sanitize'    => 'drift_curtain_sanitize_datetime',
						),
					),
				),
			),
		),
		'access'  => array(
			'label'    => __( 'Preview & access', 'drift-curtain' ),
			'sections' => array(
				'access' => array(
					'title'  => __( 'Preview & access', 'drift-curtain' ),
					'fields' => array(
						'preview_token' => array(
							'label'    => __( 'Preview link', 'drift-curtain' ),
							'type'     => 'preview_token',
							'sanitize' => 'drift_curtain_sanitize_line',
						),
						'ip_allowlist'  => array(
							'label'       => __( 'IP allowlist', 'drift-curtain' ),
							'type'        => 'textarea',
							'rows'        => 3,
							'placeholder' => '203.0.113.10, 203.0.113.11',
							'description' => __( 'IP addresses, separated by commas or new lines, that always see the live site.', 'drift-curtain' ),
							'sanitize'    => 'drift_curtain_sanitize_ip_list',
						),
						'site_password' => array(
							'label'    => __( 'Site password', 'drift-curtain' ),
							'type'     => 'password',
							'sanitize' => 'drift_curtain_sanitize_site_password',
						),
						'password_days' => array(
							'label'       => __( 'Remember password for', 'drift-curtain' ),
							'type'        => 'number',
							'min'         => 0,
							'max'         => 365,
							'suffix'      => __( 'days', 'drift-curtain' ),
							'description' => __( 'How long access lasts after entering the password. Use 0 to end access when the browser closes (at most 24 hours).', 'drift-curtain' ),
							'sanitize'    => 'drift_curtain_sanitize_password_days',
						),
					),
				),
			),
		),
		'design'  => array(
			'label'    => __( 'Design', 'drift-curtain' ),
			'sections' => array(
				'design' => array(
					'title'  => __( 'Design', 'drift-curtain' ),
					'fields' => array(
						'logo'             => array(
							'label'    => __( 'Logo', 'drift-curtain' ),
							'type'     => 'media',
							'sanitize' => 'esc_url_raw',
						),
						'background_image' => array(
							'label'       => __( 'Background image', 'drift-curtain' ),
							'type'        => 'media',
							'description' => __( 'Fills the page, in front of the background colour.', 'drift-curtain' ),
							'sanitize'    => 'esc_url_raw',
						),
						'bg_colour'        => array(
							'label'       => __( 'Background colour', 'drift-curtain' ),
							'type'        => 'colour',
							'description' => __( 'Shown when there is no background image.', 'drift-curtain' ),
							'sanitize'    => 'sanitize_hex_color',
						),
						'font_colour'      => array(
							'label'       => __( 'Text colour', 'drift-curtain' ),
							'type'        => 'colour',
							'description' => __( 'Headings, body text, the badge and social links.', 'drift-curtain' ),
							'sanitize'    => 'sanitize_hex_color',
						),
					),
				),
			),
		),
		'content' => array(
			'label'    => __( 'Content & SEO', 'drift-curtain' ),
			'sections' => array(
				'content' => array(
					'title'  => __( 'Content', 'drift-curtain' ),
					'fields' => array(
						'badge_text'    => array(
							'label'       => __( 'Badge text', 'drift-curtain' ),
							'type'        => 'text',
							'placeholder' => __( 'Scheduled maintenance', 'drift-curtain' ),
							'sanitize'    => 'drift_curtain_sanitize_line',
						),
						'header_text'   => array(
							'label'       => __( 'Heading', 'drift-curtain' ),
							'type'        => 'text',
							'placeholder' => __( "We'll be back soon!", 'drift-curtain' ),
							'sanitize'    => 'drift_curtain_sanitize_line',
						),
						'main_content'  => array(
							'label'    => __( 'Main content', 'drift-curtain' ),
							'type'     => 'editor',
							'sanitize' => 'wp_kses_post',
						),
						'embed_content' => array(
							'label'    => __( 'Embed code / HTML', 'drift-curtain' ),
							'type'     => 'embed',
							'sanitize' => 'drift_curtain_sanitize_embed',
						),
						'footer_text'   => array(
							'label'       => __( 'Footer text', 'drift-curtain' ),
							'type'        => 'text',
							'description' => __( 'Shown after the © year. Leave blank to show the year only.', 'drift-curtain' ),
							'sanitize'    => 'drift_curtain_sanitize_line',
						),
					),
				),
				'social'  => array(
					'title'  => __( 'Social links', 'drift-curtain' ),
					'fields' => array(
						'linkedin'  => array(
							'label'    => __( 'LinkedIn URL', 'drift-curtain' ),
							'type'     => 'url',
							'sanitize' => 'esc_url_raw',
						),
						'facebook'  => array(
							'label'    => __( 'Facebook URL', 'drift-curtain' ),
							'type'     => 'url',
							'sanitize' => 'esc_url_raw',
						),
						'instagram' => array(
							'label'    => __( 'Instagram URL', 'drift-curtain' ),
							'type'     => 'url',
							'sanitize' => 'esc_url_raw',
						),
					),
				),
				'seo'     => array(
					'title'  => __( 'SEO', 'drift-curtain' ),
					'fields' => array(
						'seo_title'       => array(
							'label'       => __( 'Page title', 'drift-curtain' ),
							'type'        => 'text',
							/* translators: %s: site name. */
							'placeholder' => sprintf( __( '%s – Scheduled maintenance', 'drift-curtain' ), get_bloginfo( 'name' ) ),
							'sanitize'    => 'drift_curtain_sanitize_line',
						),
						'seo_description' => array(
							'label'       => __( 'Meta description', 'drift-curtain' ),
							'type'        => 'textarea',
							'rows'        => 3,
							'maxlength'   => 320,
							'placeholder' => __( 'Optional short summary of the page', 'drift-curtain' ),
							'description' => __( 'The page is always marked noindex, nofollow, so this only shows if a page is shared.', 'drift-curtain' ),
							'sanitize'    => 'drift_curtain_sanitize_meta_desc',
						),
					),
				),
			),
		),
	);
}

add_action( 'admin_init', 'drift_curtain_register_settings' );

/**
 * Registers every option, section and field from the schema.
 *
 * @return void
 */
function drift_curtain_register_settings() {
	$defaults = drift_curtain_defaults();

	foreach ( drift_curtain_settings_schema() as $tab => $tab_config ) {
		foreach ( $tab_config['sections'] as $section_id => $section ) {
			$section_key = 'drift_curtain_section_' . $section_id;
			add_settings_section( $section_key, $section['title'], '__return_false', DRIFT_CURTAIN_PAGE_SLUG );

			foreach ( $section['fields'] as $key => $field ) {
				$option = 'drift_curtain_' . $key;

				if ( in_array( $field['type'], array( 'checkbox', 'override' ), true ) ) {
					$type = 'boolean';
				} elseif ( 'number' === $field['type'] ) {
					$type = 'integer';
				} else {
					$type = 'string';
				}

				register_setting(
					'drift_curtain_' . $tab,
					$option,
					array(
						'type'              => $type,
						'sanitize_callback' => $field['sanitize'],
						'default'           => $defaults[ $key ],
						'show_in_rest'      => false,
					)
				);

				$field['key']    = $key;
				$field['option'] = $option;

				// Editors, checkboxes and multi-control fields label themselves.
				if ( ! in_array( $field['type'], array( 'checkbox', 'override', 'editor' ), true ) ) {
					$field['label_for'] = $option;
				}

				add_settings_field( $option, $field['label'], 'drift_curtain_render_field', DRIFT_CURTAIN_PAGE_SLUG, $section_key, $field );
			}
		}
	}
}

/**
 * Whether the current user may change the embed code.
 *
 * @return bool
 */
function drift_curtain_user_can_edit_embed() {
	return current_user_can( 'unfiltered_html' );
}

/**
 * Renders one settings field.
 *
 * @param array $field Field config from drift_curtain_settings_schema(), plus key and option.
 * @return void
 */
function drift_curtain_render_field( $field ) {
	$option = $field['option'];
	$value  = drift_curtain_option( $field['key'] );
	$desc   = isset( $field['description'] ) ? $field['description'] : '';

	switch ( $field['type'] ) {
		case 'checkbox':
		case 'override':
			$disabled = 'override' === $field['type'] && is_multisite();
			printf(
				'<label><input type="checkbox" name="%1$s" id="%1$s" value="1" %2$s %3$s> %4$s</label>',
				esc_attr( $option ),
				checked( 1, (int) $value, false ),
				disabled( $disabled, true, false ),
				esc_html( $field['text'] )
			);
			if ( 'override' === $field['type'] ) {
				drift_curtain_render_override_notes();
			}
			break;

		case 'text':
		case 'url':
		case 'datetime':
		case 'colour':
			$input_types = array(
				'text'     => 'text',
				'url'      => 'url',
				'datetime' => 'datetime-local',
				'colour'   => 'color',
			);
			if ( 'colour' === $field['type'] ) {
				$value = sanitize_hex_color( $value );
				if ( ! $value ) {
					$defaults = drift_curtain_defaults();
					$value    = $defaults[ $field['key'] ];
				}
			}
			printf(
				'<input type="%1$s" name="%2$s" id="%2$s" value="%3$s" class="%4$s" placeholder="%5$s">',
				esc_attr( $input_types[ $field['type'] ] ),
				esc_attr( $option ),
				esc_attr( $value ),
				in_array( $field['type'], array( 'text', 'url' ), true ) ? 'regular-text' : '',
				esc_attr( isset( $field['placeholder'] ) ? $field['placeholder'] : '' )
			);
			break;

		case 'textarea':
			printf(
				'<textarea name="%1$s" id="%1$s" class="large-text" rows="%2$d" placeholder="%3$s"%4$s>%5$s</textarea>',
				esc_attr( $option ),
				(int) $field['rows'],
				esc_attr( isset( $field['placeholder'] ) ? $field['placeholder'] : '' ),
				isset( $field['maxlength'] ) ? ' maxlength="' . (int) $field['maxlength'] . '"' : '',
				esc_textarea( $value )
			);
			break;

		case 'number':
			printf(
				'<input type="number" name="%1$s" id="%1$s" value="%2$d" min="%3$d" max="%4$d" step="1" class="small-text"> %5$s',
				esc_attr( $option ),
				(int) $value,
				(int) $field['min'],
				(int) $field['max'],
				esc_html( $field['suffix'] )
			);
			break;

		case 'media':
			drift_curtain_render_media_field( $option, (string) $value );
			break;

		case 'editor':
			wp_editor(
				(string) $value,
				$option,
				array(
					'textarea_name' => $option,
					'textarea_rows' => 10,
					'media_buttons' => false,
					'tinymce'       => array(
						'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,link,unlink,blockquote',
						'toolbar2' => '',
					),
				)
			);
			break;

		case 'embed':
			printf(
				'<textarea name="%1$s" id="%1$s" class="large-text code" rows="5" %2$s>%3$s</textarea>',
				esc_attr( $option ),
				disabled( ! drift_curtain_user_can_edit_embed(), true, false ),
				esc_textarea( $value )
			);
			$desc = drift_curtain_user_can_edit_embed()
				? __( 'Embed code or HTML shown under the main content, such as a countdown, sign-up form or video. Printed exactly as entered, so only add code from sources you trust.', 'drift-curtain' )
				: __( 'You can view this code but not change it. Changing it needs the unfiltered_html capability, which on multisite only network admins have.', 'drift-curtain' );
			break;

		case 'preview_token':
			drift_curtain_render_preview_token_field( $option, (string) $value );
			break;

		case 'password':
			drift_curtain_render_password_field( $option, (string) $value );
			break;
	}

	if ( '' !== $desc ) {
		printf( '<p class="description">%s</p>', esc_html( $desc ) );
	}
}

/**
 * Explains the update-screen override and reports any file problems under
 * its checkbox.
 *
 * @return void
 */
function drift_curtain_render_override_notes() {
	if ( is_multisite() ) {
		printf( '<p class="description">%s</p>', esc_html__( 'Not available on multisite: the update screen is shared by every site in the network.', 'drift-curtain' ) );
		return;
	}

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Adds a maintenance.php drop-in and a copy of this page to wp-content, so visitors see your page instead of the plain "Briefly unavailable" message during updates. Both files are removed when you untick this or deactivate the plugin.', 'drift-curtain' )
	);

	if ( drift_curtain_dropin_is_foreign() ) {
		printf(
			'<p class="description drift-curtain__warning">%s</p>',
			esc_html__( 'wp-content/maintenance.php already exists and wasn\'t added by Drift: Curtain, so it has been left alone.', 'drift-curtain' )
		);
	} elseif ( ! drift_curtain_filesystem() ) {
		printf(
			'<p class="description drift-curtain__warning">%s</p>',
			esc_html__( 'WordPress can\'t write to wp-content directly on this server, so the update screen can\'t be replaced.', 'drift-curtain' )
		);
	}
}

/**
 * URL field with media library picker, remove button and thumbnail preview.
 *
 * @param string $option Option name.
 * @param string $value  Current image URL.
 * @return void
 */
function drift_curtain_render_media_field( $option, $value ) {
	printf(
		'<div class="drift-curtain__media">
			<input type="url" name="%1$s" id="%1$s" value="%2$s" class="regular-text" placeholder="https://">
			<button type="button" class="button drift-curtain-media-select" data-field="%1$s">%3$s</button>
			<button type="button" class="button drift-curtain-media-remove" data-field="%1$s" id="%1$s_remove"%4$s>%5$s</button>
		</div>
		<img id="%1$s_preview" class="drift-curtain__media-preview" src="%2$s" alt=""%4$s>',
		esc_attr( $option ),
		esc_attr( $value ),
		esc_html__( 'Choose image', 'drift-curtain' ),
		$value ? '' : ' hidden',
		esc_html__( 'Remove', 'drift-curtain' )
	);
}

/**
 * Preview token field, with a generator and the shareable link once saved.
 *
 * @param string $option Option name.
 * @param string $token  Current token.
 * @return void
 */
function drift_curtain_render_preview_token_field( $option, $token ) {
	printf(
		'<div class="drift-curtain__media">
			<input type="text" name="%1$s" id="%1$s" value="%2$s" class="regular-text" autocomplete="off" aria-describedby="%1$s_description">
			<button type="button" class="button" id="drift_curtain_generate_token">%3$s</button>
		</div>
		<p class="description" id="%1$s_description">%4$s</p>',
		esc_attr( $option ),
		esc_attr( $token ),
		esc_html__( 'Generate', 'drift-curtain' ),
		esc_html__( 'Generate a token and save, to get a link that shows the live site to anyone who has it, for a day at a time. Change or clear the token to stop the link working.', 'drift-curtain' )
	);

	if ( '' !== $token ) {
		$link = add_query_arg( DRIFT_CURTAIN_PREVIEW_PARAM, rawurlencode( $token ), home_url( '/' ) );
		printf(
			'<p><label for="drift_curtain_preview_link"><strong>%1$s</strong></label><br><input type="text" id="drift_curtain_preview_link" readonly value="%2$s" class="large-text code drift-curtain-select-on-focus"></p>',
			esc_html__( 'Share this link:', 'drift-curtain' ),
			esc_url( $link )
		);
	}
}

/**
 * Site password field. The stored hash is never printed.
 *
 * @param string $option Option name.
 * @param string $hash   Stored password hash.
 * @return void
 */
function drift_curtain_render_password_field( $option, $hash ) {
	$is_set = '' !== $hash;

	printf(
		'<input type="password" name="%1$s" id="%1$s" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s">
		<p class="description">%3$s</p>',
		esc_attr( $option ),
		esc_attr( $is_set ? __( 'Leave blank to keep the current password', 'drift-curtain' ) : '' ),
		esc_html__( 'Visitors can click "Have a password?" on the maintenance page and enter this to see the site. It is stored hashed, so it can\'t be shown again after saving. Changing it signs out everyone who used the old one.', 'drift-curtain' )
	);

	if ( $is_set ) {
		printf(
			'<p><strong>%1$s</strong></p><label><input type="checkbox" name="drift_curtain_site_password_clear" value="1"> %2$s</label>',
			esc_html__( 'A password is set.', 'drift-curtain' ),
			esc_html__( 'Remove password', 'drift-curtain' )
		);
	}
}

/*
|--------------------------------------------------------------------------
| Admin screen
|--------------------------------------------------------------------------
*/

add_action( 'admin_menu', 'drift_curtain_add_menu' );

/**
 * Adds Settings → Drift: Curtain.
 *
 * @return void
 */
function drift_curtain_add_menu() {
	add_options_page(
		__( 'Drift: Curtain', 'drift-curtain' ),
		__( 'Drift: Curtain', 'drift-curtain' ),
		'manage_options',
		DRIFT_CURTAIN_PAGE_SLUG,
		'drift_curtain_render_settings_page'
	);
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'drift_curtain_add_settings_link' );

/**
 * Adds a Settings link on the Plugins screen.
 *
 * @param array $links Action links.
 * @return array
 */
function drift_curtain_add_settings_link( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . DRIFT_CURTAIN_PAGE_SLUG ) ) . '">' . esc_html__( 'Settings', 'drift-curtain' ) . '</a>';
	array_unshift( $links, $settings_link );

	return $links;
}

add_action( 'admin_enqueue_scripts', 'drift_curtain_admin_assets' );

/**
 * Styles, the media library and the settings script, on the settings screen only.
 *
 * @param string $hook_suffix Current admin page.
 * @return void
 */
function drift_curtain_admin_assets( $hook_suffix ) {
	if ( 'settings_page_' . DRIFT_CURTAIN_PAGE_SLUG !== $hook_suffix ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_style( 'drift-curtain-admin', DRIFT_CURTAIN_URL . 'assets/admin.css', array(), DRIFT_CURTAIN_VERSION );
	wp_enqueue_script( 'drift-curtain-admin', DRIFT_CURTAIN_URL . 'assets/admin.js', array( 'jquery' ), DRIFT_CURTAIN_VERSION, true );
	wp_localize_script(
		'drift-curtain-admin',
		'driftCurtainAdmin',
		array(
			'mediaTitle'  => __( 'Select image', 'drift-curtain' ),
			'mediaButton' => __( 'Use this image', 'drift-curtain' ),
		)
	);
}

/**
 * Drift monogram. Fixed markup; only the fill is variable.
 *
 * @param string $fill Fill colour.
 * @param int    $size Width and height in px.
 * @return string
 */
function drift_curtain_mark_svg( $fill = 'currentColor', $size = 32 ) {
	$paths = array(
		'M17 12H54C77 12 91 29 91 50C91 72 76 88 54 88H45L55 76H54C70 76 79 64 79 50C79 35 69 24 54 24H29V44H17Z',
		'M17 55H29L17 67Z',
		'M41 55H54L25 88H12Z',
		'M55 76H70L59 88H43Z',
	);

	$svg = sprintf( '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 100 100" aria-hidden="true" focusable="false">', (int) $size );
	foreach ( $paths as $d ) {
		$svg .= '<path fill="' . esc_attr( $fill ) . '" d="' . esc_attr( $d ) . '"/>';
	}

	return $svg . '</svg>';
}

/**
 * Settings screen: tabs, then one card per section of the current tab.
 *
 * @return void
 */
function drift_curtain_render_settings_page() {
	global $wp_settings_sections;

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$schema = drift_curtain_settings_schema();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'status';
	if ( ! isset( $schema[ $tab ] ) ) {
		$tab = 'status';
	}

	$registered = isset( $wp_settings_sections[ DRIFT_CURTAIN_PAGE_SLUG ] ) ? (array) $wp_settings_sections[ DRIFT_CURTAIN_PAGE_SLUG ] : array();
	$active     = drift_curtain_is_active();
	$page_url   = admin_url( 'options-general.php?page=' . DRIFT_CURTAIN_PAGE_SLUG );
	?>
	<div class="wrap drift-curtain">
		<h1 class="drift-curtain__title">
			<span class="drift-curtain__mark"><?php echo drift_curtain_mark_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup, attributes escaped in drift_curtain_mark_svg(). ?></span>
			<?php echo esc_html( get_admin_page_title() ); ?>
			<?php if ( $active ) : ?>
				<span class="drift-curtain__status drift-curtain__status--on"><?php esc_html_e( 'Maintenance mode is on', 'drift-curtain' ); ?></span>
			<?php else : ?>
				<span class="drift-curtain__status"><?php esc_html_e( 'Site is live', 'drift-curtain' ); ?></span>
			<?php endif; ?>
		</h1>

		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Drift: Curtain settings', 'drift-curtain' ); ?>">
			<?php foreach ( $schema as $slug => $tab_config ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $page_url ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $slug === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tab_config['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>

		<form method="post" action="options.php">
			<?php settings_fields( 'drift_curtain_' . $tab ); ?>

			<?php
			foreach ( array_keys( $schema[ $tab ]['sections'] ) as $section_id ) :
				$section_key = 'drift_curtain_section_' . $section_id;
				if ( ! isset( $registered[ $section_key ] ) ) {
					continue;
				}
				?>
				<section class="drift-curtain__card" aria-labelledby="<?php echo esc_attr( $section_key ); ?>-title">
					<h2 class="drift-curtain__card-title" id="<?php echo esc_attr( $section_key ); ?>-title"><?php echo esc_html( $registered[ $section_key ]['title'] ); ?></h2>
					<table class="form-table" role="presentation">
						<?php do_settings_fields( DRIFT_CURTAIN_PAGE_SLUG, $section_key ); ?>
					</table>
				</section>
			<?php endforeach; ?>

			<?php
			/**
			 * Fires after the current tab's fields, inside the settings form.
			 *
			 * @param string $tab Current tab slug.
			 */
			do_action( 'drift_curtain_settings_after_fields', $tab );

			submit_button();
			?>
		</form>
	</div>
	<?php
}

/*
|--------------------------------------------------------------------------
| WordPress update screen (wp-content/maintenance.php drop-in)
|--------------------------------------------------------------------------
| While core, plugin or theme updates run, WordPress loads
| wp-content/maintenance.php if it exists. With the override on we write a
| fixed drop-in (includes/maintenance-dropin.php) plus a static HTML copy of
| the page for it to print. Nothing is written while the override is off.
*/

register_activation_hook( __FILE__, 'drift_curtain_sync_files' );
register_deactivation_hook( __FILE__, 'drift_curtain_remove_files' );

/**
 * WP_Filesystem, but only when it can write directly. These writes happen in
 * the background (activation, after a save), where there's no way to ask
 * for FTP credentials.
 *
 * @return WP_Filesystem_Base|null
 */
function drift_curtain_filesystem() {
	global $wp_filesystem;

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	if ( 'direct' !== get_filesystem_method( array(), WP_CONTENT_DIR ) || ! WP_Filesystem() ) {
		return null;
	}

	return $wp_filesystem;
}

/**
 * Path to the drop-in.
 *
 * @return string
 */
function drift_curtain_dropin_path() {
	return trailingslashit( WP_CONTENT_DIR ) . 'maintenance.php';
}

/**
 * Path to the static page the drop-in prints.
 *
 * @return string
 */
function drift_curtain_snapshot_path() {
	return trailingslashit( WP_CONTENT_DIR ) . DRIFT_CURTAIN_SNAPSHOT_FILE;
}

/**
 * Whether a maintenance.php exists that this plugin didn't write.
 *
 * @return bool
 */
function drift_curtain_dropin_is_foreign() {
	$path = drift_curtain_dropin_path();
	if ( ! file_exists( $path ) ) {
		return false;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file; reading only needs PHP, not WP_Filesystem credentials.
	$contents = file_get_contents( $path );

	return false === $contents || false === strpos( $contents, DRIFT_CURTAIN_DROPIN_MARKER );
}

/**
 * Writes or removes the drop-in and snapshot to match the override setting.
 *
 * @return void
 */
function drift_curtain_sync_files() {
	if ( is_multisite() || ! drift_curtain_option( 'override_wp_maintenance' ) ) {
		drift_curtain_remove_files();
		return;
	}

	$fs = drift_curtain_filesystem();
	if ( ! $fs ) {
		return;
	}

	$fs->put_contents( drift_curtain_snapshot_path(), drift_curtain_render_page(), FS_CHMOD_FILE );

	if ( ! drift_curtain_dropin_is_foreign() ) {
		$fs->copy( DRIFT_CURTAIN_PATH . 'includes/maintenance-dropin.php', drift_curtain_dropin_path(), true, FS_CHMOD_FILE );
	}
}

/**
 * Removes the snapshot, and the drop-in if this plugin wrote it.
 *
 * @return void
 */
function drift_curtain_remove_files() {
	$snapshot = drift_curtain_snapshot_path();
	$dropin   = drift_curtain_dropin_path();

	if ( ! file_exists( $snapshot ) && ( ! file_exists( $dropin ) || drift_curtain_dropin_is_foreign() ) ) {
		return;
	}

	$fs = drift_curtain_filesystem();
	if ( ! $fs ) {
		return;
	}

	if ( $fs->exists( $snapshot ) ) {
		$fs->delete( $snapshot );
	}
	if ( $fs->exists( $dropin ) && ! drift_curtain_dropin_is_foreign() ) {
		$fs->delete( $dropin );
	}
}

add_action( 'updated_option', 'drift_curtain_maybe_queue_sync' );
add_action( 'added_option', 'drift_curtain_maybe_queue_sync' );

/**
 * Queues a file sync, once, at the end of any request that saves one of this
 * plugin's options.
 *
 * @param string $option Option name.
 * @return void
 */
function drift_curtain_maybe_queue_sync( $option ) {
	if ( 0 === strpos( $option, 'drift_curtain_' ) && ! has_action( 'shutdown', 'drift_curtain_sync_files' ) ) {
		add_action( 'shutdown', 'drift_curtain_sync_files' );
	}
}

/*
|--------------------------------------------------------------------------
| Sanitisation
|--------------------------------------------------------------------------
*/

/**
 * Sanitises a checkbox to 0 or 1.
 *
 * @param mixed $value Raw input.
 * @return int
 */
function drift_curtain_sanitize_checkbox( $value ) {
	return (int) (bool) $value;
}

/**
 * Sanitises a single line of text: strips tags, collapses whitespace, trims.
 *
 * @param mixed $value Raw input.
 * @return string
 */
function drift_curtain_sanitize_line( $value ) {
	$value = is_string( $value ) ? $value : '';
	$value = wp_strip_all_tags( $value, true );

	return trim( preg_replace( '/\s+/', ' ', $value ) );
}

/**
 * Sanitises the meta description: a single line, at most 320 characters.
 *
 * @param mixed $value Raw input.
 * @return string
 */
function drift_curtain_sanitize_meta_desc( $value ) {
	return mb_substr( drift_curtain_sanitize_line( $value ), 0, 320 );
}

/**
 * Sanitises a datetime-local value, discarding anything that doesn't parse.
 *
 * @param mixed $value Raw input.
 * @return string
 */
function drift_curtain_sanitize_datetime( $value ) {
	$value = is_string( $value ) ? sanitize_text_field( $value ) : '';

	return drift_curtain_parse_datetime( $value ) ? $value : '';
}

/**
 * Sanitises a comma or newline separated list of IP addresses, dropping
 * anything that isn't a valid IP.
 *
 * @param mixed $value Raw input.
 * @return string Comma-separated list of valid IPs.
 */
function drift_curtain_sanitize_ip_list( $value ) {
	$value = is_string( $value ) ? $value : '';
	$parts = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
	$valid = array_filter(
		$parts,
		function ( $ip ) {
			return false !== filter_var( $ip, FILTER_VALIDATE_IP );
		}
	);

	return implode( ', ', $valid );
}

/**
 * Keeps the embed code as entered for users with unfiltered_html. Anyone
 * else can't change it, so the stored value is kept as it was.
 *
 * @param mixed $value Raw input.
 * @return string
 */
function drift_curtain_sanitize_embed( $value ) {
	$saved = (string) get_option( 'drift_curtain_embed_content', '' );

	if ( ! drift_curtain_user_can_edit_embed() ) {
		return $saved;
	}

	return is_string( $value ) ? trim( $value ) : '';
}

/**
 * Hashes a newly entered site password. A blank submission keeps the
 * existing hash, and the "Remove password" checkbox clears it.
 *
 * The result is cached per request because WordPress runs the sanitise
 * callback twice when an option is first created (update_option →
 * add_option), which would otherwise hash the hash.
 *
 * @param mixed $value Raw input (already unslashed by options.php).
 * @return string Password hash, or an empty string if no password is set.
 */
function drift_curtain_sanitize_site_password( $value ) {
	static $result = null;
	if ( null !== $result ) {
		return $result;
	}

	// Nonce already verified by options.php before sanitise callbacks run.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! empty( $_POST['drift_curtain_site_password_clear'] ) ) {
		$result = '';
	} elseif ( is_string( $value ) && '' !== $value ) {
		// Not sanitised: any character is valid in a password, and only the hash is stored.
		$result = wp_hash_password( $value );
	} else {
		$result = (string) get_option( 'drift_curtain_site_password', '' );
	}

	return $result;
}

/**
 * Sanitises the "remember password" duration to whole days, 0–365.
 *
 * @param mixed $value Raw input.
 * @return int
 */
function drift_curtain_sanitize_password_days( $value ) {
	return min( 365, absint( $value ) );
}
