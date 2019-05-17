<?php
class TeeSight_Sync_Order_Product {
	public function __construct() {
		//add_action( 'init', array( $this, 'ajax_rename_product_design' ) );
	}

	public function get_product_has_design() {
		global $wpdb;
		$sql = "
			SELECT *
			FROM `{$wpdb->postmeta}`
			WHERE meta_key = '_product_full_print_id' AND meta_value != ''
		";
		$ids = array();
		$results = $wpdb->get_results( $sql, ARRAY_A );
		if ( is_array( $results ) && ! empty( $results ) ) {
			foreach ( $results as $res ) {
				if ( isset( $res['post_id'] ) ) {
					if ( is_numeric( $res['meta_value'] ) && $res['meta_value'] > 0 ) {
						$ids[ $res['post_id'] ] = $res['meta_value'];
					}
				}
			}
		}
		return $ids;
	}

	public function ajax_rename_product_design() {
		$ids = $this->get_product_has_design();
		
		$uploaddir = wp_upload_dir();
		foreach ( $ids as $p_id => $img_id ) {
			$exists_image_url = wp_get_attachment_url( $img_id );
			$wp_filetype = wp_check_filetype( basename( $exists_image_url ), null );
			$product = wc_get_product( $p_id );
			$sku = $product->get_sku();
			$ext = $wp_filetype['ext'];

			$uploadfile = $uploaddir['path'] . '/' . $sku;

			$contents = file_get_contents( $exists_image_url );
			$savefile = fopen( $uploadfile, 'w' );
			fwrite( $savefile, $contents );
			fclose( $savefile );

			$attachment = array(
				'post_mime_type' => $wp_filetype['type'],
				'post_title' => $sku,
				'post_content' => '',
				'post_status' => 'inherit',
			);

			$attach_id = wp_insert_attachment( $attachment, $uploadfile );

			$imagenew = get_post( $attach_id );
			$fullsizepath = get_attached_file( $imagenew->ID );
			$attach_data = wp_generate_attachment_metadata( $attach_id, $fullsizepath );
			wp_update_attachment_metadata( $attach_id, $attach_data );

			echo 'URL: ' . $image_url . '<br/>';
		}
	}
}

new TeeSight_Sync_Order_Product();
