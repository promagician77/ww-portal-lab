<?php
defined( 'ABSPATH' ) || exit;

/** Who looked at whose finances, and every admin export or erase. */
final class WWP_Audit {

	public static function log( int $actor, int $subject, string $action, string $detail = '' ): void {
		global $wpdb;
		$wpdb->insert(
			WWP_Schema::t( 'audit' ),
			array(
				'actor_id'   => $actor,
				'subject_id' => $subject,
				'action'     => substr( $action, 0, 48 ),
				'detail'     => substr( $detail, 0, 190 ),
				'at'         => current_time( 'mysql', true ),
			)
		);
	}

	/** @return array<int,array> newest first */
	public static function for_subject( int $subject, int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . WWP_Schema::t( 'audit' ) . ' WHERE subject_id = %d ORDER BY id DESC LIMIT %d', $subject, $limit ),
			ARRAY_A
		);
	}

	public static function recent( int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . WWP_Schema::t( 'audit' ) . ' ORDER BY id DESC LIMIT %d', $limit ),
			ARRAY_A
		);
	}
}
