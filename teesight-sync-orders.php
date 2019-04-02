<?php
/**
 * Plugin Name: TeeSight Sync Orders
 * Plugin URI: http://wooimport.local
 * Description: Remote sync orders
 * Version: 0.1.0
 * Author: teesight
 * Author URI: http://wooimport.local
 * Text Domain: teesight-sync-order
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

function teesight_sync_order_get_var( $key = '' ) {
	$configs = array(
		'plugin_url' => plugin_dir_url( __FILE__ ),
		'plugin_dir' => plugin_dir_path( __FILE__ ),
		'plugin_ver' => '0.1.0',
	);
	if ( isset( $configs[ $key ] ) ) {
		return $configs[ $key ];
	}
	return false;
}

require_once teesight_sync_order_get_var( 'plugin_dir' ) . 'class-option-page.php';
require_once teesight_sync_order_get_var( 'plugin_dir' ) . 'vendor/autoload.php';
require_once teesight_sync_order_get_var( 'plugin_dir' ) . 'vendor/CMB2/init.php';
require_once teesight_sync_order_get_var( 'plugin_dir' ) . 'class-sync-orders.php';

class TeeSight_Sync_Order_Start {
	public function __construct() {
		add_action( 'cmb2_admin_init', array( $this, 'register_product_metabox' ) );
		do_action( 'woocommerce_api_create_product', array( $this, 'update_rest_product_fullprint' ), PHP_INT_MAX, 2 );
	}

	public function register_product_metabox() {
		$prefix = '_product_';
		/**
		 * Metabox to add fields to categories and tags
		 */
		$cmb_term = new_cmb2_box(
			array(
				'id'               => $prefix . 'setting',
				'title'            => esc_html__( 'Full Print', 'teesight' ),
				'object_types'     => array( 'product' ),
				'context'      => 'side',
				'priority'     => 'low',
			)
		);
		$cmb_term->add_field(
			array(
				'name' => esc_html__( 'Upload full print', 'teesight' ),
				'id'   => $prefix . 'full_print',
				'type' => 'file',
			)
		);
	}

	public function update_rest_product_fullprint( $id, $data ) {
		$full_print_url = $data['full_print_url'];
		$this->update_sync_product_fullprint( $id, $full_print_url );
		update_post_meta( $id, 'need_update_fullprint', 'yes' );
	}

	public function update_sync_product_fullprint( $product_id = 0, $fullprint_url = '' ) {
		$upload = wc_rest_upload_image_from_url( $fullprint_url );
		$attachment_id = wc_rest_set_uploaded_image_as_attachment( $upload );
		update_post_meta( $product_id, '_product_full_print_id', $attachment_id );
		update_post_meta( $product_id, '_product_full_print', wp_get_attachment_url( $attachment_id ) );
	}

}

new TeeSight_Sync_Order_Start();


