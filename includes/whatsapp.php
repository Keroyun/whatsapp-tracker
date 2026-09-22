<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 * Shared WhatsApp URL parsing / validation
 * ---------------------------------------------------------------------- */

function awm_supported_whatsapp_hosts() {
	return array(
		'wa.me',
		'api.whatsapp.com',
		'web.whatsapp.com',
		'wa.link',
	);
}

function awm_normalize_phone( $value ) {
	$digits = preg_replace( '/\D+/', '', (string) $value );
	$digits = substr( (string) $digits, 0, 20 );
	$length = strlen( $digits );
	return ( $length >= 7 && $length <= 15 ) ? $digits : '';
}

function awm_parse_whatsapp_url( $url ) {
	$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$url = str_replace( '\\/', '/', $url );

	if ( '' === $url ) {
		return false;
	}
	if ( 0 === strpos( $url, '/go/whatsapp/' ) ) {
		$url = home_url( $url );
	}

	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return false;
	}

	$host = strtolower( $parts['host'] );
	$managed = awm_parse_managed_route_url( $url, $parts );
	if ( $managed ) {
		return $managed;
	}
	if ( ! in_array( $host, awm_supported_whatsapp_hosts(), true ) ) {
		return false;
	}

	$destination = '';
	$link_type   = $host;
	$path        = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
	$query       = array();

	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}

	if ( 'wa.me' === $host ) {
		$first_segment = strtok( $path, '/' );
		$phone         = awm_normalize_phone( $first_segment );
		if ( $phone ) {
			$destination = $phone;
		}
	} elseif ( in_array( $host, array( 'api.whatsapp.com', 'web.whatsapp.com' ), true ) ) {
		if ( ! empty( $query['phone'] ) ) {
			$destination = awm_normalize_phone( $query['phone'] );
		}
	} elseif ( 'wa.link' === $host ) {
		$slug = strtok( $path, '/' );
		$slug = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $slug );
		if ( $slug ) {
			$destination = 'wa.link/' . $slug;
		}
	}

	return array(
		'url'         => esc_url_raw( $url ),
		'host'        => $host,
		'link_type'   => $link_type,
		'destination' => $destination,
		'is_shortlink'=> ( 'wa.link' === $host ),
		'is_valid'    => ( '' !== $destination ),
	);
}


/**
 * Cached wa.link resolution map. Resolution is admin-triggered only; normal
 * frontend traffic never performs outbound HTTP requests.
 */
function awm_get_shortlink_resolutions() {
	$map = get_option( 'awm_shortlink_resolutions', array() );
	return is_array( $map ) ? $map : array();
}

function awm_get_shortlink_resolution( $destination ) {
	$destination = sanitize_text_field( (string) $destination );
	if ( 0 !== strpos( $destination, 'wa.link/' ) ) {
		return array();
	}
	$map = awm_get_shortlink_resolutions();
	if ( empty( $map[ $destination ] ) || ! is_array( $map[ $destination ] ) ) {
		return array();
	}
	$entry = $map[ $destination ];
	$phone = isset( $entry['number'] ) ? awm_normalize_phone( $entry['number'] ) : '';
	if ( 'resolved' !== ( $entry['status'] ?? '' ) || ! $phone ) {
		return array();
	}
	$entry['number'] = $phone;
	return $entry;
}

function awm_effective_destination_from_parsed( $parsed ) {
	if ( ! is_array( $parsed ) || empty( $parsed['destination'] ) ) {
		return '';
	}
	if ( 'managed-route' === ( $parsed['link_type'] ?? '' ) && ! empty( $parsed['resolved_destination'] ) ) {
		return awm_normalize_phone( $parsed['resolved_destination'] );
	}
	if ( ! empty( $parsed['is_shortlink'] ) ) {
		$resolution = awm_get_shortlink_resolution( $parsed['destination'] );
		if ( ! empty( $resolution['number'] ) ) {
			return $resolution['number'];
		}
	}
	return (string) $parsed['destination'];
}

function awm_effective_destination_value( $destination, $resolved_destination = '' ) {
	$resolved_destination = awm_normalize_phone( $resolved_destination );
	return $resolved_destination ? $resolved_destination : (string) $destination;
}

