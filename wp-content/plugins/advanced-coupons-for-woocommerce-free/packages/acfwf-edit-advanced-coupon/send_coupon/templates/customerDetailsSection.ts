import labels from '../labels';
import formState from '../state';

declare var acfw_edit_coupon: any;

/**
 * Get the html markup for the customer details section of the modal.
 *
 * @since 4.5.3
 *
 * @returns {string} Section html markup.
 */
export default function customerDetailsSectionMarkup() {
  return `
  <div class="acfw-send-coupon-form-section ${
    formState.get('section') === 'customer_details' ? 'current' : ''
  }" data-section="customer_details">
    <div class="section-number">
      <span>2</span>
    </div>
    <div class="section-inner">
      <h3>${getSectionTitle(formState.get('option'))}</h3>
      <div class="section-content">
        ${getSectionContent(formState.get('option'))}
        <button type="button" class="button-primary acfw-next-section-btn" data-next_section="${
          'pushengage' === formState.get('option') ? 'message_details' : 'confirm_and_send'
        }">${labels.next}</button>
      </div>
    </div>
  </div>
  `;
}

/**
 * Get the title for the "details" section of the modal.
 *
 * @since 4.6.6
 *
 * @param {string} option The option to pass to the section renderer.
 * @returns {string} Section HTML markup.
 */
function getSectionTitle(option: string) {
  switch (option) {
    case 'email':
      return labels.email.customer_details;
    case 'pushengage':
      return labels.pushengage.customer_details;
  }
  return '';
}

/**
 * Get the HTML markup for the "details" section of the modal.
 *
 * @since 4.6.6
 *
 * @param {string} option The option to pass to the section renderer.
 * @returns {string} Section HTML markup.
 */
function getSectionContent(option: string) {
  switch (option) {
    case 'email':
      return `<div class="customer-details-form user-form show">
          <select data-placeholder="${labels.email.search}" name="acfw_send_coupon[user]" data-key="user" class="wc-customer-search" style="width:100%"></select>
        </div>
        <div class="customer-details-form guest-form">
          <input type="text" placeholder="${labels.email.name}" name="acfw_send_coupon[name]" data-key="name" />
          <input type="email" placeholder="${labels.email.email}" name="acfw_send_coupon[email]" data-key="email" />
          <label>
            <input type="checkbox" data-key="create_account" value="yes" /> 
            <span>${labels.email.create_new_user_account}</span>
        </div>`;
    case 'pushengage':
      return `
        <div class="customer-details-form segment-form show">
          <select id="acfw-send-coupon-to-segments" class="condition-value wc-enhanced-select" multiple data-placeholder="${
            labels.pushengage.segment_placeholder
          }" data-key="segments">
            ${segment_options()}
          </select>
        </div>
        
        <div class="customer-details-form subscriber-form">
          <select data-placeholder="${
            labels.pushengage.search
          }" name="acfw_send_coupon[subscribers]" data-key="subscribers" class="wc-enhanced-select" multiple style="width:100%"></select>
        </div>`;
  }
  return '';
}

/**
 * Get segment options markup.
 *
 * @since 4.6.6
 */
function segment_options(): string {
  const { segments }: { segments: { segment_id: number; segment_name: string }[] } =
    acfw_edit_coupon.send_coupon.pushengage;
  let markup: string = '';

  for (const segment of segments) {
    markup += `<option value="${segment.segment_id}-${segment.segment_name}">${segment.segment_name}</option>`;
  }

  markup += `<option value="create_new_segment">${labels.pushengage.create_new_segment}</option>`;

  return markup;
}
