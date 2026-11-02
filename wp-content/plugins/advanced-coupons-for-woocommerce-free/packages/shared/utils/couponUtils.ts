// Import library.
import { store } from '../library/StoreAPI';

/**
 * Discount type identifier for BOGO coupons.
 *
 * @since 4.7.3
 */
export const ACFW_BOGO_DISCOUNT_TYPE = 'acfw_bogo';

/**
 * Check if there's a BOGO coupon.
 *
 * @since 4.5.8
 * @return List of BOGO coupons.
 * */
export const hasBOGOCoupon = () => {
  const BOGOCoupons = store.getCartData().coupons.filter((coupon: any) => coupon.discount_type === ACFW_BOGO_DISCOUNT_TYPE);
  return !!BOGOCoupons.length;
};
