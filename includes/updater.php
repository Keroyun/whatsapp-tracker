<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'AWM_GITHUB_REPO', 'Keroyun/whatsapp-tracker' );
define( 'AWM_UPDATE_CACHE_KEY', 'awm_github_release' );

function awm_github_latest_release( $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( AWM_UPDATE_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}
	$response = wp_safe_remote_get(
		'https://api.github.com/repos/' . AWM_GITHUB_REPO . '/releases/latest',
		array(
			'timeout' => 8,
			'headers' => array(
				'Accept' => 'application/vnd.github+json',
				'User-Agent' => 'WhatsApp-Tracker/' . AWM_VERSION . '; ' . home_url( '/' ),
			),
		)
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return array();
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
		return array();
	}
	$package = '';
	foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
		if ( isset( $asset['name'], $asset['browser_download_url'] ) && 'whatsapp-tracker.zip' === strtolower( (string) $asset['name'] ) ) {
			$package = esc_url_raw( $asset['browser_download_url'] );
			break;
		}
	}
	if ( ! $package && ! empty( $data['zipball_url'] ) ) {
		$package = esc_url_raw( $data['zipball_url'] );
	}
	$release = array(
		'version' => ltrim( sanitize_text_field( (string) $data['tag_name'] ), 'vV' ),
		'package' => $package,
		'url' => esc_url_raw( (string) ( $data['html_url'] ?? 'https://github.com/' . AWM_GITHUB_REPO ) ),
		'body' => sanitize_textarea_field( (string) ( $data['body'] ?? '' ) ),
	);
	set_transient( AWM_UPDATE_CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );
	return $release;
}

add_filter( 'update_plugins_github.com', 'awm_github_update', 10, 4 );
function awm_github_update( $update, $plugin_data, $plugin_file, $locales ) {
	if ( plugin_basename( AWM_PLUGIN_FILE ) !== $plugin_file ) {
		return $update;
	}
	$release = awm_github_latest_release();
	if ( empty( $release['version'] ) || empty( $release['package'] ) || ! version_compare( AWM_VERSION, $release['version'], '<' ) ) {
		return false;
	}
	return array(
		'id' => 'https://github.com/' . AWM_GITHUB_REPO,
		'slug' => 'whatsapp-tracker',
		'version' => $release['version'],
		'url' => $release['url'],
		'package' => $release['package'],
		'tested' => '',
		'requires_php' => '',
	);
}

/**
 * GitHub source archives include owner/tag in the directory name. Normalize it
 * back to whatsapp-tracker so WordPress upgrades the existing plugin in place.
 */
add_filter( 'upgrader_source_selection', 'awm_normalize_github_source', 10, 4 );
function awm_normalize_github_source( $source, $remote_source, $upgrader, $hook_extra ) {
	if ( empty( $hook_extra['plugin'] ) || plugin_basename( AWM_PLUGIN_FILE ) !== $hook_extra['plugin'] ) {
		return $source;
	}
	global $wp_filesystem;
	if ( ! $wp_filesystem || ! is_string( $source ) || ! $wp_filesystem->is_dir( $source ) ) {
		return $source;
	}
	$desired = trailingslashit( $remote_source ) . 'whatsapp-tracker/';
	if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
		return $source;
	}
	if ( $wp_filesystem->exists( $desired ) ) {
		$wp_filesystem->delete( $desired, true );
	}
	if ( $wp_filesystem->move( $source, $desired, true ) ) {
		return $desired;
	}
	return new WP_Error( 'awm_update_source', 'Unable to normalize the GitHub plugin directory during update.' );
}
