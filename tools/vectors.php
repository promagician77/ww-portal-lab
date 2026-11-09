<?php
// Generates parity vectors from the real PHP code (access engine on the real DB, money parser).
$root = dirname( __DIR__ );
copy( "$root/data/clean.sqlite", "$root/data/vec.sqlite" );
putenv( 'WWP_DB_FILE=vec.sqlite' );
$_SERVER['HTTP_HOST'] = 'x'; $_SERVER['REQUEST_URI'] = '/';
require "$root/wp/wp-load.php";
update_option( 'wwp_programmes', array( 'p' => array( 'label' => 'P', 'courses' => array(), 'weeks' => 10 ) ) );
mt_srand( 20261109 );
global $wpdb;
$out = array( 'access' => array(), 'money' => array() );
for ( $s = 0; $s < 150; $s++ ) {
	$u = wp_insert_user( array( 'user_login' => "v$s", 'user_pass' => 'x', 'user_email' => "v$s@x.test" ) );
	$wins = array();
	$base = WWP_Access::add_days( '2026-01-01', mt_rand( 0, 400 ) );
	$n    = mt_rand( 1, 4 );
	for ( $k = 0; $k < $n; $k++ ) {
		$from   = WWP_Access::add_days( $base, mt_rand( -20, 160 ) );
		$to     = WWP_Access::add_days( $from, mt_rand( 1, 90 ) );
		$wins[] = array( $from, $to );
		$wpdb->insert( WWP_Schema::t( 'access' ), array( 'user_id' => $u, 'programme' => 'p', 'starts_on' => $from, 'ends_on' => $to, 'source' => 'v', 'created_at' => '2026-01-01 00:00:00' ) );
	}
	$days = array();
	foreach ( $wins as $w ) { $days[] = $w[0]; $days[] = $w[1]; $days[] = WWP_Access::add_days( $w[1], -1 ); }
	for ( $k = 0; $k < 6; $k++ ) { $days[] = WWP_Access::add_days( $base, mt_rand( -30, 260 ) ); }
	foreach ( array_unique( $days ) as $d ) {
		$st = WWP_Access::state( $u, 'p', $d );
		unset( $st['pending'] );
		$out['access'][] = array( 'windows' => $wins, 'today' => $d, 'want' => $st );
	}
}
$samples = array( 'R 12 500,50', '12,500.50', '12500', 'R1 234', '-350', '1.234,56', 'R 1 000 000', '(2 500)', "R\u{00A0}9\u{202F}999.9", '−R 400', 'R -400', '0,5', 'ZAR 45', '12abc', '', '  ', '1,2,3', '1.2.3', '99999999999999', 'R 0', '.5', ',75', '–R 1 900', 'r 300', '10 000.0' );
foreach ( $samples as $x ) { $out['money'][] = array( 'in' => $x, 'want' => WWP_Money::parse( $x ) ); }
file_put_contents( "$root/data/vectors.json", json_encode( $out, JSON_UNESCAPED_UNICODE ) );
echo count( $out['access'] ), ' access cases, ', count( $out['money'] ), " money cases\n";
