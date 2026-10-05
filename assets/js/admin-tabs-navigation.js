/**
 * RTSM Admin Tabs Navigation JavaScript
 * Handles tab switching and dynamic content loading
 */

(function($) {
    'use strict';

    /* ── Selectors ─────────────────────────────────────────────────────── */
    const SELECTORS = {
        tabButton: '.nav-tab-wrapper .nav-tab',
        tabPane:   '.mmi-tab-pane',
        tabPrefix: '#tab-',
    };

    /* ── AJAX config ───────────────────────────────────────────────────── */
    const AJAX_ACTION = 'rtsm_load_tab';

    /* ── UI constants ──────────────────────────────────────────────────── */
    const FADE_DURATION = 200;

    /* ── Messages ──────────────────────────────────────────────────────── */
    const MESSAGES = {
        configError: 'Configuration error. Please refresh the page.',
        loadingTab:  '<div class="mmi-process-section"><p class="mmi-text-muted"><span class="mmi-loading"></span> Loading %tab%…</p></div>',
        loadFailed:  'Failed to load tab content: %error%',
        ajaxFailed:  'Error loading tab content: %error%',
    };

    $(document).ready(function() {

        /* Tab button click handler */
        $(document).on('click', SELECTORS.tabButton, function(e) {
            e.preventDefault();

            const tabName = $(this).data('tab');
            if (!tabName) return;

            /* Update URL without page reload */
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({ tab: tabName }, '', url);

            /* Update tab buttons */
            $(SELECTORS.tabButton).removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            /* Hide all panes, show selected */
            $(SELECTORS.tabPane).removeClass('active').hide();
            const $pane = $(SELECTORS.tabPrefix + tabName);
            $pane.addClass('active').fadeIn(FADE_DURATION);

            /* Load content if not already loaded */
            if ($pane.data('loaded') !== 'true' && $pane.data('loaded') !== true) {
                loadTabContent(tabName, $pane);
            }
        });

        /* Handle browser back/forward buttons */
        window.addEventListener('popstate', function(event) {
            if (event.state && event.state.tab) {
                $(`[data-tab="${event.state.tab}"]`).trigger('click');
            }
        });

        /* Set initial history state */
        const currentTab = new URLSearchParams(window.location.search).get('tab') || 'dashboard';
        window.history.replaceState({ tab: currentTab }, '', window.location.href);
    });

    /**
     * Load tab content dynamically via AJAX.
     * @param {string} tabName
     * @param {jQuery} $pane
     */
    function loadTabContent(tabName, $pane) {
        if (!window.rtsmAdmin || !window.rtsmAdmin.nonce) {
            $pane.html(`<div class="notice notice-error inline"><p>${MESSAGES.configError}</p></div>`);
            return;
        }

        /* Show loading state */
        $pane.html(MESSAGES.loadingTab.replace('%tab%', tabName));

        $.ajax({
            url:  (window.rtsmAdmin && window.rtsmAdmin.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action: AJAX_ACTION,
                tab:    tabName,
                nonce:  window.rtsmAdmin.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    $pane.html(response.data);
                    $pane.data('loaded', 'true');
                    $(document).trigger('rtsm_tab_loaded', [tabName]);
                } else {
                    const errorMsg = response.data || 'Unknown error';
                    $pane.html(`<div class="notice notice-error inline"><p>${MESSAGES.loadFailed.replace('%error%', errorMsg)}</p></div>`);
                }
            },
            error: function(xhr, status, error) {
                $pane.html(`<div class="notice notice-error inline"><p>${MESSAGES.ajaxFailed.replace('%error%', error)}</p></div>`);
            }
        });
    }

})(jQuery);

