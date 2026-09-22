<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 * Central route administration
 * ---------------------------------------------------------------------- */

function awm_routes_redirect( $notice, $slug = '' ) {
	$args = array( 'page' => 'awm-routes', 'awm_notice' => sanitize_key( $notice ) );
	if ( $slug ) {
		$args['edit'] = awm_managed_route_slug( $slug );
	}
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'admin_post_awm_save_managed_route', 'awm_save_managed_route' );
function awm_save_managed_route() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'awm_save_managed_route' );
	$posted        = wp_unslash( $_POST );
	$input         = isset( $posted['route'] ) && is_array( $posted['route'] ) ? $posted['route'] : array();
	$existing_slug = awm_managed_route_slug( $input['existing_slug'] ?? '' );
	$route         = awm_sanitize_managed_route( $input, $existing_slug );
	if ( ! $route['slug'] ) {
		awm_routes_redirect( 'invalid_slug' );
	}

	$routes = awm_get_managed_routes();
	if ( ! $existing_slug && isset( $routes[ $route['slug'] ] ) ) {
		awm_routes_redirect( 'duplicate' );
	}
	$notice = 'saved';
	if ( '1' === $route['enabled'] && ! $route['primary_number'] ) {
		$route['enabled'] = '0';
		$notice = 'saved_no_number';
	}
	$routes[ $route['slug'] ] = $route;
	update_option( 'awm_managed_routes', $routes, false );
	awm_audit_log( 'route_saved', 'route', $route['slug'], array( 'enabled' => $route['enabled'], 'active_line' => $route['active_line'], 'had_existing_slug' => (bool) $existing_slug ) );
	awm_routes_redirect( $notice, $route['slug'] );
}

add_action( 'admin_post_awm_toggle_managed_route', 'awm_toggle_managed_route' );
function awm_toggle_managed_route() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'awm_toggle_managed_route' );
	$slug   = awm_managed_route_slug( isset( $_POST['route_slug'] ) ? wp_unslash( $_POST['route_slug'] ) : '' );
	$routes = awm_get_managed_routes();
	if ( ! isset( $routes[ $slug ] ) ) {
		awm_routes_redirect( 'missing' );
	}
	$is_enabled = '1' === (string) ( $routes[ $slug ]['enabled'] ?? '0' );
	if ( ! $is_enabled && ! awm_normalize_phone( $routes[ $slug ]['primary_number'] ?? '' ) ) {
		awm_routes_redirect( 'number_required', $slug );
	}
	$routes[ $slug ]['enabled']    = $is_enabled ? '0' : '1';
	$routes[ $slug ]['updated_at'] = current_time( 'mysql' );
	$routes[ $slug ]['updated_by'] = get_current_user_id();
	update_option( 'awm_managed_routes', $routes, false );
	awm_audit_log( 'route_toggled', 'route', $slug, array( 'enabled' => $routes[ $slug ]['enabled'] ) );
	awm_routes_redirect( 'toggled' );
}

add_action( 'admin_post_awm_switch_managed_route', 'awm_switch_managed_route' );
function awm_switch_managed_route() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'awm_switch_managed_route' );
	$slug   = awm_managed_route_slug( isset( $_POST['route_slug'] ) ? wp_unslash( $_POST['route_slug'] ) : '' );
	$routes = awm_get_managed_routes();
	if ( ! isset( $routes[ $slug ] ) ) {
		awm_routes_redirect( 'missing' );
	}
	$current = 'backup' === ( $routes[ $slug ]['active_line'] ?? 'primary' ) ? 'backup' : 'primary';
	if ( 'primary' === $current && ! awm_normalize_phone( $routes[ $slug ]['backup_number'] ?? '' ) ) {
		awm_routes_redirect( 'backup_required', $slug );
	}
	$routes[ $slug ]['active_line'] = 'primary' === $current ? 'backup' : 'primary';
	$routes[ $slug ]['updated_at']  = current_time( 'mysql' );
	$routes[ $slug ]['updated_by']  = get_current_user_id();
	update_option( 'awm_managed_routes', $routes, false );
	awm_audit_log( 'route_line_switched', 'route', $slug, array( 'active_line' => $routes[ $slug ]['active_line'] ) );
	awm_routes_redirect( 'switched' );
}

add_action( 'admin_post_awm_delete_managed_route', 'awm_delete_managed_route' );
function awm_delete_managed_route() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'awm_delete_managed_route' );
	$slug   = awm_managed_route_slug( isset( $_POST['route_slug'] ) ? wp_unslash( $_POST['route_slug'] ) : '' );
	$routes = awm_get_managed_routes();
	if ( ! isset( $routes[ $slug ] ) ) {
		awm_routes_redirect( 'missing' );
	}
	global $wpdb;
	$runs_table = awm_migration_runs_table();
	$in_use = awm_table_exists( $runs_table ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$runs_table} WHERE route_slug = %s", $slug ) ) : 0;
	if ( $in_use ) {
		awm_routes_redirect( 'in_use', $slug );
	}
	unset( $routes[ $slug ] );
	update_option( 'awm_managed_routes', $routes, false );
	awm_audit_log( 'route_deleted', 'route', $slug );
	awm_routes_redirect( 'deleted' );
}

