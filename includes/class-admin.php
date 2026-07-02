<?php
/**
 * Admin: menu, boekingenoverzicht met filters en beheerschermen voor
 * sloep-types, pakketten, tijdsloten en instellingen.
 *
 * @package SloephurenBooking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SHB_Admin
 */
class SHB_Admin {

	/**
	 * Singleton.
	 *
	 * @var SHB_Admin|null
	 */
	protected static $instance = null;

	/**
	 * Vereiste capability voor het beheer.
	 */
	const CAP = 'manage_options';

	/**
	 * Instantie ophalen.
	 *
	 * @return SHB_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks.
	 */
	protected function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Menu en submenu's registreren.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Sloephuren Boekingen', 'sloephuren-booking' ),
			__( 'Sloephuren', 'sloephuren-booking' ),
			self::CAP,
			'shb-bookings',
			array( $this, 'page_bookings' ),
			'dashicons-tickets-alt',
			26
		);

		add_submenu_page( 'shb-bookings', __( 'Boekingen', 'sloephuren-booking' ), __( 'Boekingen', 'sloephuren-booking' ), self::CAP, 'shb-bookings', array( $this, 'page_bookings' ) );
		add_submenu_page( 'shb-bookings', __( 'Beschikbaarheid', 'sloephuren-booking' ), __( 'Beschikbaarheid', 'sloephuren-booking' ), self::CAP, 'shb-availability', array( $this, 'page_availability' ) );
		add_submenu_page( 'shb-bookings', __( 'Sloep-types', 'sloephuren-booking' ), __( 'Sloep-types', 'sloephuren-booking' ), self::CAP, 'shb-boat-types', array( $this, 'page_boat_types' ) );
		add_submenu_page( 'shb-bookings', __( 'Pakketten', 'sloephuren-booking' ), __( 'Pakketten', 'sloephuren-booking' ), self::CAP, 'shb-products', array( $this, 'page_products' ) );
		add_submenu_page( 'shb-bookings', __( 'Tijdsloten', 'sloephuren-booking' ), __( 'Tijdsloten', 'sloephuren-booking' ), self::CAP, 'shb-timeslots', array( $this, 'page_timeslots' ) );
		add_submenu_page( 'shb-bookings', __( 'Instellingen', 'sloephuren-booking' ), __( 'Instellingen', 'sloephuren-booking' ), self::CAP, 'shb-settings', array( $this, 'page_settings' ) );
	}

	/**
	 * Kleine admin-styling.
	 *
	 * @param string $hook Huidige adminpagina.
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, 'shb-' ) ) {
			return;
		}
		$css = '.shb-admin-form label{display:block;margin:10px 0 4px;font-weight:600;}'
			. '.shb-admin-form input[type=text],.shb-admin-form input[type=number],.shb-admin-form input[type=time],.shb-admin-form input[type=email],.shb-admin-form select{min-width:280px;}'
			. '.shb-status{display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:600;}'
			. '.shb-status.paid{background:#d6f0e0;color:#1f7a4d;}'
			. '.shb-status.pending_payment{background:#fff2cc;color:#8a6d00;}'
			. '.shb-status.failed,.shb-status.expired,.shb-status.cancelled{background:#f6d6d6;color:#a12b2b;}'
			. '.shb-inline-form{display:inline;}'
			// Beschikbaarheidskalender (mobiel-vriendelijk: grote tikbare cellen).
			. '.shb-avail-wrap{max-width:560px;}'
			. '.shb-chips{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0;}'
			. '.shb-chip{display:inline-block;padding:9px 16px;border-radius:999px;border:1px solid #ccd0d4;background:#fff;text-decoration:none;color:#1d2327;font-weight:600;font-size:13px;}'
			. '.shb-chip.active{background:#15324F;border-color:#15324F;color:#fff;}'
			. '.shb-avail-head{display:flex;align-items:center;justify-content:space-between;margin:14px 0 10px;}'
			. '.shb-avail-head strong{font-size:17px;text-transform:uppercase;letter-spacing:.04em;}'
			. '.shb-avail-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;}'
			. '.shb-avail-wd{text-align:center;font-size:11px;color:#787c82;padding:4px 0;text-transform:uppercase;}'
			. '.shb-avail-grid form{margin:0;}'
			. '.shb-avail-btn{width:100%;min-height:48px;border-radius:8px;border:1px solid #dcdcde;background:#fff;cursor:pointer;font-size:14px;font-weight:600;color:#1d2327;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:2px;padding:4px 2px;}'
			. '.shb-avail-btn:hover{border-color:#15324F;}'
			. '.shb-avail-day.is-past{min-height:48px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#c3c4c7;}'
			. '.shb-avail-btn.is-blocked{background:#fbeaea;border-color:#e5b3b3;color:#a12b2b;text-decoration:line-through;}'
			. '.shb-avail-btn.is-partly{background:#fdf3e0;border-color:#e8d5a9;}'
			. '.shb-bkcount{font-size:10px;font-weight:700;color:#fff;background:#2271b1;border-radius:8px;padding:0 6px;line-height:15px;}'
			. '.shb-avail-legend{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:#50575e;margin-top:12px;}'
			. '.shb-avail-legend span{display:inline-flex;align-items:center;gap:5px;}'
			. '.shb-dot{width:12px;height:12px;border-radius:4px;display:inline-block;border:1px solid #dcdcde;}'
			. '.shb-dot.blocked{background:#fbeaea;border-color:#e5b3b3;}'
			. '.shb-dot.partly{background:#fdf3e0;border-color:#e8d5a9;}'
			. '@media(max-width:480px){.shb-avail-btn{min-height:44px;font-size:13px;}}';
		wp_add_inline_style( 'common', $css );
	}

	/* --------------------------------------------------------------------- */
	/* POST-afhandeling                                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * Alle formulierverwerking op de beheerschermen.
	 */
	public function handle_post() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( empty( $_POST['shb_action'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['shb_action'] ) );

		switch ( $action ) {
			case 'save_boat_type':
				$this->save_boat_type();
				break;
			case 'delete_boat_type':
				$this->delete_entity( 'boat_type', 'shb-boat-types' );
				break;
			case 'save_product':
				$this->save_product();
				break;
			case 'delete_product':
				$this->delete_entity( 'product', 'shb-products' );
				break;
			case 'save_timeslot':
				$this->save_timeslot();
				break;
			case 'delete_timeslot':
				$this->delete_entity( 'timeslot', 'shb-timeslots' );
				break;
			case 'save_settings':
				$this->save_settings();
				break;
			case 'update_booking_status':
				$this->update_booking_status();
				break;
			case 'toggle_block_day':
				$this->toggle_block_day();
				break;
			case 'add_block':
				$this->add_block();
				break;
			case 'delete_block':
				$this->delete_block();
				break;
		}
	}

	/**
	 * Nonce controleren of sterven.
	 *
	 * @param string $action Nonce-actie.
	 */
	protected function check_nonce( $action ) {
		check_admin_referer( $action );
	}

	/**
	 * Redirect met melding.
	 *
	 * @param string $page    Adminpagina-slug.
	 * @param string $message Meldingssleutel.
	 */
	protected function redirect( $page, $message = 'saved' ) {
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'shb_msg' => $message ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Sloep-type opslaan.
	 */
	protected function save_boat_type() {
		$this->check_nonce( 'shb_save_boat_type' );
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		SHB_Bookings::save_boat_type(
			array(
				'name'        => wp_unslash( $_POST['name'] ?? '' ),
				'stock'       => $_POST['stock'] ?? 1,
				'max_persons' => $_POST['max_persons'] ?? 8,
				'active'      => $_POST['active'] ?? 0,
				'sort_order'  => $_POST['sort_order'] ?? 0,
			),
			$id
		);
		$this->redirect( 'shb-boat-types' );
	}

	/**
	 * Pakket opslaan.
	 */
	protected function save_product() {
		$this->check_nonce( 'shb_save_product' );
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		SHB_Bookings::save_product(
			array(
				'name'       => wp_unslash( $_POST['name'] ?? '' ),
				'price'      => wp_unslash( $_POST['price'] ?? 0 ),
				'active'     => $_POST['active'] ?? 0,
				'sort_order' => $_POST['sort_order'] ?? 0,
			),
			$id
		);
		$this->redirect( 'shb-products' );
	}

	/**
	 * Tijdslot opslaan.
	 */
	protected function save_timeslot() {
		$this->check_nonce( 'shb_save_timeslot' );
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		SHB_Bookings::save_timeslot(
			array(
				'product_id' => $_POST['product_id'] ?? 0,
				'label'      => wp_unslash( $_POST['label'] ?? '' ),
				'start_time' => wp_unslash( $_POST['start_time'] ?? '10:00' ),
				'end_time'   => wp_unslash( $_POST['end_time'] ?? '18:00' ),
				'active'     => $_POST['active'] ?? 0,
				'sort_order' => $_POST['sort_order'] ?? 0,
			),
			$id
		);
		$this->redirect( 'shb-timeslots' );
	}

	/**
	 * Generieke verwijder-afhandeling.
	 *
	 * @param string $entity Type ('boat_type', 'product', 'timeslot').
	 * @param string $page   Adminpagina.
	 */
	protected function delete_entity( $entity, $page ) {
		$this->check_nonce( 'shb_delete_' . $entity );
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id ) {
			$method = 'delete_' . $entity;
			SHB_Bookings::$method( $id );
		}
		$this->redirect( $page, 'deleted' );
	}

	/**
	 * Instellingen opslaan.
	 */
	protected function save_settings() {
		$this->check_nonce( 'shb_save_settings' );

		update_option( 'shb_pending_minutes', max( 1, (int) ( $_POST['pending_minutes'] ?? 15 ) ) );

		$provider = sanitize_key( wp_unslash( $_POST['payment_provider'] ?? 'mock' ) );
		update_option( 'shb_payment_provider', in_array( $provider, array( 'mock', 'mollie' ), true ) ? $provider : 'mock' );

		update_option( 'shb_mollie_api_key', sanitize_text_field( wp_unslash( $_POST['mollie_api_key'] ?? '' ) ) );

		$admin_email = sanitize_email( wp_unslash( $_POST['admin_email'] ?? '' ) );
		update_option( 'shb_admin_email', is_email( $admin_email ) ? $admin_email : get_option( 'admin_email' ) );

		update_option( 'shb_terms_url', esc_url_raw( wp_unslash( $_POST['terms_url'] ?? '' ) ) );

		update_option( 'shb_sitewide', empty( $_POST['sitewide'] ) ? 0 : 1 );

		$this->redirect( 'shb-settings' );
	}

	/**
	 * Boekingstatus handmatig aanpassen vanuit de admin.
	 */
	protected function update_booking_status() {
		$this->check_nonce( 'shb_update_booking_status' );
		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		if ( $id && in_array( $status, SHB_Bookings::STATUSES, true ) ) {
			$extra = ( 'paid' === $status ) ? array( 'paid_at' => current_time( 'mysql' ) ) : array();
			SHB_Bookings::update_status( $id, $status, $extra );
		}
		$this->redirect( 'shb-bookings', 'updated' );
	}

	/* --------------------------------------------------------------------- */
	/* Beschikbaarheid: blokkade-handlers                                    */
	/* --------------------------------------------------------------------- */

	/**
	 * Datum saneren naar Y-m-d of leeg.
	 *
	 * @param mixed $value Ruwe invoer.
	 * @return string
	 */
	protected function clean_date( $value ) {
		$value = sanitize_text_field( wp_unslash( (string) $value ) );
		$d     = DateTime::createFromFormat( 'Y-m-d', $value );
		return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	/**
	 * Terug naar het beschikbaarheidsscherm met behoud van sloep + maand.
	 *
	 * @param string $msg  Meldingssleutel.
	 * @param int    $boat Sloep-scope.
	 * @param string $ym   Maand (Y-m).
	 */
	protected function redirect_availability( $msg, $boat, $ym ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'shb-availability',
					'boat'    => (int) $boat,
					'ym'      => preg_replace( '/[^0-9\-]/', '', $ym ),
					'shb_msg' => $msg,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Dag aantikken op de kalender: blokkade aan/uit voor de gekozen scope.
	 */
	protected function toggle_block_day() {
		$this->check_nonce( 'shb_toggle_block_day' );
		$date = $this->clean_date( $_POST['date'] ?? '' );
		$boat = isset( $_POST['boat'] ) ? (int) $_POST['boat'] : 0;
		$ym   = sanitize_text_field( wp_unslash( $_POST['ym'] ?? '' ) );

		if ( ! $date ) {
			$this->redirect_availability( 'block_invalid', $boat, $ym );
		}

		// Bestaande eendaagse blokkade voor exact deze scope? Dan uitzetten.
		$existing = SHB_Bookings::find_single_day_block( $date, $boat );
		if ( $existing ) {
			SHB_Bookings::delete_block( $existing->id );
			$this->redirect_availability( 'day_unblocked', $boat, $ym );
		}

		// Valt de dag al onder een andere (periode- of alle-sloepen-)blokkade?
		$covered = false;
		foreach ( SHB_Bookings::get_blocks_between( $date, $date ) as $bl ) {
			$scope_match = ( 0 === $boat )
				? ( 0 === (int) $bl->boat_type_id )
				: ( 0 === (int) $bl->boat_type_id || (int) $bl->boat_type_id === $boat );
			if ( $scope_match ) {
				$covered = true;
				break;
			}
		}
		if ( $covered ) {
			$this->redirect_availability( 'block_covered', $boat, $ym );
		}

		SHB_Bookings::add_block(
			array(
				'boat_type_id' => $boat,
				'timeslot_id'  => 0,
				'date_from'    => $date,
				'date_to'      => $date,
				'note'         => '',
			)
		);
		$this->redirect_availability( 'day_blocked', $boat, $ym );
	}

	/**
	 * Periode blokkeren via het formulier.
	 */
	protected function add_block() {
		$this->check_nonce( 'shb_add_block' );
		$boat = isset( $_POST['boat_type_id'] ) ? (int) $_POST['boat_type_id'] : 0;
		$from = $this->clean_date( $_POST['date_from'] ?? '' );
		$to   = $this->clean_date( $_POST['date_to'] ?? '' );
		$note = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );
		$ym   = sanitize_text_field( wp_unslash( $_POST['ym'] ?? '' ) );

		if ( ! $from ) {
			$this->redirect_availability( 'block_invalid', $boat, $ym );
		}
		if ( ! $to ) {
			$to = $from;
		}
		if ( $to < $from ) {
			list( $from, $to ) = array( $to, $from );
		}

		SHB_Bookings::add_block(
			array(
				'boat_type_id' => $boat,
				'timeslot_id'  => 0,
				'date_from'    => $from,
				'date_to'      => $to,
				'note'         => $note,
			)
		);
		$this->redirect_availability( 'block_added', $boat, $ym );
	}

	/**
	 * Blokkade verwijderen uit de lijst.
	 */
	protected function delete_block() {
		$this->check_nonce( 'shb_delete_block' );
		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$boat = isset( $_POST['boat'] ) ? (int) $_POST['boat'] : 0;
		$ym   = sanitize_text_field( wp_unslash( $_POST['ym'] ?? '' ) );
		if ( $id ) {
			SHB_Bookings::delete_block( $id );
		}
		$this->redirect_availability( 'block_deleted', $boat, $ym );
	}

	/* --------------------------------------------------------------------- */
	/* Meldingen                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Melding tonen op basis van query-arg.
	 */
	protected function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['shb_msg'] ) ? sanitize_key( wp_unslash( $_GET['shb_msg'] ) ) : '';
		if ( ! $msg ) {
			return;
		}
		$map = array(
			'saved'         => __( 'Opgeslagen.', 'sloephuren-booking' ),
			'deleted'       => __( 'Verwijderd.', 'sloephuren-booking' ),
			'updated'       => __( 'Bijgewerkt.', 'sloephuren-booking' ),
			'day_blocked'   => __( 'Dag geblokkeerd voor verhuur.', 'sloephuren-booking' ),
			'day_unblocked' => __( 'Dag weer vrijgegeven voor verhuur.', 'sloephuren-booking' ),
			'block_added'   => __( 'Periode geblokkeerd.', 'sloephuren-booking' ),
			'block_deleted' => __( 'Blokkade verwijderd.', 'sloephuren-booking' ),
		);
		$warn = array(
			'block_covered' => __( 'Deze dag valt onder een bestaande periode- of alle-sloepen-blokkade. Verwijder die blokkade onderaan de pagina.', 'sloephuren-booking' ),
			'block_invalid' => __( 'Vul een geldige datum in.', 'sloephuren-booking' ),
		);
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $map[ $msg ] ) );
		} elseif ( isset( $warn[ $msg ] ) ) {
			printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html( $warn[ $msg ] ) );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Scherm: Boekingen                                                     */
	/* --------------------------------------------------------------------- */

	/**
	 * Boekingenoverzicht met filters.
	 */
	public function page_bookings() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 30;
		$data     = SHB_Bookings::query_bookings(
			array(
				'status'    => $status,
				'date_from' => $date_from,
				'date_to'   => $date_to,
				'search'    => $search,
				'per_page'  => $per_page,
				'paged'     => $paged,
			)
		);

		$total_pages = (int) ceil( $data['total'] / $per_page );

		// Lookup-maps voor leesbare namen.
		$products  = $this->id_map( SHB_Bookings::get_products() );
		$boats     = $this->id_map( SHB_Bookings::get_boat_types() );
		$timeslots = $this->id_map( SHB_Bookings::get_timeslots() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sloephuren Boekingen', 'sloephuren-booking' ); ?></h1>
			<?php $this->notice(); ?>

			<form method="get" style="margin:16px 0;background:#fff;padding:12px 16px;border:1px solid #ddd;">
				<input type="hidden" name="page" value="shb-bookings">
				<label style="margin-right:8px;">
					<?php esc_html_e( 'Status', 'sloephuren-booking' ); ?>
					<select name="status">
						<option value=""><?php esc_html_e( 'Alle', 'sloephuren-booking' ); ?></option>
						<?php foreach ( SHB_Bookings::STATUSES as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $this->status_label( $s ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label style="margin-right:8px;">
					<?php esc_html_e( 'Van', 'sloephuren-booking' ); ?>
					<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
				</label>
				<label style="margin-right:8px;">
					<?php esc_html_e( 'Tot', 'sloephuren-booking' ); ?>
					<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
				</label>
				<label style="margin-right:8px;">
					<?php esc_html_e( 'Zoek', 'sloephuren-booking' ); ?>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'naam, e-mail, nr.', 'sloephuren-booking' ); ?>">
				</label>
				<button class="button button-primary"><?php esc_html_e( 'Filteren', 'sloephuren-booking' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=shb-bookings' ) ); ?>"><?php esc_html_e( 'Reset', 'sloephuren-booking' ); ?></a>
			</form>

			<p><?php echo esc_html( sprintf( /* translators: %d: aantal */ __( '%d boeking(en) gevonden.', 'sloephuren-booking' ), (int) $data['total'] ) ); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Nr.', 'sloephuren-booking' ); ?></th>
						<th><?php esc_html_e( 'Datum', 'sloephuren-booking' ); ?></th>
						<th><?php esc_html_e( 'Tijdslot', 'sloephuren-booking' ); ?></th>
						<th><?php esc_html_e( 'Sloep', 'sloephuren-booking' ); ?></th>
						<th><?php esc_html_e( 'Pakket', 'sloephuren-booking' ); ?></th>
						<th><?php esc_html_e( 'Klant', 'sloephuren-booking' ); ?></th>
						<th><?php esc_html_e( 'Telefoon', 'sloephuren-booking' ); ?></th>
						<th><?php esc_html_e( 'E-mail', 'sloephuren-booking' ); ?></th>
						<th><?php esc_html_e( 'Pers.', 'sloephuren-booking' ); ?></th>
						<th><?php esc_html_e( 'Bedrag', 'sloephuren-booking' ); ?></th>
						<th><?php esc_html_e( 'Status', 'sloephuren-booking' ); ?></th>
						<th><?php esc_html_e( 'Actie', 'sloephuren-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $data['items'] ) ) : ?>
					<tr><td colspan="12"><?php esc_html_e( 'Geen boekingen gevonden.', 'sloephuren-booking' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $data['items'] as $b ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $b->booking_number ); ?></strong></td>
							<td><?php echo esc_html( date_i18n( 'd-m-Y', strtotime( $b->booking_date ) ) ); ?></td>
							<td><?php echo esc_html( isset( $timeslots[ $b->timeslot_id ] ) ? $timeslots[ $b->timeslot_id ]->label : '-' ); ?></td>
							<td><?php echo esc_html( isset( $boats[ $b->boat_type_id ] ) ? $boats[ $b->boat_type_id ]->name : '-' ); ?></td>
							<td><?php echo esc_html( isset( $products[ $b->product_id ] ) ? $products[ $b->product_id ]->name : '-' ); ?></td>
							<td><?php echo esc_html( $b->customer_name ); ?></td>
							<td><?php echo esc_html( $b->customer_phone ); ?></td>
							<td><a href="mailto:<?php echo esc_attr( $b->customer_email ); ?>"><?php echo esc_html( $b->customer_email ); ?></a></td>
							<td><?php echo esc_html( $b->num_persons ); ?></td>
							<td>&euro; <?php echo esc_html( number_format_i18n( (float) $b->amount, 2 ) ); ?></td>
							<td><span class="shb-status <?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( $this->status_label( $b->status ) ); ?></span></td>
							<td>
								<form method="post" class="shb-inline-form">
									<?php wp_nonce_field( 'shb_update_booking_status' ); ?>
									<input type="hidden" name="shb_action" value="update_booking_status">
									<input type="hidden" name="id" value="<?php echo esc_attr( $b->id ); ?>">
									<select name="status" onchange="this.form.submit()">
										<?php foreach ( SHB_Bookings::STATUSES as $s ) : ?>
											<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $b->status, $s ); ?>><?php echo esc_html( $this->status_label( $s ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					$base = add_query_arg(
						array(
							'page'      => 'shb-bookings',
							'status'    => $status,
							'date_from' => $date_from,
							'date_to'   => $date_to,
							's'         => $search,
							'paged'     => '%#%',
						),
						admin_url( 'admin.php' )
					);
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => $base,
								'format'  => '',
								'current' => $paged,
								'total'   => $total_pages,
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Scherm: Beschikbaarheid                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Beschikbaarheidskalender: dagen aantikken om ze te blokkeren of vrij
	 * te geven, plus een formulier voor langere periodes. Gebouwd met
	 * gewone formulieren (geen JS) zodat het overal werkt, ook mobiel.
	 */
	public function page_availability() {
		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$boat_scope = isset( $_GET['boat'] ) ? (int) $_GET['boat'] : 0;
		$ym         = isset( $_GET['ym'] ) ? sanitize_text_field( wp_unslash( $_GET['ym'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $ym ) ) {
			$ym = gmdate( 'Y-m', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		}
		list( $year, $month ) = array_map( 'intval', explode( '-', $ym ) );

		$first = sprintf( '%04d-%02d-01', $year, $month );
		$dim   = (int) gmdate( 't', strtotime( $first . ' 12:00:00' ) );
		$last  = sprintf( '%04d-%02d-%02d', $year, $month, $dim );
		$today = gmdate( 'Y-m-d', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		$boats     = SHB_Bookings::get_boat_types( true );
		$boat_map  = $this->id_map( SHB_Bookings::get_boat_types() );
		$blocks    = SHB_Bookings::get_blocks_between( $first, $last );
		$all_rows  = SHB_Bookings::get_blocks();

		// Betaalde boekingen per dag (voor het telbolletje).
		$btable = SHB_Install::table( 'bookings' );
		if ( $boat_scope ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT booking_date d, COUNT(*) c FROM {$btable} WHERE booking_date BETWEEN %s AND %s AND status = 'paid' AND boat_type_id = %d GROUP BY booking_date", $first, $last, $boat_scope ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT booking_date d, COUNT(*) c FROM {$btable} WHERE booking_date BETWEEN %s AND %s AND status = 'paid' GROUP BY booking_date", $first, $last ) );
		}
		$booked_count = array();
		foreach ( $rows as $r ) {
			$booked_count[ $r->d ] = (int) $r->c;
		}

		// Maandnavigatie.
		$prev_ym = gmdate( 'Y-m', mktime( 12, 0, 0, $month - 1, 1, $year ) );
		$next_ym = gmdate( 'Y-m', mktime( 12, 0, 0, $month + 1, 1, $year ) );
		$nav_url = function ( $to_ym, $to_boat ) {
			return add_query_arg(
				array(
					'page' => 'shb-availability',
					'boat' => (int) $to_boat,
					'ym'   => $to_ym,
				),
				admin_url( 'admin.php' )
			);
		};

		$months_nl = array( 1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december' );
		$lead      = ( (int) gmdate( 'N', strtotime( $first . ' 12:00:00' ) ) ) - 1; // 0 = maandag.
		?>
		<div class="wrap shb-avail-wrap">
			<h1><?php esc_html_e( 'Beschikbaarheid', 'sloephuren-booking' ); ?></h1>
			<?php $this->notice(); ?>
			<p><?php esc_html_e( 'Tik op een dag om die te blokkeren voor verhuur, of tik nogmaals om de dag weer vrij te geven. Kies eerst voor welke sloep het geldt.', 'sloephuren-booking' ); ?></p>

			<div class="shb-chips">
				<a class="shb-chip <?php echo 0 === $boat_scope ? 'active' : ''; ?>" href="<?php echo esc_url( $nav_url( $ym, 0 ) ); ?>"><?php esc_html_e( 'Alle sloepen', 'sloephuren-booking' ); ?></a>
				<?php foreach ( $boats as $b ) : ?>
					<a class="shb-chip <?php echo (int) $b->id === $boat_scope ? 'active' : ''; ?>" href="<?php echo esc_url( $nav_url( $ym, $b->id ) ); ?>"><?php echo esc_html( $b->name ); ?></a>
				<?php endforeach; ?>
			</div>

			<div class="shb-avail-head">
				<a class="button" href="<?php echo esc_url( $nav_url( $prev_ym, $boat_scope ) ); ?>">&lsaquo;</a>
				<strong><?php echo esc_html( $months_nl[ $month ] . ' ' . $year ); ?></strong>
				<a class="button" href="<?php echo esc_url( $nav_url( $next_ym, $boat_scope ) ); ?>">&rsaquo;</a>
			</div>

			<div class="shb-avail-grid">
				<?php foreach ( array( 'ma', 'di', 'wo', 'do', 'vr', 'za', 'zo' ) as $wd ) : ?>
					<div class="shb-avail-wd"><?php echo esc_html( $wd ); ?></div>
				<?php endforeach; ?>

				<?php for ( $i = 0; $i < $lead; $i++ ) : ?>
					<div></div>
				<?php endfor; ?>

				<?php
				for ( $d = 1; $d <= $dim; $d++ ) :
					$date = sprintf( '%04d-%02d-%02d', $year, $month, $d );

					// Blokkade-status voor de gekozen scope bepalen.
					$fully  = false;
					$partly = false;
					foreach ( $blocks as $bl ) {
						if ( $bl->date_from > $date || $bl->date_to < $date ) {
							continue;
						}
						$bl_boat = (int) $bl->boat_type_id;
						if ( 0 === $boat_scope ) {
							if ( 0 === $bl_boat ) {
								$fully = true;
							} else {
								$partly = true;
							}
						} elseif ( 0 === $bl_boat || $bl_boat === $boat_scope ) {
							$fully = true;
						}
					}

					$count = isset( $booked_count[ $date ] ) ? $booked_count[ $date ] : 0;

					if ( $date < $today ) :
						?>
						<div class="shb-avail-day is-past"><?php echo esc_html( $d ); ?></div>
						<?php
					else :
						$classes = 'shb-avail-btn' . ( $fully ? ' is-blocked' : ( $partly ? ' is-partly' : '' ) );
						$title   = $fully
							? __( 'Tik om deze dag weer vrij te geven', 'sloephuren-booking' )
							: __( 'Tik om deze dag te blokkeren', 'sloephuren-booking' );
						?>
						<form method="post">
							<?php wp_nonce_field( 'shb_toggle_block_day' ); ?>
							<input type="hidden" name="shb_action" value="toggle_block_day">
							<input type="hidden" name="date" value="<?php echo esc_attr( $date ); ?>">
							<input type="hidden" name="boat" value="<?php echo esc_attr( $boat_scope ); ?>">
							<input type="hidden" name="ym" value="<?php echo esc_attr( $ym ); ?>">
							<button type="submit" class="<?php echo esc_attr( $classes ); ?>" title="<?php echo esc_attr( $title ); ?>">
								<span><?php echo esc_html( $d ); ?></span>
								<?php if ( $count ) : ?>
									<span class="shb-bkcount"><?php echo esc_html( $count ); ?></span>
								<?php endif; ?>
							</button>
						</form>
						<?php
					endif;
				endfor;
				?>
			</div>

			<div class="shb-avail-legend">
				<span><span class="shb-dot blocked"></span> <?php esc_html_e( 'Geblokkeerd', 'sloephuren-booking' ); ?></span>
				<?php if ( 0 === $boat_scope ) : ?>
					<span><span class="shb-dot partly"></span> <?php esc_html_e( 'Deels geblokkeerd (specifieke sloep)', 'sloephuren-booking' ); ?></span>
				<?php endif; ?>
				<span><span class="shb-bkcount">2</span> <?php esc_html_e( 'Aantal betaalde boekingen', 'sloephuren-booking' ); ?></span>
			</div>

			<hr style="margin:24px 0;">

			<h2><?php esc_html_e( 'Periode blokkeren', 'sloephuren-booking' ); ?></h2>
			<form method="post" class="shb-admin-form">
				<?php wp_nonce_field( 'shb_add_block' ); ?>
				<input type="hidden" name="shb_action" value="add_block">
				<input type="hidden" name="ym" value="<?php echo esc_attr( $ym ); ?>">
				<label><?php esc_html_e( 'Sloep', 'sloephuren-booking' ); ?></label>
				<select name="boat_type_id">
					<option value="0"><?php esc_html_e( 'Alle sloepen', 'sloephuren-booking' ); ?></option>
					<?php foreach ( $boats as $b ) : ?>
						<option value="<?php echo esc_attr( $b->id ); ?>" <?php selected( $boat_scope, (int) $b->id ); ?>><?php echo esc_html( $b->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<label><?php esc_html_e( 'Van', 'sloephuren-booking' ); ?></label>
				<input type="date" name="date_from" required>
				<label><?php esc_html_e( 'Tot en met', 'sloephuren-booking' ); ?></label>
				<input type="date" name="date_to">
				<label><?php esc_html_e( 'Notitie (optioneel)', 'sloephuren-booking' ); ?></label>
				<input type="text" name="note" placeholder="<?php esc_attr_e( 'bijv. onderhoud of vakantie', 'sloephuren-booking' ); ?>">
				<p><button class="button button-primary"><?php esc_html_e( 'Blokkeer periode', 'sloephuren-booking' ); ?></button></p>
			</form>

			<h2><?php esc_html_e( 'Actieve blokkades', 'sloephuren-booking' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e( 'Periode', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Sloep', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Notitie', 'sloephuren-booking' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Actie', 'sloephuren-booking' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $all_rows ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Nog geen blokkades. Alle dagen zijn boekbaar.', 'sloephuren-booking' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $all_rows as $bl ) : ?>
						<tr>
							<td>
								<?php
								$from_txt = date_i18n( 'd-m-Y', strtotime( $bl->date_from ) );
								$to_txt   = date_i18n( 'd-m-Y', strtotime( $bl->date_to ) );
								echo esc_html( $bl->date_from === $bl->date_to ? $from_txt : $from_txt . ' t/m ' . $to_txt );
								?>
							</td>
							<td><?php echo esc_html( (int) $bl->boat_type_id ? ( isset( $boat_map[ (int) $bl->boat_type_id ] ) ? $boat_map[ (int) $bl->boat_type_id ]->name : '#' . (int) $bl->boat_type_id ) : __( 'Alle sloepen', 'sloephuren-booking' ) ); ?></td>
							<td><?php echo esc_html( $bl->note ); ?></td>
							<td>
								<form method="post" class="shb-inline-form">
									<?php wp_nonce_field( 'shb_delete_block' ); ?>
									<input type="hidden" name="shb_action" value="delete_block">
									<input type="hidden" name="id" value="<?php echo esc_attr( $bl->id ); ?>">
									<input type="hidden" name="boat" value="<?php echo esc_attr( $boat_scope ); ?>">
									<input type="hidden" name="ym" value="<?php echo esc_attr( $ym ); ?>">
									<button class="button button-small button-link-delete"><?php esc_html_e( 'Verwijder', 'sloephuren-booking' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Scherm: Sloep-types                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Beheer van sloep-types.
	 */
	public function page_boat_types() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$editing = $edit_id ? SHB_Bookings::get_boat_type( $edit_id ) : null;
		$rows    = SHB_Bookings::get_boat_types();
		?>
		<div class="wrap shb-admin-form">
			<h1><?php esc_html_e( 'Sloep-types', 'sloephuren-booking' ); ?></h1>
			<?php $this->notice(); ?>

			<table class="wp-list-table widefat fixed striped" style="max-width:820px;">
				<thead><tr>
					<th><?php esc_html_e( 'Naam', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Voorraad', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Max personen', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Actief', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Actie', 'sloephuren-booking' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r->name ); ?></strong></td>
						<td><?php echo esc_html( $r->stock ); ?></td>
						<td><?php echo esc_html( $r->max_persons ); ?></td>
						<td><?php echo $r->active ? '&#10003;' : '&mdash;'; ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=shb-boat-types&edit=' . $r->id ) ); ?>"><?php esc_html_e( 'Bewerk', 'sloephuren-booking' ); ?></a>
							<?php $this->delete_button( 'boat_type', $r->id ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo $editing ? esc_html__( 'Sloep-type bewerken', 'sloephuren-booking' ) : esc_html__( 'Nieuw sloep-type', 'sloephuren-booking' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'shb_save_boat_type' ); ?>
				<input type="hidden" name="shb_action" value="save_boat_type">
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing ? $editing->id : 0 ); ?>">
				<label><?php esc_html_e( 'Naam', 'sloephuren-booking' ); ?></label>
				<input type="text" name="name" value="<?php echo esc_attr( $editing ? $editing->name : '' ); ?>" required>
				<label><?php esc_html_e( 'Voorraad', 'sloephuren-booking' ); ?></label>
				<input type="number" name="stock" min="0" value="<?php echo esc_attr( $editing ? $editing->stock : 1 ); ?>" required>
				<label><?php esc_html_e( 'Max personen', 'sloephuren-booking' ); ?></label>
				<input type="number" name="max_persons" min="1" value="<?php echo esc_attr( $editing ? $editing->max_persons : 8 ); ?>" required>
				<label><?php esc_html_e( 'Sorteervolgorde', 'sloephuren-booking' ); ?></label>
				<input type="number" name="sort_order" value="<?php echo esc_attr( $editing ? $editing->sort_order : 0 ); ?>">
				<label><input type="checkbox" name="active" value="1" <?php checked( $editing ? $editing->active : 1, 1 ); ?>> <?php esc_html_e( 'Actief', 'sloephuren-booking' ); ?></label>
				<p><button class="button button-primary"><?php esc_html_e( 'Opslaan', 'sloephuren-booking' ); ?></button>
				<?php if ( $editing ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=shb-boat-types' ) ); ?>"><?php esc_html_e( 'Annuleren', 'sloephuren-booking' ); ?></a><?php endif; ?></p>
			</form>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Scherm: Pakketten                                                     */
	/* --------------------------------------------------------------------- */

	/**
	 * Beheer van pakketten/producten.
	 */
	public function page_products() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$editing = $edit_id ? SHB_Bookings::get_product( $edit_id ) : null;
		$rows    = SHB_Bookings::get_products();
		?>
		<div class="wrap shb-admin-form">
			<h1><?php esc_html_e( 'Pakketten', 'sloephuren-booking' ); ?></h1>
			<?php $this->notice(); ?>

			<table class="wp-list-table widefat fixed striped" style="max-width:720px;">
				<thead><tr>
					<th><?php esc_html_e( 'Naam', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Prijs', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Actief', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Actie', 'sloephuren-booking' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r->name ); ?></strong></td>
						<td>&euro; <?php echo esc_html( number_format_i18n( (float) $r->price, 2 ) ); ?></td>
						<td><?php echo $r->active ? '&#10003;' : '&mdash;'; ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=shb-products&edit=' . $r->id ) ); ?>"><?php esc_html_e( 'Bewerk', 'sloephuren-booking' ); ?></a>
							<?php $this->delete_button( 'product', $r->id ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo $editing ? esc_html__( 'Pakket bewerken', 'sloephuren-booking' ) : esc_html__( 'Nieuw pakket', 'sloephuren-booking' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'shb_save_product' ); ?>
				<input type="hidden" name="shb_action" value="save_product">
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing ? $editing->id : 0 ); ?>">
				<label><?php esc_html_e( 'Naam', 'sloephuren-booking' ); ?></label>
				<input type="text" name="name" value="<?php echo esc_attr( $editing ? $editing->name : '' ); ?>" required>
				<label><?php esc_html_e( 'Prijs (euro)', 'sloephuren-booking' ); ?></label>
				<input type="text" name="price" value="<?php echo esc_attr( $editing ? number_format( (float) $editing->price, 2, '.', '' ) : '' ); ?>" required>
				<label><?php esc_html_e( 'Sorteervolgorde', 'sloephuren-booking' ); ?></label>
				<input type="number" name="sort_order" value="<?php echo esc_attr( $editing ? $editing->sort_order : 0 ); ?>">
				<label><input type="checkbox" name="active" value="1" <?php checked( $editing ? $editing->active : 1, 1 ); ?>> <?php esc_html_e( 'Actief', 'sloephuren-booking' ); ?></label>
				<p><button class="button button-primary"><?php esc_html_e( 'Opslaan', 'sloephuren-booking' ); ?></button>
				<?php if ( $editing ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=shb-products' ) ); ?>"><?php esc_html_e( 'Annuleren', 'sloephuren-booking' ); ?></a><?php endif; ?></p>
			</form>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Scherm: Tijdsloten                                                    */
	/* --------------------------------------------------------------------- */

	/**
	 * Beheer van tijdsloten per pakket.
	 */
	public function page_timeslots() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id  = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$editing  = $edit_id ? SHB_Bookings::get_timeslot( $edit_id ) : null;
		$rows     = SHB_Bookings::get_timeslots();
		$products = $this->id_map( SHB_Bookings::get_products() );
		?>
		<div class="wrap shb-admin-form">
			<h1><?php esc_html_e( 'Tijdsloten', 'sloephuren-booking' ); ?></h1>
			<?php $this->notice(); ?>

			<table class="wp-list-table widefat fixed striped" style="max-width:900px;">
				<thead><tr>
					<th><?php esc_html_e( 'Pakket', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Label', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Start', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Eind', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Actief', 'sloephuren-booking' ); ?></th>
					<th><?php esc_html_e( 'Actie', 'sloephuren-booking' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( isset( $products[ $r->product_id ] ) ? $products[ $r->product_id ]->name : '-' ); ?></td>
						<td><strong><?php echo esc_html( $r->label ); ?></strong></td>
						<td><?php echo esc_html( substr( $r->start_time, 0, 5 ) ); ?></td>
						<td><?php echo esc_html( substr( $r->end_time, 0, 5 ) ); ?></td>
						<td><?php echo $r->active ? '&#10003;' : '&mdash;'; ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=shb-timeslots&edit=' . $r->id ) ); ?>"><?php esc_html_e( 'Bewerk', 'sloephuren-booking' ); ?></a>
							<?php $this->delete_button( 'timeslot', $r->id ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo $editing ? esc_html__( 'Tijdslot bewerken', 'sloephuren-booking' ) : esc_html__( 'Nieuw tijdslot', 'sloephuren-booking' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'shb_save_timeslot' ); ?>
				<input type="hidden" name="shb_action" value="save_timeslot">
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing ? $editing->id : 0 ); ?>">
				<label><?php esc_html_e( 'Pakket', 'sloephuren-booking' ); ?></label>
				<select name="product_id" required>
					<?php foreach ( SHB_Bookings::get_products() as $p ) : ?>
						<option value="<?php echo esc_attr( $p->id ); ?>" <?php selected( $editing ? $editing->product_id : 0, $p->id ); ?>><?php echo esc_html( $p->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<label><?php esc_html_e( 'Label', 'sloephuren-booking' ); ?></label>
				<input type="text" name="label" value="<?php echo esc_attr( $editing ? $editing->label : '' ); ?>" required>
				<label><?php esc_html_e( 'Starttijd', 'sloephuren-booking' ); ?></label>
				<input type="time" name="start_time" value="<?php echo esc_attr( $editing ? substr( $editing->start_time, 0, 5 ) : '10:00' ); ?>" required>
				<label><?php esc_html_e( 'Eindtijd', 'sloephuren-booking' ); ?></label>
				<input type="time" name="end_time" value="<?php echo esc_attr( $editing ? substr( $editing->end_time, 0, 5 ) : '18:00' ); ?>" required>
				<label><?php esc_html_e( 'Sorteervolgorde', 'sloephuren-booking' ); ?></label>
				<input type="number" name="sort_order" value="<?php echo esc_attr( $editing ? $editing->sort_order : 0 ); ?>">
				<label><input type="checkbox" name="active" value="1" <?php checked( $editing ? $editing->active : 1, 1 ); ?>> <?php esc_html_e( 'Actief', 'sloephuren-booking' ); ?></label>
				<p><button class="button button-primary"><?php esc_html_e( 'Opslaan', 'sloephuren-booking' ); ?></button>
				<?php if ( $editing ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=shb-timeslots' ) ); ?>"><?php esc_html_e( 'Annuleren', 'sloephuren-booking' ); ?></a><?php endif; ?></p>
			</form>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Scherm: Instellingen                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Instellingenscherm.
	 */
	public function page_settings() {
		$provider = get_option( 'shb_payment_provider', 'mock' );
		?>
		<div class="wrap shb-admin-form">
			<h1><?php esc_html_e( 'Instellingen', 'sloephuren-booking' ); ?></h1>
			<?php $this->notice(); ?>
			<form method="post">
				<?php wp_nonce_field( 'shb_save_settings' ); ?>
				<input type="hidden" name="shb_action" value="save_settings">

				<h2><?php esc_html_e( 'Betaling', 'sloephuren-booking' ); ?></h2>
				<label><?php esc_html_e( 'Betaalprovider', 'sloephuren-booking' ); ?></label>
				<select name="payment_provider">
					<option value="mock" <?php selected( $provider, 'mock' ); ?>><?php esc_html_e( 'Mock (testen zonder echte betaling)', 'sloephuren-booking' ); ?></option>
					<option value="mollie" <?php selected( $provider, 'mollie' ); ?>><?php esc_html_e( 'Mollie (iDEAL)', 'sloephuren-booking' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Zonder ingevulde Mollie-sleutel wordt automatisch de mock-provider gebruikt.', 'sloephuren-booking' ); ?></p>

				<label><?php esc_html_e( 'Mollie API-sleutel', 'sloephuren-booking' ); ?></label>
				<input type="text" name="mollie_api_key" value="<?php echo esc_attr( get_option( 'shb_mollie_api_key', '' ) ); ?>" placeholder="live_... of test_...">

				<h2><?php esc_html_e( 'Weergave', 'sloephuren-booking' ); ?></h2>
				<label><input type="checkbox" name="sitewide" value="1" <?php checked( get_option( 'shb_sitewide', 0 ), 1 ); ?>> <?php esc_html_e( 'Widget overal op de site tonen', 'sloephuren-booking' ); ?></label>
				<p class="description"><?php esc_html_e( 'Toont de zwevende boek-widget op elke pagina. Laat dit uit als je de widget alleen via de shortcode op specifieke pagina\'s wilt plaatsen.', 'sloephuren-booking' ); ?></p>

				<h2><?php esc_html_e( 'Boekingen', 'sloephuren-booking' ); ?></h2>
				<label><?php esc_html_e( 'Pending-blokkade (minuten)', 'sloephuren-booking' ); ?></label>
				<input type="number" name="pending_minutes" min="1" value="<?php echo esc_attr( get_option( 'shb_pending_minutes', 15 ) ); ?>">
				<p class="description"><?php esc_html_e( 'Hoe lang een niet-betaalde boeking het tijdslot bezet houdt.', 'sloephuren-booking' ); ?></p>

				<h2><?php esc_html_e( 'E-mail & voorwaarden', 'sloephuren-booking' ); ?></h2>
				<label><?php esc_html_e( 'E-mail beheerder', 'sloephuren-booking' ); ?></label>
				<input type="email" name="admin_email" value="<?php echo esc_attr( get_option( 'shb_admin_email', get_option( 'admin_email' ) ) ); ?>">
				<label><?php esc_html_e( 'URL voorwaarden', 'sloephuren-booking' ); ?></label>
				<input type="text" name="terms_url" value="<?php echo esc_attr( get_option( 'shb_terms_url', '' ) ); ?>" placeholder="https://...">

				<p style="margin-top:16px;"><button class="button button-primary"><?php esc_html_e( 'Instellingen opslaan', 'sloephuren-booking' ); ?></button></p>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Gebruik', 'sloephuren-booking' ); ?></h2>
			<p><?php esc_html_e( 'Plaats deze shortcode op een pagina om het boekformulier te tonen:', 'sloephuren-booking' ); ?></p>
			<code>[sloephuren_booking]</code>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Verwijderknop met eigen mini-formulier.
	 *
	 * @param string $entity Type.
	 * @param int    $id     ID.
	 */
	protected function delete_button( $entity, $id ) {
		?>
		<form method="post" class="shb-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Zeker weten verwijderen?', 'sloephuren-booking' ) ); ?>');">
			<?php wp_nonce_field( 'shb_delete_' . $entity ); ?>
			<input type="hidden" name="shb_action" value="delete_<?php echo esc_attr( $entity ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
			<button class="button button-small button-link-delete"><?php esc_html_e( 'Verwijder', 'sloephuren-booking' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Lijst omzetten naar id => object map.
	 *
	 * @param array $rows Rijen.
	 * @return array
	 */
	protected function id_map( $rows ) {
		$map = array();
		foreach ( (array) $rows as $r ) {
			$map[ (int) $r->id ] = $r;
		}
		return $map;
	}

	/**
	 * Leesbaar label voor een status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	protected function status_label( $status ) {
		$labels = array(
			'pending_payment' => __( 'Wacht op betaling', 'sloephuren-booking' ),
			'paid'            => __( 'Betaald', 'sloephuren-booking' ),
			'failed'          => __( 'Mislukt', 'sloephuren-booking' ),
			'expired'         => __( 'Verlopen', 'sloephuren-booking' ),
			'cancelled'       => __( 'Geannuleerd', 'sloephuren-booking' ),
		);
		return $labels[ $status ] ?? $status;
	}
}
