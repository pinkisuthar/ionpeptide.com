declare var jQuery: any;

const $: any = jQuery;

/**
 * Add usage restrictions events script.
 *
 * @since 4.6.1
 */
export default function usage_restriction_events() {
  initExcludeWholesaleItemsRestrictionField();
}

/**
 * Init exclude wholesale items restriction field
 *
 * @since 4.6.1
 */
function initExcludeWholesaleItemsRestrictionField() {
  const $excludeWholesaleItemsRestrictionField = $('p._acfw_exclude_wholesale_items_field');

  // if exclude wholesale items restriction field is exist, then insert after exclude sale items field.
  if ($excludeWholesaleItemsRestrictionField) {
    $excludeWholesaleItemsRestrictionField.insertAfter('p.form-field.exclude_sale_items_field');
  }
}
