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
})(jQuery, humane);