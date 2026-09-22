<?php
/** Run on disposable WordPress with: wp eval-file tests/notice-form-integration.php */
if ( ! defined( 'ABSPATH' ) || ! function_exists( 'awm_notice_form_isolate_hooks' ) ) {
	throw new RuntimeException( 'Load WordPress with WhatsApp Tracker active first.' );
}
function awm_test_form_provider_callback() { echo 'provider'; }

$awm_test_checks = array();
$awm_test_file = wp_normalize_path( __FILE__ );
$awm_test_root = trailingslashit( dirname( $awm_test_file ) );
$awm_test_checks['callback owner resolved'] = awm_notice_form_callback_file( 'awm_test_form_provider_callback' ) === $awm_test_file;
$awm_test_checks['invalid callback fails closed'] = '' === awm_notice_form_callback_file( 'awm_nonexistent_callback' );
$awm_test_checks['owner file allowed'] = awm_notice_form_file_allowed( $awm_test_file, array( $awm_test_root ) );
$awm_test_checks['sibling prefix rejected'] = ! awm_notice_form_file_allowed( rtrim( $awm_test_root, '/' ) . '-other/file.php', array( $awm_test_root ) );
$awm_test_checks['single file scope exact'] = ! awm_notice_form_file_allowed( $awm_test_file . '.other', array( $awm_test_file ) );

global $wp_filter;
$awm_test_hooks = array( 'wp_head', 'wp_footer', 'wp_print_footer_scripts', 'wp_enqueue_scripts', 'wp_print_scripts', 'wp_print_styles' );
$awm_test_saved = array();
foreach ( $awm_test_hooks as $awm_test_hook ) {
	$awm_test_saved[ $awm_test_hook ] = isset( $wp_filter[ $awm_test_hook ] ) ? clone $wp_filter[ $awm_test_hook ] : null;
}
try {
	add_action( 'wp_head', 'awm_test_form_provider_callback', 8 );
	add_action( 'wp_head', 'wp_generator', 9 );
	add_action( 'wp_head', 'wp_print_head_scripts', 10 );
	add_action( 'wp_footer', 'wp_print_footer_scripts', 20 );
	awm_notice_form_isolate_hooks( array( $awm_test_root ) );
	$awm_test_checks['provider head hook preserved'] = 8 === has_action( 'wp_head', 'awm_test_form_provider_callback' );
	$awm_test_checks['unrelated head hook removed'] = false === has_action( 'wp_head', 'wp_generator' );
	$awm_test_checks['core head printer preserved'] = false !== has_action( 'wp_head', 'wp_print_head_scripts' );
	$awm_test_checks['core footer printer preserved'] = false !== has_action( 'wp_footer', 'wp_print_footer_scripts' );
	$awm_test_registry = new WP_Scripts();
	$awm_test_registry->add( 'fixture-dependency', '/dependency.js' );
	$awm_test_registry->add( 'fixture-form', plugins_url( 'fixture.js', __FILE__ ), array( 'fixture-dependency' ) );
	$awm_test_registry->add( 'fixture-chat', 'https://example.invalid/chat.js' );
	$awm_test_registry->enqueue( array( 'fixture-form', 'fixture-chat' ) );
	awm_notice_form_isolate_queue( $awm_test_registry, array( $awm_test_root ) );
	$awm_test_checks['unrelated early script removed'] = array( 'fixture-form' ) === $awm_test_registry->queue;
	$awm_test_checks['dependency remains registered'] = isset( $awm_test_registry->registered['fixture-dependency'] );
} finally {
	foreach ( $awm_test_saved as $awm_test_hook => $awm_test_value ) {
		if ( null === $awm_test_value ) { unset( $wp_filter[ $awm_test_hook ] ); }
		else { $wp_filter[ $awm_test_hook ] = $awm_test_value; }
	}
}
foreach ( $awm_test_checks as $awm_test_name => $awm_test_passed ) {
	if ( ! $awm_test_passed ) { throw new RuntimeException( 'FAIL: ' . $awm_test_name ); }
}
echo wp_json_encode( $awm_test_checks, JSON_PRETTY_PRINT );
