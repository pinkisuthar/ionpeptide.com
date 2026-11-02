<?php
if (!defined('ABSPATH')) exit;

use Wdr\App\Models\DBTable;
use Wdr\App\Helpers\Helper;

$awdr_is_pro_activated = isset($is_pro_activated) ? $is_pro_activated : false;
?>
<br>
<div class="wdr_settings ui-page-theme-a awdr-container">
    <div class="wdr_settings_container" style="border-bottom: 1px solid black; padding-bottom: 10px;">
        <div>
            <h3><?php esc_html_e('Export tool', 'woo-discount-rules'); ?></h3>
            <div>
                <p>
                <form method="post">
                    <input type="hidden" name="security" value="<?php echo esc_attr(wp_create_nonce('awdr_export_rules')) ?>">
                    <button type="submit" id="wdr-export" name="wdr-export" class="button button-primary">
                        <?php esc_html_e('Export', 'woo-discount-rules'); ?>
                    </button>
                </form>
                </p>
            </div>
        </div>
    </div>
    <?php if ($awdr_is_pro_activated) { ?>
        <div class="wdr_settings_container">
        <div>
            <h3><?php esc_html_e('Import Tool', 'woo-discount-rules'); ?></h3>
            <div><?php
                $awdr_message = '';
                if (isset($_POST['wdr-import']) && isset($_FILES["awdr_import_rule"]) && isset($_POST['security'])) {
                    //check for nonce, before
                    if (!empty($_POST['security']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['security'])), 'awdr_import_rules_csv')) {
                        $awdr_originalFileName = !empty($_FILES['awdr_import_rule']['name']) ? sanitize_file_name($_FILES['awdr_import_rule']['name']) : '';
                        $awdr_fileExtension = pathinfo($awdr_originalFileName, PATHINFO_EXTENSION);
                        //check for valid file extension
                        global $wp_filesystem;
                        if (!function_exists('WP_Filesystem')) {
                            require_once ABSPATH . 'wp-admin/includes/file.php';
                        }
                        WP_Filesystem();

                        if (strtolower($awdr_fileExtension) == "csv") {
                            $awdr_fileName = !empty($_FILES["awdr_import_rule"]["tmp_name"]) ? sanitize_text_field($_FILES["awdr_import_rule"]["tmp_name"]) : '';
                            $awdr_originalFileType = !empty($_FILES["awdr_import_rule"]["type"]) ? sanitize_mime_type($_FILES["awdr_import_rule"]["type"]) : '';
                            $awdr_valid_csv_mime_types = array('application/vnd.ms-excel', 'text/plain', 'text/csv', 'text/tsv');
                            //Check for valid mime type
                            if (isset($_FILES["awdr_import_rule"]["size"]) && $_FILES["awdr_import_rule"]["size"] > 0 && in_array($awdr_originalFileType, $awdr_valid_csv_mime_types)) {
                                $awdr_file_content = '';
                                if ($wp_filesystem->exists($awdr_fileName)) {
                                    $awdr_file_content = $wp_filesystem->get_contents($awdr_fileName);
                                }
                                if (!empty($awdr_file_content)) {
                                    $awdr_current_date_time = '';
                                    if (function_exists('current_time')) {
                                        $awdr_current_time = current_time('timestamp', true);
                                        $awdr_current_date_time = gmdate('Y-m-d H:i:s', $awdr_current_time);
                                    }

                                    $current_user = get_current_user_id();
                                    $awdr_csv_separator = apply_filters('advanced_woo_discount_rules_csv_import_export_separator', ',');
                                    $awdr_csv_length = apply_filters('advanced_woo_discount_rules_csv_length_for_import', 100000);

                                    // Convert file content into lines
                                    $awdr_lines = explode(PHP_EOL, $awdr_file_content);
                                    $awdr_i = 1;

                                    foreach ($awdr_lines as $awdr_line) {
                                        if (empty($awdr_line)) {
                                            continue;
                                        }
	                                    $awdr_column = str_getcsv($awdr_line, $awdr_csv_separator, '"', '\\');
                                        if ($awdr_i == 1) {
                                            $awdr_i++;
                                            continue;
                                        }
                                        $awdr_rule_id = intval(isset($awdr_column[0]) ? $awdr_column[0] : NULL);
                                        $awdr_enabled = intval(isset($awdr_column[1]) ? $awdr_column[1] : 0);
                                        $awdr_deleted = intval(isset($awdr_column[2]) ? $awdr_column[2] : 0);
                                        $awdr_exclusive = intval(isset($awdr_column[3]) ? $awdr_column[3] : 0);
                                        $title = sanitize_text_field(isset($awdr_column[4]) ? $awdr_column[4] : "Untitled Rule");
                                        $awdr_priority = intval(isset($awdr_column[5]) ? $awdr_column[5] : $awdr_rule_id);
                                        $awdr_apply_to = isset($awdr_column[6]) ? $awdr_column[6] : NULL;
                                        $awdr_filters = isset($awdr_column[7]) ? $awdr_column[7] : array();
                                        $awdr_filters = wp_json_encode(Helper::sanitizeJson($awdr_filters));
                                        $awdr_conditions = isset($awdr_column[8]) ? $awdr_column[8] : array();
                                        $awdr_conditions = wp_json_encode(Helper::sanitizeJson($awdr_conditions));
                                        $awdr_product_adjustments = isset($awdr_column[9]) ? $awdr_column[9] : array();
                                        $awdr_product_adjustments = wp_json_encode(Helper::sanitizeJson($awdr_product_adjustments));
                                        $awdr_cart_adjustment = isset($awdr_column[10]) ? $awdr_column[10] : array();
                                        $awdr_cart_adjustment = wp_json_encode(Helper::sanitizeJson($awdr_cart_adjustment));
                                        $awdr_buy_x_get_x = isset($awdr_column[11]) ? $awdr_column[11] : array();
                                        $awdr_buy_x_get_x = wp_json_encode(Helper::sanitizeJson($awdr_buy_x_get_x));
                                        $awdr_buy_x_get_y = isset($awdr_column[12]) ? $awdr_column[12] : array();
                                        $awdr_buy_x_get_y = wp_json_encode(Helper::sanitizeJson($awdr_buy_x_get_y));
                                        $awdr_bulk_adjustment = isset($awdr_column[13]) ? $awdr_column[13] : array();
                                        $awdr_bulk_adjustment = wp_json_encode(Helper::sanitizeJson($awdr_bulk_adjustment));
                                        $awdr_set_adjustment = isset($awdr_column[14]) ? $awdr_column[14] : array();
                                        $awdr_set_adjustment = wp_json_encode(Helper::sanitizeJson($awdr_set_adjustment));
                                        $awdr_other_discount = isset($awdr_column[15]) ? $awdr_column[15] : NULL;
                                        $awdr_date_from = isset($awdr_column[16]) && !empty($awdr_column[16]) ? intval($awdr_column[16]) : NULL;
                                        $awdr_date_to = isset($awdr_column[17]) && !empty($awdr_column[16]) ? intval($awdr_column[17]) : NULL;
                                        $awdr_usage_limits = intval(isset($awdr_column[18]) ? $awdr_column[18] : 0);
                                        $awdr_rule_language = isset($awdr_column[19]) ? $awdr_column[19] : array();
                                        $awdr_rule_language = wp_json_encode(Helper::sanitizeJson($awdr_rule_language));
                                        $awdr_used_limits = intval(isset($awdr_column[20]) ? $awdr_column[20] : 0);
                                        $awdr_additional = isset($awdr_column[21]) ? $awdr_column[21] : array('condition_relationship' => 'and');
                                        $awdr_additional = wp_json_encode(Helper::sanitizeJson($awdr_additional));
                                        $awdr_max_discount_sum = intval(isset($awdr_column[22]) ? $awdr_column[22] : NULL);
                                        $advanced_discount_message = isset($awdr_column[23]) ? $awdr_column[23] : array('display' => 0, 'badge_color_picker' => '#ffffff', 'badge_text_color_picker' => '#000000', 'badge_text' => '');
                                        $advanced_discount_message = wp_json_encode(Helper::sanitizeJson($advanced_discount_message));
                                        $awdr_discount_type = sanitize_key(isset($awdr_column[24]) ? $awdr_column[24] : "wdr_simple_discount");
                                        $awdr_used_coupons = isset($awdr_column[25]) ? $awdr_column[25] : array();
                                        $awdr_used_coupons = wp_json_encode(Helper::sanitizeJson($awdr_used_coupons));
                                        $awdr_arg = array(
                                            'enabled' => $awdr_enabled,
                                            'deleted' => $awdr_deleted,
                                            'exclusive' => $awdr_exclusive,
                                            'title' => (empty($title)) ? esc_html__('Untitled Rule', 'woo-discount-rules') : $title,
                                            'priority' => $awdr_priority,
                                            'apply_to' => $awdr_apply_to,
                                            'filters' => $awdr_filters,
                                            'conditions' => $awdr_conditions,
                                            'product_adjustments' => $awdr_product_adjustments,
                                            'cart_adjustments' => $awdr_cart_adjustment,
                                            'buy_x_get_x_adjustments' => $awdr_buy_x_get_x,
                                            'buy_x_get_y_adjustments' => $awdr_buy_x_get_y,
                                            'bulk_adjustments' => $awdr_bulk_adjustment,
                                            'set_adjustments' => $awdr_set_adjustment,
                                            'other_discounts' => $awdr_other_discount,
                                            'date_from' => $awdr_date_from,
                                            'date_to' => $awdr_date_to,
                                            'usage_limits' => $awdr_usage_limits,
                                            'rule_language' => $awdr_rule_language,
                                            'used_limits' => $awdr_used_limits,
                                            'additional' => $awdr_additional,
                                            'max_discount_sum' => $awdr_max_discount_sum,
                                            'advanced_discount_message' => $advanced_discount_message,
                                            'discount_type' => $awdr_discount_type,
                                            'used_coupons' => $awdr_used_coupons,
                                            'created_by' => $current_user,
                                            'created_on' => $awdr_current_date_time,
                                            'modified_by' => $current_user,
                                            'modified_on' => $awdr_current_date_time,
                                        );
                                        $awdr_column_format = array('%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s');
                                        $awdr_rule_id = DBTable::saveRule($awdr_column_format, $awdr_arg);
                                        if (!empty($awdr_rule_id)) {
                                            $type = "success";
                                            $awdr_message = wp_kses_post('<b style="color: green;">' . __('Rules Imported successfully', 'woo-discount-rules') . '</b>');
                                        } else {
                                            $type = "error";
                                            $awdr_message = wp_kses_post('<b style="color: red;">' . __('Problem in Importing CSV Data', 'woo-discount-rules') . '</b>');
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    }
                } ?>
                <form method="post" name="awdr-import-csv" id="awdr-import-csv" enctype="multipart/form-data">
                    <input type="hidden" name="security" value="<?php echo esc_attr(wp_create_nonce('awdr_import_rules_csv')) ?>">
                    <input type="file" name="awdr_import_rule" id="awdr-file-uploader" accept=".csv"><br>
                    <span id="awdr-upload-response"><?php echo wp_kses_post($awdr_message); ?></span></br>
                    <button type="submit" id="wdr-import" name="wdr-import" class="button button-primary">
                        <?php esc_html_e('Import', 'woo-discount-rules'); ?>
                    </button>
                </form>
            </div>
        </div>
        </div><?php
    } else { ?>
        <div class="wdr_settings_container">
        <div>
            <h3><?php esc_html_e('Import Tool', 'woo-discount-rules'); ?></h3>
            <p><?php echo wp_kses_post(__('Unlock this feature by <a href="https://www.flycart.org/products/wordpress/woocommerce-discount-rules" target="_blank">Upgrading to Pro</a>', 'woo-discount-rules')) ; ?> </p>
        </div>
        </div><?php
    }
    ?>
</div>