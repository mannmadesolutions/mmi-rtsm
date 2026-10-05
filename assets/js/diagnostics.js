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

        $button.prop('disabled', true).addClass('mmi-is-loading');
        $results.html('<div class="notice notice-info inline"><p><span class="mmi-loading"></span> Analyzing traffic patterns…</p></div>');

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
                $button.prop('disabled', false).removeClass('mmi-is-loading');
            },
        });
    }
    
    /**
     * Display diagnostic results
     */
    function displayDiagnostics(data) {
        const $results = $(SELECTORS.diagResults);
        const $details = $(SELECTORS.diagDetails);

        /* ── Threat type → shared .mmi-info-card variant ─────────────── */
        const THREAT_VARIANTS = {
            attack:           'error inline',
            bot_attack:       'error inline',
            single_ip_attack: 'error inline',
            code_issue:       'warning',
            normal:           'success',
        };
        const verdictClass = THREAT_VARIANTS[data.threat_type] || '';

        // Build verdict HTML
        $results.html(`
            <div class="mmi-info-card ${verdictClass}">
                <h3>${esc(data.verdict)} <span class="mmi-badge">Confidence: ${esc(data.confidence)}%</span></h3>
                <p>${esc(data.recommendation)}</p>
            </div>
        `);

        // Display URL analysis
        if (data.url_analysis && data.url_analysis.length > 0) {
            /* ── Concern level → display config ─────────────────────── */
            const CONCERN_CONFIG = {
                critical: { icon: '🚨', badge: 'error'   },
                high:     { icon: '⚠️',  badge: 'warning' },
                medium:   { icon: '⚡',  badge: 'info'    },
            };
            const URL_TRUNCATE_LEN = 60;

            let urlHtml = '<div class="mmi-table-scroll-wrapper"><table class="mmi-uniform-table mmi-uniform-table--hoverable">';
            urlHtml += '<thead><tr><th>URL</th><th>Hits</th><th>%</th><th>Category</th><th>Status</th></tr></thead><tbody>';

            data.url_analysis.forEach(function(url) {
                const concern       = CONCERN_CONFIG[url.concern_level] || { icon: '', badge: 'success' };
                const displayUrl    = url.url.substring(0, URL_TRUNCATE_LEN) + (url.url.length > URL_TRUNCATE_LEN ? '...' : '');
                urlHtml += `<tr>
                    <td class="rtsm-truncate" title="${esc(url.url)}"><code>${esc(displayUrl)}</code></td>
                    <td>${esc(url.hits)}</td>
                    <td><strong>${esc(url.percentage)}%</strong></td>
                    <td>${esc(url.category)}</td>
                    <td title="${esc(url.description)}"><span class="mmi-badge ${concern.badge}">${concern.icon} ${esc(String(url.concern_level))}</span></td>
                </tr>`;
            });

            urlHtml += '</tbody></table></div>';
            $(SELECTORS.urlAnalysisTable).html(urlHtml);
            $details.prop('hidden', false);
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
            let agentHtml = '<div class="rtsm-chart"><canvas id="userAgentChart"></canvas></div>';
            agentHtml += '<div class="mmi-table-scroll-wrapper"><table class="mmi-uniform-table mmi-uniform-table--hoverable"><thead><tr><th>Type</th><th>Requests</th></tr></thead><tbody>';

            for (const agent in data.user_agent_analysis) {
                const count = data.user_agent_analysis[agent];
                agentLabels.push(agent);
                agentData.push(count);
                const color = agentColors[agent] || CHART_COLORS.agentUnknown;
                agentHtml += `<tr><td><span class="rtsm-swatch" style="--swatch-color:${color}"></span> ${esc(agent)}</td><td>${esc(count)}</td></tr>`;
            }

            agentHtml += '</tbody></table></div>';
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
        // Delegated: the Diagnostics tab is usually injected by AJAX after load.
        $(document).on('click', SELECTORS.refreshDiag, refreshDiagnostics);
    });

})(jQuery);
