<?php
/**
 * Test runner: real WordPress 7.1.4, real database (SQLite drop-in), real plugin code.
 * Usage: WWP_DB_FILE=test.sqlite php tests/run.php [out.json]
 * The runner copies a clean, freshly-installed database first, so runs are repeatable.
 */
$root = dirname( __DIR__, 5 );
$data = $root . '/data';
copy( "$data/clean.sqlite", "$data/test.sqlite" );
putenv( 'WWP_DB_FILE=test.sqlite' );
$_SERVER['HTTP_HOST']   = '127.0.0.1:8090';
$_SERVER['REQUEST_URI'] = '/';
require $root . '/wp/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$results = array();
$current = null;
function t( string $group, string $name, callable $fn ): void {
	global $results, $current;
	$current = array( 'group' => $group, 'name' => $name, 'ok' => true, 'checks' => 0, 'detail' => '' );
	$t0      = hrtime( true );
	try {
		$d = $fn();
		if ( is_string( $d ) ) {
			$current['detail'] = $d;
		}
	} catch ( Throwable $e ) {
		$current['ok']     = false;
		$current['detail'] = get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine();
	}
	$current['ms'] = round( ( hrtime( true ) - $t0 ) / 1e6, 2 );
	$results[]     = $current;
	printf( "%s  %-10s %s%s\n", $current['ok'] ? 'PASS' : 'FAIL', $group, $name, $current['detail'] ? '  — ' . $current['detail'] : '' );
}
function ok( $cond, string $msg = '' ): void {
	global $current;
	++$current['checks'];
	if ( ! $cond ) {
		throw new Exception( 'assert failed: ' . $msg );
	}
}
function eq( $a, $b, string $msg = '' ): void {
	ok( $a === $b, $msg . ' expected ' . var_export( $b, true ) . ' got ' . var_export( $a, true ) );
}
function mkuser( string $login, string $role = 'subscriber' ): int {
	return wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'pw-' . $login . '-123', 'user_email' => "$login@example.test", 'role' => $role, 'first_name' => ucfirst( $login ) ) );
}
function rest( string $method, string $route, array $params = array(), $body = null ): WP_REST_Response {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	return rest_get_server()->dispatch( $r );
}
function sample( int $salary = 30000, int $savings = 5000, int $card = 12000 ): array {
	return array(
		'income'      => array( array( 'label' => 'Salary', 'amount' => "R $salary" ) ),
		'expenses'    => array( array( 'label' => 'Rent', 'amount' => '9 500' ), array( 'label' => 'Food', 'amount' => '4 250,50' ) ),
		'assets'      => array( array( 'label' => 'Savings', 'amount' => (string) $savings ), array( 'label' => 'Car', 'amount' => '120000' ) ),
		'liabilities' => array( array( 'label' => 'Credit card', 'amount' => (string) $card ), array( 'label' => 'Car finance', 'amount' => '98 000' ) ),
	);
}
function time_travel( ?string $d ): void {
	remove_all_filters( 'wwp_today' );
	if ( $d ) {
		add_filter( 'wwp_today', static fn() => $d );
	}
}

global $wpdb;
$T = static fn( $n ) => WWP_Schema::t( $n );
update_option( 'wwp_programmes', array( 'money-reset' => array( 'label' => 'Money Reset', 'courses' => array( 101, 102 ), 'weeks' => 10, 'renew_url' => '/renew/' ) ) );
update_option( 'wwp_demo_courses', array(
	101 => array( 'title' => 'Course A', 'lessons' => array_fill( 0, 12, 'Lesson' ), 'urls' => array() ),
	102 => array( 'title' => 'Course B', 'lessons' => array_fill( 0, 4, 'Lesson' ), 'urls' => array() ),
) );
$admin = 1;
$a     = mkuser( 'alice' );
$b     = mkuser( 'bongani' );
$coach = mkuser( 'coachone', 'wwp_coach' );
$coach2 = mkuser( 'coachtwo', 'wwp_coach' );
update_user_meta( $a, 'wwp_coach_id', $coach );
update_user_meta( $b, 'wwp_coach_id', $coach2 );
wp_set_current_user( $a );
time_travel( '2026-11-09' );

