<?php
/**
 * RTSM Branding — Binary License Model
 *
 * RTSM uses a simple licensed / unlicensed model.
 * A valid MMI Suite license unlocks all features.
 * Legacy tier slug arguments ('premium', 'pro', 'server') are silently mapped
 * to 'licensed' for backward compatibility.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RTSM_Tier_Branding {

    /** License state configurations */
    const STATES = [
        'licensed' => [
            'label'      => 'MMI Suite',
            'color'      => '#45852C',
            'badge_bg'   => '#45852C',
            'badge_text' => '#ffffff',
            'icon'       => 'dashicons-yes-alt',
            'css_class'  => 'rtsm-tier-licensed',
        ],
        'free' => [
            'label'      => 'Free',
            'color'      => '#666666',
            'badge_bg'   => '#f0f0f1',
            'badge_text' => '#50575e',
            'icon'       => 'dashicons-chart-bar',
            'css_class'  => 'rtsm-tier-free',
        ],
    ];

    /**
     * Normalise a state/tier slug to 'licensed' or 'free'.
     * Maps legacy slugs ('premium', 'pro', 'server') to 'licensed'.
     *
     * @param string $state
     * @return string 'licensed' | 'free'
     */
    private static function normalise( string $state ): string {
        if ( in_array( $state, [ 'premium', 'pro', 'server', 'licensed' ], true ) ) {
            return 'licensed';
        }
        return 'free';
    }

    /**
     * Get state configuration or a specific key within it.
     *
     * @param string      $state Tier slug or license state.
     * @param string|null $key   Specific config key, or null for full array.
     * @return mixed
     */
    public static function get( string $state, ?string $key = null ) {
        $config = self::STATES[ self::normalise( $state ) ];
        return $key !== null ? ( $config[ $key ] ?? null ) : $config;
    }

    /**
     * Get the display label for a license state.
     *
     * @param string $state
     * @return string
     */
    public static function get_label( string $state ): string {
        return self::get( $state, 'label' ) ?? 'Free';
    }

    /**
     * Render a license-state badge.
     *
     * @param string $state 'licensed', 'free', or any legacy tier slug.
     * @return string HTML
     */
    public static function render_badge( string $state ): string {
        $config = self::get( $state );
        return sprintf(
            '<span class="rtsm-tier-badge %s" style="--mmi-tier-bg:%s;--mmi-tier-fg:%s;">
                <span class="dashicons %s"></span>
                <span class="rtsm-tier-label">%s</span>
            </span>',
            esc_attr( $config['css_class'] ),
            esc_attr( $config['badge_bg'] ),
            esc_attr( $config['badge_text'] ),
            esc_attr( $config['icon'] ),
            esc_html( $config['label'] )
        );
    }

    /**
     * Render a lock badge for tabs that require an active license.
     * The $required_tier argument is ignored in the binary model — kept for compat.
     *
     * @param string $required_tier Ignored. Kept for backward compatibility.
     * @return string HTML
     */
    public static function render_tab_badge( string $required_tier = 'licensed' ): string {
        return '<span class="mmi-badge mmi-badge-premium rtsm-tier-licensed">SUITE</span>';
    }

    /**
     * Return the next upgrade state from the given state.
     * For the binary model: 'free' upgrades to 'licensed', 'licensed' has no next state.
     *
     * @param string $current_state
     * @return string|null 'licensed' if currently free, null if already licensed.
     */
    public static function get_next_tier( string $current_state ): ?string {
        return self::normalise( $current_state ) === 'free' ? 'licensed' : null;
    }
}
