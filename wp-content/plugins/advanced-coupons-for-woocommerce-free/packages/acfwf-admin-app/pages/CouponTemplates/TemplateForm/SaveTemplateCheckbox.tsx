// #region [Imports] ===================================================================================================

// Libraries
import { useState } from 'react';
import { Checkbox, Space, Tooltip } from 'antd';
import { InfoCircleOutlined, CrownOutlined } from '@ant-design/icons';
import { useLocation } from 'react-router-dom';
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';

// Actions
import { CouponTemplatesActions } from '../../../store/actions/couponTemplates';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

const { togglePremiumModal } = CouponTemplatesActions;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IActions {
  togglePremiumModal: typeof togglePremiumModal;
}

interface IProps {
  disabled?: boolean;
  onSaveStateChange?: (shouldSave: boolean) => void;
  insidePremiumBox?: boolean;
  actions: IActions;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const SaveTemplateCheckbox = (props: IProps) => {
  const { disabled, onSaveStateChange, actions, insidePremiumBox } = props;
  const [shouldSave, setShouldSave] = useState(false);
  const urlParams = new URLSearchParams(useLocation().search);
  const editId = urlParams.get('id') ?? '0';
  const isAITemplate = editId === 'ai-temp';
  const isPremiumActive = acfwAdminApp?.is_acfwp_active ?? false;
  const { labels } = acfwAdminApp.coupon_templates_page;

  // Only show for AI templates.
  if (!isAITemplate) {
    return null;
  }

  const handleCheckboxChange = (e: any) => {
    const checked = e.target.checked;
    setShouldSave(checked);

    if (onSaveStateChange) {
      onSaveStateChange(checked);
    }
  };

  // For non-premium users, show disabled checkbox that triggers upsell modal on click.
  if (!isPremiumActive) {
    return (
      <div
        className="save-template-section save-template-locked"
        onClick={() => actions.togglePremiumModal({ show: true })}
        style={{ cursor: 'pointer' }}
      >
        <Checkbox disabled checked={false}>
          <Space>
            {labels.save_as_template}
            {!insidePremiumBox && <CrownOutlined style={{ color: '#6bb738' }} />}
          </Space>
        </Checkbox>
      </div>
    );
  }

  return (
    <div className="save-template-section">
      <Checkbox checked={shouldSave} onChange={handleCheckboxChange} disabled={disabled}>
        <Space>
          {labels.save_as_template}
          <Tooltip title={labels.save_template_tooltip}>
            <InfoCircleOutlined style={{ color: '#999' }} />
          </Tooltip>
        </Space>
      </Checkbox>
    </div>
  );
};

const mapStateToProps = () => ({});

const mapDispatchToProps = (dispatch: any) => ({
  actions: bindActionCreators({ togglePremiumModal }, dispatch),
});

export default connect(mapStateToProps, mapDispatchToProps)(SaveTemplateCheckbox);

// #endregion [Component]
