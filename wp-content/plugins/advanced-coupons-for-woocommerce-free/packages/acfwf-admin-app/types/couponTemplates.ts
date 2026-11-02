export interface ICouponTemplatesStore {
  loading: boolean;
  templates: ICouponTemplate[];
  recent: ICouponTemplate[];
  review: ICouponTemplate[];
  edit: ICouponTemplate | null;
  categories: ICouponTemplateCategory[];
  formResponse: ICreateCouponFromTemplateResponse | null;
  premiumModal: boolean;
  searchFilters: {
    searchTerm: string;
    licenseFilter: string;
  };
  sortOptions: {
    sortBy: 'title' | 'date';
    sortOrder: 'asc' | 'desc';
  };
  // AI Generator state
  aiGeneratorModal: boolean;
  aiGenerating: boolean;
  // AI Template Storage state
  savedAITemplates: ISavedAITemplate[];
  // StoreAgent Upsell state
  storeagentUpsellModal: boolean;
  storeagentUpsellMode: 'install' | 'activate' | 'connect';
}

export interface ICouponTemplateListItem {
  id: number;
  title: string;
  description: string;
  license_type: 'free' | 'premium';
  image_bg_color: string;
  image_svg: string;
  date?: string;
}

export interface ICouponTemplate extends ICouponTemplateListItem {
  fields: ICouponTemplateField[];
  cart_conditions: (ICartConditionGroup | ICartConditionGroupLogic)[];
}

export interface ICouponTemplateCategory {
  name: string;
  slug: string;
  url: string;
  count: number;
}

export interface ICouponTemplateField {
  field: string;
  is_required: boolean;
  field_value: 'user_defined' | 'editable' | 'readonly';
  value: string;
  fixtures: IFieldFixtures;
  error?: string;
  pre_filled_value: string;
}

export interface IFieldFixtures {
  label: string;
  description: string;
  type: string;
  tooltip: string;
  placeholder?: string;
  min?: string;
  max?: string;
  step?: string;
  options?: Record<string, string>;
}

export interface IFieldComponentProps {
  defaultValue: any;
  editable: boolean;
  fixtures: IFieldFixtures;
  onChange: (value: any) => void;
}

export interface ICouponTemplateFormData {
  id: number;
  fields: ICouponTemplateFieldData[];
  cart_conditions: (ICartConditionGroup | ICartConditionGroupLogic)[];
}

export interface ICouponTemplateFieldData {
  key: string;
  value: any;
  type: string;
}

export interface ICouponTemplateFieldResponseData {
  key: string;
  label: string;
  value: string;
}

export interface ISavedAITemplate {
  id: string;
  title: string;
  prompt?: string;
  created_at: string;
}

export interface ICreateCouponFromTemplateResponse {
  status: string;
  message: string;
  fields: ICouponTemplateFieldResponseData[];
  cart_conditions: string;
  coupon_id: number;
  coupon_edit_url: string;
}

export interface ICartConditionGroup {
  type: 'group';
  fields: ICartConditionField<unknown>[];
}

export interface ICartConditionGroupLogic {
  type: string;
  value: string;
}

export interface ICartConditionFieldOption {
  key: string;
  group: string;
  premium: boolean;
  title: string;
  desc: string;
  defaultValue: any;
  validateState: (value: any) => boolean;
}

export interface ICartConditionField<T> {
  type: string;
  data: T;
  i18n: any;
  errors?: string[];
}

export interface ICartConditionError {
  groupKey: number;
  fieldKey: number | null;
}
