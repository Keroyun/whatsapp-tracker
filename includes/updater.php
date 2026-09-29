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
			$candidate = esc_url_raw( $asset['browser_download_url'] );
			$parts = wp_parse_url( $candidate );
			$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
			if ( 'https' === ( $parts['scheme'] ?? '' ) && 'github.com' === strtolower( (string) ( $parts['host'] ?? '' ) ) && 0 === strpos( $path, '/Keroyun/whatsapp-tracker/releases/download/' ) ) {
				$package = $candidate;
				break;
			}
		}
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
