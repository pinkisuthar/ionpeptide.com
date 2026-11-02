// #region [Imports] ===================================================================================================

// Libraries
import React, { useState } from 'react';

// Ant Design Components
import { Row, Col, Button, Popover, Divider, message } from 'antd';
import { QuestionCircleOutlined } from '@ant-design/icons';

// Helpers
import axiosInstance from '../../../helpers/axios';
import { sanitizeHtml } from '../../../../shared/utils';

// SCSS
import './index.scss';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces] ================================================================================================

interface IField {
  id: string;
  title: string;
  desc?: string;
  desc_tip?: string;
  labels: {
    running: string;
    run: string;
  };
}

interface IProps {
  field: IField;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const ClearDetachedStoreCreditsField = (props: IProps) => {
  const { field } = props;
  const { id, title, desc, desc_tip, labels } = field;
  const [loading, setLoading] = useState(false);

  const tooltip = desc_tip ? <div className="setting-tooltip-content">{desc_tip}</div> : null;

  const handleClearDetachedEntries = () => {
    setLoading(true);
    axiosInstance
      .post('coupons/v1/clear-detached-store-credits')
      .then((response: any) => {
        message.success(response.data?.message ?? 'Done.');
        setLoading(false);
      })
      .catch((e: any) => {
        message.error(e?.response?.data?.message || e?.message || 'Request failed.');
        setLoading(false);
      });
  };

  return (
    <>
      <Row gutter={16} className="form-control" id={`${id}_field`} key={id}>
        <Divider />
        <Col span={8} className="setting-title-column">
          <label>
            <strong>{title}</strong>
          </label>
          {desc_tip ? (
            <Popover placement="right" content={tooltip} trigger="click">
              <QuestionCircleOutlined className="setting-tooltip-icon" />
            </Popover>
          ) : null}
        </Col>
        <Col className="setting-field-column tool-field-column" span={16}>
          {desc ? <p className="field-desc" dangerouslySetInnerHTML={{ __html: sanitizeHtml(desc) }} /> : null}
          <Button type="primary" loading={loading} onClick={handleClearDetachedEntries}>
            {loading ? labels.running : labels.run}
          </Button>
        </Col>
      </Row>
    </>
  );
};

export default ClearDetachedStoreCreditsField;

// #endregion [Component]