/* ---------------- money ---------------- */
t( 'money', 'Parses how South Africans type amounts (13 formats)', function () {
	$cases = array( 'R 12 500,50' => 1250050, '12,500.50' => 1250050, '12500' => 1250000, 'R1 234' => 123400, '-350' => -35000, '1.234,56' => 123456,
		'R 1 000 000' => 100000000, '(2 500)' => -250000, "R\u{00A0}9\u{202F}999.9" => 999990, '−R 400' => -40000, 'R -400' => -40000, '0,5' => 50, 'ZAR 45' => 4500 );
	foreach ( $cases as $in => $want ) {
		eq( WWP_Money::parse( $in ), $want, $in );
	}
	ok( null === WWP_Money::parse( '12abc' ) && null === WWP_Money::parse( '99999999999999' ), 'rejects junk and absurd values' );
	foreach ( array( -40000, 123456789, -5, 0 ) as $c ) {
		eq( WWP_Money::parse( WWP_Money::format( $c, true ) ), $c, 'round trip ' . $c );
	}
	return count( $cases ) . ' formats + format→parse round trip, integer cents';
} );

/* ---------------- finance ---------------- */
t( 'finance', 'Net worth, monthly surplus and savings rate are exact to the cent', function () use ( $a ) {
	$s = WWP_Finance::save( $a, sample(), 'dashboard', '2026-10-01' );
	$t = $s['totals'];
	eq( $t['income'], 3000000 );
	eq( $t['expenses'], 1375050 );
	eq( $t['monthly_surplus'], 1624950 );
	eq( $t['net_worth'], 500000 + 12000000 - 1200000 - 9800000 );
	eq( $t['savings_rate_bp'], 5416 ); // 54.16%
	return 'surplus R 16 249.50, net worth R 15 000, savings rate 54.16%';
} );

t( 'finance', 'Stored payload is ciphertext: no labels or amounts in the database', function () use ( $a, $T ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$T('snapshots')} WHERE user_id = %d ORDER BY id DESC LIMIT 1", $a ), ARRAY_A );
	foreach ( array( 'Salary', 'Credit card', '3000000', '1250050', 'Rent' ) as $needle ) {
		ok( false === stripos( $row['payload'], $needle ), "found $needle" );
	}
	$dump = implode( '', array_map( 'implode', $wpdb->get_results( "SELECT * FROM {$T('snapshots')}", ARRAY_N ) ) );
	ok( false === stripos( $dump, 'salary' ), 'plain text in a full table dump' );
	eq( $row['key_id'], WWP_Crypto::key_id() );
	return 'payload ' . strlen( $row['payload'] ) . ' bytes base64, key_id ' . $row['key_id'];
} );

t( 'finance', "A row copied onto another client's account will not decrypt", function () use ( $a, $b, $T ) {
	global $wpdb;
	$src = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$T('snapshots')} WHERE user_id = %d LIMIT 1", $a ), ARRAY_A );
	unset( $src['id'] );
	$src['user_id'] = $b;
	$wpdb->insert( $T( 'snapshots' ), $src );
	$got = WWP_Finance::all( $b );
	eq( count( $got ), 1 );
	eq( $got[0]['ok'], false, 'decrypts for wrong owner' );
	eq( $got[0]['data'], null );
	$wpdb->delete( $T( 'snapshots' ), array( 'user_id' => $b ) );
	return "Bongani's view: 1 row, unreadable, no figures shown";
} );

