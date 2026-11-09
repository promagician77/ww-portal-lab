<?php
// Programmatic WordPress install on SQLite (no browser, no MySQL).
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST'] = '127.0.0.1:8090';
$_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/../wp/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if ( is_blog_installed() ) { echo "already installed\n"; exit; }
$r = wp_install( 'Coaching Portal (demo)', 'admin', 'admin@example.test', false, '', 'demo-admin-pass-123' );
update_option( 'siteurl', 'http://127.0.0.1:8090' );
update_option( 'home', 'http://127.0.0.1:8090' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'timezone_string', 'Africa/Johannesburg' );
update_option( 'date_format', 'j M Y' );
echo "installed user_id={$r['user_id']} wp=" . get_bloginfo( 'version' ) . " db=" . ( defined( 'DB_ENGINE' ) ? DB_ENGINE : '?' ) . "\n";
