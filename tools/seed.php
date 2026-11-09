<?php
/**
 * Demo world for screenshots. Fictional people, fictional numbers.
 */
$_SERVER['HTTP_HOST'] = '127.0.0.1:8090'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/../wp/wp-load.php';

function page( string $slug, string $title, string $content, int $parent = 0, array $meta = array() ): int {
	$existing = get_page_by_path( $parent ? get_post( $parent )->post_name . '/' . $slug : $slug );
	$id = $existing ? $existing->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_parent' => $parent ) );
	foreach ( $meta + array( '_wwp_app' => 1 ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
	return $id;
}
function person( string $login, string $first, string $last, string $role ): int {
	$u = get_user_by( 'login', $login );
	if ( $u ) { return $u->ID; }
	return wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'demo-pass-123', 'user_email' => "$login@example.test", 'first_name' => $first, 'last_name' => $last, 'display_name' => "$first $last", 'role' => $role ) );
}

// Course (demo adapter) and programme.
$lessons = array();
foreach ( array( 'Where your money goes', 'Your net worth', 'Debt: the order to pay it off', 'Emergency fund', 'Investing basics (TFSA, RA)', 'Your 12-month plan' ) as $w => $t ) {
	$lessons[] = 'Week ' . ( $w + 1 ) . ': ' . $t;
	$lessons[] = 'Week ' . ( $w + 1 ) . ' worksheet';
}
$parent = page( 'money-reset', 'Money Reset', '' );
$week2  = page( 'week-2', 'Week 2: Your net worth', '<div class="wwp-lesson"><h2>Lesson video · 14 min</h2><div class="wwp-video">▶ Lesson video (LearnDash lesson in production)</div><p>List everything you own and everything you owe. Use today\'s balances, not what you hope they will be. Whatever you enter here appears on your dashboard and stays there after the course.</p></div>[wwp_finance_form source="course:money-reset/week-2" title="Week 2 worksheet: your net worth"]', $parent, array( '_wwp_show_title' => 1, '_wwp_eyebrow' => 'Money Reset · Lesson 4 of 12' ) );
$urls = array_fill( 0, 12, get_permalink( $week2 ) );
update_option( 'wwp_demo_courses', array( 101 => array( 'title' => 'Money Reset: 6-week programme', 'lessons' => $lessons, 'urls' => $urls ) ) );
update_option( 'wwp_programmes', array( 'money-reset' => array( 'label' => 'Money Reset', 'courses' => array( 101 ), 'weeks' => 10, 'renew_url' => home_url( '/continue-monthly/' ) ) ) );

page( 'portal', 'Dashboard', '[wwp_dashboard]' );
page( 'my-numbers', 'My numbers', '[wwp_finance_form source="dashboard"]', 0, array( '_wwp_show_title' => 1, '_wwp_eyebrow' => 'Finance tracker' ) );
page( 'coach', 'Coach', '[wwp_coach]', 0, array( '_wwp_show_title' => 1, '_wwp_eyebrow' => 'Coach view' ) );
page( 'continue-monthly', 'Continue monthly', '<div class="wwp"><div class="wwp-card"><p>In production this is the WooCommerce subscription product (Payfast for rand, PayPal for international). Paying restores course access on the same request via the subscription hook.</p></div></div>', 0, array( '_wwp_show_title' => 1, '_wwp_eyebrow' => 'Renewal' ) );
update_option( 'wwp_form_page_url', home_url( '/my-numbers/' ) );

$coach  = person( 'lerato', 'Lerato', 'Mokoena', 'wwp_coach' );
$coach2 = person( 'johan', 'Johan', 'Botha', 'wwp_coach' );
$thandi = person( 'thandi', 'Thandi', 'Nkosi', 'subscriber' );
$pieter = person( 'pieter', 'Pieter', 'van Wyk', 'subscriber' );
$aisha  = person( 'aisha', 'Aisha', 'Patel', 'subscriber' );
$sipho  = person( 'sipho', 'Sipho', 'Dlamini', 'subscriber' );
$megan  = person( 'megan', 'Megan', 'Smith', 'subscriber' );
foreach ( array( $thandi, $pieter, $aisha, $sipho ) as $u ) { update_user_meta( $u, 'wwp_coach_id', $coach ); }
update_user_meta( $megan, 'wwp_coach_id', $coach2 );
wp_set_current_user( 1 );

