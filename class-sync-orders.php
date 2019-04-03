<?php
use Automattic\WooCommerce\Client;
class TeeSight_Sync_Order {
	protected $woocommerce = null;

	public function __construct() {
		add_action( 'woocommerce_thankyou', array( $this, 'sync_order' ), PHP_INT_MAX, 1 );
		$settings = get_option( 'teesight_order_options' );
		if ( isset( $settings['site_address'] ) && isset( $settings['consumer_key'] ) && isset( $settings['consumer_secret'] ) ) {
			$this->woocommerce = new Client(
				$settings['site_address'],
				$settings['consumer_key'],
				$settings['consumer_secret'],
				array(
					'wp_api' => true,
					'version' => 'wc/v3',
					'verify_ssl' => false,
				)
			);
		}
		add_filter( 'http_request_host_is_external', array( $this, 'allow_custom_host' ), 10, 3 );
	}

	public function allow_custom_host( $allow, $host, $url ) {
		$settings = get_option( 'teesight_order_options' );
		if ( isset( $settings['site_address'] ) && strpos( $settings['site_address'], $host ) > 0 ) {
			$allow = true;
		}
		return $allow;
	}

	public function sync_order( $order_id ) {
		$order = wc_get_order( $order_id );
		$deep = 2;
		if ( false === $order ) {
			return false;
		}
		if ( null !== $this->woocommerce ) {
			$data = array(
				'payment_method' => $order->get_payment_method(),
				'payment_method_title' => $order->get_payment_method_title(),
				'set_paid' => false,
				'status'    => 'pending',
				'currency' => $order->get_currency(),
				'date_created' => $order->get_date_created()->date( 'Y-m-d H:i:s' ),
				'date_modified' => $order->get_date_modified()->date( 'Y-m-d H:i:s' ),
				'discount_total' => (string) wc_format_decimal( $order->get_total_discount(), $deep ),
				'shipping_total' => (string) wc_format_decimal( $order->get_total_shipping(), $deep ),
				'total' => (string) wc_format_decimal( $order->get_total(), $deep ),
				'total_tax' => (string) wc_format_decimal( $order->get_total_tax(), $deep ),
				'order_key' => $order->get_order_key(),
				'billing' => array(
					'first_name' => $order->get_billing_first_name(),
					'last_name' => $order->get_billing_last_name(),
					'address_1' => $order->get_billing_address_1(),
					'address_2' => $order->get_billing_address_2(),
					'city' => $order->get_shipping_city(),
					'state' => $order->get_billing_state(),
					'postcode' => $order->get_billing_postcode(),
					'country' => $order->get_billing_country(),
					'email' => $order->get_billing_email(),
					'phone' => $order->get_billing_phone(),
				),
				'shipping' => array(
					'first_name' => $order->get_shipping_first_name(),
					'last_name' => $order->get_shipping_last_name(),
					'address_1' => $order->get_shipping_address_1(),
					'address_2' => $order->get_shipping_address_2(),
					'city' => $order->get_shipping_city(),
					'state' => $order->get_shipping_state(),
					'postcode' => $order->get_shipping_postcode(),
					'country' => $order->get_shipping_country(),
				),
			);

			$product_site_slug = '';
			foreach ( $order->get_items() as $item_id => $item ) {
				$product = $item->get_product();
				$product_id = null;
				$product_sku = null;
				if ( is_object( $product ) ) {
					$product_id = $product->get_id();
					$product_sku = $product->get_sku();
					$_p_id = $product_id;
					if ( ! empty( $item->get_variation_id() ) && ( 'product_variation' === $product->post_type ) ) {
						$_p_id = $product->get_parent_id();
					}
					if ( get_post_meta( $_p_id, '_product_site_slug', true ) ) {
						$product_site_slug = get_post_meta( $_p_id, '_product_site_slug', true );
					}
				}
				$data['line_items'][] = array(
					'name' => $item['name'],
					'product_id' => ( ! empty( $item->get_variation_id() ) && ( 'product_variation' === $product->post_type ) ) ? $product->get_parent_id() : $product_id,
					'variation_id' => ( ! empty( $item->get_variation_id() ) && ( 'product_variation' === $product->post_type ) ) ? $product_id : 0,
					'quantity' => wc_stock_amount( $item['qty'] ),
					'subtotal' => (string) wc_format_decimal( $order->get_line_subtotal( $item, false, false ), $deep ),
					'subtotal_tax' => (string) wc_format_decimal( $item['line_subtotal_tax'], $deep ),
					'total' => (string) wc_format_decimal( $order->get_line_total( $item, false, false ), $deep ),
					'total_tax' => (string) wc_format_decimal( $item['line_tax'], $deep ),
					'price' => (string) wc_format_decimal( $order->get_item_total( $item, false, false ), $deep ),
					'sku' => (string) $product_sku,
				);
			}

			foreach ( $order->get_shipping_methods() as $shipping_item_id => $shipping_item ) {
				$data['shipping_lines'][] = array(
					'method_id' => $shipping_item['method_id'],
					'method_title' => $shipping_item['name'],
					'total' => (string) wc_format_decimal( $shipping_item['cost'], $deep ),
				);
			}
			$data['meta_data'] = array(
				array(
					'key' => '_product_site_slug',
					'value' => $product_site_slug,
				),
				array(
					'key' => '_origin_order_id',
					'value' => $order->get_id(),
				),
				array(
					'key' => '_fullfill_status',
					'value' => 'pending',
				),
			);
			$result = $this->woocommerce->post( 'orders', $data );
		}
	}
}

new TeeSight_Sync_Order();
