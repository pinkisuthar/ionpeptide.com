// #region [Imports] ===================================================================================================

// Components
import SidebarIcon from './SidebarIcon';

// Helpers
import { sanitizeHtml } from '../../../../shared/helpers/sanitize';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

export interface IPluginStatus {
  key: string;
  name: string;
  campaign: string;
  status: string;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const PluginStatus = (props: IPluginStatus & { itemKey: string }) => {
  const { name, campaign, status, itemKey } = props;
  const {
    dashboard_page: { labels },
  } = acfwAdminApp;

  let statusHtml: string;

  if ('learn_more' === status) {
    let basePath = 'https://advancedcouponsplugin.com/pricing/';
    let pluginPath = '';

    // Determine the plugin-specific path
    switch (itemKey) {
      case 'acfwp':
        pluginPath = '';
        break;
      case 'lpfw':
        pluginPath = 'loyalty/';
        break;
      case 'agc':
        pluginPath = 'gift-cards/';
        break;
      default:
        pluginPath = '';
    }

    const link = `${basePath}${pluginPath}?utm_source=acfwf&utm_medium=dashboard&utm_campaign=${campaign}`;
    statusHtml = `<a class="${status}" href="${link}" target="_blank" href="javascript:void(0);">${labels[status]}</a>`;
  } else {
    statusHtml = `<span class="plugin-status ${status}">${labels[status]}</span>`;
  }

  return (
    <>
      <SidebarIcon iconKey={status} />
      <span className="plugin-name" dangerouslySetInnerHTML={{ __html: sanitizeHtml(`${name} (${statusHtml})`) }} />
    </>
  );
};

export default PluginStatus;

// #endregion [Component]
