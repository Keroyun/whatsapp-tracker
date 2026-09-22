<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* -------------------------------------------------------------------------
 * Admin menu and assets
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'awm_admin_menu' );
function awm_admin_menu() {
	add_menu_page(
		'WhatsApp Tracker',
		'WhatsApp Tracker',
		'manage_options',
		AWM_MENU_SLUG,
		'awm_dashboard_page',
		'dashicons-format-chat',
		58
	);

	add_submenu_page( AWM_MENU_SLUG, 'Dashboard', 'Dashboard', 'manage_options', AWM_MENU_SLUG, 'awm_dashboard_page' );
	add_submenu_page( AWM_MENU_SLUG, 'Page Analytics', 'Page Analytics', 'manage_options', 'awm-pages', 'awm_page_analytics_page' );
	add_submenu_page( AWM_MENU_SLUG, 'Central Routes', 'Central Routes', 'manage_options', 'awm-routes', 'awm_managed_routes_page' );
	add_submenu_page( AWM_MENU_SLUG, 'Bulk Migration', 'Bulk Migration', 'manage_options', 'awm-migration', 'awm_bulk_migration_page' );
	add_submenu_page( AWM_MENU_SLUG, 'Emergency Popups', 'Emergency Popups', 'manage_options', 'awm-emergency', 'awm_emergency_popups_page' );
	add_submenu_page( AWM_MENU_SLUG, 'Link Inventory', 'Link Inventory', 'manage_options', 'awm-inventory', 'awm_inventory_page' );
	add_submenu_page( AWM_MENU_SLUG, 'Link Generator', 'Link Generator', 'manage_options', 'awm-generator', 'awm_generator_page' );
	add_submenu_page( AWM_MENU_SLUG, 'Audit Log', 'Audit Log', 'manage_options', 'awm-audit', 'awm_audit_log_page' );
	add_submenu_page( AWM_MENU_SLUG, 'Settings', 'Settings', 'manage_options', 'awm-settings', 'awm_settings_page' );
}

/**
 * Backward-compatible no-op retained from 3.1. Styles are now loaded from
 * assets/admin.css to support stricter Content Security Policy rules.
 */
function awm_admin_styles() {
	return;
}

function awm_is_plugin_admin_page() {
	if ( ! is_admin() || empty( $_GET['page'] ) ) {
		return false;
	}
	$page = sanitize_key( wp_unslash( $_GET['page'] ) );
	return in_array( $page, array( AWM_MENU_SLUG, 'awm-pages', 'awm-routes', 'awm-migration', 'awm-emergency', 'awm-inventory', 'awm-generator', 'awm-audit', 'awm-settings' ), true );
}


add_action( 'admin_enqueue_scripts', 'awm_admin_security_assets' );
function awm_admin_security_assets() {
	if ( ! awm_is_plugin_admin_page() ) {
		return;
	}
	wp_enqueue_style( 'awm-admin', AWM_PLUGIN_URL . 'assets/admin.css', array(), AWM_VERSION );
	wp_enqueue_script( 'awm-admin-confirm', AWM_PLUGIN_URL . 'assets/admin-confirm.js', array(), AWM_VERSION, true );
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( 'awm-generator' === $page ) {
		wp_enqueue_script( 'awm-generator', AWM_PLUGIN_URL . 'assets/generator.js', array(), AWM_VERSION, true );
	} elseif ( 'awm-inventory' === $page ) {
		wp_enqueue_script( 'awm-scanner', AWM_PLUGIN_URL . 'assets/scanner.js', array(), AWM_VERSION, true );
	} elseif ( 'awm-migration' === $page ) {
		wp_enqueue_script( 'awm-migration', AWM_PLUGIN_URL . 'assets/migration.js', array(), AWM_VERSION, true );
	}
}


/* -------------------------------------------------------------------------
 * Dashboard
 * ---------------------------------------------------------------------- */

