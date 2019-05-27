<?php
class Teesight_Sync_Product_Export {
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	public function init() {
		if ( is_admin() ) {
			$this->export_product_csv();
		}
	}

	public function get_file_header() {
		$header = array(
			'product_id' => 'Product Id',
			'product_parent_id' => 'Product Parent Id',
			'product_parent' => 'Product Parent',
			'product_sku' => 'SKU',
			'product_type' => 'Product Type',
			'product_name' => 'Product Name',
			'product_content' => 'Product Long Description',
			'product_excerpt' => 'Product Short Description',

			'product_image' => 'Product Image',
			'product_gallery_image' => 'Product Gallery Image(s)',

			'regular_price' => 'Regular Price',
			'special_price' => 'Sale Price',
			'special_price_from' => 'Sale Price From',
			'special_price_to' => 'Sale Price To',

			'category' => 'Category(s)',
			'tag' => 'Tag(s)',
			'attribute' => 'Attribute(s)',

			'_manage_stock' => 'Manage Stock',
			'_stock' => 'Stock',
			'_backorders' => 'Backorders',
			'_stock_status' => 'Stock Status',
			'_sold_individually' => 'Sold Individually',

			'_product_url' => 'Product Url',
			'_button_text' => 'Button Text',

			'_virtual' => 'Is Virual',
			'_downloadable' => 'Is Downloadable',
			'_downloadable_files' => 'Downloadable Files',
			'_weight' => 'Weight',
			'_length' => 'Length',
			'_width' => 'Width',
			'_height' => 'Height',
			'_purchase_note' => 'Purchase Note',
			'menu_order' => 'Menu Order',
			'comment_status' => 'Enable Reviews',

		);
		return $header;
	}

	public function get_assign_categories( $product_id = '' ) {
		$taxonomy = 'product_cat';
		$post_terms = wp_get_object_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
		$categories = ( ! empty( $post_terms ) && is_array( $post_terms ) ) ? implode( ',', $post_terms ) : '';
		return $categories;
	}

	public function get_assign_tags( $product_id = '' ) {
		$taxonomy = 'product_tag';
		$post_terms = wp_get_object_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
		$tags = ( ! empty( $post_terms ) && is_array( $post_terms ) ) ? implode( ',', $post_terms ) : '';
		return $tags;
	}

	public function get_assign_attributes( $product = '', $product_id = 0 ) {
		$attributes_str = '';
		$attributes = $product->get_attributes();

		if ( ! empty( $attributes ) && is_array( $attributes ) ) {
			foreach ( $attributes as $attribute ) {
				if ( empty( $attribute['is_visible'] ) || ( $attribute['is_taxonomy'] && ! taxonomy_exists( $attribute['name'] ) ) ) {
					continue;
				} else {
					$attributes_str .= wc_attribute_label( $attribute['name'] ) . '=';
					if ( $attribute['is_taxonomy'] ) {
						$values = wc_get_product_terms( $product_id, $attribute['name'], array( 'fields' => 'names' ) );
						$attributes_str .= implode( ', ', $values ) . '|';
					} else {
						// Convert pipes to commas and display values.
						$values = array_map( 'trim', explode( WC_DELIMITER, $attribute['value'] ) );
						$attributes_str .= implode( ', ', $values ) . '|';
					}
				}
			}
		}
		$attributes_str = trim( $attributes_str, '|' );
		return $attributes_str;
	}

