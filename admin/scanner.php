<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 * Scanner helpers
 * ---------------------------------------------------------------------- */

function awm_scannable_post_types() {
	$types = get_post_types( array( 'public' => true ), 'names' );
	unset( $types['attachment'] );
	return array_values( $types );
}

function awm_scan_total_posts() {
	global $wpdb;
	$types = awm_scannable_post_types();
	if ( empty( $types ) ) {
		return 0;
	}
	$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})";
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $types ) );
}


function awm_scan_mode_label( $mode ) {
	$labels = array(
		'quick'   => 'Quick Scan',
		'deep'    => 'Deep Scan',
		'recent'  => 'Recent Changes',
		'current' => 'Current Page',
	);
	return isset( $labels[ $mode ] ) ? $labels[ $mode ] : ucfirst( (string) $mode );
}

function awm_inventory_checkpoint_gmt() {
	$checkpoint = get_option( 'awm_inventory_checkpoint_gmt', '' );
	if ( $checkpoint ) {
		return sanitize_text_field( $checkpoint );
	}

	$latest = get_option( 'awm_latest_scan', array() );
	if ( ! empty( $latest['completed_at'] ) ) {
		$derived = get_gmt_from_date( $latest['completed_at'] );
		if ( $derived ) {
			return $derived;
		}
	}

	return '';
}

function awm_recent_changes_total( $from_gmt, $to_gmt ) {
	global $wpdb;
	$types = awm_scannable_post_types();
	if ( empty( $types ) || ! $from_gmt || ! $to_gmt ) {
		return 0;
	}
	$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_modified_gmt > %s AND post_modified_gmt <= %s";
	$args = array_merge( $types, array( $from_gmt, $to_gmt ) );
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
}

function awm_get_recent_posts_batch( $from_gmt, $to_gmt, $offset, $limit ) {
	global $wpdb;
	$types = awm_scannable_post_types();
	if ( empty( $types ) || ! $from_gmt || ! $to_gmt ) {
		return array();
	}

	$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_modified_gmt > %s AND post_modified_gmt <= %s ORDER BY post_modified_gmt ASC, ID ASC LIMIT %d OFFSET %d";
	$args = array_merge( $types, array( $from_gmt, $to_gmt, absint( $limit ), absint( $offset ) ) );
	$ids  = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	if ( empty( $ids ) ) {
		return array();
	}

	$posts = array();
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( $post ) {
			$posts[] = $post;
		}
	}
	if ( $ids ) {
		update_meta_cache( 'post', $ids );
	}
	return $posts;
}

function awm_rebuild_inventory_summary( $scan_id, $mode, $pages_scanned ) {
	global $wpdb;
	$table = awm_inventory_table();

	$links_found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE scan_id = %s", $scan_id ) );
	$unique_dest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT CASE WHEN resolved_destination <> '' THEN resolved_destination ELSE destination END) FROM {$table} WHERE scan_id = %s", $scan_id ) );
	$inventory_pages = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT page_url) FROM {$table} WHERE scan_id = %s", $scan_id ) );
	$approved    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE scan_id = %s AND status = 'approved'", $scan_id ) );
	$unknown     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE scan_id = %s AND status = 'unknown'", $scan_id ) );
	$shortlinks  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE scan_id = %s AND status = 'shortlink'", $scan_id ) );
	$resolved_shortlinks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE scan_id = %s AND link_type = 'wa.link' AND resolved_destination <> ''", $scan_id ) );
	$malformed   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE scan_id = %s AND status = 'malformed'", $scan_id ) );
	$unassigned_sources = 0;
	$multi_destinations = awm_multi_label_destinations();
	if ( ! empty( $multi_destinations ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $multi_destinations ), '%s' ) );
		$args = array_merge( array( $scan_id ), $multi_destinations );
		$unassigned_sources = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE scan_id = %s AND source_label = '' AND (CASE WHEN resolved_destination <> '' THEN resolved_destination ELSE destination END) IN ({$placeholders})", $args ) );
	}

	return array(
		'scan_id'             => $scan_id,
		'mode'                => $mode,
		'completed_at'        => current_time( 'mysql' ),
		'pages_scanned'       => absint( $pages_scanned ),
		'inventory_pages'     => $inventory_pages,
		'links_found'         => $links_found,
		'unique_destinations' => $unique_dest,
		'approved'            => $approved,
		'unknown'             => $unknown,
		'shortlinks'          => $shortlinks,
		'resolved_shortlinks' => $resolved_shortlinks,
		'malformed'           => $malformed,
		'unassigned_sources'  => $unassigned_sources,
	);
}