function awm_resolve_source_for_parsed( $parsed, $requested_source = '' ) {
	$effective = awm_effective_destination_from_parsed( $parsed );
	if ( ! $effective ) {
		return '';
	}

	$requested_source = sanitize_text_field( substr( (string) $requested_source, 0, 191 ) );
	if ( '' !== $requested_source ) {
		$resolved_requested = awm_resolve_source_label( $effective, $requested_source );
		if ( '' !== $resolved_requested ) {
			return $resolved_requested;
		}
	}

	if ( is_array( $parsed ) && 'managed-route' === ( $parsed['link_type'] ?? '' ) && ! empty( $parsed['route_source'] ) ) {
		$route_source = sanitize_text_field( (string) $parsed['route_source'] );
		$resolved_route_source = awm_resolve_source_label( $effective, $route_source );
		return $resolved_route_source ? $resolved_route_source : $route_source;
	}

	if ( is_array( $parsed ) && ! empty( $parsed['is_shortlink'] ) && ! empty( $parsed['destination'] ) ) {
		$resolution = awm_get_shortlink_resolution( $parsed['destination'] );
		$mapped_source = isset( $resolution['source_label'] ) ? sanitize_text_field( (string) $resolution['source_label'] ) : '';
		if ( '' !== $mapped_source ) {
			$resolved_mapped = awm_resolve_source_label( $effective, $mapped_source );
			if ( '' !== $resolved_mapped ) {
				return $resolved_mapped;
			}
		}
	}

	return awm_resolve_source_label( $effective, '' );
}


function awm_shortlink_resolution_host_allowed( $host ) {
	$host = strtolower( trim( (string) $host ) );
	if ( in_array( $host, array( 'wa.link', 'wa.me' ), true ) ) {
		return true;
	}
	return (bool) preg_match( '/(^|\.)whatsapp\.com$/i', $host );
}

function awm_absolute_redirect_url( $location, $base_url ) {
	$location = trim( (string) $location );
	if ( '' === $location ) {
		return '';
	}
	if ( 0 === strpos( $location, '//' ) ) {
		$scheme = strtolower( (string) wp_parse_url( $base_url, PHP_URL_SCHEME ) );
		$scheme = in_array( $scheme, array( 'http', 'https' ), true ) ? $scheme : 'https';
		return esc_url_raw( $scheme . ':' . $location );
	}
	if ( wp_parse_url( $location, PHP_URL_SCHEME ) && wp_parse_url( $location, PHP_URL_HOST ) ) {
		return esc_url_raw( $location );
	}
	if ( class_exists( 'WP_Http' ) && method_exists( 'WP_Http', 'make_absolute_url' ) ) {
		return esc_url_raw( WP_Http::make_absolute_url( $location, $base_url ) );
	}
	return '';
}

function awm_extract_phone_from_resolution_url( $url ) {
	$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	if ( '' === $url ) {
		return '';
	}

	$parsed = awm_parse_whatsapp_url( $url );
	if ( $parsed && empty( $parsed['is_shortlink'] ) && ! empty( $parsed['destination'] ) ) {
		return awm_normalize_phone( $parsed['destination'] );
	}

	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! awm_shortlink_resolution_host_allowed( $parts['host'] ) ) {
		return '';
	}
	$query = array();
	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $query );
	}
	if ( ! empty( $query['phone'] ) ) {
		return awm_normalize_phone( $query['phone'] );
	}
	return '';
}

/**
 * Resolve one wa.link destination with bounded, allowlisted HTTP requests.
 * This function is only called from an authenticated admin AJAX action.
 */
