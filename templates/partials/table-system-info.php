<?php
/**
 * Template partial: System information table
 * 
 * @var array $stats Server statistics with system info
 */

if (!defined('ABSPATH')) {
    exit;
}

// Not currently included anywhere in this plugin (verified via a full grep
// for its filename) — guarded anyway in case it's wired up again. Full
// skeleton (not just []) since every key below is accessed bare.
$stats = $stats ?? [];
$stats += [
    'os'          => '',
    'php_version' => '',
    'web_server'  => '',
    'database'    => '',
];
?>
<table class="widefat">
    <tbody>
        <tr>
            <td class="width-200"><strong>Operating System:</strong></td>
            <td><?php echo esc_html($stats['os']); ?></td>
        </tr>
        <tr>
            <td><strong>PHP Version:</strong></td>
            <td><?php echo esc_html($stats['php_version']); ?></td>
        </tr>
        <tr>
            <td><strong>Web Server:</strong></td>
            <td><?php echo esc_html($stats['web_server']); ?></td>
        </tr>
        <tr>
            <td><strong>Database:</strong></td>
            <td><?php echo esc_html($stats['database']); ?></td>
        </tr>
    </tbody>
</table>
