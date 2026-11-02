// #region [Imports] ===================================================================================================

// Libraries
import { CopyOutlined } from '@ant-design/icons';
import { Input, Button, InputProps, message } from 'antd';
// @ts-ignore
import { CopyToClipboard } from 'react-copy-to-clipboard';

// #endregion [Imports]

// #region [Variables] =================================================================================================
// #endregion [Variables]

// #region [Interfaces]=================================================================================================

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const CopyInputField = (props: InputProps) => {
  const { value, ...restProps } = props;

  return (
    <Input.Group className="copy-input-field-component" compact>
      <Input style={{ width: 'calc(100% - 46px)' }} {...restProps} value={value} readOnly />
      <CopyToClipboard text={value} onCopy={() => message.success('Copy success')}>
        <Button>
          <CopyOutlined />
        </Button>
      </CopyToClipboard>
    </Input.Group>
  );
};

export default CopyInputField;

// #endregion [Component]
