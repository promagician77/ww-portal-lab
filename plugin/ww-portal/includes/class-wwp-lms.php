<?php
defined( 'ABSPATH' ) || exit;

/**
 * The portal never talks to the LMS directly; it goes through this interface, so the
 * LearnDash vs Tutor LMS decision changes one class, not the dashboard or access engine.
 */
interface WWP_LMS_Adapter {
	public function name(): string;
	public function grant( int $user_id, int $course_id ): void;
	/** Remove access only. Must never reset progress, quiz results or uploaded assignments. */
	public function revoke( int $user_id, int $course_id ): void;
	public function has_access( int $user_id, int $course_id ): bool;
	/** @return array{title:string,completed:int,total:int,percent:int,next_url:string,next_title:string} */
	public function progress( int $user_id, int $course_id ): array;
	/** @return array{pending:int,approved:int,last_feedback:string} */
	public function assignments( int $user_id, int $course_id ): array;
}

/**
 * LearnDash adapter. Written against LearnDash's documented developer functions
 * (developers.learndash.com). NOT executed in this demo because LearnDash is a paid plugin;
 * it is the first thing exercised on the staging site.
 *
 * Settings we rely on and will set during build:
 *  - Course "Access Expiration" OFF (the portal's access windows own expiry), and in particular
 *    its optional "delete course data after expiration" toggle stays OFF.
 *  - Assignments: enabled on worksheet lessons, approval by the coach (group leader).
 */
final class WWP_LMS_LearnDash implements WWP_LMS_Adapter {
	public function name(): string {
		return 'LearnDash';
	}
	public function grant( int $user_id, int $course_id ): void {
		ld_update_course_access( $user_id, $course_id, false );
	}
	public function revoke( int $user_id, int $course_id ): void {
		ld_update_course_access( $user_id, $course_id, true ); // access list only; progress meta untouched
	}
	public function has_access( int $user_id, int $course_id ): bool {
		return (bool) sfwd_lms_has_access( $course_id, $user_id );
	}
	public function progress( int $user_id, int $course_id ): array {
		$p = learndash_course_progress( array( 'user_id' => $user_id, 'course_id' => $course_id, 'array' => true ) );
		return array(
			'title'      => get_the_title( $course_id ),
			'completed'  => (int) ( $p['completed'] ?? 0 ),
			'total'      => (int) ( $p['total'] ?? 0 ),
			'percent'    => (int) ( $p['percentage'] ?? 0 ),
			'next_url'   => get_permalink( $course_id ),
			'next_title' => 'Continue course',
		);
	}
	public function assignments( int $user_id, int $course_id ): array {
		$q = get_posts( array(
			'post_type'   => 'sfwd-assignment',
			'author'      => $user_id,
			'numberposts' => 50,
			'meta_query'  => array( array( 'key' => 'course_id', 'value' => $course_id ) ),
		) );
		$approved = 0;
		foreach ( $q as $a ) {
			$approved += (int) get_post_meta( $a->ID, 'approval_status', true ) === 1 ? 1 : 0;
		}
		return array( 'pending' => count( $q ) - $approved, 'approved' => $approved, 'last_feedback' => '' );
	}
}

/**
 * Stand-in adapter for the demo: same contract, tiny storage. Lets the access engine,
 * dashboard and tests run end to end without a LearnDash licence.
 */
final class WWP_LMS_Demo implements WWP_LMS_Adapter {
	public function name(): string {
		return 'Demo LMS (LearnDash stand-in)';
	}
	public static function courses(): array {
		return get_option( 'wwp_demo_courses', array() );
	}
	public function grant( int $user_id, int $course_id ): void {
		global $wpdb;
		$t = WWP_Schema::t( 'demo_enrol' );
		$wpdb->query( $wpdb->prepare( "REPLACE INTO $t (user_id, course_id, active) VALUES (%d, %d, 1)", $user_id, $course_id ) );
	}
	public function revoke( int $user_id, int $course_id ): void {
		global $wpdb;
		$wpdb->update( WWP_Schema::t( 'demo_enrol' ), array( 'active' => 0 ), array( 'user_id' => $user_id, 'course_id' => $course_id ) );
	}
	public function has_access( int $user_id, int $course_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT active FROM ' . WWP_Schema::t( 'demo_enrol' ) . ' WHERE user_id = %d AND course_id = %d', $user_id, $course_id ) );
	}
	public static function complete( int $user_id, int $course_id, int $lesson_index ): void {
		$done                  = (array) get_user_meta( $user_id, "wwp_demo_done_$course_id", true );
		$done[ $lesson_index ] = 1;
		update_user_meta( $user_id, "wwp_demo_done_$course_id", array_filter( $done ) );
	}
	public static function submit( int $user_id, int $course_id, string $title, string $status = 'pending', string $feedback = '' ): void {
		$subs   = (array) get_user_meta( $user_id, "wwp_demo_subs_$course_id", true );
		$subs[] = compact( 'title', 'status', 'feedback' );
		update_user_meta( $user_id, "wwp_demo_subs_$course_id", array_values( array_filter( $subs ) ) );
	}
	public function progress( int $user_id, int $course_id ): array {
		$c       = self::courses()[ $course_id ] ?? array( 'title' => 'Course', 'lessons' => array(), 'urls' => array() );
		$done    = array_keys( array_filter( (array) get_user_meta( $user_id, "wwp_demo_done_$course_id", true ) ) );
		$total   = count( $c['lessons'] );
		$next    = 0;
		while ( $next < $total && in_array( $next, $done, true ) ) {
			++$next;
		}
		return array(
			'title'      => $c['title'],
			'completed'  => count( $done ),
			'total'      => $total,
			'percent'    => $total ? (int) round( 100 * count( $done ) / $total ) : 0,
			'next_url'   => $c['urls'][ $next ] ?? home_url( '/' ),
			'next_title' => $c['lessons'][ $next ] ?? 'Course complete',
		);
	}
	public function assignments( int $user_id, int $course_id ): array {
		$subs = array_filter( (array) get_user_meta( $user_id, "wwp_demo_subs_$course_id", true ) );
		$p    = count( array_filter( $subs, static fn( $s ) => 'pending' === $s['status'] ) );
		$last = '';
		foreach ( array_reverse( $subs ) as $s ) {
			if ( '' !== $s['feedback'] ) {
				$last = $s['feedback'];
				break;
			}
		}
		return array( 'pending' => $p, 'approved' => count( $subs ) - $p, 'last_feedback' => $last );
	}
}
