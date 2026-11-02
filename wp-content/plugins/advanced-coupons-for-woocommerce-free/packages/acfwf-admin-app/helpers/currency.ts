// #region [Imports] ===================================================================================================

import { isNull } from 'lodash';

// #endregion [Imports]

// #region [Variables] =================================================================================================

declare var acfwAdminApp: any;

// #endregion [Variables]

// #region [Interfaces] ================================================================================================

interface INumberFormatProps {
  precision: number | null;
  decimalSeparator: string;
  thousandSeparator: string;
}

// #endregion [Interfaces]

// #region [Functions] =================================================================================================

/**
 * Format a number with the specified precision and separators.
 *
 * @param {INumberFormatProps} props
 * @param {number} number
 * @returns {string}
 */
export function numberFormat(
  { precision = null, decimalSeparator = '.', thousandSeparator = ',' }: INumberFormatProps,
  number: number
) {
  if (isNull(precision)) {
    const [, decimals] = number.toString().split('.');
    precision = decimals ? decimals.length : 0;
  }

  let formatted = number.toFixed(precision);
  let [whole, decimal] = formatted.split('.');

  // Only apply thousand separator if it's not empty to avoid infinite loop
  if (thousandSeparator && thousandSeparator !== '') {
    const regex = /(\d+)(\d{3})/;
    while (regex.test(whole)) {
      whole = whole.replace(regex, '$1' + thousandSeparator + '$2');
    }
  }

  return decimal !== undefined ? `${whole}${decimalSeparator}${decimal}` : whole;
}

/**
 * Format a number as a price string with the currency symbol.
 *
 * @param {number} number
 * @param {boolean} useCode
 * @returns {string}
 */
export function priceFormat(number: number, useCode = false) {
  const { currency } = acfwAdminApp.store_credits_page;
  const currencySettings = {
    precision: currency.decimals,
    decimalSeparator: currency.decimal_separator,
    thousandSeparator: currency.thousand_separator,
  };

  const formattedNumber = numberFormat(currencySettings, number);

  if ('' === formattedNumber) {
    return formattedNumber;
  }

  return `${currency.symbol}${formattedNumber}`;
}

/**
 * Remove thousand separator from a value string.
 *
 * @param {string} value
 * @param {string} separator
 * @returns {string}
 */
function removeThousandSeparator(value: string, separator: string): string {
  if (separator && separator !== '') {
    const thousandRegex = new RegExp(`\\${separator}`, 'g');
    return value.replace(thousandRegex, '');
  }
  return value;
}

/**
 * Validate price input.
 *
 * @param {string} value
 * @returns {boolean}
 */
export function validatePrice(value: string): boolean {
  const { currency } = acfwAdminApp.store_credits_page;

  // First, remove thousand separators if present
  const cleanValue = removeThousandSeparator(value, currency.thousand_separator);

  const regex = new RegExp(`[^-0-9%\\${currency.decimal_separator}]+`, 'gi');
  const decimalRegex = new RegExp(`[^\\${currency.decimal_separator}"]`, 'gi');
  let newvalue = cleanValue.replace(regex, '');

  // Check if newvalue have more than one decimal point.
  if (1 < newvalue.replace(decimalRegex, '').length) {
    newvalue = newvalue.replace(decimalRegex, '');
  }

  const floatVal = parseFloat(newvalue.replace(currency.decimal_separator, '.'));

  return cleanValue === newvalue && floatVal > 0.0;
}

/**
 * Parse string as price value (float).
 *
 * @param {string} value
 * @returns {number}
 */
export function parsePrice(value: string): number {
  const { currency } = acfwAdminApp.store_credits_page;

  // Remove thousand separators if present
  const cleanValue = removeThousandSeparator(value, currency.thousand_separator);

  return parseFloat(cleanValue.replace(currency.decimal_separator, '.'));
}

// #endregion [Functions]
