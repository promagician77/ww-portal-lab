<?php
defined( 'ABSPATH' ) || exit;

/**
 * Client dashboard, finance worksheet and coach view (shortcodes, so they drop into any
 * page, LearnDash lesson or Elementor shortcode widget).
 */
final class WWP_Dashboard {

	public static function init(): void {
		add_shortcode( 'wwp_dashboard', array( __CLASS__, 'render_dashboard' ) );
		add_shortcode( 'wwp_finance_form', array( __CLASS__, 'render_form' ) );
		add_shortcode( 'wwp_coach', array( __CLASS__, 'render_coach' ) );
		add_action( 'admin_post_wwp_save_snapshot', array( __CLASS__, 'handle_save' ) );
		add_action( 'wp_enqueue_scripts', static function () {
			wp_register_style( 'wwp-portal', WWP_URL . 'assets/portal.css', array(), WWP_VERSION );
			wp_register_script( 'wwp-portal', WWP_URL . 'assets/portal.js', array(), WWP_VERSION, true );
		} );
		// Clients live in the portal, not wp-admin: no toolbar, land on the dashboard after login.
		add_filter( 'show_admin_bar', static fn( $show ) => $show && current_user_can( 'edit_posts' ) );
		add_filter( 'login_redirect', static function ( $to, $requested, $user ) {
			return ( $user instanceof WP_User && ! user_can( $user, 'edit_posts' ) ) ? home_url( '/portal/' ) : $to;
		}, 10, 3 );
		add_action( 'admin_init', static function () {
			$script = basename( $_SERVER['SCRIPT_NAME'] ?? '' );
			if ( ! current_user_can( 'edit_posts' ) && ! wp_doing_ajax() && 'admin-post.php' !== $script ) {
				wp_safe_redirect( home_url( '/portal/' ) );
				exit;
			}
		} );
		add_filter( 'template_include', static function ( $tpl ) {
			return ( is_page() && get_post_meta( get_queried_object_id(), '_wwp_app', true ) ) ? WWP_DIR . '/templates/portal.php' : $tpl;
		} );
	}

	private static function assets(): void {
		wp_enqueue_style( 'wwp-portal' );
		wp_enqueue_script( 'wwp-portal' );
	}

	/** Everything the dashboard shows, as data (also served at GET /wwp/v1/me/summary). */
	public static function summary( int $uid ): array {
		$snaps  = WWP_Finance::all( $uid );
		$ok     = array_values( array_filter( $snaps, static fn( $s ) => $s['ok'] ) );
		$latest = $ok ? end( $ok ) : null;
		$prev   = count( $ok ) > 1 ? $ok[ count( $ok ) - 2 ] : null;
		$first  = $ok[0] ?? null;

		$programmes = array();
		$lms        = wwp_lms();
		foreach ( WWP_Access::programmes() as $key => $p ) {
			$st = WWP_Access::state( $uid, $key );
			if ( 'none' === $st['status'] && ! $st['pending'] ) {
				continue;
			}
			$courses = array();
			foreach ( (array) $p['courses'] as $cid ) {
				$courses[] = $lms->progress( $uid, (int) $cid ) + array( 'id' => (int) $cid, 'assignments' => $lms->assignments( $uid, (int) $cid ) );
			}
			$programmes[] = array( 'key' => $key, 'label' => $p['label'], 'renew_url' => $p['renew_url'] ?? '', 'state' => $st, 'courses' => $courses );
		}
		$coach_id = (int) get_user_meta( $uid, 'wwp_coach_id', true );
		$coach    = $coach_id ? get_userdata( $coach_id ) : null;
		return array(
			'user'       => array( 'id' => $uid, 'name' => get_userdata( $uid )->first_name ?: get_userdata( $uid )->display_name ),
			'finance'    => array(
				'latest'         => $latest ? array( 'id' => $latest['id'], 'taken_on' => $latest['taken_on'], 'source' => $latest['source'], 'totals' => $latest['totals'] ) : null,
				'change_vs_prev' => ( $latest && $prev ) ? $latest['totals']['net_worth'] - $prev['totals']['net_worth'] : null,
				'prev_date'      => $prev['taken_on'] ?? null,
				'change_vs_first' => ( $latest && $first && $first !== $latest ) ? $latest['totals']['net_worth'] - $first['totals']['net_worth'] : null,
				'first_date'     => $first['taken_on'] ?? null,
				'series'         => array_map( static fn( $s ) => array( 'id' => $s['id'], 'date' => $s['taken_on'], 'net_worth' => $s['totals']['net_worth'], 'surplus' => $s['totals']['monthly_surplus'], 'source' => $s['source'] ), $ok ),
				'unreadable'     => count( $snaps ) - count( $ok ),
			),
			'programmes' => $programmes,
			'coach'      => $coach ? array( 'name' => $coach->display_name ) : null,
			'resources'  => WWP_Resources::for_user( $uid ),
		);
	}

