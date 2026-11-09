<?php
defined( 'ABSPATH' ) || exit;

/**
 * Resource library with per-client assignment, independent of any course.
 * Files sit in uploads/wwp-private (deny-all) and are streamed only to assigned clients,
 * coaches and admins. Guessing a URL gets nothing.
 */
final class WWP_Resources {
	const CPT = 'wwp_resource';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::CPT, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save_meta' ), 10, 2 );
		add_action( 'admin_post_wwp_download', array( __CLASS__, 'download' ) );
	}

	public static function register(): void {
		register_post_type( self::CPT, array(
			'labels'       => array( 'name' => 'Client resources', 'singular_name' => 'Resource', 'add_new_item' => 'Add resource', 'edit_item' => 'Edit resource' ),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => 'wwp-portal',
			'supports'     => array( 'title', 'editor' ),
			'capability_type' => 'post',
		) );
	}

	public static function meta_boxes(): void {
		add_meta_box( 'wwp_file', 'File or link', array( __CLASS__, 'box_file' ), self::CPT, 'normal', 'high' );
		add_meta_box( 'wwp_assign', 'Assign to clients', array( __CLASS__, 'box_assign' ), self::CPT, 'side', 'high' );
	}

	public static function box_file( WP_Post $post ): void {
		wp_nonce_field( 'wwp_res_' . $post->ID, 'wwp_res_nonce' );
		$file = get_post_meta( $post->ID, '_wwp_file', true );
		$url  = get_post_meta( $post->ID, '_wwp_url', true );
		echo '<p><label>Private file name (in uploads/wwp-private)<br><input class="widefat" name="wwp_file" value="' . esc_attr( $file ) . '" placeholder="budget-worksheet.pdf"></label></p>';
		echo '<p><label>…or a link (video, external doc)<br><input class="widefat" name="wwp_url" value="' . esc_attr( $url ) . '" placeholder="https://"></label></p>';
	}

	public static function clients(): array {
		return get_users( array( 'role__in' => array( 'customer', 'subscriber' ), 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
	}

	public static function box_assign( WP_Post $post ): void {
		$assigned = array_map( 'intval', array_column( self::assignments_for_resource( $post->ID ), 'user_id' ) );
		echo '<input type="search" placeholder="Filter clients…" style="width:100%;margin-bottom:6px" oninput="var q=this.value.toLowerCase();this.nextElementSibling.querySelectorAll(\'label\').forEach(function(l){l.style.display=l.textContent.toLowerCase().indexOf(q)>-1?\'\':\'none\'})">';
		echo '<div style="max-height:260px;overflow:auto">';
		foreach ( self::clients() as $u ) {
			printf(
				'<label style="display:block;margin:3px 0"><input type="checkbox" name="wwp_assign[]" value="%d" %s> %s <span style="color:#777">%s</span></label>',
				(int) $u->ID, checked( in_array( (int) $u->ID, $assigned, true ), true, false ), esc_html( $u->display_name ), esc_html( $u->user_email )
			);
		}
		echo '</div><p class="description">Clients see this in “My resources” immediately. No course needed.</p>';
	}

	public static function save_meta( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['wwp_res_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wwp_res_nonce'] ), 'wwp_res_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_wwp_file', sanitize_file_name( wp_unslash( $_POST['wwp_file'] ?? '' ) ) );
		update_post_meta( $post_id, '_wwp_url', esc_url_raw( wp_unslash( $_POST['wwp_url'] ?? '' ) ) );
		$want = array_map( 'intval', (array) ( $_POST['wwp_assign'] ?? array() ) );
		self::set_assignees( $post_id, $want, get_current_user_id() );
	}

	public static function set_assignees( int $resource_id, array $user_ids, int $by ): void {
		$have = array_map( 'intval', array_column( self::assignments_for_resource( $resource_id ), 'user_id' ) );
		foreach ( array_diff( $user_ids, $have ) as $u ) {
			self::assign( $resource_id, $u, $by );
		}
		foreach ( array_diff( $have, $user_ids ) as $u ) {
			self::unassign( $resource_id, $u, $by );
		}
	}

	public static function assign( int $resource_id, int $user_id, int $by, string $note = '' ): bool {
		global $wpdb;
		if ( self::CPT !== get_post_type( $resource_id ) || ! get_userdata( $user_id ) ) {
			return false;
		}
		$ok = (bool) $wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . WWP_Schema::t( 'assignments' ) . ' (resource_id, user_id, assigned_by, assigned_at, note) VALUES (%d, %d, %d, %s, %s)',
			$resource_id, $user_id, $by, current_time( 'mysql', true ), $note
		) );
		if ( $ok ) {
			WWP_Audit::log( $by, $user_id, 'resource_assigned', get_the_title( $resource_id ) );
		}
		return $ok;
	}

	public static function unassign( int $resource_id, int $user_id, int $by ): void {
		global $wpdb;
		$wpdb->delete( WWP_Schema::t( 'assignments' ), array( 'resource_id' => $resource_id, 'user_id' => $user_id ) );
		WWP_Audit::log( $by, $user_id, 'resource_unassigned', get_the_title( $resource_id ) );
	}

	public static function assignments_for_resource( int $resource_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WWP_Schema::t( 'assignments' ) . ' WHERE resource_id = %d', $resource_id ), ARRAY_A );
	}

	/** Only the given user's resources. There is no "all resources" query reachable by clients. */
	public static function for_user( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WWP_Schema::t( 'assignments' ) . ' WHERE user_id = %d ORDER BY assigned_at DESC', $user_id ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$p = get_post( (int) $r['resource_id'] );
			if ( ! $p || 'publish' !== $p->post_status ) {
				continue;
			}
			$file  = get_post_meta( $p->ID, '_wwp_file', true );
			$out[] = array(
				'id'          => $p->ID,
				'title'       => $p->post_title,
				'summary'     => wp_trim_words( wp_strip_all_tags( $p->post_content ), 18 ),
				'kind'        => $file ? strtoupper( pathinfo( $file, PATHINFO_EXTENSION ) ) : 'LINK',
				'assigned_at' => $r['assigned_at'],
				'note'        => $r['note'],
				'url'         => $file ? self::download_url( $p->ID ) : get_post_meta( $p->ID, '_wwp_url', true ),
			);
		}
		return $out;
	}

	public static function download_url( int $resource_id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=wwp_download&r=' . $resource_id ), 'wwp_dl_' . $resource_id );
	}

	public static function can_download( int $user_id, int $resource_id ): bool {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( user_can( $user_id, 'wwp_coach' ) || user_can( $user_id, 'edit_others_posts' ) ) {
			return true;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . WWP_Schema::t( 'assignments' ) . ' WHERE resource_id = %d AND user_id = %d', $resource_id, $user_id ) );
	}

	/** Resolve the private path, refusing anything that escapes the private directory. */
	public static function file_path( int $resource_id ): ?string {
		$name = basename( (string) get_post_meta( $resource_id, '_wwp_file', true ) );
		if ( '' === $name ) {
			return null;
		}
		$dir  = realpath( WWP_Schema::private_dir() );
		$path = realpath( $dir . '/' . $name );
		return ( $path && str_starts_with( $path, $dir . DIRECTORY_SEPARATOR ) && is_file( $path ) ) ? $path : null;
	}

	public static function download(): void {
		$rid = (int) ( $_GET['r'] ?? 0 );
		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'wwp_dl_' . $rid ) || ! self::can_download( get_current_user_id(), $rid ) ) {
			wp_die( 'This resource is not assigned to your account.', 'Not available', array( 'response' => 403 ) );
		}
		$path = self::file_path( $rid );
		if ( ! $path ) {
			wp_die( 'File missing', 'Not found', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: ' . ( wp_check_filetype( $path )['type'] ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( basename( $path ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}
}
