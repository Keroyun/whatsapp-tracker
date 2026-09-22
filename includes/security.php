<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small shared security primitives. Kept procedural to preserve compatibility
 * with the existing public awm_* function surface while avoiding duplicate logic.
 */
function awm_constant_time_id( $value ) {
	return hash_hmac( 'sha256', (string) $value, wp_salt( 'nonce' ) );
}

function awm_acquire_lock( $name, $ttl = 30 ) {
	$name = sanitize_key( (string) $name );
	$ttl  = max( 5, min( 300, absint( $ttl ) ) );
	if ( ! $name ) {
		return new WP_Error( 'awm_invalid_lock', 'Invalid operation lock.' );
	}

	$key     = 'awm_lock_' . $name;
	$token   = wp_generate_uuid4();
	$payload = array( 'token' => $token, 'expires' => time() + $ttl );

	if ( add_option( $key, $payload, '', false ) ) {
		return $token;
	}

	$existing = get_option( $key, array() );
	$expires  = is_array( $existing ) ? absint( $existing['expires'] ?? 0 ) : 0;
	if ( ! $expires || $expires < time() ) {
		delete_option( $key );
		if ( add_option( $key, $payload, '', false ) ) {
			return $token;
		}
	}

	return new WP_Error( 'awm_locked', 'Another request is already processing this operation.' );
}

function awm_release_lock( $name, $token ) {
	$name = sanitize_key( (string) $name );
	if ( ! $name || ! $token ) {
		return;
	}
	$key      = 'awm_lock_' . $name;
	$existing = get_option( $key, array() );
	if ( is_array( $existing ) && ! empty( $existing['token'] ) && hash_equals( (string) $existing['token'], (string) $token ) ) {
		delete_option( $key );
	}
}

function awm_url_origin_tuple( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return array();
	}
	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	$host   = strtolower( rtrim( (string) $parts['host'], '.' ) );
	$port   = isset( $parts['port'] ) ? absint( $parts['port'] ) : ( 'https' === $scheme ? 443 : ( 'http' === $scheme ? 80 : 0 ) );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! $host || ! $port ) {
		return array();
	}
	return array( $scheme, $host, $port );
}

function awm_url_is_same_origin_as_site( $url ) {
	$target = awm_url_origin_tuple( $url );
	if ( ! $target ) {
		return false;
	}
	foreach ( array( home_url( '/' ), site_url( '/' ) ) as $site_url ) {
		$site = awm_url_origin_tuple( $site_url );
		if ( $site && hash_equals( implode( '|', $site ), implode( '|', $target ) ) ) {
			return true;
		}
	}
	return false;
}
