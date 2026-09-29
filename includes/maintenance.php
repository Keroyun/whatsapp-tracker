<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'awm_reschedule_recent_scan', 'awm_reschedule_recent_scan' );
add_action( 'update_option_awm_scheduled_scan_frequency', 'awm_scan_frequency_changed', 10, 2 );

function awm_scan_frequency_changed( $old_value, $new_value ) {
	awm_reschedule_recent_scan();
}

function awm_reschedule_recent_scan() {
	wp_clear_scheduled_hook( 'awm_scheduled_recent_scan' );
	$frequency = sanitize_key( (string) get_option( 'awm_scheduled_scan_frequency', 'off' ) );
	if ( in_array( $frequency, array( 'daily', 'weekly' ), true ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, $frequency, 'awm_scheduled_recent_scan' );
	}
}

add_action( 'awm_scheduled_recent_scan', 'awm_run_scheduled_recent_scan' );
function awm_run_scheduled_recent_scan() {
	if ( get_option( 'awm_scan_running', array() ) ) {
		update_option( 'awm_scheduled_scan_status', array(
			'status' => 'skipped',
			'message' => 'Skipped because another inventory scan was running.',
			'completed_at' => current_time( 'mysql' ),
		), false );
		return;
	}

	$latest = get_option( 'awm_latest_scan', array() );
	$from_gmt = get_option( 'awm_inventory_checkpoint_gmt', '' );
	if ( empty( $latest['scan_id'] ) || ! $from_gmt ) {
		update_option( 'awm_scheduled_scan_status', array(
			'status' => 'needs_base_scan',
			'message' => 'Run a full Quick or Deep Scan once before scheduled Recent Changes scans can run.',
			'completed_at' => current_time( 'mysql' ),
		), false );
		return;
	}

	require_once AWM_PLUGIN_DIR . 'admin/scanner.php';
	$to_gmt = current_time( 'mysql', true );
	$total = awm_recent_changes_total( $from_gmt, $to_gmt );
	if ( $total > 100 ) {
		update_option( 'awm_scheduled_scan_status', array(
			'status' => 'manual_required',
			'message' => 'More than 100 content changes are pending. Run Recent Changes manually from Link Inventory.',
			'pending' => $total,
			'completed_at' => current_time( 'mysql' ),
		), false );
		return;
	}

	global $wpdb;
	$snapshot_scan_id = sanitize_text_field( (string) $latest['scan_id'] );
	$posts = awm_get_recent_posts_batch( $from_gmt, $to_gmt, 0, 100 );
	$found = 0;
	foreach ( $posts as $post ) {
		$wpdb->delete(
			awm_inventory_table(),
			array( 'scan_id' => $snapshot_scan_id, 'object_id' => absint( $post->ID ) ),
			array( '%s', '%d' )
		);
		if ( 'publish' === $post->post_status ) {
			$found += awm_scan_post_quick( $post, $snapshot_scan_id, 'recent' );
		}
	}

	$summary = awm_rebuild_inventory_summary( $snapshot_scan_id, 'recent', count( $posts ) );
	update_option( 'awm_latest_scan', $summary, false );
	update_option( 'awm_inventory_checkpoint_gmt', $to_gmt, false );
	update_option( 'awm_scheduled_scan_status', array(
		'status' => 'ok',
		'message' => 'Scheduled Recent Changes scan completed.',
		'processed' => count( $posts ),
		'links_found' => $found,
		'completed_at' => current_time( 'mysql' ),
	), false );
}

function awm_health_summary() {
	global $wpdb;
	$latest = get_option( 'awm_latest_scan', array() );
	$scan_status = get_option( 'awm_scheduled_scan_status', array() );
	$routes = awm_get_managed_routes();
	$backup_routes = 0;
	foreach ( $routes as $route ) {
		if ( is_array( $route ) && 'backup' === ( $route['active_line'] ?? 'primary' ) && '1' === (string) ( $route['enabled'] ?? '0' ) ) {
			$backup_routes++;
		}
	}
	$unknown = 0;
	$shortlinks = 0;
	if ( ! empty( $latest['scan_id'] ) && awm_table_exists( awm_inventory_table() ) ) {
		$unknown = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . awm_inventory_table() . " WHERE scan_id = %s AND status = 'unknown'", $latest['scan_id'] ) );
		$shortlinks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . awm_inventory_table() . " WHERE scan_id = %s AND status = 'shortlink'", $latest['scan_id'] ) );
	}
	return array(
		'tracking' => '1' === get_option( 'awm_tracking_enabled', '1' ),
		'approved_numbers' => count( awm_get_approved_numbers() ),
		'active_notices' => count( awm_get_active_emergency_rules() ),
		'unknown_links' => $unknown,
		'unresolved_shortlinks' => $shortlinks,
		'backup_routes' => $backup_routes,
		'last_scan' => $latest['completed_at'] ?? '',
		'scheduled_frequency' => get_option( 'awm_scheduled_scan_frequency', 'off' ),
		'scheduled_status' => $scan_status,
	);
}
