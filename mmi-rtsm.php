<?php
/**
 * Plugin Name: MMI Real-Time Server Monitor
 * Plugin URI: https://mannmade.solutions/plugins/mmi-rtsm
 * Description: Real-time server performance monitoring for WordPress. Included with all MMI Suite licenses.
 * Version: 2.8.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author: MannMade Solutions
 * Author URI: https://mannmade.solutions
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Text Domain: mmi-rtsm
 */

// ────────────────────────────────────────────────────────────────
// 1. Prevent Direct Access
// ────────────────────────────────────────────────────────────────
if (!defined('ABSPATH')) {
    exit;
}

// ────────────────────────────────────────────────────────────────
// 2. Plugin Constants
// ────────────────────────────────────────────────────────────────
define('RTSM_VERSION', '2.8.0');
define('RTSM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RTSM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RTSM_PLUGIN_FILE', __FILE__);

/**
 * The one capability that gates every RTSM admin screen and AJAX action.
 *
 * Defaults to manage_options (unchanged from before). A site can grant
 * least-privilege access to a custom role via the filter, e.g.
 * add_filter( 'rtsm_required_capability', fn() => 'rtsm_monitor' ).
 *
 * @return string
 */
function rtsm_required_capability() {
    $cap = apply_filters( 'rtsm_required_capability', 'manage_options' );
    return ( is_string( $cap ) && $cap !== '' ) ? $cap : 'manage_options';
}

/**
 * Whether the current user may use RTSM (view traffic/visitor data, kill
 * processes, toggle Cloudflare Under Attack Mode, change settings).
 *
 * @return bool
 */
function rtsm_user_can() {
    return current_user_can( rtsm_required_capability() );
}

/**
 * Record a security-relevant event in the shared audit log (no-op when the
 * shared MMI_Audit_Log class isn't loaded). Never pass secret values.
 *
 * @param string $action Dot-separated verb, e.g. 'process.kill'.
 * @param array  $args   object_type, object_id, outcome, details.
 */
function rtsm_audit( $action, array $args = [] ) {
    if ( class_exists( 'MMI_Audit_Log' ) ) {
        MMI_Audit_Log::record( 'mmi-rtsm', $action, $args );
    }
}

// ── MMI Shared Library (ADR-0006, mmi-admin/docs/decisions/) ────────
// Registers this plugin's bundled copy of MMI_Settings/MMI_Logger/
// MMI_API_Throttler/MMI_Resource_Guard/MMI_Model/MMI_Product/
// MMI_Media_Helper as a version-negotiation candidate. This plugin no
// longer hard-requires MMI_Hub (see the dependency check below) — these
// classes now resolve from this bundled copy whenever mmi-hub isn't
// present, or from mmi-hub's own copy (which still wins whenever mmi-hub
// IS present — see ADR-0006). Must load before anything below could
// reference any of those class names, including mmi_shared_lib_log_dir().
require_once RTSM_PLUGIN_DIR . 'includes/mmi-shared/bootstrap.php';

// ────────────────────────────────────────────────────────────────
// 3. Load Core Files
// ────────────────────────────────────────────────────────────────
// UI Helpers and Utilities
require_once RTSM_PLUGIN_DIR . 'includes/class-ui-helpers.php';
require_once RTSM_PLUGIN_DIR . 'includes/class-tier-branding.php';

// Server Monitoring Core
require_once RTSM_PLUGIN_DIR . 'includes/class-settings-manager.php';
require_once RTSM_PLUGIN_DIR . 'includes/class-platform-detector.php';
require_once RTSM_PLUGIN_DIR . 'includes/class-environment-detector.php';
require_once RTSM_PLUGIN_DIR . 'includes/class-shared-hosting-metrics.php';
require_once RTSM_PLUGIN_DIR . 'includes/class-stats-collector.php';
require_once RTSM_PLUGIN_DIR . 'includes/class-license-manager.php';
require_once RTSM_PLUGIN_DIR . 'includes/class-traffic-logger.php';
require_once RTSM_PLUGIN_DIR . 'includes/class-server-monitor.php';

// RTSM Admin Interface
require_once RTSM_PLUGIN_DIR . 'includes/class-rtsm-admin.php';

