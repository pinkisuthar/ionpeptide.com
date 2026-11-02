// #region [Imports] ===================================================================================================

import IStoreCreditsDashboardData, { IStoreCreditStatus, IStoreCreditEntry } from '../../types/storeCredits';

// #endregion [Imports]

// #region [Action Payloads] ===========================================================================================

export interface IReadStoreCreditsDashboardData {
  startPeriod: string;
  endPeriod: string;
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface IReadStoreCreditsHistoryData {
  page: number;
  startPeriod: string;
  endPeriod: string;
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface ISetStoreCreditsDashboardData {
  status: IStoreCreditStatus[];
}

export interface ISetStoreCreditsHistoryData {
  data: IStoreCreditEntry[];
}

// #endregion [Action Payloads]

// #region [Action Types] ==============================================================================================

export enum EStoreCreditsDashboardActionTypes {
  READ_STORE_CREDITS_DASHBOARD_DATA = 'READ_STORE_CREDITS_DASHBOARD_DATA',
  READ_STORE_CREDITS_HISTORY_DATA = 'READ_STORE_CREDITS_HISTORY',
  SET_STORE_CREDITS_DASHBOARD_DATA = 'SET_STORE_CREDITS_DASHBOARD_DATA',
  SET_STORE_CREDITS_HISTORY_DATA = 'SET_STORE_CREDITS_HISTORY_DATA',
}

// #endregion [Action Types]

// #region [Action Creators] ===========================================================================================

export const StoreCreditsDashboardActions = {
  readStoreCreditsDashboardData: (payload: IReadStoreCreditsDashboardData) => ({
    type: EStoreCreditsDashboardActionTypes.READ_STORE_CREDITS_DASHBOARD_DATA,
    payload,
  }),
  readStoreCreditsHistoryData: (payload: IReadStoreCreditsHistoryData) => ({
    type: EStoreCreditsDashboardActionTypes.READ_STORE_CREDITS_HISTORY_DATA,
    payload,
  }),
  setStoreCreditsDashboardData: (payload: ISetStoreCreditsDashboardData) => ({
    type: EStoreCreditsDashboardActionTypes.SET_STORE_CREDITS_DASHBOARD_DATA,
    payload,
  }),
  setStoreCreditsHistoryData: (payload: ISetStoreCreditsHistoryData) => ({
    type: EStoreCreditsDashboardActionTypes.SET_STORE_CREDITS_HISTORY_DATA,
    payload,
  }),
};

// #endregion [Action Creators]
