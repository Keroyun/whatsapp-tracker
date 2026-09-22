<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function awm_audit_log_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	global $wpdb;
	$table = awm_audit_log_table();
	$page  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
	$limit = 100;
	$offset = ( $page - 1 ) * $limit;
	$rows = awm_table_exists( $table ) ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ) ) : array();
	$total = awm_table_exists( $table ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0;
	$pages = max( 1, (int) ceil( $total / $limit ) );
	?>
	<div class="wrap awm-wrap">
		<h1>WhatsApp Tracker Audit Log</h1>
		<p class="awm-muted">Administrative change history only. Visitor clicks, IP addresses and user agents are not written here.</p>
		<div class="awm-card">
			<div class="awm-table-wrap"><table class="widefat striped"><thead><tr><th>Time</th><th>Administrator</th><th>Action</th><th>Object</th><th>Context</th></tr></thead><tbody>
			<?php if ( $rows ) : foreach ( $rows as $row ) : $user = $row->user_id ? get_userdata( (int) $row->user_id ) : false; $context = json_decode( (string) $row->context, true ); ?>
			<tr><td><?php echo esc_html( $row->created_at ); ?></td><td><?php echo esc_html( $user ? $user->display_name : ( $row->user_id ? '#' . $row->user_id : 'System' ) ); ?></td><td><code><?php echo esc_html( $row->action ); ?></code></td><td><?php echo esc_html( trim( $row->object_type . ' ' . $row->object_key ) ?: '—' ); ?></td><td><code><?php echo esc_html( wp_json_encode( is_array( $context ) ? $context : array() ) ); ?></code></td></tr>
			<?php endforeach; else : ?><tr><td colspan="5">No administrative changes logged yet.</td></tr><?php endif; ?>
			</tbody></table></div>
		</div>
		<?php if ( $pages > 1 ) : ?><p class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', admin_url( 'admin.php?page=awm-audit' ) ), 'format' => '', 'current' => $page, 'total' => $pages ) ) ); ?></p><?php endif; ?>
	</div>
	<?php
}
