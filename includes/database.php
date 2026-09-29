<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 * Database helpers
 * ---------------------------------------------------------------------- */

function awm_clicks_table() {
	global $wpdb;
	return $wpdb->prefix . 'awm_wa_clicks_daily';
}

function awm_inventory_table() {
	global $wpdb;
	return $wpdb->prefix . 'awm_wa_inventory';
}

function awm_legacy_table() {
	global $wpdb;
	return $wpdb->prefix . 'click_tracker_clicks';
}

function awm_migration_runs_table() {
	global $wpdb;
	return $wpdb->prefix . 'awm_wa_migration_runs';
}

function awm_migration_items_table() {
	global $wpdb;
	return $wpdb->prefix . 'awm_wa_migration_items';
}

function awm_audit_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'awm_wa_audit_log';
}

function awm_table_exists( $table_name ) {
	global $wpdb;
	$like = $wpdb->esc_like( $table_name );
	return $table_name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
}

register_activation_hook( AWM_PLUGIN_FILE, 'awm_activate' );
function awm_activate() {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$clicks_table    = awm_clicks_table();
	$inventory_table = awm_inventory_table();
	$runs_table      = awm_migration_runs_table();
	$items_table     = awm_migration_items_table();
	$audit_table     = awm_audit_log_table();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql_clicks = "CREATE TABLE {$clicks_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		event_key char(64) NOT NULL,
		click_date date NOT NULL,
		page_path text NOT NULL,
		destination varchar(191) NOT NULL,
		resolved_destination varchar(191) NOT NULL DEFAULT '',
		link_type varchar(32) NOT NULL,
		source_label varchar(191) NOT NULL DEFAULT '',
		link_text varchar(191) NOT NULL DEFAULT '',
		clicks bigint(20) unsigned NOT NULL DEFAULT 1,
		first_click datetime NOT NULL,
		last_click datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY event_key (event_key),
		KEY click_date (click_date),
		KEY destination (destination),
		KEY resolved_destination (resolved_destination),
		KEY link_type (link_type),
		KEY source_label (source_label)
	) {$charset_collate};";

	$sql_inventory = "CREATE TABLE {$inventory_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		item_key char(64) NOT NULL,
		scan_id char(36) NOT NULL,
		scan_mode varchar(16) NOT NULL,
		object_id bigint(20) unsigned NOT NULL DEFAULT 0,
		page_title text NOT NULL,
		page_url text NOT NULL,
		link_url text NOT NULL,
		destination varchar(191) NOT NULL,
		resolved_destination varchar(191) NOT NULL DEFAULT '',
		link_type varchar(32) NOT NULL,
		source_label varchar(191) NOT NULL DEFAULT '',
		link_text varchar(191) NOT NULL DEFAULT '',
		status varchar(32) NOT NULL DEFAULT 'unknown',
		last_seen datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY item_key (item_key),
		KEY scan_id (scan_id),
		KEY destination (destination),
		KEY resolved_destination (resolved_destination),
		KEY status (status),
		KEY link_type (link_type),
		KEY source_label (source_label)
	) {$charset_collate};";

	$sql_runs = "CREATE TABLE {$runs_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		run_id char(36) NOT NULL,
		route_slug varchar(191) NOT NULL,
		match_type varchar(32) NOT NULL,
		match_value text NOT NULL,
		page_scope text NOT NULL,
		language_mode varchar(8) NOT NULL DEFAULT 'auto',
		status varchar(32) NOT NULL DEFAULT 'previewing',
		total_posts bigint(20) unsigned NOT NULL DEFAULT 0,
		processed_posts bigint(20) unsigned NOT NULL DEFAULT 0,
		total_items bigint(20) unsigned NOT NULL DEFAULT 0,
		total_occurrences bigint(20) unsigned NOT NULL DEFAULT 0,
		applied_items bigint(20) unsigned NOT NULL DEFAULT 0,
		rolled_back_items bigint(20) unsigned NOT NULL DEFAULT 0,
		conflict_items bigint(20) unsigned NOT NULL DEFAULT 0,
		created_by bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		last_error text NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY run_id (run_id),
		KEY route_slug (route_slug),
		KEY status (status),
		KEY created_at (created_at)
	) {$charset_collate};";

	$sql_items = "CREATE TABLE {$items_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		item_key char(64) NOT NULL,
		run_id char(36) NOT NULL,
		post_id bigint(20) unsigned NOT NULL DEFAULT 0,
		storage_type varchar(32) NOT NULL,
		meta_key varchar(191) NOT NULL DEFAULT '',
		old_value longtext NOT NULL,
		new_value longtext NOT NULL,
		old_hash char(64) NOT NULL,
		new_hash char(64) NOT NULL,
		occurrences bigint(20) unsigned NOT NULL DEFAULT 0,
		status varchar(32) NOT NULL DEFAULT 'planned',
		error_message text NOT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY item_key (item_key),
		KEY run_status (run_id, status),
		KEY post_id (post_id)
	) {$charset_collate};";


	$sql_audit = "CREATE TABLE {$audit_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		action varchar(64) NOT NULL,
		object_type varchar(32) NOT NULL DEFAULT '',
		object_key varchar(191) NOT NULL DEFAULT '',
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		context longtext NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY action (action),
		KEY object_type (object_type),
		KEY user_id (user_id),
		KEY created_at (created_at)
	) {$charset_collate};";

	dbDelta( $sql_clicks );
	dbDelta( $sql_inventory );
	dbDelta( $sql_runs );
	dbDelta( $sql_items );
	dbDelta( $sql_audit );

	add_option( 'awm_tracking_enabled', '1' );
	add_option( 'awm_retention_days', '365' );
	add_option( 'awm_approved_numbers', array() );
	add_option( 'awm_shortlink_resolutions', array(), '', false );
	add_option( 'awm_emergency_rules', array(), '', false );
	add_option( 'awm_whatsapp_popup_enabled', '1' );
	add_option( 'awm_telephone_popup_enabled', '1' );
	add_option( 'awm_managed_routes', array(), '', false );
	add_option( 'awm_audit_retention_days', '365' );
	add_option( 'awm_delete_data_on_uninstall', '0' );
	add_option( 'awm_live_chat_provider', 'none' );
	add_option( 'awm_manager_roles', array( 'administrator' ) );
	add_option( 'awm_scheduled_scan_frequency', 'off' );
	add_option( 'awm_scheduled_scan_status', array(), '', false );
	update_option( 'awm_db_version', AWM_DB_VERSION );
	update_option( 'awm_rewrite_version', AWM_VERSION, false );
	awm_activate_capabilities();
	awm_register_managed_route_rewrite();
	flush_rewrite_rules( false );

	if ( ! wp_next_scheduled( 'awm_daily_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'awm_daily_cleanup' );
	}
	awm_reschedule_recent_scan();
}

register_deactivation_hook( AWM_PLUGIN_FILE, 'awm_deactivate' );
function awm_deactivate() {
	$timestamp = wp_next_scheduled( 'awm_daily_cleanup' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'awm_daily_cleanup' );
	}
	wp_clear_scheduled_hook( 'awm_scheduled_recent_scan' );
}

add_action( 'plugins_loaded', 'awm_maybe_upgrade' );
function awm_maybe_upgrade() {
	if ( get_option( 'awm_db_version' ) !== AWM_DB_VERSION ) {
		awm_activate();
	}
}
