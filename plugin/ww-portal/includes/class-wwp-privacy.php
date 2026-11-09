<?php
defined( 'ABSPATH' ) || exit;

/**
 * POPIA / GDPR requests through WordPress core's own tools (Tools → Export / Erase Personal Data),
 * so the admin uses screens WordPress already ships, with email confirmation built in.
 */
final class WWP_Privacy {

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', static function ( array $e ) {
			$e['wwp-finance'] = array( 'exporter_friendly_name' => 'Portal: finance snapshots', 'callback' => array( __CLASS__, 'export' ) );
			$e['wwp-access']  = array( 'exporter_friendly_name' => 'Portal: programme access and resources', 'callback' => array( __CLASS__, 'export_access' ) );
			return $e;
		} );
		add_filter( 'wp_privacy_personal_data_erasers', static function ( array $e ) {
			$e['wwp-finance'] = array( 'eraser_friendly_name' => 'Portal: finance snapshots', 'callback' => array( __CLASS__, 'erase' ) );
			return $e;
		} );
	}

	public static function export( string $email, int $page = 1 ): array {
		$u = get_user_by( 'email', $email );
		if ( ! $u ) {
			return array( 'data' => array(), 'done' => true );
		}
		$items = array();
		foreach ( WWP_Finance::all( $u->ID ) as $s ) {
			$data = array(
				array( 'name' => 'Date', 'value' => $s['taken_on'] ),
				array( 'name' => 'Entered from', 'value' => $s['source'] ),
			);
			if ( $s['ok'] ) {
				foreach ( WWP_Finance::SECTIONS as $sec ) {
					foreach ( $s['data'][ $sec ] as $l ) {
						$data[] = array( 'name' => ucfirst( $sec ) . ': ' . $l['label'], 'value' => WWP_Money::format( $l['cents'], true ) );
					}
				}
				$data[] = array( 'name' => 'Net worth', 'value' => WWP_Money::format( $s['totals']['net_worth'], true ) );
				$data[] = array( 'name' => 'Monthly surplus', 'value' => WWP_Money::format( $s['totals']['monthly_surplus'], true ) );
			} else {
				$data[] = array( 'name' => 'Status', 'value' => 'Could not be decrypted with the current key' );
			}
			$items[] = array( 'group_id' => 'wwp-finance', 'group_label' => 'Finance snapshots', 'item_id' => 'snapshot-' . $s['id'], 'data' => $data );
		}
		WWP_Audit::log( get_current_user_id(), $u->ID, 'privacy_export', count( $items ) . ' snapshots' );
		return array( 'data' => $items, 'done' => true );
	}

	public static function export_access( string $email, int $page = 1 ): array {
		$u = get_user_by( 'email', $email );
		if ( ! $u ) {
			return array( 'data' => array(), 'done' => true );
		}
		$items = array();
		foreach ( array_keys( WWP_Access::programmes() ) as $p ) {
			foreach ( WWP_Access::windows( $u->ID, $p ) as $w ) {
				$items[] = array( 'group_id' => 'wwp-access', 'group_label' => 'Programme access', 'item_id' => 'access-' . $w['id'], 'data' => array(
					array( 'name' => 'Programme', 'value' => $p ),
					array( 'name' => 'From', 'value' => $w['starts_on'] ),
					array( 'name' => 'Until (exclusive)', 'value' => $w['ends_on'] ),
					array( 'name' => 'Source', 'value' => $w['source'] ),
				) );
			}
		}
		foreach ( WWP_Resources::for_user( $u->ID ) as $r ) {
			$items[] = array( 'group_id' => 'wwp-resources', 'group_label' => 'Assigned resources', 'item_id' => 'res-' . $r['id'], 'data' => array(
				array( 'name' => 'Resource', 'value' => $r['title'] ),
				array( 'name' => 'Assigned', 'value' => $r['assigned_at'] ),
			) );
		}
		return array( 'data' => $items, 'done' => true );
	}

	public static function erase( string $email, int $page = 1 ): array {
		$u = get_user_by( 'email', $email );
		if ( ! $u ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		$n = WWP_Finance::erase_for_privacy( $u->ID );
		WWP_Audit::log( get_current_user_id(), $u->ID, 'privacy_erase', "$n snapshots" );
		return array( 'items_removed' => $n > 0, 'items_retained' => false, 'messages' => array( "$n finance snapshots erased." ), 'done' => true );
	}
}
