<?php
$_SERVER['HTTP_HOST'] = '127.0.0.1:8090'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/../wp/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$r = activate_plugin( 'ww-portal/ww-portal.php' );
echo is_wp_error( $r ) ? 'ERR ' . $r->get_error_message() : 'activated', "\n";
global $wpdb;
foreach ( array( 'snapshots', 'access', 'assignments', 'audit', 'demo_enrol' ) as $t ) {
	echo $t, ': ', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wwp_$t" ) ?? 'MISSING', "\n";
}
echo 'cols: ', implode( ',', $wpdb->get_col( "DESCRIBE {$wpdb->prefix}wwp_snapshots" ) ), "\n";