function awm_dashboard_metrics() {
	global $wpdb;
	$table = awm_clicks_table();

	$metrics = array(
		'today'       => 0,
		'month'       => 0,
		'total'       => 0,
		'peak_minute' => 0,
		'rows'        => 0,
	);

	if ( ! awm_table_exists( $table ) ) {
		return $metrics;
	}

	$today       = current_time( 'Y-m-d' );
	$month_start = current_time( 'Y-m-01' );

	$metrics['today'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(clicks),0) FROM {$table} WHERE click_date = %s", $today ) );
	$metrics['month'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(clicks),0) FROM {$table} WHERE click_date >= %s", $month_start ) );
	$metrics['total'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(clicks),0) FROM {$table}" );
	$metrics['rows']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

	return $metrics;
}

function awm_legacy_table_detected() {
	return awm_table_exists( awm_legacy_table() );
}

function awm_dashboard_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;
	$table       = awm_clicks_table();
	$metrics     = awm_dashboard_metrics();
	$latest_scan = get_option( 'awm_latest_scan', array() );
	$legacy_table_detected = awm_legacy_table_detected();
	$approved    = awm_approved_number_map();
	$approved_entries = awm_get_approved_numbers();
	$active_emergency_rules = awm_get_active_emergency_rules();

	$top_destinations = array();
	$top_pages        = array();
	$top_sources      = array();
	if ( awm_table_exists( $table ) ) {
		$month_start = current_time( 'Y-m-01' );
		$top_destinations = $wpdb->get_results( $wpdb->prepare( "SELECT CASE WHEN resolved_destination <> '' THEN resolved_destination ELSE destination END AS effective_destination, SUM(clicks) total FROM {$table} WHERE click_date >= %s GROUP BY effective_destination ORDER BY total DESC LIMIT 10", $month_start ) );
		$top_pages        = $wpdb->get_results( $wpdb->prepare( "SELECT page_path, SUM(clicks) total FROM {$table} WHERE click_date >= %s GROUP BY page_path ORDER BY total DESC LIMIT 10", $month_start ) );
		$top_sources      = $wpdb->get_results( $wpdb->prepare( "SELECT CASE WHEN resolved_destination <> '' THEN resolved_destination ELSE destination END AS effective_destination, source_label, SUM(clicks) total FROM {$table} WHERE click_date >= %s GROUP BY effective_destination, source_label ORDER BY total DESC LIMIT 10", $month_start ) );
	}
	?>
	<div class="wrap awm-wrap">
		<h1>WhatsApp Tracker</h1>
		<p class="awm-muted">Lightweight WhatsApp click analytics, link inventory and governance. Normal tracking does not delay WhatsApp navigation; active emergency rules intentionally intercept only their matching links.</p>
		<?php if ( $active_emergency_rules ) : ?>
			<div class="awm-warning"><strong>Emergency popup mode is active:</strong> <?php echo esc_html( number_format_i18n( count( $active_emergency_rules ) ) ); ?> rule(s) may divert matching WhatsApp or telephone clicks. <a href="<?php echo esc_url( admin_url( 'admin.php?page=awm-emergency' ) ); ?>">Review emergency popups</a>.</div>
		<?php endif; ?>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=awm_export_clicks' ), 'awm_export_clicks' ) ); ?>">Export Click Analytics CSV</a></p>

		<div class="awm-grid">
			<div class="awm-card"><div class="awm-muted">Clicks Today</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( $metrics['today'] ) ); ?></div></div>
			<div class="awm-card"><div class="awm-muted">Clicks This Month</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( $metrics['month'] ) ); ?></div></div>
			<div class="awm-card"><div class="awm-muted">Aggregate Rows</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( $metrics['rows'] ) ); ?></div><div class="awm-muted">No row-per-click logging</div></div>
			<div class="awm-card"><div class="awm-muted">Approved Numbers</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( count( $approved ) ) ); ?></div><div class="awm-muted"><?php echo esc_html( number_format_i18n( count( $approved_entries ) ) ); ?> configured use-case label(s)</div></div>
		</div>

		<?php if ( $legacy_table_detected ) : ?>
			<div class="awm-warning"><strong>Legacy data preserved:</strong> the previous Click Tracker table was detected. Version 2 does not delete, count on every dashboard load, or write to that table.</div>
		<?php endif; ?>

		<div class="awm-two awm-mt18">
			<div class="awm-card">
				<h2>Top Destinations — This Month</h2>
				<div class="awm-table-wrap"><table class="widefat striped"><thead><tr><th>Destination</th><th>Configured Label(s)</th><th>Clicks</th></tr></thead><tbody>
				<?php if ( $top_destinations ) : foreach ( $top_destinations as $row ) : ?>
					<tr><td><?php echo esc_html( $row->effective_destination ); ?></td><td><?php echo esc_html( isset( $approved[ $row->effective_destination ] ) ? $approved[ $row->effective_destination ] : '—' ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row->total ) ); ?></td></tr>
				<?php endforeach; else : ?><tr><td colspan="3">No click data yet.</td></tr><?php endif; ?>
				</tbody></table></div>
			</div>
			<div class="awm-card">
				<h2>Top Pages — This Month</h2>
				<div class="awm-table-wrap"><table class="widefat striped"><thead><tr><th>Page</th><th>Clicks</th></tr></thead><tbody>
				<?php if ( $top_pages ) : foreach ( $top_pages as $row ) : ?>
					<tr><td><code><?php echo esc_html( $row->page_path ); ?></code></td><td><?php echo esc_html( number_format_i18n( (int) $row->total ) ); ?></td></tr>
				<?php endforeach; else : ?><tr><td colspan="2">No click data yet.</td></tr><?php endif; ?>
				</tbody></table></div>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=awm-pages' ) ); ?>">View All Page Analytics</a></p>
			</div>
		</div>

		<div class="awm-card awm-mt18">
			<h2>Top Sources / Use Cases — This Month</h2>
			<p class="awm-muted">Source attribution is captured from <code>data-awm-source</code> / generated shortcodes, or from an admin-mapped <code>wa.link</code> use case. If a number has only one configured label, the plugin assigns it automatically. Multiple-label numbers without a tag/mapping appear as Unassigned.</p>
			<div class="awm-table-wrap"><table class="widefat striped"><thead><tr><th>Source / Use Case</th><th>Destination</th><th>Clicks</th></tr></thead><tbody>
			<?php if ( $top_sources ) : foreach ( $top_sources as $row ) : ?>
				<tr><td><?php echo esc_html( $row->source_label ? $row->source_label : 'Unassigned' ); ?></td><td><?php echo esc_html( $row->effective_destination ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row->total ) ); ?></td></tr>
			<?php endforeach; else : ?><tr><td colspan="3">No click data yet.</td></tr><?php endif; ?>
			</tbody></table></div>
		</div>

		<div class="awm-card awm-mt18">
			<h2>Latest Link Inventory Scan</h2>
			<?php if ( ! empty( $latest_scan ) ) : ?>
				<p><strong>Mode:</strong> <?php echo esc_html( awm_scan_mode_label( $latest_scan['mode'] ?? '' ) ); ?> &nbsp; <strong>Completed:</strong> <?php echo esc_html( $latest_scan['completed_at'] ); ?></p>
				<p>Pages scanned: <strong><?php echo esc_html( number_format_i18n( (int) $latest_scan['pages_scanned'] ) ); ?></strong> · Links found: <strong><?php echo esc_html( number_format_i18n( (int) $latest_scan['links_found'] ) ); ?></strong> · Unique destinations: <strong><?php echo esc_html( number_format_i18n( (int) $latest_scan['unique_destinations'] ) ); ?></strong></p>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=awm-inventory' ) ); ?>">Open Link Inventory</a></p>
			<?php else : ?>
				<p>No scan has been completed yet.</p><p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=awm-inventory' ) ); ?>">Run First Scan</a></p>
			<?php endif; ?>
		</div>
	</div>
	<?php
}


