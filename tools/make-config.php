<?php
// Writes wp/wp-config.php for the local lab: SQLite, fresh salts, and a fresh 32-byte finance key.
$root = dirname( __DIR__ );
$k = array();
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $n ) {
	$k[] = "define( '$n', '" . bin2hex( random_bytes( 32 ) ) . "' );";
}
$enc = base64_encode( random_bytes( 32 ) );
$c = "<?php\n// Local lab config. SQLite via the SQLite Database Integration drop-in.\n"
	. "define( 'DB_NAME', 'wp' );\ndefine( 'DB_USER', '' );\ndefine( 'DB_PASSWORD', '' );\ndefine( 'DB_HOST', '' );\ndefine( 'DB_CHARSET', 'utf8mb4' );\ndefine( 'DB_COLLATE', '' );\n"
	. "define( 'DB_DIR', __DIR__ . '/../data/' );\ndefine( 'DB_FILE', getenv( 'WWP_DB_FILE' ) ?: 'portal.sqlite' );\n"
	. implode( "\n", $k ) . "\n"
	. "// Finance-data key: 32 random bytes, base64. In production keep a copy OUTSIDE the site backups.\ndefine( 'WWP_DATA_KEY', '$enc' );\n"
	. "\$table_prefix = 'wp_';\ndefine( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', __DIR__ . '/../data/debug.log' );\ndefine( 'WP_DEBUG_DISPLAY', false );\n"
	. "define( 'DISABLE_WP_CRON', true );\ndefine( 'WP_HTTP_BLOCK_EXTERNAL', true );\ndefine( 'AUTOMATIC_UPDATER_DISABLED', true );\n"
	. "if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }\nrequire_once ABSPATH . 'wp-settings.php';\n";
file_put_contents( "$root/wp/wp-config.php", $c );
echo "wrote wp/wp-config.php\n";
