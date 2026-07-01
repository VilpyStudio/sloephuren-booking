<?php
/**
 * E-mails: bevestiging naar de klant en melding naar de beheerder.
 *
 * @package SloephurenBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SHB_Emails
 */
class SHB_Emails {

	/**
	 * HTML-mailheaders.
	 *
	 * @return array
	 */
	protected static function headers() {
		return array( 'Content-Type: text/html; charset=UTF-8' );
	}

	/**
	 * Leesbare boekingsgegevens verzamelen.
	 *
	 * @param object $booking Boeking.
	 * @return array
	 */
	protected static function booking_details( $booking ) {
		$product  = SHB_Bookings::get_product( $booking->product_id );
		$boat     = SHB_Bookings::get_boat_type( $booking->boat_type_id );
		$timeslot = SHB_Bookings::get_timeslot( $booking->timeslot_id );

		return array(
			'number'  => $booking->booking_number,
			'date'    => date_i18n( 'l j F Y', strtotime( $booking->booking_date ) ),
			'time'    => $timeslot ? $timeslot->label : '',
			'package' => $product ? $product->name : '',
			'boat'    => $boat ? $boat->name : '',
			'persons' => (int) $booking->num_persons,
			'amount'  => number_format_i18n( (float) $booking->amount, 2 ),
			'name'    => $booking->customer_name,
			'email'   => $booking->customer_email,
			'phone'   => $booking->customer_phone,
		);
	}

	/**
	 * Herbruikbaar HTML-blok met de boekingsdetails.
	 *
	 * @param array $d Details.
	 * @return string
	 */
	protected static function details_table( $d ) {
		$rows = array(
			__( 'Boekingsnummer', 'sloephuren-booking' ) => $d['number'],
			__( 'Datum', 'sloephuren-booking' )          => $d['date'],
			__( 'Tijd', 'sloephuren-booking' )           => $d['time'],
			__( 'Pakket', 'sloephuren-booking' )         => $d['package'],
			__( 'Sloep', 'sloephuren-booking' )          => $d['boat'],
			__( 'Aantal personen', 'sloephuren-booking' ) => $d['persons'],
			__( 'Betaald bedrag', 'sloephuren-booking' ) => '&euro; ' . $d['amount'],
		);

		$html = '<table cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;max-width:520px;">';
		$i    = 0;
		foreach ( $rows as $label => $value ) {
			$bg    = ( 0 === $i % 2 ) ? '#f4f1ea' : '#ffffff';
			$html .= '<tr style="background:' . esc_attr( $bg ) . ';">';
			$html .= '<td style="font-weight:600;color:#12324a;border-bottom:1px solid #e5ded0;">' . esc_html( $label ) . '</td>';
			$html .= '<td style="color:#333;border-bottom:1px solid #e5ded0;">' . esc_html( $value ) . '</td>';
			$html .= '</tr>';
			$i++;
		}
		$html .= '</table>';

		return $html;
	}

	/**
	 * Basis HTML-wrapper voor een mail.
	 *
	 * @param string $title Titel.
	 * @param string $body  Inhoud (HTML).
	 * @return string
	 */
	protected static function wrap( $title, $body ) {
		$site = get_bloginfo( 'name' );
		$html = '<div style="font-family:Arial,Helvetica,sans-serif;background:#eef3f6;padding:24px;">';
		$html .= '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;">';
		$html .= '<div style="background:#12324a;padding:24px 28px;">';
		$html .= '<h1 style="margin:0;color:#ffffff;font-size:22px;">' . esc_html( $title ) . '</h1>';
		$html .= '<p style="margin:4px 0 0;color:#9fc0d6;font-size:14px;">' . esc_html( $site ) . '</p>';
		$html .= '</div>';
		$html .= '<div style="padding:28px;color:#333;font-size:15px;line-height:1.6;">' . $body . '</div>';
		$html .= '<div style="padding:18px 28px;background:#f4f1ea;color:#777;font-size:12px;">';
		$html .= esc_html( sprintf( /* translators: %s: sitenaam */ __( 'Deze e-mail is automatisch verstuurd door %s.', 'sloephuren-booking' ), $site ) );
		$html .= '</div></div></div>';
		return $html;
	}

	/**
	 * Bevestigingsmail naar de klant.
	 *
	 * @param object $booking Boeking.
	 * @return bool
	 */
	public static function send_confirmation( $booking ) {
		$d = self::booking_details( $booking );

		$intro = '<p>' . esc_html( sprintf( /* translators: %s: klantnaam */ __( 'Beste %s,', 'sloephuren-booking' ), $d['name'] ) ) . '</p>';
		$intro .= '<p>' . esc_html__( 'Bedankt voor je boeking! Je betaling is ontvangen en je sloep staat voor je klaar. Hieronder vind je de gegevens van je vaartocht.', 'sloephuren-booking' ) . '</p>';

		$outro = '<p style="margin-top:20px;">' . esc_html__( 'Tot snel op het water!', 'sloephuren-booking' ) . '</p>';

		$body = $intro . self::details_table( $d ) . $outro;

		$subject = sprintf(
			/* translators: %s: boekingsnummer */
			__( 'Bevestiging van je boeking %s', 'sloephuren-booking' ),
			$d['number']
		);

		return wp_mail(
			$booking->customer_email,
			$subject,
			self::wrap( __( 'Boeking bevestigd', 'sloephuren-booking' ), $body ),
			self::headers()
		);
	}

	/**
	 * Melding naar de beheerder.
	 *
	 * @param object $booking Boeking.
	 * @return bool
	 */
	public static function send_admin_notification( $booking ) {
		$d = self::booking_details( $booking );

		$admin_email = get_option( 'shb_admin_email', get_option( 'admin_email' ) );
		if ( ! is_email( $admin_email ) ) {
			$admin_email = get_option( 'admin_email' );
		}

		$contact = '<p style="margin-top:20px;"><strong>' . esc_html__( 'Contactgegevens klant', 'sloephuren-booking' ) . '</strong><br>';
		$contact .= esc_html( $d['name'] ) . '<br>';
		$contact .= '<a href="mailto:' . esc_attr( $d['email'] ) . '">' . esc_html( $d['email'] ) . '</a><br>';
		$contact .= esc_html( $d['phone'] ) . '</p>';

		$body = '<p>' . esc_html__( 'Er is een nieuwe betaalde boeking binnengekomen:', 'sloephuren-booking' ) . '</p>';
		$body .= self::details_table( $d ) . $contact;

		$subject = sprintf(
			/* translators: 1: boekingsnummer, 2: datum */
			__( 'Nieuwe boeking %1$s - %2$s', 'sloephuren-booking' ),
			$d['number'],
			$d['date']
		);

		return wp_mail(
			$admin_email,
			$subject,
			self::wrap( __( 'Nieuwe boeking', 'sloephuren-booking' ), $body ),
			self::headers()
		);
	}
}
