(function ($) {
    var form = $('form#search-form'),
        showResults,
        doSearch,
        searching = false,
        searchQueued = false,
        previousQuery;

    showResults = function (page) {
        var list = $('.package-list'),
            newList = $(page);

        if (newList.find('.packages li').length) {
            list.replaceWith(newList);
            list.show();
        } else {
            list.hide();
        }

        searching = false;

        if (searchQueued) {
            doSearch();
            searchQueued = false;
        }
    };

    doSearch = function () {
        var currentQuery;

        if (searching) {
            searchQueued = true;
            return;
        }

        currentQuery = form.serialize();

        if (previousQuery === currentQuery) {
            return;
        }

        $.get(form.attr('action'), currentQuery, showResults);

        searching = true;
        previousQuery = currentQuery;
    };

    form.bind('keyup search', doSearch);
})(jQuery);
