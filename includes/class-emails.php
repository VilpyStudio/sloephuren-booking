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
	 * Afzendernaam voor de mails.
	 *
	 * @return string
	 */
	protected static function from_name() {
		$name = trim( (string) get_option( 'shb_from_name', '' ) );
		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	/**
	 * Afzender-e-mailadres. Valt terug op noreply@<domein> zodat de mail
	 * niet als "wordpress@..." wordt verstuurd.
	 *
	 * @return string
	 */
	protected static function from_email() {
		$email = trim( (string) get_option( 'shb_from_email', '' ) );
		if ( $email && is_email( $email ) ) {
			return $email;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? preg_replace( '/^www\./', '', $host ) : 'localhost';
		return 'noreply@' . $host;
	}

	/**
	 * Beheerder-ontvangers (kan meerdere adressen bevatten, gescheiden door
	 * komma, puntkomma of nieuwe regel).
	 *
	 * @return array Lijst geldige e-mailadressen.
	 */
	protected static function admin_recipients() {
		$raw    = (string) get_option( 'shb_admin_email', get_option( 'admin_email' ) );
		$emails = array();
		foreach ( preg_split( '/[,;\r\n]+/', $raw ) as $part ) {
			$part = sanitize_email( trim( $part ) );
			if ( $part && is_email( $part ) ) {
				$emails[] = $part;
			}
		}
		if ( ! $emails ) {
			$emails[] = get_option( 'admin_email' );
		}
		return array_values( array_unique( $emails ) );
	}

	/**
	 * HTML-mailheaders, inclusief nette afzender en optionele Reply-To.
	 *
	 * @param string $reply_to Optioneel Reply-To-adres.
	 * @return array
	 */
	protected static function headers( $reply_to = '' ) {
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', self::from_name(), self::from_email() ),
		);
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		return $headers;
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
			array( __( 'Boekingsnummer', 'sloephuren-booking' ), $d['number'], false ),
			array( __( 'Datum', 'sloephuren-booking' ), $d['date'], false ),
			array( __( 'Tijd', 'sloephuren-booking' ), $d['time'], false ),
			array( __( 'Pakket', 'sloephuren-booking' ), $d['package'], false ),
			array( __( 'Sloep', 'sloephuren-booking' ), $d['boat'], false ),
			array( __( 'Aantal personen', 'sloephuren-booking' ), $d['persons'], false ),
			array( __( 'Betaald bedrag', 'sloephuren-booking' ), '&euro; ' . $d['amount'], true ),
		);

		$html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;margin:8px 0;">';
		$total = count( $rows );
		foreach ( $rows as $i => $row ) {
			list( $label, $value, $strong ) = $row;
			$border = ( $i < $total - 1 ) ? 'border-bottom:1px solid #ece6da;' : '';
			$vstyle = $strong
				? 'font-family:Arial,Helvetica,sans-serif;font-size:17px;font-weight:700;color:#15324F;'
				: 'font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#3C4B55;';
			$html  .= '<tr>';
			$html  .= '<td style="padding:11px 0;' . $border . 'font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#8a8072;vertical-align:top;">' . esc_html( $label ) . '</td>';
			$html  .= '<td style="padding:11px 0;' . $border . $vstyle . 'text-align:right;vertical-align:top;">' . wp_kses( $value, array() ) . '</td>';
			$html  .= '</tr>';
		}
		$html .= '</table>';

		return $html;
	}

	/**
	 * URL van het lichte logo (voor op de marine header).
	 *
	 * @return string
	 */
	protected static function logo_url() {
		return SHB_PLUGIN_URL . 'public/img/logo-light.png';
	}

	/**
	 * Basis HTML-wrapper voor een mail.
	 *
	 * @param string $title Titel.
	 * @param string $body  Inhoud (HTML).
	 * @return string
	 */
	protected static function wrap( $title, $body ) {
		$site   = get_bloginfo( 'name' );
		$logo   = esc_url( self::logo_url() );
		$footer = sprintf(
			/* translators: %s: sitenaam */
			__( 'Deze e-mail is automatisch verstuurd door %s.', 'sloephuren-booking' ),
			$site
		);

		ob_start();
		?><!DOCTYPE html>
<html lang="nl" xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="x-apple-disable-message-reformatting">
	<meta name="color-scheme" content="light">
	<meta name="supported-color-schemes" content="light">
	<title><?php echo esc_html( $title ); ?></title>
	<style>
		/* Mobiel: kleinere padding, geen ronde hoeken op de rand. */
		@media only screen and (max-width:600px) {
			.shb-card { border-radius: 0 !important; }
			.shb-pad { padding: 22px 20px !important; }
			.shb-head { padding: 20px 20px !important; }
		}
		/* Voorkom dat clients de mail donker inverteren. */
		:root { color-scheme: light; supported-color-schemes: light; }
	</style>
</head>
<body style="margin:0;padding:0;background:#eef3f6;-webkit-text-size-adjust:100%;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef3f6;">
		<tr>
			<td align="center" style="padding:24px 12px;">
				<table role="presentation" class="shb-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;">
					<tr>
						<td class="shb-head" style="background:#15324F;padding:24px 30px;">
							<img src="<?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput ?>" alt="<?php echo esc_attr( $site ); ?>" height="42" style="height:42px;width:auto;display:block;border:0;margin-bottom:12px;">
							<div style="font-family:Arial,Helvetica,sans-serif;font-size:22px;font-weight:700;color:#ffffff;line-height:1.2;"><?php echo esc_html( $title ); ?></div>
							<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#9DB4C3;margin-top:3px;"><?php echo esc_html( $site ); ?></div>
						</td>
					</tr>
					<tr>
						<td class="shb-pad" style="padding:28px 30px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#3C4B55;">
							<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</td>
					</tr>
					<tr>
						<td style="padding:16px 30px;background:#F2EBE0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#8a8072;">
							<?php echo esc_html( $footer ); ?>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
		<?php
		return ob_get_clean();
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

		// Klant kan antwoorden; dat komt bij de beheerder terecht.
		$reply_to = self::admin_recipients();
		return wp_mail(
			$booking->customer_email,
			$subject,
			self::wrap( __( 'Boeking bevestigd', 'sloephuren-booking' ), $body ),
			self::headers( $reply_to[0] )
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

		$recipients = self::admin_recipients();

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

		// Reply-To = klant, zodat je direct kunt antwoorden aan de klant.
		return wp_mail(
			$recipients,
			$subject,
			self::wrap( __( 'Nieuwe boeking', 'sloephuren-booking' ), $body ),
			self::headers( $d['email'] )
		);
	}
}
