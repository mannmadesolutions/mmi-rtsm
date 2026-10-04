/**
 * RTSM Intelligent Diagnostics tab.
 *
 * Asks rtsm_get_diagnostics (RTSM_Server_Monitor::ajax_get_diagnostics) to
 * analyse RTSM's own server-traffic-analysis.log for the chosen time range,
 * then renders the verdict, URL table and traffic-source chart.
 *
 * Trimmed 2.9.0 from the old legacy-cloudflare-diagnostics.js, whose
 * dashboard/traffic/log calls went to the retired mmi-cloudflare-integration.
 *
 * @package MMI_RTSM
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
        trafficTimeRange:  '#diagnostic-time-range',
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

    /* ── Chart Segment Colors ───────────────────────────────────────── */
    const CHART_COLORS = {
        agentHuman:  '#45852C',
        agentLegit:  '#00a0d2',
        agentSocial: '#826eb4',
        agentMonitor:'#f0b849',
        agentSuspect:'#dc3232',
        agentUnknown:'#999999',
    };

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
            url: rtsmDiag.ajaxUrl,
            type: 'POST',
            data: {
                action: 'rtsm_get_diagnostics',
                nonce: rtsmDiag.nonce,
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
    

    $(document).ready(function() {
        $(SELECTORS.refreshDiag).on('click', refreshDiagnostics);
    });

})(jQuery);
