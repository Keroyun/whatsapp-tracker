<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 * Safe bulk migration engine
 * ---------------------------------------------------------------------- */

function awm_migration_post_types() {
	$types = awm_scannable_post_types();
	/* Breakdance reusable/global content may use a non-public post type. */
	foreach ( get_post_types( array(), 'names' ) as $type ) {
		if ( false !== stripos( $type, 'breakdance' ) ) {
			$types[] = $type;
		}
	}
	$types = array_values( array_unique( array_filter( $types ) ) );
	return apply_filters( 'awm_migration_post_types', $types );
}

function awm_migration_total_posts() {
	global $wpdb;
	$types = awm_migration_post_types();
	if ( ! $types ) {
		return 0;
	}
	$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status IN ('publish','private')";
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $types ) );
}

function awm_migration_posts_batch( $offset, $limit ) {
	return get_posts(
		array(
			'post_type'              => awm_migration_post_types(),
			'post_status'            => array( 'publish', 'private' ),
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

function awm_get_migration_run( $run_id ) {
	global $wpdb;
	$table  = awm_migration_runs_table();
	$run_id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $run_id );
	if ( ! $run_id || ! awm_table_exists( $table ) ) {
		return null;
	}
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s", $run_id ) );
}

function awm_migration_serialize( $value ) {
	return maybe_serialize( $value );
}

function awm_migration_hash( $value ) {
	return hash( 'sha256', awm_migration_serialize( $value ) );
}

function awm_migration_value_is_supported( $value ) {
	if ( is_null( $value ) || is_scalar( $value ) ) {
		return true;
	}
	if ( ! is_array( $value ) ) {
		return false;
	}
	foreach ( $value as $item ) {
		if ( ! awm_migration_value_is_supported( $item ) ) {
			return false;
		}
	}
	return true;
}

function awm_migration_decode_value( $serialized ) {
	$serialized = (string) $serialized;
	if ( ! is_serialized( $serialized ) ) {
		return $serialized;
	}
	$value = @unserialize( trim( $serialized ), array( 'allowed_classes' => false ) );
	if ( ! awm_migration_value_is_supported( $value ) ) {
		return new WP_Error( 'awm_unsafe_migration_value', 'Stored backup contains an unsupported object/resource and was not written.' );
	}
	return $value;
}

function awm_migration_page_matches( $pattern, $url ) {
	$pattern = awm_emergency_normalize_page_path( $pattern );
	if ( '*' === $pattern ) {
		return true;
	}
	$path = '/' . ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
	if ( '*' === substr( $pattern, -1 ) ) {
		return 0 === strpos( $path, substr( $pattern, 0, -1 ) );
	}
	return untrailingslashit( $path ) === untrailingslashit( $pattern );
}

function awm_migration_normalize_match_value( $match_type, $value ) {
	if ( 'exact_link' === $match_type ) {
		$parsed = awm_parse_whatsapp_url( trim( (string) $value ) );
		if ( ! $parsed || 'managed-route' === ( $parsed['link_type'] ?? '' ) ) {
			return '';
		}
		return $parsed['url'];
	}
	return awm_emergency_normalize_destination( $value );
}

function awm_migration_link_matches( $url, $run ) {
	$url = html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$url = str_replace( array( '\\/', '\\u0026', '\\u003A' ), array( '/', '&', ':' ), $url );
	$parsed = awm_parse_whatsapp_url( $url );
	if ( ! $parsed || 'managed-route' === ( $parsed['link_type'] ?? '' ) ) {
		return false;
	}
	if ( 'exact_link' === $run->match_type ) {
		return hash_equals( (string) $run->match_value, (string) $parsed['url'] );
	}
	$effective = awm_effective_destination_from_parsed( $parsed );
	return (string) $run->match_value === (string) $parsed['destination'] || (string) $run->match_value === (string) $effective;
}

function awm_migration_replacement_language( $run, $post_id ) {
	$language = sanitize_key( (string) $run->language_mode );
	return ( 'auto' !== $language && $language ) ? $language : awm_detect_post_language( $post_id );
}

function awm_migration_shortcode_attribute( $value ) {
	return str_replace( '"', '&quot;', sanitize_text_field( (string) $value ) );
}

function awm_migration_replace_string( $value, $run, $post_id, &$occurrences ) {
	$value       = (string) $value;
	$language    = awm_migration_replacement_language( $run, $post_id );
	$replacement = awm_managed_route_url( $run->route_slug, $language );
	$callback = function ( $matches ) use ( $run, $replacement, &$occurrences ) {
		$raw       = (string) $matches[0];
		$candidate = rtrim( $raw, ".,;:)\"']}" );
		$suffix    = substr( $raw, strlen( $candidate ) );
		if ( ! awm_migration_link_matches( $candidate, $run ) ) {
			return $raw;
		}
		$occurrences++;
		if ( false !== strpos( $candidate, '\\/' ) ) {
			return str_replace( '/', '\\/', $replacement ) . $suffix;
		}
		return $replacement . $suffix;
	};

	$standard = '~https?://(?:wa\.me|api\.whatsapp\.com|web\.whatsapp\.com|wa\.link)(?:/[^\s"\'<>]*)?~i';
	$escaped  = '~https?:\\\\/\\\\/(?:wa\.me|api\.whatsapp\.com|web\.whatsapp\.com|wa\.link)(?:\\\\/|[^\s"\'<>\\\\])*~i';
	$value    = preg_replace_callback( $standard, $callback, $value );
	$value    = preg_replace_callback( $escaped, $callback, $value );

	$value = preg_replace_callback(
		'/\[whatsapp-link\b([^\]]*)\]/i',
		function ( $matches ) use ( $run, $language, &$occurrences ) {
			$atts   = shortcode_parse_atts( $matches[1] );
			$atts   = is_array( $atts ) ? $atts : array();
			$phone  = awm_normalize_phone( $atts['phone'] ?? '' );
			$url    = $phone ? 'https://wa.me/' . $phone : '';
			if ( $url && ! empty( $atts['pretext'] ) ) {
				$url .= '?text=' . rawurlencode( sanitize_text_field( $atts['pretext'] ) );
			}
			if ( ! $url || ! awm_migration_link_matches( $url, $run ) ) {
				return $matches[0];
			}
			$occurrences++;
			$title = isset( $atts['title'] ) ? awm_migration_shortcode_attribute( $atts['title'] ) : 'Chat on WhatsApp';
			return '[whatsapp-route route="' . awm_migration_shortcode_attribute( $run->route_slug ) . '" title="' . $title . '" lang="' . awm_migration_shortcode_attribute( $language ) . '"]';
		},
		$value
	);

	return $value;
}

function awm_migration_transform_value( $value, $run, $post_id, &$occurrences ) {
	if ( is_string( $value ) ) {
		return awm_text_may_contain_whatsapp( $value ) || false !== stripos( $value, '[whatsapp-link' )
			? awm_migration_replace_string( $value, $run, $post_id, $occurrences )
			: $value;
	}
	if ( is_array( $value ) ) {
		$result = array();
		foreach ( $value as $key => $item ) {
			$result[ $key ] = awm_migration_transform_value( $item, $run, $post_id, $occurrences );
		}
		return $result;
	}
	return $value;
}

function awm_migration_store_item( $run, $post_id, $storage_type, $meta_key, $old_value, $new_value, $occurrences ) {
	global $wpdb;
	$table = awm_migration_items_table();
	$item_key = hash( 'sha256', $run->run_id . '|' . absint( $post_id ) . '|' . $storage_type . '|' . $meta_key );
	if ( ! awm_migration_value_is_supported( $old_value ) || ! awm_migration_value_is_supported( $new_value ) ) {
		return false;
	}
	$old_serialized = awm_migration_serialize( $old_value );
	$new_serialized = awm_migration_serialize( $new_value );
	if ( strlen( $old_serialized ) > AWM_MIGRATION_MAX_FIELD_BYTES || strlen( $new_serialized ) > AWM_MIGRATION_MAX_FIELD_BYTES ) {
		return false;
	}
	return false !== $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (item_key, run_id, post_id, storage_type, meta_key, old_value, new_value, old_hash, new_hash, occurrences, status, error_message, created_at, updated_at)
			VALUES (%s,%s,%d,%s,%s,%s,%s,%s,%s,%d,'planned','',%s,%s)
			ON DUPLICATE KEY UPDATE occurrences = VALUES(occurrences), updated_at = VALUES(updated_at)",
			$item_key,
			$run->run_id,
			absint( $post_id ),
			$storage_type,
			(string) $meta_key,
			$old_serialized,
			$new_serialized,
			hash( 'sha256', $old_serialized ),
			hash( 'sha256', $new_serialized ),
			absint( $occurrences ),
			current_time( 'mysql' ),
			current_time( 'mysql' )
		)
	);
}

