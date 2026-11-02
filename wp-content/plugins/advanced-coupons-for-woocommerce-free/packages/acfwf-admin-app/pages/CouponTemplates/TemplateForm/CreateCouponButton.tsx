// #region [Imports] ===================================================================================================

// Libraries
import { useState } from 'react';
import { Button, message } from 'antd';
import { useHistory, useLocation } from 'react-router-dom';
import { SizeType } from 'antd/lib/config-provider/SizeContext';
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';

// Types
import { IStore } from '../../../types/store';
import { ICouponTemplateFormData, ICreateCouponFromTemplateResponse } from '../../../types/couponTemplates';

// Actions
import { CouponTemplatesActions } from '../../../store/actions/couponTemplates';
import { ICouponTemplate } from '../../../types/couponTemplates';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

const {
  validateEditCouponTemplateData,
  validateCartConditionsData,
  createCouponFromTemplate,
  setCreatedCouponResponseData,
  clearAICouponTemplate,
  saveAITemplate,
} = CouponTemplatesActions;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IActions {
  validateEditCouponTemplateData: typeof validateEditCouponTemplateData;
  validateCartConditionsData: typeof validateCartConditionsData;
  createCouponFromTemplate: typeof createCouponFromTemplate;
  setCreatedCouponResponseData: typeof setCreatedCouponResponseData;
  clearAICouponTemplate: typeof clearAICouponTemplate;
  saveAITemplate: typeof saveAITemplate;
}

interface IProps {
  template: ICouponTemplate | null;
  text: string;
  size: SizeType;
  disabled: boolean;
  shouldSaveTemplate?: boolean;
  templateTitle?: string;
  aiPrompt?: string;
  actions: IActions;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const CreateCouponButton = (props: IProps) => {
  const { template, text, size, disabled, shouldSaveTemplate, templateTitle, aiPrompt, actions } = props;
  const [isLoading, setIsLoading] = useState(false);
  const urlParams = new URLSearchParams(useLocation().search);
  const editId = urlParams.get('id') ?? '0';
  const isAITemplate = editId === 'ai-temp';

  const { labels } = acfwAdminApp.coupon_templates_page;

  const handleCreateCoupon = () => {
    actions.validateEditCouponTemplateData();
    actions.validateCartConditionsData();

    // Don't proceed when there are fields with errors.
    const errors = template?.fields?.filter((field) => field.error);
    if (!template || errors?.length) {
      return;
    }

    const data: ICouponTemplateFormData = {
      id: template.id,
      fields: template.fields.map((field) => ({
        key: field.field,
        value: field.value,
        type: field.fixtures.type,
      })),
      cart_conditions: template.cart_conditions ?? [],
    };

    setIsLoading(true);
    actions.createCouponFromTemplate({
      data: data,
      successCB: (response: any) => {
        // Save template if checkbox was checked.
        if (shouldSaveTemplate && templateTitle && aiPrompt && isAITemplate) {
          actions.saveAITemplate({
            name: templateTitle,
            prompt: aiPrompt,
            processingCB: undefined,
            successCB: () => {
              message.success(labels.template_saved);

              // Clear AI template transient AFTER successful save
              actions.clearAICouponTemplate();
            },
            failCB: ({ error }: { error: { response?: { status: number } } }) => {
              // Check if it's a 403 permission error.
              if (error?.response?.status === 403) {
                message.error(labels.save_permission_error);
              } else {
                message.error(labels.save_template_failed);
              }

              // Clear transient even on failure
              actions.clearAICouponTemplate();
            },
          });
        } else {
          // Clear AI template transient if this was an AI-generated template (and we're not saving).
          if (isAITemplate) {
            actions.clearAICouponTemplate();
          }
        }

        setIsLoading(false);
        actions.setCreatedCouponResponseData({ data: response.data as ICreateCouponFromTemplateResponse });
      },
      failCB: () => {
        setIsLoading(false);
      },
    });
  };

  return (
    <Button loading={isLoading} type="primary" onClick={handleCreateCoupon} size={size} disabled={disabled}>
      {text}
    </Button>
  );
};

const mapStateToProps = (state: IStore) => ({
  template: state.couponTemplates?.edit ?? null,
  formResponse: state.couponTemplates?.formResponse ?? null,
});

const mapDispatchToProps = (dispatch: any) => ({
  actions: bindActionCreators(
    {
      validateEditCouponTemplateData,
      validateCartConditionsData,
      createCouponFromTemplate,
      setCreatedCouponResponseData,
      clearAICouponTemplate,
      saveAITemplate,
    },
    dispatch,
  ),
});

export default connect(mapStateToProps, mapDispatchToProps)(CreateCouponButton);

// #endregion [Component]
