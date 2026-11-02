// #region [Imports] ===================================================================================================

// Libraries
import { useEffect, useMemo, useCallback } from 'react';
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';

// Types
import { ICouponTemplateListItem } from '../../types/couponTemplates';
import { IStore } from '../../types/store';

// Actions
import { CouponTemplatesActions } from '../../store/actions/couponTemplates';

// Components
import TemplatesSkeleton from './TemplatesSkeleton';
import TemplatesList from './TemplatesList';
import SearchFilter from './SearchFilter';

// #endregion [Imports]

// #region [Variables] =================================================================================================

const { readCouponTemplates, setCouponTemplatesLoading, setSearchFilters, setSortOptions } = CouponTemplatesActions;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IActions {
  readCouponTemplates: typeof readCouponTemplates;
  setCouponTemplatesLoading: typeof setCouponTemplatesLoading;
  setSearchFilters: typeof setSearchFilters;
  setSortOptions: typeof setSortOptions;
}

interface IProps {
  templates: ICouponTemplateListItem[];
  loading: boolean;
  searchFilters: {
    searchTerm: string;
    licenseFilter: string;
  };
  sortOptions: {
    sortBy: 'title' | 'date';
    sortOrder: 'asc' | 'desc';
  };
  actions: IActions;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const ReviewTemplates = (props: IProps) => {
  const { templates, loading, searchFilters, sortOptions, actions } = props;

  // Filter and sort templates based on search criteria and sort options
  const filteredTemplates = useMemo(() => {
    let filtered = [...templates];

    // Filter by search term (title and description)
    if (searchFilters.searchTerm.trim()) {
      const searchTerm = searchFilters.searchTerm.toLowerCase().trim();
      filtered = filtered.filter(
        (template) =>
          template.title.toLowerCase().includes(searchTerm) || template.description.toLowerCase().includes(searchTerm)
      );
    }

    // Filter by license type
    if (searchFilters.licenseFilter !== 'all') {
      filtered = filtered.filter((template) => template.license_type === searchFilters.licenseFilter);
    }

    // Sort templates
    filtered.sort((a, b) => {
      let comparison = 0;

      switch (sortOptions.sortBy) {
        case 'title':
          comparison = a.title.localeCompare(b.title);
          break;
        case 'date':
          const dateA = a.date ? new Date(a.date).getTime() : 0;
          const dateB = b.date ? new Date(b.date).getTime() : 0;
          comparison = dateA - dateB;
          break;
        default:
          comparison = 0;
      }

      return sortOptions.sortOrder === 'desc' ? -comparison : comparison;
    });

    return filtered;
  }, [templates, searchFilters, sortOptions]);

  const handleSearchChange = useCallback(
    (searchTerm: string) => {
      actions.setSearchFilters({
        searchTerm,
        licenseFilter: searchFilters.licenseFilter,
      });
    },
    [actions, searchFilters.licenseFilter]
  );

  const handleLicenseFilterChange = useCallback(
    (licenseFilter: string) => {
      actions.setSearchFilters({
        searchTerm: searchFilters.searchTerm,
        licenseFilter,
      });
    },
    [actions, searchFilters.searchTerm]
  );

  const handleSortByChange = useCallback(
    (sortBy: 'title' | 'date') => {
      actions.setSortOptions({
        sortBy,
        sortOrder: sortOptions.sortOrder,
      });
    },
    [actions, sortOptions.sortOrder]
  );

  const handleSortOrderChange = useCallback(
    (sortOrder: 'asc' | 'desc') => {
      actions.setSortOptions({
        sortBy: sortOptions.sortBy,
        sortOrder,
      });
    },
    [actions, sortOptions.sortBy]
  );

  useEffect(() => {
    actions.setCouponTemplatesLoading({ loading: true });
    actions.readCouponTemplates({
      isReview: true,
      successCB: () => actions.setCouponTemplatesLoading({ loading: false }),
    });
  }, [actions]);

  if (loading) {
    return (
      <>
        <SearchFilter
          onSearchChange={handleSearchChange}
          onLicenseFilterChange={handleLicenseFilterChange}
          onSortByChange={handleSortByChange}
          onSortOrderChange={handleSortOrderChange}
          searchTerm={searchFilters.searchTerm}
          licenseFilter={searchFilters.licenseFilter}
          sortBy={sortOptions.sortBy}
          sortOrder={sortOptions.sortOrder}
        />
        <TemplatesSkeleton className="queried-templates-list" />
      </>
    );
  }

  return (
    <>
      <SearchFilter
        onSearchChange={handleSearchChange}
        onLicenseFilterChange={handleLicenseFilterChange}
        onSortByChange={handleSortByChange}
        onSortOrderChange={handleSortOrderChange}
        searchTerm={searchFilters.searchTerm}
        licenseFilter={searchFilters.licenseFilter}
        sortBy={sortOptions.sortBy}
        sortOrder={sortOptions.sortOrder}
      />
      <TemplatesList templates={filteredTemplates} isReview={true} />
    </>
  );
};

const mapStateToProps = (state: IStore) => ({
  templates: state.couponTemplates?.review ?? [],
  loading: state.couponTemplates?.loading ?? false,
  searchFilters: state.couponTemplates?.searchFilters ?? { searchTerm: '', licenseFilter: 'all' },
  sortOptions: state.couponTemplates?.sortOptions ?? { sortBy: 'title', sortOrder: 'asc' },
});

const mapDispatchToProps = (dispatch: any) => ({
  actions: bindActionCreators(
    { readCouponTemplates, setCouponTemplatesLoading, setSearchFilters, setSortOptions },
    dispatch
  ),
});

export default connect(mapStateToProps, mapDispatchToProps)(ReviewTemplates);

// #endregion [Component]
