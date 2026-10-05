/**
 * RTSM Refactored Inline Code
 * Handles all previously inline JavaScript event handlers
 *
 * @package MMI_RTSM
 */

(function() {
    'use strict';

    /* ── DOM Selectors ──────────────────────────────────────────────── */
    const SELECTORS = {
        reloadButton:       '[data-action="reload"]',
        clearLogButton:     '#rtsm-clear-log-btn',
        activateLicense:    '#rtsm-activate-license',
        deactivateLicense:  '#rtsm-deactivate-license',
        licenseKeyInput:    '#rtsm-license-key',
        licenseMessage:     '#rtsm-license-message',
        licenseNonce:       'input[name="rtsm_license_nonce"]',
        processFilter:      '.rtsm-process-filter',
        refreshProcesses:   '#refresh-processes',
        processContainer:   '#processes-container',
        processItem:        '[data-process-type]',
        logButton:          '[data-rtsm-log][data-rtsm-log-action]',
    };

    /* ── CSS Classes ────────────────────────────────────────────────── */
    const CLASS_NAMES = {
        notice:       'notice',
        noticeInline: 'inline',
        noticeInfo:   'notice-info',
        noticePrefix: 'notice-',
        muted:        'mmi-text-muted',
        tableScroll:  'mmi-table-scroll-wrapper',
        table:        'mmi-uniform-table mmi-uniform-table--hoverable',
        truncate:     'rtsm-truncate',
        nowrap:       'rtsm-nowrap',
        cpuHigh:      'mmi-text-error',
        cpuMedium:    'mmi-text-warning',
    };

    /* ── Messages ───────────────────────────────────────────────────── */
    const MESSAGES = {
        clearConfirm:        'Are you sure you want to clear all traffic analysis data? This action cannot be undone.',
        clearLogConfirm:     {
            traffic: 'Clear the traffic log? This cannot be undone. Download it first if you need a copy.',
            alert:   'Clear all alerts? This cannot be undone. Download the log first if you need a copy.',
        },
        clearLogFail:        'Could not clear the log.',
        enterLicenseKey:     'Please enter a license key',
        deactivateConfirm:   'Are you sure you want to deactivate your license?',
        licenseActivateFail: 'License activation failed',
        licenseDeactivateFail: 'License deactivation failed',
        ajaxError:           'An error occurred: %error%',
        noProcessesFound:    '<strong>No processes found for this filter.</strong>',
        reloadDelay:         2000,
    };

    /* ── AJAX Actions ───────────────────────────────────────────────── */
    const AJAX_ACTIONS = {
        activateLicense:   'rtsm_activate_license',
        deactivateLicense: 'rtsm_deactivate_license',
        downloadLog:       'rtsm_download_log',
        clearLog:          'rtsm_clear_log',
    };

    /* ── Data Attributes ────────────────────────────────────────────── */
    const DATA_ATTRS = {
        action:     'data-action',
        filter:     'data-filter',
        nonce:      'data-nonce',
        url:        'data-url',
    };

    /**
     * Delegated click binding. Every tab except the first one shown is
     * injected later by the rtsm_load_tab AJAX call, so handlers bound
     * directly at DOMContentLoaded never reached those tabs' buttons.
     * The handler runs with `this` set to the matched element.
     */
    function onClick(selector, handler) {
        document.addEventListener('click', function(event) {
            const el = event.target.closest(selector);
            if (el) {
                handler.call(el, event);
            }
        });
    }

    // ========================================================================
    // RELOAD BUTTON HANDLERS
    // ========================================================================

    /**
     * Simple reload button handler
     */
    function initializeReloadButtons() {
        onClick(SELECTORS.reloadButton, function() {
            location.reload();
        });
    }

    // ========================================================================
    // CONFIRM & NAVIGATE HANDLERS
    // ========================================================================

    /**
     * Handle clear log data with confirmation
     */
    function initializeClearLogButtons() {
        onClick(SELECTORS.clearLogButton, function() {
            if (confirm(MESSAGES.clearConfirm)) {
                window.location.href = this.getAttribute(DATA_ATTRS.url);
            }
        });
    }

    // ========================================================================
    // LOG FILE BUTTONS (Logs tab + Traffic tab "Log File Management")
    // ========================================================================

    /**
     * Download / Clear buttons carry data-rtsm-log (traffic|alert) and
     * data-rtsm-log-action (download|clear).
     */
    function initializeLogFileButtons() {
        onClick(SELECTORS.logButton, function() {
            const config = window.rtsmAdmin;
            if (!config || !config.ajaxUrl || !config.nonce) return;

            const log    = this.getAttribute('data-rtsm-log');
            const action = this.getAttribute('data-rtsm-log-action');

            if (action === 'download') {
                const url = new URL(config.ajaxUrl, window.location.href);
                url.searchParams.set('action', AJAX_ACTIONS.downloadLog);
                url.searchParams.set('log', log);
                url.searchParams.set('nonce', config.nonce);
                window.location.href = url.toString();
                return;
            }

            if (action !== 'clear') return;
            if (!confirm(MESSAGES.clearLogConfirm[log] || MESSAGES.clearLogConfirm.traffic)) return;

            const button = this;
            button.disabled = true;

            const body = new FormData();
            body.append('action', AJAX_ACTIONS.clearLog);
            body.append('log', log);
            body.append('nonce', config.nonce);

            fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function(res) { return res.json(); })
                .then(function(result) {
                    if (result.success) {
                        // Re-render so counts, sizes and empty states update.
                        location.reload();
                        return;
                    }
                    button.disabled = false;
                    showLogNotice(button, (result.data && result.data.message) || MESSAGES.clearLogFail, 'error');
                })
                .catch(function(error) {
                    button.disabled = false;
                    showLogNotice(button, MESSAGES.ajaxError.replace('%error%', error.message), 'error');
                });
        });
    }

    /**
     * Inline notice at the top of the button's page section (the button may
     * sit in the section header or in a toolbar inside it).
     */
    function showLogNotice(button, message, type) {
        const section = button.closest('.mmi-process-section');
        const host    = section ? section.querySelector('.mmi-section-content') : button.parentElement;
        if (!host) return;
        let notice = host.querySelector(':scope > .rtsm-log-notice');
        if (!notice) {
            notice = document.createElement('div');
            host.insertAdjacentElement('afterbegin', notice);
        }
        notice.className = 'rtsm-log-notice ' + [CLASS_NAMES.notice, CLASS_NAMES.noticeInline, CLASS_NAMES.noticePrefix + type].join(' ');
        notice.innerHTML = '<p>' + escapeHtml(message) + '</p>';
    }

    // ========================================================================
    // LICENSE ACTIVATION HANDLERS
    // ========================================================================

    /**
     * Initialize license activation button
     */
    function initializeLicenseActivation() {
        onClick(SELECTORS.activateLicense, function() {
            const licenseKeyInput = document.querySelector(SELECTORS.licenseKeyInput);
            const messageDiv = document.querySelector(SELECTORS.licenseMessage);
            if (!licenseKeyInput) return;

            const licenseKey = licenseKeyInput.value.trim();
            if (!licenseKey) {
                showMessage(messageDiv, MESSAGES.enterLicenseKey, 'error');
                return;
            }

            activateLicense(licenseKey, messageDiv);
        });

        onClick(SELECTORS.deactivateLicense, function() {
            if (confirm(MESSAGES.deactivateConfirm)) {
                deactivateLicense(document.querySelector(SELECTORS.licenseMessage));
            }
        });
    }

    /**
     * Activate license via AJAX
     */
    function activateLicense(licenseKey, messageDiv) {
        const data = new FormData();
        data.append('action', AJAX_ACTIONS.activateLicense);
        data.append('license_key', licenseKey);
        data.append('nonce', document.querySelector(SELECTORS.licenseNonce)?.value || '');

        fetch(ajaxurl, {
            method: 'POST',
            body: data
        })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showMessage(messageDiv, result.data.message, 'success');
                    setTimeout(() => location.reload(), MESSAGES.reloadDelay);
                } else {
                    showMessage(messageDiv, result.data?.message || MESSAGES.licenseActivateFail, 'error');
                }
            })
            .catch(error => {
                const errorMsg = MESSAGES.ajaxError.replace('%error%', error.message);
                showMessage(messageDiv, errorMsg, 'error');
            });
    }

    /**
     * Deactivate license via AJAX
     */
    function deactivateLicense(messageDiv) {
        const data = new FormData();
        data.append('action', AJAX_ACTIONS.deactivateLicense);
        data.append('nonce', document.querySelector(SELECTORS.licenseNonce)?.value || '');

        fetch(ajaxurl, {
            method: 'POST',
            body: data
        })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showMessage(messageDiv, result.data.message, 'success');
                    setTimeout(() => location.reload(), MESSAGES.reloadDelay);
                } else {
                    showMessage(messageDiv, result.data?.message || MESSAGES.licenseDeactivateFail, 'error');
                }
            })
            .catch(error => {
                const errorMsg = MESSAGES.ajaxError.replace('%error%', error.message);
                showMessage(messageDiv, errorMsg, 'error');
            });
    }

    /**
     * Display a message to the user
     */
    function showMessage(messageDiv, message, type) {
        if (!messageDiv) return;

        messageDiv.innerHTML = '';
        const classList = [CLASS_NAMES.notice, CLASS_NAMES.noticeInline, CLASS_NAMES.noticePrefix + type];
        messageDiv.className = classList.join(' ');
        messageDiv.innerHTML = '<p>' + escapeHtml(message) + '</p>';
        messageDiv.classList.remove('mmi-hidden');
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        return window.MMIEscapeHtml(text);
    }

    // ========================================================================
    // PROCESS MONITOR - AJAX LOADING
    // ========================================================================

    /** Track current filter so refresh and kill reuse it */
    let currentProcessFilter = 'all';

    /**
     * Load process list from server via AJAX and render the table.
     * @param {string} filter  all|php|mysql|node|python|high-cpu
     */
    function loadProcesses(filter) {
        filter = filter || 'all';
        currentProcessFilter = filter;

        const container = document.getElementById('processes-container');
        if (!container) return;

        // Highlight the active filter button
        document.querySelectorAll('.rtsm-process-filter').forEach(function(btn) {
            const btnFilter = (btn.getAttribute('data-filter') || 'all').replace('_', '-');
            btn.classList.remove('button-primary', 'button-secondary');
            btn.classList.add(btnFilter === filter ? 'button-primary' : 'button-secondary');
        });

        container.innerHTML = '<p class="' + CLASS_NAMES.muted + '"><span class="mmi-loading"></span> Loading processes&hellip;</p>';

        const config = window.rtsmAdmin;
        if (!config || !config.ajaxUrl || !config.nonce) {
            container.innerHTML = '<div class="notice notice-error inline"><p>Configuration error. Please refresh the page.</p></div>';
            return;
        }

        const body = new FormData();
        body.append('action', 'rtsm_get_processes');
        body.append('nonce',  config.nonce);
        body.append('filter', filter);

        fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    renderProcessTable(data.data.processes);
                } else {
                    const msg = (data.data && data.data.message)
                        ? data.data.message
                        : (typeof data.data === 'string' ? data.data : 'Failed to load processes.');
                    container.innerHTML = '<div class="notice notice-warning inline "><p>' + escapeHtml(msg) + '</p></div>';
                }
            })
            .catch(function() {
                container.innerHTML = '<div class="notice notice-error inline "><p>Network error loading processes. Please try again.</p></div>';
            });
    }

    /**
     * Render the process data as a WP-style table inside #processes-container.
     * @param {Array} processes
     */
    function renderProcessTable(processes) {
        const container = document.getElementById('processes-container');
        if (!container) return;

        if (!processes || processes.length === 0) {
            container.innerHTML = '<div class="notice notice-info inline "><p>No processes match the current filter.</p></div>';
            return;
        }

        const rows = processes.map(function(p) {
            const cpu     = parseFloat(p.cpu) || 0;
            const cpuCls  = cpu >= 50 ? CLASS_NAMES.cpuHigh : cpu >= 20 ? CLASS_NAMES.cpuMedium : '';
            const killBtn = p.killable
                ? '<button type="button" class="button button-small rtsm-kill-process" data-pid="'
                  + escapeHtml(String(p.pid)) + '" title="Terminate process">&#x2715; Kill</button>'
                : '<span class="dashicons dashicons-lock ' + CLASS_NAMES.muted + '" title="System process — protected"></span>';
            return '<tr>'
                + '<td>' + escapeHtml(String(p.pid)) + '</td>'
                + '<td>' + escapeHtml(String(p.user)) + '</td>'
                + '<td class="' + cpuCls + '">' + escapeHtml(String(p.cpu)) + '%</td>'
                + '<td>' + escapeHtml(String(p.mem)) + '%</td>'
                + '<td class="' + CLASS_NAMES.nowrap + '">' + escapeHtml(String(p.elapsed)) + '</td>'
                + '<td class="' + CLASS_NAMES.truncate + '" title="' + escapeHtml(String(p.command)) + '"><code>' + escapeHtml(String(p.command)) + '</code></td>'
                + '<td>' + killBtn + '</td>'
                + '</tr>';
        }).join('');

        // PID first (ID column rule). Widths are per data-resize-col in admin.css.
        container.innerHTML = '<div class="' + CLASS_NAMES.tableScroll + '">'
            + '<table class="' + CLASS_NAMES.table + ' rtsm-process-table">'
            + '<thead><tr>'
            + '<th data-resize-col="pid">PID</th>'
            + '<th data-resize-col="user">User</th>'
            + '<th data-resize-col="cpu">CPU %</th>'
            + '<th data-resize-col="mem">Mem %</th>'
            + '<th data-resize-col="elapsed">Elapsed</th>'
            + '<th data-resize-col="command">Command</th>'
            + '<th data-resize-col="kill">Kill</th>'
            + '</tr></thead>'
            + '<tbody>' + rows + '</tbody>'
            + '</table></div>';

        // Wire up kill buttons
        container.querySelectorAll('.rtsm-kill-process').forEach(function(btn) {
            btn.addEventListener('click', function() {
                killProcess(this.getAttribute('data-pid'));
            });
        });
    }

    /**
     * Send a kill request for the given PID via AJAX.
     * @param {string} pid
     */
    function killProcess(pid) {
        if (!confirm('Kill process ' + escapeHtml(String(pid)) + '? This cannot be undone.')) return;

        const config = window.rtsmAdmin;
        if (!config) return;

        const body = new FormData();
        body.append('action', 'rtsm_kill_process');
        body.append('nonce',  config.nonce);
        body.append('pid',    pid);

        fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success) {
                    loadProcesses(currentProcessFilter);
                } else {
                    showProcessNotice('Kill failed: ' + String(data.data || 'Unknown error'), 'error');
                }
            })
            .catch(function() {
                showProcessNotice('Network error while killing process. Please try again.', 'error');
            });
    }

    /**
     * Inline notice above the process table (no blocking alert()).
     */
    function showProcessNotice(message, type) {
        const container = document.getElementById('processes-container');
        if (!container) return;
        let notice = container.previousElementSibling;
        if (!notice || !notice.classList.contains('rtsm-process-notice')) {
            notice = document.createElement('div');
            container.insertAdjacentElement('beforebegin', notice);
        }
        notice.className = 'rtsm-process-notice ' + [CLASS_NAMES.notice, CLASS_NAMES.noticeInline, CLASS_NAMES.noticePrefix + type].join(' ');
        notice.innerHTML = '<p>' + escapeHtml(message) + '</p>';
    }

    // ========================================================================
    // PROCESS FILTER HANDLERS
    // ========================================================================

    /**
     * Initialize process filter buttons
     */
    function initializeProcessFilters() {
        onClick(SELECTORS.processFilter, function() {
            // Normalize data-filter value (high_cpu → high-cpu) to match PHP
            const filter = (this.getAttribute(DATA_ATTRS.filter) || 'all').replace('_', '-');
            loadProcesses(filter);
        });

        // Refresh button — reload with current filter
        onClick(SELECTORS.refreshProcesses, function() {
            loadProcesses(currentProcessFilter);
        });
    }

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    document.addEventListener('DOMContentLoaded', function() {
        initializeReloadButtons();
        initializeClearLogButtons();
        initializeLogFileButtons();
        initializeLicenseActivation();
        initializeProcessFilters();

        // Auto-load processes if the processes tab is the initial active tab
        if (document.getElementById('processes-container')) {
            loadProcesses('all');
        }

        // Re-load processes when the tab is switched to via AJAX
        if (window.jQuery) {
            window.jQuery(document).on('rtsm_tab_loaded', function(event, tabName) {
                if (tabName === 'processes') {
                    loadProcesses(currentProcessFilter);
                }
            });
        }
    });
})();
