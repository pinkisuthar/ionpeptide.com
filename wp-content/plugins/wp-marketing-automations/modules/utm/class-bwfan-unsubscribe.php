<?php
defined( 'ABSPATH' ) || exit;

#[\AllowDynamicProperties]
class BWFAN_unsubscribe {

	private static $ins = null;
	protected $settings = null;
	protected $recipient = '';
	protected $uid = '';
	protected $unsubscribe_lists = [];
	protected $unsubscribe_all = false;

	/** @var WooFunnels_Contact */
	protected $contact = '';

	/** @var BWFCRM_Contact */
	protected $crm_contact = '';
	protected $one_click = 0;

	protected $unsubscribe_page = false;

	protected $common_shortcodes = null;

	protected $unsubscribe_redirect = null;

	protected $shortcode_loaded = false;

	public function __construct() {
		add_action( 'bwfan_db_1_0_tables_created', array( $this, 'create_unsubscribe_sample_page' ) );

		/** Admin page selection call */
		add_action( 'wp_ajax_bwfan_select_unsubscribe_page', array( $this, 'bwfan_select_unsubscribe_page' ) );

		/** Front ajax call */
		add_action( 'wp_ajax_bwfan_unsubscribe_user', array( $this, 'bwfan_unsubscribe_user' ) );
		add_action( 'wp_ajax_nopriv_bwfan_unsubscribe_user', array( $this, 'bwfan_unsubscribe_user' ) );
		if ( ! is_admin() ) {
			add_action( 'wp', array( $this, 'bwfan_display_body_content' ) );
			add_action( 'wp', array( $this, 'unsubscribe_page_non_crawlable' ) );
			add_action( 'wp', array( $this, 'unsubscribe_static_page' ), 100 );
			/** Shortcodes for unsubscribe */
			add_shortcode( 'bwfan_unsubscribe_button', array( $this, 'bwfan_unsubscribe_button' ) );
			add_shortcode( 'wfan_unsubscribe_button', array( $this, 'bwfan_unsubscribe_button' ) );
			add_shortcode( 'fka_contact_subscribe_form', array( $this, 'bwfan_unsubscribe_button' ) );
			add_shortcode( 'fka_unsubscribe_button', array( $this, 'bwfan_unsubscribe_button' ) );

		}

		if ( ! is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			$this->common_shortcodes = BWFAN_Common_Shortcodes::get_instance();
		}


		/** Check if page built using elementor, delete the cache for the page */
		if ( defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, '3.23.0', '>=' ) ) {
			if ( ! ( ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) ) || ! is_admin() ) {
				add_action( 'wp', array( $this, 'delete_elementor_cache' ) );
			}
		}
	}

	/**
	 * get the instance of the current class
	 * @return self|null
	 */
	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	/**
	 * Check for distraction-free mode
	 *
	 * @param array $setting
	 *
	 * @return bool
	 */
	public function is_distraction_free_mode( $setting = [] ) {
		$isPreview = filter_input( INPUT_GET, 'bwf-preview' ) ?? '';
		switch ( $isPreview ) {
			case 'prebuild':
				return true;
			case 'custom':
				return false;
			default:
				return ! empty( $setting['bwfan_unsubscribe_page_type'] ) && 'prebuild' === $setting['bwfan_unsubscribe_page_type'];
		}
	}

	/**
	 * get the body content
	 * @return false|void
	 */
	public function bwfan_display_body_content() {
		$setting = $this->get_global_settings();

		/** Check current page is unsubscribe page */
		$manage_unsubscribe_page_check = false;
		if ( isset( $setting['bwfan_unsubscribe_page'] ) && ! empty( $setting['bwfan_unsubscribe_page'] ) ) {
			$manage_unsubscribe_page_check = is_page( absint( $setting['bwfan_unsubscribe_page'] ) );
		}

		if ( ! $manage_unsubscribe_page_check ) {
			return;
		}

		$this->set_data();

		if ( empty( $this->contact ) ) {
			return;
		}

		/** Check if distraction-free mode is enabled */
		if ( ! $this->is_distraction_free_mode( $setting ) ) {
			return;
		}

		$header_logo = ( isset( $setting['bwfan_setting_business_logo'] ) ) ? $setting['bwfan_setting_business_logo'] : '';

		// Remove emoji detection script and styles
		remove_action('wp_head', 'print_emoji_detection_script', 7);
		remove_action('wp_print_styles', 'print_emoji_styles');

		// Remove all actions from widgets_init
		remove_all_actions( 'widgets_init' );

		// Enqueue the styles and scripts early
		BWFAN_Public::get_instance()->enqueue_assets( $setting );
		$page_title = ! empty( $setting['bwfan_unsubscribe_page_title'] ) ? $setting['bwfan_unsubscribe_page_title'] : __( 'Unsubscribe', 'wp-marketing-automations' );
		ob_start();
		?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>"/>
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
            <meta name="robots" content="noindex, nofollow">
			<?php wp_print_styles(); ?>
            <title><?php echo esc_html( $page_title ); ?></title>
<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page prints its own head without wp_head(), so an enqueued style would never print ?>
            <link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet">
        </head>
        <body class="bwfan-manage-profile-page-body <?php echo is_rtl() ? 'is-rtl' : ''; ?>">
        <div class="bwfan-manage-profile-page-wrapper">
			<?php

			// Wrapper
			echo '<div class="bwfan-subscribe-page-wrapper ' . ( $header_logo ? 'bwf-distraction-free-mode' : 'bwf-distraction-free-mode no-header' ) . '">';
			// Output unsubscribe page content
			echo $this->bwfan_unsubscribe_button(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_print_scripts();
			?>
        </div>
        </body>
        </html>
		<?php
		echo ob_get_clean();  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function create_unsubscribe_sample_page() {
		$global_settings = get_option( 'bwfan_global_settings', array() );

		if ( isset( $global_settings['bwfan_unsubscribe_page'] ) && intval( $global_settings['bwfan_unsubscribe_page'] ) > 0 ) {
			return;
		}

		$content  = sprintf( __( "Hi %s \n\nHelp us to improve your experience with us through better communication. Please adjust your preferences for email %s. \n\n%s.", 'wp-marketing-automations' ), '[fka_contact_name]', '[fka_contact_email]', '[fka_contact_subscribe_form label="Update my preference"]' ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment, WordPress.WP.I18n.UnorderedPlaceholdersText
		$new_page = array(
			'post_title'   => __( "Let's Keep In Touch", 'wp-marketing-automations' ),
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'page',
		);
		$post_id  = wp_insert_post( $new_page );

		$global_settings['bwfan_unsubscribe_page'] = strval( $post_id );
		update_option( 'bwfan_global_settings', $global_settings, true );
	}

	/**
	 * Adding noindex, nofollow meta tag for unsubscribe page
	 * Set data for execution
	 *
	 * @return void
	 */
	public function unsubscribe_page_non_crawlable() {
		$global_settings     = $this->get_global_settings();
		$unsubscribe_page_id = isset( $global_settings['bwfan_unsubscribe_page'] ) ? $global_settings['bwfan_unsubscribe_page'] : 0;
		if ( empty( $unsubscribe_page_id ) || ! is_page( $unsubscribe_page_id ) ) {
			return;
		}

		$uid = filter_input( INPUT_GET, 'uid' );
		$uid = sanitize_key( $uid );
		if ( ! empty( $uid ) && ! headers_sent() ) {
			BWFAN_Common::set_cookie( '_fk_contact_uid', $uid, time() + ( 10 * YEAR_IN_SECONDS ) );
		}

		$one_click = filter_input( INPUT_POST, 'List-Unsubscribe' );
		if ( 'One-Click' === $one_click ) {
			$this->bwfan_unsubscribe_user( true );

			return;
		}

		$this->unsubscribe_page = true;

		$one_click = filter_input( INPUT_GET, 'List-Unsubscribe' );
		if ( 'One-Click' === $one_click ) {
			$this->one_click = 1;
		}

		add_filter( 'bwfan_public_scripts_include', '__return_true' );

		/** Set no cache header */
		BWFAN_Common::nocache_headers();

		add_action( 'wp_head', function () {
			if ( 0 === $this->one_click ) {
				return;
			}
			echo "<script>window.bwfan_unsubscribe_preference = 'one_click';</script>";
		} );
	}

	/**
	 * Backward handling in case contact uid present and not an unsubscribe page
	 *
	 * @return void
	 */
	public function unsubscribe_static_page() {
		if ( true === $this->unsubscribe_page ) {
			return;
		}

		/** Not an unsubscribe page */

		$uid = filter_input( INPUT_GET, 'uid' );
		$uid = sanitize_key( $uid );
		if ( empty( $uid ) ) {
			return;
		}

		$action = filter_input( INPUT_GET, 'bwfan-action' );
		if ( in_array( $action, [ 'subscribe', 'view_in_browser', 'incentive' ], true ) ) {
			return;
		}
		$link_hash = filter_input( INPUT_GET, 'bwfan-link-trigger' );
		if ( ! empty( $link_hash ) ) {
			return;
		}
		$feed_id = filter_input( INPUT_GET, 'form_feed_id' );
		if ( ! empty( $feed_id ) ) {
			return;
		}

		$track_action = filter_input( INPUT_GET, 'bwfan-track-action' );
		if ( 'unsubscribe' === $action && is_null( $track_action ) ) {
			$this->set_data();
			$this->display_unsubscribe_page();

			return;
		}
	}

	/**
	 * Display static unsubscribe page
	 *
	 * @return void
	 */
	protected function display_unsubscribe_page() {
		if ( empty( $this->recipient ) ) {
			return;
		}

		/** Mark contact unsubscribed */
		$this->mark_unsubscribe( false );

		$settings = BWFAN_Common::get_global_settings();

		echo sprintf( "<div class='bwf-h2'>%s</div>", esc_html__( 'Successfully Unsubscribed', 'wp-marketing-automations' ) );
		if ( isset( $settings['bwfan_unsubscribe_data_success'] ) && ! empty( $settings['bwfan_unsubscribe_data_success'] ) ) {
			echo sprintf( "<p>%s</p>", wp_kses_post( $settings['bwfan_unsubscribe_data_success'] ) );
		}
		exit;
	}

	/**
	 * Unsubscribe button shortcode
	 *
	 * @param array $attrs Shortcode attributes
	 *
	 * @return string|void
	 */
	public function bwfan_unsubscribe_button( $attrs = [] ) {

		if ( true === $this->shortcode_loaded ) {
			return '';
		}
		$this->shortcode_loaded = true;

		$this->set_data();

		if ( empty( $this->contact ) ) {
			return;
		}

		$global_settings = $this->get_global_settings();
		$attr            = shortcode_atts( array(
			'label' => __( 'Update my preference', 'wp-marketing-automations' ),
		), $attrs );
		$mode            = $this->is_distraction_free_mode( $global_settings ) ? 'bwf-distraction-free-mode' : '';
		$btn_label       = $attr['label'];

		if ( 'bwf-distraction-free-mode' === $mode ) {
			// Unsubscribe Header
			$this->common_shortcodes->print_header();
			$btn_label = ! empty( $global_settings['bwfan_unsubscribe_button_text'] ) ? $global_settings['bwfan_unsubscribe_button_text'] : __( 'Unsubscribe', 'wp-marketing-automations' );
			$this->get_contact_detail_section( $mode );
		}
		ob_start();
		echo "<style type='text/css'>
			.bwfan_loading{opacity: 1!important;position: relative;color: rgba(255,255,255,.05)!important;pointer-events: none!important;}
			.bwfan_loading::-moz-selection {color: rgba(255, 255, 255, .05) !important;}
			.bwfan_loading::selection {color: rgba(255, 255, 255, .05) !important;}
			.bwfan_loading:after {animation: bwfan_spin 500ms infinite linear;border: 2px solid #fff;border-radius: 50%;border-right-color: transparent !important;border-top-color: transparent !important;content: '';display: block;width: 16px;height: 16px;top: 50%;left: 50%;margin-top: -8px;margin-left: -8px;position: absolute;}
			@keyframes bwfan_spin { 0% {transform: rotate(0deg)}100% {transform: rotate(360deg)}}
		</style>";

		echo '<form id="bwfan_unsubscribe_fields">';
		do_action( 'bwfan_print_custom_data', $this->contact );

		$this->print_unsubscribe_lists();

		echo '<a id="bwfan_unsubscribe" class="button-primary button" href="#">' . esc_html( $btn_label ) . '</a>'; //phpcs:ignore WordPress.Security.EscapeOutput
		$aid = filter_input( INPUT_GET, 'automation_id', FILTER_SANITIZE_NUMBER_INT );
		if ( ! empty( $aid ) ) {
			echo '<input type="hidden" id="bwfan_automation_id" value="' . esc_attr( $aid ) . '" name="automation_id">';
		}

		$bid = filter_input( INPUT_GET, 'bid', FILTER_SANITIZE_NUMBER_INT );
		$bid = empty( $bid ) ? filter_input( INPUT_GET, 'broadcast_id', FILTER_SANITIZE_NUMBER_INT ) : $bid;
		if ( ! empty( $bid ) ) {
			echo '<input type="hidden" id="bwfan_broadcast_id" value="' . esc_attr( $bid ) . '" name="broadcast_id">';
		}

		$fid = filter_input( INPUT_GET, 'fid', FILTER_SANITIZE_NUMBER_INT );
		$fid = empty( $fid ) ? filter_input( INPUT_GET, 'form_feed_id', FILTER_SANITIZE_NUMBER_INT ) : $fid;
		if ( ! empty( $fid ) ) {
			echo '<input type="hidden" id="bwfan_form_feed_id" value="' . esc_attr( $fid ) . '" name="form_feed_id">';
		}

		$uid = filter_input( INPUT_GET, 'uid' );
		$uid = empty( $uid ) ? filter_input( INPUT_POST, 'uid' ) : $uid;
		$uid = sanitize_key( $uid );
		if ( empty( $uid ) && ! empty( $this->uid ) ) {
			$uid = $this->uid;
		}
		if ( ! empty( $uid ) ) {
			echo '<input type="hidden" id="bwfan_form_uid_id" value="' . esc_attr( $uid ) . '" name="uid">';
		}

		echo '<input type="hidden" id="bwfan_one_click" value="' . esc_attr( $this->one_click ) . '" name="one_click">';

		$sid = filter_input( INPUT_GET, 'sid', FILTER_SANITIZE_NUMBER_INT );
		if ( ! empty( $sid ) ) {
			echo '<input type="hidden" id="bwfan_sid" value="' . esc_attr( $sid ) . '">';
		}

		$tid = filter_input( INPUT_GET, 'tid', FILTER_SANITIZE_NUMBER_INT );
		if ( ! empty( $tid ) ) {
			echo '<input type="hidden" id="bwfan_tid" value="' . esc_attr( $tid ) . '">';
		}

		echo '<input type="hidden" id="bwfan_unsubscribe_nonce" value="' . esc_attr( wp_create_nonce( 'bwfan-unsubscribe-nonce' ) ) . '" name="bwfan_unsubscribe_nonce">';
		echo '</form>';

		return ob_get_clean();
	}

	/*
	 * get the contact details section
	 */
	public function get_contact_detail_section( $mode = '' ) {
		$global_settings    = $this->get_global_settings();
		$contact_details    = $this->get_subscriber_details();
		$name               = ! empty( $contact_details['subscriber_name'] ) ? $contact_details['subscriber_name'] : '';
		$email              = ! empty( $contact_details['subscriber_email'] ) ? $contact_details['subscriber_email'] : '';
		$show_profile_link  = ! empty( $global_settings['bwfan_unsubscribe_show_manage_link'] ) ? $global_settings['bwfan_unsubscribe_show_manage_link'] : 0;
		$page_title         = ! empty( $global_settings['bwfan_unsubscribe_page_title'] ) ? $global_settings['bwfan_unsubscribe_page_title'] : __( 'Unsubscribe', 'wp-marketing-automations' );
		$url                = '';
		$profile_link_title = '';
		if ( $show_profile_link && ! empty( $global_settings['bwfan_profile_page'] ) ) {
			$url = get_permalink( absint( $global_settings['bwfan_profile_page'] ) );
			if ( ! empty( $global_settings['bwfan_profile_page_title'] ) ) {
				$profile_link_title = $global_settings['bwfan_profile_page_title'];
			} else {
				$profile_link_title = __( 'Manage Profile', 'wp-marketing-automations' );
			}
		}

		$style = '';
		if ( ! empty( $url ) && ! empty( $global_settings['bwfan_setting_business_color'] ) ) {
			// Validate hex color format
			$color = sanitize_hex_color( $global_settings['bwfan_setting_business_color'] );
			if ( $color ) {
				$style = 'style="color: ' . esc_attr( $color ) . '; border: 1px solid ' . esc_attr( $color ) . ';"';
			}
		}
		?>
        <div class="bwfan-manage-profile-contact-details">
            <div class="bwfan-details">
				<?php
				if ( 'bwf-distraction-free-mode' === $mode ) {
					echo '<div class="bwf-page-title">' . esc_html( $page_title ) . '</div>';
				}
				?>
				<?php
				if ( ! empty( $name ) && 'bwf-distraction-free-mode' === $mode ) {
					echo '<div class="bwf-contact-info"> <span class="bwfan-manage-profile-contact-name">' . esc_html( $name ) . '</span> (<span class="bwfan-manage-profile-contact-email">' . esc_html( $email ) . '</span>) </div>';
				}
				?>
            </div>
			<?php echo( ! empty( $url ) ? '<a href="' . esc_url( $url ) . '" class="bwf-manage-profile-link" ' . esc_attr( $style ) . ' >' . esc_html( $profile_link_title ) . '</a>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
		<?php
	}

	/**
	 * print the unsubscribe lists
	 * @return bool
	 */
	public function print_unsubscribe_lists() {
		/** If admin screen, return */
		if ( is_admin() ) {
			return false;
		}

		$this->set_data();

		$settings = $this->get_global_settings();

		/** One click unsubscribe call via get request */
		if ( 1 === $this->one_click ) {
			$this->only_unsubscribe_from_all_lists_html();

			return false;
		}

		$enabled = isset( $settings['bwfan_unsubscribe_lists_enable'] ) ? $settings['bwfan_unsubscribe_lists_enable'] : 0;
		if ( 0 === absint( $enabled ) ) {
			$this->only_unsubscribe_from_all_lists_html();

			return false;
		}

		$lists = isset( $settings['bwfan_unsubscribe_public_lists'] ) ? $settings['bwfan_unsubscribe_public_lists'] : [];
		if ( empty( $lists ) || ! is_array( $lists ) ) {
			$this->only_unsubscribe_from_all_lists_html();

			return false;
		}

		if ( ! $this->contact instanceof WooFunnels_Contact || 0 === $this->contact->get_id() ) {
			$this->only_unsubscribe_from_all_lists_html();

			return false;
		}

		$lists            = array_map( 'absint', $lists );
		$is_unsubscribed  = false;
		$subscribed_lists = array();

		if ( $this->crm_contact instanceof BWFCRM_Contact ) {
			/** Is Unsubscribed Flag */
			$is_unsubscribed = BWFCRM_Contact::$DISPLAY_STATUS_UNSUBSCRIBED === $this->crm_contact->get_display_status();

			$contact_lists    = $this->get_contact_lists();
			$subscribed_lists = $contact_lists['subscribed'];
			$contact_lists    = $contact_lists['all'];

			/** Show contact their subscribed public lists only */
			$visibility = isset( $settings['bwfan_unsubscribe_lists_visibility'] ) ? $settings['bwfan_unsubscribe_lists_visibility'] : 0;
			if ( 1 === intval( $visibility ) || true === $visibility || 'true' === $visibility ) {
				/** Common lists from public lists and contact lists */
				$lists = array_values( array_intersect( $contact_lists, $lists ) );
			}

			if ( empty( $lists ) ) {
				$this->only_unsubscribe_from_all_lists_html( $this->crm_contact );

				return false;
			}
		}

		$lists = BWFCRM_Lists::get_lists( $lists );

		usort( $lists, function ( $l1, $l2 ) {
			return strcmp( strtolower( $l1['name'] ), strtolower( $l2['name'] ) );
		} );
		$this->unsubscribe_lists_html( $lists, $subscribed_lists, $is_unsubscribed );

		return true;
	}

	/**
	 * Renders HTML for the "unsubscribe from all" option only
	 *
	 * @param $contact
	 *
	 * @return void
	 */
	public function only_unsubscribe_from_all_lists_html( $contact = false ) {
		if ( false === $contact && $this->crm_contact instanceof BWFCRM_Contact ) {
			$contact = $this->crm_contact;
		}

		/** In case Pro is active and Contact is valid */
		if ( class_exists( 'BWFCRM_Contact' ) && $contact instanceof BWFCRM_Contact ) {
			$is_unsubscribed = ( BWFCRM_Contact::$DISPLAY_STATUS_UNSUBSCRIBED === $contact->get_display_status() );

			$this->unsubscribe_lists_html( array(), array(), $is_unsubscribed );

			return;
		}

		/** If Pro is not active OR Contact is not valid */
		$is_unsubscribed = false;
		if ( $this->contact instanceof WooFunnels_Contact ) {
			$data = array(
				'recipient' => array( $this->contact->get_email(), $this->contact->get_contact_no() ),
			);

			$unsubscribed_rows = BWFAN_Model_Message_Unsubscribe::get_message_unsubscribe_row( $data, false );
			if ( 0 < count( $unsubscribed_rows ) ) {
				$is_unsubscribed = true;
			}
		}

		$this->unsubscribe_lists_html( array(), array(), $is_unsubscribed );
	}

	/**
	 * Renders the HTML for unsubscribe lists interface
	 *
	 * Displays checkboxes for individual lists and the global unsubscribe option
	 *
	 * @param $lists
	 * @param $subscribed_lists
	 * @param $is_unsubscribed
	 *
	 * @return void
	 */
	public function unsubscribe_lists_html( $lists = array(), $subscribed_lists = array(), $is_unsubscribed = false ) {
		$settings    = $this->get_global_settings();
		$label       = isset( $settings['bwfan_unsubscribe_from_all_label'] ) && ! empty( $settings['bwfan_unsubscribe_from_all_label'] ) ? $settings['bwfan_unsubscribe_from_all_label'] : __( "Unsubscribe from all Email Lists", 'wp-marketing-automations' );
		$description = isset( $settings['bwfan_unsubscribe_from_all_description'] ) && ! empty( $settings['bwfan_unsubscribe_from_all_description'] ) ? $settings['bwfan_unsubscribe_from_all_description'] : __( 'You will still receive important billing and transactional emails', 'wp-marketing-automations' );
		?>
        <div class="bwfan-unsubscribe-lists" id="bwfan-unsubscribe-lists">
			<?php
			$heading = ! empty( $settings['bwfan_unsubscribe_lists_text'] ) ? $settings['bwfan_unsubscribe_lists_text'] : __( 'Manage List', 'wp-marketing-automations' );
			echo '<div class="bwf-h2">' . esc_html( $heading ) . '</div>';
			foreach ( $lists as $list ) {
				$is_checked = in_array( absint( $list['ID'] ), $subscribed_lists, true ) && ! $is_unsubscribed;
				?>
                <div class="bwfan-unsubscribe-single-list">
                    <div class="bwfan-unsubscribe-list-checkbox">
                        <input
                            id="bwfan-list-<?php echo esc_attr( $list['ID'] ); ?>"
                            type="checkbox"
                            value="<?php echo esc_attr( $list['ID'] ); ?>"
							<?php echo $is_checked ? 'checked="checked"' : ''; ?>
                        />
                        <label for="bwfan-list-<?php echo esc_attr( $list['ID'] ); ?>"><?php echo esc_html( $list['name'] ); ?></label>
                    </div>
					<?php if ( isset( $list['description'] ) ) : ?>
                        <p class="bwfan-unsubscribe-list-description"><?php echo wp_kses_post( $list['description'] ); ?></p>
					<?php endif; ?>
                </div>
				<?php
			}
			?>
            <!-- Global Unsubscription option -->
            <div class="bwfan-unsubscribe-single-list bwfan-unsubscribe-from-all-lists">
                <div class="bwfan-unsubscribe-list-checkbox">
                    <input id="bwfan-list-unsubscribe-all" type="checkbox" value="unsubscribe_all" <?php echo esc_attr( $is_unsubscribed ? 'checked="checked"' : '' ); ?> />
                    <label for="bwfan-list-unsubscribe-all"><?php echo wp_kses_post( $label ); ?></label>
                </div>
				<?php if ( ! empty( $description ) ) : ?>
                    <p class="bwfan-unsubscribe-list-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
            </div>
        </div>
		<?php
	}

	/**
	 * Ajax handler for selecting unsubscribe page
	 * Retrieves pages matching the search term and returns them in JSON format
	 * @return void
	 */
	public function bwfan_select_unsubscribe_page() {
		BWFAN_Common::nocache_headers();

		// Verify nonce
		$nonce = filter_input( INPUT_POST, 'bwf_page_nonce' );
		if ( ! wp_verify_nonce( $nonce, 'bwf_page_nonce' ) ) {
			wp_send_json( array(
				'results' => [],
			) );
		}

		// Check admin capability
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json( array(
				'results' => [],
			) );
		}

		global $wpdb;
		$search_data = filter_input( INPUT_POST, 'search_term', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
		$term        = isset( $search_data['term'] ) ? sanitize_text_field( $search_data['term'] ) : '';
		$v2          = filter_input( INPUT_POST, 'fromApp', FILTER_VALIDATE_BOOLEAN );

		// Use esc_like for LIKE queries to prevent SQL injection
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live post title search for the unsubscribe page; not cacheable
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->prefix}posts WHERE post_title LIKE %s AND post_type = %s AND post_status = %s",
				$like,
				'page',
				'publish'
			)
		); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( empty( $results ) || ! is_array( $results ) ) {
			wp_send_json( array(
				'results' => [],
			) );
		}

		$response = array();
		foreach ( $results as $result ) {
			if ( $v2 ) {
				$response[] = array(
					'key'   => $result->ID,
					'value' => $result->post_title,
				);
			} else {
				$response[] = array(
					'id'    => $result->ID,
					'text'  => $result->post_title,
					'value' => $result->ID,
					'label' => $result->post_title,
				);
			}
		}

		wp_send_json( array(
			'results' => $response,
		) );
	}

	/**
	 * manage unsubscribe user cases
	 *
	 * @param $post
	 *
	 * @return void
	 */
	public function bwfan_unsubscribe_user( $post = false ) {
		BWFAN_Common::nocache_headers();

		/** Security check */
		$nonce = filter_input( INPUT_POST, '_nonce' ); //phpcs:ignore WordPress.Security.NonceVerification
		if ( false === $post && ! wp_verify_nonce( $nonce, 'bwfan-unsubscribe-nonce' ) ) {
			$this->return_message( 7 );
		}

		/** Set contact data */
		$this->set_data();

		/** If data is not present then return */
		if ( empty( $this->contact ) ) {
			$this->return_message( 1 );
		}

		do_action( 'bwfan_save_custom_field_data', $this->contact );

		$one_click = filter_input( INPUT_POST, 'one_click' );
		if ( true === $post || 1 === intval( $one_click ) ) {
			$one_click_get = filter_input( INPUT_POST, 'one_click_get' );
			if ( 1 === intval( $one_click_get ) ) {
				/** remove list from contact */
				$redirect_url = apply_filters( 'fka_redirect_confirm_unsubscribed', '', $this->contact );
				if ( ! empty( $redirect_url ) ) {
					$this->unsubscribe_redirect = $redirect_url;
				}
			}
			$this->mark_unsubscribe();

			return;
		}

		if ( false === $this->is_lists_display_active() ) {
			/** Just Unsubscribe or resubscribe only */
			$this->maybe_subscribe_or_unsubscribe();

			/** Will return from the function itself */
		}
		/** Maybe complete unsubscribe - all case */
		if ( true === $this->unsubscribe_all ) {
			/** remove list from contact */
			$redirect_url = apply_filters( 'fka_redirect_confirm_unsubscribed', '', $this->contact );
			if ( ! empty( $redirect_url ) ) {
				$this->unsubscribe_redirect = $redirect_url;
			}
			$this->unsubscribe_all_lists();

			/** Will return from the function itself */
			return;
		}

		/** Not an all case */
		$this->handle_unsubscribe_lists();
	}

	/**
	 * Set contact data from the query arguments
	 *
	 * @return void
	 */
	protected function set_data() {
		if ( ! empty( $this->contact ) ) {
			return;
		}

		// Get contact from common shortcodes class
		$contact = $this->common_shortcodes->set_data();

		if ( $contact ) {
			$this->contact   = $contact;
			$this->uid       = $contact->get_uid();
			$this->recipient = $contact->get_email();

			if ( class_exists( 'BWFCRM_Contact' ) ) {
				$crm_contact = new BWFCRM_Contact( $contact );
				if ( $crm_contact->is_contact_exists() ) {
					$this->crm_contact = $crm_contact;
				}
			}
		}

		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			return;
		}

		/** Set up unsubscribe lists */
		$lists = filter_input( INPUT_POST, 'unsubscribe_lists' );
		$lists = empty( $lists ) ? [] : $lists;
		if ( ! empty( $lists ) ) {
			$lists = stripslashes_deep( $lists );
			$lists = json_decode( $lists, true );
			$lists = is_array( $lists ) ? array_map( 'sanitize_text_field', $lists ) : [];
			$this->unsubscribe_all = in_array( 'all', $lists, false );
		}

		if ( true === $this->unsubscribe_all ) {
			$lists = array_diff( $lists, [ 'all' ] );
			sort( $lists );
		}
		$this->unsubscribe_lists = ! empty( $lists ) ? array_map( 'intval', $lists ) : [];
	}

	/**
	 * Set global settings and return
	 *
	 * @return mixed|void
	 */
	protected function get_global_settings() {
		if ( is_null( $this->settings ) ) {
			$this->settings = BWFAN_Common::get_global_settings();
		}

		return $this->settings;
	}

	/**
	 * Check if lists visibility enabled
	 * @return bool
	 */
	protected function is_lists_display_active() {
		$settings = $this->get_global_settings();
		$active   = ( isset( $settings['bwfan_unsubscribe_lists_enable'] ) && true === $settings['bwfan_unsubscribe_lists_enable'] ) ? true : false;

		return $active;
	}

	/**
	 * Subscribe or Unsubscribe contact
	 *
	 * @return void
	 */
	protected function maybe_subscribe_or_unsubscribe() {
		if ( true === $this->unsubscribe_all ) {
			$redirect_url = apply_filters( 'fka_redirect_confirm_unsubscribed', '', $this->contact );
			if ( ! empty( $redirect_url ) ) {
				$this->unsubscribe_redirect = $redirect_url;
			}
			$this->mark_unsubscribe();

			return;
		}
		$this->mark_subscribe();
	}

	/**
	 * Unsubscribe contact
	 * If assigned public lists then un-assign
	 *
	 * @return void
	 */
	public function unsubscribe_all_lists() {
		$contact = $this->crm_contact;

		$lists            = $this->get_contact_lists();
		$subscribed_lists = $lists['subscribed'];

		$visible_lists = $this->get_visible_lists();

		$lists_to_unsub = array_values( array_intersect( $visible_lists, $subscribed_lists ) );
		sort( $lists_to_unsub );

		if ( ! empty( $lists_to_unsub ) ) {
			$contact->remove_lists( $lists_to_unsub );
		}

		$contact->contact->set_last_modified( current_time( 'mysql', 1 ) );
		if ( method_exists( $contact, 'save' ) ) {
			$contact->save();
		} else {
			$contact->contact->save();
		}

		/** Unsubscribe from lists which are unchecked, but are assigned to contact */
		$this->update_unassigned_lists_field( $lists_to_unsub );

		/** Mark unsubscribe */
		$this->mark_unsubscribe();
	}

	/**
	 * Subscribe contact if not subscribed
	 * Assign or Un-assign lists
	 *
	 * @return void
	 */
	public function handle_unsubscribe_lists() {
		$unsubscribe_lists = $this->unsubscribe_lists;
		$visible_lists     = $this->get_visible_lists();

		/**
		 * Assign lists which are not checked and already not assigned
		 */
		$lists_to_sub = array_diff( $visible_lists, $unsubscribe_lists );
		sort( $lists_to_sub );
		if ( is_array( $lists_to_sub ) && count( $lists_to_sub ) > 0 ) {
			$assigned_list = [];
			foreach ( $lists_to_sub as $list ) {
				$assigned_list[] = array( 'id' => $list, 'value' => '' );
			}
			$this->crm_contact->add_lists( $assigned_list );
			$this->crm_contact->save();
		}

		/** Maybe subscribe the contact */
		if ( BWFCRM_Contact::$DISPLAY_STATUS_UNSUBSCRIBED === $this->crm_contact->get_display_status() ) {
			$this->crm_contact->resubscribe();
		}

		$contact_lists   = $this->get_contact_lists();
		$subscribe_lists = $contact_lists['subscribed'];


		$lists_to_unsub = array_values( array_intersect( $unsubscribe_lists, $subscribe_lists ) );
		sort( $lists_to_unsub );

		if ( ! empty( $lists_to_unsub ) ) {
			$this->crm_contact->remove_lists( $lists_to_unsub );
		}

		$this->crm_contact->contact->set_last_modified( current_time( 'mysql', 1 ) );
		$this->crm_contact->save();

		/** Unsubscribe from lists which are unchecked, but are assigned to contact */
		$this->update_unassigned_lists_field( $lists_to_unsub );

		$this->return_message( 3 );
	}

	/**
	 * Mark contact unsubscribe
	 *
	 * @param $return
	 *
	 * @return void
	 */
	protected function mark_unsubscribe( $return = true ) {
		global $wpdb;

		$automation_id = filter_input( INPUT_POST, 'automation_id', FILTER_SANITIZE_NUMBER_INT );
		$broadcast_id  = filter_input( INPUT_POST, 'broadcast_id', FILTER_SANITIZE_NUMBER_INT );
		$form_feed_id  = filter_input( INPUT_POST, 'form_feed_id', FILTER_SANITIZE_NUMBER_INT );
		$sid           = filter_input( INPUT_POST, 'sid', FILTER_SANITIZE_NUMBER_INT );
		$tid           = filter_input( INPUT_POST, 'tid', FILTER_SANITIZE_NUMBER_INT );

		$automation_id = empty( $automation_id ) ? filter_input( INPUT_GET, 'automation_id', FILTER_SANITIZE_NUMBER_INT ) : $automation_id;
		$broadcast_id  = empty( $broadcast_id ) ? filter_input( INPUT_GET, 'broadcast_id', FILTER_SANITIZE_NUMBER_INT ) : $broadcast_id;
		$form_feed_id  = empty( $form_feed_id ) ? filter_input( INPUT_GET, 'form_feed_id', FILTER_SANITIZE_NUMBER_INT ) : $form_feed_id;
		$tid           = empty( $tid ) ? filter_input( INPUT_GET, 'tid', FILTER_SANITIZE_NUMBER_INT ) : $tid;
		$sid           = empty( $sid ) ? filter_input( INPUT_GET, 'sid', FILTER_SANITIZE_NUMBER_INT ) : $sid;
		$sid           = empty( $sid ) ? 0 : $sid;
		$tid           = empty( $tid ) ? 0 : $tid;
		$mode          = 0;
		if ( false !== filter_var( $this->recipient, FILTER_VALIDATE_EMAIL ) ) {
			$mode = 1;
		} elseif ( is_numeric( $this->recipient ) ) {
			$mode = 2;
		} else {
			$this->return_message( 1 );
		}

		/**
		 * Checking if recipient already added to unsubscribe table
		 */
		$query = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->prefix}bwfan_message_unsubscribe WHERE `recipient` = %s AND `mode` = %d ORDER BY ID DESC LIMIT 1",
			sanitize_text_field( $this->recipient ),
			$mode
		);
		$unsubscribers = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
		if ( $unsubscribers > 0 ) {
			$this->return_message( 6 );
		}

		/** Manual (Single Sending) */
		$c_type = 3;
		if ( ! empty( $automation_id ) ) {
			$c_type = 1;
		} elseif ( ! empty( $broadcast_id ) ) {
			$c_type = 2;
		} elseif ( ! empty( $form_feed_id ) ) {
			$c_type = 4;
		}

		$oid = 0;
		if ( ! empty( $automation_id ) ) {
			$oid = absint( $automation_id );
		} elseif ( ! empty( $broadcast_id ) ) {
			$oid = absint( $broadcast_id );
		} elseif ( ! empty( $form_feed_id ) ) {
			$oid = absint( $form_feed_id );
		} elseif ( ! empty( $tid ) ) {
			$oid = absint( $tid );
			$c_type = BWFCRM_Contact::UNSUBSCRIBE_SOURCE_TRANSACTIONAL_MAIL;
		}

		$insert_data = array(
			'recipient'     => $this->recipient,
			'c_date'        => current_time( 'mysql' ),
			'mode'          => $mode,
			'automation_id' => $oid,
			'c_type'        => $c_type,
			'sid'           => $sid,
		);

		BWFAN_Model_Message_Unsubscribe::insert( $insert_data );

		/** hook when any contact unsubscribed  */
		do_action( 'bwfcrm_after_contact_unsubscribed', array( $insert_data ) );

		if ( true === $return ) {
			$this->return_message( 2 );
		}
	}

	/**
	 * Mark contact subscribe
	 *
	 * @return void
	 */
	protected function mark_subscribe() {
		$this->crm_contact->resubscribe();

		$mode = 1;
		if ( is_numeric( $this->recipient ) ) {
			$mode = 2;
		} else {
			$this->return_message();
		}
		global $wpdb;
		$query = $wpdb->prepare(
			"SELECT ID, recipient FROM {$wpdb->prefix}bwfan_message_unsubscribe WHERE `recipient` = %s AND `mode` = %d ORDER BY ID DESC",
			sanitize_text_field( $this->recipient ),
			$mode
		);
		$unsubscribers = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
		if ( ! empty( $unsubscribers ) ) {
			foreach ( $unsubscribers as $unsubscriber ) {
				$id = $unsubscriber['ID'];
				BWFAN_Model_Message_Unsubscribe::delete( $id );
			}

			$this->return_message( 5 );
		}

		$this->return_message( 6 );
	}

	/**
	 * Get contact subscribed and unsubscribed lists
	 *
	 * @return array
	 */
	protected function get_contact_lists() {
		/** Get Unsubscribed Lists */
		$unsubscribed_lists = $this->crm_contact->get_field_by_slug( 'unsubscribed-lists' );
		$unsubscribed_lists = ( 'null' === $unsubscribed_lists || empty( $unsubscribed_lists ) ) ? array() : json_decode( $unsubscribed_lists, true );
		$unsubscribed_lists = array_map( 'intval', $unsubscribed_lists );

		/** Get Contact Lists (Include Unsubscribed Lists) */
		$subscribed_lists = $this->crm_contact->get_lists();
		$subscribed_lists = array_map( 'intval', $subscribed_lists );
		$contact_lists    = array_values( array_merge( $subscribed_lists, $unsubscribed_lists ) );

		return array(
			'subscribed'   => $subscribed_lists,
			'unsubscribed' => $unsubscribed_lists,
			'all'          => $contact_lists,
		);
	}

	/**
	 * Get public lists for display
	 *
	 * @return array|mixed
	 */
	protected function get_visible_lists() {
		$settings     = $this->get_global_settings();
		$public_lists = ! empty( $settings['bwfan_unsubscribe_public_lists'] ) ? $settings['bwfan_unsubscribe_public_lists'] : [];
		$visibility   = isset( $settings['bwfan_unsubscribe_lists_visibility'] ) ? $settings['bwfan_unsubscribe_lists_visibility'] : 0;

		if ( 1 !== absint( $visibility ) ) {
			return $public_lists;
		}

		/** Get Contact List */
		$contact_lists    = $this->get_contact_lists();
		$subscribed_lists = $contact_lists['subscribed'];

		/** Public Lists for Contact will consists of only which contact has been added to */
		$public_lists = array_values( array_intersect( $subscribed_lists, $public_lists ) );

		return $public_lists;
	}

	/**
	 * Get subscriber details using uid
	 *
	 * @return array|false
	 */
	protected function get_subscriber_details() {
		$contact_details = $this->common_shortcodes->get_contact_details();

		if ( empty( $contact_details ) ) {
			return false;
		}

		return array(
			'subscriber_email'     => $contact_details['email'],
			'subscriber_phone'     => $contact_details['phone'],
			'subscriber_name'      => $contact_details['name'],
			'subscriber_firstname' => $contact_details['firstname'],
			'subscriber_lastname'  => $contact_details['lastname']
		);
	}

	/**
	 * Update contact unassigned list field
	 *
	 * @param $lists_to_unsub
	 *
	 * @return void
	 */
	protected function update_unassigned_lists_field( $lists_to_unsub ) {
		if ( empty( $lists_to_unsub ) ) {
			return;
		}
		$lists_to_unsub = array_map( 'intval', $lists_to_unsub );

		$current_unsub_lists = $this->crm_contact->get_field_by_slug( 'unsubscribed-lists' );
		$current_unsub_lists = ( 'null' === $current_unsub_lists || empty( $current_unsub_lists ) ) ? [] : json_decode( $current_unsub_lists, true );

		$current_unsub_lists = array_merge( $current_unsub_lists, $lists_to_unsub );
		$current_unsub_lists = array_unique( $current_unsub_lists );
		sort( $current_unsub_lists );

		$current_unsub_lists = array_map( 'intval', $current_unsub_lists );

		$this->crm_contact->set_field_by_slug( 'unsubscribed-lists', wp_json_encode( $current_unsub_lists ) );
		$this->crm_contact->save_fields();
	}

	/**
	 * Return messages compiled
	 *
	 * @param $type
	 *
	 * @return void
	 */
	protected function return_message( $type = 1 ) {
		$page_exist = class_exists( 'BWFAN_Common' ) ? BWFAN_Common::is_unsubscribe_page_valid() : array();

		$default_messages = [
			1 => __( 'Sorry! We are unable to update preferences as no contact found.', 'wp-marketing-automations' ),
			2 => __( 'Your subscription preference has been updated.', 'wp-marketing-automations' ),
			7 => __( 'Security check failed', 'wp-marketing-automations' ),
		];

		$error_messages = apply_filters( 'bwfcrm_unsubscribe_error_messages', $default_messages );

		if ( 1 === absint( $type ) ) {
			wp_send_json( array(
				'success' => 0,
				'message' => ! empty( $error_messages[1] ) ? $error_messages[1] : $default_messages[1],
			) );
		}

		if ( in_array( intval( $type ), array( 2, 3, 4, 5, 6 ), true ) && ( isset( $page_exist ) && 3 !== $page_exist['status'] ) ) {
			$global_settings = $this->get_global_settings();
			$return_array    = array(
				'success' => 1,
				'message' => ! empty( $global_settings['bwfan_unsubscribe_data_success'] ) ? wp_kses_post( $global_settings['bwfan_unsubscribe_data_success'] ) : '',
			);
			if ( ! empty( $this->unsubscribe_redirect ) ) {
				$return_array['redirect']     = true;
				$return_array['redirect_url'] = esc_url_raw( $this->unsubscribe_redirect );

				$this->unsubscribe_redirect = null;
			}

			wp_send_json( $return_array );
		}

		if ( 7 === absint( $type ) ) {
			wp_send_json( array(
				'success' => 0,
				'message' => ! empty( $error_messages[7] ) ? $error_messages[7] : $default_messages[7],
			) );
		}
	}

	/**
	 * Delete elementor cache
	 *
	 * @return void
	 */
	public function delete_elementor_cache() {
		if ( ! class_exists( 'Elementor\Core\Base\Document' ) || ! method_exists( 'Elementor\Core\Base\Document', 'is_built_with_elementor' ) ) {
			return;
		}
		$setting = BWFAN_Common::get_global_settings();

		$unsubscribe_page_id = isset( $setting['bwfan_unsubscribe_page'] ) ? $setting['bwfan_unsubscribe_page'] : 0;
		if ( empty( $unsubscribe_page_id ) || ( intval( $unsubscribe_page_id ) !== intval( get_the_ID() ) ) ) {
			return;
		}

		/**
		 * Check if it's a page built using elementor, delete the cache for the page
		 */
		$document = Elementor\Plugin::$instance->documents->get( $unsubscribe_page_id );
		if ( empty( $document ) || empty( $document->is_built_with_elementor() ) ) {
			return;
		}

		delete_post_meta( get_the_ID(), Elementor\Core\Base\Document::CACHE_META_KEY );
	}
}

BWFAN_unsubscribe::get_instance();
