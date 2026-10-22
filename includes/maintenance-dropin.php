<?php
/**
 * Drift: Curtain drop-in for wp-content/maintenance.php.
 *
 * Drift: Curtain copies this file to wp-content/maintenance.php when
 * "Also show this page while WordPress installs updates" is ticked, and
 * removes it again when that's unticked or the plugin is deactivated.
 * WordPress loads it while updates run, before plugins are loaded, so it
 * can't use plugin functions. It prints the static copy of the maintenance
 * page saved alongside it.
 *
 * The marker "Drift: Curtain drop-in" above tells the plugin this file is its
 * own; it never overwrites or deletes a maintenance.php without it.
 *
 * @package Drift_Curtain
 */

defined( 'ABSPATH' ) || exit;

if ( ! headers_sent() ) {
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) && 'HTTP/1.0' === $_SERVER['SERVER_PROTOCOL'] ? 'HTTP/1.0' : 'HTTP/1.1' ) . ' 503 Service Unavailable' );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Retry-After: 600' );
	header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
}

$drift_curtain_page = __DIR__ . '/drift-curtain.html';

if ( is_readable( $drift_curtain_page ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Runs before WordPress has loaded WP_Filesystem.
	readfile( $drift_curtain_page );
} else {
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>Maintenance</title></head><body><h1>Briefly unavailable for scheduled maintenance. Check back in a minute.</h1></body></html>';
}

exit;
