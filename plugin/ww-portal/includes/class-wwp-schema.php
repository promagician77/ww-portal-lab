<?php
defined( 'ABSPATH' ) || exit;

/**
 * Four small tables. Finance payloads are ciphertext; everything else is metadata.
 */
final class WWP_Schema {
	const DB_VERSION = '3';

	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'wwp_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		// Append-only: one row per save. No UPDATE path exists in code. Per-user hash chain.
		dbDelta( 'CREATE TABLE ' . self::t( 'snapshots' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			taken_on date NOT NULL,
			source varchar(80) NOT NULL DEFAULT 'dashboard',
			created_at datetime NOT NULL,
			created_by bigint(20) unsigned NOT NULL,
			key_id varchar(16) NOT NULL,
			payload longtext NOT NULL,
			prev_hash char(64) NOT NULL,
			hash char(64) NOT NULL,
			PRIMARY KEY  (id),
			KEY user_taken (user_id,taken_on)
		) $c;" );

		// Access windows: [starts_on, ends_on). Many per user+programme (cohort, renewals, admin extensions).
		dbDelta( 'CREATE TABLE ' . self::t( 'access' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			programme varchar(64) NOT NULL,
			starts_on date NOT NULL,
			ends_on date NOT NULL,
			source varchar(64) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_prog (user_id,programme)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::t( 'assignments' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			resource_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			assigned_by bigint(20) unsigned NOT NULL,
			assigned_at datetime NOT NULL,
			note varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY res_user (resource_id,user_id),
			KEY user_id (user_id)
		) $c;" );

		dbDelta( 'CREATE TABLE ' . self::t( 'audit' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint(20) unsigned NOT NULL,
			subject_id bigint(20) unsigned NOT NULL,
			action varchar(48) NOT NULL,
			detail varchar(190) NOT NULL DEFAULT '',
			at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY subject_id (subject_id)
		) $c;" );

		// Demo LMS state (only used when LearnDash is not active).
		dbDelta( 'CREATE TABLE ' . self::t( 'demo_enrol' ) . " (
			user_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (user_id,course_id)
		) $c;" );

		if ( ! get_role( 'wwp_coach' ) ) {
			add_role( 'wwp_coach', 'Coach', array( 'read' => true, 'wwp_coach' => true ) );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'wwp_coach' );
			$admin->add_cap( 'wwp_manage_portal' );
		}
		self::private_dir();
		update_option( 'wwp_db_version', self::DB_VERSION );
	}

	/** Private file store for resources. Deny-all on Apache; Nginx needs the rule in docs/HANDOVER.md. */
	public static function private_dir(): string {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'wwp-private';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( "$dir/.htaccess" ) ) {
			file_put_contents( "$dir/.htaccess", "Require all denied\nDeny from all\n" );
			file_put_contents( "$dir/index.php", "<?php // Silence.\n" );
		}
		return $dir;
	}
}
