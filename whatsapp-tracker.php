<?php
/**
 * Plugin Name: WhatsApp Tracker
 * Description: Central WhatsApp routes, safe bulk migration, WhatsApp and telephone service notices, click analytics, source attribution, link inventory scanning, wa.link resolution and approved-number governance.
 * Version: 3.4.0
 * Author: Azhar
 * Author URI: https://github.com/Keroyun
 * Plugin URI: https://khairulazhar.com
 * Text Domain: whatsapp-tracker
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AWM_VERSION', '3.4.0' );
define( 'AWM_DB_VERSION', '3.2.0' );
define( 'AWM_MENU_SLUG', 'awm-dashboard' );
define( 'AWM_PLUGIN_FILE', __FILE__ );
define( 'AWM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AWM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action( 'init', function () {
	load_plugin_textdomain( 'whatsapp-tracker', false, dirname( plugin_basename( AWM_PLUGIN_FILE ) ) . '/languages' );
} );

/* Scanner safety limits — deliberately conservative for production. */
define( 'AWM_QUICK_SCAN_BATCH', 5 );
define( 'AWM_DEEP_SCAN_BATCH', 1 );
define( 'AWM_SCAN_DELAY_MS', 650 );
define( 'AWM_DEEP_SCAN_TIMEOUT', 6 );
define( 'AWM_DEEP_SCAN_MAX_BYTES', 5 * MB_IN_BYTES );
define( 'AWM_SCAN_LOCK_TTL', 30 * MINUTE_IN_SECONDS );
define( 'AWM_MIGRATION_PREVIEW_BATCH', 5 );
define( 'AWM_MIGRATION_WRITE_BATCH', 10 );
define( 'AWM_MIGRATION_LOCK_TTL', 45 );
define( 'AWM_MIGRATION_MAX_FIELD_BYTES', 10 * MB_IN_BYTES );

/* Manual wa.link resolver safety limits. */
define( 'AWM_SHORTLINK_RESOLVE_TIMEOUT', 5 );
define( 'AWM_SHORTLINK_RESOLVE_MAX_HOPS', 5 );
define( 'AWM_SHORTLINK_RESOLVE_MAX_BYTES', 128 * KB_IN_BYTES );
define( 'AWM_SHORTLINK_RESOLVE_BATCH_LIMIT', 100 );

require_once AWM_PLUGIN_DIR . 'includes/security.php';
require_once AWM_PLUGIN_DIR . 'includes/database.php';
require_once AWM_PLUGIN_DIR . 'includes/audit.php';
require_once AWM_PLUGIN_DIR . 'includes/whatsapp.php';
require_once AWM_PLUGIN_DIR . 'includes/emergency.php';
require_once AWM_PLUGIN_DIR . 'includes/notice-form.php';
require_once AWM_PLUGIN_DIR . 'includes/frontend.php';

if ( is_admin() ) {
	require_once AWM_PLUGIN_DIR . 'admin/admin-core.php';
	require_once AWM_PLUGIN_DIR . 'admin/scanner.php';
	require_once AWM_PLUGIN_DIR . 'admin/generator.php';
	require_once AWM_PLUGIN_DIR . 'admin/migration.php';
	require_once AWM_PLUGIN_DIR . 'admin/routes.php';
	require_once AWM_PLUGIN_DIR . 'admin/emergency.php';
	require_once AWM_PLUGIN_DIR . 'admin/settings.php';
	require_once AWM_PLUGIN_DIR . 'admin/audit.php';
}
