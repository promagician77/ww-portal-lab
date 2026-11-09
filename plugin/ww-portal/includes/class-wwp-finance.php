<?php
defined( 'ABSPATH' ) || exit;

/**
 * Finance snapshots.
 *
 * One store, three entry points (lesson worksheet, dashboard, REST). A figure typed in
 * Week 2 of a course is the same row the dashboard reads, so "data entered in a course is
 * available outside it" is true by construction, not by syncing.
 *
 * Append-only: every save is a new dated row; no code path updates a row. Each row's hash
 * covers the previous row's hash, so a row edited or deleted directly in the database
 * shows up in verify_chain().
 */
final class WWP_Finance {

	const SECTIONS  = array( 'income', 'expenses', 'assets', 'liabilities' );
	const MAX_LINES = 40;
	const GENESIS   = '0000000000000000000000000000000000000000000000000000000000000000';

	/**
	 * Normalise any input shape into the stored payload.
	 * Accepts [section => [ [label, amount|cents], ... ]] or form arrays [section => [label=>[], amount=>[]]].
	 * @return array|WP_Error
	 */
	public static function normalise( array $in ) {
		$out    = array();
		$errors = array();
		foreach ( self::SECTIONS as $sec ) {
			$rows = $in[ $sec ] ?? array();
			if ( isset( $rows['label'] ) && is_array( $rows['label'] ) ) { // form shape
				$pairs = array();
				foreach ( $rows['label'] as $i => $lab ) {
					$pairs[] = array( 'label' => $lab, 'amount' => $rows['amount'][ $i ] ?? '' );
				}
				$rows = $pairs;
			}
			$clean = array();
			foreach ( array_slice( (array) $rows, 0, self::MAX_LINES ) as $i => $r ) {
				$label = trim( sanitize_text_field( (string) ( $r['label'] ?? '' ) ) );
				if ( isset( $r['cents'] ) && is_int( $r['cents'] ) ) {
					$cents = $r['cents'];
				} else {
					$raw = $r['amount'] ?? '';
					if ( '' === $label && '' === trim( (string) $raw ) ) {
						continue; // empty row in the form
					}
					$cents = WWP_Money::parse( $raw );
					if ( null === $cents ) {
						$errors[] = sprintf( '%s line %d: "%s" is not an amount', $sec, $i + 1, substr( (string) $raw, 0, 30 ) );
						continue;
					}
				}
				if ( abs( $cents ) > 1e15 ) {
					$errors[] = "$sec line " . ( $i + 1 ) . ': amount out of range';
					continue;
				}
				$clean[] = array( 'label' => mb_substr( '' === $label ? ucfirst( $sec ) . ' ' . ( $i + 1 ) : $label, 0, 60 ), 'cents' => $cents );
			}
			$out[ $sec ] = $clean;
		}
		$out['note'] = mb_substr( sanitize_textarea_field( (string) ( $in['note'] ?? '' ) ), 0, 500 );
		if ( $errors ) {
			return new WP_Error( 'wwp_invalid_amount', implode( '; ', $errors ), array( 'status' => 400 ) );
		}
		return $out;
	}

	public static function totals( array $p ): array {
		$sum = static fn( array $rows ): int => array_sum( array_column( $rows, 'cents' ) );
		$t   = array(
			'income'      => $sum( $p['income'] ?? array() ),
			'expenses'    => $sum( $p['expenses'] ?? array() ),
			'assets'      => $sum( $p['assets'] ?? array() ),
			'liabilities' => $sum( $p['liabilities'] ?? array() ),
		);
		$t['net_worth']       = $t['assets'] - $t['liabilities'];
		$t['monthly_surplus'] = $t['income'] - $t['expenses'];
		$t['savings_rate_bp'] = $t['income'] > 0 ? intdiv( $t['monthly_surplus'] * 10000, $t['income'] ) : null;
		return $t;
	}

