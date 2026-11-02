// #region [Imports] ===================================================================================================
// Libraries
import { useMemo } from 'react';
import { Select } from 'antd';

// Types
import { ICartConditionField } from '../../../../../types/couponTemplates';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;
declare var _: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IProps {
  field: ICartConditionField<string[]>;
  onChange: (data: string[]) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const CustomerUserRole = (props: IProps) => {
  const { field, onChange } = props;

  const options = useMemo(
    () => _.map(field.i18n.options, (label: string, value: string) => ({ value, label })),
    [field.i18n.options]
  );

  return (
    <div className="condition-field">
      <h3>{field.i18n.title}</h3>
      <div className="condition-field-form">
        <div className="field-control full-width">
          <Select
            mode="multiple"
            placeholder={field.i18n.placeholder}
            value={field.data}
            options={options}
            onChange={onChange}
            status={field.errors?.includes('value') ? 'error' : ''}
          />
        </div>
      </div>
    </div>
  );
};

export default CustomerUserRole;

// #endregion [Component]

// #region [Validator] =================================================================================================

export const customerUserRoleDataValidator = (rawData: unknown) => {
  const data = rawData as string[];
  const errors: string[] = [];

  if (data.length < 1) {
    errors.push('value');
  }

  return errors;
};

// #endregion [Validator]
