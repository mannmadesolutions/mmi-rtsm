<?php
/**
 * UI Helper Class
 * 
 * Provides utility methods for rendering UI components and determining status
 * 
 * @package MMI_RTSM
 * @since 2.3.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTSM_UI_Helpers {

    /**
     * Canonical display thresholds — single source of truth for ALL display layers.
     * Passed via wp_localize_script so JS never needs hardcoded threshold values.
     *
     * @return array
     */
    public static function get_thresholds(): array {
        return [
            'load'   => ['critical' => 8.0, 'high' => 6.0, 'elevated' => 4.0],
            'cpu'    => ['critical' => 80,  'warning' => 60],
            'memory' => ['critical' => 90,  'warning' => 75],
        ];
    }

    /**
     * Determine load average status and styling
     * 
     * @param float $load1 Load average (1 minute)
     * @return array Status with color, icon, and class
     */
    public static function get_load_status($load1) {
        if ($load1 >= 8.0) {
            return [
                'status' => 'critical',
                'color' => '#dc3232',
                'icon' => '🔴',
                'label' => 'Critical',
                'class' => 'rtsm-stat-critical',
                'variant' => 'error',
            ];
        } elseif ($load1 >= 6.0) {
            return [
                'status' => 'high',
                'color' => '#f0b849',
                'icon' => '🟡',
                'label' => 'High',
                'class' => 'rtsm-stat-warning',
                'variant' => 'warning',
            ];
        } elseif ($load1 >= 4.0) {
            return [
                'status' => 'elevated',
                'color' => '#f0b849',
                'icon' => '🟡',
                'label' => 'Elevated',
                'class' => 'rtsm-stat-warning',
                'variant' => 'warning',
            ];
        } else {
            return [
                'status' => 'normal',
                'color' => '#45852C',
                'icon' => '✅',
                'label' => 'Normal',
                'class' => 'rtsm-stat-normal',
                'variant' => 'success',
            ];
        }
    }

    /**
     * Determine memory status and styling
     * 
     * @param float $percent Memory usage percentage
     * @return array Status with color, icon, and class
     */
    public static function get_memory_status($percent) {
        if ($percent >= 90) {
            return [
                'status' => 'critical',
                'color' => '#dc3232',
                'icon' => '🔴',
                'label' => 'Critical',
                'class' => 'rtsm-stat-critical',
                'variant' => 'error',
            ];
        } elseif ($percent >= 75) {
            return [
                'status' => 'warning',
                'color' => '#f0b849',
                'icon' => '🟡',
                'label' => 'Warning',
                'class' => 'rtsm-stat-warning',
                'variant' => 'warning',
            ];
        } else {
            return [
                'status' => 'normal',
                'color' => '#8c4bff',
                'icon' => '✅',
                'label' => 'Normal',
                'class' => 'rtsm-stat-normal',
                'variant' => 'success',
            ];
        }
    }

    /**
     * Determine CPU status and styling
     * 
     * @param float $percent CPU usage percentage
     * @return array Status with color, icon, and class
     */
    public static function get_cpu_status($percent) {
        if ($percent >= 80) {
            return [
                'status' => 'critical',
                'color' => '#dc3232',
                'icon' => '🔴',
                'label' => 'Critical',
                'class' => 'rtsm-stat-critical',
                'variant' => 'error',
            ];
        } elseif ($percent >= 60) {
            return [
                'status' => 'warning',
                'color' => '#f0b849',
                'icon' => '🟡',
                'label' => 'Warning',
                'class' => 'rtsm-stat-warning',
                'variant' => 'warning',
            ];
        } else {
            return [
                'status' => 'normal',
                'color' => '#2271b1',
                'icon' => '✅',
                'label' => 'Normal',
                'class' => 'rtsm-stat-normal',
                'variant' => 'success',
            ];
        }
    }

    /**
     * Page-section header (suite standard: AGENTS.md → Admin Page Shell →
     * "Page body — page sections"). Callers wrap it themselves:
     *
     *   <div class="mmi-process-section">
     *     <?php echo RTSM_UI_Helpers::section_header( … ); ?>
     *     <div class="mmi-section-content">…</div>
     *   </div>
     *
     * @param string $icon    Dashicon name without the "dashicons-" prefix.
     * @param string $title   Plain-text title (escaped here).
     * @param string $desc    Plain-text one-line description (escaped here).
     * @param string $extra   Trusted HTML placed after the title (a badge).
     * @param string $actions Trusted HTML for the right-hand side (primary
     *                        button, status text) — a section's main action
     *                        belongs here, never in .mmi-header-actions.
     * @return string
     */
    public static function section_header($icon, $title, $desc = '', $extra = '', $actions = '') {
        ob_start();
        ?>
        <div class="mmi-section-header">
            <div>
                <h3 class="mmi-process-section-header"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span> <?php echo esc_html($title); ?><?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput -- trusted, built escaped by the caller. ?></h3>
                <?php if ($desc !== ''): ?>
                    <p class="mmi-process-section-description"><?php echo esc_html($desc); ?></p>
                <?php endif; ?>
            </div>
            <?php if ($actions !== ''): ?>
                <div class="rtsm-section-actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput -- trusted, built escaped by the caller. ?></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Thin progress bar (RTSM-local: the shared library has no meter
     * primitive). $variant is a shared state word: success|warning|error|info.
     *
     * @param float  $pct     0-100.
     * @param string $variant
     * @param string $id      Optional id on the fill (JS live updates).
     * @return string
     */
    public static function progress_bar($pct, $variant = 'info', $id = '') {
        $pct = min(100, max(0, (float) $pct));
        return sprintf(
            '<div class="rtsm-progress is-%1$s"%3$s><span class="rtsm-progress-fill" style="--fill-pct:%2$s%%;"></span></div>',
            esc_attr($variant),
            esc_attr(number_format($pct, 1, '.', '')),
            $id ? ' id="' . esc_attr($id) . '"' : ''
        );
    }

    /**
     * Traffic severity → shared state word. Must match traffic-tab.js's
     * SEVERITY map (the JS re-renders the same badge on every poll).
     */
    const SEVERITY_VARIANTS = [
        'NORMAL'    => ['variant' => 'success', 'icon' => '✅'],
        'ELEVATED'  => ['variant' => 'warning', 'icon' => '⚠️'],
        'CRITICAL'  => ['variant' => 'error',   'icon' => '🔴'],
        'EMERGENCY' => ['variant' => 'error',   'icon' => '🚨'],
    ];

    /**
     * Severity badge (shared .mmi-badge) for traffic/alert severities.
     *
     * @param string $sev NORMAL|ELEVATED|CRITICAL|EMERGENCY (unknown → NORMAL look).
     * @return string
     */
    public static function severity_badge($sev) {
        $sev  = strtoupper((string) $sev);
        $conf = self::SEVERITY_VARIANTS[$sev] ?? self::SEVERITY_VARIANTS['NORMAL'];
        return sprintf('<span class="mmi-badge %s">%s %s</span>', esc_attr($conf['variant']), $conf['icon'], esc_html($sev));
    }

    /**
     * Render a shared .mmi-stat-box KPI tile.
     *
     * @param array $args {
     *     @type string     $label    Tile label.
     *     @type string     $value    Value (trusted HTML allowed via wp_kses_post).
     *     @type string     $subtitle Optional meta line under the value.
     *     @type string     $variant  success|warning|error|info|'' (neutral).
     *     @type string     $icon     Optional icon/emoji before the value.
     *     @type string     $id       Optional id on the tile (JS live updates).
     *     @type float|null $progress 0-100 renders a progress bar when set.
     * }
     * @return string
     */
    public static function render_stat_box($args) {
        $args = wp_parse_args($args, [
            'label'    => '',
            'value'    => '',
            'subtitle' => '',
            'variant'  => '',
            'icon'     => '',
            'id'       => '',
            'progress' => null,
        ]);

        // `inline` opts the tile out of WP core's div.error relocation
        // (AGENTS.md → "Never Give a <div> a Bare error … Class Token").
        $class = trim('mmi-stat-box inline ' . $args['variant']);

        ob_start();
        ?>
        <div class="<?php echo esc_attr($class); ?>"<?php echo $args['id'] ? ' id="' . esc_attr($args['id']) . '"' : ''; ?>>
            <div class="mmi-stat-label"><?php echo esc_html($args['label']); ?></div>
            <div class="mmi-stat-value"><?php echo wp_kses_post(trim($args['icon'] . ' ' . $args['value'])); ?></div>
            <?php if ($args['progress'] !== null): ?>
                <?php echo self::progress_bar($args['progress'], $args['variant'] ?: 'info'); ?>
            <?php endif; ?>
            <?php if ($args['subtitle'] !== ''): ?>
                <div class="mmi-stat-meta"><?php echo wp_kses_post($args['subtitle']); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Format bytes to human-readable format
     * 
     * @param int $bytes Number of bytes
     * @return string Human-readable size
     */
    public static function format_bytes($bytes) {
        return size_format($bytes);
    }

    /**
     * Get alert severity classes
     * 
     * @param string $alert_line Log line
     * @return string CSS class
     */
    public static function get_alert_severity_class($alert_line) {
        // Shared .mmi-log-entry variants. `inline` keeps WP core from
        // relocating a div.error (see render_stat_box()).
        if (stripos($alert_line, 'EMERGENCY') !== false || stripos($alert_line, 'CRITICAL') !== false) {
            return 'error inline';
        } elseif (stripos($alert_line, 'WARNING') !== false) {
            return 'warning';
        } elseif (stripos($alert_line, 'RESOLVED') !== false) {
            return 'success';
        } elseif (stripos($alert_line, 'INFO') !== false) {
            return 'info';
        }
        return '';
    }

    /**
     * Render a notice/alert box
     * 
     * @param string $message Message text
     * @param string $type Notice type (success, error, warning, info)
     * @param bool $dismissible Whether notice is dismissible
     * @return string HTML notice
     */
    public static function render_notice($message, $type = 'info', $dismissible = true) {
        $class = 'notice notice-' . esc_attr($type);
        if ($dismissible) {
            $class .= ' is-dismissible';
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr($class); ?>">
            <p><?php echo wp_kses_post($message); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a feature overlay for unlicensed users.
     *
     * @param array $args {
     *     Overlay configuration.
     *     @type string $feature_name    Display name of the feature.
     *     @type string $description     Feature description.
     *     @type array  $features        Array of feature bullet points (HTML allowed via wp_kses_post).
     *     @type string $preview_content HTML content to show blurred in background.
     *     @type string $required_tier   Unused — kept for backward compatibility.
     * }
     * @return string HTML overlay
     */
    public static function render_premium_overlay( $args = [] ) {
        $defaults = [
            'feature_name'    => 'MMI Suite Feature',
            'description'     => 'This feature requires an active MMI Suite license.',
            'features'        => [],
            'preview_content' => '',
            'required_tier'   => 'licensed', // kept for compat; ignored
        ];

        $args            = wp_parse_args( $args, $defaults );
        $license_manager = RTSM_License_Manager::get_instance();

        ob_start();
        ?>
        <div class="rtsm-premium-preview-wrapper">
            <?php if ( ! empty( $args['preview_content'] ) ) : ?>
                <div class="rtsm-premium-preview-content">
                    <?php echo $args['preview_content']; ?>
                </div>
            <?php endif; ?>

            <div class="rtsm-premium-feature-overlay">
                <div class="rtsm-premium-feature-content">
                    <div class="rtsm-premium-lock-icon">🔓</div>

                    <h2 class="rtsm-premium-heading">
                        <?php echo esc_html( $args['feature_name'] ); ?>
                    </h2>

                    <p class="rtsm-premium-description">
                        <?php echo esc_html( $args['description'] ); ?>
                    </p>

                    <?php if ( ! empty( $args['features'] ) ) : ?>
                        <ul class="rtsm-premium-feature-list">
                            <?php foreach ( $args['features'] as $feature ) : ?>
                                <li><?php echo wp_kses_post( $feature ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div class="rtsm-premium-actions">
                        <a href="<?php echo esc_url( $license_manager->get_upgrade_url() ); ?>"
                           class="rtsm-premium-btn rtsm-premium-btn-primary"
                           target="_blank">
                            <span class="dashicons dashicons-unlock"></span>
                            Get MMI Suite
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=mmi-rtsm&tab=settings' ) ); ?>"
                           class="rtsm-premium-btn rtsm-premium-btn-secondary">
                            <span class="dashicons dashicons-admin-network"></span>
                            Activate License
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

}
