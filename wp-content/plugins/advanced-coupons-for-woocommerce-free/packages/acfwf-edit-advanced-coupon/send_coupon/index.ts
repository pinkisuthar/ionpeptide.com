import sendCouponButtonMarkup from './templates/sendCouponButton';
import modalMarkup from './templates/modalMarkup';
import formState, { initFormState, updateStateFromInput } from './state';
import labels from './labels';
import { reRenderSection } from './templates/modalMarkup';

declare var jQuery: any;
declare var acfw_edit_coupon: any;
declare var vex: any;
declare var wp: any;
declare var ajaxurl: string;

const $ = jQuery;

/**
 * Register events related to the send coupon feature.
 *
 * @since 4.5.3
 */
export default function sendCouponEvents() {
  appendSendCouponButton();
  initFormState();

  $('body').on('click', 'button.acfw-send-coupon-btn', showSendCouponModal);
  $('body').on(
    'change clearForm',
    ".acfw-send-coupon-form-section input[type='radio']:checked",
    toggleCustomerDetailsForm
  );
  $('body').on('click', '.acfw-send-coupon-form-section .acfw-next-section-btn', toggleFormNextSection);
  $('body').on(
    'click',
    '.acfw-send-coupon-form-section .section-number,.acfw-send-coupon-form-section h3',
    navigateToSection
  );
  $('body').on(
    'change',
    '.acfw-send-coupon-form-section input, .acfw-send-coupon-form-section select',
    updateStateFromInput
  );
  $('body').on('click', '.acfw-send-coupon-form-section .preview-email-link', previewCouponEmail);
  $('body').on('click', '.acfw-send-coupon-form-section .preview-pushengage-link', previewCouponPushengage);
  $('body').on('click', '.acfw-send-coupon-form-section .acfw-send-email-btn', sendCouponEmail);
  $('body').on('click', '.acfw-send-coupon-form-section .acfw-send-pushengage-btn', sendCouponPushengage);
  $('body').on('click', '#acfw-send-coupon .acfw-button-pushengage-install', installPushEngage);

  $('body').on('change', '#acfw-send-coupon #acfw-send-coupon-options', updateOptions);
  $('body').on('change', '#acfw-send-coupon #acfw-send-coupon-options', updateStateFromInput);

  // Show the send coupon modal when the "sendcoupon" parameter is set in the URL.
  if (window.location.href.includes('sendcoupon')) {
    showSendCouponModal();
  }
}

/**
 * Append the send coupon button next to the "Add coupon" button.
 *
 * @since 4.5.3
 */
function appendSendCouponButton() {
  $('.page-title-action').after(sendCouponButtonMarkup());
}

/**
 * Show the send coupon modal UI.
 *
 * @since 4.5.3
 */
function showSendCouponModal() {
  // display vex dialog.
  vex.dialog.open({
    unsafeMessage: modalMarkup(),
    className: 'vex-theme-plain acfw-send-coupon-modal',
    showCloseButton: true,
    buttons: {},
    afterOpen: () => $(document.body).trigger('wc-enhanced-select-init'),
  });
}

/**
 * Toggle the customer details form depending on the "Send to" option value selected.
 *
 * @since 4.5.3
 */
function toggleCustomerDetailsForm() {
  // @ts-ignore
  const $radio = $(this);
  const sendTo = $radio.val().toString();
  let formClassName = 'user' === sendTo ? '.user-form' : '.guest-form';
  if ('pushengage' === formState.get('option')) {
    formClassName = 'subscribers' === sendTo ? '.subscriber-form' : '.segment-form';
  }

  $('.acfw-send-coupon-form-section .customer-details-form')
    .removeClass('show')
    .find('input,select')
    .prop('disabled', true);
  $(`.acfw-send-coupon-form-section ${formClassName}`).addClass('show').find('input,select').prop('disabled', false);
}

/**
 * Navigate to the next section when the "Next" button is clicked.
 *
 * @since 4.5.3
 */
function toggleFormNextSection() {
  // @ts-ignore
  const $button = $(this);
  const sectionName = $button.data('next_section');

  $('.acfw-send-coupon-form-section').removeClass('current');
  $(`.acfw-send-coupon-form-section[data-section='${sectionName}']`).addClass('current');
  formState.set('section', sectionName);
}

/**
 * Navigate to a section when the section number or header text is clicked.
 *
 * @since 4.5.3
 */
