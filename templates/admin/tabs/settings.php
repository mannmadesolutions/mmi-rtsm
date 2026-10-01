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

$license_manager   = RTSM_License_Manager::get_instance();
$is_licensed       = $license_manager->is_licensed();
$is_premium        = $is_licensed; // compat alias
$license_tier      = $is_licensed ? 'licensed' : 'free';
$license_source    = $license_manager->get_license_source(); // 'suite' | 'direct' | 'legacy' | 'free'
$is_suite_source   = ( $license_source === 'suite' );
$suite_data        = $is_suite_source && class_exists( 'MMI_Settings' ) ? MMI_Settings::get( 'mmi_license_mmi-suite' ) : null;
$license_key       = $is_licensed ? $license_manager->get_license_key() : '';
// Never render the full stored key back to the browser — last 4 characters only.
if ($license_key !== '') {
    $license_key = str_repeat('•', 8) . substr($license_key, -4);
}
$activation_date   = $is_licensed ? $license_manager->get_activation_date() : '';
$days_until_expiry = $license_manager->get_days_until_expiry();
$in_grace_period   = $license_manager->is_in_grace_period();

// Get platform info
$platform_info = class_exists('RTSM_Platform_Detector') 
    ? RTSM_Platform_Detector::get_instance()->get_platform_info() 
    : ['platform' => php_uname('s') . ' ' . php_uname('r')];
?>

