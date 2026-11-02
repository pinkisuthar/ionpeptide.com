<?php
//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Wdr\App\Controllers\Admin\Tabs;

if (!defined('ABSPATH')) exit;

class Help extends Base
{
    public $priority = 100;
    protected $tab = 'help';

    /**
     * Help constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->title = __('Documentation', 'woo-discount-rules');
    }

    /**
     * Render Read documents page
     * @param null $page
     * @return mixed|void
     */
    public function render($page = NULL)
    {
        $is_pro_installed = \Wdr\App\Helpers\Helper::hasPro();
        $params = array(
            'is_pro' => $is_pro_installed,
	        'recommended_addon' => $this->getRecommendedAddon()

    );
        self::$template_helper->setPath(WDR_PLUGIN_PATH . 'App/Views/Admin/Tabs/help.php')->setData($params)->display();
    }

	public function getRecommendedAddon(){
		$site_name = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		return  [
			"wployalty" => [
				"name" => "WPLoyalty - Loyalty Points, Rewards & Referrals",
				"author" => "WPLoyalty",
				"description" => "Best Loyalty Points, Rewards & Referral plugin for WooCommerce. 10x your repeat sales by rewarding customers with points for purchases, sign-ups, reviews, referrals, social shares, birthdays, and more",
				"icon_url" => "https://static.flycart.net/recommendation/icons/wployalty.png",
				"plugin_url" => str_replace('{site-name}', $site_name,"https://wployalty.net/?utm_campaign=rule&utm_source=woo-discount-rules&utm_medium={site-name}"),
				"banner_image" => "https://static.flycart.net/recommendation/image/wployalty.png",
				"primary_color" => "#5C54EC",
			],
			"upsellwp" => [
				"name" => "UpsellWP - Upsells, Cross-sells, Order bumps",
				"author" => "UpsellWP",
				"description" => "Increase average order value with the all-in-one upsell plugin. Effortlessly create one-click upsells, order bumps, frequently bought together offers, post purchase upsells, cart upsells, thank you page upsells, and added-to-cart popup product recommendations.",
				"icon_url" => "https://static.flycart.net/recommendation/icons/upsellwp.png",
				"plugin_url" => str_replace('{site-name}', $site_name,"https://upsellwp.com/?utm_campaign=rule&utm_source=woo-discount-rules&utm_medium={site-name}"),
				"banner_image" => "https://static.flycart.net/recommendation/image/upsellwp.png",
				"primary_color" => "#012359",
			],
		];
	}

}