function awm_migration_plan_post( $run, $post ) {
	$page_url = get_permalink( $post );
	if ( ! $page_url && '*' === awm_emergency_normalize_page_path( $run->page_scope ) ) {
		$page_url = home_url( '/' );
	}
	if ( ! $page_url || ! awm_migration_page_matches( $run->page_scope, $page_url ) ) {
		return;
	}

	foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
		$old = (string) $post->{$field};
		$occurrences = 0;
		$new = awm_migration_transform_value( $old, $run, $post->ID, $occurrences );
		if ( $occurrences > 0 && $new !== $old ) {
			awm_migration_store_item( $run, $post->ID, $field, '', $old, $new, $occurrences );
		}
	}

	$all_meta = get_post_meta( $post->ID );
	foreach ( $all_meta as $meta_key => $values ) {
		if ( in_array( $meta_key, array( '_edit_lock', '_edit_last' ), true ) || 0 === strpos( $meta_key, '_awm_' ) ) {
			continue;
		}
		$old = (array) $values;
		$occurrences = 0;
		$new = awm_migration_transform_value( $old, $run, $post->ID, $occurrences );
		if ( $occurrences > 0 && awm_migration_hash( $new ) !== awm_migration_hash( $old ) ) {
			awm_migration_store_item( $run, $post->ID, 'post_meta', $meta_key, $old, $new, $occurrences );
		}
	}
}

