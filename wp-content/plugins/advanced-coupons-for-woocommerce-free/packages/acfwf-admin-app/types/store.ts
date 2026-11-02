// #region [Imports] ===================================================================================================

import { ISection } from './section';
import { ISettingValue } from './settings';
import { IStoreCreditCustomer, IStoreCreditEntry, IStoreCreditStatus } from './storeCredits';
import { IDashboardWidget } from './dashboard';
import { ISingleNotice } from './notices';
import { ICouponTemplatesStore } from './couponTemplates';

// #endregion [Imports]

// #region [Types] =====================================================================================================

export interface IStore {
  sections: ISection[];
  settingValues: ISettingValue[];
  page: string;
  storeCreditsDashboard: IStoreCreditStatus[];
  storeCreditsHistory: IStoreCreditEntry[];
  storeCreditsCustomers: IStoreCreditCustomer[];
  dashboardWidgets: IDashboardWidget[];
  adminNotices: ISingleNotice[];
  couponTemplates: ICouponTemplatesStore | null;
}

// #endregion [Types]
