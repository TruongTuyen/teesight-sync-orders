<?php
use Automattic\WooCommerce\Client;
class TeeSight_Sync_Order {
	protected $woocommerce = null;
	protected $remote_site_url = '';

	public function __construct() {
		add_action( 'woocommerce_thankyou', array( $this, 'sync_order' ), PHP_INT_MAX, 1 );
		$settings = get_option( 'teesight_order_options' );
		if ( isset( $settings['site_address'] ) && isset( $settings['consumer_key'] ) && isset( $settings['consumer_secret'] ) ) {
			$this->remote_site_url = $settings['site_address'];
			$this->woocommerce = new Client(
				$settings['site_address'],
				$settings['consumer_key'],
				$settings['consumer_secret'],
				array(
					'wp_api' => true,
					'version' => 'wc/v3',
					'verify_ssl' => false,
					'timeout' => 1800,
				)
			);
		}
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'modify_order_query' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'admin_orders_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_re_sync_order' ) );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_custom_table_orders_list_columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'add_custom_table_orders_list_columns_content' ) );
		add_filter( 'http_request_host_is_external', array( $this, 'allow_custom_host' ), 10, 3 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'manual_create_order' ), PHP_INT_MAX, 1 );
		add_action( 'woocommerce_order_edit_status', array( $this, 'detect_order_bulk_action' ), PHP_INT_MAX, 2 );
		add_action( 'teesight_sync_orders_two_hours_event', array( $this, 'cronjob_check_order_not_synced' ) );

		add_action( 'init', array( $this, 'change_products_site_slug' ), 1000 );
		add_filter( 'manage_edit-product_columns', array( $this, 'add_custom_table_product_list_columns' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'add_custom_table_product_list_columns_content' ) );
		add_action( 'init', array( $this, 'manual_sync_orders_not_synced' ), 10000 );

	}

	public function change_products_site_slug() {
		if ( isset( $_GET['dev'] ) && $_GET['dev'] && isset( $_GET['ts_action'] ) && 'change_product_site_slug' == $_GET['ts_action'] ) {
			global $wpdb;
			$new_product_slug = 'new_site_slug';
			if ( isset( $_GET['site_slug'] ) && ! empty( $_GET['site_slug'] ) ) {
				$new_product_slug = sanitize_text_field( wp_unslash( $_GET['site_slug'] ) );
			}
			$sql = "UPDATE {$wpdb->postmeta} SET meta_value='{$new_product_slug}' WHERE meta_key='_product_site_slug'";
			$results = $wpdb->get_results( $sql ); // @codingStandardsIgnoreLine .
		}
	}

	public function remote_check_site_exists() {
		$site_domain = $_SERVER['HTTP_HOST']; // @codingStandardsIgnoreLine .
		$rest_api_link = untrailingslashit( $this->remote_site_url ) . '/wp-json/teesight/v1/is_site_registered/' . str_replace( '.', '__ts_dot__', $site_domain );
		$remote_args = array(
			'timeout' => 3600,
			'sslverify' => false,
		);
		$response = wp_remote_get( $rest_api_link, $remote_args );
		if ( is_array( $response ) && isset( $response['body'] ) ) {
			$result = json_decode( $response['body'], true );
			if ( is_array( $result ) && isset( $result['is_registered'] ) && 'yes' == $result['is_registered'] ) {
				return true;
			}
		}
		return false;
	}

	public function try_sync_orders_not_synced() {
		set_time_limit( 0 );
		$args = array(
			'status' => 'processing',
			'order_not_synced' => 'yes',
		);
		$errors = array();
		$orders = wc_get_orders( $args );
		if ( is_array( $orders ) && ! empty( $orders ) ) {
			foreach ( $orders as $__order ) {
				if ( is_object( $__order ) && method_exists( $__order, 'get_id' ) ) {
					$order_id = $__order->get_id();
					try {
						$this->manual_create_order( $order_id );
					} catch ( Exception $e ) {
						$errors[] = $e->getMessage();
					}
				}
			}
		}
	}

	public function manual_sync_orders_not_synced() {
		if ( isset( $_GET['dev'] ) && $_GET['dev'] && isset( $_GET['ts_action'] ) && 'sync_orders_not_synced' == $_GET['ts_action'] ) {
			$this->try_sync_orders_not_synced();
		}
	}

	public function cronjob_check_order_not_synced() {
		$this->try_sync_orders_not_synced();
	}

	public function modify_order_query( $query, $query_vars ) {
		if ( isset( $query_vars['order_not_synced'] ) && 'yes' == $query_vars['order_not_synced'] ) {
			$query['meta_query'][] = array(
				'relation' => 'AND',
				array(
					'relation' => 'OR',
					array(
						'key' => '_order_synced',
						'value' => 'yes',
						'compare' => '!=',
					),
					array(
						'key' => '_order_synced',
						'compare' => 'NOT EXISTS',
					),
				),
				array(
					'relation' => 'OR',
					array(
						'key' => '_ignore_from_teesight',
						'value' => 'yes',
						'compare' => '!=',
					),
					array(
						'key' => '_ignore_from_teesight',
						'compare' => 'NOT EXISTS',
					),
				),
			);
		}
		return $query;
	}

	public function detect_order_bulk_action( $id, $new_status ) {
		if ( 'processing' === $new_status && get_post_status( $id ) ) {
			$this->manual_create_order( $id );
		}
	}

	public function update_order_uniqid( $order_id ) {
		$order_uniqid = uniqid( 'order_uniqid_' );
		update_post_meta( $order_id, '_origin_order_uniqid', $order_uniqid );
	}

	public function manual_create_order( $order_id ) {
		$order = wc_get_order( $order_id );
		$order_status = $order->get_status();
		if ( false === get_post_meta( $order_id, '_origin_order_uniqid', true ) || empty( get_post_meta( $order_id, '_origin_order_uniqid', true ) ) ) { // First time create will not have uniqid.
			$this->update_order_uniqid( $order_id );
		}

		if ( 'processing' == $order_status ) {
			$order_uniqid = get_post_meta( $order_id, '_origin_order_uniqid', true );
			update_post_meta( $order_id, 'order_create_manual_status', $order_status );
			update_post_meta( $order_id, 'order_create_manual_status_remote_url', $this->remote_site_url );
			$result = $this->remote_check_order_exists( $order_uniqid );

			if ( 'not_exist' === $result ) {
				return $this->sync_order( $order_id, true );
			} elseif ( 'exist' === $result ) {
				update_post_meta( $order_id, '_order_synced', 'yes' );
			}
		}
		return false;
	}

	public function remote_check_order_exists( $order_uniqid ) {
		$rest_api_link = untrailingslashit( $this->remote_site_url ) . '/wp-json/teesight/v1/order_exists/' . $order_uniqid;
		$remote_args = array(
			'timeout' => 3600,
			'sslverify' => false,
		);
		$response = wp_remote_get( $rest_api_link, $remote_args );
		if ( is_array( $response ) && isset( $response['body'] ) ) {
			$result = json_decode( $response['body'], true );

			if ( is_array( $result ) && isset( $result['is_exist'] ) && 'true' === $result['is_exist'] ) {
				return 'exist';
			} elseif ( is_array( $result ) && isset( $result['is_exist'] ) && 'fail' === $result['is_exist'] ) {
				return 'not_exist';
			}
		}
		return 'unknow';
	}

	public function allow_custom_host( $allow, $host, $url ) {
		$settings = get_option( 'teesight_order_options' );
		if ( isset( $settings['site_address'] ) && strpos( $settings['site_address'], $host ) > 0 ) {
			$allow = true;
		}
		return $allow;
	}

	public function get_origin_product_full_print( $product_id ) {
		$return = false;
		if ( null !== $this->woocommerce ) {
			$product_info = $this->woocommerce->get( 'products/' . $product_id );
			if ( isset( $product_info->meta_data ) && is_array( $product_info->meta_data ) && ! empty( $product_info->meta_data ) ) {
				foreach ( $product_info->meta_data as $meta_data ) {
					if ( isset( $meta_data->key ) && '_product_full_print' == $meta_data->key ) {
						if ( '' !== $meta_data->value ) {
							return $meta_data->value;
						}
					}
				}
			}
		}
		return $return;
	}

	public function sync_order( $order_id, $is_manual = false ) {
		if ( ! $this->remote_check_site_exists() ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		$deep = 2;
		if ( false === $order ) {
			return false;
		}
		if ( false === get_post_meta( $order_id, '_origin_order_uniqid', true ) || empty( get_post_meta( $order_id, '_origin_order_uniqid', true ) ) ) { // First time create will not have uniqid.
			$this->update_order_uniqid( $order_id );
		}
		if ( ! $is_manual ) {
			if ( 'processing' !== $order->get_status() ) {
				return false;
			}
		}

		$order_uniqid = get_post_meta( $order_id, '_origin_order_uniqid', true );
		$order_synced = get_post_meta( $order_id, '_order_synced', true );
		if ( false !== $order_synced && 'yes' == $order_synced ) {
			return false;
		}
		$result = $this->remote_check_order_exists( $order_uniqid );
		if ( null !== $this->woocommerce && 'not_exist' === $result ) {
			$data = array(
				'payment_method' => $order->get_payment_method(),
				'payment_method_title' => $order->get_payment_method_title(),
				'status'    => 'processing',
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
				'line_items' => array(),
			);

			$product_site_slug = '';
			foreach ( $order->get_items() as $item_id => $item ) {
				$product = $item->get_product();
				$product_id = null;
				$product_sku = null;
				if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
					$product_id = $product->get_id();
					$product_sku = $product->get_sku();
					$_p_id = $product_id;
					$_p_variation_id = 0;
					if ( ! empty( $item->get_variation_id() ) && ( 'product_variation' === $product->post_type ) ) {
						$_p_id = $product->get_parent_id();
						$_p_variation_id = $product_id;
					}
					if ( get_post_meta( $_p_id, '_product_site_slug', true ) ) {
						$product_site_slug = get_post_meta( $_p_id, '_product_site_slug', true );
					}

					$__product_base_supplier = get_post_meta( $_p_id, '_product_base_supplier', true );
					$__product_origin_unique_id = get_post_meta( $_p_id, '_product_origin_unique_id', true );
					if ( ! empty( $__product_origin_unique_id ) && ! empty( $__product_base_supplier ) && ! empty( $product_site_slug ) ) {
						$data['line_items'][] = array(
							'name' => $item['name'],
							'product_id' => $_p_id,
							'variation_id' => $_p_variation_id,
							'quantity' => wc_stock_amount( $item['qty'] ),
							'subtotal' => (string) wc_format_decimal( $order->get_line_subtotal( $item, false, false ), $deep ),
							'subtotal_tax' => (string) wc_format_decimal( $item['line_subtotal_tax'], $deep ),
							'total' => (string) wc_format_decimal( $order->get_line_total( $item, false, false ), $deep ),
							'total_tax' => (string) wc_format_decimal( $item['line_tax'], $deep ),
							'price' => (string) wc_format_decimal( $order->get_item_total( $item, false, false ), $deep ),
							'sku' => (string) $product_sku,
						);
					}
				}
			}

			if ( is_array( $data['line_items'] ) && empty( $data['line_items'] ) ) {
				// Line item not contain any product from teesight -> ignore.
				update_post_meta( $order_id, '_ignore_from_teesight', 'yes' );
				return false;
			}

			foreach ( $order->get_shipping_methods() as $shipping_item_id => $shipping_item ) {
				$data['shipping_lines'][] = array(
					'method_id' => $shipping_item['method_id'],
					'method_title' => $shipping_item['name'],
					'total' => (string) wc_format_decimal( $shipping_item['cost'], $deep ),
				);
			}

			$order_fulfill_status = 'pending';
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
					'key' => '_origin_order_uniqid',
					'value' => $order_uniqid,
				),
				array(
					'key' => '_fulfill_status',
					'value' => $order_fulfill_status,
				),
				array(
					'key' => '_order_recent_sync_from_store',
					'value' => 'yes',
				),
			);
			if ( $is_manual ) {
				$data['meta_data'][] = array(
					'key' => '_is_manual_created',
					'value' => 'true',
				);
			}
			if ( ! empty( $data['line_items'] ) ) {
				if ( is_object( $this->woocommerce ) && method_exists( $this->woocommerce, 'post' ) ) {
					update_post_meta( $order_id, '_order_synced', 'yes', 'no' );
					update_metadata( 'post', $order_id, '_order_synced', 'yes', 'no' );
					$result = $this->woocommerce->post( 'orders', $data );
					return true;
				}
			}
		}
		return false;
	}

	public function add_custom_table_orders_list_columns( $columns ) {
		$columns['ts_synced'] = esc_html__( 'Synced', 'teesight' );
		return $columns;
	}

	public function add_custom_table_product_list_columns( $columns ) {
		$columns['ts_product_site_slug'] = esc_html__( 'Site Slug', 'teesight' );
		return $columns;
	}

	public function add_custom_table_orders_list_columns_content( $column ) {
		global $post;
		if ( 'ts_synced' === $column ) {
			$is_synced = get_post_meta( $post->ID, '_order_synced', true );
			if ( 'yes' == $is_synced ) {
				$icon = '<span style="color: #5b841b;"><span class="dashicons dashicons-yes-alt"></span></span>';
			} else {
				$icon = '<span style="color:#f44336;" class="dashicons dashicons-dismiss"></span>';
				$is_ignored = get_post_meta( $post->ID, '_ignore_from_teesight', true );
				if ( 'yes' === $is_ignored ) {
					$icon = '<span style="color: #f44336; font-weight:bold;">Ignored</span>';
				} else {
					$order_id = $post->ID;
					$order = wc_get_order( $order_id );
					$order_status = $order->get_status();
					if ( 'processing' == $order_status ) {
						$resync_url = add_query_arg(
							array(
								'post_type' => 'shop_order',
								'ts_action' => 're-sync-order',
								'order_id' => $order_id,
							),
							admin_url( 'edit.php' )
						);
						$icon .= '<a style="display:inline-block; margin-left:10px;" href="' . esc_url( $resync_url ) . '">
							<span style="color: #5b841b;">		
								<span class="dashicons dashicons-update"></span>
							</span>
						</a>';
					}
				}
			}
			echo $icon;
		}
	}

	public function add_custom_table_product_list_columns_content( $column ) {
		global $post;
		if ( 'ts_product_site_slug' === $column ) {
			$p_site_slug = get_post_meta( $post->ID, '_product_site_slug', true );
			echo $p_site_slug;
		}
	}

	public function admin_orders_notice() {
		if ( isset( $_GET['result_code'] ) && ! empty( $_GET['result_code'] ) ) {
			switch ( $_GET['result_code'] ) {
				case 'sync_success':
					$message = esc_html__( 'Your order synced', 'teesight' );
					$this->message_sync_success( $message );
					break;
				case 'sync_fail':
					$message = esc_html__( 'Could not sync your order, please try again', 'teesight' );
					$this->message_sync_fail( $message );
					break;
			}
		}
	}

	public function message_sync_success( $message = '' ) {
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	public function message_sync_fail( $message = '' ) {
		?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	public function maybe_re_sync_order() {
		if ( isset( $_GET['ts_action'] ) && 're-sync-order' === $_GET['ts_action'] ) {
			if ( isset( $_GET['order_id'] ) && ! empty( $_GET['order_id'] ) ) {
				$order_id = sanitize_text_field( wp_unslash( $_GET['order_id'] ) );
				$result = $this->manual_create_order( $order_id );
				$redirect_args = array(
					'post_type' => 'shop_order',
				);
				if ( $result ) {
					$redirect_args['result_code'] = 'sync_success';
				} else {
					$redirect_args['result_code'] = 'sync_fail';
				}
				$redirect_url = add_query_arg( $redirect_args, admin_url( 'edit.php' ) );
				wp_redirect( $redirect_url );
				die;
			}
		}
	}

	public function rest_upload_image_url_from_gg_drive( $image_url ) {
		$parsed_url = wp_parse_url( $image_url );
		$errors = array();

		// Check parsed URL.
		if ( ! $parsed_url || ! is_array( $parsed_url ) ) {
			/* translators: %s: image URL */
			$errors[] = new WP_Error( 'woocommerce_rest_invalid_image_url', sprintf( __( 'Invalid URL %s.', 'woocommerce' ), $image_url ), array( 'status' => 400 ) );
		}

		// Ensure url is valid.
		$image_url = esc_url_raw( $image_url );

		// download_url function is part of wp-admin.
		if ( ! function_exists( 'download_url' ) ) {
			include_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file_array         = array();
		$file_array['name'] = 'file-name-' . time() . '.jpg';// basename( current( explode( '?', $image_url ) ) );

		// Download file to temp location.
		$file_array['tmp_name'] = download_url( $image_url );
		$file_array['type'] = 'image/jpg';

		// If error storing temporarily, return the error.
		if ( is_wp_error( $file_array['tmp_name'] ) ) {
			$errors[] = new WP_Error(
				'woocommerce_rest_invalid_remote_image_url',
				/* translators: %s: image URL */
				sprintf( __( 'Error getting remote image %s.', 'woocommerce' ), $image_url ) . ' '
				/* translators: %s: error message */
				. sprintf( __( 'Error: %s', 'woocommerce' ), $file_array['tmp_name']->get_error_message() ),
				array( 'status' => 400 )
			);
		}

		// Do the validation and storage stuff.
		$file = wp_handle_sideload(
			$file_array,
			array(
				'test_form' => false,
				// 'mimes'     => wc_rest_allowed_image_mime_types(),
			),
			current_time( 'Y/m' )
		);
		if ( isset( $file['error'] ) ) {
			@unlink( $file_array['tmp_name'] ); // @codingStandardsIgnoreLine.

			/* translators: %s: error message */
			$errors[] = new WP_Error( 'woocommerce_rest_invalid_image', sprintf( __( 'Invalid image: %s', 'woocommerce' ), $file['error'] ), array( 'status' => 400 ) );
		}
		if ( ! empty( $errors ) ) {
			return $errors;
		}
		return $file;
	}

	public function when_rest_image_upload_error( $boolean, $upload, $product_id, $images ) {
		foreach ( $images as $image ) {
			$upload = $this->rest_upload_image_url_from_gg_drive( esc_url_raw( $image['src'] ) );
			if ( ! is_wp_error( $upload ) ) {
				$attachment_id = wc_rest_set_uploaded_image_as_attachment( $upload, $product->get_id() );
			}
		}
		return true;
	}


	public function rest_pre_insert_product_object( $product, $request, $creating ) {
		if ( isset( $request['meta_data'] ) ) {
			if ( isset( $request['meta_data']['ts_images'] ) ) {
				$product->update_meta_data( 'ts_images2', $request['meta_data']['ts_images'] );
			}
		}
	}
}

new TeeSight_Sync_Order();

