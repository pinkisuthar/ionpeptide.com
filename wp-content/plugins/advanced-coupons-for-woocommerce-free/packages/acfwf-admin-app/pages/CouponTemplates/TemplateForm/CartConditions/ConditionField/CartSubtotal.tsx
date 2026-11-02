// #region [Imports] ===================================================================================================
// Libraries
import { Select, InputNumber } from 'antd';

// Types
import { ICartConditionField } from '../../../../../types/couponTemplates';

// Components
import ConditionLogic from '../ConditionLogic';

// Helpers
import { getConditionOptions } from '../../../../../helpers/utils';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IFieldData {
  condition: string;
  value: number;
}

interface IProps {
  field: ICartConditionField<IFieldData>;
  onChange: (data: IFieldData) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const CartSubtotal = (props: IProps) => {
  const { field, onChange } = props;
  const { labels } = acfwAdminApp.coupon_templates_page;

  const handleChange = (dataKey: string, value: any) => {
    const data = { ...field.data, [dataKey]: value };
    onChange(data);
  };

  return (
    <div className="condition-field">
      <h3>{field.i18n.title}</h3>
      <div className="condition-field-form">
        <div className="field-control">
          <label>{labels.condition}</label>
          <Select
            value={field.data.condition}
            options={getConditionOptions()}
            onSelect={(value) => handleChange('condition', value)}
            status={field?.errors?.includes('condition') ? 'error' : ''}
          />
        </div>
        <div className="field-control">
          <label>{field.i18n.field}</label>
          <InputNumber
            type="number"
            value={field.data.value}
            onChange={(value) => handleChange('value', value ?? undefined)}
            status={field?.errors?.includes('value') ? 'error' : ''}
          />
        </div>
      </div>
    </div>
  );
};

export default CartSubtotal;

// #endregion [Component]

// #region [Validator] =================================================================================================

export const cartSubtotalDataValidator = (rawData: unknown) => {
  const data = rawData as IFieldData;
  const errors: string[] = [];

  if (data.value < 0 || typeof data.value !== 'number') {
    errors.push('value');
  }

  if (!data.condition) {
    errors.push('condition');
  }

  return errors;
};

// #endregion [Validator]