function awm_resolve_shortlink_remote( $destination ) {
	$destination = sanitize_text_field( (string) $destination );
	$parsed = awm_parse_whatsapp_url( 'https://' . ltrim( $destination, '/' ) );
	if ( ! $parsed || empty( $parsed['is_shortlink'] ) || empty( $parsed['is_valid'] ) ) {
		return new WP_Error( 'awm_invalid_shortlink', 'Invalid wa.link shortlink.' );
	}

	$current = $parsed['url'];
	$last_host = 'wa.link';

	for ( $hop = 0; $hop <= AWM_SHORTLINK_RESOLVE_MAX_HOPS; $hop++ ) {
		$phone = awm_extract_phone_from_resolution_url( $current );
		if ( $phone ) {
			return array( 'number' => $phone, 'final_host' => strtolower( (string) wp_parse_url( $current, PHP_URL_HOST ) ) );
		}

		$host = strtolower( (string) wp_parse_url( $current, PHP_URL_HOST ) );
		if ( ! awm_shortlink_resolution_host_allowed( $host ) || 'https' !== strtolower( (string) wp_parse_url( $current, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'awm_unsafe_shortlink_redirect', 'Shortlink redirected outside approved HTTPS WhatsApp domains.' );
		}
		$last_host = $host;

		$response = wp_safe_remote_get(
			$current,
			array(
				'timeout'             => AWM_SHORTLINK_RESOLVE_TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => AWM_SHORTLINK_RESOLVE_MAX_BYTES,
				'user-agent'          => 'WhatsAppTrackerResolver/' . AWM_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'awm_shortlink_request_failed', sanitize_text_field( $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( $code >= 300 && $code < 400 && $location ) {
			$next = awm_absolute_redirect_url( is_array( $location ) ? reset( $location ) : $location, $current );
			if ( ! $next ) {
				return new WP_Error( 'awm_invalid_shortlink_redirect', 'Shortlink returned an invalid redirect.' );
			}
			$next_host = strtolower( (string) wp_parse_url( $next, PHP_URL_HOST ) );
			if ( ! awm_shortlink_resolution_host_allowed( $next_host ) || 'https' !== strtolower( (string) wp_parse_url( $next, PHP_URL_SCHEME ) ) ) {
				return new WP_Error( 'awm_unsafe_shortlink_redirect', 'Shortlink redirected outside approved HTTPS WhatsApp domains.' );
			}
			$current = $next;
			continue;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' !== $body ) {
			/* Prefer explicit WhatsApp URLs found in the bounded response body. */
			preg_match_all( '~https?://[^\s"\'<>]+~i', html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $urls );
			foreach ( isset( $urls[0] ) ? $urls[0] : array() as $candidate ) {
				$candidate_host = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
				if ( ! awm_shortlink_resolution_host_allowed( $candidate_host ) ) {
					continue;
				}
				$phone = awm_extract_phone_from_resolution_url( $candidate );
				if ( $phone ) {
					return array( 'number' => $phone, 'final_host' => $candidate_host );
				}
			}

			/* Fallback for encoded query fragments such as phone%3D1555... */
			if ( preg_match( '/phone(?:=|%3D)(?:%2B|\+)?([0-9]{7,15})/i', $body, $match ) ) {
				$phone = awm_normalize_phone( $match[1] );
				if ( $phone ) {
					return array( 'number' => $phone, 'final_host' => $last_host );
				}
			}
		}

		break;
	}

	return new WP_Error( 'awm_shortlink_unresolved', 'No WhatsApp phone number could be resolved from this shortlink.' );
}

function awm_save_shortlink_resolution( $destination, $number, $method = 'auto', $final_host = '', $source_label = '' ) {
	$destination = sanitize_text_field( (string) $destination );
	$number      = awm_normalize_phone( $number );
	if ( 0 !== strpos( $destination, 'wa.link/' ) || ! $number ) {
		return false;
	}
	$source_label = sanitize_text_field( substr( (string) $source_label, 0, 191 ) );
	$valid_source = '';
	if ( '' !== $source_label ) {
		$labels = awm_labels_for_destination( $number );
		if ( in_array( $source_label, $labels, true ) ) {
			$valid_source = $source_label;
		}
	}
	$map = awm_get_shortlink_resolutions();
	$map[ $destination ] = array(
		'status'       => 'resolved',
		'number'       => $number,
		'source_label' => $valid_source,
		'method'       => in_array( $method, array( 'auto', 'manual' ), true ) ? $method : 'auto',
		'final_host'   => sanitize_text_field( substr( (string) $final_host, 0, 191 ) ),
		'resolved_at'  => current_time( 'mysql' ),
		'last_error'   => '',
	);
	update_option( 'awm_shortlink_resolutions', $map, false );
	return true;
}

function awm_save_shortlink_failure( $destination, $message ) {
	$destination = sanitize_text_field( (string) $destination );
	if ( 0 !== strpos( $destination, 'wa.link/' ) ) {
		return;
	}
	$map = awm_get_shortlink_resolutions();
	$existing = isset( $map[ $destination ] ) && is_array( $map[ $destination ] ) ? $map[ $destination ] : array();
	/* Never overwrite a valid cached resolution with a later network failure. */
	if ( 'resolved' === ( $existing['status'] ?? '' ) && ! empty( $existing['number'] ) ) {
		return;
	}
	$map[ $destination ] = array(
		'status'       => 'failed',
		'number'       => '',
		'method'       => 'auto',
		'final_host'   => '',
		'resolved_at'  => '',
		'last_attempt' => current_time( 'mysql' ),
		'last_error'   => sanitize_text_field( substr( (string) $message, 0, 191 ) ),
	);
	update_option( 'awm_shortlink_resolutions', $map, false );
}

function awm_apply_shortlink_resolution_to_data( $destination, $number, $mapped_source = '' ) {
	global $wpdb;
	$destination = sanitize_text_field( (string) $destination );
	$number      = awm_normalize_phone( $number );
	if ( 0 !== strpos( $destination, 'wa.link/' ) || ! $number ) {
		return;
	}

	$labels = awm_labels_for_destination( $number );
	$mapped_source = sanitize_text_field( substr( (string) $mapped_source, 0, 191 ) );
	$auto_label = ( '' !== $mapped_source && in_array( $mapped_source, $labels, true ) ) ? $mapped_source : ( 1 === count( $labels ) ? $labels[0] : '' );
	$approved_map = awm_approved_number_map();
	$status = isset( $approved_map[ $number ] ) ? 'approved' : 'unknown';

	$clicks = awm_clicks_table();
	if ( awm_table_exists( $clicks ) ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$clicks} SET resolved_destination = %s WHERE destination = %s", $number, $destination ) );
		if ( $auto_label ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$clicks} SET source_label = %s WHERE destination = %s AND source_label = ''", $auto_label, $destination ) );
		}
	}

	$inventory = awm_inventory_table();
	if ( awm_table_exists( $inventory ) ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$inventory} SET resolved_destination = %s, status = %s WHERE destination = %s", $number, $status, $destination ) );
		if ( $auto_label ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$inventory} SET source_label = %s WHERE destination = %s AND source_label = ''", $auto_label, $destination ) );
		}
	}
}

