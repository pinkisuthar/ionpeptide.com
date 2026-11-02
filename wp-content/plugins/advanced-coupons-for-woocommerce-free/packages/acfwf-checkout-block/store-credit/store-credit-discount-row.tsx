// Import Library.
import config from '../config.json';
import { getSetting } from '../../shared/library/BlockIntegration';
import { store } from '../../shared/library/StoreAPI';

// Global variables.
declare var acfwfObj: any;

/**
 * Store Credit Discount Row component.
 *
 * @since 4.5.9
 *
 * @return {JSX.Element} Accordion component.
 * */
export default function () {
  const { OrderSummarySubtotalBlock } = acfwfObj.components;

  // Get the settings from the integration interface.
  const { store_credits } = getSetting(config.integration);
  const { pay_with_store_credits_text } = store_credits;

  // Get data from Store API.
  const { acfwf_block } = store.getCartData().extensions;

  // Return component if there's a store credit discount and store credits are still allowed for the cart.
  // `is_allow_store_credits` is read live so the row hides/shows on AJAX cart updates, matching the
  // server-side classic checkout behaviour (display_store_credits_discount_row gates on the same value).
  if (
    acfwf_block &&
    acfwf_block.store_credits &&
    acfwf_block.store_credits.amount &&
    acfwf_block.store_credits.is_allow_store_credits
  ) {
    const { amount_text } = acfwf_block.store_credits;
    return (
      <OrderSummarySubtotalBlock label={pay_with_store_credits_text} value={amount_text}></OrderSummarySubtotalBlock>
    );
  }

  // Return nothing.
  return null;
}