function awm_migration_refresh_run_counts( $run_id ) {
	global $wpdb;
	$runs  = awm_migration_runs_table();
	$items = awm_migration_items_table();
	$summary = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT COUNT(*) total_items, COALESCE(SUM(occurrences),0) total_occurrences,
			SUM(status='applied') applied_items, SUM(status='rolled_back') rolled_back_items,
			SUM(status='conflict') conflict_items FROM {$items} WHERE run_id = %s",
			$run_id
		)
	);
	$wpdb->update(
		$runs,
		array(
			'total_items'        => (int) ( $summary->total_items ?? 0 ),
			'total_occurrences'  => (int) ( $summary->total_occurrences ?? 0 ),
			'applied_items'      => (int) ( $summary->applied_items ?? 0 ),
			'rolled_back_items'  => (int) ( $summary->rolled_back_items ?? 0 ),
			'conflict_items'     => (int) ( $summary->conflict_items ?? 0 ),
			'updated_at'         => current_time( 'mysql' ),
		),
		array( 'run_id' => $run_id )
	);
}

function awm_migration_current_value( $item ) {
	$post = get_post( $item->post_id );
	if ( ! $post ) {
		return new WP_Error( 'awm_missing_post', 'The content item no longer exists.' );
	}
	if ( 'post_content' === $item->storage_type ) {
		return (string) $post->post_content;
	}
	if ( 'post_excerpt' === $item->storage_type ) {
		return (string) $post->post_excerpt;
	}
	if ( 'post_meta' === $item->storage_type ) {
		return (array) get_post_meta( $item->post_id, $item->meta_key, false );
	}
	return new WP_Error( 'awm_invalid_storage', 'Unsupported storage type.' );
}

function awm_migration_write_value( $item, $value ) {
	if ( 'post_content' === $item->storage_type || 'post_excerpt' === $item->storage_type ) {
		$result = wp_update_post(
			array(
				'ID' => absint( $item->post_id ),
				/* Core unslashes post data before storing it. Preserve JSON/backslashes. */
				$item->storage_type => wp_slash( (string) $value ),
			),
			true
		);
		return is_wp_error( $result ) ? $result : true;
	}
	if ( 'post_meta' === $item->storage_type ) {
		if ( ! is_array( $value ) || ! awm_migration_value_is_supported( $value ) ) {
			return new WP_Error( 'awm_invalid_meta_backup', 'The stored metadata backup is invalid.' );
		}
		$before = (array) get_post_meta( $item->post_id, $item->meta_key, false );
		delete_post_meta( $item->post_id, $item->meta_key );
		foreach ( $value as $meta_value ) {
			/* Metadata APIs also unslash values before serializing them. */
			if ( false === add_post_meta( $item->post_id, $item->meta_key, wp_slash( $meta_value ) ) ) {
				delete_post_meta( $item->post_id, $item->meta_key );
				foreach ( $before as $restore_value ) {
					add_post_meta( $item->post_id, $item->meta_key, wp_slash( $restore_value ) );
				}
				clean_post_cache( $item->post_id );
				return new WP_Error( 'awm_meta_write_failed', 'Metadata write failed; the previous values were restored.' );
			}
		}
		clean_post_cache( $item->post_id );
		return true;
	}
	return new WP_Error( 'awm_invalid_storage', 'Unsupported storage type.' );
}

