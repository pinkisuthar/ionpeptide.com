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

declare var acfwAdminApp: any;
declare var ajaxurl: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IFieldData {
  condition: string;
  quantity: string;
  value: number[];
}

interface IProps {
  field: ICartConditionField<IFieldData>;
  onChange: (data: IFieldData) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const CustomTaxonomyExistsInCart = (props: IProps) => {
  const { field, onChange } = props;
  const { labels } = acfwAdminApp.coupon_templates_page;

  const searchCategories = async (value: string) => {
    const response = await jQuery.ajax({
      url: ajaxurl,
      type: 'GET',
      data: {
        action: 'woocommerce_json_search_taxonomy_terms',
        term: value,
        taxonomy: 'product_cat',
        security: acfwAdminApp.nonces.search_taxonomy_terms,
        exclude: field.data.value,
      },
    });

    return Object.keys(response).map((key) => ({
      label: response[key].name,
      value: response[key].term_id,
    }));
  };

  const handleChange = (dataKey: string, value: any) => {
    const data = { ...field.data, [dataKey]: value };
    onChange(data);
  };

  return (
    <div className="condition-field">
      <h3>{field.i18n.title}</h3>
      <div className="condition-field-form">
        <div className="field-control full-width">
          <label>{field.i18n.field}</label>
          <DebounceSelect
            mode="multiple"
            placeholder="Select categories"
            value={field.data.value}
            style={{ width: '100%' }}
            fetchOptions={searchCategories}
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
            options={getConditionOptions()}
            onSelect={(value) => handleChange('condition', value)}
            status={field?.errors?.includes('condition') ? 'error' : ''}
          />
        </div>
        <div className="field-control">
          <label>{labels.quantity}</label>
          <InputNumber
            value={parseInt(field.data.quantity)}
            onChange={(value) => handleChange('quantity', value?.toString() ?? undefined)}
            min={0}
            status={field?.errors?.includes('quantity') ? 'error' : ''}
          />
        </div>
      </div>
    </div>
  );
};

export default CustomTaxonomyExistsInCart;

// #endregion [Component]

// #region [Validator] =================================================================================================

export const customTaxonomysExistsDataValidator = (rawData: unknown) => {
  const data = rawData as IFieldData;
  const errors: string[] = [];

  if (parseInt(data.quantity) < 0 || typeof data.quantity !== 'number') {
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
