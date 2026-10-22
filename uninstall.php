<?php
/**
 * Removes the settings, and any files the plugin wrote, when it is deleted.
 *
 * @package Drift_Curtain
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$drift_curtain_options = array(
	'enabled',
	'override_wp_maintenance',
	'show_main_content',
	'schedule_enabled',
	'schedule_start',
	'schedule_end',
	'preview_token',
	'ip_allowlist',
	'site_password',
	'password_days',
	'logo',
	'background_image',
	'bg_colour',
	'font_colour',
	'badge_text',
	'header_text',
	'main_content',
	'embed_content',
	'footer_text',
	'linkedin',
	'facebook',
	'instagram',
	'seo_title',
	'seo_description',
);

if ( is_multisite() ) {
	$drift_curtain_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
} else {
	$drift_curtain_site_ids = array( 0 );
}

foreach ( $drift_curtain_site_ids as $drift_curtain_site_id ) {
	if ( $drift_curtain_site_id ) {
		switch_to_blog( $drift_curtain_site_id );
	}

	foreach ( $drift_curtain_options as $drift_curtain_option ) {
		delete_option( 'drift_curtain_' . $drift_curtain_option );
	}

	if ( $drift_curtain_site_id ) {
		restore_current_blog();
	}
}

/*
 * Deactivation normally removes these already. Delete the snapshot, and the
 * drop-in only if it is ours (it carries the marker).
 */
$drift_curtain_snapshot = trailingslashit( WP_CONTENT_DIR ) . 'drift-curtain.html';
$drift_curtain_dropin   = trailingslashit( WP_CONTENT_DIR ) . 'maintenance.php';

if ( file_exists( $drift_curtain_snapshot ) ) {
	wp_delete_file( $drift_curtain_snapshot );
}

if ( file_exists( $drift_curtain_dropin ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
	$drift_curtain_contents = file_get_contents( $drift_curtain_dropin );
	if ( false !== $drift_curtain_contents && false !== strpos( $drift_curtain_contents, 'Drift: Curtain drop-in' ) ) {
		wp_delete_file( $drift_curtain_dropin );
	}
}
