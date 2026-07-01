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
			. '.shb-inline-form{display:inline;}';
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
			'saved'   => __( 'Opgeslagen.', 'sloephuren-booking' ),
			'deleted' => __( 'Verwijderd.', 'sloephuren-booking' ),
			'updated' => __( 'Bijgewerkt.', 'sloephuren-booking' ),
		);
		$text = $map[ $msg ] ?? '';
		if ( $text ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $text ) );
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
