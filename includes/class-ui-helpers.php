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
            ];
        } elseif ($load1 >= 6.0) {
            return [
                'status' => 'high',
                'color' => '#f0b849',
                'icon' => '🟡',
                'label' => 'High',
                'class' => 'rtsm-stat-warning',
            ];
        } elseif ($load1 >= 4.0) {
            return [
                'status' => 'elevated',
                'color' => '#f0b849',
                'icon' => '🟡',
                'label' => 'Elevated',
                'class' => 'rtsm-stat-warning',
            ];
        } else {
            return [
                'status' => 'normal',
                'color' => '#45852C',
                'icon' => '✅',
                'label' => 'Normal',
                'class' => 'rtsm-stat-normal',
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
            ];
        } elseif ($percent >= 75) {
            return [
                'status' => 'warning',
                'color' => '#f0b849',
                'icon' => '🟡',
                'label' => 'Warning',
                'class' => 'rtsm-stat-warning',
            ];
        } else {
            return [
                'status' => 'normal',
                'color' => '#8c4bff',
                'icon' => '✅',
                'label' => 'Normal',
                'class' => 'rtsm-stat-normal',
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
            ];
        } elseif ($percent >= 60) {
            return [
                'status' => 'warning',
                'color' => '#f0b849',
                'icon' => '🟡',
                'label' => 'Warning',
                'class' => 'rtsm-stat-warning',
            ];
        } else {
            return [
                'status' => 'normal',
                'color' => '#2271b1',
                'icon' => '✅',
                'label' => 'Normal',
                'class' => 'rtsm-stat-normal',
            ];
        }
    }

    /**
     * Get status badge CSS classes
     * 
     * @param string $status Status key
     * @return string CSS class
     */
    public static function get_status_class($status) {
        $classes = [
            'critical' => 'mmi-status-critical',
            'error' => 'mmi-status-error',
            'warning' => 'mmi-status-warning',
            'elevated' => 'mmi-status-elevated',
            'high' => 'mmi-status-high',
            'success' => 'mmi-status-success',
            'normal' => 'mmi-status-normal',
            'info' => 'mmi-status-info'
        ];
        
        return isset($classes[$status]) ? $classes[$status] : 'mmi-status-default';
    }

    /**
     * Get colored status HTML
     * 
     * @param string $label Status label
     * @param string $color Hex color code
     * @param string $value Optional value to display
     * @return string HTML span with color
     */
    public static function get_colored_status($label, $color, $value = null) {
        $display = $value !== null ? $label . ' (' . $value . ')' : $label;
        // Use CSS classes instead of inline styles
        $class = 'status-' . self::color_to_class($color);
        return '<span class="' . esc_attr($class) . '">' . esc_html($display) . '</span>';
    }
    
    /**
     * Convert hex color to CSS class
     */
    private static function color_to_class($color) {
        switch($color) {
            case '#dc3232':
            case '#d63638':
                return 'critical';
            case '#f0b849':
                return 'warning';
            case '#45852C':
                return 'success';
            case '#2271b1':
                return 'info';
            default:
                return 'info';
        }
    }

    /**
     * Get threshold status HTML for metrics comparison
     * 
     * @param float $value Current value
     * @param float $warning_level Warning threshold
     * @param float $critical_level Critical threshold
     * @param string $format Format string (e.g., "%.2f" for numbers)
     * @param string $suffix Suffix to append (e.g., "%" or "%")
     * @return string HTML with status styling
     */
    public static function get_threshold_status($value, $warning_level, $critical_level, $format = '%.2f', $suffix = '') {
        if ($value >= $critical_level) {
            $icon = '🔴';
            $label = 'Critical';
            $class = 'status-critical';
        } elseif ($value >= $warning_level) {
            $icon = '🟡';
            $label = 'Warning';
            $class = 'status-warning';
        } else {
            $icon = '✅';
            $label = 'Normal';
            $class = 'status-success';
        }

        $formatted = sprintf($format, $value) . $suffix;
        return '<span class="' . esc_attr($class) . '">' . $icon . ' ' . $label . ' (' . $formatted . ')</span>';
    }

    /**
     * Render a stat box component
     * 
     * @param array $args Arguments for the stat box
     *        - 'label' (string) Stat label
     *        - 'value' (mixed) Stat value
     *        - 'subtitle' (string) Optional subtitle
     *        - 'color' (string) Optional left border color
     *        - 'status' (string) Optional status class
     *        - 'icon' (string) Optional icon HTML
     * @return string HTML for stat box
     */
    public static function render_stat_box($args) {
        $defaults = [
            'label'    => '',
            'value'    => '',
            'subtitle' => '',
            'color'    => '#2271b1',
            'status'   => '',
            'icon'     => '',
            'classes'  => '',     // Applied to .stat-value only (NOT outer div — avoids WP div.error conflicts)
            'id'       => '',     // Optional id for outer div (used by JS live-update)
            'progress' => null,   // Float 0-100: renders a progress bar when set
            'progress_color' => null, // Override bar fill color (defaults to border color)
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $border_class = 'stat-box-' . self::color_to_class($args['color']);
        // Outer div: only the border-colour variant class — never 'error'/'warning'/'success'
        // Adding those to the outer div triggers WordPress admin's div.error / div.warning styles
        $class = trim('mmi-panel-stat-box ' . $border_class);

        $id_attr = $args['id'] ? ' id="' . esc_attr($args['id']) . '"' : '';

        // Value element: use rtsm-stat-* classes that don't conflict with WP admin notices
        $value_classes = trim($args['status'] . ' ' . $args['classes']);

        // Progress bar
        $show_progress = $args['progress'] !== null;
        $progress_pct  = $show_progress ? min(100, max(0, (float) $args['progress'])) : 0;
        $bar_color     = $args['progress_color'] ?? $args['color'];

        ob_start();
        ?>
        <div class="<?php echo esc_attr($class); ?>"<?php echo $id_attr; ?>>
            <div class="stat-label"><?php echo esc_html($args['label']); ?></div>
            <div class="stat-value <?php echo esc_attr($value_classes); ?>">
                <?php echo wp_kses_post($args['icon']); ?>
                <?php echo wp_kses_post($args['value']); ?>
            </div>
            <?php if ($show_progress): ?>
                <div class="stat-progress-track">
                    <div class="stat-progress-fill"
                         style="--fill-pct:<?php echo esc_attr(number_format($progress_pct, 1)); ?>%;--fill-color:<?php echo esc_attr($bar_color); ?>;"
                         title="<?php echo esc_attr(number_format($progress_pct, 1) . '%'); ?>"></div>
                </div>
            <?php endif; ?>
            <?php if ($args['subtitle']): ?>
                <div class="stat-sublabel"><?php echo wp_kses_post($args['subtitle']); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a card component
     * 
     * @param array $args Arguments for the card
     *        - 'title' (string) Card title
     *        - 'icon' (string) Optional icon class
     *        - 'content' (string) Card content HTML
     *        - 'classes' (string) Additional classes
     * @return string HTML for card
     */
    public static function render_card($args) {
        $defaults = [
            'title' => '',
            'icon' => '',
            'content' => '',
            'classes' => ''
        ];
        
        $args = wp_parse_args($args, $defaults);
        $class = 'mmi-panel-card ' . $args['classes'];

        ob_start();
        ?>
        <div class="<?php echo esc_attr($class); ?>">
            <?php if ($args['title']): ?>
                <h2>
                    <?php if ($args['icon']): ?>
                        <span class="dashicons <?php echo esc_attr($args['icon']); ?>"></span>
                    <?php endif; ?>
                    <?php echo esc_html($args['title']); ?>
                </h2>
            <?php endif; ?>
            <div class="card-content">
                <?php echo wp_kses_post($args['content']); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a metric table row with status indicator
     * 
     * @param string $metric_name Metric name
     * @param string $normal Normal threshold
     * @param string $warning Warning threshold
     * @param string $critical Critical threshold
     * @param float $current_value Current value
     * @param string $format Format string
     * @return string HTML table row
     */
    public static function render_metric_row($metric_name, $normal, $warning, $critical, $current_value, $format = '%.2f') {
        $status = self::get_threshold_status($current_value, floatval($warning), floatval($critical), $format);
        
        ob_start();
        ?>
        <tr>
            <td><strong><?php echo esc_html($metric_name); ?></strong></td>
            <td><?php echo esc_html($normal); ?></td>
            <td><?php echo esc_html($warning); ?></td>
            <td><?php echo esc_html($critical); ?></td>
            <td><?php echo wp_kses_post($status); ?></td>
        </tr>
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
        if (stripos($alert_line, 'EMERGENCY') !== false || stripos($alert_line, 'CRITICAL') !== false) {
            return 'alert-emergency';
        } elseif (stripos($alert_line, 'WARNING') !== false) {
            return 'alert-warning';
        } elseif (stripos($alert_line, 'RESOLVED') !== false) {
            return 'alert-resolved';
        } elseif (stripos($alert_line, 'INFO') !== false) {
            return 'alert-info';
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
