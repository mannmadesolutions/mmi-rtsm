<?php
/**
 * RTSM Activity Logs Tab
 */

if (!defined('ABSPATH')) {
    exit;
}

$log_file = rtrim( mmi_shared_lib_log_dir(), '/' ) . '/server-traffic-analysis.log';
$alert_file = rtrim( mmi_shared_lib_log_dir(), '/' ) . '/server-alerts.log';

// Get recent log entries
$traffic_logs = [];
$alert_logs = [];

if (file_exists($log_file)) {
    $traffic_logs = array_reverse(array_slice(file($log_file), -50));
}

if (file_exists($alert_file)) {
    $alert_logs = array_reverse(array_slice(file($alert_file), -50));
}
?>

<?php
$rtsm_log_buttons = static function ( $log, $clear_label ) {
    return '<button type="button" class="button button-secondary" id="download-' . esc_attr( $log ) . '-log-page" data-rtsm-log="' . esc_attr( $log ) . '" data-rtsm-log-action="download"><span class="dashicons dashicons-download"></span> Download Full Log</button>'
        . '<button type="button" class="button button-secondary" id="clear-' . esc_attr( $log ) . '-log-page" data-rtsm-log="' . esc_attr( $log ) . '" data-rtsm-log-action="clear"><span class="dashicons dashicons-trash"></span> ' . esc_html( $clear_label ) . '</button>';
};
?>

<div class="mmi-process-section">
    <?php echo RTSM_UI_Helpers::section_header(
        'megaphone',
        'System Alerts',
        empty( $alert_logs ) ? 'Alerts RTSM has written to the alert log.' : 'Last 50 alert-log entries, newest first.',
        empty( $alert_logs ) ? '' : ' <span class="mmi-badge warning">' . esc_html( count( $alert_logs ) ) . ' recent</span>',
        empty( $alert_logs ) ? '' : $rtsm_log_buttons( 'alert', 'Clear All Alerts' )
    ); ?>
    <div class="mmi-section-content">
        <?php if ( empty( $alert_logs ) ) : ?>
            <p class="mmi-log-empty"><span class="dashicons dashicons-yes-alt mmi-text-success"></span> No alerts recorded. Your server is running smoothly!</p>
        <?php else : ?>
            <div class="mmi-log-container">
                <?php foreach ( $alert_logs as $log ) : ?>
                    <div class="mmi-log-entry <?php echo esc_attr( RTSM_UI_Helpers::get_alert_severity_class( $log ) ); ?>"><?php echo esc_html( rtrim( $log ) ); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="mmi-process-section">
    <?php echo RTSM_UI_Helpers::section_header(
        'networking',
        'Traffic Logs',
        empty( $traffic_logs ) ? 'Requests logged while server load was elevated.' : 'Last 50 traffic-log entries, newest first.',
        empty( $traffic_logs ) ? '' : ' <span class="mmi-badge">' . esc_html( count( $traffic_logs ) ) . ' recent</span>',
        empty( $traffic_logs ) ? '' : $rtsm_log_buttons( 'traffic', 'Clear Traffic Logs' )
    ); ?>
    <div class="mmi-section-content">
        <?php if ( empty( $traffic_logs ) ) : ?>
            <p class="mmi-log-empty">No traffic logged yet. Logs will appear when server load exceeds 5.0</p>
        <?php else : ?>
            <div class="mmi-log-container">
                <?php foreach ( $traffic_logs as $log ) :
                    $severity_class = '';
                    $load_match     = [];
                    if ( preg_match( '/Load:\s*([\d\.]+)/', $log, $load_match ) ) {
                        $load_value = floatval( $load_match[1] );
                        if ( $load_value >= 10.0 ) {
                            $severity_class = 'error inline';
                        } elseif ( $load_value >= 7.0 ) {
                            $severity_class = 'warning';
                        }
                    }
                ?>
                    <div class="mmi-log-entry <?php echo esc_attr( $severity_class ); ?>"><?php echo esc_html( rtrim( $log ) ); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="mmi-process-section">
    <?php echo RTSM_UI_Helpers::section_header( 'media-document', 'Log File Information', 'Where the logs live on disk.' ); ?>
    <div class="mmi-section-content">
        <div class="mmi-table-scroll-wrapper">
            <table class="mmi-uniform-table mmi-uniform-table--hoverable">
                <thead>
                    <tr>
                        <th>Log Type</th>
                        <th>File Path</th>
                        <th>Size</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( [ 'Traffic Analysis' => $log_file, 'System Alerts' => $alert_file ] as $rtsm_label => $rtsm_path ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $rtsm_label ); ?></strong></td>
                        <td><code class="rtsm-break-all"><?php echo esc_html( $rtsm_path ); ?></code></td>
                        <td><?php echo file_exists( $rtsm_path ) ? esc_html( size_format( filesize( $rtsm_path ) ) ) : '—'; ?></td>
                        <td><?php echo file_exists( $rtsm_path ) ? '<span class="mmi-badge success">Active</span>' : '<span class="mmi-badge">No data</span>'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
