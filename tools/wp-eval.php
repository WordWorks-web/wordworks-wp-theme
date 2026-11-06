<?php
// Minimal stand-in for `wp eval-file <file> [args...]` for local testing without WP-CLI.
// Usage: php tools/wp-eval.php /path/to/wordpress file.php [args...]
$ww_root = $argv[1]; $ww_file = $argv[2]; $args = array_slice( $argv, 3 );
$_SERVER['HTTP_HOST'] = 'localhost:8080'; $_SERVER['REQUEST_URI'] = '/';
define( 'WP_USE_THEMES', false );
require $ww_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );
include $ww_file;
