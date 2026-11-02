<?php
$gutenify_base_theme = wp_get_theme();
// var_dump( $gutenify_base_theme->get( 'Name' ) );

$gutenify_base_screen = get_current_screen();
if (!empty($gutenify_base_screen->base) && 'appearance_page_gutenify-base-info' === $gutenify_base_screen->base) {
	return false;
}

$gutenify_is_installed = file_exists(plugin_dir_path('gutenify'));
$gutenify_is_active = in_array('gutenify/gutenify.php', apply_filters('active_plugins', get_option('active_plugins')));

$show_notice = !($gutenify_is_installed && $gutenify_is_active);

if (!$show_notice) {
	return false;
}

?>
<div class="notice notice-success is-dismissible gutenify-base-admin-notice">
	<div class="gutenify-base-admin-notice-wrapper">
		<h2><?php esc_html_e('Hey, Thank you for installing ', 'gutenify-base'); ?>
			<?php echo $gutenify_base_theme->get('Name'); ?>
			<?php esc_html_e('Theme!', 'gutenify-base'); ?>
		</h2>
		<p><?php esc_html_e('We recommend installing plugin: ', 'gutenify-base'); ?>
			<strong><?php esc_html_e('Gutenify', 'gutenify-base'); ?></strong>
		</p>
		<p><strong><?php esc_html_e('Gutenify', 'gutenify-base'); ?></strong>
			<?php esc_html_e('provides multiple site demo and add advance features to your site.', 'gutenify-base'); ?>
		</p>
		<div style="    display: flex;gap: 10px;align-items: center;">
			<a href="<?php echo esc_url(admin_url('themes.php?page=gutenify-base-info')); ?>"
				class="button button-primary"><?php esc_html_e('Get Site Demo', 'gutenify-base'); ?></a>
			<a href="<?php echo esc_url('https://gutenify.com'); ?>"
				target="_blank"><?php esc_html_e('Learn more about Gutenify', 'gutenify-base'); ?></a>
		</div>
	</div>
</div>
<?php