function awm_extract_urls_from_text( $text ) {
	$text = (string) $text;
	if ( '' === $text ) {
		return array();
	}

	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = str_replace( array( '\\/', '\\u0026' ), array( '/', '&' ), $text );

	$items = array();
	$pattern = '~https?://(?:wa\.me|api\.whatsapp\.com|web\.whatsapp\.com|wa\.link)(?:/[^\s"\'<>]*)?~i';
	preg_match_all( $pattern, $text, $matches );

	foreach ( isset( $matches[0] ) ? $matches[0] : array() as $url ) {
		$url = rtrim( $url, ".,;:)\"']}" );
		$parsed = awm_parse_whatsapp_url( $url );
		if ( $parsed ) {
			$key = hash( 'sha256', $parsed['url'] . '||' );
			$items[ $key ] = array( 'url' => $parsed['url'], 'text' => '', 'source' => '' );
		}
	}

	$managed_matches = array();
	$managed_pattern = '~(?:' . preg_quote( home_url( '/go/whatsapp/' ), '~' ) . '|/go/whatsapp/)[A-Za-z0-9_-]+/?(?:\?lang=(?:en|zh|id))?~i';
	preg_match_all( $managed_pattern, $text, $managed_matches );
	foreach ( isset( $managed_matches[0] ) ? $managed_matches[0] : array() as $url ) {
		$parsed = awm_parse_whatsapp_url( $url );
		if ( $parsed ) {
			$key = hash( 'sha256', $parsed['url'] . '||' );
			$items[ $key ] = array( 'url' => $parsed['url'], 'text' => '', 'source' => $parsed['route_source'] ?? '' );
		}
	}

	/* Capture the plugin shortcode too, including its optional tracking source. */
	if ( false !== stripos( $text, '[whatsapp-link' ) ) {
		preg_match_all( '/\[whatsapp-link\b([^\]]*)\]/i', $text, $shortcodes );
		foreach ( isset( $shortcodes[1] ) ? $shortcodes[1] : array() as $raw_atts ) {
			$atts = shortcode_parse_atts( $raw_atts );
			$atts = is_array( $atts ) ? $atts : array();
			$phone = awm_normalize_phone( isset( $atts['phone'] ) ? $atts['phone'] : '' );
			if ( ! $phone ) {
				continue;
			}
			$url = 'https://wa.me/' . rawurlencode( $phone );
			if ( ! empty( $atts['pretext'] ) ) {
				$url .= '?text=' . rawurlencode( sanitize_text_field( $atts['pretext'] ) );
			}
			$parsed = awm_parse_whatsapp_url( $url );
			if ( ! $parsed ) {
				continue;
			}
			$source = sanitize_text_field( substr( (string) ( $atts['source'] ?? '' ), 0, 191 ) );
			$title  = sanitize_text_field( substr( (string) ( $atts['title'] ?? '' ), 0, 191 ) );
			$key = hash( 'sha256', $parsed['url'] . '|' . $source . '|' . $title );
			$items[ $key ] = array( 'url' => $parsed['url'], 'text' => $title, 'source' => $source );
		}
	}

	return array_values( $items );
}

function awm_text_may_contain_whatsapp( $text ) {
	return (bool) preg_match( '/(?:wa\.me|whatsapp\.com|wa\.link|\/go\/whatsapp\/)/i', (string) $text );
}

function awm_extract_urls_from_html( $html ) {
	$items = array();

	if ( class_exists( 'DOMDocument' ) ) {
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . (string) $html );
		if ( $loaded ) {
			foreach ( $dom->getElementsByTagName( 'a' ) as $anchor ) {
				$href = $anchor->getAttribute( 'href' );
				$parsed = awm_parse_whatsapp_url( $href );
				if ( ! $parsed ) {
					continue;
				}
				$text = sanitize_text_field( preg_replace( '/\s+/', ' ', $anchor->textContent ) );
				$source = $anchor->getAttribute( 'data-awm-source' );
				if ( '' === $source ) {
					$source = $anchor->getAttribute( 'data-whatsapp-source' );
				}
				$source = sanitize_text_field( substr( (string) $source, 0, 191 ) );
				$text   = substr( $text, 0, 191 );
				$key    = hash( 'sha256', $parsed['url'] . '|' . $source . '|' . $text );
				$items[ $key ] = array(
					'url'    => $parsed['url'],
					'text'   => $text,
					'source' => $source,
				);
			}
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}

	if ( empty( $items ) ) {
		foreach ( awm_extract_urls_from_text( $html ) as $item ) {
			$key = hash( 'sha256', $item['url'] . '|' . ( $item['source'] ?? '' ) . '|' . ( $item['text'] ?? '' ) );
			$items[ $key ] = $item;
		}
	}

	return array_values( $items );
}

