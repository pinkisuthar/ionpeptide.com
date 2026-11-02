// #region [Imports] ===================================================================================================

// Libraries
import { Button, ButtonProps } from 'antd';
import { useHistory } from 'react-router-dom';
import { SizeType } from 'antd/lib/config-provider/SizeContext';

// #endregion [Imports]

// #region [Variables] =================================================================================================
// #endregion [Variables]

// #region [Interfaces]=================================================================================================

interface IProps extends ButtonProps {
  text: string;
  size: SizeType;
  onClick?: () => void;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const GoBackButton = (props: IProps) => {
  const { text, onClick, ...restProps } = props;
  const history = useHistory();

  const handleClick = () => {
    if (typeof onClick === 'function') onClick();

    history.goBack();
  };

  return (
    <Button onClick={handleClick} {...restProps}>
      {text}
    </Button>
  );
};

export default GoBackButton;

// #endregion [Component]
