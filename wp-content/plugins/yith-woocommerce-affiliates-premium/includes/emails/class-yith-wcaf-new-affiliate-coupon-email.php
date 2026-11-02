<?php
/**
 * New Affiliate Coupon Email
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_New_Affiliate_Coupon_Email' ) ) {
	/**
	 * New affiliate email
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_New_Affiliate_Coupon_Email extends YITH_WCAF_Abstract_Affiliate_Email {

		/**
		 * Affiliate object
		 *
		 * @var YITH_WCAF_Affiliate
		 */
		protected $affiliate;

		/**
		 * Constructor method, used to return object of the class to WC
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// set trigger.
			$this->trigger = 'yith_wcaf_affiliate_coupon_saved';

			// set email data.
			$this->id             = 'affiliate_new_coupon';
			$this->title          = 'YITH WooCommerce Affiliates - ' . _x( 'New affiliate\'s coupon', '[EMAILS] New affiliate coupon', 'yith-woocommerce-affiliates' );
			$this->description    = _x( 'This email is sent to the affiliate whenever the admin creates a coupon for him/her; you can enable/disable this from YITH > Affiliates > General Options.', '[EMAILS] New affiliate coupon', 'yith-woocommerce-affiliates' );
			$this->customer_email = true;

			// set heading and subject.
			$this->heading = _x( 'You have a new coupon', '[EMAILS] New affiliate coupon', 'yith-woocommerce-affiliates' );
			$this->subject = _x( 'We created a new coupon for you to share!', '[EMAILS] New affiliate coupon', 'yith-woocommerce-affiliates' );

			// set available placeholders.
			$this->available_placeholders      = array( 'site_title', 'display_name', 'coupon_code', 'affiliate_dashboard_link' );
			$this->available_text_placeholders = array( 'site_title', 'display_name', 'coupon_code', 'affiliate_dashboard_plain_link' );

			// set contents.
			$this->content_html = $this->get_option(
				'content_html',
				_x(
					'<p>An admin just created a new coupon for you!</p>
<p><b>Coupon code:</b> {coupon_code}</p>
<p>Share it with your users: you will earn commissions each time a customer makes a purchase using this coupon code.</p>
<p>Please, have a look at your {affiliate_dashboard_link} for further information, and don\'t hesitate to contact us if you have any doubts.</p>',
					'[EMAILS] New affiliate coupon',
					'yith-woocommerce-affiliates'
				)
			);
			$this->content_text = $this->get_option(
				'content_text',
				_x(
					'An admin created a new coupon for you!
Coupon code: {coupon_code}
Share it with your users: you will earn commissions each time a customer makes a purchase using this coupon code.
Please, have a look at your {affiliate_dashboard_link} for further information, and don\'t hesitate to contact us if you have any doubts.',
					'[EMAILS] New affiliate coupon',
					'yith-woocommerce-affiliates'
				)
			);

			// set templates.
			$this->template_html  = 'emails/new-affiliate-coupon.php';
			$this->template_plain = 'emails/plain/new-affiliate-coupon.php';

			// Call parent constructor.
			parent::__construct();
		}

		/**
		 * Method triggered to send email
		 *
		 * @param WC_Coupon $coupon Coupon object.
		 */
		public function trigger( $coupon ) {
			$this->object = $coupon;

			if ( ! $this->object ) {
				return;
			}

			$this->affiliate = YITH_WCAF_Coupons()->get_coupon_affiliate( $coupon );

			if ( ! $this->is_enabled() || ! $this->get_recipient() || ! $this->affiliate ) {
				return;
			}

			// set replaces.
			$this->set_replaces();

			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		/**
		 * Check if mail is enabled
		 *
		 * @return bool Whether email notification is enabled or not
		 * @since 1.0.0
		 */
		public function is_enabled() {
			$coupon_handling_enabled = get_option( 'yith_wcaf_coupon_enable' );
			$notify_affiliates       = get_option( 'yith_wcaf_coupon_notify_affiliate' );

			return yith_plugin_fw_is_true( $coupon_handling_enabled ) && yith_plugin_fw_is_true( $notify_affiliates );
		}

		/**
		 * Retrieve recipient address
		 *
		 * @return string Email address
		 * @since 1.0.0
		 */
		public function get_recipient() {
			return $this->get_recipient_from_affiliate( $this->affiliate );
		}

		/**
		 * Set custom replace value for this email
		 *
		 * @return void
		 */
		public function set_replaces() {
			$show_coupon_section            = get_option( 'yith_wcaf_coupon_show_section', 'yes' );
			$affiliate_dashboard_url        = YITH_WCAF_Dashboard()->get_dashboard_url( 'yes' === $show_coupon_section ? 'coupons' : '' );
			$affiliate_dashboard_link       = sprintf( '<a href="%s" target="_blank">%s</a>', $affiliate_dashboard_url, _x( 'Affiliate Dashboard', '[EMAILS] New affiliate coupon', 'yith-woocommerce-affiliates' ) );
			$affiliate_dashboard_plain_link = sprintf( '%s [%s]', _x( 'Affiliate Dashboard', '[EMAILS] New affiliate coupon', 'yith-woocommerce-affiliates' ), $affiliate_dashboard_url );

			$placeholders = array(
				'{display_name}'                   => $this->affiliate->get_formatted_name(),
				'{coupon_code}'                    => $this->object->get_code(),
				'{affiliate_dashboard_link}'       => $affiliate_dashboard_link,
				'{affiliate_dashboard_plain_link}' => $affiliate_dashboard_plain_link,
			);

			// add coupon data.
			foreach ( $this->object->get_data() as $key => $value ) {
				// fix timestamps.
				if ( $value instanceof WC_DateTime ) {
					$value = $value->date_i18n();
				} elseif ( 'discount_type' === $key ) {
					$coupon_types = wc_get_coupon_types();
					$value        = isset( $coupon_types[ $value ] ) ? $coupon_types[ $value ] : $value;
				} elseif ( 'email_restrictions' === $key ) {
					$value = implode( ', ', $value );
				} elseif ( in_array( $key, array( 'individual_use', 'free_shipping', 'exclude_sale_items' ), true ) ) {
					$value = yith_plugin_fw_is_true( $value ) ? _x( 'Yes', '[EMAILS] New affiliate coupon', 'yith-woocommerce-affiliates' ) : _x( 'No', '[EMAILS] New affiliate coupon', 'yith-woocommerce-affiliates' );
				} elseif ( in_array( $key, array( 'product_ids', 'excluded_product_ids' ), true ) ) {
					$value = implode(
						', ',
						array_map(
							function ( $product_id ) {
								return get_the_title( $product_id );
							},
							$value
						)
					);
				} elseif ( in_array( $key, array( 'product_categories', 'excluded_product_categories' ), true ) ) {
					$value = implode(
						', ',
						array_map(
							function ( $term_id ) {
								$term = get_term( $term_id );

								return $term->name;
							},
							$value
						)
					);
				}

				// skip if value is not a string.
				if ( ! is_string( $value ) && ! apply_filters( 'yith_wcaf_new_coupon_email_coupon_meta_value', false, $key, $value ) ) {
					continue;
				}

				// remove initial underscore, if any.
				if ( strpos( $key, '_' ) === 0 ) {
					$key = substr( $key, 1 );
				}

				// generate replace key.
				$key = "{coupon_{$key}}";

				// add key/value pair to placeholders array.
				$placeholders[ $key ] = $value;
			}

			$this->placeholders = array_merge(
				$this->placeholders,
				$placeholders
			);

			// add formatted content text, using placeholders that we just add.
			parent::set_replaces();
		}

		/**
		 * Get HTML content for the mail
		 *
		 * @return string HTML content of the mail
		 * @since 1.0.0
		 */
		public function get_content_html() {
			ob_start();
			yith_wcaf_get_template(
				$this->template_html,
				array(
					'coupon'        => $this->object,
					'affiliate'     => $this->affiliate,
					'user'          => $this->affiliate->get_user(),
					'display_name'  => $this->affiliate->get_formatted_name(),
					'email_heading' => $this->get_heading(),
					'email'         => $this,
					'sent_to_admin' => false,
					'plain_text'    => false,
				)
			);

			return $this->format_string( ob_get_clean() );
		}

		/**
		 * Get plain text content of the mail
		 *
		 * @return string Plain text content of the mail
		 * @since 1.0.0
		 */
		public function get_content_plain() {
			ob_start();
			yith_wcaf_get_template(
				$this->template_plain,
				array(
					'coupon'        => $this->object,
					'affiliate'     => $this->affiliate,
					'user'          => $this->affiliate->get_user(),
					'display_name'  => $this->affiliate->get_formatted_name(),
					'email_heading' => $this->get_heading(),
					'email'         => $this,
					'sent_to_admin' => false,
					'plain_text'    => true,
				)
			);

			return $this->format_string( ob_get_clean() );
		}

		/**
		 * Init form fields to display in WC admin pages
		 */
		public function init_form_fields() {
			parent::init_form_fields();

			unset( $this->form_fields['tos_label'] );
			unset( $this->form_fields['tos_url'] );
		}
	}
}
