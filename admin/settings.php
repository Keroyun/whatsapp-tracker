<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

add_filter( 'option_page_capability_awm_settings', 'awm_settings_capability' );
function awm_settings_capability() {
	return AWM_CAPABILITY;
}

add_action( 'admin_init', 'awm_register_settings' );
function awm_register_settings() {
	register_setting( 'awm_settings', 'awm_tracking_enabled', array( 'sanitize_callback' => 'awm_sanitize_checkbox' ) );
	register_setting( 'awm_settings', 'awm_whatsapp_popup_enabled', array( 'sanitize_callback' => 'awm_sanitize_checkbox' ) );
	register_setting( 'awm_settings', 'awm_telephone_popup_enabled', array( 'sanitize_callback' => 'awm_sanitize_checkbox' ) );
	register_setting( 'awm_settings', 'awm_retention_days', array( 'sanitize_callback' => 'awm_sanitize_retention' ) );
	register_setting( 'awm_settings', 'awm_audit_retention_days', array( 'sanitize_callback' => 'awm_sanitize_retention' ) );
	register_setting( 'awm_settings', 'awm_delete_data_on_uninstall', array( 'sanitize_callback' => 'awm_sanitize_checkbox' ) );
	register_setting( 'awm_settings', 'awm_approved_numbers', array( 'sanitize_callback' => 'awm_sanitize_approved_numbers' ) );
	register_setting( 'awm_settings', 'awm_live_chat_provider', array( 'sanitize_callback' => 'awm_sanitize_live_chat_provider' ) );
	register_setting( 'awm_settings', 'awm_manager_roles', array( 'sanitize_callback' => 'awm_sanitize_manager_roles' ) );
	register_setting( 'awm_settings', 'awm_scheduled_scan_frequency', array( 'sanitize_callback' => 'awm_sanitize_scan_frequency' ) );
}

function awm_sanitize_checkbox( $value ) {
	return '1' === (string) $value ? '1' : '0';
}

function awm_sanitize_live_chat_provider( $value ) {
	$value = sanitize_key( (string) $value );
	return in_array( $value, array( 'none', 'tawk' ), true ) ? $value : 'none';
}

function awm_sanitize_scan_frequency( $value ) {
	$value = sanitize_key( (string) $value );
	return in_array( $value, array( 'off', 'daily', 'weekly' ), true ) ? $value : 'off';
}

function awm_sanitize_retention( $value ) {
	$value = absint( $value );
	return max( 30, min( 3650, $value ) );
}

function awm_sanitize_approved_numbers( $value ) {
	if ( is_string( $value ) ) {
		$lines = preg_split( '/\r\n|\r|\n/', $value );
	} else {
		$lines = array();
	}

	$result = array();
	$seen   = array();
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$parts  = array_map( 'trim', explode( '|', $line, 2 ) );
		$label  = sanitize_text_field( isset( $parts[0] ) ? $parts[0] : '' );
		$number = awm_normalize_phone( isset( $parts[1] ) ? $parts[1] : $parts[0] );
		if ( ! $number ) {
			continue;
		}
		if ( empty( $parts[1] ) || '' === $label ) {
			$label = 'Approved Number';
		}
		$key = strtolower( $label ) . '|' . $number;
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$result[] = array( 'label' => $label, 'number' => $number );
	}
	return $result;
}

