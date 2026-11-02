import labels from '../labels';
import formState from '../state';
import sendCouponToSectionMarkup from './sendCouponToSection';
import customerDetailsSectionMarkup from './customerDetailsSection';
import messageDetailsSectionMarkup from './messageDetailsSection';
import confirmAndSendSection from './confirmAndSendSection';

declare var jQuery: any;

const $ = jQuery;

/**
 * Get the html markup of the send coupon modal UI.
 *
 * @since 4.5.3
 *
 * @returns {string} Modal html markup.
 */
export default function modalMarkup() {
  return `
    <div id="acfw-send-coupon">
      <h2>${labels.title}</h2>

      <div class="option-sections">
        <label>${labels.select_options.label}</label>
        <select id="acfw-send-coupon-options" data-key="option">
          ${labels.select_options.options
            .map(
              (option: { value: string; label: string }) => `<option value='${option.value}'>${option.label}</option>`
            )
            .join('')}
        </select>
      </div>

      <div class="description-sections">
        <p class="description">${labels.description.email}</p>
      </div>

      <div class="acfw-send-coupon-form-sections">
        ${getSectionRenderer('send_coupon_to')()}
        ${getSectionRenderer('customer_details')()}
        ${getSectionRenderer('message_details')()}
        ${getSectionRenderer('confirm_and_send')()}
      </div>
    </div>
  `;
}

/**
 * Rerender a given section in the modal.
 *
 * @since 4.5.3
 *
 * @param {string} section The section to rerender.
 */
export function reRenderSection(section: string) {
  const $container = $('.acfw-send-coupon-form-sections');
  const $section = $container.find(`[data-section='${section}']`);
  const newSection = getSectionRenderer(section)();

  // Remove message_details if formState.get('option') is not 'pushengage'
  if (formState.get('option') !== 'pushengage') {
    $container.find(`[data-section='message_details']`).remove();
  }

  // Find the "customer_details" section
  const $customerDetails = $container.find(`[data-section='customer_details']`);

  // If the section exists, replace it; otherwise, insert it after customer_details
  if ($section.length) {
    $section.replaceWith(newSection);
  } else if ($customerDetails.length) {
    $customerDetails.after(newSection);
  } else {
    $container.append(newSection);
  }
}

/**
 * Returns the renderer callback for a given section.
 *
 * @since 4.5.3
 *
 * @param {string} section Section id.
 * @returns {function} Section renderer callback.
 */
function getSectionRenderer(section: string) {
  switch (section) {
    case 'send_coupon_to':
      return sendCouponToSectionMarkup;
    case 'customer_details':
      return customerDetailsSectionMarkup;
    case 'message_details':
      return messageDetailsSectionMarkup;
    case 'confirm_and_send':
      return confirmAndSendSection;
  }

  return () => {};
}
