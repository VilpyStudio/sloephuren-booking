<?php
/**
 * Hoofd-controller: shortcode, assets, REST API, betaal-callbacks en cron.
 *
 * @package SloephurenBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SHB_Plugin
 */
class SHB_Plugin {

	/**
	 * Singleton-instantie.
	 *
	 * @var SHB_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * REST-namespace.
	 */
	const REST_NS = 'sloephuren/v1';

	/**
	 * Instantie ophalen.
	 *
	 * @return SHB_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks registreren.
	 */
	protected function __construct() {
		// Frontend.
		add_shortcode( 'sloephuren_booking', array( $this, 'render_shortcode' ) );
		add_shortcode( 'sloephuren_open', array( $this, 'render_open_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		// Widget overal op de site tonen (indien ingeschakeld).
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_sitewide' ), 20 );

		// REST API.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		// Dynamische endpoints nooit laten cachen (LiteSpeed/CDN/browser).
		add_filter( 'rest_post_dispatch', array( $this, 'no_cache_rest' ), 10, 3 );

		// Mock-betaalpagina en retour-afhandeling.
		add_action( 'template_redirect', array( $this, 'handle_frontend_actions' ) );

		// Cron: verlopen pending-boekingen opruimen.
		add_action( SHB_Install::CRON_HOOK, array( 'SHB_Bookings', 'expire_stale_pending' ) );

		// Admin.
		if ( is_admin() ) {
			SHB_Admin::instance();
		}
	}

	/* --------------------------------------------------------------------- */
	/* Assets                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Scripts en styles registreren (worden pas ingeladen bij de shortcode).
	 */
	public function register_assets() {
		// Google Fonts van het designsysteem (Bebas Neue, Hanken Grotesk, Space Grotesk).
		wp_register_style(
			'shb-fonts',
			'https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Hanken+Grotesk:wght@400;500;600;700&family=Space+Grotesk:wght@400;500&display=swap',
			array(),
			null
		);

		wp_register_style(
			'shb-booking',
			SHB_PLUGIN_URL . 'public/css/booking.css',
			array( 'shb-fonts' ),
			SHB_VERSION
		);

		wp_register_script(
			'shb-booking',
			SHB_PLUGIN_URL . 'public/js/booking.js',
			array(),
			SHB_VERSION,
			true
		);
	}

	/* --------------------------------------------------------------------- */
	/* Shortcode                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Shortcode [sloephuren_booking] weergeven.
	 *
	 * @param array $atts Attributen.
	 * @return string
	 */
	/**
	 * Of de widget-assets al zijn ingeladen (voorkomt dubbele output).
	 *
	 * @var bool
	 */
	protected $enqueued = false;

	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'sloep'        => 'geen', // Koppel aan één sloep (naam); slaat stap 1 over.
				'start_open'   => '0',    // Widget direct geopend tonen.
				'auto_advance' => '1',    // Automatisch doorgaan na een keuze.
			),
			$atts,
			'sloephuren_booking'
		);

		$this->enqueue_widget( $atts );

