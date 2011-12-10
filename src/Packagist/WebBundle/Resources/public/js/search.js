(function ($) {
    var form = $('form#search-form'),
        showResults,
        doSearch,
        searching = false,
        searchQueued = false;

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
        if (searching) {
            searchQueued = true;
            return;
        }

        $.get(form.attr('action'), form.serialize(), showResults);

        searching = true;
    };

    form.bind('keyup search', doSearch);
})(jQuery);
