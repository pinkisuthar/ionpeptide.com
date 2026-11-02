// #region [Imports] ===================================================================================================

// Libraries
import { useEffect, useState } from 'react';
import { Card, Table, Pagination, Popover } from 'antd';
import { FileTextOutlined } from '@ant-design/icons';
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';

// Types
import { IStoreCreditEntry } from '../../types/storeCredits';

// Actions
import { StoreCreditsDashboardActions } from '../../store/actions/storeCreditsDashboard';

// Utils
import { getStoreCreditEntryPrefix } from '../../helpers/utils';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

const { readStoreCreditsHistoryData } = StoreCreditsDashboardActions;

// #endregion [Variables]

// #region [Interfaces] ================================================================================================

interface IActions {
  readStoreCreditHistory: typeof readStoreCreditsHistoryData;
}

interface IProps {
  entries: IStoreCreditEntry[];
  actions: IActions;
  filter: any;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const StoreCreditsHistory = (props: IProps) => {
  const { entries, actions, filter } = props;
  const {
    store_credits_page: { labels },
  } = acfwAdminApp;
  const [page, setPage]: [number, any] = useState(1);
  const [loading, setLoading]: [boolean, any] = useState(true);
  const [total, setTotal] = useState(0);
  // Use filter props instead of maintaining own state
  const { beforeDate, afterDate } = filter;

  useEffect(() => {
    setLoading(true);
    actions.readStoreCreditHistory({
      page,
      startPeriod: beforeDate.format('YYYY-MM-DD'),
      endPeriod: afterDate.format('YYYY-MM-DD'),
      successCB: (response) => {
        setTotal(response.headers['x-total']);
        setLoading(false);
      },
    });
  }, [page, beforeDate, afterDate]);

  /**
   * Set loading state when sources list is empty.
   */
  useEffect(() => {
    if (entries && entries.length) setLoading(false);
  }, [entries, setLoading]);

  const columns = [
    {
      title: labels.customer_name,
      dataIndex: 'customer_name',
      key: 'customer_name',
    },
    {
      title: labels.email,
      dataIndex: 'customer_email',
      key: 'customer_email',
    },
    {
      title: labels.amount,
      dataIndex: 'amount',
      key: 'amount',
      render: (text: string, record: IStoreCreditEntry) => {
        return `${getStoreCreditEntryPrefix(record)}${text}`;
      },
    },
    {
      title: labels.activity,
      dataIndex: 'activity',
      key: 'activity',
      render: (text: string, record: IStoreCreditEntry) => {
        return [
          text,
          record.note ? (
            <Popover placement="top" content={record.note} trigger="click" overlayClassName="entry-note-popover">
              <FileTextOutlined style={{ paddingLeft: '5px' }} />
            </Popover>
          ) : null,
        ];
      },
    },
    {
      title: labels.related,
      dataIndex: 'rel_label',
      key: 'rel_label',
      render: (label: string, record: IStoreCreditEntry) => {
        if (!record.rel_link) return label;

        return <a href={record.rel_link}>{label}</a>;
      },
    },
    {
      title: labels.date,
      dataIndex: 'date',
      key: 'date',
    },
  ];

  return (
    <Card className="acfw-dashboard-history" title={labels.history}>
      <Table loading={loading} pagination={false} dataSource={entries} columns={columns} />
      {total > 10 && (
        <Pagination disabled={loading} current={page} total={total} onChange={setPage} showSizeChanger={false} />
      )}
    </Card>
  );
};

const mapStateToProps = (store: any) => ({ entries: store.storeCreditsDashboard?.history });

const mapDispatchToProps = (dispatch: any) => ({
  actions: bindActionCreators({ readStoreCreditHistory: readStoreCreditsHistoryData }, dispatch),
});

export default connect(mapStateToProps, mapDispatchToProps)(StoreCreditsHistory);

// #endregion [Component]