function awm_refresh_latest_inventory_summary() {
	$latest = get_option( 'awm_latest_scan', array() );
	if ( empty( $latest['scan_id'] ) ) {
		return array();
	}
	$summary = awm_rebuild_inventory_summary(
		$latest['scan_id'],
		isset( $latest['mode'] ) ? $latest['mode'] : 'quick',
		isset( $latest['pages_scanned'] ) ? absint( $latest['pages_scanned'] ) : 0
	);
	update_option( 'awm_latest_scan', $summary, false );
	return $summary;
}

function awm_get_approved_numbers() {
	$numbers = get_option( 'awm_approved_numbers', array() );
	return is_array( $numbers ) ? $numbers : array();
}

function awm_approved_number_labels_map() {
	$map = array();
	foreach ( awm_get_approved_numbers() as $item ) {
		if ( empty( $item['number'] ) ) {
			continue;
		}
		$number = awm_normalize_phone( $item['number'] );
		$label  = isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '';
		if ( ! $number ) {
			continue;
		}
		if ( ! isset( $map[ $number ] ) ) {
			$map[ $number ] = array();
		}
		if ( '' !== $label && ! in_array( $label, $map[ $number ], true ) ) {
			$map[ $number ][] = $label;
		}
	}
	return $map;
}

/* Backward-compatible display map: one number => all configured labels. */
function awm_approved_number_map() {
	$map = array();
	foreach ( awm_approved_number_labels_map() as $number => $labels ) {
		$map[ $number ] = implode( ' / ', $labels );
	}
	return $map;
}

function awm_labels_for_destination( $destination ) {
	$map = awm_approved_number_labels_map();
	return isset( $map[ $destination ] ) ? array_values( $map[ $destination ] ) : array();
}

function awm_multi_label_destinations() {
	$destinations = array();
	foreach ( awm_approved_number_labels_map() as $number => $labels ) {
		if ( count( $labels ) > 1 ) {
			$destinations[] = $number;
		}
	}
	return $destinations;
}

