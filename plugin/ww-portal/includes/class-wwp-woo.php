<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce + WooCommerce Subscriptions glue. Thin on purpose: every hook calls the access
 * engine, which is what the tests exercise. WooCommerce is not installed in this demo, so these
 * hooks are not executed here; they are covered on the staging site with Payfast/PayPal sandbox.
 *
 * Product setup (admin, no code): product meta _wwp_programme = "money-reset".
 * Optional checkout field or admin-set order meta _wwp_programme_start = YYYY-MM-DD.
 */
final class WWP_Woo {

	const GRACE_DAYS = 3; // a late debit order should not lock someone out mid-lesson

	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_completed' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_completed' ) ); // virtual, non-downloadable products stay in processing
		add_action( 'woocommerce_subscription_renewal_payment_complete', array( __CLASS__, 'on_renewal' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_updated', array( __CLASS__, 'on_sub_status' ), 10, 3 );
	}

	public static function on_order_completed( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_user_id() || $order->get_meta( '_wwp_granted' ) ) {
			return; // guest orders are linked on account creation; never grant twice
		}
		foreach ( $order->get_items() as $item ) {
			$prog = get_post_meta( $item->get_product_id(), '_wwp_programme', true );
			if ( ! $prog || function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) {
				continue; // subscriptions handled below
			}
			$start = $order->get_meta( '_wwp_programme_start' );
			if ( $start ) {
				WWP_Access::grant( $order->get_user_id(), $prog, $start, null, 'order:' . $order_id );
			} else {
				update_user_meta( $order->get_user_id(), "wwp_pending_start_$prog", $order_id ); // shows in Client access
			}
		}
		$order->update_meta_data( '_wwp_granted', 1 );
		$order->save();
	}

	private static function paid_until( $sub ): ?string {
		$next = $sub->get_date( 'next_payment' ) ?: $sub->get_date( 'end' );
		return $next ? WWP_Access::add_days( substr( $next, 0, 10 ), self::GRACE_DAYS ) : null;
	}

	public static function on_renewal( $sub ): void {
		self::extend_from_sub( $sub, 'subscription:' . $sub->get_id() );
	}

	public static function on_sub_status( $sub, string $new, string $old ): void {
		if ( 'active' === $new ) {
			self::extend_from_sub( $sub, 'subscription:' . $sub->get_id() . ':reactivated' );
		}
		// cancelled / on-hold / expired: do nothing. Access already ends at the paid-through date,
		// the daily sweep locks the course, and no data is touched.
	}

	private static function extend_from_sub( $sub, string $source ): void {
		$until = self::paid_until( $sub );
		if ( ! $until ) {
			return;
		}
		foreach ( $sub->get_items() as $item ) {
			$prog = get_post_meta( $item->get_product_id(), '_wwp_programme', true );
			if ( $prog ) {
				WWP_Access::extend_to( $sub->get_user_id(), $prog, $until, $source );
			}
		}
	}
}
