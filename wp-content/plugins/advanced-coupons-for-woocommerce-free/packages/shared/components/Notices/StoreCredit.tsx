// Import library.
import config from '../../../acfwf-checkout-block/config.json';
import { getSetting } from '../../../shared/library/BlockIntegration';
import { store, dispatch, useSelect, WC_STORE_CART } from '../../library/StoreAPI';
import { CORE_NOTICE, ID_NOTICE } from '../../../shared/wc-block/wc_block_notice';
import { WC_CHECKOUT } from '../../../shared/library/Context';

const $ = jQuery;

/**
 * Selector for the reapply store credit discount link.
 *
 * WooCommerce sanitizes a block notice against a fixed allow-list that keeps "href"
 * and drops "class", so the link can only be matched on its "href" fragment here.
 * The notice banner around it survives sanitization, so scope the match to that and
 * a stray anchor carrying the same fragment elsewhere on the page cannot fire this.
 *
 * @since 4.7.6
 */
const REAPPLY_LINK_SELECTOR = '.wc-block-components-notice-banner a[href="#acfw-reapply-sc-discount"]';

/**
 * Show store credit notice.
 *
 * @since 4.6.0
 * @param {string} notice_store_credits_text - Notice store credits text.
 */
const showStoreCreditNotice = (notice_store_credits_text: string) => {
  // Bind on the document, because the notice renders after this call. Rebind from
  // scratch every time, so a repeated notice cannot stack duplicate handlers.
  $(document).off('click', REAPPLY_LINK_SELECTOR, reapplyStoreCreditsLink);
  $(document).on('click', REAPPLY_LINK_SELECTOR, reapplyStoreCreditsLink);

  // Debounce event
  setTimeout(function () {
    dispatch(CORE_NOTICE).createNotice('error', notice_store_credits_text, {
      context: WC_CHECKOUT,
      id: ID_NOTICE.ACFWF_NOTICE_STORE_CREDIT,
    });
  }, 400);
};

/**
 * Reapply store credits link and perform auto-scroll and focus.
 *
 * @since 4.6.0
 * @param {JQuery.ClickEvent} event - The click event on the reapply link.
 */
const reapplyStoreCreditsLink = (event: JQuery.ClickEvent) => {
  event.preventDefault();

  const storeCreditsAccordion = $('.acfw-checkout-ui-block');
  const storeCreditsBlock = storeCreditsAccordion.find('.acfw-store-credits-checkout-ui');
  const accordion = storeCreditsAccordion.find('h3');

  if (!storeCreditsBlock.hasClass('show')) {
    accordion.trigger('click');
  }

  // Debounce event
  setTimeout(function () {
    window.scroll({
      top: storeCreditsAccordion?.offset()?.top,
      behavior: 'smooth',
    });

    const inputToFocus = storeCreditsAccordion.find('input');
    inputToFocus.trigger('focus');
  }, 400);
};

/**
 * Store Credit Notice function.
 *
 * @since 4.6.0
 */
export default function () {
  const getHasCalculatedShipping = useSelect((select: any) => {
    return select(WC_STORE_CART).getHasCalculatedShipping();
  });

  // Get the settings from the integration interface.
  const { store_credits } = getSetting(config.integration);

  if (!store_credits) return null;

  const { apply_type, notice_store_credits_text } = store_credits;

  // Get data from Store API.
  const { acfwf_block } = store.getCartData().extensions;

  // If there's a store credit applied and have notice error
  if ('coupon' !== apply_type && getHasCalculatedShipping && acfwf_block.store_credits.notice) {
    showStoreCreditNotice(notice_store_credits_text);
  }
}
