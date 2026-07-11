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

		// Een sloep is bezet zolang zijn boeking in TIJD overlapt met het gevraagde
		// tijdslot, ook als het een ander pakket/tijdslot is. Een middag-boeking
		// (14:30-18:30) bezet de sloep dus ook voor "hele dag" (10:00-18:00), maar
		// niet voor de ochtend. We tellen daarom alle boekingen op de overlappende
		// tijdsloten mee, niet alleen op exact hetzelfde tijdslot-ID.
		$times = self::slot_times();
		$req   = isset( $times[ (int) $timeslot_id ] ) ? $times[ (int) $timeslot_id ] : null;

		$ids = array();
		if ( $req ) {
			foreach ( $times as $sid => $t ) {
				if ( self::times_overlap( $req, $t ) ) {
					$ids[] = (int) $sid;
				}
			}
		}
		if ( ! $ids ) {
			$ids = array( (int) $timeslot_id ); // fallback: exact tijdslot.
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT COUNT(*) FROM {$table}
			WHERE booking_date = %s
			  AND boat_type_id = %d
			  AND timeslot_id IN ({$placeholders})
			  AND id <> %d
			  AND (
			      status = 'paid'
			      OR ( status = 'pending_payment' AND created_at >= %s )
			  )";

		$params = array_merge( array( $date, $boat_type_id ), $ids, array( $exclude_id, $cutoff ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Start-/eindtijden van alle tijdsloten (cache per request).
	 *
	 * @return array id => array( start, end ).
	 */
	public static function slot_times() {
		static $map = null;
		if ( null === $map ) {
			$map = array();
			foreach ( SHB_Bookings::get_timeslots() as $slot ) {
				$map[ (int) $slot->id ] = array( $slot->start_time, $slot->end_time );
			}
		}
		return $map;
	}

	/**
	 * Overlappen twee tijdvakken elkaar?
	 *
	 * @param array $a array( start, end ) als H:i:s-strings.
	 * @param array $b array( start, end ).
	 * @return bool
	 */
	protected static function times_overlap( $a, $b ) {
		return $a[0] < $b[1] && $b[0] < $a[1];
	}

	/**
	 * Is een combinatie geblokkeerd door een beheerder-blokkade?
	 *
	 * Een blokkade geldt wanneer de datum binnen de periode valt en de
	 * blokkade op alle sloepen (0) of op deze specifieke sloep staat. Voor
	 * het tijdvak geldt OVERLAP: een hele-dag-blokkade (timeslot 0) raakt
	 * alles, en een dagdeel-blokkade raakt elk tijdslot dat er qua tijden
	 * mee overlapt. Zo blokkeert een ochtend-blokkade ook de hele-dag-
	 * verhuur (die de ochtend nodig heeft), maar niet de middag.
	 *
	 * @param string $date         Datum (Y-m-d).
	 * @param int    $timeslot_id  Tijdslot-ID.
	 * @param int    $boat_type_id Sloep-ID.
	 * @return bool
	 */
	public static function is_blocked( $date, $timeslot_id, $boat_type_id ) {
		global $wpdb;
		$table = SHB_Install::table( 'blocks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$blocks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT timeslot_id FROM {$table}
				WHERE date_from <= %s AND date_to >= %s
				  AND ( boat_type_id = 0 OR boat_type_id = %d )",
				$date,
				$date,
				(int) $boat_type_id
			)
		);
		if ( ! $blocks ) {
			return false;
		}

		$times = self::slot_times();
		$req   = isset( $times[ (int) $timeslot_id ] ) ? $times[ (int) $timeslot_id ] : null;

		foreach ( $blocks as $bl ) {
			$bl_slot = (int) $bl->timeslot_id;
			if ( 0 === $bl_slot ) {
				return true; // Hele dag geblokkeerd.
			}
			if ( $req && isset( $times[ $bl_slot ] ) && self::times_overlap( $req, $times[ $bl_slot ] ) ) {
				return true;
			}
		}
		return false;
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
		if ( self::is_blocked( $date, (int) $timeslot_id, (int) $boat_type->id ) ) {
			return 0;
		}
		$taken = self::count_taken( $date, (int) $timeslot_id, (int) $boat_type->id );
		return max( 0, (int) $boat_type->stock - $taken );
	}

	/**
	 * Niet-beschikbare dagen van een maand (voor de widget-kalender).
	 *
	 * Een dag is niet beschikbaar wanneer geen enkel tijdslot van het pakket
	 * nog een vrije sloep heeft (door blokkades en/of boekingen). Alles wordt
	 * met twee aggregatie-queries opgehaald zodat dit één snelle call blijft.
	 *
	 * @param int $product_id   Pakket-ID.
	 * @param int $year         Jaar.
	 * @param int $month        Maand (1-12).
	 * @param int $boat_type_id Optioneel: alleen deze sloep meetellen.
	 * @return array Lijst ISO-datums (Y-m-d) die niet beschikbaar zijn.
	 */
	public static function get_month_unavailable_days( $product_id, $year, $month, $boat_type_id = 0 ) {
		global $wpdb;

		$year  = (int) $year;
		$month = (int) $month;
		$first = sprintf( '%04d-%02d-01', $year, $month );
		$dim   = (int) gmdate( 't', strtotime( $first . ' 12:00:00' ) );
		$last  = sprintf( '%04d-%02d-%02d', $year, $month, $dim );

		$slots = SHB_Bookings::get_timeslots( (int) $product_id, true );
		$boat  = $boat_type_id ? SHB_Bookings::get_boat_type( (int) $boat_type_id ) : null;
		$boats = ( $boat && $boat->active ) ? array( $boat ) : SHB_Bookings::get_boat_types( true );

		if ( ! $slots || ! $boats ) {
			return array();
		}

		// Bezette plekken per dag/tijdslot/sloep in één query.
		$btable  = SHB_Install::table( 'bookings' );
		$minutes = (int) get_option( 'shb_pending_minutes', 15 );
		$cutoff  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $minutes * MINUTE_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT booking_date d, timeslot_id t, boat_type_id b, COUNT(*) c FROM {$btable}
				WHERE booking_date BETWEEN %s AND %s
				  AND ( status = 'paid' OR ( status = 'pending_payment' AND created_at >= %s ) )
				GROUP BY booking_date, timeslot_id, boat_type_id",
				$first,
				$last,
				$cutoff
			)
		);
		$taken = array();
		foreach ( $rows as $r ) {
			$taken[ $r->d . '|' . $r->t . '|' . $r->b ] = (int) $r->c;
		}

		$blocks      = SHB_Bookings::get_blocks_between( $first, $last );
		$all_times   = self::slot_times(); // Alle tijdsloten (ook van andere pakketten) voor de overlap-som.
		$unavailable = array();

		for ( $d = 1; $d <= $dim; $d++ ) {
			$date = sprintf( '%04d-%02d-%02d', $year, $month, $d );
			$free = false;
			foreach ( $slots as $slot ) {
				$slot_time = array( $slot->start_time, $slot->end_time );
				foreach ( $boats as $bt ) {
					if ( self::block_covers( $blocks, $date, (int) $slot->id, (int) $bt->id ) ) {
						continue;
					}
					// Overlap-bewust tellen: alle boekingen op tijdsloten die met dit
					// tijdslot overlappen (ook van andere pakketten) bezetten de sloep.
					$c = 0;
					foreach ( $all_times as $sid => $t ) {
						if ( self::times_overlap( $slot_time, $t ) ) {
							$k = $date . '|' . $sid . '|' . $bt->id;
							if ( isset( $taken[ $k ] ) ) {
								$c += $taken[ $k ];
							}
						}
					}
					if ( ( (int) $bt->stock - $c ) > 0 ) {
						$free = true;
						break 2;
					}
				}
			}
			if ( ! $free ) {
				$unavailable[] = $date;
			}
		}

		return $unavailable;
	}

	/**
	 * Dekt één van de (reeds opgehaalde) blokkades deze combinatie af?
	 *
	 * Zelfde overlap-semantiek als is_blocked(): hele-dag-blokkades raken
	 * alles, dagdeel-blokkades raken elk tijdslot dat qua tijden overlapt.
	 *
	 * @param array  $blocks       Blokkade-rijen.
	 * @param string $date         Datum.
	 * @param int    $timeslot_id  Tijdslot-ID.
	 * @param int    $boat_type_id Sloep-ID.
	 * @return bool
	 */
	protected static function block_covers( $blocks, $date, $timeslot_id, $boat_type_id ) {
		$times = self::slot_times();
		$req   = isset( $times[ (int) $timeslot_id ] ) ? $times[ (int) $timeslot_id ] : null;

		foreach ( $blocks as $bl ) {
			if ( $bl->date_from > $date || $bl->date_to < $date ) {
				continue;
			}
			if ( 0 !== (int) $bl->boat_type_id && (int) $bl->boat_type_id !== $boat_type_id ) {
				continue;
			}
			$bl_slot = (int) $bl->timeslot_id;
			if ( 0 === $bl_slot ) {
				return true;
			}
			if ( $req && isset( $times[ $bl_slot ] ) && self::times_overlap( $req, $times[ $bl_slot ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * De blokkeerbare dagdelen voor het beheerscherm.
	 *
	 * Unieke tijdvakken uit de actieve tijdsloten; tijdvakken die met alle
	 * andere dagdelen overlappen (zoals het hele-dag-vaarslot) vallen weg,
	 * want die zijn gelijkwaardig aan een hele-dag-blokkade.
	 *
	 * @return array Lijst van array( id, label, start, end ).
	 */
	public static function get_blockable_dayparts() {
		$unique = array();
		foreach ( SHB_Bookings::get_timeslots( 0, true ) as $slot ) {
			$key = $slot->start_time . '|' . $slot->end_time;
			if ( ! isset( $unique[ $key ] ) ) {
				$unique[ $key ] = array(
					'id'    => (int) $slot->id,
					'label' => preg_replace( '/\s*\(.*\)\s*$/', '', $slot->label ),
					'start' => $slot->start_time,
					'end'   => $slot->end_time,
				);
			}
		}
		$parts = array_values( $unique );
		if ( count( $parts ) < 2 ) {
			return $parts;
		}

		// Dagdelen die alle andere overlappen eruit filteren.
		$filtered = array();
		foreach ( $parts as $p ) {
			$overlaps_all = true;
			foreach ( $parts as $other ) {
				if ( $other['id'] === $p['id'] ) {
					continue;
				}
				if ( ! self::times_overlap( array( $p['start'], $p['end'] ), array( $other['start'], $other['end'] ) ) ) {
					$overlaps_all = false;
					break;
				}
			}
			if ( ! $overlaps_all ) {
				$filtered[] = $p;
			}
		}
		return $filtered ? $filtered : $parts;
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

		// Unieke lock-naam per combinatie datum/sloep-type. Bewust NIET per
		// tijdslot: overlappende tijdsloten (bijv. middag en hele dag) moeten
		// elkaar serialiseren, anders kunnen twee gelijktijdige aanvragen op
		// verschillende-maar-overlappende sloten de sloep dubbel boeken.
		$lock_name = 'shb_' . md5( $date . '|' . $boat_type_id );

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

			// Beheerder-blokkade (onderhoud, gesloten, privegebruik).
			if ( self::is_blocked( $date, $timeslot_id, $boat_type_id ) ) {
				return new WP_Error( 'shb_blocked', __( 'Deze sloep is op de gekozen datum niet beschikbaar voor verhuur. Kies een andere dag.', 'sloephuren-booking' ) );
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