t( 'finance', 'Wrong key or flipped byte: decrypt returns nothing, never garbage', function () {
	$aad    = WWP_Crypto::aad( 5, '2026-01-01' );
	$sealed = WWP_Crypto::seal( '{"x":1}', $aad );
	eq( WWP_Crypto::open( $sealed, $aad ), '{"x":1}' );
	$raw      = base64_decode( $sealed );
	$raw[30]  = chr( ord( $raw[30] ) ^ 1 );
	eq( WWP_Crypto::open( base64_encode( $raw ), $aad ), null, 'tampered' );
	$other = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
	$n     = random_bytes( 24 );
	eq( WWP_Crypto::open( base64_encode( $n . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( 'x', $aad, $n, $other ) ), $aad ), null, 'other key' );
	eq( WWP_Crypto::open( $sealed, WWP_Crypto::aad( 5, '2026-01-02' ) ), null, 'other date' );
} );

t( 'finance', 'Append-only: saving again adds a row and leaves the old one byte-identical', function () use ( $a, $T ) {
	global $wpdb;
	$before = $wpdb->get_results( $wpdb->prepare( "SELECT id, hash, payload FROM {$T('snapshots')} WHERE user_id = %d", $a ), ARRAY_A );
	WWP_Finance::save( $a, sample( 31000, 7000, 11000 ), 'dashboard', '2026-10-15' );
	$after = $wpdb->get_results( $wpdb->prepare( "SELECT id, hash, payload FROM {$T('snapshots')} WHERE user_id = %d", $a ), ARRAY_A );
	eq( count( $after ), count( $before ) + 1 );
	eq( array_slice( $after, 0, count( $before ) ), $before, 'old rows changed' );
	$src = file_get_contents( WWP_DIR . '/includes/class-wwp-finance.php' );
	ok( ! preg_match( '/->update\(|UPDATE\s/i', $src ), 'an UPDATE path exists in finance code' );
	return 'no UPDATE statement exists in the finance class';
} );

t( 'finance', 'Hash chain catches a row edited or deleted directly in the database', function () use ( $a, $T ) {
	global $wpdb;
	WWP_Finance::save( $a, sample( 32000, 9000, 10000 ), 'dashboard', '2026-11-01' );
	$v = WWP_Finance::verify_chain( $a );
	ok( $v['ok'] && 3 === $v['rows'], 'clean chain' );
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$T('snapshots')} WHERE user_id = %d ORDER BY id", $a ) );
	$orig = $wpdb->get_var( "SELECT taken_on FROM {$T('snapshots')} WHERE id = {$ids[1]}" );
	$wpdb->query( "UPDATE {$T('snapshots')} SET taken_on = '2026-10-16' WHERE id = {$ids[1]}" ); // back-dating
	$v1 = WWP_Finance::verify_chain( $a );
	$wpdb->query( "UPDATE {$T('snapshots')} SET taken_on = '$orig' WHERE id = {$ids[1]}" );
	$row = $wpdb->get_row( "SELECT * FROM {$T('snapshots')} WHERE id = {$ids[1]}", ARRAY_A );
	$wpdb->delete( $T( 'snapshots' ), array( 'id' => $ids[1] ) ); // silent deletion
	$v2 = WWP_Finance::verify_chain( $a );
	$wpdb->insert( $T( 'snapshots' ), $row );
	eq( $v1['broken_at'], (int) $ids[1], 'edit' );
	eq( $v2['broken_at'], (int) $ids[2], 'delete' );
	return "edit flagged at row {$ids[1]}, deletion flagged at row {$ids[2]}";
} );

t( 'finance', 'Bad input is refused whole; nothing half-saved', function () use ( $a ) {
	$n   = WWP_Finance::count( $a );
	$bad = sample();
	$bad['expenses'][] = array( 'label' => 'Typo', 'amount' => '4OO' );
	$r = WWP_Finance::save( $a, $bad );
	ok( is_wp_error( $r ) && 400 === $r->get_error_data()['status'], 'error' );
	ok( str_contains( $r->get_error_message(), 'expenses line 3' ), 'message names the line: ' . $r->get_error_message() );
	ok( is_wp_error( WWP_Finance::save( $a, sample(), 'dashboard', '2026-11-10' ) ), 'future date' );
	ok( is_wp_error( WWP_Finance::save( $a, sample(), 'dashboard', '2026-02-30' ) ), '30 February' );
	eq( WWP_Finance::count( $a ), $n );
	return $r->get_error_message();
} );

