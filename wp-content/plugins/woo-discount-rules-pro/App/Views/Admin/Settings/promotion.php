<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>
<tr>
    <td scope="row">
        <label for="" class="awdr-left-align"><?php esc_html_e('Enable Buy X Get Y based Cross-sell Offers', 'woo-discount-rules-pro') ?></label>
        <span class="wdr_settings_desc_text awdr-clear-both"><?php esc_html_e('NOTE: This setting applies only for Buy X Get Y - Product rule type', 'woo-discount-rules-pro'); ?></span>
    </td>
    <td>
        <?php $wdrpro_show_cross_sell_on_cart = $configuration->getConfig('show_cross_sell_on_cart', 0); ?>
        <input type="radio" name="show_cross_sell_on_cart" class="settings_option_show_hide"
               id="show_cross_sell_on_cart_1" data-name="hide_show_cross_sell_blocks"
               value="1" <?php echo($wdrpro_show_cross_sell_on_cart == 1 ? 'checked' : '') ?>><label
                for="show_cross_sell_on_cart_1"><?php esc_html_e('Yes', 'woo-discount-rules-pro'); ?></label>

        <input type="radio" name="show_cross_sell_on_cart" class="settings_option_show_hide"
               id="show_cross_sell_on_cart_2" data-name="hide_show_cross_sell_blocks"
               value="0" <?php echo($wdrpro_show_cross_sell_on_cart == 0 ? 'checked' : '') ?>><label
                for="show_cross_sell_on_cart_2"><?php esc_html_e('No', 'woo-discount-rules-pro'); ?></label>
    </td>
</tr>
<tr class="hide_show_cross_sell_blocks" style="<?php echo (!$wdrpro_show_cross_sell_on_cart) ? 'display:none' : ''; ?>">
    <td scope="row">
        <label for="cross_sell_on_cart_limit" class="awdr-left-align"><?php esc_html_e('Number of Products', 'woo-discount-rules-pro') ?></label>
        <span class="wdr_settings_desc_text awdr-clear-both"><?php esc_html_e('Number of items to show', 'woo-discount-rules-pro'); ?></span>
    </td>
    <td>
        <input name="cross_sell_on_cart_limit" type="number" id="cross_sell_on_cart_limit" value="<?php echo esc_attr($configuration->getConfig('cross_sell_on_cart_limit', 2)); ?>" placeholder="2"/>
    </td>
</tr>
<tr class="hide_show_cross_sell_blocks" style="<?php echo (!$wdrpro_show_cross_sell_on_cart) ? 'display:none' : ''; ?>">
    <td scope="row">
        <label for="cross_sell_on_cart_column" class="awdr-left-align"><?php esc_html_e('Columns', 'woo-discount-rules-pro') ?></label>
        <span class="wdr_settings_desc_text awdr-clear-both"><?php esc_html_e('Number of columns', 'woo-discount-rules-pro'); ?></span>
    </td>
    <td>
        <input name="cross_sell_on_cart_column" id="cross_sell_on_cart_column" type="number" value="<?php echo esc_attr($configuration->getConfig('cross_sell_on_cart_column', 2)); ?>" placeholder="2"/>
    </td>
</tr>
<tr class="hide_show_cross_sell_blocks" style="<?php echo (!$wdrpro_show_cross_sell_on_cart) ? 'display:none' : ''; ?>">
    <td scope="row">
        <label for="cross_sell_on_cart_order_by" class="awdr-left-align"><?php esc_html_e('Sorting Order', 'woo-discount-rules-pro') ?></label>
        <span class="wdr_settings_desc_text awdr-clear-both"><?php esc_html_e('Sorting order', 'woo-discount-rules-pro'); ?></span>
    </td>
    <td>
        <?php $wdrpro_cross_sell_on_cart_order_by = $configuration->getConfig('cross_sell_on_cart_order_by', 'rand'); ?>
        <select name="cross_sell_on_cart_order_by" id="cross_sell_on_cart_order_by">
            <option value="rand"<?php echo ($wdrpro_cross_sell_on_cart_order_by == 'rand')? " selected": '' ?>><?php esc_html_e('Random ordering', 'woo-discount-rules-pro'); ?></option>
            <option value="menu_order"<?php echo ($wdrpro_cross_sell_on_cart_order_by == 'menu_order')? " selected": '' ?>><?php esc_html_e('Menu based ordering', 'woo-discount-rules-pro'); ?></option>
            <option value="price"<?php echo ($wdrpro_cross_sell_on_cart_order_by == 'price')? " selected": '' ?>><?php esc_html_e('Price based ordering', 'woo-discount-rules-pro'); ?></option>
        </select>
    </td>
</tr>
<tr class="hide_show_cross_sell_blocks" style="<?php echo (!$wdrpro_show_cross_sell_on_cart) ? 'display:none' : ''; ?>">
    <td scope="row">
        <label for="cross_sell_on_cart_order" class="awdr-left-align"><?php esc_html_e('Ordering', 'woo-discount-rules-pro') ?></label>
        <span class="wdr_settings_desc_text awdr-clear-both"><?php esc_html_e('Ascending or descending order of items', 'woo-discount-rules-pro'); ?></span>
    </td>
    <td>
        <?php $wdrpro_cross_sell_on_cart_order = $configuration->getConfig('cross_sell_on_cart_order', 'desc'); ?>
        <select name="cross_sell_on_cart_order" id="cross_sell_on_cart_order">
            <option value="desc"<?php echo ($wdrpro_cross_sell_on_cart_order == 'desc')? " selected": '' ?>><?php esc_html_e('Desc', 'woo-discount-rules-pro'); ?></option>
            <option value="asc"<?php echo ($wdrpro_cross_sell_on_cart_order == 'asc')? " selected": '' ?>><?php esc_html_e('Asc', 'woo-discount-rules-pro'); ?></option>
        </select>
    </td>
</tr>
