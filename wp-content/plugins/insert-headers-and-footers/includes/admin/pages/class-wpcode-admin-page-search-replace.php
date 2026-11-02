<?php
/**
 * Admin page promoting Search & Replace Everything.
 *
 * @package WPCode
 */

/**
 * Class WPCode_Admin_Page_Search_Replace.
 */
class WPCode_Admin_Page_Search_Replace extends WPCode_Admin_Page_Plugin_Promo {

	/**
	 * The page slug to be used when adding the submenu.
	 *
	 * @var string
	 */
	public $page_slug = 'wpcode-search-replace';

	/**
	 * Installer slug.
	 *
	 * @var string
	 */
	protected $plugin_key = 'search-replace-wpcode';

	/**
	 * Plugin display name.
	 *
	 * @var string
	 */
	protected $plugin_name = 'Search & Replace Everything';

	/**
	 * Set the titles.
	 */
	public function __construct() {
		$this->page_title = __( 'Search & Replace Everything', 'insert-headers-and-footers' );
		$this->menu_title = __( 'Search & Replace', 'insert-headers-and-footers' );
		parent::__construct();
	}

	/**
	 * The Search & Replace screen under Tools, shared by both editions.
	 *
	 * @return string[]
	 */
	protected function get_plugin_menu_slugs() {
		return array( 'wsrw-search-replace' );
	}

	/**
	 * Inline SVG hero icon.
	 *
	 * @return void
	 */
	protected function output_hero_icon() {
		wpcode_icon( 'logo-sr', 90, 90, '0 0 65 64' );
	}

	/**
	 * Headline.
	 *
	 * @return string
	 */
	protected function get_headline() {
		return __( 'Search & Replace Everything by WPCode', 'insert-headers-and-footers' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	protected function get_description() {
		return __( 'Confidently replace any text in your database and update images without duplicating uploads, all with a single plugin.', 'insert-headers-and-footers' );
	}

	/**
	 * Feature bullets.
	 *
	 * @return string[]
	 */
	protected function get_features() {
		return array(
			__( 'Replace Text Across Your Whole Database.', 'insert-headers-and-footers' ),
			__( 'Preview Changes Every Time.', 'insert-headers-and-footers' ),
			__( 'Replace Image Sources to Clear Up Space.', 'insert-headers-and-footers' ),
			__( 'Support for Serialized Data.', 'insert-headers-and-footers' ),
		);
	}

	/**
	 * Screenshot thumbnail.
	 *
	 * @return string
	 */
	protected function get_screenshot_thumb() {
		return 'sr-screenshot-thumb.jpg';
	}

	/**
	 * Full-size screenshot.
	 *
	 * @return string
	 */
	protected function get_screenshot_full() {
		return 'sr-screenshot.jpg';
	}
}