t( 'finance', 'Numbers typed in a course lesson are the dashboard\'s latest figures', function () use ( $a ) {
	$s = WWP_Finance::save( $a, sample( 33000, 12000, 8000 ), 'course:money-reset/week-2' );
	$sum = WWP_Dashboard::summary( $a );
	eq( $sum['finance']['latest']['id'], $s['id'] );
	eq( $sum['finance']['latest']['source'], 'course:money-reset/week-2' );
	$d = WWP_Finance::save( $a, sample( 33000, 12500, 7500 ), 'dashboard' );
	$sum2 = WWP_Dashboard::summary( $a );
	eq( $sum2['finance']['latest']['id'], $d['id'] );
	ok( in_array( 'course:money-reset/week-2', array_column( $sum2['finance']['series'], 'source' ), true ), 'course entry kept in history' );
	eq( $sum2['finance']['change_vs_prev'], 100000 );
	return 'one store, two entry points; course row stays in history';
} );

t( 'finance', 'Compare two dates: totals and per-line changes', function () use ( $a ) {
	$all = WWP_Finance::all( $a );
	$c   = WWP_Finance::compare( $a, $all[0]['id'], end( $all )['id'] );
	eq( $c['delta']['income'], 300000 );
	eq( $c['delta']['net_worth'], ( 1250000 + 12000000 - 750000 - 9800000 ) - 1500000 );
	$card = array_values( array_filter( $c['lines']['liabilities'], static fn( $l ) => 'Credit card' === $l['label'] ) )[0];
	eq( $card['delta'], -450000 );
	eq( $c['days'], 39 );
	ok( null === WWP_Finance::compare( $a, $all[0]['id'], 999999 ), 'missing id' );
	return 'net worth +R ' . ( $c['delta']['net_worth'] / 100 ) . ' over ' . $c['days'] . ' days';
} );

t( 'finance', 'Stale per-user write lock is cleared; a live one returns 409', function () use ( $a ) {
	add_option( 'wwp_lock_' . $a, time() - 60, '', false );
	ok( ! is_wp_error( WWP_Finance::save( $a, sample() ) ), 'stale lock blocked save' );
	add_option( 'wwp_lock_' . $a, time(), '', false );
	$r = WWP_Finance::save( $a, sample() );
	delete_option( 'wwp_lock_' . $a );
	ok( is_wp_error( $r ) && 'wwp_busy' === $r->get_error_code(), 'live lock' );
	ok( WWP_Finance::verify_chain( $a )['ok'], 'chain intact' );
} );

/* ---------------- access ---------------- */
$lms = wwp_lms();
t( 'access', '10 weeks from the agreed start date, not from the purchase date', function () use ( $b ) {
	time_travel( '2026-11-20' ); // bought today…
	update_user_meta( $b, 'wwp_pending_start_money-reset', 77 );
	$id = WWP_Access::grant( $b, 'money-reset', '2027-01-11', null, 'order:77' ); // …starts in January
	ok( $id > 0, 'window' );
	$st = WWP_Access::state( $b, 'money-reset' );
	eq( $st['status'], 'upcoming' );
	eq( $st['ends_on'], '2027-03-22' );
	eq( $st['last_day'], '2027-03-21' );
	ok( ! get_user_meta( $b, 'wwp_pending_start_money-reset', true ), 'pending flag cleared' );
	return 'bought 20 Nov, access 11 Jan → 21 Mar inclusive (70 days)';
} );

t( 'access', 'State on the exact boundary days', function () use ( $b ) {
	$at = static fn( $d ) => WWP_Access::state( $b, 'money-reset', $d );
	eq( $at( '2027-01-10' )['status'], 'upcoming' );
	eq( $at( '2027-01-11' )['status'], 'active' );
	eq( $at( '2027-03-21' )['status'], 'active' );
	eq( $at( '2027-03-21' )['days_left'], 1 );
	eq( $at( '2027-03-22' )['status'], 'expired' );
	eq( WWP_Access::state( 99999, 'money-reset' )['status'], 'none' );
} );

