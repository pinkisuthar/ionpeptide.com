// Global variables.
declare var wp: any;
declare var wc: any;

// Helper functions to safely access globals with lazy evaluation
const getWpData = () => {
  if (typeof wp === 'undefined' || !wp?.data) {
    throw new Error('wp.data is not available. Make sure WordPress is loaded.');
  }
  return wp.data;
};

const getWcBlocksData = () => {
  if (typeof wc === 'undefined' || !wc?.wcBlocksData) {
    throw new Error('wc.wcBlocksData is not available. Make sure WooCommerce Blocks is loaded.');
  }
  return wc.wcBlocksData;
};

// WooCommerce Blocks Data Store.
// Use wrapper functions to prevent errors when globals are not yet available at module load time
export const subscribe = (...args: any[]) => getWpData().subscribe(...args);
export const select = (...args: any[]) => getWpData().select(...args);
export const dispatch = (...args: any[]) => getWpData().dispatch(...args);
export const useSelect = (...args: any[]) => getWpData().useSelect(...args);
export const useDispatch = (...args: any[]) => getWpData().useDispatch(...args);

// WooCommerce Blocks Data Validation.
// Lazy-load VALIDATION_STORE_KEY to prevent errors when WooCommerce Blocks isn't loaded yet
export const getValidationStoreKey = () => getWcBlocksData().VALIDATION_STORE_KEY;

// Export VALIDATION_STORE_KEY with safe access - only access when actually used
// Use optional chaining to prevent module load errors
export const VALIDATION_STORE_KEY = (typeof wc !== 'undefined' && wc?.wcBlocksData?.VALIDATION_STORE_KEY) 
  ? wc.wcBlocksData.VALIDATION_STORE_KEY 
  : undefined as any;

export const WC_STORE_CART = 'wc/store/cart';

/**
 * Get the store.
 * - To learn more you can go to : https://github.com/woocommerce/woocommerce-blocks/blob/trunk/docs/third-party-developers/extensibility/data-store/cart.md
 *
 * @since 1.8.6
 * */
export const store = (typeof wp !== 'undefined' && wp?.data?.select) 
  ? wp.data.select('wc/store/cart')
  : undefined as any;
