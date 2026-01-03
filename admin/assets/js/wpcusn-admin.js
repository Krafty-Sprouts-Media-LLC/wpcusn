jQuery(document).ready(function ($) {
    // === Auto-Save System ===
    var saveTimeout = null;
    var isSaving = false;
    var initialFormData = null;

    // Create save indicator
    function createSaveIndicator() {
        if ($('#wpcusn-save-indicator').length === 0) {
            $('body').append('<div id="wpcusn-save-indicator" style="position: fixed; top: 32px; right: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 12px 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 999999; display: none; font-size: 14px; font-weight: 500;"><span class="indicator-icon" style="display: inline-block; margin-right: 8px;"></span><span class="indicator-text"></span></div>');
        }
    }

    // Show save indicator
    function showSaveIndicator(status, message) {
        var $indicator = $('#wpcusn-save-indicator');
        var $icon = $indicator.find('.indicator-icon');
        var $text = $indicator.find('.indicator-text');

        if (status === 'saving') {
            $icon.html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;"><circle cx="12" cy="12" r="10" opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10" opacity="0.75"/></svg>');
            $text.text(message || 'Saving...');
            $indicator.css({ background: '#fff', color: '#666', borderColor: '#ddd' }).fadeIn(200);
        } else if (status === 'success') {
            $icon.html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>');
            $text.text(message || 'Saved');
            $indicator.css({ background: '#f0fdf4', color: '#16a34a', borderColor: '#bbf7d0' }).fadeIn(200);
            setTimeout(function () { $indicator.fadeOut(300); }, 2000);
        } else if (status === 'error') {
            $icon.html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>');
            $text.text(message || 'Save failed');
            $indicator.css({ background: '#fef2f2', color: '#dc2626', borderColor: '#fecaca' }).fadeIn(200);
            setTimeout(function () { $indicator.fadeOut(300); }, 3000);
        }
    }

    // Add CSS for spinner animation
    if ($('#wpcusn-autosave-styles').length === 0) {
        $('head').append('<style id="wpcusn-autosave-styles">@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>');
    }

    // Get current form data
    function getFormData() {
        var $form = $('form[action*="admin-post.php"]').first();
        var data = {};

        $form.find('input, select, textarea').each(function () {
            var $field = $(this);
            var name = $field.attr('name');

            if (!name || name === 'action' || name.indexOf('nonce') !== -1) return;

            if ($field.attr('type') === 'checkbox') {
                data[name] = $field.is(':checked') ? '1' : '0';
            } else if ($field.attr('type') !== 'hidden' || name.indexOf('wpcusn_') === 0) {
                data[name] = $field.val();
            }
        });

        return JSON.stringify(data);
    }

    // Save settings via AJAX
    function autoSaveSettings() {
        if (isSaving) return;

        var $form = $('form[action*="admin-post.php"]').first();
        var formData = new FormData($form[0]);

        isSaving = true;
        showSaveIndicator('saving');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wpcusn_autosave_settings',
                nonce: wpcusn_vars.nonce,
                form_data: $form.serialize()
            },
            success: function (response) {
                if (response.success) {
                    showSaveIndicator('success', response.data.message || 'Settings saved');
                    initialFormData = getFormData();
                } else {
                    showSaveIndicator('error', response.data.message || 'Failed to save');
                }
            },
            error: function () {
                showSaveIndicator('error', 'Network error');
            },
            complete: function () {
                isSaving = false;
            }
        });
    }

    // Debounced save
    function triggerAutoSave() {
        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(function () {
            var currentData = getFormData();
            if (currentData !== initialFormData) {
                autoSaveSettings();
            }
        }, 1000); // 1 second delay
    }

    // Initialize auto-save
    function initAutoSave() {
        createSaveIndicator();
        initialFormData = getFormData();

        // Watch for changes on all form fields
        var $form = $('form[action*="admin-post.php"]').first();

        $form.find('input[type="text"], input[type="password"], input[type="number"], textarea, select').on('input change', function () {
            triggerAutoSave();
        });

        $form.find('input[type="checkbox"]').on('change', function () {
            triggerAutoSave();
        });
    }

    // Initialize on page load
    initAutoSave();

    // === Space ID Logic ===

    // Manual space ID toggle
    $('#wpcusn-toggle-manual-space').on('click', function (e) {
        e.preventDefault();
        var select = $('#wpcusn_space_id');
        var manual = $('#wpcusn_space_id_manual');
        var link = $(this);
        var teamInput = $('#wpcusn_team_id');
        var wrapper = $('.input-select-wrapper');

        if (manual.is(':visible')) {
            // Switch to Dropdown
            wrapper.show();
            select.attr('name', 'wpcusn_space_id').prop('disabled', false);
            manual.removeAttr('name').hide();
            link.text('Enter Space ID manually');

            // Restore Team ID from selected option
            var opt = select.find('option:selected');
            teamInput.val(opt.data('team-id') || '');
        } else {
            // Switch to Manual
            wrapper.hide();
            manual.attr('name', 'wpcusn_space_id').show();
            select.removeAttr('name').prop('disabled', true);
            link.text('Use dropdown list');

            // Clear team ID as manual entry has no context
            teamInput.val('');
        }

        // Trigger auto-save after toggle
        triggerAutoSave();
    });

    // Auto-load spaces logic
    var loadingSpaces = false;

    $('#wpcusn-load-spaces').on('click', function (e) {
        e.preventDefault();
        if (loadingSpaces) return;

        var button = $(this);
        var select = $('#wpcusn_space_id');
        var originalText = button.text();

        button.prop('disabled', true).text('Loading...');
        loadingSpaces = true;

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wpcusn_get_spaces',
                nonce: wpcusn_vars.nonce
            },
            success: function (response) {
                if (response.success && response.data.spaces) {
                    var currentSpaceId = $('#wpcusn_current_space_id').val() || '';

                    // Clear existing (keep first placeholder if needed, or just refresh all)
                    select.find('option').remove();

                    if (response.data.spaces.length === 0) {
                        select.append('<option value="">No spaces found</option>');
                    } else {
                        $.each(response.data.spaces, function (i, space) {
                            var selected = (space.id == currentSpaceId) ? ' selected' : '';
                            var label = space.name + (space.team_name ? ' (' + space.team_name + ')' : '');
                            select.append('<option value="' + space.id + '"' + selected + ' data-team-id="' + space.team_id + '">' + label + '</option>');
                        });
                    }

                    // Update hidden team input
                    var selectedOpt = select.find('option:selected');
                    if (selectedOpt.length) {
                        $('#wpcusn_team_id').val(selectedOpt.data('team-id') || '');
                    }

                    // Bind change event
                    select.off('change.wpcusn').on('change.wpcusn', function () {
                        var opt = $(this).find('option:selected');
                        $('#wpcusn_team_id').val(opt.data('team-id') || '');
                    });

                    // Toast success (mock)
                    // alert('Spaces refreshed successfully'); 
                } else {
                    alert(response.data.message || 'Failed to load spaces');
                }
            },
            error: function () {
                alert('Error loading spaces. Check console.');
            },
            complete: function () {
                button.prop('disabled', false).text(originalText);
                loadingSpaces = false;
            }
        });
    });

    // Auto-load on page load if dropdown is empty but connected
    if ($('#wpcusn_space_id').length && $('#wpcusn_space_id option').length <= 1) {
        // Optional: Trigger load automatically
        // $('#wpcusn-load-spaces').trigger('click');
    }

    // === Advanced Auth Toggle ===
    $('#wpcusn-toggle-advanced-auth').on('click', function (e) {
        e.preventDefault();
        $('#wpcusn-advanced-auth-group').slideToggle(200);
    });

    // === Copy to Clipboard ===
    $('.wpcusn-copy-btn').on('click', function () {
        var targetId = $(this).data('target');
        var text = $(targetId).val() || $(targetId).text();

        if (text) {
            navigator.clipboard.writeText(text).then(function () {
                alert('Copied to clipboard!');
            });
        }
    });

    // === Logs Console Scroll ===
    var terminal = $('.wpcusn-terminal-body');
    if (terminal.length) {
        // Scroll to bottom optionally
        // terminal.scrollTop(terminal[0].scrollHeight);
    }
});
