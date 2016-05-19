(function ($) {
    var showSimilarMax = 5;
    var onSubmit = function(e) {
        var success;
        $('ul.package-errors, ul.similar-packages, div.confirmation', this).remove();
        success = function (data) {
            var html = '';
            $('#submit').removeClass('loading');
            if (data.status === 'error') {
                $.each(data.reason, function (k, v) {
                    html += '<li><div class="alert alert-warning">'+v+'</div></li>';
                });
                $('#submit-package-form').prepend('<ul class="list-unstyled package-errors">'+html+'</ul>');
            } else {
                if (data.similar.length) {
                    var $similar = $('<ul class="list-unstyled similar-packages">');
                    var limit = data.similar.length > showSimilarMax ? showSimilarMax : data.similar.length;
                    for ( var i = 0; i < limit; i++ ) {
                        var similar = data.similar[i];
                        var $link = $('<a>').attr('href', similar.url).text(similar.name);
                        $similar.append($('<li>').append($link))
                    }
                    if (limit != data.similar.length) {
                        $similar.append($('<li>').text('And ' + (data.similar.length - limit) + ' more'));
                    }
                    $('#submit-package-form input[type="submit"]').before($('<div>').append(
                        '<p><strong>Notice:</strong> One or more similarly named packages have already been submitted to Packagist. If this is a fork read the notice above regarding VCS Repositories.'
                    ).append(
                        '<p>Similarly named packages:'
                    ).append($similar));
                }
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

