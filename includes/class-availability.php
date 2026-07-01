<?php
/**
 * Beschikbaarheidslogica en veilige (race-condition-vrije) boeking-aanmaak.
 *
 * @package SloephurenBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SHB_Availability
 */
class SHB_Availability {

	/**
	 * Statussen die een sloep-plek bezet houden (naast recente pending-boekingen).
	 */
	const BLOCKING_STATUSES = array( 'paid' );

	/**
	 * Aantal reeds bezette plekken tellen voor een specifieke combinatie.
	 *
	 * Telt betaalde boekingen én pending-boekingen die nog binnen de
	 * 15-minuten-window vallen. Verlopen pending-boekingen tellen niet mee.
	 *
	 * @param string $date        Datum (Y-m-d).
	 * @param int    $timeslot_id Tijdslot-ID.
	 * @param int    $boat_type_id Sloep-type-ID.
	 * @param int    $exclude_id  Boeking-ID om over te slaan (bij hercontrole).
	 * @return int
	 */
	public static function count_taken( $date, $timeslot_id, $boat_type_id, $exclude_id = 0 ) {
		global $wpdb;
		$table   = SHB_Install::table( 'bookings' );
		$minutes = (int) get_option( 'shb_pending_minutes', 15 );
		$cutoff  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $minutes * MINUTE_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		// Een plek is bezet wanneer:
		// - de boeking betaald is, OF
		// - de boeking pending is én nog niet verlopen (created_at >= cutoff).
		$sql = "SELECT COUNT(*) FROM {$table}
			WHERE booking_date = %s
			  AND timeslot_id = %d
			  AND boat_type_id = %d
			  AND id <> %d
			  AND (
			      status = 'paid'
			      OR ( status = 'pending_payment' AND created_at >= %s )
			  )";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( $sql, $date, $timeslot_id, $boat_type_id, $exclude_id, $cutoff )
		);
	}

	/**
	 * Resterende beschikbaarheid voor een combinatie.
	 *
	 * @param object $boat_type    Sloep-type-object.
	 * @param string $date         Datum.
	 * @param int    $timeslot_id  Tijdslot-ID.
	 * @return int Aantal nog vrije plekken (>= 0).
	 */
	public static function remaining( $boat_type, $date, $timeslot_id ) {
		$taken = self::count_taken( $date, (int) $timeslot_id, (int) $boat_type->id );
		return max( 0, (int) $boat_type->stock - $taken );
	}

	/**
	 * Beschikbare tijdsloten voor een pakket op een datum.
	 *
	 * Zonder $boat_type_id is een tijdslot beschikbaar zolang minstens één
	 * sloep-type nog vrij is. Met $boat_type_id wordt alleen de resterende
	 * capaciteit van díe specifieke sloep meegeteld (gebruikt wanneer de
	 * klant de sloep al heeft gekozen vóórdat het tijdslot gekozen wordt).
	 *
	 * @param int    $product_id   Pakket-ID.
	 * @param string $date         Datum (Y-m-d).
	 * @param int    $boat_type_id Optioneel: filter op één sloep-type.
	 * @return array Lijst met slot-info voor de frontend.
	 */
	public static function get_available_timeslots( $product_id, $date, $boat_type_id = 0 ) {
		$slots = SHB_Bookings::get_timeslots( $product_id, true );
		$boat  = $boat_type_id ? SHB_Bookings::get_boat_type( (int) $boat_type_id ) : null;
		$boats = ( $boat && $boat->active ) ? array( $boat ) : SHB_Bookings::get_boat_types( true );
		$result = array();

		foreach ( $slots as $slot ) {
			$total_remaining = 0;
			foreach ( $boats as $bt ) {
				$total_remaining += self::remaining( $bt, $date, $slot->id );
			}
			$result[] = array(
				'id'        => (int) $slot->id,
				'label'     => $slot->label,
				'start'     => substr( $slot->start_time, 0, 5 ),
				'end'       => substr( $slot->end_time, 0, 5 ),
				'available' => $total_remaining > 0,
			);
		}

		return $result;
	}

	/**
	 * Beschikbare sloep-types voor een pakket + datum + tijdslot.
	 *
	 * @param int    $product_id  Pakket-ID (voor toekomstige koppeling).
	 * @param string $date        Datum.
	 * @param int    $timeslot_id Tijdslot-ID.
	 * @return array
	 */
	public static function get_available_boat_types( $product_id, $date, $timeslot_id ) {
		$boat_types = SHB_Bookings::get_boat_types( true );
		$result     = array();

		foreach ( $boat_types as $boat ) {
			$remaining = self::remaining( $boat, $date, $timeslot_id );
			$result[]  = array(
				'id'          => (int) $boat->id,
				'name'        => $boat->name,
				'max_persons' => (int) $boat->max_persons,
				'remaining'   => $remaining,
				'available'   => $remaining > 0,
			);
		}

		return $result;
	}

	/**
	 * Booking veilig aanmaken met een MySQL named lock.
	 *
	 * De lock zorgt dat twee gelijktijdige aanvragen voor dezelfde combinatie
	 * elkaar niet passeren: de tweede wacht tot de eerste klaar is en ziet dan
	 * de zojuist ingevoegde pending-boeking. Zo blijft dubbelboeken onmogelijk.
	 *
	 * @param array $data Gevalideerde boekingsgegevens.
	 * @return array|WP_Error Bij succes: array met booking-object.
	 */
	public static function create_booking_safely( $data ) {
		global $wpdb;

		$date         = $data['booking_date'];
		$timeslot_id  = (int) $data['timeslot_id'];
		$boat_type_id = (int) $data['boat_type_id'];

		// Unieke lock-naam per combinatie datum/tijdslot/sloep-type.
		// (max 64 tekens voor GET_LOCK; hash houdt het kort en veilig.)
		$lock_name = 'shb_' . md5( $date . '|' . $timeslot_id . '|' . $boat_type_id );

		// Lock verkrijgen (max 10 seconden wachten).
		$got_lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 10 ) );
		if ( 1 !== $got_lock ) {
			return new WP_Error( 'shb_lock_failed', __( 'Kan de beschikbaarheid nu niet vergrendelen. Probeer het opnieuw.', 'sloephuren-booking' ) );
		}

		try {
			$boat = SHB_Bookings::get_boat_type( $boat_type_id );
			if ( ! $boat || ! $boat->active ) {
				return new WP_Error( 'shb_boat_invalid', __( 'Deze sloep is niet beschikbaar.', 'sloephuren-booking' ) );
			}

			// Nog één keer controleren binnen de lock.
			$taken = self::count_taken( $date, $timeslot_id, $boat_type_id );
			if ( $taken >= (int) $boat->stock ) {
				return new WP_Error( 'shb_not_available', __( 'Helaas, dit tijdslot is zojuist volgeboekt. Kies een ander moment.', 'sloephuren-booking' ) );
			}

			// Boeking invoegen met status pending_payment.
			$table  = SHB_Install::table( 'bookings' );
			$number = SHB_Bookings::generate_booking_number();
			$now    = current_time( 'mysql' );

			$inserted = $wpdb->insert(
				$table,
				array(
					'booking_number' => $number,
					'product_id'     => (int) $data['product_id'],
					'boat_type_id'   => $boat_type_id,
					'timeslot_id'    => $timeslot_id,
					'booking_date'   => $date,
					'customer_name'  => $data['customer_name'],
					'customer_email' => $data['customer_email'],
					'customer_phone' => $data['customer_phone'],
					'num_persons'    => (int) $data['num_persons'],
					'status'         => 'pending_payment',
					'amount'         => (float) $data['amount'],
					'return_url'     => isset( $data['return_url'] ) ? esc_url_raw( $data['return_url'] ) : '',
					'created_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%f', '%s', '%s', '%s' )
			);

			if ( false === $inserted ) {
				return new WP_Error( 'shb_insert_failed', __( 'De boeking kon niet worden opgeslagen. Probeer het opnieuw.', 'sloephuren-booking' ) );
			}

			$booking = SHB_Bookings::get_booking( (int) $wpdb->insert_id );
			return array( 'booking' => $booking );

		} finally {
			// Lock altijd vrijgeven, ook bij een fout.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}
