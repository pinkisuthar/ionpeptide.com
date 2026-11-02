// #region [Imports] ===================================================================================================
// Libraries
import { useMemo } from 'react';
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
declare var _: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IDataRow {
  id: string;
  label: string;
}

interface ITableRow {
  product: IDataRow;
  condition: IDataRow;
}

interface IFieldData {
  products: ITableRow[];
}

interface IProps {
  field: ICartConditionField<IFieldData>;
  onChange: (data: IFieldData) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const ProductQuantitiesExistsInCart = (props: IProps) => {
  const { field, onChange } = props;
  const { labels } = acfwAdminApp.coupon_templates_page;

  const handleChange = (index: number, rowData: ITableRow) => {
    const products = [...field.data.products];
    products[index] = rowData;
    onChange({ products });
  };

  const conditionOptions = useMemo(
    () => _.map(field.i18n.products.options.conditions, (label: string, value: string) => ({ value, label })),
    [field.i18n.products.options]
  );

  const columns = [
    {
      title: labels.product,
      dataIndex: 'product_id',
      key: 'product_id',
      render: (text: string, record: ITableRow, index: number) => (
        <DebounceSelect
          value={record?.product ? { value: record.product.id, label: record.product.label } : undefined}
          onChange={(data: IFieldOption) =>
            handleChange(index, { ...record, product: { id: data.value, label: data.label } })
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
          labelInValue
          value={record?.condition ? { value: record.condition.id, label: record.condition.label } : undefined}
          options={conditionOptions}
          onChange={(data: IFieldOption) =>
            handleChange(index, { ...record, condition: { id: data.value, label: data.label } })
          }
          status={field?.errors?.includes(`condition_${index}`) ? 'error' : ''}
        />
      ),
    },
  ];

  return (
    <div className="condition-field">
      <h3>{field.i18n.title}</h3>
      <div className="condition-field-table">
        <Table columns={columns} dataSource={field.data.products} bordered pagination={false} />
      </div>
    </div>
  );
};

export default ProductQuantitiesExistsInCart;

// #endregion [Component]

// #region [Validator] =================================================================================================

export const productStockDataValidator = (rawData: unknown) => {
  const { products } = rawData as IFieldData;
  const errors: string[] = [];

  products.forEach((row, index) => {
    if (!row.product.id || row.product.id === '0') {
      errors.push(`product_id_${index}`);
    }

    if (!row.condition.id) {
      errors.push(`condition_${index}`);
    }
  });

  return errors;
};

// #endregion [Validator]
