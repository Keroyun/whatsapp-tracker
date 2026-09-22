<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Resolve the owning provider without depending on a specific form brand. */
function awm_notice_form_callback_file( $callback ) {
	try {
		if ( is_array( $callback ) ) {
			$reflection = new ReflectionMethod( $callback[0], $callback[1] );
		} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
			$reflection = new ReflectionMethod( $callback );
		} elseif ( is_object( $callback ) && ! ( $callback instanceof Closure ) ) {
			$reflection = new ReflectionMethod( $callback, '__invoke' );
		} else {
			$reflection = new ReflectionFunction( $callback );
		}
		$file = $reflection->getFileName();
		return $file ? wp_normalize_path( realpath( $file ) ?: $file ) : '';
	} catch ( Throwable $error ) {
		return '';
	}
}

function awm_notice_form_provider_root( $file ) {
	foreach ( array( WP_PLUGIN_DIR, WPMU_PLUGIN_DIR ) as $directory ) {
		$base = trailingslashit( wp_normalize_path( realpath( $directory ) ?: $directory ) );
		if ( 0 !== strpos( $file, $base ) ) { continue; }
		$relative = substr( $file, strlen( $base ) );
		$slash = strpos( $relative, '/' );
		return false === $slash ? $file : $base . substr( $relative, 0, $slash ) . '/';
	}
	// A theme/custom shortcode may retain its own file, never the whole theme.
	return $file;
}

function awm_notice_form_file_allowed( $file, $roots ) {
	if ( ! $file ) { return false; }
	foreach ( $roots as $root ) {
		if ( $file === $root || ( '/' === substr( $root, -1 ) && 0 === strpos( $file, $root ) ) ) { return true; }
	}
	return false;
}

/** Strip site chrome hooks only in this short-lived form request. */
function awm_notice_form_isolate_hooks( $roots ) {
	global $wp_filter;
	$core = array(
		'wp_head' => array( 'wp_enqueue_scripts', 'wp_print_styles', 'wp_print_head_scripts' ),
		'wp_footer' => array( 'wp_print_footer_scripts' ),
		'wp_print_footer_scripts' => array( '_wp_footer_scripts' ),
		'wp_enqueue_scripts' => array(),
		'wp_print_scripts' => array(),
		'wp_print_styles' => array(),
	);
	foreach ( $core as $hook => $allowed_core ) {
		if ( empty( $wp_filter[ $hook ] ) ) { continue; }
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback = $entry['function'];
				if ( is_string( $callback ) && in_array( $callback, $allowed_core, true ) ) { continue; }
				if ( awm_notice_form_file_allowed( awm_notice_form_callback_file( $callback ), $roots ) ) { continue; }
				remove_action( $hook, $callback, $priority );
			}
		}
	}
}

/** Keep early provider assets; dependencies stay registered for WP to resolve. */
function awm_notice_form_isolate_queue( $registry, $roots ) {
	$allowed = array();
	foreach ( $registry->queue as $handle ) {
		$src = isset( $registry->registered[ $handle ] ) ? $registry->registered[ $handle ]->src : '';
		foreach ( $roots as $root ) {
			if ( '/' !== substr( $root, -1 ) ) { continue; }
			$url = plugins_url( '', $root . 'provider.php' ) . '/';
			if ( is_string( $src ) && 0 === strpos( $src, $url ) ) { $allowed[] = $handle; break; }
		}
	}
	$registry->queue = array_values( array_unique( $allowed ) );
}

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
	global $shortcode_tags;
	$provider = awm_notice_form_provider_root( awm_notice_form_callback_file( $shortcode_tags[ $shortcode[2] ] ) );
	// Add-on integrations can explicitly opt in a provider directory/file.
	$roots = (array) apply_filters( 'awm_notice_form_provider_roots', array_filter( array( $provider ) ), $shortcode[2] );
	$roots = array_values( array_filter( array_map( 'wp_normalize_path', $roots ) ) );
	awm_notice_form_isolate_hooks( $roots );
	awm_notice_form_isolate_queue( wp_scripts(), $roots );
	awm_notice_form_isolate_queue( wp_styles(), $roots );
	// Only the shortcode provider and WordPress asset printers run in this frame.
	// Sitewide chat, popup and sticky-footer hooks are not rendered at all.
	show_admin_bar( false );
	$form = do_shortcode( $selected['third_shortcode'] );
	awm_notice_form_isolate_hooks( $roots );
	wp_enqueue_style( 'awm-notice-form', AWM_PLUGIN_URL . 'assets/notice-form-' . AWM_VERSION . '.css', array(), AWM_VERSION );
	status_header( 200 );
	?><!doctype html>
	<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo esc_html( $selected['third_label'] ?? '' ); ?></title><?php wp_head(); ?></head>
	<body class="awm-notice-form-document"><main><?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted saved shortcode output, as in normal page content. ?></main><?php awm_notice_form_isolate_hooks( $roots ); wp_footer(); ?></body></html><?php
	exit;
}
