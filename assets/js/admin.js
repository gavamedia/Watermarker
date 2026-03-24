/**
 * Watermarker — Admin settings media uploader.
 */
jQuery(document).ready(function ($) {
    var frame;

    $('#watermarker-upload-btn').on('click', function (e) {
        e.preventDefault();

        if (frame) {
            frame.open();
            return;
        }

        frame = wp.media({
            title: 'Select Letterhead Template',
            button: { text: 'Use as Letterhead' },
            multiple: false,
            library: { type: ['application/pdf', 'image'] }
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#watermarker_letterhead_id').val(attachment.id);
            $('#watermarker-letterhead-preview').html(
                '<p>Selected: <strong>' + attachment.filename + '</strong></p>'
            );
            $('#watermarker-upload-btn').text('Change Letterhead');

            // Ensure a Remove button exists.
            if ($('#watermarker-remove-btn').length === 0) {
                $('<button type="button" class="button" id="watermarker-remove-btn">Remove</button>')
                    .insertAfter('#watermarker-upload-btn');
                bindRemove();
            }
        });

        frame.open();
    });

    function bindRemove() {
        $('#watermarker-remove-btn').off('click').on('click', function (e) {
            e.preventDefault();
            $('#watermarker_letterhead_id').val('');
            $('#watermarker-letterhead-preview').html('');
            $('#watermarker-upload-btn').text('Upload Letterhead');
            $(this).remove();
        });
    }

    bindRemove();

    // ---- Font management ----

    if (typeof watermarkerFonts !== 'undefined') {

        // Upload button click -> trigger hidden file input
        $(document).on('click', '.wm-font-upload', function () {
            $(this).next('input[type=file]').trigger('click');
        });

        // File selected -> upload via AJAX
        $(document).on('change', '#watermarker-fonts-table input[type=file]', function () {
            var input = $(this);
            var btn = input.prev('.wm-font-upload');
            var family = btn.data('family');
            var variant = btn.data('variant');
            var file = this.files[0];
            if (!file) return;

            var cell = $('#wm-font-' + family + '-' + variant);
            cell.html('<em>Installing\u2026</em>');

            var fd = new FormData();
            fd.append('action', 'watermarker_upload_font');
            fd.append('nonce', watermarkerFonts.nonce);
            fd.append('family', family);
            fd.append('variant', variant);
            fd.append('font_file', file);

            $.ajax({
                url: watermarkerFonts.ajaxUrl,
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                success: function (resp) {
                    if (resp.success) {
                        cell.html(
                            '<span style="color:green">Installed</span> ' +
                            '<button type="button" class="button-link wm-font-delete" data-family="' + family + '" data-variant="' + variant + '" style="color:#b32d2e;margin-left:4px;">Delete</button>'
                        );
                    } else {
                        cell.html(
                            '<span style="color:red">' + (resp.data && resp.data.message || 'Error') + '</span> ' +
                            '<button type="button" class="button button-small wm-font-upload" data-family="' + family + '" data-variant="' + variant + '">Retry</button>' +
                            '<input type="file" accept=".ttf" style="display:none;">'
                        );
                    }
                },
                error: function () {
                    cell.html(
                        '<span style="color:red">Upload failed</span> ' +
                        '<button type="button" class="button button-small wm-font-upload" data-family="' + family + '" data-variant="' + variant + '">Retry</button>' +
                        '<input type="file" accept=".ttf" style="display:none;">'
                    );
                }
            });
        });

        // Delete font
        $(document).on('click', '.wm-font-delete', function () {
            var btn = $(this);
            var family = btn.data('family');
            var variant = btn.data('variant');
            if (!confirm('Remove this font variant?')) return;

            var cell = $('#wm-font-' + family + '-' + variant);
            $.post(watermarkerFonts.ajaxUrl, {
                action: 'watermarker_delete_font',
                nonce: watermarkerFonts.nonce,
                family: family,
                variant: variant
            }, function () {
                cell.html(
                    '<button type="button" class="button button-small wm-font-upload" data-family="' + family + '" data-variant="' + variant + '">Upload</button>' +
                    '<input type="file" accept=".ttf" style="display:none;">'
                );
            });
        });
    }
});
