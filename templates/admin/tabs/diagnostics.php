<?php
/**
 * RTSM Intelligent Diagnostics Tab
 * 
 * Analyzes traffic to determine if server issues are caused by code or external attacks
 * Premium Feature
 */

if (!defined('ABSPATH')) exit;

// Check if user has an active MMI Suite license.
$license_manager    = RTSM_License_Manager::get_instance();
$has_premium_access = $license_manager->is_licensed();

if (!$has_premium_access) {
    // Generate obfuscated preview content
    $preview_content = '
        <div class="mmi-panel-card">
            <h3>Intelligent Diagnostics Analysis</h3>
            <div class="rtsm-diag-grid-4">
                <div class="rtsm-diag-stat">
                    <div class="rtsm-diag-label">████████</div>
                    <div class="rtsm-diag-value">███</div>
                </div>
                <div class="rtsm-diag-stat">
                    <div class="rtsm-diag-label">██████</div>
                    <div class="rtsm-diag-value">████</div>
                </div>
                <div class="rtsm-diag-stat">
                    <div class="rtsm-diag-label">█████████</div>
                    <div class="rtsm-diag-value">██%</div>
                </div>
                <div class="rtsm-diag-stat">
                    <div class="rtsm-diag-label">███████</div>
                    <div class="rtsm-diag-value">█████</div>
                </div>
            </div>
            <table class="mmi-uniform-table mmi-uniform-table--hoverable rtsm-mt-20">
                <thead><tr><th>████████</th><th>████</th><th>██████</th><th>████████</th></tr></thead>
                <tbody>
                    ' . str_repeat('<tr><td>████████████</td><td>███</td><td>██████</td><td>████████</td></tr>', 8) . '
                </tbody>
            </table>
        </div>
    ';
    
    // Render overlay prompting the user to get an MMI Suite license.
    echo RTSM_UI_Helpers::render_premium_overlay([
        'feature_name'    => 'Intelligent Diagnostics',
        'description'     => 'Analyze traffic patterns to determine if server issues are caused by code problems or external attacks. Requires an active MMI Suite license.',
        'features'        => [
            '<strong>Attack Detection</strong> — Identify XML-RPC attacks, brute force attempts, and bot swarms',
            '<strong>Code Issue Identification</strong> — Find heavy AJAX calls, WP-Cron issues, and WooCommerce bottlenecks',
            '<strong>URL Analysis</strong> — See which endpoints are consuming the most resources',
            '<strong>Bot vs Human Traffic</strong> — Understand your visitor sources and patterns',
            '<strong>Actionable Recommendations</strong> — Get specific instructions to fix identified issues',
        ],
        'preview_content' => $preview_content,
    ]);
    
    return;
}

// Premium user - show diagnostics interface
// Check if the MMI Cloudflare Integration plugin is active
$cf_active = defined('MMI_CLOUDFLARE_VERSION') && class_exists('MMI_CF_Admin');

if (!$cf_active) {
    ?>
    <div class="mmi-panel-card">
        <div class="mmi-rtsm-empty-state-card">
            <span class="dashicons dashicons-cloud"></span>
            <h2>Cloudflare Integration Not Active</h2>
            <p class="mmi-rtsm-empty-state-intro">
                Intelligent Diagnostics is powered by the <strong>MMI Cloudflare Integration</strong> plugin.
                It analyses real edge-traffic data to distinguish external attacks from code-level issues.
            </p>
            <div class="mmi-rtsm-empty-state-box">
                <p>What you get with Cloudflare Integration:</p>
                <ul>
                    <li>&#x1F6E1;&#xFE0F; <strong>Bot attack detection</strong> &mdash; XML-RPC hammering, brute force, scraper swarms</li>
                    <li>&#x1F527; <strong>Code issue analysis</strong> &mdash; heavy AJAX handlers, WP-Cron pile-ups, WooCommerce bottlenecks</li>
                    <li>&#x26A1; <strong>Under Attack Mode automation</strong> &mdash; RTSM triggers Cloudflare protection automatically when load spikes</li>
                    <li>&#x1F4CA; <strong>Edge traffic data</strong> &mdash; bot vs human ratios and top offending IPs at the CDN level</li>
                </ul>
            </div>
            <a href="<?php echo esc_url( admin_url('admin.php?page=mmi-dashboard') ); ?>" class="button button-primary button-large mmi-action-btn mmi-rtsm-empty-state-cta">
                <span class="dashicons dashicons-admin-plugins"></span>
                Manage MMI Plugins
            </a>
            <p class="mmi-rtsm-empty-state-footnote">
                Already installed? <a href="<?php echo esc_url( admin_url('plugins.php') ); ?>">Check Plugins list</a> to make sure it&rsquo;s activated.
            </p>
        </div>
    </div>
    <?php
    return;
}

