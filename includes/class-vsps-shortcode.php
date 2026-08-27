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
	}

	public static function render( $atts ) {
		$settings = vsps_get_settings();
		$atts     = shortcode_atts( array(
			'location_id'          => $settings['default_location'],
			'appointment_type_ids' => '',
			'days'                 => 7,
			'mode'                 => 'book',   // book | link
			'link_url'             => '',
			'title'                => __( 'Book an Appointment', 'vetspire-scheduler' ),
			'layout'               => $settings['layout'], // full | bar | calendar | float
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
		wp_localize_script( 'vsps-scheduler', 'vspsConfig', array(
			'restUrl'   => esc_url_raw( rest_url( 'vetspire/v1' ) ),
			'analytics' => (int) $settings['analytics_enabled'],
			'i18n'      => array(
				'loading'        => __( 'Loading available times…', 'vetspire-scheduler' ),
				'noOptions'      => __( 'Online booking is not available right now. Please call the clinic.', 'vetspire-scheduler' ),
				'loadFailed'     => __( 'Could not load booking options. Please call the clinic.', 'vetspire-scheduler' ),
				'timesFailed'    => __( 'Could not load times. Please try again later.', 'vetspire-scheduler' ),
				'noTimes'        => __( 'No online times available in the next %d days. Please call the clinic.', 'vetspire-scheduler' ),
				'apptType'       => __( 'Appointment type', 'vetspire-scheduler' ),
				'open'           => __( 'open', 'vetspire-scheduler' ),
				'today'          => __( 'Today', 'vetspire-scheduler' ),
				'tomorrow'       => __( 'Tomorrow', 'vetspire-scheduler' ),
				'firstName'      => __( 'First name', 'vetspire-scheduler' ),
				'lastName'       => __( 'Last name', 'vetspire-scheduler' ),
				'email'          => __( 'Email', 'vetspire-scheduler' ),
				'phone'          => __( 'Phone', 'vetspire-scheduler' ),
				'petName'        => __( 'Pet name', 'vetspire-scheduler' ),
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
				'todaysAvailability' => __( "Today's Availability", 'vetspire-scheduler' ),
				'availability'   => __( 'Availability', 'vetspire-scheduler' ),
				'viewAll'        => __( 'View All', 'vetspire-scheduler' ),
				'bookOnline'     => __( 'Book Online', 'vetspire-scheduler' ),
				'firstAvailable' => __( 'Book First Available Appointment', 'vetspire-scheduler' ),
				'moreAppointments' => __( 'More available appointments »', 'vetspire-scheduler' ),
				'showingTimesFor' => __( 'Showing available times for', 'vetspire-scheduler' ),
				'back'           => __( '‹ Back', 'vetspire-scheduler' ),
				'currentlyViewing' => __( 'Currently Viewing', 'vetspire-scheduler' ),
				'hoursTitle'     => __( 'Hours', 'vetspire-scheduler' ),
				'website'        => __( 'Visit Website', 'vetspire-scheduler' ),
				'reviews'        => __( 'Google Reviews', 'vetspire-scheduler' ),
				'directions'     => __( 'Get Directions', 'vetspire-scheduler' ),
				'callUs'         => __( 'Call Us', 'vetspire-scheduler' ),
			),
		) );

		$layout = in_array( $atts['layout'], array( 'full', 'bar', 'calendar', 'float' ), true ) ? $atts['layout'] : 'full';

		$config = array(
			'locationId'    => $location_id,
			'typeIds'       => $type_ids,
			'days'          => min( 14, max( 1, absint( $atts['days'] ) ) ),
			'mode'          => $mode,
			'linkUrl'       => esc_url_raw( $atts['link_url'] ),
			'layout'        => $layout,
			'defaultTypeId' => absint( $settings['default_type'] ),
		);

		$style = '--vsps-primary:' . esc_attr( $settings['primary_color'] ) . ';';

		return sprintf(
			'<div class="vsps-widget" style="%s" data-vsps-config="%s"><h3 class="vsps-title">%s</h3><div class="vsps-body"><p class="vsps-loading">Loading available times…</p></div></div>',
			esc_attr( $style ),
			esc_attr( wp_json_encode( $config ) ),
			esc_html( $atts['title'] )
		);
	}
}