function awm_resolve_source_label( $destination, $requested_source = '' ) {
	$labels = awm_labels_for_destination( $destination );
	if ( empty( $labels ) ) {
		return '';
	}

	$requested_source = sanitize_text_field( substr( (string) $requested_source, 0, 191 ) );
	if ( '' !== $requested_source && in_array( $requested_source, $labels, true ) ) {
		return $requested_source;
	}

	/* A single configured label is unambiguous, so assign it automatically. */
	if ( 1 === count( $labels ) ) {
		return $labels[0];
	}

	/* Multiple labels require an explicit source tag on the CTA. */
	return '';
}

function awm_inventory_status( $parsed ) {
	if ( ! is_array( $parsed ) || empty( $parsed['is_valid'] ) ) {
		return 'malformed';
	}

	$effective = awm_effective_destination_from_parsed( $parsed );
	if ( ! empty( $parsed['is_shortlink'] ) && $effective === $parsed['destination'] ) {
		return 'shortlink';
	}

	$approved = awm_approved_number_map();
	return isset( $approved[ $effective ] ) ? 'approved' : 'unknown';
}

/* -------------------------------------------------------------------------
 * Centrally managed WhatsApp routes
 * ---------------------------------------------------------------------- */

function awm_managed_route_defaults() {
	return array(
		'slug'             => '',
		'name'             => '',
		'enabled'          => '1',
		'primary_number'   => '',
		'backup_number'    => '',
		'active_line'      => 'primary',
		'source_label'     => '',
		'message_en'       => '',
		'message_zh'       => '',
		'message_id'       => '',
		'fallback_url'     => home_url( '/' ),
		'updated_at'       => '',
		'updated_by'       => 0,
	);
}

function awm_managed_route_slug( $value ) {
	$value = sanitize_title( (string) $value );
	return substr( $value, 0, 80 );
}

function awm_get_managed_routes() {
	$routes = get_option( 'awm_managed_routes', array() );
	return is_array( $routes ) ? $routes : array();
}

function awm_get_managed_route( $slug ) {
	$slug   = awm_managed_route_slug( $slug );
	$routes = awm_get_managed_routes();
	return isset( $routes[ $slug ] ) && is_array( $routes[ $slug ] ) ? array_merge( awm_managed_route_defaults(), $routes[ $slug ] ) : array();
}

function awm_managed_route_number( $route ) {
	$route = is_array( $route ) ? $route : array();
	if ( 'backup' === ( $route['active_line'] ?? 'primary' ) ) {
		$backup = awm_normalize_phone( $route['backup_number'] ?? '' );
		if ( $backup ) {
			return $backup;
		}
	}
	return awm_normalize_phone( $route['primary_number'] ?? '' );
}

function awm_managed_route_url( $slug, $language = '' ) {
	$slug = awm_managed_route_slug( $slug );
	$url  = home_url( '/go/whatsapp/' . rawurlencode( $slug ) . '/' );
	if ( in_array( $language, array( 'en', 'zh', 'id' ), true ) ) {
		$url = add_query_arg( 'lang', $language, $url );
	}
	return $url;
}

function awm_detect_post_language( $post_id ) {
	if ( function_exists( 'pll_get_post_language' ) ) {
		$language = sanitize_key( (string) pll_get_post_language( $post_id, 'slug' ) );
		if ( in_array( $language, array( 'en', 'zh', 'id' ), true ) ) {
			return $language;
		}
	}

	$url  = get_permalink( $post_id );
	$path = '/' . ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
	if ( 0 === strpos( $path, '/zh/' ) ) {
		return 'zh';
	}
	if ( 0 === strpos( $path, '/id/' ) ) {
		return 'id';
	}
	return 'en';
}

function awm_managed_route_message( $route, $language ) {
	$language = in_array( $language, array( 'en', 'zh', 'id' ), true ) ? $language : 'en';
	$message  = trim( (string) ( $route[ 'message_' . $language ] ?? '' ) );
	if ( '' === $message && 'en' !== $language ) {
		$message = trim( (string) ( $route['message_en'] ?? '' ) );
	}
	return $message;
}

