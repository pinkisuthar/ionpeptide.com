// #region [Imports] ===================================================================================================

// Libraries
import { useHistory, useLocation } from 'react-router-dom';
import { Button, Card, Tabs } from 'antd';
import { LockFilled } from '@ant-design/icons';

// Components
import AdminHeader from '../../components/AdminHeader';
import StoreCreditsDashboard from '../../components/StoreCreditsDashboard';
import StoreCreditsCustomers from '../../components/StoreCreditsCustomers';
import StoreCreditsCustomersContextProvider from '../../contexts/StoreCreditsCustomersQuery';
import StoreCreditAutomations from '../../components/StoreCreditAutomations';

// Helpers
import { getPathPrefix } from '../../helpers/utils';

// SCSS
import './index.scss';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface ITab {
  label: string;
  key: string;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const BulkAdjustUpsell = () => {
  const upsell = acfwAdminApp?.store_credits_page?.bulk_adjust_upsell;

  if (!upsell) {
    return null;
  }

  return (
    <Card className="bulk-adjust-upsell">
      <LockFilled />
      <h2>{upsell.title}</h2>
      <p>{upsell.desc}</p>
      <Button type="primary" href={upsell.cta_url} target="_blank" rel="noopener noreferrer">
        {upsell.cta}
      </Button>
    </Card>
  );
};

const TabSwitch = (props: any) => {
  const { tabKey } = props;

  switch (tabKey) {
    case 'dashboard':
      return <StoreCreditsDashboard />;
    case 'customers':
      return (
        <StoreCreditsCustomersContextProvider>
          <StoreCreditsCustomers />
        </StoreCreditsCustomersContextProvider>
      );
    case 'automations':
      return <StoreCreditAutomations />;
    case 'bulk-adjust': {
      // Reach the global through `window` so a missing `acfwpElements` (premium
      // plugin inactive) doesn't throw a ReferenceError under strict-mode ESM.
      const acfwp = (window as any).acfwpElements;
      const isPremiumActive = parseInt(acfwp?.is_acfwp_active, 10);
      const StoreCreditsBulkAdjust = acfwp?.StoreCreditsBulkAdjust;

      if (isPremiumActive && StoreCreditsBulkAdjust) {
        return <StoreCreditsBulkAdjust />;
      }

      return <BulkAdjustUpsell />;
    }
  }

  return null;
};

const StoreCredits = () => {
  const {
    store_credits_page: { title, tabs },
  } = acfwAdminApp;
  const history = useHistory();
  const urlParams = new URLSearchParams(useLocation().search);
  const defaultTab = urlParams.get('tab') ?? 'dashboard';

  const handleTabClick = (key: string) => {
    history.push(`${getPathPrefix()}admin.php?page=acfw-store-credits&tab=${key}`);
  };

  return (
    <div className="store-credits-page">
      <AdminHeader title={title} className="store-credits-header" />
      <Tabs defaultActiveKey={defaultTab} onTabClick={handleTabClick}>
        {tabs.map((tab: ITab) => (
          <Tabs.TabPane tab={tab.label} key={tab.key}>
            <TabSwitch tabKey={tab.key} />
          </Tabs.TabPane>
        ))}
      </Tabs>
    </div>
  );
};

export default StoreCredits;

// #endregion [Component]
