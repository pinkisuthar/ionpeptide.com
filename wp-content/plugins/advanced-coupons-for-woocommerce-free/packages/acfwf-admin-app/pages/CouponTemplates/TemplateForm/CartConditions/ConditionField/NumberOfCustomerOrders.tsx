// #region [Imports] ===================================================================================================
// Libraries
import { Select, InputNumber } from 'antd';

// Types
import { ICartConditionField } from '../../../../../types/couponTemplates';

// Helpers
import { getConditionOptions } from '../../../../../helpers/utils';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IFieldData {
  condition: string;
  offset: number;
  value: string;
}

interface IProps {
  field: ICartConditionField<IFieldData>;
  onChange: (data: IFieldData) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const TotalCustomerSpend = (props: IProps) => {
  const { field, onChange } = props;
  const { labels } = acfwAdminApp.coupon_templates_page;

  const handleChange = (dataKey: string, value: any) => {
    const data = { ...field.data, [dataKey]: value };
    onChange(data);
  };

  return (
    <div className="condition-field">
      <h3>{field.i18n.title}</h3>
      <p>{field.i18n.desc}</p>
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
          <label>{field.i18n.count_label}</label>
          <InputNumber
            value={parseInt(field.data.value)}
            onChange={(value) => handleChange('value', value ?? 0)}
            min={0}
            status={field?.errors?.includes('value') ? 'error' : ''}
          />
        </div>
        <div className="field-control">
          <label>{field.i18n.prev_days_label}</label>
          <InputNumber
            value={field.data.offset}
            onChange={(value) => handleChange('offset', value ?? 0)}
            min={0}
            status={field?.errors?.includes('offset') ? 'error' : ''}
          />
        </div>
      </div>
    </div>
  );
};

export default TotalCustomerSpend;

// #endregion [Component]

// #region [Validator] =================================================================================================

export const numberOfOrdersCatDataValidator = (rawData: unknown) => {
  const data = rawData as IFieldData;
  const errors: string[] = [];

  if (data.offset < 0 || typeof data.offset !== 'number') {
    errors.push('offset');
  }

  if (parseInt(data.value) < 0 || data.value === '') {
    errors.push('value');
  }

  if (!data.condition) {
    errors.push('condition');
  }

  return errors;
};

// #endregion [Validator]