/* -------------------------------------------------------------------------
 * Page analytics — complete page-level reporting
 * ---------------------------------------------------------------------- */

function awm_page_analytics_valid_date( $value ) {
	$value = sanitize_text_field( (string) $value );
	$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
	return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
}

function awm_page_analytics_filters_from_request() {
	$allowed_periods = array( 'all', 'this_month', 'last_30', 'last_90', 'custom' );
	$period = isset( $_GET['awm_period'] ) ? sanitize_key( wp_unslash( $_GET['awm_period'] ) ) : 'all';
	if ( ! in_array( $period, $allowed_periods, true ) ) {
		$period = 'all';
	}

	$search = isset( $_GET['awm_search'] ) ? sanitize_text_field( wp_unslash( $_GET['awm_search'] ) ) : '';
	$search = substr( $search, 0, 191 );
	$start  = '';
	$end    = '';
	$now    = new DateTimeImmutable( 'now', wp_timezone() );

	switch ( $period ) {
		case 'this_month':
			$start = $now->format( 'Y-m-01' );
			$end   = $now->format( 'Y-m-d' );
			break;
		case 'last_30':
			$start = $now->modify( '-29 days' )->format( 'Y-m-d' );
			$end   = $now->format( 'Y-m-d' );
			break;
		case 'last_90':
			$start = $now->modify( '-89 days' )->format( 'Y-m-d' );
			$end   = $now->format( 'Y-m-d' );
			break;
		case 'custom':
			$start = isset( $_GET['awm_start'] ) ? awm_page_analytics_valid_date( wp_unslash( $_GET['awm_start'] ) ) : '';
			$end   = isset( $_GET['awm_end'] ) ? awm_page_analytics_valid_date( wp_unslash( $_GET['awm_end'] ) ) : '';
			if ( $start && $end && $start > $end ) {
				$temporary = $start;
				$start     = $end;
				$end       = $temporary;
			}
			break;
	}

	$per_page = isset( $_GET['awm_per_page'] ) ? absint( $_GET['awm_per_page'] ) : 100;
	if ( ! in_array( $per_page, array( 50, 100, 200 ), true ) ) {
		$per_page = 100;
	}

	$order = isset( $_GET['awm_order'] ) ? sanitize_key( wp_unslash( $_GET['awm_order'] ) ) : 'most';
	if ( ! in_array( $order, array( 'most', 'least' ), true ) ) {
		$order = 'most';
	}

	return array(
		'period'   => $period,
		'search'   => $search,
		'start'    => $start,
		'end'      => $end,
		'per_page' => $per_page,
		'order'    => $order,
	);
}

