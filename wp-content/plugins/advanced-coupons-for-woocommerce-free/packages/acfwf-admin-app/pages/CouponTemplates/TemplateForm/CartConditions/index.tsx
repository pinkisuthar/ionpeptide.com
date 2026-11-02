// #region [Imports] ===================================================================================================

// Libraries
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';
import { ICartConditionGroup, ICartConditionGroupLogic } from '../../../../types/couponTemplates';

// Components
import ConditionGroup from './ConditionGroup';
import ConditionLogic from './ConditionLogic';

// Actions
import { CouponTemplatesActions } from '../../../../store/actions/couponTemplates';

// Types
import { IStore } from '../../../../types/store';

// Styles
import './index.scss';

// #endregion [Imports]

// #region [Variables] =================================================================================================

const { setCartConditionItemData } = CouponTemplatesActions;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IActions {
  setCartConditionItemData: typeof setCartConditionItemData;
}

interface IProps {
  data: (ICartConditionGroup | ICartConditionGroupLogic)[];
  actions: IActions;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const CartConditions = (props: IProps) => {
  const { data, actions } = props;

  return (
    <div className="cart-conditions">
      <h2>IF...</h2>
      <div className="condition-groups">
        {data.map((group, groupKey) => (
          <>
            {group.type === 'group_logic' ? (
              <ConditionLogic
                key={groupKey}
                value={group.value}
                onChange={(value) => actions.setCartConditionItemData({ groupKey, fieldKey: null, data: value })}
              />
            ) : (
              <ConditionGroup key={groupKey} groupKey={groupKey} group={group as ICartConditionGroup} />
            )}
          </>
        ))}
      </div>
      <h2>...THEN ALLOW COUPON TO BE APPLIED</h2>
    </div>
  );
};

const mapStateToProps = (state: IStore) => ({
  data: state.couponTemplates?.edit?.cart_conditions ?? [],
});

const mapDispatchToProps = (dispatch: any) => ({
  actions: bindActionCreators({ setCartConditionItemData }, dispatch),
});

export default connect(mapStateToProps, mapDispatchToProps)(CartConditions);
// #endregion [Component]