function awm_migration_process_item( $item, $direction ) {
	global $wpdb;
	$table   = awm_migration_items_table();
	$current = awm_migration_current_value( $item );
	if ( is_wp_error( $current ) ) {
		$wpdb->update( $table, array( 'status' => 'conflict', 'error_message' => $current->get_error_message(), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $item->id ) );
		return false;
	}

	$expected_hash = 'rollback' === $direction ? $item->new_hash : $item->old_hash;
	if ( ! hash_equals( (string) $expected_hash, awm_migration_hash( $current ) ) ) {
		$wpdb->update( $table, array( 'status' => 'conflict', 'error_message' => 'The stored field changed after the preview. No overwrite was performed.', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $item->id ) );
		return false;
	}

	$serialized = 'rollback' === $direction ? $item->old_value : $item->new_value;
	$value      = awm_migration_decode_value( $serialized );
	if ( is_wp_error( $value ) ) {
		$wpdb->update( $table, array( 'status' => 'conflict', 'error_message' => $value->get_error_message(), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $item->id ) );
		return false;
	}
	$result     = awm_migration_write_value( $item, $value );
	if ( is_wp_error( $result ) ) {
		$wpdb->update( $table, array( 'status' => 'conflict', 'error_message' => $result->get_error_message(), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $item->id ) );
		return false;
	}
	$written = awm_migration_current_value( $item );
	$target_hash = 'rollback' === $direction ? $item->old_hash : $item->new_hash;
	if ( is_wp_error( $written ) || ! hash_equals( (string) $target_hash, awm_migration_hash( $written ) ) ) {
		$message = is_wp_error( $written ) ? $written->get_error_message() : 'WordPress or a content filter changed the field during writing. Review this field manually; its backup was retained.';
		$wpdb->update( $table, array( 'status' => 'conflict', 'error_message' => $message, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $item->id ) );
		return false;
	}
	$wpdb->update( $table, array( 'status' => 'rollback' === $direction ? 'rolled_back' : 'applied', 'error_message' => '', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $item->id ) );
	return true;
}

function awm_migration_ajax_guard() {
	check_ajax_referer( 'awm_migration', 'nonce' );
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
	}
}

add_action( 'wp_ajax_awm_migration_preview_start', 'awm_ajax_migration_preview_start' );
function awm_ajax_migration_preview_start() {
	awm_migration_ajax_guard();
	global $wpdb;
	$route_slug   = awm_managed_route_slug( isset( $_POST['route_slug'] ) ? wp_unslash( $_POST['route_slug'] ) : '' );
	$match_type   = isset( $_POST['match_type'] ) && 'exact_link' === sanitize_key( wp_unslash( $_POST['match_type'] ) ) ? 'exact_link' : 'destination';
	$match_value  = awm_migration_normalize_match_value( $match_type, isset( $_POST['match_value'] ) ? wp_unslash( $_POST['match_value'] ) : '' );
	$page_scope   = awm_emergency_normalize_page_path( isset( $_POST['page_scope'] ) ? wp_unslash( $_POST['page_scope'] ) : '*' );
	$language     = isset( $_POST['language_mode'] ) ? sanitize_key( wp_unslash( $_POST['language_mode'] ) ) : 'auto';
	$language     = ( 'auto' === $language || in_array( $language, awm_available_languages(), true ) ) ? $language : 'auto';
	$route = awm_get_managed_route( $route_slug );
	if ( ! $route || ! awm_managed_route_destination_url( $route, awm_default_language() ) || ! $match_value ) {
		wp_send_json_error( array( 'message' => 'Choose an active central route with a usable number, and enter a valid source destination or exact WhatsApp link.' ), 400 );
	}

	$run_id = wp_generate_uuid4();
	$now    = current_time( 'mysql' );
	$inserted = $wpdb->insert(
		awm_migration_runs_table(),
		array(
			'run_id' => $run_id, 'route_slug' => $route_slug, 'match_type' => $match_type,
			'match_value' => $match_value, 'page_scope' => $page_scope ?: '*', 'language_mode' => $language,
			'status' => 'previewing', 'total_posts' => awm_migration_total_posts(), 'processed_posts' => 0,
			'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now, 'last_error' => '',
		)
	);
	if ( false === $inserted ) {
		wp_send_json_error( array( 'message' => 'Unable to create the migration preview.' ), 500 );
	}
	awm_audit_log( 'migration_preview_started', 'migration', $run_id, array( 'route_slug' => $route_slug, 'match_type' => $match_type, 'page_scope' => $page_scope ?: '*', 'language' => $language ) );
	wp_send_json_success( array( 'run_id' => $run_id, 'processed' => 0, 'total' => awm_migration_total_posts() ) );
}

