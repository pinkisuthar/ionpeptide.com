// #region [Imports] ===================================================================================================

// Libraries
import { useState, useEffect } from 'react';
import { Card, Table, DatePicker, Select, Divider } from 'antd';
// @ts-ignore
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';
import moment from 'moment';

// Components
import StoreCreditsHistory from './History';

// Actions
import { StoreCreditsDashboardActions } from '../../store/actions/storeCreditsDashboard';

// Types
import { IStoreCreditStatus } from '../../types/storeCredits';

// Helpers
import { axiosCancel } from '../../helpers/axios';

// SCSS
import './index.scss';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

const { readStoreCreditsDashboardData } = StoreCreditsDashboardActions;

// #endregion [Variables]

// #region [Interfaces] ================================================================================================

interface IActions {
  readDashboardData: typeof readStoreCreditsDashboardData;
}

interface IProps {
  status: IStoreCreditStatus[];
  actions: IActions;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const StoreCreditsDashboard = (props: IProps) => {
  const { status, actions } = props;
  const {
    store_credits_page: { labels, period_options },
  } = acfwAdminApp;
  const [loading, setLoading] = useState(false);
  const [periodValue, setPeriodValue] = useState('month_to_date');
  const [startPeriod, setStartPeriod] = useState(moment().startOf('month'));
  const [endPeriod, setEndPeriod] = useState(moment().startOf('day'));
  // Combine filter state and handlers from both components
  const [historyFilter, setHistoryFilter] = useState({
    periodValue: 'month_to_date',
    beforeDate: moment().startOf('month'),
    afterDate: moment().startOf('day'),
  });
  const handleHistoryFilterChange = (filter: any) => {
    setHistoryFilter(filter);
  };

  const columns = [
    {
      title: labels.statistics,
      dataIndex: 'label',
      key: 'label',
    },
    {
      title: labels.amount,
      dataIndex: 'amount',
      key: 'amount',
    },
  ];

  /**
   * Initialize loading dashboard data.
   */
  useEffect(() => {
    setLoading(true);
    axiosCancel('scdashboard');
    actions.readDashboardData({
      startPeriod: startPeriod.format('YYYY-MM-DD'),
      endPeriod: endPeriod.format('YYYY-MM-DD'),
      successCB: () => setLoading(false),
    });
  }, [startPeriod, endPeriod]);

  const handlePeriodChange = (value: string) => {
    setPeriodValue(value);
    let start, end;

    switch (value) {
      case 'week_to_date':
        start = moment().startOf('week');
        end = moment().startOf('day');
        break;
      case 'month_to_date':
        start = moment().startOf('month');
        end = moment().startOf('day');
        break;
      case 'quarter_to_date':
        start = moment().startOf('quarter');
        end = moment().startOf('day');
        break;
      case 'year_to_date':
        start = moment().startOf('year');
        end = moment().startOf('day');
        break;
      case 'last_week':
        start = moment().subtract(1, 'weeks').startOf('week');
        end = moment().subtract(1, 'weeks').endOf('week');
        break;
      case 'last_month':
        start = moment().subtract(1, 'months').startOf('month');
        end = moment().subtract(1, 'months').endOf('month');
        break;
      case 'last_quarter':
        start = moment().subtract(1, 'quarters').startOf('quarter');
        end = moment().subtract(1, 'quarters').endOf('quarter');
        break;
      case 'last_year':
        start = moment().subtract(1, 'years').startOf('year');
        end = moment().subtract(1, 'years').endOf('year');
        break;
      default:
        start = moment().startOf('day');
        end = moment().startOf('day');
        break;
    }

    setStartPeriod(start);
    setEndPeriod(end);

    // Call handleHistoryFilterChange with the updated filter values
    handleHistoryFilterChange({ periodValue: value, beforeDate: start, afterDate: end });
  };

  const handleCustomDateRange = (values: any) => {
    setStartPeriod(values[0]);
    setEndPeriod(values[1]);
    setPeriodValue('custom');

    // Call handleHistoryFilterChange with the updated filter values
    handleHistoryFilterChange({ periodValue: 'custom', beforeDate: values[0], afterDate: values[1] });
  };

  return (
    <>
      <div className="store-credits-period-selector">
        <Select value={periodValue} onSelect={handlePeriodChange}>
          {period_options.map((period: { value: string; label: string }) => (
            <Select.Option value={period.value}>{period.label}</Select.Option>
          ))}
        </Select>
        <DatePicker.RangePicker value={[startPeriod, endPeriod]} onChange={handleCustomDateRange} />
      </div>
      <Card title={labels.status}>
        <Table loading={loading} pagination={false} dataSource={status} columns={columns} />
      </Card>
      <Divider />
      <StoreCreditsHistory filter={historyFilter} />
    </>
  );
};

const mapStateToProps = (store: any) => ({ status: store.storeCreditsDashboard?.status });

const mapDispatchToProps = (dispatch: any) => ({
  actions: bindActionCreators({ readDashboardData: readStoreCreditsDashboardData }, dispatch),
});

export default connect(mapStateToProps, mapDispatchToProps)(StoreCreditsDashboard);

// #endregion [Component]
