// #region [Imports] ===================================================================================================

// Types
import {
  ICouponTemplate,
  ICouponTemplateCategory,
  ICouponTemplateFormData,
  ICreateCouponFromTemplateResponse,
  ISavedAITemplate,
} from '../../types/couponTemplates';

// #endregion [Imports]

// #region [Action Payloads] ===========================================================================================

export interface IReadCouponTemplatesPayload {
  isReview?: boolean;
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface IReadCouponTemplatePayload {
  id: number;
  isReview?: boolean;
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface IReadRecentCouponTemplatesPayload {
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface IReadCouponTemplateCategoriesPayload {
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface IReadCouponTemplateCategoryPayload {
  slug: string;
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface ICreateCouponFromTemplatePayload {
  data: ICouponTemplateFormData;
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface IDeleteRecentCouponTemplatePayload {
  id: number;
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface ISetCouponTemplatesPayload {
  data: ICouponTemplate[];
  type?: 'main' | 'recent' | 'review';
}

export interface ISetCouponTemplatesCategoriesPayload {
  data: ICouponTemplateCategory[];
}

export interface IUnsetRecentCouponTemplatePayload {
  id: number;
}

export interface ISetCouponTemplatesLoadingPayload {
  loading: boolean;
}

export interface ISetEditCouponTemplatePayload {
  data: ICouponTemplate | null;
}

export interface ISetEditCouponTemplateFieldValuePayload {
  field: string;
  value: any;
}

export interface ISetCreatedCouponResponseDataPayload {
  data: ICreateCouponFromTemplateResponse | null;
}

export interface ISetCartConditionItemDataPayload {
  groupKey: number;
  fieldKey: number | null;
  data: any;
}

export interface ITogglePremiumModalPayload {
  show: boolean;
}

export interface ISetSearchFiltersPayload {
  searchTerm: string;
  licenseFilter: string;
}

export interface ISetSortOptionsPayload {
  sortBy: 'title' | 'date';
  sortOrder: 'asc' | 'desc';
}

export interface IToggleAIGeneratorModalPayload {
  show: boolean;
}

export interface ISetAIGeneratingPayload {
  generating: boolean;
}

export interface IGenerateCouponWithAIPayload {
  prompt: string;
  amount?: number;
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface IReadAICouponTemplatePayload {
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface ISaveAITemplatePayload {
  name?: string;
  prompt?: string;
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface IReadSavedAITemplatesPayload {
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface ISetSavedAITemplatesPayload {
  templates: ISavedAITemplate[];
}

export interface IDeleteSavedAITemplatePayload {
  id: string;
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface ILoadSavedAITemplatePayload {
  id: string;
  processingCB?: () => void;
  successCB?: (arg: any) => void;
  failCB?: (arg: any) => void;
}

export interface IToggleStoreAgentUpsellModalPayload {
  show: boolean;
  mode?: 'install' | 'activate' | 'connect';
}

// #endregion [Action Payloads]

// #region [Action Types] ==============================================================================================

export enum ECouponTemplatesActionTypes {
  READ_COUPON_TEMPLATES = 'READ_COUPON_TEMPLATES',
  READ_COUPON_TEMPLATE = 'READ_COUPON_TEMPLATE',
  READ_RECENT_COUPON_TEMPLATES = 'READ_RECENT_COUPON_TEMPLATES',
  READ_COUPON_TEMPLATE_CATEGORIES = 'READ_COUPON_TEMPLATE_CATEGORIES',
  READ_COUPON_TEMPLATE_CATEGORY = 'READ_COUPON_TEMPLATE_CATEGORY',
  CREATE_COUPON_FROM_TEMPLATE = 'CREATE_COUPON_FROM_TEMPLATE',
  DELETE_RECENT_COUPON_TEMPLATE = 'DELETE_RECENT_COUPON_TEMPLATE',
  SET_COUPON_TEMPLATES = 'SET_COUPON_TEMPLATES',
  SET_COUPON_TEMPLATE_CATEGORIES = 'SET_COUPON_TEMPLATE_CATEGORIES',
  UNSET_RECENT_COUPON_TEMPLATE = 'UNSET_RECENT_COUPON_TEMPLATE',
  SET_COUPON_TEMPLATES_LOADING = 'SET_COUPON_TEMPLATES_LOADING',
  SET_EDIT_COUPON_TEMPLATE = 'SET_EDIT_COUPON_TEMPLATE',
  SET_EDIT_COUPON_TEMPLATE_FIELD_VALUE = 'SET_EDIT_COUPON_TEMPLATE_FIELD_VALUE',
  SET_CART_CONDITION_ITEM_DATA = 'SET_CART_CONDITION_ITEM_DATA',
  VALIDATE_EDIT_COUPON_TEMPLATE_DATA = 'VALIDATE_EDIT_COUPON_TEMPLATE_DATA',
  VALIDATE_CART_CONDITIONS_DATA = 'VALIDATE_CART_CONDITIONS_DATA',
  SET_COUPON_CREATED_RESPONSE_DATA = 'SET_COUPON_CREATED_RESPONSE_DATA',
  CLEAR_COUPON_CREATED_RESPONSE_DATA = 'CLEAR_COUPON_CREATED_RESPONSE_DATA',
  TOGGLE_PREMIUM_MODAL = 'TOGGLE_PREMIUM_MODAL',
  SET_SEARCH_FILTERS = 'SET_SEARCH_FILTERS',
  SET_SORT_OPTIONS = 'SET_SORT_OPTIONS',
  // AI Generator actions
  TOGGLE_AI_GENERATOR_MODAL = 'TOGGLE_AI_GENERATOR_MODAL',
  SET_AI_GENERATING = 'SET_AI_GENERATING',
  GENERATE_COUPON_WITH_AI = 'GENERATE_COUPON_WITH_AI',
  READ_AI_COUPON_TEMPLATE = 'READ_AI_COUPON_TEMPLATE',
  CLEAR_AI_COUPON_TEMPLATE = 'CLEAR_AI_COUPON_TEMPLATE',
  // AI Template Storage actions
  SAVE_AI_TEMPLATE = 'SAVE_AI_TEMPLATE',
  READ_SAVED_AI_TEMPLATES = 'READ_SAVED_AI_TEMPLATES',
  SET_SAVED_AI_TEMPLATES = 'SET_SAVED_AI_TEMPLATES',
  DELETE_SAVED_AI_TEMPLATE = 'DELETE_SAVED_AI_TEMPLATE',
  LOAD_SAVED_AI_TEMPLATE = 'LOAD_SAVED_AI_TEMPLATE',
  // StoreAgent Upsell actions
  TOGGLE_STOREAGENT_UPSELL_MODAL = 'TOGGLE_STOREAGENT_UPSELL_MODAL',
}

// #endregion [Action Types]

// #region [Action Creators] ===========================================================================================

export const CouponTemplatesActions = {
  readCouponTemplates: (payload: IReadCouponTemplatesPayload) => ({
    type: ECouponTemplatesActionTypes.READ_COUPON_TEMPLATES,
    payload,
  }),
  readCouponTemplate: (payload: IReadCouponTemplatePayload) => ({
    type: ECouponTemplatesActionTypes.READ_COUPON_TEMPLATE,
    payload,
  }),
  readRecentCouponTemplates: (payload: IReadRecentCouponTemplatesPayload) => ({
    type: ECouponTemplatesActionTypes.READ_RECENT_COUPON_TEMPLATES,
    payload,
  }),
  readCouponTemplateCategories: (payload: IReadCouponTemplateCategoriesPayload) => ({
    type: ECouponTemplatesActionTypes.READ_COUPON_TEMPLATE_CATEGORIES,
    payload,
  }),
  readCouponTemplateCategory: (payload: IReadCouponTemplateCategoryPayload) => ({
    type: ECouponTemplatesActionTypes.READ_COUPON_TEMPLATE_CATEGORY,
    payload,
  }),
  createCouponFromTemplate: (payload: ICreateCouponFromTemplatePayload) => ({
    type: ECouponTemplatesActionTypes.CREATE_COUPON_FROM_TEMPLATE,
    payload,
  }),
  deleteRecentCouponTemplate: (payload: IDeleteRecentCouponTemplatePayload) => ({
    type: ECouponTemplatesActionTypes.DELETE_RECENT_COUPON_TEMPLATE,
    payload,
  }),
  setCouponTemplates: (payload: ISetCouponTemplatesPayload) => ({
    type: ECouponTemplatesActionTypes.SET_COUPON_TEMPLATES,
    payload,
  }),
  setCouponTemplateCategories: (payload: ISetCouponTemplatesCategoriesPayload) => ({
    type: ECouponTemplatesActionTypes.SET_COUPON_TEMPLATE_CATEGORIES,
    payload,
  }),
  unsetRecentCouponTemplate: (payload: IUnsetRecentCouponTemplatePayload) => ({
    type: ECouponTemplatesActionTypes.UNSET_RECENT_COUPON_TEMPLATE,
    payload,
  }),
  setCouponTemplatesLoading: (payload: ISetCouponTemplatesLoadingPayload) => ({
    type: ECouponTemplatesActionTypes.SET_COUPON_TEMPLATES_LOADING,
    payload,
  }),
  setEditCouponTemplate: (payload: ISetEditCouponTemplatePayload) => ({
    type: ECouponTemplatesActionTypes.SET_EDIT_COUPON_TEMPLATE,
    payload,
  }),
  setEditCouponTemplateFieldValue: (payload: ISetEditCouponTemplateFieldValuePayload) => ({
    type: ECouponTemplatesActionTypes.SET_EDIT_COUPON_TEMPLATE_FIELD_VALUE,
    payload,
  }),
  validateEditCouponTemplateData: () => ({
    type: ECouponTemplatesActionTypes.VALIDATE_EDIT_COUPON_TEMPLATE_DATA,
  }),
  validateCartConditionsData: () => ({
    type: ECouponTemplatesActionTypes.VALIDATE_CART_CONDITIONS_DATA,
  }),
  setCreatedCouponResponseData: (payload: ISetCreatedCouponResponseDataPayload) => ({
    type: ECouponTemplatesActionTypes.SET_COUPON_CREATED_RESPONSE_DATA,
    payload,
  }),
  setCartConditionItemData: (payload: ISetCartConditionItemDataPayload) => ({
    type: ECouponTemplatesActionTypes.SET_CART_CONDITION_ITEM_DATA,
    payload,
  }),
  clearCreatedCouponResponseData: () => ({
    type: ECouponTemplatesActionTypes.CLEAR_COUPON_CREATED_RESPONSE_DATA,
  }),
  togglePremiumModal: (payload: ITogglePremiumModalPayload) => ({
    type: ECouponTemplatesActionTypes.TOGGLE_PREMIUM_MODAL,
    payload,
  }),
  setSearchFilters: (payload: ISetSearchFiltersPayload) => ({
    type: ECouponTemplatesActionTypes.SET_SEARCH_FILTERS,
    payload,
  }),
  setSortOptions: (payload: ISetSortOptionsPayload) => ({
    type: ECouponTemplatesActionTypes.SET_SORT_OPTIONS,
    payload,
  }),
  // AI Generator action creators
  toggleAIGeneratorModal: (payload: IToggleAIGeneratorModalPayload) => ({
    type: ECouponTemplatesActionTypes.TOGGLE_AI_GENERATOR_MODAL,
    payload,
  }),
  setAIGenerating: (payload: ISetAIGeneratingPayload) => ({
    type: ECouponTemplatesActionTypes.SET_AI_GENERATING,
    payload,
  }),
  generateCouponWithAI: (payload: IGenerateCouponWithAIPayload) => ({
    type: ECouponTemplatesActionTypes.GENERATE_COUPON_WITH_AI,
    payload,
  }),
  readAICouponTemplate: (payload: IReadAICouponTemplatePayload) => ({
    type: ECouponTemplatesActionTypes.READ_AI_COUPON_TEMPLATE,
    payload,
  }),
  clearAICouponTemplate: () => ({
    type: ECouponTemplatesActionTypes.CLEAR_AI_COUPON_TEMPLATE,
  }),
  // AI Template Storage action creators
  saveAITemplate: (payload: ISaveAITemplatePayload) => ({
    type: ECouponTemplatesActionTypes.SAVE_AI_TEMPLATE,
    payload,
  }),
  readSavedAITemplates: (payload: IReadSavedAITemplatesPayload) => ({
    type: ECouponTemplatesActionTypes.READ_SAVED_AI_TEMPLATES,
    payload,
  }),
  setSavedAITemplates: (payload: ISetSavedAITemplatesPayload) => ({
    type: ECouponTemplatesActionTypes.SET_SAVED_AI_TEMPLATES,
    payload,
  }),
  deleteSavedAITemplate: (payload: IDeleteSavedAITemplatePayload) => ({
    type: ECouponTemplatesActionTypes.DELETE_SAVED_AI_TEMPLATE,
    payload,
  }),
  loadSavedAITemplate: (payload: ILoadSavedAITemplatePayload) => ({
    type: ECouponTemplatesActionTypes.LOAD_SAVED_AI_TEMPLATE,
    payload,
  }),
  // StoreAgent Upsell action creators
  toggleStoreAgentUpsellModal: (payload: IToggleStoreAgentUpsellModalPayload) => ({
    type: ECouponTemplatesActionTypes.TOGGLE_STOREAGENT_UPSELL_MODAL,
    payload,
  }),
};

// #endregion [Action Creators]