// Include necessary assets for CloudFlare diagnostics integration
wp_enqueue_script('chartjs', RTSM_PLUGIN_URL . 'assets/vendor/chart.umd.min.js', [], '4.4.1', true);
wp_enqueue_style('mmi-panel-diagnostics', plugin_dir_url(dirname(dirname(dirname(__FILE__)))) . 'assets/css/legacy-cloudflare-diagnostics.css', ['mmi-suite-common'], RTSM_VERSION);
wp_enqueue_script('mmi-panel-diagnostics', plugin_dir_url(dirname(dirname(dirname(__FILE__)))) . 'assets/js/legacy-cloudflare-diagnostics.js', ['jquery', 'chartjs'], RTSM_VERSION, true);
wp_localize_script('mmi-panel-diagnostics', 'mmiCfAdmin', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('mmi-panel-admin-nonce')
]);
?>

<div class="mmi-panel-card">
    <div class="rtsm-flex-header m-bottom-medium">
        <h2 class="margin-none">
            <span class="dashicons dashicons-admin-generic"></span>
            Intelligent Diagnostics
        </h2>
        <div class="rtsm-flex-row">
            <label for="diagnostic-time-range" class="margin-none">Analyze:</label>
            <select id="diagnostic-time-range" class="regular-text" class="rtsm-w-auto">
                <option value="30min">Last 30 Minutes</option>
                <option value="1hour" selected>Last 1 Hour</option>
                <option value="6hours">Last 6 Hours</option>
                <option value="24hours">Last 24 Hours</option>
            </select>
            <button type="button" class="button button-primary mmi-action-btn" id="cf-refresh-diagnostics">
                <span class="dashicons dashicons-search"></span>
                Analyze Traffic
            </button>
        </div>
    </div>
    
    <div class="rtsm-info-box m-bottom-large">
        <p class="margin-none font-small">
            <strong>💡 What This Does:</strong> Analyzes your server traffic to determine if load issues are caused by:
            <strong class="text-critical">External Attacks</strong> (bots, DDoS, brute force) or 
            <strong class="text-warning">Code Issues</strong> (inefficient plugins, slow queries, heavy AJAX).
        </p>
    </div>
    
    <div id="diagnostic-results" class="m-top-medium">
        <div class="notice notice-info inline">
            <p>Click "Analyze Traffic" to generate diagnostics for the selected time period.</p>
        </div>
    </div>
    
    <div id="diagnostic-details" class="m-top-large mmi-hidden">
        <div class="rtsm-grid-2col">
            <div>
                <h3 class="margin-none">📊 URL Analysis</h3>
                <div id="url-analysis-table"></div>
            </div>
            <div>
                <h3 class="margin-none">🤖 Traffic Sources</h3>
                <div id="user-agent-analysis"></div>
            </div>
        </div>
    </div>
</div>

<!-- Example Scenarios -->
<div class="mmi-panel-card">
    <h2>
        <span class="dashicons dashicons-lightbulb"></span>
        How to Interpret Results
    </h2>
    
    <div class="rtsm-grid-responsive m-top-medium">
        <div class="scenario-box scenario-attack">
            <h4>🚨 Attack Detected</h4>
            <p>
                <strong>Indicators:</strong> High bot traffic (>70%), XML-RPC/wp-login hammering, single IP responsible for >50% of requests.
            </p>
            <p>
                <strong>Action:</strong> Block IPs, enable CloudFlare "Under Attack Mode", disable XML-RPC.
            </p>
        </div>
        <div class="scenario-box scenario-code-issue">
            <h4>🔧 Code Issue</h4>
            <p>
                <strong>Indicators:</strong> Heavy admin-ajax.php (>30%), excessive WP-Cron (>10%), WooCommerce bottlenecks, low bot ratio.
            </p>
            <p>
                <strong>Action:</strong> Profile AJAX handlers, optimize database queries, enable caching, disable WP-Cron.
            </p>
        </div>
        
        <div class="scenario-box scenario-normal">
            <h4>✅ Normal Operations</h4>
            <p>
                <strong>Indicators:</strong> Load <5.0, balanced bot/human ratio, distributed IPs, no suspicious patterns.
            </p>
            <p>
                <strong>Action:</strong> Continue regular monitoring. System is healthy.
            </p>
        </div>
    </div>
</div>

