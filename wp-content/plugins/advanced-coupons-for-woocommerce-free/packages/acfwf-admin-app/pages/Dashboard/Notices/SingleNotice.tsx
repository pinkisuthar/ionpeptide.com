// #region [Imports] ===================================================================================================

// Libraries
import { Button, Card } from 'antd';
import { CloseOutlined } from '@ant-design/icons';

// Types
import { ISingleNotice } from '../../../types/notices';

// Helpers
import { sanitizeHtml } from '../../../../shared/utils';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IProps {
  notice: ISingleNotice;
  onDismiss: (slug: string, nonce: string, response?: string) => void;
  onRead: (slug: string, nonce: string) => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const SingleNotice = (props: IProps) => {
  const {
    notice: { slug, heading, content, type, nonce, actions, is_dismissable, is_in_app_notifications },
    onDismiss,
    onRead,
  } = props;

  const {
    dashboard_page: { labels },
  } = acfwAdminApp;

  return (
    <Card
      className={`single-notice notice-${type} ${slug}-notice`}
      onMouseEnter={is_in_app_notifications ? () => onRead(slug, nonce) : undefined}
    >
      {heading && (
        <div className="notice-heading">
          <h3>{heading}</h3>
        </div>
      )}
      <div className="notice-content">
        {content.map((text, i) => (
          <p key={i} dangerouslySetInnerHTML={{ __html: sanitizeHtml(text) }} />
        ))}
      </div>
      <div className="notice-actions">
        {actions.map((action, i) => (
          <Button
            key={action.key}
            type={0 === i ? 'primary' : 'default'}
            href={action.link ? action.link.replace(/&amp;/g, '&') : undefined}
            target={action?.is_external ? '_blank' : undefined}
            onClick={action?.response ? () => onDismiss(slug, nonce, action.response) : undefined}
          >
            {action.text}
          </Button>
        ))}
        {is_dismissable && slug !== 'review_request' && (
          <Button onClick={() => onDismiss(slug, nonce)}>{labels.dismiss}</Button>
        )}
      </div>
      {is_dismissable && (
        <Button
          className="dismiss-notice-icon"
          icon={<CloseOutlined />}
          size="small"
          type="text"
          shape="circle"
          onClick={() => onDismiss(slug, nonce, slug === 'review_request' ? 'snooze' : 'dismissed')}
        />
      )}
    </Card>
  );
};

export default SingleNotice;

// #endregion [Component]
