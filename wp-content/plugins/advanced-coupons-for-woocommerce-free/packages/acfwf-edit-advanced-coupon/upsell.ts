declare var jQuery: any;
declare var acfw_edit_coupon: any;
declare var vex: any;

const $: any = jQuery;
let exludeCouponShown = false;

/**
 * Add upsell events script.
 *
 * @since 1.0.0
 */
export default function upsell_events() {
  $('#usage_limit_coupon_data').on('change', '#reset_usage_limit_period', upsell_advance_usage_limits);

  $(
    `#usage_restriction_coupon_data .acfw_exclude_coupons_field,
    #usage_restriction_coupon_data .acfw_allowed_customers_field,
    #usage_restriction_coupon_data .acfw_allowed_coupons_field,
    #usage_restriction_coupon_data .acfw_product_attributes_field,
    #usage_restriction_coupon_data .acfw_exclude_product_attributes_field,
    #usage_restriction_coupon_data .acfw_disallowed_emails_field,
    #usage_restriction_coupon_data .acfw_disallowed_customers_field`
  ).on('click change focus', 'input,select', upsell_exclude_coupons_restriction);

  $('#acfw-auto-apply-coupon').on('change', '#acfw_auto_apply_coupon_field', upsell_auto_apply);
  $('#acfw-virtual-coupon').on('change', '#acfw_enable_virtual_coupons', upsell_virtual_coupons);
  $('#acfw_url_coupon').on('change', '#acfw_defer_apply_url_coupon', upsell_defer_apply_url_coupon);
  $('#acfw_cart_conditions').on('change', '.condition-types', cart_condition_select_notice);
  $('#woocommerce-coupon-data').on('change acfw_load', '#discount_type', hideGeneralUpsellOnBogo);
  $('#woocommerce-coupon-data').on('change acfw_load', '#discount_type', displayCashbackUpsellModal);
  $('#woocommerce-coupon-data').on('focusin', '#discount_type', cacheCurrentDiscountType);

  $('#acfw_bogo_deals').on('change acfw_load', 'select#bogo-deals-type', toggle_bogo_auto_add_products_field);
  $('#acfw_bogo_deals').on('change', "input[name='acfw_bogo_auto_add_products']", upsell_bogo_auto_add_get_products);
  $('#acfw_bogo_deals').on('change acfw_load', 'select#bogo-deals-type', toggle_bogo_discount_order);
  $('#acfw_bogo_deals').on('change', 'select[name="acfw_bogo_discount_order"]', upsell_bogo_discount_order);

  $('#acfw_scheduler').on(
    'change',
    ".acfw-daytime-schedules-section .acfw-section-toggle input[type='checkbox']",
    upsell_day_time_schedules
  );

  $('body').on('change acfw_load', 'input#individual_use', toggleAllowedCouponsField);

  $('#woocommerce-coupon-data #discount_type').trigger('acfw_load');

  initExcludeCouponField();
  initAllowedCouponsField();
  initProductAttributeFields();
}

export function affiliate_discount_upsell_events() {
  $('#woocommerce-coupon-data').on('click change focus', '#acfw_affiliate_discount', upsell_affiliate_discount);
}

function initExcludeCouponField() {
  const $excludeField = $('p.acfw_exclude_coupons_field');

  $excludeField.insertAfter('p.form-field.individual_use_field');
}

/**
 * Usage limits upsell vex dialog.
 *
 * @since 1.1
 */
function upsell_advance_usage_limits() {
  if (!acfw_edit_coupon.upsell) return;
  const { usage_limits } = acfw_edit_coupon.upsell;

  vex.dialog.alert({
    unsafeMessage: `<div class="upsell-alert usage-limits">${usage_limits}</div>`,
  });
  // @ts-ignore
  $(this).val('none');
}

/**
 * Usage restriction for exclude coupons upsell vex dialog.
 *
 * @since 1.1
 */
function upsell_exclude_coupons_restriction() {
  // prevent duplicate dialogs showing up.
  if (exludeCouponShown) return;
  exludeCouponShown = true;

  if (!acfw_edit_coupon.upsell) return;
  const { usage_restriction } = acfw_edit_coupon.upsell;

  vex.dialog.alert({
    unsafeMessage: `<div class="upsell-alert exclude-coupon">${usage_restriction}</div>`,
    afterClose: () => (exludeCouponShown = false),
  });
  // @ts-ignore
  $(this).val('').blur();
}

