<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 * Lightweight frontend tracking
 * ---------------------------------------------------------------------- */

// Contact interception must execute before the first tap, including for guests.
add_filter( 'litespeed_optimize_js_excludes', 'awm_exclude_contact_tracker' );
add_filter( 'litespeed_optm_js_defer_exc', 'awm_exclude_contact_tracker' );
add_filter( 'litespeed_optm_gm_js_exc', 'awm_exclude_contact_tracker' );
function awm_exclude_contact_tracker( $excludes ) {
	$excludes = (array) $excludes;
	$excludes[] = 'whatsapp-tracker/assets/frontend-tracker';
	return array_values( array_unique( $excludes ) );
}

add_filter( 'script_loader_tag', 'awm_contact_tracker_script_tag', 100, 2 );
function awm_contact_tracker_script_tag( $tag, $handle ) {
	if ( 'awm-tracker' !== $handle ) { return $tag; }
	return str_replace( '<script ', '<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" ', $tag );
}

add_action( 'wp_enqueue_scripts', 'awm_enqueue_frontend_tracker' );
function awm_enqueue_frontend_tracker() {
	if ( is_admin() || isset( $_GET['awm_notice_form'] ) ) {
		return;
	}
	$tracking_enabled = '1' === get_option( 'awm_tracking_enabled', '1' );
	$emergency_rules  = awm_get_active_emergency_rules();
	// Always load the small listener so cached pages can receive newly enabled notices.

	$shortlink_map = array();
	foreach ( awm_get_shortlink_resolutions() as $destination => $resolution ) {
		if ( 'resolved' !== (string) ( $resolution['status'] ?? '' ) ) {
			continue;
		}
		$number = awm_normalize_phone( $resolution['number'] ?? '' );
		if ( $number ) {
			$shortlink_map[ (string) $destination ] = $number;
		}
	}

	$managed_route_map = array();
	foreach ( awm_get_managed_routes() as $slug => $route ) {
		if ( ! is_array( $route ) ) {
			continue;
		}
		$managed_route_map[ $slug ] = array(
			'url'         => awm_managed_route_url( $slug ),
			'destination' => awm_managed_route_number( $route ),
			'source'      => sanitize_text_field( (string) ( $route['source_label'] ?? '' ) ),
			'enabled'     => '1' === (string) ( $route['enabled'] ?? '0' ),
		);
	}

	$GLOBALS['awm_frontend_config'] = array(
		'endpoint'       => esc_url_raw( rest_url( 'whatsapp-tracker/v1/track' ) ),
		'configEndpoint' => esc_url_raw( rest_url( 'whatsapp-tracker/v1/emergency-rules' ) ),
		'trackingEnabled' => $tracking_enabled,
		'popupEnabled'    => awm_get_popup_visibility(),
		'language'       => function_exists( 'pll_current_language' ) ? (string) pll_current_language( 'slug' ) : '',
		'direction'      => is_rtl() ? 'rtl' : 'ltr',
		'emergencyRules' => $emergency_rules,
		'shortlinkMap'   => $shortlink_map,
		'managedRoutes'  => $managed_route_map,
	);

	wp_enqueue_style( 'awm-tracker', AWM_PLUGIN_URL . 'assets/frontend-' . AWM_VERSION . '.css', array(), AWM_VERSION );
	// Load in the head so capture-phase mobile interception is registered before
	// typical theme/page-builder footer handlers can open a tel: destination.
	wp_enqueue_script( 'awm-tracker', AWM_PLUGIN_URL . 'assets/frontend-tracker-' . AWM_VERSION . '.js', array(), AWM_VERSION, false );
}

add_action( 'wp_head', 'awm_render_frontend_config', 1 );
function awm_render_frontend_config() {
	if ( empty( $GLOBALS['awm_frontend_config'] ) || ! wp_script_is( 'awm-tracker', 'enqueued' ) ) {
		return;
	}
	printf(
		'<meta id="awm-tracker-config" data-config="%s">',
		esc_attr( wp_json_encode( $GLOBALS['awm_frontend_config'] ) )
	);
}

