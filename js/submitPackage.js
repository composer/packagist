import { request, formParams, ignoreAjaxError } from './ajax';

const init = function () {
    var showSimilarMax = 5;
    var form = document.querySelector('#submit-package-form');
    var submit = document.querySelector('#submit');

    var onSubmit = function(e) {
        e.preventDefault();
        if (submit.classList.contains('loading')) {
            return;
        }
        form.querySelectorAll('ul.package-errors, div.similar-packages-notice, div.confirmation').forEach((el) => el.remove());
        var success = function (data) {
            var html = '';
            if (data.status === 'error') {
                Object.values(data.reason).forEach(function (v) {
                    html += '<li><div class="alert alert-warning">'+v+'</div></li>';
                });
                form.insertAdjacentHTML('afterbegin', '<ul class="list-unstyled package-errors">'+html+'</ul>');
            } else {
                if (data.similar.length) {
                    var similarList = document.createElement('ul');
                    similarList.className = 'list-unstyled similar-packages';
                    var limit = data.similar.length > showSimilarMax ? showSimilarMax : data.similar.length;
                    for ( var i = 0; i < limit; i++ ) {
                        var similar = data.similar[i];
                        var link = document.createElement('a');
                        link.href = similar.url;
                        link.textContent = similar.name;
                        var item = document.createElement('li');
                        item.append(link);
                        similarList.append(item);
                    }
                    if (limit != data.similar.length) {
                        var more = document.createElement('li');
                        more.textContent = 'And ' + (data.similar.length - limit) + ' more';
                        similarList.append(more);
                    }
                    var notice = document.createElement('div');
                    notice.className = 'similar-packages-notice';
                    notice.innerHTML = '<p><strong>Notice:</strong> One or more similarly named packages have already been submitted to Packagist. If this is a fork read the notice above regarding VCS Repositories.</p>'
                        + '<p>Similarly named packages:</p>';
                    notice.append(similarList);
                    submit.before(notice);
                }
                submit.insertAdjacentHTML(
                    'beforebegin',
                    '<div class="confirmation">The package name found for your repository is: <strong>'+data.name+'</strong>, press Submit to confirm.</div>'
                );
                submit.textContent = 'Submit';
                form.removeEventListener('submit', onSubmit);
            }
        };
        request(form.dataset.checkUrl, {method: 'POST', params: formParams(form)})
            .then(success)
            .catch(ignoreAjaxError)
            .finally(() => submit.classList.remove('loading'));
        submit.classList.add('loading');
    };

    var onRepositoryChange = function () {
        form.addEventListener('submit', onSubmit);
        submit.textContent = 'Check';
    };

    document.querySelector('#package_repository').addEventListener('change', onRepositoryChange);
    onRepositoryChange();
};

if (document.querySelector('#submit-package-page')) {
    init();
}
