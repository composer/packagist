(function ($) {
    var doSearch,
        searching = false,
        searchQueued = false;

    doSearch = function () {
        var form = $('form#search-form');

        $.get(form.attr('action'), form.serialize(), function (page) {
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
        });

        searching = true;
    };

    $('form#search-form').keyup(function (event) {
        if (searching) {
            searchQueued = true;
        } else {
            doSearch();
        }
    });
})(jQuery);