function awm_page_analytics_period_label( $filters ) {
	switch ( $filters['period'] ) {
		case 'this_month':
			return 'This month';
		case 'last_30':
			return 'Last 30 days';
		case 'last_90':
			return 'Last 90 days';
		case 'custom':
			if ( $filters['start'] && $filters['end'] ) {
				return $filters['start'] . ' to ' . $filters['end'];
			}
			if ( $filters['start'] ) {
				return 'From ' . $filters['start'];
			}
			if ( $filters['end'] ) {
				return 'Through ' . $filters['end'];
			}
			return 'Custom range (all dates)';
		default:
			return 'All retained data';
	}
}

function awm_page_analytics_query_parts( $filters ) {
	global $wpdb;
	$clauses = array( '1=1' );
	$params  = array();

	if ( $filters['start'] ) {
		$clauses[] = 'click_date >= %s';
		$params[]  = $filters['start'];
	}
	if ( $filters['end'] ) {
		$clauses[] = 'click_date <= %s';
		$params[]  = $filters['end'];
	}
	if ( '' !== $filters['search'] ) {
		$clauses[] = 'page_path LIKE %s';
		$params[]  = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
	}

	return array(
		'where'  => 'WHERE ' . implode( ' AND ', $clauses ),
		'params' => $params,
	);
}

function awm_page_analytics_prepare_query( $sql, $params ) {
	global $wpdb;
	return $params ? $wpdb->prepare( $sql, $params ) : $sql;
}