function awm_store_inventory_item( $scan_id, $scan_mode, $object_id, $page_title, $page_url, $link_url, $link_text = '', $source_label = '' ) {
	global $wpdb;
	$table = awm_inventory_table();
	$parsed = awm_parse_whatsapp_url( $link_url );

	if ( ! $parsed ) {
		return false;
	}

	$status       = awm_inventory_status( $parsed );
	$link_text    = sanitize_text_field( substr( (string) $link_text, 0, 191 ) );
	$effective_destination = awm_effective_destination_from_parsed( $parsed );
	$resolved_destination  = ( $effective_destination !== $parsed['destination'] ) ? $effective_destination : '';
	$source_label = awm_resolve_source_for_parsed( $parsed, $source_label );
	$item_key     = hash( 'sha256', $scan_id . '|' . $page_url . '|' . $parsed['url'] . '|' . $source_label . '|' . $link_text );

	$sql = $wpdb->prepare(
		"INSERT INTO {$table}
		(item_key, scan_id, scan_mode, object_id, page_title, page_url, link_url, destination, resolved_destination, link_type, source_label, link_text, status, last_seen)
		VALUES (%s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
		ON DUPLICATE KEY UPDATE
		link_text = VALUES(link_text), resolved_destination = VALUES(resolved_destination), source_label = VALUES(source_label), status = VALUES(status), last_seen = VALUES(last_seen)",
		$item_key,
		$scan_id,
		$scan_mode,
		absint( $object_id ),
		sanitize_text_field( $page_title ),
		esc_url_raw( $page_url ),
		$parsed['url'],
		$parsed['destination'],
		$resolved_destination,
		$parsed['link_type'],
		$source_label,
		$link_text,
		$status,
		current_time( 'mysql' )
	);

	return false !== $wpdb->query( $sql );
}

function awm_get_scan_posts_batch( $offset, $limit ) {
	$types = awm_scannable_post_types();
	if ( empty( $types ) ) {
		return array();
	}

	return get_posts(
		array(
			'post_type'              => $types,
			'post_status'            => 'publish',
			'posts_per_page'         => absint( $limit ),
			'offset'                 => absint( $offset ),
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		)
	);
}

function awm_scan_post_quick( $post, $scan_id, $scan_mode = 'quick' ) {
	$found = 0;
	$page_url = get_permalink( $post );
	if ( ! $page_url ) {
		return 0;
	}

	$chunks = array( $post->post_content );
	$all_meta = get_post_meta( $post->ID );
	foreach ( $all_meta as $values ) {
		foreach ( (array) $values as $value ) {
			if ( is_scalar( $value ) && awm_text_may_contain_whatsapp( (string) $value ) ) {
				$chunks[] = (string) $value;
			}
		}
	}

	$seen = array();
	foreach ( $chunks as $chunk ) {
		$items = ( false !== stripos( (string) $chunk, '<a' ) && awm_text_may_contain_whatsapp( (string) $chunk ) )
			? awm_extract_urls_from_html( $chunk )
			: awm_extract_urls_from_text( $chunk );
		foreach ( $items as $item ) {
			$seen_key = hash( 'sha256', $item['url'] . '|' . ( $item['source'] ?? '' ) . '|' . ( $item['text'] ?? '' ) );
			if ( isset( $seen[ $seen_key ] ) ) {
				continue;
			}
			$seen[ $seen_key ] = true;
			if ( awm_store_inventory_item( $scan_id, $scan_mode, $post->ID, get_the_title( $post ), $page_url, $item['url'], $item['text'], $item['source'] ?? '' ) ) {
				$found++;
			}
		}
	}

	return $found;
}

function awm_collect_post_deep_items( $post ) {
	$page_url = get_permalink( $post );
	if ( ! $page_url || ! awm_url_is_local( $page_url ) ) {
		return new WP_Error( 'awm_invalid_page', 'The page does not have a valid local public URL.' );
	}

	$response = wp_safe_remote_get(
		$page_url,
		array(
			'timeout'             => AWM_DEEP_SCAN_TIMEOUT,
			'redirection'         => 2,
			'limit_response_size' => AWM_DEEP_SCAN_MAX_BYTES,
			'user-agent'          => 'WhatsAppTrackerScanner/' . AWM_VERSION . '; ' . home_url( '/' ),
			'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$final_url = isset( $response['http_response'] ) && is_object( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ? $response['http_response']->get_response_object() : null;
	if ( $final_url && isset( $final_url->url ) && ! awm_url_is_local( $final_url->url ) ) {
		return new WP_Error( 'awm_scan_redirected_offsite', 'Rendered-page scan redirected outside this website.' );
	}

	$status_code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $status_code ) {
		return new WP_Error( 'awm_scan_http_error', 'Page returned HTTP ' . $status_code . ' during scan.' );
	}

	return awm_extract_urls_from_html( wp_remote_retrieve_body( $response ) );
}

function awm_scan_post_deep( $post, $scan_id, $scan_mode = 'deep' ) {
	$page_url = get_permalink( $post );
	$items    = awm_collect_post_deep_items( $post );
	if ( is_wp_error( $items ) ) {
		return 0;
	}

	$found = 0;
	foreach ( $items as $item ) {
		if ( awm_store_inventory_item( $scan_id, $scan_mode, $post->ID, get_the_title( $post ), $page_url, $item['url'], $item['text'], $item['source'] ?? '' ) ) {
			$found++;
		}
	}
	return $found;
}

function awm_url_is_local( $url ) {
	$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	return $home_host && $url_host && hash_equals( $home_host, $url_host );
}

/* -------------------------------------------------------------------------
 * Scanner AJAX — admin only and on demand
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_awm_scan_start', 'awm_ajax_scan_start' );
function awm_ajax_scan_start() {
	check_ajax_referer( 'awm_scan', 'nonce' );
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}

	$mode = isset( $_POST['mode'] ) && 'deep' === sanitize_key( wp_unslash( $_POST['mode'] ) ) ? 'deep' : 'quick';

	/* Prevent concurrent scans from multiple admin tabs/users. Stale locks are cleared. */
	$existing = get_option( 'awm_scan_running', array() );
	if ( ! empty( $existing['scan_id'] ) ) {
		$started_ts = isset( $existing['started_ts'] ) ? absint( $existing['started_ts'] ) : 0;
		if ( $started_ts && ( time() - $started_ts ) < AWM_SCAN_LOCK_TTL ) {
			wp_send_json_error( array( 'message' => 'Another inventory scan is already running. Cancel it or wait for it to finish.' ), 409 );
		}

		global $wpdb;
		$inventory_table = awm_inventory_table();
		$wpdb->delete( $inventory_table, array( 'scan_id' => sanitize_text_field( (string) $existing['scan_id'] ) ), array( '%s' ) );
		delete_option( 'awm_scan_running' );
	}

	$scan_id = wp_generate_uuid4();
	$total   = awm_scan_total_posts();
	$batch   = 'deep' === $mode ? AWM_DEEP_SCAN_BATCH : AWM_QUICK_SCAN_BATCH;

	update_option(
		'awm_scan_running',
		array(
			'scan_id'    => $scan_id,
			'mode'       => $mode,
			'started_at' => current_time( 'mysql' ),
			'started_ts' => time(),
			'checkpoint_gmt' => current_time( 'mysql', true ),
			'total'      => $total,
			'processed'  => 0,
		),
		false
	);

	wp_send_json_success(
		array(
			'scan_id' => $scan_id,
			'mode'    => $mode,
			'total'   => $total,
			'batch'   => $batch,
		)
	);
}

add_action( 'wp_ajax_awm_scan_batch', 'awm_ajax_scan_batch' );
function awm_ajax_scan_batch() {
	check_ajax_referer( 'awm_scan', 'nonce' );
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}

	$running = get_option( 'awm_scan_running', array() );
	$scan_id = isset( $_POST['scan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_id'] ) ) : '';
	$mode    = isset( $_POST['mode'] ) && 'deep' === sanitize_key( wp_unslash( $_POST['mode'] ) ) ? 'deep' : 'quick';
	$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
	$batch   = 'deep' === $mode ? AWM_DEEP_SCAN_BATCH : AWM_QUICK_SCAN_BATCH;

	if ( empty( $running['scan_id'] ) || ! hash_equals( (string) $running['scan_id'], $scan_id ) || $running['mode'] !== $mode ) {
		wp_send_json_error( array( 'message' => 'Scan session expired. Start a new scan.' ), 400 );
	}

	$posts = awm_get_scan_posts_batch( $offset, $batch );
	$found = 0;
	foreach ( $posts as $post ) {
		$found += ( 'deep' === $mode ) ? awm_scan_post_deep( $post, $scan_id ) : awm_scan_post_quick( $post, $scan_id );
	}

	$processed   = count( $posts );
	$next_offset = $offset + $processed;
	$total       = isset( $running['total'] ) ? absint( $running['total'] ) : awm_scan_total_posts();
	$done        = ( 0 === $processed || $next_offset >= $total );

	$running['processed'] = min( $total, $next_offset );
	update_option( 'awm_scan_running', $running, false );

	wp_send_json_success(
		array(
			'processed'   => $processed,
			'next_offset' => $next_offset,
			'found'       => $found,
			'total'       => $total,
			'done'        => $done,
		)
	);
}

