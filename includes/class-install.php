<?php
/**
 * Installatie / database-schema.
 *
 * Maakt de eigen tabellen aan met dbDelta, plaatst standaarddata en beheert cron.
 *
 * @package SloephurenBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SHB_Install
 */
class SHB_Install {

	/**
	 * Naam van het geplande cron-event dat verlopen pending-boekingen opruimt.
	 */
	const CRON_HOOK = 'shb_cleanup_pending';

	/**
	 * Volledige tabelnaam opbouwen.
	 *
	 * @param string $name Korte tabelnaam (bijv. 'bookings').
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . SHB_TABLE_PREFIX . $name;
	}

	/**
	 * Activatie-routine.
	 */
	public static function activate() {
		self::create_tables();
		self::seed_defaults();

		// Standaardinstellingen (alleen als ze nog niet bestaan).
		add_option( 'shb_pending_minutes', 15 );          // Hoe lang een pending-boeking blokkeert.
		add_option( 'shb_payment_provider', 'mock' );     // mock | mollie.
		add_option( 'shb_mollie_api_key', '' );
		add_option( 'shb_admin_email', get_option( 'admin_email' ) );
		add_option( 'shb_terms_url', '' );
		add_option( 'shb_sitewide', 1 ); // Widget standaard overal tonen.
		add_option( 'shb_db_version', SHB_VERSION );

		// Cron inplannen om verlopen pending-boekingen te markeren.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'shb_five_minutes', self::CRON_HOOK );
		}

