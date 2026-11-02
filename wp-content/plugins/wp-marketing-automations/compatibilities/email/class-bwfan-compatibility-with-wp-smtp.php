<?php
defined( 'ABSPATH' ) || exit;
/**
 * WP Mail SMTP
 * https://wordpress.org/plugins/wp-mail-smtp/
 */
if ( ! class_exists( 'BWFAN_Compatibility_With_WP_SMTP' ) ) {
	class BWFAN_Compatibility_With_WP_SMTP {

		/**
		 * checking for smart routing/Backup connection enabled
		 *
		 * @return bool
		 */
		public static function has_multiple_connections() {
			if ( ! class_exists( 'WPMailSMTP\Options' ) ) {
				return false;
			}

			return ( WPMailSMTP\Options::init()->get( 'smart_routing', 'enabled' ) || WPMailSMTP\Options::init()->get( 'backup_connection', 'connection_id' ) );
		}
	}
}
