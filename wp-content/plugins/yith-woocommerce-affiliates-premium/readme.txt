=== YITH WooCommerce Affiliates ===

Contributors: yithemes
Tags:  affiliate, affiliate marketing, affiliate plugin, affiliate tool, affiliates, woocommerce affiliates, woocommerce referral, lead, link, marketing, money, partner, referral, referral links, referrer, sales, woocommerce, wp e-commerce, affiliate campaign, affiliate marketing, affiliate plugin, affiliate program, affiliate software, affiliate tool, track affiliates, tracking, affiliates manager, yit, yith, yithemes, yit affiliates, yith affiliates, yithemes affiliates
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 3.30.0
License: GPLv2 or later
Documentation: https://yithemes.com/docs-plugins/yith-woocommerce-affiliates

== Changelog ==

= 3.30.0 - Released on 24 August 2026 =

* New: support for WordPress 7.1
* New: support for WooCommerce 11.1
* Update: YITH plugin framework

= 3.29.0 - Released on 20 July 2026 =

* New: support for WooCommerce 11.0
* Update: YITH plugin framework
* Dev: send 'New commission' email to affiliates only for pending commissions

= 3.28.0 - Released on 16 June 2026 =

* New: support for WooCommerce 10.9
* Update: YITH plugin framework

= 3.27.0 - Released on 12 May 2026 =

* New: support for WooCommerce 10.8
* Update: YITH plugin framework
* Fix: ensure commissions are loaded in 'Pending commission' email

= 3.26.0 - Released on 31 March 2026 =

* New: support for WordPress 7.0
* New: support for WooCommerce 10.7
* Update: YITH plugin framework

= 3.25.0 - Released on 3 March 2026 =

* New: support for WooCommerce 10.6
* Update: YITH plugin framework

= 3.24.0 - Released on 22 January 2026 =

* New: support for WooCommerce 10.5
* Update: YITH plugin framework
* Dev: added compatibility with blocks API v3

= 3.23.0 - Released on 25 November 2025 =

* New: support for WordPress 6.9
* New: support for WooCommerce 10.4
* Update: YITH plugin framework

= 3.22.1 - Released on 15 October 2025 =

* Update: YITH plugin framework

= 3.22.0 - Released on 7 October 2025 =

* New: support for WooCommerce 10.3
* Update: YITH plugin framework
* Dev: added new filter yith_wcaf_subtract_from_commissions_rate

= 3.21.0 - Released on 3 September 2025 =

* New: support for WooCommerce 10.2
* Update: YITH plugin framework

= 3.20.0 - Released on 6 August 2025 =

* New: support for WooCommerce 10.1
* Update: YITH plugin framework

= 3.19.0 - Released on 3 July 2025 =

* New: support for WooCommerce 10.0
* Update: YITH plugin framework

= 3.18.0 - Released on 4 June 2025 =

* New: support for WooCommerce 9.9
* Update: YITH plugin framework
* Dev: added new action yith_wcaf_after_gateway_{$gateway->get_id()}_fields
* Dev: order visits and commissions correctly in the affiliate dashboard summary

= 3.17.0 - Released on 8 April 2025 =

* New: support for WordPress 6.8
* Update: YITH plugin framework

= 3.16.0 - Released on 18 March 2025 =

* New: support for WooCommerce 9.8
* Update: YITH plugin framework

= 3.15.0 - Released on 12 February 2025 =

* New: support for WooCommerce 9.7
* Update: YITH plugin framework

= 3.14.0 - Released on 21 January 2025 =

* New: support for WooCommerce 9.6
* Update: YITH plugin framework
* Dev: added new action yith_wcaf_commission_created

= 3.13.0 - Released on 5 December 2024 =

* New: support for WooCommerce 9.5
* Tweak: minor changes to admin area style
* Update: YITH plugin framework

= 3.12.0 - Released on 6 November 2024 =

* New: support for WordPress 6.7
* New: support for WooCommerce 9.4
* Tweak: only show Available Balance box when Total and Available balances differ from each other
* Tweak: when saving affiliate preferences, if no other payment_email is provided, try to use PayPal email
* Update: YITH plugin framework
* Fix: impossibility to generate correct delete query for Clicks, causes cron yith_wcaf_delete_clicks to delete all clicks at every execution
* Fix: typo in duration-related functions, when processing minutes
* Fix: typo in date format, when creating commissions and using order date for the commission
* Fix: prevent possible collisions between Rate Rules, due to partial configuration match

= 3.11.1 - Released on 27 September 2024 =

* Fix: Rate Rules editing & creation broken due to Fatal Error since version 3.11.0

= 3.11.0 - Released on 23 September 2024 =

* New: support for WooCommerce 9.3
* New: added support to multi-check field type for Registration Form
* New: added Become an Affiliate field values to admin-new-affiliate email
* New: enabled option for minimum commission age for payment requests too
* New: added option to skip commissions creation when a specific coupon is applied to the order
* New: added option to subtract coupon percentage from commission percentage, when coupon is applied to a referred order (for percentage coupons only)
* Tweak: reviewed notes shown on Payment page and  Withdrawal modal
* Tweak: only generate commissions once the order is completed for the first time
* Tweak: moved Request Withdrawal modal in Dashboard Summary section
* Tweak: make sure we validate invoice file and billing profile fields in Withdraw Modal before enabling submit
* Update: YITH plugin framework
* Dev: minor refactoring of Factory and Model classes
* Dev: added yith_wcaf_rule_matching_conditions filter
* Dev: added yith_wcaf_dependencies_applied trigger
* Dev: added yith_wcaf_validated_form trigger
* Dev: added yith_wcaf_validated_field trigger
* Dev: added yith_wcaf_invoice_number filter

