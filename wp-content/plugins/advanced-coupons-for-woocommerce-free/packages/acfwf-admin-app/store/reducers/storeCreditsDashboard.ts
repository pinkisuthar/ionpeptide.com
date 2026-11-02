// #region [Imports] ===================================================================================================

// Types
import { IStoreCreditStatus } from '../../types/storeCredits';

// Actions
import {
  ISetStoreCreditsDashboardData,
  EStoreCreditsDashboardActionTypes,
  ISetStoreCreditsHistoryData,
} from '../actions/storeCreditsDashboard';

// #endregion [Imports]

// #region [Reducer] ===================================================================================================

const reducer = (
  storeCreditsDashboardState: IStoreCreditStatus[] | null = null,
  action: { type: string; payload: any }
) => {
  switch (action.type) {
    case EStoreCreditsDashboardActionTypes.SET_STORE_CREDITS_DASHBOARD_DATA: {
      const { status } = action.payload as ISetStoreCreditsDashboardData;
      return { ...storeCreditsDashboardState, status: status };
    }

    case EStoreCreditsDashboardActionTypes.SET_STORE_CREDITS_HISTORY_DATA: {
      const { data } = action.payload as ISetStoreCreditsHistoryData;
      return { ...storeCreditsDashboardState, history: data };
    }

    default:
      return storeCreditsDashboardState;
  }
};

export default reducer;

// #endregion [Reducer]
