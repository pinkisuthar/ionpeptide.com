<?php
/**
 * Main plugin settings array
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH Infinite Scrolling
 * @version 1.0.0
 */

defined( 'YITH_INFS' ) || exit; // Exit if accessed directly.

$settings = array(

	'general' => array(

		'header'   => array(

			array(
				'name' => __( 'Plugin options', 'yith-infinite-scrolling' ),
				'type' => 'title',
				'desc' => __( 'Enable the plugin and set sections.', 'yith-infinite-scrolling' ),
			),

			array(
				'id'   => 'yith-infs-enable-mobile',
				'name' => __( 'Enable Infinite Scrolling on mobile devices', 'yith-infinite-scrolling' ),
				'desc' => '',
				'type' => 'on-off',
				'std'  => 'yes',
			),

			array(
				'id'   => 'yith-infs-navselector',
				'name' => __( 'Navigation Selector', 'yith-infinite-scrolling' ),
				'desc' => __( 'The selector containing your theme\'s navigation.', 'yith-infinite-scrolling' ),
				'type' => 'text',
				'std'  => '.navigation',
				'deps' => array(
					'ids'    => 'yith-infs-enable',
					'values' => 'yes',
				),
			),

			array(
				'id'   => 'yith-infs-nextselector',
				'name' => __( 'Next Selector', 'yith-infinite-scrolling' ),
				'desc' => __( 'The link to the next page with content.', 'yith-infinite-scrolling' ),
				'type' => 'text',
				'std'  => '.navigation a.next',
				'deps' => array(
					'ids'    => 'yith-infs-enable',
					'values' => 'yes',
				),
			),

			array(
				'id'   => 'yith-infs-itemselector',
				'name' => __( 'Item Selector', 'yith-infinite-scrolling' ),
				'desc' => __( 'The selector containing a single post or product.', 'yith-infinite-scrolling' ),
				'type' => 'text',
				'std'  => 'article.post',
				'deps' => array(
					'ids'    => 'yith-infs-enable',
					'values' => 'yes',
				),
			),

			array(
				'id'   => 'yith-infs-contentselector',
				'name' => __( 'Content Selector', 'yith-infinite-scrolling' ),
				'desc' => __( 'The selector containing your theme\'s content.', 'yith-infinite-scrolling' ),
				'type' => 'text',
				'std'  => '#main',
				'deps' => array(
					'ids'    => 'yith-infs-enable',
					'values' => 'yes',
				),
			),

			array(
				'id'   => 'yith-infs-loader-image',
				'name' => __( 'Loader', 'yith-infinite-scrolling' ),
				'desc' => __( 'Upload a custom loader.', 'yith-infinite-scrolling' ),
				'type' => 'media',
				'std'  => YITH_INFS_ASSETS_URL . '/images/loader.gif',
				'deps' => array(
					'ids'    => 'yith-infs-enable',
					'values' => 'yes',
				),
			),

			array(
				'type' => 'close',
			),
		),

	),
);

return apply_filters( 'yith_infs_panel_settings_options', $settings );