= 3.10.0 - Released on 26 August 2024 =

* New: support for WooCommerce 9.2
* Update: YITH plugin framework

= 3.9.0 - Released on 31 July 2024 =

* New: support for WordPress 6.6
* New: support for WooCommerce 9.1
* Update: YITH plugin framework

= 3.8.2 - Released on 1 July 2024 =

* Fix: removed polyfill.io references

= 3.8.1 - Released on 24 June 2024 =

* Fix: possible errors with table aliases in the database queries

= 3.8.0 - Released on 11 June 2024 =

* New: support for WooCommerce 9.0
* Update: YITH plugin framework
* Fix: possible type warnings with PHP 8.2
* Dev: moved table definitions to DataStores classes

= 3.7.0 - Released on 13 May 2024 =

* New: support for WooCommerce 8.9
* Tweak: dashboard stats now only count confirmed and refunded commissions
* Tweak: improved conditions checked before setting history cookie
* Update: YITH plugin framework

= 3.6.0 - Released on 8 April 2024 =

* New: support for WooCommerce 8.8
* Update: YITH plugin framework
* Fix: improved conditions checked before setting session cookie

= 3.5.0 - Released on 11 March 2024 =

* New: support for WordPress 6.5
* New: support for WooCommerce 8.7
* Update: YITH plugin framework
* Fix: PayPal IPN amount not matching commission amount

= 3.4.0 - Released on 14 February 2024 =

* New: support for WooCommerce 8.6
* Tweak: removed usage of filter_var to validate URLs
* Tweak: when in secure context, use Clipboard API to copy values from browser
* Update: YITH plugin framework
* Fix: make sure we save promo coupon in cookie, when cart is empty, for future usage
* Dev: added new filter yith_wcaf_cookie_exp

= 3.3.0 - Released on 16 January 2024 =

* New: support for WooCommerce 8.5
* Update: YITH plugin framework
* Dev: added new filter yith_wcaf_hide_commissions_table_new_order_email

= 3.2.0 - Released on 19 December 2023 =

* New: support for WooCommerce 8.4
* Tweak: switch admin panel filter to GET method, so that filters are preserved when adding new query strings to current URL
* Update: YITH plugin framework

= 3.1.0 - Released on 21 November 2023 =

* New: support for WordPress 6.4
* New: support for WooCommerce 8.3
* Update: YITH plugin framework

= 3.0.0 - Released on 02 November 2023 =

* New: admin panel UI
* Update: YITH plugin framework
* Update: language files
* Fix: error with admin profile permissions check
* Dev: added yith_wcaf_create_order_commission_skip_zero_commissions filter

= 2.19.0 - Released on 23 October 2023 =

* New: support for WooCommerce 8.2
* Update: YITH plugin framework
* Fix: display of the application date in the affiliate detail panel

= 2.18.0 - Released on 12 September 2023 =

* New: support for WooCommerce 8.1
* Update: YITH plugin framework
* Fix: RateRules matching algorithm now correctly checks both for rules on variation and on parent, before proceeding to more general rules

= 2.17.0 - Released on 2 August 2023 =

* New: support for WordPress 6.3
* New: support for WooCommerce 8.0
* Tweak: set sensible default values for duration settings
* Update: YITH plugin framework
* Fix: prevent JS error when dependencies module can't find any checked radio
* Fix: error in data parsing when trying to create Click object with empty origin
* Dev: added new filter yith_wcaf_commission_{$current_status}_status_label

= 2.16.0 - Released on 12 July 2023 =

* New: support for WooCommerce 7.9
* Update: YITH plugin framework
* Fix: prevent system from updating rates rules not directly related to affiliate when saving affiliate profile
* Dev: added new filter yith_wcaf_show_referral_field
* Dev: added new filter yith_wcaf_origin_url

= 2.15.0 - Released on 12 June 2023 =

* New: support for WooCommerce 7.8
* Update: YITH plugin framework
* Dev: added new action yith_wcaf_affiliate_saved

= 2.14.0 - Released on 11 May 2023 =

* New: support for WooCommerce 7.7
* Update: YITH plugin framework
* Fix: fatal error triggered in the emails when paying a commission

= 2.13.1 - Released on 19 April 2023 =

* Fix: minor bugs

= 2.13.0 - Released on 18 April 2023 =

* New: support for WooCommerce 7.6
* Update: YITH plugin framework
* Fix: REST controller uses before and after parameters in reverse when listing items

= 2.12.0 - Released on 13 March 2023 =

* New: support for WordPress 6.2
* New: support for WooCommerce 7.5
* Update: YITH plugin framework
* Dev: added new filter yith_wcaf_affiliate_invoice_template_args

= 2.11.0 - Released on 13 February 2023 =

* New: support for WooCommerce 7.4
* Update: YITH plugin framework
* Dev: save the application date also when the affiliate is created manually from the backend

= 2.10.0 - Released on 10 January 2023 =

* New: support for WooCommerce 7.3
* Tweak: make sure to use threshold date in correct timezone when cheking if an hit should be registered
* Tweak: make sure to stop trying to add promo to cart, when it is already applied
* Tweak: removed useless call to $cart->recalculate_totals()
* Update: YITH plugin framework

= 2.9.0 - Released on 14 December 2022 =

* New: support for WooCommerce 7.2
* Update: YITH plugin framework
* Fix: issue when copying coupons in the Affiliate Dashboard

= 2.8.0 - Released on 14 November 2022 =

