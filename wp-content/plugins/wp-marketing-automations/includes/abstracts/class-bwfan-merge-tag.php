<?php

#[AllowDynamicProperties]
abstract class BWFAN_Merge_Tag {

	public $date_formats = array(
		array(
			'format' => 'j M Y',
		),
		array(
			'format' => 'jS M Y',
		),
		array(
			'format' => 'M j Y',
		),
		array(
			'format' => 'M jS Y',
		),
		array(
			'format' => 'd/m/Y',
		),
		array(
			'format' => 'd-m-Y',
		),
		array(
			'format' => 'Y/m/d',
		),
		array(
			'format' => 'Y-m-d',
		),
		array(
			'format' => 'd/m/Y H:i:s',
		),
		array(
			'format' => 'd-m-Y H:i:s',
		),
		array(
			'format' => 'Y/m/d H:i:s',
		),
		array(
			'format' => 'Y-m-d H:i:s',
		),
		array(
			'format' => 'd.m.Y',
		),
	);
	protected $support_fallback = false;
	protected $support_date = false;
	protected $support_modify = true;
	protected $fallback = '';
	protected $tag_name = '';
	protected $tag_description = '';
	protected $support_v2 = true;
	protected $support_v1 = true;
	protected $priority = 10;
	protected $is_delay_variable = false;
	protected $is_crm_broadcast = false;

	public function get_coupon_fields() {
		$time_types = array(
			'days' => __( 'Days', 'wp-marketing-automations' ),
		);
		?>
        <div class="bwfan_mtag_wrap">
            <div class="bwfan_label">
                <label for="" class="bwfan-label-title"><?php esc_html_e( 'Select coupon', 'wp-marketing-automations' ); ?></label>
            </div>
            <div class="bwfan_label_val">
                <select required id="" data-search="coupon" data-search-text="<?php esc_attr_e( 'Select Coupon', 'wp-marketing-automations' ); ?>" class="bwfan-select2ajax-single bwfan-input-wrapper bwfan_tag_select" name="parent_coupon">
                    <option value=""><?php esc_html_e( 'Choose Coupon', 'wp-marketing-automations' ); ?></option>
                </select>
            </div>
        </div>
        <div class="bwfan_mtag_wrap">
            <div class="bwfan_label">
                <label for="" class="bwfan-label-title"><?php esc_html_e( 'Coupon name', 'wp-marketing-automations' ); ?></label>
            </div>
            <div class="bwfan_label_val">
                <input required type="text" class="bwfan-input-wrapper bwfan_tag_input" name="coupon_name"/>
            </div>
        </div>
        <div class="bwfan_mtag_wrap">
            <div class="bwfan_label">
                <label for="" class="bwfan-label-title"><?php esc_html_e( 'Select Expiry Type', 'wp-marketing-automations' ); ?></label>
            </div>
            <div class="bwfan_label_val">
                <select required id="" class="bwfan-input-wrapper bwfan_tag_select" name="expiry_type">
					<?php
					foreach ( $time_types as $value1 => $text ) {
						?>
                        <option value="<?php echo esc_attr( $value1 ); ?>"><?php echo esc_attr( $text ); ?></option>
						<?php
					}
					?>
                </select>
            </div>
        </div>
        <div class="bwfan_mtag_wrap">
            <div class="bwfan_label">
                <label for="" class="bwfan-label-title"><?php esc_html_e( 'Expiry', 'wp-marketing-automations' ); ?></label>
            </div>
            <div class="bwfan_label_val">
                <input min="0" type="number" class="bwfan-input-wrapper bwfan_tag_input" name="expiry"/>
            </div>
        </div>
        <div class="bwfan_mtag_wrap">
            <label for="bwfan-restrict" class="bwfan-label-title"><input type="checkbox" name="restrict" id="bwfan-restrict" class="bwfan_tag_select" value="yes"/><?php esc_html_e( 'Restrict user email with coupon', 'wp-marketing-automations' ); ?>
            </label>
        </div>
		<?php
	}

	/**
	 * Show the html in popup for the merge tag.
	 */
	public function get_view() {
		$this->get_back_button();
		if ( $this->support_date ) {
			$this->date_format();
		}
		if ( $this->support_fallback ) {
			$this->get_fallback();
		}

		$this->get_preview();
		$this->get_copy_button();
	}

