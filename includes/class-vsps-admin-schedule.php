<?php
/**
 * Admin screens:
 *  - Bookings: the widget's own log (what the website booked, when, and what
 *    happened to it since — statuses refreshed from Vetspire, rows never vanish).
 *  - Clinic day view (secondary tab): the whole appointment book for one day,
 *    with confirm / reschedule / cancel on ONLINE bookings only.
 * Vetspire remains the source of truth — full editing happens there.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Admin_Schedule {

	const PER_PAGE = 25;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_dashboard_widget' ) );
		add_action( 'admin_post_vsps_appt_action', array( __CLASS__, 'handle_action' ) );
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

	/** Appointments for one clinic-local day, sorted by start. */
	private static function day_appointments( $ctx, $date ) {
		$start = new DateTimeImmutable( $date . ' 00:00', $ctx['timezone'] );
		$end   = $start->modify( '+1 day' );
		$appts = $ctx['api']->get_appointments( $ctx['id'], $start->format( 'c' ), $end->format( 'c' ) );
		if ( is_wp_error( $appts ) ) {
			return $appts;
		}
		usort( $appts, function ( $a, $b ) {
			return strcmp( $a['start'], $b['start'] );
		} );
		return $appts;
	}

	private static function local_time( $iso, DateTimeZone $tz ) {
		try {
			$dt = new DateTimeImmutable( $iso );
			return $dt->setTimezone( $tz )->format( 'g:i A' );
		} catch ( Exception $e ) {
			return $iso;
		}
	}

	private static function current_date() {
		$date = isset( $_GET['vsps_date'] ) ? sanitize_text_field( wp_unslash( $_GET['vsps_date'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = current_time( 'Y-m-d' );
		}
		return $date;
	}

	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'bookings';
		return 'day' === $tab ? 'day' : 'bookings';
	}

	/* ---------- actions (online bookings only) ---------- */

	public static function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'vsps_appt_action' );

		$return = isset( $_POST['vsps_return'] ) && 'day' === $_POST['vsps_return'] ? 'day' : 'bookings';
		$date   = self::current_date_from_post();
		$back   = 'day' === $return
			? admin_url( 'admin.php?page=vsps-appointments&tab=day&vsps_date=' . $date )
			: admin_url( 'admin.php?page=vsps-appointments' );

		// Server-side kill switch (SOW 0.7): when actions are disabled in
		// Settings, no write ever reaches Vetspire — not just hidden buttons.
		if ( empty( vsps_get_settings()['admin_actions_enabled'] ) ) {
			wp_safe_redirect( $back . '&vsps_msg=disabled' );
			exit;
		}

		$api = vsps_api();
		$id  = isset( $_POST['appt_id'] ) ? sanitize_text_field( wp_unslash( $_POST['appt_id'] ) ) : '';
		$do  = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';

		if ( null === $api || '' === $id || ! preg_match( '/^\d+$/', $id ) ) {
			wp_safe_redirect( $back . '&vsps_msg=err' );
			exit;
		}

		// Guard: only appointments booked online through this widget are actionable.
		$ctx = self::location_context();
		if ( null === $ctx || ! self::is_online_booking( $ctx, $id, $date ) ) {
			wp_safe_redirect( $back . '&vsps_msg=notonline' );
			exit;
		}

		$result = null;
		if ( 'confirm' === $do ) {
			$result = $api->update_appointment( $id, array( 'isConfirmed' => true ) );
		} elseif ( 'cancel' === $do ) {
			$result = $api->update_appointment( $id, array( 'status' => 'CANCELLED' ) );
		} elseif ( 'reschedule' === $do ) {
			$result = self::do_reschedule( $ctx, $id );
		}

		$ok = null !== $result && ! is_wp_error( $result );
		if ( $ok ) {
			VSPS_Log::note_admin_action( $id, $do, $result );
			self::refresh_pending_count();
		} elseif ( is_wp_error( $result ) ) {
			error_log( '[vetspire-scheduler] admin action failed: ' . $result->get_error_message() );
		}
		wp_safe_redirect( $back . '&vsps_msg=' . ( $ok ? 'ok' : 'err' ) );
		exit;
	}

	private static function current_date_from_post() {
		$date = isset( $_POST['vsps_date'] ) ? sanitize_text_field( wp_unslash( $_POST['vsps_date'] ) ) : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : current_time( 'Y-m-d' );
	}

	/**
	 * The widget's own log is proof enough that WE booked it; otherwise fall
	 * back to Vetspire's bookedOnline flag for that day.
	 */
	private static function is_online_booking( $ctx, $id, $date ) {
		$row = VSPS_Log::find_by_appointment( $id );
		if ( $row && 'booked' === $row->outcome ) {
			return true;
		}
		$appts = self::day_appointments( $ctx, $date );
		if ( is_wp_error( $appts ) ) {
			return false;
		}
		foreach ( $appts as $appt ) {
			if ( (string) $appt['id'] === (string) $id ) {
				return ! empty( $appt['bookedOnline'] );
			}
		}
		return false;
	}

	private static function do_reschedule( $ctx, $id ) {
		$new_date = isset( $_POST['new_date'] ) ? sanitize_text_field( wp_unslash( $_POST['new_date'] ) ) : '';
		$new_time = isset( $_POST['new_time'] ) ? sanitize_text_field( wp_unslash( $_POST['new_time'] ) ) : '';
		$type_id  = isset( $_POST['type_id'] ) ? absint( $_POST['type_id'] ) : 0;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_date ) || ! preg_match( '/^\d{2}:\d{2}$/', $new_time ) || ! $type_id ) {
			return new WP_Error( 'vsps_invalid', 'Invalid reschedule request.' );
		}
		// Re-validate against live availability; take provider/schedule from the slot.
		$slots = $ctx['api']->get_available_times( $ctx['id'], $type_id, $new_date );
		if ( is_wp_error( $slots ) ) {
			return $slots;
		}
		$match = null;
		foreach ( $slots as $slot ) {
			if ( isset( $slot['time'] ) && $slot['time'] === $new_time ) {
				$match = $slot;
				break;
			}
		}
		if ( null === $match ) {
			return new WP_Error( 'vsps_slot', 'That time is no longer available.' );
		}
		$start = new DateTimeImmutable( $new_date . ' ' . $new_time, $ctx['timezone'] );
		$input = array( 'start' => $start->format( 'c' ) );
		if ( ! empty( $match['providerId'] ) ) {
			$input['providerId'] = (string) $match['providerId'];
		}
		if ( ! empty( $match['scheduleId'] ) ) {
			$input['scheduleId'] = (string) $match['scheduleId'];
		}
		return $ctx['api']->update_appointment( $id, $input );
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
		if ( ! in_array( $status, array( 'all', 'pending', 'confirmed', 'cancelled', 'deleted', 'completed', 'failed' ), true ) ) {
			$status = 'all';
		}
		$date = function ( $k ) use ( $src ) {
			$v = isset( $src[ $k ] ) ? sanitize_text_field( wp_unslash( $src[ $k ] ) ) : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		return array(
			'status' => $status,
			'from'   => $date( 'from' ),
			'to'     => $date( 'to' ),
			's'      => isset( $src['s'] ) ? substr( sanitize_text_field( wp_unslash( $src['s'] ) ), 0, 100 ) : '',
			'failed' => ! empty( $src['failed'] ) ? 1 : 0,
		);
	}

	/* ---------- page ---------- */

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ctx = self::location_context();
		$tab = self::current_tab();
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . ( 'day' === $tab ? 'Clinic day view' : 'Bookings' ) . '</h1>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=vsps-settings' ) ) . '" class="page-title-action">Settings</a>';
		echo '<hr class="wp-header-end" />';

		if ( null === $ctx ) {
			echo '<div class="card" style="max-width:520px;padding:20px 24px;"><h2 style="margin-top:0;">Almost ready</h2>'
				. '<p>Connect your Vetspire account and pick a clinic location to see bookings here.</p>'
				. '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=vsps-settings' ) ) . '">Open Settings</a></p></div></div>';
			return;
		}

		self::render_notices();

		$base = admin_url( 'admin.php?page=vsps-appointments' );
		echo '<h2 class="nav-tab-wrapper" style="margin-bottom:14px;">'
			. '<a class="nav-tab' . ( 'bookings' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base ) . '">Bookings</a>'
			. '<a class="nav-tab' . ( 'day' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&tab=day' ) . '">Clinic day view</a>'
			. '</h2>';

		if ( 'day' === $tab ) {
			self::render_day_view( $ctx );
		} else {
			self::render_bookings( $ctx );
		}
		echo '</div>';
	}

	private static function render_notices() {
		$msg = isset( $_GET['vsps_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['vsps_msg'] ) ) : '';
		if ( 'ok' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>Done — the appointment was updated in Vetspire.</p></div>';
		} elseif ( 'err' === $msg ) {
			echo '<div class="notice notice-error is-dismissible"><p>The action could not be completed (the time may no longer be available).</p></div>';
		} elseif ( 'disabled' === $msg ) {
			echo '<div class="notice notice-warning is-dismissible"><p>Appointment actions are disabled in Settings. Manage appointments in Vetspire.</p></div>';
		} elseif ( 'notonline' === $msg ) {
			echo '<div class="notice notice-warning is-dismissible"><p>Only appointments booked online through the website widget can be managed here. Use Vetspire for everything else.</p></div>';
		}
	}

	/* ---------- Bookings tab (the widget's log) ---------- */

	private static function render_bookings( $ctx ) {
		$settings        = vsps_get_settings();
		$actions_enabled = ! empty( $settings['admin_actions_enabled'] );
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
			. '<th>Created</th>' . ( $show_client ? '<th>Client</th>' : '' ) . '<th>Pet</th><th>Type</th><th>Appointment</th><th>Status</th><th>Source</th>'
			. ( $actions_enabled ? '<th>Actions</th>' : '' )
			. '</tr></thead><tbody>';
		foreach ( $data['rows'] as $row ) {
			self::render_booking_row( $row, $ctx, $show_client, $actions_enabled );
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $data['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			$args = array_filter( $filters );
			unset( $args['pii'] );
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
		echo '<p class="description" style="margin-top:10px;">Statuses are refreshed from Vetspire when you open this page (at most every 5 minutes per booking). Cancelled or deleted appointments stay listed with their final status. Only bookings made through the website widget are shown; the full appointment book is under <a href="' . esc_url( admin_url( 'admin.php?page=vsps-appointments&tab=day' ) ) . '">Clinic day view</a>.</p>';
		self::reschedule_script();
	}

	private static function render_filters( $filters ) {
		unset( $filters['pii'] ); // internal flag, never part of a URL
		$statuses = array(
			'all'       => 'All bookings',
			'pending'   => 'Pending confirmation',
			'confirmed' => 'Confirmed',
			'completed' => 'Completed',
			'cancelled' => 'Cancelled',
			'deleted'   => 'Deleted in Vetspire',
			'failed'    => 'Failed attempts',
		);
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="vsps-filters" style="margin:0 0 12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';
		echo '<input type="hidden" name="page" value="vsps-appointments" />';
		echo '<select name="status">';
		foreach ( $statuses as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $filters['status'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<label>From <input type="date" name="from" value="' . esc_attr( $filters['from'] ) . '" /></label>';
		echo '<label>To <input type="date" name="to" value="' . esc_attr( $filters['to'] ) . '" /></label>';
		echo '<input type="search" name="s" placeholder="Name, email, pet or appointment id" value="' . esc_attr( $filters['s'] ) . '" style="min-width:240px;" />';
		echo '<label><input type="checkbox" name="failed" value="1"' . checked( 1, $filters['failed'], false ) . ' /> Include failed attempts</label>';
		echo '<button class="button">Filter</button>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=vsps-appointments' ) ) . '">Reset</a>';
		$here    = add_query_arg( array_merge( array_filter( $filters ), array( 'page' => 'vsps-appointments', 'paged' => max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ) ) ), admin_url( 'admin.php' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$refresh = add_query_arg( 'vsps_sync', wp_create_nonce( 'vsps_sync' ), $here );
		$export  = wp_nonce_url( add_query_arg( array_merge( array_filter( $filters ), array( 'action' => 'vsps_export_bookings' ) ), admin_url( 'admin-post.php' ) ), 'vsps_export_bookings' );
		echo '<a class="button" href="' . esc_url( $refresh ) . '" style="margin-left:auto;" title="Statuses refresh on their own every 5 minutes; this forces it for the bookings on this page.">Refresh from Vetspire</a>';
		echo '<a class="button" href="' . esc_url( $export ) . '">Export CSV</a>';
		echo '</form>';
	}

	private static function render_booking_row( $row, $ctx, $show_client, $actions_enabled ) {
		list( $label, $fg, $bg ) = VSPS_Log::status_label( $row );
		$created = get_date_from_gmt( $row->created_at, 'M j, Y' ) . '<br /><span style="color:#777;">' . get_date_from_gmt( $row->created_at, 'g:i A' ) . '</span>';
		$source  = 'backfill' === $row->layout ? 'Imported from Vetspire' : ( $row->layout ? ucfirst( $row->layout ) . ' layout' : 'Widget' );
		if ( $row->variant ) {
			$source .= ' · variant ' . strtoupper( $row->variant );
		}
		if ( $row->page_url ) {
			$path   = wp_parse_url( $row->page_url, PHP_URL_PATH );
			$source .= '<br /><a href="' . esc_url( $row->page_url ) . '" target="_blank" rel="noopener" style="color:#777;">' . esc_html( $path ? $path : $row->page_url ) . '</a>';
		}
		$actionable = $actions_enabled && 'booked' === $row->outcome && ! $row->is_deleted && ! in_array( $row->status, VSPS_Log::TERMINAL, true );

		echo '<tr' . ( 'failed' === $row->outcome ? ' style="opacity:.75;"' : '' ) . '>';
		echo '<td>' . $created . '</td>'; // already escaped by get_date_from_gmt formatting
		if ( $show_client ) {
			echo '<td>' . esc_html( $row->client_name ?: '—' ) . ( $row->client_email ? '<br /><span style="color:#777;">' . esc_html( $row->client_email ) . '</span>' : '' )
				. '<br /><span style="color:#999;font-size:11px;">' . ( 'existing' === $row->client_type ? 'returning client' : 'new client' ) . '</span></td>';
		}
		echo '<td>' . esc_html( $row->patient_name ?: '—' ) . ( $row->pet_is_new ? '<br /><span style="color:#999;font-size:11px;">new pet</span>' : '' ) . '</td>';
		echo '<td>' . esc_html( $row->type_name ?: '—' ) . '</td>';
		echo '<td>' . esc_html( VSPS_Log::appt_local( $row, $ctx['timezone'] ) )
			. ( $row->provider_name ? '<br /><span style="color:#777;">' . esc_html( $row->provider_name ) . '</span>' : '' )
			. ( $row->appointment_id ? '<br /><span style="color:#999;font-size:11px;">#' . esc_html( $row->appointment_id ) . '</span>' : '' ) . '</td>';
		echo '<td><span style="display:inline-block;background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';border-radius:4px;padding:2px 8px;font-size:11px;font-weight:600;">' . esc_html( $label ) . '</span>';
		if ( 'failed' === $row->outcome && $row->error_message ) {
			echo '<br /><span style="color:#777;font-size:11px;">' . esc_html( $row->error_message ) . '</span>';
		}
		if ( null !== $row->after_hours && 'booked' === $row->outcome ) {
			echo '<br /><span style="color:#999;font-size:11px;">' . ( $row->after_hours ? 'booked after hours' : 'booked during office hours' ) . '</span>';
		}
		echo '</td>';
		echo '<td>' . wp_kses( $source, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array(), 'style' => array() ), 'br' => array() ) ) . '</td>';
		if ( $actions_enabled ) {
			echo '<td>';
			if ( $actionable ) {
				self::action_buttons( array(
					'id'          => $row->appointment_id,
					'isConfirmed' => (bool) $row->is_confirmed,
					'type'        => array( 'id' => (int) $row->appointment_type_id ),
				), $row->slot_date ?: current_time( 'Y-m-d' ), 'bookings' );
			} else {
				echo '<span style="color:#999;">—</span>';
			}
			echo '</td>';
		}
		echo '</tr>';
	}

	/* ---------- Clinic day view tab (whole book, one day) ---------- */

	private static function render_day_view( $ctx ) {
		$date = self::current_date();
		$prev = ( new DateTimeImmutable( $date ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$next = ( new DateTimeImmutable( $date ) )->modify( '+1 day' )->format( 'Y-m-d' );
		$base = admin_url( 'admin.php?page=vsps-appointments&tab=day&vsps_date=' );

		echo '<p><strong>' . esc_html( $ctx['name'] ) . '</strong> — ' . esc_html( ( new DateTimeImmutable( $date ) )->format( 'l, M j, Y' ) ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( $base . $prev ) . '">‹ Prev</a> ';
		echo '<a class="button" href="' . esc_url( $base . current_time( 'Y-m-d' ) ) . '">Today</a> ';
		echo '<a class="button" href="' . esc_url( $base . $next ) . '">Next ›</a> ';
		echo '<input type="date" id="vsps-goto" value="' . esc_attr( $date ) . '" style="margin-left:8px;" /></p>';
		echo '<script>document.getElementById("vsps-goto").addEventListener("change",function(){window.location="' . esc_js( $base ) . '"+this.value;});</script>';

		$appts = self::day_appointments( $ctx, $date );
		if ( is_wp_error( $appts ) ) {
			echo '<p>Could not load appointments from Vetspire. Try again in a minute.</p>';
			return;
		}
		if ( empty( $appts ) ) {
			echo '<p><em>No appointments this day.</em></p>';
			return;
		}

		$settings_admin  = vsps_get_settings();
		$actions_enabled = ! empty( $settings_admin['admin_actions_enabled'] );
		$show_client     = ! empty( $settings_admin['admin_show_client'] );
		$badge           = strtoupper( $settings_admin['source_label'] ?: 'Online' );

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>Time</th>' . ( $show_client ? '<th>Client</th>' : '' ) . '<th>Pet</th><th>Type</th><th>Provider</th><th>Status</th><th>Source</th>'
			. ( $actions_enabled ? '<th>Actions</th>' : '' )
			. '</tr></thead><tbody>';

		foreach ( $appts as $appt ) {
			if ( 'CANCELLED' === $appt['status'] ) {
				continue;
			}
			$client       = isset( $appt['patient']['client'] ) ? $appt['patient']['client'] : null;
			$client_name  = $client ? trim( $client['givenName'] . ' ' . $client['familyName'] ) : '—';
			$client_phone = $client && ! empty( $client['phoneNumbers'][0]['value'] ) ? $client['phoneNumbers'][0]['value'] : '';
			$online       = ! empty( $appt['bookedOnline'] );

			echo '<tr>';
			echo '<td><strong>' . esc_html( self::local_time( $appt['start'], $ctx['timezone'] ) ) . '</strong><br /><span style="color:#777;">' . esc_html( $appt['duration'] ) . ' min</span></td>';
			if ( $show_client ) {
				echo '<td>' . esc_html( $client_name ) . ( $client_phone ? '<br /><span style="color:#777;">' . esc_html( $client_phone ) . '</span>' : '' ) . '</td>';
			}
			echo '<td>' . esc_html( isset( $appt['patient']['name'] ) ? $appt['patient']['name'] : '—' ) . '</td>';
			echo '<td>' . esc_html( isset( $appt['type']['name'] ) ? $appt['type']['name'] : '—' ) . '</td>';
			echo '<td>' . esc_html( isset( $appt['provider']['name'] ) ? $appt['provider']['name'] : '—' ) . '</td>';
			echo '<td>' . esc_html( $appt['status'] ) . ( $appt['isConfirmed'] ? ' ✅' : '' ) . '</td>';
			echo '<td>' . ( $online ? '<span style="background:#2f6f4f;color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;">' . esc_html( $badge ) . '</span>' : '<span style="color:#999;">Vetspire</span>' ) . '</td>';
			if ( $actions_enabled ) {
				echo '<td>';
				if ( $online ) {
					self::action_buttons( $appt, $date, 'day' );
				} else {
					echo '<span style="color:#999;">—</span>';
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description" style="margin-top:10px;">The whole appointment book for the day. Only <strong>ONLINE</strong> bookings (made through the website widget) can be confirmed, rescheduled or cancelled here. Everything else is managed in Vetspire.</p>';
		self::reschedule_script();
	}

	private static function action_buttons( $appt, $date, $return ) {
		$post_url = admin_url( 'admin-post.php' );
		$type_id  = isset( $appt['type']['id'] ) ? absint( $appt['type']['id'] ) : 0;
		?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline;">
			<?php wp_nonce_field( 'vsps_appt_action' ); ?>
			<input type="hidden" name="action" value="vsps_appt_action" />
			<input type="hidden" name="appt_id" value="<?php echo esc_attr( $appt['id'] ); ?>" />
			<input type="hidden" name="vsps_date" value="<?php echo esc_attr( $date ); ?>" />
			<input type="hidden" name="vsps_return" value="<?php echo esc_attr( $return ); ?>" />
			<?php if ( empty( $appt['isConfirmed'] ) ) : ?>
				<button class="button button-small" name="do" value="confirm">Confirm</button>
			<?php endif; ?>
			<button class="button button-small vsps-resched-toggle" type="button" data-appt="<?php echo esc_attr( $appt['id'] ); ?>">Reschedule</button>
			<button class="button button-small" name="do" value="cancel"
				onclick="return confirm('Cancel this appointment in Vetspire?');">Cancel</button>
			<span class="vsps-resched" id="vsps-resched-<?php echo esc_attr( $appt['id'] ); ?>" style="display:none;margin-top:6px;">
				<input type="date" name="new_date" value="<?php echo esc_attr( $date ); ?>" />
				<select name="new_time"><option value="">— load times —</option></select>
				<input type="hidden" name="type_id" value="<?php echo esc_attr( $type_id ); ?>" />
				<button class="button button-small vsps-load-times" type="button" data-type="<?php echo esc_attr( $type_id ); ?>">Load</button>
				<button class="button button-primary button-small" name="do" value="reschedule">Save</button>
			</span>
		</form>
		<?php
	}

	private static function reschedule_script() {
		$rest = esc_url_raw( rest_url( 'vetspire/v1' ) );
		$loc  = absint( vsps_get_settings()['default_location'] );
		?>
		<script>
		(function () {
			document.querySelectorAll('.vsps-resched-toggle').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var box = document.getElementById('vsps-resched-' + btn.getAttribute('data-appt'));
					if (box) { box.style.display = box.style.display === 'none' ? 'inline-block' : 'none'; }
				});
			});
			document.querySelectorAll('.vsps-load-times').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var box = btn.closest('.vsps-resched');
					var dateInput = box.querySelector('[name="new_date"]');
					var select = box.querySelector('[name="new_time"]');
					select.innerHTML = '<option value="">Loading…</option>';
					fetch('<?php echo esc_js( $rest ); ?>/availability?location_id=<?php echo esc_js( $loc ); ?>&appointment_type_id=' +
						btn.getAttribute('data-type') + '&start_date=' + dateInput.value + '&days=1')
						.then(function (r) { return r.json(); })
						.then(function (data) {
							var slots = (data.days && data.days[0] && data.days[0].slots) || [];
							select.innerHTML = slots.length ? '' : '<option value="">No times available</option>';
							slots.forEach(function (s) {
								var o = document.createElement('option');
								o.value = s.time;
								o.textContent = s.time + (s.provider && s.provider.name ? ' — ' + s.provider.name : '');
								select.appendChild(o);
							});
						})
						.catch(function () { select.innerHTML = '<option value="">Error loading times</option>'; });
				});
			});
		})();
		</script>
		<?php
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
				echo '<li>' . esc_html( get_date_from_gmt( $row->created_at, 'g:i A' ) ) . ' — '
					. esc_html( $row->patient_name ?: '—' ) . ' (' . esc_html( $row->type_name ?: '' ) . ') → '
					. esc_html( VSPS_Log::appt_local( $row, $ctx['timezone'] ) )
					. ' <span style="color:#777;">· ' . esc_html( $label ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=vsps-appointments' ) ) . '">Open the bookings log →</a></p>';
	}
}
