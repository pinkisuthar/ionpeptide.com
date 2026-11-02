// #region [Imports] ===================================================================================================
// Libraries
import { InputNumber } from 'antd';

// Types
import { ICartConditionField } from '../../../../../types/couponTemplates';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IProps {
  field: ICartConditionField<number>;
  min: number;
  onChange: (data: number) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const WithinHours = (props: IProps) => {
  const { field, min, onChange } = props;

  return (
    <div className="condition-field">
      <h3>{field.i18n.title}</h3>
      <p>{field.i18n.desc}</p>
      <div className="condition-field-form">
        <div className="field-control">
          <InputNumber
            type="number"
            value={field.data}
            onChange={(value) => onChange(value ?? 1)}
            min={min}
            addonAfter={field.i18n.hours}
            status={field.errors?.includes('value') ? 'error' : ''}
          />
        </div>
      </div>
    </div>
  );
};

export default WithinHours;

// #endregion [Component]

// #region [Validator] =================================================================================================

export const withinHoursDataValidator = (rawData: unknown) => {
  const data = rawData as number;
  const errors: string[] = [];

  if (!data) {
    errors.push('value');
  }

  return errors;
};

// #endregion [Validator]
