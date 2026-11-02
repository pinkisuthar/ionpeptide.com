// #region [Imports] ===================================================================================================
// Libraries
import { useMemo } from 'react';
import { Select, Input, InputNumber } from 'antd';

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
  key: string;
  value: string;
  type: string;
}

interface IProps {
  field: ICartConditionField<IFieldData>;
  onChange: (data: IFieldData) => void;
}

interface IMetaValueProps {
  value: string;
  type: string;
  handleChange: (dataKey: string, value: any) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const MetaValueInput = (props: IMetaValueProps) => {
  const { value, type, handleChange } = props;

  if (type === 'number') {
    return <InputNumber value={parseInt(value)} onChange={(value) => handleChange('value', value?.toString())} />;
  }

  if (type === 'price') {
    return (
      <InputNumber
        value={parseFloat(value)}
        onChange={(value) => handleChange('value', value?.toString())}
        decimalSeparator="."
        parser={(value) => parseFloat(value ?? '')}
      />
    );
  }

  return <Input value={value} onChange={(e: any) => handleChange('value', e.target.value)} />;
};

const CustomMeta = (props: IProps) => {
  const { field, onChange } = props;
  const { labels } = acfwAdminApp.coupon_templates_page;

  const handleChange = (dataKey: string, value: any) => {
    const data = { ...field.data, [dataKey]: value };
    onChange(data);
  };

  const conditionOptions = useMemo(
    () =>
      getConditionOptions().map((option) => ({
        ...option,
        disabled: ['<', '>'].includes(option.value) && field.data.type === 'string',
      })),
    [field.data.type]
  );

  return (
    <div className="condition-field">
      <h3>{field.i18n.title}</h3>
      <div className="condition-field-form">
        <div className="field-control">
          <label>{field.i18n.value_type}</label>
          <Select value={field.data.type} onSelect={(value) => handleChange('type', value)}>
            <Select.Option value="string">{field.i18n.type_options.string}</Select.Option>
            <Select.Option value="number">{field.i18n.type_options.number}</Select.Option>
            <Select.Option value="price">{field.i18n.type_options.price}</Select.Option>
          </Select>
        </div>
        <div className="field-control">
          <label>{labels.condition}</label>
          <Select
            value={field.data.condition}
            options={conditionOptions}
            onSelect={(value) => handleChange('condition', value)}
          />
        </div>
        <div className="field-control">
          <label>{field.i18n.meta_key}</label>
          <Input value={field.data.key} onChange={(value) => handleChange('key', value)} />
        </div>
        <div className="field-control">
          <label>{field.i18n.meta_value}</label>
          <MetaValueInput value={field.data.value} type={field.data.type} handleChange={handleChange} />
        </div>
      </div>
    </div>
  );
};

export default CustomMeta;

// #endregion [Component]

// #region [Validator] =================================================================================================

export const customMetaDataValidator = (rawData: unknown) => {
  const data = rawData as IFieldData;
  const errors: string[] = [];

  if (!data.type) {
    errors.push('type');
  }

  if ((data.type === 'string' && !data.value) || parseInt(data.value) < 0 || data.value === '') {
    errors.push('value');
  }

  if (!data.key) {
    errors.push('key');
  }

  if (!data.condition) {
    errors.push('condition');
  }

  return errors;
};

// #endregion [Validator]