	public function make_product_row( $product_id = '', $is_variation = false, $parent_product_sku = '' ) {
		$product = wc_get_product( $product_id );
		$post = get_post( $product_id );

		$product_info_row = array();
		if ( $is_variation ) {
			/* fecthing categories :: start */
			$categories = '';
			/* fecthing categories :: end */

			/* fecthing tags :: start */
			$tags = '';
			/* fecthing tags :: start */

			/* fecthing attributes :: start */
			$attributes_str = '';
			$attributes = $product->get_attributes();
			if ( ! empty( $attributes ) && is_array( $attributes ) ) {
				foreach ( $attributes as $key => $attribute ) {
					if ( empty( $attribute['is_visible'] ) || ( $attribute['is_taxonomy'] && ! taxonomy_exists( $attribute['name'] ) ) ) {
						$taxonomy = $key;
						$meta = get_post_meta( $product_id, 'attribute_' . $taxonomy, true );
						$term = get_term_by( 'slug', $meta, $taxonomy );
						if ( is_object( $term ) ) {
							$attributes_str .= wc_attribute_label( $taxonomy ) . '=';
							$attributes_str .= $term->name . '|';
						}
					} else {
						$attributes_str .= wc_attribute_label( $attribute['name'] ) . '=';
						$temp_attr_val = get_post_meta( $product_id, 'attribute_' . $attribute['name'], true );
						$value = $temp_attr_val;
						$taxonomy = $attribute['name'];
						$output = ARRAY_A;
						$term_info = get_term_by( $field = 'slug', $value, $taxonomy, $output );
						$temp_attr_val = $term_info['name'];
						$attributes_str .= $temp_attr_val . '|';
					}
				}
			}
			$attributes_str = trim( $attributes_str, '|' );
		} else {
			/* fecthing categories :: start */
			$categories = $this->get_assign_categories( $product_id );
			/* fecthing categories :: end */

			/* fecthing tags :: start */
			$tags = $this->get_assign_tags( $product_id );
			/* fecthing tags :: start */

			/* fecthing attributes :: start */
			$attributes_str = $this->get_assign_attributes( $product, $product_id );
			/* fecthing attributes :: end */
		}

		$woo_version = floatval( WOOCOMMERCE_VERSION );
		if ( $woo_version >= 3.0 ) {
			$reflection = new ReflectionClass( $product );
			$property = $reflection->getProperty( 'data' );
			$property->setAccessible( true );
			$_product_data = $property->getValue( $product );

			$product_type = $product->get_type();
			$post_title = $_product_data['name'];
			$post_excerpt = $_product_data['short_description'];
			$post_content = $_product_data['description'];
		} else {
			$product_type = $product->product_type;
			$post_title = $product->post->post_title;
			$post_excerpt = $product->post->post_excerpt;
			$post_content = $product->post->post_content;
		}

		$product_parent_id = 0;
		if ( $product->is_type( 'variation' ) ) {
			$product_parent_id = $product->get_parent_id();
		}

		$product_info_row[] = $product_id;
		$product_info_row[] = $product_parent_id;
		$product_info_row[] = $parent_product_sku;
		$product_info_row[] = get_post_meta( $product_id, '_sku', true );
		$product_info_row[] = ( $is_variation ) ? '' : $product_type;
		$product_info_row[] = $post_title;
		$product_info_row[] = ( $is_variation ) ? '' : $post_excerpt;
		$product_info_row[] = ( $is_variation ) ? '' : $post_content;
		$product_info_row[] = ( get_the_post_thumbnail_url( $product_id ) ) ? get_the_post_thumbnail_url( $product_id ) : '';

		$gallery_img_urls = '';
		$image_gallery = get_post_meta( $product_id, '_product_image_gallery', true );
		$image_gallery = explode( ',', $image_gallery );
		foreach ( $image_gallery as $key => $image_id ) {
			$gallery_img_urls .= wp_get_attachment_url( $image_id ) . ',';
		}
		$gallery_img_urls = trim( $gallery_img_urls, ',' );
		$product_info_row[] = $gallery_img_urls;

		$product_info_row[] = get_post_meta( $product_id, '_regular_price', true );
		$product_info_row[] = get_post_meta( $product_id, '_sale_price', true );
		$product_info_row[] = get_post_meta( $product_id, '_sale_price_dates_from', true );
		$product_info_row[] = get_post_meta( $product_id, '_sale_price_dates_to', true );

		$product_info_row[] = $categories;
		$product_info_row[] = $tags;
		$product_info_row[] = $attributes_str;

		/* Inventory Fields :: Start */
		$product_info_row[] = get_post_meta( $product_id, '_manage_stock', true );
		$product_info_row[] = get_post_meta( $product_id, '_stock', true );
		$product_info_row[] = get_post_meta( $product_id, '_backorders', true );
		$product_info_row[] = get_post_meta( $product_id, '_stock_status', true );
		$product_info_row[] = get_post_meta( $product_id, '_sold_individually', true );
		/* Inventory Fields :: End */

		/* External Product Fields :: Start */
		$product_info_row[] = get_post_meta( $product_id, '_product_url', true );
		$product_info_row[] = get_post_meta( $product_id, '_button_text', true );
		/* External Product Fields :: End */

		$product_info_row[] = get_post_meta( $product_id, '_virtual', true );
		$product_info_row[] = get_post_meta( $product_id, '_downloadable', true );

		$_downloadable_files = get_post_meta( $product_id, '_downloadable_files', true );
		$downloadable_files = '';
		if ( ! empty( $_downloadable_files ) && is_array( $_downloadable_files ) ) {
			foreach ( $_downloadable_files as $key => $value ) {
				$downloadable_files .= $value['name'] . '=' . $value['file'] . '|';
			}
		}
		$downloadable_files = trim( $downloadable_files, '|' );
		$product_info_row[] = $downloadable_files;

		$product_info_row[] = get_post_meta( $product_id, '_weight', true );
		$product_info_row[] = get_post_meta( $product_id, '_length', true );
		$product_info_row[] = get_post_meta( $product_id, '_width', true );
		$product_info_row[] = get_post_meta( $product_id, '_height', true );

		$product_info_row[] = get_post_meta( $product_id, '_purchase_note', true );
		$product_info_row[] = $post->menu_order;
		$product_info_row[] = $post->comment_status;

		return $product_info_row;
	}

