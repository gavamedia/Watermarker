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
});
