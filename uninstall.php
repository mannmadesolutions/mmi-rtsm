<?php
/**
 * MMI Real-Time Server Monitor — Uninstall
 *
 * Fired when the plugin is deleted via WP Admin → Plugins.
 * Cleans up all plugin data: options, settings, transients, cron, and log files.
 *
 * @package MMI_RTSM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/* ── 1. Clear scheduled cron events ──────────────────────────────────────── */

wp_clear_scheduled_hook( 'rtsm_periodic_check' );
wp_clear_scheduled_hook( 'rtsm_cron_health_check' );
wp_clear_scheduled_hook( 'rtsm_daily_license_check' );

/* ── 2. Delete transients ────────────────────────────────────────────────── */

$transient_keys = [
    'rtsm_check_log_ran',
    'rtsm_cpu_cores',
    'rtsm_cpu_prev_stat',
    'rtsm_critical_load_checks',
    'rtsm_critical_load_first_ts',
    'rtsm_cron_health_alert_sent',
    'rtsm_emergency_load_checks',
    'rtsm_emergency_load_first_ts',
    'rtsm_emergency_mode_triggered',
    'rtsm_license_tier',
    'rtsm_load_cause_analysis',
    'rtsm_log_purge_ran',
    'rtsm_php_fpm_workers',
    'rtsm_php_worker_info',
    'rtsm_process_cache',
    'rtsm_process_count',
    'rtsm_snapshot',
    'rtsm_stat_connections',
    'rtsm_stat_cpu',
    'rtsm_stat_disk',
    'rtsm_stat_load',
    'rtsm_stat_memory',
];

foreach ( $transient_keys as $key ) {
    delete_transient( $key );
}

/* ── 3. Delete wp_options entries ────────────────────────────────────────── */

$option_keys = [
    'rtsm_refresh_interval',
    'rtsm_show_admin_bar',
    'rtsm_show_dashboard_widget',
    'rtsm_auto_maintenance',
    'rtsm_settings_migrated',
];

foreach ( $option_keys as $key ) {
    delete_option( $key );
}

/* ── 4. Delete settings from the shared wp_mmi_settings table ────────────── */

global $wpdb;
$table_name = $wpdb->prefix . 'mmi_settings';

// Only attempt if the table exists (Hub may have been removed first).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$table_exists = $wpdb->get_var(
    $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
);

if ( $table_exists ) {
    $rtsm_setting_keys = [
        'rtsm_license_key',
        'rtsm_license_active',
        'rtsm_license_activated_date',
        'rtsm_settings_migrated',
        'rtsm_runcloud_alert_floor',
        'rtsm_refresh_interval',
        'rtsm_show_admin_bar',
        'rtsm_show_dashboard_widget',
        'rtsm_auto_maintenance',
    ];

    foreach ( $rtsm_setting_keys as $key ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $table_name, [ 'setting_key' => $key ], [ '%s' ] );
    }
}

/* ── 5. Delete log files ─────────────────────────────────────────────────── */

// Check the shared-lib log directory first, then fallback uploads directory.
$log_dirs = [];

if ( function_exists( 'mmi_shared_lib_log_dir' ) ) {
    $log_dirs[] = rtrim( mmi_shared_lib_log_dir(), '/' );
}

$upload_dir = wp_upload_dir();
$log_dirs[] = $upload_dir['basedir'];

$log_patterns = [
    'server-traffic-analysis.log',
    'server-traffic-analysis.log.*',
    'server-alerts.log',
    'server-alerts.log.*',
    'active-incident.flag',
];

foreach ( $log_dirs as $dir ) {
    foreach ( $log_patterns as $pattern ) {
        $matches = glob( $dir . '/' . $pattern );
        if ( is_array( $matches ) ) {
            foreach ( $matches as $file ) {
                if ( is_file( $file ) ) {
                    @unlink( $file );
                }
            }
        }
    }
}