add_action( 'wp_ajax_awm_migration_preview_batch', 'awm_ajax_migration_preview_batch' );
function awm_ajax_migration_preview_batch() {
	awm_migration_ajax_guard();
	$awm_lock = awm_acquire_lock( 'awm_ajax_migration_preview_batch_' . ( isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : 'none' ), AWM_MIGRATION_LOCK_TTL );
	if ( is_wp_error( $awm_lock ) ) {
		wp_send_json_error( array( 'message' => $awm_lock->get_error_message() ), 409 );
	}
	global $wpdb;
	$run_id = isset( $_POST['run_id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_POST['run_id'] ) ) : '';
	$run    = awm_get_migration_run( $run_id );
	if ( ! $run || 'previewing' !== $run->status ) {
		awm_release_lock( __FUNCTION__ . '_' . ( $run_id ?: 'none' ), $awm_lock );
		wp_send_json_error( array( 'message' => 'This migration preview is not available.' ), 400 );
	}
	$offset = absint( $run->processed_posts );
	$posts  = awm_migration_posts_batch( $offset, AWM_MIGRATION_PREVIEW_BATCH );
	foreach ( $posts as $post ) {
		awm_migration_plan_post( $run, $post );
	}
	$processed = min( (int) $run->total_posts, $offset + count( $posts ) );
	$done      = ! $posts || $processed >= (int) $run->total_posts;
	$wpdb->update( awm_migration_runs_table(), array( 'processed_posts' => $processed, 'status' => $done ? 'ready' : 'previewing', 'updated_at' => current_time( 'mysql' ) ), array( 'run_id' => $run_id ) );
	awm_migration_refresh_run_counts( $run_id );
	$updated = awm_get_migration_run( $run_id );
	if ( $done ) {
		awm_audit_log( 'migration_preview_ready', 'migration', $run_id, array( 'items' => (int) $updated->total_items, 'occurrences' => (int) $updated->total_occurrences ) );
	}
	awm_release_lock( 'awm_ajax_migration_preview_batch_' . ( $run_id ?: 'none' ), $awm_lock );
	wp_send_json_success( array( 'run_id' => $run_id, 'processed' => $processed, 'total' => (int) $run->total_posts, 'done' => $done, 'items' => (int) $updated->total_items, 'occurrences' => (int) $updated->total_occurrences ) );
}

add_action( 'wp_ajax_awm_migration_apply_batch', 'awm_ajax_migration_apply_batch' );
function awm_ajax_migration_apply_batch() {
	awm_migration_ajax_guard();
	$awm_lock = awm_acquire_lock( 'awm_ajax_migration_apply_batch_' . ( isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : 'none' ), AWM_MIGRATION_LOCK_TTL );
	if ( is_wp_error( $awm_lock ) ) {
		wp_send_json_error( array( 'message' => $awm_lock->get_error_message() ), 409 );
	}
	global $wpdb;
	$run_id = isset( $_POST['run_id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_POST['run_id'] ) ) : '';
	$run    = awm_get_migration_run( $run_id );
	if ( ! $run || ! in_array( $run->status, array( 'ready', 'applying' ), true ) ) {
		awm_release_lock( __FUNCTION__ . '_' . ( $run_id ?: 'none' ), $awm_lock );
		wp_send_json_error( array( 'message' => 'This migration is not ready to apply.' ), 400 );
	}
	$wpdb->update( awm_migration_runs_table(), array( 'status' => 'applying', 'updated_at' => current_time( 'mysql' ) ), array( 'run_id' => $run_id ) );
	$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . awm_migration_items_table() . " WHERE run_id = %s AND status = 'planned' ORDER BY id ASC LIMIT %d", $run_id, AWM_MIGRATION_WRITE_BATCH ) );
	foreach ( $items as $item ) {
		awm_migration_process_item( $item, 'apply' );
	}
	$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . awm_migration_items_table() . " WHERE run_id = %s AND status = 'planned'", $run_id ) );
	awm_migration_refresh_run_counts( $run_id );
	$updated = awm_get_migration_run( $run_id );
	if ( 0 === $remaining ) {
		$status = (int) $updated->conflict_items > 0 ? 'partial' : 'applied';
		$wpdb->update( awm_migration_runs_table(), array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'run_id' => $run_id ) );
		$updated = awm_get_migration_run( $run_id );
		awm_audit_log( 'migration_applied', 'migration', $run_id, array( 'status' => $status, 'applied_items' => (int) $updated->applied_items, 'conflicts' => (int) $updated->conflict_items ) );
	}
	awm_release_lock( 'awm_ajax_migration_apply_batch_' . ( $run_id ?: 'none' ), $awm_lock );
	wp_send_json_success( array( 'run_id' => $run_id, 'remaining' => $remaining, 'done' => 0 === $remaining, 'applied' => (int) $updated->applied_items, 'conflicts' => (int) $updated->conflict_items ) );
}

