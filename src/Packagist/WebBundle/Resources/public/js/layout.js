"use strict";

/*
    Adjust width for packades data
*/
$(window).load(function() {
    var row = $('ul.packages');
    if (row.length) {
        $('div.package-details > div').css({
            'min-width': Math.max(400, Math.floor(860 - row.width())) + 'px'
        });
    }
});

/*
    Ajax error handler
*/
$.ajaxSetup({
    error: function (xhr) {
        humane.info("We're so sorry, something is wrong on our end.");
    }
})
