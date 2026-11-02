<?php
defined( 'ABSPATH' ) || exit;

#[\AllowDynamicProperties]
class BWFAN_Subscribe_Link_Handler {

	private static $ins = null;

	public function __construct() {
		add_action( 'wp', [ __CLASS__, 'handle_subscribe_link' ], 999 );
	}

	/**
	 * Return the object of current class
	 *
	 * @return null|BWFAN_Subscribe_Link_Handler
	 */
	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	/**
	 * handling subscribe link
	 */
	public static function handle_subscribe_link() {
		/** Check for pro version */
		if ( ! bwfan_is_autonami_pro_active() ) {
			return;
		}

		$uid = filter_input( INPUT_GET, 'bwfan-uid' );
		if ( empty( $uid ) ) {
			return;
		}

		$action = filter_input( INPUT_GET, 'bwfan-action' );
		if ( empty( $action ) || 'subscribe' !== sanitize_text_field( $action ) ) {
			return;
		}

		$bwf_contacts = BWF_Contacts::get_instance();
		$dbcontact    = $bwf_contacts->get_contact_by( 'uid', $uid );

		$link = filter_input( INPUT_GET, 'bwfan-link' );
		if ( empty( $link ) ) {
			$general_options   = BWFAN_Common::get_global_settings();
			$confirmation_type = isset( $general_options['after_confirmation_type'] ) ? $general_options['after_confirmation_type'] : 'show_message';
			if ( 'show_message' !== $confirmation_type ) {
				$link = ! empty( $general_options['bwfan_confirmation_redirect_url'] ) ? trim( $general_options['bwfan_confirmation_redirect_url'] ) : '';
			}
		}
	
		$url = '';
		if ( ! empty( $link ) ) {
			// redirecting to bwfan-link if there
			$link = BWFAN_Common::bwfan_correct_protocol_url( $link );
			$automation_id = filter_input( INPUT_GET, 'automation-id' );
			$link = BWFAN_Common::validate_target_link( $link,'', [[  'oid'=>intval($automation_id), 'type'=>1 ]] );
			$link = BWFAN_Common::append_extra_url_arguments( $link );
			if ( false !== BWFAN_Common::bwfan_is_valid_link( $link ) ) {
				$url = BWFAN_Email_Conversations::validate_link( $link );
			}
		}

		if ( empty( $dbcontact->db_contact ) ) {
			BWFAN_Common::wp_redirect( ! empty( $url ) ? urldecode( $url ) : home_url() );
			exit;
		}

		$contact = new BWFCRM_Contact( $dbcontact->db_contact->id );

		/** Apply "Add Tags after confirmation" from form feed when feed-id is in URL */
		$feed_id = filter_input( INPUT_GET, 'feed-id', FILTER_SANITIZE_NUMBER_INT );
		if ( ! empty( $feed_id ) && class_exists( 'BWFCRM_Form_Feed' ) ) {
			$feed = new BWFCRM_Form_Feed( absint( $feed_id ) );
			if ( $feed->is_feed_exists() && method_exists( $feed, 'get_data' ) ) {
				$tag_enabled = $feed->get_data( 'add_tag_enable' );
				if ( $tag_enabled ) {
					$tag_to_add = $feed->get_data( 'tag_to_add' );
					if ( ! empty( $tag_to_add ) && is_array( $tag_to_add ) && method_exists( $contact, 'set_tags' ) ) {
						$contact->set_tags( $tag_to_add );
					}
				}
			}
		}

		/** to mark the contact subscribe and remove the unsubscribe record */
		$contact->resubscribe( false );
		$contact->save();

		/** Hook after subscribe link clicked */
		do_action( 'bwfcrm_confirmation_link_clicked', $contact );

		if ( ! empty( $url ) ) {
			BWFAN_Common::wp_redirect( urldecode( $url ) );
			exit;
		}

		self::display_confirmation_message();
	}

	public static function display_confirmation_message() {
		$settings = BWFAN_Common::get_global_settings();

		add_filter( 'bwfan_public_scripts_include', '__return_true' );

		$header_logo = isset( $settings['bwfan_setting_business_logo'] ) ? $settings['bwfan_setting_business_logo'] : '';

		$common_shortcodes = BWFAN_Common_Shortcodes::get_instance();

		// Enqueue the styles and scripts early
		BWFAN_Public::get_instance()->enqueue_assets( $settings );
		ob_start();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>"/>
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<meta name="robots" content="noindex, nofollow">
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
			<?php wp_print_styles(); ?>
			<title><?php esc_html_e( 'Subscribe', 'wp-marketing-automations' ); ?></title>
<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page prints its own head without wp_head(), so an enqueued style would never print ?>
			<link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">
		</head>
		<body class="bwfan-manage-profile-page-body <?php echo is_rtl() ? 'is-rtl' : ''; ?>">
			<div class="bwfan-manage-profile-page-wrapper">
				<div class="bwfan-manage-profile-wrapper bwf-distraction-free-mode">
					<?php $common_shortcodes->print_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div class="bwfan-manage-profile-contact-details">
						<div class="bwfan-subscribe-page-details">
							<?php
							// Output page content
							echo ! empty( $settings['bwfan_confirmation_message'] ) ? wp_kses_post( $settings['bwfan_confirmation_message'] ) : '';
							?>
						</div>
					</div>
				</div>
			</div>
			<?php wp_print_scripts(); ?>
		</body>
		</html>
		<?php
		echo ob_get_clean();  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

}

/**
 * Register action handler to BWFCRM_Core
 */
if ( class_exists( 'BWFAN_Subscribe_Link_Handler' ) ) {
	BWFAN_Core::register( 'subscribe_link_handler', 'BWFAN_Subscribe_Link_Handler' );
}