* New: support for WordPress 6.1
* New: support for WooCommerce 7.1
* Tweak: changed structure of rate rules table in the database
* Update: YITH plugin framework
* Fix: patched security vulnerability

= 2.7.0 - Released on 5 October 2022 =

* New: support for WooCommerce 7.0
* Tweak: added pagination to the rates table
* Update: YITH plugin framework
* Fix: wrong label for total_paid stat
* Dev: added yith_wcaf_referral_link filter in the field to generate referral URL in the affiliate profile
* Dev: added new action yith_wcaf_after_affiliate_options

= 2.6.0 - Released on 8 September 2022 =

* New: support for WooCommerce 6.9
* Update: YITH plugin framework
* Fix: removed parameter from yith_wcaf_settings_form_start action to avoid a possible fatal error
* Dev: added new filter yith_wcaf_show_login_section

= 2.5.0 - Released on 4 August 2022 =

* New: support for WooCommerce 6.8
* Tweak: improve Reset default fields, to avoid saving empty set
* Update: YITH plugin framework
* Fix: Affiliate rejected email was not using correct message from options
* Dev: fixed wrong display of product name in the rate rule modal
* Dev: correctly apply product category rate rules for variable products
* Dev: added new filter yith_wcaf_affiliate_avoid_auto_commission

= 2.4.0 - Released on 13 July 2022 =

* New: support for WooCommerce 6.7
* Update: YITH plugin framework
* Fix: make sure to use filtered list of fields for the Gateway, whenever dealing with fields
* Dev: upgraded react-router-dom to version 6.3

= 2.3.0 - Released on 27 June 2022 =

* New: support for WooCommerce 6.6
* Tweak: removed yith_wcaf_refeal_totals_table legacy filter from template
* Update: YITH plugin framework
* Dev: added new filter yith_wcaf_commissions_metabox_total
* Dev: added new filter yith_wcaf_swift_code_label

= 2.2.0 - Released on 25 May 2022 =

* New: support for WordPress 6.0
* New: support for WooCommerce 6.5
* Update: YITH plugin framework
* Tweak: whenever possible use placeholders for query generation, avoiding direct concatenation of strings
* Fix: missing heading for the commission CSV export
* Fix: prevent notices when stats query returns empty set, during REST API processing
* Dev: added new filter yith_wcaf_share_title
* Dev: added yith_wcaf_dashboard_leaderboards_per_page filter

= 2.1.1 - Released on 04 May 2022 =

* Update: YITH plugin framework
* Tweak: better management of coupons in Affiliate Dashboard in the integration with WC Subscription
* Tweak: changed affiliates table structure, to allow for 3 digits rates (100%)
* Tweak: improved link-generator template attributes
* Tweak: use GET method for List views (fix pagination and filtering problems on admin side)
* Tweak: PayPal-related gateways now filter affiliate preferences, to fallback to legacy payment_email when no specific preference has been set
* Fix: prevent unnecessary action scheduling during upgrade to version 2.x.x
* Dev: added filter yith_wcaf_affiliate_{$gateway_id}_gateway_preferences to allow third party code filter affiliate gateway preferences
* Dev: replaced yith_wcaf_is_hosted filter with yith_wcaf_is_url_hosted (corrected value being filtered)

= 2.1.0 - Released on 21 April 2022 =

* New: support for WooCommerce 6.4
* Update: YITH plugin framework
* Tweak: refactored Rate Handler class, and applied changes to all code that previously used it
* Tweak: prevent duplicated queries to count coupons (use cache)
* Tweak: improved cache system (better identify cache miss)
* Tweak: improved update queries, to correctly update record even when only one field needs to be updated to a NULL value
* Tweak: improvements to dependencies system
* Tweak: changed payment email column in payments table, to show details about payment gateway, if any
* Tweak: make sure gateway is correctly assigned to the payment record when processing payment
* Tweak: special handling for first/last name field value (fallback to user first/last name when empty)
* Fix: avoid problem with withdraw limits, caused by formatting function
* Fix: notify admin about new payments even when they come from withdraw operation
* Fix: show notes dates in current timezone
* Fix: JS error on Edit Gateway Preferences modal
* Fix: avoid checking terms during process_withdraw if invoice is not required
* Fix: prevent notice when processing bulk actions to process payments
* Fix: wrong affiliate shown in Current Affiliate shortcode
* Fix: avoid saving empty token from user profile screen
* Fix: prevent Fatal error on commission/payment details page, when related order has been deleted
* Fix: options for products categories/tags exclusions now work as intended
* Dev: added yith_wcaf_get_rate and yith_wcaf_get_formatted_rate functions to easily retrieve rate that best match passed parameters
* Dev: added filter yith_wcaf_global_localize to allow third party code to change localized variables

= 2.0.1 - Released on 23 March 2022 =

* Update: YITH plugin framework
* Fix: state field in billing profile not working correctly with WC country scripts in backend
* Fix: prevent problems with coupons endpoint
* Fix: wrong currency shown in the Dashboard tab
* Fix: prevent possible endless loop when converting secs amount to new duration format
* Dev: added yith_wcaf_dashboard_{$items}_table_render_{$field}_column action, to print custom columns inside Dashboard Tables

= 2.0.0 - Released on 21 March 2022 =

