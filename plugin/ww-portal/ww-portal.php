<?php
/**
 * Plugin Name: Client Portal Core (ww-portal)
 * Description: Small custom layer on top of LearnDash + WooCommerce: encrypted finance snapshots, programme access windows, assigned resources, coach audit, POPIA export/erase, client dashboard.
 * Version: 0.9.0
 * Requires PHP: 8.0
 * Requires at least: 6.5
 * License: GPL-2.0-or-later
 *
 * Design rules:
 *  1. Plugins own what they already do well (courses, lessons, quizzes, assignments, checkout, subscriptions).
 *  2. This plugin owns only what no plugin does: dated finance snapshots, programme-start access windows,
 *     per-client resources and one dashboard that reads all of it.
 *  3. Nothing in here deletes learning or finance data on expiry. The only delete path is the
 *     WordPress privacy eraser, run by an administrator, and it is audited.
 */

defined( 'ABSPATH' ) || exit;

define( 'WWP_VERSION', '0.9.0' );
define( 'WWP_DIR', __DIR__ );
define( 'WWP_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-wwp-schema.php';
require_once __DIR__ . '/includes/class-wwp-crypto.php';
require_once __DIR__ . '/includes/class-wwp-money.php';
require_once __DIR__ . '/includes/class-wwp-audit.php';
require_once __DIR__ . '/includes/class-wwp-finance.php';
require_once __DIR__ . '/includes/class-wwp-lms.php';
require_once __DIR__ . '/includes/class-wwp-access.php';
require_once __DIR__ . '/includes/class-wwp-resources.php';
require_once __DIR__ . '/includes/class-wwp-rest.php';
require_once __DIR__ . '/includes/class-wwp-privacy.php';
require_once __DIR__ . '/includes/class-wwp-dashboard.php';
require_once __DIR__ . '/includes/class-wwp-admin.php';
require_once __DIR__ . '/includes/class-wwp-woo.php';

register_activation_hook( __FILE__, array( 'WWP_Schema', 'install' ) );

add_action( 'plugins_loaded', static function () {
	if ( get_option( 'wwp_db_version' ) !== WWP_Schema::DB_VERSION ) {
		WWP_Schema::install();
	}
	WWP_Resources::init();
	WWP_Rest::init();
	WWP_Privacy::init();
	WWP_Dashboard::init();
	WWP_Admin::init();
	WWP_Woo::init();
	WWP_Access::init();
} );

/** The LMS adapter in use. LearnDash when it is active, otherwise the demo adapter. */
function wwp_lms(): WWP_LMS_Adapter {
	static $lms = null;
	if ( null === $lms ) {
		$lms = function_exists( 'ld_update_course_access' ) ? new WWP_LMS_LearnDash() : new WWP_LMS_Demo();
		$lms = apply_filters( 'wwp_lms_adapter', $lms );
	}
	return $lms;
}

/** Today's date in the site timezone (Africa/Johannesburg), overridable in tests. */
function wwp_today(): string {
	$forced = apply_filters( 'wwp_today', null );
	return $forced ?: wp_date( 'Y-m-d' );
}
