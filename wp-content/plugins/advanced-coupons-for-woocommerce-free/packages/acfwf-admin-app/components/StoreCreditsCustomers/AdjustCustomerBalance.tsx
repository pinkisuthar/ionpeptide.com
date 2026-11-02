// #region [Imports] ===================================================================================================

// Libraries
import { useEffect, useState, useRef } from 'react';
import { connect } from 'react-redux';
import { bindActionCreators } from 'redux';
import { Select, Input, Button, Space, Checkbox, message } from 'antd';

// Actions
import { StoreCreditsCustomersActions } from '../../store/actions/storeCreditsCustomers';

// Types
import { IStoreCreditCustomer } from '../../types/storeCredits';

// Helpers
import { priceFormat, validatePrice, parsePrice } from '../../helpers/currency';
import { sanitizeHtml } from '../../../shared/helpers/sanitize';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

const { adjustCustomerStoreCredits, setStoreCreditsCustomerBalance } = StoreCreditsCustomersActions;

// #endregion [Variables]

// #region [Interfaces] ================================================================================================

interface IActions {
  adjustCustomerStoreCredits: typeof adjustCustomerStoreCredits;
  setStoreCreditsCustomerBalance: typeof setStoreCreditsCustomerBalance;
}

interface IProps {
  customer: IStoreCreditCustomer | null;
  setAdjustCustomer: (customer: IStoreCreditCustomer | null) => void;
  actions: IActions;
}

// #endregion [Interfaces]

// #region [Component] =================================================================================================

const AdjustCustomerBalance = (props: IProps) => {
  const { customer, setAdjustCustomer, actions } = props;
  const { adjust_modal, currency } = acfwAdminApp.store_credits_page;
  const [loading, setLoading] = useState(false);
  const [type, setType] = useState('increase');
  const [amount, setAmount] = useState('');
  const [newBalance, setNewBalance] = useState('');
  const [entryNote, setEntryNote] = useState('');
  const [noteVisible, setNoteVisible] = useState(false);
  const noteTextArea = useRef<HTMLTextAreaElement>(null);
  const [sendEmailNotification, setSendEmailNotification] = useState(true);

  /**
   * Show or hide the note input.
   */
  const handleAddNote = () => {
    setNoteVisible(!noteVisible); // Toggle visibility

    // Debounce event, to focus on the input after it's visible
    setTimeout(() => noteTextArea.current?.focus(), 400);
  };

  /**
   * Handle the adjustment of the customer's store credits.
   */
  const handleAdjustment = () => {
    if (!customer) return;

    if (!validatePrice(amount)) {
      message.error(adjust_modal.invalid_price);
      return;
    }

    setLoading(true);
    actions.adjustCustomerStoreCredits({
      id: customer?.id,
      type,
      amount: parsePrice(amount),
      note: noteVisible && entryNote ? entryNote : '',
      send_email_notification: sendEmailNotification,
      successCB: (response: any) => {
        setLoading(false);
        message.success(response.data.message);
        actions.setStoreCreditsCustomerBalance({
          id: customer?.id,
          balance: response.data.balance,
          balance_raw: response.data.balance_raw,
        });
        setAdjustCustomer(null);
        setAmount('');
        setType('increase');
        setEntryNote('');
      },
      failCB: ({ error }: any) => {
        setLoading(false);
        message.error(error.response.data.message);
      },
    });
  };

  // calculate new balance when either customer or amount values are changed.
  useEffect(() => {
    if (!customer || !amount) {
      setNewBalance('');
      return;
    }

    const newBalance =
      'increase' === type ? customer.balance_raw + parsePrice(amount) : customer.balance_raw - parsePrice(amount);
    setNewBalance(priceFormat(Math.max(0, newBalance)));
  }, [customer, amount, type]);

  if (!customer) return null;

  return (
    <div className="adjust-store-credits">
      <h2>{adjust_modal.title}</h2>
      <p className="description">{adjust_modal.description}</p>
      <h3 className="current-balance">{adjust_modal.current_balance.replace('{balance}', customer.balance)}</h3>
      <div className="adjust-store-credits-form">
        <Select value={type} onSelect={(value: string) => setType(value)}>
          <Select.Option value="increase">{adjust_modal.increase}</Select.Option>
          <Select.Option value="decrease">{adjust_modal.decrease}</Select.Option>
        </Select>
        <Input value={amount} onChange={(e) => setAmount(e.target.value)} addonBefore={currency.symbol} allowClear />
        <Space.Compact direction="vertical" className="entry-note-wrap">
          <a onClick={handleAddNote} style={{ marginBottom: '0px' }}>
            {adjust_modal.add_note}
          </a>
          {noteVisible ? (
            <Input.TextArea
              ref={noteTextArea}
              rows={2}
              placeholder={adjust_modal.note_placeholder}
              value={entryNote ?? undefined}
              onChange={(e) => setEntryNote(e.target.value)}
              maxLength={255}
            />
          ) : null}
        </Space.Compact>
      </div>
      {newBalance ? (
        <h3
          className="new-balance"
          dangerouslySetInnerHTML={{
            __html: sanitizeHtml(adjust_modal.new_balance.replace('{balance}', `<span>${newBalance}</span>`)),
          }}
        />
      ) : null}
      <div className="adjust-store-credits-form-actions">
        <Button
          className="make-adjustment-btn"
          type="primary"
          size="large"
          loading={loading}
          onClick={handleAdjustment}
        >
          {adjust_modal.make_adjustment}
        </Button>

        <Checkbox checked={sendEmailNotification} onChange={(e) => setSendEmailNotification(e.target.checked)}>
          {adjust_modal.send_email_notification}
        </Checkbox>
      </div>
    </div>
  );
};

const mapDispatchToProps = (dispatch: any) => ({
  actions: bindActionCreators({ adjustCustomerStoreCredits, setStoreCreditsCustomerBalance }, dispatch),
});

export default connect(null, mapDispatchToProps)(AdjustCustomerBalance);

// #endregion [Component]
