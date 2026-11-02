// #region [Imports] ===================================================================================================

// Libraries
import React, { useState, useMemo } from 'react';
import { useHistory } from 'react-router-dom';
import { Modal, Input, Button, Typography, Space, Alert } from 'antd';
import { ThunderboltOutlined, LinkOutlined } from '@ant-design/icons';
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';

// Actions
import { CouponTemplatesActions } from '../../store/actions/couponTemplates';

// Types
import { IStore } from '../../types/store';

// Helpers
import { getPathPrefix } from '../../helpers/utils';

// SCSS
import './index.scss';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

const { TextArea } = Input;
const { Text, Title } = Typography;

const MIN_PROMPT_LENGTH = 10;

const { toggleAIGeneratorModal, generateCouponWithAI } = CouponTemplatesActions;

// #endregion [Variables]

// #region [Interfaces] ================================================================================================

interface IActions {
  toggleAIGeneratorModal: typeof toggleAIGeneratorModal;
  generateCouponWithAI: typeof generateCouponWithAI;
}

interface IProps {
  showModal: boolean;
  isGenerating: boolean;
  actions: IActions;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const AIGeneratorModal = (props: IProps) => {
  const { showModal, isGenerating, actions } = props;
  const [prompt, setPrompt] = useState('');
  const [error, setError] = useState<string | null>(null);
  const history = useHistory();
  const pathPrefix = getPathPrefix();

  const labels = acfwAdminApp.ai_generator;

  // Randomly select 3 examples when modal opens
  const randomExamples = useMemo(() => {
    if (!labels.examples || labels.examples.length <= 3) {
      return labels.examples;
    }
    const shuffled = [...labels.examples].sort(() => 0.5 - Math.random());
    return shuffled.slice(0, 3);
  }, [labels.examples, showModal]);

  const handleClose = () => {
    setPrompt('');
    setError(null);
    actions.toggleAIGeneratorModal({ show: false });
  };

  const handleGenerate = () => {
    if (prompt.trim().length < MIN_PROMPT_LENGTH) {
      setError(labels.error_min_chars);
      return;
    }

    setError(null);

    actions.generateCouponWithAI({
      prompt: prompt.trim(),
      amount: 1,
      processingCB: () => {},
      successCB: (response) => {
        handleClose();
        // Navigate to template form with AI template ID and prompt
        const encodedPrompt = encodeURIComponent(prompt.trim());
        history.push(`${pathPrefix}admin.php?page=acfw-coupon-templates&id=ai-temp&ai_prompt=${encodedPrompt}`);
      },
      failCB: ({ error: err }) => {
        setError(err?.message ?? labels.error_generate);
      },
    });
  };

  const handleExampleClick = (example: string) => {
    setPrompt(example);
  };

  return (
    <Modal
      className="ai-generator-modal"
      title={
        <Space>
          <ThunderboltOutlined />
          <span>{labels.title}</span>
        </Space>
      }
      open={showModal}
      centered
      width={600}
      onCancel={handleClose}
      footer={null}
    >
      <div className="ai-generator-content">
        <Text type="secondary">{labels.description}</Text>

        <TextArea
          className="ai-generator-textarea"
          rows={4}
          value={prompt}
          onChange={(e) => setPrompt(e.target.value)}
          placeholder={labels.placeholder}
          disabled={isGenerating}
        />

        {error && <Alert type="error" message={error} showIcon style={{ marginBottom: 16 }} />}

        {labels.limitations_notice && (
          <Alert type="info" message={labels.limitations_notice} showIcon className="ai-generator-limitations" />
        )}

        <div className="ai-generator-examples">
          <Text strong>{labels.examples_title}</Text>
          <div className="example-prompts">
            {randomExamples.map((example: string, index: number) => (
              <Button
                key={index}
                type="dashed"
                size="small"
                onClick={() => handleExampleClick(example)}
                disabled={isGenerating}
              >
                {example}
              </Button>
            ))}
          </div>
        </div>

        <div className="ai-generator-actions">
          <Button onClick={handleClose} disabled={isGenerating}>
            Cancel
          </Button>
          <Button
            type="primary"
            icon={<ThunderboltOutlined />}
            onClick={handleGenerate}
            loading={isGenerating}
            disabled={prompt.trim().length < MIN_PROMPT_LENGTH}
          >
            {isGenerating ? labels.generating : labels.generate_btn}
          </Button>
        </div>
      </div>

      <div className="ai-generator-footer">
        <a
          href={acfwAdminApp?.storeagent_website_url}
          target="_blank"
          rel="noopener noreferrer"
          className="powered-by-link"
        >
          <LinkOutlined /> Powered by StoreAgent
        </a>
      </div>
    </Modal>
  );
};

const mapStateToProps = (state: IStore) => ({
  showModal: state.couponTemplates?.aiGeneratorModal ?? false,
  isGenerating: state.couponTemplates?.aiGenerating ?? false,
});

const mapDispatchToProps = (dispatch: any) => ({
  actions: bindActionCreators({ toggleAIGeneratorModal, generateCouponWithAI }, dispatch),
});

export default connect(mapStateToProps, mapDispatchToProps)(AIGeneratorModal);

// #endregion [Component]