// ────────────────────────────────────────────────────────────────
// 4. Initialize Plugin
// ────────────────────────────────────────────────────────────────
add_action('plugins_loaded', 'rtsm_init');
function rtsm_init() {
    // MMI_Hub is no longer a hard requirement (ADR-0006 /
    // MMI_HUB_ELIMINATION_HANDOFF.md, Phase 2) — every RTSM_* class's
    // MMI_Logger/MMI_Settings usage now resolves from this plugin's own
    // bundled includes/mmi-shared/ copy regardless of whether mmi-hub is
    // present. This plugin has no other hard dependency (WooCommerce isn't
    // touched anywhere in this plugin — it's server/traffic monitoring, not
    // commerce).

    // Admin menu registration always runs, licensed or not — a customer
    // with an expired trial and no active license must still be able to
    // find this plugin's own settings page to see why nothing works and
    // enter a real key (same reasoning as mmi-data-pipeline's boot gate).
    // RTSM_Admin::render_page() itself checks MMI_License_Gate::is_open()
    // before rendering the real dashboard.
    if (is_admin()) {
        RTSM_Admin::init();
        add_action('admin_notices', 'rtsm_admin_notices');
    }

    // License-or-trial guard (MMI_License_Gate, shared library — replaces
    // the old bare mmi_is_licensed() gate, which had no trial support and
    // returned here with no menu at all, leaving an unlicensed customer with
    // no way to find an activation page). Everything past this point is the
    // real feature set.
    if ( ! class_exists( 'MMI_License_Gate' ) || ! MMI_License_Gate::is_open( 'mmi-rtsm' ) ) {
        return;
    }

    // Initialize shared hosting metrics (FREE tier)
    RTSM_Shared_Hosting_Metrics::instance();

    // Initialize server monitoring
    if (class_exists('RTSM_Server_Monitor')) {
        new RTSM_Server_Monitor();
    }
}

// Show admin notices for configuration issues and active incidents
function rtsm_admin_notices() {
    // Check if monitoring is properly configured
    if (!class_exists('RTSM_Server_Monitor')) {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>MMI Real-Time Server Monitor:</strong> Core monitoring class not found. Please reinstall the plugin.';
        echo '</p></div>';
        return;
    }

    // Show a global admin-bar notice when an incident flag is active so admins
    // see it regardless of which admin page they are on. Shared with
    // mmi-cloudflare-integration (a reader too) — both must agree on the
    // same path via mmi_shared_lib_log_dir() regardless of whether mmi-hub
    // is present; they previously had different hardcoded fallbacks.
    $incident_file = rtrim(mmi_shared_lib_log_dir(), '/') . '/active-incident.flag';

    if (!file_exists($incident_file)) {
        return;
    }

    $incident = json_decode(file_get_contents($incident_file), true);
    $severity = isset($incident['severity']) ? strtoupper(esc_html($incident['severity'])) : 'CRITICAL';
    $started  = isset($incident['started_at']) ? esc_html($incident['started_at']) : '';
    $rtsm_url = esc_url(admin_url('admin.php?page=mmi-rtsm'));

    echo '<div id="mmi-incident-notice" class="notice notice-error">';
    echo '<p>';
    echo '<strong>⚠️ MMI Server Monitor — Active Incident (' . $severity . ')</strong>';
    if ($started) {
        echo ' &mdash; Started: ' . $started;
    }
    echo '. <a href="' . $rtsm_url . '">View monitor →</a>';
    echo '</p>';
    echo '</div>';
}