function awm_managed_route_destination_url( $route, $language = 'en' ) {
	if ( ! is_array( $route ) || '1' !== (string) ( $route['enabled'] ?? '0' ) ) {
		return '';
	}
	$number = awm_managed_route_number( $route );
	if ( ! $number ) {
		return '';
	}
	$url     = 'https://wa.me/' . rawurlencode( $number );
	$message = awm_managed_route_message( $route, $language );
	if ( '' !== $message ) {
		$url .= '?text=' . rawurlencode( $message );
	}
	return $url;
}

function awm_parse_managed_route_url( $url, $parts = array() ) {
	$parts = is_array( $parts ) && $parts ? $parts : wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return false;
	}
	$host      = strtolower( (string) $parts['host'] );
	$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$site_host = strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) );
	if ( ! in_array( $host, array_filter( array( $home_host, $site_host ) ), true ) ) {
		return false;
	}

	$path      = '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' );
	$home_path = '/' . trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( '/' !== $home_path && ( $path === $home_path || 0 === strpos( $path, $home_path . '/' ) ) ) {
		$path = '/' . ltrim( substr( $path, strlen( $home_path ) ), '/' );
	}
	if ( ! preg_match( '#^/go/whatsapp/([A-Za-z0-9_-]+)/?$#', $path, $matches ) ) {
		return false;
	}

	$slug  = awm_managed_route_slug( $matches[1] );
	$route = awm_get_managed_route( $slug );
	if ( ! $route ) {
		return false;
	}
	$number = awm_managed_route_number( $route );

	return array(
		'url'                  => esc_url_raw( $url ),
		'host'                 => $host,
		'link_type'            => 'managed-route',
		'destination'          => 'route/' . $slug,
		'resolved_destination' => $number,
		'route_slug'           => $slug,
		'route_source'         => sanitize_text_field( (string) ( $route['source_label'] ?? '' ) ),
		'is_shortlink'         => false,
		'is_valid'             => true,
	);
}

function awm_sanitize_managed_route( $input, $existing_slug = '' ) {
	$input    = is_array( $input ) ? $input : array();
	$defaults = awm_managed_route_defaults();
	$slug     = $existing_slug ? awm_managed_route_slug( $existing_slug ) : awm_managed_route_slug( $input['slug'] ?? '' );
	$name     = sanitize_text_field( awm_emergency_limit_text( $input['name'] ?? '', 191 ) );
	$primary  = awm_normalize_phone( $input['primary_number'] ?? '' );
	$backup   = awm_normalize_phone( $input['backup_number'] ?? '' );
	$active   = 'backup' === ( $input['active_line'] ?? '' ) && $backup ? 'backup' : 'primary';
	$fallback = esc_url_raw( (string) ( $input['fallback_url'] ?? $defaults['fallback_url'] ) );
	if ( ! $fallback || ! awm_url_is_same_origin_as_site( $fallback ) ) {
		$fallback = $defaults['fallback_url'];
	}

	return array(
		'slug'             => $slug,
		'name'             => $name ? $name : ucwords( str_replace( '-', ' ', $slug ) ),
		'enabled'          => ! empty( $input['enabled'] ) ? '1' : '0',
		'primary_number'   => $primary,
		'backup_number'    => $backup,
		'active_line'      => $active,
		'source_label'     => sanitize_text_field( awm_emergency_limit_text( $input['source_label'] ?? '', 191 ) ),
		'message_en'       => sanitize_textarea_field( awm_emergency_limit_text( $input['message_en'] ?? '', 1000 ) ),
		'message_zh'       => sanitize_textarea_field( awm_emergency_limit_text( $input['message_zh'] ?? '', 1000 ) ),
		'message_id'       => sanitize_textarea_field( awm_emergency_limit_text( $input['message_id'] ?? '', 1000 ) ),
		'fallback_url'     => $fallback ? $fallback : $defaults['fallback_url'],
		'updated_at'       => current_time( 'mysql' ),
		'updated_by'       => get_current_user_id(),
	);
}

add_action( 'init', 'awm_register_managed_route_rewrite' );
function awm_register_managed_route_rewrite() {
	add_rewrite_rule( '^go/whatsapp/([A-Za-z0-9_-]+)/?$', 'index.php?awm_wa_route=$matches[1]', 'top' );
}

add_filter( 'query_vars', 'awm_managed_route_query_vars' );
function awm_managed_route_query_vars( $vars ) {
	$vars[] = 'awm_wa_route';
	return $vars;
}