* New: redesigned UI / improved UX of the plugin
* New: dashboard that allows admin to keep track of affiliate program performance
* New: registration form editor
* New: custom fields in Become an Affiliate form
* New: custom fields in Settings endpoint of Affiliate Dashboard
* New: option to show Affiliate Dashboard as endpoints of My Account page
* New: custom rates system, to create complex rate rule
* New: allow affiliates to withdraw funds through a dedicated modal
* New: improved Gateways handling, with specific options for each gateway
* New: BACS gateway to request your affiliate Bank account for offline payments
* New: modal to create user as an affiliate from admin panel
* New: exclude products from affiliate program by category or tag
* New: exclude user from affiliate program by role
* New: CRUD REST API
* New: added invoice column on payment tables
* Update: YITH plugin framework
* Update: mpdf library to version 8.0.15
* Tweak: completely refactored plugin code
* Tweak: prevent rounding issues when requesting withdraw
* Tweak: refactored affiliate status emails, to have more specific messages depending on the status
* Tweak: minor improvements to invoice template and appearance
* Tweak: improved privacy class
* Tweak: refactored Gutenberg blocks / Elementor widget with new parameters
* Fix: prevent creation of two affiliates records for the same user
* Dev: added action yith_wcaf_before_process_withdraw_request
* Dev: fixed name of yith_wcaf_can_affiliate_withdraw filter
* Dev: added yith_wcaf_should_register_hit filter
* Dev: added new yith_wcaf_instance_check filter
* Dev: added new yith_wcaf_use_dashboard_pretty_permalinks filter
* Dev: added new yith_wcaf_matched_rule filter
* Dev: added new {$tag}_shortcode_template_atts filter, to allow third party dev to change shortcode attributes
* Dev: added new yith_wcaf_{$field_key}_type filter, to allow third party dev to change each field type
* Dev: added new yith_wcaf_{$field_key}_label filter, to allow third party dev to change each field label
* Dev: added new yith_wcaf_{$field_key}_required filter, to allow third party dev to change each field required flag

= 1.15.0 - Released on 10 March 2022 =

* New: support for WooCommerce 6.3
* Update: YITH plugin framework
* Fix: Change text domain in localize string that prevents them to be translated
* Dev: added new yith_wcaf_instance_check filter
* Dev: New filter yith_wcaf_earnings_affiliates_table

= 1.14.0 - Released on 08 February 2022 =

* New: support for WooCommerce 6.2
* Update: YITH plugin framework
* Dev: added new filter yith_wcaf_skip_payout_payment

= 1.13.0 - Released on 10 January 2022 =

* New: support for WordPress 5.9
* New: support for WooCommerce 6.1
* Update: YITH plugin framework

= 1.12.0 - Released on 16 December 2021 =

* New: support for WooCommerce 6.0
* Update: YITH plugin framework
* Update: language files

= 1.11.0 - Released on 4 November 2021 =

* New: support for WooCommerce 5.9
* Update: YITH plugin framework
* Dev: added function_exists check when Affiliate Dashboard page is created
* Dev: added some check to prevent errors in the WooCommerce emails settings page
* Dev: added new filter yith_wcaf_use_percentage_rates_field

= 1.10.0 - Released on 11 October 2021 =

* New: support for WooCommerce 5.8
* Update: YITH plugin framework
* Dev: added new filter yith_wcaf_admin_tabs_control

= 1.9.1 - Released on 27 September 2021 =

* Update: YITH Plugin Framework
* Fix: debug info feature removed for all logged in users

= 1.9.0 - Released on 23 September 2021 =

* New: support for WooCommerce 5.7
* Update: YITH Plugin Framework
* Fix: product search box in Affiliate Dashboard page

= 1.8.7 - Released on 05 August 2021 =

* New: support for WooCommerce 5.6
* Update: YITH Plugin Framework

= 1.8.6 - Released on 26 July 2021 =

* New: support for WooCommerce 5.5
* New: support for WordPress 5.8
* Update: YITH Plugin Framework
* Dev: new params on filter 'yith_wcaf_use_percentage_rates'
* Dev: added new yith_wcaf_payment_email_label_address_register_form filter and yith_wcaf_payment_email_dashboard_settings filter
* Dev: added yith_wcaf_show_if_affiliate_result filter, to allow custom rules for yith_wcan_show_if_affiliate shortcode

= 1.8.5 - Released on 16 June 2021 =

* New: support for WooCommerce 5.4
* Update: YITH Plugin Framework
* Tweak: minor style improvements for admin panel
* Fix: fixed total commission amount (balance) of the affiliate when doing withdraw of amount type

= 1.8.4 - Released on 13 May 2021 =

* New: support for WooCommerce 5.3
* Update: YITH Plugin Framework
* Tweak: minor style improvements for admin view
* Dev: added new yith_wcaf_payments_table_column_amount filter
* Dev: filter yith_wcaf_apply_promo_param is now applied whenever needed to change apply-promo parameter

= 1.8.3 - Released on 20 April 2021 =

* New: support for WooCommerce 5.2
* Update: YITH Plugin Framework
* Update: new panel style
* Dev: added new yith_wcaf_requester_origin filter

= 1.8.2 - Released on 12 March 2021 =

* New: support for WordPress 5.7
* New: support for WooCommerce 5.1
* New: check over instance before processing payments
* Update YITH Plugin Framework
* Update: Italian language
* Tweak: get translated page of Terms and conditions with WPML
* Dev: added new yith_wcaf_set_ref_cookie filter
* Dev: added wpml config file to translate admin texts
* Dev: add a note to the payment when payment process doesn't belong to the correct instance

= 1.8.1 - Released on 18 February 2021 =

* New: support for WooCommerce 5.0
* New: German language
* Update: YITH Plugin Framework
* Update: Dutch language
* Fix: declared nonce to correctly save affiliate details
* Dev: added yith_wcaf_register_form action in registration form for logged users

