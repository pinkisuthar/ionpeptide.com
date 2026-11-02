export interface IMessage {
  id: string;
  note_id: string;
  title: string;
  content: string;
  source: string;
  type: string;
  locale: string;
  status: string;
  relative_time: string;
  is_deleted: boolean;
  is_read: boolean;
  actions: Array<IButton>;
}

export interface IButton {
  id: string;
  name: string;
  label: string;
  query: string;
}