function awm_managed_routes_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$routes  = awm_get_managed_routes();
	$edit    = isset( $_GET['edit'] ) ? awm_managed_route_slug( wp_unslash( $_GET['edit'] ) ) : '';
	$route   = $edit && isset( $routes[ $edit ] ) ? array_merge( awm_managed_route_defaults(), $routes[ $edit ] ) : awm_managed_route_defaults();
	$is_edit = (bool) ( $edit && isset( $routes[ $edit ] ) );
	$notice  = isset( $_GET['awm_notice'] ) ? sanitize_key( wp_unslash( $_GET['awm_notice'] ) ) : '';
	$messages = array(
		'saved'            => 'Central WhatsApp route saved.',
		'saved_no_number'  => 'The route was saved as disabled because it does not have a primary number.',
		'toggled'          => 'Route status updated.',
		'switched'         => 'The active WhatsApp line was switched.',
		'deleted'          => 'Central WhatsApp route deleted.',
		'invalid_slug'     => 'Enter a valid route slug.',
		'duplicate'        => 'That route slug already exists.',
		'missing'          => 'The selected route could not be found.',
		'number_required'  => 'Add a primary number before enabling this route.',
		'backup_required'  => 'Add a backup number before switching this route.',
		'in_use'           => 'This route has migration history and cannot be deleted. Disable it instead.',
	);
	?>
	<div class="wrap awm-wrap">
		<h1>Central WhatsApp Routes</h1>
		<p class="awm-muted">Create stable website-owned links whose number and prefilled messages can be changed from one place. Route redirects are temporary (HTTP 302), excluded from indexing and never cacheable.</p>
		<?php if ( $notice && isset( $messages[ $notice ] ) ) : ?><div class="notice <?php echo in_array( $notice, array( 'saved', 'toggled', 'switched', 'deleted' ), true ) ? 'notice-success' : 'notice-warning'; ?> is-dismissible"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div><?php endif; ?>

		<div class="awm-card awm-mt18">
			<h2>Managed Routes</h2>
			<div class="awm-table-wrap"><table class="widefat striped"><thead><tr><th>Status</th><th>Route</th><th>Active Number</th><th>Stable Links</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
			<?php if ( $routes ) : foreach ( $routes as $slug => $item ) : $item = array_merge( awm_managed_route_defaults(), $item ); ?>
				<tr>
					<td><span class="awm-status <?php echo '1' === $item['enabled'] ? 'awm-status-approved' : ''; ?>"><?php echo '1' === $item['enabled'] ? 'Active' : 'Disabled'; ?></span><br><span class="awm-muted"><?php echo 'backup' === $item['active_line'] ? 'Backup line' : 'Primary line'; ?></span></td>
					<td><strong><?php echo esc_html( $item['name'] ); ?></strong><br><code><?php echo esc_html( $slug ); ?></code><?php if ( $item['source_label'] ) : ?><br><span class="awm-muted"><?php echo esc_html( $item['source_label'] ); ?></span><?php endif; ?></td>
					<td><code><?php echo esc_html( awm_managed_route_number( $item ) ?: 'Not configured' ); ?></code></td>
					<td><div><a href="<?php echo esc_url( awm_managed_route_url( $slug, 'en' ) ); ?>" target="_blank" rel="noopener noreferrer">Default</a><?php if ( trim( (string) $item['message_zh'] ) !== '' ) : ?> · <a href="<?php echo esc_url( awm_managed_route_url( $slug, 'zh' ) ); ?>" target="_blank" rel="noopener noreferrer">ZH</a><?php endif; ?><?php if ( trim( (string) $item['message_id'] ) !== '' ) : ?> · <a href="<?php echo esc_url( awm_managed_route_url( $slug, 'id' ) ); ?>" target="_blank" rel="noopener noreferrer">ID</a><?php endif; ?></div><code><?php echo esc_html( awm_managed_route_url( $slug ) ); ?></code></td>
					<td><?php echo esc_html( $item['updated_at'] ?: '—' ); ?></td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'awm-routes', 'edit' => $slug ), admin_url( 'admin.php' ) ) ); ?>">Edit</a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="awm-inline-form"><input type="hidden" name="action" value="awm_toggle_managed_route"><input type="hidden" name="route_slug" value="<?php echo esc_attr( $slug ); ?>"><?php wp_nonce_field( 'awm_toggle_managed_route' ); ?><button class="button button-small" type="submit"><?php echo '1' === $item['enabled'] ? 'Disable' : 'Enable'; ?></button></form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="awm-inline-form"><input type="hidden" name="action" value="awm_switch_managed_route"><input type="hidden" name="route_slug" value="<?php echo esc_attr( $slug ); ?>"><?php wp_nonce_field( 'awm_switch_managed_route' ); ?><button class="button button-small" type="submit">Use <?php echo 'backup' === $item['active_line'] ? 'Primary' : 'Backup'; ?></button></form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="awm-inline-form" data-awm-confirm="Delete this central route? Existing managed links will stop working."><input type="hidden" name="action" value="awm_delete_managed_route"><input type="hidden" name="route_slug" value="<?php echo esc_attr( $slug ); ?>"><?php wp_nonce_field( 'awm_delete_managed_route' ); ?><button class="button button-small" type="submit">Delete</button></form>
					</td>
				</tr>
			<?php endforeach; else : ?><tr><td colspan="6">No central routes have been created.</td></tr><?php endif; ?>
			</tbody></table></div>
		</div>

		<div class="awm-card awm-mt18">
			<h2><?php echo $is_edit ? 'Edit Central Route' : 'Add Central Route'; ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="awm_save_managed_route"><input type="hidden" name="route[existing_slug]" value="<?php echo esc_attr( $is_edit ? $edit : '' ); ?>"><?php wp_nonce_field( 'awm_save_managed_route' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="awm-route-name">Route Name</label></th><td><input id="awm-route-name" name="route[name]" type="text" class="regular-text" maxlength="191" required value="<?php echo esc_attr( $route['name'] ); ?>" placeholder="Customer Support"></td></tr>
					<tr><th><label for="awm-route-slug">Route Slug</label></th><td><?php if ( $is_edit ) : ?><code><?php echo esc_html( $edit ); ?></code><input type="hidden" name="route[slug]" value="<?php echo esc_attr( $edit ); ?>"><p class="description">The slug is locked so existing website links cannot be broken.</p><?php else : ?><input id="awm-route-slug" name="route[slug]" type="text" class="regular-text code" maxlength="80" required placeholder="customer-support"><p class="description">Used in <code>/go/whatsapp/customer-support/</code>. It cannot be changed later.</p><?php endif; ?></td></tr>
					<tr><th>Route Status</th><td><label><input name="route[enabled]" type="checkbox" value="1" <?php checked( $route['enabled'], '1' ); ?>> Enable redirect</label></td></tr>
					<tr><th><label for="awm-primary-number">Primary Number</label></th><td><input id="awm-primary-number" name="route[primary_number]" type="text" class="regular-text code" required value="<?php echo esc_attr( $route['primary_number'] ); ?>" placeholder="12025550100"></td></tr>
					<tr><th><label for="awm-backup-number">Backup Number</label></th><td><input id="awm-backup-number" name="route[backup_number]" type="text" class="regular-text code" value="<?php echo esc_attr( $route['backup_number'] ); ?>" placeholder="12025550108"></td></tr>
					<tr><th><label for="awm-active-line">Active Line</label></th><td><select id="awm-active-line" name="route[active_line]"><option value="primary" <?php selected( $route['active_line'], 'primary' ); ?>>Primary</option><option value="backup" <?php selected( $route['active_line'], 'backup' ); ?>>Backup</option></select></td></tr>
					<tr><th><label for="awm-route-source">Source / Use Case</label></th><td><input id="awm-route-source" name="route[source_label]" type="text" class="regular-text" maxlength="191" value="<?php echo esc_attr( $route['source_label'] ); ?>" placeholder="Customer Support"><p class="description">Used for tracker attribution.</p></td></tr>
					<tr><th><label for="awm-message-en">Default Message</label></th><td><textarea id="awm-message-en" name="route[message_en]" rows="4" class="large-text" maxlength="1000"><?php echo esc_textarea( $route['message_en'] ); ?></textarea></td></tr>
					<tr><th><label for="awm-message-zh">Optional Chinese (zh) Message</label></th><td><textarea id="awm-message-zh" name="route[message_zh]" rows="4" class="large-text" maxlength="1000"><?php echo esc_textarea( $route['message_zh'] ); ?></textarea><p class="description">Falls back to the default message when empty.</p></td></tr>
					<tr><th><label for="awm-message-id">Optional Indonesian (id) Message</label></th><td><textarea id="awm-message-id" name="route[message_id]" rows="4" class="large-text" maxlength="1000"><?php echo esc_textarea( $route['message_id'] ); ?></textarea><p class="description">Falls back to the default message when empty.</p></td></tr>
					<tr><th><label for="awm-route-fallback">Unavailable Fallback URL</label></th><td><input id="awm-route-fallback" name="route[fallback_url]" type="url" class="large-text" value="<?php echo esc_attr( $route['fallback_url'] ); ?>"><p class="description">Used when the route is disabled or has no usable number. Keep this on the same website.</p></td></tr>
				</table>
				<?php submit_button( $is_edit ? 'Update Central Route' : 'Create Central Route' ); ?><?php if ( $is_edit ) : ?> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=awm-routes' ) ); ?>">Cancel Editing</a><?php endif; ?>
			</form>
		</div>
	</div>
	<?php
}
