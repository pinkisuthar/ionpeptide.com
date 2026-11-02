// #region [Imports] ===================================================================================================

// Libraries
import { Select, Row, Col } from 'antd';
import { SortAscendingOutlined, SortDescendingOutlined } from '@ant-design/icons';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

const { Option } = Select;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IProps {
  sortBy: 'title' | 'date';
  sortOrder: 'asc' | 'desc';
  onSortByChange: (sortBy: 'title' | 'date') => void;
  onSortOrderChange: (sortOrder: 'asc' | 'desc') => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const SortControl = (props: IProps) => {
  const { sortBy, sortOrder, onSortByChange, onSortOrderChange } = props;
  const { labels } = acfwAdminApp.coupon_templates_page;

  const handleSortByChange = (value: 'title' | 'date') => {
    onSortByChange(value);
  };

  const handleSortOrderToggle = () => {
    onSortOrderChange(sortOrder === 'asc' ? 'desc' : 'asc');
  };

  const getSortByLabel = (value: string) => {
    switch (value) {
      case 'title':
        return labels.sort_by_title;
      case 'date':
        return labels.sort_by_date;
      default:
        return value;
    }
  };

  return (
    <div className="coupon-templates-sort-control">
      <Row gutter={8} align="middle">
        <Col>
          <span className="sort-label">{labels.sort_by}:</span>
        </Col>
        <Col>
          <Select value={sortBy} onChange={handleSortByChange} style={{ width: 120 }}>
            <Option value="title">{getSortByLabel('title')}</Option>
            <Option value="date">{getSortByLabel('date')}</Option>
          </Select>
        </Col>
        <Col>
          <div
            className={`sort-order-toggle ${sortOrder}`}
            onClick={handleSortOrderToggle}
            title={sortOrder === 'asc' ? labels.sort_ascending : labels.sort_descending}
          >
            {sortOrder === 'asc' ? <SortAscendingOutlined /> : <SortDescendingOutlined />}
          </div>
        </Col>
      </Row>
    </div>
  );
};

export default SortControl;

// #endregion [Component]
