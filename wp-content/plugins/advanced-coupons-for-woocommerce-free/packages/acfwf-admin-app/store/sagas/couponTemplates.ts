// #region [Imports] ===================================================================================================

// Libraries
import 'cross-fetch/polyfill';
import { put, call, takeEvery } from 'redux-saga/effects';

// Actions
import {
  IReadCouponTemplatesPayload,
  IReadCouponTemplatePayload,
  IReadRecentCouponTemplatesPayload,
  IReadCouponTemplateCategoriesPayload,
  IReadCouponTemplateCategoryPayload,
  ICreateCouponFromTemplatePayload,
  IDeleteRecentCouponTemplatePayload,
  IGenerateCouponWithAIPayload,
  IReadAICouponTemplatePayload,
  ISaveAITemplatePayload,
  IReadSavedAITemplatesPayload,
  IDeleteSavedAITemplatePayload,
  ILoadSavedAITemplatePayload,
  CouponTemplatesActions,
  ECouponTemplatesActionTypes,
} from '../actions/couponTemplates';

// Helpers
import axiosInstance, { getCancelToken } from '../../helpers/axios';
import { ICartConditionField, ICartConditionGroup } from '../../types/couponTemplates';

// #endregion [Imports]

// #endregion [Helpers]

// #region [Sagas] =====================================================================================================

export function* readCouponTemplatesSaga(action: { type: string; payload: IReadCouponTemplatesPayload }): any {
  const { isReview = false, processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.get(`coupons/v1/templates`, {
        cancelToken: getCancelToken('coupon_templates'),
        params: isReview ? { is_review: 1 } : {},
      }),
    );

    if (response && response.data) {
      yield put(
        CouponTemplatesActions.setCouponTemplates({
          data: response.data,
          type: isReview ? 'review' : 'main',
        }),
      );

      if (typeof successCB === 'function') successCB(response);
    }
  } catch (error) {
    if (typeof failCB === 'function') failCB({ error });
  }
}

export function* readCouponTemplateSaga(action: { type: string; payload: IReadCouponTemplatePayload }): any {
  const { id, isReview, processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.get(`coupons/v1/templates/${id}`, {
        cancelToken: getCancelToken('coupon_template'),
        params: isReview ? { is_review: 1 } : {},
      }),
    );

    if (response && response.data) {
      response.data.fields = response.data.fields.map((field: any) => {
        field.value = field.pre_filled_value ?? '';
        return field;
      });

      if (typeof successCB === 'function') successCB(response);
    }
  } catch (error) {
    if (typeof failCB === 'function') failCB({ error });
  }
}

export function* readRecentCouponTemplatesSaga(action: {
  type: string;
  payload: IReadRecentCouponTemplatesPayload;
}): any {
  const { processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.get(`coupons/v1/templates/recent`, {
        cancelToken: getCancelToken('recent_coupon_templates'),
      }),
    );

    if (response && response.data) {
      yield put(
        CouponTemplatesActions.setCouponTemplates({
          data: response.data,
          type: 'recent',
        }),
      );

      if (typeof successCB === 'function') successCB(response);
    }
  } catch (error) {
    if (typeof failCB === 'function') failCB({ error });
  }
}

export function* readCouponTemplateCategoriesSaga(action: {
  type: string;
  payload: IReadCouponTemplateCategoriesPayload;
}): any {
  const { processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.get(`coupons/v1/templates/categories`, {
        cancelToken: getCancelToken('coupon_template_categories'),
      }),
    );

    if (response && response.data) {
      yield put(
        CouponTemplatesActions.setCouponTemplateCategories({
          data: response.data,
        }),
      );

      if (typeof successCB === 'function') successCB(response);
    }
  } catch (error) {
    if (typeof failCB === 'function') failCB({ error });
  }
}

export function* readCouponTemplateCategorySaga(action: {
  type: string;
  payload: IReadCouponTemplateCategoryPayload;
}): any {
  const { slug, processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.get(`coupons/v1/templates/categories/${slug}`, {
        cancelToken: getCancelToken('coupon_template_category'),
      }),
    );

    if (response && response.data) {
      yield put(
        CouponTemplatesActions.setCouponTemplates({
          data: response.data,
        }),
      );

      if (typeof successCB === 'function') successCB(response);
    }
  } catch (error) {
    if (typeof failCB === 'function') failCB({ error });
  }
}

