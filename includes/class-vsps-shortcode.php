<?php
/**
 * [vetspire_scheduler] shortcode: renders the widget container and
 * enqueues front-end assets with the widget configuration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VSPS_Shortcode {

	public static function init() {
		add_shortcode( 'vetspire_scheduler', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style(
			'vsps-scheduler',
			VSPS_PLUGIN_URL . 'assets/css/scheduler.css',
			array(),
			VSPS_VERSION
		);
		wp_register_script(
			'vsps-scheduler',
			VSPS_PLUGIN_URL . 'assets/js/scheduler.js',
			array(),
			VSPS_VERSION,
			true
		);

		// The #vsps-book external trigger is meant to work from a link ANYWHERE
		// on the site -- a "Book Online" nav item shows on every page, while
		// the shortcode itself might only be embedded on the homepage. That
		// only works if the script (and something for it to open) is present
		// on every front-end page, not only the one the shortcode renders on.
		// This runs on every page load, so only do it once the plugin actually
		// has somewhere to book. render() below ALSO enqueues + localizes for
		// whichever page DOES carry the shortcode (using that instance's own
		// location, which can differ from the site default) -- WordPress's
		// wp_localize_script() concatenates raw script text rather than
		// merging data on a second call for the same object name, so
		// whichever call runs later (render()'s, since shortcodes process
		// after this wp_enqueue_scripts hook) fully REPLACES this one; render()
		// includes the same defaultWidget shape too so that doesn't matter.
		$settings    = vsps_get_settings();
		$location_id = absint( $settings['default_location'] );
		if ( $location_id ) {
			wp_enqueue_style( 'vsps-scheduler' );
			wp_enqueue_script( 'vsps-scheduler' );
			wp_localize_script( 'vsps-scheduler', 'vspsConfig', array_merge(
				self::common_config( $settings ),
				array( 'defaultWidget' => self::default_widget_config( $settings, $location_id ) )
			) );
		}
	}

	/** The restUrl/analytics/i18n data every page needs, shortcode or not. */
	private static function common_config( array $settings ) {
		return array(
			'restUrl'   => esc_url_raw( rest_url( 'vetspire/v1' ) ),
			'analytics' => (int) $settings['analytics_enabled'],
			'i18n'      => array(
				'loading'        => __( 'Loading available times…', 'vetspire-scheduler' ),
				'noOptions'      => __( 'Online booking is not available right now. Please call the clinic.', 'vetspire-scheduler' ),
				'loadFailed'     => __( 'Could not load booking options. Please call the clinic.', 'vetspire-scheduler' ),
				'timesFailed'    => __( 'Could not load times. Please try again later.', 'vetspire-scheduler' ),
				'noTimes'        => __( 'No online times available in the next %d days. Please call the clinic.', 'vetspire-scheduler' ),
				'apptType'       => __( 'Select an Appointment Type', 'vetspire-scheduler' ),
				'open'           => __( 'open', 'vetspire-scheduler' ),
				'today'          => __( 'Today', 'vetspire-scheduler' ),
				'tomorrow'       => __( 'Tomorrow', 'vetspire-scheduler' ),
				'firstName'      => __( 'First name', 'vetspire-scheduler' ),
				'lastName'       => __( 'Last name', 'vetspire-scheduler' ),
				'email'          => __( 'Email', 'vetspire-scheduler' ),
				'phone'          => __( 'Phone', 'vetspire-scheduler' ),
				'petName'        => __( 'Pet name', 'vetspire-scheduler' ),
				'species'        => __( 'Pet type', 'vetspire-scheduler' ),
				'dog'            => __( 'Dog', 'vetspire-scheduler' ),
				'cat'            => __( 'Cat', 'vetspire-scheduler' ),
				'other'          => __( 'Other', 'vetspire-scheduler' ),
				'reason'         => __( 'Reason for visit (optional)', 'vetspire-scheduler' ),
				'cancel'         => __( 'Cancel', 'vetspire-scheduler' ),
				'confirm'        => __( 'Confirm Booking', 'vetspire-scheduler' ),
				'booking'        => __( 'Booking…', 'vetspire-scheduler' ),
				'booked'         => __( "✅ You're booked!", 'vetspire-scheduler' ),
				'confirmationTo' => __( 'A confirmation will be sent to your email. See you soon!', 'vetspire-scheduler' ),
				'close'          => __( 'Close', 'vetspire-scheduler' ),
				'bookingFailed'  => __( 'Booking failed. Please try another time or call the clinic.', 'vetspire-scheduler' ),
				'at'             => __( 'at', 'vetspire-scheduler' ),
				'viewAll'        => __( 'View All', 'vetspire-scheduler' ),
				'bookOnline'     => __( 'Book Online', 'vetspire-scheduler' ),
				'firstAvailable' => __( 'Book First Available Appointment', 'vetspire-scheduler' ),
				'moreAppointments' => __( 'More available appointments »', 'vetspire-scheduler' ),
				'showingTimesFor' => __( 'Showing available times for', 'vetspire-scheduler' ),
				'back'           => __( '‹ Back', 'vetspire-scheduler' ),
				'nextAvailable'  => __( 'Next Available Appointment', 'vetspire-scheduler' ),
				'chooseAnother'  => __( 'Choose Another Time', 'vetspire-scheduler' ),
				'slotGoneMessage' => __( 'The appointment time you selected is no longer available. Please choose another.', 'vetspire-scheduler' ),
				'earlierDates'   => __( 'Earlier dates', 'vetspire-scheduler' ),
				'laterDates'     => __( 'Later dates', 'vetspire-scheduler' ),
				'moreDates'      => __( 'More dates', 'vetspire-scheduler' ),
				'searchingDates' => __( 'Looking for open times %s…', 'vetspire-scheduler' ),
				'hoursTitle'     => __( 'Hours', 'vetspire-scheduler' ),
				'reviews'        => __( 'Google Reviews', 'vetspire-scheduler' ),
				'directions'     => __( 'Get Directions', 'vetspire-scheduler' ),
				'callUs'         => __( 'Call Us', 'vetspire-scheduler' ),
				'haveVisited'    => __( 'Have you visited us before?', 'vetspire-scheduler' ),
				'returningClient' => __( "Yes — I'm a returning client", 'vetspire-scheduler' ),
				'newClient'      => __( "No — I'm a new client", 'vetspire-scheduler' ),
				'emailAtClinic'  => __( 'Email you use at the clinic', 'vetspire-scheduler' ),
				'continueBtn'    => __( 'Continue', 'vetspire-scheduler' ),
				'notFoundEmail'  => __( "We couldn't find that email — let's book you as a new client.", 'vetspire-scheduler' ),
				'lookupFailed'   => __( 'Lookup is unavailable right now — you can continue as a new client.', 'vetspire-scheduler' ),
				'whosVisit'      => __( 'Who is this visit for?', 'vetspire-scheduler' ),
				'aNewPet'        => __( '+ A new pet', 'vetspire-scheduler' ),
				'bookingFor'     => __( 'Booking for', 'vetspire-scheduler' ),
				'addingPetTo'    => __( 'Adding a new pet to the account for', 'vetspire-scheduler' ),
				'last4Label'     => __( 'Last 4 digits of the phone on file', 'vetspire-scheduler' ),
				'cantVerify'     => __( "Can't verify? Book with the full form instead", 'vetspire-scheduler' ),
				'breed'          => __( 'Breed (optional)', 'vetspire-scheduler' ),
				'sexLabel'       => __( 'Sex (optional)', 'vetspire-scheduler' ),
				'male'           => __( 'Male', 'vetspire-scheduler' ),
				'female'         => __( 'Female', 'vetspire-scheduler' ),
				'ageYears'       => __( 'Age in years (optional)', 'vetspire-scheduler' ),
				'neuteredQ'      => __( 'Spayed / Neutered? (optional)', 'vetspire-scheduler' ),
				'yes'            => __( 'Yes', 'vetspire-scheduler' ),
				'no'             => __( 'No', 'vetspire-scheduler' ),
			),
		);
	}

	/**
	 * A ready-to-book config for the #vsps-book trigger to synthesize a
	 * standalone lightbox on a page that has no shortcode/widget markup at
	 * all -- the site's default location/layout/branding, same shape as the
	 * per-instance config the shortcode below writes into data-vsps-config.
	 */
	private static function default_widget_config( array $settings, $location_id ) {
		return array(
			'locationId'    => $location_id,
			'typeIds'       => array(),
			'days'          => 7,
			'horizonDays'   => 30,
			'mode'          => 'book',
			'linkUrl'       => '',
			'layout'        => 'full',
			'defaultTypeId' => absint( $settings['default_type'] ),
			'petFields'     => array(
				'breed'    => (int) $settings['ask_breed'],
				'sex'      => (int) $settings['ask_sex'],
				'age'      => (int) $settings['ask_age'],
				'neutered' => (int) $settings['ask_neutered'],
			),
			'variant'      => '',
			'primaryColor' => $settings['primary_color'],
			'title'        => __( 'Book an Appointment', 'vetspire-scheduler' ),
		);
	}

	public static function render( $atts ) {
		$settings = vsps_get_settings();
		$atts     = shortcode_atts( array(
			'location_id'          => $settings['default_location'],
			'appointment_type_ids' => '',
			'days'                 => 7,        // days per page of the date strip
			'max_days'             => 30,       // how far ahead the strip can page (cap 60)
			'mode'                 => 'book',   // book | link
			'link_url'             => '',
			'title'                => __( 'Book an Appointment', 'vetspire-scheduler' ),
			'layout'               => $settings['layout'], // full | bar | calendar | float
			'variant'              => '',       // a = minimal form, b = with optional questions
			'primary'              => '',       // "1" = the #vsps-book external trigger targets THIS instance
		), $atts, 'vetspire_scheduler' );

		$location_id = absint( $atts['location_id'] );
		if ( ! $location_id ) {
			return current_user_can( 'manage_options' )
				? '<p><em>[vetspire_scheduler] needs a location_id (or set a default in Settings → Vetspire Scheduler).</em></p>'
				: '';
		}

		$type_ids = array_values( array_filter( array_map( 'absint', explode( ',', $atts['appointment_type_ids'] ) ) ) );
		$mode     = 'link' === $atts['mode'] ? 'link' : 'book';

		wp_enqueue_style( 'vsps-scheduler' );
		wp_enqueue_script( 'vsps-scheduler' );
		// WordPress's localize() concatenates raw script text rather than
		// merging data ("var vspsConfig = {...}; var vspsConfig = {...};"),
		// and the shortcode's own do_shortcode() call runs after
		// register_assets()'s wp_enqueue_scripts hook -- so whatever this
		// call writes always wins and fully REPLACES register_assets()'s
		// object, defaultWidget included. Include it here too (built from
		// THIS instance's own resolved location, layout, etc. -- a sensible
		// fallback if the #vsps-book trigger is used on this same page but
		// for some reason no on-page Widget ends up in primaryWidget()'s
		// list, e.g. a data-vsps-noinit preview instance).
		wp_localize_script( 'vsps-scheduler', 'vspsConfig', array_merge(
			self::common_config( $settings ),
			array( 'defaultWidget' => self::default_widget_config( $settings, $location_id ) )
		) );

		$layout = in_array( $atts['layout'], array( 'full', 'bar', 'calendar', 'float' ), true ) ? $atts['layout'] : 'full';

		$variant    = in_array( strtolower( $atts['variant'] ), array( 'a', 'b' ), true ) ? strtolower( $atts['variant'] ) : '';
		$pet_fields = array(
			'breed'    => (int) $settings['ask_breed'],
			'sex'      => (int) $settings['ask_sex'],
			'age'      => (int) $settings['ask_age'],
			'neutered' => (int) $settings['ask_neutered'],
		);
		if ( 'a' === $variant ) {
			$pet_fields = array( 'breed' => 0, 'sex' => 0, 'age' => 0, 'neutered' => 0 );
		} elseif ( 'b' === $variant && 0 === array_sum( $pet_fields ) ) {
			// Variant B with nothing configured would be identical to A — turn
			// everything on so the test actually compares something.
			$pet_fields = array( 'breed' => 1, 'sex' => 1, 'age' => 1, 'neutered' => 1 );
		}

		$days_per_page = min( 14, max( 1, absint( $atts['days'] ) ) );
		$config = array(
			'locationId'    => $location_id,
			'typeIds'       => $type_ids,
			'days'          => $days_per_page,
			'horizonDays'   => min( 60, max( $days_per_page, absint( $atts['max_days'] ) ) ),
			'mode'          => $mode,
			'linkUrl'       => esc_url_raw( $atts['link_url'] ),
			'layout'        => $layout,
			'defaultTypeId' => absint( $settings['default_type'] ),
			'petFields'     => $pet_fields,
			'variant'       => $variant,
		);

		$style = '--vsps-primary:' . esc_attr( $settings['primary_color'] ) . ';';

		// On a page with several widgets, the #vsps-book external trigger (any
		// existing "Book Online" link pointed at "#vsps-book") opens the FIRST one
		// in the page's HTML by default; add primary="1" to pin a specific instance
		// instead of relying on markup order.
		$primary_attr = ! empty( $atts['primary'] ) ? ' data-vsps-primary="1"' : '';

		return sprintf(
			'<div class="vsps-widget" style="%s" data-vsps-config="%s"%s><h3 class="vsps-title">%s</h3><div class="vsps-body"><p class="vsps-loading">Loading available times…</p></div></div>',
			esc_attr( $style ),
			esc_attr( wp_json_encode( $config ) ),
			$primary_attr,
			esc_html( $atts['title'] )
		);
	}
}
