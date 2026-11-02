// Global variables.
declare var wp: any;

/**
 * Simple accordion component.
 *
 * @since 4.5.9
 *
 * @return {JSX.Element} Accordion component.
 * */
const Accordion = (props: any) => {
  const { useState, useEffect, useRef } = wp.element;
  const { title, children, caret_img_src, auto_display_store_credits_redeem_form } = props;
  const [visible, setVisibile] = useState(false);
  const contentRef = useRef(null);

  /**
   * Handle Accordion Click Toggle.
   *
   * @since 4.5.9
   * */
  const handleAccordionClickToggle = () => {
    // Toggle Accordion.
    setVisibile(!visible);

    /**
     * Toggle the inner content.
     * - This is due to transition needs precise height for animation.
     * */
    if (contentRef.current) {
      let maxHeight = contentRef.current.style.maxHeight;
      maxHeight = parseInt(maxHeight.replace('px', ''));
      maxHeight = maxHeight > 0 ? 0 : contentRef.current.scrollHeight;
      contentRef.current.style.maxHeight = `${maxHeight}px`;
    }
  };

  /**
   * Get carret element.
   *
   * @since 4.5.9
   *
   * @return {JSX.Element} Carret element.
   * */
  const getCarret = () => {
    if (caret_img_src) {
      return (
        <span className="caret">
          <img src={caret_img_src} />
        </span>
      );
    }
  };

  useEffect(() => {
    if (auto_display_store_credits_redeem_form === 'yes') {
      setVisibile(true);
      // Expand accordion by default if auto_display_store_credits_redeem_form set to 'yes'
      handleAccordionClickToggle();
    }
  }, [auto_display_store_credits_redeem_form]);

  return (
    <div className="acfw-accordion">
      <div className={`acfw-accordion acfw-store-credits-checkout-ui ${visible ? 'show' : ''}`}>
        <h3 onClick={handleAccordionClickToggle}>
          <span className="acfw-accordion-title">{title}</span>
          {getCarret()}
        </h3>
        <div className="acfw-accordion-inner" ref={contentRef}>
          <div className="acfw-accordion-content">{children}</div>
        </div>
      </div>
    </div>
  );
};

export default Accordion;
