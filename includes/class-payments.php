<?php
/**
 * Betalingen: mock-provider (werkt direct) en Mollie (iDEAL).
 *
 * De provider wordt gekozen via de instelling 'shb_payment_provider'.
 * Zolang er geen geldige Mollie-key is, valt de plugin automatisch terug op
 * de mock-provider zodat de flow altijd getest kan worden.
 *
 * @package SloephurenBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SHB_Payments
 */
class SHB_Payments {

	/**
	 * Actieve provider bepalen.
	 *
	 * @return string 'mollie' of 'mock'.
	 */
	public static function active_provider() {
		$provider = get_option( 'shb_payment_provider', 'mock' );
		$key      = trim( (string) get_option( 'shb_mollie_api_key', '' ) );

		// Zonder geldige Mollie-key vallen we terug op mock.
		if ( 'mollie' === $provider && '' !== $key ) {
			return 'mollie';
		}
		return 'mock';
	}

	/**
	 * Betaling starten voor een boeking.
	 *
	 * @param object $booking Boeking-object.
	 * @return array|WP_Error { payment_id, checkout_url, provider }.
	 */
	public static function create_payment( $booking ) {
		$provider = self::active_provider();

		if ( 'mollie' === $provider ) {
			return self::create_mollie_payment( $booking );
		}
		return self::create_mock_payment( $booking );
	}

	/* --------------------------------------------------------------------- */
	/* Mock-provider                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Mock-betaling: geeft een interne checkout-URL terug met een testpagina
	 * waarop je de betaling kunt laten "slagen" of "mislukken".
	 *
	 * @param object $booking Boeking.
	 * @return array
	 */
	public static function create_mock_payment( $booking ) {
		$payment_id = 'mock_' . $booking->booking_number;

		$checkout_url = add_query_arg(
			array(
				'shb_action' => 'mock_checkout',
				'booking'    => rawurlencode( $booking->booking_number ),
				'key'        => self::booking_hash( $booking ),
			),
			home_url( '/' )
		);

		return array(
			'provider'     => 'mock',
			'payment_id'   => $payment_id,
			'checkout_url' => $checkout_url,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Mollie-provider (iDEAL)                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Echte Mollie-betaling aanmaken via de REST API.
	 *
	 * @param object $booking Boeking.
	 * @return array|WP_Error
	 */
	public static function create_mollie_payment( $booking ) {
		$api_key = trim( (string) get_option( 'shb_mollie_api_key', '' ) );
		if ( '' === $api_key ) {
			return new WP_Error( 'shb_no_mollie_key', __( 'Mollie API-key ontbreekt.', 'sloephuren-booking' ) );
		}

		$redirect_url = add_query_arg(
			array(
				'shb_booking' => rawurlencode( $booking->booking_number ),
				'shb_return'  => '1',
			),
			$booking->return_url ? $booking->return_url : home_url( '/' )
		);

		$webhook_url = rest_url( 'sloephuren/v1/webhook/mollie' );

		$body = array(
			'amount'      => array(
				'currency' => 'EUR',
				'value'    => number_format( (float) $booking->amount, 2, '.', '' ),
			),
			'description' => sprintf(
				/* translators: %s: boekingsnummer */
				__( 'Sloepverhuur boeking %s', 'sloephuren-booking' ),
				$booking->booking_number
			),
			'redirectUrl' => $redirect_url,
			'webhookUrl'  => $webhook_url,
			'method'      => 'ideal',
			'metadata'    => array(
				'booking_number' => $booking->booking_number,
				'booking_id'     => (int) $booking->id,
			),
		);

		$response = wp_remote_post(
			'https://api.mollie.com/v2/payments',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $data['id'] ) ) {
			$message = isset( $data['detail'] ) ? $data['detail'] : __( 'Onbekende fout bij Mollie.', 'sloephuren-booking' );
			return new WP_Error( 'shb_mollie_error', $message );
		}

		return array(
			'provider'     => 'mollie',
			'payment_id'   => sanitize_text_field( $data['id'] ),
			'checkout_url' => esc_url_raw( $data['_links']['checkout']['href'] ),
		);
	}

	/**
	 * Actuele Mollie-status ophalen (gebruikt door de webhook).
	 *
	 * @param string $payment_id Mollie payment-ID.
	 * @return string|WP_Error Mollie-status (paid, open, failed, expired, canceled).
	 */
	public static function fetch_mollie_status( $payment_id ) {
		$api_key = trim( (string) get_option( 'shb_mollie_api_key', '' ) );
		if ( '' === $api_key ) {
			return new WP_Error( 'shb_no_mollie_key', __( 'Mollie API-key ontbreekt.', 'sloephuren-booking' ) );
		}

		$response = wp_remote_get(
			'https://api.mollie.com/v2/payments/' . rawurlencode( $payment_id ),
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['status'] ) ) {
			return new WP_Error( 'shb_mollie_status', __( 'Kon Mollie-status niet ophalen.', 'sloephuren-booking' ) );
		}

		return sanitize_text_field( $data['status'] );
	}

