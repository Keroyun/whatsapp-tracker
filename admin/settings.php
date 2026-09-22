<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

add_action( 'admin_init', 'awm_register_settings' );
function awm_register_settings() {
	register_setting( 'awm_settings', 'awm_tracking_enabled', array( 'sanitize_callback' => 'awm_sanitize_checkbox' ) );
	register_setting( 'awm_settings', 'awm_whatsapp_popup_enabled', array( 'sanitize_callback' => 'awm_sanitize_checkbox' ) );
	register_setting( 'awm_settings', 'awm_telephone_popup_enabled', array( 'sanitize_callback' => 'awm_sanitize_checkbox' ) );
	register_setting( 'awm_settings', 'awm_retention_days', array( 'sanitize_callback' => 'awm_sanitize_retention' ) );
	register_setting( 'awm_settings', 'awm_audit_retention_days', array( 'sanitize_callback' => 'awm_sanitize_retention' ) );
	register_setting( 'awm_settings', 'awm_delete_data_on_uninstall', array( 'sanitize_callback' => 'awm_sanitize_checkbox' ) );
	register_setting( 'awm_settings', 'awm_approved_numbers', array( 'sanitize_callback' => 'awm_sanitize_approved_numbers' ) );
}

function awm_sanitize_checkbox( $value ) {
	return '1' === (string) $value ? '1' : '0';
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
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$approved = awm_get_approved_numbers();
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
				<li>No automatic site crawler or scanner cron.</li>
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
