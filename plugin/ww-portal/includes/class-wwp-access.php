<?php
defined( 'ABSPATH' ) || exit;

/**
 * Programme access windows.
 *
 * Why not only LearnDash's own expiry? LearnDash's "Course Access Expiration" counts days
 * from enrolment, not from an agreed programme start date, and its optional data-deletion
 * setting removes course data after expiry. LearnDash group start/end dates do model a
 * cohort, but a group end date overrides per-user access extensions, which fights the
 * "subscribe monthly to restore access" requirement. A window table is ~200 lines and makes
 * every case below explicit and testable.
 *
 * A window is [starts_on, ends_on): active on starts_on, gone on ends_on.
 * Expiry removes LMS access only. Finance snapshots, progress, assignments, orders and the
 * WordPress account are never touched, so expired clients can log in, see their history
 * and buy a renewal.
 */
final class WWP_Access {

	public static function init(): void {
		add_action( 'wwp_daily', array( __CLASS__, 'sweep' ) );
		if ( ! wp_next_scheduled( 'wwp_daily' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 00:05' ), 'daily', 'wwp_daily' );
		}
		add_action( 'wp_login', static function ( $login, $user ) {
			self::sync( (int) $user->ID ); // never wait for cron to restore a paid renewal
		}, 10, 2 );
	}

	public static function programmes(): array {
		return (array) get_option( 'wwp_programmes', array() );
	}

	private static function valid_date( string $d ): bool {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $d );
		return $dt && $dt->format( 'Y-m-d' ) === $d;
	}

	public static function add_days( string $d, int $days ): string {
		return ( new DateTimeImmutable( $d ) )->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
	}

	/** Cohort purchase: N weeks from the agreed programme start date. @return int|WP_Error */
	public static function grant( int $user_id, string $programme, string $starts_on, ?int $weeks = null, string $source = 'admin' ) {
		$p = self::programmes()[ $programme ] ?? null;
		if ( ! $p ) {
			return new WP_Error( 'wwp_programme', "Unknown programme $programme" );
		}
		if ( ! self::valid_date( $starts_on ) ) {
			return new WP_Error( 'wwp_date', 'Start date must be YYYY-MM-DD' );
		}
		$weeks = $weeks ?? (int) $p['weeks'];
		$id    = self::insert( $user_id, $programme, $starts_on, self::add_days( $starts_on, 7 * $weeks ), $source );
		delete_user_meta( $user_id, "wwp_pending_start_$programme" );
		self::sync( $user_id );
		return $id;
	}

	/**
	 * Subscription renewal or admin extension: access until $until (exclusive).
	 * Starts where current access ends (no gap, no double counting) or today if already expired.
	 */
	public static function extend_to( int $user_id, string $programme, string $until, string $source ) {
		if ( ! self::valid_date( $until ) ) {
			return new WP_Error( 'wwp_date', 'Date must be YYYY-MM-DD' );
		}
		$st    = self::state( $user_id, $programme );
		$start = ( 'active' === $st['status'] ) ? $st['ends_on'] : wwp_today();
		if ( $until <= $start ) {
			return 0; // already covered
		}
		$id = self::insert( $user_id, $programme, $start, $until, $source );
		self::sync( $user_id );
		return $id;
	}

	private static function insert( int $user_id, string $programme, string $from, string $to, string $source ): int {
		global $wpdb;
		$wpdb->insert( WWP_Schema::t( 'access' ), array(
			'user_id'    => $user_id,
			'programme'  => $programme,
			'starts_on'  => $from,
			'ends_on'    => $to,
			'source'     => substr( $source, 0, 64 ),
			'created_at' => current_time( 'mysql', true ),
		) );
		$last = self::add_days( $to, -1 );
		WWP_Audit::log( get_current_user_id(), $user_id, 'access_window', "{$programme} {$from} to {$last} inclusive ({$source})" );
		return (int) $wpdb->insert_id;
	}

	public static function windows( int $user_id, string $programme ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . WWP_Schema::t( 'access' ) . ' WHERE user_id = %d AND programme = %s ORDER BY starts_on ASC, id ASC',
			$user_id, $programme
		), ARRAY_A );
	}

	/**
	 * @return array{status:string,starts_on:?string,ends_on:?string,last_day:?string,days_left:?int,pending:bool}
	 * status: active | upcoming | expired | none
	 */
	public static function state( int $user_id, string $programme, ?string $today = null ): array {
		$today   = $today ?? wwp_today();
		$pending = (bool) get_user_meta( $user_id, "wwp_pending_start_$programme", true );
		$ws      = self::windows( $user_id, $programme );
		if ( ! $ws ) {
			return array( 'status' => 'none', 'starts_on' => null, 'ends_on' => null, 'last_day' => null, 'days_left' => null, 'pending' => $pending );
		}
		// Merge overlapping and touching windows into spans.
		$spans = array();
		foreach ( $ws as $w ) {
			$n = count( $spans );
			if ( $n && $w['starts_on'] <= $spans[ $n - 1 ][1] ) {
				$spans[ $n - 1 ][1] = max( $spans[ $n - 1 ][1], $w['ends_on'] );
			} else {
				$spans[] = array( $w['starts_on'], $w['ends_on'] );
			}
		}
		foreach ( $spans as $s ) {
			if ( $s[0] <= $today && $today < $s[1] ) {
				$left = (int) ( ( strtotime( $s[1] ) - strtotime( $today ) ) / DAY_IN_SECONDS );
				return array( 'status' => 'active', 'starts_on' => $s[0], 'ends_on' => $s[1], 'last_day' => self::add_days( $s[1], -1 ), 'days_left' => $left, 'pending' => $pending );
			}
		}
		foreach ( $spans as $s ) {
			if ( $s[0] > $today ) {
				return array( 'status' => 'upcoming', 'starts_on' => $s[0], 'ends_on' => $s[1], 'last_day' => self::add_days( $s[1], -1 ), 'days_left' => null, 'pending' => $pending );
			}
		}
		$last = end( $spans );
		return array( 'status' => 'expired', 'starts_on' => $last[0], 'ends_on' => $last[1], 'last_day' => self::add_days( $last[1], -1 ), 'days_left' => 0, 'pending' => $pending );
	}

	/** Make LMS access match the windows. Idempotent. @return string[] changes made */
	public static function sync( int $user_id ): array {
		$lms     = wwp_lms();
		$changes = array();
		foreach ( self::programmes() as $key => $p ) {
			$st = self::state( $user_id, $key );
			if ( 'none' === $st['status'] ) {
				continue;
			}
			$want = 'active' === $st['status'];
			foreach ( (array) $p['courses'] as $course_id ) {
				$has = $lms->has_access( $user_id, (int) $course_id );
				if ( $want && ! $has ) {
					$lms->grant( $user_id, (int) $course_id );
					$changes[] = "grant:$course_id";
				} elseif ( ! $want && $has ) {
					$lms->revoke( $user_id, (int) $course_id );
					$changes[] = "revoke:$course_id";
				}
			}
		}
		if ( $changes ) {
			WWP_Audit::log( 0, $user_id, 'lms_sync', implode( ',', $changes ) );
		}
		return $changes;
	}

	/** Daily: reconcile everyone who has ever had a window. @return array{users:int,changes:int} */
	public static function sweep(): array {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT DISTINCT user_id FROM ' . WWP_Schema::t( 'access' ) );
		$n   = 0;
		foreach ( $ids as $id ) {
			$n += count( self::sync( (int) $id ) );
		}
		return array( 'users' => count( $ids ), 'changes' => $n );
	}
}