	/* --------------------------------------------------------------------- */
	/* Statusverwerking (gedeeld door mock en Mollie)                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Een betaalde boeking afronden: status op 'paid', paid_at zetten en mails.
	 *
	 * Idempotent: al betaalde boekingen worden niet dubbel verwerkt.
	 *
	 * @param object $booking Boeking.
	 * @return bool
	 */
	public static function mark_paid( $booking ) {
		if ( 'paid' === $booking->status ) {
			return true;
		}

		SHB_Bookings::update_status(
			$booking->id,
			'paid',
			array( 'paid_at' => current_time( 'mysql' ) )
		);

		// Bevestigingsmails versturen (klant + beheerder).
		$fresh = SHB_Bookings::get_booking( $booking->id );
		SHB_Emails::send_confirmation( $fresh );
		SHB_Emails::send_admin_notification( $fresh );

		return true;
	}

	/**
	 * Een mislukte/verlopen betaling verwerken.
	 *
	 * @param object $booking Boeking.
	 * @param string $status  'failed' of 'expired'.
	 */
	public static function mark_unpaid( $booking, $status = 'failed' ) {
		if ( 'paid' === $booking->status ) {
			return; // Nooit een reeds betaalde boeking terugzetten.
		}
		$status = in_array( $status, array( 'failed', 'expired', 'cancelled' ), true ) ? $status : 'failed';
		SHB_Bookings::update_status( $booking->id, $status );
	}

	/**
	 * Mollie-status vertalen naar onze boekingstatus en toepassen.
	 *
	 * @param object $booking       Boeking.
	 * @param string $mollie_status Mollie-status.
	 */
	public static function apply_mollie_status( $booking, $mollie_status ) {
		switch ( $mollie_status ) {
			case 'paid':
				self::mark_paid( $booking );
				break;
			case 'failed':
			case 'canceled':
				self::mark_unpaid( $booking, 'failed' );
				break;
			case 'expired':
				self::mark_unpaid( $booking, 'expired' );
				break;
			// 'open' / 'pending' laten we ongemoeid.
		}
	}

	/**
	 * Beveiligingshash voor een boeking (voorkomt sleutel-gokken op de mock-URL).
	 *
	 * @param object $booking Boeking.
	 * @return string
	 */
	public static function booking_hash( $booking ) {
		return substr( wp_hash( 'shb_' . $booking->id . '_' . $booking->booking_number ), 0, 20 );
	}

	/**
	 * Hash verifiëren.
	 *
	 * @param object $booking Boeking.
	 * @param string $hash    Ontvangen hash.
	 * @return bool
	 */
	public static function verify_hash( $booking, $hash ) {
		return hash_equals( self::booking_hash( $booking ), (string) $hash );
	}
}
