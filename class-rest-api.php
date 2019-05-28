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

		$get_product_design = array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_product_design' ),
			'args' => array(
				'id' => array(
					'default' => 0,
				),
			),
		);
		register_rest_route( 'teesight/v1', '/product_design/(?P<id>\w+)', $get_product_design );

		$drive_product_images = array(
			'methods' => 'POST',
			'callback' => array( $this, 'drive_product_images' ),
			'args' => array(
				'product_id' => array(
					'default' => 0,
				),
				'drive_files' => array(
					'default' => array(),
				),
			),
		);
		register_rest_route( 'teesight/v1', '/drive_product_images/', $drive_product_images );
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

	public function get_product_design( $request ) {
		$product_design = '';
		if ( isset( $request['id'] ) ) {
			$product_id = $this->get_product_id_by_uniqid( $request['id'] );
			if ( $product_id > 0 ) {
				$design_id = get_post_meta( $product_id, '_product_full_print_id', true );
				$product_design = wp_get_attachment_url( $design_id );
			}
		}
		return $product_design;
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

	public function drive_product_images( $request ) {
		$parameters = $request->get_params();
		$return = array(
			'type' => 'fail',
		);
		$attachment_ids = array();
		if ( isset( $parameters['product_id'] ) && ! empty( $parameters['product_id'] ) && isset( $parameters['drive_files'] ) && is_array( $parameters['drive_files'] ) && ! empty( $parameters['drive_files'] ) ) {
			$product_id = $parameters['product_id'];

			$drive_files = $parameters['drive_files'];
			$gallery = array();
			foreach ( $drive_files as $index => $file ) {
				$attachment_id = 0;
				$upload = $this->rest_upload_image_url_from_gg_drive( esc_url_raw( $file['src'] ) );
				if ( ! is_wp_error( $upload ) ) {
					$return['type'] = 'success';
					$attachment_id = wc_rest_set_uploaded_image_as_attachment( $upload, $product_id );
					$attachment_ids[] = $attachment_id;
				}

				if ( 0 === $index ) {
					set_post_thumbnail( $product_id, $attachment_id );
				} else {
					$gallery[] = $attachment_id;
				}
			}
			if ( ! empty( $gallery ) ) {
				update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery ) );
			}
		}
		$return['attachment_ids'] = $attachment_ids;
		return $return;
	}

	public function rest_upload_image_url_from_gg_drive( $image_url ) {
		$parsed_url = wp_parse_url( $image_url );
		if ( ! $parsed_url || ! is_array( $parsed_url ) ) {
			return new WP_Error( 'woocommerce_rest_invalid_image_url', sprintf( __( 'Invalid URL %s.', 'teesight' ), $image_url ), array( 'status' => 400 ) );
		}

		$image_url = esc_url_raw( $image_url );
		if ( ! function_exists( 'download_url' ) ) {
			include_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file_array         = array();
		$file_array['name'] = 'ts-file-name-' . time() . '.jpg';

		$file_array['tmp_name'] = download_url( $image_url );
		$file_array['type'] = 'image/jpg';

		if ( is_wp_error( $file_array['tmp_name'] ) ) {
			return new WP_Error(
				'woocommerce_rest_invalid_remote_image_url',
				/* translators: %s: image URL */
				sprintf( __( 'Error getting remote image %s.', 'teesight' ), $image_url ) . ' '
				/* translators: %s: error message */
				. sprintf( __( 'Error: %s', 'teesight' ), $file_array['tmp_name']->get_error_message() ),
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
			return new WP_Error( 'woocommerce_rest_invalid_image', sprintf( __( 'Invalid image: %s', 'teesight' ), $file['error'] ), array( 'status' => 400 ) );
		}
		return $file;
	}


}
function teesight_sync_order_rest_api() {
	return TeeSight_Sync_Order_Rest_API::get_instance();
}
teesight_sync_order_rest_api();
