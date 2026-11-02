<?php
/**
 * Admin page promoting WP Mail SMTP.
 *
 * @package WPCode
 */

/**
 * Class WPCode_Admin_Page_WP_Mail_SMTP.
 */
class WPCode_Admin_Page_WP_Mail_SMTP extends WPCode_Admin_Page_Plugin_Promo {

	/**
	 * The page slug to be used when adding the submenu.
	 *
	 * @var string
	 */
	public $page_slug = 'wpcode-wp-mail-smtp';

	/**
	 * Installer slug.
	 *
	 * @var string
	 */
	protected $plugin_key = 'wp-mail-smtp';

	/**
	 * Plugin display name.
	 *
	 * @var string
	 */
	protected $plugin_name = 'WP Mail SMTP';

	/**
	 * Set the titles.
	 */
	public function __construct() {
		$this->page_title = __( 'SMTP', 'insert-headers-and-footers' );
		$this->menu_title = __( 'SMTP', 'insert-headers-and-footers' );
		parent::__construct();
	}

	/**
	 * WP Mail SMTP's settings screen, shared by both editions.
	 *
	 * @return string[]
	 */
	protected function get_plugin_menu_slugs() {
		return array( 'wp-mail-smtp' );
	}

	/**
	 * Headline.
	 *
	 * @return string
	 */
	protected function get_headline() {
		return __( 'Making Email Deliverability Easy for WordPress', 'insert-headers-and-footers' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	protected function get_description() {
		return __( 'WP Mail SMTP fixes deliverability problems with your WordPress emails and form notifications.', 'insert-headers-and-footers' );
	}

	/**
	 * Feature bullets.
	 *
	 * @return string[]
	 */
	protected function get_features() {
		return array(
			__( 'Improves email deliverability in WordPress.', 'insert-headers-and-footers' ),
			__( 'Used by 4+ million websites.', 'insert-headers-and-footers' ),
			__( 'Free mailers: SendLayer, SMTP.com, Brevo, Google Workspace / Gmail, Mailgun, Postmark, SendGrid.', 'insert-headers-and-footers' ),
			__( 'Pro mailers: Amazon SES, Microsoft 365 / Outlook.com, Zoho Mail.', 'insert-headers-and-footers' ),
		);
	}

	/**
	 * Screenshot thumbnail.
	 *
	 * @return string
	 */
	protected function get_screenshot_thumb() {
		return 'wp-mail-smtp-screenshot-thumb.png';
	}

	/**
	 * Full-size screenshot.
	 *
	 * @return string
	 */
	protected function get_screenshot_full() {
		return 'wp-mail-smtp-screenshot.png';
	}
}