	public static function coach_clients( int $coach_id ): array {
		$ids = get_users( array( 'meta_key' => 'wwp_coach_id', 'meta_value' => $coach_id, 'fields' => 'ID' ) );
		$out = array();
		foreach ( $ids as $id ) {
			$s     = self::summary( (int) $id );
			$out[] = array(
				'id'        => (int) $id,
				'name'      => get_userdata( $id )->display_name,
				'status'    => ( $s['programmes'][0]['state']['status'] ?? 'none' ) === 'none' ? 'awaiting start' : $s['programmes'][0]['state']['status'],
				'last_day'  => $s['programmes'][0]['state']['last_day'] ?? null,
				'progress'  => $s['programmes'][0]['courses'][0]['percent'] ?? 0,
				'pending'   => $s['programmes'][0]['courses'][0]['assignments']['pending'] ?? 0,
				'last_snap' => $s['finance']['latest']['taken_on'] ?? null,
				'nw_change' => $s['finance']['change_vs_first'],
			);
		}
		return $out;
	}

	/* ---------- helpers ---------- */

	private static function d( ?string $ymd ): string {
		return $ymd ? wp_date( 'j M Y', strtotime( $ymd . ' 12:00:00' ) ) : '—';
	}
	private static function m( ?int $c, bool $signed = false ): string {
		return null === $c ? '—' : esc_html( WWP_Money::format( $c, false, $signed && 0 !== $c ) );
	}
	private static function source_label( string $s ): string {
		if ( str_starts_with( $s, 'course:' ) ) {
			$parts = explode( '/', substr( $s, 7 ) );
			return 'Course worksheet · ' . ucwords( str_replace( '-', ' ', end( $parts ) ) );
		}
		return 'coach' === $s ? 'Entered by coach' : 'Dashboard';
	}

	/** Server-rendered SVG line chart: no chart library, works without JS, prints well. */
	public static function chart( array $series, int $w = 560, int $h = 190 ): string {
		if ( count( $series ) < 2 ) {
			return '<p class="wwp-muted">Your trend appears after your second snapshot.</p>';
		}
		$vals = array_column( $series, 'net_worth' );
		$max  = max( $vals );
		// Zero baseline only when the line crosses zero; otherwise frame the movement (labelled axis).
		$min  = min( $vals ) <= 0 ? min( $vals ) : max( 0, min( $vals ) - ( $max - min( $vals ) ) * 0.35 );
		$span = max( 1, $max - $min );
		$pad  = 10;
		$n    = count( $series );
		$pts  = array();
		foreach ( $series as $i => $s ) {
			$x     = $pad + ( $w - 2 * $pad ) * $i / ( $n - 1 );
			$y     = $pad + ( $h - 2 * $pad - 18 ) * ( 1 - ( $s['net_worth'] - $min ) / $span );
			$pts[] = array( round( $x, 1 ), round( $y, 1 ), $s );
		}
		$line  = implode( ' ', array_map( static fn( $p ) => $p[0] . ',' . $p[1], $pts ) );
		$baseY = round( $h - $pad - 18, 1 );
		$zeroY = round( $pad + ( $h - 2 * $pad - 18 ) * ( 1 - ( 0 - $min ) / $span ), 1 );
		$floor = $min < 0 ? $zeroY : $baseY;
		$area  = $line . ' ' . end( $pts )[0] . ',' . $floor . ' ' . $pts[0][0] . ',' . $floor;
		$out   = '<svg class="wwp-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Net worth over ' . $n . ' snapshots">';
		$out  .= '<line x1="' . $pad . '" x2="' . ( $w - $pad ) . '" y1="' . $floor . '" y2="' . $floor . '" class="wwp-zero"/>';
		$out  .= '<text x="' . $pad . '" y="' . ( $pad + 2 ) . '" text-anchor="start" class="wwp-axis">' . esc_html( WWP_Money::format( $max ) ) . '</text>';
		$out  .= '<text x="' . ( $w - $pad ) . '" y="' . ( $floor - 4 ) . '" text-anchor="end" class="wwp-axis">' . esc_html( WWP_Money::format( (int) round( $min, -2 ) ) ) . '</text>';
		$out  .= '<polygon points="' . $area . '" class="wwp-area"/><polyline points="' . $line . '" class="wwp-line"/>';
		foreach ( $pts as $i => $p ) {
			$out .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="' . ( $i === $n - 1 ? 5 : 3.5 ) . '" class="wwp-dot' . ( $i === $n - 1 ? ' is-last' : '' ) . '"><title>' . esc_html( self::d( $p[2]['date'] ) . ': ' . WWP_Money::format( $p[2]['net_worth'] ) ) . '</title></circle>';
			if ( 0 === $i || $i === $n - 1 ) {
				$out .= '<text x="' . $p[0] . '" y="' . ( $h - 4 ) . '" text-anchor="' . ( 0 === $i ? 'start' : 'end' ) . '" class="wwp-axis">' . esc_html( wp_date( 'M Y', strtotime( $p[2]['date'] ) ) ) . '</text>';
			}
		}
		return $out . '</svg>';
	}