export function* createCouponFromTemplateSaga(action: {
  type: string;
  payload: ICreateCouponFromTemplatePayload;
}): any {
  const { data, processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    // Filter cart condition fields data to exclude i18n and error data.
    const filteredData: any = { ...data };
    filteredData.cart_conditions = data.cart_conditions.map((group) => {
      if ('group_logic' === group.type) {
        return group;
      }

      // @ts-ignore
      const fields = group.fields as ICartConditionField<unknown>[];

      return {
        type: group.type,
        fields: fields.map(({ type, data }) => ({ type, data })),
      };
    });

    const response = yield call(() =>
      axiosInstance.post(`coupons/v1/templates`, filteredData, {
        cancelToken: getCancelToken('coupon_template_create'),
      }),
    );

    if (response && response.data) {
      if (typeof successCB === 'function') successCB(response);
    }
  } catch (error) {
    if (typeof failCB === 'function') failCB({ error });
  }
}

export function* deleteRecentCouponTemplateSaga(action: {
  type: string;
  payload: IDeleteRecentCouponTemplatePayload;
}): any {
  const { id, processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.delete(`coupons/v1/templates/recent/${id}`, {
        cancelToken: getCancelToken('coupon_template_delete'),
      }),
    );

    if (typeof successCB === 'function') successCB(response);
  } catch (error) {
    if (typeof failCB === 'function') failCB({ error });
  }
}

export function* generateCouponWithAISaga(action: { type: string; payload: IGenerateCouponWithAIPayload }): any {
  const { prompt, amount = 1, processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    yield put(CouponTemplatesActions.setAIGenerating({ generating: true }));

    const response = yield call(() =>
      axiosInstance.post(
        `storeagent-ai/v1/coupon-generator-ai/generate`,
        { prompt, amount },
        {
          cancelToken: getCancelToken('ai_coupon_generate'),
        },
      ),
    );

    if (response && response.data && response.data.success) {
      // Backend has saved the raw AI response to transient
      // Close the AI generator modal
      yield put(CouponTemplatesActions.toggleAIGeneratorModal({ show: false }));

      if (typeof successCB === 'function') successCB(response);
    }
  } catch (error: any) {
    if (typeof failCB === 'function') {
      const serverMessage = error?.response?.data?.message;
      if (serverMessage) {
        error.message = serverMessage;
      }
      failCB({ error });
    }
  } finally {
    yield put(CouponTemplatesActions.setAIGenerating({ generating: false }));
  }
}

/**
 * Read AI-generated coupon template from transient.
 * Transforms the raw AI response to match ICouponTemplate structure.
 */
/**
 * Fetch AI-generated template from transient.
 *
 * Backend now handles all processing (field prepending, fixtures, cart conditions).
 * No transformation needed on frontend - just fetch and return.
 */
export function* readAICouponTemplateSaga(action: { type: string; payload: IReadAICouponTemplatePayload }): any {
  const { processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.get(`storeagent-ai/v1/coupon-generator-ai/template`, {
        cancelToken: getCancelToken('ai_coupon_template'),
      }),
    );

    // Backend returns fully processed template - no transformation needed!
    if (response && response.data && response.data.data) {
      if (typeof successCB === 'function') {
        successCB(response);
      }
    }
  } catch (error) {
    if (typeof failCB === 'function') failCB({ error });
  }
}

/**
 * Clear AI-generated coupon template from transient.
 */
export function* clearAICouponTemplateSaga(): any {
  try {
    yield call(() =>
      axiosInstance.delete(`storeagent-ai/v1/coupon-generator-ai/template`, {
        cancelToken: getCancelToken('ai_coupon_template_clear'),
      }),
    );
  } catch (error) {
    // Silent fail - transient may have already expired.
  }
}

/**
 * Save AI-generated template to permanent storage.
 * Requires ACFWP Premium.
 */
export function* saveAITemplateSaga(action: { type: string; payload: ISaveAITemplatePayload }): any {
  const { name, prompt, processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.post(
        `storeagent-ai/v1/coupon-generator-ai/save`,
        { name, prompt },
        {
          cancelToken: getCancelToken('save_ai_template'),
        },
      ),
    );

    if (response && response.data && response.data.success) {
      // Refresh the list of saved templates
      yield put(
        CouponTemplatesActions.readSavedAITemplates({
          successCB: undefined,
          failCB: undefined,
        }),
      );

      if (typeof successCB === 'function') successCB(response);
    }
  } catch (error) {
    if (typeof failCB === 'function') failCB({ error });
  }
}

