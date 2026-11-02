<?php
/**
 * Discount table
 *
 * This template can be overridden by copying it to yourtheme/advanced_woo_discount_rules/discount_table.php.
 *
 * HOWEVER, on occasion Discount rules will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 */
if (!defined('ABSPATH')) exit; // Exit if accessed directly

if (!empty($ranges) && !empty($woocommerce)) {
    if ($ranges['layout']['type'] == 'advanced') {
        $awdr_i = 0;
        $awdr_existing_rule_id = 0;
        $awdr_tag_opened = false;
        /* This have been added from v2.3.9 */
        if ( ! did_action( 'advanced_woo_discount_rules_before_display_discount_bar' ) ) {
            do_action('advanced_woo_discount_rules_before_display_discount_bar');
            ?>
            <style>
                .awdr_discount_bar{
                    padding: 10px;
                    margin-bottom: 10px;
                    border-radius: 4px;
                }
            </style>
            <?php
        }
        foreach ($ranges as $awdr_key => $awdr_badge_settings){
            if($awdr_key !== 'layout'){
                $awdr_current_rule_id = isset($awdr_badge_settings['rule_id'])? $awdr_badge_settings['rule_id'] : '';
                $awdr_badge_bg_color = (!empty($awdr_badge_settings['badge_bg_color'])) ? $awdr_badge_settings['badge_bg_color'] : false;
                $awdr_badge_text_color = (!empty($awdr_badge_settings['badge_text_color'])) ? $awdr_badge_settings['badge_text_color'] : false;
                $awdr_badge_text = (!empty($awdr_badge_settings['badge_text'])) ? htmlspecialchars_decode($awdr_badge_settings['badge_text']) : '';
                if($awdr_current_rule_id !== $awdr_existing_rule_id){
                    $awdr_tag_opened = true;
                    if($awdr_existing_rule_id !== 0){
                        ?>
                        </div>
                        <?php
                    }
                    $awdr_existing_rule_id = $awdr_current_rule_id;
                    ?>

                    <div class="awdr_discount_bar awdr_row_<?php echo esc_attr($awdr_i); ?>" style="<?php if($awdr_badge_bg_color){
                        echo "background-color:". esc_attr($awdr_badge_bg_color) . ';';
                    }if($awdr_badge_text_color) {
                        echo "color:". esc_attr($awdr_badge_text_color) . ';';
                    }?>">
                    <?php
                }
                ?>
                <div class="awdr_discount_bar_content">
                    <?php echo wp_kses_post($awdr_badge_text);?>
                </div>
                <?php
                $awdr_i++;
            }
        }
        if($awdr_tag_opened){
            ?>
            </div>
            <?php
        }
    } elseif ($ranges['layout']['type'] == 'default') {
        if(isset($ranges['layout']['bulk_variant_table']) && $ranges['layout']['bulk_variant_table'] == "default_variant_empty"){?>
            <div class="awdr-bulk-customizable-table"> </div><?php
        }else{
            $awdr_tbl_title = $base::$config->getConfig('customize_bulk_table_title', 0);
            $awdr_tbl_discount = $base::$config->getConfig('customize_bulk_table_discount', 2);
            $awdr_tbl_range = $base::$config->getConfig('customize_bulk_table_range', 1);

            $awdr_tbl_title_text = $base::$config->getConfig('table_title_column_name', 'Title');
            $awdr_tbl_discount_text = $base::$config->getConfig('table_discount_column_name', 'Discount');
            $awdr_tbl_range_text = $base::$config->getConfig('table_range_column_name', 'Range');

            $awdr_table_sort_by_columns = array(
                'tbl_title' => $awdr_tbl_title,
                'tbl_discount' => $awdr_tbl_discount,
                'tbl_range' => $awdr_tbl_range,
            );
            asort($awdr_table_sort_by_columns); ?>
            <div class="awdr-bulk-customizable-table">
            <table id="sort_customizable_table" class="wdr_bulk_table_msg sar-table">
                <thead class="wdr_bulk_table_thead">
                <tr class="wdr_bulk_table_tr wdr_bulk_table_thead" style="<?php echo (!$base::$config->getConfig('table_column_header', 1) ? 'display:none' : '')?>">
                    <?php foreach ($awdr_table_sort_by_columns as $awdr_column => $awdr_order) {
                        if ($awdr_column == "tbl_title") {
                            ?>
                        <th id="customize-bulk-table-title" class="wdr_bulk_table_td awdr-dragable"
                            style="<?php if(!$base::$config->getConfig('table_column_header', 0)){
                                echo 'display:none';
                            }else{
                                echo((!$base::$config->getConfig('table_title_column', 0)) ? 'display:none' : '');
                            } ?>"><span><?php esc_html_e($awdr_tbl_title_text, 'woo-discount-rules')//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></span>
                            </th><?php
                        } elseif ($awdr_column == "tbl_discount") {
                            ?>
                        <th id="customize-bulk-table-discount" class="wdr_bulk_table_td awdr-dragable"
                            style="<?php if(!$base::$config->getConfig('table_column_header', 0)){
                                echo 'display:none';
                            }else{
                                echo((!$base::$config->getConfig('table_discount_column', 0)) ? 'display:none' : '');
                            } ?>"><span><?php esc_html_e($awdr_tbl_discount_text, 'woo-discount-rules')//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></span>
                            </th><?php
                        } else {
                            ?>
                        <th id="customize-bulk-table-range" class="wdr_bulk_table_td awdr-dragable"
                            style="<?php if(!$base::$config->getConfig('table_column_header', 0)){
                                echo 'display:none';
                            }else{
                                echo((!$base::$config->getConfig('table_range_column', 0)) ? 'display:none' : '');
                            }?>"><span><?php esc_html_e($awdr_tbl_range_text, 'woo-discount-rules')//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></span></th><?php
                        }
                    }?>
                </tr>
                </thead>
                <tbody><?php
                foreach ($ranges as $awdr_range) :
                    $awdr_cart_discount_text = '';
                    $awdr_discount_type_value = isset($awdr_range['discount_value']) ? $awdr_range['discount_value'] : 0;
                    if (!isset($awdr_range['discount_value'])){
                        continue;
                    }
                    ?>
                    <tr class="wdr_bulk_table_tr bulk_table_row">
                        <?php
                        /**
                         * Discount value
                         */

                        if (isset($awdr_range['discount_method']) && $awdr_range['discount_method'] == 'cart') {
                            $awdr_cart_discount_text = __(' (in cart)', 'woo-discount-rules');
                        }
                        $awdr_discount_type = isset($awdr_range['discount_type']) ? $awdr_range['discount_type'] : 'flat';
                        if ($awdr_discount_type == "flat") {
                            $awdr_discount_value = $woocommerce->formatPrice($awdr_discount_type_value);
                            $awdr_discount_value .= __(' flat', 'woo-discount-rules');
                            $awdr_discount_value .= !empty($awdr_cart_discount_text) ? $awdr_cart_discount_text : '';
                        } elseif ($awdr_discount_type == "percentage") {
                            $awdr_discount_value = isset($awdr_range['discount_value']) ? $awdr_range['discount_value'] : 0;
                            $awdr_discount_value .= '%';
                            $awdr_discount_value .= !empty($awdr_cart_discount_text) ? $awdr_cart_discount_text : '';
                        } else {
                            $awdr_discount_value = $woocommerce->formatPrice($awdr_discount_type_value);
                        }

                        if (isset($awdr_range['discount_method']) && $awdr_range['discount_method'] != 'cart') {
                            $awdr_discounted_price_for_customizer = $woocommerce->formatPrice(isset($awdr_range['discounted_price']) ? $awdr_range['discounted_price'] : 0);
                        }else{
                            $awdr_discounted_price_for_customizer = $awdr_discount_value;
                        }
                        /**
                         * Discount Range
                         */
                        if (isset($awdr_range['discount_method']) && $awdr_range['discount_method'] == 'set') {
                            $awdr_for_text = '';
                        } else {
                            $awdr_for_text = ' +';
                        }
                        if (isset($awdr_range['from']) && !empty($awdr_range['from']) && isset($awdr_range['to']) && !empty($awdr_range['to'])) {
                            if($awdr_range['from'] == $awdr_range['to']) {
                                $awdr_discount_range = $awdr_range['from'];
                            } else {
                                $awdr_discount_range = $awdr_range['from'] . ' - ' . $awdr_range['to'];
                            }
                        } elseif (isset($awdr_range['from']) && !empty($awdr_range['from']) && isset($awdr_range['to']) && empty($awdr_range['to'])) {
                            $awdr_discount_range = $awdr_range['from']. $awdr_for_text;
                        } elseif (isset($awdr_range['from']) && empty($awdr_range['from']) && isset($awdr_range['to']) && !empty($awdr_range['to'])) {
                            $awdr_discount_range =  '0 - ' . $awdr_range['to'];
                        } elseif (isset($awdr_range['from']) && empty($awdr_range['from']) && isset($awdr_range['to']) && empty($awdr_range['to'])) {
                            $awdr_discount_range = '';
                        }?><?php
                        /**
                         * Table Data <td>'s
                         */
                        $awdr_j=1;
                        foreach ($awdr_table_sort_by_columns as $awdr_column => $awdr_order) {
                            if ($awdr_column == "tbl_title") {?>
                            <td class="wdr_bulk_table_td wdr_bulk_title  col_index_<?php echo esc_attr($awdr_j);?>" data-colindex="<?php echo esc_attr($awdr_j);?>"
                                style="<?php echo (!$base::$config->getConfig('table_title_column', 0)) ? 'display:none' : '';?>">
                                <?php echo isset($awdr_range['rule_title']) ? esc_html($awdr_range['rule_title']) : '-' ?>
                                </td><?php

                            } elseif ($awdr_column == "tbl_discount") {?>
                            <td class="wdr_bulk_table_td wdr_bulk_table_discount  col_index_<?php echo esc_attr($awdr_j);?>" data-colindex="<?php echo esc_attr($awdr_j);?>"
                                style="<?php echo (!$base::$config->getConfig('table_discount_column', 0)) ? 'display:none' : '';?>">
                                <span class="wdr_table_discounted_value" style="<?php echo ( !$base::$config->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php echo wp_kses_post($awdr_discount_value); ?></span>
                                <span class="wdr_table_discounted_price" style="<?php echo ( $base::$config->getConfig('table_discount_column_value', 0)) ? 'display: none' : '';?>"><?php echo wp_kses_post($awdr_discounted_price_for_customizer); ?></span>
                                </td><?php
                            } else {?>
                                <td class="wdr_bulk_table_td wdr_bulk_range  col_index_<?php echo esc_attr($awdr_j);?>" data-colindex="<?php echo esc_attr($awdr_j);?>"
                                    style="<?php echo (!$base::$config->getConfig('table_range_column', 0) || isset($awdr_range['discount_method']) && in_array($awdr_range['discount_method'], array('product', 'cart'))) ? 'display:none':'';?>"><?php echo esc_html($awdr_discount_range); ?></td><?php
                            }
                            $awdr_j++;
                        }?>
                    </tr>
                <?php
                endforeach;
                ?>
                </tbody>
            </table>
            </div><?php
        }
    }
}