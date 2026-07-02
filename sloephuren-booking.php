<?php
/**
 * Plugin Name:       Sloephuren Booking
 * Plugin URI:        https://sloepverhuurzaanstad.nl
 * Description:        Online sloepen boeken en direct afrekenen via iDEAL. Beschikbaarheidscontrole op datum, tijdslot en sloep-type zodat dubbele boekingen onmogelijk zijn.
 * Version:           2.4.1
 * Author:            Studio Vilpy
 * Author URI:        https://vilpy.nl
 * License:           GPL-2.0-or-later
 * Text Domain:       sloephuren-booking
 * Domain Path:       /languages
 *
 * @package SloephurenBooking
 */

// Directe toegang blokkeren.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vaste plugin-constanten.
 */
define( 'SHB_VERSION', '2.4.1' );
define( 'SHB_PLUGIN_FILE', __FILE__ );
define( 'SHB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SHB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Prefix voor alle eigen database-tabellen (wordt gecombineerd met $wpdb->prefix).
define( 'SHB_TABLE_PREFIX', 'shb_' );

/**
 * Alle klasse-bestanden inladen.
 */
require_once SHB_PLUGIN_DIR . 'includes/class-install.php';
require_once SHB_PLUGIN_DIR . 'includes/class-bookings.php';
require_once SHB_PLUGIN_DIR . 'includes/class-availability.php';
require_once SHB_PLUGIN_DIR . 'includes/class-payments.php';
require_once SHB_PLUGIN_DIR . 'includes/class-emails.php';
require_once SHB_PLUGIN_DIR . 'includes/class-admin.php';
require_once SHB_PLUGIN_DIR . 'includes/class-github-updater.php';
require_once SHB_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Activatie: tabellen aanmaken, standaarddata plaatsen en cron inplannen.
 */
register_activation_hook( __FILE__, array( 'SHB_Install', 'activate' ) );

/**
 * Deactivatie: geplande cron-events opruimen.
 */
register_deactivation_hook( __FILE__, array( 'SHB_Install', 'deactivate' ) );

/**
 * Plugin starten zodra alle plugins geladen zijn.
 */
function shb_run() {
	// Automatische updates vanuit GitHub Releases.
	new SHB_GitHub_Updater();
	return SHB_Plugin::instance();
}
add_action( 'plugins_loaded', 'shb_run' );