t( 'access', 'Leap year and month ends are plain date arithmetic', function () {
	eq( WWP_Access::add_days( '2028-02-21', 70 ), '2028-05-01' );
	eq( WWP_Access::add_days( '2027-12-27', 7 ), '2028-01-03' );
	eq( WWP_Access::add_days( '2026-10-26', 7 * 10 ), '2027-01-04' );
} );

t( 'access', 'Programme start grants course access; expiry removes access only', function () use ( $b, $lms ) {
	time_travel( '2027-01-11' );
	WWP_Access::sync( $b );
	ok( $lms->has_access( $b, 101 ) && $lms->has_access( $b, 102 ), 'granted on start day' );
	time_travel( '2027-02-16' );
	foreach ( range( 0, 7 ) as $i ) {
		WWP_LMS_Demo::complete( $b, 101, $i );
	}
	WWP_LMS_Demo::submit( $b, 101, 'Week 3 worksheet', 'approved', 'Good' );
	WWP_Finance::save( $b, sample(), 'course:money-reset/week-1', '2027-01-12' );
	WWP_Finance::save( $b, sample( 30000, 9000, 9000 ), 'course:money-reset/week-6', '2027-02-16' );
	$before = array( WWP_Finance::count( $b ), $lms->progress( $b, 101 ), $lms->assignments( $b, 101 ), get_userdata( $b )->roles );
	time_travel( '2027-03-22' );
	$changes = WWP_Access::sweep();
	ok( ! $lms->has_access( $b, 101 ) && ! $lms->has_access( $b, 102 ), 'revoked' );
	$after = array( WWP_Finance::count( $b ), $lms->progress( $b, 101 ), $lms->assignments( $b, 101 ), get_userdata( $b )->roles );
	eq( $after, $before, 'data changed on expiry' );
	ok( ! is_wp_error( wp_authenticate( 'bongani', 'pw-bongani-123' ) ), 'cannot log in after expiry' );
	return "sweep: {$changes['changes']} access changes; 2 snapshots, 8/12 lessons, 1 reviewed worksheet, role all unchanged";
} );

t( 'access', 'Monthly subscription after expiry restores access with progress intact', function () use ( $b, $lms ) {
	time_travel( '2027-04-05' ); // two weeks after expiry
	WWP_Access::extend_to( $b, 'money-reset', '2027-05-08', 'subscription:501' ); // next payment + grace
	$st = WWP_Access::state( $b, 'money-reset' );
	eq( $st['status'], 'active' );
	eq( $st['starts_on'], '2027-04-05' );
	ok( $lms->has_access( $b, 101 ), 'restored immediately, not at next cron' );
	eq( $lms->progress( $b, 101 )['completed'], 8 );
	return 'expired 22 Mar → renewed 5 Apr → lesson 9 of 12 is next';
} );

t( 'access', 'Renewal while active extends from the current end: no gap, no double days', function () use ( $b ) {
	time_travel( '2027-05-01' );
	WWP_Access::extend_to( $b, 'money-reset', '2027-06-08', 'subscription:501' );
	$st = WWP_Access::state( $b, 'money-reset' );
	eq( $st['starts_on'], '2027-04-05' );
	eq( $st['ends_on'], '2027-06-08' );
	eq( WWP_Access::extend_to( $b, 'money-reset', '2027-06-01', 'subscription:501' ), 0, 'already covered' );
} );

t( 'access', 'Cancelled subscription keeps access to the paid-through date, then locks', function () use ( $b, $lms ) {
	time_travel( '2027-06-07' );
	WWP_Access::sweep();
	ok( $lms->has_access( $b, 101 ), 'still paid' );
	time_travel( '2027-06-08' );
	WWP_Access::sweep();
	ok( ! $lms->has_access( $b, 101 ), 'locked' );
	eq( WWP_Finance::count( $b ), 2 );
} );

