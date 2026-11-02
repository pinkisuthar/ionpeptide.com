// #region [Imports] ===================================================================================================

// Libraries
import { useEffect, useState } from 'react';

// Components
import Logo from '../Logo';

// #endregion [Imports]

// #region [Interfaces]=================================================================================================

interface IProps {
  title?: string;
  className?: string;
  description?: string;
  hideUpgrade?: boolean;
  actions?: React.ReactNode;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const AdminHeader = (props: IProps) => {
  const { title, className, description, actions } = props;
  const hideUpgrade = props.hideUpgrade ?? false;

  return (
    <div className={`page-header ${className ?? ''}`}>
      <Logo hideUpgrade />
      <div className="page-header-title-row">
        {!!title && <h1>{title}</h1>}
        {actions && <div className="page-header-actions">{actions}</div>}
      </div>
      {!!description && <p>{description}</p>}
    </div>
  );
};

export default AdminHeader;

// #endregion [Component]