= 1.8.0 - Released on 12 January 2021 =

* New: support for WooCommerce 4.9
* Update: plugin framework
* Fix: prevent fatal error on column line item refunds if order doesn't exists
* Fix: undefined variable $column when order doesn't exists
* Dev: added function yith_wcaf_number_format to format the rates

= 1.7.9 - Released on 09 December 2020 =

* New: support for WooCommerce 4.8
* Update: plugin framework
* Dev: added filter yith_wcaf_display_format in order to allow to change the format on conversion rate and rate section

= 1.7.8 - Released on 10 November 2020 =

* New: support for WordPress 5.6
* New: support for WooCommerce 4.7
* New: possibility to update plugin via WP-CLI
* Update: plugin framework
* Tweak: add referral code to apply-promo url
* Fix: error when saving billing profile from settings page
* Dev: added yith_wcaf_show_register_section filter
* Dev: removed deprecated method .ready from scripts

= 1.7.7 - Released on 15 October 2020 =

* New: support for WooCommerce 4.6
* Update: plugin framework
* Tweak: show correct currency for commissions on admin table
* Tweak: fixed typo in plugin's options
* Tweak: removed usage of deprecated jQuery method $.fn.toggle from admin assets
* Fix: possible notice when getting formatted affiliate billing address for invoice
* Dev: added new filter yith_wcaf_commissions_dashboard_commissions

= 1.7.6 - Released on 17 September 2020 =

* New: support for WooCommerce 4.5
* New: apply promo url for easy coupon sharing
* Update: plugin framework
* Tweak: allow admin show T&C fields on withdraw view, even if no invoice is required
* Tweak: hide billing details section in withdraw view, when it is not needed
* Tweak: improved show_if_affiliates shortcode, to accept more than one rule, and to accept negated rules too
* Fix: plugin not consider bottom select for bulk actions on Commissions, Payments and Affiliates views
* Fix: appended additional content to pending commission email
* Fix: possible notice happening when requesting withdraw by amount
* Fix: avoid system deleting persistent_token after order placed
* Fix: avoid persistent token change on order_completed when avoid_referral_change is enabled
* Dev: added new parameters to yith_wcaf_apply_persistent_token filter

= 1.7.5 - Released on 20 August 2020 =

* New: save and show date of first application for the affiliate
* Update: plugin framework
* Fix: fixed process checkout handling when coupon section isn't enable

= 1.7.4 - Released on 13 August 2020 =

* New: support for WordPress 5.5
* New: support for WooCommerce 4.4
* New: option to allow affiliates withdraw an amount of their choice
* New: breadcrumb for Affiliate Dashboard now contains link to get back to Dashboard home
* Update: plugin framework
* Tweak: reviewed withdraw shortcode appearance
* Tweak: added affiliate handling to REST api that creates order
* Tweak: affiliate now logs out directly from affiliate dashboard
* Fix: improved affiliate profile update, to avoid affiliate not having correct role
* Fix: prevent possible fatal when showing referrer form
* Fix: avoid auto commission also when there is a affiliate coupon applied in the order
* Dev: added yith_wcaf_max_rate_value filter
* Dev: added yith_wcaf_line_item_commission_total filter
* Dev: added yith_wcaf_line_total_check_amount_total filter
* Dev: added yith_wcaf_no_affiliate_message filter
* Dev: added yith_wcaf_user_details_affiliates_table filter

= 1.7.3 - Released on 09 June 2020 =

* New: support for WooCommerce 4.2
* Tweak: fixed wrong text domain for some strings
* Tweak: improved appearance of affiliate details panel
* Fix: losing status selection after filtering on admin views
* Dev: added yith_wcaf_comission_table_columns filter
* Dev: added yith_wcaf_create_item_commission filter
* Dev: added yith_wcaf_enqueue_fontello_stylesheet filter
* Dev: added yith_wcaf_ipn_listener_apply_custom_cainfo filter for IPN validation curl call
* Dev: added yith_wcaf_ipn_listener_custom_cainfo filter for IPN validation curl call
* Dev: added yith_wcaf_ipn_listener_apply_custom_httpheader filter for IPN validation curl call
* Dev: added yith_wcaf_ipn_listener_custom_httpheader filter for IPN validation curl call
* Dev: add second parameter on yith_wcaf_use_percentage_rates hook

= 1.7.2 - Released on 08 May 2020 =

* New: support for WooCommerce 4.1
* Update: plugin framework
* Tweak: removed max attribute from payment threshold field
* Tweak: hotfix paypal return url, to set back affiliate cookie when getting back to site after cancelling order
* Fix: removed translation on screen id, that was causing missing assets on admin on non-english sites
* Dev: added yith_wcaf_withdraw_amount_allow_exceeding_max filter

= 1.7.1 - Released on 20 April 2020 =

* New: added list of associated users to affiliate detail screen
* Update: plugin framework
* Update: Italian language
* Tweak: moved script localization just after script registration
* Tweak: minor improvements to frontend layouts, for better theme integration
* Tweak: removed not-pertinent CSS rules (this styling should be demanded by theme)
* Tweak: changed all doubleval to floatval function
* Tweak: added affiliate dashboard shortcode as gutenberg block on brand new Dashboard page
* Fix: fixed escaped labels of Term and Conditions (changed to wp_kses)
* Dev: added yith_wcaf_check_affiliate_validation_error filter

= 1.7.0 - Released on 09 March 2020 =

