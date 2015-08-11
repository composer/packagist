/*jslint browser: true */
/*global jQuery: true */
(function ($) {
    "use strict";

    var list = $('.search-list'),
        form = $('form#search-form'),
        showResults,
        doSearch,
        searching = false,
        searchQueued = false,
        previousQuery = form.serialize(),
        firstQuery = true;

    showResults = function (page) {
        var newList = $(page);

        list.html(newList.html());
        list.parent().removeClass('hidden');
        list.find('ul.packages li:first').addClass('selected');
        $('.order-by-group').attr('href', function (index, current) {
            return current.replace(/q=.*?&/, 'q=' + encodeURIComponent($('input[type="search"]', form).val()) + '&')
        });
        $('.js-search-field-wrapper').removeClass('col-xs-12').addClass('col-xs-8');
        $('#order-bys-wrapper').removeClass('hidden');

        searching = false;

        if (searchQueued) {
            doSearch();
            searchQueued = false;
        }
    };

    doSearch = function () {
        var currentQuery,
            q,
            pathname,
            urlPrefix,
            url,
            title;

        if (searching) {
            searchQueued = true;
            return;
        }

        if ($('#search_query_query').val().match(/^\s*$/) !== null) {
            if (!firstQuery) {
                list.parent().addClass('hidden');
                $('#order-bys-wrapper').addClass('hidden');
            }
            return;
        }

        currentQuery = form.serialize();

        if (previousQuery === currentQuery) {
            return;
        }

        $('.order-by-group .active').removeClass('active');

        if (window.history.pushState) {
            q = encodeURIComponent($('input[type="search"]', form).val());

            pathname = window.location.pathname;

            if (pathname.indexOf('/app_dev.php') === 0) {
                urlPrefix = '/app_dev.php';
            } else if (pathname.indexOf('/app.php') === 0) {
                urlPrefix = '/app.php';
            } else {
                urlPrefix = '';
            }

            url = urlPrefix + '/search/?q=' + q;
            title = 'Search';

            if (firstQuery) {
                window.history.pushState(null, title, url);
                firstQuery = false;
            } else {
                window.history.replaceState(null, title, url);
            }
        }

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
        var keymap,
            currentSelected,
            nextSelected;

        keymap = {
            enter: 13,
            left: 37,
            up: 38,
            right: 39,
            down: 40
        };

        if (keymap.up !== event.which && keymap.down !== event.which && keymap.enter !== event.which) {
            return;
        }

        if ($('#search_query_query').val().match(/^\s*$/) !== null) {
            document.activeElement.blur();
            return;
        }

        event.preventDefault();

        currentSelected = list.find('ul.packages li.selected');
        nextSelected = (keymap.down === event.which) ? currentSelected.next('li') : currentSelected.prev('li');

        if (currentSelected.length === 0 && keymap.down === event.which) {
            currentSelected = list.find('ul.packages li:first').addClass('selected');
        }

        if (keymap.enter === event.which && currentSelected.data('url')) {
            window.location = currentSelected.data('url');
            return;
        }

        if (nextSelected.length > 0) {
            currentSelected.removeClass('selected');
            nextSelected.addClass('selected');

            var elTop = nextSelected.position().top,
                elHeight = nextSelected.height(),
                windowTop = $(window).scrollTop(),
                windowHeight = $(window).height();

            if (elTop < windowTop) {
                $(window).scrollTop(elTop);
            } else if (elTop + elHeight > windowTop + windowHeight) {
                $(window).scrollTop(elTop + elHeight + 20 - windowHeight);
            }
        }
    });

    // handle pressing S to focus the search
    $(document.body).bind('keyup', function (event) {
        if (event.which === 83 && (!document.activeElement || -1 === ['INPUT','SELECT','TEXTAREA'].indexOf(document.activeElement.tagName))) {
            $('#search_query_query').focus();
        }
    });

    if ($(document).width() >= 992) {
        $('#search_query_query').focus();
    }
}(jQuery));
