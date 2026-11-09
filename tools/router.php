<?php
// php -S router: real files as-is, everything else through WordPress (pretty permalinks).
$root = realpath( __DIR__ . '/../wp' );
$path = urldecode( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
$file = $root . $path;
if ( $path !== '/' && file_exists( $file ) ) {
	if ( is_dir( $file ) ) {
		if ( file_exists( rtrim( $file, '/' ) . '/index.php' ) ) {
			if ( substr( $path, -1 ) !== '/' ) { header( 'Location: ' . $path . '/' ); exit; }
			$_SERVER['SCRIPT_NAME'] = rtrim( $path, '/' ) . '/index.php';
			chdir( rtrim( $file, '/' ) );
			require rtrim( $file, '/' ) . '/index.php';
			return true;
		}
	}
	if ( str_contains( $path, '/wwp-private/' ) ) { http_response_code( 403 ); echo 'Forbidden'; return true; } // mirrors the .htaccess deny / nginx rule
	return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
chdir( $root );
require $root . '/index.php';