function navigateToSection() {
  // @ts-ignore
  const $section = $(this).closest('.acfw-send-coupon-form-section');

  if (!$section.hasClass('current')) {
    $('.acfw-send-coupon-form-section').removeClass('current');
    $section.addClass('current');
  }
  formState.set('section', $section.data('section'));
}

/**
 * Preview the coupon email content in a new tab.
 *
 * @since 4.5.3
 */
function previewCouponEmail() {
  // @ts-ignore
  const $button = $(this);

  if ($button.hasClass('disabled')) {
    return;
  }

  const query = new URLSearchParams();

  query.append('action', 'acfw_advanced_coupon_preview_email');
  query.append('email_id', 'acfw_coupon_email');
  query.append('args[coupon_id]', $('#post_ID').val());
  query.append('args[name]', formState.get('name'));
  query.append('args[email]', formState.get('email'));
  query.append('args[user_id]', formState.get('user_id'));
  query.append('_wpnonce', acfw_edit_coupon.send_coupon.form_email_nonce);

  window.open(`${ajaxurl}?${query.toString()}`, '_blank');
}

/**
 * Preview the coupon pushengage content.
 *
 * @since 4.6.4
 */
function previewCouponPushengage() {
  // @ts-ignore
  const $button = $(this);

  if ($button.hasClass('disabled')) {
    return;
  }

  const storeName = acfw_edit_coupon.send_coupon.pushengage.storename;
  const couponCode = acfw_edit_coupon.send_coupon.pushengage.coupon_code;
  const url = formState.get('url');

  let title = formState.get('title'); // "You have received a coupon from {storename}"
  let message = formState.get('message'); // "Get a discount with your next order using the coupon {coupon_code}"

  // Replace placeholders.
  title = title.replace('{storename}', storeName);
  message = message.replace('{coupon_code}', couponCode);

  const notification = new Notification(title, {
    body: message,
  });

  notification.onclick = function (event) {
    event.preventDefault();
    window.open(url, '_blank');
  };
}

/**
 * Send the coupon email API request.
 *
 * @since 4.5.3
 */
function sendCouponEmail() {
  // @ts-ignore
  const $button = $(this);

  $button.prop('disabled', true);
  $('#acfw-send-coupon .request-message').html('').removeClass('success error');

  wp.apiFetch({
    path: '/coupons/v1/sendcoupon',
    method: 'POST',
    data: {
      coupon_id: $('#post_ID').val(),
      customer: parseInt(formState.get('user_id')) ? formState.get('user_id') : formState.get('email'),
      name: formState.get('name'),
      create_account: formState.get('create_account'),
    },
  })
    .then((res: any) => {
      clearForm();
      displayResponseMessage('success', res.message);
    })
    .catch((error: any) => displayResponseMessage('error', error.message));
}

/**
 * Send the coupon pushengage API request.
 *
 * @since 4.6.4
 */
function sendCouponPushengage() {
  // @ts-ignore
  const $button = $(this);

  $button.prop('disabled', true);
  $('#acfw-send-coupon .request-message').html('').removeClass('success error');

  wp.apiFetch({
    path: '/coupons/v1/sendcoupon/pushengage',
    method: 'POST',
    data: {
      coupon_id: $('#post_ID').val(),
      send_to: formState.get('send_to'),
      segments: formState.get('segments'),
      segment_ids: formState.get('segment_ids'),
      subscribers: formState.get('subscribers'),
      subscriber_ids: formState.get('subscriber_ids'),
      title: formState.get('title'),
      message: formState.get('message'),
      url: formState.get('url'),
    },
  })
    .then((res: any) => {
      clearForm('confirm_and_send', 'pushengage');
      displayResponseMessage('success', res.message);
    })
    .catch((error: any) => displayResponseMessage('error', error.message));
}

/**
 * Clear all form fields.
 *
 * @since 4.5.3
 */
