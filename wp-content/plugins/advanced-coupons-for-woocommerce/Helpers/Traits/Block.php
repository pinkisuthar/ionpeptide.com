<?php
namespace ACFWP\Helpers\Traits;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait that houses all the helper functions specifically for Block.
 *
 * @since 3.5.9
 */
trait Block {
    /**
     * Check if the current page is using cart block.
     *
     * @since 3.5.9
     * @access public
     *
     * @return bool Returns true if the cart page is using blocks, false otherwise.
     */
    public function is_cart_block() {
        global $post;

        // Bail early if post is not set.
        if ( ! $post instanceof \WP_Post ) {
            return false;
        }

        // Check if the content is using regular cart and checkout block shortcode.
        if ( has_shortcode( $post->post_content, 'woocommerce_cart' ) || has_shortcode( $post->post_content, 'woocommerce_checkout' ) ) {
            return false;
        }

        // check if page using cart or checkout block.
        $blocks = parse_blocks( $post->post_content );
        foreach ( $blocks as $block ) {
            if ( 'woocommerce/cart' === $block['blockName'] || 'woocommerce/checkout' === $block['blockName'] ) {
                return true;
            }
        }

        return false;
    }
}
