import { esc_attr } from '../../helper';
import upsell_template from './upsell';

declare var acfw_edit_coupon: any;

/**
 * Product brands condition type.
 *
 * @since 4.7.4
 */
export default function product_brands_template(data: any, is_deals = false) {
  const { brands, quantity, discount_type, discount_value } = data;
  const { bogo_form_fields, bogo_instructions } = acfw_edit_coupon;
  const { quantity: quantityLabel, brands: brandsLabel, price_discount: priceDiscountLabel, type_to_search } =
    bogo_form_fields;

  const priceType: string = discount_type ? data.discount_type : 'override';
  const priceCol: string = is_deals ? get_price_column(priceType, discount_value) : '';
  const priceTh: string = is_deals ? `<th class="price">${priceDiscountLabel}</th>` : '';
  const options: string = get_options(brands);
  const instructions: string = is_deals ? `${bogo_instructions.brands_get}` : bogo_instructions.brands_buy;

  const markup = `
      <p class="instructions">${instructions}</p>
      <table class="acfw-styled-table combined-objects-form"
          data-combined="${esc_attr(JSON.stringify(data))}"
          data-isdeals="${is_deals}"
          data-type="brand">
          <thead>
              <tr>
                  <th class="objects-list">${brandsLabel}</th>
                  <th class="quantity">${quantityLabel}</th>
                  ${priceTh}
              </tr>
          </thead>
          <tbody>
              <tr>
                  <td class="objects-list object">
                      <select class="product-brands-list wc-product-search"
                      data-placeholder="${type_to_search}"
                      data-action="acfw_search_product_brands"
                      data-exclude="" multiple>${options}</select>
                  </td>
                  <td class="quantity">
                      <input type="number" class="condition-quantity" value="${quantity}" min="1">
                  </td>
                  ${priceCol}
              </tr>
          </tbody>
      </table>
      ${upsell_template()}
    `;

  return markup;
}

/**
 * Get brand(s) price column.
 *
 * @since 4.7.4
 *
 * @param priceType
 * @param discount_value
 */
function get_price_column(priceType: string, discount_value: string = '0'): string {
  const { currency_symbol, discount_field_options } = acfw_edit_coupon;
  const { override, percent, fixed } = discount_field_options;

  return `
    <td class="price">
        <div>
        <select class="discount_type">
            <option value="override" ${
              priceType == 'override' ? 'selected' : ''
            }>${currency_symbol} : ${override}</option>
            <option value="percent" ${priceType == 'percent' ? 'selected' : ''}>% : ${percent}</option>
            <option value="fixed" ${priceType == 'fixed' ? 'selected' : ''}>-${currency_symbol} : ${fixed}</option>
        </select>
        <input type="text" class="discount_value short wc_input_price" value="${esc_attr(discount_value)}">
        </div>
    </td>
    `;
}

/**
 * Get brand(s) row options.
 *
 * @since 4.7.4
 *
 * @param brands
 */
function get_options(brands: any): string {
  if (typeof brands != 'object' || !brands.length) return '';

  let options = '';
  for (let b of brands) options += `<option value="${esc_attr(b.brand_id)}" selected>${esc_attr(b.label)}</option>`;

  return options;
}
