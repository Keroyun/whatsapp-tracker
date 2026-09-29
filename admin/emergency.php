<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 * Emergency popup administration
 * ---------------------------------------------------------------------- */

function awm_emergency_find_rule( $rules, $id ) {
	foreach ( $rules as $index => $rule ) {
		if ( is_array( $rule ) && (string) ( $rule['id'] ?? '' ) === (string) $id ) {
			return $index;
		}
	}
	return false;
}

function awm_emergency_redirect( $notice, $edit_id = '' ) {
	$args = array(
		'page'       => 'awm-emergency',
		'awm_notice' => sanitize_key( $notice ),
	);
	if ( $edit_id ) {
		$args['edit'] = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $edit_id );
	}
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_awm_save_popup_visibility', 'awm_save_popup_visibility' );
function awm_save_popup_visibility() {
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'awm_save_popup_visibility' );
	$posted = wp_unslash( $_POST );
	update_option( 'awm_whatsapp_popup_enabled', awm_sanitize_checkbox( $posted['awm_whatsapp_popup_enabled'] ?? '0' ), false );
	update_option( 'awm_telephone_popup_enabled', awm_sanitize_checkbox( $posted['awm_telephone_popup_enabled'] ?? '0' ), false );
	awm_emergency_redirect( 'visibility' );
}

add_action( 'admin_post_awm_save_emergency_rule', 'awm_save_emergency_rule' );
function awm_save_emergency_rule() {
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'awm_save_emergency_rule' );

	$posted = wp_unslash( $_POST );
	$rule   = awm_emergency_sanitize_rule( isset( $posted['rule'] ) ? $posted['rule'] : array() );
	$notice = 'saved';
	if ( '1' === $rule['enabled'] && empty( $rule['page_paths'] ) && empty( $rule['destinations'] ) && empty( $rule['exact_links'] ) ) {
		$rule['enabled'] = '0';
		$notice = 'saved_no_targets';
	}
	$rules  = awm_get_emergency_rules();
	$index  = awm_emergency_find_rule( $rules, $rule['id'] );

	if ( false === $index && count( $rules ) >= 50 ) {
		awm_emergency_redirect( 'limit' );
	}
	if ( false === $index ) {
		$rules[] = $rule;
	} else {
		$rules[ $index ] = $rule;
	}

	update_option( 'awm_emergency_rules', array_values( $rules ), false );
	awm_audit_log( 'emergency_rule_saved', 'emergency_rule', $rule['id'], array( 'enabled' => $rule['enabled'], 'priority' => $rule['priority'], 'target_summary' => awm_emergency_target_summary( $rule ) ) );
	awm_emergency_redirect( $notice, $rule['id'] );
}

