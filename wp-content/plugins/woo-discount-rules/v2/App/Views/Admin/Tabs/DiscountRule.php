<?php
if (!defined('ABSPATH')) exit;

$awdr_rules_count = isset($rule_count) && !empty($rule_count) ? $rule_count : 0 ;
$awdr_total_page = isset($total_count) && !empty($total_count) ? $total_count : 0 ;
$awdr_recommended_addon = isset($recommended_addon) && !empty($recommended_addon) ?  $recommended_addon: [] ;
$awdr_is_pro = \Wdr\App\Helpers\Helper::hasPro();
?>
<br>
<div id="wpbody-content" class="awdr-container">
    <div class="" style="width: 70%; float: left;">
        <div class="col-md-6 col-lg-6 text-left awdr-list-header-btn" style="width:100%; float:left;">
            <h1 class="wp-heading-inline"><?php esc_html_e('Discount Rules', 'woo-discount-rules'); ?></h1>
            <a href="<?php echo esc_url(admin_url("admin.php?" . http_build_query(array('page' => WDR_SLUG, 'tab' => 'rules', 'task' => 'create')))); ?>"
               class="btn btn-primary"><?php esc_html_e('Add New Rule', 'woo-discount-rules'); ?></a>
            <?php if ($has_migration == true) {
                ?>
                <a class="wdr-popup-link btn btn-primary"><span class="modal-trigger"
                                                                data-modal="wdr_migration_popup"><?php esc_html_e("Migrate rules from v1", 'woo-discount-rules'); ?>
                </a>

                <div class="modal" id="wdr_migration_popup">
                    <div class="modal-sandbox"></div>
                    <div class="modal-box">
                        <div class="modal-header">
                            <div class="close-modal"><span class="wdr-close-modal-box">&#10006;</span></div>
                            <h1 class="wdr-modal-header-title"><?php esc_html_e("Migration", 'woo-discount-rules'); ?></h1>
                        </div>
                        <div class="modal-body">
                            <h2 class="wdr_tabs_container nav-tab-wrapper">
                                <?php esc_html_e('Migrate rules from v1 to v2', 'woo-discount-rules'); ?>
                            </h2>
                            <div class="wdr_migration_text_con">
                                <p>
                                    <b><?php esc_html_e('Available price rules', 'woo-discount-rules'); ?>:</b> <?php echo isset($migration_rule_count['price_rules']) ? esc_html($migration_rule_count['price_rules']) : 0; ?>
                                </p>
                                <p>
                                    <b><?php esc_html_e('Available cart rules', 'woo-discount-rules'); ?>:</b> <?php echo isset($migration_rule_count['cart_rules']) ? esc_html($migration_rule_count['cart_rules']) : 0; ?>
                                </p>
                                <p>
                                    <?php echo wp_kses_post(__('Once migration is completed, please open the rules and check their configuration once again to make sure it meets your discount scenario. If required, please adjust the rule configuration. If you need any help, just open a ticket at <a href="https://www.flycart.org/support" target="_blank">https://www.flycart.org/support</a>', 'woo-discount-rules')); ?>
                                </p>
                            </div>
                            <div class="wdr_settings">
                                <div class="wdr_migration_container">
                                    <button class="btn btn-primary" type="button" data-awdr_nonce="<?php echo esc_attr(\Wdr\App\Helpers\Helper::create_nonce('awdr_v2_migration')); ?>" id="awdr_do_v1_v2_migration"><?php esc_html_e('Migrate', 'woo-discount-rules'); ?></button>
                                    <span class="close-modal"><button class="btn btn-warning wdr-close-modal-box" type="button"><?php esc_html_e('Skip', 'woo-discount-rules'); ?></button></span>
                                    <div class="wdr_migration_process">
                                    </div>
                                    <div class="wdr_migration_process_status">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
            } ?>
            <a href="https://www.flycart.org/woocommerce-discount-rules-examples?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=examples#commonly-asked-scenarios"
               target="_blank"
               class="btn btn-info text-right"
               style="float: right"><?php esc_html_e('View Examples', 'woo-discount-rules'); ?></a>
            <a href="https://docs.flycart.org/en/collections/806883-discount-rules-for-woocommerce?utm_source=woo-discount-rules-v2&utm_campaign=doc&utm_medium=text-click&utm_content=documentation"
               target="_blank"
               class="btn btn-info text-right"
               style="float: right"><?php esc_html_e('Documentation', 'woo-discount-rules'); ?></a>
        </div>

        <br/>
        <?php
        $awdr_page_limit = !empty($input->get('limit')) ? $input->get('limit') : $limit ;
        $awdr_page_sort = !empty($input->get('sort')) ? $input->get('sort') : $sort ;
        ?>
        <form id="wdr-search-top" method="get" style="display: none">
            <input type="hidden" name="adminUrl"
                   value="<?php echo esc_url(admin_url('admin.php?page=woo_discount_rules')); ?>">
                <input type="hidden" name="name" value="<?php echo esc_attr($input->get('name')); ?>" class="wdr-rule-search-key">
                 <input type="hidden" name="limit" value="<?php echo esc_attr($awdr_page_limit); ?>" class="wdr-rule-limit-key">
                 <input type="hidden" name="total_page" value="<?php echo esc_attr($awdr_total_page); ?>" class="wdr-rule-limit-key">
                <input type="hidden" name="awdr_nonce" value="<?php echo esc_attr(\Wdr\App\Helpers\Helper::create_nonce('awdr_ajax_search_rule')); ?>">
                <input type="submit" class="button" class="wdr-trigger-search-key"
                       value="<?php esc_html_e('Search Rules', 'woo-discount-rules'); ?>">
            <input type="submit" class="button" class="wdr-trigger-limit-key"
                   value="<?php esc_html_e('Limit', 'woo-discount-rules'); ?>">
        </form>
        <form id="wdr-bulk-action-top" method="post">
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <label for="bulk-action-selector-top"
                           class="screen-reader-text"><?php esc_html_e('Search Rules', 'woo-discount-rules'); ?></label>
                    <select name="wdr_bulk_action" id="bulk-action-selector-top">
                        <option value="-1"><?php esc_html_e('Bulk Actions', 'woo-discount-rules'); ?></option>
                        <option value="enable"><?php esc_html_e('Enable', 'woo-discount-rules'); ?></option>
                        <option value="disable"><?php esc_html_e('Disable', 'woo-discount-rules'); ?></option>
                        <option value="delete"><?php esc_html_e('Delete', 'woo-discount-rules'); ?></option>
                    </select>
                    <input type="submit" id="doaction" class="button action"
                           value="<?php esc_html_e('Apply', 'woo-discount-rules'); ?>">
                    <input type="search" name="awdr-hidden-name" id="awdr-name" class="awdr-hidden-name"
                           value="<?php echo esc_attr($input->get('name')); ?>">
                    <input type="hidden" name="awdr_nonce"
                           value="<?php echo esc_attr(\Wdr\App\Helpers\Helper::create_nonce('awdr_ajax_rule_bulk_actions')); ?>">
                    <input type="button" class="button awdr-hidden-search"
                           value="<?php esc_html_e('Search Rules', 'woo-discount-rules'); ?>">
