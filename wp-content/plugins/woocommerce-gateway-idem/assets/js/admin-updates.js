/**
 * IDEM Payment Gateway - Admin Updates Interface
 */
jQuery(document).ready(function($) {
    
    // Add update status section after auto-update setting
    if ($('#woocommerce_idem_auto_update_enabled').length) {
        var updateStatusHtml = `
            <tr valign="top">
                <th scope="row" class="titledesc">
                    <label>Update Status</label>
                </th>
                <td class="forminp">
                    <div id="idem-update-status-container" style="background: #f5f5f5; padding: 15px; border-radius: 5px;">
                        <div id="idem-update-status-loading">
                            <span class="spinner is-active" style="float: none;"></span> Checking for updates...
                        </div>
                        <div id="idem-update-status-content" style="display: none;">
                            <p><strong>Current Version:</strong> <span id="idem-current-version">-</span></p>
                            <p><strong>Latest Version:</strong> <span id="idem-latest-version">-</span></p>
                            <p><strong>Last Check:</strong> <span id="idem-last-check">-</span></p>
                            <div id="idem-update-available" style="display: none; margin-top: 10px;">
                                <div class="notice notice-warning inline" style="margin: 10px 0;">
                                    <p><strong>Update Available!</strong> A new version is available for download.</p>
                                </div>
                                <button type="button" id="idem-manual-update-btn" class="button button-primary">Update Now</button>
                                <span id="idem-update-progress" style="display: none; margin-left: 10px;">
                                    <span class="spinner is-active" style="float: none;"></span> Updating...
                                </span>
                            </div>
                            <div id="idem-up-to-date" style="display: none; margin-top: 10px;">
                                <div class="notice notice-success inline" style="margin: 10px 0;">
                                    <p>✓ Plugin is up to date</p>
                                </div>
                            </div>
                            <div id="idem-update-history" style="margin-top: 15px;">
                                <h4>Backup History</h4>
                                <div id="idem-backup-list">
                                    <p>No backups found</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        `;
        
        // Insert after the auto-update setting row
        $('#woocommerce_idem_auto_update_enabled').closest('tr').after(updateStatusHtml);
        
        // Check update status
        checkUpdateStatus();
        
        // Manual update button handler
        $(document).on('click', '#idem-manual-update-btn', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to update the plugin now? The site will be in maintenance mode during the update.')) {
                return;
            }
            
            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#idem-update-progress').show();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'idem_manual_update',
                    nonce: idem_admin_settings.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#idem-update-available').hide();
                        $('#idem-up-to-date').show();
                        $('#idem-current-version').text(response.data.new_version);
                        
                        // Show success message
                        var successHtml = '<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>';
                        $('#idem-update-status-container').before(successHtml);
                        
                        // Refresh page after 3 seconds
                        setTimeout(function() {
                            window.location.reload();
                        }, 3000);
                    } else {
                        // Show error message
                        var errorHtml = '<div class="notice notice-error is-dismissible"><p><strong>Update Failed:</strong> ' + response.data.message + '</p></div>';
                        $('#idem-update-status-container').before(errorHtml);
                    }
                },
                error: function() {
                    var errorHtml = '<div class="notice notice-error is-dismissible"><p><strong>Update Failed:</strong> An unexpected error occurred.</p></div>';
                    $('#idem-update-status-container').before(errorHtml);
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $('#idem-update-progress').hide();
                }
            });
        });
        
        // Rollback button handler
        $(document).on('click', '.idem-rollback-btn', function(e) {
            e.preventDefault();
            
            var backupFile = $(this).data('backup');
            
            if (!confirm('Are you sure you want to rollback to this version? Current version will be replaced.')) {
                return;
            }
            
            var $btn = $(this);
            $btn.prop('disabled', true).text('Rolling back...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'idem_rollback',
                    backup: backupFile,
                    nonce: idem_admin_settings.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        var successHtml = '<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>';
                        $('#idem-update-status-container').before(successHtml);
                        
                        // Refresh page after 2 seconds
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        // Show error message
                        var errorHtml = '<div class="notice notice-error is-dismissible"><p><strong>Rollback Failed:</strong> ' + response.data.message + '</p></div>';
                        $('#idem-update-status-container').before(errorHtml);
                        $btn.prop('disabled', false).text('Rollback');
                    }
                },
                error: function() {
                    var errorHtml = '<div class="notice notice-error is-dismissible"><p><strong>Rollback Failed:</strong> An unexpected error occurred.</p></div>';
                    $('#idem-update-status-container').before(errorHtml);
                    $btn.prop('disabled', false).text('Rollback');
                }
            });
        });
    }
    
    function checkUpdateStatus() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'idem_check_update_status',
                nonce: idem_admin_settings.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    
                    $('#idem-current-version').text(data.current_version);
                    $('#idem-latest-version').text(data.latest_version || 'Unable to check');
                    
                    // Format last check time
                    if (data.last_check) {
                        var lastCheck = new Date(data.last_check * 1000);
                        $('#idem-last-check').text(lastCheck.toLocaleString());
                    } else {
                        $('#idem-last-check').text('Never');
                    }
                    
                    // Show update available or up-to-date message
                    if (data.update_available) {
                        $('#idem-update-available').show();
                        $('#idem-up-to-date').hide();
                    } else {
                        $('#idem-update-available').hide();
                        $('#idem-up-to-date').show();
                    }
                    
                    // Display backups
                    if (data.backups && data.backups.length > 0) {
                        var backupHtml = '<table class="widefat" style="margin-top: 10px;"><thead><tr><th>Backup File</th><th>Size</th><th>Date</th><th>Action</th></tr></thead><tbody>';
                        
                        data.backups.forEach(function(backup) {
                            backupHtml += '<tr>';
                            backupHtml += '<td>' + backup.file + '</td>';
                            backupHtml += '<td>' + backup.size + '</td>';
                            backupHtml += '<td>' + backup.date + '</td>';
                            backupHtml += '<td><button type="button" class="button idem-rollback-btn" data-backup="' + backup.file + '">Rollback</button></td>';
                            backupHtml += '</tr>';
                        });
                        
                        backupHtml += '</tbody></table>';
                        $('#idem-backup-list').html(backupHtml);
                    }
                    
                    $('#idem-update-status-loading').hide();
                    $('#idem-update-status-content').show();
                }
            },
            error: function() {
                $('#idem-update-status-loading').html('<p style="color: red;">Failed to check update status</p>');
            }
        });
    }
});