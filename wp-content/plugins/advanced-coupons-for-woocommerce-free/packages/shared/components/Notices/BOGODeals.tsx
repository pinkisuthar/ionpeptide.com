// Import library.
import { dispatch, select, useSelect, WC_STORE_CART } from '../../library/StoreAPI';
import { CORE_NOTICE, ID_NOTICE } from '../../../shared/wc-block/wc_block_notice';
import { decodeHTML } from '../../library/html';
import { WC_CART } from '../../../shared/library/Context';

// Global Variables.
declare var acfwfObj: any;

/**
 * BOGO Deals Notice function.
 *
 * @since 4.6.0
 */
export default function () {
  const cartData = useSelect((select: any) => {
    return select(WC_STORE_CART).getCartData();
  });

  const { dummyUpdateCart } = acfwfObj.wc;
  const isApplyingCoupon = select(WC_STORE_CART).isApplyingCoupon();
  const isRemovingCoupon = select(WC_STORE_CART).isRemovingCoupon();

  // We must trigger dummyUpdateCart after coupon being applied / removed.
  // So we can get the latest bogo deals data from Store API.
  if (isApplyingCoupon || isRemovingCoupon) {
    dummyUpdateCart();
  }

  const { acfwf_block } = cartData.extensions;
  // Wait until the data is loaded, this is due to Store API is asynchronous.
  if (!acfwf_block || !acfwf_block.bogo_deals) return null;

  const bogo_deals = acfwf_block.bogo_deals;

  if (bogo_deals) {
    bogo_deals.forEach((notice: string) => {
      dispatch(CORE_NOTICE).createNotice('info', decodeHTML(notice), {
        context: WC_CART,
        id: ID_NOTICE.ACFWF_NOTICE_BOGO,
      });
    });
  }
}
