<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

function awm_uninstall_current_site_data() {
	global $wpdb;
	// Capabilities are code permissions, not retained site data.
	foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) { $role->remove_cap( 'manage_whatsapp_tracker' ); }
	}
	wp_clear_scheduled_hook( 'awm_scheduled_recent_scan' );

	if ( '1' !== (string) get_option( 'awm_delete_data_on_uninstall', '0' ) ) {
		return;
	}

	$tables = array(
		$wpdb->prefix . 'awm_wa_clicks_daily',
		$wpdb->prefix . 'awm_wa_inventory',
		$wpdb->prefix . 'awm_wa_migration_runs',
		$wpdb->prefix . 'awm_wa_migration_items',
		$wpdb->prefix . 'awm_wa_audit_log',
	);
	foreach ( $tables as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' );
	}

	$options = array(
		'awm_tracking_enabled', 'awm_retention_days', 'awm_approved_numbers', 'awm_shortlink_resolutions',
		'awm_emergency_rules', 'awm_whatsapp_popup_enabled', 'awm_telephone_popup_enabled', 'awm_managed_routes', 'awm_db_version', 'awm_rewrite_version', 'awm_latest_scan',
		'awm_inventory_checkpoint_gmt', 'awm_scan_running', 'awm_audit_retention_days', 'awm_delete_data_on_uninstall', 'awm_live_chat_provider', 'awm_manager_roles', 'awm_scheduled_scan_frequency', 'awm_scheduled_scan_status',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove only dynamic options/transients owned by this plugin namespace.
	$like_locks = $wpdb->esc_like( 'awm_lock_' ) . '%';
	$like_rl    = $wpdb->esc_like( '_transient_awm_rl_' ) . '%';
	$like_rlt   = $wpdb->esc_like( '_transient_timeout_awm_rl_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $like_locks, $like_rl, $like_rlt ) );
	wp_clear_scheduled_hook( 'awm_daily_cleanup' );
}

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		awm_uninstall_current_site_data();
		restore_current_blog();
	}
} else {
	awm_uninstall_current_site_data();
}
