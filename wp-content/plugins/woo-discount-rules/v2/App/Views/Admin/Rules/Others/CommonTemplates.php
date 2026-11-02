<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
/**
 * filter selector
 * condition selector
 * discount selector
 */
?>

<div id="templates" style="display: none;">
    <div class="wdr-icon-remove">
        <div class="wdr-btn-remove wdr_filter_remove">
            <span class="dashicons dashicons-no-alt remove-current-row"></span>
        </div>
    </div>
    <?php $awdr_product_filters = $base->getProductFilterTypes(); ?>
    <div class="wdr-build-filter-type">
        <div class="wdr-filter-type">
            <select name="filters[{i}][type]" class="wdr-product-filter-type"><?php
                if (isset($awdr_product_filters) && !empty($awdr_product_filters)) {
                    foreach ($awdr_product_filters as $awdr_filter_key => $awdr_filter_value) {
                        ?>
                        <optgroup label="<?php echo esc_attr($awdr_filter_key); ?>"><?php
                        foreach ($awdr_filter_value as $awdr_key => $awdr_value) {
                            ?>
                            <option
                            <?php
                            if(isset($awdr_value['active']) && $awdr_value['active'] == false){
                                ?>
                                disabled="disabled"
                                <?php
                            } else {
                                ?>
                                value="<?php echo esc_attr($awdr_key); ?>"
                                <?php
                            }
                            ?>
                             <?php if ($awdr_key == 'products') {
                                echo 'selected';
                            } ?>><?php esc_html_e($awdr_value['label'], 'woo-discount-rules');//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText  ?></option><?php
                        } ?>
                        </optgroup><?php
                    }
                } ?>
            </select>
        </div>
    </div>
    <?php $awdr_product_filter_templates = $base->getFilterTemplatesContent();
    if (isset($awdr_product_filter_templates) && !empty($awdr_product_filter_templates)) {
        foreach ($awdr_product_filter_templates as $awdr_filter_template) {
            echo wp_kses($awdr_filter_template,[
               'div' => [
                    'class' => [],
                    'id' => [],
                ],
                'span' => [
                    'class' => [],
                ],
                'select' => [
                    'name' => [],
                    'class' => [],
                    'multiple' => [],
                    'data-placeholder' => [],
                    'data-action' => [],
                    'tabindex' => [],
                    'style' => [],
                    'data-list' => [],
                    'data-field' => [],
                    'data-taxonomy' => [],
                ],
                'option' => [
                    'value' => [],
                    'selected' => [],
                ],
            ]);
        }
    }
    $awdr_product_conditions = $base->getProductConditionsTypes();
    ?>
    <div class="wdr-build-condition-type">
        <div class="wdr-condition-type">
            <select name="conditions[{i}][type]" class="wdr-product-condition-type awdr-left-align"><?php
                if (isset($awdr_product_conditions) && !empty($awdr_product_conditions)) {
                    foreach ($awdr_product_conditions as $awdr_condition_key => $awdr_condition_value) {
                        ?>
                        <optgroup label="<?php echo esc_attr($awdr_condition_key); ?>"><?php
                        foreach ($awdr_condition_value as $awdr_key => $awdr_value) {
                            ?>
                            <option class="<?php echo ( $awdr_key == 'cart_item_product_onsale') ? 'wdr-hide awdr-free-shipping-special-condition' : ''; ?>"
                            <?php
                            if(isset($awdr_value['enable']) && $awdr_value['enable'] === false){
                                ?>
                                disabled="disabled"
                                <?php
                            } else {
                                ?>
                                value="<?php echo esc_attr($awdr_key); ?>"
                                <?php
                            }
                            ?>
                             <?php if ($awdr_key == 'products') {
                                echo 'selected';
                            } ?>><?php esc_html_e($awdr_value['label'], 'woo-discount-rules');//phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></option><?php
                        } ?>
                        </optgroup><?php
                    }
                } ?>
            </select>
            <span class="wdr_desc_text awdr-clear-both"><?php esc_html_e('Condition Type', 'woo-discount-rules'); ?></span>
        </div>
    </div>
    <?php $awdr_product_conditions_templates = $base->getConditionsTemplatesContent();
    if (isset($awdr_product_conditions_templates) && !empty($awdr_product_conditions_templates)) {
        foreach ($awdr_product_conditions_templates as $awdr_conditions_template) {
            echo wp_kses($awdr_conditions_template,[
		        'div' => [
			        'class' => [],
			        'id' => [],
                    'style' => [],
		        ],
		        'span' => [
			        'class' => [],
			        'style' => [],
		        ],
		        'select' => [
			        'name' => [],
			        'class' => [],
                    'id' => [],
                    'aria-hidden'=>[],
			        'multiple' => [],
			        'data-placeholder' => [],
			        'data-action' => [],
			        'tabindex' => [],
			        'style' => [],
			        'data-list' => [],
			        'data-field' => [],
			        'data-taxonomy' => [],
		        ],
		        'option' => [
			        'style' => [],
			        'value' => [],
			        'selected' => [],
		        ],
                'input' => [
                    'name' => [],
                    'type' => [],
                    'class' => [],
                    'value' => [],
                    'placeholder' => [],
                    'min' => [],
                    'max' => [],
                    'autocomplete' => [],
                    'data-class' => [],
                    'data-field' => [],
                    'style' => [],
                ],
                'optgroup' => [
                    'label' => []
                ]
	        ]);
        }
    }
    $render_saved_condition = false;//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- shared template variable, read by pro add-on condition templates
    include'SubtotalPromotion.php';
    include'QuantityPromotion.php'; ?>
</div>