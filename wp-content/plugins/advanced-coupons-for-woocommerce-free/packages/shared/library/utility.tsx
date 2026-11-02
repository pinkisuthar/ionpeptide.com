/**
 * Debounce function.
 *
 * @param func Function to debounce.
 * @param wait Wait time.
 *
 * @return Function.
 * */
export const debounce = (func: (...args: any[]) => any, wait: number) => {
  let timeout: any;
  return function (...args: any[]) {
    clearTimeout(timeout);
    timeout = setTimeout(() => func(...args), wait);
  };
};
