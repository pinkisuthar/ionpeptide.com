// #region [Imports] ===================================================================================================

// Libraries
import { useState, useEffect, useRef } from 'react';
import { Input, Select, Row, Col } from 'antd';
import { SearchOutlined } from '@ant-design/icons';

// Components
import SortControl from './SortControl';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

const { Option } = Select;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IProps {
  onSearchChange: (searchTerm: string) => void;
  onLicenseFilterChange: (licenseType: string) => void;
  onSortByChange: (sortBy: 'title' | 'date') => void;
  onSortOrderChange: (sortOrder: 'asc' | 'desc') => void;
  searchTerm: string;
  licenseFilter: string;
  sortBy: 'title' | 'date';
  sortOrder: 'asc' | 'desc';
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const SearchFilter = (props: IProps) => {
  const {
    onSearchChange,
    onLicenseFilterChange,
    onSortByChange,
    onSortOrderChange,
    searchTerm,
    licenseFilter,
    sortBy,
    sortOrder,
  } = props;
  const { labels } = acfwAdminApp.coupon_templates_page;
  const [localSearchTerm, setLocalSearchTerm] = useState(searchTerm);
  const onSearchChangeRef = useRef(onSearchChange);

  // Keep the ref updated
  useEffect(() => {
    onSearchChangeRef.current = onSearchChange;
  }, [onSearchChange]);

  // Debounce search input
  useEffect(() => {
    const timeoutId = setTimeout(() => {
      onSearchChangeRef.current(localSearchTerm);
    }, 300);

    return () => clearTimeout(timeoutId);
  }, [localSearchTerm]);

  const handleSearchInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setLocalSearchTerm(e.target.value);
  };

  const handleLicenseFilterChange = (value: string) => {
    onLicenseFilterChange(value);
  };

  return (
    <div className="coupon-templates-search-filter">
      <Row gutter={[16, 16]} align="middle" className="search-filter-row">
        <Col xs={24} sm={24} md={8} lg={12}>
          <Input
            placeholder={labels.search_templates_placeholder}
            prefix={<SearchOutlined />}
            value={localSearchTerm}
            onChange={handleSearchInputChange}
            allowClear
            size="small"
          />
        </Col>
        <Col xs={24} sm={12} md={7} lg={5}>
          <Select value={licenseFilter} onChange={handleLicenseFilterChange} style={{ width: '100%' }} size="middle">
            <Option value="all">{labels.all_templates}</Option>
            <Option value="free">{labels.free_templates}</Option>
            <Option value="premium">{labels.premium_templates}</Option>
          </Select>
        </Col>
        <Col xs={24} sm={12} md={9} lg={7}>
          <SortControl
            sortBy={sortBy}
            sortOrder={sortOrder}
            onSortByChange={onSortByChange}
            onSortOrderChange={onSortOrderChange}
          />
        </Col>
      </Row>
    </div>
  );
};

export default SearchFilter;

// #endregion [Component]
