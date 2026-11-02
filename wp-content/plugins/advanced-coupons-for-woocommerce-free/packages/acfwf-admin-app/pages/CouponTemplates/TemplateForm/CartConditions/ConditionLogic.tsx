// #region [Imports] ===================================================================================================

// Libraries
import { Select } from 'antd';

// Types
import { ICartConditionGroupLogic } from '../../../../types/couponTemplates';

// #endregion [Imports]

// #region [Variables] =================================================================================================
// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IProps {
  value: string;
  onChange: (value: string) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const ConditionGroupLogic = (props: IProps) => {
  const { value, onChange } = props;

  const options = [
    {
      value: 'and',
      label: 'AND',
    },
    {
      value: 'or',
      label: 'OR',
    },
  ];

  return (
    <div className="condition-logic centered-line">
      <div className="field-control">
        <Select value={value} options={options} onSelect={onChange} />
      </div>
    </div>
  );
};

export default ConditionGroupLogic;

// #endregion [Component]