t( 'access', 'Sync is idempotent and rejects bad input', function () use ( $b ) {
	eq( WWP_Access::sync( $b ), array() );
	ok( is_wp_error( WWP_Access::grant( $b, 'nope', '2027-01-01' ) ), 'programme' );
	ok( is_wp_error( WWP_Access::grant( $b, 'money-reset', '2027-02-30' ) ), 'date' );
	time_travel( '2026-11-09' );
	WWP_Access::sync( $b );
} );

/* ---------------- REST ---------------- */
t( 'rest', 'Logged-out requests get 401 on every /me route', function () {
	wp_set_current_user( 0 );
	foreach ( array( '/wwp/v1/me/summary', '/wwp/v1/me/snapshots', '/wwp/v1/me/resources' ) as $r ) {
		eq( rest( 'GET', $r )->get_status(), 401, $r );
	}
	eq( rest( 'POST', '/wwp/v1/me/snapshots', array(), sample() )->get_status(), 401 );
} );

t( 'rest', '/me routes ignore any user id in the query or body', function () use ( $a, $b ) {
	wp_set_current_user( $b );
	$rows = rest( 'GET', '/wwp/v1/me/snapshots', array( 'user_id' => $a, 'id' => $a ) )->get_data();
	eq( count( $rows ), WWP_Finance::count( $b ) );
	$before = WWP_Finance::count( $a );
	$res    = rest( 'POST', '/wwp/v1/me/snapshots', array(), sample() + array( 'user_id' => $a, 'source' => 'api' ) );
	eq( $res->get_status(), 201 );
	eq( WWP_Finance::count( $a ), $before, "wrote into Alice's account" );
	$cmp = rest( 'GET', '/wwp/v1/me/snapshots/compare', array( 'from' => WWP_Finance::all( $a )[0]['id'], 'to' => WWP_Finance::all( $a )[1]['id'] ) );
	eq( $cmp->get_status(), 404, "compare of Alice's ids" );
	return "Bongani posting user_id=Alice wrote to Bongani; Alice's ids return 404";
} );

t( 'rest', 'Coach sees assigned clients only, and every read is audited', function () use ( $a, $b, $coach ) {
	wp_set_current_user( $coach );
	$n0  = count( WWP_Audit::for_subject( $a ) );
	$own = rest( 'GET', "/wwp/v1/coach/clients/$a/snapshots" );
	eq( $own->get_status(), 200 );
	eq( count( WWP_Audit::for_subject( $a ) ), $n0 + 1 );
	eq( WWP_Audit::for_subject( $a, 1 )[0]['action'], 'coach_viewed_finances' );
	$other = rest( 'GET', "/wwp/v1/coach/clients/$b/snapshots" );
	eq( $other->get_status(), 403 );
	eq( WWP_Audit::for_subject( $b, 1 )[0]['action'], 'coach_denied' );
	wp_set_current_user( $a );
	eq( rest( 'GET', '/wwp/v1/coach/clients' )->get_status(), 403, 'client on coach list' );
	eq( rest( 'GET', "/wwp/v1/coach/clients/$b/snapshots" )->get_status(), 403, 'client on coach route' );
} );