	/** @return array|WP_Error the saved snapshot (decoded) */
	public static function save( int $user_id, array $input, string $source = 'dashboard', ?string $taken_on = null, ?int $by = null ) {
		global $wpdb;
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'wwp_no_user', 'Unknown user', array( 'status' => 400 ) );
		}
		$payload = self::normalise( $input );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$taken_on = $taken_on ?: wwp_today();
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $taken_on );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $taken_on || $taken_on > wwp_today() ) {
			return new WP_Error( 'wwp_bad_date', 'Snapshot date must be today or earlier (YYYY-MM-DD)', array( 'status' => 400 ) );
		}
		$source = substr( preg_replace( '/[^a-z0-9:\/_-]/', '', strtolower( $source ) ), 0, 80 ) ?: 'dashboard';

		$lock = 'wwp_lock_' . $user_id; // one writer per user so the hash chain never forks
		$tries = 0;
		while ( ! add_option( $lock, time(), '', false ) ) {
			if ( ++$tries > 50 ) {
				$held = (int) get_option( $lock );
				if ( $held && time() - $held > 10 ) {
					delete_option( $lock ); // stale lock from a crashed request
					continue;
				}
				return new WP_Error( 'wwp_busy', 'Another save is in progress, try again', array( 'status' => 409 ) );
			}
			usleep( 20000 );
		}
		try {
			$prev   = $wpdb->get_var( $wpdb->prepare( 'SELECT hash FROM ' . WWP_Schema::t( 'snapshots' ) . ' WHERE user_id = %d ORDER BY id DESC LIMIT 1', $user_id ) ) ?: self::GENESIS;
			$sealed = WWP_Crypto::seal( wp_json_encode( $payload ), WWP_Crypto::aad( $user_id, $taken_on ) );
			$row    = array(
				'user_id'    => $user_id,
				'taken_on'   => $taken_on,
				'source'     => $source,
				'created_at' => current_time( 'mysql', true ),
				'created_by' => $by ?? get_current_user_id() ?: $user_id,
				'key_id'     => WWP_Crypto::key_id(),
				'payload'    => $sealed,
				'prev_hash'  => $prev,
			);
			$row['hash'] = self::row_hash( $row );
			$wpdb->insert( WWP_Schema::t( 'snapshots' ), $row );
			$id = (int) $wpdb->insert_id;
		} finally {
			delete_option( $lock );
		}
		do_action( 'wwp_snapshot_saved', $user_id, $id, $source );
		return self::get( $user_id, $id );
	}

	private static function row_hash( array $r ): string {
		return hash( 'sha256', implode( '|', array( $r['prev_hash'], $r['user_id'], $r['taken_on'], $r['source'], $r['created_at'], $r['payload'] ) ) );
	}

	private static function decode( array $row ): array {
		$pt   = WWP_Crypto::open( $row['payload'], WWP_Crypto::aad( (int) $row['user_id'], $row['taken_on'] ) );
		$data = null === $pt ? null : json_decode( $pt, true );
		return array(
			'id'       => (int) $row['id'],
			'taken_on' => $row['taken_on'],
			'source'   => $row['source'],
			'ok'       => is_array( $data ),
			'data'     => is_array( $data ) ? $data : null,
			'totals'   => is_array( $data ) ? self::totals( $data ) : null,
		);
	}

	/** @return array[] oldest first */
	public static function all( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WWP_Schema::t( 'snapshots' ) . ' WHERE user_id = %d ORDER BY taken_on ASC, id ASC', $user_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'decode' ), $rows );
	}

	public static function get( int $user_id, int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WWP_Schema::t( 'snapshots' ) . ' WHERE user_id = %d AND id = %d', $user_id, $id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	public static function latest( int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WWP_Schema::t( 'snapshots' ) . ' WHERE user_id = %d ORDER BY taken_on DESC, id DESC LIMIT 1', $user_id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	public static function count( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WWP_Schema::t( 'snapshots' ) . ' WHERE user_id = %d', $user_id ) );
	}

	/** Totals and per-line deltas between two of the same user's snapshots. */
	public static function compare( int $user_id, int $from_id, int $to_id ): ?array {
		$a = self::get( $user_id, $from_id );
		$b = self::get( $user_id, $to_id );
		if ( ! $a || ! $b || ! $a['ok'] || ! $b['ok'] ) {
			return null;
		}
		$delta = array();
		foreach ( $b['totals'] as $k => $v ) {
			$delta[ $k ] = ( null === $v || null === $a['totals'][ $k ] ) ? null : $v - $a['totals'][ $k ];
		}
		$lines = array();
		foreach ( self::SECTIONS as $sec ) {
			$idx = array();
			foreach ( $a['data'][ $sec ] as $r ) {
				$idx[ mb_strtolower( $r['label'] ) ] = array( 'label' => $r['label'], 'from' => $r['cents'], 'to' => 0 );
			}
			foreach ( $b['data'][ $sec ] as $r ) {
				$k = mb_strtolower( $r['label'] );
				$idx[ $k ] = array( 'label' => $r['label'], 'from' => $idx[ $k ]['from'] ?? 0, 'to' => $r['cents'] );
			}
			foreach ( $idx as $l ) {
				$l['delta']      = $l['to'] - $l['from'];
				$lines[ $sec ][] = $l;
			}
		}
		$days = (int) ( ( strtotime( $b['taken_on'] ) - strtotime( $a['taken_on'] ) ) / DAY_IN_SECONDS );
		return array( 'from' => $a, 'to' => $b, 'delta' => $delta, 'lines' => $lines, 'days' => $days );
	}

	/** @return array{ok:bool,rows:int,broken_at:?int,undecryptable:int} */
	public static function verify_chain( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WWP_Schema::t( 'snapshots' ) . ' WHERE user_id = %d ORDER BY id ASC', $user_id ), ARRAY_A );
		$prev = self::GENESIS;
		$bad  = 0;
		foreach ( $rows as $r ) {
			if ( $r['prev_hash'] !== $prev || ! hash_equals( $r['hash'], self::row_hash( $r ) ) ) {
				return array( 'ok' => false, 'rows' => count( $rows ), 'broken_at' => (int) $r['id'], 'undecryptable' => $bad );
			}
			if ( null === WWP_Crypto::open( $r['payload'], WWP_Crypto::aad( (int) $r['user_id'], $r['taken_on'] ) ) ) {
				++$bad;
			}
			$prev = $r['hash'];
		}
		return array( 'ok' => 0 === $bad, 'rows' => count( $rows ), 'broken_at' => null, 'undecryptable' => $bad );
	}

	/** Only caller: the WordPress privacy eraser (WWP_Privacy), which audits it. */
	public static function erase_for_privacy( int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( WWP_Schema::t( 'snapshots' ), array( 'user_id' => $user_id ) );
	}
}
