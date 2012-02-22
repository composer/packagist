(function ($) {
    $('#add-maintainer').click(function (e) {
        $('#add-maintainer-form').toggleClass('hidden');
        e.preventDefault();
    });
    $('.package .details-toggler').click(function (e) {
        $(this).toggleClass('open')
            .prev().toggleClass('open');
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
        });
        submit.addClass('loading');
    });
})(jQuery);