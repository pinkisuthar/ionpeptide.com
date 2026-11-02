// #region [Imports] ===================================================================================================
// Libraries
import { useState } from 'react';
import { Table, Select, InputNumber } from 'antd';

// Components
import DebounceSelect from '../../../../../components/DebounceSelect';

// Types
import { ICartConditionField } from '../../../../../types/couponTemplates';

// Helpers
import { searchProductOptions, getConditionOptions } from '../../../../../helpers/utils';
import { IFieldOption } from '../../../../../types/fields';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface ITableRow {
  product_id: number;
  condition: string;
  quantity: number;
  product_label: string;
  condition_label: string;
}

interface IFieldData {
  condition: string;
  products: ITableRow[];
  value: number;
}

interface IProps {
  field: ICartConditionField<IFieldData>;
  onChange: (data: IFieldData) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const HasOrderedProductsBefore = (props: IProps) => {
  const { field, onChange } = props;
  const { labels } = acfwAdminApp.coupon_templates_page;

  const i18n = field.i18n;

  const [numberLabel, setNumberLabel] = useState(i18n.within_a_period);

  const handleChange = (dataKey: string, value: any) => {
    const data = { ...field.data, [dataKey]: value };
    onChange(data);

    if (dataKey === 'condition') {
      setNumberLabel(value === 'within-a-period' ? i18n.within_a_period : i18n.number_of_orders);
    }
  };

  const handleTableChange = (index: number, rowData: ITableRow) => {
    const products = [...field.data.products];
    products[index] = rowData;
    onChange({ ...field.data, products });
  };

  const columns = [
    {
      title: labels.product,
      dataIndex: 'product_id',
      key: 'product_id',
      render: (text: string, record: ITableRow, index: number) => (
        <DebounceSelect
          value={record.product_id ? { value: record.product_id, label: record.product_label } : undefined}
          onChange={(data: IFieldOption) =>
            handleTableChange(index, { ...record, product_id: parseInt(data.value), product_label: data.label })
          }
          showSearch
          fetchOptions={(value: string) => searchProductOptions(value, [])}
          placeholder={labels.search_product}
          status={field?.errors?.includes(`product_id_${index}`) ? 'error' : ''}
        />
      ),
    },
    {
      title: labels.condition,
      dataIndex: 'condition',
      key: 'condition',
      render: (text: string, record: ITableRow, index: number) => (
        <Select
          value={record.condition}
          options={getConditionOptions()}
          onSelect={(value) => handleTableChange(index, { ...record, condition: value.toString() })}
          status={field?.errors?.includes(`condition_${index}`) ? 'error' : ''}
        />
      ),
    },
    {
      title: labels.quantity,
      dataIndex: 'quantity',
      key: 'quantity',
      render: (text: string, record: ITableRow, index: number) => (
        <InputNumber
          value={record.quantity}
          onChange={(value) => handleTableChange(index, { ...record, quantity: value ?? 0 })}
          min={0}
          status={field?.errors?.includes(`quantity_${index}`) ? 'error' : ''}
        />
      ),
    },
  ];

  return (
    <div className="condition-field">
      <h3>{i18n.title}</h3>
      <div className="condition-field-form">
        <div className="field-control">
          <label>{i18n.type}</label>
          <Select
            value={field.data.condition}
            options={getConditionOptions('period')}
            onSelect={(value) => handleChange('condition', value)}
            status={field?.errors?.includes('condition') ? 'error' : ''}
          />
        </div>
        <div className="field-control">
          <label>{numberLabel}</label>
          <InputNumber
            value={field.data.value}
            onChange={(value) => handleChange('value', value)}
            min={0}
            status={field?.errors?.includes('value') ? 'error' : ''}
          />
        </div>
      </div>
      <div className="condition-field-table">
        <Table columns={columns} dataSource={field.data.products} bordered pagination={false} />
      </div>
    </div>
  );
};

export default HasOrderedProductsBefore;

// #endregion [Component]

// #region [Validator] =================================================================================================

export const hasOrderedProductsDataValidator = (rawData: unknown) => {
  const data = rawData as IFieldData;
  const errors: string[] = [];

  if (!data.condition) {
    errors.push('condition');
  }

  if (data.value < 0 || typeof data.value !== 'number') {
    errors.push('value');
  }

  data.products.forEach((row, index) => {
    if (row.product_id === 0) {
      errors.push(`product_id_${index}`);
    }

    if (row.quantity < 0 || typeof row.quantity !== 'number') {
      errors.push(`quantity_${index}`);
    }

    if (!row.condition) {
      errors.push(`condition_${index}`);
    }
  });

  return errors;
};

// #endregion [Validator]
