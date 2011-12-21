"use strict";

/**
 * Ajax error handler
 */
$.ajaxSetup({
    error: function (xhr) {
        humane.info("We're so sorry, something is wrong on our end.");
    }
})