add_action( 'wp_ajax_awm_scan_cancel', 'awm_ajax_scan_cancel' );
function awm_ajax_scan_cancel() {
	check_ajax_referer( 'awm_scan', 'nonce' );
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}

	global $wpdb;
	$running = get_option( 'awm_scan_running', array() );
	$scan_id = isset( $_POST['scan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_id'] ) ) : '';

	if ( ! empty( $running['scan_id'] ) && $scan_id && hash_equals( (string) $running['scan_id'], $scan_id ) ) {
		$wpdb->delete( awm_inventory_table(), array( 'scan_id' => $scan_id ), array( '%s' ) );
		delete_option( 'awm_scan_running' );
	}

	wp_send_json_success( array( 'cancelled' => true ) );
}

add_action( 'wp_ajax_awm_scan_finalize', 'awm_ajax_scan_finalize' );
function awm_ajax_scan_finalize() {
	check_ajax_referer( 'awm_scan', 'nonce' );
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}

	global $wpdb;
	$table   = awm_inventory_table();
	$running = get_option( 'awm_scan_running', array() );
	$scan_id = isset( $_POST['scan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_id'] ) ) : '';

	if ( empty( $running['scan_id'] ) || ! hash_equals( (string) $running['scan_id'], $scan_id ) ) {
		wp_send_json_error( array( 'message' => 'Scan session expired.' ), 400 );
	}

	$summary = awm_rebuild_inventory_summary(
		$scan_id,
		isset( $running['mode'] ) ? $running['mode'] : 'quick',
		isset( $running['processed'] ) ? absint( $running['processed'] ) : 0
	);

	update_option( 'awm_latest_scan', $summary, false );
	if ( ! empty( $running['checkpoint_gmt'] ) ) {
		update_option( 'awm_inventory_checkpoint_gmt', sanitize_text_field( $running['checkpoint_gmt'] ), false );
	}
	delete_option( 'awm_scan_running' );

	/* Keep only the latest full inventory snapshot so the scanner table cannot grow forever. */
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE scan_id <> %s", $scan_id ) );

	wp_send_json_success( $summary );
}

/* -------------------------------------------------------------------------
 * Incremental scanner — recent changes + one-page targeted scan
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_awm_recent_scan_start', 'awm_ajax_recent_scan_start' );
function awm_ajax_recent_scan_start() {
	check_ajax_referer( 'awm_scan', 'nonce' );
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}

	$latest = get_option( 'awm_latest_scan', array() );
	if ( empty( $latest['scan_id'] ) ) {
		wp_send_json_error( array( 'message' => 'Run a full Quick or Deep Scan first to create the base inventory.' ), 400 );
	}

	$existing = get_option( 'awm_scan_running', array() );
	if ( ! empty( $existing['scan_id'] ) ) {
		$started_ts = isset( $existing['started_ts'] ) ? absint( $existing['started_ts'] ) : 0;
		if ( $started_ts && ( time() - $started_ts ) < AWM_SCAN_LOCK_TTL ) {
			wp_send_json_error( array( 'message' => 'Another inventory scan is already running. Cancel it or wait for it to finish.' ), 409 );
		}
		delete_option( 'awm_scan_running' );
	}

	$from_gmt = awm_inventory_checkpoint_gmt();
	if ( ! $from_gmt ) {
		wp_send_json_error( array( 'message' => 'No inventory checkpoint is available. Run a full scan first.' ), 400 );
	}
	$to_gmt = current_time( 'mysql', true );
	$total  = awm_recent_changes_total( $from_gmt, $to_gmt );
	$operation_id = wp_generate_uuid4();

	update_option(
		'awm_scan_running',
		array(
			'scan_id'          => $operation_id,
			'snapshot_scan_id' => sanitize_text_field( $latest['scan_id'] ),
			'mode'             => 'recent',
			'started_at'       => current_time( 'mysql' ),
			'started_ts'       => time(),
			'from_gmt'         => $from_gmt,
			'checkpoint_gmt'   => $to_gmt,
			'total'            => $total,
			'processed'        => 0,
		),
		false
	);

	wp_send_json_success(
		array(
			'scan_id' => $operation_id,
			'mode'    => 'recent',
			'total'   => $total,
			'batch'   => AWM_QUICK_SCAN_BATCH,
		)
	);
}

add_action( 'wp_ajax_awm_recent_scan_batch', 'awm_ajax_recent_scan_batch' );
function awm_ajax_recent_scan_batch() {
	check_ajax_referer( 'awm_scan', 'nonce' );
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}

	global $wpdb;
	$running = get_option( 'awm_scan_running', array() );
	$operation_id = isset( $_POST['scan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_id'] ) ) : '';
	$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

	if ( empty( $running['scan_id'] ) || ! hash_equals( (string) $running['scan_id'], $operation_id ) || 'recent' !== ( $running['mode'] ?? '' ) ) {
		wp_send_json_error( array( 'message' => 'Recent Changes scan session expired.' ), 400 );
	}

	$snapshot_scan_id = sanitize_text_field( $running['snapshot_scan_id'] );
	$posts = awm_get_recent_posts_batch( $running['from_gmt'], $running['checkpoint_gmt'], $offset, AWM_QUICK_SCAN_BATCH );
	$found = 0;

	foreach ( $posts as $post ) {
		/* Replace inventory only for this changed object; all other pages remain untouched. */
		$wpdb->delete(
			awm_inventory_table(),
			array( 'scan_id' => $snapshot_scan_id, 'object_id' => absint( $post->ID ) ),
			array( '%s', '%d' )
		);
		if ( 'publish' === $post->post_status ) {
			$found += awm_scan_post_quick( $post, $snapshot_scan_id, 'recent' );
		}
	}

	$processed   = count( $posts );
	$next_offset = $offset + $processed;
	$total       = isset( $running['total'] ) ? absint( $running['total'] ) : 0;
	$done        = ( 0 === $processed || $next_offset >= $total );

	$running['processed'] = min( $total, $next_offset );
	update_option( 'awm_scan_running', $running, false );

	wp_send_json_success(
		array(
			'processed'   => $processed,
			'next_offset' => $next_offset,
			'found'       => $found,
			'total'       => $total,
			'done'        => $done,
		)
	);
}

