import labels from '../labels';
import formState from '../state';

/**
 * Get the html markup for the message details section of the modal.
 *
 * @since 4.6.6
 *
 * @returns {string} Section html markup.
 */
export default function messageDetailsSectionMarkup() {
  if ('pushengage' !== formState.get('option')) {
    return '';
  }

  return `
  <div class="acfw-send-coupon-form-section ${
    formState.get('section') === 'message_details' ? 'current' : ''
  }" data-section="message_details">
    <div class="section-number">
      <span>3</span>
    </div>
    <div class="section-inner">
      <h3>${getSectionTitle(formState.get('option'))}</h3>
      <div class="section-content">
        ${getSectionContent(formState.get('option'))}
        <button type="button" class="button-primary acfw-next-section-btn" data-next_section="confirm_and_send">${
          labels.next
        }</button>
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
    case 'pushengage':
      return labels.pushengage.message_details;
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
    case 'pushengage':
      return `<div class="message-form show">
          <div>
            <label>${labels.pushengage.title}</label>
            <input type="text" placeholder="${labels.pushengage.title_placeholder}" value="${formState.get(
        'title'
      )}" name="acfw_send_coupon[title]" data-key="title" />
          </div>
          <div>
            <label>${labels.pushengage.message}</label>
            <input type="text" placeholder="${labels.pushengage.message_placeholder}" value="${formState.get(
        'message'
      )}" name="acfw_send_coupon[message]" data-key="message" />
          </div>
          <div>
            <label>${labels.pushengage.url}</label>
            <input type="text" placeholder="${labels.pushengage.url_placeholder}" value="${formState.get(
        'url'
      )}" name="acfw_send_coupon[url]" data-key="url" />
          </div>
      </div>`;
  }
  return '';
}