add_action( 'rest_api_init', 'awm_register_rest_routes' );
function awm_register_rest_routes() {
	register_rest_route( 'whatsapp-tracker/v1', '/emergency-rules', array(
		'methods' => WP_REST_Server::READABLE,
		'callback' => 'awm_rest_emergency_config',
		// Public display settings only; no admin data, credentials, or visitor data.
		'permission_callback' => '__return_true',
	) );
	register_rest_route(
		'whatsapp-tracker/v1',
		'/track',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'awm_rest_track_click',
			'permission_callback' => 'awm_rest_track_permission',
		)
	);
}

function awm_rest_emergency_config() {
	nocache_headers();
	$response = new WP_REST_Response( array(
		'emergencyRules' => awm_get_active_emergency_rules(),
		'trackingEnabled' => '1' === get_option( 'awm_tracking_enabled', '1' ),
		'popupEnabled'    => awm_get_popup_visibility(),
	), 200 );
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	return $response;
}

function awm_request_is_same_origin() {
	$fetch_site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';
	if ( $fetch_site && ! in_array( $fetch_site, array( 'same-origin', 'same-site', 'none' ), true ) ) {
		return false;
	}

	$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
	$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
	$source  = $origin ? $origin : $referer;
	return $source ? awm_url_is_same_origin_as_site( $source ) : false;
}

function awm_rate_limit_allows_request() {
	$limit = (int) apply_filters( 'awm_tracking_rate_limit_per_minute', 60 );
	$limit = max( 10, min( 600, $limit ) );
	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	$id    = substr( awm_constant_time_id( $ip . '|' . substr( $ua, 0, 160 ) ), 0, 32 );

	if ( wp_using_ext_object_cache() ) {
		$key = 'rl_' . $id . '_' . gmdate( 'YmdHi' );
		if ( wp_cache_add( $key, 1, 'awm', MINUTE_IN_SECONDS + 5 ) ) {
			return true;
		}
		$count = wp_cache_incr( $key, 1, 'awm' );
		return false !== $count && (int) $count <= $limit;
	}

	/*
	 * Fallback for hosts without Redis/Memcached. Only a keyed hash is stored;
	 * the raw visitor IP/UA is never persisted. One transient is reused per
	 * identity instead of creating a new options row for every click/minute.
	 */
	$key   = 'awm_rl_' . $id;
	$state = get_transient( $key );
	$now   = time();
	if ( ! is_array( $state ) || empty( $state['reset'] ) || (int) $state['reset'] <= $now ) {
		set_transient( $key, array( 'count' => 1, 'reset' => $now + MINUTE_IN_SECONDS ), MINUTE_IN_SECONDS + 10 );
		return true;
	}
	$count = absint( $state['count'] ?? 0 );
	if ( $count >= $limit ) {
		return false;
	}
	$state['count'] = $count + 1;
	set_transient( $key, $state, max( 1, (int) $state['reset'] - $now + 10 ) );
	return true;
}

