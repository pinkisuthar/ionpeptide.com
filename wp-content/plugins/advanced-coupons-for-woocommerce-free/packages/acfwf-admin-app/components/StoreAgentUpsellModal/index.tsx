// #region [Imports] ===================================================================================================

// Libraries
import React, { useState } from 'react';
import { Modal, Button, Typography, Space, message } from 'antd';

import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';

// Actions
import { CouponTemplatesActions } from '../../store/actions/couponTemplates';

// Helpers
import axiosInstance from '../../helpers/axios';

// Types
import { IStore } from '../../types/store';

// SCSS
import './index.scss';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

const { Title, Paragraph } = Typography;

const { toggleStoreAgentUpsellModal } = CouponTemplatesActions;

// #endregion [Variables]

// #region [Interfaces] ================================================================================================

interface IActions {
  toggleStoreAgentUpsellModal: typeof toggleStoreAgentUpsellModal;
}

interface IProps {
  showModal: boolean;
  mode: 'install' | 'activate' | 'connect';
  actions: IActions;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const StoreAgentUpsellModal = (props: IProps) => {
  const { showModal, mode, actions } = props;
  const [loading, setLoading] = useState(false);

  const upsellLabels = acfwAdminApp.storeagent_upsell;
  const acfwLogoUrl = acfwAdminApp.logo;
  const logoUrl = acfwAdminApp.storeagent_logo_url;
  const storeagentWebsiteUrl = acfwAdminApp.storeagent_website_url;
  const storeagentConnectUrl = acfwAdminApp.storeagent_connect_url;
  const adminAjaxUrl = acfwAdminApp.admin_ajax_url;
  const installNonce = acfwAdminApp.nonces?.install_plugin;
  const pluginSlug = acfwAdminApp.storeagent_plugin_slug;

  const handleClose = () => {
    actions.toggleStoreAgentUpsellModal({ show: false });
  };

  /**
   * Shared AJAX call to install/activate the StoreAgent plugin.
   * The acfw_install_activate_plugin endpoint handles both fresh installs
   * and activating an already-installed-but-inactive plugin.
   */
  const handleInstallOrActivate = async () => {
    setLoading(true);

    try {
      const formData = new FormData();
      formData.append('action', 'acfw_install_activate_plugin');
      formData.append('plugin_slug', pluginSlug);
      formData.append('nonce', installNonce);

      const response = await axiosInstance.post(adminAjaxUrl, formData);
      const data = response.data;

      if (data.success) {
        message.success(mode === 'install' ? upsellLabels.install_success : upsellLabels.activate_success);

        // Update the global state so other components reflect the new plugin status
        // within the current SPA session. On page refresh, PHP provides fresh values
        // via wp_localize_script, so this stays in sync with the server state.
        acfwAdminApp.is_storeagent_installed = true;
        acfwAdminApp.is_storeagent_active = true;

        // Transition the modal to the "connect" step without a page reload.
        actions.toggleStoreAgentUpsellModal({ show: true, mode: 'connect' });
      } else {
        message.error(data.data?.message || upsellLabels.install_error);
      }
    } catch (error) {
      message.error(upsellLabels.install_error);
    } finally {
      setLoading(false);
    }
  };

  const getModalContent = () => {
    switch (mode) {
      case 'install':
        return {
          title: upsellLabels.install_title,
          description: upsellLabels.install_description,
          primaryButton: {
            text: upsellLabels.install_button,
            onClick: handleInstallOrActivate,
            loading,
          },
          secondaryButton: {
            text: upsellLabels.learn_more,
            onClick: () => {
              window.open(storeagentWebsiteUrl, '_blank');
            },
          },
        };

      case 'activate':
        return {
          title: upsellLabels.activate_title,
          description: upsellLabels.activate_description,
          primaryButton: {
            text: upsellLabels.activate_button,
            onClick: handleInstallOrActivate,
            loading,
          },
          secondaryButton: {
            text: upsellLabels.cancel,
            onClick: handleClose,
          },
        };

      case 'connect':
        return {
          title: upsellLabels.connect_title,
          description: upsellLabels.connect_description,
          primaryButton: {
            text: upsellLabels.connect_button,
            onClick: () => {
              window.open(storeagentConnectUrl, '_blank');
              handleClose();
            },
            loading: false,
          },
          secondaryButton: {
            text: upsellLabels.cancel,
            onClick: handleClose,
          },
        };

      default:
        return null;
    }
  };

  const content = getModalContent();

  if (!content) return null;

  return (
    <Modal
      className="storeagent-upsell-modal"
      open={showModal}
      centered
      width={560}
      onCancel={handleClose}
      footer={null}
      closable={true}
    >
      <div className="storeagent-upsell-body">
        {/* Logos */}
        {(acfwLogoUrl || logoUrl) && (
          <div className="storeagent-logo-container">
            {acfwLogoUrl && <img src={acfwLogoUrl} alt="Advanced Coupons" className="acfw-logo" />}
            {acfwLogoUrl && logoUrl && <span className="logo-separator">+</span>}
            {logoUrl && <img src={logoUrl} alt="StoreAgent" className="storeagent-logo" />}
          </div>
        )}

        {/* Title */}
        <Title level={2} className="storeagent-title">
          {content.title}
        </Title>

        {/* Description */}
        <Paragraph className="storeagent-description">{content.description}</Paragraph>

        {/* Buttons */}
        <Space direction="horizontal" size="middle" className="storeagent-actions">
          <Button onClick={content.secondaryButton.onClick} disabled={loading}>
            {content.secondaryButton.text}
          </Button>
          <Button
            type="primary"
            onClick={content.primaryButton.onClick}
            loading={content.primaryButton.loading}
            disabled={loading && !content.primaryButton.loading}
          >
            {content.primaryButton.text}
          </Button>
        </Space>
      </div>
    </Modal>
  );
};

const mapStateToProps = (state: IStore) => ({
  showModal: state.couponTemplates?.storeagentUpsellModal ?? false,
  mode: state.couponTemplates?.storeagentUpsellMode ?? 'install',
});

const mapDispatchToProps = (dispatch: any) => ({
  actions: bindActionCreators({ toggleStoreAgentUpsellModal }, dispatch),
});

export default connect(mapStateToProps, mapDispatchToProps)(StoreAgentUpsellModal);

// #endregion [Component]