add_action( 'wp_ajax_awm_migration_rollback_batch', 'awm_ajax_migration_rollback_batch' );
function awm_ajax_migration_rollback_batch() {
	awm_migration_ajax_guard();
	$awm_lock = awm_acquire_lock( 'awm_ajax_migration_rollback_batch_' . ( isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : 'none' ), AWM_MIGRATION_LOCK_TTL );
	if ( is_wp_error( $awm_lock ) ) {
		wp_send_json_error( array( 'message' => $awm_lock->get_error_message() ), 409 );
	}
	global $wpdb;
	$run_id = isset( $_POST['run_id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_POST['run_id'] ) ) : '';
	$run    = awm_get_migration_run( $run_id );
	if ( ! $run || ! in_array( $run->status, array( 'applied', 'partial', 'rolling_back', 'rollback_partial' ), true ) ) {
		awm_release_lock( __FUNCTION__ . '_' . ( $run_id ?: 'none' ), $awm_lock );
		wp_send_json_error( array( 'message' => 'This migration cannot be rolled back.' ), 400 );
	}
	$wpdb->update( awm_migration_runs_table(), array( 'status' => 'rolling_back', 'updated_at' => current_time( 'mysql' ) ), array( 'run_id' => $run_id ) );
	$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . awm_migration_items_table() . " WHERE run_id = %s AND status = 'applied' ORDER BY id DESC LIMIT %d", $run_id, AWM_MIGRATION_WRITE_BATCH ) );
	foreach ( $items as $item ) {
		awm_migration_process_item( $item, 'rollback' );
	}
	$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . awm_migration_items_table() . " WHERE run_id = %s AND status = 'applied'", $run_id ) );
	awm_migration_refresh_run_counts( $run_id );
	$updated = awm_get_migration_run( $run_id );
	if ( 0 === $remaining ) {
		$status = (int) $updated->conflict_items > 0 ? 'rollback_partial' : 'rolled_back';
		$wpdb->update( awm_migration_runs_table(), array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'run_id' => $run_id ) );
		$updated = awm_get_migration_run( $run_id );
		awm_audit_log( 'migration_rolled_back', 'migration', $run_id, array( 'status' => $status, 'rolled_back_items' => (int) $updated->rolled_back_items, 'conflicts' => (int) $updated->conflict_items ) );
	}
	awm_release_lock( 'awm_ajax_migration_rollback_batch_' . ( $run_id ?: 'none' ), $awm_lock );
	wp_send_json_success( array( 'run_id' => $run_id, 'remaining' => $remaining, 'done' => 0 === $remaining, 'rolled_back' => (int) $updated->rolled_back_items, 'conflicts' => (int) $updated->conflict_items ) );
}

