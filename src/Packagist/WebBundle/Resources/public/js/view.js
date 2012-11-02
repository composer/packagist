(function ($) {
    $('#add-maintainer').click(function (e) {
        $('#add-maintainer-form').toggleClass('hidden');
        e.preventDefault();
    });
    $('.package .details-toggler').click(function (e) {
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
    $('.package .force-update').submit(function (e) {
        var submit = $('input[type=submit]', this);
        e.preventDefault();
        if (submit.is('.loading')) {
            return;
        }
        $.ajax({
            url: $(this).attr('action'),
            dataType: 'json',
            cache: false,
            data: $(this).serializeArray(),
            type: 'PUT',
            success: function (data) {
                window.location.href = window.location.href;
            },
            context: this
        }).complete(function () { submit.removeClass('loading'); });
        submit.addClass('loading');
    });
    $('.package .mark-favorite').click(function (e) {
        var options = {
            dataType: 'json',
            cache: false,
            success: function (data) {
                $(this).toggleClass('is-favorite');
            },
            context: this
        };
        e.preventDefault();
        if ($(this).is('.loading')) {
            return;
        }
        if ($(this).is('.is-favorite')) {
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
    $('.package .force-delete').submit(function (e) {
        e.preventDefault();
        if (confirm('Are you sure?')) {
            e.target.submit();
        }
    });
    $('.package').on('click', '.requireme input', function (e) {
        this.select();
    });
    if ($('.package').data('force-crawl')) {
        $('.package .force-update').submit();
    }
})(jQuery);