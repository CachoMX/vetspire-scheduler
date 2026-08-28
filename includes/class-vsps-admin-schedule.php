<?php
/**
 * Admin schedule: read-only day view of the clinic's appointments, plus
 * limited actions (confirm / reschedule / cancel) on ONLINE bookings only.
 * Vetspire remains the source of truth — full editing happens there.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Admin_Schedule {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_dashboard_widget' ) );
		add_action( 'admin_post_vsps_appt_action', array( __CLASS__, 'handle_action' ) );
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
		// Rename the auto-created first submenu item to "Appointments".
		add_submenu_page(
			'vsps-appointments',
			'Vetspire Scheduler — Appointments',
			'Appointments' . $bubble,
			'manage_options',
			'vsps-appointments',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * WooCommerce-style pending count: today's unconfirmed ONLINE bookings.
	 * READ-ONLY here — never triggers an API call (admin_menu runs on every
	 * wp-admin page). The count is refreshed by the screens that already
	 * fetch today's appointments (Appointments page, dashboard widget).
	 */
	private static function pending_bubble() {
		$count = (int) get_transient( 'vsps_pending_online' );
		if ( ! $count ) {
			return '';
		}
		return ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count">' . $count . '</span></span>';
	}

	/** Stores the bubble count from an already-fetched list of today's appointments. */
	private static function refresh_pending_count( $appts ) {
		$count = count( array_filter( $appts, function ( $a ) {
			return ! empty( $a['bookedOnline'] ) && empty( $a['isConfirmed'] ) && 'CANCELLED' !== $a['status'];
		} ) );
		set_transient( 'vsps_pending_online', $count, 600 );
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

	/* ---------- actions (online bookings only) ---------- */

	public static function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'vsps_appt_action' );

		$api = vsps_api();
		$id  = isset( $_POST['appt_id'] ) ? sanitize_text_field( wp_unslash( $_POST['appt_id'] ) ) : '';
		$do  = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';
		$date = self::current_date_from_post();
		$back = admin_url( 'admin.php?page=vsps-appointments&vsps_date=' . $date );

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
		if ( ! $ok && is_wp_error( $result ) ) {
			error_log( '[vetspire-scheduler] admin action failed: ' . $result->get_error_message() );
		}
		wp_safe_redirect( $back . '&vsps_msg=' . ( $ok ? 'ok' : 'err' ) );
		exit;
	}

	private static function current_date_from_post() {
		$date = isset( $_POST['vsps_date'] ) ? sanitize_text_field( wp_unslash( $_POST['vsps_date'] ) ) : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : current_time( 'Y-m-d' );
	}

	private static function is_online_booking( $ctx, $id, $date ) {
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

	/* ---------- page ---------- */

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ctx  = self::location_context();
		$date = self::current_date();
		echo '<div class="wrap"><h1 class="wp-heading-inline">Appointments</h1>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=vsps-settings' ) ) . '" class="page-title-action">Settings</a>';
		echo '<hr class="wp-header-end" />';

		if ( null === $ctx ) {
			echo '<div class="card" style="max-width:520px;padding:20px 24px;"><h2 style="margin-top:0;">Almost ready</h2>'
				. '<p>Connect your Vetspire account and pick a clinic location to see the schedule here.</p>'
				. '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=vsps-settings' ) ) . '">Open Settings</a></p></div></div>';
			return;
		}

		$msg = isset( $_GET['vsps_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['vsps_msg'] ) ) : '';
		if ( 'ok' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>Done — the appointment was updated in Vetspire.</p></div>';
		} elseif ( 'err' === $msg ) {
			echo '<div class="notice notice-error is-dismissible"><p>The action could not be completed (the time may no longer be available).</p></div>';
		} elseif ( 'notonline' === $msg ) {
			echo '<div class="notice notice-warning is-dismissible"><p>Only appointments booked online through the website widget can be managed here. Use Vetspire for everything else.</p></div>';
		}

		$prev = ( new DateTimeImmutable( $date ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$next = ( new DateTimeImmutable( $date ) )->modify( '+1 day' )->format( 'Y-m-d' );
		$base = admin_url( 'admin.php?page=vsps-appointments&vsps_date=' );

		echo '<p><strong>' . esc_html( $ctx['name'] ) . '</strong> — ' . esc_html( ( new DateTimeImmutable( $date ) )->format( 'l, M j, Y' ) ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( $base . $prev ) . '">‹ Prev</a> ';
		echo '<a class="button" href="' . esc_url( $base . current_time( 'Y-m-d' ) ) . '">Today</a> ';
		echo '<a class="button" href="' . esc_url( $base . $next ) . '">Next ›</a> ';
		echo '<input type="date" id="vsps-goto" value="' . esc_attr( $date ) . '" style="margin-left:8px;" /></p>';
		echo '<script>document.getElementById("vsps-goto").addEventListener("change",function(){window.location="' . esc_js( $base ) . '"+this.value;});</script>';

		$appts = self::day_appointments( $ctx, $date );
		if ( is_wp_error( $appts ) ) {
			echo '<p>Could not load appointments from Vetspire. Try again in a minute.</p></div>';
			return;
		}
		if ( $date === current_time( 'Y-m-d' ) ) {
			self::refresh_pending_count( $appts );
		}
		if ( empty( $appts ) ) {
			echo '<p><em>No appointments this day.</em></p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>Time</th><th>Client</th><th>Pet</th><th>Type</th><th>Provider</th><th>Status</th><th>Source</th><th>Actions</th>'
			. '</tr></thead><tbody>';

		foreach ( $appts as $appt ) {
			if ( 'CANCELLED' === $appt['status'] ) {
				continue;
			}
			$client = isset( $appt['patient']['client'] ) ? $appt['patient']['client'] : null;
			$client_name  = $client ? trim( $client['givenName'] . ' ' . $client['familyName'] ) : '—';
			$client_phone = $client && ! empty( $client['phoneNumbers'][0]['value'] ) ? $client['phoneNumbers'][0]['value'] : '';
			$online       = ! empty( $appt['bookedOnline'] );

			echo '<tr>';
			echo '<td><strong>' . esc_html( self::local_time( $appt['start'], $ctx['timezone'] ) ) . '</strong><br /><span style="color:#777;">' . esc_html( $appt['duration'] ) . ' min</span></td>';
			echo '<td>' . esc_html( $client_name ) . ( $client_phone ? '<br /><span style="color:#777;">' . esc_html( $client_phone ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $appt['patient']['name'] ) ? $appt['patient']['name'] : '—' ) . '</td>';
			echo '<td>' . esc_html( isset( $appt['type']['name'] ) ? $appt['type']['name'] : '—' ) . '</td>';
			echo '<td>' . esc_html( isset( $appt['provider']['name'] ) ? $appt['provider']['name'] : '—' ) . '</td>';
			echo '<td>' . esc_html( $appt['status'] ) . ( $appt['isConfirmed'] ? ' ✅' : '' ) . '</td>';
			$badge = strtoupper( vsps_get_settings()['source_label'] ?: 'Online' );
			echo '<td>' . ( $online ? '<span style="background:#2f6f4f;color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;">' . esc_html( $badge ) . '</span>' : '<span style="color:#999;">Vetspire</span>' ) . '</td>';
			echo '<td>';
			if ( $online ) {
				self::action_buttons( $appt, $date );
			} else {
				echo '<span style="color:#999;">—</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description" style="margin-top:10px;">Only <strong>ONLINE</strong> bookings (made through the website widget) can be confirmed, rescheduled or cancelled here. Everything else is managed in Vetspire.</p>';
		self::reschedule_script();
		echo '</div>';
	}

	private static function action_buttons( $appt, $date ) {
		$post_url = admin_url( 'admin-post.php' );
		$type_id  = isset( $appt['type']['id'] ) ? absint( $appt['type']['id'] ) : 0;
		?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="display:inline;">
			<?php wp_nonce_field( 'vsps_appt_action' ); ?>
			<input type="hidden" name="action" value="vsps_appt_action" />
			<input type="hidden" name="appt_id" value="<?php echo esc_attr( $appt['id'] ); ?>" />
			<input type="hidden" name="vsps_date" value="<?php echo esc_attr( $date ); ?>" />
			<?php if ( ! $appt['isConfirmed'] ) : ?>
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
		wp_add_dashboard_widget( 'vsps_today', 'Vetspire Scheduler — Today\'s Bookings', array( __CLASS__, 'render_dashboard_widget' ) );
	}

	public static function render_dashboard_widget() {
		$ctx = self::location_context();
		if ( null === $ctx ) {
			echo '<p>Configure Vetspire Scheduler first.</p>';
			return;
		}
		$today = current_time( 'Y-m-d' );
		$appts = self::day_appointments( $ctx, $today );
		if ( is_wp_error( $appts ) ) {
			echo '<p>Could not load appointments.</p>';
			return;
		}
		self::refresh_pending_count( $appts );
		$active = array_values( array_filter( $appts, function ( $a ) {
			return 'CANCELLED' !== $a['status'];
		} ) );
		echo '<p><strong>' . count( $active ) . '</strong> appointments today at ' . esc_html( $ctx['name'] ) . '.</p>';
		if ( $active ) {
			echo '<ul style="margin:0;">';
			foreach ( array_slice( $active, 0, 5 ) as $appt ) {
				echo '<li>' . esc_html( self::local_time( $appt['start'], $ctx['timezone'] ) ) . ' — '
					. esc_html( isset( $appt['patient']['name'] ) ? $appt['patient']['name'] : '' ) . ' ('
					. esc_html( isset( $appt['type']['name'] ) ? $appt['type']['name'] : '' ) . ')'
					. ( ! empty( $appt['bookedOnline'] ) ? ' <strong style="color:#2f6f4f;">· online</strong>' : '' )
					. '</li>';
			}
			echo '</ul>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=vsps-appointments' ) ) . '">Open the schedule →</a></p>';
	}
}
