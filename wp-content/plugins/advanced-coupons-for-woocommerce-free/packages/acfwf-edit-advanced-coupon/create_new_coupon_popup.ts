declare var acfw_edit_coupon: any;
declare var vex: any;
declare var jQuery: any;

var $: any = jQuery;

const { create_new_coupon_popup } = acfw_edit_coupon;
const url = new URL(window.location.href);

/**
 * Initialize new coupon popup functionality.
 *
 * @since 4.7.0
 */
export default function create_new_coupon_popup_events() {
  if (url.pathname.includes('post-new.php') && url.searchParams.get('post_type') === 'shop_coupon') {
    $('#title').blur();

    // Wait for the title field to be blurred before showing the popup.
    setTimeout(() => {
      showNewCouponPopup();
    }, 100);
  }
}

/**
 * Show the new coupon popup modal.
 *
 * @since 4.7.0
 */
function showNewCouponPopup() {
  // Show vex modal with the popup template
  vex.dialog.open({
    unsafeMessage: newCouponPopupTemplate(),
    className: 'vex-theme-plain acfw-new-coupon-popup',
    showCloseButton: true,
    buttons: {},
    afterOpen: function () {
      bindPopupEvents();

      // Ensure the focus is not on the title field when the popup is opened.
      setTimeout(() => {
        $('.vex-content :focus').blur();
      }, 100);
    },
  });
}

/**
 * Bind events for the popup modal.
 *
 * @since 4.7.0
 */
function bindPopupEvents() {
  // Handle Create Manually button
  $('#acfw-create-manually').on('click', function () {
    setTimeout(() => {
      $('#title').focus();
    }, 100);
  });

  // Handle Use Coupon Template button
  $('#acfw-use-template').on('click', function () {
    window.location.href = create_new_coupon_popup.site_url + '/wp-admin/admin.php?page=acfw-coupon-templates';
  });

  // Handle Generate with AI button
  $('#acfw-generate-ai').on('click', function () {
    window.location.href =
      create_new_coupon_popup.site_url + '/wp-admin/admin.php?page=acfw-coupon-templates&show_ai_modal=true';
  });
}

/**
 * Get the AI option button HTML.
 * Always shown regardless of StoreAgent connection status.
 * The coupon templates page will handle showing the appropriate upsell modal
 * if StoreAgent is not yet installed, activated, or connected.
 *
 * @since 4.7.0
 *
 * @return {string} AI option button HTML.
 */
function getAIOptionButton(): string {
  return `
    <button id="acfw-generate-ai" class="acfw-option-card acfw-option-card-ai">
      <div class="acfw-option-content">
        <div class="acfw-option-icon acfw-option-icon-ai">
          <svg class="acfw-icon acfw-icon-ai" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2v4"></path>
            <path d="m6.8 15-3.5 2"></path>
            <path d="m20.7 7-3.5 2"></path>
            <path d="m6.8 9-3.5-2"></path>
            <path d="m20.7 17-3.5-2"></path>
            <circle cx="12" cy="12" r="4"></circle>
          </svg>
        </div>
        <div class="acfw-option-text">
          <h3 class="acfw-option-title">${create_new_coupon_popup.generate_ai}</h3>
          <p class="acfw-option-description">${create_new_coupon_popup.generate_ai_desc}</p>
        </div>
      </div>
    </button>
  `;
}

/**
 * Get the new coupon popup template.
 *
 * @since 4.7.0
 *
 * @return {string} New coupon popup template.
 */
function newCouponPopupTemplate() {
  return `
    <div class="acfw-new-coupon-popup-header">
      <h2>${create_new_coupon_popup.title}</h2>
      <p>${create_new_coupon_popup.description}</p>
    </div>

    <div class="acfw-new-coupon-popup-buttons">
      <div class="acfw-options-grid">
        <button id="acfw-create-manually" class="acfw-option-card acfw-option-card-manual">
          <div class="acfw-option-content">
            <div class="acfw-option-icon acfw-option-icon-manual">
              <svg class="acfw-icon acfw-icon-manual" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"></path>
                <path d="M14 2v4a2 2 0 0 0 2 2h4"></path>
                <path d="M10 9H8"></path>
                <path d="M16 13H8"></path>
                <path d="M16 17H8"></path>
              </svg>
            </div>
            <div class="acfw-option-text">
              <h3 class="acfw-option-title">${create_new_coupon_popup.create_manually}</h3>
              <p class="acfw-option-description">${create_new_coupon_popup.create_manually_desc}</p>
            </div>
          </div>
        </button>

        <button id="acfw-use-template" class="acfw-option-card acfw-option-card-template">
          <div class="acfw-option-content">
            <div class="acfw-option-icon acfw-option-icon-template">
              <svg class="acfw-icon acfw-icon-template" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
              </svg>
            </div>
            <div class="acfw-option-text">
              <h3 class="acfw-option-title">${create_new_coupon_popup.use_template}</h3>
              <p class="acfw-option-description">${create_new_coupon_popup.use_template_desc}</p>
            </div>
          </div>
        </button>

        ${getAIOptionButton()}
      </div>
    </div>
  `;
}
