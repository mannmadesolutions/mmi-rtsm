/**
 * LEGACY: CloudFlare Diagnostics Integration JavaScript
 * 
 * This file contains scripts for the CloudFlare diagnostics integration
 * used in the Diagnostics tab when MMI CloudFlare Protection plugin is active.
 * 
 * Status: LEGACY - Only used for diagnostics tab integration
 * Location: Used in templates/admin/tabs/diagnostics.php
 * Can be removed if: CloudFlare integration is fully deprecated
 * 
 * @package MMI_RTSM
 * @since 2.0.0
 * @deprecated-status: Maintained for compatibility
 */

/**
 * MMI CloudFlare Protection - Admin JavaScript
 * Handles all AJAX interactions for the CloudFlare admin interface
 */

(function($) {
    'use strict';

    /**
     * HTML-escape server data (log URLs, IPs, user agents, process command
     * lines) before it goes into .html()/template strings. Delegates to the
     * shared MMIEscapeHtml when loaded; this script doesn't declare that
     * handle as a dependency, so a local fallback is kept.
     *
     * @param {*} str
     * @returns {string}
     */
    function esc(str) {
        if (typeof window.MMIEscapeHtml === 'function') {
            return window.MMIEscapeHtml(str);
        }
        if (str === null || str === undefined) {
            return '';
        }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }


    /* ── DOM Selectors ──────────────────────────────────────────────── */
    const SELECTORS = {
        protectionLevel:   '.current-protection-level',
        serverLoadValue:   '.server-load-value',
        trafficTimeRange:  '#diagnostic-time-range',
        trafficGraphWrap:  '.traffic-graph-container',
        topAttackingIPs:   '#top-attacking-ips',
        trafficSpikeAlert: '.traffic-spike-alert',
        spikeCount:        '.spike-count',
        logLines:          '#log-lines',
        logContent:        '.log-content pre',
        wrapH1:            '.wrap h1',
        cfAdminForm:       '.cf-admin-form',
        refreshDiag:       '#cf-refresh-diagnostics',
        diagResults:       '#diagnostic-results',
        diagDetails:       '#diagnostic-details',
        urlAnalysisTable:  '#url-analysis-table',
        userAgentAnalysis: '#user-agent-analysis',
        userAgentChart:    '#userAgentChart',
    };

    /* ── Concern-Level Colors ───────────────────────────────────────── */
    const SEVERITY_COLORS = {
        normal:   '#45852C',
        medium:   '#00a0d2',
        high:     '#f0b849',
        critical: '#dc3232',
        unknown:  '#999999',
    };

    /* ── Chart Segment Colors ───────────────────────────────────────── */
    const CHART_COLORS = {
        spike:       { bg: 'rgba(255, 99, 132, 0.2)', border: 'rgba(255, 99, 132, 1)' },
        normal:      { bg: 'rgba(75, 192, 192, 0.2)', border: 'rgba(75, 192, 192, 1)' },
        agentHuman:  '#45852C',
        agentLegit:  '#00a0d2',
        agentSocial: '#826eb4',
        agentMonitor:'#f0b849',
        agentSuspect:'#dc3232',
        agentUnknown:'#999999',
    };

    /* ── Auto-refresh state ─────────────────────────────────────────── */
    // let (not const) — reassigned by start/stopAutoRefresh
    let autoRefreshInterval = null;
    
    /**
     * Get current tab from URL parameter
     */
    function getCurrentTab() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('tab') || 'dashboard';
    }

    /**
     * Start auto-refresh interval
     */
    function startAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }

        /* ── Auto-refresh interval constant ─ 30 s ─────────────────── */
        const AUTO_REFRESH_MS = 30000;

        autoRefreshInterval = setInterval(function() {
            const currentTab = getCurrentTab();
            
            if (currentTab === 'dashboard') {
                refreshDashboard();
            }
            if (currentTab === 'logs') {
                refreshLogs();
            }
        }, AUTO_REFRESH_MS);
    }
    
    /**
     * Stop auto-refresh
     */
    function stopAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
    }
    
    /**
     * Refresh dashboard data
     */
    function refreshDashboard() {
        // Get current security level
        $.ajax({
            url: mmiCfAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mmi_cf_get_current_level',
                nonce: mmiCfAdmin.nonce
            },
            success: function(response) {
                if (response.success && response.data.level) {
                    updateProtectionLevel(response.data.level);
                }
            }
        });
        
        // Refresh server load (this would typically come from a server-side source)
        $.ajax({
            url: mmiCfAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mmi_cf_get_server_status',
                nonce: mmiCfAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateServerLoad(response.data.load);
                }
            }
        });
    }
    
    /**
     * Update protection level display
     */
    function updateProtectionLevel(level) {
        // Was 'protection-badge' + 'badge-off'/'-low'/'-medium'/'-high'/-critical' —
        // none of those classes had a CSS definition anywhere in this plugin, so 4 of
        // these 5 states rendered as plain unstyled text; 'under_attack' only picked
        // up color by accident, from .rtsm-badge's bare (unscoped) .badge-critical
        // rule elsewhere on the page. Fixed by mapping onto the real shared .mmi-badge
        // semantics instead of inventing a 6th local badge system in this plugin.
        const badges = {
            'off':             { class: 'warning', text: 'Off'             },
            'essentially_off': { class: 'warning', text: 'Essentially Off' },
            'low':             { class: 'warning', text: 'Low'             },
            'medium':          { class: 'info',    text: 'Medium'          },
            'high':            { class: 'success', text: 'High'            },
            'under_attack':    { class: 'error',   text: 'Under Attack'    },
        };

        const badge = badges[level] || badges['off'];
        const html  = `<span class="mmi-badge ${badge.class}">${badge.text}</span>`;
        $(SELECTORS.protectionLevel).html(html);
    }
    
    /**
     * Update server load display
     */
    function updateServerLoad(load) {
        /* ── Server load thresholds ─────────────────────────────────── */
        const LOAD_THRESHOLDS = { elevated: 5.0, critical: 15.0 };

        const $elem = $(SELECTORS.serverLoadValue);
        $elem.text(load.toFixed(2));

        $elem.removeClass('load-normal load-elevated load-critical');
        if (load < LOAD_THRESHOLDS.elevated) {
            $elem.addClass('load-normal');
        } else if (load < LOAD_THRESHOLDS.critical) {
            $elem.addClass('load-elevated');
        } else {
            $elem.addClass('load-critical');
        }
    }
    
    /**
     * Refresh traffic graph with current time range
     */
    function refreshTrafficGraph() {
        const interval = $(SELECTORS.trafficTimeRange).val() || '1hour';

        $.ajax({
            url: mmiCfAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mmi_cf_get_traffic_data',
                nonce: mmiCfAdmin.nonce,
                interval,
            },
            beforeSend: function() {
                $(SELECTORS.trafficGraphWrap).addClass('rtsm-loading-dimmed');
            },
            success: function(response) {
                if (response.success && response.data.traffic_data) {
                    updateTrafficChart(response.data.traffic_data);
                    updateAttackIPs(response.data.attack_ips || {});
                    updateSpikeAlert(response.data.spike_count || 0);
                }
            },
            complete: function() {
                $(SELECTORS.trafficGraphWrap).removeClass('rtsm-loading-dimmed');
            },
        });
    }
    
    /**
     * Update traffic chart with new data
     */
    function updateTrafficChart(data) {
        if (typeof Chart === 'undefined' || !window.trafficChart) return;
        
        window.trafficChart.data.labels = data.timestamps;
        window.trafficChart.data.datasets[0].data = data.loads;
        window.trafficChart.data.datasets[0].backgroundColor = data.spikes.map(
            (isSpike) => isSpike ? CHART_COLORS.spike.bg : CHART_COLORS.normal.bg
        );
        window.trafficChart.data.datasets[0].borderColor = data.spikes.map(
            (isSpike) => isSpike ? CHART_COLORS.spike.border : CHART_COLORS.normal.border
        );
        window.trafficChart.update();
    }
    
    /**
     * Update attacking IPs list
     */
    function updateAttackIPs(ips) {
        const $container = $(SELECTORS.topAttackingIPs);
        if (!$container.length) return;

        if (Object.keys(ips).length === 0) {
            $container.html('<p class="mmi-muted">No attack data available yet.</p>');
            return;
        }

        let html = '<table class="widefat"><thead><tr><th>IP Address</th><th>Requests</th></tr></thead><tbody>';
        $.each(ips, function(ip, count) {
            html += `<tr><td>${esc(ip)}</td><td>${esc(count)}</td></tr>`;
        });
        html += '</tbody></table>';
        $container.html(html);
    }
    
    /**
     * Update spike alert box
     */
    function updateSpikeAlert(count) {
        const $alert = $(SELECTORS.trafficSpikeAlert);
        if (!$alert.length) return;

        if (count === 0) {
            $alert.hide();
        } else {
            $alert.show();
            $alert.find(SELECTORS.spikeCount).text(count);
        }
    }
    
    /**
     * Refresh logs
     */
    function refreshLogs() {
        const DEFAULT_LOG_LINES = 100;
        const lines = $(SELECTORS.logLines).val() || DEFAULT_LOG_LINES;

        $.ajax({
            url: mmiCfAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mmi_cf_get_logs',
                nonce: mmiCfAdmin.nonce,
                lines,
            },
            success: function(response) {
                if (response.success && response.data.logs) {
                    displayLogs(response.data.logs);
                }
            },
        });
    }

    /**
     * Display logs in the log viewer
     */
    function displayLogs(logs) {
        const html = logs.join('');
        $(SELECTORS.logContent).html(html);
    }
    
    /**
     * Show success message
     */
    function showSuccess(message) {
        showNotice(message, 'success');
    }
    
    /**
     * Show error message
     */
    function showError(message) {
        showNotice(message, 'error');
    }
    
    /**
     * Show notice message
     */
    function showNotice(message, type) {
        const NOTICE_AUTO_DISMISS_MS = 5000;

        const $notice = $(`<div class="notice notice-${type} is-dismissible"><p>${esc(message)}</p></div>`);
        $(SELECTORS.wrapH1).before($notice);

        // Auto-dismiss after timeout
        setTimeout(function() {
            $notice.fadeOut(function() { $(this).remove(); });
        }, NOTICE_AUTO_DISMISS_MS);

        // Manual dismiss
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut(function() { $(this).remove(); });
        });
    }
    
    /**
     * Format timestamp to relative time
     */
    function formatRelativeTime(timestamp) {
        if (!timestamp || timestamp === 'Never') {
            return 'Never';
        }

        /* ── Time constants (seconds) ───────────────────────────────── */
        const SECONDS_IN_MINUTE = 60;
        const SECONDS_IN_HOUR   = 3600;
        const SECONDS_IN_DAY    = 86400;

        const date = new Date(timestamp);
        const now  = new Date();
        const diff = Math.floor((now - date) / 1000); // seconds

        if (diff < SECONDS_IN_MINUTE) return `${diff} seconds ago`;
        if (diff < SECONDS_IN_HOUR)   return `${Math.floor(diff / SECONDS_IN_MINUTE)} minutes ago`;
        if (diff < SECONDS_IN_DAY)    return `${Math.floor(diff / SECONDS_IN_HOUR)} hours ago`;
        return `${Math.floor(diff / SECONDS_IN_DAY)} days ago`;
    }
    
    /**
     * Get current tab from URL
     */
    function getCurrentTab() {
        var urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('tab') || 'dashboard';
    }
    
    /**
     * Refresh intelligent diagnostics
     */
    function refreshDiagnostics() {
        const interval  = $(SELECTORS.trafficTimeRange).val() || '1hour';
        const $button   = $(SELECTORS.refreshDiag);
        const $results  = $(SELECTORS.diagResults);

        $button.prop('disabled', true);
$results.html('<div class="notice notice-info inline"><p><span class="spinner is-active rtsm-spinner-leading"></span>Analyzing traffic patterns...</p></div>');

        $.ajax({
            url: mmiCfAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'mmi_cf_get_diagnostics',
                nonce: mmiCfAdmin.nonce,
                interval,
            },
            success: function(response) {
                if (response.success) {
                    displayDiagnostics(response.data);
                } else {
                    $results.html(`<div class="notice notice-error inline"><p>Failed to analyze traffic: ${esc(response.data || 'Unknown error')}</p></div>`);
                }
            },
            error: function() {
                $results.html('<div class="notice notice-error inline"><p>Failed to connect to server. Please try again.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false);
            },
        });
    }
    
    /**
     * Display diagnostic results
     */
    function displayDiagnostics(data) {
        const $results = $(SELECTORS.diagResults);
        const $details = $(SELECTORS.diagDetails);

        /* ── Threat type → WP notice class map ─────────────────────── */
        const THREAT_NOTICE_CLASSES = {
            attack:           'notice-error',
            bot_attack:       'notice-error',
            single_ip_attack: 'notice-error',
            code_issue:       'notice-warning',
            normal:           'notice-success',
        };
        const verdictClass = THREAT_NOTICE_CLASSES[data.threat_type] || 'notice-info';

        // Build verdict HTML
        $results.html(`
            <div class="notice ${verdictClass} inline mmi-verdict-box">
                <h3 class="mmi-verdict-heading">${esc(data.verdict)}</h3>
                <div class="mmi-verdict-confidence"><strong>Confidence:</strong> ${esc(data.confidence)}%</div>
                <div>${esc(data.recommendation)}</div>
            </div>
        `);

        // Display URL analysis
        if (data.url_analysis && data.url_analysis.length > 0) {
            /* ── Concern level → display config ─────────────────────── */
            const CONCERN_CONFIG = {
                critical: { icon: '🚨', color: SEVERITY_COLORS.critical },
                high:     { icon: '⚠️',  color: SEVERITY_COLORS.high     },
                medium:   { icon: '⚡',  color: SEVERITY_COLORS.medium   },
            };
            const URL_TRUNCATE_LEN = 60;

            let urlHtml = '<table class="wp-list-table widefat fixed striped">';
            urlHtml += '<thead><tr><th>URL</th><th>Hits</th><th>%</th><th>Category</th><th>Status</th></tr></thead><tbody>';

            data.url_analysis.forEach(function(url) {
                const concern       = CONCERN_CONFIG[url.concern_level] || { icon: '', color: SEVERITY_COLORS.normal };
                const displayUrl    = url.url.substring(0, URL_TRUNCATE_LEN) + (url.url.length > URL_TRUNCATE_LEN ? '...' : '');
                urlHtml += `<tr>
                    <td><code class="mmi-url-code">${esc(displayUrl)}</code></td>
                    <td>${esc(url.hits)}</td>
                    <td class="mmi-bold">${esc(url.percentage)}%</td>
                    <td>${esc(url.category)}</td>
                    <td class="rtsm-concern-td" style="--concern-color:${concern.color}" title="${esc(url.description)}">${concern.icon} ${esc(String(url.concern_level).toUpperCase())}</td>
                </tr>`;
            });

            urlHtml += '</tbody></table>';
            $(SELECTORS.urlAnalysisTable).html(urlHtml);
            $details.show();
        }

        // Display user agent analysis
        if (data.user_agent_analysis) {
            const agentColors = {
                'Human Browser':     CHART_COLORS.agentHuman,
                'Legitimate Bot':    CHART_COLORS.agentLegit,
                'Social Media Bot':  CHART_COLORS.agentSocial,
                'Monitoring Service':CHART_COLORS.agentMonitor,
                'Suspicious Bot':    CHART_COLORS.agentSuspect,
                'Unknown':           CHART_COLORS.agentUnknown,
            };

            const agentLabels = [];
            const agentData   = [];
            let agentHtml = '<canvas id="userAgentChart" class="mmi-agent-chart"></canvas>';
            agentHtml += '<table class="wp-list-table widefat fixed striped mmi-agent-table"><thead><tr><th>Type</th><th>Requests</th></tr></thead><tbody>';

            for (const agent in data.user_agent_analysis) {
                const count = data.user_agent_analysis[agent];
                agentLabels.push(agent);
                agentData.push(count);
                const color = agentColors[agent] || CHART_COLORS.agentUnknown;
                agentHtml += `<tr><td class="rtsm-agent-td" style="--agent-color:${color}">${esc(agent)}</td><td>${esc(count)}</td></tr>`;
            }

            agentHtml += '</tbody></table>';
            $(SELECTORS.userAgentAnalysis).html(agentHtml);

            // Create pie chart for user agents
            if (typeof Chart !== 'undefined' && agentLabels.length > 0) {
                const ctx = document.querySelector(SELECTORS.userAgentChart).getContext('2d');
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: agentLabels,
                        datasets: [{
                            data: agentData,
                            backgroundColor: agentLabels.map((label) => agentColors[label] || CHART_COLORS.agentUnknown),
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } },
                    },
                });
            }
        }
    }
    
    // Initialize when document is ready
    $(document).ready(function() {

        // Time range change listener for traffic graph
        $(SELECTORS.trafficTimeRange).on('change', refreshTrafficGraph);

        // Diagnostics refresh button
        $(SELECTORS.refreshDiag).on('click', refreshDiagnostics);

        // Initial load of traffic data if on dashboard
        if (getCurrentTab() === 'dashboard' && $(SELECTORS.trafficTimeRange).length) {
            refreshTrafficGraph();
        }

        // Start auto-refresh for active tabs
        startAutoRefresh();

        // Stop auto-refresh when user leaves the page
        $(window).on('beforeunload', stopAutoRefresh);

        // NOTE: Tab switching is now handled by PHP (page reload with ?tab= parameter)
        // The old JavaScript tab switching has been removed to allow PHP navigation

        // Prevent form submissions from causing page reload
        $(SELECTORS.cfAdminForm).on('submit', function(e) {
            e.preventDefault();
        });
    });
    
})(jQuery);
