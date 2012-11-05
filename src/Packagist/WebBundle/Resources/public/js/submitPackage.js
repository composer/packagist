(function ($) {
    var onSubmit = function(e) {
        var success;
        $('div > ul, div.confirmation', this).remove();
        success = function (data) {
            var html = '';
            $('#submit').removeClass('loading');
            if (data.status === 'error') {
                $.each(data.reason, function (k, v) {
                    html += '<li>'+v+'</li>';
                });
                $('#submit-package-form div').prepend('<ul>'+html+'</ul>');
            } else {
                $('#submit-package-form input[type="submit"]').before(
                    '<div class="confirmation">The package name found for your repository is: <strong>'+data.name+'</strong>, press Submit to confirm.</div>'
                );
                $('#submit').val('Submit');
                $('#submit-package-form').unbind('submit');
            }
        };
        $.post($(this).data('check-url'), $(this).serializeArray(), success);
        $('#submit').addClass('loading');
        e.preventDefault();
    };

    $('#package_repository').change(function() {
        $('#submit-package-form').unbind('submit');
        $('#submit-package-form').submit(onSubmit);
        $('#submit').val('Check');
    });

    $('#package_repository').triggerHandler('change');
})(jQuery);

