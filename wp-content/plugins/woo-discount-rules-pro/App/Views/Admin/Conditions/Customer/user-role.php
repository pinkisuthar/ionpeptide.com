<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
$wdrpro_operator = isset($options->operator) ? $options->operator : 'in_list';
$wdrpro_values = isset($options->value) ? $options->value : false;
$wdrpro_settings = new \Wdr\App\Controllers\Admin\Settings();
$wdrpro_user_roles = $wdrpro_settings->getUserRoles();
echo ($render_saved_condition == true) ? '' : '<div class="user_role">';
?>
<div class="wdr_user_role_group wdr-condition-type-options">
    <div class="wdr-select-filed-hight">
        <select name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][operator]" class="awdr-left-align">
            <option value="in_list" <?php echo ($wdrpro_operator == "in_list") ? "selected" : ""; ?>><?php esc_html_e('in list', 'woo-discount-rules-pro') ?></option>
            <option value="not_in_list" <?php echo ($wdrpro_operator == "not_in_list") ? "selected" : ""; ?>><?php esc_html_e('not in list', 'woo-discount-rules-pro') ?></option>
        </select>
        <span class="wdr_desc_text awdr-clear-both "><?php esc_html_e('user role should be', 'woo-discount-rules-pro'); ?></span>
    </div>

    <div class="wdr-select-filed-hight wdr-search-box">
        <select multiple
                class="wdr_user_role <?php echo ($render_saved_condition == true) ? 'edit-all-loaded-values' : '' ?>"
                data-list="user_roles"
                data-field="autoloaded"
                data-placeholder="<?php esc_attr_e('Search User Roles', 'woo-discount-rules-pro');?>"
                name="conditions[<?php echo (isset($i)) ? esc_attr($i) : '{i}' ?>][options][value][]"><?php
            if ($wdrpro_values) {
                foreach ($wdrpro_values as $wdrpro_value) {
                    foreach ($wdrpro_user_roles as $wdrpro_user_key => $wdrpro_user_role) {
                        if ($wdrpro_user_role['id'] == $wdrpro_value) {
                            ?>
                            <option value="<?php echo esc_attr($wdrpro_value); ?>" selected><?php echo esc_html($wdrpro_user_role['text']); ?></option>
                            <?php
                        }
                    }
                }
            }
            ?>
        </select>
        <span class="wdr_select2_desc_text"><?php esc_html_e('Select User Roles', 'woo-discount-rules-pro'); ?></span>
    </div>
</div>
<?php echo ($render_saved_condition == true) ? '' : '</div>'; ?>