		flush_rewrite_rules();
	}

	/**
	 * Deactivatie-routine.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		flush_rewrite_rules();
	}

	/**
	 * Alle tabellen aanmaken via dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$boat_types = self::table( 'boat_types' );
		$products   = self::table( 'products' );
		$timeslots  = self::table( 'timeslots' );
		$bookings    = self::table( 'bookings' );
		$blocks      = self::table( 'blocks' );
		$boat_prices = self::table( 'boat_prices' );

		// Sloep-types: naam, voorraad, max personen, actief.
		$sql_boat_types = "CREATE TABLE {$boat_types} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL DEFAULT '',
			stock SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			max_persons SMALLINT UNSIGNED NOT NULL DEFAULT 8,
			image_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			active TINYINT(1) NOT NULL DEFAULT 1,
			sort_order SMALLINT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY active (active)
		) {$charset_collate};";

		// Pakketten/producten: naam, prijs, actief.
		$sql_products = "CREATE TABLE {$products} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL DEFAULT '',
			price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			active TINYINT(1) NOT NULL DEFAULT 1,
			sort_order SMALLINT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY active (active)
		) {$charset_collate};";

		// Tijdsloten per pakket.
		$sql_timeslots = "CREATE TABLE {$timeslots} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			label VARCHAR(191) NOT NULL DEFAULT '',
			start_time TIME NOT NULL DEFAULT '10:00:00',
			end_time TIME NOT NULL DEFAULT '18:00:00',
			active TINYINT(1) NOT NULL DEFAULT 1,
			sort_order SMALLINT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY active (active)
		) {$charset_collate};";

		// Boekingen.
		$sql_bookings = "CREATE TABLE {$bookings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_number VARCHAR(20) NOT NULL DEFAULT '',
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			boat_type_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			timeslot_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			booking_date DATE NOT NULL DEFAULT '1970-01-01',
			customer_name VARCHAR(191) NOT NULL DEFAULT '',
			customer_email VARCHAR(191) NOT NULL DEFAULT '',
			customer_phone VARCHAR(60) NOT NULL DEFAULT '',
			num_persons SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			status VARCHAR(20) NOT NULL DEFAULT 'pending_payment',
			amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			payment_provider VARCHAR(20) NOT NULL DEFAULT '',
			payment_id VARCHAR(191) NOT NULL DEFAULT '',
			checkout_url TEXT NULL,
			return_url TEXT NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			paid_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY booking_number (booking_number),
			KEY status (status),
			KEY booking_date (booking_date),
			KEY availability (booking_date,timeslot_id,boat_type_id,status),
			KEY payment_id (payment_id)
		) {$charset_collate};";

		// Blokkades: dagen/periodes waarop een sloep (of alles) niet verhuurd wordt.
		// boat_type_id 0 = alle sloepen; timeslot_id 0 = hele dag.
		$sql_blocks = "CREATE TABLE {$blocks} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			boat_type_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			timeslot_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			date_from DATE NOT NULL DEFAULT '1970-01-01',
			date_to DATE NOT NULL DEFAULT '1970-01-01',
			note VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY date_range (date_from,date_to),
			KEY boat_type_id (boat_type_id)
		) {$charset_collate};";

		// Prijs per sloep + pakket (override op de standaard pakketprijs).
		$sql_boat_prices = "CREATE TABLE {$boat_prices} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			boat_type_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			PRIMARY KEY  (id),
			UNIQUE KEY boat_product (boat_type_id,product_id)
		) {$charset_collate};";

		dbDelta( $sql_boat_types );
		dbDelta( $sql_products );
		dbDelta( $sql_timeslots );
		dbDelta( $sql_bookings );
		dbDelta( $sql_blocks );
		dbDelta( $sql_boat_prices );
	}

	/**
	 * Standaarddata plaatsen (alleen als de tabellen nog leeg zijn).
	 */
	public static function seed_defaults() {
		global $wpdb;

		$now = current_time( 'mysql' );

		// --- Sloep-types ---
		$boat_types = self::table( 'boat_types' );
		$boat_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$boat_types}" ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( 0 === $boat_count ) {
			$defaults = array(
				array( 'Luxal Nautic', 1, 8, 1 ),
				array( 'Stout 650', 1, 8, 2 ),
				array( 'Zaanse Sloep', 2, 8, 3 ),
			);
			foreach ( $defaults as $row ) {
				$wpdb->insert(
					$boat_types,
					array(
						'name'        => $row[0],
						'stock'       => $row[1],
						'max_persons' => $row[2],
						'sort_order'  => $row[3],
						'active'      => 1,
						'created_at'  => $now,
					),
					array( '%s', '%d', '%d', '%d', '%d', '%s' )
				);
			}
		}

		// --- Pakketten ---
		$products      = self::table( 'products' );
		$product_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$products}" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$product_ids = array();
		if ( 0 === $product_count ) {
			$defaults = array(
				array( 'Halve dag varen', 265.00, 1 ),
				array( 'Hele dag varen', 399.00, 2 ),
			);
			foreach ( $defaults as $row ) {
				$wpdb->insert(
					$products,
					array(
						'name'       => $row[0],
						'price'      => $row[1],
						'sort_order' => $row[2],
						'active'     => 1,
						'created_at' => $now,
					),
					array( '%s', '%f', '%d', '%d', '%s' )
				);
				$product_ids[ $row[2] ] = (int) $wpdb->insert_id;
			}
		}

		// --- Tijdsloten per pakket ---
		$timeslots      = self::table( 'timeslots' );
		$timeslot_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$timeslots}" ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( 0 === $timeslot_count && ! empty( $product_ids ) ) {
			$halve_dag = isset( $product_ids[1] ) ? $product_ids[1] : 0;
			$hele_dag  = isset( $product_ids[2] ) ? $product_ids[2] : 0;

			$slots = array(
				// Halve dag: ochtend + middag.
				array( $halve_dag, 'Ochtend (10:00 - 14:00)', '10:00:00', '14:00:00', 1 ),
				array( $halve_dag, 'Middag (14:30 - 18:30)', '14:30:00', '18:30:00', 2 ),
				// Hele dag: één slot.
				array( $hele_dag, 'Hele dag (10:00 - 18:00)', '10:00:00', '18:00:00', 1 ),
			);

			foreach ( $slots as $slot ) {
				if ( ! $slot[0] ) {
					continue;
				}
				$wpdb->insert(
					$timeslots,
					array(
						'product_id' => $slot[0],
						'label'      => $slot[1],
						'start_time' => $slot[2],
						'end_time'   => $slot[3],
						'sort_order' => $slot[4],
						'active'     => 1,
						'created_at' => $now,
					),
					array( '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
				);
			}
		}
	}
}

/**
 * Eigen cron-interval van 5 minuten registreren.
 *
 * @param array $schedules Bestaande schema's.
 * @return array
 */
function shb_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['shb_five_minutes'] ) ) {
		$schedules['shb_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Elke 5 minuten (Sloephuren)', 'sloephuren-booking' ),
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'shb_cron_schedules' );
