<?php
/**
 * Admin page promoting Duplicator.
 *
 * @package WPCode
 */

/**
 * Class WPCode_Admin_Page_Duplicator.
 */
class WPCode_Admin_Page_Duplicator extends WPCode_Admin_Page_Plugin_Promo {

	/**
	 * The page slug to be used when adding the submenu.
	 *
	 * @var string
	 */
	public $page_slug = 'wpcode-duplicator';

	/**
	 * Installer slug.
	 *
	 * @var string
	 */
	protected $plugin_key = 'duplicator';

	/**
	 * Plugin display name.
	 *
	 * @var string
	 */
	protected $plugin_name = 'Duplicator';

	/**
	 * Set the titles.
	 */
	public function __construct() {
		$this->page_title = __( 'Secure Backups', 'insert-headers-and-footers' );
		$this->menu_title = __( 'Backups', 'insert-headers-and-footers' );
		parent::__construct();
	}

	/**
	 * Duplicator's main screen, Pro first.
	 *
	 * @return string[]
	 */
	protected function get_plugin_menu_slugs() {
		return array( 'duplicator-pro', 'duplicator' );
	}

	/**
	 * Inline SVG hero icon.
	 *
	 * @return void
	 */
	protected function output_hero_icon() {
		wpcode_icon( 'duplicator', 90, 90, '198 55 90 396' );
	}

	/**
	 * Headline.
	 *
	 * @return string
	 */
	protected function get_headline() {
		return __( 'Protect Your Website with Secure WordPress Backups', 'insert-headers-and-footers' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	protected function get_description() {
		return __( 'Duplicator offers cloud WordPress backups with military-grade encryption. Automatically backup your entire WordPress site to secure cloud storage.', 'insert-headers-and-footers' );
	}

	/**
	 * Feature bullets.
	 *
	 * @return string[]
	 */
	protected function get_features() {
		return array(
			__( 'Automatic Website Backups Made Easy.', 'insert-headers-and-footers' ),
			__( 'Secure Cloud Storage Integration (Google Drive, AWS, Dropbox, and more).', 'insert-headers-and-footers' ),
			__( '1-Click Restore from Recovery Points.', 'insert-headers-and-footers' ),
			__( 'Website Cloning and Migration.', 'insert-headers-and-footers' ),
			__( 'Used by 1.5 Million websites.', 'insert-headers-and-footers' ),
		);
	}

	/**
	 * Screenshot thumbnail.
	 *
	 * @return string
	 */
	protected function get_screenshot_thumb() {
		return 'duplicator.png';
	}

	/**
	 * Full-size screenshot.
	 *
	 * @return string
	 */
	protected function get_screenshot_full() {
		return 'duplicator.png';
	}
}
