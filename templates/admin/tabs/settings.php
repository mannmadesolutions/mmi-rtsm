<?php
/**
 * RTSM Settings Tab
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get settings manager instance
$settings_manager = RTSM_Settings_Manager::get_instance();

// Handle form submission
// Both callers (RTSM_Admin::render_page / ajax_load_tab) already check the
// capability; checked again here because this template writes settings.
if (isset($_POST['rtsm_save_settings']) && !rtsm_user_can()) {
    rtsm_audit('settings.update', ['outcome' => 'denied']);
    return;
}
if (isset($_POST['rtsm_save_settings']) && check_admin_referer('rtsm_settings')) {
    $rtsm_before = [
        'rtsm_show_admin_bar'        => (int) $settings_manager->get('rtsm_show_admin_bar', 1),
        'rtsm_show_dashboard_widget' => (int) $settings_manager->get('rtsm_show_dashboard_widget', 1),
        'rtsm_refresh_interval'      => (int) $settings_manager->get_refresh_interval(),
        'rtsm_auto_maintenance'      => (int) $settings_manager->get('rtsm_auto_maintenance', 0),
    ];

    $settings_manager->set('rtsm_show_admin_bar', isset($_POST['rtsm_show_admin_bar']) ? 1 : 0);
    $settings_manager->set('rtsm_show_dashboard_widget', isset($_POST['rtsm_show_dashboard_widget']) ? 1 : 0);
    
    $refresh_interval = isset($_POST['rtsm_refresh_interval']) ? intval($_POST['rtsm_refresh_interval']) : 10;
    $refresh_interval = max(5, min(60, $refresh_interval));
    $settings_manager->set_refresh_interval($refresh_interval);

    if (isset($_POST['rtsm_auto_maintenance'])) {
        $settings_manager->set('rtsm_auto_maintenance', (int) $_POST['rtsm_auto_maintenance'] === 1 ? 1 : 0);
    }

    $rtsm_after = [
        'rtsm_show_admin_bar'        => (int) $settings_manager->get('rtsm_show_admin_bar', 1),
        'rtsm_show_dashboard_widget' => (int) $settings_manager->get('rtsm_show_dashboard_widget', 1),
        'rtsm_refresh_interval'      => (int) $settings_manager->get_refresh_interval(),
        'rtsm_auto_maintenance'      => (int) $settings_manager->get('rtsm_auto_maintenance', 0),
    ];
    // Key names only — none of these are secret, but the audit log policy is names, not values.
    rtsm_audit('settings.update', [
        'object_type' => 'settings',
        'outcome'     => 'success',
        'details'     => ['changed_keys' => array_keys(array_diff_assoc($rtsm_after, $rtsm_before))],
    ]);
    
    echo wp_kses_post(RTSM_UI_Helpers::render_notice('Settings saved successfully!', 'success'));
}

// Get current settings values
$refresh_interval = $settings_manager->get_refresh_interval();
$show_admin_bar = $settings_manager->get('rtsm_show_admin_bar', 1);
$show_dashboard_widget = $settings_manager->get('rtsm_show_dashboard_widget', 1);

$auto_maintenance = $settings_manager->get('rtsm_auto_maintenance', 0);

// Get platform info
$platform_info = class_exists('RTSM_Platform_Detector')
    ? RTSM_Platform_Detector::get_instance()->get_platform_info()
    : ['platform' => php_uname('s') . ' ' . php_uname('r')];

$rtsm_save_btn = '<button type="submit" name="rtsm_save_settings" class="button button-primary"><span class="dashicons dashicons-saved"></span> Save Settings</button>';
?>

<?php
// License: the shared suite panel (MMI_License_UI) — status, key, activate /
// deactivate — instead of RTSM's own card, which linked to the deleted
// mmi-hub page.
if ( class_exists( 'MMI_License_UI' ) ) {
    MMI_License_UI::render_panel( 'mmi-rtsm', 'Server Monitor' );
}
?>

<?php /* One form for both settings sections: the save handler reads every
         field, so a second form without the display checkboxes used to
         switch the admin bar stats and dashboard widget off. */ ?>
