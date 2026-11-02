<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}  ?>

<div class="options_group">
    <p class="form-field <?php echo esc_attr( $classname ); ?>">
        <label for="<?php echo esc_attr( $label_for ); ?>"><?php echo esc_html( $field_label ); ?></label>
        <select class="wc-product-search" multiple style="width: 50%;" name="<?php echo esc_attr( $field_name ); ?>"
            data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
            data-action="acfw_search_product_attributes"
            data-include="true">
            <?php foreach ( $options as $key => $label ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" selected><?php echo esc_html( $label ); ?></option>
            <?php endforeach ?>
        </select>
        <?php echo wp_kses_post( wc_help_tip( $tooltip ) ); ?>
    </p>
</div>
