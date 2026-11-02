<?php if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// Exit if accessed directly. ?>

<select name="acfw_feature_filter" id="acfw_feature_filter">
    <option value=""><?php echo esc_html__( 'Show all features', 'advanced-coupons-for-woocommerce-free' ); ?></option>
    
    <?php foreach ( $terms as $feature_term ) : ?>
        <?php
        $term_name = $this->_get_feature_display_name( $feature_term->slug );

        // Skip if validation failed and returned empty name.
        if ( empty( $term_name ) ) {
            continue;
        }
        ?>

        <option value="<?php echo esc_attr( $feature_term->slug ); ?>" <?php selected( $selected, $feature_term->slug, true ); ?>>
            <?php echo esc_html( $term_name ); ?>
        </option>
    <?php endforeach; ?>
</select>
