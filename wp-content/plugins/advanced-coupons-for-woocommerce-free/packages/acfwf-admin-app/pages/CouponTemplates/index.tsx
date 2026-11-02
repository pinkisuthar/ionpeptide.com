// #region [Imports] ===================================================================================================

// Libraries
import { useCallback, useEffect, useState } from 'react';
import { useHistory, useLocation } from 'react-router-dom';
import { Row, Col, Tabs, Modal, Button, Space } from 'antd';
import AIIcon from '../../components/AIIcon';
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';

// Components
import AdminHeader from '../../components/AdminHeader';
import RecentTemplates from './RecentTemplates';
import QueriedTemplates from './QueriedTemplates';
import ReviewTemplates from './ReviewTemplates';
import AITemplates from './AITemplates';
import Sidebar from './Sidebar';
import TemplateForm from './TemplateForm';
import Logo from '../../components/Logo';
import AIGeneratorModal from '../../components/AIGeneratorModal';
import StoreAgentUpsellModal from '../../components/StoreAgentUpsellModal';

// Actions
import { CouponTemplatesActions } from '../../store/actions/couponTemplates';

// Types
import { IStore } from '../../types/store';

// Helpers
import { getPathPrefix } from '../../helpers/utils';
import axiosInstance, { axiosCancel, getCancelToken } from '../../helpers/axios';

// SCSS
import './index.scss';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

const { togglePremiumModal, toggleAIGeneratorModal, toggleStoreAgentUpsellModal } = CouponTemplatesActions;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IActions {
  togglePremiumModal: typeof togglePremiumModal;
  toggleAIGeneratorModal: typeof toggleAIGeneratorModal;
  toggleStoreAgentUpsellModal: typeof toggleStoreAgentUpsellModal;
}