* New: support for WordPress 5.4
* New: support for WooCommerce 4.0
* New: Greek translation
* New: added option to set up affiliates cookie via AJAX call (to better work with cache systems)
* New: added Elementor widgets
* Tweak: included variations in Excluded products field
* Tweak: include commissions and commissions history metaboxes into WC Subscription edit page
* Tweak: code reformat and improvements for PHPCS
* Update: plugin framework
* Fix: pending commission email for admin is not sent if the commission amount is zero or not exists
* Fix: removed duplicated id from form-referrer box
* Dev: added new filter yith_wcaf_customer_status_change_dashboard_url
* Dev: added new filter yith_wcaf_show_message_wc_print_notice

= 1.6.9 – Released on 23 December 2019 =

* New: support for WooCommerce 3.9
* Update: plugin framework
* Update: Italian language
* Update: Greek language
* Update: Dutch language
* Fix: system not recognizing correct value for "Pay only commission older than" option
* Dev: added yith_wcaf_website_type filter

= 1.6.8 - Released on 12 December 2019 =

* New: added link generator on Affiliate details page, on backend
* Update: Greek translation
* Update: plugin framework

= 1.6.6 – Released on 29 November 2019 =

* New: added category column to commissions table and commissions CSV file
* Tweak: check if dependencies are registered in order to prevent error in gutenberg pages
* Update: Italian language
* Update: notice handler
* Update: plugin framework
* Fix: prevent warning when global $post do not contain WP_Post object

= 1.6.5 - Released on 06 November 2019 =

* Tweak: changed Fontello class names to avoid conflicts with themes
* Tweak: added checks before Fontello style inclusion, to load it just when needed

= 1.6.4 – Released on 05 November 2019 =

* New: support for WordPress 5.3
* New: support for WooCommerce 3.8
* New: Added affiliates export as CSV feature
* New: Added social sharing for referral link
* Update: Italian language
* Update: Spanish language
* Update: Dutch language
* Tweak: allow showing affiliate menu on coupons section
* Tweak: added cache for commission status count
* Tweak: reviewed endpoint handling to prevent 404 errors on coupon section when it is hidden to affiliates that do not have coupons
* Tweak: optimized has_unpaid_commissions method
* Tweak: optimized affiliates per_status_count, using wp_cache
* Fix: notices related to missing variables, or unhandled exception return values
* Fix: issue with ban & reject affiliate bulk actions
* Fix: fixed user edit link and avatar image from rates table
* Fix: reset button not appearing on commission page when filtering by status
* Fix: exclude trashed commissions from commission count on the commission page
* Dev: added new filter yith_wcaf_process_become_an_affiliate_request_correctly
* Dev: added new filter yith_wcaf_ipn_listener_force_ssl_v4 and changed force_ssl_v4 of IPN listener to false
* Dev: added new filter yith_wcaf_link_generator_generated_url
* Dev: added new filter yith_wcaf_show_dashboard_links_withdraw for withdraw template to show menu items
* Dev: added new filter yith_wcaf_display_symbol
* Dev: added new action yith_wcaf_process_checkout_with_affiliate

= 1.6.3 - Released on 09 August 2019 =

* New: WooCommerce 3.7.0 RC2 support
* New: added integration with WC Subscription
* Tweak: added coupon meta data as placeholders for new affiliate coupon email
* Tweak: changed doubleval for floatval
* Tweak: regenerate invoice now saves submitted values as invoice profile
* Tweak: using publishable url for download invoice on backend
* Update: internal plugin framework
* Update: Italian language
* Fix: new condition to accept Terms & Conditions in withdraw panel
* Fix: array to string conversion when regenerating affiliate invoice
* Fix: allow copy button from iphone/ipad
* Fix: wrong value was used in affiliate selection dropdown, on user edit page, after saving a user as Associated affiliate
* Dev: added new filter yith_wcaf_withdraw_valid_payment_email for payment email of withdraw tab
* Dev: added new filter yith_wcaf_become_an_affiliate_check
* Dev: added new filter yith_wcaf_show_coupon_section
* Dev: added new filter yith_wcaf_check_affiliate_val_error
* Dev: added new filter yith_wcaf_check_affiliate_val_error_premium
* Dev: added new filter yith_wcaf_dashboard_navigation_menu
* Dev: added new action yith_wcaf_process_withdraw_request
* Dev: added new action yith_wcaf_referrer_set

= 1.6.2 - Released on 31 May 2019 =

* Fix: added missing plugin-upgrade directory

= 1.6.1 - Released on 29 May 2019 =

* New: Switch to Cancelled bulk action for payments
* Tweak: improved uninstall procedure
* Tweak: rel nofollow to anchors with query strings
* Tweak: improved click handling for users that does not have yith_wcaf_click_enabled option registered in db
* Update: .pot file
* Update: Dutch version
* Update: plugin-fw
* Fix problem translation on dashboard-withdraw template
* Fix: affiliates not being auto-enaled after registration
* Fix: minimum withdraw conditions
* Dev: New action 'yith_wcaf_after_set_cookie'
* Dev: filter yith_wcaf_payment_table_column_default
* Dev filter yith_wcaf_payments_table_get_columns
* Dev: Fixed action 'yith_wcaf_after_set_cookie'
* Dev: Added new parameters in do_action 'woocommerce_email_header' and 'woocommerce_email_footer' for new affiliate email

= 1.6.0 - Released on 03 April 2019 =

