jQuery(function ($) {
  $(document).on('click', '.remove-bank-button', function (e) {
    e.preventDefault();
    if (!confirm('Are you sure you want to remove this bank?')) return;

    const $btn  = $(this);
    const token = $btn.data('bank-token');
    const $row  = $btn.closest('tr');

    $.ajax({
      url: fingridVars.ajaxUrl,
      type: 'POST',
      dataType: 'json',
      data: { action: 'fingrid_remove_bank_token', bank_token: token, nonce: fingridVars.nonce },
      beforeSend: () => $btn.prop('disabled', true).text('Removing…'),
      success: (res) => {
        if (res && res.success) {
          $row.fadeOut(200, function () {
            $(this).remove();
            if ($('#myTable tbody tr').length === 0) {
              $('#myTable').remove();
              $('#no-banks-msg').show();
            }
          });
        } else {
          alert((res && res.data && res.data.message) || 'Failed to remove bank.');
          $btn.prop('disabled', false).text('Remove');
        }
      },
      error: (xhr) => {
        alert('AJAX error: ' + (xhr.responseText || xhr.statusText));
        $btn.prop('disabled', false).text('Remove');
      }
    });
  });
});
