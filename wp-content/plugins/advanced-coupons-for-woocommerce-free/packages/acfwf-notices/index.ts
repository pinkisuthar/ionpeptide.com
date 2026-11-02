import './index.scss';

declare var ajaxurl: any;

jQuery(document).ready(function ($) {
  var $adminNotices = $('.acfw-admin-notice');

  $adminNotices.on(
    'click',
    'button.notice-dismiss,.acfw-notice-dismiss,.review-actions .snooze,.review-actions .dismissed,.action-button.with-response',
    handleNoticeClick
  );

  function handleNoticeClick(this: any, e: any) {
    e.preventDefault();
    var $notice = $(this).closest('.acfw-admin-notice');
    var response = $(this).data('response');

    handleNoticeDisplay($(this), $notice);

    dismissAdminNotice($notice, response);
  }

  function handleNoticeDisplay($element: any, $notice: any) {
    var withLoading = $element.hasClass('with-loading');
    var loadingText = $element.data('loading-text');
    if (withLoading && loadingText.length > 0) {
      $notice.empty().append($('<p>').text(loadingText));
    } else {
      $notice.fadeOut('fast');
    }
  }

  function dismissAdminNotice($notice: any, response: any) {
    $.post(
      ajaxurl,
      {
        action: 'acfw_dismiss_admin_notice',
        notice: $notice.data('notice'),
        response: response ? response : 'dismissed',
        nonce: $notice.data('nonce'),
      },
      handleDismissResponse
    );
  }

  function handleDismissResponse(response: any) {
    if (response.success && response.redirect) {
      window.location.href = response.redirect;
    }
  }
});
