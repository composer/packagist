/*jslint nomen: true, browser: true*/
(function ($, humane) {
    "use strict";

    var versionCache = {},
        ongoingRequest = false;

    $('#add-maintainer').click(function (e) {
        $('#remove-maintainer-form').addClass('hidden');
        $('#add-maintainer-form').toggleClass('hidden');
        e.preventDefault();
    });
    $('#remove-maintainer').click(function (e) {
        $('#add-maintainer-form').addClass('hidden');
        $('#remove-maintainer-form').toggleClass('hidden');
        e.preventDefault();
    });

    $('.package .details-toggler').click(function () {
        var target = $(this);

        if (versionCache[target.attr('data-version-id')]) {
            $('.package .version-details').html(versionCache[target.attr('data-version-id')]);
        } else if (target.attr('data-load-more')) {
            if (ongoingRequest) { // TODO cancel previous requests instead?
                return;
            }
            ongoingRequest = true;
            $('.package .version-details').addClass('loading');
            $.ajax({
                url: target.attr('data-load-more'),
                dataType: 'json',
                success: function (data) {
                    versionCache[target.attr('data-version-id')] = data.content;
                    ongoingRequest = false;
                    $('.package .version-details')
                        .removeClass('loading')
                        .html(data.content);
                }
            });
        }

        $('.package .versions .open').removeClass('open');
        target.toggleClass('open');
    });

    // initializer for #<version-id> present on page load
    (function () {
        var hash = document.location.hash;
        if (hash.length > 1) {
            hash = hash.substring(1);
            $('.package .details-toggler[data-version-id="'+hash+'"]').click();
        }
    }());

    function forceUpdatePackage(e, updateAll) {
        var submit = $('input[type=submit]', '.package .force-update'), data;
        if (e) {
            e.preventDefault();
        }
        if (submit.is('.loading')) {
            return;
        }
        data = $('.package .force-update').serializeArray();
        if (updateAll) {
            data.push({name: 'updateAll', value: '1'});
        }
        $.ajax({
            url: $('.package .force-update').attr('action'),
            dataType: 'json',
            cache: false,
            data: data,
            type: 'PUT',
            success: function () {
                window.location.href = window.location.href;
            },
            context: $('.package .force-update')[0]
        }).complete(function () { submit.removeClass('loading'); });
        submit.addClass('loading');
    }
    $('.package .force-update').submit(forceUpdatePackage);
    $('.package .mark-favorite').click(function (e) {
        var options = {
            dataType: 'json',
            cache: false,
            success: function () {
                $(this).toggleClass('is-starred');
            },
            context: this
        };
        e.preventDefault();
        if ($(this).is('.loading')) {
            return;
        }
        if ($(this).is('.is-starred')) {
            options.type = 'DELETE';
            options.url = $(this).data('remove-url');
        } else {
            options.type = 'POST';
            options.data = {"package": $(this).data('package')};
            options.url = $(this).data('add-url');
        }
        $.ajax(options).complete(function () { $(this).removeClass('loading'); });
        $(this).addClass('loading');
    });
    $('.package .delete').submit(function (e) {
        e.preventDefault();
        if (window.confirm('Are you sure?')) {
            e.target.submit();
        }
    });
    $('.package .delete-version .submit').click(function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        $(e.target).closest('.delete-version').submit();
    });
    $('.package .delete-version').submit(function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        if (window.confirm('Are you sure?')) {
            e.target.submit();
        }
    });
    $('.package').on('click', '.requireme input', function () {
        this.select();
    });
    if ($('.package').data('force-crawl')) {
        forceUpdatePackage(null, true);
    }

    var versionsList = $('.package .versions')[0];
    if (versionsList.offsetHeight < versionsList.scrollHeight) {
        $('.package .versions-expander').removeClass('hidden').on('click', function () {
            $(this).addClass('hidden')
            $(versionsList).css('overflow-y', 'visible')
                .css('max-height', 'auto');
        });
    }
}(jQuery, humane));
