<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function awm_audit_normalize_context( $value, $depth = 0 ) {
	if ( $depth > 4 ) {
		return '[max-depth]';
	}
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( array_slice( $value, 0, 50, true ) as $key => $item ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) { continue; }
			$out[ $key ] = awm_audit_normalize_context( $item, $depth + 1 );
		}
		return $out;
	}
	if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_null( $value ) ) {
		return $value;
	}
	if ( is_scalar( $value ) ) {
		return sanitize_text_field( substr( (string) $value, 0, 500 ) );
	}
	return '[unsupported]';
}

function awm_audit_log( $action, $object_type = '', $object_key = '', $context = array() ) {
	global $wpdb;
	$table = awm_audit_log_table();
	if ( ! awm_table_exists( $table ) ) {
		return false;
	}
	$action      = substr( sanitize_key( (string) $action ), 0, 64 );
	$object_type = substr( sanitize_key( (string) $object_type ), 0, 32 );
	$object_key  = sanitize_text_field( substr( (string) $object_key, 0, 191 ) );
	if ( ! $action ) {
		return false;
	}
	$encoded = wp_json_encode( awm_audit_normalize_context( is_array( $context ) ? $context : array( 'value' => $context ) ) );
	if ( ! is_string( $encoded ) || strlen( $encoded ) > 20000 ) {
		$encoded = '{}';
	}
	return false !== $wpdb->insert(
		$table,
		array(
			'action'      => $action,
			'object_type' => $object_type,
			'object_key'  => $object_key,
			'user_id'     => get_current_user_id(),
			'context'     => $encoded,
			'created_at'  => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s', '%d', '%s', '%s' )
	);
}

add_action( 'awm_daily_cleanup', 'awm_audit_daily_cleanup', 20 );
function awm_audit_daily_cleanup() {
	global $wpdb;
	$table = awm_audit_log_table();
	if ( ! awm_table_exists( $table ) ) { return; }
	$days   = max( 30, min( 3650, absint( get_option( 'awm_audit_retention_days', 365 ) ) ) );
	$cutoff = wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ), wp_timezone() );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
}

function awm_audit_setting_change( $option, $old_value, $new_value ) {
	$watched = array( 'awm_tracking_enabled', 'awm_whatsapp_popup_enabled', 'awm_telephone_popup_enabled', 'awm_retention_days', 'awm_audit_retention_days', 'awm_delete_data_on_uninstall', 'awm_approved_numbers' );
	if ( ! in_array( $option, $watched, true ) || ! is_admin() || ! get_current_user_id() || $old_value === $new_value ) { return; }
	$context = array();
	if ( 'awm_approved_numbers' === $option ) {
		$context = array( 'old_count' => is_array( $old_value ) ? count( $old_value ) : 0, 'new_count' => is_array( $new_value ) ? count( $new_value ) : 0 );
	} else {
		$context = array( 'old' => $old_value, 'new' => $new_value );
	}
	awm_audit_log( 'settings_updated', 'setting', $option, $context );
}

add_action( 'updated_option', 'awm_audit_setting_change', 10, 3 );
