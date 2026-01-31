<?php
/**
 * Version Check - Access this file directly to verify plugin version
 * URL: http://localhost:8888/wordpress/wp-content/plugins/wp-copilot/version-check.php
 */

header('Content-Type: application/json');

echo json_encode(array(
    'plugin_version' => '1.2.1',
    'file_location' => __FILE__,
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
));
