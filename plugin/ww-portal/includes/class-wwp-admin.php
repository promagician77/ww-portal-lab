<?php
defined( 'ABSPATH' ) || exit;

/**
 * Two admin screens: Client access (set the agreed start date, extend, see status) and the
 * audit log. Course building stays in LearnDash's own builder; resources in their own menu.
 */
final class WWP_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wwp_grant', array( __CLASS__, 'handle_grant' ) );
	}

	public static function menu(): void {
		add_menu_page( 'Client portal', 'Client portal', 'wwp_manage_portal', 'wwp-portal', array( __CLASS__, 'page_access' ), 'dashicons-chart-line', 26 );
		add_submenu_page( 'wwp-portal', 'Client access', 'Client access', 'wwp_manage_portal', 'wwp-portal', array( __CLASS__, 'page_access' ) );
		add_submenu_page( 'wwp-portal', 'Audit log', 'Audit log', 'wwp_manage_portal', 'wwp-audit', array( __CLASS__, 'page_audit' ) );
	}

	public static function page_access(): void {
		$progs = WWP_Access::programmes();
		echo '<div class="wrap"><h1>Client access</h1>';
		if ( isset( $_GET['done'] ) ) {
			echo '<div class="notice notice-success"><p>Access updated and course enrolment synced.</p></div>';
		}
		echo '<p>Purchases create a pending client here. Set the agreed programme start date; access runs for the programme length from that date. Expiry only locks course content. Finance history, progress and worksheets are kept.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Client</th><th>Programme</th><th>Status</th><th>Access window</th><th>Snapshots</th><th>Set start / extend</th></tr></thead><tbody>';
		foreach ( WWP_Resources::clients() as $u ) {
			foreach ( $progs as $key => $p ) {
				$st = WWP_Access::state( (int) $u->ID, $key );
				if ( 'none' === $st['status'] && ! $st['pending'] ) {
					continue;
				}
				$status = $st['pending'] && 'none' === $st['status'] ? 'Paid · needs start date' : ucfirst( $st['status'] );
				$colour = array( 'active' => '#0a7d55', 'expired' => '#b4442c', 'upcoming' => '#2a5db0' )[ $st['status'] ] ?? '#8a6d1f';
				echo '<tr><td><strong>' . esc_html( $u->display_name ) . '</strong><br><span style="color:#666">' . esc_html( $u->user_email ) . '</span></td>';
				echo '<td>' . esc_html( $p['label'] ) . '</td><td><span style="color:' . $colour . ';font-weight:600">' . esc_html( $status ) . '</span></td>';
				echo '<td>' . ( $st['starts_on'] ? esc_html( $st['starts_on'] . ' → ' . $st['last_day'] ) : '—' ) . '</td>';
				echo '<td>' . (int) WWP_Finance::count( (int) $u->ID ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:6px;align-items:center">';
				wp_nonce_field( 'wwp_grant' );
				echo '<input type="hidden" name="action" value="wwp_grant"><input type="hidden" name="user" value="' . (int) $u->ID . '"><input type="hidden" name="programme" value="' . esc_attr( $key ) . '">';
				if ( 'none' === $st['status'] ) {
					echo '<input type="date" name="start" required> <input type="number" name="weeks" value="' . (int) $p['weeks'] . '" min="1" max="104" style="width:64px"> weeks <button class="button button-primary">Start</button>';
				} else {
					echo '<input type="date" name="until" required> <button class="button">Extend to</button>';
				}
				echo '</form></td></tr>';
			}
		}
		echo '</tbody></table></div>';
	}

	public static function handle_grant(): void {
		if ( ! current_user_can( 'wwp_manage_portal' ) || ! check_admin_referer( 'wwp_grant' ) ) {
			wp_die( 'Not allowed', 403 );
		}
		$user = (int) $_POST['user'];
		$prog = sanitize_key( $_POST['programme'] );
		if ( ! empty( $_POST['start'] ) ) {
			WWP_Access::grant( $user, $prog, sanitize_text_field( $_POST['start'] ), (int) $_POST['weeks'], 'admin:' . get_current_user_id() );
		} elseif ( ! empty( $_POST['until'] ) ) {
			// "Extend to" is the last day the client should have access; windows are end-exclusive.
			WWP_Access::extend_to( $user, $prog, WWP_Access::add_days( sanitize_text_field( $_POST['until'] ), 1 ), 'admin:' . get_current_user_id() );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wwp-portal&done=1' ) );
		exit;
	}

	public static function page_audit(): void {
		echo '<div class="wrap"><h1>Audit log</h1><p>Who viewed or exported whose finance data, access changes and resource assignments. Rows are never edited.</p>';
		echo '<table class="widefat striped"><thead><tr><th>When (UTC)</th><th>Who</th><th>Client</th><th>Action</th><th>Detail</th></tr></thead><tbody>';
		foreach ( WWP_Audit::recent( 100 ) as $r ) {
			$actor   = (int) $r['actor_id'] ? ( get_userdata( (int) $r['actor_id'] )->display_name ?? '#' . $r['actor_id'] ) : 'System';
			$subject = get_userdata( (int) $r['subject_id'] );
			echo '<tr><td>' . esc_html( $r['at'] ) . '</td><td>' . esc_html( $actor ) . '</td><td>' . esc_html( $subject ? $subject->display_name : '#' . $r['subject_id'] ) . '</td><td><code>' . esc_html( $r['action'] ) . '</code></td><td>' . esc_html( $r['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
