<?php
/**
 * Lichte GitHub-updater.
 *
 * Laat WordPress plugin-updates ophalen uit de GitHub Releases van een
 * PUBLIEKE repo (geen token nodig). Vergelijkt de laatste release-tag met de
 * geïnstalleerde versie en biedt de update aan in het normale WordPress
 * update-scherm, inclusief de zip die de release-workflow bouwt.
 *
 * @package SloephurenBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SHB_GitHub_Updater
 */
class SHB_GitHub_Updater {

	/**
	 * GitHub-eigenaar/organisatie.
	 */
	const OWNER = 'VilpyStudio';

	/**
	 * Repo-naam (tevens plugin-map en zip-naam).
	 */
	const REPO = 'sloephuren-booking';

	/**
	 * Hoe lang het release-antwoord gecachet wordt (seconden).
	 */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Plugin-basename, bijv. sloephuren-booking/sloephuren-booking.php.
	 *
	 * @var string
	 */
	protected $basename;

	/**
	 * Plugin-slug (mapnaam).
	 *
	 * @var string
	 */
	protected $slug;

	/**
	 * Huidige geïnstalleerde versie.
	 *
	 * @var string
	 */
	protected $version;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->basename = plugin_basename( SHB_PLUGIN_FILE );
		$this->slug     = dirname( $this->basename );
		$this->version  = SHB_VERSION;

		// Update-check injecteren.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		// Detailvenster ("Details bekijken") vullen.
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		// Na installatie de map correct hernoemen.
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		// "Controleer op updates"-link in de pluginregel.
		add_filter( 'plugin_action_links_' . $this->basename, array( $this, 'action_links' ) );
		// Handmatige check afhandelen.
		add_action( 'admin_init', array( $this, 'handle_manual_check' ) );
		// Resultaat van de handmatige check tonen.
		add_action( 'admin_notices', array( $this, 'manual_check_notice' ) );
	}

	/**
	 * Melding na een handmatige update-check.
	 */
	public function manual_check_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['shb_updates'] ) ? sanitize_key( wp_unslash( $_GET['shb_updates'] ) ) : '';
		if ( ! $state ) {
			return;
		}
		if ( 'update' === $state ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__( 'Sloephuren Booking: er is een nieuwe versie beschikbaar. Werk bij via het plugins-overzicht.', 'sloephuren-booking' )
			);
		} elseif ( 'current' === $state ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Sloephuren Booking is up-to-date.', 'sloephuren-booking' )
			);
		}
	}

	/**
	 * Laatste release ophalen van de GitHub API (met cache).
	 *
	 * @param bool $force Cache negeren.
	 * @return array|null Release-data of null.
	 */
	protected function get_latest_release( $force = false ) {
		$cache_key = 'shb_gh_release';

		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : null;
			}
		}

		$url      = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', self::OWNER, self::REPO );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'SloephurenBooking-Updater',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Kort cachen om de API niet plat te bellen bij fouten.
			set_transient( $cache_key, 'none', 30 * MINUTE_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['tag_name'] ) ) {
			set_transient( $cache_key, 'none', 30 * MINUTE_IN_SECONDS );
			return null;
		}

		// Zip-asset zoeken (de release-workflow uploadt sloephuren-booking.zip).
		$package = '';
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && substr( $asset['name'], -4 ) === '.zip' ) {
					$package = $asset['browser_download_url'];
					break;
				}
			}
		}
		// Fallback: door GitHub gegenereerde source-zip.
		if ( '' === $package && ! empty( $data['zipball_url'] ) ) {
			$package = $data['zipball_url'];
		}

		$release = array(
			'version'      => ltrim( $data['tag_name'], 'v' ),
			'tag'          => $data['tag_name'],
			'package'      => $package,
			'html_url'     => isset( $data['html_url'] ) ? $data['html_url'] : '',
			'body'         => isset( $data['body'] ) ? $data['body'] : '',
			'published_at' => isset( $data['published_at'] ) ? $data['published_at'] : '',
		);

		set_transient( $cache_key, $release, self::CACHE_TTL );
		return $release;
	}

	/**
	 * Update injecteren in de update-transient wanneer er een nieuwere versie is.
	 *
	 * @param object $transient Update-transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release || empty( $release['package'] ) ) {
			return $transient;
		}

		if ( version_compare( $release['version'], $this->version, '>' ) ) {
			$item = array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $release['version'],
				'url'         => $release['html_url'],
				'package'     => $release['package'],
			);
			$transient->response[ $this->basename ] = (object) $item;
		} else {
			// Geen update: netjes in no_update zetten zodat WP dit weet.
			$transient->no_update[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $this->version,
				'url'         => sprintf( 'https://github.com/%s/%s', self::OWNER, self::REPO ),
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Informatie voor het "Details bekijken"-venster.
	 *
	 * @param false|object|array $result Bestaand resultaat.
	 * @param string             $action API-actie.
	 * @param object             $args   Argumenten.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$info = array(
			'name'          => 'Sloephuren Booking',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://vilpy.nl">Studio Vilpy</a>',
			'homepage'      => sprintf( 'https://github.com/%s/%s', self::OWNER, self::REPO ),
			'download_link' => $release['package'],
			'trigger'       => $release['package'],
			'sections'      => array(
				'changelog' => $release['body'] ? nl2br( esc_html( $release['body'] ) ) : esc_html__( 'Zie GitHub voor de release-notities.', 'sloephuren-booking' ),
			),
		);

		return (object) $info;
	}

	/**
	 * De uitgepakte bronmap hernoemen naar de juiste plugin-slug.
	 *
	 * GitHub-zips pakken soms uit naar een map met versie/hash in de naam;
	 * WordPress verwacht exact de plugin-slug als mapnaam.
	 *
	 * @param string      $source        Uitgepakte bronmap.
	 * @param string      $remote_source Tijdelijke map.
	 * @param WP_Upgrader $upgrader       Upgrader-instantie.
	 * @param array       $hook_extra     Extra context.
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		// Alleen voor deze plugin.
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * "Controleer op updates"-link toevoegen aan de pluginregel.
	 *
	 * @param array $links Bestaande actielinks.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = wp_nonce_url(
			add_query_arg(
				array( 'shb_check_updates' => 1 ),
				admin_url( 'plugins.php' )
			),
			'shb_check_updates'
		);
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Controleer op updates', 'sloephuren-booking' ) . '</a>';
		return $links;
	}

	/**
	 * Handmatige update-check afhandelen (wist cache en forceert een check).
	 */
	public function handle_manual_check() {
		if ( empty( $_GET['shb_check_updates'] ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'shb_check_updates' );

		delete_transient( 'shb_gh_release' );
		$release = $this->get_latest_release( true );
		delete_site_transient( 'update_plugins' );

		$has_update = $release && version_compare( $release['version'], $this->version, '>' );
		$msg        = $has_update ? 'update' : 'current';

		wp_safe_redirect( add_query_arg( 'shb_updates', $msg, admin_url( 'plugins.php' ) ) );
		exit;
	}
}
