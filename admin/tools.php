<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function awm_portable_option_names() {
	return array(
		'awm_tracking_enabled',
		'awm_whatsapp_popup_enabled',
		'awm_telephone_popup_enabled',
		'awm_retention_days',
		'awm_audit_retention_days',
		'awm_approved_numbers',
		'awm_managed_routes',
		'awm_emergency_rules',
		'awm_live_chat_provider',
		'awm_manager_roles',
		'awm_scheduled_scan_frequency',
	);
}

add_action( 'admin_post_awm_export_configuration', 'awm_export_configuration' );
function awm_export_configuration() {
	if ( ! awm_current_user_can_manage() ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'whatsapp-tracker' ) );
	}
	check_admin_referer( 'awm_export_configuration' );
	$config = array(
		'schema_version' => 1,
		'plugin_version' => AWM_VERSION,
		'exported_at' => current_time( 'mysql' ),
		'site' => home_url( '/' ),
		'settings' => array(),
	);
	foreach ( awm_portable_option_names() as $name ) {
		$config['settings'][ $name ] = get_option( $name );
	}
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=whatsapp-tracker-config-' . gmdate( 'Y-m-d' ) . '.json' );
	echo wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	exit;
}

add_action( 'admin_post_awm_import_configuration', 'awm_import_configuration' );
function awm_import_configuration() {
	if ( ! awm_current_user_can_manage() ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'whatsapp-tracker' ) );
	}
	check_admin_referer( 'awm_import_configuration' );
	if ( empty( $_FILES['awm_config_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['awm_config_file']['tmp_name'] ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=awm-tools&awm_notice=no_file' ) );
		exit;
	}
	if ( (int) $_FILES['awm_config_file']['size'] > 1024 * 1024 ) {
		wp_safe_redirect( admin_url( 'admin.php?page=awm-tools&awm_notice=too_large' ) );
		exit;
	}
	$raw = file_get_contents( $_FILES['awm_config_file']['tmp_name'] );
	$data = json_decode( (string) $raw, true );
	if ( ! is_array( $data ) || 1 !== (int) ( $data['schema_version'] ?? 0 ) || ! is_array( $data['settings'] ?? null ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=awm-tools&awm_notice=invalid' ) );
		exit;
	}
	$allowed = array_flip( awm_portable_option_names() );
	foreach ( $data['settings'] as $name => $value ) {
		if ( ! isset( $allowed[ $name ] ) ) {
			continue;
		}
		switch ( $name ) {
			case 'awm_tracking_enabled':
			case 'awm_whatsapp_popup_enabled':
			case 'awm_telephone_popup_enabled':
				$value = awm_sanitize_checkbox( $value );
				break;
			case 'awm_retention_days':
			case 'awm_audit_retention_days':
				$value = awm_sanitize_retention( $value );
				break;
			case 'awm_manager_roles':
				$value = awm_sanitize_manager_roles( $value );
				break;
			case 'awm_live_chat_provider':
				$value = in_array( sanitize_key( $value ), array( 'none', 'tawk' ), true ) ? sanitize_key( $value ) : 'none';
				break;
			case 'awm_scheduled_scan_frequency':
				$value = in_array( sanitize_key( $value ), array( 'off', 'daily', 'weekly' ), true ) ? sanitize_key( $value ) : 'off';
				break;
			case 'awm_approved_numbers':
				$clean_numbers = array();
				foreach ( is_array( $value ) ? $value : array() as $item ) {
					if ( ! is_array( $item ) ) { continue; }
					$number = awm_normalize_phone( $item['number'] ?? '' );
					if ( ! $number ) { continue; }
					$clean_numbers[] = array(
						'label' => sanitize_text_field( awm_emergency_limit_text( $item['label'] ?? 'Approved Number', 191 ) ),
						'number' => $number,
					);
				}
				$value = $clean_numbers;
				break;
			case 'awm_managed_routes':
				$clean_routes = array();
				foreach ( is_array( $value ) ? $value : array() as $slug => $route ) {
					if ( ! is_array( $route ) ) { continue; }
					$slug = awm_managed_route_slug( $slug ?: ( $route['slug'] ?? '' ) );
					if ( ! $slug ) { continue; }
					$route['slug'] = $slug;
					$clean = awm_sanitize_managed_route( $route, $slug );
					if ( $clean['slug'] ) { $clean_routes[ $clean['slug'] ] = $clean; }
				}
				$value = $clean_routes;
				break;
			case 'awm_emergency_rules':
				$clean_rules = array();
				foreach ( array_slice( is_array( $value ) ? $value : array(), 0, 50 ) as $rule ) {
					if ( ! is_array( $rule ) ) { continue; }
					$clean = awm_emergency_sanitize_rule( $rule );
					if ( '1' === $clean['enabled'] && empty( $clean['page_paths'] ) && empty( $clean['destinations'] ) && empty( $clean['exact_links'] ) ) {
						$clean['enabled'] = '0';
					}
					$clean_rules[] = $clean;
				}
				$value = $clean_rules;
				break;
		}
		update_option( $name, $value, false );
	}
	awm_sync_manager_capabilities();
	do_action( 'awm_reschedule_recent_scan' );
	awm_audit_log( 'configuration_imported', 'settings', 'portable_config' );
	wp_safe_redirect( admin_url( 'admin.php?page=awm-tools&awm_notice=imported' ) );
	exit;
}

function awm_tools_page() {
	if ( ! awm_current_user_can_manage() ) { return; }
	$notice = isset( $_GET['awm_notice'] ) ? sanitize_key( wp_unslash( $_GET['awm_notice'] ) ) : '';
	$messages = array(
		'imported' => 'Configuration imported successfully.',
		'no_file' => 'Choose a JSON configuration file first.',
		'too_large' => 'Configuration file is too large.',
		'invalid' => 'The selected file is not a valid WhatsApp Tracker configuration.',
	);
	?>
	<div class="wrap awm-wrap">
		<h1>WhatsApp Tracker Tools</h1>
		<?php if ( isset( $messages[ $notice ] ) ) : ?><div class="notice <?php echo 'imported' === $notice ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div><?php endif; ?>
		<div class="awm-card">
			<h2>Portable Configuration</h2>
			<p>Move plugin settings, approved numbers, managed routes and notice rules between websites. Analytics, inventory results, audit logs and migration backups are intentionally excluded.</p>
			<p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=awm_export_configuration' ), 'awm_export_configuration' ) ); ?>">Export Configuration</a></p>
			<hr>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="awm_import_configuration">
				<?php wp_nonce_field( 'awm_import_configuration' ); ?>
				<input type="file" name="awm_config_file" accept=".json,application/json" required>
				<?php submit_button( 'Import Configuration', 'secondary', 'submit', false ); ?>
				<p class="description">Import replaces only portable plugin configuration. Take a backup first when importing into an existing configured site.</p>
			</form>
		</div>
	</div>
	<?php
}