* New: WooCommerce 3.6.0 RC1 support
* New: admin can now disable Click handling
* New: current affiliate shortcode
* New: admin can now regenerate invoice for affiliates
* New: admin can now assign coupons to affiliates
* New: affiliates can receive commissions by coupons
* New: email sent when admin assign a coupon to an affiliate
* Update: internal plugin framework
* Update: Spanish language
* Tweak: improved withdraw handling
* Fix: change Generate Link URL in customer emails
* Fix: default value for new_status and old_status in email class
* Fix: billing country on invoices
* Fix: fixed issue with hidden sections (generate link not removed from affiliate dashboard menu)
* Fix: DB error on backend
* Dev: added new filter yith_wcaf_prepare_items_commissions

= 1.5.1 - Released on 31 January 2019 =

* New: WooCommerce 3.5.3 support
* Tweak: replacing state code with state name when available in invoices
* Update: Spanish translation
* Update: internal plugin framework
* Fix: totals shown in affiliate details page
* Fix: prevent fatal error Can't use method return value in write context
* Dev: added yith_wcaf_email_currency to let third party code filter currencies showed plugin emails
* Dev: added do action yith_wcaf_refeal_totals_table
* Dev: added filter yith_wcaf_add_affiliate_role

= 1.5.0 - Released on 12 December 2018 =

* New: support to WordPress 5.0
* New: support to WooCommerce 3.5.2
* New: Gutenberg block for yith_wcaf_registration_form shortcode
* New: Gutenberg block for yith_wcaf_affiliate_dashboard shortcode
* New: Gutenberg block for yith_wcaf_link_generator shortcode
* Tweak: improved can_user_see_section method
* Tweak: added autocomplete for withdraw fields
* Tweak: updated plugin framework
* Fix: notice in affiliate dashboard
* Fix: notice "trying to retrieve user_login from non-object" on commission table
* Fix: issue with Withdraw for countries that do not require state
* Fix: prevent Notice when get_userdata returns a non-object
* Fix: doubled input fields on custom registration form
* Fix: section title in withdraw template
* Dev: added missing actions on link generator template

= 1.4.1 - Released on 24 October 2018 =

* New: added yith_wcaf_show_withdraw shortcode
* New: email sent to affiliates when account is banned
* Tweak: updated plugin framework
* Tweak: improved layout of the Withdraw template
* Tweak: improved email sent when affiliate account changes status
* Updated: dutch language
* Fix: minor issues introduced with last update

= 1.4.0 - Released on 03 October 2018 =

* New: support to WooCommerce 3.5-RC1
* New: support to WordPress 4.9.8
* New: updated plugin framework
* New: added new Reject status for affiliates
* New: affiliates receives an email on account status change
* New: added commissions Trash
* New: affiliates can now request commissions withdraws
* New: affiliates can now upload invoices for their withdraw requests
* New: affiliates can now generate invoices for their withdraw requests
* New: added affiliate details page
* Fix: affiliate backend creation
* Fix: fixed some queries on various admin views
* Tweak: improved balance calculation
* Dev: added filter get_referral_url filter

= 1.3.1 - Released on 19 July 2018 =

* New: support to YITH PayPal Payouts for WooCommerce
* New: added new fields during affiliate registration
* New: admin can now exclude products/users from affiliate program
* Tweak: improved filters, counters, views and redirection on affiliates admin panel
* Tweak: manual payment are now registered by default as on-hold
* Fixed: warning occurring when WooCommerce does not send all params to woocommerce_email_order_meta action
* Dev: added filter yith_wcaf_dashboard_affiliate_message

= 1.3.0 - Released on 28 May 2018 =

* New: WooCommerce 3.4 compatibility
* New: WordPress 4.9.6 compatibility
* New: updated plugin-fw
* New: GDPR compliance
* New: admin can now ban Affiliates
* Update: Italian Language
* Update: Spanish language
* Tweak: improved pagination of dashboard sections
* Fix: preventing notice when filtering by date payments

= 1.2.4 - Released on 05 April 2018 =

* New: added "process orphan commissions" procedure
* New: added shortcodes for Affiliate Dashboard sections ( [yith_wcaf_show_clicks], [yith_wcaf_show_commissions], [yith_wcaf_show_payments], [yith_wcaf_show_settings] )
* New: added handling for subscription renews (YITH WooCommerce Subscription 1.3.2 required)
* Dev: added yith_wcaf_requester_link filter to let third party code change requester link

= 1.2.3 - Released on 02 March 2018 =

* New: "yith_wcaf_show_if_affiliate" shortcode
* Tweak: remove user_trailingslashit from get_referral_url to improve compatibility
* Tweak: improved user capability handling, now all admin operations require at least manage_woocommerce capability (edited)
* Dev: new filter "yith_wcaf_panel_capability" to let third party code change minimum required capability for admin operations
* Dev: added "order_id" param for "yith_wcaf_affiliate_rate" filter
* Update: italian translation

= 1.2.2 - Released on 01 February 2018 =

* New: added WooCommerce 3.3.x support
* New: added WordPress 4.9.2 support
* New: added Dutch translation
* New: pay commissions every day
* New: pay only commissions older than a certain number of days
* Tweak: added SAMEORIGIN header to Affiliate Dashboard page
* Tweak: fixed error with wrong Affiliate ID when adding new affiliate to database
* Fix: preventing fatal error on commission details view when order meta are retrieved as objects (WC 3.0+)
* Dev: added yith_wcaf_commissions_csv_heading and yith_wcaf_commissions_csv_row filters to let third party developers change output of csv export operation

= 1.2.1 - Released on 14 November 2017 =

* Fix: added check over user before adding role

= 1.2.0 - Released on 10 November 2017 =

