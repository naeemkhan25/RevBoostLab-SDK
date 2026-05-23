jQuery(document).ready(function($) {
    // 1. License Page Logic
    var $eye = $('#toggle-license-visibility');
    if ($eye.length > 0) {
        $eye.on('click', function() {
            var input = document.getElementById('revboostlab-license-key');
            if (input.type === "password") {
                input.type = "text";
                $eye.removeClass('dashicons-visibility').addClass('dashicons-hidden');
            } else {
                input.type = "password";
                $eye.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            }
        });

        // Activate License
        $('#revboostlab-activate-license-btn').on('click', function(e) {
            e.preventDefault();
            var btn = $(this);
            var wrap = btn.closest('.revboostlab-license-wrap');
            var slug = wrap.data('slug');
            var ajaxUrl = wrap.data('ajax-url') || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
            var nonce = wrap.find('input[name="license_nonce"]').val() || wrap.find('#license_nonce').val();
            
            var key = $('#revboostlab-license-key').val().trim();
            if (!key) {
                alert('Please enter your license key.');
                return;
            }

            var originalText = btn.html();
            btn.html('<span class="dashicons dashicons-update spin" style="margin-right: 8px;"></span> Activating...').prop('disabled', true);

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: slug + '_activate_license',
                    license_key: key,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        window.location.reload();
                    } else {
                        alert(response.data.message || 'Activation failed.');
                        btn.html(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Connection error occurred. Please try again.');
                    btn.html(originalText).prop('disabled', false);
                }
            });
        });

        // Deactivate License
        $('#revboostlab-deactivate-license-btn').on('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to deactivate your license? Premium features will be disabled.')) {
                return;
            }

            var btn = $(this);
            var wrap = btn.closest('.revboostlab-license-wrap');
            var slug = wrap.data('slug');
            var ajaxUrl = wrap.data('ajax-url') || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
            var nonce = wrap.find('input[name="license_nonce"]').val() || wrap.find('#license_nonce').val();

            var originalText = btn.html();
            btn.html('<span class="dashicons dashicons-update spin" style="margin-right: 8px;"></span> Deactivating...').prop('disabled', true);

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: slug + '_deactivate_license',
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        window.location.reload();
                    } else {
                        alert(response.data.message || 'Deactivation failed.');
                        btn.html(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Connection error occurred. Please try again.');
                    btn.html(originalText).prop('disabled', false);
                }
            });
        });
    }

    // 2. Plugins List Page Logic (Deactivation Interceptor)
    var deactivateUrl = '';

    console.log('[RevBoostLab SDK] JS loaded and ready.');

    // Delegate deactivation link click to support dynamic listings safely
    $(document).on('click', 'a[id^="deactivate-"], tr .deactivate a, a[href*="action=deactivate"]', function(e) {
        var $link = $(this);
        var href = $link.attr('href') || '';
        var id = $link.attr('id') || '';
        
        console.log('[RevBoostLab SDK] Deactivate clicked. ID:', id, 'HREF:', href);

        // Exclude non-deactivate link clicks if any
        if (href.indexOf('action=deactivate') === -1) {
            return;
        }

        var $modal = null;
        $('.revboostlab-deactivate-modal').each(function() {
            var modalSlug = $(this).data('slug');
            if (modalSlug && (id.indexOf(modalSlug) !== -1 || href.indexOf(modalSlug) !== -1)) {
                $modal = $(this);
                return false; // Break loop
            }
        });

        if ($modal && $modal.length > 0) {
            console.log('[RevBoostLab SDK] Matching modal found:', $modal.attr('id'));
            e.preventDefault();
            deactivateUrl = href;
            $modal.fadeIn(200);
        } else {
            console.log('[RevBoostLab SDK] No matching modal found for this link.');
        }
    });

    // Close modal handlers (using delegated events)
    $(document).on('click', '.revboostlab-close-modal, .revboostlab-modal-overlay', function() {
        $(this).closest('.revboostlab-deactivate-modal').fadeOut(150);
    });

    // Handle Skip and Deactivate
    $(document).on('click', '.revboostlab-skip-deactivate', function(e) {
        e.preventDefault();
        var btn = $(this);
        var modal = btn.closest('.revboostlab-deactivate-modal');
        var slug = modal.data('slug');
        var nonce = modal.data('feedback-nonce');
        var ajaxUrl = modal.data('ajax-url') || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
        
        btn.text('Deactivating...').prop('disabled', true);
        modal.find('.revboostlab-submit-deactivate').prop('disabled', true);
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: slug + '_deactivate_feedback',
                reason: 'skipped',
                comments: 'User skipped feedback.',
                nonce: nonce
            },
            complete: function() {
                window.location.href = deactivateUrl;
            }
        });
    });

    // Handle Submit and Deactivate
    $(document).on('click', '.revboostlab-submit-deactivate', function(e) {
        e.preventDefault();
        var btn = $(this);
        var modal = btn.closest('.revboostlab-deactivate-modal');
        var slug = modal.data('slug');
        var nonce = modal.data('feedback-nonce');
        var ajaxUrl = modal.data('ajax-url') || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');

        var selectedReason = modal.find('input[name="revboostlab_deactivate_reason_' + slug + '"]:checked').val();
        if (!selectedReason) {
            alert('Please select a reason or click Skip.');
            return;
        }

        var comments = modal.find('#revboostlab-deactivate-comments-' + slug).val();
        btn.text('Submitting...').prop('disabled', true);
        modal.find('.revboostlab-skip-deactivate').prop('disabled', true);

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: slug + '_deactivate_feedback',
                reason: selectedReason,
                comments: comments,
                nonce: nonce
            },
            complete: function() {
                window.location.href = deactivateUrl;
            }
        });
    });

    // Show textarea if 'other' is selected (using delegated events)
    $(document).on('change', '.revboostlab-deactivate-reason-radio', function() {
        var $radio = $(this);
        var modal = $radio.closest('.revboostlab-deactivate-modal');
        if ($radio.val() === 'other') {
            modal.find('.revboostlab-other-reason-input').slideDown(150);
        } else {
            modal.find('.revboostlab-other-reason-input').slideUp(150);
        }
    });
});
