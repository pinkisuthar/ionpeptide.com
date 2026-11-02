// #region [Imports] ===================================================================================================
// Libraries
import { useState } from 'react';
import { InputNumber, Select } from 'antd';

// Components
import DebounceSelect from '../../../../../components/DebounceSelect';

// Types
import { ICartConditionField } from '../../../../../types/couponTemplates';
import { IFieldOption } from '../../../../../types/fields';

// Helpers
import { getConditionOptions, searchCategoryOptions } from '../../../../../helpers/utils';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IFieldData {
  categories: number[];
  condition: string;
  quantity: number;
  type: {
    condition: string;
    label: string;
    value: number;
  };
}

interface IProps {
  field: ICartConditionField<IFieldData>;
  onChange: (data: IFieldData) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const HasOrderedProductCategoriesBefore = (props: IProps) => {
  const { field, onChange } = props;
  const { labels } = acfwAdminApp.coupon_templates_page;

  const [numberLabel, setNumberLabel] = useState(field.i18n.within_a_period);

  const handleChange = (dataKey: string, value: any) => {
    const data = { ...field.data, [dataKey]: value };
    onChange(data);

    if (dataKey === 'type') {
      setNumberLabel(value.condition === 'within-a-period' ? field.i18n.within_a_period : field.i18n.number_of_orders);
    }
  };

  return (
    <div className="condition-field">
      <h3>{field.i18n.title}</h3>
      <div className="condition-field-form">
        <div className="field-control">
          <label>{field.i18n.type}</label>
          <Select
            value={field.data.type.condition}
            options={getConditionOptions('period')}
            onSelect={(value) => handleChange('type', { ...field.data.type, condition: value })}
            status={field?.errors?.includes('type_condition') ? 'error' : ''}
          />
        </div>
        <div className="field-control">
          <label>{numberLabel}</label>
          <InputNumber
            value={field.data.type.value}
            onChange={(value) => handleChange('type', { ...field.data.type, value: value })}
            min={0}
            status={field?.errors?.includes('type_value') ? 'error' : ''}
          />
        </div>
      </div>
      <div className="condition-field-form">
        <div className="field-control full-width">
          <label>{field.i18n.field}</label>
          <DebounceSelect
            mode="multiple"
            placeholder="Select categories"
            value={field.data.categories}
            style={{ width: '100%' }}
            fetchOptions={(value: string) => searchCategoryOptions(value, field.data.categories)}
            status={field?.errors?.includes('categories') ? 'error' : ''}
            onChange={(value: IFieldOption[]) =>
              handleChange(
                'categories',
                value.map((v) => v.value)
              )
            }
          />
        </div>
      </div>
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
          <label>{labels.quantity}</label>
          <InputNumber
            value={field.data.quantity}
            onChange={(value) => handleChange('quantity', value ?? 0)}
            min={0}
            status={field?.errors?.includes('quantity') ? 'error' : ''}
          />
        </div>
      </div>
    </div>
  );
};

export default HasOrderedProductCategoriesBefore;

// #endregion [Component]

// #region [Validator] =================================================================================================

export const hasOrderedProductCategoriesBeforeDataValidator = (rawData: unknown) => {
  const data = rawData as IFieldData;
  const errors: string[] = [];

  if (data.quantity < 0 || typeof data.quantity !== 'number') {
    errors.push('quantity');
  }

  if (!data.condition) {
    errors.push('condition');
  }

  if (data.categories.length < 1) {
    errors.push('categories');
  }

  if (!data.type.condition) {
    errors.push('type_condition');
  }

  if (data.type.value < 0 || typeof data.type.value !== 'number') {
    errors.push('type_value');
  }

  return errors;
};

// #endregion [Validator]