* New: WooCommerce 3.2.x support
* New: new affiliate role
* New: added login form in "Registration form" template
* New: added copy button for generated referral url
* New: added export csv procedure for commissions
* Tweak: added "Commissions table" to new order admin email
* Fix: removed profile panel when customer have permissions lower then shop manager
* Fix: problem with manual order affiliate assignment, when there are no previous commissions to delete
* Dev: added yith_wcaf_settings_form_start action
* Dev: added yith_wcaf_settings_form action
* Dev: added yith_wcaf_save_affiliate_settings action
* Dev: added yith_wcaf_show_dashboard_links filter to let dev show navigation menu on all affiliates dashboard pages

= 1.1.0 - Released on 03 April 2017 =

* New: WordPress 4.7.3 compatibility
* New: WooCommerce 3.0-RC2 compatibility
* New: field to user profile, to let admin set current permanent affiliate token for the user
* New: option to let admin choose that referral cookie won't change once set, till its expiration
* New: capability for the admin to set an affiliate for an unassigned order
* New: capability for the admin to remove an affiliate and relative commissions from an order
* New: Delete bulk action for payments
* New: option to force commissions deletion
* New: added Hungarian - HUNGARY translation (thanks to Szabolcs)
* Tweak: text domain to yith-woocommerce-affiliates. IMPORTANT: this will delete all previous translations
* Tweak: send paid email at yith_wcaf_commission_status_paid
* Tweak: complete revision for paid commissions emails triggers
* Tweak: delete notes while deleting commission
* Fix: email replacements
* Fix: delete method for payments
* Fix: commission paid email trigger
* Fix: commission delete process
* Fix: commission notes delete process
* Dev: added yith_wcaf_notify_user_pending_commission filter to let third party plugin prevent or enable pending commission notification
* Dev: added yith_wcaf_notify_user_paid_commissions filter to let third party plugin prevent or enable paid commission notification
* Dev: added yith_wcaf_affiliate_rate filter to let third party plugin customize affiliate commission rate
* Dev: added yith_wcaf_use_percentage_rates filter to let switch from percentage rate to fixed amount (use it at your own risk, as no control over item total is performed)
* Dev: added yith_wcaf_become_an_affiliate_redirection filter to let third party plugin customize redirection after "Become an Affiliate" butotn is clicked
* Dev: added yith_wcaf_become_affiliate_button_text filter to let third party plugin change Become Affiliate button label
* Dev: added yith_wcaf_persistent_rate filter to let third party plugin enable/disable persistent rate
* Dev: added yith_wcaf_payment_email_required filter to let third party plugin to remove payment email from affiliate registration form
* Dev: added yith_wcaf_create_order_commissions filter, to let dev skip commission handling
* Dev: added filters yith_wcaf_before_dashboard_section and yith_wcaf_after_dashboard_section
* Dev: added hooks after payment status change
* Dev: added yith_wcaf_get_current_affiliate_token function to get current affiliate token
* Dev: added yith_wcaf_get_current_affiliate function to get current affiliate object
* Dev: added yith_wcaf_get_current_affiliate_user function to get current affiliate user object

= 1.0.8 - Released on 08 June 2016 =

* Added: support WC 2.6 RC1
* Added: italian translation
* Added: spanish translation
* Added: attributes to affiliate_dashboard shortcode (will be passed to single section shortcode callbacks)
* Added: current_page attribute to all shortcodes that implements pagination
* Added: per page input in affiliate dashboard
* Added: style to #yith_wcaf_order_referral_commissions, #yith_wcaf_payment_affiliate, #yith_wcaf_commission_payments
* Tweak: added controls to show variation everywhere a variable product may be print
* Tweak: let rate set for Variable Product to apply to all variations
* Tweak: added filter yith_wcaf_is_hosted to filter check over submitted host / server name match in link_generator callback
* Fixed: Order links class/query vars

= 1.0.7 - Released on 05 May 2016 =

* Added: WordPress 4.5.x support
* Added: option to avoid referral cookie to be deleted after first customer checkout
* Added: new stat in Stas panel (sum of all affiliation earnings since program start)
* Fixed: removed useless library invocation
* Fixed: generate link shortcode (removed protocol before check for local url)

= 1.0.6 - Released on 05 April 2016 =

* Added: check over product existence on product table rates print method
* Added: capability for the admin to set commissions completed, without using any gateway
* Added: WooCommerce 2.5.x compatibility
* Added: WordPress 4.4.x compatibility
* Added: Users can now enter affiliate code from checkout page
* Added: Permanent token can now be locked, so to not be changed when a new affiliation link is visited
* Tweak: Performance improved with new plugin core 2.0
* Fixed: order awaiting payment handling
* Fixed: problems with views, due to new YITH menu name
* Fixed: generate link shortcode (url parsing improvements)
* Fixed: affiliate search method
* Fixed: default WC emails templates not found

= 1.0.5 - Released on 16 October 2015 =

* Added: Option to prevent referral cookie to expire
* Added: Option to prevent referral history cookie to expire
* Tweak: Increased expire seconds limit
* Tweak: Changed disabled attribute in readonly attribute for link-generator template
* Fixed: Corrected email templates
* Fixed: Option for auto-enable affiliates not showing on settings page
* Fixed: Commissions/Payment status now translatable from .po files
* Fixed: Fatal error occurring sometimes when using YOAST on backend

= 1.0.4 - Released on 13 August 2015 =

* Added: Compatibility with WC 2.4.2
* Tweak: Added missing text domain on link-generator template (thanks to dabodude)
* Tweak: Updated internal plugin-fw

= 1.0.3 - Released on 05 August 2015 =

* Initial release