interface IProps {
  showModal: boolean;
  actions: IActions;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const CouponTemplates = (props: IProps) => {
  const { showModal, actions } = props;
  const { title, labels, enable_review_tab } = acfwAdminApp.coupon_templates_page;
  const history = useHistory();
  const location = useLocation();
  const urlParams = new URLSearchParams(location.search);
  const editId = urlParams.get('id') ?? null;
  const pathPrefix = getPathPrefix();
  const currentTab = urlParams.get('cttab') ?? 'main';
  const [aiTemplateExists, setAITemplateExists] = useState(false);

  const handleTabClick = (key: string) => {
    history.push(`${pathPrefix}admin.php?page=acfw-coupon-templates&cttab=${key}`);
  };

  const aiGeneratorLabels = acfwAdminApp.ai_generator;
  const isStoreAgentConnected = acfwAdminApp.is_storeagent_connected;
  const isStoreAgentInstalled = acfwAdminApp.is_storeagent_installed;
  const isStoreAgentActive = acfwAdminApp.is_storeagent_active;
  const showAIModal = urlParams.get('show_ai_modal') === 'true';

  // Handler for Generate with AI button - checks plugin state
  const handleGenerateWithAI = useCallback(() => {
    if (!isStoreAgentInstalled) {
      actions.toggleStoreAgentUpsellModal({ show: true, mode: 'install' });
    } else if (!isStoreAgentActive) {
      actions.toggleStoreAgentUpsellModal({ show: true, mode: 'activate' });
    } else if (!isStoreAgentConnected) {
      actions.toggleStoreAgentUpsellModal({ show: true, mode: 'connect' });
    } else {
      actions.toggleAIGeneratorModal({ show: true });
    }
  }, [isStoreAgentInstalled, isStoreAgentActive, isStoreAgentConnected, actions]);

  // Check if AI template exists in transient
  useEffect(() => {
    if (isStoreAgentConnected && !editId) {
      checkAITemplateExists();
    }

    return () => {
      axiosCancel('check_ai_template');
    };
  }, [isStoreAgentConnected, editId]);

  const checkAITemplateExists = async () => {
    try {
      const response = await axiosInstance.get(`storeagent-ai/v1/coupon-generator-ai/template/exists`, {
        cancelToken: getCancelToken('check_ai_template'),
      });
      setAITemplateExists(response.data?.exists ?? false);
    } catch (error) {
      // Silent fail - just assume no template exists
      setAITemplateExists(false);
    }
  };

  // Auto-open AI modal or upsell modal if redirected from coupon popup with show_ai_modal=true.
  useEffect(() => {
    if (!showAIModal) return;

    // Remove the show_ai_modal parameter from URL regardless of state.
    urlParams.delete('show_ai_modal');
    history.replace(
      `${pathPrefix}admin.php?page=acfw-coupon-templates${urlParams.toString() ? '&' + urlParams.toString() : ''}`,
    );

    // Show the appropriate modal based on the current StoreAgent plugin state.
    if (isStoreAgentConnected) {
      actions.toggleAIGeneratorModal({ show: true });
    } else {
      handleGenerateWithAI();
    }
  }, [showAIModal, handleGenerateWithAI]);

  return (
    <div className="coupon-templates-page">
      <AdminHeader
        title={title}
        className="coupon-templates-header"
        actions={
          !editId ? (
            <Space>
              {isStoreAgentConnected && aiTemplateExists && (
                <Button
                  type="default"
                  onClick={() => history.push(`${pathPrefix}admin.php?page=acfw-coupon-templates&id=ai-temp`)}
                  className="ai-continue-btn"
                >
                  {aiGeneratorLabels.continue_btn}
                </Button>
              )}
              <Button icon={<AIIcon />} onClick={handleGenerateWithAI} className="ai-generate-btn">
                {aiGeneratorLabels.generate_btn}
              </Button>
            </Space>
          ) : undefined
        }
      />
      {editId ? (
        <TemplateForm />
      ) : (
        <Tabs defaultActiveKey={currentTab} className="coupon-templates-tabs" onTabClick={handleTabClick}>
          <Tabs.TabPane tab={labels.recently_used_templates} key="recent">
            <Row gutter={16}>
              <Col xs={24} sm={24} md={18} lg={18} xl={18}>
                <RecentTemplates />
              </Col>
            </Row>
          </Tabs.TabPane>
          <Tabs.TabPane tab={labels.available_templates} key="main">
            <Row gutter={16}>
              <Col xs={24} sm={24} md={18} lg={18} xl={18}>
                <QueriedTemplates />
              </Col>
              <Col xs={24} sm={24} md={6} lg={6} xl={6}>
                <Sidebar />
              </Col>
            </Row>
          </Tabs.TabPane>
          {enable_review_tab && (
            <Tabs.TabPane tab={labels.review_templates} key="review">
              <Row gutter={16}>
                <Col xs={24} sm={24} md={18} lg={18} xl={18}>
                  <ReviewTemplates />
                </Col>
              </Row>
            </Tabs.TabPane>
          )}
          <Tabs.TabPane tab={labels.ai_templates} key="ai">
            <Row gutter={16}>
              <Col xs={24} sm={24} md={24} lg={24} xl={24}>
                <AITemplates />
              </Col>
            </Row>
          </Tabs.TabPane>
        </Tabs>
      )}
      <Modal
        className="coupon-templates-premium-modal"
        open={showModal}
        centered
        onCancel={() => actions.togglePremiumModal({ show: false })}
        footer={null}
      >
        <Logo hideUpgrade />
        <p>{labels.premium_modal_text}</p>
        <Button type="primary" href={acfwAdminApp.coupon_templates_page.link} size="large" target="_blank">
          {labels.premium_modal_btn}
        </Button>
      </Modal>
      <AIGeneratorModal />
      <StoreAgentUpsellModal />
    </div>
  );
};

const mapStateToProps = (state: IStore) => ({
  showModal: state.couponTemplates?.premiumModal ?? false,
});

const mapDispatchToProps = (dispatch: any) => ({
  actions: bindActionCreators({ togglePremiumModal, toggleAIGeneratorModal, toggleStoreAgentUpsellModal }, dispatch),
});

export default connect(mapStateToProps, mapDispatchToProps)(CouponTemplates);

// #endregion [Component]
