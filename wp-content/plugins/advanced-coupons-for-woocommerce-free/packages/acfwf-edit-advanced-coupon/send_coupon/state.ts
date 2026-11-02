import { validateEmail, validate_url } from '../helper';
import { reRenderSection } from './templates/modalMarkup';
import labels from './labels';

declare var jQuery: any;
declare var lodash: any;
declare var acfw_edit_coupon: any;

const $ = jQuery;
const formState = new Map();

/**
 * Initialize the send coupon form state.
 *
 * @since 4.5.3
 */
export function initFormState(section = 'send_coupon_to', option = 'email') {
  formState.set('section', section);
  formState.set('option', option);
  formState.set('send_to', option === 'pushengage' ? 'segments' : option === 'user' ? 'user' : '');

  // Email.
  formState.set('user_id', '0');
  formState.set('name', '');
  formState.set('email', '');
  formState.set('create_account', '');

  // PushEngage.
  formState.set('segment_ids', '0');
  formState.set('subscriber_ids', '0');
  formState.set('segments', labels.pushengage.segment_placeholder);
  formState.set('subscribers', '—');
  formState.set('title', acfw_edit_coupon.send_coupon.pushengage.default_content.title);
  formState.set('message', acfw_edit_coupon.send_coupon.pushengage.default_content.message);
  formState.set('url', acfw_edit_coupon.send_coupon.pushengage.default_content.url);
}

/**
 * Update state from input.
 * Triggered when an form input value is changed.
 *
 * @since 4.5.3
 */
export function updateStateFromInput() {
  // @ts-ignore
  const $input = $(this);
  const key = $input.data('key');
  let value = $input.val() ? $input.val().toString() : '';

  switch (key) {
    case 'option':
      formState.set('option', value);
      break;

    case 'user':
      const $selected = $input.find('option:selected');
      formState.set('user_id', value);
      formState.set('name', lodash.first($selected.text().split(' (')));
      formState.set('email', lodash.last($selected.text().split('&ndash; ')).replace(')', ''));
      break;

    case 'email':
      $input.removeClass('error');

      if (validateEmail(value) || value === '') {
        formState.set(key, value);
        formState.set('user_id', '0'); // clear the user_id value.
      } else {
        formState.set(key, ''); // clear email value.
        $input.addClass('error');
      }
      break;

    case 'create_account':
      value = $input.is(':checked') ? value : '';
      formState.set(key, value);
      break;

    case 'url':
      $input.removeClass('error');

      if (validate_url(value) || value === '') {
        formState.set(key, value);
      } else {
        formState.set(key, '');
        $input.addClass('error');
      }
      break;

    case 'segments':
      if (value === 'create_new_segment') {
        $input.val(null).trigger('change');
        window.location.href = acfw_edit_coupon.send_coupon.pushengage_segment_page;
      }

      if (!value) {
        value = `0-${labels.pushengage.segment_placeholder}`;
      }

      const pairs = value.split(',');

      const segment_ids: string[] = pairs.map((pair: string) => {
        const [id] = pair.split('-'); // Extract the id (before the first hyphen).
        return id;
      });

      const segments: string[] = pairs.map((pair: string) => {
        const name = pair.split('-').slice(1).join('-'); // Join the remaining parts as the name.
        return name;
      });

      formState.set('segment_ids', segment_ids);
      formState.set('segments', segments);
      break;

    case 'subscribers':
      const subscriberIds: string[] = value ? value.toString().split(',') : [];
      formState.set('subscriber_ids', subscriberIds);

      const $selectedSubscribers = $input.find('option:selected');
      const subscriberNames: string[] = Array.from($selectedSubscribers).map((el) => $(el).text());

      formState.set('subscribers', subscriberNames);
      formState.get('subscribers');
      break;

    default:
      formState.set(key, value);
  }

  reRenderSection('confirm_and_send');
}

export default formState;
