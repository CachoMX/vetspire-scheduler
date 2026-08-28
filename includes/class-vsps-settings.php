<?php
/**
 * Settings screen (Vetspire Scheduler → Settings).
 * First run: a single centered "Connect" card asking for the API key.
 * Connected: two-column layout — controls left, sticky live preview right
 * (Customizer-style split; preview reacts instantly to every control).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Settings {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/** Submenu under the top-level menu registered by VSPS_Admin_Schedule. */
	public static function add_menu() {
		add_submenu_page(
			'vsps-appointments',
			'Vetspire Scheduler — Settings',
			'Settings',
			'manage_options',
			'vsps-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register() {
		register_setting( 'vsps_settings_group', VSPS_OPTION_KEY, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
		) );
	}

	public static function sanitize( $input ) {
		$current = get_option( VSPS_OPTION_KEY, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		if ( ! is_array( $input ) ) {
			return $current;
		}

		// Connect-card saves post ONLY the token (+ endpoint). Merge them into the
		// stored settings instead of rebuilding everything — otherwise re-entering
		// the key on a live site would silently wipe layout/analytics/toggles.
		if ( ! empty( $input['connect_only'] ) ) {
			$merged                 = array_merge( vsps_get_settings(), $current );
			$merged['api_endpoint'] = esc_url_raw( isset( $input['api_endpoint'] ) ? $input['api_endpoint'] : 'https://api.vetspire.com/graphql' );
			$token                  = isset( $input['api_token'] ) ? trim( $input['api_token'] ) : '';
			if ( '' !== $token && false === strpos( $token, '•' ) ) {
				$merged['api_token'] = sanitize_text_field( $token );
			}
			VSPS_Cache::flush();
			return $merged;
		}

		$default_location = absint( isset( $input['default_location'] ) ? $input['default_location'] : 0 );
		$default_location = $default_location ? (string) $default_location : '';

		$clean = array(
			'api_endpoint'      => esc_url_raw( isset( $input['api_endpoint'] ) ? $input['api_endpoint'] : 'https://api.vetspire.com/graphql' ),
			'cache_ttl'         => isset( $current['cache_ttl'] ) ? (int) $current['cache_ttl'] : 300,
			'analytics_enabled' => empty( $input['analytics_enabled'] ) ? 0 : 1,
			'extended_pet_fields' => empty( $input['extended_pet_fields'] ) ? 0 : 1,
			'admin_actions_enabled' => empty( $input['admin_actions_enabled'] ) ? 0 : 1,
			'admin_show_client'     => empty( $input['admin_show_client'] ) ? 0 : 1,
			'source_label'      => '' !== trim( isset( $input['source_label'] ) ? $input['source_label'] : '' )
				? substr( sanitize_text_field( $input['source_label'] ), 0, 40 ) : 'Online',
			'primary_color'     => sanitize_hex_color( isset( $input['primary_color'] ) ? $input['primary_color'] : '#2f6f4f' ),
			'layout'            => self::valid_layout( isset( $input['layout'] ) ? $input['layout'] : 'full' ),
			'default_type'      => absint( isset( $input['default_type'] ) ? $input['default_type'] : 0 ) ? (string) absint( $input['default_type'] ) : '',
			'default_location'  => $default_location,
			// One site = one location: the REST layer only allows the selected one.
			'allowed_locations' => $default_location,
		);

		// Keep the stored token unless a new (non-placeholder) one was entered.
		$token = isset( $input['api_token'] ) ? trim( $input['api_token'] ) : '';
		if ( '' !== $token && false === strpos( $token, '•' ) ) {
			$clean['api_token'] = sanitize_text_field( $token );
		} else {
			$clean['api_token'] = isset( $current['api_token'] ) ? $current['api_token'] : '';
		}
		VSPS_Cache::flush();
		return $clean;
	}

	private static function valid_layout( $layout ) {
		return in_array( $layout, array( 'full', 'bar', 'calendar', 'float' ), true ) ? $layout : 'full';
	}

	/** Locations from the API for the picker (cached 10 min). Null on failure. */
	public static function fetch_locations() {
		$api = vsps_api();
		if ( null === $api ) {
			return null;
		}
		$data = VSPS_Cache::remember( array( 'admin-locations' ), function () use ( $api ) {
			return $api->get_org_and_locations();
		}, 600 );
		if ( is_wp_error( $data ) ) {
			return null;
		}
		// A valid key with 0 locations is CONNECTED — don't confuse it with a bad key.
		$locations = isset( $data['locations'] ) && is_array( $data['locations'] ) ? $data['locations'] : array();
		usort( $locations, function ( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );
		return array(
			'org'       => isset( $data['org']['name'] ) ? $data['org']['name'] : '',
			'locations' => $locations,
		);
	}

	/* ---------------------------------------------------------------- */

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings  = vsps_get_settings();
		$has_token = '' !== $settings['api_token'];
		$org       = $has_token ? self::fetch_locations() : null;

		if ( null === $org ) {
			self::render_connect_card( $has_token );
			return;
		}
		self::render_settings( $settings, $org );
	}

	/** First-run: a single centered card asking only for the API key. */
	private static function render_connect_card( $has_token ) {
		?>
		<div class="wrap">
			<h1>Vetspire Scheduler</h1>
			<div class="card" style="max-width:520px;margin-top:24px;padding:24px 28px;">
				<h2 style="margin-top:0;"><span class="dashicons dashicons-calendar-alt" style="color:#2271b1;"></span> Connect to Vetspire</h2>
				<p>Paste your practice's API key to start showing live appointment times on your website. Everything else is configured after connecting.</p>
				<?php if ( $has_token ) : ?>
					<div class="notice notice-error inline"><p>That key wasn't accepted by Vetspire. Double-check it and try again.</p></div>
				<?php endif; ?>
				<form method="post" action="options.php">
					<?php settings_fields( 'vsps_settings_group' ); ?>
					<input type="hidden" name="<?php echo esc_attr( VSPS_OPTION_KEY ); ?>[api_endpoint]" value="https://api.vetspire.com/graphql" />
					<input type="hidden" name="<?php echo esc_attr( VSPS_OPTION_KEY ); ?>[connect_only]" value="1" />
					<p>
						<input type="password" name="<?php echo esc_attr( VSPS_OPTION_KEY ); ?>[api_token]"
							class="regular-text" style="width:100%;" placeholder="Vetspire API Key" autocomplete="off" required />
					</p>
					<p class="description">Found in Vetspire → Admin → API Keys. Treat it like an admin password.</p>
					<p style="margin-bottom:0;">
						<button type="submit" class="button button-primary button-hero" style="width:100%;">Connect to Vetspire</button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/** Connected: two-column settings with sticky live preview. */
	private static function render_settings( $settings, $org ) {
		$default_id = absint( $settings['default_location'] );
		$color      = $settings['primary_color'] && preg_match( '/^#[0-9a-f]{3,6}$/i', $settings['primary_color'] ) ? $settings['primary_color'] : '#2f6f4f';
		$masked     = str_repeat( '•', 12 ) . substr( $settings['api_token'], -4 );
		$opt        = VSPS_OPTION_KEY;
		$layouts    = array(
			'full'     => 'Full',
			'bar'      => 'Bar',
			'calendar' => 'Calendar',
			'float'    => 'Floating card',
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Vetspire Scheduler — Settings</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=vsps-appointments' ) ); ?>" class="page-title-action">View Appointments</a>
			<hr class="wp-header-end" />

			<style>
				.vsps-cols { display:flex; gap:20px; align-items:flex-start; margin-top:16px; }
				.vsps-main { flex:1; min-width:0; }
				.vsps-side { width:460px; flex-shrink:0; }
				.vsps-side .vsps-sticky { position:sticky; top:46px; }
				@media (max-width:1100px){ .vsps-cols{display:block;} .vsps-side{width:auto;} }
				.vsps-box { background:#fff; border:1px solid #dcdcde; border-radius:4px; margin-bottom:16px; }
				.vsps-box > h2 { margin:0; padding:10px 14px; border-bottom:1px solid #f0f0f1; font-size:14px; }
				.vsps-box .inside { padding:14px; margin:0; }
				.vsps-cards { display:grid; grid-template-columns:repeat(2,1fr); gap:12px; --vsps-accent:<?php echo esc_attr( $color ); ?>; }
				.vsps-card { display:block; border:2px solid #dcdcde; border-radius:6px; padding:10px; cursor:pointer; text-align:center; background:#fff; transition:border-color .12s, transform .12s, box-shadow .12s; position:relative; }
				.vsps-card:hover { border-color:#8c8f94; transform:translateY(-1px); box-shadow:0 2px 6px rgba(0,0,0,.08); }
				.vsps-card input { position:absolute; opacity:0; pointer-events:none; }
				.vsps-card svg { width:100%; height:78px; display:block; background:#f6f7f7; border-radius:4px; }
				.vsps-card .vsps-card-name { display:block; font-weight:600; margin-top:8px; }
				.vsps-card.is-selected { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; background:#f0f6fc; }
				.vsps-card.is-selected::after { content:"✓"; position:absolute; top:6px; right:6px; width:20px; height:20px; border-radius:50%; background:#2271b1; color:#fff; font-size:12px; line-height:20px; }
				.vsps-preview-head { display:flex; justify-content:space-between; align-items:center; }
				.vsps-preview-chip { font-size:12px; color:#646970; }
				.vsps-preview-frame { background:#f0f0f1 url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2216%22 height=%2216%22%3E%3Ccircle cx=%221%22 cy=%221%22 r=%221%22 fill=%22%23d5d5d5%22/%3E%3C/svg%3E'); padding:18px; border-radius:4px; overflow:auto; max-height:calc(100vh - 240px); }
				@keyframes vsps-flash { 0%{ box-shadow:0 0 0 3px rgba(34,113,177,.55);} 100%{ box-shadow:0 0 0 3px rgba(34,113,177,0);} }
				.vsps-preview-frame.is-updated { animation:vsps-flash .6s ease-out; }
				@media (prefers-reduced-motion: reduce){ .vsps-preview-frame.is-updated { animation:none; } }
				.vsps-connected { color:#00a32a; }
				.vsps-copy-ok { color:#00a32a; margin-left:6px; display:none; }
			</style>

			<form method="post" action="options.php" id="vsps-settings-form">
				<?php settings_fields( 'vsps_settings_group' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[api_endpoint]" value="<?php echo esc_attr( $settings['api_endpoint'] ); ?>" />

				<div class="vsps-cols">
					<div class="vsps-main">

						<div class="vsps-box">
							<h2>Connection</h2>
							<div class="inside">
								<p class="vsps-connected" style="margin-top:0;">
									<span class="dashicons dashicons-yes-alt"></span>
									Connected to <strong><?php echo esc_html( $org['org'] ); ?></strong> · <?php echo count( $org['locations'] ); ?> locations
									<a href="#" id="vsps-change-key" style="margin-left:10px;font-weight:normal;">Change API key</a>
								</p>
								<input type="hidden" id="vsps-token-masked" name="<?php echo esc_attr( $opt ); ?>[api_token]" value="<?php echo esc_attr( $masked ); ?>" />
								<p id="vsps-key-wrap" style="display:none;">
									<input type="password" id="vsps-token-new" class="regular-text" placeholder="New Vetspire API Key" autocomplete="off" disabled />
									<span class="description">Save Changes to apply the new key.</span>
								</p>
								<?php if ( empty( $org['locations'] ) ) : ?>
									<div class="notice notice-warning inline"><p>Connected, but this Vetspire account has no locations configured yet. Add a location in Vetspire, then reload this page.</p></div>
								<?php endif; ?>
								<label for="vsps_location"><strong>Clinic location shown by the widget</strong></label><br />
								<select id="vsps_location" name="<?php echo esc_attr( $opt ); ?>[default_location]" style="margin-top:6px;min-width:280px;">
									<option value="">— Select the clinic location —</option>
									<?php foreach ( $org['locations'] as $loc ) : ?>
										<option value="<?php echo esc_attr( absint( $loc['id'] ) ); ?>" <?php selected( $default_id, absint( $loc['id'] ) ); ?>>
											<?php echo esc_html( $loc['name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php
								$types = array();
								if ( $default_id ) {
									$vsps_api_ref = vsps_api();
									$types        = VSPS_Cache::remember( array( 'admin-types', $default_id ), function () use ( $vsps_api_ref, $default_id ) {
										return $vsps_api_ref->get_bookable_types( $default_id );
									}, 600 );
									if ( is_wp_error( $types ) ) {
										$types = array();
									}
								}
								?>
								<p style="margin-bottom:0;margin-top:12px;">
									<label for="vsps_default_type"><strong>Primary appointment type</strong></label><br />
									<select id="vsps_default_type" name="<?php echo esc_attr( $opt ); ?>[default_type]" style="margin-top:6px;min-width:280px;" <?php disabled( empty( $types ) ); ?>>
										<option value="">Auto (first available type)</option>
										<?php foreach ( $types as $type ) : ?>
											<option value="<?php echo esc_attr( absint( $type['id'] ) ); ?>" <?php selected( absint( $settings['default_type'] ), absint( $type['id'] ) ); ?>>
												<?php echo esc_html( $type['name'] ); ?> (<?php echo esc_html( $type['duration'] ); ?> min)
											</option>
										<?php endforeach; ?>
									</select>
								</p>
								<p class="description">The Bar and Floating designs book this type; Full and Calendar preselect it in their dropdown.<?php echo $default_id ? '' : ' Select and save a location first.'; ?></p>
							</div>
						</div>

						<div class="vsps-box">
							<h2>Widget Design</h2>
							<div class="inside">
								<div class="vsps-cards" id="vsps-cards">
									<?php foreach ( $layouts as $key => $name ) : ?>
										<label class="vsps-card<?php echo $settings['layout'] === $key ? ' is-selected' : ''; ?>">
											<input type="radio" class="vsps-layout-radio" name="<?php echo esc_attr( $opt ); ?>[layout]"
												value="<?php echo esc_attr( $key ); ?>" <?php checked( $settings['layout'], $key ); ?> />
											<?php self::layout_thumb( $key ); ?>
											<span class="vsps-card-name"><?php echo esc_html( $name ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
								<p style="margin-bottom:0;margin-top:14px;">
									<label for="vsps_primary_color"><strong>Brand color</strong></label>
									<input type="color" id="vsps_primary_color" name="<?php echo esc_attr( $opt ); ?>[primary_color]"
										value="<?php echo esc_attr( $color ); ?>" style="width:56px;height:32px;padding:2px;cursor:pointer;vertical-align:middle;margin-left:8px;" />
									<span class="description">Buttons and highlights.</span>
								</p>
							</div>
						</div>

						<div class="vsps-box">
							<h2>Booking Form</h2>
							<div class="inside">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[extended_pet_fields]"
										value="1" <?php checked( 1, (int) $settings['extended_pet_fields'] ); ?> />
									Ask for extended pet details (breed, sex, age, spayed/neutered — all optional)
								</label>
								<p class="description">Off = shortest form (contact + pet name and species) — shorter forms convert better. On adds the optional fields in the same single screen.</p>
								<p style="margin-bottom:0;">
									<label for="vsps_source_label"><strong>Booking source label</strong></label>
									<input type="text" id="vsps_source_label" name="<?php echo esc_attr( $opt ); ?>[source_label]"
										value="<?php echo esc_attr( $settings['source_label'] ); ?>" class="regular-text" style="margin-left:8px;max-width:200px;" maxlength="40" />
								</p>
								<p class="description" style="margin-bottom:0;">Tags appointments sent by this widget (shows in the appointment reason in Vetspire and as the badge in the Appointments view). E.g. "Vetcelerator".</p>
							</div>
						</div>

						<div class="vsps-box">
							<h2>Admin View</h2>
							<div class="inside">
								<label style="display:block;margin-bottom:8px;">
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[admin_actions_enabled]"
										value="1" <?php checked( 1, (int) $settings['admin_actions_enabled'] ); ?> />
									Enable appointment actions (Confirm / Reschedule / Cancel) in the Appointments view
								</label>
								<p class="description">Keep ON while testing. <strong>Turn OFF at go-live</strong> so nobody can accidentally change a real appointment from WordPress — everything is still managed in Vetspire.</p>
								<label style="display:block;margin:10px 0 8px;">
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[admin_show_client]"
										value="1" <?php checked( 1, (int) $settings['admin_show_client'] ); ?> />
									Show the client name &amp; phone column
								</label>
								<p class="description" style="margin-bottom:0;">Off by default — pet names are enough for the schedule overview, and owner details stay in Vetspire.</p>
							</div>
						</div>

						<div class="vsps-box">
							<h2>Analytics</h2>
							<div class="inside">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[analytics_enabled]"
										value="1" <?php checked( 1, (int) $settings['analytics_enabled'] ); ?> />
									Push widget events to <code>window.dataLayer</code> (GTM / GA4)
								</label>
								<p class="description" style="margin-bottom:0;">Events: <code>vsps_widget_view</code>, <code>vsps_slot_selected</code>, <code>vsps_booking_submitted</code>, <code>vsps_booking_completed</code>, <code>vsps_booking_failed</code>.</p>
							</div>
						</div>

						<?php submit_button( 'Save Changes' ); ?>
					</div>

					<div class="vsps-side">
						<div class="vsps-sticky">
							<div class="vsps-box">
								<h2 class="vsps-preview-head">Live Preview
									<span class="vsps-preview-chip" id="vsps-preview-chip" aria-live="polite"></span>
								</h2>
								<div class="inside">
									<p id="vsps-preview-hint" class="description" <?php echo $default_id ? 'style="display:none;"' : ''; ?>><em>Select a clinic location to see the preview.</em></p>
									<div class="vsps-preview-frame" id="vsps-preview-frame" <?php echo $default_id ? '' : 'style="display:none;"'; ?>>
										<link rel="stylesheet" href="<?php echo esc_url( VSPS_PLUGIN_URL . 'assets/css/scheduler.css?ver=' . VSPS_VERSION ); ?>" />
										<div class="vsps-widget" id="vsps-preview" style="--vsps-primary:<?php echo esc_attr( $color ); ?>;margin:0 auto;"
											data-vsps-config="<?php echo esc_attr( wp_json_encode( array(
												'locationId' => $default_id,
												'typeIds'    => array(),
												'days'       => 7,
												'mode'          => 'link',
												'linkUrl'       => '#vsps-preview',
												'layout'        => $settings['layout'],
												'defaultTypeId' => absint( $settings['default_type'] ),
											) ) ); ?>"<?php echo $default_id ? '' : ' data-vsps-noinit="1"'; ?>>
											<h3 class="vsps-title">Book an Appointment</h3>
											<div class="vsps-body"><p class="vsps-loading">Loading available times…</p></div>
										</div>
									</div>
									<p class="description" style="margin-bottom:0;">Updates as you change the settings. No real bookings can be made from here.</p>
								</div>
							</div>
							<div class="vsps-box">
								<h2>Add it to your site</h2>
								<div class="inside">
									<p style="margin-top:0;">Paste this shortcode in any page or builder block:</p>
									<p>
										<code id="vsps-shortcode">[vetspire_scheduler]</code>
										<button type="button" class="button button-small" id="vsps-copy">Copy</button>
										<span class="vsps-copy-ok" id="vsps-copy-ok">✓ Copied</span>
									</p>
									<p class="description" style="margin-bottom:0;">Optional: <code>layout="bar"</code>, <code>days="7"</code>, <code>appointment_type_ids="5541"</code>, <code>title="..."</code>.</p>
								</div>
							</div>
						</div>
					</div>
				</div>
			</form>

			<script>window.vspsConfig = {
				restUrl: <?php echo wp_json_encode( esc_url_raw( rest_url( 'vetspire/v1' ) ) ); ?>,
				analytics: 0,
				nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>
			};</script>
			<script src="<?php echo esc_url( VSPS_PLUGIN_URL . 'assets/js/scheduler.js?ver=' . VSPS_VERSION ); ?>"></script>
			<script>
			(function () {
				var preview = document.getElementById('vsps-preview');
				var frame   = document.getElementById('vsps-preview-frame');
				var hint    = document.getElementById('vsps-preview-hint');
				var chip    = document.getElementById('vsps-preview-chip');
				var picker  = document.getElementById('vsps_primary_color');
				var locSel  = document.getElementById('vsps_location');
				var cards   = document.getElementById('vsps-cards');
				if (!preview) { return; }

				function layoutName(v) {
					return { full: 'Full', bar: 'Bar', calendar: 'Calendar', float: 'Floating card' }[v] || v;
				}
				function currentLayout() {
					var checked = document.querySelector('.vsps-layout-radio:checked');
					return checked ? checked.value : 'full';
				}
				function flash() {
					frame.classList.remove('is-updated');
					void frame.offsetWidth;
					frame.classList.add('is-updated');
				}
				function updateChip() {
					var name = locSel && locSel.selectedIndex > 0 ? locSel.options[locSel.selectedIndex].text : '';
					chip.textContent = 'Previewing: ' + layoutName(currentLayout()) + (name ? ' · ' + name.trim() : '');
				}
				function reinit() {
					var locationId = locSel ? parseInt(locSel.value, 10) : 0;
					updateChip();
					if (!locationId) {
						frame.style.display = 'none';
						if (hint) { hint.style.display = ''; }
						return;
					}
					if (hint) { hint.style.display = 'none'; }
					frame.style.display = '';
					try {
						var cfg = JSON.parse(preview.getAttribute('data-vsps-config'));
						cfg.locationId = locationId;
						cfg.layout = currentLayout();
						var typeSel = document.getElementById('vsps_default_type');
						cfg.defaultTypeId = ( typeSel && ! typeSel.disabled ) ? parseInt(typeSel.value, 10) || 0 : 0;
						preview.setAttribute('data-vsps-config', JSON.stringify(cfg));
						preview.className = 'vsps-widget';
						if (picker) { preview.style.setProperty('--vsps-primary', picker.value); }
						preview.innerHTML = '<h3 class="vsps-title">Book an Appointment</h3><div class="vsps-body"><p class="vsps-loading">Loading…</p></div>';
						window.vspsInitWidget(preview);
						flash();
					} catch (e) { /* non-blocking */ }
				}

				if (picker) {
					picker.addEventListener('input', function () {
						preview.style.setProperty('--vsps-primary', picker.value);
						if (cards) { cards.style.setProperty('--vsps-accent', picker.value); }
						flash();
					});
				}
				if (locSel) {
					locSel.addEventListener('change', function () {
						// The type list belongs to the previously saved location.
						var typeSel = document.getElementById('vsps_default_type');
						if (typeSel && locSel.value !== <?php echo wp_json_encode( (string) $default_id ); ?>) {
							typeSel.value = '';
							typeSel.disabled = true;
						} else if (typeSel) {
							typeSel.disabled = typeSel.options.length <= 1;
						}
						reinit();
					});
				}
				var typeSelMain = document.getElementById('vsps_default_type');
				if (typeSelMain) { typeSelMain.addEventListener('change', reinit); }
				document.querySelectorAll('.vsps-layout-radio').forEach(function (radio) {
					radio.addEventListener('change', function () {
						document.querySelectorAll('.vsps-card').forEach(function (c) { c.classList.remove('is-selected'); });
						radio.closest('.vsps-card').classList.add('is-selected');
						reinit();
					});
				});
				updateChip();

				var copy = document.getElementById('vsps-copy');
				if (copy) {
					copy.addEventListener('click', function () {
						var text = '[vetspire_scheduler]';
						var done = function () {
							var ok = document.getElementById('vsps-copy-ok');
							ok.style.display = 'inline';
							setTimeout(function () { ok.style.display = 'none'; }, 1500);
						};
						// Clipboard API needs a secure context; fall back for plain-http dev sites.
						if (navigator.clipboard && window.isSecureContext) {
							navigator.clipboard.writeText(text).then(done);
						} else {
							var ta = document.createElement('textarea');
							ta.value = text;
							ta.style.position = 'fixed';
							ta.style.opacity = '0';
							document.body.appendChild(ta);
							ta.select();
							try { document.execCommand('copy'); done(); } catch (e) { /* no-op */ }
							document.body.removeChild(ta);
						}
					});
				}

				// "Change API key": swap the masked hidden token for a fresh input.
				var changeKey = document.getElementById('vsps-change-key');
				if (changeKey) {
					changeKey.addEventListener('click', function (e) {
						e.preventDefault();
						var masked = document.getElementById('vsps-token-masked');
						var wrap = document.getElementById('vsps-key-wrap');
						var input = document.getElementById('vsps-token-new');
						if (masked) { masked.remove(); }
						wrap.style.display = '';
						input.disabled = false;
						input.name = <?php echo wp_json_encode( VSPS_OPTION_KEY . '[api_token]' ); ?>;
						input.focus();
						changeKey.remove();
					});
				}
			})();
			</script>
		</div>
		<?php
	}

	/** Inline SVG schematic for a layout card (accent parts use --vsps-accent). */
	private static function layout_thumb( $key ) {
		$a = 'var(--vsps-accent, #2f6f4f)';
		$g = '#c9cdd1';
		if ( 'full' === $key ) {
			echo '<svg viewBox="0 0 120 78" xmlns="http://www.w3.org/2000/svg">'
				. '<rect x="14" y="8" width="50" height="6" rx="2" fill="' . esc_attr( $g ) . '"/>'
				. '<rect x="14" y="20" width="22" height="12" rx="3" fill="' . esc_attr( $a ) . '"/>'
				. '<rect x="40" y="20" width="22" height="12" rx="3" fill="' . esc_attr( $g ) . '"/>'
				. '<rect x="66" y="20" width="22" height="12" rx="3" fill="' . esc_attr( $g ) . '"/>'
				. '<rect x="14" y="40" width="26" height="10" rx="3" fill="none" stroke="' . esc_attr( $a ) . '" stroke-width="1.5"/>'
				. '<rect x="46" y="40" width="26" height="10" rx="3" fill="none" stroke="' . esc_attr( $a ) . '" stroke-width="1.5"/>'
				. '<rect x="78" y="40" width="26" height="10" rx="3" fill="none" stroke="' . esc_attr( $a ) . '" stroke-width="1.5"/>'
				. '<rect x="14" y="56" width="26" height="10" rx="3" fill="none" stroke="' . esc_attr( $a ) . '" stroke-width="1.5"/>'
				. '<rect x="46" y="56" width="26" height="10" rx="3" fill="none" stroke="' . esc_attr( $a ) . '" stroke-width="1.5"/>'
				. '</svg>';
		} elseif ( 'bar' === $key ) {
			echo '<svg viewBox="0 0 120 78" xmlns="http://www.w3.org/2000/svg">'
				. '<rect x="8" y="30" width="104" height="18" rx="4" fill="#fff" stroke="' . esc_attr( $g ) . '"/>'
				. '<rect x="12" y="36" width="18" height="6" rx="2" fill="' . esc_attr( $g ) . '"/>'
				. '<rect x="34" y="34" width="14" height="10" rx="5" fill="' . esc_attr( $a ) . '"/>'
				. '<rect x="50" y="34" width="14" height="10" rx="5" fill="' . esc_attr( $a ) . '"/>'
				. '<rect x="66" y="34" width="14" height="10" rx="5" fill="' . esc_attr( $a ) . '"/>'
				. '<rect x="88" y="33" width="20" height="12" rx="3" fill="' . esc_attr( $a ) . '"/>'
				. '</svg>';
		} elseif ( 'calendar' === $key ) {
			$dots = '';
			for ( $r = 0; $r < 3; $r++ ) {
				for ( $c = 0; $c < 7; $c++ ) {
					$fill  = ( 1 === $r && 4 === $c ) ? $a : $g;
					$dots .= '<circle cx="' . ( 25 + $c * 12 ) . '" cy="' . ( 16 + $r * 12 ) . '" r="3.4" fill="' . esc_attr( $fill ) . '"/>';
				}
			}
			echo '<svg viewBox="0 0 120 78" xmlns="http://www.w3.org/2000/svg">' . $dots
				. '<rect x="22" y="56" width="22" height="10" rx="5" fill="none" stroke="' . esc_attr( $a ) . '" stroke-width="1.5"/>'
				. '<rect x="50" y="56" width="22" height="10" rx="5" fill="none" stroke="' . esc_attr( $a ) . '" stroke-width="1.5"/>'
				. '<rect x="78" y="56" width="22" height="10" rx="5" fill="none" stroke="' . esc_attr( $a ) . '" stroke-width="1.5"/>'
				. '</svg>';
		} else { // float
			echo '<svg viewBox="0 0 120 78" xmlns="http://www.w3.org/2000/svg">'
				. '<rect x="10" y="8" width="100" height="62" rx="3" fill="#fff" stroke="' . esc_attr( $g ) . '"/>'
				. '<rect x="16" y="14" width="40" height="5" rx="2" fill="' . esc_attr( $g ) . '"/>'
				. '<rect x="16" y="24" width="60" height="4" rx="2" fill="#e3e5e8"/>'
				. '<rect x="52" y="36" width="52" height="28" rx="5" fill="#fff" stroke="' . esc_attr( $a ) . '" stroke-width="1.5"/>'
				. '<rect x="57" y="42" width="12" height="10" rx="2" fill="' . esc_attr( $a ) . '"/>'
				. '<rect x="72" y="42" width="12" height="10" rx="2" fill="' . esc_attr( $a ) . '"/>'
				. '<rect x="87" y="42" width="12" height="10" rx="2" fill="' . esc_attr( $a ) . '"/>'
				. '</svg>';
		}
	}
}
