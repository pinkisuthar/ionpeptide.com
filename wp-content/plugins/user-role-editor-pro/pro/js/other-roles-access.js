var ure_other_roles_access = {
  autoselect: function (event) {
    if (event.shiftKey) {
      jQuery('.ure-cb-column').each(function () {   // reverse selection                
        jQuery(this).prop('checked', !jQuery(this).prop('checked'));
      });
    } else {    // switch On/Off all checkboxes
      jQuery('.ure-cb-column').prop('checked', jQuery('#ure_other_roles_select_all').prop('checked'));
    }
  },
  dialog: function (data) {
    jQuery('#ure_other_roles_access_dialog').dialog({
      dialogClass: 'wp-dialog',
      modal: true,
      autoOpen: true,
      closeOnEscape: true,
      width: 550,
      height: 500,
      resizable: false,
      title: ure_data_other_roles_access.dialog_title + ' for "' + ure_current_role + '"',
      'buttons': {
        'Update': function () {
          ure_other_roles_access.update();
          jQuery(this).dialog('close');
        },
        'Cancel': function () {
          jQuery(this).dialog('close');
          return false;
        }
      }
    });
    jQuery('.ui-dialog-buttonpane button:contains("Update")').attr("id", "dialog-update-button");
    jQuery('#dialog-update-button').html(ure_ui_button_text(ure_data_other_roles_access.update_button));
    jQuery('.ui-dialog-buttonpane button:contains("Cancel")').attr("id", "dialog-cancel-button");
    jQuery('#dialog-cancel-button').html(ure_ui_button_text(ure_data.cancel));

    jQuery('#ure_other_roles_access_container').html(data.html);
    jQuery('#ure_other_roles_select_all').on('click', ure_other_roles_access_auto_select );
  },
    
  dialog_prepare: function () {
    if (ure_data_other_roles_access.not_block_local_admin == 1 && jQuery('#user_role').val() === 'administrator') {
      alert(ure_data_other_roles_access.not_applicable_to_admin);
      return;
    }
    if (!jQuery('#edit_users').is(':checked')) {
      alert(ure_data_other_roles_access.edit_users_required);
      return;
    }
    jQuery.ajax({
      url: ajaxurl,
      type: 'POST',
      dataType: 'html',
      data: {
        action: 'ure_ajax',
        sub_action: 'get_other_roles_list',
        current_role: ure_current_role,
        network_admin: ure_data.network_admin,
        wp_nonce: ure_data.wp_nonce
      },
      success: function (response) {
        var data = jQuery.parseJSON(response);
        if (typeof data.result !== 'undefined') {
          if (data.result === 'success') {
            ure_other_roles_access.dialog(data);
          } else if (data.result === 'failure') {
            alert(data.message);
          } else {
            alert('Wrong response: ' + response)
          }
        } else {
          alert('Wrong response: ' + response)
        }
      },
      error: function (XMLHttpRequest, textStatus, exception) {
        alert("Ajax failure\n" + exception);
      },
      async: true
    });

  },
    
  update_success: function (data) {
    jQuery('#ure_task_status').hide();
    if (data.result == 'success') {
      jQuery.notify(data.message, 'success');
    } else {
      jQuery.notify(data.message, 'error');
    }

  },
    
  update: function () {
    var values = {};
    jQuery.each(jQuery('#ure_other_roles_access_form').serializeArray(), function (i, field) {
      values[field.name] = field.value;
    });
    jQuery('#ure_task_status').show();
    jQuery.ajax({
      url: ajaxurl,
      type: 'POST',
      dataType: 'json',
      async: true,
      data: {
        action: 'ure_ajax',
        sub_action: 'other_roles_access_update',
        values: values,
        user_role_id: values['user_role'],
        network_admin: ure_data.network_admin,
        wp_nonce: ure_data.wp_nonce
      },
      success: ure_other_roles_access.update_success,
      error: ure_main.ajax_error
    });
  }
};

jQuery(function() {
  jQuery('#ure_other_roles_access_button').button(
    {
      label: ure_data_other_roles_access.other_roles
    }).on('click', (function (event) {
      event.preventDefault();
      ure_other_roles_access.dialog_prepare();
    }));
});


function ure_other_roles_access_auto_select(event) {
  if (event.shiftKey) {
    jQuery('.ure-cb-column').each(function () {   // reverse selection                
      jQuery(this).prop('checked', !jQuery(this).prop('checked'));
    });
  } else {    // switch On/Off all checkboxes
    jQuery('.ure-cb-column').prop('checked', jQuery('#ure_other_roles_select_all').prop('checked'));
  }
}