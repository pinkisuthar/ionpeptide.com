// #region [Imports] ===================================================================================================

import { useEffect, useState } from 'react';
import { useHistory } from 'react-router-dom';
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';
import { Card, Button, Empty, List, Typography, Space, Popconfirm, Tag, Row, Col } from 'antd';
import { DeleteOutlined, EyeOutlined, CrownOutlined, ThunderboltOutlined } from '@ant-design/icons';

// Components
import AIIcon from '../../components/AIIcon';

// Types
import { IStore } from '../../types/store';
import { ISavedAITemplate } from '../../types/couponTemplates';

// Actions
import { CouponTemplatesActions } from '../../store/actions/couponTemplates';

// Helpers
import { getPathPrefix } from '../../helpers/utils';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

const { Text, Title, Paragraph } = Typography;

const { readSavedAITemplates, deleteSavedAITemplate, loadSavedAITemplate } = CouponTemplatesActions;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IActions {
  readSavedAITemplates: typeof readSavedAITemplates;
  deleteSavedAITemplate: typeof deleteSavedAITemplate;
  loadSavedAITemplate: typeof loadSavedAITemplate;
}

interface IProps {
  savedTemplates: ISavedAITemplate[];
  actions: IActions;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const AITemplates = (props: IProps) => {
  const { savedTemplates, actions } = props;
  const [loading, setLoading] = useState(false);
  const history = useHistory();
  const pathPrefix = getPathPrefix();

  const isPremiumActive = acfwAdminApp.is_acfwp_active;
  const premiumLink = acfwAdminApp.coupon_templates_page.link;
  const { labels } = acfwAdminApp.coupon_templates_page;

  useEffect(() => {
    if (isPremiumActive) {
      setLoading(true);
      actions.readSavedAITemplates({ successCB: () => setLoading(false), failCB: () => setLoading(false) });
    }
  }, [isPremiumActive, actions]);

  const handleUseTemplate = (id: string) => {
    actions.loadSavedAITemplate({
      id,
      successCB: (response) => {
        // Navigate to template form
        history.push(`${pathPrefix}admin.php?page=acfw-coupon-templates&id=${id}`);
      },
      failCB: undefined,
    });
  };

  const handleDeleteTemplate = (id: string) => {
    actions.deleteSavedAITemplate({
      id,
      processingCB: undefined,
      successCB: undefined,
      failCB: undefined,
    });
  };

  // Premium Upsell
  if (!isPremiumActive) {
    return (
      <Card className="ai-templates-upsell">
        <div style={{ textAlign: 'center', padding: '40px 20px' }}>
          <CrownOutlined style={{ fontSize: 64, color: '#faad14', marginBottom: 24 }} />
          <Title level={3}>{labels.ai_upsell_title}</Title>
          <Paragraph style={{ fontSize: 16, marginBottom: 24 }}>{labels.ai_upsell_description}</Paragraph>
          <ul style={{ textAlign: 'left', display: 'inline-block', marginBottom: 24 }}>
            <li>{labels.ai_upsell_feature_save}</li>
            <li>{labels.ai_upsell_feature_reuse}</li>
            <li>{labels.ai_upsell_feature_organize}</li>
            <li>{labels.ai_upsell_feature_premium}</li>
          </ul>
          <br />
          <Button type="primary" size="large" href={premiumLink} target="_blank" icon={<CrownOutlined />}>
            {labels.upgrade_to_premium}
          </Button>
        </div>
      </Card>
    );
  }

  // Empty State
  if (!loading && savedTemplates.length === 0) {
    return (
      <Card>
        <Empty
          image={Empty.PRESENTED_IMAGE_SIMPLE}
          description={
            <span>
              <Text type="secondary">{labels.no_ai_templates}</Text>
              <br />
              <Text type="secondary">{labels.no_ai_templates_desc}</Text>
            </span>
          }
        >
          <Button
            icon={<AIIcon />}
            onClick={() => history.push(`${pathPrefix}admin.php?page=acfw-coupon-templates&show_ai_modal=true`)}
            className="ai-generate-btn"
          >
            {labels.generate_with_ai}
          </Button>
        </Empty>
      </Card>
    );
  }

  // Templates List
  return (
    <div className="ai-templates-list">
      <List
        loading={loading}
        grid={{ gutter: 16, xs: 1, sm: 1, md: 2, lg: 2, xl: 3, xxl: 3 }}
        dataSource={savedTemplates}
        renderItem={(template: ISavedAITemplate) => (
          <List.Item>
            <Card
              className="ai-template-card"
              hoverable
              actions={[
                <Button type="text" icon={<EyeOutlined />} onClick={() => handleUseTemplate(template.id)} key="use">
                  {labels.use_template}
                </Button>,
                <Popconfirm
                  title={labels.delete_template_confirm}
                  description={labels.delete_template_desc}
                  onConfirm={() => handleDeleteTemplate(template.id)}
                  okText={labels.delete_template}
                  cancelText={labels.cancel}
                  okButtonProps={{ danger: true }}
                  key="delete"
                >
                  <Button type="text" danger icon={<DeleteOutlined />}>
                    {labels.delete_template}
                  </Button>
                </Popconfirm>,
              ]}
            >
              <Card.Meta
                title={
                  <Space direction="vertical" size={4} style={{ width: '100%' }}>
                    <Text strong style={{ fontSize: 16 }}>
                      {template.title}
                    </Text>
                    <Tag color="purple" icon={<ThunderboltOutlined />}>
                      {labels.ai_generated}
                    </Tag>
                  </Space>
                }
                description={
                  <div>
                    {template.prompt && (
                      <Paragraph
                        ellipsis={{ rows: 2, expandable: false }}
                        type="secondary"
                        style={{ marginTop: 12, marginBottom: 8 }}
                      >
                        {template.prompt}
                      </Paragraph>
                    )}
                    <Text type="secondary" style={{ fontSize: 12 }}>
                      Created: {new Date(template.created_at).toLocaleDateString()}
                    </Text>
                  </div>
                }
              />
            </Card>
          </List.Item>
        )}
      />
    </div>
  );
};

const mapStateToProps = (state: IStore) => ({
  savedTemplates: state.couponTemplates?.savedAITemplates ?? [],
});

const mapDispatchToProps = (dispatch: any) => ({
  actions: bindActionCreators({ readSavedAITemplates, deleteSavedAITemplate, loadSavedAITemplate }, dispatch),
});

export default connect(mapStateToProps, mapDispatchToProps)(AITemplates);

// #endregion [Component]
