<?php
class TeeSight_Sync_Order_Rest_API extends WP_REST_Controller {
	protected static $_instance = null;
	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_product_permalink' ), 10 );
	}

	public function register_rest_product_permalink() {
		// http://teestore.local/wp-json/teesight/v1/product_permalink/1 .
		$product_permalink = array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_product_permalink' ),
			'args' => array(
				'id' => array(
					'default' => 0,
				),
			),
		);
		register_rest_route( 'teesight/v1', '/product_permalink/(?P<id>\w+)', $product_permalink );

		$update_product_design = array(
			'methods' => 'POST',
			'callback' => array( $this, 'update_product_design' ),
			'args' => array(
				'id' => array(
					'default' => 0,
				),
				'url' => array(
					'default' => '',
				),
			),
		);
		// register_rest_route( 'teesight/v1', '/update_product_design/(?P<id>\w+)/(?P<url>\w+)', $update_product_design );
		register_rest_route( 'teesight/v1', '/update_product_design', $update_product_design );
	}

	public function get_product_id_by_uniqid( $uniqid = '' ) {
		$product_id = 0;
		if ( isset( $uniqid ) ) {
			$query_args = array(
				'posts_per_page'   => 1,
				'post_type'        => 'product',
				'meta_key'         => '_product_origin_unique_id',
				'meta_value'       => $uniqid,
			);
			$query = new WP_Query( $query_args );
			if ( $query->have_posts() ) {
				while ( $query->have_posts() ) {
					$query->the_post();
					$product_id = get_the_ID();
				}
				wp_reset_postdata();
			}
		}
		return $product_id;
	}

	public function get_product_permalink( $request ) {
		// http://teestore.local/wp-json/teesight/v1/product_permalink/1/ .
		$product_url = '#';
		if ( isset( $request['id'] ) ) {
			$product_id = $this->get_product_id_by_uniqid( $request['id'] );
			if ( $product_id > 0 ) {
				$product_url = get_permalink( $product_id );
			}
		}
		return $product_url;
	}

	public function update_product_design( $request ) {
		// http://teestore.local/wp-json/teesight/v1/update_product_design/PRODUCT_UNIQUE_ID/URL_TO_IMG .
		$parameters = $request->get_params();
		if ( isset( $request['id'] ) && '' !== $request['id'] && isset( $request['url'] ) && '' !== $request['url'] ) {
			$p_id = $this->get_product_id_by_uniqid( $request['id'] );
			$url = $request['url'];
			if ( get_post_status( $p_id ) ) {
				$attachment_id = teesight_sync_order_upload_image_as_attachment( $url );
				if ( is_numeric( $attachment_id ) && $attachment_id > 0 ) {
					update_post_meta( $p_id, '_product_full_print_id', $attachment_id );
					update_post_meta( $p_id, '_product_full_print', wp_get_attachment_url( $attachment_id ) );
					return array( 'result' => 'done' );
				}
			}
		}
		return array( 'result' => 'fail' );
	}
}
function teesight_sync_order_rest_api() {
	return TeeSight_Sync_Order_Rest_API::get_instance();
}
teesight_sync_order_rest_api();