	public function get_back_button() {
		?>
        <div class="bwfan_inner_merge_tag_desc"></div>
		<?php
	}

	public function date_format() {
		$formats = $this->date_formats;

		echo '<div class="bwfan_mtag_wrap">';
		echo '<div class="bwfan_label">';
		echo '<label class="bwfan-label-fallback-title">' . __( 'Select Date Format', 'wp-marketing-automations' ) . '</label>'; //phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
		echo '<div class="bwfan_label_val">';
		echo '<select class="bwfan_date_format bwfan-input-wrapper bwfan_tag_select" name="format" style="width:100%;">';
		foreach ( $formats as $parameters ) {
			$date_time = $this->format_datetime( gmdate( 'Y-m-d H:i:s' ), $parameters );
			echo '<option value="' . $parameters['format'] . '">' . $date_time . '</option>'; //phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</select>';
		echo '</div></div>';

		if ( true === $this->support_modify ) {
			echo '<div class="bwfan_mtag_wrap">
                <div class="bwfan_label">
                    <label for="" class="bwfan-label-fallback-title">' . esc_html__( 'Modify (Optional)', 'wp-marketing-automations' ) . '</label>
                </div>
                <div class="bwfan_label_val">
                    <input type="text" class="bwfan-input-wrapper bwfan_tag_input" name="modify" placeholder="e.g. +2 months, -1 day, +6 hours"/>
                </div>
             </div>';
		}
	}

	public static function maybe_parse_nested_merge_tags( $string ) {
		$position_end = strpos( $string, ']' );
		if ( false === $position_end ) {
			return $string;
		}

		$split          = str_split( $string, $position_end );
		$position_start = strrpos( $split[0], '[', - 1 );

		if ( false === $position_start ) {
			return $string;
		}

		$shortcode_array = explode( '[', $split[0] );
		$shortcode       = end( $shortcode_array );
		$result          = do_shortcode( '[' . $shortcode . ']' );

		/** Handling in case shortcode is not available and the output again contains the shortcode that would results in infinite loop */
		$result = str_replace( '[', '&#91;', $result );
		$result = str_replace( ']', '&#93;', $result );

		$string = str_replace( '[' . $shortcode . ']', $result, $string );

		return unescape_invalid_shortcodes( self::maybe_parse_nested_merge_tags( $string ) );
	}

	public function format_datetime( $input, $parameters, $is_gmt = false ) {
		if ( ! $input ) {
			return false;
		}
		/** Date saved value format */
		$input_format = ( isset( $parameters['input_format'] ) && ! empty( $parameters['input_format'] ) ) ? $parameters['input_format'] : 'Y-m-d H:i:s';

		/** Format in which fetch date value (output format) */
		$output_format = ( isset( $parameters['format'] ) && ! empty( $parameters['format'] ) ) ? $parameters['format'] : 'Y-m-d H:i:s';

		/** For delay variable */
		if ( isset( $parameters['from'] ) && 'delay' === $parameters['from'] ) {
			$input_format  = ( isset( $parameters['format'] ) && ! empty( $parameters['format'] ) ) ? $parameters['format'] : 'Y-m-d H:i:s';
			$output_format = 'Y-m-d H:i:s';
		}
		if ( is_a( $input, 'DateTime' ) ) {
			/**
			 * Cloned so the timezone switch below cannot leak into the caller's object.
			 *
			 * Pro's subscription start date tag passes `$subscription->get_date_created()`, a live
			 * `WC_DateTime` that WooCommerce hands out by reference. `WC_DateTime::setTimezone()` also
			 * zeroes the UTC offset it stores for sites on a manual `gmt_offset`, and that offset feeds
			 * its own `date()` / `date_i18n()` helpers, so mutating it here could shift the caller's
			 * later reads. The `order_date` tag guards itself by cloning at its own call site; doing it
			 * here covers the ones that do not.
			 */
			$date = clone $input;
		} else {
			if ( is_numeric( $input ) ) {
				$date = new DateTime();
				$date->setTimestamp( $input );
			} else {
				/**
				 * The parse is pinned to UTC because this function treats its input as GMT.
				 *
				 * Left implicit, the instant depended on `date_default_timezone_get()`. WordPress
				 * forces that to UTC, so nothing moves today — but it is ambient state any plugin
				 * can change with `date_default_timezone_set()`, and it was the last input to the
				 * result that this function did not control. A string carrying its own offset keeps
				 * it; the argument applies only when the input has none.
				 */
				$utc = new DateTimeZone( 'UTC' );
				try {
					$parsed = DateTime::createFromFormat( $input_format, $input, $utc );
					$date   = false !== $parsed ? $parsed : new DateTime( $input, $utc );
				} catch ( Exception $e ) {
					return '';
				}
			}
		}

		/**
		 * Render in the store timezone, or UTC when GMT was requested.
		 *
		 * `wp_timezone()` is the single source of truth for both the conversion and the
		 * formatting below. The previous implementation shifted the instant by
		 * `convert_to_site_time()` -> `BWFAN_Common::get_timezone_offset()`, then handed the result
		 * to `date_i18n()`, which reads a supplied timestamp back as a UTC wall clock and reinterprets
		 * that wall clock as site-local. The round trip is faithful, so it echoed back whatever offset
		 * `get_timezone_offset()` had applied: correct while the two resolutions of the site timezone
		 * agreed, and out by the full divergence when they did not. Converting on the DateTime itself
		 * leaves no second source to disagree with.
		 *
		 * This also keeps the `T` / `O` format characters honest: GMT output previously carried
		 * the store timezone label while holding a UTC value.
		 *
		 * `$is_gmt` is cast deliberately. The guard this replaced was `false === $is_gmt`, which sent
		 * every falsy non-boolean — `0`, `null`, `''` — down the GMT branch, a full UTC offset away
		 * from where a caller passing `null` would expect to land. Reading it as a plain boolean is
		 * the intended meaning. Every caller in Lite, Pro and Sublium Subscriptions passes a strict
		 * boolean, so nothing in tree moves; the cast is here so the choice is visible to third-party
		 * subclasses of this abstract, and so the old strict check does not get "restored" later.
		 */
		$timezone = (bool) $is_gmt ? new DateTimeZone( 'UTC' ) : wp_timezone();
		$date->setTimezone( $timezone );

		/**
		 * Applied after the timezone switch, so it operates on the site-local wall clock.
		 *
		 * The old ordering did too: it shifted the instant first, leaving a UTC-zoned DateTime that
		 * already carried the site wall clock. So the result is unchanged by the switch. PHP's
		 * relative units are wall-clock based — `+24 hours` no less than `+1 day` — and across the
		 * end of DST both land on the same local time the following day, 25 real hours later.
		 */
		if ( isset( $parameters['modify'] ) && ! empty( $parameters['modify'] ) ) {
			try {
				$date->modify( $parameters['modify'] );
			} catch ( Exception $e ) {
				/**
				 * `modify` is typed by hand into a merge tag field, so an unparseable value is a
				 * routine typo, not an exceptional condition. PHP 8.3 turned that from a `false`
				 * return into a thrown `DateMalformedStringException`, which was uncaught here and
				 * so fatal partway through rendering an email.
				 *
				 * A failed `modify()` leaves the DateTime untouched on both PHP versions, so the
				 * unmodified date still renders. Dropping the tag over a bad modifier would be the
				 * harsher failure, and PHP is lenient enough that only genuine garbage lands here —
				 * `+1 dayz` still parses as `+1 day`.
				 */
				unset( $e );
			}
		}

		/**
		 * Format localized month/day names in the language of the record being rendered (e.g. WPML) instead
		 * of the ambient request locale, which is nondeterministic in cron/async workers.
		 *
		 * Resolved here once for every date merge tag rather than in each merge tag class, so tags do not
		 * have to opt in one by one. $parameters['locale'] stays available as an explicit per-tag override.
		 * Always restored afterward so batch workers are not left with a mutated locale bleeding into
		 * subsequent records.
		 */
		if ( ! BWFAN_Common::should_skip_language_support() ) {
			$locale = ( isset( $parameters['locale'] ) && ! empty( $parameters['locale'] ) ) ? $parameters['locale'] : BWFAN_Common::get_locale_from_language_code( self::get_context_language() );

			if ( ! empty( $locale ) ) {
				/**
				 * switch_to_locale() decides on its own whether a switch is needed, and that is deliberate:
				 * WP_Locale_Switcher::switch_to_locale() compares against determine_locale() and refuses
				 * locales whose language pack is not installed. get_locale() must not be used for that check:
				 * WPML filters 'locale' (SitePress::locale_filter), so it reports WPML's current language
				 * rather than the locale whose translations are actually loaded. determine_locale() is
				 * filtered by the locale switcher itself, so it does reflect an active switch. A redundant or
				 * impossible switch returns false, costs nothing, and is not restored.
				 */
				$switched = switch_to_locale( $locale );

				/**
				 * A refused switch is silent and falls back to the ambient locale, which is the behaviour
				 * this fix exists to correct. The usual cause is a missing language pack, so record it when
				 * logging is enabled rather than leaving support to guess from a screenshot.
				 */
				if ( false === $switched && $locale !== determine_locale() ) {
					BWFAN_Common::log_test_data( 'Date merge tag: could not switch to ' . $locale . ', formatting with ' . determine_locale(), 'fka-merge-tag-locale' );
				}

				/**
				 * Same terminal call as the unswitched path below. wp_date() reads $wp_locale for month/day
				 * names, so the switch above is all it needs to localize. date_i18n() must not be used here:
				 * it reads a supplied timestamp back as a UTC wall clock and reinterprets it as site-local,
				 * so a true epoch renders the UTC time, off by the site offset, and it ignores $timezone
				 * so GMT output would carry the store timezone label. That is the bug #4835 removed.
				 */
				$formatted = wp_date( $output_format, $date->getTimestamp(), $timezone );
				if ( true === $switched ) {
					restore_previous_locale();
				}

				return $formatted;
			}
		}

		return wp_date( $output_format, $date->getTimestamp(), $timezone );
	}

	/**
	 * Language of the record currently being rendered, read from the merge tag data bag.
	 *
	 * The automation engines set 'language' explicitly before rendering (BWFAN_Tasks for V1,
	 * BWFAN_Action_Controller for V2). Transactional mails only set the order, so fall back to resolving
	 * the language from that order.
	 *
	 * @return string
	 */
	protected static function get_context_language() {
		$lang = BWFAN_Merge_Tag_Loader::get_data( 'language' );
		if ( ! empty( $lang ) && is_string( $lang ) ) {
			return $lang;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return '';
		}

		$order = BWFAN_Merge_Tag_Loader::get_data( 'wc_order' );
		if ( ! $order instanceof WC_Order ) {
			$order_id = BWFAN_Merge_Tag_Loader::get_data( 'order_id' );
			$order    = empty( $order_id ) ? false : wc_get_order( $order_id );
		}

		return $order instanceof WC_Order ? BWFAN_Common::get_order_language( $order ) : '';
	}

	public function get_fallback() {
		?>
        <div class="bwfan_mtag_wrap">
            <div class="bwfan_label">
                <label for="" class="bwfan-label-fallback-title"><?php esc_html_e( 'Fallback', 'wp-marketing-automations' ); ?></label>
            </div>
            <div class="bwfan_label_val">
                <input type="text" class="bwfan-input-wrapper bwfan_tag_input" name="fallback"/>
            </div>
        </div>
		<?php
	}

	public function get_preview() {
		?>
        <textarea style="margin: 5px 20px 15px;  width: 93%;" class="bwfan-preview-merge-tag bwfan-input-wrapper" readonly></textarea>
		<?php
	}

	public function get_copy_button() {
		?>
        <span style="line-height: 70px;" class="bwfan-use-merge-tag"><?php esc_html_e( 'Copy To Clipboard', 'wp-marketing-automations' ); ?></span>
        <input type="submit" class="bwfan-display-none"/>
		<?php
	}

	public function parse_shortcode_output( $output, $atts ) {
		$default = $output;
		if ( empty( $output ) && isset( $atts['fallback'] ) && ! empty( $atts['fallback'] ) ) {
			$this->fallback = $atts['fallback'];
			$output         = $this->fallback;
		}
		/** If value is array */
		$output = is_array( $output ) && count( $output ) > 0 ? implode( ', ', $output ) : $output;
		if ( ! empty( $output ) && isset( $atts['prefix'] ) && ! empty( $atts['prefix'] ) ) {
			$output = $atts['prefix'] . $output;
		}

		if ( ! empty( $output ) && isset( $atts['suffix'] ) && ! empty( $atts['suffix'] ) ) {
			$output = $output . $atts['suffix'];
		}

		return apply_filters( 'bwfan_parse_merge_tag_output', $output, $atts, $default );
	}

	public function data_key() {
		?>
        <div class="bwfan_mtag_wrap">
            <div class="bwfan_label">
                <label for="" class="bwfan-label-title"><?php esc_html_e( 'Meta Key', 'wp-marketing-automations' ); ?></label>
            </div>
            <div class="bwfan_label_val">
                <input type="text" class="bwfan-input-wrapper bwfan_tag_input" name="key" required/>
                <div class="clearfix bwfan_field_desc"><?php esc_html_e( 'Input the correct meta key in order to get the data', 'wp-marketing-automations' ); ?></div>
            </div>
        </div>
		<?php
	}

	public function get_customer_city() {
		$customer_city = '';
		$order         = BWFAN_Merge_Tag_Loader::get_data( 'wc_order' );
		$user_id       = BWFAN_Merge_Tag_Loader::get_data( 'user_id' );

		if ( ! empty( $order ) ) {
			return BWFAN_Woocommerce_Compatibility::get_order_billing_city( $order );
		}
		if ( ! empty( $user_id ) ) {
			return get_user_meta( (int) $user_id, 'billing_city', true );
		}

		return $customer_city;
	}

	public function get_customer_country() {
		$customer_country = '';
		$country_slug     = '';
		$order            = BWFAN_Merge_Tag_Loader::get_data( 'wc_order' );
		$user_id          = BWFAN_Merge_Tag_Loader::get_data( 'user_id' );

		if ( ! empty( $order ) ) {
			$country_slug = BWFAN_Woocommerce_Compatibility::get_billing_country_from_order( $order );
		}
		if ( ! empty( $user_id ) ) {
			$country_slug = get_user_meta( (int) $user_id, 'billing_country', true );
		}

		if ( ! empty( $country_slug ) ) {
			$countries_obj    = new WC_Countries();
			$countries        = $countries_obj->__get( 'countries' );
			$customer_country = $countries[ $country_slug ];
		}

		return $customer_country;
	}

	public function initialize_product_details() {
		$product_details = BWFAN_Merge_Tag_Loader::get_data( 'product_details' );
		$product_id      = BWFAN_Merge_Tag_Loader::get_data( 'product_id' );
		$product         = BWFAN_Merge_Tag_Loader::get_data( 'product' );

		if ( empty( $product_details ) ) {
			BWFAN_Merge_Tag_Loader::set_data( array(
				'product_details' => get_post( $product_id ),
			) );
		}
		if ( empty( $product ) ) {
			BWFAN_Merge_Tag_Loader::set_data( array(
				'product' => wc_get_product( $product_id ),
			) );
		}
	}

	public function get_slug() {
		return sanitize_title( get_class( $this ) );
	}

	public function get_name() {
		return $this->tag_name;
	}

	public function get_description() {
		return $this->tag_description;
	}

	public function get_localize_data() {
		return [
			'tag_name'        => $this->tag_name,
			'tag_description' => $this->tag_description,
			'support_v1'      => $this->support_v1,
		];
	}

	public function get_formatted_billing_address( $empty_content = '' ) {
		$order = BWFAN_Merge_Tag_Loader::get_data( 'wc_order' );
		if ( $order instanceof WC_Order ) {
			$address   = apply_filters( 'woocommerce_order_formatted_billing_address', $order->get_address( 'billing' ), $order );
			$separator = apply_filters( 'bwfan_' . $this->tag_name . '_separator', '</br>' );
			$address   = WC()->countries->get_formatted_address( $address, $separator );

			return $address ? $address : $empty_content;
		}

		return '';
	}

	public function get_formatted_shipping_address( $empty_content = '' ) {
		$order = BWFAN_Merge_Tag_Loader::get_data( 'wc_order' );
		if ( $order instanceof WC_Order ) {
			$address   = apply_filters( 'woocommerce_order_formatted_shipping_address', $order->get_address( 'shipping' ), $order );
			$separator = apply_filters( 'bwfan_' . $this->tag_name . '_separator', '</br>' );
			$address   = WC()->countries->get_formatted_address( $address, $separator );

			return $address ? $address : $empty_content;
		}

		return '';
	}

	/**
	 * Get date value in WordPress set date format
	 *
	 * @param $date_value
	 *
	 * @return string
	 */
	public function get_formatted_date_value( $date_value ) {
		if ( empty( $date_value ) ) {
			return '';
		}
		if ( false === $this->validate_date( $date_value ) ) {
			return $date_value;
		}
		$date_format = BWFAN_Common::bwfan_get_date_format();
		$date_value  = date( $date_format, strtotime( $date_value ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Reformats strtotime(date string) round-trip; gmdate would shift the calendar day

		return $date_value;
	}

	/**
	 * Validate date
	 *
	 * @param $date
	 * @param string $format
	 *
	 * @return bool
	 */
	public function validate_date( $date, $format = 'Y-m-d' ) {
		$d = DateTime::createFromFormat( $format, $date );

		return $d && $d->format( $format ) === $date;
	}

	/** contact data will be used in the contact merge tag
	 * @return false|WooFunnels_Contact
	 */
	public function get_contact_data() {
		/** Check if contact id is present */
		$get_data = BWFAN_Merge_Tag_Loader::get_data();
		if ( isset( $get_data['contact_id'] ) && 0 < intval( $get_data['contact_id'] ) ) {
			$contact = new WooFunnels_Contact( '', '', '', intval( $get_data['contact_id'] ) );
			if ( $contact instanceof WooFunnels_Contact && $contact->get_id() > 0 ) {
				return $contact;
			}
		}

		$user = wp_get_current_user();
		if ( ! $user instanceof WP_USER ) {
			return false;
		}

		if ( $user->ID === 0 ) {
			return false;
		}

		/** get user email */
		$user_email = $user->user_email;

		$contact = new WooFunnels_Contact( $user->ID, $user_email );
		if ( $contact instanceof WooFunnels_Contact && $contact->get_id() > 0 ) {
			return $contact;
		}

		return false;
	}

	/**
	 * Get order object
	 *
	 * @param $get_data
	 *
	 * @return string|WC_Order
	 */
	public function get_order_object( $get_data ) {
		if ( ! is_array( $get_data ) || ! bwfan_is_woocommerce_active() ) {
			return '';
		}

		$order = isset( $get_data['wc_order'] ) ? $get_data['wc_order'] : '';
		if ( $order instanceof WC_Order ) {
			return $order;
		}

		$order = ( isset( $get_data['order_id'] ) && intval( $get_data['order_id'] ) > 0 ) ? wc_get_order( $get_data['order_id'] ) : '';
		if ( $order instanceof WC_Order ) {
			return $order;
		}

		return '';
	}

	public function is_support_v2() {
		return $this->support_v2;
	}

	public function get_priority() {
		return $this->priority;
	}

	/**
	 * to avoid unserialize of the current class
	 */
	public function __wakeup() {
		throw new ErrorException( __CLASS__ . ' can`t converted to string' );
	}

	/**
	 * to avoid serialize of the current class
	 */
	public function __sleep() {
		throw new ErrorException( __CLASS__ . ' can`t converted to string' );
	}

	/**
	 * Returns true if support for v1
	 *
	 * @return bool
	 */
	public function is_support_v1() {
		return $this->support_v1;
	}

	/**
	 * Returns true if it is a delay variable
	 * @return bool|mixed
	 */
	public function is_delay_variable() {
		return $this->is_delay_variable;
	}

	/**
	 * To avoid cloning of current class
	 */
	protected function __clone() {
	}

	public function is_crm_broadcast() {
		return $this->is_crm_broadcast;
	}

	/**
	 * Get affiliate id from contact
	 *
	 * @return mixed|void
	 */
	public function get_affiliate_id() {
		$data = BWFAN_Merge_Tag_Loader::get_data();
		if ( ! isset( $data['contact_id'] ) || empty( $data['contact_id'] ) ) {
			return '';
		}

		if ( ! class_exists( 'BWFAN_PRO_Common' ) || ! bwfan_is_affiliatewp_active() ) {
			return '';
		}

		$contact_id = absint( $data['contact_id'] );
		$contact    = new BWFCRM_Contact( $contact_id );
		if ( ! $contact->is_contact_exists() ) {
			return '';
		}

		$user_id = $contact->contact->get_wpid();
		if ( empty( $user_id ) ) {
			return '';
		}

		$affiliate_id = BWFAN_PRO_Common::get_aff_id_by_user_id( $user_id );

		return ! $affiliate_id ? '' : $affiliate_id;
	}
}
