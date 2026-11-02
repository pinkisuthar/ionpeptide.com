import labels from '../labels';
import formState from '../state';

/**
 * Get the html markup for the "send coupon to" section of the modal.
 *
 * @since 4.5.3
 *
 * @returns {string} Section html markup.
 */
export default function sendCouponToSectionMarkup() {
  return `
  <div class="acfw-send-coupon-form-section ${
    formState.get('section') === 'send_coupon_to' ? 'current' : ''
  }" data-section="send_coupon_to">
    <div class="section-number">
      <span>1</span>
    </div>
    <div class="section-inner">
      <h3>${labels.send_coupon_to}</h3>
      <div class="section-content">
        ${getSectionContent(formState.get('option'))}
        <button type="button" class="button-primary acfw-next-section-btn" style="margin-top: 10px" data-next_section="customer_details">${
          labels.next
        }</button>
      </div>
    </div>
  </div>
  `;
}

/**
 * Get the HTML markup for the "send coupon to" section of the modal.
 *
 * @since 4.6.4
 *
 * @param {string} option The option to pass to the section renderer.
 * @returns {string} Section HTML markup.
 */
function getSectionContent(option: string) {
  switch (option) {
    case 'email':
      return `<label>
          <input type="radio" name="acfw_send_coupon[to]" value="user" data-key="send_to" checked />
           <span>${labels.email.existing_customer_account}<span>
        </label>
        <label>
          <input type="radio" name="acfw_send_coupon[to]" value="email" data-key="send_to" />
           <span>${labels.email.new_customer}<span>
        </label>`;
    case 'pushengage':
      return `<label>
        <input type="radio" name="acfw_send_coupon[to]" value="segments" data-key="send_to" checked />
        <span>${labels.pushengage.segments}<span>
      </label>
      <label>
        <input type="radio" name="acfw_send_coupon[to]" value="subscribers" data-key="send_to" />
        <span>${labels.pushengage.subscribers}<span>
      </label>`;
  }
  return '';
}