/**
 * Auto apply upsell vex dialog.
 *
 * @since 1.1
 */
function upsell_auto_apply() {
  if (!acfw_edit_coupon.upsell) return;
  const { auto_apply } = acfw_edit_coupon.upsell;

  vex.dialog.alert({
    unsafeMessage: `<div class="upsell-alert auto-apply">${auto_apply}</div>`,
  });
  // @ts-ignore
  $(this).prop('checked', false);
}

/**
 * Virtual coupons upsell vex dialog.
 *
 * @since 4.3
 */
function upsell_virtual_coupons() {
  if (!acfw_edit_coupon.upsell) return;
  const { virtual_coupons } = acfw_edit_coupon.upsell;

  vex.dialog.alert({
    unsafeMessage: `<div class="upsell-alert auto-apply">${virtual_coupons}</div>`,
  });
  // @ts-ignore
  $(this).prop('checked', false);
}

/**
 * Defer apply URL coupon upsell vex dialog.
 *
 * @since 4.6.9
 */
function upsell_defer_apply_url_coupon() {
  if (!acfw_edit_coupon.upsell) return;
  const { defer_apply_url_coupon } = acfw_edit_coupon.upsell;

  vex.dialog.alert({
    unsafeMessage: `<div class="upsell-alert defer-apply">${defer_apply_url_coupon}</div>`,
  });
  // @ts-ignore
  $(this).prop('checked', false);
}

/**
 * Affiliate discount upsell vex dialog for AffiliateWP integration.
 *
 * @since 4.7.0
 */
function upsell_affiliate_discount(e: JQuery.ClickEvent) {
  // Use separate affiliate_discount_upsell data, not the standard upsell data
  const { affiliate_discount_upsell } = acfw_edit_coupon;

  if (!affiliate_discount_upsell) {
    return;
  }

  vex.dialog.alert({
    unsafeMessage: `<div class="upsell-alert affiliate-discount">${affiliate_discount_upsell}</div>`,
  });
}

/**
 * Display did you know notice below cart condition selector when premium option is selected.
 *
 * @since 1.6
 */
function cart_condition_select_notice() {
  // @ts-ignore
  const $select = $(this);
  const $moduleBlock = $select.closest('#acfw_cart_conditions');
  const $formBlock = $select.closest('.add-condition-form');
  const premiumConditions: string[] = $moduleBlock.data('premium-conditions');
  const $noticeHolder = $moduleBlock.find('.acfw-dyk-notice-holder');

  let $noticeBlock = $formBlock.find('.acfw-dyk-notice');

  if (!$noticeBlock.length) {
    $formBlock.append($noticeHolder.html());
    $noticeBlock = $formBlock.find('.acfw-dyk-notice');
  }

  if ($.inArray($select.val(), premiumConditions) >= 0) {
    $noticeBlock.show();
  } else {
    $noticeBlock.hide();
  }
}

/**
 * Hide did you know notice upsell under general tab when BOGO discount type is selected.
 *
 * @since 3.0
 */
function hideGeneralUpsellOnBogo() {
  // @ts-ignore
  const type = $(this).val();

  if ('acfw_bogo' === type) {
    $('p.acfw-dyk-notice-general').hide();
  } else {
    $('p.acfw-dyk-notice-general').show();
  }
}

/**
 * Cache current discount type.
 *
 * @since 4.5.6
 */
function cacheCurrentDiscountType() {
  // @ts-ignore
  $(this).data('cachedval', $(this).val());
}

/**
 * Display cashback upsell modal.
 *
 * @since 4.5.6
 */
function displayCashbackUpsellModal() {
  // @ts-ignore
  const type = $(this).val();
  if (!acfw_edit_coupon.upsell) return;
  const { cashback_coupon } = acfw_edit_coupon.upsell;

  if ('acfw_percentage_cashback' === type || 'acfw_fixed_cashback' === type) {
    vex.dialog.alert({
      unsafeMessage: `<div class="upsell-alert usage-limits">${cashback_coupon}</div>`,
    });
    // @ts-ignore
    $(this).val($(this).data('cachedval') ?? 'percent');
  }
}