function awm_rest_track_permission( WP_REST_Request $request ) {
	if ( '1' !== get_option( 'awm_tracking_enabled', '1' ) ) {
		return true;
	}
	$body = (string) $request->get_body();
	if ( strlen( $body ) > 8192 ) {
		return new WP_Error( 'awm_payload_too_large', 'Request rejected.', array( 'status' => 413 ) );
	}
	$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
	if ( 0 !== strpos( $content_type, 'application/json' ) ) {
		return new WP_Error( 'awm_unsupported_media_type', 'Request rejected.', array( 'status' => 415 ) );
	}
	if ( ! awm_request_is_same_origin() ) {
		return new WP_Error( 'awm_forbidden_origin', 'Request rejected.', array( 'status' => 403 ) );
	}
	if ( ! awm_rate_limit_allows_request() ) {
		return new WP_Error( 'awm_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
	}
	return true;
}

function awm_rest_track_click( WP_REST_Request $request ) {
	if ( '1' !== get_option( 'awm_tracking_enabled', '1' ) ) {
		return new WP_REST_Response( array( 'tracked' => false ), 202 );
	}


	$params = $request->get_json_params();
	$params = is_array( $params ) ? $params : array();

	$href = isset( $params['href'] ) ? esc_url_raw( substr( (string) $params['href'], 0, 2048 ) ) : '';
	$path = isset( $params['path'] ) ? sanitize_text_field( substr( (string) $params['path'], 0, 768 ) ) : '/';
	$text   = isset( $params['text'] ) ? sanitize_text_field( substr( (string) $params['text'], 0, 191 ) ) : '';
	$source = isset( $params['source'] ) ? sanitize_text_field( substr( (string) $params['source'], 0, 191 ) ) : '';

	/* Prefer the same-origin Referer path over client-supplied path data. */
	if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$referer_path = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_PATH );
		if ( is_string( $referer_path ) && '' !== $referer_path ) {
			$path = sanitize_text_field( substr( $referer_path, 0, 768 ) );
		}
	}

	if ( '' === $path || '/' !== $path[0] ) {
		$path = '/';
	}

	$parsed = awm_parse_whatsapp_url( $href );
	if ( ! $parsed || empty( $parsed['is_valid'] ) ) {
		return new WP_Error( 'awm_invalid_destination', 'Invalid WhatsApp destination.', array( 'status' => 400 ) );
	}

	global $wpdb;
	$table = awm_clicks_table();


	$date   = current_time( 'Y-m-d' );
	$now    = current_time( 'mysql' );
	$original_destination = $parsed['destination'];
	$effective_destination = awm_effective_destination_from_parsed( $parsed );
	$resolved_destination = ( $effective_destination !== $original_destination ) ? $effective_destination : '';
	$source = awm_resolve_source_for_parsed( $parsed, $source );

	/* Keep the original link identity while allowing resolved wa.link reporting. */
	$event_material = $date . '|' . $path . '|' . $original_destination . '|' . $parsed['link_type'];
	if ( '' !== $source ) {
		$event_material .= '|' . $source;
	}
	$event_key = hash( 'sha256', $event_material );

	$sql = $wpdb->prepare(
		"INSERT INTO {$table}
		(event_key, click_date, page_path, destination, resolved_destination, link_type, source_label, link_text, clicks, first_click, last_click)
		VALUES (%s, %s, %s, %s, %s, %s, %s, %s, 1, %s, %s)
		ON DUPLICATE KEY UPDATE
		clicks = clicks + 1,
		last_click = VALUES(last_click),
		link_text = VALUES(link_text),
		resolved_destination = VALUES(resolved_destination),
		source_label = VALUES(source_label)",
		$event_key,
		$date,
		$path,
		$original_destination,
		$resolved_destination,
		$parsed['link_type'],
		$source,
		$text,
		$now,
		$now
	);

	$result = $wpdb->query( $sql );
	if ( false === $result ) {
		error_log( 'WhatsApp Tracker tracking insert failed.' );
		return new WP_Error( 'awm_track_failed', 'Unable to record click.', array( 'status' => 500 ) );
	}

	return new WP_REST_Response( array( 'tracked' => true ), 202 );
}

/* -------------------------------------------------------------------------
 * Retention cleanup
 * ---------------------------------------------------------------------- */

add_action( 'awm_daily_cleanup', 'awm_run_daily_cleanup' );
function awm_run_daily_cleanup() {
	global $wpdb;
	$table = awm_clicks_table();

	if ( ! awm_table_exists( $table ) ) {
		return;
	}

	$days = absint( get_option( 'awm_retention_days', 365 ) );
	$days = max( 30, min( 3650, $days ) );

	$cutoff = wp_date( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ), wp_timezone() );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE click_date < %s", $cutoff ) );
}
