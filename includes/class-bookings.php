<?php
/**
 * Data-laag: boekingen, sloep-types, pakketten en tijdsloten.
 *
 * Alle databasetoegang loopt via deze klasse zodat queries op één plek staan.
 *
 * @package SloephurenBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SHB_Bookings
 */
class SHB_Bookings {

	/**
	 * Toegestane boekingstatussen.
	 */
	const STATUSES = array( 'pending_payment', 'paid', 'failed', 'expired', 'cancelled' );

	/* --------------------------------------------------------------------- */
	/* Sloep-types                                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * Sloep-types ophalen.
	 *
	 * @param bool $only_active Alleen actieve types.
	 * @return array
	 */
	public static function get_boat_types( $only_active = false ) {
		global $wpdb;
		$table = SHB_Install::table( 'boat_types' );
		$where = $only_active ? 'WHERE active = 1' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, id ASC" );
	}

	/**
	 * Eén sloep-type ophalen.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get_boat_type( $id ) {
		global $wpdb;
		$table = SHB_Install::table( 'boat_types' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Sloep-type opslaan (invoegen of bijwerken).
	 *
	 * @param array $data Gesaneerde velden.
	 * @param int   $id   0 = nieuw.
	 * @return int Rij-ID.
	 */
	public static function save_boat_type( $data, $id = 0 ) {
		global $wpdb;
		$table = SHB_Install::table( 'boat_types' );

		$fields = array(
			'name'        => sanitize_text_field( $data['name'] ),
			'stock'       => max( 0, (int) $data['stock'] ),
			'max_persons' => max( 1, (int) $data['max_persons'] ),
			'image_id'    => max( 0, (int) ( $data['image_id'] ?? 0 ) ),
			'active'      => empty( $data['active'] ) ? 0 : 1,
			'sort_order'  => (int) ( $data['sort_order'] ?? 0 ),
		);
		$formats = array( '%s', '%d', '%d', '%d', '%d', '%d' );

		if ( $id > 0 ) {
			$wpdb->update( $table, $fields, array( 'id' => $id ), $formats, array( '%d' ) );
			return $id;
		}

		$fields['created_at'] = current_time( 'mysql' );
		$formats[]            = '%s';
		$wpdb->insert( $table, $fields, $formats );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Sloep-type snel aan- of uitzetten (actief-vlag).
	 *
	 * @param int  $id     ID.
	 * @param bool $active Nieuwe staat.
	 */
	public static function set_boat_type_active( $id, $active ) {
		global $wpdb;
		$wpdb->update(
			SHB_Install::table( 'boat_types' ),
			array( 'active' => $active ? 1 : 0 ),
			array( 'id' => (int) $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Sloep-type verwijderen.
	 *
	 * @param int $id ID.
	 */
	public static function delete_boat_type( $id ) {
		global $wpdb;
		$wpdb->delete( SHB_Install::table( 'boat_types' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Pakketten / producten                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Pakketten ophalen.
	 *
	 * @param bool $only_active Alleen actieve.
	 * @return array
	 */
	public static function get_products( $only_active = false ) {
		global $wpdb;
		$table = SHB_Install::table( 'products' );
		$where = $only_active ? 'WHERE active = 1' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, id ASC" );
	}

	/**
	 * Eén pakket ophalen.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get_product( $id ) {
		global $wpdb;
		$table = SHB_Install::table( 'products' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Pakket opslaan.
	 *
	 * @param array $data Gesaneerde velden.
	 * @param int   $id   0 = nieuw.
	 * @return int
	 */
	public static function save_product( $data, $id = 0 ) {
		global $wpdb;
		$table = SHB_Install::table( 'products' );

		$fields = array(
			'name'       => sanitize_text_field( $data['name'] ),
			'price'      => round( (float) str_replace( ',', '.', $data['price'] ), 2 ),
			'active'     => empty( $data['active'] ) ? 0 : 1,
			'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
		);
		$formats = array( '%s', '%f', '%d', '%d' );

		if ( $id > 0 ) {
			$wpdb->update( $table, $fields, array( 'id' => $id ), $formats, array( '%d' ) );
			return $id;
		}

		$fields['created_at'] = current_time( 'mysql' );
		$formats[]            = '%s';
		$wpdb->insert( $table, $fields, $formats );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Pakket verwijderen.
	 *
	 * @param int $id ID.
	 */
	public static function delete_product( $id ) {
		global $wpdb;
		$wpdb->delete( SHB_Install::table( 'products' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Prijs per sloep (override op de standaard pakketprijs)                */
	/* --------------------------------------------------------------------- */

	/**
	 * Alle prijs-overrides als map [boat_type_id][product_id] => price.
	 *
	 * @return array
	 */
	public static function get_price_overrides() {
		global $wpdb;
		$table = SHB_Install::table( 'boat_prices' );
		$map   = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( "SELECT boat_type_id, product_id, price FROM {$table}" ) as $r ) {
			$map[ (int) $r->boat_type_id ][ (int) $r->product_id ] = (float) $r->price;
		}
		return $map;
	}

	/**
	 * Prijs-overrides voor één sloep: [product_id] => price.
	 *
	 * @param int $boat_type_id Sloep-ID.
	 * @return array
	 */
	public static function get_boat_price_overrides( $boat_type_id ) {
		global $wpdb;
		$table = SHB_Install::table( 'boat_prices' );
		$map   = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT product_id, price FROM {$table} WHERE boat_type_id = %d", (int) $boat_type_id ) ) as $r ) {
			$map[ (int) $r->product_id ] = (float) $r->price;
		}
		return $map;
	}

	/**
	 * Prijs-override zetten of (bij lege waarde) verwijderen.
	 *
	 * @param int         $boat_type_id Sloep-ID.
	 * @param int         $product_id   Pakket-ID.
	 * @param string|null $price        Prijs, of leeg/null om de override te wissen.
	 */
	public static function set_boat_price( $boat_type_id, $product_id, $price ) {
		global $wpdb;
		$table = SHB_Install::table( 'boat_prices' );

		if ( null === $price || '' === trim( (string) $price ) ) {
			$wpdb->delete( $table, array( 'boat_type_id' => (int) $boat_type_id, 'product_id' => (int) $product_id ), array( '%d', '%d' ) );
			return;
		}

		$value    = round( (float) str_replace( ',', '.', $price ), 2 );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE boat_type_id = %d AND product_id = %d", (int) $boat_type_id, (int) $product_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			$wpdb->update( $table, array( 'price' => $value ), array( 'id' => (int) $existing ), array( '%f' ), array( '%d' ) );
		} else {
			$wpdb->insert( $table, array( 'boat_type_id' => (int) $boat_type_id, 'product_id' => (int) $product_id, 'price' => $value ), array( '%d', '%d', '%f' ) );
		}
	}

	/**
	 * Effectieve prijs voor een sloep + pakket: de override indien aanwezig,
	 * anders de standaardprijs van het pakket.
	 *
	 * @param int $boat_type_id Sloep-ID.
	 * @param int $product_id   Pakket-ID.
	 * @return float
	 */
	public static function effective_price( $boat_type_id, $product_id ) {
		global $wpdb;
		$table = SHB_Install::table( 'boat_prices' );
		$over  = $wpdb->get_var( $wpdb->prepare( "SELECT price FROM {$table} WHERE boat_type_id = %d AND product_id = %d", (int) $boat_type_id, (int) $product_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null !== $over ) {
			return (float) $over;
		}
		$product = self::get_product( (int) $product_id );
		return $product ? (float) $product->price : 0.0;
	}

	/* --------------------------------------------------------------------- */
	/* Tijdsloten                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * Tijdsloten ophalen, optioneel gefilterd op pakket.
	 *
	 * @param int  $product_id  Pakket-ID (0 = alle).
	 * @param bool $only_active Alleen actieve.
	 * @return array
	 */
	public static function get_timeslots( $product_id = 0, $only_active = false ) {
		global $wpdb;
		$table   = SHB_Install::table( 'timeslots' );
		$clauses = array();
		$params  = array();

		if ( $product_id > 0 ) {
			$clauses[] = 'product_id = %d';
			$params[]  = $product_id;
		}
		if ( $only_active ) {
			$clauses[] = 'active = 1';
		}
		$where = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';
		$sql   = "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, start_time ASC";

		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $sql );
	}

	/**
	 * Eén tijdslot ophalen.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get_timeslot( $id ) {
		global $wpdb;
		$table = SHB_Install::table( 'timeslots' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Tijdslot opslaan.
	 *
	 * @param array $data Gesaneerde velden.
	 * @param int   $id   0 = nieuw.
	 * @return int
	 */
	public static function save_timeslot( $data, $id = 0 ) {
		global $wpdb;
		$table = SHB_Install::table( 'timeslots' );

		$fields = array(
			'product_id' => (int) $data['product_id'],
			'label'      => sanitize_text_field( $data['label'] ),
			'start_time' => self::sanitize_time( $data['start_time'] ),
			'end_time'   => self::sanitize_time( $data['end_time'] ),
			'active'     => empty( $data['active'] ) ? 0 : 1,
			'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
		);
		$formats = array( '%d', '%s', '%s', '%s', '%d', '%d' );

		if ( $id > 0 ) {
			$wpdb->update( $table, $fields, array( 'id' => $id ), $formats, array( '%d' ) );
			return $id;
		}

		$fields['created_at'] = current_time( 'mysql' );
		$formats[]            = '%s';
		$wpdb->insert( $table, $fields, $formats );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Tijdslot verwijderen.
	 *
	 * @param int $id ID.
	 */
	public static function delete_timeslot( $id ) {
		global $wpdb;
		$wpdb->delete( SHB_Install::table( 'timeslots' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Tijd normaliseren naar H:i:s.
	 *
	 * @param string $value Ruwe invoer (bijv. "10:00").
	 * @return string
	 */
	public static function sanitize_time( $value ) {
		$value = preg_replace( '/[^0-9:]/', '', (string) $value );
		$parts = array_map( 'intval', explode( ':', $value ) );
		$h     = isset( $parts[0] ) ? min( 23, max( 0, $parts[0] ) ) : 0;
		$m     = isset( $parts[1] ) ? min( 59, max( 0, $parts[1] ) ) : 0;
		$s     = isset( $parts[2] ) ? min( 59, max( 0, $parts[2] ) ) : 0;
		return sprintf( '%02d:%02d:%02d', $h, $m, $s );
	}

	/* --------------------------------------------------------------------- */
	/* Blokkades (dagen/periodes niet beschikbaar voor verhuur)              */
	/* --------------------------------------------------------------------- */

	/**
	 * Alle blokkades ophalen (nieuwste periode eerst).
	 *
	 * @return array
	 */
	public static function get_blocks() {
		global $wpdb;
		$table = SHB_Install::table( 'blocks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY date_from DESC, id DESC LIMIT 300" );
	}

	/**
	 * Blokkades die (deels) binnen een datumbereik vallen.
	 *
	 * @param string $from Begindatum (Y-m-d).
	 * @param string $to   Einddatum (Y-m-d).
	 * @return array
	 */
	public static function get_blocks_between( $from, $to ) {
		global $wpdb;
		$table = SHB_Install::table( 'blocks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE date_from <= %s AND date_to >= %s ORDER BY date_from ASC", $to, $from )
		);
	}

	/**
	 * Eén-daagse blokkade zoeken voor een exacte dag + sloep-scope + dagdeel.
	 *
	 * @param string $date         Datum (Y-m-d).
	 * @param int    $boat_type_id Sloep-ID (0 = alle sloepen).
	 * @param int    $timeslot_id  Tijdslot-ID (0 = hele dag).
	 * @return object|null
	 */
	public static function find_single_day_block( $date, $boat_type_id, $timeslot_id = 0 ) {
		global $wpdb;
		$table = SHB_Install::table( 'blocks' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE date_from = %s AND date_to = %s AND boat_type_id = %d AND timeslot_id = %d LIMIT 1",
				$date,
				$date,
				(int) $boat_type_id,
				(int) $timeslot_id
			)
		);
	}

	/**
	 * Blokkade toevoegen.
	 *
	 * @param array $data boat_type_id, timeslot_id, date_from, date_to, note.
	 * @return int Rij-ID.
	 */
	public static function add_block( $data ) {
		global $wpdb;
		$wpdb->insert(
			SHB_Install::table( 'blocks' ),
			array(
				'boat_type_id' => (int) ( $data['boat_type_id'] ?? 0 ),
				'timeslot_id'  => (int) ( $data['timeslot_id'] ?? 0 ),
				'date_from'    => $data['date_from'],
				'date_to'      => $data['date_to'],
				'note'         => sanitize_text_field( $data['note'] ?? '' ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Blokkade verwijderen.
	 *
	 * @param int $id ID.
	 */
	public static function delete_block( $id ) {
		global $wpdb;
		$wpdb->delete( SHB_Install::table( 'blocks' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Boekingen                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Boeking ophalen op ID.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get_booking( $id ) {
		global $wpdb;
		$table = SHB_Install::table( 'bookings' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Boeking ophalen op boekingsnummer.
	 *
	 * @param string $number Boekingsnummer.
	 * @return object|null
	 */
	public static function get_booking_by_number( $number ) {
		global $wpdb;
		$table = SHB_Install::table( 'bookings' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_number = %s", $number ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Boeking ophalen op payment-ID.
	 *
	 * @param string $payment_id Betaal-ID van de provider.
	 * @return object|null
	 */
	public static function get_booking_by_payment_id( $payment_id ) {
		global $wpdb;
		$table = SHB_Install::table( 'bookings' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE payment_id = %s", $payment_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Uniek boekingsnummer genereren, bijv. SLP-20260701-A1B2.
	 *
	 * @return string
	 */
	public static function generate_booking_number() {
		$date = gmdate( 'Ymd', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		do {
			$suffix = strtoupper( wp_generate_password( 4, false, false ) );
			$number = 'SLP-' . $date . '-' . $suffix;
		} while ( self::get_booking_by_number( $number ) );
		return $number;
	}

	/**
	 * Boeking-status bijwerken.
	 *
	 * @param int    $id     Boeking-ID.
	 * @param string $status Nieuwe status.
	 * @param array  $extra  Extra velden (bijv. paid_at).
	 * @return bool
	 */
	public static function update_status( $id, $status, $extra = array() ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$table  = SHB_Install::table( 'bookings' );
		$fields = array_merge(
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			$extra
		);
		return false !== $wpdb->update( $table, $fields, array( 'id' => (int) $id ), null, array( '%d' ) );
	}

	/**
	 * Betaalgegevens op een boeking opslaan.
	 *
	 * @param int   $id   Boeking-ID.
	 * @param array $data payment_provider, payment_id, checkout_url.
	 */
	public static function set_payment_data( $id, $data ) {
		global $wpdb;
		$table = SHB_Install::table( 'bookings' );
		$wpdb->update(
			$table,
			array(
				'payment_provider' => sanitize_text_field( $data['payment_provider'] ),
				'payment_id'       => sanitize_text_field( $data['payment_id'] ),
				'checkout_url'     => esc_url_raw( $data['checkout_url'] ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Boekingen ophalen voor de admin, met filters.
	 *
	 * @param array $args status, date_from, date_to, search, per_page, paged.
	 * @return array { items: object[], total: int }
	 */
	public static function query_bookings( $args = array() ) {
		global $wpdb;
		$table = SHB_Install::table( 'bookings' );

		$defaults = array(
			'status'    => '',
			'date_from' => '',
			'date_to'   => '',
			'search'    => '',
			'per_page'  => 30,
			'paged'     => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( $args['status'] && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( $args['date_from'] ) {
			$where[]  = 'booking_date >= %s';
			$params[] = $args['date_from'];
		}
		if ( $args['date_to'] ) {
			$where[]  = 'booking_date <= %s';
			$params[] = $args['date_to'];
		}
		if ( $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s OR booking_number LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// Totaal aantal.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $count_sql );
		}

		// Paginering.
		$per_page = max( 1, (int) $args['per_page'] );
		$paged    = max( 1, (int) $args['paged'] );
		$offset   = ( $paged - 1 ) * $per_page;

		$list_sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY booking_date DESC, id DESC LIMIT %d OFFSET %d";
		$list_params    = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Boeking definitief verwijderen.
	 *
	 * @param int $id Boeking-ID.
	 */
	public static function delete_booking( $id ) {
		global $wpdb;
		$wpdb->delete( SHB_Install::table( 'bookings' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Verlopen pending-boekingen markeren als 'expired'.
	 *
	 * Wordt aangeroepen door de cron. Boekingen ouder dan de pending-window
	 * die nog niet betaald zijn, worden vrijgegeven.
	 */
	public static function expire_stale_pending() {
		global $wpdb;
		$table   = SHB_Install::table( 'bookings' );
		$minutes = (int) get_option( 'shb_pending_minutes', 15 );
		$cutoff  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $minutes * MINUTE_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'expired', updated_at = %s WHERE status = 'pending_payment' AND created_at < %s",
				current_time( 'mysql' ),
				$cutoff
			)
		);
	}
}
