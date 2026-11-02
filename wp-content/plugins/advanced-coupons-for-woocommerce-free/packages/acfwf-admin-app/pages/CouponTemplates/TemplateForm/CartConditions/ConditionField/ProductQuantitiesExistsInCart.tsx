// #region [Imports] ===================================================================================================
// Libraries
import { Table, Select, InputNumber, Button } from 'antd';

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

interface IProps {
  field: ICartConditionField<ITableRow[]>;
  onChange: (data: ITableRow[]) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const ProductQuantitiesExistsInCart = (props: IProps) => {
  const { field, onChange } = props;
  const { labels } = acfwAdminApp.coupon_templates_page;

  const handleChange = (index: number, rowData: ITableRow) => {
    const data = [...field.data];
    data[index] = rowData;
    onChange(data);
  };

  const columns = [
    {
      title: field.i18n.product_col,
      dataIndex: 'product_id',
      key: 'product_id',
      render: (text: string, record: ITableRow, index: number) => (
        <DebounceSelect
          value={record.product_id ? { value: record.product_id, label: record.product_label } : undefined}
          onChange={(data: IFieldOption) =>
            handleChange(index, { ...record, product_id: parseInt(data.value), product_label: data.label })
          }
          showSearch
          fetchOptions={(value: string) => searchProductOptions(value, [])}
          placeholder={labels.search_product}
          status={field?.errors?.includes(`product_id_${index}`) ? 'error' : ''}
        />
      ),
    },
    {
      title: field.i18n.condition_col,
      dataIndex: 'condition',
      key: 'condition',
      render: (text: string, record: ITableRow, index: number) => (
        <Select
          value={record.condition}
          options={getConditionOptions()}
          onSelect={(value) => handleChange(index, { ...record, condition: value.toString() })}
          status={field?.errors?.includes(`condition_${index}`) ? 'error' : ''}
        />
      ),
    },
    {
      title: field.i18n.quantity_col,
      dataIndex: 'quantity',
      key: 'quantity',
      render: (text: string, record: ITableRow, index: number) => (
        <InputNumber
          value={record.quantity}
          onChange={(value) => handleChange(index, { ...record, quantity: value ?? 0 })}
          min={0}
          status={field?.errors?.includes(`quantity_${index}`) ? 'error' : ''}
        />
      ),
    },
  ];

  return (
    <div className="condition-field">
      <h3>{field.i18n.title}</h3>
      <div className="condition-field-table">
        <Table columns={columns} dataSource={field.data} bordered pagination={false} />
      </div>
    </div>
  );
};

export default ProductQuantitiesExistsInCart;

// #endregion [Component]

// #region [Validator] =================================================================================================

export const productQuantitiesDataValidator = (rawData: unknown) => {
  const data = rawData as ITableRow[];
  const errors: string[] = [];

  data.forEach((row, index) => {
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
