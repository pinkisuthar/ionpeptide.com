<?php
/**
 * Admin page promoting Uncanny Automator.
 *
 * @package WPCode
 */

/**
 * Class WPCode_Admin_Page_Uncanny_Automator.
 */
class WPCode_Admin_Page_Uncanny_Automator extends WPCode_Admin_Page_Plugin_Promo {

	/**
	 * The page slug to be used when adding the submenu.
	 *
	 * @var string
	 */
	public $page_slug = 'wpcode-uncanny-automator';

	/**
	 * Installer slug.
	 *
	 * @var string
	 */
	protected $plugin_key = 'uncanny-automator';

	/**
	 * Plugin display name.
	 *
	 * @var string
	 */
	protected $plugin_name = 'Uncanny Automator';

	/**
	 * Set the titles.
	 */
	public function __construct() {
		$this->page_title = __( 'Automation', 'insert-headers-and-footers' );
		$this->menu_title = __( 'Automation', 'insert-headers-and-footers' );
		parent::__construct();
	}

	/**
	 * The Automator dashboard, registered under the recipes post type by both editions.
	 *
	 * @return string[]
	 */
	protected function get_plugin_menu_slugs() {
		return array( 'uncanny-automator-dashboard' );
	}

	/**
	 * Headline.
	 *
	 * @return string
	 */
	protected function get_headline() {
		return __( 'Let Your Site Handle the Busywork', 'insert-headers-and-footers' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	protected function get_description() {
		return __( 'Automate tasks, save time, and keep everything running smoothly. Uncanny Automator connects your favorite plugins and tools so your site works smarter. No code. No stress.', 'insert-headers-and-footers' );
	}

	/**
	 * Feature bullets.
	 *
	 * @return string[]
	 */
	protected function get_features() {
		return array(
			__( 'Connect WordPress plugins and apps with no-code recipes.', 'insert-headers-and-footers' ),
			__( 'Trigger actions when users register, purchase or submit a form.', 'insert-headers-and-footers' ),
			__( 'Send emails, update your CRM or post to Slack automatically.', 'insert-headers-and-footers' ),
			__( 'Works with the tools you already use.', 'insert-headers-and-footers' ),
		);
	}

	/**
	 * Screenshot thumbnail.
	 *
	 * @return string
	 */
	protected function get_screenshot_thumb() {
		return 'uncanny-automator-screenshot-thumb.png';
	}

	/**
	 * Full-size screenshot.
	 *
	 * @return string
	 */
	protected function get_screenshot_full() {
		return 'uncanny-automator-screenshot.jpg';
	}
}
