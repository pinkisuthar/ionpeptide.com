import { debounce } from '../library/utility';
import { hasBOGOCoupon } from './couponUtils';
import { sanitizeHtml } from '../helpers/sanitize';

// Export default
export default {
  debounce,
  hasBOGOCoupon,
  sanitizeHtml,
};

// Named exports for easier importing
export { sanitizeHtml };
