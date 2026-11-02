// #region [Imports] ===================================================================================================

// Types
import { ICartConditionField, ICartConditionGroup } from '../../../../types/couponTemplates';

// Components
import ConditionField from './ConditionField';

// #endregion [Imports]

// #region [Variables] =================================================================================================
// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IProps {
  group: ICartConditionGroup;
  groupKey: number;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const ConditionGroup = (props: IProps) => {
  const { group, groupKey } = props;
  return (
    <div className="condition-group">
      {!!group.fields.length ? (
        <div className="condition-group-fields">
          {group.fields.map((field: ICartConditionField<unknown>, fieldKey: number) => (
            <ConditionField key={fieldKey} field={field} groupKey={groupKey} fieldKey={fieldKey} />
          ))}
        </div>
      ) : (
        <div className="empty-conditions">
          This is an empty condition group, just waiting for you to add some conditions in it...
        </div>
      )}
    </div>
  );
};

export default ConditionGroup;

// #endregion [Component]
