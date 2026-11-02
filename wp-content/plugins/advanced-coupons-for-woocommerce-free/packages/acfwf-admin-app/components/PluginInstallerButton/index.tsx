// #region [Imports] ===================================================================================================

// Libraries
import { useState } from 'react';
import { Button, message } from 'antd';
import type { SizeType } from 'antd/lib/config-provider/SizeContext';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var ajaxurl: string;
declare var jQuery: any;

// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IProps {
  pluginSlug: string;
  className?: string;
  type: 'text' | 'link' | 'ghost' | 'default' | 'primary' | 'dashed' | undefined;
  size: SizeType;
  text: string;
  nonce: string;
  successMessage: string;
  afterInstall?: () => void;
  isActivation?: boolean;
}
// #endregion [Interfaces]

// #region [Component] =================================================================================================

const PluginInstallerButton = (props: IProps) => {
  const { pluginSlug, className, type, size, text, successMessage, nonce, afterInstall, isActivation = false } = props;
  const [loading, setLoading] = useState(false);

  const handleButtonClick = () => {
    setLoading(true);
    console.log(isActivation ? 'Activating plugin:' : 'Installing plugin:', pluginSlug);

    jQuery
      .ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
          action: 'acfw_install_activate_plugin',
          plugin_slug: pluginSlug,
          nonce: nonce,
        },
        dataType: 'json',
      })
      .done((response: any) => {
        console.log(isActivation ? 'Activation response:' : 'Installation response:', response);
        setLoading(false);

        if (response.success) {
          message.success(successMessage);
          if (typeof afterInstall === 'function') afterInstall();
          // Refresh the page after successful installation to update plugin status
          setTimeout(() => {
            window.location.reload();
          }, 1000);
        } else {
          const errorMessage =
            response.data?.message ||
            response.data ||
            (isActivation ? 'Unknown error occurred during activation' : 'Unknown error occurred during installation');
          message.error(errorMessage);
          console.error(isActivation ? 'Activation failed:' : 'Installation failed:', errorMessage);

          // Log debug info if available
          if (response.data?.debug) {
            console.log('Debug info:', response.data.debug);
          }
        }
      })
      .fail((error: any) => {
        console.error('AJAX request failed:', error);
        setLoading(false);

        const errorMessage =
          error.responseJSON?.data?.message ||
          error.responseJSON?.data ||
          (isActivation ? 'Activation failed - please try again' : 'Installation failed - please try again');
        message.error(errorMessage);

        // Log debug info if available
        if (error.responseJSON?.data?.debug) {
          console.log('Debug info:', error.responseJSON.data.debug);
        }
      });
  };

  return (
    <Button className={className} type={type} size={size} loading={loading} onClick={handleButtonClick}>
      {text}
    </Button>
  );
};

export default PluginInstallerButton;
// #endregion [Component]
