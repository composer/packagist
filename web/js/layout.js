(function ($, humane) {
    "use strict";

    /**
     * Ajax error handler
     */
    $.ajaxSetup({
        error: function (xhr) {
            var resp, message, details = '';

            humane.remove();

            if (xhr.responseText) {
                try {
                    resp = JSON.parse(xhr.responseText);
                    if (resp.status && resp.status === 'error') {
                        message = resp.message;
                        details = resp.details;
                    }
                } catch (e) {
                    message = "We're so sorry, something is wrong on our end.";
                }
            }

            humane.log(details ? [message, details] : message, {timeout: 0, clickToClose: true});
        }
    });

    /**
     * API Token visibility toggling
     */
    var token = $('#api-token');
    token.val('');

    $('.btn-show-api-token,#api-token').each(function() {
        $(this).click(function (e) {
            token.val(token.data('api-token'));
            token.select();

            $('.btn-show-api-token').text('Your API token');

            e.preventDefault();
        });
    });

    $('.toc a').click(function (e) {
        setTimeout(function () {
            scrollTo(0, $($(e.target).attr('href')).offset().top - 65);
        }, 0);
    });
})(jQuery, humane);
