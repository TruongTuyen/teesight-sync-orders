<?php

class TeeSightOrders {
	private $options;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'orders_add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'orders_page_init' ) );
	}

	public function orders_add_plugin_page() {
		add_menu_page(
			esc_html__( 'TeeSight Orders', 'teesight-sync-orders' ),
			esc_html__( 'TeeSight Orders', 'teesight-sync-orders' ),
			'manage_options',
			'teesight-orders',
			array( $this, 'orders_create_admin_page' ),
			'dashicons-admin-generic',
			76
		);

		add_submenu_page(
			'teesight-orders',
			esc_html__( 'Export Products', 'teesight-sync-orders' ),
			esc_html__( 'Export Products', 'teesight-sync-orders' ),
			'manage_options',
			'teesight-export-products',
			array( $this, 'orders_create_export_page' ),
			'dashicons-admin-generic',
			1
		);
	}

	public function orders_create_export_page() {
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Export product to CSV', 'teesight' ); ?></h2>
			<form action="<?php echo admin_url( 'admin.php' ); ?>?page=teesight-export-products" method="GET">
				<input type="hidden" name="page" value="teesight-export-products" />
				<input type="hidden" name="teesight_action" value="export_product_csv" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Export product', 'teesight' ); ?></button>
			</form>
		</div>
		<?php
	}

	public function orders_create_admin_page() {
		$this->options = get_option( 'teesight_order_options' ); ?>

		<div class="wrap">
			<h2><?php esc_html_e( 'TeeSight Orders', 'teesight-sync-orders' ); ?></h2>
			<p><?php esc_html_e( 'TeeSight Sync Orders', 'teesight-sync-orders' ); ?></p>
			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php
					settings_fields( 'orders_option_group' );
					do_settings_sections( 'teesight-orders-admin' );
					submit_button();
				?>
			</form>
		</div>
	<?php }

	public function orders_page_init() {
		register_setting(
			'orders_option_group',
			'teesight_order_options',
			array( $this, 'orders_sanitize' )
		);

		add_settings_section(
			'orders_setting_section',
			esc_html__( 'Settings', 'teesight-sync-orders' ),
			array( $this, 'orders_section_info' ),
			'teesight-orders-admin'
		);

		add_settings_field(
			'site_name',
			esc_html__( 'Site Name', 'teesight-sync-orders' ),
			array( $this, 'site_name_callback' ),
			'teesight-orders-admin',
			'orders_setting_section'
		);

		add_settings_field(
			'site_address',
			esc_html__( 'Site Address', 'teesight-sync-orders' ),
			array( $this, 'site_address_callback' ),
			'teesight-orders-admin',
			'orders_setting_section'
		);

		add_settings_field(
			'consumer_key',
			esc_html__( 'Consumer key', 'teesight-sync-orders' ),
			array( $this, 'consumer_key_callback' ),
			'teesight-orders-admin',
			'orders_setting_section'
		);

		add_settings_field(
			'consumer_secret',
			esc_html__( 'Consumer secret', 'teesight-sync-orders' ),
			array( $this, 'consumer_secret_callback' ),
			'teesight-orders-admin',
			'orders_setting_section'
		);
	}

	public function orders_sanitize( $input ) {
		$sanitary_values = array();
		if ( isset( $input['site_name'] ) ) {
			$sanitary_values['site_name'] = sanitize_text_field( $input['site_name'] );
		}

		if ( isset( $input['site_address'] ) ) {
			$sanitary_values['site_address'] = sanitize_text_field( $input['site_address'] );
		}

		if ( isset( $input['consumer_key'] ) ) {
			$sanitary_values['consumer_key'] = sanitize_text_field( $input['consumer_key'] );
		}

		if ( isset( $input['consumer_secret'] ) ) {
			$sanitary_values['consumer_secret'] = sanitize_text_field( $input['consumer_secret'] );
		}

		return $sanitary_values;
	}

	public function orders_section_info() {

	}

	public function site_name_callback() {
		printf(
			'<input class="regular-text" type="text" name="teesight_order_options[site_name]" id="site_name" value="%s">',
			isset( $this->options['site_name'] ) ? esc_attr( $this->options['site_name'] ) : ''
		);
	}

	public function site_address_callback() {
		printf(
			'<input class="regular-text" type="text" name="teesight_order_options[site_address]" id="site_address" value="%s">',
			isset( $this->options['site_address'] ) ? esc_attr( $this->options['site_address'] ) : ''
		);
	}

	public function consumer_key_callback() {
		printf(
			'<input class="regular-text" type="text" name="teesight_order_options[consumer_key]" id="consumer_key" value="%s">',
			isset( $this->options['consumer_key'] ) ? esc_attr( $this->options['consumer_key'] ) : ''
		);
	}

	public function consumer_secret_callback() {
		printf(
			'<input class="regular-text" type="text" name="teesight_order_options[consumer_secret]" id="consumer_secret" value="%s">',
			isset( $this->options['consumer_secret'] ) ? esc_attr( $this->options['consumer_secret'] ) : ''
		);
	}

}
if ( is_admin() ) {
	$orders = new TeeSightOrders();
}

/*
 * Retrieve this value with:
 * $options = get_option( 'teesight_order_options' ); // Array of All Options
 * $site_name = $options['site_name']; // Site Name
 * $site_address = $options['site_address']; // Site Address
 * $consumer_key = $options['consumer_key']; // Consumer key
 * $consumer_secret = $options['consumer_secret']; // Consumer secret
 */
