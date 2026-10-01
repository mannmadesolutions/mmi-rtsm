/**
 * MMI CloudFlare Protection - Logs Tab JavaScript
 */

(function($) {
    'use strict';

    /* ── Selectors ─────────────────────────────────────────────────────── */
    const SELECTORS = {
        filterBtn:    '.log-filter',
        linesSelect:  '#log-lines-select',
        refreshBtn:   '#btn-refresh-logs',
        downloadBtn:  '#btn-download-logs',
        logEntry:     '.mmi-panel-log-entry',
    };

    /* ── Filter types ──────────────────────────────────────────────────── */
    const FILTER_TYPES = {
        ALL:      'all',
        CRITICAL: 'critical',
        ELEVATED: 'elevated',
        NORMAL:   'normal',
    };

    /* ── Messages / URL templates ──────────────────────────────────────── */
    const MESSAGES = {
        logsPageUrl:  '?page=mmi-cloudflare&tab=logs&lines=%lines%',
        downloadUrl:  '?action=mmi_cf_download_logs&nonce=%nonce%',
    };

    let currentFilter = FILTER_TYPES.ALL;

    $(document).ready(function() {

        /* Filter logs */
        $(SELECTORS.filterBtn).on('click', function() {
            $(SELECTORS.filterBtn).removeClass('button-primary');
            $(this).addClass('button-primary');
            currentFilter = $(this).data('filter');
            filterLogs();
        });

        /* Change lines displayed */
        $(SELECTORS.linesSelect).on('change', function() {
            refreshLogs();
        });

        /* Refresh logs */
        $(SELECTORS.refreshBtn).on('click', function() {
            refreshLogs();
        });

        /* Download logs */
        $(SELECTORS.downloadBtn).on('click', function() {
            if (typeof mmiCfAdmin !== 'undefined') {
                const downloadPath = MESSAGES.downloadUrl.replace('%nonce%', mmiCfAdmin.nonce);
                window.location.href = mmiCfAdmin.ajaxUrl + downloadPath;
            }
        });
    });

    /**
     * Show/hide log entries based on the current filter.
     */
    function filterLogs() {
        if (currentFilter === FILTER_TYPES.ALL) {
            $(SELECTORS.logEntry).show();
            return;
        }

        $(SELECTORS.logEntry).each(function() {
            const $entry = $(this);
            const text   = $entry.text().toLowerCase();
            let shouldShow = false;

            switch (currentFilter) {
                case FILTER_TYPES.CRITICAL:
                    shouldShow = text.includes(FILTER_TYPES.CRITICAL) || text.includes('error');
                    break;
                case FILTER_TYPES.ELEVATED:
                    shouldShow = text.includes(FILTER_TYPES.ELEVATED) || text.includes('warning');
                    break;
                case FILTER_TYPES.NORMAL:
                    shouldShow = !text.includes(FILTER_TYPES.CRITICAL) && !text.includes(FILTER_TYPES.ELEVATED)
                              && !text.includes('error') && !text.includes('warning');
                    break;
            }

            $entry.toggle(shouldShow);
        });
    }

    /**
     * Reload the page with the selected line count.
     */
    function refreshLogs() {
        const lines   = $(SELECTORS.linesSelect).val();
        const pageUrl = MESSAGES.logsPageUrl.replace('%lines%', lines);
        window.location.href = window.location.pathname + pageUrl;
    }

})(jQuery);

