// #region [Imports] ===================================================================================================

// Types
import {
  ICouponTemplate,
  ICouponTemplatesStore,
  ICouponTemplateCategory,
  ICartConditionGroup,
  ICartConditionGroupLogic,
} from '../../types/couponTemplates';
import {
  ECouponTemplatesActionTypes,
  ISetCouponTemplatesCategoriesPayload,
  ISetCouponTemplatesPayload,
  IUnsetRecentCouponTemplatePayload,
  ITogglePremiumModalPayload,
  ISetSearchFiltersPayload,
  ISetSortOptionsPayload,
  IToggleAIGeneratorModalPayload,
  ISetAIGeneratingPayload,
  ISetSavedAITemplatesPayload,
  IToggleStoreAgentUpsellModalPayload,
} from '../actions/couponTemplates';

import { cloneDeep } from 'lodash';

import { validateConditionField } from '../../pages/CouponTemplates/TemplateForm/CartConditions/ConditionField';

// #endregion [Imports]

// #region [Reducer] ===================================================================================================

const reducer = (
  state: ICouponTemplatesStore = {
    loading: false,
    templates: [],
    recent: [],
    review: [],
    edit: null,
    categories: [],
    formResponse: null,
    premiumModal: false,
    searchFilters: {
      searchTerm: '',
      licenseFilter: 'all',
    },
    sortOptions: {
      sortBy: 'title',
      sortOrder: 'asc',
    },
    // AI Generator initial state
    aiGeneratorModal: false,
    aiGenerating: false,
    // AI Template Storage initial state
    savedAITemplates: [],
    // StoreAgent Upsell initial state
    storeagentUpsellModal: false,
    storeagentUpsellMode: 'install',
  },
  action: { type: string; payload: any },
): ICouponTemplatesStore => {
  let index;
  switch (action.type) {
    case ECouponTemplatesActionTypes.SET_COUPON_TEMPLATES: {
      const { data: templates, type = 'main' } = action.payload as ISetCouponTemplatesPayload;

      switch (type) {
        case 'main':
          return { ...state, templates };
        case 'recent':
          return { ...state, recent: templates };
        case 'review':
          return { ...state, review: templates };
      }
    }

    case ECouponTemplatesActionTypes.SET_COUPON_TEMPLATE_CATEGORIES: {
      const { data: categories } = action.payload as ISetCouponTemplatesCategoriesPayload;
      return { ...state, categories: categories };
    }

    case ECouponTemplatesActionTypes.UNSET_RECENT_COUPON_TEMPLATE: {
      const { id } = action.payload as IUnsetRecentCouponTemplatePayload;
      const recentTemplates = [...state.recent];
      index = recentTemplates.findIndex((c) => c.id === id);
      recentTemplates.splice(index, 1);
      return { ...state, recent: recentTemplates };
    }

    case ECouponTemplatesActionTypes.SET_COUPON_TEMPLATES_LOADING: {
      const { loading } = action.payload;
      return { ...state, loading };
    }

    case ECouponTemplatesActionTypes.SET_EDIT_COUPON_TEMPLATE: {
      const { data: edit } = action.payload;
      return { ...state, edit };
    }

    case ECouponTemplatesActionTypes.SET_EDIT_COUPON_TEMPLATE_FIELD_VALUE: {
      const { field, value } = action.payload;
      const edit = { ...state.edit } as ICouponTemplate;

      if (!edit || !edit.fields) return state;

      const index = edit.fields.findIndex((f) => f.field === field);

      if (index > -1) {
        edit.fields[index].value = value;
      }

      return { ...state, edit: { ...edit } };
    }

    case ECouponTemplatesActionTypes.VALIDATE_EDIT_COUPON_TEMPLATE_DATA: {
      const edit = { ...state.edit } as ICouponTemplate;

      if (!edit || !edit.fields) return state;

      const fields = edit.fields.map((f) => {
        if (f.is_required && (!f.value || f.fixtures?.options?.disabled === f.value)) {
          f.error = 'This field is required';
        } else {
          f.error = undefined;
        }
        return f;
      });

      return { ...state, edit: { ...edit, fields } };
    }

    case ECouponTemplatesActionTypes.VALIDATE_CART_CONDITIONS_DATA: {
      const edit = { ...state.edit } as ICouponTemplate;

      if (!edit || !edit.cart_conditions || !edit.cart_conditions.length) return state;

      let cartConditions = cloneDeep(edit.cart_conditions);

      cartConditions = cartConditions.map((group) => {
        if ('group_logic' === group.type) return group;

        // @ts-ignore
        let fields = group.fields as ICartConditionGroup['fields'];

        fields = fields.map((field) => {
          if ('logic' === field.type) return field;

          field.errors = validateConditionField(field.type, field.data);

          return field;
        });

        return { ...group, fields };
      });

      return { ...state, edit: { ...edit, cart_conditions: cartConditions } };
    }

    case ECouponTemplatesActionTypes.SET_COUPON_CREATED_RESPONSE_DATA: {
      const { data } = action.payload;
      return { ...state, formResponse: data };
    }

    case ECouponTemplatesActionTypes.SET_CART_CONDITION_ITEM_DATA: {
      const { groupKey, fieldKey, data } = action.payload;
      const edit = { ...state.edit } as ICouponTemplate;

      if (!edit || !edit.cart_conditions) return state;

      const cartConditions = cloneDeep(edit.cart_conditions);

      const groupLogic =
        cartConditions[groupKey].type === 'group_logic' ? (cartConditions[groupKey] as ICartConditionGroupLogic) : null;
      const group =
        cartConditions[groupKey].type === 'group' ? (cartConditions[groupKey] as ICartConditionGroup) : null;

      if (fieldKey === null && groupLogic !== null) {
        groupLogic.value = data as string;
        cartConditions[groupKey] = groupLogic;
      } else if (fieldKey !== null && group?.type === 'group') {
        group.fields[fieldKey].data = data;
        cartConditions[groupKey] = group;
      }

      return { ...state, edit: { ...edit, cart_conditions: cartConditions } };
    }

    case ECouponTemplatesActionTypes.CLEAR_COUPON_CREATED_RESPONSE_DATA: {
      const edit = { ...state.edit } as ICouponTemplate;

      edit.fields = edit.fields.map((f) => {
        f.value = f.pre_filled_value ?? '';
        return f;
      });

      return { ...state, formResponse: null, edit: null };
    }

    case ECouponTemplatesActionTypes.TOGGLE_PREMIUM_MODAL: {
      const { show } = action.payload as ITogglePremiumModalPayload;
      return { ...state, premiumModal: show };
    }

    case ECouponTemplatesActionTypes.SET_SEARCH_FILTERS: {
      const { searchTerm, licenseFilter } = action.payload as ISetSearchFiltersPayload;
      return { ...state, searchFilters: { searchTerm, licenseFilter } };
    }

    case ECouponTemplatesActionTypes.SET_SORT_OPTIONS: {
      const { sortBy, sortOrder } = action.payload as ISetSortOptionsPayload;
      return { ...state, sortOptions: { sortBy, sortOrder } };
    }

    // AI Generator cases
    case ECouponTemplatesActionTypes.TOGGLE_AI_GENERATOR_MODAL: {
      const { show } = action.payload as IToggleAIGeneratorModalPayload;
      return { ...state, aiGeneratorModal: show };
    }

    case ECouponTemplatesActionTypes.SET_AI_GENERATING: {
      const { generating } = action.payload as ISetAIGeneratingPayload;
      return { ...state, aiGenerating: generating };
    }

    // AI Template Storage cases
    case ECouponTemplatesActionTypes.SET_SAVED_AI_TEMPLATES: {
      const { templates } = action.payload as ISetSavedAITemplatesPayload;
      return { ...state, savedAITemplates: templates };
    }

    // StoreAgent Upsell cases
    case ECouponTemplatesActionTypes.TOGGLE_STOREAGENT_UPSELL_MODAL: {
      const { show, mode = 'install' } = action.payload as IToggleStoreAgentUpsellModalPayload;
      return {
        ...state,
        storeagentUpsellModal: show,
        storeagentUpsellMode: show ? mode : state.storeagentUpsellMode,
      };
    }
  }

  return state;
};

export default reducer;

// #endregion [Reducer]