function clearForm(state = 'confirm_and_send', option = 'email') {
  initFormState(state, option);
  $('#acfw-send-coupon')
    .find("select:not(#acfw-send-coupon-options),input[type='text'],input[type='email']")
    .val('')
    .trigger('change');
  $('#acfw-send-coupon').find("input[type='radio']").prop('checked', false);
  $('#acfw-send-coupon').find("label:first-child input[type='radio']").prop('checked', true).trigger('clearForm');
  $('#acfw-send-coupon').find("input[type='checkbox']").prop('checked', false);

  // Set to default content pushengage.
  $('#acfw-send-coupon')
    .find("input[name='acfw_send_coupon[title]']")
    .val(acfw_edit_coupon.send_coupon.pushengage.default_content.title)
    .trigger('change');
  $('#acfw-send-coupon')
    .find("input[name='acfw_send_coupon[url]']")
    .val(acfw_edit_coupon.send_coupon.pushengage.default_content.url)
    .trigger('change');
  $('#acfw-send-coupon')
    .find("input[name='acfw_send_coupon[message]']")
    .val(acfw_edit_coupon.send_coupon.pushengage.default_content.message)
    .trigger('change');
  $('#acfw-send-coupon').find("select[name='acfw_send_coupon[subscribers]']").val(null).trigger('change');
}

/**
 * Display form response message notice.
 *
 * @since 4.5.3
 *
 * @param {string} type Notice type.
 * @param {string} message Notice message.
 */
function displayResponseMessage(type: string, message: string) {
  $('#acfw-send-coupon .request-message').html(message).addClass(type);
}

/**
 * Updates the options for sending coupons based on the selected state.
 *
 * @since 4.6.6
 */
function updateOptions() {
  let state = 'send_coupon_to';

  $('.acfw-send-coupon-form-section').removeClass('current');
  $(`.acfw-send-coupon-form-section[data-section='${state}']`).addClass('current');

  let option = $('#acfw-send-coupon-options').val();
  clearForm(state, option);

  if (!acfw_edit_coupon.send_coupon.is_pushengage_plugin_active && 'pushengage' === option) {
    option = 'pushengage_not_installed';
    $('.acfw-send-coupon-form-sections').hide();
  } else if (
    acfw_edit_coupon.send_coupon.is_pushengage_plugin_active &&
    'pushengage' === option &&
    !acfw_edit_coupon.send_coupon.is_pushengage_site_connected
  ) {
    option = 'pushengage_site_not_connected';
    $('.acfw-send-coupon-form-sections').hide();
  } else if (
    acfw_edit_coupon.send_coupon.is_pushengage_plugin_active &&
    'pushengage' === option &&
    acfw_edit_coupon.send_coupon.is_pushengage_error
  ) {
    option = 'pushengage_error';
    $('.acfw-send-coupon-form-sections').hide();
  } else {
    $('.acfw-send-coupon-form-sections').show();
  }

  $('#acfw-send-coupon .description').html(labels.description[option]);

  formState.set('option', option);

  reRenderSection('send_coupon_to');
  reRenderSection('customer_details');
  if ('pushengage' === option) {
    reRenderSection('message_details');
  }
  reRenderSection('confirm_and_send');

  $(document.body).trigger('wc-enhanced-select-init');
  initSubscribers();
}

/**
 * Installs and activates the PushEngage plugin.
 *
 * @since 4.6.6
 */
function installPushEngage() {
  // @ts-ignore
  const $button = $(this);
  const $installingText = $(`<span class="installing-text">${labels.installing} ...</span>`);
  const $errorText = $(`<span class="error-text" style="color: red; display: none;"></span>`);

  const currentLocation = location.href;

  $button.prop('disabled', true).after($installingText).after($errorText);

  $.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
      action: 'acfw_install_activate_plugin',
      plugin_slug: 'pushengage',
      silent: true,
      nonce: acfw_edit_coupon.send_coupon.pushengage_download_nonce,
    },
  })
    .done((response: any) => {
      if (response.success) {
        // Ensure to not redirect on pushengage page.
        window.stop();
        setTimeout(() => {
          window.location.href = currentLocation;
        }, 400);
      } else {
        $errorText.text(response.data).show();
      }
    })
    .fail((error: any) => {
      $installingText.remove();
      const errorMessage = error.responseJSON?.data || 'An unexpected error occurred.';
      $errorText.text(errorMessage).show();
      $button.prop('disabled', false);
    });
}

/**
 * Initializes the Select2 dropdown for subscriber selection.
 *
 * @since 4.6.6
 */
function initSubscribers(): void {
  $('select.wc-enhanced-select[data-key="subscribers"]').select2({
    ajax: {
      url: ajaxurl,
      dataType: 'json',
      data: (params: { term: string }) => ({
        action: 'acfw_pushengage_subscribed_customer_search',
        search: params.term,
        nonce: acfw_edit_coupon.send_coupon.pushengage_subscriber_nonce,
      }),
      processResults: (data: { id: string; text: string }[]) => ({
        results: data,
      }),
    },
  });
}
