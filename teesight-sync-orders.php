<?php
/**
 * Plugin Name: TeeSight Sync Orders
 * Plugin URI: http://wooimport.local
 * Description: Remote sync orders
 * Version: 0.1.5.2
 * Author: teesight
 * Author URI: http://wooimport.local
 * Text Domain: teesight-sync-order
 * GitHub Plugin URI: https://github.com/TruongTuyen/teesight-sync-orders
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

function teesight_sync_order_upload_image_as_attachment( $image_url = '', $post_id = 0, $title = '' ) {
	$img_name = basename( $image_url );
	$upload_dir = wp_get_upload_dir();
	$local_url = $upload_dir['path'] . '/' . $img_name;
	$img_url = $upload_dir['url'] . '/' . $img_name;
	if ( file_exists( $local_url ) && attachment_url_to_postid( $img_url ) > 0 ) {
		$id = attachment_url_to_postid( $img_url );
		return $id;
	} else {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$attachment_src = media_sideload_image( $image_url, $post_id, $title, 'src' );
		$id = attachment_url_to_postid( $attachment_src );
		return $id;
	}
}
require_once teesight_sync_order_get_var( 'plugin_dir' ) . 'class-products.php';
require_once teesight_sync_order_get_var( 'plugin_dir' ) . 'class-option-page.php';
require_once teesight_sync_order_get_var( 'plugin_dir' ) . 'vendor/autoload.php';
require_once teesight_sync_order_get_var( 'plugin_dir' ) . 'vendor/CMB2/init.php';
require_once teesight_sync_order_get_var( 'plugin_dir' ) . 'class-sync-orders.php';
require_once teesight_sync_order_get_var( 'plugin_dir' ) . 'class-rest-api.php';

class TeeSight_Sync_Order_Start {
	public function __construct() {

		add_filter( 'woocommerce_rest_pre_insert_product_object', array( $this, 'rest_update_meta' ), PHP_INT_MAX, 2 );
	}

	public function rest_update_meta( $product, $request ) {
		$full_print_url = $request['full_print_url'];
		$attachment_id = teesight_sync_order_upload_image_as_attachment( $full_print_url );
		$product->update_meta_data( '_product_full_print_id', $attachment_id );
		$product->update_meta_data( '_product_full_print', wp_get_attachment_url( $attachment_id ) );
		return $product;
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
}

new TeeSight_Sync_Order_Start();
// Github Personal access tokens: 51a1b6738968d42118aef6dd279886d6b819dfc4
register_activation_hook( __FILE__, 'teesight_sync_orders_register_activation' );

function teesight_sync_orders_register_activation() {
	if ( ! wp_next_scheduled( 'teesight_sync_orders_two_hours_event' ) ) {
		wp_schedule_event( time(), 'teesight_sync_orders_every_two_hours', 'teesight_sync_orders_two_hours_event' );
	}
}

register_deactivation_hook( __FILE__, 'teesight_sync_orders_register_deactivation' );

function teesight_sync_orders_register_deactivation() {
	wp_clear_scheduled_hook( 'teesight_sync_orders_two_hours_event' );
}

add_filter( 'cron_schedules', 'teesight_sync_orders_custom_cron_schedule' );
function teesight_sync_orders_custom_cron_schedule( $schedules ) {
	$schedules['teesight_sync_orders_every_two_hours'] = array(
		'interval' => 2 * 60 * 60, // Every 2 hours.
		'display'  => esc_html__( 'Every 2 hours', 'teesight' ),
	);
	return $schedules;
}

