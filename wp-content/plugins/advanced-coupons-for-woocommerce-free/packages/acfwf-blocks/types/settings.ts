export interface IContentVisibility {
  discount_value: true;
  description: true;
  usage_limit: true;
  schedule: true;
  expired_coupons: true;
}

export interface IAttributes {
  categories?: number[];
  order_by?: string;
  columns?: number;
  count?: number;
  contentVisibility?: IContentVisibility;
  display_type?: string;
}
