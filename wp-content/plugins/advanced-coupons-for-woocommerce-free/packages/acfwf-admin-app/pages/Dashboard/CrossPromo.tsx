// #region [Imports] ===================================================================================================

// Libraries
import { useState, useEffect } from 'react';

// Components
import PluginInstallerButton from '../../components/PluginInstallerButton';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IProduct {
  key: string;
  name: string;
  slug: string;
  description: string;
  logo: string;
  badges: string[];
  is_active: boolean;
}

interface IProps {
  className?: string;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const CrossPromo = (props: IProps) => {
  const { className } = props;
  const [currentProduct, setCurrentProduct] = useState<IProduct | null>(null);
  const [isVisible, setIsVisible] = useState(true);

  // Get products from backend
  const crossPromoProducts = acfwAdminApp?.dashboard_page?.cross_promo_products;

  // Select a random product on component mount
  useEffect(() => {
    if (crossPromoProducts && crossPromoProducts.length > 0) {
      // Filter only inactive products
      const inactiveProducts = crossPromoProducts.filter((product: IProduct) => !product.is_active);

      if (inactiveProducts.length > 0) {
        const randomIndex = Math.floor(Math.random() * inactiveProducts.length);
        setCurrentProduct(inactiveProducts[randomIndex]);
      }
    }
  }, []);

  // Don't show if no products available or not visible
  if (!isVisible || !currentProduct) return null;

  return (
    <div className={`cross-promo-widget ${className}`}>
      {/* Badge and Title Section - Inline */}
      <div className="header-section">
        <div className="badge-section">
          {currentProduct.badges.map((badgeType) => (
            <span key={badgeType} className={`badge badge-${badgeType}`}>
              {acfwAdminApp?.dashboard_page?.labels?.[badgeType === 'free' ? 'free_plugin' : badgeType] || badgeType}
            </span>
          ))}
        </div>
      </div>

      {/* Title Section */}
      <div className="title-section">
        <img
          src={currentProduct.logo}
          alt={currentProduct.name}
          className="product-logo"
          onError={(e) => {
            const target = e.target as HTMLImageElement;
            target.style.display = 'none';
          }}
        />
        <h3 className="product-title">{currentProduct.name}</h3>
      </div>

      {/* Description Section */}
      <p className="product-description">{currentProduct.description}</p>

      {/* Button Section */}
      <PluginInstallerButton
        pluginSlug={currentProduct.slug}
        type="primary"
        size="small"
        // @ts-ignore
        text={
          <span className="install-button-content">
            <svg
              width="16"
              height="16"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
              <polyline points="7 10 12 15 17 10"></polyline>
              <line x1="12" x2="12" y1="15" y2="3"></line>
            </svg>
            {acfwAdminApp?.dashboard_page?.labels?.install_and_activate}
          </span>
        }
        nonce={acfwAdminApp?.nonces?.install_plugin}
        successMessage={`${currentProduct.name} ${acfwAdminApp?.dashboard_page?.labels?.installed_successfully}`}
        className="install-button"
      />
    </div>
  );
};

export default CrossPromo;

// #endregion [Component]
