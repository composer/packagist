import { init as initPlausible } from '@plausible-analytics/tracker'
import './search';
import './view';
import './submitPackage';
import './filterListAdmin';
import './packagePicker';
import '../css/app.scss';
// bare side-effect import: registers the delegated data-bs-* handlers the navbar toggler and the
// update-history rows need. It must stay on the same specifier as every other bootstrap import -
// mixing in 'bootstrap/js/dist/collapse' bundles a second copy, and the two data-api handlers then
// toggle each other back, so a panel opens but never closes.
import 'bootstrap';
import { Tooltip } from 'bootstrap';

// Bootstrap focuses the modal itself on show and ignores [autofocus]
document.addEventListener('shown.bs.modal', function (e) {
    e.target.querySelector('[autofocus]')?.focus();
});

/**
 * API Token visibility toggling
 */
document.querySelectorAll('.btn-show-api-token, .api-token').forEach(function (el) {
    el.addEventListener('click', function (e) {
        const parent = el.closest('.api-token-group');
        const token = parent.querySelector('.api-token');
        token.value = token.dataset.apiToken;
        token.select();

        const button = parent.querySelector('.btn-show-api-token');
        button.textContent = button.textContent.replace('Show', 'Your');

        e.preventDefault();
    });
});
document.querySelectorAll('.btn-rotate-api-token').forEach(function (el) {
    el.addEventListener('click', function (e) {
        if (!window.confirm('Are you sure? This will revoke your current API tokens and generate new ones.')) {
            e.preventDefault();
        }
    });
});

document.querySelectorAll('.toc a').forEach(function (el) {
    el.addEventListener('click', function (e) {
        setTimeout(function () {
            const target = document.getElementById(e.target.getAttribute('href').substring(1));
            scrollTo(0, target.getBoundingClientRect().top + window.scrollY - 65);
        }, 0);
    });
});

const bannerClose = document.querySelector('.banner .banner-close');
const currentBannerId = bannerClose?.dataset.bannerId;
bannerClose?.addEventListener('click', function () {
    document.querySelectorAll('.banner').forEach((el) => el.classList.add('d-none'));
    try {
        window.localStorage.setItem('banner-read', currentBannerId);
    } catch (e) {}
});
if (currentBannerId !== undefined) {
    try {
        if (window.localStorage.getItem('banner-read') !== currentBannerId) {
            document.querySelectorAll('.banner').forEach((el) => el.classList.remove('d-none'));
        }
    } catch (e) {}
}

document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
    new Tooltip(el);
});

if (
    window.trackPageload !== false
    && location.host === 'packagist.org'
    && navigator.userAgent !== "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36"
) {
    initPlausible({
      domain: 'packagist.org',
      endpoint: 'https://packagist.org/api/event',
    });
}
