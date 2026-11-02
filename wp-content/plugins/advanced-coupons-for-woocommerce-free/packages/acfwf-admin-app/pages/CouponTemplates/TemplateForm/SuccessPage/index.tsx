// #region [Imports] ===================================================================================================

// Libraries
import { Button, Descriptions } from 'antd';
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';

// Components
import GoBackButton from '../GoBackButton';
import CopyInputField from '../../../../components/CopyInputField';

// Types
import { IStore } from '../../../../types/store';

// Actions
import { CouponTemplatesActions } from '../../../../store/actions/couponTemplates';

// SCSS
import './index.scss';
import { ICreateCouponFromTemplateResponse } from '../../../../types/couponTemplates';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

const { clearCreatedCouponResponseData } = CouponTemplatesActions;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IActions {
  clearCreatedCouponResponseData: typeof clearCreatedCouponResponseData;
}

interface IProps {
  formResponse: ICreateCouponFromTemplateResponse | null;
  actions: IActions;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const SuccessPage = (props: IProps) => {
  const { formResponse, actions } = props;
  const { labels } = acfwAdminApp.coupon_templates_page;

  if (formResponse) {
    const { message, coupon_edit_url } = formResponse;

    return (
      <div className="coupon-template-success-page">
        <div className="inner">
          <div className="content-box">
            <h2>{message}</h2>
            <p>{labels.success_page_desc}</p>
            <Descriptions bordered size="small" layout="horizontal" column={1}>
              {formResponse.fields.map((field) => (
                <Descriptions.Item key={field.key} label={field.label}>
                  {['coupon_code', 'coupon_url'].includes(field.key) ? (
                    <CopyInputField value={field.value} />
                  ) : (
                    field.value
                  )}
                </Descriptions.Item>
              ))}
              {!!formResponse.cart_conditions && (
                <Descriptions.Item label={labels.cart_conditions}>{formResponse.cart_conditions}</Descriptions.Item>
              )}
            </Descriptions>
          </div>
          <div className="actions-box">
            <GoBackButton
              className="create-another-btn"
              text={labels.create_another_coupon}
              type="primary"
              size="large"
              onClick={() => actions.clearCreatedCouponResponseData()}
            />
            <Button href={`${coupon_edit_url}&sendcoupon`} size="large">
              {labels.send_coupon}
            </Button>
            <Button href={coupon_edit_url} size="large">
              {labels.edit_coupon}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  return null;
};

const mapStateToProps = (state: IStore) => ({
  formResponse: state.couponTemplates?.formResponse ?? null,
});

const mapDispatchToProps = (dispatch: any) => ({
  actions: bindActionCreators({ clearCreatedCouponResponseData }, dispatch),
});

export default connect(mapStateToProps, mapDispatchToProps)(SuccessPage);

// #endregion [Component]
