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

	public function new_order_created( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( false === $order ) {
			return false;
		}
		$order_data = array(
			'id' => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'created_at' => $order->get_date_created()->date( 'Y-m-d H:i:s' ),
			'updated_at' => $order->get_date_modified()->date( 'Y-m-d H:i:s' ),
			'completed_at' => ! empty( $order->get_date_completed() ) ? $order->get_date_completed()->date( 'Y-m-d H:i:s' ) : '',
			'status' => $order->get_status(),
			'currency' => $order->get_currency(),
			'total' => wc_format_decimal( $order->get_total(), $dp ),
			'subtotal' => wc_format_decimal( $order->get_subtotal(), $dp ),
			'total_line_items_quantity' => $order->get_item_count(),
			'total_tax' => wc_format_decimal( $order->get_total_tax(), $dp ),
			'total_shipping' => wc_format_decimal( $order->get_total_shipping(), $dp ),
			'cart_tax' => wc_format_decimal( $order->get_cart_tax(), $dp ),
			'shipping_tax' => wc_format_decimal( $order->get_shipping_tax(), $dp ),
			'total_discount' => wc_format_decimal( $order->get_total_discount(), $dp ),
			'shipping_methods' => $order->get_shipping_method(),
			'order_key' => $order->get_order_key(),
			'payment_details' => array(
				'method_id' => $order->get_payment_method(),
				'method_title' => $order->get_payment_method_title(),
				'paid_at' => ! empty( $order->get_date_paid() ) ? $order->get_date_paid()->date( 'Y-m-d H:i:s' ) : '',
			),
			'billing_address' => array(
				'first_name' => $order->get_billing_first_name(),
				'last_name' => $order->get_billing_last_name(),
				'company' => $order->get_billing_company(),
				'address_1' => $order->get_billing_address_1(),
				'address_2' => $order->get_billing_address_2(),
				'city' => $order->get_billing_city(),
				'state' => $order->get_billing_state(),
				'formated_state' => WC()->countries->states[ $order->get_billing_country() ][ $order->get_billing_state() ], // human readable formated state name
				'postcode' => $order->get_billing_postcode(),
				'country' => $order->get_billing_country(),
				'formated_country' => WC()->countries->countries[ $order->get_billing_country() ], // human readable formated country name
				'email' => $order->get_billing_email(),
				'phone' => $order->get_billing_phone(),
			),
			'shipping_address' => array(
				'first_name' => $order->get_shipping_first_name(),
				'last_name' => $order->get_shipping_last_name(),
				'company' => $order->get_shipping_company(),
				'address_1' => $order->get_shipping_address_1(),
				'address_2' => $order->get_shipping_address_2(),
				'city' => $order->get_shipping_city(),
				'state' => $order->get_shipping_state(),
				'formated_state' => WC()->countries->states[ $order->get_shipping_country() ][ $order->get_shipping_state() ], // human readable formated state name
				'postcode' => $order->get_shipping_postcode(),
				'country' => $order->get_shipping_country(),
				'formated_country' => WC()->countries->countries[ $order->get_shipping_country() ], // human readable formated country name
			),
			'note' => $order->get_customer_note(),
			'customer_ip' => $order->get_customer_ip_address(),
			'customer_user_agent' => $order->get_customer_user_agent(),
			'customer_id' => $order->get_user_id(),
			'view_order_url' => $order->get_view_order_url(),
		);
		// getting all line items .
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			$product_id = null;
			$product_sku = null;
			// Check if the product exists.
			if ( is_object( $product ) ) {
				$product_id = $product->get_id();
				$product_sku = $product->get_sku();
			}
			$order_data['line_items'][] = array(
				'id' => $item_id,
				'subtotal' => wc_format_decimal( $order->get_line_subtotal( $item, false, false ), $dp ),
				'subtotal_tax' => wc_format_decimal( $item['line_subtotal_tax'], $dp ),
				'total' => wc_format_decimal( $order->get_line_total( $item, false, false ), $dp ),
				'total_tax' => wc_format_decimal( $item['line_tax'], $dp ),
				'price' => wc_format_decimal( $order->get_item_total( $item, false, false ), $dp ),
				'quantity' => wc_stock_amount( $item['qty'] ),
				'tax_class' => ( ! empty( $item['tax_class'] ) ) ? $item['tax_class'] : null,
				'name' => $item['name'],
				'product_id' => ( ! empty( $item->get_variation_id() ) && ( 'product_variation' === $product->post_type ) ) ? $product->get_parent_id() : $product_id,
				'variation_id' => ( ! empty( $item->get_variation_id() ) && ( 'product_variation' === $product->post_type ) ) ? $product_id : 0,
				'product_url' => get_permalink( $product_id ),
				'product_thumbnail_url' => wp_get_attachment_image_src( get_post_thumbnail_id( $product_id ), 'thumbnail', true )[0],
				'sku' => $product_sku,
				'meta' => wc_display_item_meta( $item, [ 'echo' => false ] ),
			);
		}
		// getting shipping .
		foreach ( $order->get_shipping_methods() as $shipping_item_id => $shipping_item ) {
			$order_data['shipping_lines'][] = array(
				'id' => $shipping_item_id,
				'method_id' => $shipping_item['method_id'],
				'method_title' => $shipping_item['name'],
				'total' => wc_format_decimal( $shipping_item['cost'], $dp ),
			);
		}
		// getting taxes .
		foreach ( $order->get_tax_totals() as $tax_code => $tax ) {
			$order_data['tax_lines'][] = array(
				'id' => $tax->id,
				'rate_id' => $tax->rate_id,
				'code' => $tax_code,
				'title' => $tax->label,
				'total' => wc_format_decimal( $tax->amount, $dp ),
				'compound' => (bool) $tax->is_compound,
			);
		}
		// getting fees .
		foreach ( $order->get_fees() as $fee_item_id => $fee_item ) {
			$order_data['fee_lines'][] = array(
				'id' => $fee_item_id,
				'title' => $fee_item['name'],
				'tax_class' => ( ! empty( $fee_item['tax_class'] ) ) ? $fee_item['tax_class'] : null,
				'total' => wc_format_decimal( $order->get_line_total( $fee_item ), $dp ),
				'total_tax' => wc_format_decimal( $order->get_line_tax( $fee_item ), $dp ),
			);
		}
		// getting coupons .
		foreach ( $order->get_items( 'coupon' ) as $coupon_item_id => $coupon_item ) {
			$order_data['coupon_lines'][] = array(
				'id' => $coupon_item_id,
				'code' => $coupon_item['name'],
				'amount' => wc_format_decimal( $coupon_item['discount_amount'], $dp ),
			);
		}
		return $order_data;
	}

	public function prepare_order_data() {

	}

}

new TeeSight_Sync_Order_Start();