global $wpdb;
if ( ! WWP_Finance::count( $thandi ) ) {
	WWP_Access::grant( $thandi, 'money-reset', '2026-09-28', null, 'order:1042' );
	WWP_Access::grant( $pieter, 'money-reset', '2026-06-01', null, 'order:987' );
	WWP_Access::grant( $sipho, 'money-reset', '2027-01-11', null, 'order:1077' );
	WWP_Access::grant( $megan, 'money-reset', '2026-10-12', null, 'order:1051' );
	update_user_meta( $aisha, 'wwp_pending_start_money-reset', 1081 );

	$snap = function ( $u, $date, $src, $inc, $exp, $ass, $lia ) {
		$rows = static fn( $a ) => array_map( static fn( $k, $v ) => array( 'label' => $k, 'cents' => $v * 100 ), array_keys( $a ), $a );
		$r = WWP_Finance::save( $u, array( 'income' => $rows( $inc ), 'expenses' => $rows( $exp ), 'assets' => $rows( $ass ), 'liabilities' => $rows( $lia ) ), $src, $date, $u );
		if ( is_wp_error( $r ) ) { echo $r->get_error_message(), "\n"; }
	};
	$inc = array( 'Salary (after tax)' => 38500, 'Weekend baking orders' => 3200 );
	$snap( $thandi, '2026-09-28', 'course:money-reset/week-1', $inc, array( 'Rent' => 12500, 'Groceries' => 5600, 'Transport' => 3900, 'Debt repayments' => 4100, 'Insurance' => 1650, 'Eating out & takeaways' => 4200, 'Subscriptions' => 890, 'Other' => 6400 ), array( 'Savings account' => 6200, 'Retirement annuity' => 141000, 'Car (resale value)' => 165000 ), array( 'Credit card' => 26400, 'Car finance' => 124800, 'Store accounts' => 7900, 'Personal loan' => 18500 ) );
	$snap( $thandi, '2026-10-05', 'course:money-reset/week-2', $inc, array( 'Rent' => 12500, 'Groceries' => 5100, 'Transport' => 3800, 'Debt repayments' => 5600, 'Insurance' => 1650, 'Eating out & takeaways' => 2600, 'Subscriptions' => 640, 'Other' => 5200 ), array( 'Savings account' => 9800, 'Retirement annuity' => 142600, 'Car (resale value)' => 164000 ), array( 'Credit card' => 24100, 'Car finance' => 123400, 'Store accounts' => 6100, 'Personal loan' => 17600 ) );
	$snap( $thandi, '2026-10-19', 'course:money-reset/week-4', $inc, array( 'Rent' => 12500, 'Groceries' => 4900, 'Transport' => 3700, 'Debt repayments' => 6800, 'Insurance' => 1650, 'Eating out & takeaways' => 1900, 'Subscriptions' => 640, 'Other' => 4600 ), array( 'Savings account' => 14900, 'Retirement annuity' => 145100, 'Car (resale value)' => 162500 ), array( 'Credit card' => 19800, 'Car finance' => 121900, 'Store accounts' => 2700, 'Personal loan' => 16700 ) );
	$snap( $thandi, '2026-11-02', 'course:money-reset/week-6', $inc + array( 'Tax refund (once-off)' => 0 ), array( 'Rent' => 12500, 'Groceries' => 4800, 'Transport' => 3700, 'Debt repayments' => 7200, 'Insurance' => 1650, 'Eating out & takeaways' => 1700, 'Subscriptions' => 640, 'Other' => 4300 ), array( 'Savings account' => 19600, 'Retirement annuity' => 147300, 'Car (resale value)' => 161000, 'TFSA' => 2500 ), array( 'Credit card' => 15200, 'Car finance' => 120300, 'Store accounts' => 0, 'Personal loan' => 15800 ) );
	$snap( $thandi, '2026-11-09', 'dashboard', $inc, array( 'Rent' => 12500, 'Groceries' => 4750, 'Transport' => 3650, 'Debt repayments' => 7200, 'Insurance' => 1650, 'Eating out & takeaways' => 1500, 'Subscriptions' => 640, 'Other' => 4200 ), array( 'Savings account' => 22400, 'Retirement annuity' => 148100, 'Car (resale value)' => 160500, 'TFSA' => 5000 ), array( 'Credit card' => 12900, 'Car finance' => 119500, 'Store accounts' => 0, 'Personal loan' => 15000 ) );

	$p_inc = array( 'Salary (after tax)' => 46200 );
	$snap( $pieter, '2026-06-01', 'course:money-reset/week-1', $p_inc, array( 'Bond' => 14800, 'Groceries' => 7200, 'School fees' => 6900, 'Transport' => 4500, 'Debt repayments' => 5200, 'Insurance' => 3100, 'Other' => 6800 ), array( 'House (market value)' => 1450000, 'Savings' => 12000, 'Pension fund' => 386000 ), array( 'Home loan' => 1180000, 'Credit card' => 38000, 'Vehicle finance' => 96000 ) );
	$snap( $pieter, '2026-07-06', 'course:money-reset/week-4', $p_inc, array( 'Bond' => 14800, 'Groceries' => 6600, 'School fees' => 6900, 'Transport' => 4300, 'Debt repayments' => 6400, 'Insurance' => 3100, 'Other' => 4900 ), array( 'House (market value)' => 1450000, 'Savings' => 17500, 'Pension fund' => 391000 ), array( 'Home loan' => 1174000, 'Credit card' => 31200, 'Vehicle finance' => 93100 ) );
	$snap( $pieter, '2026-08-03', 'course:money-reset/week-6', $p_inc, array( 'Bond' => 14800, 'Groceries' => 6500, 'School fees' => 6900, 'Transport' => 4300, 'Debt repayments' => 6400, 'Insurance' => 3100, 'Other' => 4600 ), array( 'House (market value)' => 1450000, 'Savings' => 23800, 'Pension fund' => 396500 ), array( 'Home loan' => 1168000, 'Credit card' => 24600, 'Vehicle finance' => 90200 ) );
	$snap( $megan, '2026-10-12', 'course:money-reset/week-1', array( 'Salary' => 29000 ), array( 'Rent' => 9800, 'Other' => 14000 ), array( 'Savings' => 4000 ), array( 'Student loan' => 52000 ) );

	foreach ( range( 0, 8 ) as $i ) { WWP_LMS_Demo::complete( $thandi, 101, $i ); }
	foreach ( range( 0, 9 ) as $i ) { WWP_LMS_Demo::complete( $pieter, 101, $i ); }
	WWP_LMS_Demo::submit( $thandi, 101, 'Week 1 worksheet', 'approved', '' );
	WWP_LMS_Demo::submit( $thandi, 101, 'Week 2 worksheet', 'approved', '' );
	WWP_LMS_Demo::submit( $thandi, 101, 'Week 3 worksheet', 'approved', 'Great call closing the store accounts first. Next: point that R1 400 at the credit card, not the car.' );
	WWP_LMS_Demo::submit( $thandi, 101, 'Week 4 worksheet', 'pending', '' );
	WWP_LMS_Demo::submit( $pieter, 101, 'Week 5 worksheet', 'approved', 'Your RA contribution covers the tax angle. Keep the school-fee sinking fund.' );

	// Resources with private files.
	$dir = WWP_Schema::private_dir();
	$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 595 842]/Parent 2 0 R>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
	file_put_contents( "$dir/debt-snowball-planner.pdf", $pdf );
	file_put_contents( "$dir/emergency-fund-calculator.xlsx", "PK demo bytes" );
	file_put_contents( "$dir/school-fees-sinking-fund.pdf", $pdf );
	$mk = function ( $title, $body, $file = '', $url = '' ) {
		$id = wp_insert_post( array( 'post_type' => 'wwp_resource', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $body ) );
		update_post_meta( $id, '_wwp_file', $file ); update_post_meta( $id, '_wwp_url', $url );
		return $id;
	};
	$r1 = $mk( 'Debt snowball planner', 'Lists your debts smallest to largest and shows the payoff month for each.', 'debt-snowball-planner.pdf' );
	$r2 = $mk( 'Emergency fund calculator', 'Three, six or twelve months of essential expenses, with your own numbers.', 'emergency-fund-calculator.xlsx' );
	$r3 = $mk( 'Session 3 recording: your questions', 'Recording of the live Q&A.', '', 'https://example.com/recording' );
	$r4 = $mk( 'School fees sinking fund', 'Spread annual fees across twelve months.', 'school-fees-sinking-fund.pdf' );
	WWP_Resources::assign( $r1, $thandi, $coach ); WWP_Resources::assign( $r2, $thandi, $coach ); WWP_Resources::assign( $r3, $thandi, $coach );
	WWP_Resources::assign( $r4, $pieter, $coach ); WWP_Resources::assign( $r1, $pieter, $coach );
	$t = WWP_Schema::t( 'assignments' );
	foreach ( array( array( $r1, $thandi, '2026-10-06 08:10:00' ), array( $r2, $thandi, '2026-10-20 09:30:00' ), array( $r3, $thandi, '2026-11-04 18:05:00' ), array( $r4, $pieter, '2026-07-07 10:00:00' ), array( $r1, $pieter, '2026-06-15 10:00:00' ) ) as $a ) {
		$wpdb->update( $t, array( 'assigned_at' => $a[2] ), array( 'resource_id' => $a[0], 'user_id' => $a[1] ) );
	}
}
echo json_encode( array( 'thandi' => $thandi, 'pieter' => $pieter, 'aisha' => $aisha, 'sipho' => $sipho, 'megan' => $megan, 'coach' => $coach, 'sweep' => WWP_Access::sweep() ) ), "\n";
foreach ( array( $thandi, $pieter, $sipho ) as $u ) { $s = WWP_Access::state( $u, 'money-reset' ); echo get_userdata( $u )->display_name, ': ', $s['status'], ' ', $s['starts_on'], '→', $s['last_day'], ' lms=', wwp_lms()->has_access( $u, 101 ) ? 'yes' : 'no', "\n"; }
