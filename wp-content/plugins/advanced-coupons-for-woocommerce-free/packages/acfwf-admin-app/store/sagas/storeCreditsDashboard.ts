// #region [Imports] ===================================================================================================

// Libraries
import 'cross-fetch/polyfill';
import { put, call, takeEvery } from 'redux-saga/effects';

// Actions
import {
  IReadStoreCreditsDashboardData,
  IReadStoreCreditsHistoryData,
  EStoreCreditsDashboardActionTypes,
  StoreCreditsDashboardActions,
} from '../actions/storeCreditsDashboard';

// Helpers
import axiosInstance, { getCancelToken } from '../../helpers/axios';

// #endregion [Imports]

// #region [Sagas] =====================================================================================================

export function* readStoreCreditsDashboardDataSaga(action: {
  type: string;
  payload: IReadStoreCreditsDashboardData;
}): any {
  const { startPeriod, endPeriod, processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.get(`store-credits/v1/customers/status`, {
        params: { startPeriod, endPeriod },
        cancelToken: getCancelToken('scdashboard'),
      })
    );

    if (response && response.data) {
      yield put(
        StoreCreditsDashboardActions.setStoreCreditsDashboardData({
          status: response.data,
        })
      );

      if (typeof successCB === 'function') successCB(response);
    }
  } catch (error) {
    if (typeof failCB === 'function') failCB({ error });
  }
}

export function* readStoreCreditsHistoryDataSaga(action: { type: string; payload: IReadStoreCreditsHistoryData }): any {
  const { page, startPeriod, endPeriod, processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.get(`store-credits/v1/entries`, {
        params: {
          page,
          startPeriod,
          endPeriod,
          is_admin: true,
        },
      })
    );

    if (response && response.data && put) {
      yield put(
        StoreCreditsDashboardActions.setStoreCreditsHistoryData({
          data: response.data,
        })
      );

      if (typeof successCB === 'function') successCB(response);
    }
  } catch (e) {
    if (typeof failCB === 'function') failCB({ error: e });
  }
}

// #endregion [Sagas]

// #region [Action Listeners] ==========================================================================================

export const actionListener = [
  takeEvery(EStoreCreditsDashboardActionTypes.READ_STORE_CREDITS_DASHBOARD_DATA, readStoreCreditsDashboardDataSaga),
  takeEvery(EStoreCreditsDashboardActionTypes.READ_STORE_CREDITS_HISTORY_DATA, readStoreCreditsHistoryDataSaga),
];

// #endregion [Action Listeners]
