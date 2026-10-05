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
        <div class="mmi-process-section">
            <div class="mmi-section-header"><div>
                <h3 class="mmi-process-section-header"><span class="dashicons dashicons-admin-generic"></span> Intelligent Diagnostics Analysis</h3>
                <p class="mmi-process-section-description">████████████████████████</p>
            </div></div>
            <div class="mmi-section-content">
                <div class="mmi-stats-grid">'
                    . str_repeat('<div class="mmi-stat-box"><div class="mmi-stat-label">████████</div><div class="mmi-stat-value">███</div></div>', 4) . '
                </div>
                <table class="mmi-uniform-table">
                    <thead><tr><th>████████</th><th>████</th><th>██████</th><th>████████</th></tr></thead>
                    <tbody>'
                        . str_repeat('<tr><td>████████████</td><td>███</td><td>██████</td><td>████████</td></tr>', 8) . '
                    </tbody>
                </table>
            </div>
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

// Licensed - show the diagnostics interface. It reads RTSM's own traffic log.
// Its script/style are enqueued in RTSM_Admin::enqueue_assets(): this tab is
// usually rendered by the rtsm_load_tab AJAX call, where enqueueing does nothing.
?>

<div class="mmi-process-section">
    <?php
    $rtsm_diag_actions = '<label for="diagnostic-time-range">Analyze:</label>'
        . '<select id="diagnostic-time-range">'
        . '<option value="30min">Last 30 Minutes</option>'
        . '<option value="1hour" selected>Last 1 Hour</option>'
        . '<option value="6hours">Last 6 Hours</option>'
        . '<option value="24hours">Last 24 Hours</option>'
        . '</select>'
        . '<button type="button" class="button button-primary" id="cf-refresh-diagnostics"><span class="dashicons dashicons-search"></span> Analyze Traffic</button>';
    echo RTSM_UI_Helpers::section_header(
        'admin-generic',
        'Intelligent Diagnostics',
        'Reads the traffic log to tell external attacks apart from code issues.',
        '',
        $rtsm_diag_actions
    );
    ?>
    <div class="mmi-section-content">
        <div class="mmi-info-card">
            <p>
                <strong>💡 What This Does:</strong> Analyzes your server traffic to determine if load issues are caused by
                <strong class="mmi-text-error">External Attacks</strong> (bots, DDoS, brute force) or
                <strong class="mmi-text-warning">Code Issues</strong> (inefficient plugins, slow queries, heavy AJAX).
            </p>
        </div>

        <div id="diagnostic-results">
            <div class="notice notice-info inline">
                <p>Click "Analyze Traffic" to generate diagnostics for the selected time period.</p>
            </div>
        </div>

        <div id="diagnostic-details" class="mmi-grid-2" hidden>
            <div>
                <h4>📊 URL Analysis</h4>
                <div id="url-analysis-table"></div>
            </div>
            <div>
                <h4>🤖 Traffic Sources</h4>
                <div id="user-agent-analysis"></div>
            </div>
        </div>
    </div>
</div>

<div class="mmi-process-section">
    <?php echo RTSM_UI_Helpers::section_header( 'lightbulb', 'How to Interpret Results', 'The three verdicts the analysis can return, and what to do about each.' ); ?>
    <div class="mmi-section-content">
        <div class="mmi-grid-3">
            <div class="mmi-info-card error inline">
                <h3>🚨 Attack Detected</h3>
                <p><strong>Indicators:</strong> High bot traffic (&gt;70%), XML-RPC/wp-login hammering, single IP responsible for &gt;50% of requests.</p>
                <p><strong>Action:</strong> Block IPs, turn on Under Attack Mode in your Cloudflare dashboard, disable XML-RPC.</p>
            </div>
            <div class="mmi-info-card warning">
                <h3>🔧 Code Issue</h3>
                <p><strong>Indicators:</strong> Heavy admin-ajax.php (&gt;30%), excessive WP-Cron (&gt;10%), WooCommerce bottlenecks, low bot ratio.</p>
                <p><strong>Action:</strong> Profile AJAX handlers, optimize database queries, enable caching, disable WP-Cron.</p>
            </div>
            <div class="mmi-info-card success">
                <h3>✅ Normal Operations</h3>
                <p><strong>Indicators:</strong> Load &lt;5.0, balanced bot/human ratio, distributed IPs, no suspicious patterns.</p>
                <p><strong>Action:</strong> Continue regular monitoring. System is healthy.</p>
            </div>
        </div>
    </div>
</div>
