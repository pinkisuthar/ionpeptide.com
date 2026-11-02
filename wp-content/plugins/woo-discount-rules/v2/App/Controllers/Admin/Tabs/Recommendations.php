<?php
//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Wdr\App\Controllers\Admin\Tabs;

use Wdr\App\Controllers\Admin\Settings;
use Wdr\App\Controllers\Configuration;
use Wdr\App\Helpers\Helper;

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class Recommendations extends Base
{
	public $priority = 80;
	protected $tab = 'recommendations';

	/**
	 * GeneralSettings constructor.
	 */
	public function __construct()
	{
		parent::__construct();
		$this->title = __('Recommendations', 'woo-discount-rules');
	}

	/**
	 * Render settings page
	 * @param null $page
	 * @return mixed|void
	 */
	public function render($page = NULL)
	{
		$is_pro_installed = \Wdr\App\Helpers\Helper::hasPro();
		$params = array(
			'is_pro' => $is_pro_installed,
			'wdr_recommendations_list' => self::getRecommendations(),
		);
		self::$template_helper->setPath(WDR_PLUGIN_PATH . 'App/Views/Admin/Tabs/Recommendations.php')->setData($params)->display();
	}

	/**
	 * Get Recommendations list
	 *
	 * @return array
	 *
	 */
	public static function getRecommendations()
	{
		$recommendation_list_url = 'https://static.flycart.net/recommendation/product/woo-discount-rules.json';
		$recommendations_list = get_transient('wdr_recommendations_list');
		if (empty($recommendations_list)) {
			$response = wp_remote_get($recommendation_list_url);
			if (!is_wp_error($response)) {
				$recommendations_list = (array)json_decode(wp_remote_retrieve_body($response), true);
				$site_name = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
				foreach ($recommendations_list as &$recommendation) {
					$recommendation['plugin_url'] = str_replace('{site-name}', $site_name, $recommendation['plugin_url']);
				}
				set_transient('wdr_recommendations_list', $recommendations_list, 24 * 60 * 60);
			}
		}
		return $recommendations_list;
	}
}