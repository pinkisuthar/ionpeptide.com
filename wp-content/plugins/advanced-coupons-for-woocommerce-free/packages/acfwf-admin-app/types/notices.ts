export interface ISingleNotice {
  slug: string;
  id: string;
  is_dismissable: boolean;
  is_in_app_notifications: boolean;
  type: string;
  nonce: string;
  heading: string;
  content: string[];
  actions: INoticeAction[];
}

export interface INoticeAction {
  key: string;
  response: string;
  link: string;
  text: string;
  is_external?: boolean;
}
