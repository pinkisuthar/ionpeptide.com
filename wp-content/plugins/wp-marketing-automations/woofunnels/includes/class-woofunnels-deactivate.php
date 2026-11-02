<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WooFunnels_Deactivate' ) ) {
	/**
	 * Contains the logic for deactivation popups
	 * @since 1.0.0
	 * @author woofunnels
	 * @package WooFunnels
	 */
	#[AllowDynamicProperties]
	class WooFunnels_Deactivate {

		public static $deactivation_str;

		/**
		 * Initialization of hooks where we prepare the functionality to ask use for survey
		 */
		public static function init() {


			add_action( 'admin_init', array( __CLASS__, 'load_all_str' ) );
			add_action( 'admin_footer', array( __CLASS__, 'maybe_load_deactivate_options' ) );

			add_action( 'wp_ajax_woofunnels_submit_uninstall_reason', array( __CLASS__, '_submit_uninstall_reason_action' ) );
		}

		/**
		 * Localizes all the string used
		 */
		public static function load_all_str() {

			self::$deactivation_str = array(
				'deactivation-share-reason'                => __( 'If you have a moment, please let us know why you are deactivating', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-no-longer-needed'                  => __( 'No longer need the plugin', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-found-a-better-plugin'             => __( 'I found an alternate plugin', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-needed-for-a-short-period'         => __( 'I only needed the plugin for a short period', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'placeholder-plugin-name'                  => __( 'Please share which plugin', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-broke-my-site'                     => __( 'Encountered a fatal error', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-suddenly-stopped-working'          => __( 'The plugin suddenly stopped working', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-other'                             => _x( 'Other', 'the text of the "other" reason for deactivating the plugin that is shown in the modal box.', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'deactivation-modal-button-submit'         => __( 'Submit & Deactivate', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'deactivate'                               => __( 'Deactivate', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'deactivation-modal-button-deactivate'     => __( 'Deactivate', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'deactivation-modal-button-confirm'        => __( 'Yes - Deactivate', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'deactivation-modal-button-cancel'         => _x( 'Cancel', 'the text of the cancel button of the plugin deactivation dialog box.', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-cant-pay-anymore'                  => __( "I can't pay for it anymore", 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'placeholder-comfortable-price'            => __( 'What price would you feel comfortable paying?', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-couldnt-make-it-work'              => __( "I couldn't understand how to make it work", 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-great-but-need-specific-feature'   => __( "The plugin is great, but I need specific feature that you don't support", 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-not-working'                       => __( 'Couldn\'t get the plugin to work', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-not-what-i-was-looking-for'        => __( "It's not what I was looking for", 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-didnt-work-as-expected'            => __( "The plugin didn't work as expected", 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'placeholder-feature'                      => __( 'What feature?', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'placeholder-share-what-didnt-work'        => __( "Kindly share what didn't work so we can fix it for future users...", 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'placeholder-what-youve-been-looking-for'  => __( "What you've been looking for?", 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'placeholder-what-did-you-expect'          => __( 'What did you expect?', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-didnt-work'                        => __( "The plugin didn't work", 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'reason-dont-like-to-share-my-information' => __( "I don't like to share my information with you", 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'conflicts-other-plugins'                  => __( "Conflicts with other plugins", 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'temporary-deactivation'                   => __( "It's a temporary deactivation", 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			);
		}

		/**
		 * Checking current page and pushing html, js and css for this task
		 * @global string $pagenow current admin page
		 * @global array $VARS global vars to pass to view file
		 */
		public static function maybe_load_deactivate_options() {
			global $pagenow;

			if ( $pagenow === 'plugins.php' ) {
				global $VARS;

				$VARS = array(
					'slug'    => '',
					'reasons' => self::deactivate_options(),
				);

				include_once dirname( dirname( __FILE__ ) ) . '/views/woofunnels-deactivate-modal.phtml';
			}
		}

		/**
		 * deactivation reasons in array format
		 * @return array reasons array
		 * @since 1.0.0
		 */
		public static function deactivate_options() {

			$reasons            = array( 'default' => [] );
			$reasons['default'] = array(
				array(
					'id'                => 1,
					'text'              => self::load_str( 'reason-not-working' ),
					'input_type'        => '',
					'input_placeholder' => self::load_str( 'placeholder-plugin-name' ),
				),
				array(
					'id'                => 2,
					'text'              => self::load_str( 'reason-found-a-better-plugin' ),
					'input_type'        => 'textfield',
					'input_placeholder' => self::load_str( 'placeholder-plugin-name' )
				),
				array(
					'id'                => 3,
					'text'              => self::load_str( 'reason-no-longer-needed' ),
					'input_type'        => '',
					'input_placeholder' => '',
				),

				array(
					'id'                => 4,
					'text'              => self::load_str( 'temporary-deactivation' ),
					'input_type'        => '',
					'input_placeholder' => '',
				),
				array(
					'id'                => 5,
					'text'              => self::load_str( 'conflicts-other-plugins' ),
					'input_type'        => 'textfield',
					'input_placeholder' => self::load_str( 'placeholder-plugin-name' )
				),
				array(
					'id'                => 6,
					'text'              => self::load_str( 'reason-broke-my-site' ),
					'input_type'        => '',
					'input_placeholder' => '',
				),
				array(
					'id'                => 7,
					'text'              => self::load_str( 'reason-other' ),
					'input_type'        => 'textfield',
					'input_placeholder' => __( 'Please share the reason', 'wp-marketing-automations' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				)
			);


			return $reasons;
		}

		/**
		 * get exact str against the slug
		 *
		 * @param $slug
		 *
		 * @return mixed
		 */
		public static function load_str( $slug ) {
			return self::$deactivation_str[ $slug ];
		}

		/**
		 * Called after the user has submitted his reason for deactivating the plugin.
		 *
		 * @since  1.1.2
		 */
		public static function _submit_uninstall_reason_action() {
			try {
				check_admin_referer( 'bwf_secure_key', '_nonce' );

				if ( ! current_user_can( 'install_plugins' ) ) {
					wp_send_json_error();
				}
				if ( ! isset( $_POST['reason_id'] ) ) {
					wp_send_json_error();
				}

				$reason_info = isset( $_POST['reason_info'] ) ? sanitize_textarea_field( bwf_clean( wp_unslash( $_POST['reason_info'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verification handled by AJAX system; bwf_clean() sanitizes recursively

				$reason = array(
					'id'   => sanitize_text_field( wp_unslash( $_POST['reason_id'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled by AJAX system
					'info' => substr( $reason_info, 0, 128 ),
				);

				$licenses        = WooFunnels_addons::get_installed_plugins();
				$version         = 'NA';
				$plugin_basename = isset( $_POST['plugin_basename'] ) ? bwf_clean( wp_unslash( $_POST['plugin_basename'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- bwf_clean() sanitizes recursively

				if ( $licenses && count( $licenses ) > 0 ) {
					foreach ( $licenses as $key => $license ) {
						if ( $key === $plugin_basename ) {
							$version = $license['Version'];
						}
					}
				}

				$deactivations = array(
					$plugin_basename . '(' . $version . ')' => $reason,
				);

				WooFunnels_API::post_deactivation_data( $deactivations );

			} catch ( Exception $e ) {
				// Log the exception if necessary
			}

			wp_send_json_success();
		}


	}
}