function awm_settings_page() {
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		return;
	}

	$approved = awm_get_approved_numbers();
	$wp_roles = wp_roles();
	$manager_roles = awm_manager_roles();
	$lines    = array();
	foreach ( $approved as $item ) {
		$lines[] = $item['label'] . ' | ' . $item['number'];
	}
	?>
	<div class="wrap awm-wrap">
		<h1>WhatsApp Tracker Settings</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'awm_settings' ); ?>
			<div class="awm-card">
			<table class="form-table">
				<tr><th scope="row">Frontend Tracking</th><td><input type="hidden" name="awm_tracking_enabled" value="0"><label><input type="checkbox" name="awm_tracking_enabled" value="1" <?php checked( get_option( 'awm_tracking_enabled', '1' ), '1' ); ?>> Enable contact click tracking</label><p class="description">Controls WhatsApp aggregate recording and all plugin dataLayer events, including telephone and popup events. Telephone events are dataLayer-only; they do not appear in the WhatsApp dashboard. GTM consent and event tags must be configured separately. Turning this off does not disable service notices.</p></td></tr>
				<tr><th scope="row">Emergency Popup Visibility</th><td><input type="hidden" name="awm_whatsapp_popup_enabled" value="0"><label><input type="checkbox" name="awm_whatsapp_popup_enabled" value="1" <?php checked( get_option( 'awm_whatsapp_popup_enabled', '1' ), '1' ); ?>> Show popups for matching WhatsApp links</label><br><input type="hidden" name="awm_telephone_popup_enabled" value="0"><label><input type="checkbox" name="awm_telephone_popup_enabled" value="1" <?php checked( get_option( 'awm_telephone_popup_enabled', '1' ), '1' ); ?>> Show popups for matching telephone links</label><p class="description">These are independent master switches. Untick a channel to let its links open normally while keeping its rules saved. Individual rules must still be enabled on the Emergency Popups page.</p></td></tr>
				<tr><th scope="row"><label for="awm-retention">Data Retention</label></th><td><input id="awm-retention" type="number" min="30" max="3650" name="awm_retention_days" value="<?php echo esc_attr( get_option( 'awm_retention_days', 365 ) ); ?>"> days<p class="description">Daily aggregate rows older than this are removed by one lightweight daily cleanup task.</p></td></tr>
				<tr><th scope="row"><label for="awm-audit-retention">Audit Log Retention</label></th><td><input id="awm-audit-retention" type="number" min="30" max="3650" name="awm_audit_retention_days" value="<?php echo esc_attr( get_option( 'awm_audit_retention_days', 365 ) ); ?>"> days<p class="description">Administrative change history older than this is removed during daily cleanup.</p></td></tr>
				<tr><th scope="row">Uninstall Data Policy</th><td><input type="hidden" name="awm_delete_data_on_uninstall" value="0"><label><input type="checkbox" name="awm_delete_data_on_uninstall" value="1" <?php checked( get_option( 'awm_delete_data_on_uninstall', '0' ), '1' ); ?>> Permanently delete WhatsApp Tracker data when the plugin is uninstalled</label><p class="description"><strong>Off by default.</strong> Deactivation never deletes data. Only enable this before uninstalling when you intentionally want to remove plugin-owned tables and options. The legacy Click Tracker table is never deleted by this setting.</p></td></tr>
				<tr><th scope="row"><label for="awm-live-chat-provider">Live Chat Integration</label></th><td><select id="awm-live-chat-provider" name="awm_live_chat_provider"><option value="none" <?php selected( get_option( 'awm_live_chat_provider', 'none' ), 'none' ); ?>>None</option><option value="tawk" <?php selected( get_option( 'awm_live_chat_provider', 'none' ), 'tawk' ); ?>>Tawk.to</option></select><p class="description">Emergency rules can use a generic Live Chat action. Choose the provider loaded by this website. The plugin does not inject the provider widget.</p></td></tr>
				<tr><th scope="row">Plugin Access</th><td><?php foreach ( $wp_roles->roles as $role_key => $role_data ) : ?><label class="awm-role-option"><input type="checkbox" name="awm_manager_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $manager_roles, true ) ); ?> <?php disabled( 'administrator' === $role_key ); ?>> <?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?></label><br><?php endforeach; ?><p class="description">Administrators always retain access. Grant WhatsApp Tracker access to a marketing/editor role without giving full WordPress administration privileges.</p></td></tr>
				<tr><th scope="row"><label for="awm-scheduled-scan">Recent Changes Scan</label></th><td><select id="awm-scheduled-scan" name="awm_scheduled_scan_frequency"><option value="off" <?php selected( get_option( 'awm_scheduled_scan_frequency', 'off' ), 'off' ); ?>>Off</option><option value="daily" <?php selected( get_option( 'awm_scheduled_scan_frequency', 'off' ), 'daily' ); ?>>Daily</option><option value="weekly" <?php selected( get_option( 'awm_scheduled_scan_frequency', 'off' ), 'weekly' ); ?>>Weekly</option></select><p class="description">Runs the lightweight incremental inventory scan after a full base scan exists. Deep/rendered-page scans always remain manual.</p></td></tr>
				<tr><th scope="row"><label for="awm-approved">Approved WhatsApp Numbers</label></th><td><textarea id="awm-approved" name="awm_approved_numbers" rows="10" class="large-text code"><?php echo esc_textarea( implode( "\n", $lines ) ); ?></textarea><p class="description">One per line: <code>Label | Number</code>. The <strong>same number may be used with multiple labels</strong> for different use-cases.<br>Example:<br><code>General Enquiries | 12025550100</code><br><code>Sales | 12025550100</code><br><code>Support | 12025550100</code><br><br>If a number has multiple labels, use the Link Generator/shortcode source tag for accurate attribution; otherwise clicks to that number are recorded as <em>Unassigned</em>.<br><br><strong>Do not enter <code>wa.link/...</code> here.</strong> Resolve shortlinks from Link Inventory; once resolved, the plugin matches them to these approved phone numbers automatically.</p></td></tr>
			</table>
			<?php submit_button( 'Save Settings' ); ?>
			</div>
		</form>
		<div class="awm-card awm-mt18">
			<h2>Production Design Notes</h2>
			<ul class="awm-list-disc">
				<li>No jQuery dependency.</li>
				<li>Normal analytics tracking does not intercept clicks. Only enabled emergency rules in a channel whose popup visibility checkbox is ticked prevent navigation and show the configured fallback popup.</li>
				<li>No visitor IP, user-agent or individual timestamp stored in analytics tables.</li>
				<li>No automatic deep/rendered-page crawler. Optional scheduled Recent Changes scans use the lightweight database scanner only.</li>
				<li>Multiple labels can share one approved number; accurate multi-label attribution uses a lightweight <code>data-awm-source</code> CTA attribute.</li>
				<li><code>wa.link</code> resolution is admin-triggered only, bounded to WhatsApp-owned domains, cached, and never runs during normal visitor traffic.</li>
				<li>Scanner is admin-only and on-demand.</li>
				<li>Administrative changes are recorded in the Audit Log without visitor IP addresses or user agents.</li>
				<li>Plugin data is preserved on uninstall unless the explicit delete-data setting is enabled first.</li>
				<li>Existing legacy click table is preserved and never deleted automatically.</li>
			</ul>
		</div>
	</div>
	<?php
}
