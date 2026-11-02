<?php
defined('ABSPATH') || exit;

$awdr_recommendations_list = !empty($wdr_recommendations_list) ? $wdr_recommendations_list : [];
?>
<style>
    .awdr-addons {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        padding: 20px;
        /*justify-content: center;*/
    }
    .awdr-addon {
        background: #fff;
        border: 2px solid #000;
        border-radius: 12px;
        width: 320px;
        min-height: 460px;
        box-shadow: 6px 6px 0px rgba(0, 0, 0, 1);
        transition: transform 0.2s ease-in-out;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    .awdr-addon:hover {
        transform: translateY(-5px);
    }
    .awdr-addon img.banner {
        width: 100%;
        height: 160px;
        object-fit: cover;
    }
    .awdr-addon .addon-content {
        padding: 15px;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
    }
    .awdr-addon-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 10px;
    }
    .awdr-addon-icon {
        width: 50px;
        height: 50px;
        display: flex;
        justify-content: center;
        align-items: center;
        border-radius: 8px;
    }
    .awdr-addon-icon img {
        width: 30px;
        height: 30px;
    }
    .awdr-addon-header h2 {
        font-size: 16px;
        font-weight: 700;
        margin: 0;
        flex-grow: 1;
    }
    .awdr-addon .author {
        font-size: 13px;
        color: #555;
        margin-bottom: 6px;
    }
    .awdr-addon .description {
        font-size: 14px;
        color: #333;
        flex-grow: 1;
    }
    .awdr-addon .addon-actions {
        display: flex;
        justify-content: flex-end;
        padding: 15px;
        margin-top: auto;
    }
    .addon-actions a {
        background: #000;
        color: #fff;
        padding: 8px 14px;
        font-size: 14px;
        text-decoration: none;
        border-radius: 6px;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .addon-actions a:hover {
        background: #222;
    }
</style>

<div class="awdr-addons">
	<?php foreach ($awdr_recommendations_list as $awdr_slug => $awdr_recommendation) { ?>
		<div class="awdr-addon">
			<img class="banner" src="<?php echo esc_url($awdr_recommendation['banner_image']); // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage ?>"
			     alt="<?php echo esc_attr($awdr_recommendation['name'], 'woo-discount-rules'); ?>">

			<div class="addon-content">
				<div class="awdr-addon-header">
					<div class="awdr-addon-icon">
						<img src="<?php echo esc_url($awdr_recommendation['icon_url']); // phpcs:ignore PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage ?>"
						     alt="<?php echo esc_attr($awdr_recommendation['name'], 'woo-discount-rules'); ?>" width="48" height="48">
					</div>
					<h2><?php echo esc_html($awdr_recommendation['name']); ?></h2>
				</div>
				<div class="description">
					<?php echo esc_html($awdr_recommendation['description']); ?>
				</div>
			</div>

			<div class="addon-actions">
				<?php if (!empty($awdr_recommendation['plugin_url'])):  ?>
					<a href="<?php echo esc_url($awdr_recommendation['plugin_url']); ?>" target="_blank">
						<?php echo esc_html__('Get Plugin', 'woo-discount-rules'); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
	<?php } ?>
</div>
