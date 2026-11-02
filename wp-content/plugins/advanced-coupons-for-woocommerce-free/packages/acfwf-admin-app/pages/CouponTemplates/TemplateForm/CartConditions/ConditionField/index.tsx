// #region [Imports] ===================================================================================================
// #endregion [Imports]

// Libraries
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';

// Types
import { ICartConditionField } from '../../../../../types/couponTemplates';

// Components
import ConditionLogic from '../ConditionLogic';
import CartQuantity, { cartQuantityDataValidator } from './CartQuantity';
import CartSubtotal, { cartSubtotalDataValidator } from './CartSubtotal';
import CartWeight, { cartWeightDataValidator } from './CartWeight';
import CouponsAppliedInCart, { couponsInCartDataValidator } from './CouponsAppliedInCart';
import ProductQuantitiesExistsInCart, { productQuantitiesDataValidator } from './ProductQuantitiesExistsInCart';
import ProductStockAvailabilityExistsInCart, {
  productStockDataValidator,
} from './ProductStockAvailabilityExistsInCart';
import HasOrderedProductsBefore, { hasOrderedProductsDataValidator } from './HasOrderedProductsBefore';
import ProductCategoriesExistsInCart, { productCategoriesExistsDataValidator } from './ProductCategoriesExistsInCart';
import CustomTaxonomyExistsInCart from './CustomTaxonomyExistsInCart';
import HasOrderedProductCategoriesBefore, {
  hasOrderedProductCategoriesBeforeDataValidator,
} from './HasOrderedProductCategoriesBefore';
import TotalCustomerSpendOnProductCategory, {
  totalCustomerSpendProductCatDataValidator,
} from './TotalCustomerSpendOnProductCategory';
import CustomerLoggedInStatus, { loginStatusDataValidator } from './CustomerLoggedInStatus';
import CustomerUserRole, { customerUserRoleDataValidator } from './CustomerUserRole';
import WithinHours, { withinHoursDataValidator } from './WithinHours';
import TotalCustomerSpend, { totalCustomerSpendDataValidator } from './TotalCustomerSpend';
import NumberOfCustomerOrders, { numberOfOrdersCatDataValidator } from './NumberOfCustomerOrders';
import CustomMeta, { customMetaDataValidator } from './CustomMeta';

// Actions
import { CouponTemplatesActions } from '../../../../../store/actions/couponTemplates';

// #region [Variables] =================================================================================================

const { setCartConditionItemData } = CouponTemplatesActions;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IActions {
  setCartConditionItemData: typeof setCartConditionItemData;
}

interface IProps {
  field: ICartConditionField<any>;
  groupKey: number;
  fieldKey: number;
  actions: IActions;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const ConditionField = (props: IProps) => {
  const { field, groupKey, fieldKey, actions } = props;

  const onChange = (data: any) => {
    actions.setCartConditionItemData({ groupKey, fieldKey, data });
  };

  switch (field.type) {
    case 'logic':
      return <ConditionLogic value={field.data} onChange={onChange} />;
    case 'cart-quantity':
      return <CartQuantity field={field} onChange={onChange} />;
    case 'cart-subtotal':
      return <CartSubtotal field={field} onChange={onChange} />;
    case 'cart-weight':
      return <CartWeight field={field} onChange={onChange} />;
    case 'coupons-applied-in-cart':
      return <CouponsAppliedInCart field={field} onChange={onChange} />;
    case 'product-quantity':
      return <ProductQuantitiesExistsInCart field={field} onChange={onChange} />;
    case 'product-stock-availability-exists-in-cart':
      return <ProductStockAvailabilityExistsInCart field={field} onChange={onChange} />;
    case 'has-ordered-before':
      return <HasOrderedProductsBefore field={field} onChange={onChange} />;
    case 'product-category':
      return <ProductCategoriesExistsInCart field={field} onChange={onChange} />;
    case 'custom-taxonomy':
      return <CustomTaxonomyExistsInCart field={field} onChange={onChange} />;
    case 'has-ordered-product-categories-before':
      return <HasOrderedProductCategoriesBefore field={field} onChange={onChange} />;
    case 'total-customer-spend-on-product-category':
      return <TotalCustomerSpendOnProductCategory field={field} onChange={onChange} />;
    case 'customer-logged-in-status':
      return <CustomerLoggedInStatus field={field} onChange={onChange} />;
    case 'customer-user-role':
    case 'disallowed-customer-user-role':
      return <CustomerUserRole field={field} onChange={onChange} />;
    case 'customer-registration-date':
      return <WithinHours field={field} onChange={onChange} min={1} />;
    case 'customer-last-ordered':
      return <WithinHours field={field} onChange={onChange} min={0} />;
    case 'total-customer-spend':
      return <TotalCustomerSpend field={field} onChange={onChange} />;
    case 'number-of-orders':
      return <NumberOfCustomerOrders field={field} onChange={onChange} />;
    case 'custom-user-meta':
    case 'custom-cart-item-meta':
      return <CustomMeta field={field} onChange={onChange} />;
  }

  return <div>Unknown field type</div>;
};

const mapDispatchToProps = (dispatch: any) => ({
  actions: bindActionCreators({ setCartConditionItemData }, dispatch),
});

export default connect(null, mapDispatchToProps)(ConditionField);

// #endregion [Component]

// #region [Validator] =================================================================================================

export function validateConditionField(type: string, data: unknown) {
  switch (type) {
    case 'cart-quantity':
      return cartQuantityDataValidator(data);
    case 'cart-subtotal':
      return cartSubtotalDataValidator(data);
    case 'cart-weight':
      return cartWeightDataValidator(data);
    case 'coupons-applied-in-cart':
      return couponsInCartDataValidator(data);
    case 'product-quantity':
      return productQuantitiesDataValidator(data);
    case 'product-stock-availability-exists-in-cart':
      return productStockDataValidator(data);
    case 'has-ordered-before':
      return hasOrderedProductsDataValidator(data);
    case 'product-category':
      return productCategoriesExistsDataValidator(data);
    case 'has-ordered-product-categories-before':
      return hasOrderedProductCategoriesBeforeDataValidator(data);
    case 'total-customer-spend-on-product-category':
      return totalCustomerSpendProductCatDataValidator(data);
    case 'customer-logged-in-status':
      return loginStatusDataValidator(data);
    case 'customer-user-role':
    case 'disallowed-customer-user-role':
      return customerUserRoleDataValidator(data);
    case 'customer-registration-date':
    case 'customer-last-ordered':
      return withinHoursDataValidator(data);
    case 'total-customer-spend':
      return totalCustomerSpendDataValidator(data);
    case 'number-of-orders':
      return numberOfOrdersCatDataValidator(data);
    case 'custom-user-meta':
    case 'custom-cart-item-meta':
      return customMetaDataValidator(data);
  }

  return [];
}

// #endregion [Validator]
