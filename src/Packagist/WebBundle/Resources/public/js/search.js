/*jslint browser: true */
/*global jQuery: true */
(function ($) {
    "use strict";

    var list = $('.search-list')
        , form = $('form#search-form')
        , showResults
        , doSearch
        , searching = false
        , searchQueued = false
        , previousQuery;

    showResults = function (page) {
        var newList = $(page);

        list.html(newList.html());
        list.removeClass('hidden');
        list.find('ul.packages li:first').addClass('selected');

        searching = false;

        if (searchQueued) {
            doSearch();
            searchQueued = false;
        };
    };

    doSearch = function () {
        var currentQuery;

        if (searching) {
            searchQueued = true;
            return;
        };

        if ($('#search_query_query').val().match(/^\s*$/) !== null) {
            if (previousQuery !== undefined) list.addClass('hidden');
            return;
        };

        currentQuery = form.serialize();

        if (previousQuery === currentQuery) return;

        if (window.history.pushState) {
            var states = [
                null,
                "Search",
                "/search/?q=" + encodeURIComponent($('input[type="search"]', form).val())
            ];
            
            undefined === previousQuery ? 
                window.history.pushState.apply(window, states) : 
                window.history.replaceState.apply(window, states);
        };

        $.ajax({
            url: form.attr('action'),
            data: currentQuery,
            success: showResults
        });

        searching = true;
        previousQuery = currentQuery;
    };

    form.bind('keyup search', doSearch);

    form.bind('keydown', function (event) {
        event.preventDefault();
        
        var keymap
            , currentSelected
            , nextSelected;

        keymap = {
            enter: 13,
            left: 37,
            up: 38,
            right: 39,
            down: 40
        };

        if (~[keymap.up, keymap.down, keymap.enter].indexOf(event.which)) return;

        if ($('#search_query_query').val().match(/^\s*$/) !== null) {
            document.activeElement.blur();
            return;
        };

        currentSelected = list.find('ul.packages li.selected');
        nextSelected = (keymap.down === event.which) ? currentSelected.next('li') : currentSelected.prev('li');

        if (keymap.enter === event.which && currentSelected.data('url')) {
            window.location = currentSelected.data('url');
            return;
        };

        if (nextSelected.length > 0) {
            currentSelected.removeClass('selected');
            nextSelected.addClass('selected');

            var elTop = nextSelected.position().top
                , elHeight = nextSelected.height()
                , windowTop = $(window).scrollTop()
                , windowHeight = $(window).height();

            if (elTop < windowTop) {
                $(window).scrollTop(elTop);
            } else if (elTop + elHeight > windowTop + windowHeight) {
                $(window).scrollTop(elTop + elHeight + 20 - windowHeight);
            };
        };
    });
}(jQuery));