function awm_page_analytics_summary( $filters ) {
	global $wpdb;
	$table = awm_clicks_table();
	if ( ! awm_table_exists( $table ) ) {
		return array( 'pages' => 0, 'clicks' => 0 );
	}

	$parts = awm_page_analytics_query_parts( $filters );
	$sql   = "SELECT COUNT(DISTINCT page_path) AS pages, COALESCE(SUM(clicks),0) AS clicks FROM {$table} {$parts['where']}";
	$row   = $wpdb->get_row( awm_page_analytics_prepare_query( $sql, $parts['params'] ) );

	return array(
		'pages'  => $row ? (int) $row->pages : 0,
		'clicks' => $row ? (int) $row->clicks : 0,
	);
}

function awm_page_analytics_rows( $filters, $limit = 0, $offset = 0 ) {
	global $wpdb;
	$table = awm_clicks_table();
	if ( ! awm_table_exists( $table ) ) {
		return array();
	}

	$parts = awm_page_analytics_query_parts( $filters );
	$order = ( 'least' === $filters['order'] ) ? 'total_clicks ASC, page_path ASC' : 'total_clicks DESC, page_path ASC';
	$sql   = "SELECT
		page_path,
		SUM(clicks) AS total_clicks,
		MIN(first_click) AS first_click,
		MAX(last_click) AS last_click,
		GROUP_CONCAT(DISTINCT (CASE WHEN resolved_destination <> '' THEN resolved_destination ELSE destination END) ORDER BY (CASE WHEN resolved_destination <> '' THEN resolved_destination ELSE destination END) SEPARATOR '||') AS destinations,
		GROUP_CONCAT(DISTINCT (CASE WHEN source_label <> '' THEN source_label ELSE 'Unassigned' END) ORDER BY (CASE WHEN source_label <> '' THEN source_label ELSE 'Unassigned' END) SEPARATOR '||') AS sources
		FROM {$table}
		{$parts['where']}
		GROUP BY page_path
		ORDER BY {$order}";

	$params = $parts['params'];
	if ( $limit > 0 ) {
		$sql     .= ' LIMIT %d OFFSET %d';
		$params[] = absint( $limit );
		$params[] = absint( $offset );
	}

	return $wpdb->get_results( awm_page_analytics_prepare_query( $sql, $params ) );
}

function awm_page_analytics_split_list( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return array();
	}
	return array_values( array_filter( array_map( 'trim', explode( '||', $value ) ), 'strlen' ) );
}

function awm_page_analytics_url_for_path( $path ) {
	$path      = '/' . ltrim( (string) $path, '/' );
	$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$home_path = '/' . trim( $home_path, '/' );

	if ( '/' !== $home_path && ( $path === $home_path || 0 === strpos( $path, $home_path . '/' ) ) ) {
		$path = substr( $path, strlen( $home_path ) );
		$path = '/' . ltrim( $path, '/' );
	}

	return home_url( $path );
}

function awm_page_analytics_filter_args( $filters ) {
	$args = array(
		'awm_period'   => $filters['period'],
		'awm_per_page' => $filters['per_page'],
		'awm_order'    => $filters['order'],
	);
	if ( '' !== $filters['search'] ) {
		$args['awm_search'] = $filters['search'];
	}
	if ( 'custom' === $filters['period'] ) {
		if ( $filters['start'] ) {
			$args['awm_start'] = $filters['start'];
		}
		if ( $filters['end'] ) {
			$args['awm_end'] = $filters['end'];
		}
	}
	return $args;
}

