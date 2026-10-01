/**
 * MMI CloudFlare Protection - Value Metrics JavaScript
 */

(function($) {
    'use strict';

    /* ── Selectors ─────────────────────────────────────────────────────── */
    const SELECTORS = {
        card: '.mmi-panel-card',
    };

    $(document).ready(function() {
        /* Show upgrade nudge when user hovers over metrics */
        $(SELECTORS.card).on('mouseenter', function() {
            if (!$(this).data('nudge-shown')) {
                $(this).data('nudge-shown', true);

                /* Subtle animation to draw attention to upgrade value */
                $(this).find('.button-primary').addClass('rtsm-pulse-once');
            }
        });
    });

})(jQuery);