<!--                    <input type="button" class="button awdr-hidden-search"-->
<!--                           value="--><?php //_e('Limit', 'woo-discount-rules'); ?><!--">-->


                    <select name="awdr-hidden-limit" id="awdr_limit" class="awdr-hidden-rule-limit page_limit">
                        <option value="20" <?php echo ($awdr_page_limit == 20) ? 'selected' : '';?> >20</option>
                        <option value="50" <?php echo ($awdr_page_limit == 50) ? 'selected' : '';?> >50</option>
                        <option value="100" <?php echo ($awdr_page_limit == 100) ? 'selected' : '';?> >100</option>
                        <option value="all" <?php echo ($awdr_page_limit == 'all') ? 'selected' : '';?> ><?php esc_html_e('All', 'woo-discount-rules'); ?> </option>
                    </select>
                </div>
                <div class="tablenav-pages one-page">
                <span class="displaying-num"><?php echo esc_html($awdr_rules_count) . ' ';
                    ($awdr_rules_count == 0 || $awdr_rules_count == 1) ? esc_html_e('item', 'woo-discount-rules') : esc_html_e('items', 'woo-discount-rules'); ?></span>
                    <?php include 'pagination.php'; ?>
                </div>
                <br class="clear">
            </div>
            <input type="hidden" name="sort"  id="page_sort" value="<?php echo esc_attr($awdr_page_sort); ?>" class="wdr-rule-limit-key">
            <table class="wp-list-table widefat fixed posts">
                <thead>
                <tr>
                    <td id="cb" class="manage-column column-cb check-column">
                        <input name="bulk_check[]" class="wdr-rules-select" type="checkbox" value="off"/>
                    </td>
                    <th scope="col" id="re_order" style="width: 100px"
                        class="manage-column column-author column-primary sortable asc">
                        <a href="javascript:void(0);" id="awdr_re_order" >
                            <span><?php esc_html_e('Re - Order', 'woo-discount-rules'); ?></span>
                        </a></th>
                    <th scope="col" id="title"
                        class="manage-column column-title"><span><?php esc_html_e('Title', 'woo-discount-rules'); ?></span></th>
                    <th scope="col" id="author"
                        class="manage-column column-author"><?php esc_html_e('Discount Type', 'woo-discount-rules'); ?></th>
                    <th scope="col" id="author"
                        class="manage-column column-author"><?php esc_html_e('Start Date', 'woo-discount-rules'); ?></th>
                    <th scope="col" id="tags"
                        class="manage-column column-tags"><?php esc_html_e('Expired On', 'woo-discount-rules'); ?></th>
                    <?php
                    if (count($site_languages) > 1) {
                        ?>
                        <th scope="col" id="tags"
                            class="manage-column column-tags"><?php esc_html_e('Language(s)', 'woo-discount-rules'); ?></th>
                        <?php
                    }
                    ?>
                    <!--<th scope="col" id="tags" class="manage-column column-tags">
                        <?php /*esc_html_e('Priority','woo-discount-rules') */?>
                    </th>-->
                    <th scope="col" id="status"
                        class="manage-column column-tags"><?php esc_html_e('Status', 'woo-discount-rules'); ?></th>
                    <th scope="col" id="title"
                        class="manage-column column-title"><?php esc_html_e('Action', 'woo-discount-rules'); ?></th>
                </tr>
                </thead>
                <tbody class="wdr-ruleboard" id="sortable"><?php
                if ($rules) {
                    foreach ($rules as $awdr_rule_row) { ?>
                        <tr id="<?php echo esc_attr($awdr_rule_row->getId()); ?>"  data-priority="<?php echo esc_attr($awdr_rule_row->rule->priority); ?>" class="awdr-listing-rule-tr">
                            <th scope="row" class="check-column awdr-listing-rule-check-box-align">
                                <input id="cb-select-<?php echo esc_attr($awdr_rule_row->getId()); ?>" class="wdr-rules-selector"
                                       type="checkbox" name="saved_rules[]"
                                       value="<?php echo esc_attr($awdr_rule_row->getId()); ?>">
                            </th>
                            <th scope="row" class="check-column awdr-listing-rule-check-box-align" aria-disabled="false">
                                <span class="dashicons dashicons-menu awdr-sortable-handle" style="padding-left: 25px;"></span>
                            </th>
                            <td class="title column-title has-row-actions column-primary page-title"
                                data-colname="Title">
                                <strong>
                                    <a class="row-title"
                                       href="<?php echo esc_url(admin_url("admin.php?" . http_build_query(array('page' => WDR_SLUG, 'tab' => 'rules', 'task' => 'view', 'id' => $awdr_rule_row->getId() ,'page_no' => $current_page)))); ?>"
                                       aria-label="“<?php echo esc_attr($awdr_rule_row->getTitle()); ?>” (Edit)"><?php echo esc_html($awdr_rule_row->getTitle());
                                       if($awdr_rule_row->isExclusive()) {?>
                                               <span class="awdr-exclusive-disable-listing"><?php esc_html_e('Exclusive', 'woo-discount-rules'); ?></span> <?php
                                       }?></a>
                                </strong>
                                <div class="awdr_created_date_html">
                                    <?php
                                    $awdr_created_by = $awdr_rule_row->getRuleCreatedBy();
                                    if ($awdr_created_by) {
                                        if (function_exists('get_userdata')) {
                                            if ($awdr_user = get_userdata($awdr_created_by)) {
                                                if (isset($awdr_user->data->display_name)) {
                                                    $awdr_created_by = $awdr_user->data->display_name;
                                                }
                                            }
                                        }
                                    }
                                    $awdr_created_on = $awdr_rule_row->getRuleCreatedOn();

                                    $awdr_modified_by = $awdr_rule_row->getRuleModifiedBy();
                                    if ($awdr_modified_by) {
                                        if (function_exists('get_userdata')) {
                                            if ($awdr_user = get_userdata($awdr_modified_by)) {
                                                if (isset($awdr_user->data->display_name)) {
                                                    $awdr_modified_by = $awdr_user->data->display_name;
                                                }
                                            }
                                        }
                                    }
                                    $awdr_modified_on = $awdr_rule_row->getRuleModifiedOn();
                                    if ($awdr_created_by && !empty($awdr_created_by) && !empty($awdr_created_on)) { ?>
                                        <span class="wdr_desc_text">
                                        <?php
                                        /* translators: %s used to display created user.*/
                                        echo esc_html(sprintf(__('Created by: %s', 'woo-discount-rules'),$awdr_created_by)); ?>
                                        ,<?php
	                                    /* translators: %s used to display created/modified On*/
                                        echo esc_html(sprintf(__('On: %s', 'woo-discount-rules'),$awdr_created_on)); ?> &nbsp;</span><?php }
                                    if ($awdr_modified_by && !empty($awdr_modified_by) && !empty($awdr_modified_on)) {
                                        ?>
                                        <span class="wdr_desc_text"><?php
	                                    /* translators: %s used to display Modified user.*/
                                        echo esc_html(sprintf(__('Modified by: %s', 'woo-discount-rules'),$awdr_modified_by));?>
                                        ,<?php
	                                    /* translators: %s used to display created/modified On*/
	                                    echo esc_html(sprintf(__('On: %s', 'woo-discount-rules'),$awdr_modified_on)); ?> </span><?php
                                    } ?>
                                </div>
                            </td>
                            <td class="author column-author" data-colname="Author"><?php
                                $awdr_get_discount_type = $awdr_rule_row->getRuleDiscountType();
                                $awdr_discount_type_name = '-';
                                switch ($awdr_get_discount_type) {
                                    case'wdr_simple_discount':
                                        $awdr_discount_type_name = __('Product Adjustment', 'woo-discount-rules');
                                        break;
                                    case'wdr_cart_discount':
                                        $awdr_discount_type_name = __('Cart Adjustment', 'woo-discount-rules');
                                        break;
                                    case'wdr_free_shipping':
                                        $awdr_discount_type_name = __('Free Shipping', 'woo-discount-rules');
                                        break;
                                    case'wdr_bulk_discount':
                                        $awdr_discount_type_name = __('Bulk Discount', 'woo-discount-rules');
                                        break;
                                    case'wdr_set_discount':
                                        $awdr_discount_type_name = __('Set Discount', 'woo-discount-rules');
                                        break;
                                    case'wdr_buy_x_get_x_discount':
                                        $awdr_discount_type_name = __('Buy X get X', 'woo-discount-rules');
                                        break;
                                    case'wdr_buy_x_get_y_discount':
                                        $awdr_discount_type_name = __('Buy X get Y', 'woo-discount-rules');
                                        break;
                                }
                                ?>
                                <abbr><?php echo esc_html($awdr_discount_type_name); ?></abbr>
                            </td>
                            <td class="author column-author" data-colname="Author"><?php
                                $awdr_get_start_date = $awdr_rule_row->getStartDate( false, "Y-m-d H:i");
                                ?>
                                <abbr><?php echo is_null($awdr_get_start_date) ? '-' : esc_html($awdr_get_start_date); ?></abbr>
                            </td>
                            <td class="date column-date" data-colname="Date"><?php
                                $awdr_get_end_date = $awdr_rule_row->getEndDate(false, "Y-m-d H:i");
                                ?>
                                <abbr><?php echo is_null($awdr_get_end_date) ? '-' : esc_html($awdr_get_end_date); ?></abbr>
                            </td>
                            <?php
                            if (count($site_languages) > 1) {
                                ?>
                                <td>
                                    <?php
                                    $awdr_chosen_languages = $awdr_rule_row->getLanguages();
                                    if (!empty($awdr_chosen_languages)) {
                                        $awdr_i = 1;
                                        foreach ($awdr_chosen_languages as $awdr_language) {
                                            echo isset($site_languages[$awdr_language]) ? esc_html($site_languages[$awdr_language]) : '';
                                            if (count($awdr_chosen_languages) > $awdr_i) {
                                                echo ', ';
                                            }
                                            $awdr_i++;
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <?php
                            }
                            ?>
                            <!-- <td></td>-->
                            <td class="date column-tag" data-colname="wdr-rule-status">
                                <label class="switch switch-left-right">
                                    <input class="switch-input wdr_manage_status" name="toogle_action" type="checkbox" data-awdr_="<?php echo esc_attr($awdr_rule_row->getId()); ?>" data-awdr_nonce="<?php echo esc_attr(\Wdr\App\Helpers\Helper::create_nonce('wdr_ajax_manage_status'.$awdr_rule_row->getId())); ?>" data-manage-status="<?php echo esc_attr($awdr_rule_row->getId()); ?>" <?php echo ($awdr_rule_row->isEnabled()) ? 'checked' : '';?>/>
                                    <span class="switch-label" data-on="<?php esc_attr_e('Enabled', 'woo-discount-rules'); ?>" data-off="<?php esc_attr_e('Disabled', 'woo-discount-rules'); ?>"></span>
                                    <span class="switch-handle"></span>
                                </label>
                                    <span class="awdr-enabled-status" style="<?php echo (!$awdr_rule_row->isEnabled()) ? 'display:none' : '';?>">
                                    <?php
                                    $awdr_rule_status = $awdr_rule_row->getRuleVaildStatus();
                                    $awdr_check_rule_limit = $awdr_rule_row->checkRuleUsageLimits();
                                    $awdr_current_time_stamp = current_time('timestamp');
                                    $awdr_current_time = $awdr_rule_row->formatDate($awdr_current_time_stamp, "Y-m-d H:i", false);
                                    if($awdr_rule_status == 'in_future'){
                                        if ($awdr_check_rule_limit == 'Disabled') { ?>
                                            <span class="awdr-listing-status-text"><?php esc_html_e(' - ( Not running )', 'woo-discount-rules');?></span><br>
                                                <span class="awdr-text-warning"><b><?php esc_html_e('Rule reached maximum usage limit', 'woo-discount-rules');?> </b>
                                                </span><?php

                                        } else { ?>
                                            <span class="awdr-listing-status-text"><?php esc_html_e(' - ( Will run in future)', 'woo-discount-rules'); ?></span>
                                            <br><?php
                                            if (isset($awdr_current_time) && !empty($awdr_current_time)) {
                                                ?>
                                                <span class="awdr-text-warning"><b><?php esc_html_e('Your server current date and time:', 'woo-discount-rules');?> </b><?php echo esc_html($awdr_current_time); ?>
                                                </span><?php
                                            }
                                        }
                                    } elseif ($awdr_rule_status == 'expired') {
                                        if ($awdr_check_rule_limit == 'Disabled') { ?>
                                            <span class="awdr-listing-status-text"><?php esc_html_e(' - ( Not running )', 'woo-discount-rules'); ?></span>
                                            <br>
                                            <span class="awdr-text-warning">
                                            <b><?php esc_html_e('Rule reached maximum usage limit', 'woo-discount-rules'); ?> </b>
                                            </span><?php
                                        } else { ?>
                                            <span class="awdr-listing-status-text"><?php esc_html_e(' - ( Not running - validity expired)', 'woo-discount-rules'); ?></span>
                                            <br><?php
                                            if (isset($awdr_current_time) && !empty($awdr_current_time)) {
                                                ?>
                                                <span class="awdr-text-warning"><b><?php esc_html_e('Your server current date and time:', 'woo-discount-rules');?> </b><?php echo esc_html($awdr_current_time); ?>
                                                </span><?php
                                            }
                                        }
                                    } else {
                                        if ($awdr_check_rule_limit == 'Disabled') { ?>
                                            <span class="awdr-listing-status-text"><?php esc_html_e(' - ( Not running )', 'woo-discount-rules'); ?></span>
                                            <br>
                                            <span class="awdr-text-warning">
                                            <b><?php esc_html_e('Rule reached maximum usage limit', 'woo-discount-rules'); ?> </b>
                                            </span><?php
                                        } else { ?>
                                            <span class="awdr-listing-status-text"><?php esc_html_e(' - (Running)', 'woo-discount-rules'); ?></span><?php
                                        }
                                    } ?>
                                    </span>
                            </td>
                            <td class="awdr-rule-buttons">
                                <a class="btn btn-primary"
                                   href="<?php echo esc_url(admin_url("admin.php?" . http_build_query(array('page' => WDR_SLUG, 'tab' => 'rules', 'task' => 'view', 'id' => $awdr_rule_row->getId())))); ?>">
                                    <?php esc_html_e('Edit', 'woo-discount-rules'); ?></a>
                                <a class="btn btn-primary wdr_duplicate_rule"
                                   data-duplicate-rule="<?php echo esc_attr($awdr_rule_row->getId()); ?>"
                                   data-awdr_nonce="<?php echo esc_attr(\Wdr\App\Helpers\Helper::create_nonce('wdr_ajax_duplicate_rule' . $awdr_rule_row->getId())); ?>"><?php esc_html_e('Duplicate', 'woo-discount-rules'); ?></a>
                                <a class="btn btn-danger wdr_delete_rule"
                                   data-delete-rule="<?php echo esc_attr($awdr_rule_row->getId()); ?>"
                                   data-priority="<?php echo esc_attr($awdr_rule_row->rule->priority); ?>"
                                   data-awdr_nonce="<?php echo esc_attr(\Wdr\App\Helpers\Helper::create_nonce('wdr_ajax_delete_rule' . $awdr_rule_row->getId())); ?>">
                                    <?php esc_html_e('Delete', 'woo-discount-rules'); ?></a>
                            </td>
                        </tr>

                        <?php
                    }
                } else {
                    ?>
                    <tr class="no-items">
                        <td></td>
                        <td></td>
                        <td class="colspanchange" colspan="2"><?php esc_html_e('No rules found.', 'woo-discount-rules'); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
                <tfoot>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input name="bulk_check[]" class="wdr-rules-select" type="checkbox" value="off"/>
                    </td>
                    <td class="manage-column column-cb check-column">
                    </td>
                    <th scope="col" id="title" class="manage-column column-title">
                            <span><?php esc_html_e('Title', 'woo-discount-rules'); ?></span>
                    </th>
                    <th scope="col" id="author"
                        class="manage-column column-author"><?php esc_html_e('Discount Type', 'woo-discount-rules'); ?></th>
                    <th scope="col" id="author"
                        class="manage-column column-author"><?php esc_html_e('Start Date', 'woo-discount-rules'); ?></th>
                    <th scope="col" id="tags"
                        class="manage-column column-tags"><?php esc_html_e('Expired On', 'woo-discount-rules'); ?></th>
                    <?php
                    if (count($site_languages) > 1) {
                        ?>
                        <th scope="col" id="tags"
                            class="manage-column column-tags"><?php esc_html_e('Language(s)', 'woo-discount-rules'); ?></th>
                        <?php
                    }
                    ?>
                    <!--<th scope="col" id="tags" class="manage-column column-tags">
                        <?php /*esc_html_e('Priority','woo-discount-rules') */?>
                    </th>-->
                    <th scope="col" id="status"
                        class="manage-column column-tags"><?php esc_html_e('Status', 'woo-discount-rules'); ?></th>
                    <th scope="col" id="title"
                        class="manage-column column-title"><?php esc_html_e('Action', 'woo-discount-rules'); ?></th>
                </tr>
                </tfoot>
            </table>


            <div class="tablenav bottom">
                <div class="alignleft actions bulkactions">
                    <!-- <label for="bulk-action-selector-bottom" class="screen-reader-text">Select bulk
                         action</label><select name="action2" id="bulk-action-selector-bottom">
                         <option value="-1">Bulk Actions</option>
                         <option value="edit" class="hide-if-no-js">Edit</option>
                         <option value="trash">Move to Trash</option>
                     </select>
                     <input type="submit" id="doaction2" class="button action" value="Apply">-->
                </div>
                <div class="alignleft actions">
                </div>
                <div class="tablenav-pages one-page"><span class="displaying-num"><?php echo esc_html($awdr_rules_count) . ' ';
                        ($awdr_rules_count == 0 || $awdr_rules_count == 1) ? esc_html_e('item', 'woo-discount-rules') : esc_html_e('items', 'woo-discount-rules'); ?></span></span>
                    <?php include 'pagination.php'; ?>
                </div>
                <br class="clear">
            </div>
            <input type="hidden" name="awdr_rule_list_nonce" value="<?php echo esc_attr(\Wdr\App\Helpers\Helper::create_nonce('awdr_rule_list')); ?>">
            <input type="hidden" name="action" value="wdr_ajax">
            <input type="hidden" name="method" value="bulk_action">
            <input type="hidden" name="adminUrl" value="<?php echo esc_url(admin_url('admin.php?page=woo_discount_rules')); ?>">
        </form>
        <br class="clear">
    </div>
	<?php  if($awdr_page_limit != 'all' && $total_count > 1) : ?>
    </div>
    </div>
	<?php endif;?>

    <?php
    if (!$awdr_is_pro) {?>
        <div class="awdr-pro-content-card-list">
            <div class="awdr-pro-content-card card" style="float: right;">
                <div class="card-body text-right">
                    <img class="banner" style="    width: 100%;height: 160px;object-fit: cover;"  src="https://static.flycart.net/recommendation/image/discount-rule.png" <?php //phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent,PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage ?>
                         alt="discount-rule-banner-image">
                    <div class="awdr-pro-content-header">
                        <div class="awdr-pro-content-icon">
                            <img src="https://static.flycart.net/recommendation/icons/discount-rules.png"<?php //phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent,PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage  ?>
                                 alt="discount-rule-icon" width="48" height="48">
                        </div>
                        <h2><?php esc_html_e('Discount Rules PRO for WooCommerce', 'woo-discount-rules') ?></h2>
                    </div>
                    <a href="https://docs.flycart.org/en/articles/3806305-upgrade-from-free-to-pro?utm_source=wpwoodiscountrules&utm_medium=plugin&utm_campaign=inline&utm_content=upgrade"
                       target="_blank"
                       class="btn btn-dark-blue"><?php esc_html_e('Looking for more features? Upgrade to PRO', 'woo-discount-rules'); ?></a>
                    <div class="woo-side-panel mt-3">
                        <div class="panel">
                            <div class="panel-body">
                                <h3><?php esc_html_e('With PRO version, you can create:', 'woo-discount-rules') ?></h3>
                                <ul class="list-unstyled">
                                    <li><?php esc_html_e('- Categories based discounts', 'woo-discount-rules') ?></li>
                                    <li><?php esc_html_e('- User roles based discounts', 'woo-discount-rules') ?></li>
                                    <li><?php esc_html_e('- Buy One Get One Free deals', 'woo-discount-rules') ?></li>
                                    <li><?php esc_html_e('- Buy X Get Y deals', 'woo-discount-rules') ?></li>
                                    <li><?php esc_html_e('- Buy 2, get 1 at 50% discount', 'woo-discount-rules') ?></li>
                                    <li><?php esc_html_e('- Buy 3 for $10 (Package / Bundle [Set] Discount)', 'woo-discount-rules') ?></li>
                                    <li><?php esc_html_e('- Different discounts with one coupon code', 'woo-discount-rules') ?></li>
                                    <li><?php esc_html_e('- Purchase history based discounts', 'woo-discount-rules') ?></li>
                                    <li><?php esc_html_e('- Free product / gift', 'woo-discount-rules') ?></li>
                                    <li><?php esc_html_e('- Discount for variants', 'woo-discount-rules') ?></li>
                                    <li><?php esc_html_e('- Conditional discounts', 'woo-discount-rules') ?></li>
                                    <li><?php esc_html_e('- Fixed cost discounts', 'woo-discount-rules') ?></li>
                                    <li><?php esc_html_e('- Offer fixed price on certain conditions', 'woo-discount-rules') ?></li>
                                </ul>
                            </div>

                            <a href="https://www.flycart.org/products/wordpress/woocommerce-discount-rules?utm_source=wpwoodiscountrules&amp;utm_medium=plugin&amp;utm_campaign=inline&amp;utm_content=woo-discount-rules"
                                   class="btn btn-dark-blue"
                                   target="_blank"><?php esc_html_e('Go PRO', 'woo-discount-rules'); ?></a>
                        </div>
                    </div>
                </div>
            </div>
        <?php } ?>
            <?php foreach ($awdr_recommended_addon as  $awdr_slug => $awdr_recommendation) :?>
    <div class="awdr-pro-content-card-list">
            <div class="awdr-pro-content-card card" style="float: right;">
                <div class="card-body text-right">
                    <div class="awdr-pro-content-header">
                        <div class="awdr-pro-content-icon">
                            <img src="<?php echo esc_url($awdr_recommendation['icon_url']); // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage	 ?>"
                                 alt="discount-rule-icon" width="48" height="48">
                        </div>
                        <h2><?php esc_html_e($awdr_recommendation['name'], 'woo-discount-rules'); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText?></h2>
                    </div>
                    <div class="description" style="margin-bottom: 6px;">
                        <?php echo esc_html($awdr_recommendation['description'], 'woo-discount-rules'); ?>
                    </div>
                    <a href="<?php echo esc_url($awdr_recommendation['plugin_url']); ?>"
                       class="btn btn-dark-blue"
                       target="_blank"><?php esc_html_e('Get Plugin', 'woo-discount-rules'); ?></a>
                </div>
            </div>
             <?php endforeach; ?>
        </div>
    </div>
<?php
if ($awdr_page_sort == 1 ) { ?>
<style>
    .awdr-listing-rule-tr:hover{
        background-color: #ddf2ff;
    }
</style>
<?php } ?>