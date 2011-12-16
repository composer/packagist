"use strict";

/*
    Adjust width for packades data
*/
$(window).load(function()
{
    var row = $('ul.packages');
    if(!row.length) return;
    $('div.package-details > div').css('min-width', Math.max(400, Math.floor(860 - row.width())) + 'px');
});