/**
 * Toggle BOGO auto add products field.
 *
 * @since 4.1
 */
function toggle_bogo_auto_add_products_field() {
  // @ts-ignore
  const $this = $(this);
  const $module = $this.closest('#acfw_bogo_deals');
  const $field = $module.find('.bogo-auto-add-products-field');
  const $input = $field.find("input[type='checkbox']");
  const applyType = $this.val();

  if (applyType === 'specific-products') {
    $input.prop('disabled', false);
    $field.addClass('show');
  } else {
    $input.prop('disabled', false);
    $field.removeClass('show');
  }
}

/**
 * Upsell BOGO auto add get products feature.
 *
 * @since 4.1
 */
function upsell_bogo_auto_add_get_products() {
  // @ts-ignore
  const $this = $(this);

  $this.prop('checked', false);

  if (!acfw_edit_coupon.upsell) return;
  const { bogo_auto_add_get_products } = acfw_edit_coupon.upsell;

  vex.dialog.alert({
    unsafeMessage: `<div class="upsell-alert usage-limits">${bogo_auto_add_get_products}</div>`,
  });
  // @ts-ignore
  $(this).val('none');
}

/**
 * Toggle BOGO discount order feature.
 *
 * @since 4.6.9
 */
function toggle_bogo_discount_order() {
  // @ts-ignore
  const $this = $(this);
  const $module = $this.closest('#acfw_bogo_deals');
  const $field = $module.find('.bogo-discount-order-field');
  const $input = $field.find('select');
  const applyType = $this.val();

  if (applyType === 'specific-products') {
    $input.prop('disabled', false);
    $field.addClass('show');
  } else {
    $input.prop('disabled', false);
    $field.removeClass('show');
  }
}

/**
 * Upsell BOGO auto add get products feature.
 *
 * @since 4.6.9
 */
function upsell_bogo_discount_order() {
  // @ts-ignore
  const $this = $(this);

  $this.val('none');

  if (!acfw_edit_coupon.upsell) return;
  const { bogo_discount_order } = acfw_edit_coupon.upsell;

  vex.dialog.alert({
    unsafeMessage: `<div class="upsell-alert usage-limits">${bogo_discount_order}</div>`,
  });
  // @ts-ignore
  $(this).val('none');
}

/**
 * Display upsell for day time schedules when section checkbox is checked.
 *
 * @since 4.5
 */
function upsell_day_time_schedules() {
  // @ts-ignore
  const $checkbox = $(this);

  if (!acfw_edit_coupon.upsell) return;
  const { day_time_schedules } = acfw_edit_coupon.upsell;

  vex.dialog.alert({
    unsafeMessage: `<div class="upsell-alert day-time-schedules">${day_time_schedules}</div>`,
  });

  $checkbox.prop('checked', false).trigger('acfw_load');
}

/**
 * Display upsell for allowed coupons.
 *
 * @since 4.6.1
 */
function initAllowedCouponsField() {
  const $allowedCouponsField = $('p.acfw_allowed_coupons_field');

  $allowedCouponsField.insertAfter('p.form-field.individual_use_field');

  $allowedCouponsField.addClass('hidden');
}

/**
 * Handling toggle for allowed coupons field.
 *
 * @since 4.6.1
 */
function toggleAllowedCouponsField() {
  // @ts-ignore
  const $individualUse = $(this);
  const $wrapper = $('.acfw_allowed_coupons_field');
  const $field = $wrapper.find('select');

  if ($individualUse.is(':checked')) {
    $field.prop('disabled', false);
    $wrapper.show();
  } else {
    $field.prop('disabled', true);
    $wrapper.hide();
  }
}

/**
 * Display upsell for product attributes.
 *
 * @since 4.6.4
 */
function initProductAttributeFields() {
  const $productAttributesField = jQuery('p.acfw_product_attributes_field');
  const $excludeProductAttributesField = jQuery('p.acfw_exclude_product_attributes_field');

  const $parentOfExcludeCategoriesLabel = jQuery('label[for="exclude_product_categories"]').closest(
    'div.options_group'
  );

  const $wrapper = jQuery('<div class="options_group"></div>');

  $wrapper.append($productAttributesField);
  $wrapper.append($excludeProductAttributesField);

  $wrapper.insertAfter($parentOfExcludeCategoriesLabel);
}