<form method="post" action="">
    <?php wp_nonce_field('rtsm_settings'); ?>

    <div class="mmi-process-section">
        <?php echo RTSM_UI_Helpers::section_header('admin-settings', 'General Settings', 'Refresh rate and where the live stats appear.', '', $rtsm_save_btn); ?>
        <div class="mmi-section-content">
            <div class="mmi-label-grid mmi-label-grid--start">
                <div class="mmi-label-grid-row">
                    <label for="rtsm_refresh_interval"><strong>Auto-Refresh Interval</strong></label>
                    <div>
                        <select name="rtsm_refresh_interval" id="rtsm_refresh_interval">
                            <option value="5" <?php selected($refresh_interval, 5); ?>>5 seconds</option>
                            <option value="10" <?php selected($refresh_interval, 10); ?>>10 seconds</option>
                            <option value="15" <?php selected($refresh_interval, 15); ?>>15 seconds</option>
                            <option value="30" <?php selected($refresh_interval, 30); ?>>30 seconds</option>
                            <option value="60" <?php selected($refresh_interval, 60); ?>>1 minute</option>
                        </select>
                        <span class="mmi-hint-text">How often to refresh stats in the dashboard widget and admin bar.</span>
                    </div>
                </div>
                <div class="mmi-label-grid-row">
                    <span><strong>Display Options</strong></span>
                    <div class="mmi-label-grid-value">
                        <label>
                            <input type="checkbox" name="rtsm_show_admin_bar" value="1" <?php checked($show_admin_bar, 1); ?> />
                            Show server stats in admin bar
                        </label>
                        <label>
                            <input type="checkbox" name="rtsm_show_dashboard_widget" value="1" <?php checked($show_dashboard_widget, 1); ?> />
                            Show dashboard widget
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mmi-process-section">
        <?php echo RTSM_UI_Helpers::section_header('shield-alt', 'Auto-Remediation', 'Optionally take the site offline for visitors while load is critical.', '', $rtsm_save_btn); ?>
        <div class="mmi-section-content">
            <p>When enabled, RTSM will automatically write <code>ABSPATH/.maintenance</code> at Critical and Emergency load levels, taking the site offline for non-admin visitors until load recovers. Incident logging, alerts and the <code>rtsm_critical_load</code> / <code>rtsm_emergency_mode_*</code> hooks happen regardless of this setting.</p>

            <div class="mmi-info-card warning">
                <p><strong>⚠️ Think before enabling.</strong> Auto-maintenance protects the server during genuine DDoS events, but will also fire during legitimate traffic spikes (flash sales, viral content). Turning on Under Attack Mode in your Cloudflare dashboard is a safer first response and does not take your site offline.</p>
            </div>

            <div class="mmi-label-grid mmi-label-grid--start">
                <div class="mmi-label-grid-row">
                    <span><strong>Automatic Maintenance Mode</strong></span>
                    <div>
                        <label>
                            <input type="hidden" name="rtsm_auto_maintenance" value="0" />
                            <input type="checkbox" name="rtsm_auto_maintenance" value="1" <?php checked($auto_maintenance, 1); ?> />
                            Enable auto-maintenance at Critical &amp; Emergency thresholds
                        </label>
                        <span class="mmi-hint-text">Off by default. Grace periods still apply — Critical requires 3&nbsp;checks spanning 120&nbsp;seconds of sustained load; Emergency requires 2&nbsp;checks spanning 60&nbsp;seconds.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<div class="mmi-process-section">
    <?php echo RTSM_UI_Helpers::section_header('info', 'System Information', 'Versions and paths, for support requests.'); ?>
    <div class="mmi-section-content">
        <div class="mmi-table-scroll-wrapper">
            <table class="mmi-uniform-table">
                <tbody>
                    <tr><th scope="row">Plugin Version</th><td><?php echo esc_html(RTSM_VERSION); ?></td></tr>
                    <tr><th scope="row">WordPress Version</th><td><?php echo esc_html(get_bloginfo('version')); ?></td></tr>
                    <tr><th scope="row">PHP Version</th><td><?php echo esc_html(PHP_VERSION); ?></td></tr>
                    <tr><th scope="row">Web Server</th><td><?php echo esc_html($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'); ?></td></tr>
                    <tr><th scope="row">Platform</th><td><?php echo esc_html($platform_info['platform']); ?></td></tr>
                    <tr><th scope="row">Plugin Directory</th><td><code><?php echo esc_html(RTSM_PLUGIN_DIR); ?></code></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
