// #region [Imports] ===================================================================================================
// Libraries
import { Select } from 'antd';

// Types
import { ICartConditionField } from '../../../../../types/couponTemplates';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IProps {
  field: ICartConditionField<string>;
  onChange: (data: string) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const CartQuantity = (props: IProps) => {
  const { field, onChange } = props;

  return (
    <div className="condition-field">
      <h3>{field.i18n.title}</h3>
      <div className="condition-field-form">
        <div className="field-control">
          <Select value={field.data} onSelect={onChange} status={field.errors?.includes('value') ? 'error' : ''}>
            <Select.Option value="logged-in">{field.i18n.options['logged-in']}</Select.Option>
            <Select.Option value="guest">{field.i18n.options['guest']}</Select.Option>
          </Select>
        </div>
      </div>
    </div>
  );
};

export default CartQuantity;

// #endregion [Component]

// #region [Validator] =================================================================================================

export const loginStatusDataValidator = (rawData: unknown) => {
  const data = rawData as string;
  const errors: string[] = [];

  if (!data) {
    errors.push('value');
  }

  return errors;
};

// #endregion [Validator]
