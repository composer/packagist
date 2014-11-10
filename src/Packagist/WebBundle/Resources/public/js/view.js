/*jslint nomen: true, browser: true*/
(function ($, humane, ZeroClipboard) {
    "use strict";
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
    $('.package .version h1').click(function (e) {
        e.preventDefault();
        $(this).siblings('.details-toggler').click();
    });
    $('.package .details-toggler').click(function () {
        var target = $(this);
        target.toggleClass('open')
            .prev().toggleClass('open');
        if (target.attr('data-load-more')) {
            $.ajax({
                url: target.attr('data-load-more'),
                dataType: 'json',
                success: function (data) {
                    target.attr('data-load-more', '')
                        .prev().html(data.content);
                }
            });
        }
    });

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
                $(this).toggleClass('icon-star icon-star-empty');
            },
            context: this
        };
        e.preventDefault();
        if ($(this).is('.loading')) {
            return;
        }
        if ($(this).is('.icon-star')) {
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
    $('.package .delete-version').click(function (e) {
        e.stopImmediatePropagation();
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

    ZeroClipboard.setMoviePath("/js/libs/ZeroClipboard.swf");
    var clip = new ZeroClipboard.Client("#copy");
    clip.on("complete", function () {
        humane.log("Copied");
    });
}(jQuery, humane, ZeroClipboard));
