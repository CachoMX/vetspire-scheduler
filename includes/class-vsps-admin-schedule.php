<?php
/**
 * Admin screen: Bookings — the widget's own log (what the website booked,
 * when, and what happened to it since — statuses refreshed from Vetspire,
 * rows never vanish). Vetspire remains the source of truth for editing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Admin_Schedule {

	const PER_PAGE = 25;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_dashboard_widget' ) );
		add_action( 'admin_post_vsps_export_bookings', array( __CLASS__, 'handle_export' ) );
	}

	public static function add_menu() {
		$bubble = self::pending_bubble();
		add_menu_page(
			'Vetspire Scheduler',
			'Vetspire Scheduler' . $bubble,
			'manage_options',
			'vsps-appointments',
			array( __CLASS__, 'render_page' ),
			'dashicons-calendar-alt',
			56
		);
		// Rename the auto-created first submenu item to "Bookings".
		add_submenu_page(
			'vsps-appointments',
			'Vetspire Scheduler — Bookings',
			'Bookings' . $bubble,
			'manage_options',
			'vsps-appointments',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Pending count = unconfirmed, upcoming widget bookings in the local log.
	 * Cheap local query, cached briefly because admin_menu runs on every wp-admin page.
	 */
	private static function pending_bubble() {
		$count = get_transient( 'vsps_pending_online' );
		if ( false === $count ) {
			$count = class_exists( 'VSPS_Log' ) ? VSPS_Log::pending_count() : 0;
			set_transient( 'vsps_pending_online', $count, 120 );
		}
		if ( ! $count ) {
			return '';
		}
		return ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>';
	}

	private static function refresh_pending_count() {
		set_transient( 'vsps_pending_online', VSPS_Log::pending_count(), 120 );
	}

	/* ---------- data ---------- */

	private static function location_context() {
		$settings    = vsps_get_settings();
		$location_id = absint( $settings['default_location'] );
		$api         = vsps_api();
		if ( null === $api || ! $location_id ) {
			return null;
		}
		$location = VSPS_Cache::remember( array( 'admin-loc', $location_id ), function () use ( $api, $location_id ) {
			return $api->get_location( $location_id );
		}, 3600 );
		if ( is_wp_error( $location ) || empty( $location['timezone'] ) ) {
			return null;
		}
		return array(
			'api'      => $api,
			'id'       => $location_id,
			'name'     => $location['name'],
			'timezone' => new DateTimeZone( $location['timezone'] ),
		);
	}

	/* ---------- CSV export (current filters) ---------- */

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'vsps_export_bookings' );
		$ctx     = self::location_context();
		$tz      = $ctx ? $ctx['timezone'] : wp_timezone();
		$filters        = self::filters_from_request();
		$show_client    = ! empty( vsps_get_settings()['admin_show_client'] );
		$filters['pii'] = $show_client ? 1 : 0;
		$data           = VSPS_Log::query( $filters, 1, 5000 );
		$csv            = VSPS_Log::csv( $data['rows'], $show_client, $tz );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="vetspire-scheduler-bookings-' . current_time( 'Y-m-d' ) . '.csv"' );
		echo "\xEF\xBB\xBF" . $csv; // BOM so Excel reads UTF-8
		exit;
	}

	private static function filters_from_request() {
		$src = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters
		$status = isset( $src['status'] ) ? sanitize_key( wp_unslash( $src['status'] ) ) : 'all';
		if ( ! in_array( $status, array( 'all', 'pending', 'confirmed', 'cancelled', 'deleted', 'completed', 'no_show', 'failed' ), true ) ) {
			$status = 'all';
		}
		$after_hours = isset( $src['after_hours'] ) ? sanitize_key( wp_unslash( $src['after_hours'] ) ) : 'all';
		if ( ! in_array( $after_hours, array( 'all', 'yes', 'no' ), true ) ) {
			$after_hours = 'all';
		}
		$date = function ( $k ) use ( $src ) {
			$v = isset( $src[ $k ] ) ? sanitize_text_field( wp_unslash( $src[ $k ] ) ) : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		// "Include failed attempts" defaults to CHECKED on a fresh page load; once the
		// filter form has been submitted (vsps_filtered present) an unchecked box means 0.
		$failed  = isset( $src['vsps_filtered'] ) ? ( ! empty( $src['failed'] ) ? 1 : 0 ) : 1;
		$orderby = isset( $src['orderby'] ) ? sanitize_key( wp_unslash( $src['orderby'] ) ) : 'created';
		if ( ! array_key_exists( $orderby, VSPS_Log::SORTABLE_COLUMNS ) ) {
			$orderby = 'created';
		}
		$order = isset( $src['order'] ) && 'asc' === sanitize_key( wp_unslash( $src['order'] ) ) ? 'asc' : 'desc';
		return array(
			'status'              => $status,
			'after_hours'         => $after_hours,
			'appointment_type_id' => isset( $src['appointment_type_id'] ) ? absint( $src['appointment_type_id'] ) : 0,
			'provider'            => isset( $src['provider'] ) ? substr( sanitize_text_field( wp_unslash( $src['provider'] ) ), 0, 120 ) : '',
			'from'                => $date( 'from' ),
			'to'                  => $date( 'to' ),
			'failed'              => $failed,
			'orderby'             => $orderby,
			'order'               => $order,
		);
	}

	/**
	 * Turns the current filters into URL query args for pagination/Sync/Export
	 * links. Plain `array_filter($filters)` looks safe but silently drops
	 * `failed => 0` (an unchecked "Include failed attempts" box) because 0 is
	 * falsy, and never adds `vsps_filtered` — so a link built that way makes
	 * `filters_from_request()` treat the request as if the filter form was
	 * never submitted and fall back to its "include failed" default,
	 * regardless of what the checkbox actually said. This keeps every
	 * genuinely-set value (including an explicit 0) and always marks the
	 * link as filtered.
	 */
	private static function filters_as_query_args( array $filters ) {
		$args = array();
		foreach ( $filters as $key => $value ) {
			if ( 'pii' === $key ) {
				continue;
			}
			if ( '' !== $value && 0 !== $value ) {
				$args[ $key ] = $value;
			}
		}
		$args['failed']        = (int) $filters['failed'];
		$args['vsps_filtered'] = 1;
		return $args;
	}

	/* ---------- page ---------- */

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ctx = self::location_context();
		echo '<div class="wrap"><h1 class="wp-heading-inline">Bookings</h1>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=vsps-settings' ) ) . '" class="page-title-action">Settings</a>';
		echo '<hr class="wp-header-end" />';

		if ( null === $ctx ) {
			echo '<div class="card" style="max-width:520px;padding:20px 24px;"><h2 style="margin-top:0;">Almost ready</h2>'
				. '<p>Connect your Vetspire account and pick a clinic location to see bookings here.</p>'
				. '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=vsps-settings' ) ) . '">Open Settings</a></p></div></div>';
			return;
		}

		self::render_bookings( $ctx );
		echo '</div>';
	}

	/* ---------- Bookings tab (the widget's log) ---------- */

	private static function render_bookings( $ctx ) {
		$settings        = vsps_get_settings();
		$show_client     = ! empty( $settings['admin_show_client'] );
		$filters         = self::filters_from_request();
		$filters['pii']  = $show_client ? 1 : 0;
		$paged           = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$imported = VSPS_Log::maybe_backfill( $ctx['api'], $ctx['id'], $ctx['timezone'] );
		if ( $imported ) {
			echo '<div class="notice notice-info is-dismissible"><p>Imported ' . (int) $imported . ' earlier online bookings from Vetspire into the log.</p></div>';
		}

		$force  = isset( $_GET['vsps_sync'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['vsps_sync'] ) ), 'vsps_sync' );
		$data   = VSPS_Log::query( $filters, $paged, self::PER_PAGE );
		$synced = VSPS_Log::sync_rows( $ctx['api'], $data['rows'], $force );
		if ( $force ) {
			echo '<div class="notice notice-info is-dismissible"><p>Refreshed ' . (int) $synced . ' ' . ( 1 === $synced ? 'booking' : 'bookings' ) . ' from Vetspire.</p></div>';
		}
		if ( $synced ) {
			// Statuses may have changed under the current filter: read again.
			$data = VSPS_Log::query( $filters, $paged, self::PER_PAGE );
			self::refresh_pending_count();
			// The admin menu was already printed with the old count: patch it in place.
			$pending = VSPS_Log::pending_count();
			echo '<script>document.querySelectorAll("#toplevel_page_vsps-appointments .awaiting-mod").forEach(function(b){'
				. 'var n=' . (int) $pending . ';if(!n){b.remove();return;}b.className="awaiting-mod count-"+n;var c=b.querySelector(".pending-count");if(c){c.textContent=n;}});</script>';
		}

		self::render_filters( $filters );

		if ( empty( $data['rows'] ) ) {
			echo '<p><em>No bookings match. Bookings made through the website widget appear here the moment they are created.</em></p>';
			return;
		}

		echo '<table class="widefat striped vsps-bookings"><thead><tr>'
			. self::sort_header( 'created', 'Created (clinic time)', $filters, 'desc' )
			. ( $show_client ? self::sort_header( 'client', 'Client', $filters, 'asc' ) : '' )
			. self::sort_header( 'pet', 'Pet', $filters, 'asc' )
			. self::sort_header( 'type', 'Type', $filters, 'asc' )
			. self::sort_header( 'appointment', 'Appointment', $filters, 'desc' )
			. self::sort_header( 'provider', 'Provider', $filters, 'asc' )
			. self::sort_header( 'status', 'Status', $filters, 'asc' )
			. self::sort_header( 'after_hours', 'After hours', $filters, 'asc' )
			. self::sort_header( 'edited', 'Edited', $filters, 'desc' )
			. self::sort_header( 'source', 'Source', $filters, 'asc' )
			. '</tr></thead><tbody>';
		foreach ( $data['rows'] as $row ) {
			self::render_booking_row( $row, $ctx, $show_client );
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $data['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			$args         = self::filters_as_query_args( $filters );
			$args['page'] = 'vsps-appointments';
			echo '<div class="tablenav bottom"><div class="tablenav-pages"><span class="displaying-num">' . (int) $data['total'] . ' bookings</span> ';
			echo paginate_links( array(
				'base'      => add_query_arg( array_merge( $args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			) );
			echo '</div></div>';
		} else {
			echo '<p class="description">' . (int) $data['total'] . ' ' . ( 1 === (int) $data['total'] ? 'booking' : 'bookings' ) . '</p>';
		}
		echo '<p class="description" style="margin-top:10px;">Statuses are refreshed from Vetspire when you open this page (at most every 5 minutes per booking). Cancelled or deleted appointments stay listed with their final status. Only bookings made through the website widget are shown.</p>';
	}

	/**
	 * A clickable column header: sorts by $key, keeping every current filter.
	 * Clicking the already-active column flips its direction; clicking a
	 * different one starts at $default_dir (recent-first for date columns,
	 * alphabetical for everything else).
	 */
	private static function sort_header( $key, $label, $filters, $default_dir ) {
		$active  = ( isset( $filters['orderby'] ) ? $filters['orderby'] : 'created' ) === $key;
		$dir     = $active ? ( 'asc' === $filters['order'] ? 'desc' : 'asc' ) : $default_dir;
		$args    = self::filters_as_query_args( $filters );
		$args['orderby'] = $key;
		$args['order']   = $dir;
		unset( $args['paged'] ); // a new sort starts back on page 1
		$url     = add_query_arg( array_merge( $args, array( 'page' => 'vsps-appointments' ) ), admin_url( 'admin.php' ) );
		$arrow   = $active ? ( 'asc' === $filters['order'] ? ' ▲' : ' ▼' ) : '';
		return '<th><a href="' . esc_url( $url ) . '" style="text-decoration:none;color:inherit;"><strong>' . esc_html( $label ) . '</strong>' . esc_html( $arrow ) . '</a></th>';
	}

	private static function render_filters( $filters ) {
		unset( $filters['pii'] ); // internal flag, never part of a URL
		$statuses = array(
			'all'       => 'All bookings',
			'pending'   => 'Pending confirmation',
			'confirmed' => 'Confirmed',
			'completed' => 'Completed',
			'cancelled' => 'Cancelled',
			'no_show'   => 'No Show',
			'deleted'   => 'Deleted in Vetspire',
			'failed'    => 'Failed attempts',
		);

		$here    = add_query_arg( array_merge( self::filters_as_query_args( $filters ), array( 'page' => 'vsps-appointments', 'paged' => max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ) ) ), admin_url( 'admin.php' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$refresh = add_query_arg( 'vsps_sync', wp_create_nonce( 'vsps_sync' ), $here );
		$export  = wp_nonce_url( add_query_arg( array_merge( self::filters_as_query_args( $filters ), array( 'action' => 'vsps_export_bookings' ) ), admin_url( 'admin-post.php' ) ), 'vsps_export_bookings' );
		echo '<div style="margin:0 0 12px;display:flex;gap:8px;">';
		echo '<a class="button" href="' . esc_url( $refresh ) . '" title="Statuses refresh on their own every 5 minutes; this forces it for the bookings on this page.">Sync with PIMS</a>';
		echo '<a class="button" href="' . esc_url( $export ) . '">Export CSV</a>';
		echo '</div>';

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="vsps-filters" style="margin:0 0 12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';
		echo '<input type="hidden" name="page" value="vsps-appointments" />';
		echo '<label>From <input type="date" name="from" value="' . esc_attr( $filters['from'] ) . '" /></label>';
		echo '<label>To <input type="date" name="to" value="' . esc_attr( $filters['to'] ) . '" /></label>';
		echo '<select name="appointment_type_id"><option value="0">All appointment types</option>';
		foreach ( VSPS_Log::distinct_types() as $t ) {
			echo '<option value="' . esc_attr( $t->appointment_type_id ) . '"' . selected( (int) $filters['appointment_type_id'], (int) $t->appointment_type_id, false ) . '>' . esc_html( $t->type_name ) . '</option>';
		}
		echo '</select>';
		echo '<select name="provider"><option value="">All providers</option>';
		foreach ( VSPS_Log::distinct_providers() as $p ) {
			echo '<option value="' . esc_attr( $p ) . '"' . selected( $filters['provider'], $p, false ) . '>' . esc_html( $p ) . '</option>';
		}
		echo '</select>';
		echo '<select name="status">';
		foreach ( $statuses as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $filters['status'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<select name="after_hours">';
		foreach ( array( 'all' => 'Any time of day', 'yes' => 'After hours only', 'no' => 'Office hours only' ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $filters['after_hours'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<input type="hidden" name="vsps_filtered" value="1" />';
		echo '<input type="hidden" name="orderby" value="' . esc_attr( $filters['orderby'] ) . '" />';
		echo '<input type="hidden" name="order" value="' . esc_attr( $filters['order'] ) . '" />';
		echo '<label><input type="checkbox" name="failed" value="1"' . checked( 1, $filters['failed'], false ) . ' /> Include failed attempts</label>';
		echo '<button class="button">Filter</button>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=vsps-appointments' ) ) . '">Reset</a>';
		echo '</form>';
	}

	private static function render_booking_row( $row, $ctx, $show_client ) {
		list( $label, $fg, $bg ) = VSPS_Log::status_label( $row );
		$created = VSPS_Log::utc_to_local( $row->created_at, $ctx['timezone'], 'M j, Y' ) . '<br /><span style="color:#777;">' . VSPS_Log::utc_to_local( $row->created_at, $ctx['timezone'], 'g:i A' ) . '</span>';
		$source  = 'backfill' === $row->layout ? 'Imported from Vetspire' : ( $row->layout ? ucfirst( $row->layout ) . ' layout' : 'Widget' );
		if ( $row->variant ) {
			$source .= ' · variant ' . strtoupper( $row->variant );
		}
		if ( $row->page_url ) {
			$path   = wp_parse_url( $row->page_url, PHP_URL_PATH );
			$source .= '<br /><a href="' . esc_url( $row->page_url ) . '" target="_blank" rel="noopener" style="color:#777;">' . esc_html( $path ? $path : $row->page_url ) . '</a>';
		}
		echo '<tr' . ( 'failed' === $row->outcome ? ' style="opacity:.75;"' : '' ) . '>';
		echo '<td>' . $created . '</td>'; // already escaped by utc_to_local's date formatting
		if ( $show_client ) {
			echo '<td>' . esc_html( $row->client_name ?: '—' ) . ( $row->client_email ? '<br /><span style="color:#777;">' . esc_html( $row->client_email ) . '</span>' : '' )
				. '<br /><span style="color:#999;font-size:11px;">' . ( 'existing' === $row->client_type ? 'returning client' : 'new client' ) . '</span></td>';
		}
		echo '<td>' . esc_html( $row->patient_name ?: '—' ) . ( $row->pet_is_new ? '<br /><span style="color:#999;font-size:11px;">new pet</span>' : '' ) . '</td>';
		echo '<td>' . esc_html( $row->type_name ?: '—' ) . '</td>';
		echo '<td>' . esc_html( VSPS_Log::appt_local( $row, $ctx['timezone'] ) )
			. ( $row->appointment_id ? '<br /><span style="color:#999;font-size:11px;">' . esc_html( $row->appointment_id ) . '</span>' : '' ) . '</td>';
		echo '<td>' . esc_html( $row->provider_name ?: '—' ) . '</td>';
		echo '<td><span style="display:inline-block;background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';border-radius:4px;padding:2px 8px;font-size:11px;font-weight:600;">' . esc_html( $label ) . '</span>';
		if ( 'failed' === $row->outcome && $row->error_message ) {
			echo '<br /><span style="color:#777;font-size:11px;">' . esc_html( $row->error_message ) . '</span>';
		}
		echo '</td>';
		if ( null === $row->after_hours ) {
			echo '<td><span style="color:#999;">—</span></td>';
		} else {
			echo '<td>' . ( $row->after_hours ? 'Yes' : 'No' ) . '</td>';
		}
		if ( $row->edited_at ) {
			echo '<td>Yes<br /><span style="color:#777;font-size:11px;">' . esc_html( VSPS_Log::utc_to_local( $row->edited_at, $ctx['timezone'], 'M j, g:i A' ) ) . '</span></td>';
		} else {
			echo '<td>' . ( 'booked' === $row->outcome ? 'No' : '<span style="color:#999;">—</span>' ) . '</td>';
		}
		echo '<td>' . wp_kses( $source, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array(), 'style' => array() ), 'br' => array() ) ) . '</td>';
		echo '</tr>';
	}

	/* ---------- dashboard widget ---------- */

	public static function add_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'vsps_today', 'Vetspire Scheduler — Website Bookings', array( __CLASS__, 'render_dashboard_widget' ) );
	}

	public static function render_dashboard_widget() {
		$ctx = self::location_context();
		if ( null === $ctx ) {
			echo '<p>Configure Vetspire Scheduler first.</p>';
			return;
		}
		$rows    = VSPS_Log::today();
		$pending = VSPS_Log::pending_count();
		self::refresh_pending_count();
		echo '<p><strong>' . VSPS_Log::count_today() . '</strong> booked through the website today · <strong>' . (int) $pending . '</strong> awaiting confirmation.</p>';
		if ( $rows ) {
			echo '<ul style="margin:0;">';
			foreach ( $rows as $row ) {
				list( $label ) = VSPS_Log::status_label( $row );
				echo '<li>' . esc_html( VSPS_Log::utc_to_local( $row->created_at, $ctx['timezone'], 'g:i A' ) ) . ' — '
					. esc_html( $row->patient_name ?: '—' ) . ' (' . esc_html( $row->type_name ?: '' ) . ') → '
					. esc_html( VSPS_Log::appt_local( $row, $ctx['timezone'] ) )
					. ' <span style="color:#777;">· ' . esc_html( $label ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=vsps-appointments' ) ) . '">Open the bookings log →</a></p>';
	}
}
