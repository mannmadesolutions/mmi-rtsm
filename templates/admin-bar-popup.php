<?php 
/**
 * Real-Time Server Monitor - Admin Bar Popup Template
 * 
 * Styles and JavaScript are now loaded from external files.
 * Assets and localization handled in class-server-monitor.php
 * 
 * @package MMI_RTSM
 * @since 2.1.1
 */

if (!defined('ABSPATH')) exit; 
?>
<!-- RTSM v2.1.2 - Build: <?php echo time(); ?> -->
<div id="rtsm-popup">
    <button id="rtsm-close">&times;</button>
    <h3>📊 <?php _e('Server Monitor', 'mmi-rtsm'); ?></h3>
    <div id="rtsm-popup-content">
        <p class="rtsm-loading"><?php _e('Loading...', 'mmi-rtsm'); ?></p>
    </div>
</div>
