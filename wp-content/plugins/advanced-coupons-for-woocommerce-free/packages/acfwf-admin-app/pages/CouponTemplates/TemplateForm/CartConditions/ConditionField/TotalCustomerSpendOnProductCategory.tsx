// #region [Imports] ===================================================================================================
// Libraries
import { Select, InputNumber } from 'antd';

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
  condition: string;
  spend: number;
  categories: number[];
  type: any;
}

interface IProps {
  field: ICartConditionField<IFieldData>;
  onChange: (data: IFieldData) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const TotalCustomerSpendOnProductCategory = (props: IProps) => {
  const { field, onChange } = props;
  const { labels } = acfwAdminApp.coupon_templates_page;

  const handleChange = (dataKey: string, value: any) => {
    const data = { ...field.data, [dataKey]: value };
    onChange(data);
  };

  const getTypeValueLabel = (): string => {
    switch (field.data.type.condition) {
      case 'within-a-period':
        return field.i18n.num_prev_days;
      case 'number-of-orders':
        return field.i18n.number_of_orders;
      default:
        return '';
    }
  };

  const getCategoryOptions = (categories: Record<string, string>) => {
    return Object.keys(categories).map((key) => ({
      value: key,
      label: categories[key],
    }));
  };

  return (
    <div className="condition-field">
      <h3>{field.i18n.title}</h3>
      <div className="condition-field-form">
        <div className="field-control">
          <label>{field.i18n.type}</label>
          <Select
            value={field.data.type.condition}
            options={field.i18n.type_options}
            onSelect={(value: string) => handleChange('type', { ...field.data.type, condition: value })}
          />
        </div>

        <div className="field-control">
          <label>{getTypeValueLabel()}</label>
          <InputNumber
            value={field.data.type.value}
            onChange={(value: number | null) => handleChange('type', { ...field.data.type, value: value })}
            min={0}
          />
        </div>
      </div>

      <div className="condition-field-form">
        <div className="field-control full-width">
          <label>{field.i18n.categories.field}</label>
          <Select
            mode="multiple"
            placeholder="Select categories"
            style={{ width: '100%' }}
            options={getCategoryOptions(field.i18n.categories.options)}
            onChange={(selectedValues: string[]) => handleChange('categories', selectedValues)}
          />
        </div>
      </div>

      <div className="condition-field-form">
        <div className="field-control">
          <label>{labels.condition}</label>
          <Select
            value={field.data.condition}
            options={getConditionOptions()}
            onSelect={(value: string) => handleChange('condition', value)}
          />
        </div>

        <div className="field-control">
          <label>{field.i18n.total_spend}</label>
          <InputNumber
            value={field.data.spend}
            onChange={(value: number | null) => handleChange('spend', value ?? 0)}
            min={0}
          />
        </div>
      </div>
    </div>
  );
};

export default TotalCustomerSpendOnProductCategory;

// #endregion [Component]

// #region [Validator] =================================================================================================

export const totalCustomerSpendProductCatDataValidator = (rawData: unknown) => {
  const data = rawData as IFieldData;
  const errors: string[] = [];

  if (data.spend < 0 || typeof data.spend !== 'number') {
    errors.push('spend');
  }

  if (!data.condition) {
    errors.push('condition');
  }

  if (data.categories.length < 1) {
    errors.push('categories');
  }

  return errors;
};

// #endregion [Validator]
