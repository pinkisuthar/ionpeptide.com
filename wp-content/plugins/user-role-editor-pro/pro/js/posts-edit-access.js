/**
 * 
 * Posts Edit access management for roles support
 * 
 **/

var ure_posts_edit_access = {
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
    jQuery.each(jQuery('#ure_posts_edit_access_form').serializeArray(), function (i, field) {
      if ( field.name!=='ure_post_types[]' ) {
        values[field.name] = field.value;
      }
    });
    
    const post_types = jQuery('#ure_posts_edit_access_form .ure_post_types:checked')
            .map(
            function() {
              return this.value;
            }).get(); 
    values['ure_post_types'] = post_types;
    
    jQuery('#ure_task_status').show();
    jQuery.ajax({
      url: ajaxurl,
      type: 'POST',
      dataType: 'json',
      async: true,
      data: {
        action: 'ure_ajax',
        sub_action: 'posts_edit_access_update',
        values: values,
        user_role_id: values['user_role'],
        network_admin: ure_data.network_admin,
        wp_nonce: ure_data.wp_nonce
      },
      success: ure_posts_edit_access.update_success,
      error: ure_main.ajax_error
    });
  },

  show_dialog: function (data) {
    jQuery('#ure_posts_edit_access_dialog').dialog({
      dialogClass: 'wp-dialog',
      modal: true,
      autoOpen: true,
      closeOnEscape: true,
      width: 680,
      height: 580,
      resizable: false,
      title: ure_data_posts_edit_access.dialog_title + ' (' + ure_current_role + ')',
      'buttons': {
        'Update': function () {
          ure_posts_edit_access.update();
          jQuery(this).dialog('close');
        },
        'Cancel': function () {
          jQuery(this).dialog('close');
          return false;
        }
      }
    });
    jQuery('.ui-dialog-buttonpane button:contains("Update")').attr("id", "dialog-update-button");
    jQuery('#dialog-update-button').html(ure_ui_button_text(ure_data_posts_edit_access.update_button));
    jQuery('.ui-dialog-buttonpane button:contains("Cancel")').attr("id", "dialog-cancel-button");
    jQuery('#dialog-cancel-button').html(ure_ui_button_text(ure_data.cancel));

    jQuery('#ure_posts_edit_access_container').html(data.html);

  },
  
  dialog_prepare: function () {
    jQuery.ajax({
      url: ajaxurl,
      type: 'POST',
      dataType: 'html',
      data: {
        action: 'ure_ajax',
        sub_action: 'get_posts_edit_access_data_for_role',
        current_role: ure_current_role,
        wp_nonce: ure_data.wp_nonce
      },
      success: function (response) {
        var data = jQuery.parseJSON(response);
        if (typeof data.result !== 'undefined') {
          if (data.result === 'success') {
            ure_posts_edit_access.show_dialog(data);
          } else if (data.result === 'error') {
            alert(data.message);
          } else {
            alert('Wrong response: ' + response)
          }
        } else {
          alert('Wrong response: ' + response)
        }
      },
      error: function (XMLHttpRequest, textStatus, exception) {
        alert("Ajax failure\n" + XMLHttpRequest.statusText);
      },
      async: true
    });
  }
}

jQuery(function() {
    if (jQuery('#ure_posts_edit_access_button').length==0) {
        return;
    }
    // "Posts Edit" button at User Role Editor dialog
    jQuery('#ure_posts_edit_access_button').button({
        label: ure_data_posts_edit_access.posts_edit
    }).on('click', (function(event) {
        event.preventDefault();
        ure_posts_edit_access.dialog_prepare();
    }));

});
