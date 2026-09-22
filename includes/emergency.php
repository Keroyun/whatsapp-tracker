<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 * Emergency popup rules
 * ---------------------------------------------------------------------- */

function awm_emergency_rule_defaults() {
	return array(
		'id'               => '',
		'name'             => 'Contact Availability Notice',
		'enabled'          => '0',
		'priority'         => 10,
		'match_mode'       => 'all',
		'channel'          => 'whatsapp',
		'language'         => '',
		'page_paths'       => array(),
		'destinations'     => array(),
		'exact_links'      => array(),
		'popup_kicker'     => 'Service notice',
		'popup_title'      => 'Contact service temporarily unavailable',
		'popup_message'    => 'This contact channel is temporarily unavailable. Please use another contact method on this website or try again later.',
		'primary_label'    => 'Go to Homepage',
		'primary_action'   => 'url',
		'primary_url'      => home_url( '/' ),
		'secondary_label'  => '',
		'secondary_url'    => '',
		'close_label'      => 'Close',
		'third_enabled'    => '0',
		'third_label'      => '',
		'third_action'     => 'url',
		'third_url'        => '',
		'third_shortcode'  => '',
		'back_label'       => 'Back',
	);
}

function awm_emergency_lines( $value ) {
	if ( is_array( $value ) ) {
		$lines = $value;
	} else {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
	}
	$lines = is_array( $lines ) ? array_slice( $lines, 0, 200 ) : array();
	return array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
}

function awm_emergency_limit_text( $value, $length ) {
	$value = (string) $value;
	return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length, 'UTF-8' ) : substr( $value, 0, $length );
}

function awm_emergency_normalize_page_path( $value ) {
	$value = trim( (string) $value );
	if ( '*' === $value ) {
		return '*';
	}

	$is_prefix = '*' === substr( $value, -1 );
	if ( $is_prefix ) {
		$value = substr( $value, 0, -1 );
	}

	if ( preg_match( '#^https?://#i', $value ) ) {
		$host      = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( ! $host || $host !== $home_host ) {
			return '';
		}
		$value = (string) wp_parse_url( $value, PHP_URL_PATH );
	}

	$value = sanitize_text_field( $value );
	if ( '' === $value ) {
		$value = '/';
	}
	$value = '/' . ltrim( $value, '/' );
	if ( $is_prefix && '/' !== $value ) {
		$value .= '*';
	}
	return substr( $value, 0, 768 );
}

/** Telephone identity preserves country prefixes and extensions; never guesses a country. */
function awm_emergency_normalize_telephone( $value, $allow_short = false ) {
	$value = preg_replace( '/^tel:/i', '', trim( (string) $value ) );
	if ( ! preg_match( '/^(\+?[0-9(). \-]+)(?:;ext=([0-9]{1,10}))?$/iD', $value, $matches ) ) {
		return '';
	}
	$number = preg_replace( '/[(). \-]/', '', $matches[1] );
	$digits = ltrim( $number, '+' );
	// DO NOT REMOVE: short service/emergency numbers cannot be intercepted.
	if ( strlen( $digits ) < ( $allow_short ? 2 : 7 ) || strlen( $digits ) > 15 ) {
		return '';
	}
	return $number . ( isset( $matches[2] ) && '' !== $matches[2] ? ';ext=' . $matches[2] : '' );
}

function awm_emergency_sanitize_action_url( $value ) {
	$value = trim( (string) $value );
	if ( 0 === stripos( $value, 'tel:' ) ) {
		$number = awm_emergency_normalize_telephone( $value, true );
		return $number ? 'tel:' . $number : '';
	}
	return esc_url_raw( $value );
}

function awm_emergency_normalize_destination( $value ) {
	$value  = trim( (string) $value );
	if ( 0 === stripos( $value, 'tel:' ) ) {
		$number = awm_emergency_normalize_telephone( $value );
		return $number ? 'tel:' . $number : '';
	}
	$parsed = awm_parse_whatsapp_url( $value );
	if ( $parsed && ! empty( $parsed['is_valid'] ) ) {
		return $parsed['destination'];
	}

	if ( 0 === stripos( $value, 'wa.link/' ) ) {
		$slug = preg_replace( '/[^A-Za-z0-9_-]/', '', substr( $value, 8 ) );
		return $slug ? 'wa.link/' . $slug : '';
	}

	$number = awm_normalize_phone( $value );
	return strlen( $number ) >= 7 ? $number : '';
}

function awm_emergency_normalize_exact_link( $value ) {
	if ( 0 === stripos( trim( (string) $value ), 'tel:' ) ) {
		$number = awm_emergency_normalize_telephone( $value );
		return $number ? 'tel:' . $number : '';
	}
	$parsed = awm_parse_whatsapp_url( trim( (string) $value ) );
	return ( $parsed && ! empty( $parsed['is_valid'] ) ) ? $parsed['url'] : '';
}

