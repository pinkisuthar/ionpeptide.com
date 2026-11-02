<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>
<div class="notice notice-info is-dismissible">
    <div>
        <img style="max-width: 85px; margin-top: 13px" src="https://ps.w.org/woo-discount-rules/assets/icon-256x256.png" <?php //phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent,PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage  ?>>
    </div>
    <div style="padding-bottom : 30px; margin-left: 115px; margin-top: -78px;">
        <?php esc_html_e('Fantastic! You have got 100+ sales with the Discount Rules plugin!', 'woo-discount-rules'); ?>
        <br>
        <?php esc_html_e('Could you please share us with your feedback and a 5-star review on the WordPress repository? Your support will help us continually improve.', 'woo-discount-rules'); ?>
    </div>
    <div style="margin-left: 25px">
        <div style="padding-bottom : 20px; margin-left: 90px; margin-top: -20px;">
            <a href="<?php echo esc_url(add_query_arg( 'awdr_review', 'add')) ?>" target="_blank" class="button-primary"><?php esc_html_e('Ok, you deserve it', 'woo-discount-rules');?></a>
        </div>
        <div style="padding-bottom : 20px; margin-left: 225px; margin-top: -50px;">
            <a href="<?php echo esc_url(add_query_arg( 'awdr_review', 'later')) ?>" class="button-secondary "><?php esc_html_e('Nope, maybe later', 'woo-discount-rules');?></a>
        </div>
        <div style="padding-bottom : 20px; margin-left: 362px; margin-top: -50px;">
            <a href="<?php echo esc_url(add_query_arg( 'awdr_review', 'done')) ?>" class="button-secondary"><?php esc_html_e('I already did', 'woo-discount-rules');?></a>
        </div>
    </div>
</div>