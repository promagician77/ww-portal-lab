<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API. Two rules make cross-client leaks structurally impossible rather than "checked":
 *  1. /me/* routes take no user id at all. They read get_current_user_id() and nothing else.
 *  2. /coach/* routes need the wwp_coach capability AND the client must be assigned to that
 *     coach (admins pass but are still audited). Every read is written to the audit log.
 */
final class WWP_Rest {
	const NS = 'wwp/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function me_only(): bool|WP_Error {
		return is_user_logged_in() ? true : new WP_Error( 'rest_forbidden', 'Log in to see your portal.', array( 'status' => 401 ) );
	}

	public static function coach_of( WP_REST_Request $req ): bool|WP_Error {
		$me     = get_current_user_id();
		$client = (int) $req['id'];
		if ( ! $me ) {
			return new WP_Error( 'rest_forbidden', 'Log in first.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'wwp_coach' ) ) {
			return new WP_Error( 'rest_forbidden', 'Coaches only.', array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'wwp_manage_portal' ) && (int) get_user_meta( $client, 'wwp_coach_id', true ) !== $me ) {
			WWP_Audit::log( $me, $client, 'coach_denied', 'not assigned' );
			return new WP_Error( 'rest_forbidden', 'This client is not assigned to you.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/me/summary', array(
			'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'me_only' ),
			'callback' => static fn() => rest_ensure_response( WWP_Dashboard::summary( get_current_user_id() ) ),
		) );
		register_rest_route( self::NS, '/me/snapshots', array(
			array(
				'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'me_only' ),
				'callback' => static fn() => rest_ensure_response( WWP_Finance::all( get_current_user_id() ) ),
			),
			array(
				'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'me_only' ),
				'callback' => static function ( WP_REST_Request $r ) {
					$body = $r->get_json_params() ?: $r->get_body_params();
					$res  = WWP_Finance::save( get_current_user_id(), (array) $body, (string) ( $body['source'] ?? 'api' ), $body['taken_on'] ?? null );
					return is_wp_error( $res ) ? $res : new WP_REST_Response( $res, 201 );
				},
			),
		) );
		register_rest_route( self::NS, '/me/snapshots/compare', array(
			'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'me_only' ),
			'args' => array( 'from' => array( 'required' => true, 'type' => 'integer' ), 'to' => array( 'required' => true, 'type' => 'integer' ) ),
			'callback' => static function ( WP_REST_Request $r ) {
				$c = WWP_Finance::compare( get_current_user_id(), (int) $r['from'], (int) $r['to'] );
				return $c ?? new WP_Error( 'wwp_not_found', 'Snapshot not found', array( 'status' => 404 ) );
			},
		) );
		register_rest_route( self::NS, '/me/resources', array(
			'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'me_only' ),
			'callback' => static fn() => rest_ensure_response( WWP_Resources::for_user( get_current_user_id() ) ),
		) );
		register_rest_route( self::NS, '/coach/clients', array(
			'methods' => 'GET',
			'permission_callback' => static fn() => current_user_can( 'wwp_coach' ) ? true : new WP_Error( 'rest_forbidden', 'Coaches only.', array( 'status' => is_user_logged_in() ? 403 : 401 ) ),
			'callback' => static fn() => rest_ensure_response( WWP_Dashboard::coach_clients( get_current_user_id() ) ),
		) );
		register_rest_route( self::NS, '/coach/clients/(?P<id>\d+)/snapshots', array(
			'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'coach_of' ),
			'callback' => static function ( WP_REST_Request $r ) {
				$client = (int) $r['id'];
				WWP_Audit::log( get_current_user_id(), $client, 'coach_viewed_finances', 'rest' );
				return rest_ensure_response( WWP_Finance::all( $client ) );
			},
		) );
	}
}