add_action( 'wp_ajax_awm_recent_scan_finalize', 'awm_ajax_recent_scan_finalize' );
function awm_ajax_recent_scan_finalize() {
	check_ajax_referer( 'awm_scan', 'nonce' );
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}

	$running = get_option( 'awm_scan_running', array() );
	$operation_id = isset( $_POST['scan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_id'] ) ) : '';
	if ( empty( $running['scan_id'] ) || ! hash_equals( (string) $running['scan_id'], $operation_id ) || 'recent' !== ( $running['mode'] ?? '' ) ) {
		wp_send_json_error( array( 'message' => 'Recent Changes scan session expired.' ), 400 );
	}

	$snapshot_scan_id = sanitize_text_field( $running['snapshot_scan_id'] );
	$summary = awm_rebuild_inventory_summary( $snapshot_scan_id, 'recent', absint( $running['processed'] ?? 0 ) );
	update_option( 'awm_latest_scan', $summary, false );
	update_option( 'awm_inventory_checkpoint_gmt', sanitize_text_field( $running['checkpoint_gmt'] ), false );
	delete_option( 'awm_scan_running' );
	wp_send_json_success( $summary );
}

function awm_resolve_target_post_from_request() {
	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	if ( ! $post_id && ! empty( $_POST['page_url'] ) ) {
		$url = esc_url_raw( wp_unslash( $_POST['page_url'] ) );
		if ( ! awm_url_is_local( $url ) ) {
			return new WP_Error( 'awm_nonlocal_url', 'Enter a URL from this website.' );
		}
		$post_id = absint( url_to_postid( $url ) );
	}

	$post = $post_id ? get_post( $post_id ) : null;
	if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, awm_scannable_post_types(), true ) ) {
		return new WP_Error( 'awm_invalid_target', 'Unable to identify a published scannable page from that selection.' );
	}
	return $post;
}

add_action( 'wp_ajax_awm_scan_current_page', 'awm_ajax_scan_current_page' );
function awm_ajax_scan_current_page() {
	check_ajax_referer( 'awm_scan', 'nonce' );
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}

	global $wpdb;
	$latest = get_option( 'awm_latest_scan', array() );
	if ( empty( $latest['scan_id'] ) ) {
		wp_send_json_error( array( 'message' => 'Run a full Quick or Deep Scan first to create the base inventory.' ), 400 );
	}

	$running = get_option( 'awm_scan_running', array() );
	if ( ! empty( $running['scan_id'] ) ) {
		$started_ts = isset( $running['started_ts'] ) ? absint( $running['started_ts'] ) : 0;
		if ( $started_ts && ( time() - $started_ts ) < AWM_SCAN_LOCK_TTL ) {
			wp_send_json_error( array( 'message' => 'Another inventory scan is already running.' ), 409 );
		}
		delete_option( 'awm_scan_running' );
	}

	$post = awm_resolve_target_post_from_request();
	if ( is_wp_error( $post ) ) {
		wp_send_json_error( array( 'message' => $post->get_error_message() ), 400 );
	}

	$items = awm_collect_post_deep_items( $post );
	if ( is_wp_error( $items ) ) {
		wp_send_json_error( array( 'message' => 'Unable to fetch the rendered page: ' . $items->get_error_message() ), 502 );
	}

	$snapshot_scan_id = sanitize_text_field( $latest['scan_id'] );
	$wpdb->delete(
		awm_inventory_table(),
		array( 'scan_id' => $snapshot_scan_id, 'object_id' => absint( $post->ID ) ),
		array( '%s', '%d' )
	);

	$page_url = get_permalink( $post );
	$found = 0;
	foreach ( $items as $item ) {
		if ( awm_store_inventory_item( $snapshot_scan_id, 'current', $post->ID, get_the_title( $post ), $page_url, $item['url'], $item['text'], $item['source'] ?? '' ) ) {
			$found++;
		}
	}

	$existing_checkpoint = awm_inventory_checkpoint_gmt();
	$summary = awm_rebuild_inventory_summary( $snapshot_scan_id, 'current', 1 );
	update_option( 'awm_latest_scan', $summary, false );
	if ( $existing_checkpoint && ! get_option( 'awm_inventory_checkpoint_gmt', '' ) ) {
		update_option( 'awm_inventory_checkpoint_gmt', $existing_checkpoint, false );
	}

	wp_send_json_success(
		array(
			'post_id' => $post->ID,
			'title'   => get_the_title( $post ),
			'found'   => $found,
			'summary' => $summary,
		)
	);
}

/* -------------------------------------------------------------------------
 * wa.link resolver AJAX — admin only, manual/on-demand
 * ---------------------------------------------------------------------- */