	/** Where this month's income goes: one stacked bar, biggest five lines plus the rest. */
	private static function spend_mix( int $uid, int $snap_id ): string {
		$s = WWP_Finance::get( $uid, $snap_id );
		if ( ! $s || ! $s['ok'] || $s['totals']['income'] <= 0 ) {
			return '';
		}
		$inc  = $s['totals']['income'];
		$rows = $s['data']['expenses'];
		usort( $rows, static fn( $a, $b ) => $b['cents'] <=> $a['cents'] );
		$top  = array_slice( $rows, 0, 5 );
		$rest = array_sum( array_column( array_slice( $rows, 5 ), 'cents' ) );
		if ( $rest > 0 ) {
			$top[] = array( 'label' => 'Everything else', 'cents' => $rest );
		}
		if ( $s['totals']['monthly_surplus'] > 0 ) {
			$top[] = array( 'label' => 'Left over', 'cents' => $s['totals']['monthly_surplus'], 'surplus' => true );
		}
		$out = '<div class="wwp-mix"><p class="wwp-label">Where this month\'s income goes</p><div class="wwp-mixbar">';
		$leg = '<ul class="wwp-mixleg">';
		foreach ( $top as $i => $r ) {
			$pct  = max( 0.5, 100 * $r['cents'] / max( $inc, $s['totals']['expenses'] ) );
			$cls  = ! empty( $r['surplus'] ) ? ' is-surplus' : ' c' . $i;
			$out .= '<span class="' . trim( $cls ) . '" style="width:' . round( $pct, 2 ) . '%" title="' . esc_attr( $r['label'] ) . '"></span>';
			$leg .= '<li><i class="' . trim( $cls ) . '"></i>' . esc_html( $r['label'] ) . ' <b>' . (int) round( 100 * $r['cents'] / $inc ) . '%</b></li>';
		}
		return $out . '</div>' . $leg . '</ul></div>';
	}

	/* ---------- dashboard ---------- */