add_action( 'admin_post_awm_toggle_emergency_rule', 'awm_toggle_emergency_rule' );
function awm_toggle_emergency_rule() {
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'awm_toggle_emergency_rule' );

	$id    = isset( $_POST['rule_id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_POST['rule_id'] ) ) : '';
	$rules = awm_get_emergency_rules();
	$index = awm_emergency_find_rule( $rules, $id );
	if ( false === $index ) {
		awm_emergency_redirect( 'missing' );
	}

	$is_enabled = '1' === (string) ( $rules[ $index ]['enabled'] ?? '0' );
	if ( ! $is_enabled && empty( $rules[ $index ]['page_paths'] ) && empty( $rules[ $index ]['destinations'] ) && empty( $rules[ $index ]['exact_links'] ) ) {
		awm_emergency_redirect( 'targets', $id );
	}
	$rules[ $index ]['enabled'] = $is_enabled ? '0' : '1';
	update_option( 'awm_emergency_rules', array_values( $rules ), false );
	awm_audit_log( 'emergency_rule_toggled', 'emergency_rule', $id, array( 'enabled' => $rules[ $index ]['enabled'] ) );
	awm_emergency_redirect( 'toggled' );
}

add_action( 'admin_post_awm_delete_emergency_rule', 'awm_delete_emergency_rule' );
function awm_delete_emergency_rule() {
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'awm_delete_emergency_rule' );

	$id    = isset( $_POST['rule_id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_POST['rule_id'] ) ) : '';
	$rules = awm_get_emergency_rules();
	$index = awm_emergency_find_rule( $rules, $id );
	if ( false === $index ) {
		awm_emergency_redirect( 'missing' );
	}

	array_splice( $rules, $index, 1 );
	update_option( 'awm_emergency_rules', array_values( $rules ), false );
	awm_audit_log( 'emergency_rule_deleted', 'emergency_rule', $id );
	awm_emergency_redirect( 'deleted' );
}

function awm_emergency_target_summary( $rule ) {
	$parts = array();
	$pages = count( (array) ( $rule['page_paths'] ?? array() ) );
	$destinations = count( (array) ( $rule['destinations'] ?? array() ) );
	$links = count( (array) ( $rule['exact_links'] ?? array() ) );
	if ( $pages ) {
		$parts[] = sprintf( _n( '%s page rule', '%s page rules', $pages ), number_format_i18n( $pages ) );
	}
	if ( $destinations ) {
		$parts[] = sprintf( _n( '%s destination', '%s destinations', $destinations ), number_format_i18n( $destinations ) );
	}
	if ( $links ) {
		$parts[] = sprintf( _n( '%s exact link', '%s exact links', $links ), number_format_i18n( $links ) );
	}
	return $parts ? implode( ' · ', $parts ) : 'No targets configured';
}

function awm_emergency_popups_page() {
	if ( ! current_user_can( AWM_CAPABILITY ) ) {
		return;
	}

	$rules   = awm_get_emergency_rules();
	$edit_id = isset( $_GET['edit'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_GET['edit'] ) ) : '';
	$editing = awm_emergency_rule_defaults();
	$is_edit = false;
	$new_channel = isset( $_GET['new_channel'] ) ? sanitize_key( wp_unslash( $_GET['new_channel'] ) ) : '';
	if ( ! $edit_id && 'telephone' === $new_channel ) {
		$editing['channel'] = 'telephone';
		$editing['name'] = 'Telephone Availability Notice';
		$editing['popup_title'] = 'Telephone service temporarily unavailable';
		$editing['popup_message'] = 'This telephone line is temporarily unavailable. Please use one of the alternative contact options below.';
	}
	if ( $edit_id ) {
		$index = awm_emergency_find_rule( $rules, $edit_id );
		if ( false !== $index ) {
			$editing = array_merge( $editing, $rules[ $index ] );
			$is_edit = true;
		}
	}

	$notice = isset( $_GET['awm_notice'] ) ? sanitize_key( wp_unslash( $_GET['awm_notice'] ) ) : '';
	$notice_messages = array(
		'saved'            => 'Emergency popup rule saved.',
		'saved_no_targets' => 'The rule was saved as disabled because it does not have any targets.',
		'toggled'          => 'Emergency popup rule status updated.',
		'deleted'          => 'Emergency popup rule deleted.',
		'missing'          => 'The selected emergency popup rule could not be found.',
		'targets'          => 'Add at least one page, destination or exact link before enabling this rule.',
		'limit'            => 'The maximum of 50 emergency popup rules has been reached.',
		'visibility'       => 'Popup visibility updated. Unticked channels now open their links normally.',
	);
	$popup_visibility = awm_get_popup_visibility();

	$list_rules = $rules;
	usort(
		$list_rules,
		function ( $a, $b ) {
			$enabled_compare = strcmp( (string) ( $b['enabled'] ?? '0' ), (string) ( $a['enabled'] ?? '0' ) );
			if ( 0 !== $enabled_compare ) {
				return $enabled_compare;
			}
			return (int) ( $b['priority'] ?? 0 ) <=> (int) ( $a['priority'] ?? 0 );
		}
	);
	?>
	<div class="wrap awm-wrap">
		<h1><?php esc_html_e( 'Emergency Popups', 'whatsapp-tracker' ); ?></h1>
		<p><a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'awm-emergency', 'new_channel' => 'telephone' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Add Telephone Notice', 'whatsapp-tracker' ); ?></a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=awm-emergency' ) ); ?>"><?php esc_html_e( 'Add WhatsApp Notice', 'whatsapp-tracker' ); ?></a></p>
		<p class="awm-muted"><?php esc_html_e( 'Temporarily intercept selected WhatsApp or telephone links and direct visitors to Live Chat or another official channel. Rules work across existing pages without changing their stored URLs. Settings refresh on page load and every 60 seconds while the page is visible. Exclude the emergency-rules REST endpoint from CDN caching.', 'whatsapp-tracker' ); ?></p>

		<?php if ( $notice && isset( $notice_messages[ $notice ] ) ) : ?>
			<div class="notice <?php echo in_array( $notice, array( 'missing', 'targets', 'limit', 'saved_no_targets' ), true ) ? 'notice-warning' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notice_messages[ $notice ] ); ?></p></div>
		<?php endif; ?>

		<div class="awm-card awm-mt18">
			<h2><?php esc_html_e( 'Popup Visibility', 'whatsapp-tracker' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="awm_save_popup_visibility">
				<?php wp_nonce_field( 'awm_save_popup_visibility' ); ?>
				<p><input type="hidden" name="awm_whatsapp_popup_enabled" value="0"><label><input type="checkbox" name="awm_whatsapp_popup_enabled" value="1" <?php checked( $popup_visibility['whatsapp'] ); ?>><?php esc_html_e( 'Show popups for matching WhatsApp links', 'whatsapp-tracker' ); ?></label></p>
				<p><input type="hidden" name="awm_telephone_popup_enabled" value="0"><label><input type="checkbox" name="awm_telephone_popup_enabled" value="1" <?php checked( $popup_visibility['telephone'] ); ?>><?php esc_html_e( 'Show popups for matching telephone links', 'whatsapp-tracker' ); ?></label></p>
				<p class="description"><?php esc_html_e( 'Unticking a channel leaves all its rules saved but allows its links to open normally on desktop and mobile. Individual rules must also be enabled below.', 'whatsapp-tracker' ); ?></p>
				<?php submit_button( 'Save Popup Visibility', 'secondary', 'submit', false ); ?>
			</form>
		</div>

		<div class="awm-card awm-mt18">
			<h2><?php esc_html_e( 'Configured Popup Rules', 'whatsapp-tracker' ); ?></h2>
			<p class="description"><?php esc_html_e( 'A matching page-language rule wins over Default / All languages. Within the same language scope, the most specific match wins; priority breaks ties. Popup wording is entered by you, not automatically translated.', 'whatsapp-tracker' ); ?></p>
			<div class="awm-table-wrap">
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Status', 'whatsapp-tracker' ); ?></th><th><?php esc_html_e( 'Rule', 'whatsapp-tracker' ); ?></th><th><?php esc_html_e( 'Targets', 'whatsapp-tracker' ); ?></th><th><?php esc_html_e( 'Match', 'whatsapp-tracker' ); ?></th><th><?php esc_html_e( 'Priority', 'whatsapp-tracker' ); ?></th><th><?php esc_html_e( 'Actions', 'whatsapp-tracker' ); ?></th></tr></thead>
					<tbody>
					<?php if ( $list_rules ) : foreach ( $list_rules as $rule ) : ?>
						<tr>
							<td><span class="awm-status <?php echo '1' === (string) ( $rule['enabled'] ?? '0' ) ? 'awm-status-approved' : ''; ?>"><?php echo '1' === (string) ( $rule['enabled'] ?? '0' ) ? 'Active' : 'Disabled'; ?></span></td>
							<td><strong><?php echo esc_html( $rule['name'] ?? 'Untitled rule' ); ?></strong><br><span class="awm-muted"><?php echo esc_html( $rule['popup_title'] ?? '' ); ?></span><br><small>Channel: <?php echo esc_html( array( 'whatsapp' => 'WhatsApp', 'telephone' => 'Telephone', 'both' => 'Both' )[ $rule['channel'] ?? 'whatsapp' ] ?? 'WhatsApp' ); ?></small></td>
							<td><?php echo esc_html( awm_emergency_target_summary( $rule ) ); ?></td>
							<td><?php echo 'all' === ( $rule['match_mode'] ?? 'any' ) ? 'All populated target groups' : 'Any target'; ?>
							<?php if ( 'all' !== ( $rule['match_mode'] ?? 'any' ) && ! empty( $rule['page_paths'] ) && ( ! empty( $rule['destinations'] ) || ! empty( $rule['exact_links'] ) ) ) : ?>
							<p><strong><?php esc_html_e( 'Review scope:', 'whatsapp-tracker' ); ?></strong> A matching number or link can trigger outside these page paths. Use Match all for page-specific targeting; select Language for translated wording.</p>
							<?php endif; ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $rule['priority'] ?? 0 ) ) ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'awm-emergency', 'edit' => $rule['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'whatsapp-tracker' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="awm-inline-form">
									<input type="hidden" name="action" value="awm_toggle_emergency_rule"><input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['id'] ); ?>"><?php wp_nonce_field( 'awm_toggle_emergency_rule' ); ?>
									<button type="submit" class="button button-small"><?php echo '1' === (string) ( $rule['enabled'] ?? '0' ) ? 'Disable' : 'Enable'; ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="awm-inline-form" data-awm-confirm="Delete this emergency popup rule?">
									<input type="hidden" name="action" value="awm_delete_emergency_rule"><input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['id'] ); ?>"><?php wp_nonce_field( 'awm_delete_emergency_rule' ); ?>
									<button type="submit" class="button button-small"><?php esc_html_e( 'Delete', 'whatsapp-tracker' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; else : ?>
						<tr><td colspan="6">No emergency popup rules have been created.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="awm-card awm-mt18">
			<h2><?php echo $is_edit ? 'Edit Emergency Popup Rule' : ( 'telephone' === $editing['channel'] ? 'Add Telephone Notice' : 'Add Emergency Popup Rule' ); ?></h2>
			<?php if ( 'telephone' === $editing['channel'] ) : ?>
			<p class="description"><?php esc_html_e( 'This notice blocks the original telephone link and offers your configured alternatives. Enter the affected telephone number, set a working alternative URL, and enable the rule after testing. To target the number sitewide, leave Page Paths and Exact Contact Links empty.', 'whatsapp-tracker' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="awm_save_emergency_rule">
				<input type="hidden" name="rule[id]" value="<?php echo esc_attr( $editing['id'] ); ?>">
				<?php wp_nonce_field( 'awm_save_emergency_rule' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="awm-rule-name"><?php esc_html_e( 'Rule Name', 'whatsapp-tracker' ); ?></label></th><td><input id="awm-rule-name" name="rule[name]" type="text" class="regular-text" maxlength="191" required value="<?php echo esc_attr( $editing['name'] ); ?>"><p class="description"><?php esc_html_e( 'Internal administrator-only name, for example: WhatsApp Outage — Main Contact.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-analytics-label"><?php esc_html_e( 'Analytics Label', 'whatsapp-tracker' ); ?></label></th><td><input id="awm-analytics-label" name="rule[analytics_label]" type="text" class="regular-text code" maxlength="80" value="<?php echo esc_attr( $editing['analytics_label'] ?? 'contact_unavailable' ); ?>"><p class="description"><?php esc_html_e( 'Public-safe event label sent to dataLayer. The internal Rule Name is not exposed to visitors.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Rule Status', 'whatsapp-tracker' ); ?></th><td><label><input type="checkbox" name="rule[enabled]" value="1" <?php checked( $editing['enabled'], '1' ); ?>><?php esc_html_e( 'Enable this emergency popup', 'whatsapp-tracker' ); ?></label><p class="description"><?php esc_html_e( 'Test the targets and alternative contact links before enabling the rule on production.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-rule-priority"><?php esc_html_e( 'Priority', 'whatsapp-tracker' ); ?></label></th><td><input id="awm-rule-priority" name="rule[priority]" type="number" min="0" max="999" value="<?php echo esc_attr( $editing['priority'] ); ?>"><p class="description"><?php esc_html_e( 'Used only when two matching rules are equally specific. Higher priority wins.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-rule-channel"><?php esc_html_e( 'Channel', 'whatsapp-tracker' ); ?></label></th><td><select id="awm-rule-channel" name="rule[channel]"><?php foreach ( array( 'whatsapp' => 'WhatsApp', 'telephone' => 'Telephone', 'both' => 'Both' ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $editing['channel'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Existing rules remain WhatsApp only. Short telephone service numbers are never intercepted. Add data-awm-emergency-exempt="1" to any emergency-service link that must always open normally.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-rule-language"><?php esc_html_e( 'Language', 'whatsapp-tracker' ); ?></label></th><td><select id="awm-rule-language" name="rule[language]"><option value=""><?php esc_html_e( 'Default / All languages', 'whatsapp-tracker' ); ?></option><?php
					$languages = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( array( 'fields' => 'slug' ) ) : array();
					if ( $editing['language'] && ! in_array( $editing['language'], $languages, true ) ) { $languages[] = $editing['language']; }
					foreach ( $languages as $language ) : ?><option value="<?php echo esc_attr( $language ); ?>" <?php selected( $editing['language'], $language ); ?>><?php echo esc_html( $language ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'A selected Polylang language is always required, even with Match any. Rules with a language restriction do not run when the page language cannot be determined.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-match-mode"><?php esc_html_e( 'Match Logic', 'whatsapp-tracker' ); ?></label></th><td><select id="awm-match-mode" name="rule[match_mode]"><option value="any" <?php selected( $editing['match_mode'], 'any' ); ?>><?php esc_html_e( 'Match any configured target', 'whatsapp-tracker' ); ?></option><option value="all" <?php selected( $editing['match_mode'], 'all' ); ?>><?php esc_html_e( 'Match all populated target groups', 'whatsapp-tracker' ); ?></option></select><p class="description"><?php esc_html_e( 'Use “all” to target a particular number or exact link only when it is clicked on selected pages.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-page-paths"><?php esc_html_e( 'Page Paths', 'whatsapp-tracker' ); ?></label></th><td><textarea id="awm-page-paths" name="rule[page_paths]" rows="6" class="large-text code" placeholder="/example-page/&#10;/example-section/*&#10;*"><?php echo esc_textarea( implode( "\n", (array) $editing['page_paths'] ) ); ?></textarea><p class="description">One per line. Enter paths from this website only. Use an exact path, a trailing <code>*</code> for a section, or <code>*</code><?php esc_html_e( 'for the whole website.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-destinations"><?php esc_html_e( 'Contact Numbers / Destinations', 'whatsapp-tracker' ); ?></label></th><td><textarea id="awm-destinations" name="rule[destinations]" rows="6" class="large-text code" placeholder="12025550100&#10;wa.link/example"><?php echo esc_textarea( implode( "\n", (array) $editing['destinations'] ) ); ?></textarea><p class="description">One per line. WhatsApp accepts international numbers, <code>wa.link/slug</code>, or full WhatsApp URLs. Telephone accepts <code>tel:+12025550101</code> or a bare number. For Both, numeric destinations match both channels; <code>tel:</code> entries target telephone only. Number targets match identical digits with or without a leading <code>+</code>. Local numbers such as <code>020...</code> are not converted to <code>+4420...</code>; add each form used on your pages. Extensions use <code>;ext=123</code><?php esc_html_e( 'and match separately.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-exact-links"><?php esc_html_e( 'Exact Contact Links', 'whatsapp-tracker' ); ?></label></th><td><textarea id="awm-exact-links" name="rule[exact_links]" rows="6" class="large-text code" placeholder="https://wa.me/12025550100?text=Example..."><?php echo esc_textarea( implode( "\n", (array) $editing['exact_links'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'Optional: one full WhatsApp or tel: link per line. Exact telephone links retain the leading + distinction. For ordinary number targeting, use Contact Numbers / Destinations and leave this field empty. With Match all, every populated target group must match.', 'whatsapp-tracker' ); ?></p></td></tr>
				</table>

				<h3><?php esc_html_e( 'Popup Content', 'whatsapp-tracker' ); ?></h3>
				<p class="description"><?php esc_html_e( 'If your website uses multiple languages, create separate rules for each translation and select its Language above. For legacy path-based rules, use Match all to require both the selected pages and the number. Existing match settings are preserved.', 'whatsapp-tracker' ); ?></p>
				<table class="form-table" role="presentation">
					<tr><th><label for="awm-popup-kicker"><?php esc_html_e( 'Small Heading', 'whatsapp-tracker' ); ?></label></th><td><input id="awm-popup-kicker" name="rule[popup_kicker]" type="text" class="regular-text" maxlength="80" value="<?php echo esc_attr( $editing['popup_kicker'] ); ?>"><p class="description"><?php esc_html_e( 'For example: Service notice.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-popup-title"><?php esc_html_e( 'Title', 'whatsapp-tracker' ); ?></label></th><td><input id="awm-popup-title" name="rule[popup_title]" type="text" class="large-text" maxlength="191" required value="<?php echo esc_attr( $editing['popup_title'] ); ?>"></td></tr>
					<tr><th><label for="awm-popup-message"><?php esc_html_e( 'Message', 'whatsapp-tracker' ); ?></label></th><td><textarea id="awm-popup-message" name="rule[popup_message]" rows="5" class="large-text" maxlength="1000" required><?php echo esc_textarea( $editing['popup_message'] ); ?></textarea></td></tr>
					<tr><th><label for="awm-primary-label"><?php esc_html_e( 'Primary Button', 'whatsapp-tracker' ); ?></label></th><td><input id="awm-primary-label" name="rule[primary_label]" type="text" class="regular-text" maxlength="80" value="<?php echo esc_attr( $editing['primary_label'] ); ?>"></td></tr>
					<tr><th><label for="awm-primary-action"><?php esc_html_e( 'Primary Action', 'whatsapp-tracker' ); ?></label></th><td><select id="awm-primary-action" name="rule[primary_action]"><option value="live_chat" <?php selected( in_array( $editing['primary_action'], array( 'live_chat', 'tawk' ), true ) ? 'live_chat' : $editing['primary_action'], 'live_chat' ); ?>><?php esc_html_e( 'Open configured live chat', 'whatsapp-tracker' ); ?></option><option value="url" <?php selected( $editing['primary_action'], 'url' ); ?>><?php esc_html_e( 'Open a URL', 'whatsapp-tracker' ); ?></option></select></td></tr>
					<tr><th><label for="awm-primary-url"><?php esc_html_e( 'Primary / Live Chat Fallback URL', 'whatsapp-tracker' ); ?></label></th><td><input id="awm-primary-url" name="rule[primary_url]" type="url" class="large-text" value="<?php echo esc_attr( $editing['primary_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"><p class="description"><?php esc_html_e( 'Use https://, http://, or tel: followed by a full contact number. For live chat, visitors are sent here if the configured provider is unavailable. Provide a fallback URL.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-secondary-label"><?php esc_html_e( 'Secondary Button', 'whatsapp-tracker' ); ?></label></th><td><input id="awm-secondary-label" name="rule[secondary_label]" type="text" class="regular-text" maxlength="80" value="<?php echo esc_attr( $editing['secondary_label'] ); ?>"></td></tr>
					<tr><th><label for="awm-secondary-url"><?php esc_html_e( 'Secondary URL', 'whatsapp-tracker' ); ?></label></th><td><input id="awm-secondary-url" name="rule[secondary_url]" type="url" class="large-text" value="<?php echo esc_attr( $editing['secondary_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Optional Third Button', 'whatsapp-tracker' ); ?></th><td><label><input type="checkbox" name="rule[third_enabled]" value="1" <?php checked( $editing['third_enabled'], '1' ); ?>><?php esc_html_e( 'Show a third action button', 'whatsapp-tracker' ); ?></label><p class="description"><?php esc_html_e( 'Off by default. The X always remains available to close the popup.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-third-label"><?php esc_html_e( 'Third Button Label', 'whatsapp-tracker' ); ?></label></th><td><input id="awm-third-label" name="rule[third_label]" type="text" class="regular-text" maxlength="80" value="<?php echo esc_attr( $editing['third_label'] ); ?>"></td></tr>
					<tr><th><label for="awm-third-action"><?php esc_html_e( 'Third Button Action', 'whatsapp-tracker' ); ?></label></th><td><select id="awm-third-action" name="rule[third_action]"><option value="url" <?php selected( $editing['third_action'], 'url' ); ?>><?php esc_html_e( 'Open a link or call a number', 'whatsapp-tracker' ); ?></option><option value="shortcode" <?php selected( $editing['third_action'], 'shortcode' ); ?>><?php esc_html_e( 'Open a shortcode form inside the popup', 'whatsapp-tracker' ); ?></option></select></td></tr>
					<tr><th><label for="awm-third-url"><?php esc_html_e( 'Third Button URL', 'whatsapp-tracker' ); ?></label></th><td><input id="awm-third-url" name="rule[third_url]" type="url" class="large-text" value="<?php echo esc_attr( $editing['third_url'] ); ?>" placeholder="tel:+12025550100"><p class="description"><?php esc_html_e( 'Use tel: for a phone call, a WhatsApp link for WhatsApp, or an https:// page address.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-third-shortcode"><?php esc_html_e( 'Form Shortcode', 'whatsapp-tracker' ); ?></label></th><td><textarea id="awm-third-shortcode" name="rule[third_shortcode]" rows="3" class="large-text code" maxlength="2000" placeholder='[wpforms id="123"]'><?php echo esc_textarea( $editing['third_shortcode'] ); ?></textarea><p class="description"><?php esc_html_e( 'Use a shortcode from an installed form plugin. This form is public. It opens in a same-site embedded document, with the form plugin responsible for submissions and validation. Test its scripts, CAPTCHA and confirmation behaviour before publishing. Page-specific or theme-dependent shortcodes may need a normal form-page URL instead. Exclude awm_notice_form requests from CDN caching.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-back-label"><?php esc_html_e( 'Form Back Button', 'whatsapp-tracker' ); ?></label></th><td><input id="awm-back-label" name="rule[back_label]" type="text" class="regular-text" maxlength="80" value="<?php echo esc_attr( $editing['back_label'] ); ?>"><p class="description"><?php esc_html_e( 'Going back or closing discards an unfinished form.', 'whatsapp-tracker' ); ?></p></td></tr>
					<tr><th><label for="awm-close-label"><?php esc_html_e( 'X Accessible Label', 'whatsapp-tracker' ); ?></label></th><td><input id="awm-close-label" name="rule[close_label]" type="text" class="regular-text" maxlength="80" value="<?php echo esc_attr( $editing['close_label'] ); ?>"></td></tr>
				</table>
				<?php submit_button( $is_edit ? 'Update Emergency Popup' : 'Create Emergency Popup' ); ?>
				<?php if ( $is_edit ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=awm-emergency' ) ); ?>"><?php esc_html_e( 'Cancel Editing', 'whatsapp-tracker' ); ?></a><?php endif; ?>
			</form>
		</div>
	</div>
	<?php
}
