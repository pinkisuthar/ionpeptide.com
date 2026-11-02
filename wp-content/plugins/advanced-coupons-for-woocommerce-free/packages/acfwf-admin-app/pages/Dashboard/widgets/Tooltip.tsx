// #region [Imports] ===================================================================================================

// Libraries
import { Card, Popover } from "antd";
import { ExclamationCircleOutlined } from "@ant-design/icons";

// Helpers
import { sanitizeHtml } from "../../../../shared/utils";

// #endregion [Imports]

// #region [Interfaces]=================================================================================================

interface IProps {
  content: string;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================


const Tooltip = (props: IProps) => {
  const {content} = props;

  return (
    <span className="tooltip">
      <Popover overlayClassName="dashboard-tooltip" placement="top" content={<span dangerouslySetInnerHTML={{__html: sanitizeHtml(content)}} />} trigger="click">
        <ExclamationCircleOutlined />
      </Popover>
    </span>
  );
}

export default Tooltip;

// #endregion [Component]