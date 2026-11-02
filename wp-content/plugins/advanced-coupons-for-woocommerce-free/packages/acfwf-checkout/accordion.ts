// Global variables.
declare var jQuery: any;
declare var acfwfCheckout: any;

const $ = jQuery;

/**
 * Sets up accordion events for the checkout UI block.
 *
 * @since 4.6.0
 */
export default function accordionEvents() {
  // Remove any existing handlers to prevent duplicates when re-initialized.
  $(document.body).off(
    'click acfw_toggle_accordion',
    '.acfw-checkout-ui-block .acfw-accordion > h3',
    toggleAccordionContent
  );

  // Add the event handler
  $(document.body).on(
    'click acfw_toggle_accordion',
    '.acfw-checkout-ui-block .acfw-accordion > h3',
    toggleAccordionContent
  );

  // Expand accordion by default if auto_display_store_credits_redeem_form set to 'yes'
  if ('yes' === acfwfCheckout.auto_display_store_credits_redeem_form) {
    $('.acfw-checkout-ui-block .acfw-accordion.acfw-store-credits-checkout-ui > h3').trigger('acfw_toggle_accordion');
  }
}

/**
 * Toggles the visibility of the accordion content.
 *
 * @since 4.6.0
 * @param this {JQuery} The element that triggered the event.
 */
function toggleAccordionContent(this: JQuery) {
  const $accordion = $(this).parent();
  const $block = $accordion.closest('.acfw-checkout-ui-block');
  const $accordions = $block.find('.acfw-accordion');
  const isShown = $accordion.hasClass('show');

  $accordions.removeClass('show');

  if (!isShown) {
    $accordion.addClass('show');
  } else {
    $accordion.removeClass('show');
  }

  calculateAccordionContentMaxHeight.call(this);
}

/**
 * Calculates and sets the maximum height for accordion content.
 *
 * @since 4.6.0
 * @param this {JQuery} The element that triggered the event.
 */
function calculateAccordionContentMaxHeight(this: JQuery) {
  const $block = $('.acfw-checkout-ui-block');

  /**
   * Calculates the maximum height based on the height of the inner content.
   * The calculation takes into account the font size of the accordion content for accurate padding.
   */
  $block.find('.acfw-accordion').each(function (this: HTMLElement) {
    const $accordion = $(this);
    const $content = $accordion.find('.acfw-accordion-content');
    const $accordionInner = $accordion.find('.acfw-accordion-inner');
    const padding = 2 * parseInt(getComputedStyle($content[0]).fontSize) + 10;

    let contentHeight = $content.height() ?? 0;
    let accordionInnerHeight = ($accordionInner.height() ?? 0) - padding;

    contentHeight = accordionInnerHeight > 0 ? 0 : contentHeight + padding;
    // Override contentHeight if the accordion should not be displayed.
    if (!$accordion.hasClass('show')) {
      contentHeight = 0;
    }

    $accordionInner.css({ maxHeight: contentHeight });
  });
}