add_action( 'admin_post_awm_delete_migration_run', 'awm_delete_migration_run' );
function awm_delete_migration_run() {
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'awm_delete_migration_run' );
	global $wpdb;
	$run_id = isset( $_POST['run_id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_POST['run_id'] ) ) : '';
	$run    = awm_get_migration_run( $run_id );
	if ( ! $run || (int) $run->applied_items > 0 ) {
		wp_safe_redirect( admin_url( 'admin.php?page=awm-migration&awm_notice=delete_blocked' ) );
		exit;
	}
	$wpdb->delete( awm_migration_items_table(), array( 'run_id' => $run_id ) );
	$wpdb->delete( awm_migration_runs_table(), array( 'run_id' => $run_id ) );
	awm_audit_log( 'migration_deleted', 'migration', $run_id );
	wp_safe_redirect( admin_url( 'admin.php?page=awm-migration&awm_notice=deleted' ) );
	exit;
}

function awm_bulk_migration_page() {
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		return;
	}
	global $wpdb;
	$routes = awm_get_managed_routes();
	$runs   = awm_table_exists( awm_migration_runs_table() ) ? $wpdb->get_results( "SELECT * FROM " . awm_migration_runs_table() . " ORDER BY id DESC LIMIT 20" ) : array();
	$view_id = isset( $_GET['run'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_GET['run'] ) ) : '';
	$view_run = $view_id ? awm_get_migration_run( $view_id ) : null;
	$view_items = $view_run ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . awm_migration_items_table() . " WHERE run_id = %s ORDER BY id ASC LIMIT 100", $view_id ) ) : array();
	$nonce = wp_create_nonce( 'awm_migration' );
	$notice = isset( $_GET['awm_notice'] ) ? sanitize_key( wp_unslash( $_GET['awm_notice'] ) ) : '';
	?>
	<div id="awm-migration-config" class="awm-config" hidden data-config="<?php echo esc_attr( wp_json_encode( array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => $nonce, 'migrationUrl' => admin_url( 'admin.php?page=awm-migration&run=' ) ) ) ); ?>"></div>
	<div class="wrap awm-wrap">
		<h1>Bulk Migration to Central Routes</h1>
		<p class="awm-muted">Replace existing hardcoded WhatsApp URLs with stable central routes. Every migration begins with a read-only preview. Applying is batched, verifies that each field is unchanged since preview, and retains a rollback backup.</p>
		<?php if ( 'deleted' === $notice ) : ?><div class="notice notice-success is-dismissible"><p>Migration preview and its backups were deleted.</p></div><?php elseif ( 'delete_blocked' === $notice ) : ?><div class="notice notice-warning is-dismissible"><p>Rollback every applied field before deleting this migration history.</p></div><?php endif; ?>
		<div class="awm-warning"><strong>Before applying:</strong> create a full WordPress/database backup, test on staging and review the preview. The tool covers published/private post content, excerpts and post metadata—including structured Breakdance/ACF values attached to those posts. Theme files and unrelated global options are not edited.</div>

		<div class="awm-card awm-mt18">
			<h2>Create Dry-Run Preview</h2>
			<?php if ( ! $routes ) : ?><p>Create a <a href="<?php echo esc_url( admin_url( 'admin.php?page=awm-routes' ) ); ?>">Central WhatsApp Route</a> first.</p><?php else : ?>
			<form id="awm-migration-form">
				<table class="form-table" role="presentation">
					<tr><th><label for="awm-migration-route">Destination Route</label></th><td><select id="awm-migration-route" name="route_slug" required><option value="">Select a route</option><?php foreach ( $routes as $slug => $route ) : ?><option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( ( $route['name'] ?? $slug ) . ( '1' === (string) ( $route['enabled'] ?? '0' ) ? '' : ' (disabled)' ) . ' — ' . awm_managed_route_url( $slug ) ); ?></option><?php endforeach; ?></select><p class="description">The selected route must be enabled and have a usable active number.</p></td></tr>
					<tr><th><label for="awm-match-type">Find Existing Links By</label></th><td><select id="awm-match-type" name="match_type"><option value="destination">Number or wa.link destination</option><option value="exact_link">Exact WhatsApp link</option></select></td></tr>
					<tr><th><label for="awm-match-value">Existing Value</label></th><td><input id="awm-match-value" name="match_value" type="text" class="large-text code" required placeholder="12025550100 or https://wa.me/..."><p class="description">Destination matching includes resolved wa.link shortlinks when a resolution is available.</p></td></tr>
					<tr><th><label for="awm-page-scope">Page Scope</label></th><td><input id="awm-page-scope" name="page_scope" type="text" class="large-text code" value="*"><p class="description"><code>*</code> for all eligible content, an exact path such as <code>/example-page/</code>, or a prefix such as <code>/example-section/*</code>.</p></td></tr>
					<tr><th><label for="awm-language-mode">Managed Link Language</label></th><td><select id="awm-language-mode" name="language_mode"><option value="auto">Detect from each post</option><?php foreach ( awm_available_languages() as $language_code ) : ?><option value="<?php echo esc_attr( $language_code ); ?>"><?php echo esc_html( strtoupper( $language_code ) . ( $language_code === awm_default_language() ? ' — default' : '' ) ); ?></option><?php endforeach; ?></select></td></tr>
				</table>
				<p><button type="submit" class="button button-primary">Create Dry-Run Preview</button></p>
			</form>
			<?php endif; ?>
			<div class="awm-progress-wrap" id="awm-migration-progress" hidden><progress class="awm-progress-meter" id="awm-migration-progress-meter" max="100" value="0">0%</progress></div><p id="awm-migration-status" class="awm-muted" aria-live="polite"></p>
		</div>

		<div class="awm-card awm-mt18">
			<h2>Migration History</h2>
			<div class="awm-table-wrap"><table class="widefat striped"><thead><tr><th>Created</th><th>Route</th><th>Criteria</th><th>Status</th><th>Fields / Occurrences</th><th>Actions</th></tr></thead><tbody>
			<?php if ( $runs ) : foreach ( $runs as $run ) : ?>
				<tr><td><?php echo esc_html( $run->created_at ); ?></td><td><code><?php echo esc_html( $run->route_slug ); ?></code></td><td><?php echo esc_html( 'exact_link' === $run->match_type ? 'Exact link' : 'Destination' ); ?>: <code><?php echo esc_html( $run->match_value ); ?></code><br><span class="awm-muted">Scope: <?php echo esc_html( $run->page_scope ); ?> · Language: <?php echo esc_html( strtoupper( $run->language_mode ) ); ?></span></td><td><span class="awm-status <?php echo in_array( $run->status, array( 'ready', 'applied', 'rolled_back' ), true ) ? 'awm-status-approved' : ''; ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $run->status ) ) ); ?></span><?php if ( $run->conflict_items ) : ?><br><span class="awm-status awm-status-malformed"><?php echo esc_html( number_format_i18n( $run->conflict_items ) ); ?> conflict(s)</span><?php endif; ?></td><td><?php echo esc_html( number_format_i18n( $run->total_items ) ); ?> / <?php echo esc_html( number_format_i18n( $run->total_occurrences ) ); ?></td><td><a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'awm-migration', 'run' => $run->run_id ), admin_url( 'admin.php' ) ) ); ?>">Review</a> <?php if ( 'previewing' === $run->status ) : ?><button type="button" class="button button-small awm-migration-action" data-operation="preview" data-run="<?php echo esc_attr( $run->run_id ); ?>">Resume Preview</button><?php elseif ( in_array( $run->status, array( 'ready', 'applying' ), true ) && $run->total_items > 0 ) : ?><button type="button" class="button button-small button-primary awm-migration-action" data-operation="apply" data-run="<?php echo esc_attr( $run->run_id ); ?>">Apply</button><?php elseif ( in_array( $run->status, array( 'applied', 'partial', 'rolling_back', 'rollback_partial' ), true ) && $run->applied_items > 0 ) : ?><button type="button" class="button button-small awm-migration-action" data-operation="rollback" data-run="<?php echo esc_attr( $run->run_id ); ?>">Rollback</button><?php endif; ?> <?php if ( 0 === (int) $run->applied_items && ! in_array( $run->status, array( 'previewing', 'applying', 'rolling_back' ), true ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="awm-inline-form" data-awm-confirm="Delete this migration preview and its stored backups?"><input type="hidden" name="action" value="awm_delete_migration_run"><input type="hidden" name="run_id" value="<?php echo esc_attr( $run->run_id ); ?>"><?php wp_nonce_field( 'awm_delete_migration_run' ); ?><button type="submit" class="button button-small">Delete</button></form><?php endif; ?></td></tr>
			<?php endforeach; else : ?><tr><td colspan="6">No migration previews have been created.</td></tr><?php endif; ?>
			</tbody></table></div>
		</div>

		<?php if ( $view_run ) : ?><div class="awm-card awm-mt18"><h2>Preview Details — <?php echo esc_html( $view_run->run_id ); ?></h2><p>Showing up to 100 changed fields. Backups contain full field values but are intentionally not displayed here.</p><div class="awm-table-wrap"><table class="widefat striped"><thead><tr><th>Content</th><th>Storage</th><th>Occurrences</th><th>Status</th><th>Result</th></tr></thead><tbody><?php if ( $view_items ) : foreach ( $view_items as $item ) : ?><tr><td><a href="<?php echo esc_url( get_permalink( $item->post_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( get_the_title( $item->post_id ) ?: '#' . $item->post_id ); ?></a></td><td><?php echo esc_html( $item->storage_type ); ?><?php if ( $item->meta_key ) : ?><br><code><?php echo esc_html( $item->meta_key ); ?></code><?php endif; ?></td><td><?php echo esc_html( number_format_i18n( $item->occurrences ) ); ?></td><td><?php echo esc_html( $item->status ); ?></td><td><?php echo esc_html( $item->error_message ?: '—' ); ?></td></tr><?php endforeach; else : ?><tr><td colspan="5">No matching fields found.</td></tr><?php endif; ?></tbody></table></div></div><?php endif; ?>
	</div>
	<?php
}
