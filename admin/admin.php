<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Educator_WooCommerce_Admin {
	/**
	 * @var Educator_WooCommerce_Admin
	 */
	protected static $instance;

	/**
	 * Get instance.
	 *
	 * @return Educator_WooCommerce_Admin
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta' ) );
		add_action( 'save_post', array( $this, 'update_price' ), 20 );
		add_action( 'update_option_woocommerce_currency', array( $this, 'update_currency' ), 20, 2 );
	}

	/**
	 * Add Product meta box to courses and memberships.
	 */
	public function add_meta_box() {
		foreach ( array( 'ib_educator_course', 'ib_edu_membership' ) as $screen ) {
			add_meta_box(
				'edu_wc_products',
				__( 'Product', 'ibeducator' ),
				array( $this, 'products_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Output product meta box.
	 *
	 * @param WP_Post $post
	 */
	public function products_meta_box( $post ) {
		wp_nonce_field( 'edu_wc_products', 'edu_wc_products_nonce' );

		$cur_product_id = get_post_meta( $post->ID, '_edu_wc_product', true );

		$products = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'tax_query'      => array(
				array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'simple' )
			),
		) );

		$output = '';

		if ( ! empty( $products ) ) {
			$output .= '<select name="_edu_wc_product">';
			$output .= '<option value="0"></option>';

			foreach ( $products as $product ) {
				$output .= '<option value="' . esc_attr( $product->ID ) . '"' . selected( $cur_product_id, $product->ID, false ) . '>' . esc_html( $product->post_title ) . '</option>';
			}

			$output .= '</select>';
		}

		echo $output;
	}

	/**
	 * Save post meta.
	 *
	 * @param int $post_id
	 */
	public function save_meta( $post_id ) {
		if ( ! isset( $_POST['edu_wc_products_nonce'] ) || ! wp_verify_nonce( $_POST['edu_wc_products_nonce'], 'edu_wc_products' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = get_post( $post_id );

		if ( empty( $post ) || ! in_array( $post->post_type, array( 'ib_educator_course', 'ib_edu_membership' ) ) ) {
			return;
		}

		$obj = get_post_type_object( $post->post_type );

		if ( ! $obj || ! current_user_can( $obj->cap->edit_post, $post_id ) ) {
			return;
		}

		if ( isset( $_POST['_edu_wc_product'] ) ) {
			update_post_meta( $post_id, '_edu_wc_product', intval( $_POST['_edu_wc_product'] ) );
		}
	}

	/**
	 * Update course/membership price when a
	 * related product's price is updated.
	 *
	 * @param int $post_id
	 */
	public function update_price( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post = get_post( $post_id );

		if ( empty( $post ) ) {
			return;
		}

		$obj = get_post_type_object( $post->post_type );

		if ( ! $obj || ! current_user_can( $obj->cap->edit_post, $post_id ) ) {
			return;
		}

		if ( 'product' == $post->post_type ) {
			$product = wc_get_product( $post_id );
			$product_price = $product->get_price();
			$objects = edu_wc_get_objects_by_product( $post_id );
			$ms = IB_Educator_Memberships::get_instance();

			foreach ( $objects as $object ) {
				if ( 'ib_educator_course' == $object->post_type ) {
					if ( $product_price != get_post_meta( $object->ID, '_ibedu_price', true ) ) {
						update_post_meta( $object->ID, '_ibedu_price', $product_price );
					}
				} elseif ( 'ib_edu_membership' == $object->post_type ) {
					$meta = $ms->get_membership_meta( $object->ID );

					if ( $product_price != $meta['price'] ) {
						$meta['price'] = $product_price;
						update_post_meta( $object->ID, '_ib_educator_membership', $meta );
					}
				}
			}
		} elseif ( in_array( $post->post_type, array( 'ib_educator_course', 'ib_edu_membership' ) ) ) {
			$product_id = get_post_meta( $post_id, '_edu_wc_product', true );

			if ( $product_id ) {
				$product = wc_get_product( $product_id );

				if ( $product ) {
					$product_price = $product->get_price();

					if ( 'ib_educator_course' == $post->post_type ) {
						if ( $product_price != get_post_meta( $post_id, '_ibedu_price', true ) ) {
							update_post_meta( $post_id, '_ibedu_price', $product_price );
						}
					} else {
						$meta = IB_Educator_Memberships::get_instance()->get_membership_meta( $post_id );

						if ( $product_price != $meta['price'] ) {
							$meta['price'] = $product->get_price();
							update_post_meta( $post_id, '_ib_educator_membership', $meta );
						}
					}
				}
			}
		}
	}

	/**
	 * Update Educator's currency when WooCommerce's currency changes.
	 *
	 * @param string $old_currency
	 * @param string $new_currency
	 */
	public function update_currency( $old_currency, $new_currency ) {
		$edu_settings = get_option( 'ib_educator_settings', array() );
		
		$edu_settings['currency'] = $new_currency;

		update_option( 'ib_educator_settings', $edu_settings );
	}
}