function awm_page_analytics_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$filters     = awm_page_analytics_filters_from_request();
	$summary     = awm_page_analytics_summary( $filters );
	$page_number = isset( $_GET['awm_paged'] ) ? max( 1, absint( $_GET['awm_paged'] ) ) : 1;
	$total_pages = max( 1, (int) ceil( $summary['pages'] / $filters['per_page'] ) );
	$page_number = min( $page_number, $total_pages );
	$offset      = ( $page_number - 1 ) * $filters['per_page'];
	$rows        = awm_page_analytics_rows( $filters, $filters['per_page'], $offset );
	$approved    = awm_approved_number_map();

	$export_args = array_merge(
		array( 'action' => 'awm_export_page_analytics' ),
		awm_page_analytics_filter_args( $filters )
	);
	$export_url = wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin-post.php' ) ), 'awm_export_page_analytics' );
	?>
	<div class="wrap awm-wrap">
		<h1>Page Analytics</h1>
		<p class="awm-muted">See every website page that has recorded a WhatsApp click, not only the dashboard's top 10 pages for this month. To find installed WhatsApp links that have never been clicked, use <a href="<?php echo esc_url( admin_url( 'admin.php?page=awm-inventory' ) ); ?>">Link Inventory</a>.</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="awm-pages">
			<div class="awm-toolbar">
				<label for="awm-period"><strong>Period</strong></label>
				<select id="awm-period" name="awm_period">
					<option value="all" <?php selected( $filters['period'], 'all' ); ?>>All retained data</option>
					<option value="this_month" <?php selected( $filters['period'], 'this_month' ); ?>>This month</option>
					<option value="last_30" <?php selected( $filters['period'], 'last_30' ); ?>>Last 30 days</option>
					<option value="last_90" <?php selected( $filters['period'], 'last_90' ); ?>>Last 90 days</option>
					<option value="custom" <?php selected( $filters['period'], 'custom' ); ?>>Custom range</option>
				</select>
				<label for="awm-start">From</label>
				<input id="awm-start" type="date" name="awm_start" value="<?php echo esc_attr( $filters['start'] ); ?>">
				<label for="awm-end">To</label>
				<input id="awm-end" type="date" name="awm_end" value="<?php echo esc_attr( $filters['end'] ); ?>">
				<label for="awm-page-search">Page</label>
				<input id="awm-page-search" type="search" name="awm_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Search page path">
				<label for="awm-order">Click order</label>
				<select id="awm-order" name="awm_order">
					<option value="most" <?php selected( $filters['order'], 'most' ); ?>>Most to least</option>
					<option value="least" <?php selected( $filters['order'], 'least' ); ?>>Least to most</option>
				</select>
				<label for="awm-per-page">Rows</label>
				<select id="awm-per-page" name="awm_per_page">
					<option value="50" <?php selected( $filters['per_page'], 50 ); ?>>50</option>
					<option value="100" <?php selected( $filters['per_page'], 100 ); ?>>100</option>
					<option value="200" <?php selected( $filters['per_page'], 200 ); ?>>200</option>
				</select>
				<button type="submit" class="button button-primary">Apply Filters</button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=awm-pages' ) ); ?>">Reset</a>
				<a class="button" href="<?php echo esc_url( $export_url ); ?>">Export All Matching Pages CSV</a>
			</div>
			<p class="description">The From and To fields apply when Period is set to Custom range.</p>
		</form>

		<div class="awm-grid">
			<div class="awm-card"><div class="awm-muted">Tracked Pages</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( $summary['pages'] ) ); ?></div></div>
			<div class="awm-card"><div class="awm-muted">WhatsApp Clicks</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( $summary['clicks'] ) ); ?></div></div>
			<div class="awm-card"><div class="awm-muted">Reporting Period</div><div class="awm-report-period"><?php echo esc_html( awm_page_analytics_period_label( $filters ) ); ?></div></div>
			<div class="awm-card"><div class="awm-muted">Current Result Page</div><div class="awm-metric"><?php echo esc_html( number_format_i18n( $page_number ) ); ?> / <?php echo esc_html( number_format_i18n( $total_pages ) ); ?></div></div>
		</div>

		<div class="awm-card">
			<div class="awm-table-wrap">
				<table class="widefat striped">
					<thead><tr><th>Website Page</th><th>Destination(s)</th><th>Source / Use Case(s)</th><th>Clicks</th><th>First Click</th><th>Last Click</th></tr></thead>
					<tbody>
					<?php if ( $rows ) : foreach ( $rows as $row ) : ?>
						<?php $destinations = awm_page_analytics_split_list( $row->destinations ); ?>
						<tr>
							<td><a href="<?php echo esc_url( awm_page_analytics_url_for_path( $row->page_path ) ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( $row->page_path ); ?></code></a></td>
							<td>
								<?php if ( $destinations ) : foreach ( $destinations as $destination ) : ?>
									<div><code><?php echo esc_html( $destination ); ?></code><?php if ( isset( $approved[ $destination ] ) ) : ?> <span class="awm-muted">— <?php echo esc_html( $approved[ $destination ] ); ?></span><?php endif; ?></div>
								<?php endforeach; else : ?>—<?php endif; ?>
							</td>
							<td><?php echo esc_html( implode( ', ', awm_page_analytics_split_list( $row->sources ) ) ); ?></td>
							<td><strong><?php echo esc_html( number_format_i18n( (int) $row->total_clicks ) ); ?></strong></td>
							<td><?php echo esc_html( $row->first_click ); ?></td>
							<td><?php echo esc_html( $row->last_click ); ?></td>
						</tr>
					<?php endforeach; else : ?>
						<tr><td colspan="6">No page-level WhatsApp click data matches these filters.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $total_pages > 1 ) : ?>
				<?php
				$pagination_args = array_merge(
					array( 'page' => 'awm-pages' ),
					awm_page_analytics_filter_args( $filters ),
					array( 'awm_paged' => 999999999 )
				);
				$pagination_base = str_replace( '999999999', '%#%', add_query_arg( $pagination_args, admin_url( 'admin.php' ) ) );
				?>
				<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => $pagination_base, 'format' => '', 'current' => $page_number, 'total' => $total_pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ) ); ?></div></div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}