	public static function render_dashboard(): string {
		self::assets();
		if ( ! is_user_logged_in() ) {
			return '<div class="wwp"><div class="wwp-card wwp-login"><h2>Sign in to your portal</h2>' . wp_login_form( array( 'echo' => false, 'redirect' => get_permalink() ) ) . '</div></div>';
		}
		$uid = get_current_user_id();
		$s   = self::summary( $uid );
		$f   = $s['finance'];
		$p   = $s['programmes'][0] ?? null;
		$st  = $p['state'] ?? array( 'status' => 'none', 'pending' => false );
		$c   = $p['courses'][0] ?? null;
		$form_url = get_option( 'wwp_form_page_url', '#' );

		ob_start();
		echo '<div class="wwp">';

		// Status strip.
		echo '<section class="wwp-hello"><div><p class="wwp-eyebrow">' . esc_html( wp_date( 'l j F Y' ) ) . '</p><h1>Hi ' . esc_html( $s['user']['name'] ) . '</h1></div>';
		if ( $p ) {
			$chip = array(
				'active'   => array( 'ok', esc_html( $p['label'] ) . ' · access until ' . self::d( $st['last_day'] ) ),
				'upcoming' => array( 'info', esc_html( $p['label'] ) . ' · starts ' . self::d( $st['starts_on'] ) ),
				'expired'  => array( 'warn', esc_html( $p['label'] ) . ' · access ended ' . self::d( $st['last_day'] ) ),
				'none'     => array( 'info', 'Paid · your coach will confirm your start date' ),
			)[ $st['status'] ];
			echo '<span class="wwp-chip is-' . $chip[0] . '">' . $chip[1] . '</span>';
		}
		echo '</section>';

		if ( 'expired' === $st['status'] ) {
			echo '<div class="wwp-banner"><div><strong>Your course access has ended, and nothing has been deleted.</strong> Your finance history, course progress and coach feedback are saved here. Renew to pick up exactly where you left off.</div>';
			if ( ! empty( $p['renew_url'] ) ) {
				echo '<a class="wwp-btn" href="' . esc_url( $p['renew_url'] ) . '">Renew monthly</a>';
			}
			echo '</div>';
		}

		echo '<nav class="wwp-tabs" aria-label="Portal sections"><a href="#finances">Finances</a><a href="#learning">Learning</a><a href="#coaching">Coaching</a><a href="#resources">Resources</a><a href="#products">Products</a></nav>';

		echo '<div class="wwp-grid">';

		// Finances.
		echo '<section id="finances" class="wwp-card wwp-fin"><header><h2>Finances</h2>';
		if ( $f['latest'] ) {
			echo '<span class="wwp-muted">Updated ' . self::d( $f['latest']['taken_on'] ) . ' · ' . esc_html( self::source_label( $f['latest']['source'] ) ) . '</span>';
		}
		echo '</header>';
		if ( ! $f['latest'] ) {
			echo '<p>No snapshot yet. Your Week 1 worksheet creates the first one, or start here.</p><a class="wwp-btn" href="' . esc_url( $form_url ) . '">Add my first snapshot</a>';
		} else {
			$t = $f['latest']['totals'];
			echo '<div class="wwp-hero"><div><p class="wwp-label">Net worth</p><p class="wwp-big">' . self::m( $t['net_worth'] ) . '</p>';
			if ( null !== $f['change_vs_prev'] ) {
				$cls = $f['change_vs_prev'] >= 0 ? 'up' : 'down';
				echo '<p class="wwp-delta is-' . $cls . '">' . self::m( $f['change_vs_prev'], true ) . ' since ' . self::d( $f['prev_date'] ) . '</p>';
			}
			if ( null !== $f['change_vs_first'] ) {
				echo '<p class="wwp-muted">' . self::m( $f['change_vs_first'], true ) . ' since your first snapshot (' . self::d( $f['first_date'] ) . ')</p>';
			}
			echo '</div>' . self::chart( $f['series'] ) . '</div>';
			$rate = null === $t['savings_rate_bp'] ? '—' : number_format( $t['savings_rate_bp'] / 100, 1 ) . '%';
			echo '<dl class="wwp-stats">';
			echo '<div><dt>Monthly surplus</dt><dd class="' . ( $t['monthly_surplus'] < 0 ? 'is-neg' : '' ) . '">' . self::m( $t['monthly_surplus'] ) . '</dd></div>';
			echo '<div><dt>Savings rate</dt><dd>' . esc_html( $rate ) . '</dd></div>';
			echo '<div><dt>Assets</dt><dd>' . self::m( $t['assets'] ) . '</dd></div>';
			echo '<div><dt>Liabilities</dt><dd>' . self::m( $t['liabilities'] ) . '</dd></div>';
			echo '</dl>';
			echo self::spend_mix( $uid, $f['latest']['id'] );
			echo '<div class="wwp-actions"><a class="wwp-btn" href="' . esc_url( $form_url ) . '">Update my numbers</a><a class="wwp-link" href="#history">Compare snapshots</a></div>';
		}
		echo '</section>';

		// Learning.
		echo '<section id="learning" class="wwp-card"><header><h2>Learning</h2></header>';
		if ( $c ) {
			$locked = 'active' !== $st['status'];
			echo '<p class="wwp-course">' . esc_html( $c['title'] ) . '</p>';
			echo '<div class="wwp-bar" role="progressbar" aria-valuenow="' . (int) $c['percent'] . '" aria-valuemin="0" aria-valuemax="100"><span style="width:' . (int) $c['percent'] . '%"></span></div>';
			echo '<p class="wwp-muted">' . (int) $c['completed'] . ' of ' . (int) $c['total'] . ' lessons complete · ' . (int) $c['percent'] . '%</p>';
			if ( $locked ) {
				echo '<p class="wwp-lock">Locked until you renew. Your progress is kept.</p>';
			} else {
				echo '<a class="wwp-next" href="' . esc_url( $c['next_url'] ) . '"><span>Up next</span>' . esc_html( $c['next_title'] ) . '</a>';
			}
		} else {
			echo '<p class="wwp-muted">Your course appears here once your programme starts.</p>';
		}
		echo '</section>';

		// Coaching.
		echo '<section id="coaching" class="wwp-card"><header><h2>Coaching</h2></header>';
		echo '<p>' . ( $s['coach'] ? 'Your coach: <strong>' . esc_html( $s['coach']['name'] ) . '</strong>' : 'A coach will be assigned when you start.' ) . '</p>';
		if ( $c ) {
			$a = $c['assignments'];
			echo '<ul class="wwp-list"><li><span>Worksheets awaiting review</span><b>' . (int) $a['pending'] . '</b></li><li><span>Reviewed</span><b>' . (int) $a['approved'] . '</b></li></ul>';
			if ( $a['last_feedback'] ) {
				echo '<blockquote class="wwp-quote">' . esc_html( $a['last_feedback'] ) . '</blockquote>';
			}
		}
		echo '</section>';

		// Resources.
		echo '<section id="resources" class="wwp-card"><header><h2>Resources</h2><span class="wwp-muted">Shared with you by your coach</span></header>';
		if ( ! $s['resources'] ) {
			echo '<p class="wwp-muted">Nothing assigned yet.</p>';
		} else {
			echo '<ul class="wwp-res">';
			foreach ( array_slice( $s['resources'], 0, 5 ) as $r ) {
				echo '<li><span class="wwp-kind">' . esc_html( $r['kind'] ) . '</span><a href="' . esc_url( $r['url'] ) . '">' . esc_html( $r['title'] ) . '</a><span class="wwp-muted">' . self::d( substr( $r['assigned_at'], 0, 10 ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '</section>';

		// Products.
		echo '<section id="products" class="wwp-card"><header><h2>Products</h2></header>';
		if ( $p ) {
			$label = array( 'active' => 'Active', 'upcoming' => 'Starts soon', 'expired' => 'Ended', 'none' => 'Awaiting start date' )[ $st['status'] ];
			echo '<ul class="wwp-list"><li><span>' . esc_html( $p['label'] ) . '</span><b class="is-' . esc_attr( $st['status'] ) . '">' . esc_html( $label ) . '</b></li>';
			if ( ! empty( $st['starts_on'] ) ) {
				echo '<li><span>Access window</span><b>' . self::d( $st['starts_on'] ) . ' – ' . self::d( $st['last_day'] ) . '</b></li>';
			}
			if ( 'active' === $st['status'] ) {
				echo '<li><span>Days left</span><b>' . (int) $st['days_left'] . '</b></li>';
			}
			echo '</ul>';
			if ( in_array( $st['status'], array( 'active', 'expired' ), true ) && ! empty( $p['renew_url'] ) ) {
				echo '<a class="wwp-link" href="' . esc_url( $p['renew_url'] ) . '">' . ( 'expired' === $st['status'] ? 'Renew monthly' : 'Continue monthly after your programme' ) . '</a>';
			}
		}
		echo '<a class="wwp-link" href="' . esc_url( home_url( '/my-account/orders/' ) ) . '">Orders and invoices</a></section>';

		echo '</div>'; // grid

		echo self::render_history( $uid, $f );
		echo '</div>';
		return ob_get_clean();
	}

	private static function render_history( int $uid, array $f ): string {
		if ( count( $f['series'] ) < 2 ) {
			return '';
		}
		$ids  = array_column( $f['series'], 'id' );
		$from = (int) ( $_GET['wwp_from'] ?? $ids[0] );
		$to   = (int) ( $_GET['wwp_to'] ?? end( $ids ) );
		$cmp  = WWP_Finance::compare( $uid, $from, $to );
		ob_start();
		echo '<section id="history" class="wwp-card wwp-history"><header><h2>Compare snapshots</h2><span class="wwp-muted">' . count( $ids ) . ' saved · none are ever overwritten</span></header>';
		echo '<form method="get" action="#history" class="wwp-cmpform">';
		foreach ( array( 'wwp_from' => array( 'From', $from ), 'wwp_to' => array( 'To', $to ) ) as $name => $cfg ) {
			echo '<label>' . $cfg[0] . ' <select name="' . $name . '">';
			foreach ( $f['series'] as $s ) {
				echo '<option value="' . (int) $s['id'] . '"' . selected( $s['id'], $cfg[1], false ) . '>' . self::d( $s['date'] ) . ' · ' . esc_html( WWP_Money::format( $s['net_worth'] ) ) . '</option>';
			}
			echo '</select></label>';
		}
		echo '<button class="wwp-btn is-ghost">Compare</button></form>';
		if ( $cmp ) {
			echo '<p class="wwp-muted">' . self::d( $cmp['from']['taken_on'] ) . ' → ' . self::d( $cmp['to']['taken_on'] ) . ' (' . (int) $cmp['days'] . ' days)</p>';
			echo '<table class="wwp-table"><thead><tr><th></th><th>' . self::d( $cmp['from']['taken_on'] ) . '</th><th>' . self::d( $cmp['to']['taken_on'] ) . '</th><th>Change</th></tr></thead><tbody>';
			$rows = array( 'income' => 'Monthly income', 'expenses' => 'Monthly expenses', 'monthly_surplus' => 'Monthly surplus', 'assets' => 'Assets', 'liabilities' => 'Liabilities', 'net_worth' => 'Net worth' );
			foreach ( $rows as $k => $lab ) {
				$d    = $cmp['delta'][ $k ];
				$good = in_array( $k, array( 'expenses', 'liabilities' ), true ) ? $d <= 0 : $d >= 0;
				echo '<tr class="' . ( in_array( $k, array( 'net_worth', 'monthly_surplus' ), true ) ? 'is-total' : '' ) . '"><th>' . esc_html( $lab ) . '</th><td>' . self::m( $cmp['from']['totals'][ $k ] ) . '</td><td>' . self::m( $cmp['to']['totals'][ $k ] ) . '</td><td class="' . ( 0 === $d ? '' : ( $good ? 'is-good' : 'is-bad' ) ) . '">' . self::m( $d, true ) . '</td></tr>';
			}
			echo '</tbody></table>';
			$moves = array();
			foreach ( $cmp['lines'] as $sec => $ls ) {
				foreach ( $ls as $l ) {
					if ( 0 !== $l['delta'] ) {
						$moves[] = $l + array( 'sec' => $sec );
					}
				}
			}
			usort( $moves, static fn( $a, $b ) => abs( $b['delta'] ) <=> abs( $a['delta'] ) );
			if ( $moves ) {
				echo '<p class="wwp-label">Biggest moves</p><ul class="wwp-moves">';
				foreach ( array_slice( $moves, 0, 4 ) as $mv ) {
					echo '<li><span>' . esc_html( $mv['label'] ) . ' <em>' . esc_html( $mv['sec'] ) . '</em></span><b>' . self::m( $mv['delta'], true ) . '</b></li>';
				}
				echo '</ul>';
			}
		}
		echo '</section>';
		return ob_get_clean();
	}

	/* ---------- worksheet ---------- */

	public static function render_form( $atts ): string {
		self::assets();
		$a = shortcode_atts( array( 'source' => 'dashboard', 'title' => 'Your numbers this month' ), $atts );
		if ( ! is_user_logged_in() ) {
			return '<p>Please log in to complete this worksheet.</p>';
		}
		$uid    = get_current_user_id();
		$latest = WWP_Finance::latest( $uid );
		$prefill = ( $latest && $latest['ok'] ) ? $latest['data'] : array(
			'income'      => array( array( 'label' => 'Salary (after tax)', 'cents' => 0 ) ),
			'expenses'    => array( array( 'label' => 'Rent / bond', 'cents' => 0 ), array( 'label' => 'Groceries', 'cents' => 0 ) ),
			'assets'      => array( array( 'label' => 'Savings account', 'cents' => 0 ) ),
			'liabilities' => array( array( 'label' => 'Credit card', 'cents' => 0 ) ),
		);
		$titles = array( 'income' => 'Monthly income', 'expenses' => 'Monthly expenses', 'assets' => 'What you own', 'liabilities' => 'What you owe' );
		ob_start();
		echo '<div class="wwp"><form class="wwp-card wwp-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wwp-form>';
		if ( isset( $_GET['wwp_saved'] ) ) {
			echo '<p class="wwp-saved" role="status">Saved. This snapshot is now on your dashboard.</p>';
		}
		if ( isset( $_GET['wwp_error'] ) ) {
			echo '<p class="wwp-err" role="alert">' . esc_html( sanitize_text_field( wp_unslash( $_GET['wwp_error'] ) ) ) . '</p>';
		}
		echo '<header><h2>' . esc_html( $a['title'] ) . '</h2><span class="wwp-muted">' . ( $latest ? 'Pre-filled from your snapshot of ' . self::d( $latest['taken_on'] ) . '. Saving adds a new one; the old one stays.' : 'Your first snapshot.' ) . '</span></header>';
		echo '<input type="hidden" name="action" value="wwp_save_snapshot"><input type="hidden" name="source" value="' . esc_attr( $a['source'] ) . '"><input type="hidden" name="back" value="' . esc_url( get_permalink() ) . '">';
		wp_nonce_field( 'wwp_save', 'wwp_nonce' );
		echo '<div class="wwp-sections">';
		foreach ( WWP_Finance::SECTIONS as $sec ) {
			echo '<fieldset data-sec="' . $sec . '"><legend>' . esc_html( $titles[ $sec ] ) . '</legend>';
			foreach ( $prefill[ $sec ] as $r ) {
				self::form_row( $sec, $r['label'], $r['cents'] );
			}
			echo '<button type="button" class="wwp-add" data-add="' . $sec . '">+ Add line</button><p class="wwp-sub">Total <b data-total="' . $sec . '">' . self::m( array_sum( array_column( $prefill[ $sec ], 'cents' ) ) ) . '</b></p></fieldset>';
		}
		echo '</div>';
		$t = WWP_Finance::totals( $prefill );
		echo '<div class="wwp-live"><div><span>Net worth</span><b data-out="net_worth">' . self::m( $t['net_worth'] ) . '</b></div><div><span>Monthly surplus</span><b data-out="monthly_surplus">' . self::m( $t['monthly_surplus'] ) . '</b></div>';
		echo '<label>Snapshot date <input type="date" name="taken_on" value="' . esc_attr( wwp_today() ) . '" max="' . esc_attr( wwp_today() ) . '"></label><button class="wwp-btn">Save snapshot</button></div>';
		echo '<template data-row><div class="wwp-row"><input name="" aria-label="Description" placeholder="Description"><input name="" inputmode="decimal" aria-label="Amount in rand" placeholder="R 0"><button type="button" class="wwp-x" aria-label="Remove line">×</button></div></template>';
		echo '</form></div>';
		return ob_get_clean();
	}

	private static function form_row( string $sec, string $label, int $cents ): void {
		$val = 0 === $cents ? '' : WWP_Money::format( $cents, 0 !== $cents % 100 );
		echo '<div class="wwp-row"><input name="' . $sec . '[label][]" value="' . esc_attr( $label ) . '" aria-label="Description"><input name="' . $sec . '[amount][]" value="' . esc_attr( $val ) . '" inputmode="decimal" aria-label="Amount in rand" placeholder="R 0"><button type="button" class="wwp-x" aria-label="Remove line">×</button></div>';
	}

	public static function handle_save(): void {
		$back = esc_url_raw( wp_unslash( $_POST['back'] ?? home_url( '/' ) ) );
		if ( ! is_user_logged_in() || ! wp_verify_nonce( sanitize_key( $_POST['wwp_nonce'] ?? '' ), 'wwp_save' ) ) {
			wp_die( 'Your session expired. Go back, refresh and save again.', 'Not saved', array( 'response' => 403 ) );
		}
		$input = array();
		foreach ( WWP_Finance::SECTIONS as $sec ) {
			$input[ $sec ] = wp_unslash( (array) ( $_POST[ $sec ] ?? array() ) );
		}
		$res = WWP_Finance::save( get_current_user_id(), $input, sanitize_text_field( wp_unslash( $_POST['source'] ?? 'dashboard' ) ), sanitize_text_field( wp_unslash( $_POST['taken_on'] ?? '' ) ) ?: null );
		$url = is_wp_error( $res ) ? add_query_arg( 'wwp_error', rawurlencode( $res->get_error_message() ), $back ) : add_query_arg( 'wwp_saved', $res['id'], $back );
		wp_safe_redirect( $url );
		exit;
	}

	/* ---------- coach ---------- */

	public static function render_coach(): string {
		self::assets();
		if ( ! current_user_can( 'wwp_coach' ) ) {
			return '<p>Coaches only.</p>';
		}
		$me = get_current_user_id();
		ob_start();
		echo '<div class="wwp">';
		$view = (int) ( $_GET['wwp_client'] ?? 0 );
		if ( $view ) {
			$allowed = current_user_can( 'wwp_manage_portal' ) || (int) get_user_meta( $view, 'wwp_coach_id', true ) === $me;
			if ( ! $allowed ) {
				WWP_Audit::log( $me, $view, 'coach_denied', 'page' );
				echo '<div class="wwp-card"><p>This client is not assigned to you.</p></div></div>';
				return ob_get_clean();
			}
			WWP_Audit::log( $me, $view, 'coach_viewed_finances', 'page' );
			$u = get_userdata( $view );
			echo '<section class="wwp-card"><header><h2>' . esc_html( $u->display_name ) . ' · finance history</h2><a class="wwp-link" href="' . esc_url( remove_query_arg( 'wwp_client' ) ) . '">← All clients</a></header>';
			echo '<table class="wwp-table"><thead><tr><th>Date</th><th>Entered from</th><th>Income</th><th>Expenses</th><th>Net worth</th></tr></thead><tbody>';
			foreach ( array_reverse( WWP_Finance::all( $view ) ) as $sn ) {
				$t = $sn['totals'];
				echo '<tr><td>' . self::d( $sn['taken_on'] ) . '</td><td>' . esc_html( self::source_label( $sn['source'] ) ) . '</td><td>' . self::m( $t['income'] ?? null ) . '</td><td>' . self::m( $t['expenses'] ?? null ) . '</td><td>' . self::m( $t['net_worth'] ?? null ) . '</td></tr>';
			}
			echo '</tbody></table><p class="wwp-muted">This view was recorded in the audit log.</p></section></div>';
			return ob_get_clean();
		}
		$rows = self::coach_clients( $me );
		echo '<section class="wwp-card"><header><h2>My clients</h2><span class="wwp-muted">' . count( $rows ) . ' assigned to you</span></header><table class="wwp-table"><thead><tr><th>Client</th><th>Access</th><th>Course</th><th>To review</th><th>Last snapshot</th><th>Net worth change</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r['name'] ) . '</td><td><span class="wwp-pill is-' . esc_attr( $r['status'] ) . '">' . esc_html( ucfirst( $r['status'] ) ) . '</span></td><td>' . (int) $r['progress'] . '%</td><td>' . (int) $r['pending'] . '</td><td>' . self::d( $r['last_snap'] ) . '</td><td>' . self::m( $r['nw_change'], true ) . '</td><td><a class="wwp-link" href="' . esc_url( add_query_arg( 'wwp_client', $r['id'] ) ) . '">Open</a></td></tr>';
		}
		echo '</tbody></table></section></div>';
		return ob_get_clean();
	}
}