function awm_emergency_sanitize_target_list( $value, $callback ) {
	$result = array();
	foreach ( awm_emergency_lines( $value ) as $line ) {
		$normalized = call_user_func( $callback, $line );
		if ( '' !== $normalized && ! in_array( $normalized, $result, true ) ) {
			$result[] = $normalized;
		}
	}
	return $result;
}

function awm_emergency_sanitize_rule( $input ) {
	$input    = is_array( $input ) ? $input : array();
	$defaults = awm_emergency_rule_defaults();
	$id       = isset( $input['id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $input['id'] ) : '';
	if ( '' === $id ) {
		$id = wp_generate_uuid4();
	}

	$channel = sanitize_key( $input['channel'] ?? 'whatsapp' );
	if ( ! in_array( $channel, array( 'whatsapp', 'telephone', 'both' ), true ) ) {
		$channel = 'whatsapp';
	}
	$language = sanitize_key( $input['language'] ?? '' );
	$languages = function_exists( 'pll_languages_list' ) ? pll_languages_list( array( 'fields' => 'slug' ) ) : array();
	if ( $language && ! in_array( $language, (array) $languages, true ) ) {
		// Preserve an existing restriction if Polylang is temporarily unavailable.
		foreach ( awm_get_emergency_rules() as $existing_rule ) {
			if ( is_array( $existing_rule ) && (string) ( $existing_rule['id'] ?? '' ) === $id ) {
				$language = (string) ( $existing_rule['language'] ?? $language );
				break;
			}
		}
	}
	$primary_action = isset( $input['primary_action'] ) ? sanitize_key( $input['primary_action'] ) : $defaults['primary_action'];
	if ( ! in_array( $primary_action, array( 'tawk', 'url' ), true ) ) {
		$primary_action = $defaults['primary_action'];
	}

	$match_mode = isset( $input['match_mode'] ) ? sanitize_key( $input['match_mode'] ) : $defaults['match_mode'];
	if ( ! in_array( $match_mode, array( 'any', 'all' ), true ) ) {
		$match_mode = 'any';
	}


	$destinations = awm_emergency_lines( $input['destinations'] ?? array() );
	if ( 'telephone' === $channel ) {
		$destinations = array_map( function ( $number ) {
			return 0 === stripos( $number, 'tel:' ) ? $number : 'tel:' . $number;
		}, $destinations );
	}
	return array(
		'id'              => substr( $id, 0, 64 ),
		'name'            => sanitize_text_field( awm_emergency_limit_text( $input['name'] ?? $defaults['name'], 191 ) ),
		'enabled'         => ! empty( $input['enabled'] ) ? '1' : '0',
		'priority'        => max( 0, min( 999, absint( $input['priority'] ?? $defaults['priority'] ) ) ),
		'match_mode'      => $match_mode,
		'channel'         => $channel,
		'language'        => $language,
		'page_paths'      => awm_emergency_sanitize_target_list( $input['page_paths'] ?? array(), 'awm_emergency_normalize_page_path' ),
		'destinations'    => awm_emergency_sanitize_target_list( $destinations, 'awm_emergency_normalize_destination' ),
		'exact_links'     => awm_emergency_sanitize_target_list( $input['exact_links'] ?? array(), 'awm_emergency_normalize_exact_link' ),
		'popup_kicker'    => sanitize_text_field( awm_emergency_limit_text( $input['popup_kicker'] ?? $defaults['popup_kicker'], 80 ) ),
		'popup_title'     => sanitize_text_field( awm_emergency_limit_text( $input['popup_title'] ?? $defaults['popup_title'], 191 ) ),
		'popup_message'   => sanitize_textarea_field( awm_emergency_limit_text( $input['popup_message'] ?? $defaults['popup_message'], 1000 ) ),
		'primary_label'   => sanitize_text_field( awm_emergency_limit_text( $input['primary_label'] ?? $defaults['primary_label'], 80 ) ),
		'primary_action'  => $primary_action,
		'primary_url'     => awm_emergency_sanitize_action_url( $input['primary_url'] ?? '' ),
		'secondary_label' => sanitize_text_field( awm_emergency_limit_text( $input['secondary_label'] ?? $defaults['secondary_label'], 80 ) ),
		'secondary_url'   => awm_emergency_sanitize_action_url( $input['secondary_url'] ?? '' ),
		'close_label'     => sanitize_text_field( awm_emergency_limit_text( $input['close_label'] ?? $defaults['close_label'], 80 ) ),
		'third_enabled'   => ! empty( $input['third_enabled'] ) ? '1' : '0',
		'third_label'     => sanitize_text_field( awm_emergency_limit_text( $input['third_label'] ?? '', 80 ) ),
		'third_action'    => 'shortcode' === ( $input['third_action'] ?? '' ) ? 'shortcode' : 'url',
		'third_url'       => awm_emergency_sanitize_action_url( $input['third_url'] ?? '' ),
		'third_shortcode' => sanitize_textarea_field( awm_emergency_limit_text( $input['third_shortcode'] ?? '', 2000 ) ),
		'back_label'      => sanitize_text_field( awm_emergency_limit_text( $input['back_label'] ?? 'Back', 80 ) ),
	);
}

function awm_get_emergency_rules() {
	$rules = get_option( 'awm_emergency_rules', array() );
	return is_array( $rules ) ? array_values( $rules ) : array();
}

function awm_get_active_emergency_rules() {
	$active = array();
	foreach ( awm_get_emergency_rules() as $rule ) {
		if ( ! is_array( $rule ) || '1' !== (string) ( $rule['enabled'] ?? '0' ) ) {
			continue;
		}
		if ( empty( $rule['page_paths'] ) && empty( $rule['destinations'] ) && empty( $rule['exact_links'] ) ) {
			continue;
		}
		$active[] = array(
			'id'              => (string) ( $rule['id'] ?? '' ),
			'name'            => (string) ( $rule['name'] ?? '' ),
			'priority'        => (int) ( $rule['priority'] ?? 0 ),
			'channel'         => (string) ( $rule['channel'] ?? 'whatsapp' ),
			'language'        => (string) ( $rule['language'] ?? '' ),
			'matchMode'       => (string) ( $rule['match_mode'] ?? 'any' ),
			'pagePaths'       => array_values( (array) ( $rule['page_paths'] ?? array() ) ),
			'destinations'    => array_values( (array) ( $rule['destinations'] ?? array() ) ),
			'exactLinks'      => array_values( (array) ( $rule['exact_links'] ?? array() ) ),
			'kicker'          => (string) ( $rule['popup_kicker'] ?? 'Service notice' ),
			'title'           => (string) ( $rule['popup_title'] ?? '' ),
			'message'         => (string) ( $rule['popup_message'] ?? '' ),
			'primaryLabel'    => (string) ( $rule['primary_label'] ?? '' ),
			'primaryAction'   => (string) ( $rule['primary_action'] ?? 'url' ),
			'primaryUrl'      => (string) ( $rule['primary_url'] ?? '' ),
			'secondaryLabel'  => (string) ( $rule['secondary_label'] ?? '' ),
			'secondaryUrl'    => (string) ( $rule['secondary_url'] ?? '' ),
			'closeLabel'      => (string) ( $rule['close_label'] ?? 'Close' ),
			'thirdEnabled'    => '1' === (string) ( $rule['third_enabled'] ?? '0' ),
			'thirdLabel'      => (string) ( $rule['third_label'] ?? '' ),
			'thirdAction'     => (string) ( $rule['third_action'] ?? 'url' ),
			'thirdUrl'        => (string) ( $rule['third_url'] ?? '' ),
			'thirdFormUrl'    => ! empty( $rule['third_shortcode'] ) ? add_query_arg( 'awm_notice_form', (string) $rule['id'], home_url( '/' ) ) : '',
			'backLabel'       => (string) ( $rule['back_label'] ?? 'Back' ),
		);
	}
	return $active;
}

function awm_get_popup_visibility() {
	return array(
		'whatsapp'  => '1' === (string) get_option( 'awm_whatsapp_popup_enabled', '1' ),
		'telephone' => '1' === (string) get_option( 'awm_telephone_popup_enabled', '1' ),
	);
}


/** Purge only on contact-notice/tracking setting changes, never on visitor requests. */
add_action( 'updated_option', 'awm_emergency_purge_on_option_change', 10, 3 );
add_action( 'added_option', 'awm_emergency_purge_on_option_add', 10, 2 );
function awm_emergency_purge_on_option_add( $option, $value ) {
	awm_emergency_purge_on_option_change( $option, null, $value );
}
function awm_emergency_purge_on_option_change( $option, $old_value, $value ) {
	if ( ! in_array( $option, array( 'awm_emergency_rules', 'awm_tracking_enabled', 'awm_whatsapp_popup_enabled', 'awm_telephone_popup_enabled' ), true ) || $old_value === $value ) {
		return;
	}
	do_action( 'litespeed_purge_all' );
	// Optional integration point for the site's authenticated CDN purge implementation.
	do_action( 'awm_emergency_settings_changed', $option );
}