/**
 * Read all saved AI templates for current user.
 */
export function* readSavedAITemplatesSaga(action: { type: string; payload: IReadSavedAITemplatesPayload }): any {
  const { processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.get(`storeagent-ai/v1/coupon-generator-ai/saved`, {
        cancelToken: getCancelToken('saved_ai_templates'),
      }),
    );

    if (response && response.data && response.data.success) {
      yield put(
        CouponTemplatesActions.setSavedAITemplates({
          templates: response.data.templates || [],
        }),
      );

      if (typeof successCB === 'function') successCB(response);
    }
  } catch (error) {
    if (typeof failCB === 'function') failCB({ error });
  }
}

/**
 * Delete saved AI template.
 */
export function* deleteSavedAITemplateSaga(action: { type: string; payload: IDeleteSavedAITemplatePayload }): any {
  const { id, processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.delete(`storeagent-ai/v1/coupon-generator-ai/saved/${id}`, {
        cancelToken: getCancelToken('delete_ai_template'),
      }),
    );

    if (response && response.data && response.data.success) {
      // Refresh the list of saved templates
      yield put(
        CouponTemplatesActions.readSavedAITemplates({
          successCB: undefined,
          failCB: undefined,
        }),
      );

      if (typeof successCB === 'function') successCB(response);
    }
  } catch (error) {
    if (typeof failCB === 'function') failCB({ error });
  }
}

/**
 * Load saved AI template by ID.
 * Fetches the template and sets it as the edit template.
 */
export function* loadSavedAITemplateSaga(action: { type: string; payload: ILoadSavedAITemplatePayload }): any {
  const { id, processingCB, successCB, failCB } = action.payload;

  try {
    if (typeof processingCB === 'function') processingCB();

    const response = yield call(() =>
      axiosInstance.get(`storeagent-ai/v1/coupon-generator-ai/saved/${id}`, {
        cancelToken: getCancelToken('load_ai_template'),
      }),
    );

    if (response && response.data && response.data.success) {
      // Set the loaded template as edit template
      yield put(
        CouponTemplatesActions.setEditCouponTemplate({
          data: response.data.data,
        }),
      );

      if (typeof successCB === 'function') successCB(response);
    }
  } catch (error) {
    if (typeof failCB === 'function') failCB({ error });
  }
}

// #endregion [Sagas]

// #region [Action Listeners] ==========================================================================================

export const actionListener = [
  takeEvery(ECouponTemplatesActionTypes.READ_COUPON_TEMPLATES, readCouponTemplatesSaga),
  takeEvery(ECouponTemplatesActionTypes.READ_COUPON_TEMPLATE, readCouponTemplateSaga),
  takeEvery(ECouponTemplatesActionTypes.READ_RECENT_COUPON_TEMPLATES, readRecentCouponTemplatesSaga),
  takeEvery(ECouponTemplatesActionTypes.READ_COUPON_TEMPLATE_CATEGORIES, readCouponTemplateCategoriesSaga),
  takeEvery(ECouponTemplatesActionTypes.READ_COUPON_TEMPLATE_CATEGORY, readCouponTemplateCategorySaga),
  takeEvery(ECouponTemplatesActionTypes.CREATE_COUPON_FROM_TEMPLATE, createCouponFromTemplateSaga),
  takeEvery(ECouponTemplatesActionTypes.DELETE_RECENT_COUPON_TEMPLATE, deleteRecentCouponTemplateSaga),
  takeEvery(ECouponTemplatesActionTypes.GENERATE_COUPON_WITH_AI, generateCouponWithAISaga),
  takeEvery(ECouponTemplatesActionTypes.READ_AI_COUPON_TEMPLATE, readAICouponTemplateSaga),
  takeEvery(ECouponTemplatesActionTypes.CLEAR_AI_COUPON_TEMPLATE, clearAICouponTemplateSaga),
  // AI Template Storage listeners
  takeEvery(ECouponTemplatesActionTypes.SAVE_AI_TEMPLATE, saveAITemplateSaga),
  takeEvery(ECouponTemplatesActionTypes.READ_SAVED_AI_TEMPLATES, readSavedAITemplatesSaga),
  takeEvery(ECouponTemplatesActionTypes.DELETE_SAVED_AI_TEMPLATE, deleteSavedAITemplateSaga),
  takeEvery(ECouponTemplatesActionTypes.LOAD_SAVED_AI_TEMPLATE, loadSavedAITemplateSaga),
];

// #endregion [Action Listeners]