<div class="mmi-settings-content">
    <!-- License Status -->
    <div class="mmi-panel-card <?php echo $is_licensed ? 'license-active' : 'license-inactive'; ?>">
        <h2>
            <span class="dashicons <?php echo $is_licensed ? 'dashicons-yes-alt' : 'dashicons-lock'; ?>"></span>
            <?php echo $is_licensed ? 'MMI Suite — Licensed' : 'Free Version'; ?>
            <?php echo RTSM_Tier_Branding::render_badge( $license_tier ); ?>
        </h2>

        <?php if ($in_grace_period): ?>
            <div class="notice notice-warning inline" class="rtsm-notice-mg">
                <p><strong>Your license has expired.</strong> Features will be disabled in <?php echo esc_html(max(0, RTSM_License_Manager::GRACE_PERIOD_DAYS + $days_until_expiry)); ?> days. <a href="<?php echo esc_url($license_manager->get_upgrade_url()); ?>" target="_blank">Renew now</a> to maintain access.</p>
            </div>
        <?php elseif ($days_until_expiry !== null && $days_until_expiry <= 30 && $days_until_expiry > 0): ?>
            <div class="notice notice-info inline" class="rtsm-notice-mg">
                <p>Your license expires in <strong><?php echo esc_html($days_until_expiry); ?> days</strong>. <a href="<?php echo esc_url($license_manager->get_upgrade_url()); ?>" target="_blank">Renew now</a> for uninterrupted access.</p>
            </div>
        <?php endif; ?>

        <?php if ( $is_suite_source && $suite_data ) : ?>
            <?php
            /* Suite license is granting access — management happens at MMI Hub. */
            $hub_manage_url = admin_url( 'admin.php?page=mmi-hub#license-activation' );
            ?>
            <p>Access to all RTSM features is granted by your active <strong>MMI Suite</strong> license.</p>
            <div class="mmi-panel-stat-grid two-col">
                <div class="mmi-panel-stat-box">
                    <div class="stat-label">License Key</div>
                    <div class="stat-value monospace"><?php echo esc_html( $license_key ); ?></div>
                </div>
            </div>
            <p class="license-info">
                <a href="<?php echo esc_url( $hub_manage_url ); ?>" class="button button-secondary mmi-action-btn">
                    Manage License at MMI Hub &rarr;
                </a>
            </p>

        <?php elseif ( $is_licensed ) : ?>
            <?php /* Direct per-plugin or legacy RTSM license. */ ?>
            <div class="mmi-panel-stat-grid two-col">
                <div class="mmi-panel-stat-box">
                    <div class="stat-label">License Key</div>
                    <div class="stat-value monospace"><?php echo esc_html( $license_key ); ?></div>
                </div>
                <div class="mmi-panel-stat-box">
                    <div class="stat-label">Activated</div>
                    <div class="stat-value"><?php echo date_i18n( get_option( 'date_format' ), strtotime( $activation_date ) ); ?></div>
                </div>
            </div>
            <p class="license-info">
                <button type="button" id="rtsm-deactivate-license" class="button button-secondary mmi-action-btn">
                    Deactivate License
                </button>
            </p>

        <?php else: ?>
            <?php /* No active license — suite is the primary path. */ ?>
            <p class="upgrade-cta">You have access to basic server monitoring (CPU, Memory, Load).</p>
            <p>Activate an <strong>MMI Suite</strong> license to unlock all Pro features including Process Monitor, Intelligent Diagnostics, and Auto-Remediation.</p>
            <p class="upgrade-link">
                <a href="<?php echo esc_url(admin_url('admin.php?page=mmi-hub#license-activation')); ?>" class="button button-primary mmi-action-btn">
                    Activate Suite License at MMI Hub
                </a>
                <a href="<?php echo esc_url($license_manager->get_upgrade_url()); ?>" class="button button-secondary mmi-action-btn" target="_blank">
                    Get MMI Suite
                </a>
            </p>
            <hr>
            <p class="description"><strong>Have a standalone RTSM license key?</strong></p>
            <div class="license-activation-form">
                <input type="text" id="rtsm-license-key" class="regular-text" placeholder="MMI-RTSM-XXXX-XXXX-XXXX" />
                <button type="button" id="rtsm-activate-license" class="button button-secondary mmi-action-btn">
                    Activate License
                </button>
            </div>
            <div id="rtsm-license-message" class="license-message"></div>

        <?php endif; ?>
    </div>

    <!-- General Settings -->
    <div class="mmi-panel-card">
        <h2>
            <span class="dashicons dashicons-admin-settings"></span>
            General Settings
        </h2>
        
        <form method="post" action="">
            <?php wp_nonce_field('rtsm_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="rtsm_refresh_interval">Auto-Refresh Interval</label>
                    </th>
                    <td>
                        <select name="rtsm_refresh_interval" id="rtsm_refresh_interval">
                            <option value="5" <?php selected($refresh_interval, 5); ?>>5 seconds</option>
                            <option value="10" <?php selected($refresh_interval, 10); ?>>10 seconds</option>
                            <option value="15" <?php selected($refresh_interval, 15); ?>>15 seconds</option>
                            <option value="30" <?php selected($refresh_interval, 30); ?>>30 seconds</option>
                            <option value="60" <?php selected($refresh_interval, 60); ?>>1 minute</option>
                        </select>
                        <p class="description">How often to refresh stats in the dashboard widget and admin bar.</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Display Options</th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="rtsm_show_admin_bar" value="1" <?php checked($show_admin_bar, 1); ?> />
                                Show server stats in admin bar
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="rtsm_show_dashboard_widget" value="1" <?php checked($show_dashboard_widget, 1); ?> />
                                Show dashboard widget
                            </label>
                        </fieldset>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="submit" name="rtsm_save_settings" class="button button-primary mmi-action-btn">
                    Save Settings
                </button>
            </p>
        </form>
    </div>

    <!-- Auto-Remediation -->
    <div class="mmi-panel-card">
        <h2>
            <span class="dashicons dashicons-shield-alt"></span>
            Auto-Remediation
        </h2>

        <p>When enabled, RTSM will automatically write <code>ABSPATH/.maintenance</code> at Critical and Emergency load levels, taking the site offline for non-admin visitors until load recovers. Incident logging and Cloudflare escalation (if installed) happen regardless of this setting.</p>

        <div class="notice notice-warning inline" class="rtsm-notice-compact">
            <p class="rtsm-m-0"><strong>⚠️ Think before enabling.</strong> Auto-maintenance protects the server during genuine DDoS events, but will also fire during legitimate traffic spikes (flash sales, viral content). Cloudflare Under Attack Mode — triggered automatically by the Cloudflare integration — is a safer first response and does not take your site offline.</p>
        </div>

        <form method="post" action="">
            <?php
            wp_nonce_field('rtsm_settings');
            $auto_maintenance = $settings_manager->get('rtsm_auto_maintenance', 0);
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Automatic Maintenance Mode</th>
                    <td>
                        <label>
                            <input type="hidden" name="rtsm_auto_maintenance" value="0" />
                            <input type="checkbox" name="rtsm_auto_maintenance" value="1" <?php checked($auto_maintenance, 1); ?> />
                            Enable auto-maintenance at Critical &amp; Emergency thresholds
                        </label>
                        <p class="description">
                            Off by default. Grace periods still apply &mdash; Critical requires 3&nbsp;checks spanning 120&nbsp;seconds of sustained load; Emergency requires 2&nbsp;checks spanning 60&nbsp;seconds.
                        </p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" name="rtsm_save_settings" class="button button-primary mmi-action-btn">Save Settings</button>
            </p>
        </form>
    </div>

    <!-- System Information -->
    <div class="mmi-panel-card">
        <h2>
            <span class="dashicons dashicons-info"></span>
            System Information
        </h2>
        
        <table class="mmi-uniform-table mmi-uniform-table--hoverable">
            <tbody>
                <tr>
                    <th class="rtsm-col-30">Plugin Version</th>
                    <td><?php echo esc_html(RTSM_VERSION); ?></td>
                </tr>
                <tr>
                    <th>WordPress Version</th>
                    <td><?php echo get_bloginfo('version'); ?></td>
                </tr>
                <tr>
                    <th>PHP Version</th>
                    <td><?php echo PHP_VERSION; ?></td>
                </tr>
                <tr>
                    <th>Web Server</th>
                    <td><?php echo esc_html($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'); ?></td>
                </tr>
                <tr>
                    <th>Platform</th>
                    <td><?php echo esc_html($platform_info['platform']); ?></td>
                </tr>
                <tr>
                    <th>Plugin Directory</th>
                    <td><code><?php echo esc_html(RTSM_PLUGIN_DIR); ?></code></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