/* ---------------- resources ---------------- */
t( 'resources', 'Assigned without a course; visible only to that client', function () use ( $a, $b, $coach, $admin ) {
	$dir = WWP_Schema::private_dir();
	file_put_contents( "$dir/plan.pdf", '%PDF-1.4 test' );
	$r = wp_insert_post( array( 'post_type' => 'wwp_resource', 'post_status' => 'publish', 'post_title' => 'Debt plan' ) );
	update_post_meta( $r, '_wwp_file', 'plan.pdf' );
	$draft = wp_insert_post( array( 'post_type' => 'wwp_resource', 'post_status' => 'draft', 'post_title' => 'Unfinished' ) );
	WWP_Resources::assign( $r, $a, $coach );
	WWP_Resources::assign( $draft, $a, $coach );
	eq( array_column( WWP_Resources::for_user( $a ), 'title' ), array( 'Debt plan' ), 'drafts hidden' );
	eq( WWP_Resources::for_user( $b ), array() );
	ok( WWP_Resources::can_download( $a, $r ) && ! WWP_Resources::can_download( $b, $r ) && ! WWP_Resources::can_download( 0, $r ), 'download rule' );
	ok( WWP_Resources::can_download( $coach, $r ) && WWP_Resources::can_download( $admin, $r ), 'staff' );
	WWP_Resources::set_assignees( $r, array( $b ), $coach );
	ok( ! WWP_Resources::can_download( $a, $r ) && WWP_Resources::can_download( $b, $r ), 'reassigned' );
} );

t( 'resources', 'File path cannot escape the private folder', function () {
	$r = wp_insert_post( array( 'post_type' => 'wwp_resource', 'post_status' => 'publish', 'post_title' => 'Evil' ) );
	foreach ( array( '../../../wp-config.php', '..%2F..%2Fwp-config.php', '/etc/passwd', '' ) as $p ) {
		update_post_meta( $r, '_wwp_file', $p );
		eq( WWP_Resources::file_path( $r ), null, $p );
	}
} );

/* ---------------- privacy ---------------- */
t( 'privacy', 'POPIA export returns one client\'s decrypted history; erase removes only theirs', function () use ( $a, $b, $admin ) {
	wp_set_current_user( $admin );
	$na  = WWP_Finance::count( $a );
	$nb  = WWP_Finance::count( $b );
	$exp = WWP_Privacy::export( 'alice@example.test' );
	eq( count( $exp['data'] ), $na );
	$flat = wp_json_encode( $exp['data'] );
	ok( str_contains( $flat, 'Credit card' ) && str_contains( $flat, 'Net worth' ), 'decrypted' );
	$er = WWP_Privacy::erase( 'alice@example.test' );
	ok( $er['items_removed'], 'removed' );
	eq( WWP_Finance::count( $a ), 0 );
	eq( WWP_Finance::count( $b ), $nb, "Bongani's rows" );
	eq( array_column( WWP_Audit::for_subject( $a, 2 ), 'action' ), array( 'privacy_erase', 'privacy_export' ) );
	$hooks = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	ok( isset( $hooks['wwp-finance'] ), 'registered with core Tools → Erase Personal Data' );
	return "exported $na snapshots, erased $na, Bongani's $nb untouched, both actions audited";
} );

/* ---------------- dashboard ---------------- */
t( 'dashboard', 'Expired client sees history, progress and a renew button; no one else\'s data', function () use ( $a, $b ) {
	time_travel( '2027-07-01' );
	wp_set_current_user( $b );
	$html = do_shortcode( '[wwp_dashboard]' );
	ok( str_contains( $html, 'nothing has been deleted' ) && str_contains( $html, 'Renew monthly' ), 'expired banner' );
	ok( str_contains( $html, '8 of 12 lessons' ), 'progress' );
	ok( str_contains( $html, 'Locked until you renew' ), 'locked' );
	ok( ! str_contains( $html, 'Alice' ), 'other client' );
	wp_set_current_user( 0 );
	ok( str_contains( do_shortcode( '[wwp_dashboard]' ), 'loginform' ), 'login form when logged out' );
} );

t( 'dashboard', 'Labels are escaped (stored XSS attempt renders as text)', function () use ( $b ) {
	wp_set_current_user( $b );
	$x = sample();
	$x['assets'][0]['label'] = '<img src=x onerror=alert(1)>Shares';
	$x['expenses'][0]['label'] = '"><script>alert(2)</script>';
	WWP_Finance::save( $b, $x );
	$html = do_shortcode( '[wwp_dashboard]' ) . do_shortcode( '[wwp_finance_form]' );
	ok( ! str_contains( $html, '<script>alert' ) && ! str_contains( $html, 'onerror=alert' ), 'raw markup in output' );
} );

