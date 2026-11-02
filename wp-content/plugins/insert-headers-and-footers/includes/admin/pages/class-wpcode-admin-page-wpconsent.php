<?php
/**
 * Admin page promoting WPConsent.
 *
 * @package WPCode
 */

/**
 * Class WPCode_Admin_Page_WPConsent.
 */
class WPCode_Admin_Page_WPConsent extends WPCode_Admin_Page_Plugin_Promo {

	/**
	 * The page slug to be used when adding the submenu.
	 *
	 * @var string
	 */
	public $page_slug = 'wpcode-wpconsent';

	/**
	 * Installer slug.
	 *
	 * @var string
	 */
	protected $plugin_key = 'wpconsent';

	/**
	 * Plugin display name.
	 *
	 * @var string
	 */
	protected $plugin_name = 'WPConsent';

	/**
	 * Set the titles.
	 */
	public function __construct() {
		$this->page_title = __( 'Privacy Compliance', 'insert-headers-and-footers' );
		$this->menu_title = __( 'Privacy Compliance', 'insert-headers-and-footers' );
		parent::__construct();
	}

	/**
	 * WPConsent's main screen, shared by both editions.
	 *
	 * @return string[]
	 */
	protected function get_plugin_menu_slugs() {
		return array( 'wpconsent' );
	}

	/**
	 * Headline.
	 *
	 * @return string
	 */
	protected function get_headline() {
		return __( 'Make Your Website Privacy-Compliant in Minutes', 'insert-headers-and-footers' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	protected function get_description() {
		return __( 'Build trust with clear, compliant privacy practices. WPConsent adds a clean, professional cookie banner and handles the technical side for you.', 'insert-headers-and-footers' );
	}

	/**
	 * Feature bullets.
	 *
	 * @return string[]
	 */
	protected function get_features() {
		return array(
			__( 'A professional cookie banner that fits your site.', 'insert-headers-and-footers' ),
			__( 'Tools like Google Analytics and Facebook Pixel paused until consent.', 'insert-headers-and-footers' ),
			__( 'Peace of mind knowing you are aligned with global privacy laws.', 'insert-headers-and-footers' ),
			__( 'Self-hosted. Your data remains on your site.', 'insert-headers-and-footers' ),
		);
	}

	/**
	 * Screenshot thumbnail.
	 *
	 * @return string
	 */
	protected function get_screenshot_thumb() {
		return 'wpconsent-screenshot-thumb.png';
	}

	/**
	 * Full-size screenshot.
	 *
	 * @return string
	 */
	protected function get_screenshot_full() {
		return 'wpconsent-screenshot.png';
	}
}
