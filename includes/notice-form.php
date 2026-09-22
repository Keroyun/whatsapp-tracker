<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** A public form document, never an arbitrary shortcode execution endpoint. */
add_action( 'template_redirect', 'awm_render_notice_form', 0 );
function awm_render_notice_form() {
	if ( ! isset( $_GET['awm_notice_form'] ) ) { return; }
	$id = sanitize_text_field( wp_unslash( $_GET['awm_notice_form'] ) );
	$selected = null;
	$visibility = awm_get_popup_visibility();
	foreach ( awm_get_emergency_rules() as $rule ) {
		if ( ! is_array( $rule ) || ( $rule['id'] ?? '' ) !== $id ) { continue; }
		$channel = $rule['channel'] ?? 'whatsapp';
		$visible = 'both' === $channel ? ( $visibility['telephone'] || $visibility['whatsapp'] ) : ! empty( $visibility[ $channel ] );
		if ( $visible && '1' === ( $rule['enabled'] ?? '0' ) && '1' === ( $rule['third_enabled'] ?? '0' ) && 'shortcode' === ( $rule['third_action'] ?? '' ) && ! empty( $rule['third_shortcode'] ) && ( ! empty( $rule['page_paths'] ) || ! empty( $rule['destinations'] ) || ! empty( $rule['exact_links'] ) ) ) {
			$selected = $rule;
		}
		break;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
	nocache_headers();
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( "Content-Security-Policy: frame-ancestors 'self'" );
	if ( ! $selected ) {
		wp_die( esc_html__( 'This form is unavailable.', 'whatsapp-tracker' ), '', array( 'response' => 404 ) );
	}
	if ( ! preg_match( '/' . get_shortcode_regex() . '/s', $selected['third_shortcode'], $shortcode ) || ! shortcode_exists( $shortcode[2] ) ) {
		wp_die( esc_html__( 'The form plugin is unavailable. Please choose another contact option.', 'whatsapp-tracker' ), '', array( 'response' => 503 ) );
	}
	// Render before wp_head so the form plugin can enqueue its own styles/scripts.
	// Only a manage_options administrator can save this public shortcode.
	show_admin_bar( false );
	$form = do_shortcode( $selected['third_shortcode'] );
	status_header( 200 );
	?><!doctype html>
	<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo esc_html( $selected['third_label'] ?? '' ); ?></title><?php wp_head(); ?></head>
	<body class="awm-notice-form-document"><main><?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted saved shortcode output, as in normal page content. ?></main><?php wp_footer(); ?></body></html><?php
	exit;
}
