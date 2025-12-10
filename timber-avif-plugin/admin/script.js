jQuery(document).ready(function($) {
    // Tab switching
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        var target = $(this).attr('href');

        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        $('.tab-content').removeClass('active');
        $(target).addClass('active');
    });

    // Bulk convert
    $('#bulk-convert-btn').on('click', function() {
        if (!confirm('Start bulk conversion? This may take a while depending on the number of images.')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Converting...');
        $('#bulk-convert-progress').show();
        $('#bulk-progress-bar').val(50);
        $('#bulk-progress-text').text('Processing images...');

        $.ajax({
            url: timberAvifAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'timber_avif_bulk_convert',
                nonce: timberAvifAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#bulk-progress-text').html('<strong>✓ Completed!</strong> ' + response.data.message);
                    $('#bulk-progress-bar').val(100);
                    $btn.text('Start Bulk Conversion');
                    
                    // Reload stats after 2 seconds
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    alert('Error: ' + response.data.message);
                    $btn.prop('disabled', false).text('Start Bulk Conversion');
                    $('#bulk-convert-progress').hide();
                }
            },
            error: function(xhr, status, error) {
                alert('An error occurred during bulk conversion: ' + error);
                $btn.prop('disabled', false).text('Start Bulk Conversion');
                $('#bulk-convert-progress').hide();
            }
        });
    });
});