/* ---------------- performance (measured, this machine) ---------------- */
$perf = array();
t( 'perf', '10 years of weekly snapshots for one client', function () use ( &$perf ) {
	$u = mkuser( 'heavy' );
	time_travel( '2026-11-09' );
	$t0 = hrtime( true );
	for ( $i = 0; $i < 520; $i++ ) {
		WWP_Finance::save( $u, sample( 30000 + $i, 1000 + 50 * $i, 20000 - 30 * $i ), 'dashboard', WWP_Access::add_days( '2016-11-14', 7 * $i ) );
	}
	$perf['save_ms_avg'] = round( ( hrtime( true ) - $t0 ) / 1e6 / 520, 2 );
	wp_set_current_user( $u );
	$lat = array();
	for ( $i = 0; $i < 30; $i++ ) {
		$t1 = hrtime( true );
		$r  = rest( 'GET', '/wwp/v1/me/summary' );
		$lat[] = ( hrtime( true ) - $t1 ) / 1e6;
	}
	sort( $lat );
	$perf['summary_p50_ms'] = round( $lat[14], 1 );
	$perf['summary_p95_ms'] = round( $lat[28], 1 );
	$t2 = hrtime( true );
	$v  = WWP_Finance::verify_chain( $u );
	$perf['verify_520_ms'] = round( ( hrtime( true ) - $t2 ) / 1e6, 1 );
	ok( $v['ok'] && 520 === $v['rows'], 'chain' );
	eq( count( $r->get_data()['finance']['series'] ), 520 );
	return "save avg {$perf['save_ms_avg']} ms · /me/summary p50 {$perf['summary_p50_ms']} ms, p95 {$perf['summary_p95_ms']} ms · verify 520 rows {$perf['verify_520_ms']} ms";
} );

t( 'perf', 'Nightly expiry sweep across 300 clients', function () use ( &$perf, $lms ) {
	time_travel( '2026-09-01' );
	$ids = array();
	for ( $i = 0; $i < 300; $i++ ) {
		$ids[] = $u = mkuser( "bulk$i" );
		WWP_Access::grant( $u, 'money-reset', WWP_Access::add_days( '2026-06-01', $i % 120 ) );
	}
	time_travel( '2026-11-09' );
	$t0 = hrtime( true );
	$s  = WWP_Access::sweep();
	$perf['sweep_300_ms'] = round( ( hrtime( true ) - $t0 ) / 1e6, 1 );
	$expired = count( array_filter( $ids, static fn( $u ) => 'expired' === WWP_Access::state( $u, 'money-reset' )['status'] ) );
	$locked  = count( array_filter( $ids, static fn( $u ) => ! $lms->has_access( $u, 101 ) ) );
	eq( $locked, $expired, 'lms matches windows' );
	$perf['sweep_changes'] = $s['changes'];
	return "{$s['users']} users, {$s['changes']} access changes in {$perf['sweep_300_ms']} ms; $expired expired = $locked locked";
} );

$pass = count( array_filter( $results, static fn( $r ) => $r['ok'] ) );
$out  = array(
	'ran_at'    => gmdate( 'c' ),
	'php'       => PHP_VERSION,
	'wordpress' => get_bloginfo( 'version' ),
	'database'  => 'SQLite via sqlite-database-integration ' . ( defined( 'SQLITE_DRIVER_VERSION' ) ? SQLITE_DRIVER_VERSION : '' ),
	'lms'       => wwp_lms()->name(),
	'passed'    => $pass,
	'total'     => count( $results ),
	'checks'    => array_sum( array_column( $results, 'checks' ) ),
	'perf'      => $perf,
	'results'   => $results,
);
file_put_contents( $argv[1] ?? "$data/test-results.json", json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
echo "\n$pass/" . count( $results ) . ' tests passed, ' . $out['checks'] . " assertions\n";
exit( $pass === count( $results ) ? 0 : 1 );
