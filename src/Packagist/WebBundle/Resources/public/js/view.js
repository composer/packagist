(function ($) {
    $('#add-maintainer').click(function (e) {
        $('#add-maintainer-form').toggleClass('hidden');
        e.preventDefault();
    });
    $('.package .details-toggler').click(function (e) {
        $(this).toggleClass('open')
            .prev().toggleClass('open');
    });
})(jQuery);