function awm_managed_route_request_language() {
	$language = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
	if ( in_array( $language, array( 'en', 'zh', 'id' ), true ) ) {
		return $language;
	}
	$referer_path = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_PATH ) : '';
	if ( 0 === strpos( '/' . ltrim( $referer_path, '/' ), '/zh/' ) ) {
		return 'zh';
	}
	if ( 0 === strpos( '/' . ltrim( $referer_path, '/' ), '/id/' ) ) {
		return 'id';
	}
	return 'en';
}

add_action( 'template_redirect', 'awm_handle_managed_route_redirect', 0 );
function awm_handle_managed_route_redirect() {
	$slug = awm_managed_route_slug( get_query_var( 'awm_wa_route' ) );
	if ( ! $slug ) {
		return;
	}
	$route = awm_get_managed_route( $slug );
	if ( ! $route ) {
		status_header( 404 );
		nocache_headers();
		wp_die( 'This WhatsApp route is not available.', 'WhatsApp Route Not Found', array( 'response' => 404 ) );
	}

	header( 'X-Robots-Tag: noindex, nofollow', true );
	nocache_headers();
	$destination = awm_managed_route_destination_url( $route, awm_managed_route_request_language() );
	if ( $destination ) {
		do_action( 'awm_managed_route_redirect', $slug, $route, $destination );
		wp_redirect( $destination, 302, 'WhatsApp Tracker' );
		exit;
	}

	$fallback = wp_validate_redirect( (string) ( $route['fallback_url'] ?? '' ), home_url( '/' ) );
	wp_safe_redirect( $fallback, 302, 'WhatsApp Tracker' );
	exit;
}

function awm_whatsapp_route_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'route' => '',
			'title' => 'Chat on WhatsApp',
			'lang'  => 'auto',
			'class' => '',
		),
		$atts,
		'whatsapp-route'
	);
	$slug  = awm_managed_route_slug( $atts['route'] );
	$route = awm_get_managed_route( $slug );
	if ( ! $route ) {
		return '';
	}
	$language = sanitize_key( $atts['lang'] );
	if ( 'auto' === $language ) {
		$language = awm_detect_post_language( get_the_ID() );
	}
	$language = in_array( $language, array( 'en', 'zh', 'id' ), true ) ? $language : 'en';
	$source   = sanitize_text_field( (string) ( $route['source_label'] ?? '' ) );
	$class    = sanitize_html_class( (string) $atts['class'] );
	return sprintf(
		'<a href="%1$s"%4$s data-awm-route="%2$s" data-awm-source="%3$s">%5$s</a>',
		esc_url( awm_managed_route_url( $slug, $language ) ),
		esc_attr( $slug ),
		esc_attr( $source ),
		$class ? ' class="' . esc_attr( $class ) . '"' : '',
		esc_html( sanitize_text_field( $atts['title'] ) )
	);
}
add_shortcode( 'whatsapp-route', 'awm_whatsapp_route_shortcode' );

/* -------------------------------------------------------------------------
 * Existing shortcode compatibility
 * ---------------------------------------------------------------------- */

function awm_whatsapp_link_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'title'   => 'Chat on WhatsApp',
			'phone'   => '',
			'pretext' => '',
			'source'  => '',
		),
		$atts,
		'whatsapp-link'
	);

	$phone   = awm_normalize_phone( $atts['phone'] );
	$title   = sanitize_text_field( $atts['title'] );
	$pretext = sanitize_text_field( $atts['pretext'] );
	$source  = sanitize_text_field( substr( (string) $atts['source'], 0, 191 ) );

	if ( ! $phone ) {
		return '';
	}

	$url = 'https://wa.me/' . rawurlencode( $phone );
	if ( '' !== $pretext ) {
		$url .= '?text=' . rawurlencode( $pretext );
	}

	$source_attr = '' !== $source ? ' data-awm-source="' . esc_attr( $source ) . '"' : '';

	return sprintf(
		'<a href="%1$s" target="_blank" rel="noopener noreferrer"%3$s>%2$s</a>',
		esc_url( $url ),
		esc_html( $title ),
		$source_attr
	);
}
add_shortcode( 'whatsapp-link', 'awm_whatsapp_link_shortcode' );
