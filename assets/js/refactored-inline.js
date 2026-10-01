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
    };

    /* ── CSS Classes ────────────────────────────────────────────────── */
    const CLASS_NAMES = {
        notice:       'notice',
        noticeInline: 'inline',
        noticeInfo:   'notice-info',
        noticePrefix: 'notice-',
        loadingText:  'mmi-rtsm-loading-text',
        noticeBody:   'mmi-rtsm-notice-inline',
        protectedIcon: 'mmi-rtsm-icon-protected',
        tdNowrap:     'mmi-rtsm-td-nowrap',
        codeCommand:  'mmi-rtsm-code-command',
        tableScroll:  'mmi-rtsm-table-scroll',
        colPid:       'mmi-rtsm-col-pid',
        colUser:      'mmi-rtsm-col-user',
        colCpu:       'mmi-rtsm-col-cpu',
        colMem:       'mmi-rtsm-col-mem',
        colElapsed:   'mmi-rtsm-col-elapsed',
        colKill:      'mmi-rtsm-col-kill',
    };

    /* ── Messages ───────────────────────────────────────────────────── */
    const MESSAGES = {
        clearConfirm:        'Are you sure you want to clear all traffic analysis data? This action cannot be undone.',
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
    };

    /* ── Data Attributes ────────────────────────────────────────────── */
    const DATA_ATTRS = {
        action:     'data-action',
        filter:     'data-filter',
        nonce:      'data-nonce',
        url:        'data-url',
    };

    // ========================================================================
    // RELOAD BUTTON HANDLERS
    // ========================================================================

    /**
     * Simple reload button handler
     */
    function initializeReloadButtons() {
        const reloadButtons = document.querySelectorAll(SELECTORS.reloadButton);
        reloadButtons.forEach(button => {
            button.addEventListener('click', function() {
                location.reload();
            });
        });
    }

    // ========================================================================
    // CONFIRM & NAVIGATE HANDLERS
    // ========================================================================

    /**
     * Handle clear log data with confirmation
     */
    function initializeClearLogButtons() {
        const clearButton = document.getElementById(SELECTORS.clearLogButton.slice(1));
        if (clearButton) {
            clearButton.addEventListener('click', function() {
                if (confirm(MESSAGES.clearConfirm)) {
                    const nonce = this.getAttribute(DATA_ATTRS.nonce);
                    const clearUrl = this.getAttribute(DATA_ATTRS.url);
                    window.location.href = clearUrl;
                }
            });
        }
    }

    // ========================================================================
    // LICENSE ACTIVATION HANDLERS
    // ========================================================================

    /**
     * Initialize license activation button
     */
    function initializeLicenseActivation() {
        const activateBtn = document.getElementById(SELECTORS.activateLicense.slice(1));
        const deactivateBtn = document.getElementById(SELECTORS.deactivateLicense.slice(1));
        const licenseKeyInput = document.getElementById(SELECTORS.licenseKeyInput.slice(1));
        const messageDiv = document.getElementById(SELECTORS.licenseMessage.slice(1));

        if (activateBtn && licenseKeyInput) {
            activateBtn.addEventListener('click', function() {
                const licenseKey = licenseKeyInput.value.trim();
                if (!licenseKey) {
                    showMessage(messageDiv, MESSAGES.enterLicenseKey, 'error');
                    return;
                }

                activateLicense(licenseKey, messageDiv);
            });
        }

        if (deactivateBtn) {
            deactivateBtn.addEventListener('click', function() {
                if (confirm(MESSAGES.deactivateConfirm)) {
                    deactivateLicense(messageDiv);
                }
            });
        }
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

        container.innerHTML = '<p class="' + CLASS_NAMES.loadingText + '">Loading processes&hellip;</p>';

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
                    container.classList.remove('processes-loading');
                    const msg = (data.data && data.data.message)
                        ? data.data.message
                        : (typeof data.data === 'string' ? data.data : 'Failed to load processes.');
                    container.innerHTML = '<div class="notice notice-warning inline ' + CLASS_NAMES.noticeBody + '"><p>' + escapeHtml(msg) + '</p></div>';
                }
            })
            .catch(function() {
                container.classList.remove('processes-loading');
                container.innerHTML = '<div class="notice notice-error inline ' + CLASS_NAMES.noticeBody + '"><p>Network error loading processes. Please try again.</p></div>';
            });
    }

    /**
     * Render the process data as a WP-style table inside #processes-container.
     * @param {Array} processes
     */
    function renderProcessTable(processes) {
        const container = document.getElementById('processes-container');
        if (!container) return;

        // Clear loading state — class drives centering/spinner CSS
        container.classList.remove('processes-loading');

        if (!processes || processes.length === 0) {
            container.innerHTML = '<div class="notice notice-info inline ' + CLASS_NAMES.noticeBody + '"><p>No processes match the current filter.</p></div>';
            return;
        }

        const rows = processes.map(function(p) {
            const cpu     = parseFloat(p.cpu) || 0;
            const cpuCls  = cpu >= 50 ? ' process-cpu high' : cpu >= 20 ? ' process-cpu medium' : ' process-cpu';
            const killBtn = p.killable
                ? '<button type="button" class="button button-small rtsm-kill-process" data-pid="'
                  + escapeHtml(String(p.pid)) + '" title="Terminate process">&#x2715; Kill</button>'
                : '<span class="' + CLASS_NAMES.protectedIcon + '" title="System process — protected">&#x1F512;</span>';
            return '<tr>'
                + '<td><code class="process-pid">' + escapeHtml(String(p.pid))     + '</code></td>'
                + '<td>'                                                            + escapeHtml(String(p.user))    + '</td>'
                + '<td class="' + cpuCls + '">'                                    + escapeHtml(String(p.cpu))     + '%</td>'
                + '<td>'                                                            + escapeHtml(String(p.mem))     + '%</td>'
                + '<td class="' + CLASS_NAMES.tdNowrap + '">'                      + escapeHtml(String(p.elapsed)) + '</td>'
                + '<td><code class="' + CLASS_NAMES.codeCommand + '">'             + escapeHtml(String(p.command)) + '</code></td>'
                + '<td>'                                                            + killBtn                       + '</td>'
                + '</tr>';
        }).join('');

        container.innerHTML = '<div class="' + CLASS_NAMES.tableScroll + '">'
            + '<table class="wp-list-table widefat fixed striped">'
            + '<thead><tr>'
            + '<th class="' + CLASS_NAMES.colPid + '">PID</th>'
            + '<th class="' + CLASS_NAMES.colUser + '">User</th>'
            + '<th class="' + CLASS_NAMES.colCpu + '">CPU %</th>'
            + '<th class="' + CLASS_NAMES.colMem + '">Mem %</th>'
            + '<th class="' + CLASS_NAMES.colElapsed + '">Elapsed</th>'
            + '<th>Command</th>'
            + '<th class="' + CLASS_NAMES.colKill + '">Kill</th>'
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
                    alert('Kill failed: ' + escapeHtml(String(data.data || 'Unknown error')));
                }
            })
            .catch(function() {
                alert('Network error while killing process. Please try again.');
            });
    }

    // ========================================================================
    // PROCESS FILTER HANDLERS
    // ========================================================================

    /**
     * Initialize process filter buttons
     */
    function initializeProcessFilters() {
        const filterButtons = document.querySelectorAll(SELECTORS.processFilter);
        filterButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                // Normalize data-filter value (high_cpu → high-cpu) to match PHP
                const filter = (this.getAttribute(DATA_ATTRS.filter) || 'all').replace('_', '-');
                loadProcesses(filter);
            });
        });

        // Refresh button — reload with current filter
        const refreshBtn = document.getElementById(SELECTORS.refreshProcesses.slice(1));
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function() {
                loadProcesses(currentProcessFilter);
            });
        }
    }

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    document.addEventListener('DOMContentLoaded', function() {
        initializeReloadButtons();
        initializeClearLogButtons();
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
