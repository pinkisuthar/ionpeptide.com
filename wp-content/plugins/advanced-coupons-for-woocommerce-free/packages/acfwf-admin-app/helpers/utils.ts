// #region [Imports] ===================================================================================================

import moment from 'moment';
import { IStoreCreditEntry } from '../types/storeCredits';
import { IFieldOption } from '../types/fields';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;
declare var location: any;
declare var ajaxurl: any;

// #endregion [Variables]

// #region [Functions] ==================================================================================================

export const getPathPrefix = function () {
  return acfwAdminApp.admin_url.replace(location.origin, '');
};

export const validateURL = (str: string) => {
  const pattern = new RegExp(
    '^(https?:\\/\\/)?' + // protocol
      '((([a-z\\d]([a-z\\d-]*[a-z\\d])*)\\.)+[a-z]{2,}|' + // domain name
      '((\\d{1,3}\\.){3}\\d{1,3}))' + // OR ip (v4) address
      '(\\:\\d+)?(\\/[-a-z\\d%_.~+]*)*' + // port and path
      '(\\?[;&a-z\\d%_.~+=-]*)?' + // query string
      '(\\#[-a-z\\d_]*)?$',
    'i'
  ); // fragment locator
  return !!pattern.test(str);
};

export const getDateRangeMomentValues = (value: string) => {
  let startPeriod: moment.Moment = moment().startOf('month');
  let endPeriod: moment.Moment = moment().startOf('day');

  switch (value) {
    case 'week_to_date':
      startPeriod = moment().startOf('week');
      endPeriod = moment().startOf('day');
      break;
    case 'month_to_date':
      startPeriod = moment().startOf('month');
      endPeriod = moment().startOf('day');
      break;
    case 'quarter_to_date':
      startPeriod = moment().startOf('quarter');
      endPeriod = moment().startOf('day');
      break;
    case 'year_to_date':
      startPeriod = moment().startOf('year');
      endPeriod = moment().startOf('day');
      break;
    case 'last_week':
      startPeriod = moment().subtract(1, 'weeks').startOf('week');
      endPeriod = moment().subtract(1, 'weeks').endOf('week');
      break;
    case 'last_month':
      startPeriod = moment().subtract(1, 'months').startOf('month');
      endPeriod = moment().subtract(1, 'months').endOf('month');
      break;
    case 'last_quarter':
      startPeriod = moment().subtract(1, 'quarters').startOf('quarter');
      endPeriod = moment().subtract(1, 'quarters').endOf('quarter');
      break;
    case 'last_year':
      startPeriod = moment().subtract(1, 'years').startOf('year');
      endPeriod = moment().subtract(1, 'years').endOf('year');
      break;
  }

  return [startPeriod, endPeriod];
};

/**
 * Search category options.
 *
 * @param value
 * @param exclude
 * @returns {IFieldOption[]}
 */
export async function searchCategoryOptions(value: string, exclude: number[]) {
  const response = await jQuery.ajax({
    url: ajaxurl,
    type: 'GET',
    data: {
      action: 'woocommerce_json_search_taxonomy_terms',
      term: value,
      taxonomy: 'product_cat',
      security: acfwAdminApp.nonces.search_taxonomy_terms,
      exclude: exclude,
    },
  });

  return Object.keys(response).map((key) => ({
    label: response[key].name,
    value: response[key].term_id,
  }));
}

export async function searchProductOptions(value: string, exclude: number[]) {
  const response = await jQuery.ajax({
    url: ajaxurl,
    type: 'GET',
    data: {
      action: 'woocommerce_json_search_products',
      term: value,
      security: acfwAdminApp.nonces.search_products,
      exclude: exclude,
    },
  });

  return Object.keys(response).map((key) => ({
    label: response[key],
    value: key,
  }));
}

/**
 * Get the prefix (+/-) for store credit entry.
 *
 * @param {IStoreCreditEntry} record
 * @returns
 */
export function getStoreCreditEntryPrefix(record: IStoreCreditEntry) {
  return 'increase' === record.type ? '+' : '-';
}

/**
 * Get condition select field options.
 *
 * @returns {Array<{ value: string; label: string }>}
 */
export function getConditionOptions(type = 'default') {
  if (type === 'atleast') {
    return [
      {
        value: 'atleast',
        label: acfwAdminApp.condition_options.atleast,
      },
      {
        value: 'all',
        label: acfwAdminApp.condition_options.all,
      },
    ];
  }

  if (type === 'period') {
    return [
      {
        value: 'within-a-period',
        label: acfwAdminApp.condition_options.withinaperiod,
      },
      {
        value: 'number-of-orders',
        label: acfwAdminApp.condition_options.numberoforders,
      },
    ];
  }

  return [
    {
      value: '=',
      label: acfwAdminApp.condition_options.exactly,
    },
    {
      value: '!=',
      label: acfwAdminApp.condition_options.anyexcept,
    },
    {
      value: '>',
      label: acfwAdminApp.condition_options.morethan,
    },
    {
      value: '<',
      label: acfwAdminApp.condition_options.lessthan,
    },
  ];
}

// #endregion [Functions]