/* -------------------------------------------------------------------------
 * CSV exports — admin only, formula-injection hardened
 * ---------------------------------------------------------------------- */

function awm_csv_safe_cell( $value ) {
	$value = (string) $value;
	if ( preg_match( '/^[\x00-\x20]*[=+\-@]/', $value ) ) {
		$value = "'" . $value;
	}
	return $value;
}

add_action( 'admin_post_awm_export_page_analytics', 'awm_export_page_analytics_csv' );
function awm_export_page_analytics_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'awm_export_page_analytics' );

	$filters  = awm_page_analytics_filters_from_request();
	$rows     = awm_page_analytics_rows( $filters );
	$approved = awm_approved_number_map();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=whatsapp-tracker-pages-' . current_time( 'Y-m-d' ) . '.csv' );

	$output = fopen( 'php://output', 'w' );
	fputcsv( $output, array( 'Page Path', 'Page URL', 'Destinations', 'Approved Label(s)', 'Source / Use Case(s)', 'Clicks', 'First Click', 'Last Click', 'Reporting Period' ) );

	foreach ( $rows as $row ) {
		$destinations    = awm_page_analytics_split_list( $row->destinations );
		$approved_labels = array();
		foreach ( $destinations as $destination ) {
			if ( isset( $approved[ $destination ] ) && ! in_array( $approved[ $destination ], $approved_labels, true ) ) {
				$approved_labels[] = $approved[ $destination ];
			}
		}

		fputcsv(
			$output,
			array(
				awm_csv_safe_cell( $row->page_path ),
				awm_csv_safe_cell( awm_page_analytics_url_for_path( $row->page_path ) ),
				awm_csv_safe_cell( implode( ' / ', $destinations ) ),
				awm_csv_safe_cell( implode( ' / ', $approved_labels ) ),
				awm_csv_safe_cell( implode( ' / ', awm_page_analytics_split_list( $row->sources ) ) ),
				(int) $row->total_clicks,
				awm_csv_safe_cell( $row->first_click ),
				awm_csv_safe_cell( $row->last_click ),
				awm_csv_safe_cell( awm_page_analytics_period_label( $filters ) ),
			)
		);
	}

	fclose( $output );
	exit;
}

