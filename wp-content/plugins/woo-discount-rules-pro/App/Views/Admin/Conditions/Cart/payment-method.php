<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
$wdrpro_operator = isset($options->operator) ? $options->operator : 'in_list';
$wdrpro_values = isset($options->value) ? $options->value : false;
echo ($render_saved_condition == true) ? '' : '<div class="cart_payment_method">';
$wdrpro_settings_controller = new \Wdr\App\Controllers\Admin\Settings();
$wdrpro_available_payment_methods = $wdrpro_settings_controller->getPaymentMethod();
?>
    <div class="wdr_cart_payment_method_group wdr-condition-type-options">
        <div class="wdr-cart-subtotal wdr-select-filed-hight">
            <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][operator]" class="awdr-left-align">
                <option value="in_list" <?php echo ($wdrpro_operator == "in_list") ? "selected" : ""; ?>><?php esc_html_e('In List', 'woo-discount-rules-pro'); ?></option>
                <option value="not_in_list" <?php echo ($wdrpro_operator == "not_in_list") ? "selected" : ""; ?>><?php esc_html_e('Not In List', 'woo-discount-rules-pro'); ?></option>
            </select>
            <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('Select by', 'woo-discount-rules-pro'); ?></span>
        </div>

        <div class="cart-subtotal-value wdr-select-filed-hight wdr-search-box">
            <select multiple
                    class="<?php echo ($render_saved_condition == true) ? 'edit-all-loaded-values' : '' ?>"
                    data-list="payment_methods"
                    data-field="autoloaded"
                    data-placeholder="<?php esc_attr_e('Search Payment Method', 'woo-discount-rules-pro');?>"
                    name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][value][]">
                <?php
                if ($wdrpro_values) {
                    foreach ($wdrpro_values as $wdrpro_value) {
                        foreach ($wdrpro_available_payment_methods as $wdrpro_payment_method) {
                            if ($wdrpro_payment_method['id'] == $wdrpro_value) {
                                $wdrpro_payment_method_name = $wdrpro_payment_method['text'];
                            }
                        } ?>
                        <option value="<?php echo esc_attr($wdrpro_value); ?>" selected><?php echo esc_html($wdrpro_payment_method_name); ?></option>
                    <?php }
                }
                ?>
            </select>
            <span class="wdr_select2_desc_text"><?php esc_html_e('Select payment method', 'woo-discount-rules-pro'); ?></span>
        </div>
    </div>
<?php echo ($render_saved_condition == true) ? '' : '</div>'; ?>