add_action( 'wp_ajax_awm_shortlinks_start', 'awm_ajax_shortlinks_start' );
function awm_ajax_shortlinks_start() {
	check_ajax_referer( 'awm_shortlinks', 'nonce' );
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}

	$running = get_option( 'awm_scan_running', array() );
	if ( ! empty( $running['scan_id'] ) ) {
		$started_ts = isset( $running['started_ts'] ) ? absint( $running['started_ts'] ) : 0;
		if ( $started_ts && ( time() - $started_ts ) < AWM_SCAN_LOCK_TTL ) {
			wp_send_json_error( array( 'message' => 'Finish or cancel the active inventory scan before resolving shortlinks.' ), 409 );
		}
	}

	global $wpdb;
	$latest = get_option( 'awm_latest_scan', array() );
	if ( empty( $latest['scan_id'] ) ) {
		wp_send_json_error( array( 'message' => 'Run an inventory scan first.' ), 400 );
	}

	$table = awm_inventory_table();
	$items = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT destination FROM {$table} WHERE scan_id = %s AND status = 'shortlink' AND destination LIKE 'wa.link/%%' ORDER BY destination ASC LIMIT %d",
			$latest['scan_id'],
			AWM_SHORTLINK_RESOLVE_BATCH_LIMIT
		)
	);
	$items = array_values( array_filter( array_map( 'sanitize_text_field', (array) $items ) ) );
	wp_send_json_success( array( 'items' => $items, 'total' => count( $items ) ) );
}

add_action( 'wp_ajax_awm_resolve_shortlink', 'awm_ajax_resolve_shortlink' );
function awm_ajax_resolve_shortlink() {
	check_ajax_referer( 'awm_shortlinks', 'nonce' );
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}

	$destination = isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : '';
	$parsed = awm_parse_whatsapp_url( 'https://' . ltrim( $destination, '/' ) );
	if ( ! $parsed || empty( $parsed['is_shortlink'] ) || empty( $parsed['is_valid'] ) ) {
		wp_send_json_error( array( 'message' => 'Invalid wa.link shortlink.' ), 400 );
	}
	$destination = $parsed['destination'];

	$cached = awm_get_shortlink_resolution( $destination );
	if ( ! empty( $cached['number'] ) ) {
		awm_apply_shortlink_resolution_to_data( $destination, $cached['number'], $cached['source_label'] ?? '' );
		wp_send_json_success(
			array(
				'destination' => $destination,
				'resolved'    => true,
				'number'      => $cached['number'],
				'cached'      => true,
			)
		);
	}

	$result = awm_resolve_shortlink_remote( $destination );
	if ( is_wp_error( $result ) ) {
		awm_save_shortlink_failure( $destination, $result->get_error_message() );
		wp_send_json_success(
			array(
				'destination' => $destination,
				'resolved'    => false,
				'number'      => '',
				'error'       => $result->get_error_message(),
			)
		);
	}

	$number = awm_normalize_phone( $result['number'] ?? '' );
	if ( ! $number ) {
		awm_save_shortlink_failure( $destination, 'No valid phone number was returned.' );
		wp_send_json_success( array( 'destination' => $destination, 'resolved' => false, 'number' => '', 'error' => 'No valid phone number was returned.' ) );
	}

	awm_save_shortlink_resolution( $destination, $number, 'auto', $result['final_host'] ?? '' );
	awm_apply_shortlink_resolution_to_data( $destination, $number );
	awm_audit_log( 'shortlink_resolved', 'shortlink', $destination, array( 'number' => $number, 'method' => 'auto' ) );
	wp_send_json_success(
		array(
			'destination' => $destination,
			'resolved'    => true,
			'number'      => $number,
			'cached'      => false,
		)
	);
}

add_action( 'wp_ajax_awm_map_shortlink_manual', 'awm_ajax_map_shortlink_manual' );
function awm_ajax_map_shortlink_manual() {
	check_ajax_referer( 'awm_shortlinks', 'nonce' );
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}

	$destination = isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : '';
	$number      = isset( $_POST['number'] ) ? awm_normalize_phone( wp_unslash( $_POST['number'] ) ) : '';
	$source_label = isset( $_POST['source'] ) ? sanitize_text_field( substr( (string) wp_unslash( $_POST['source'] ), 0, 191 ) ) : '';
	$parsed      = awm_parse_whatsapp_url( 'https://' . ltrim( $destination, '/' ) );
	if ( ! $parsed || empty( $parsed['is_shortlink'] ) || empty( $parsed['is_valid'] ) || strlen( $number ) < 7 || strlen( $number ) > 15 ) {
		wp_send_json_error( array( 'message' => 'Choose a valid wa.link shortlink and enter a valid international phone number.' ), 400 );
	}

	$destination = $parsed['destination'];
	if ( '' !== $source_label && ! in_array( $source_label, awm_labels_for_destination( $number ), true ) ) {
		wp_send_json_error( array( 'message' => 'The selected Source / Use Case is not configured for this phone number under Settings.' ), 400 );
	}
	awm_save_shortlink_resolution( $destination, $number, 'manual', 'manual', $source_label );
	awm_apply_shortlink_resolution_to_data( $destination, $number, $source_label );
	awm_audit_log( 'shortlink_mapped', 'shortlink', $destination, array( 'number' => $number, 'source' => $source_label, 'method' => 'manual' ) );
	$summary = awm_refresh_latest_inventory_summary();
	wp_send_json_success( array( 'destination' => $destination, 'number' => $number, 'source' => $source_label, 'summary' => $summary ) );
}

add_action( 'wp_ajax_awm_shortlinks_finalize', 'awm_ajax_shortlinks_finalize' );
function awm_ajax_shortlinks_finalize() {
	check_ajax_referer( 'awm_shortlinks', 'nonce' );
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}
	$summary = awm_refresh_latest_inventory_summary();
	wp_send_json_success( array( 'summary' => $summary ) );
}

/* -------------------------------------------------------------------------
 * Inventory admin page
 * ---------------------------------------------------------------------- */

