declare var jQuery: any;
declare var ClipboardJS: any;
declare var ACFW_QRCode: any;
declare var acfw_edit_coupon: any;
var $: any = jQuery;

/**
 * URL Coupon events.
 *
 * @since 4.3.1
 */
export default function url_coupon_events() {
  const moduleBlock = document.querySelector('#acfw_url_coupon');

  $(moduleBlock).on('click', '.copy-coupon-url', showCopiedText);

  initCouponUrlClipboard();

  // If the QR code setting is disabled, then the QR code library will not be loaded from the backend.
  if (typeof ACFW_QRCode !== 'undefined') {
    initQRCode();
  }
}

/**
 * Initialize coupon URL field copy to clipboard button.
 *
 * @since 4.3.1
 */
function initCouponUrlClipboard() {
  const $couponUrl = $('input#_acfw_coupon_url');

  $couponUrl.after(
    `<button type="button" class="copy-coupon-url" data-clipboard-target="#_acfw_coupon_url">
    <svg width="15" height="15" version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 460 460" style="enable-background:new 0 0 460 460;" xml:space="preserve">
      <g>
        <path d="M425.934,0H171.662c-18.122,0-32.864,14.743-32.864,32.864v77.134h30V32.864c0-1.579,1.285-2.864,2.864-2.864h254.272
          c1.579,0,2.864,1.285,2.864,2.864v254.272c0,1.58-1.285,2.865-2.864,2.865h-74.729v30h74.729
          c18.121,0,32.864-14.743,32.864-32.865V32.864C458.797,14.743,444.055,0,425.934,0z"/>
        <path d="M288.339,139.998H34.068c-18.122,0-32.865,14.743-32.865,32.865v254.272C1.204,445.257,15.946,460,34.068,460h254.272
          c18.122,0,32.865-14.743,32.865-32.864V172.863C321.206,154.741,306.461,139.998,288.339,139.998z M288.341,430H34.068
          c-1.58,0-2.865-1.285-2.865-2.864V172.863c0-1.58,1.285-2.865,2.865-2.865h254.272c1.58,0,2.865,1.285,2.865,2.865v254.273h0.001
          C291.206,428.715,289.92,430,288.341,430z"/>
      </g>
    </svg>
    </button>`
  );

  new ClipboardJS('.copy-coupon-url');
}

/**
 * Display a "Copied" text after clicking the copy button.
 *
 * @since 4.3.1
 */
function showCopiedText() {
  // @ts-ignore
  const $button = $(this);

  $button.after(`<span class="coupon-url-copied">${acfw_edit_coupon.copied_label}</span>`);

  setTimeout(() => {
    $('span.coupon-url-copied').fadeOut(500);
  }, 1000);
}

/**
 * Initialize QR Code.
 *
 * @since 4.6.1
 */
function initQRCode() {
  const $optionsGroupContainer = $('#acfw_url_coupon').find('.options_group');
  // Prepend QR code HTML element
  $optionsGroupContainer.prepend(
    `<div class="acfw-qr-code-container">
        <div id="acfw-qr-code"></div>
        <a id="acfw-download-qr-link" href="#">${acfw_edit_coupon.download_qr_image_label}</a>
    </div>`
  );
  generateQRCode();
}

/**
 * Generate QR Code based coupon url.
 *
 * @since 4.6.1
 */
function generateQRCode() {
  var couponUrl = $('input#_acfw_coupon_url').val();
  var downloadLink = $('#acfw-download-qr-link');
  // If couponUrl is exist, generate the QR Code.
  if (couponUrl) {
    let qrcodeContainer = $('#acfw-qr-code');

    try {
      let qrcode = new ACFW_QRCode(qrcodeContainer[0], {
        text: couponUrl,
        width: 200,
        height: 200,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: ACFW_QRCode.CorrectLevel.L,
      });
      qrcode.makeCode(couponUrl);

      downloadLink.click(function () {
        // Get the QR image and its src attribute
        var qrImage = $('#acfw-qr-code img');
        var qrURL = qrImage.attr('src');
        // Download the QR image with coupon title name
        downloadLink.attr('href', qrURL);
        downloadLink.attr('download', `${$('#title').val()}.png`);
      });
    } catch (error) {
      $('#acfw-qr-code').remove();
      $('#acfw-download-qr-link').addClass('disabled');
      $('#acfw-download-qr-link').before(`<p class="error-message">${acfw_edit_coupon.error_generate_qr_image}</p>`);
    }
  } else {
    // Hide acfw-qr-code-container if the couponUrl is not exist
    $('.acfw-qr-code-container').hide();
  }
}