		// De widget (launcher + paneel) wordt door JS aan <body> gehangen.
		return '<div class="shb-widget-anchor" aria-hidden="true" style="display:none"></div>';
	}

	/**
	 * Shortcode [sloephuren_open]...[/sloephuren_open]: maakt van de inhoud een
	 * link die de boek-widget opent. Handig om op een bestaande knop/link te
	 * plaatsen. Optioneel sloep="Stout 650" om die sloep voor te selecteren.
	 *
	 * @param array       $atts    Attributen.
	 * @param string|null $content Inhoud tussen de tags (linktekst).
	 * @return string
	 */
	public function render_open_shortcode( $atts, $content = null ) {
		$atts = shortcode_atts(
			array(
				'sloep' => '',
				'class' => '',
				'text'  => '',
			),
			$atts,
			'sloephuren_open'
		);

		// Zorg dat de widget op deze pagina bestaat.
		$this->enqueue_widget();

		$label = '' !== trim( (string) $content ) ? $content : $atts['text'];
		if ( '' === trim( (string) $label ) ) {
			$label = __( 'Boek je sloep', 'sloephuren-booking' );
		}

		$classes = trim( 'shb-open ' . sanitize_text_field( $atts['class'] ) );

		return sprintf(
			'<a href="#sloephuren" class="%1$s"%2$s>%3$s</a>',
			esc_attr( $classes ),
			$atts['sloep'] ? ' data-shb-sloep="' . esc_attr( sanitize_text_field( $atts['sloep'] ) ) . '"' : '',
			do_shortcode( wp_kses_post( $label ) )
		);
	}

	/**
	 * Widget-assets + configuratie inladen (gedeeld door shortcode en sitewide).
	 *
	 * @param array $atts Widget-attributen.
	 */
	public function enqueue_widget( $atts = array() ) {
		if ( $this->enqueued ) {
			return; // Slechts één keer per pagina.
		}
		$this->enqueued = true;

		$atts = wp_parse_args(
			$atts,
			array(
				'sloep'        => 'geen',
				'start_open'   => '0',
				'auto_advance' => '1',
			)
		);

		wp_enqueue_style( 'shb-booking' );
		wp_enqueue_script( 'shb-booking' );

		// Pakketten.
		$products = array();
		foreach ( SHB_Bookings::get_products( true ) as $p ) {
			$products[] = array(
				'id'    => (int) $p->id,
				'name'  => $p->name,
				'price' => (float) $p->price,
			);
		}

		// Sloep-types.
		$boats = array();
		foreach ( SHB_Bookings::get_boat_types( true ) as $b ) {
			$boats[] = array(
				'id'          => (int) $b->id,
				'name'        => $b->name,
				'max_persons' => (int) $b->max_persons,
			);
		}

		wp_localize_script(
			'shb-booking',
			'SHB_DATA',
			array(
				'rest'        => esc_url_raw( rest_url( self::REST_NS ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'products'    => $products,
				'boats'       => $boats,
				'return'      => esc_url_raw( $this->current_url() ),
				'terms'       => esc_url_raw( get_option( 'shb_terms_url', '' ) ),
				'logo'        => esc_url_raw( SHB_PLUGIN_URL . 'public/img/logo-mark.png?v=' . SHB_VERSION ),
				'fixedSloep'  => sanitize_text_field( $atts['sloep'] ),
				'startOpen'   => ( '1' === (string) $atts['start_open'] || 'true' === $atts['start_open'] ),
				'autoAdvance' => ! ( '0' === (string) $atts['auto_advance'] || 'false' === $atts['auto_advance'] ),
				'currency'    => '€',
			)
		);
	}

	/**
	 * De widget overal op de site tonen wanneer de instelling aanstaat.
	 */
	public function maybe_enqueue_sitewide() {
		if ( is_admin() ) {
			return;
		}
		if ( ! get_option( 'shb_sitewide', 0 ) ) {
			return;
		}
		$this->enqueue_widget();
	}

	/**
	 * Retour-melding na betaling (op basis van query-parameters).
	 */
	protected function maybe_render_return_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['shb_booking'] ) ) {
			return;
		}
		$number  = sanitize_text_field( wp_unslash( $_GET['shb_booking'] ) );
		$booking = SHB_Bookings::get_booking_by_number( $number );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $booking ) {
			return;
		}

		if ( 'paid' === $booking->status ) {
			printf(
				'<div class="shb-notice shb-notice-success"><strong>%s</strong><p>%s</p></div>',
				esc_html__( 'Bedankt, je betaling is gelukt!', 'sloephuren-booking' ),
				esc_html(
					sprintf(
						/* translators: %s: boekingsnummer */
						__( 'Je ontvangt een bevestiging per e-mail. Je boekingsnummer is %s.', 'sloephuren-booking' ),
						$booking->booking_number
					)
				)
			);
		} else {
			printf(
				'<div class="shb-notice shb-notice-error"><strong>%s</strong><p>%s</p></div>',
				esc_html__( 'De betaling is niet afgerond.', 'sloephuren-booking' ),
				esc_html__( 'Je boeking is nog niet definitief. Probeer opnieuw te boeken.', 'sloephuren-booking' )
			);
		}
	}

	/* --------------------------------------------------------------------- */
	/* REST API                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * REST-routes registreren.
	 */
	public function register_rest_routes() {
		// Beschikbare tijdsloten.
		register_rest_route(
			self::REST_NS,
			'/timeslots',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_timeslots' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'product_id'   => array( 'required' => true ),
					'date'         => array( 'required' => true ),
					'boat_type_id' => array( 'required' => false ),
				),
			)
		);

		// Beschikbare sloep-types.
		register_rest_route(
			self::REST_NS,
			'/boats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_boats' ),
				'permission_callback' => '__return_true',
			)
		);

		// Niet-beschikbare dagen per maand (voor de widget-kalender).
		register_rest_route(
			self::REST_NS,
			'/month',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_month' ),
				'permission_callback' => '__return_true',
			)
		);

		// Boekingstatus opvragen (voor het terugkeer-scherm na de betaling).
		register_rest_route(
			self::REST_NS,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => '__return_true',
			)
		);

		// Verse beveiligings-nonce (nodig omdat de pagina gecachet kan zijn en
		// de ingebakken nonce dan verlopen is). Dit endpoint is niet-gecachet.
		register_rest_route(
			self::REST_NS,
			'/token',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_token' ),
				'permission_callback' => '__return_true',
			)
		);

		// Boeking + betaling starten.
		register_rest_route(
			self::REST_NS,
			'/book',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_book' ),
				'permission_callback' => array( $this, 'verify_rest_nonce' ),
			)
		);

		// Mollie-webhook.
		register_rest_route(
			self::REST_NS,
			'/webhook/mollie',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_mollie_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Voorkom dat de plugin-endpoints gecachet worden.
	 *
	 * Beschikbaarheid, status en boekingen zijn live data. LiteSpeed cachet
	 * REST-responses standaard (zagen we: 7 dagen), waardoor bezoekers oude
	 * beschikbaarheid krijgen en dubbel kunnen boeken. We sturen daarom expliciet
	 * no-cache mee voor alles onder onze namespace.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Verzoek.
	 * @return WP_HTTP_Response
	 */
	public function no_cache_rest( $response, $server, $request ) {
		$route = $request ? $request->get_route() : '';
		if ( is_string( $route ) && 0 === strpos( $route, '/' . self::REST_NS ) ) {
			if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
				$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0' );
				$response->header( 'Pragma', 'no-cache' );
			}
			// LiteSpeed Cache bepaalt zijn eigen cache-header via zijn API; met
			// deze actie weet de plugin dat deze request NIET gecachet mag worden.
			do_action( 'litespeed_control_set_nocache', 'Sloephuren dynamische API' );
		}
		return $response;
	}

	/**
	 * Nonce controleren voor beveiligde REST-calls (ook voor gasten).
	 *
	 * @param WP_REST_Request $request Verzoek.
	 * @return bool
	 */
	public function verify_rest_nonce( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * REST: beschikbare tijdsloten teruggeven.
	 *
	 * @param WP_REST_Request $request Verzoek.
	 * @return WP_REST_Response
	 */
	public function rest_timeslots( $request ) {
		$product_id   = (int) $request->get_param( 'product_id' );
		$date         = $this->sanitize_date( $request->get_param( 'date' ) );
		$boat_type_id = (int) $request->get_param( 'boat_type_id' );

		if ( ! $product_id || ! $date ) {
			return new WP_REST_Response( array( 'error' => __( 'Ongeldige aanvraag.', 'sloephuren-booking' ) ), 400 );
		}

		$slots = SHB_Availability::get_available_timeslots( $product_id, $date, $boat_type_id );
		return new WP_REST_Response( array( 'timeslots' => $slots ), 200 );
	}

	/**
	 * REST: beschikbare sloep-types teruggeven.
	 *
	 * @param WP_REST_Request $request Verzoek.
	 * @return WP_REST_Response
	 */
	public function rest_boats( $request ) {
		$product_id  = (int) $request->get_param( 'product_id' );
		$date        = $this->sanitize_date( $request->get_param( 'date' ) );
		$timeslot_id = (int) $request->get_param( 'timeslot_id' );

		if ( ! $product_id || ! $date || ! $timeslot_id ) {
			return new WP_REST_Response( array( 'error' => __( 'Ongeldige aanvraag.', 'sloephuren-booking' ) ), 400 );
		}

		$boats = SHB_Availability::get_available_boat_types( $product_id, $date, $timeslot_id );
		return new WP_REST_Response( array( 'boats' => $boats ), 200 );
	}

	/**
	 * REST: verse wp_rest-nonce teruggeven.
	 *
	 * @param WP_REST_Request $request Verzoek.
	 * @return WP_REST_Response
	 */
	public function rest_token( $request ) {
		return new WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ), 200 );
	}

	/**
	 * REST: niet-beschikbare dagen van een maand teruggeven.
	 *
	 * @param WP_REST_Request $request Verzoek.
	 * @return WP_REST_Response
	 */
	public function rest_month( $request ) {
		$product_id = (int) $request->get_param( 'product_id' );
		$year       = (int) $request->get_param( 'year' );
		$month      = (int) $request->get_param( 'month' );
		$boat_id    = (int) $request->get_param( 'boat_type_id' );

		if ( ! $product_id || $year < 2020 || $year > 2100 || $month < 1 || $month > 12 ) {
			return new WP_REST_Response( array( 'error' => __( 'Ongeldige aanvraag.', 'sloephuren-booking' ) ), 400 );
		}

		$unavailable = SHB_Availability::get_month_unavailable_days( $product_id, $year, $month, $boat_id );
		return new WP_REST_Response( array( 'unavailable' => $unavailable ), 200 );
	}

	/**
	 * REST: status van een boeking teruggeven (op boekingsnummer).
	 *
	 * Gebruikt door het terugkeer-scherm om de ECHTE betaalstatus te tonen,
	 * onafhankelijk van eventuele URL-parameters. Geeft alleen niet-persoonlijke
	 * samenvattingsvelden terug (geen naam/e-mail/telefoon).
	 *
	 * @param WP_REST_Request $request Verzoek.
	 * @return WP_REST_Response
	 */
	public function rest_status( $request ) {
		$number  = sanitize_text_field( (string) $request->get_param( 'number' ) );
		$booking = $number ? SHB_Bookings::get_booking_by_number( $number ) : null;

		if ( ! $booking ) {
			return new WP_REST_Response( array( 'error' => __( 'Boeking niet gevonden.', 'sloephuren-booking' ) ), 404 );
		}

		$product  = SHB_Bookings::get_product( $booking->product_id );
		$boat     = SHB_Bookings::get_boat_type( $booking->boat_type_id );
		$timeslot = SHB_Bookings::get_timeslot( $booking->timeslot_id );

		return new WP_REST_Response(
			array(
				'status'  => $booking->status,
				'number'  => $booking->booking_number,
				'amount'  => (float) $booking->amount,
				'summary' => array(
					array( 'label' => __( 'Sloep', 'sloephuren-booking' ), 'val' => $boat ? $boat->name : '' ),
					array( 'label' => __( 'Pakket', 'sloephuren-booking' ), 'val' => $product ? $product->name : '' ),
					array( 'label' => __( 'Datum', 'sloephuren-booking' ), 'val' => date_i18n( 'l j F Y', strtotime( $booking->booking_date ) ) ),
					array( 'label' => __( 'Tijdslot', 'sloephuren-booking' ), 'val' => $timeslot ? $timeslot->label : '' ),
				),
			),
			200
		);
	}

	/**
	 * REST: boeking aanmaken en betaling starten.
	 *
	 * @param WP_REST_Request $request Verzoek.
	 * @return WP_REST_Response
	 */
	public function rest_book( $request ) {
		// Invoer valideren en saneren.
		$product_id  = (int) $request->get_param( 'product_id' );
		$boat_id     = (int) $request->get_param( 'boat_type_id' );
		$timeslot_id = (int) $request->get_param( 'timeslot_id' );
		$date        = $this->sanitize_date( $request->get_param( 'date' ) );
		$name        = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email       = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone       = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$persons     = (int) $request->get_param( 'persons' );
		$agree       = (bool) $request->get_param( 'agree' );
		$return_url  = esc_url_raw( (string) $request->get_param( 'return_url' ) );

		// Basisvalidatie.
		$errors = array();
		$product = SHB_Bookings::get_product( $product_id );
		$boat    = SHB_Bookings::get_boat_type( $boat_id );
		$slot    = SHB_Bookings::get_timeslot( $timeslot_id );

		if ( ! $product || ! $product->active ) {
			$errors[] = __( 'Kies een geldig pakket.', 'sloephuren-booking' );
		}
		if ( ! $boat || ! $boat->active ) {
			$errors[] = __( 'Kies een geldige sloep.', 'sloephuren-booking' );
		}
		if ( ! $slot || ! $slot->active ) {
			$errors[] = __( 'Kies een geldig tijdslot.', 'sloephuren-booking' );
		}
		if ( ! $date || strtotime( $date ) < strtotime( gmdate( 'Y-m-d', current_time( 'timestamp' ) ) ) ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$errors[] = __( 'Kies een geldige datum in de toekomst.', 'sloephuren-booking' );
		}
		if ( '' === $name ) {
			$errors[] = __( 'Vul je naam in.', 'sloephuren-booking' );
		}
		if ( ! is_email( $email ) ) {
			$errors[] = __( 'Vul een geldig e-mailadres in.', 'sloephuren-booking' );
		}
		if ( '' === $phone ) {
			$errors[] = __( 'Vul je telefoonnummer in.', 'sloephuren-booking' );
		}
		if ( $boat && ( $persons < 1 || $persons > (int) $boat->max_persons ) ) {
			$errors[] = sprintf(
				/* translators: %d: max personen */
				__( 'Aantal personen moet tussen 1 en %d liggen.', 'sloephuren-booking' ),
				$boat ? (int) $boat->max_persons : 8
			);
		}
		if ( ! $agree ) {
			$errors[] = __( 'Je moet akkoord gaan met de voorwaarden.', 'sloephuren-booking' );
		}

		if ( $errors ) {
			return new WP_REST_Response( array( 'error' => implode( ' ', $errors ) ), 400 );
		}

		// Boeking veilig aanmaken (met lock + hercontrole beschikbaarheid).
		$result = SHB_Availability::create_booking_safely(
			array(
				'product_id'     => $product_id,
				'boat_type_id'   => $boat_id,
				'timeslot_id'    => $timeslot_id,
				'booking_date'   => $date,
				'customer_name'  => $name,
				'customer_email' => $email,
				'customer_phone' => $phone,
				'num_persons'    => $persons,
				'amount'         => (float) $product->price,
				'return_url'     => $return_url,
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 409 );
		}

		$booking = $result['booking'];

		// Betaling starten.
		$payment = SHB_Payments::create_payment( $booking );
		if ( is_wp_error( $payment ) ) {
			// Boeking meteen annuleren zodat de plek weer vrijkomt.
			SHB_Bookings::update_status( $booking->id, 'failed' );
			return new WP_REST_Response( array( 'error' => $payment->get_error_message() ), 502 );
		}

		// Betaalgegevens opslaan.
		SHB_Bookings::set_payment_data(
			$booking->id,
			array(
				'payment_provider' => $payment['provider'],
				'payment_id'       => $payment['payment_id'],
				'checkout_url'     => $payment['checkout_url'],
			)
		);

		return new WP_REST_Response(
			array(
				'booking_number' => $booking->booking_number,
				'checkout_url'   => $payment['checkout_url'],
			),
			200
		);
	}

	/**
	 * REST: Mollie-webhook. Mollie stuurt hier het payment-ID naartoe.
	 *
	 * @param WP_REST_Request $request Verzoek.
	 * @return WP_REST_Response
	 */
	public function rest_mollie_webhook( $request ) {
		$payment_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		if ( '' === $payment_id ) {
			return new WP_REST_Response( 'missing id', 400 );
		}

		$booking = SHB_Bookings::get_booking_by_payment_id( $payment_id );
		if ( ! $booking ) {
			// Altijd 200 teruggeven zodat Mollie niet blijft herhalen.
			return new WP_REST_Response( 'ok', 200 );
		}

		$status = SHB_Payments::fetch_mollie_status( $payment_id );
		if ( ! is_wp_error( $status ) ) {
			SHB_Payments::apply_mollie_status( $booking, $status );
		}

		return new WP_REST_Response( 'ok', 200 );
	}

	/* --------------------------------------------------------------------- */
	/* Frontend-acties: mock-betaalpagina + retour                           */
	/* --------------------------------------------------------------------- */

	/**
	 * Afhandeling van de mock-betaalpagina en het afronden daarvan.
	 */
	public function handle_frontend_actions() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['shb_action'] ) ) {
			return;
		}
		$action  = sanitize_key( wp_unslash( $_GET['shb_action'] ) );
		$number  = isset( $_GET['booking'] ) ? sanitize_text_field( wp_unslash( $_GET['booking'] ) ) : '';
		$key     = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$booking = $number ? SHB_Bookings::get_booking_by_number( $number ) : null;
		if ( ! $booking || ! SHB_Payments::verify_hash( $booking, $key ) ) {
			return;
		}

		if ( 'mock_checkout' === $action ) {
			$this->render_mock_checkout( $booking );
			exit;
		}

		if ( 'mock_complete' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$result = isset( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : '';
			if ( 'paid' === $result ) {
				SHB_Payments::mark_paid( $booking );
			} else {
				SHB_Payments::mark_unpaid( $booking, 'failed' );
			}

			// Terug naar de boekingspagina met een statusmelding.
			$return = $booking->return_url ? $booking->return_url : home_url( '/' );
			$url    = add_query_arg(
				array(
					'shb_booking' => rawurlencode( $booking->booking_number ),
					'shb_status'  => ( 'paid' === $result ) ? 'paid' : 'failed',
				),
				$return
			);
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Mock-betaalpagina renderen (simuleert de bank/iDEAL-omgeving).
	 *
	 * @param object $booking Boeking.
	 */
	protected function render_mock_checkout( $booking ) {
		$key      = SHB_Payments::booking_hash( $booking );
		$base     = home_url( '/' );
		$paid_url = add_query_arg(
			array(
				'shb_action' => 'mock_complete',
				'booking'    => rawurlencode( $booking->booking_number ),
				'key'        => $key,
				'result'     => 'paid',
			),
			$base
		);
		$fail_url = add_query_arg(
			array(
				'shb_action' => 'mock_complete',
				'booking'    => rawurlencode( $booking->booking_number ),
				'key'        => $key,
				'result'     => 'failed',
			),
			$base
		);

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<title><?php esc_html_e( 'Testbetaling', 'sloephuren-booking' ); ?></title>
	<style>
		body{font-family:Arial,Helvetica,sans-serif;background:#eef3f6;margin:0;padding:40px 16px;color:#12324a;}
		.box{max-width:440px;margin:0 auto;background:#fff;border-radius:14px;padding:32px;box-shadow:0 10px 30px rgba(18,50,74,.12);}
		h1{margin-top:0;font-size:22px;}
		.amount{font-size:32px;font-weight:700;margin:12px 0;}
		.tag{display:inline-block;background:#f4f1ea;padding:4px 10px;border-radius:20px;font-size:13px;margin-bottom:8px;}
		.btn{display:block;width:100%;padding:14px;border:0;border-radius:10px;font-size:16px;font-weight:600;cursor:pointer;margin-top:12px;text-align:center;text-decoration:none;}
		.btn-paid{background:#1f7a4d;color:#fff;}
		.btn-fail{background:#f4f1ea;color:#12324a;}
		.note{font-size:12px;color:#7d8a95;margin-top:18px;text-align:center;}
	</style>
</head>
<body>
	<div class="box">
		<span class="tag"><?php esc_html_e( 'Testomgeving (mock betaling)', 'sloephuren-booking' ); ?></span>
		<h1><?php esc_html_e( 'Betaling sloepverhuur', 'sloephuren-booking' ); ?></h1>
		<p><?php echo esc_html( sprintf( /* translators: %s: boekingsnummer */ __( 'Boeking %s', 'sloephuren-booking' ), $booking->booking_number ) ); ?></p>
		<div class="amount">&euro; <?php echo esc_html( number_format_i18n( (float) $booking->amount, 2 ) ); ?></div>
		<a class="btn btn-paid" href="<?php echo esc_url( $paid_url ); ?>"><?php esc_html_e( 'Betaling gelukt', 'sloephuren-booking' ); ?></a>
		<a class="btn btn-fail" href="<?php echo esc_url( $fail_url ); ?>"><?php esc_html_e( 'Betaling mislukt / annuleren', 'sloephuren-booking' ); ?></a>
		<p class="note"><?php esc_html_e( 'Deze pagina verschijnt alleen zolang er nog geen Mollie-sleutel is ingesteld.', 'sloephuren-booking' ); ?></p>
	</div>
</body>
</html>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Datum saneren naar Y-m-d of leeg.
	 *
	 * @param mixed $value Ruwe invoer.
	 * @return string
	 */
	protected function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		$d     = DateTime::createFromFormat( 'Y-m-d', $value );
		if ( $d && $d->format( 'Y-m-d' ) === $value ) {
			return $value;
		}
		return '';
	}

	/**
	 * Huidige URL (voor return_url).
	 *
	 * @return string
	 */
	protected function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( ! $host ) {
			return home_url( '/' );
		}
		$scheme = is_ssl() ? 'https' : 'http';
		// Query-parameters van eerdere retour strippen.
		$clean = remove_query_arg( array( 'shb_booking', 'shb_status', 'shb_return' ), esc_url_raw( $scheme . '://' . $host . $uri ) );
		return $clean;
	}

	/**
	 * Vertaalbare strings voor de frontend-JS.
	 *
	 * @return array
	 */
	protected function i18n_strings() {
		return array(
			'next'         => __( 'Volgende', 'sloephuren-booking' ),
			'book'         => __( 'Boek &amp; betaal direct', 'sloephuren-booking' ),
			'loading'      => __( 'Bezig met laden...', 'sloephuren-booking' ),
			'noSlots'      => __( 'Geen beschikbare tijdsloten op deze datum. Kies een andere dag.', 'sloephuren-booking' ),
			'noBoats'      => __( 'Geen beschikbare sloepen voor dit tijdslot.', 'sloephuren-booking' ),
			'full'         => __( 'Volgeboekt', 'sloephuren-booking' ),
			'available'    => __( 'Beschikbaar', 'sloephuren-booking' ),
			'spotsLeft'    => __( 'plek(ken) vrij', 'sloephuren-booking' ),
			'perPersons'   => __( 'max. %d personen', 'sloephuren-booking' ),
			'redirecting'  => __( 'Je wordt doorgestuurd naar de betaling...', 'sloephuren-booking' ),
			'genericError' => __( 'Er ging iets mis. Probeer het opnieuw.', 'sloephuren-booking' ),
			'summaryTitle' => __( 'Overzicht van je boeking', 'sloephuren-booking' ),
			'total'        => __( 'Totaal', 'sloephuren-booking' ),
			'chooseDate'   => __( 'Kies eerst een datum.', 'sloephuren-booking' ),
		);
	}
}
