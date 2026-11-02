// #region [Imports] ===================================================================================================
// Libraries
import { Select, InputNumber } from 'antd';

// Components
import DebounceSelect from '../../../../../components/DebounceSelect';

// Types
import { ICartConditionField } from '../../../../../types/couponTemplates';
import { IFieldOption } from '../../../../../types/fields';

// Helpers
import { getConditionOptions } from '../../../../../helpers/utils';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var ajaxurl: string;
declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IFieldData {
  condition: string;
  quantity: number;
  value: number[];
}

interface IProps {
  field: ICartConditionField<IFieldData>;
  onChange: (data: IFieldData) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const CouponsAppliedInCart = (props: IProps) => {
  const { field, onChange } = props;
  const { labels } = acfwAdminApp.coupon_templates_page;

  const searchCoupons = async (value: string) => {
    const response = await jQuery.ajax({
      url: ajaxurl,
      type: 'GET',
      data: {
        action: 'acfw_search_coupons',
        term: value,
        security: acfwAdminApp.nonces.search_products,
        include: true,
        exclude: field.data.value,
      },
    });

    return Object.keys(response).map((key) => ({
      label: response[key],
      value: key,
    }));
  };

  const handleChange = (dataKey: string, value: any) => {
    const data = { ...field.data, [dataKey]: value };
    onChange(data);
  };

  return (
    <div className="condition-field">
      <h3>{field.i18n.title}</h3>
      <p>{field.i18n.desc}</p>
      <div className="condition-field-form">
        <div className="field-control full-width">
          <label>{field.i18n.coupons_label}</label>
          <DebounceSelect
            mode="multiple"
            placeholder={field.i18n.select_coupons}
            value={field.data.value}
            style={{ width: '100%' }}
            fetchOptions={searchCoupons}
            status={field?.errors?.includes('value') ? 'error' : ''}
            onChange={(value: IFieldOption[]) =>
              handleChange(
                'value',
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
            onSelect={(value) => handleChange('condition', value)}
            status={field?.errors?.includes('condition') ? 'error' : ''}
          >
            <Select.Option value="atleast">{acfwAdminApp.condition_options.atleast}</Select.Option>
            <Select.Option value="all">{acfwAdminApp.condition_options.all}</Select.Option>
          </Select>
        </div>
        <div className="field-control">
          <label>{field.i18n.quantity_label}</label>
          <InputNumber
            value={field.data.quantity}
            onChange={(value) => handleChange('quantity', value ?? undefined)}
            min={1}
            status={field?.errors?.includes('quantity') ? 'error' : ''}
          />
        </div>
      </div>
    </div>
  );
};

export default CouponsAppliedInCart;

// #endregion [Component]

// #region [Validator] =================================================================================================

export const couponsInCartDataValidator = (rawData: unknown) => {
  const data = rawData as IFieldData;
  const errors: string[] = [];

  if (data.quantity < 0 || typeof data.quantity !== 'number') {
    errors.push('quantity');
  }

  if (!data.condition) {
    errors.push('condition');
  }

  if (data.value.length < 1) {
    errors.push('value');
  }

  return errors;
};

// #endregion [Validator]
