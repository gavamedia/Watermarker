<?php
/**
 * Watermarker uninstall handler.
 *
 * Cleans up plugin options and transients when the plugin is deleted
 * via the WordPress admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-temp-storage.php';

// Remove plugin options.
delete_option( 'watermarker_url_slug' );
delete_option( 'watermarker_letterhead_id' );
delete_option( 'watermarker_show_logo' );
delete_option( 'watermarker_show_site_name' );

// Remove all temp directories, including the legacy uploads-based directory used
// by older plugin versions before temp storage moved to a private location.
Watermarker_Temp_Storage::purge_all();

// Clean up transients with wildcarded keys.
//
// The plugin stores per-request download tokens, rate-limit counters, and
// processing locks under dynamic keys. Direct SQL is the simplest reliable way
// to remove the whole family during uninstall.
global $wpdb;
if ( isset( $wpdb ) ) {
    $patterns = [
        '_transient_watermarker_dl_',
        '_transient_timeout_watermarker_dl_',
        '_transient_watermarker_rate_',
        '_transient_timeout_watermarker_rate_',
        '_transient_watermarker_rate_office_',
        '_transient_timeout_watermarker_rate_office_',
        '_transient_watermarker_lock_',
        '_transient_timeout_watermarker_lock_',
    ];

    foreach ( $patterns as $pattern ) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $pattern ) . '%'
            )
        );
    }
}
