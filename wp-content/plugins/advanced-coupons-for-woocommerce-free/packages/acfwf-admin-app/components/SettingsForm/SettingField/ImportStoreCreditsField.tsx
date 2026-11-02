// #region [Imports] ===================================================================================================

// Libraries
import React, { useState, useEffect, useMemo } from 'react';

// Types
import { ISectionField } from '../../../types/section';

// Ant Design Components
import { Row, Col, Checkbox, Select, Popover, Button, Progress, message, Descriptions, Divider } from 'antd';
import { QuestionCircleOutlined } from '@ant-design/icons';

// Helpers
import axiosInstance from '../../../helpers/axios';
import { sanitizeHtml } from '../../../../shared/utils';

// SCSS
import './index.scss';

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces] ================================================================================================

interface IProps {
  field: ISectionField;
}

interface ICheckImportResponse {
  total: number;
  completed: number;
  pending: number;
  failed: number;
}

interface ImportSummary {
  total: number;
  imported: number;
  failed: number;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const ImportStoreCreditsField = (props: IProps) => {
  const { field } = props;
  const { id, title, desc, desc_tip, placeholder, options, data, labels } = field;
  const [selected, setSelected]: [string | null, any] = useState(data?.plugin ?? null);
  const [pluginOptions, setPluginOptions] = useState(options ?? []);
  const [deactivate, setDeactivate] = useState(data ? data?.deactivate : true);
  const [loading, setLoading] = useState(!!data?.plugin);
  const [importDone, setImportDone] = useState(false);
  const [percentage, setPercentage] = useState(0);
  const [importSummary, setImportSummary]: [ImportSummary, any] = useState({ total: 0, imported: 0, failed: 0 });
  const [totalImportedStoreCredits, setTotalImportedStoreCredits] = useState(0);

  // Tooltip child component.
  const tooltip = desc_tip ? <div className="setting-tooltip-content">{desc_tip}</div> : null;

  // Get the selected plugin name.
  const selectedPlugin = useMemo(() => {
    if (!pluginOptions?.length || !selected) return '';

    const index = pluginOptions?.findIndex((o) => o.key.toString() === selected);

    if (index <= -1) return '';

    return pluginOptions[index].label;
  }, [selected, pluginOptions]);

  /**
   * Event for when the selected plugin value is changed.
   *
   * @since 4.6.7
   *
   * @param {string|null} value Plugin value to set.
   */
  const onSelectPlugin = (value: string | null) => {
    setSelected(value);
    setImportDone(false);
  };

  /**
   * Event for remove plugin.
   *
   * @since 4.6.7
   *
   * @param {string|null} value selected plugin.
   */
  const onRemovePlugin = (value: string | null) => {
    setSelected(null);

    setPluginOptions((prev) => {
      const index = prev.findIndex((o: any) => o.label?.key.toString() === String(value));

      if (index > -1) {
        const newOptions = [...prev];
        newOptions.splice(index, 1);
        return newOptions;
      }
      return prev;
    });
  };

  /**
   * Handle import store credits events when the "Import" button is clicked.
   *
   * @since 4.6.7
   */
  const handleImportStoreCredits = () => {
    setLoading(true);
    axiosInstance
      .post(`coupons/v1/import-store-credits`, { plugin: selected, deactivate: deactivate })
      .then(() => checkImportProgress())
      .catch((e: any) => {
        message.error(e.response.data.message);

        if (e.response.data.code === 'acfw_invalid_plugin_importer') {
          onRemovePlugin(selected);
        }

        setLoading(false);
      });
  };

  /**
   * Check the progress of the import store credits process.
   *
   * @since 4.6.7
   */
  const checkImportProgress = () => {
    axiosInstance
      .get(`coupons/v1/import-store-credits`, { params: { plugin: selected } })
      .then((response: any) => {
        const { data }: { data: ICheckImportResponse } = response.data;
        const calcPercentage = 100 - (data.pending / data.total) * 100;
        setPercentage(parseInt(calcPercentage.toString()));

        if (data.pending > 0) {
          setTimeout(() => checkImportProgress(), 2000);
          return;
        }

        const { summary, total_store_credits }: { summary: ImportSummary; total_store_credits: number } = response.data;

        setLoading(false);
        setImportSummary(summary);
        setTotalImportedStoreCredits(total_store_credits);
        setImportDone(true);

        if (deactivate) {
          onRemovePlugin(selected);
        }

        message.success(response.data.message);
      })
      .catch((e: any) => {
        message.error(e.response.data.message);
        setLoading(false);
      });
  };

  /**
   * Trigger check of import progress on initial load of the component when an import process has already been started.
   *
   * @since 4.6.7
   */
  useEffect(() => {
    if (!!data?.plugin) {
      checkImportProgress();
    }
  }, []);

  return (
    <>
      <Row gutter={16} className="form-control" id={`${id}_field`} key={id}>
        <Divider />
        <Col span={8}>
          <label className="lpfw-setting-field-label tool-field-label">
            <strong>{title}</strong>
          </label>
          {desc_tip ? (
            <Popover placement="right" content={tooltip} trigger="click">
              <QuestionCircleOutlined className="setting-tooltip-icon" />
            </Popover>
          ) : null}
        </Col>
        <Col className="setting-field-column tool-field-column" span={16}>
          {pluginOptions?.length ? (
            <>
              {desc ? <p className="field-desc" dangerouslySetInnerHTML={{ __html: sanitizeHtml(desc) }} /> : null}
              <div className="import-store-credits-form-wrapper">
                <Select
                  style={{ width: `100%` }}
                  placeholder={placeholder}
                  allowClear={true}
                  value={selected}
                  disabled={loading}
                  onSelect={(value: string) => onSelectPlugin(value)}
                  onClear={() => onSelectPlugin(null)}
                >
                  {pluginOptions.map(({ label }) => {
                    const objLabel = label as unknown as { key: string | number; label: string };
                    return (
                      <Select.Option key={objLabel.key.toString()} value={objLabel.key.toString()}>
                        {objLabel.label}
                      </Select.Option>
                    );
                  })}
                </Select>

                <Checkbox
                  className="deactivate-plugin-checkbox"
                  checked={deactivate}
                  disabled={loading}
                  onChange={(e: any) => setDeactivate(e.target.checked)}
                >
                  {labels.deactivate_plugin}
                </Checkbox>

                <Button
                  type="primary"
                  disabled={!selected || importDone}
                  loading={loading}
                  onClick={() => handleImportStoreCredits()}
                >
                  {labels.import_btn}
                </Button>
              </div>

              {(loading || importDone) && (
                <div className="import-store-credits-progress-wrapper">
                  <p
                    dangerouslySetInnerHTML={{
                      __html: sanitizeHtml(labels.progress_text.replace('%s', `<strong>${selectedPlugin}</strong>`)),
                    }}
                  />
                  <Progress
                    percent={percentage}
                    status={100 > percentage ? 'active' : undefined}
                    strokeColor={{ from: '#108ee9', to: '#87d068' }}
                  />
                  {importDone && (
                    <Descriptions column={1} bordered={true} size="small">
                      <Descriptions.Item label={labels.processed}>
                        {importSummary.imported.toLocaleString()} {labels.users}
                      </Descriptions.Item>
                      <Descriptions.Item label={labels.failed}>
                        {importSummary.failed.toLocaleString()} {labels.users}
                      </Descriptions.Item>
                      <Descriptions.Item label={labels.total_imported_store_credits}>
                        {totalImportedStoreCredits.toLocaleString()} {labels.store_credits}
                      </Descriptions.Item>
                    </Descriptions>
                  )}
                </div>
              )}
            </>
          ) : (
            <p className="field-desc">{labels.no_plugins_found}</p>
          )}
        </Col>
      </Row>
    </>
  );
};

export default ImportStoreCreditsField;

// #endregion [Component]
