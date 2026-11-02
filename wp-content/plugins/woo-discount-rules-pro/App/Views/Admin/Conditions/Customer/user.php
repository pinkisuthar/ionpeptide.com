<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
$wdrpro_operator = isset($options->operator) ? $options->operator : 'in_list';
$wdrpro_values = isset($options->value) ? $options->value : false;
echo ($render_saved_condition == true) ? '' : '<div class="user_list">';
?>
<div class="wdr_user_list_group wdr-condition-type-options">
    <div class="wdr_operator wdr-select-filed-hight">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][operator]" class="awdr-left-align">
            <option value="in_list" <?php echo ($wdrpro_operator == "in_list") ? "selected" : ""; ?>><?php esc_html_e('in list', 'woo-discount-rules-pro') ?></option>
            <option value="not_in_list" <?php echo ($wdrpro_operator == "not_in_list") ? "selected" : ""; ?>><?php esc_html_e('not in list', 'woo-discount-rules-pro') ?></option>
        </select>
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('User should be', 'woo-discount-rules-pro'); ?></span>
    </div>

    <div class="wdr_value wdr-select-filed-hight wdr-search-box">
        <select multiple
                class="wdr_user_list <?php echo ($render_saved_condition == true) ? 'edit-filters' : ''; ?>"
                data-list="users_list"
                data-field="autocomplete"
                data-placeholder="<?php esc_attr_e('Search User', 'woo-discount-rules-pro');?>"
                name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][value][]"><?php
            if ($wdrpro_values) {
                $wdrpro_users = get_users(array('include' => $wdrpro_values, 'fields' => array('ID', 'user_nicename'), 'orderby' => 'user_nicename'));
                foreach ($wdrpro_users as $wdrpro_user) {
                    if (isset($wdrpro_user->user_nicename) && $wdrpro_user->user_nicename != '') {
                        ?>
                        <option value="<?php echo esc_attr($wdrpro_user->ID) ?>" selected><?php echo esc_html($wdrpro_user->user_nicename); ?></option>
                        <?php
                    }
                }
            }
            ?>
        </select>
        <span class="wdr_select2_desc_text"><?php esc_html_e('Select User', 'woo-discount-rules-pro'); ?></span>
    </div>
</div>
<?php echo ($render_saved_condition == true) ? '' : '</div>'; ?>
