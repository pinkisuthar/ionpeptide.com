// Import scripts.
import initACFWFShared from '../shared';
import registerCouponSummaryPlugin from '../shared/plugins/CouponSummaryPlugin';
import registeNoticesPlugin from '../shared/plugins/NoticesPlugin';
import { subscribe, select, WC_STORE_CART } from '../shared/library/StoreAPI';
import { ACFW_BOGO_DISCOUNT_TYPE } from '../shared/utils/couponUtils';

// Import CSS.
import '../shared/wc-block/index.scss';

// Global Variables.
declare var acfwfObj: any;
declare var wp: any;

// WooCommerce Blocks Hooks.
const { addAction } = wp.hooks;

// Initiate scripts.
initACFWFShared(); // Shared Components.
registeNoticesPlugin();
registerCouponSummaryPlugin();

/**
 * BOGO Coupon - Dummy Update Cart
 * - This is a dummy update cart to trigger the update cart action.
 * - This requires timeout because the cart is not yet updated when this action is fired.
 *
 * @since 4.5.8
 * */
const BOGODummyUpdateCart = () => {
  const { dummyUpdateCart } = acfwfObj.wc;
  const { debounce, hasBOGOCoupon } = acfwfObj.utils;
  if (hasBOGOCoupon()) {
    debounce(() => {
      dummyUpdateCart();
    }, 750)();
  }
};

/**
 * Handle if item quantity is changed.
 * Code sample can be found here :
 * - https://github.com/woocommerce/woocommerce-blocks/blob/trunk/docs/internal-developers/blocks/feature-flags-and-experimental-interfaces.md
 *
 * The hook is still experimental and might change in the future.
 *
 * @since 4.5.8
 * */
addAction('experimental__woocommerce_blocks-cart-set-item-quantity', 'acfwf', ({ product = {}, quantity = 1 }) =>
  BOGODummyUpdateCart()
);

/**
 * Handle if item is removed from cart.
 * Code sample can be found here :
 * - https://github.com/woocommerce/woocommerce-blocks/blob/trunk/docs/internal-developers/blocks/feature-flags-and-experimental-interfaces.md
 * - https://github.com/woocommerce/woocommerce-blocks/issues/9885
 *
 * The hook is still experimental and might change in the future.
 *
 * @since 4.5.8
 * */
addAction('experimental__woocommerce_blocks-cart-remove-item', 'acfwf', ({ product = {}, quantity = 1 }) =>
  BOGODummyUpdateCart()
);

/**
 * Watch for BOGO coupons being newly added to the cart store.
 *
 * When a BOGO coupon is applied, the free item is added server-side via WC()->cart->add_to_cart()
 * inside implement_bogo_deals (Same Product) or auto_add_deal_products_to_cart (Specific Products
 * with auto-add enabled). This server-side change triggers a second cart recalculation that the
 * Block Cart is unaware of — the quantity-change hooks used by BOGODummyUpdateCart only fire on
 * manual UI interactions, not on server-initiated adds.
 *
 * By subscribing to cart store changes and detecting a newly-added BOGO coupon code, we trigger
 * BOGODummyUpdateCart() to force the second recalculation, so the correct discount row and
 * estimated total appear immediately without requiring a page refresh.
 *
 * @since 4.7.3
 */
let previousBOGOCodes: string[] = [];

subscribe(() => {
  const cartStore = select(WC_STORE_CART);
  const coupons: { code: string; discount_type: string }[] = cartStore?.getCartData?.()?.coupons ?? [];
  const currentBOGOCodes = coupons
    .filter((coupon) => coupon.discount_type === ACFW_BOGO_DISCOUNT_TYPE)
    .map((coupon) => coupon.code);

  const newBOGOAdded = currentBOGOCodes.some((code: string) => !previousBOGOCodes.includes(code));
  previousBOGOCodes = currentBOGOCodes;

  if (newBOGOAdded) {
    BOGODummyUpdateCart();
  }
}, WC_STORE_CART);