	public function export_product_csv() {
		if ( isset( $_GET['teesight_action'] ) && 'export_product_csv' == $_GET['teesight_action'] ) {
			$xlxs_header = $this->get_file_header();
			$title = array_values( $xlxs_header );

			$filename = 'teesight-product-export' . date( 'H-i-s' ) . '.csv';

			header( 'Content-type: text/csv' );
			header( "Content-Disposition: attachment; filename=$filename" );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );

			$output = fopen( 'php://output', 'w' );
			fputcsv( $output, $title );

			$query_args = array(
				'post_type' => array( 'product', 'product_variation' ),
				'posts_per_page' => -1,
				'post_status' => 'publish',
			);
			$products = get_posts( $query_args );
			foreach ( $products as $key => $value ) {
				$product_info_row = array();
				$product_id = $value->ID;
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}
				if ( $product->is_type( 'simple' ) ) {

					$product_info_row = $this->make_product_row( $product_id );

					fputcsv( $output, $product_info_row );
				} elseif ( $product->is_type( 'variable' ) ) {

					$product_info_row = $this->make_product_row( $product_id );

					fputcsv( $output, $product_info_row );

					$available_variations = $product->get_available_variations();
					$parent_product_sku = get_post_meta( $product_id, '_sku', true );
					foreach ( $available_variations as $var_key => $var_value ) {
						$product_info_row = array();
						$product_id = $var_value['variation_id'];

						$product_info_row = $this->make_product_row( $product_id, true, $parent_product_sku );

						fputcsv( $output, $product_info_row );
					}
				}
			}
			die();
		}
	}

	public function get_product_has_unnecessary_design() {
		if ( isset( $_GET['teesight_action'] ) && 'clear_unnecessary_design' == $_GET['teesight_action'] ) {
			$this->remove_unnecessary_design();
		}
	}

	public function product_has_fullprint() {
		global $wpdb;
		$sql = "SELECT * FROM {$wpdb->postmeta} WHERE ( meta_key = '_product_full_print_id' && meta_value != '' )";
		$results = $wpdb->get_results( $sql, ARRAY_A ); // @codingStandardsIgnoreLine .
		$designs = array();
		if ( is_array( $results ) && ! empty( $results ) ) {
			foreach ( $results as $res ) {
				if ( isset( $res['meta_value'] ) && wp_attachment_is_image( $res['meta_value'] ) ) {
					$designs[ $res['post_id'] ] = $res['meta_value'];
				}
			}
		}
		return $designs;
	}

	public function remove_unnecessary_design() {
		$posts_has_unnecessary_design = $this->product_has_fullprint();
		if ( is_array( $posts_has_unnecessary_design ) && ! empty( $posts_has_unnecessary_design ) ) {
			foreach ( $posts_has_unnecessary_design as $post_id => $attachment_id ) {
				if ( wp_delete_attachment( $attachment_id ) ) {
					update_post_meta( $post_id, '_product_full_print_id', '' );
					update_post_meta( $post_id, '_product_full_print', '' );
				}
			}
		}
	}

}
new Teesight_Sync_Product_Export();
