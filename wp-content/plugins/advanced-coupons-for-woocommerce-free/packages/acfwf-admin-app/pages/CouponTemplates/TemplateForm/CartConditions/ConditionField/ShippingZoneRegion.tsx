// #region [Imports] ===================================================================================================
// Libraries
import { Select } from 'antd';

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
  condition: number;
  value: string[];
}

interface IProps {
  field: ICartConditionField<IFieldData>;
  onChange: (data: IFieldData) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const CartQuantity = (props: IProps) => {
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
        <div className="field-control full-width">
          <label>{field.i18n.zone_label}</label>
          <Select value={field.data.condition} onSelect={(value) => handleChange('condition', value)} />
        </div>
        <div className="field-control full-width">
          <label>{field.i18n.regions_label}</label>
          <Select mode="multiple" value={field.data.condition} onSelect={(value) => handleChange('condition', value)} />
        </div>
      </div>
    </div>
  );
};

export default CartQuantity;

// #endregion [Component]
