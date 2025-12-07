import Plausible from 'plausible-tracker'
import './jquery';
import notifier from './notifier';
import './search';
import './view';
import './submitPackage';
import '../css/app.scss';
import 'bootstrap';

(function ($) {
    "use strict";

    /**
     * Ajax error handler
     */
    $.ajaxSetup({
        error: function (xhr) {
            var resp, message, details = undefined;

            notifier.remove();

            if (xhr.responseText) {
                try {
                    resp = JSON.parse(xhr.responseText);
                    if (resp.status && resp.status === 'error') {
                        message = resp.message;
                        details = resp.details;
                    }
                } catch (e) {
                    message = "We're so sorry, something is wrong on our end.";
                }
            }

            notifier.log(message, {}, details);
        }
    });

    /**
     * API Token visibility toggling
     */
    $('.btn-show-api-token, .api-token').each(function() {
        $(this).click(function (e) {
            const parent = $(this).closest('.api-token-group');
            const token = parent.find('.api-token');
            token.val(token.data('api-token'));
            token.select();

            const button = parent.find('.btn-show-api-token').first();
            button.text(button.text().replace('Show', 'Your'));

            e.preventDefault();
        });
    });
    $('.btn-rotate-api-token').click(function (e) {
        if (!window.confirm('Are you sure? This will revoke your current API tokens and generate new ones.')) {
            e.preventDefault();
        }
    });

    $('.toc a').click(function (e) {
        setTimeout(function () {
            scrollTo(0, $($(e.target).attr('href')).offset().top - 65);
        }, 0);
    });

    let currentBannerId = $('.banner .banner-close').data('banner-id');
    $('.banner .banner-close').click(function () {
        $('.banner').addClass('hidden');
        try {
            window.localStorage.setItem('banner-read', currentBannerId);
        } catch (e) {}
    });
    if (currentBannerId !== undefined) {
        try {
            if (window.localStorage.getItem('banner-read') !== currentBannerId) {
                $('.banner').removeClass('hidden');
            }
        } catch (e) {}
    }
})(jQuery);

if (
    window.trackPageload !== false
    && location.host === 'packagist.org'
    && navigator.userAgent !== "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36"
) {
    const plausible = Plausible({
      domain: 'packagist.org',
      apiHost: 'https://packagist.org',
    });
    plausible.trackPageview();
}