// ────────────────────────────────────────────────────────────────
// 5. Activation Hook
// ────────────────────────────────────────────────────────────────
register_activation_hook(__FILE__, 'rtsm_activate');
function rtsm_activate() {
    // Create upload directory for logs
    $upload_dir = WP_CONTENT_DIR . '/uploads';
    if (!file_exists($upload_dir)) {
        wp_mkdir_p($upload_dir);
    }
    
    // Initialize settings manager (will create table and migrate settings)
    $settings = RTSM_Settings_Manager::get_instance();
    
    // Set defaults for new installations (migration will preserve existing)
    if (!$settings->get('rtsm_show_admin_bar')) {
        $settings->set('rtsm_show_admin_bar', 1);
    }
    if (!$settings->get('rtsm_show_dashboard_widget')) {
        $settings->set('rtsm_show_dashboard_widget', 1);
    }
    if (!$settings->get('rtsm_refresh_interval')) {
        $settings->set('rtsm_refresh_interval', 10);
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// ────────────────────────────────────────────────────────────────
// 6. Deactivation Hook
// ────────────────────────────────────────────────────────────────
register_deactivation_hook(__FILE__, 'rtsm_deactivate');
function rtsm_deactivate() {
    // Clear all scheduled cron events.
    wp_clear_scheduled_hook( 'rtsm_periodic_check' );
    wp_clear_scheduled_hook( 'rtsm_cron_health_check' );
    wp_clear_scheduled_hook( 'rtsm_daily_license_check' );

    // Flush rewrite rules
    flush_rewrite_rules();

    // mmi-hub may already be gone by the time this fires — e.g. a
    // standalone customer deactivating Hub before removing this plugin.
    if ( class_exists( 'MMI_Logger' ) ) {
        MMI_Logger::info( 'Plugin deactivated', [], 'general', 'RTSM' );
    }
}

// ────────────────────────────────────────────────────────────────
// 7. WP-CLI Command Registration
// ────────────────────────────────────────────────────────────────
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    // @phpstan-ignore-next-line WP_CLI is available when this code runs
    \WP_CLI::add_command('rtsm stats', function() {
        // @phpstan-ignore-next-line
        $settings = RTSM_Settings_Manager::get_instance();
        
        // @phpstan-ignore-next-line
        \WP_CLI::log('Real-Time Server Monitor Statistics:');
        // @phpstan-ignore-next-line
        \WP_CLI::log('  Admin Bar: ' . ($settings->get('rtsm_show_admin_bar', 1) ? 'Enabled' : 'Disabled'));
        // @phpstan-ignore-next-line
        \WP_CLI::log('  Dashboard Widget: ' . ($settings->get('rtsm_show_dashboard_widget', 1) ? 'Enabled' : 'Disabled'));
        // @phpstan-ignore-next-line
        \WP_CLI::log('  Refresh Interval: ' . $settings->get_refresh_interval() . ' seconds');
        
        // Show current system stats if available
        if (class_exists('RTSM_Stats_Collector')) {
            $load = sys_getloadavg();
            // @phpstan-ignore-next-line
            \WP_CLI::log('  Current Load: ' . round($load[0], 2));
        }
    });
}

// ────────────────────────────────────────────────────────────────
// 9. Cron Health Monitor
// Runs hourly. Emails the admin when WP-Cron events are backing up
// (> 50 overdue events), throttled to one alert per 6 hours.
// ────────────────────────────────────────────────────────────────

add_action( 'plugins_loaded', 'rtsm_schedule_cron_health_check', 5 );
function rtsm_schedule_cron_health_check() {
    if ( ! wp_next_scheduled( 'rtsm_cron_health_check' ) ) {
        wp_schedule_event( time(), 'hourly', 'rtsm_cron_health_check' );
    }
}

add_action( 'rtsm_cron_health_check', 'rtsm_run_cron_health_check' );
function rtsm_run_cron_health_check() {
    $crons    = _get_cron_array();
    $now      = time();
    $overdue  = 0;
    $total    = 0;

    // Events must be more than 5 minutes past due before counting as overdue.
    // A 5-minute buffer prevents false positives from the 2-minute cron runner
    // lag and from events whose scheduled time just ticked past.
    $overdue_threshold = $now - 300;

    if ( ! empty( $crons ) ) {
        foreach ( $crons as $timestamp => $hooks ) {
            if ( ! is_int( $timestamp ) || empty( $hooks ) ) {
                continue;
            }
            $count = 0;
            foreach ( $hooks as $args ) {
                $count += count( $args );
            }
            $total += $count;
            if ( $timestamp < $overdue_threshold ) {
                $overdue += $count;
            }
        }
    }

    // Alert threshold: more than 50 overdue events is a sign something is wrong.
    $threshold = 50;
    if ( $overdue <= $threshold ) {
        return;
    }

    // Throttle: don't send more than one alert per 6 hours.
    if ( get_transient( 'rtsm_cron_health_alert_sent' ) ) {
        return;
    }
    set_transient( 'rtsm_cron_health_alert_sent', 1, 6 * HOUR_IN_SECONDS );

    $site_name  = get_bloginfo( 'name' );
    $admin_url  = admin_url( 'admin.php?page=mmi-rtsm' );
    $subject    = "[{$site_name}] WARNING: WP-Cron queue is backing up ({$overdue} overdue events)";

    if ( class_exists( 'MMI_Email_Templates' ) ) {
        $message = rtsm_render_cron_health_html( $overdue, $total, $threshold, $admin_url );
        wp_mail( get_option( 'admin_email' ), $subject, $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
    } else {
        // Fallback if mmi-hub's email templates somehow aren't loaded — keep the alert deliverable.
        $message = "This is an automated alert from the MannMade Solutions server monitor.\n\n"
                    . "The WordPress cron queue has backed up on {$site_name}.\n\n"
                    . "  Total queued events : {$total}\n"
                    . "  Overdue events      : {$overdue}\n"
                    . "  Alert threshold     : {$threshold}\n\n"
                    . "Check the cron event list here:\n"
                    . $admin_url . "\n\n"
                    . "Time of alert: " . current_time( 'mysql' ) . "\n";
        wp_mail( get_option( 'admin_email' ), $subject, $message );
    }
    // This cron is scheduled unconditionally at plugins_loaded/5 (below),
    // independent of rtsm_init()'s own mmi-hub guard — mmi-hub may be gone
    // by the time an already-scheduled hourly event actually fires.
    if ( class_exists( 'MMI_Logger' ) ) {
        MMI_Logger::info( "Cron health check alert sent", [ 'overdue' => $overdue, 'total' => $total ], 'general', 'RTSM' );
    }
}

/**
 * The HTML body for the cron health alert — split out so MMI VIP's Email
 * Customization preview can render the exact same markup without actually
 * sending mail or touching the once-per-6-hours throttle.
 */
function rtsm_render_cron_health_html( $overdue, $total, $threshold, $admin_url ) {
    return MMI_Email_Templates::render_alert( [
        'site_name'     => get_bloginfo( 'name' ),
        'headline'      => 'WP-Cron Queue Is Backing Up',
        'status_color'  => MMI_Email_Templates::token( 'warning' ),
        'status_label'  => 'Overdue Events Detected',
        'completed_at'  => current_time( 'F j, Y \a\t g:i a' ),
        'duration'      => '—',
        'summary_stats' => [
            [ 'label' => 'Overdue Events',  'value' => number_format( $overdue ), 'highlight' => true ],
            [ 'label' => 'Total Queued',    'value' => number_format( $total ) ],
            [ 'label' => 'Alert Threshold', 'value' => number_format( $threshold ) ],
        ],
        'detail_table_header' => '<tr><th style="padding:8px 12px;text-align:left;background:' . MMI_Email_Templates::token( 'warning_bg' ) . ';font-size:12px;text-transform:uppercase;color:' . MMI_Email_Templates::token( 'warning' ) . ';"' . MMI_Email_Templates::data_attr( [ 'warning', 'warning_bg' ] ) . '>May Affect</th></tr>',
        'detail_table_rows'   => '<tr><td style="padding:12px;font-size:13px;color:' . MMI_Email_Templates::token( 'text_dark' ) . ';"' . MMI_Email_Templates::data_attr( [ 'text_dark' ] ) . '>Scheduled product syncs, Cloudflare IP unblocking, WooCommerce background processing, SEO and plugin maintenance tasks.</td></tr>',
        'dashboard_url' => $admin_url,
        'cta_label'     => 'Check Cron Event List &rarr;',
    ] );
}

/**
 * Register this file's email type with MMI VIP's Email Customization tab
 * preview picker — see MMI_Email_Customizer::get_registered_samples().
 */
add_filter( 'mmi_email_customizer_samples', function ( $samples ) {
    // rtsm_render_cron_health_html() calls MMI_Email_Templates directly with
    // no internal guard of its own (unlike its other call site above, which
    // checks first). MMI_Email_Templates now ships bundled as part of the
    // shared library (ADR-0006, added as its 15th class 2026-09-18), so this
    // guard is kept as defensive dead code — same precedent as MMI_Settings
    // guards elsewhere in the suite — rather than assumed unreachable.
    if ( ! class_exists( 'MMI_Email_Templates' ) ) {
        return $samples;
    }

    $samples[] = [
        'id'     => 'rtsm_cron_health',
        'group'  => 'Server Monitor',
        'label'  => 'Cron Health Alert',
        'render' => function () {
            return rtsm_render_cron_health_html( 75, 120, 50, admin_url( 'admin.php?page=mmi-rtsm' ) );
        },
    ];
    return $samples;
} );