<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'AWM_CAPABILITY' ) ) {
	define( 'AWM_CAPABILITY', 'manage_whatsapp_tracker' );
}

/**
 * Keep the plugin's management permission separate from full WordPress admin
 * access so site owners can delegate WhatsApp operations safely.
 */
function awm_current_user_can_manage() {
	return current_user_can( AWM_CAPABILITY );
}

function awm_manager_roles() {
	$roles = get_option( 'awm_manager_roles', array( 'administrator' ) );
	$roles = is_array( $roles ) ? array_values( array_unique( array_map( 'sanitize_key', $roles ) ) ) : array();
	if ( ! in_array( 'administrator', $roles, true ) ) {
		$roles[] = 'administrator';
	}
	return $roles;
}

function awm_sanitize_manager_roles( $roles ) {
	$roles = is_array( $roles ) ? $roles : array();
	$wp_roles = wp_roles();
	$valid = array();
	foreach ( $roles as $role ) {
		$role = sanitize_key( $role );
		if ( isset( $wp_roles->roles[ $role ] ) ) {
			$valid[] = $role;
		}
	}
	$valid = array_values( array_unique( $valid ) );
	if ( ! in_array( 'administrator', $valid, true ) ) {
		$valid[] = 'administrator';
	}
	return $valid;
}

function awm_sync_manager_capabilities( $roles = null ) {
	$roles = is_array( $roles ) ? awm_sanitize_manager_roles( $roles ) : awm_manager_roles();
	$wp_roles = wp_roles();
	foreach ( array_keys( $wp_roles->roles ) as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		if ( in_array( $role_name, $roles, true ) ) {
			$role->add_cap( AWM_CAPABILITY );
		} else {
			$role->remove_cap( AWM_CAPABILITY );
		}
	}
}

function awm_activate_capabilities() {
	if ( false === get_option( 'awm_manager_roles', false ) ) {
		add_option( 'awm_manager_roles', array( 'administrator' ) );
	}
	awm_sync_manager_capabilities();
}

add_action( 'admin_init', 'awm_ensure_capabilities' );
function awm_ensure_capabilities() {
	$administrator = get_role( 'administrator' );
	if ( $administrator && ! $administrator->has_cap( AWM_CAPABILITY ) ) {
		awm_sync_manager_capabilities();
	}
}

add_action( 'update_option_awm_manager_roles', 'awm_manager_roles_updated', 10, 2 );
function awm_manager_roles_updated( $old_value, $new_value ) {
	awm_sync_manager_capabilities( $new_value );
}