add_action( 'admin_post_awm_export_clicks', 'awm_export_clicks_csv' );
function awm_export_clicks_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'awm_export_clicks' );

	global $wpdb;
	$table    = awm_clicks_table();
	$approved = awm_approved_number_map();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=whatsapp-tracker-clicks-' . current_time( 'Y-m-d' ) . '.csv' );

	$output = fopen( 'php://output', 'w' );
	fputcsv( $output, array( 'Date', 'Page Path', 'Original Destination', 'Resolved / Effective Destination', 'Approved Label(s)', 'Source / Use Case', 'Link Type', 'Link Text', 'Clicks', 'First Click', 'Last Click' ) );

	$offset = 0;
	$limit  = 1000;
	do {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT click_date, page_path, destination, resolved_destination, source_label, link_type, link_text, clicks, first_click, last_click FROM {$table} ORDER BY click_date DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset ) );
		foreach ( $rows as $row ) {
			$effective = awm_effective_destination_value( $row->destination, $row->resolved_destination );
			fputcsv(
				$output,
				array(
					awm_csv_safe_cell( $row->click_date ),
					awm_csv_safe_cell( $row->page_path ),
					awm_csv_safe_cell( $row->destination ),
					awm_csv_safe_cell( $effective ),
					awm_csv_safe_cell( isset( $approved[ $effective ] ) ? $approved[ $effective ] : '' ),
					awm_csv_safe_cell( $row->source_label ),
					awm_csv_safe_cell( $row->link_type ),
					awm_csv_safe_cell( $row->link_text ),
					(int) $row->clicks,
					awm_csv_safe_cell( $row->first_click ),
					awm_csv_safe_cell( $row->last_click ),
				)
			);
		}
		$offset += $limit;
	} while ( count( $rows ) === $limit );

	fclose( $output );
	exit;
}

add_action( 'admin_post_awm_export_inventory', 'awm_export_inventory_csv' );
function awm_export_inventory_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'awm_export_inventory' );

	$latest = get_option( 'awm_latest_scan', array() );
	if ( empty( $latest['scan_id'] ) ) {
		wp_die( 'No completed inventory scan is available.' );
	}

	global $wpdb;
	$table    = awm_inventory_table();
	$approved = awm_approved_number_map();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=whatsapp-tracker-inventory-' . current_time( 'Y-m-d' ) . '.csv' );

	$output = fopen( 'php://output', 'w' );
	fputcsv( $output, array( 'Status', 'Original Destination', 'Resolved / Effective Destination', 'Approved Label(s)', 'Source / Use Case', 'Page Title', 'Page URL', 'WhatsApp Link', 'Link Type', 'Link Text', 'Scan Mode', 'Last Seen' ) );

	$offset = 0;
	$limit  = 1000;
	do {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, destination, resolved_destination, source_label, page_title, page_url, link_url, link_type, link_text, scan_mode, last_seen FROM {$table} WHERE scan_id = %s ORDER BY id ASC LIMIT %d OFFSET %d", $latest['scan_id'], $limit, $offset ) );
		foreach ( $rows as $row ) {
			$effective = awm_effective_destination_value( $row->destination, $row->resolved_destination );
			fputcsv(
				$output,
				array(
					awm_csv_safe_cell( $row->status ),
					awm_csv_safe_cell( $row->destination ),
					awm_csv_safe_cell( $effective ),
					awm_csv_safe_cell( isset( $approved[ $effective ] ) ? $approved[ $effective ] : '' ),
					awm_csv_safe_cell( $row->source_label ),
					awm_csv_safe_cell( $row->page_title ),
					awm_csv_safe_cell( $row->page_url ),
					awm_csv_safe_cell( $row->link_url ),
					awm_csv_safe_cell( $row->link_type ),
					awm_csv_safe_cell( $row->link_text ),
					awm_csv_safe_cell( $row->scan_mode ),
					awm_csv_safe_cell( $row->last_seen ),
				)
			);
		}
		$offset += $limit;
	} while ( count( $rows ) === $limit );

	fclose( $output );
	exit;
}