function awm_inventory_page() {
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		return;
	}

	global $wpdb;
	$table       = awm_inventory_table();
	$latest_scan = get_option( 'awm_latest_scan', array() );
	$nonce       = wp_create_nonce( 'awm_scan' );
	$shortlink_nonce = wp_create_nonce( 'awm_shortlinks' );
	$approved    = awm_approved_number_map();
	$approved_entries = awm_get_approved_numbers();
	$rows        = array();
	$unresolved_shortlinks = array();
	$all_shortlinks = array();
	$resolution_map = awm_get_shortlink_resolutions();
	$resolved_mapping_count = 0;
	$failed_mapping_count = 0;
	foreach ( $resolution_map as $resolution ) {
		if ( 'resolved' === ( $resolution['status'] ?? '' ) && ! empty( $resolution['number'] ) ) {
			$resolved_mapping_count++;
		} elseif ( 'failed' === ( $resolution['status'] ?? '' ) ) {
			$failed_mapping_count++;
		}
	}

	if ( ! empty( $latest_scan['scan_id'] ) && awm_table_exists( $table ) ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE scan_id = %s ORDER BY status DESC, destination ASC, page_title ASC LIMIT 500", $latest_scan['scan_id'] ) );
		$unresolved_shortlinks = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT destination FROM {$table} WHERE scan_id = %s AND status = 'shortlink' AND destination LIKE 'wa.link/%%' ORDER BY destination ASC", $latest_scan['scan_id'] ) );
		$all_shortlinks = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT destination FROM {$table} WHERE scan_id = %s AND link_type = 'wa.link' AND destination LIKE 'wa.link/%%' ORDER BY destination ASC", $latest_scan['scan_id'] ) );
	}
	?>
	<div id="awm-scanner-config" class="awm-config" hidden data-config="<?php echo esc_attr( wp_json_encode( array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => $nonce, 'shortlinkNonce' => $shortlink_nonce, 'scanDelay' => AWM_SCAN_DELAY_MS ) ) ); ?>"></div>
	<div class="wrap awm-wrap">
		<h1>WhatsApp Link Inventory</h1>
		<p class="awm-muted">The scanner runs only when an administrator starts it. It does not run on normal visitor requests or scheduled crawler jobs.</p>

		<div class="awm-card awm-mb16">
			<h2>Routine Updates</h2>
			<p class="awm-muted">Use these after a base Quick or Deep Scan. They update only changed/selected pages and preserve the rest of the inventory.</p>
			<div class="awm-toolbar">
				<button type="button" class="button button-primary" id="awm-recent-scan" <?php disabled( empty( $latest_scan['scan_id'] ) ); ?>>Scan Recent Changes</button>
				<input type="url" id="awm-current-url" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/example-page/' ) ); ?>" aria-label="Page URL to scan">
				<button type="button" class="button" id="awm-current-scan" <?php disabled( empty( $latest_scan['scan_id'] ) ); ?>>Scan Current Page</button>
			</div>
			<p class="description"><strong>Recent Changes</strong> checks only published content modified since the last successful inventory checkpoint using the lightweight database scan. <strong>Current Page</strong> fetches one rendered local page only, so it can detect dynamic/template-generated WhatsApp links without rescanning the whole site.</p>
		</div>

		<div class="awm-card awm-mb16">
			<h2>Full Inventory Scans</h2>
			<div class="awm-toolbar">
				<button type="button" class="button" id="awm-quick-scan">Run Full Quick Scan</button>
				<button type="button" class="button" id="awm-deep-scan">Run Full Deep Scan</button>
				<button type="button" class="button" id="awm-cancel-scan" hidden>Cancel Scan</button>
				<?php if ( ! empty( $latest_scan['scan_id'] ) ) : ?><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=awm_export_inventory' ), 'awm_export_inventory' ) ); ?>">Export Inventory CSV</a><?php endif; ?>
				<span id="awm-scan-status" class="awm-muted"></span>
			</div>
			<div class="awm-progress-wrap" id="awm-progress" hidden><progress class="awm-progress-meter" id="awm-progress-meter" max="100" value="0">0%</progress></div>
			<p class="description">Full Quick Scan reads up to <?php echo esc_html( AWM_QUICK_SCAN_BATCH ); ?> published items per request. Full Deep Scan fetches only <?php echo esc_html( AWM_DEEP_SCAN_BATCH ); ?> rendered public page per request, waits between batches, has a <?php echo esc_html( AWM_DEEP_SCAN_TIMEOUT ); ?>-second request timeout and caps each HTML response at <?php echo esc_html( size_format( AWM_DEEP_SCAN_MAX_BYTES ) ); ?>.</p>
			<div class="awm-warning"><strong>Production-safe scanner:</strong> PHP memory limit: <?php echo esc_html( ini_get( 'memory_limit' ) ? ini_get( 'memory_limit' ) : 'Unknown' ); ?>. Deep Scan is intentionally slow to reduce PHP-worker and RAM pressure. Routine changes should normally use Recent Changes or Current Page instead.</div>
		</div>

		<div class="awm-card awm-mb16">
			<h2>wa.link Shortlink Resolution</h2>
			<p class="awm-muted">Resolve <code>wa.link/...</code> destinations to their actual WhatsApp phone number. Resolution is <strong>manual and admin-triggered only</strong>; the plugin never follows shortlinks during normal visitor traffic.</p>
			<div class="awm-grid awm-my12">
				<div class="awm-card"><div class="awm-muted">Unresolved — Current Inventory</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( count( $unresolved_shortlinks ) ) ); ?></div></div>
				<div class="awm-card"><div class="awm-muted">Cached Resolutions</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( $resolved_mapping_count ) ); ?></div></div>
				<div class="awm-card"><div class="awm-muted">Previous Failed Attempts</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( $failed_mapping_count ) ); ?></div></div>
			</div>
			<div class="awm-toolbar">
				<button type="button" class="button button-primary" id="awm-resolve-shortlinks" <?php disabled( empty( $unresolved_shortlinks ) ); ?>>Resolve Shortlinks</button>
				<span id="awm-shortlink-status" class="awm-muted"></span>
			</div>
			<div class="awm-progress-wrap" id="awm-shortlink-progress" hidden><progress class="awm-progress-meter" id="awm-shortlink-progress-meter" max="100" value="0">0%</progress></div>
			<p class="description">Automatic resolution processes one shortlink per request, waits between requests, and only follows redirects on WhatsApp-owned domains. Successful mappings are cached and reused by future tracking/scans.</p>

			<?php if ( ! empty( $all_shortlinks ) ) : ?>
			<hr>
			<h3>Manual Mapping / Attribution — fallback or override</h3>
			<p class="awm-muted">Use this if automatic resolution fails, or to assign a specific <strong>Source / Use Case</strong> to a known shortlink when the same phone number has multiple labels. This can also override an existing cached mapping.</p>
			<div class="awm-toolbar">
				<input type="text" id="awm-manual-shortlink" class="regular-text" list="awm-shortlink-list" placeholder="wa.link/abc123" aria-label="wa.link shortlink">
				<datalist id="awm-shortlink-list"><?php foreach ( $all_shortlinks as $shortlink ) : ?><option value="<?php echo esc_attr( $shortlink ); ?>"></option><?php endforeach; ?></datalist>
				<input type="text" id="awm-manual-number" class="regular-text" inputmode="numeric" placeholder="12025550100" aria-label="WhatsApp phone number">
				<select id="awm-manual-source" aria-label="Source or use case"><option value="" data-number="">No specific source</option><?php foreach ( $approved_entries as $entry ) : ?><option value="<?php echo esc_attr( $entry['label'] ); ?>" data-number="<?php echo esc_attr( $entry['number'] ); ?>"><?php echo esc_html( $entry['label'] . ' — ' . $entry['number'] ); ?></option><?php endforeach; ?></select>
				<button type="button" class="button" id="awm-save-shortlink-map">Save Mapping</button>
			</div>
			<p class="description">Selecting a Source / Use Case fills its approved number automatically. If you leave Source blank, a single configured label for that number is still assigned automatically; multi-label numbers remain Unassigned.</p>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $latest_scan ) ) : ?>
			<div class="awm-grid">
				<div class="awm-card"><div class="awm-muted">Pages Checked — Last Run</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( (int) $latest_scan['pages_scanned'] ) ); ?></div></div>
				<div class="awm-card"><div class="awm-muted">Inventory Pages With Links</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( (int) ( $latest_scan['inventory_pages'] ?? 0 ) ) ); ?></div></div>
				<div class="awm-card"><div class="awm-muted">Links Found</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( (int) $latest_scan['links_found'] ) ); ?></div></div>
				<div class="awm-card"><div class="awm-muted">Warnings</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( (int) $latest_scan['unknown'] + (int) $latest_scan['shortlinks'] + (int) $latest_scan['malformed'] + (int) ( $latest_scan['unassigned_sources'] ?? 0 ) ) ); ?></div></div>
			</div>
			<p><strong>Latest inventory update:</strong> <?php echo esc_html( awm_scan_mode_label( $latest_scan['mode'] ?? '' ) ); ?> · <?php echo esc_html( $latest_scan['completed_at'] ); ?></p>
			<?php if ( ! empty( $latest_scan['unassigned_sources'] ) ) : ?><div class="awm-warning"><strong>Attribution warning:</strong> <?php echo esc_html( number_format_i18n( (int) $latest_scan['unassigned_sources'] ) ); ?> WhatsApp link(s) use a number with multiple configured labels but do not have a source/use-case tag yet.</div><?php endif; ?>
		<?php else : ?>
			<div class="awm-warning"><strong>No base inventory yet.</strong> Run a Full Quick Scan first. Use Full Deep Scan later when you need rendered-page verification.</div>
		<?php endif; ?>

		<div class="awm-card">
			<h2>Current Inventory</h2>
			<p class="awm-muted">Showing up to 500 links from the current inventory snapshot. For numbers with multiple configured labels, <strong>Unassigned</strong> means the CTA has not yet been tagged with a source/use-case.</p>
			<div class="awm-table-wrap">
			<table class="widefat fixed striped">
				<thead><tr><th class="awm-col-status">Status</th><th class="awm-col-destination">Destination</th><th class="awm-col-source">Source / Use Case</th><th>Page</th><th>Link</th><th class="awm-col-linktext">Link Text</th></tr></thead>
				<tbody>
				<?php if ( $rows ) : foreach ( $rows as $row ) : $effective_destination = awm_effective_destination_value( $row->destination, $row->resolved_destination ); ?>
					<tr>
						<td><span class="awm-status awm-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span><?php if ( 'wa.link' === $row->link_type && $row->resolved_destination ) : ?><br><span class="awm-muted">Resolved shortlink</span><?php endif; ?></td>
						<td><strong><?php echo esc_html( $effective_destination ? $effective_destination : 'Unresolved' ); ?></strong><?php if ( $row->resolved_destination ) : ?><br><span class="awm-muted">via <?php echo esc_html( $row->destination ); ?></span><?php endif; ?><?php if ( isset( $approved[ $effective_destination ] ) ) : ?><br><span class="awm-muted"><?php echo esc_html( $approved[ $effective_destination ] ); ?></span><?php endif; ?></td>
						<td><?php if ( $row->source_label ) : ?><?php echo esc_html( $row->source_label ); ?><?php elseif ( count( awm_labels_for_destination( $effective_destination ) ) > 1 ) : ?><span class="awm-status awm-status-unknown">Unassigned</span><?php else : ?><?php echo esc_html( awm_resolve_source_label( $effective_destination, '' ) ?: 'Unassigned' ); ?><?php endif; ?></td>
						<td><a href="<?php echo esc_url( $row->page_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row->page_title ? $row->page_title : $row->page_url ); ?></a></td>
						<td><code><?php echo esc_html( $row->link_url ); ?></code></td>
						<td><?php echo esc_html( $row->link_text ? $row->link_text : '—' ); ?></td>
					</tr>
				<?php endforeach; else : ?>
					<tr><td colspan="6">No inventory yet. Run a scan above.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			</div>
		</div>
	</div>
	<?php
}
