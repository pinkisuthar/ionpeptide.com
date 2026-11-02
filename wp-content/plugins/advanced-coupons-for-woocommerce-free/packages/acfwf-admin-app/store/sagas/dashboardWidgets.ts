// #region [Imports] ===================================================================================================

// Libraries
import 'cross-fetch/polyfill';
import { put, call, takeEvery } from 'redux-saga/effects';

// Actions
import {
  IReadDashboardWidgetsData,
  EDashboardWidgetsActionTypes,
  DashboardWidgetsActions,
} from '../actions/dashboardWidgets';

// Helpers
import axiosInstance, { getCancelToken, isCancel } from '../../helpers/axios';

// #endregion [Imports]

// #region [Sagas] =====================================================================================================

export function* readDashboardWidgetsDataSaga(action: { type: string; payload: IReadDashboardWidgetsData }): any {
  const { startPeriod, endPeriod, processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.get(`coupons/v1/reports`, {
        params: { startPeriod, endPeriod },
        cancelToken: getCancelToken('dashboardwidgets'),
      }),
    );

    // A catchable server failure can still come back as HTTP 200 carrying an HTML fatal body
    // instead of a JSON array (issue #1618) — treat anything that isn't an array as a failure
    // instead of dispatching it as widget data.
    if (response && Array.isArray(response.data)) {
      yield put(
        DashboardWidgetsActions.setDashboardWidgetsData({
          widgets: response.data,
        }),
      );

      if (typeof successCB === 'function') successCB(response);
    } else if (typeof failCB === 'function') {
      failCB({ error: response });
    }
  } catch (error) {
    // Changing the report period cancels the in-flight request on purpose. That rejection is not
    // a failure, and surfacing it would show an error notice during normal use (issue #1618).
    if (isCancel(error)) {
      return;
    }

    if (typeof failCB === 'function') failCB({ error });
  }
}

// #endregion [Sagas]

// #region [Action Listeners] ==========================================================================================

export const actionListener = [
  takeEvery(EDashboardWidgetsActionTypes.READ_DASHBOARD_WIDGETS_DATA, readDashboardWidgetsDataSaga),
];

// #endregion [Action Listeners]
