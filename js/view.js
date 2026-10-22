import { Modal, Tooltip } from 'bootstrap';
import notifier from './notifier';
import { request, formParams, ignoreRequestError } from './request';

const init = function () {
    "use strict";

    var versionCache = {},
        ongoingRequest = false;

    const all = (selector, root = document) => root.querySelectorAll(selector);
    const on = (selector, event, handler) => all(selector).forEach((el) => el.addEventListener(event, handler));

    const togglePackageForm = function (selector) {
        all('#remove-maintainer-form, #add-maintainer-form, #transfer-package-form').forEach((el) => el.classList.add('d-none'));
        document.querySelector(selector)?.classList.remove('d-none');
    }

    on('#add-maintainer', 'click', function (e) {
        togglePackageForm('#add-maintainer-form');
        e.preventDefault();
    });
    on('#remove-maintainer', 'click', function (e) {
        togglePackageForm('#remove-maintainer-form');
        e.preventDefault();
    });
    on('#transfer-package', 'click', function (e) {
        togglePackageForm('#transfer-package-form');
        e.preventDefault();
    });

    function setVersionDetails(html) {
        all('.package .version-details').forEach((el) => { el.innerHTML = html; });
    }

    on('.package .details-toggler', 'click', function () {
        var target = this;
        var versionId = target.getAttribute('data-version-id');

        if (versionCache[versionId]) {
            setVersionDetails(versionCache[versionId]);
        } else if (target.getAttribute('data-load-more')) {
            if (ongoingRequest) { // TODO cancel previous requests instead?
                return;
            }
            ongoingRequest = true;
            all('.package .version-details').forEach((el) => el.classList.add('loading'));
            request(target.getAttribute('data-load-more'))
                .then(function (data) {
                    versionCache[versionId] = data.content;
                    setVersionDetails(data.content);
                })
                .catch(ignoreRequestError)
                .finally(() => {
                    ongoingRequest = false;
                    all('.package .version-details').forEach((el) => el.classList.remove('loading'));
                });
        }

        all('.package .versions .open').forEach((el) => el.classList.remove('open'));
        target.classList.add('open');
    });

    // Opens the row named by #<version-id>, on page load and on back/forward.
    function openVersionFromHash() {
        var hash = document.location.hash.substring(1);
        if (!hash.length) {
            return;
        }
        // matched by attribute rather than an interpolated selector, as version strings are
        // user-controlled and a quote in one would break the selector parse
        var row = Array.from(all('.package .details-toggler')).find(function (el) {
            return el.getAttribute('data-version-id') === hash;
        });
        // clicking a version anchor writes the hash itself, so the hashchange that follows would
        // otherwise redo the load the click just did
        if (row && !row.classList.contains('open')) {
            row.click();
        }
    }

    openVersionFromHash();
    window.addEventListener('hashchange', openVersionFromHash);

    function dispatchForm(form, success, className, extraParams) {
        if (form.classList.contains(className)) {
            return;
        }
        var params = formParams(form);
        Object.entries(extraParams || {}).forEach(([name, value]) => params.append(name, value));
        form.classList.add(className);
        request(form.getAttribute('action'), {method: form.getAttribute('method'), params: params, cache: false})
            .then(success)
            .catch(ignoreRequestError)
            .finally(() => form.classList.remove(className));
    }

    // Show the shared deletion-reason modal to capture an optional public + internal (admin-only) reason.
    // Calls onConfirm(publicReason, internalReason) on confirm; aborts silently on cancel/dismiss.
    // Falls back to a plain confirm() if the modal markup is absent on the page.
    function promptDeletionReasons(opts, onConfirm) {
        var modal = document.querySelector('#deletion-reason-modal');
        if (!modal) {
            if (window.confirm(opts.title + '?')) {
                onConfirm('', '');
            }
            return;
        }
        var publicInput = modal.querySelector('.deletion-reason-public');
        var internalInput = modal.querySelector('.deletion-reason-internal');
        var confirmButton = modal.querySelector('.deletion-reason-confirm');
        modal.querySelector('.modal-title').textContent = opts.title;
        confirmButton.textContent = opts.confirmLabel || 'Confirm';
        publicInput.value = '';
        internalInput.value = '';
        var modalInstance = Modal.getOrCreateInstance(modal);
        confirmButton.onclick = function () {
            var publicReason = publicInput.value.trim();
            var internalReason = internalInput.value.trim();
            modalInstance.hide();
            onConfirm(publicReason, internalReason);
        };
        modalInstance.show();
    }

    // the toast doubles as the running flag - a second variable for the same fact only gives the
    // two something to disagree about when the toast is dismissed or swept from under us
    var updateToast = null;

    // the Update action lives in the Manage menu, so its own spinner is invisible as soon as the
    // menu closes - and never visible at all for the data-force-crawl auto-trigger below. Show the
    // state on the menu's toggle and in a toast instead.
    function setUpdateRunning(running) {
        all('.package-actions .dropdown-toggle').forEach((el) => el.classList.toggle('loading', running));
        // the trigger is an <a>, so it takes aria-disabled; the menu entry is a real submit input
        all('.package .force-update .force-update-trigger').forEach(function (el) {
            el.classList.toggle('loading', running);
            el.classList.toggle('disabled', running);
            if (running) {
                el.setAttribute('aria-disabled', 'true');
            } else {
                el.removeAttribute('aria-disabled');
            }
        });
        all('.package .force-update input[type=submit]').forEach((el) => { el.disabled = running; });

        if (running) {
            // sticky: an unrelated request failure clearing its messages must not take the only
            // explanation for the spinner with it
            updateToast = notifier.log('Update in progress…', {spinner: true, sticky: true});
        } else if (updateToast) {
            notifier.remove(updateToast);
            updateToast = null;
        }
    }

    function forceUpdatePackage(e, updateAll) {
        var showOutput = e && e.shiftKey;
        if (e) {
            e.preventDefault();
        }
        if (updateToast) {
            return;
        }
        // data-force-crawl is granted by 'edit' but this form by 'update', and neither role implies
        // the other - without the form there is no action/method to submit to
        var forms = all('.package .force-update');
        if (!forms.length) {
            return;
        }
        var params = new URLSearchParams();
        forms.forEach((form) => formParams(form).forEach((value, name) => params.append(name, value)));
        if (updateAll) {
            params.append('updateAll', '1');
        }
        params.append('manualUpdate', '1');

        setUpdateRunning(true);

        request(forms[0].getAttribute('action'), {method: forms[0].getAttribute('method'), params: params, cache: false})
            .then(function (data) {
                if (!data.job) {
                    setUpdateRunning(false);
                    return;
                }

                var pollFailures = 0;
                var checkJobStatus = function () {
                    // no error toast: a poll blip is not worth one while we are still retrying
                    request('/jobs/' + data.job, {cache: false, showError: false}).then(function (data) {
                        pollFailures = 0;
                        if (data.status == 'completed' || data.status == 'errored' || data.status == 'failed' || data.status == 'package_deleted') {
                            setUpdateRunning(false);
                            notifier.remove();

                            var message = data.message;
                            var details = '';
                            if (data.status !== 'completed') {
                                message += ' [' + data.exceptionClass + '] ' + data.exceptionMsg;
                                details = data.details;
                            } else if (showOutput) {
                                details = data.details;
                            }

                            if (details) {
                                notifier.log(message, {}, details);
                            } else {
                                notifier.log(message, {timeout: 2000});
                                setTimeout(function () {
                                    document.location.reload(true);
                                }, 700);
                            }

                            return;
                        }

                        setTimeout(checkJobStatus, 1000);
                    }, function (err) {
                        ignoreRequestError(err);
                        pollFailures++;
                        if (pollFailures > 3) {
                            setUpdateRunning(false);
                            notifier.log('Lost contact with the server while the update was running — reload to see the result.', {timeout: 5000});
                            return;
                        }
                        setTimeout(checkJobStatus, 1000 * Math.pow(2, pollFailures));
                    });
                };

                setTimeout(checkJobStatus, 1000);
            }, function (err) {
                ignoreRequestError(err);
                setUpdateRunning(false);
            });
    }
    on('.package .force-update', 'submit', forceUpdatePackage);
    on('.package .force-update', 'click', forceUpdatePackage);
    on('.package .mark-favorite', 'click', function (e) {
        var el = this;
        e.preventDefault();
        if (el.classList.contains('loading')) {
            return;
        }
        var pending = el.classList.contains('is-starred')
            ? request(el.dataset.removeUrl, {method: 'DELETE', cache: false})
            : request(el.dataset.addUrl, {method: 'POST', params: {package: el.dataset.package}, cache: false});
        el.classList.add('loading');
        pending
            .then(() => el.classList.toggle('is-starred'))
            .catch(ignoreRequestError)
            .finally(() => el.classList.remove('loading'));
    });
    on('.package .delete', 'submit', function (e) {
        e.preventDefault();
        var form = this;
        var success = function () {
            notifier.log('Package successfully deleted');
            setTimeout(function () {
                document.location.href = document.location.href.replace(/\/[^\/]+$/, '/');
            })
        };

        if (form.dataset.admin) {
            promptDeletionReasons({title: 'Delete package', confirmLabel: 'Delete'}, function (publicReason, internalReason) {
                var extra = {};
                if (publicReason) extra.reason = publicReason;
                if (internalReason) extra.internalReason = internalReason;
                dispatchForm(form, success, 'request-sent', extra);
            });
        } else if (window.confirm('Are you sure you want to delete this package?')) {
            dispatchForm(form, success, 'request-sent');
        }
    });
    on('.package .view-log', 'click', function (e) {
        e.preventDefault();
        const message = e.target.dataset.msg;
        const details = e.target.dataset.details;
        notifier.log(message, {}, details);
    });
    on('.package .delete-version .submit, .package .recover-version .submit, .package .hide-version .submit', 'click', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        // a submit event rather than requestSubmit(), which Safari only has from 16
        e.target.closest('form').dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
    });

    function getVersionLabel(form) {
        return form.closest('.version').querySelector('.version-number').textContent.trim();
    }

    // Point a deletion tooltip at a new title. BS5 has no "update the title" call, so dispose any
    // existing instance and rebuild it; the text goes on data-bs-title, not the native title
    // attribute, so the browser does not render its own tooltip on top.
    function setDeletionTooltip(elements, title) {
        elements.forEach(function (el) {
            var existing = Tooltip.getInstance(el);
            if (existing) {
                existing.dispose();
            }
            el.removeAttribute('title');
            el.setAttribute('data-bs-title', title);
            new Tooltip(el, {placement: 'top', container: 'body'});
        });
    }

    function applyVersionDeleteResponse(form, data, deletedToast, softDeletedToast) {
        var row = form.closest('.version');
        if (data && data.softDeleted) {
            notifier.log(softDeletedToast || 'Version soft-deleted. Reload the page to access the recovery action.', {timeout: 4000});
            row.classList.add('version-soft-deleted');
            // The row may already carry a badge (hiding an already soft-deleted version), so reuse
            // and refresh it rather than leaving the previous icon and title in place.
            var alert = row.querySelector('.deletion-alert');
            if (!alert) {
                row.querySelector('form')?.insertAdjacentHTML('beforebegin', '<span class="action-alert deletion-alert" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-container="body"><i class="bi"></i></span>');
                alert = row.querySelector('.deletion-alert');
            }
            alert.querySelector('i').className = 'bi ' + (data.deletionIcon || 'bi-trash-fill');
            // The version number carries the same title, and has no tooltip at all until the row is
            // soft-deleted, so it needs the same treatment as the badge.
            var title = data.deletionTitle || 'Deleted';
            setDeletionTooltip([alert], title);
            setDeletionTooltip(all('.version-number', row), title);
            all('.delete-version, .hide-version', row).forEach((el) => el.remove());
        } else {
            notifier.log(deletedToast, {timeout: 3000});
            row.remove();
        }
    }

    // Submit a version-action form via fetch, guarding against duplicate submits.
    // `overrides` may carry {url, method, params} to override the form's defaults (used by the
    // admin-reason fallthrough in .delete-version which retargets to admin_delete_version).
    function dispatchVersionAction(form, onSuccess, overrides) {
        if (form.classList.contains('request-sent')) {
            return;
        }
        overrides = overrides || {};
        form.classList.add('request-sent');
        request(overrides.url || form.getAttribute('action'), {
            method: overrides.method || form.getAttribute('method'),
            params: overrides.params || formParams(form),
            cache: false,
        })
            .then(onSuccess)
            .catch(ignoreRequestError)
            .finally(() => form.classList.remove('request-sent'));
    }

    on('.package .recover-version', 'submit', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        var form = this;
        dispatchVersionAction(form, function () {
            notifier.log('Version recovered. Reload the page to see the active version.', {timeout: 3000});
            var row = form.closest('.version');
            row.classList.remove('version-soft-deleted');
            all('.deletion-alert', row).forEach((el) => el.remove());
            form.remove();
        });
    });

    on('.package .delete-version', 'submit', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        var form = this;
        var label = getVersionLabel(form);
        var success = function (data) {
            applyVersionDeleteResponse(form, data, 'Version successfully deleted');
        };

        if (form.dataset.admin) {
            promptDeletionReasons({title: 'Remove version ' + label, confirmLabel: 'Remove'}, function (publicReason, internalReason) {
                var overrides = {};
                // Only retarget to the admin route when a reason is given; otherwise fall through to the
                // ordinary delete (soft-delete stable / hard-delete dev), preserving prior behavior.
                if (publicReason || internalReason) {
                    overrides.url = form.dataset.adminUrl;
                    overrides.method = 'POST';
                    overrides.params = new URLSearchParams({_token: form.querySelector('input[name="_token"]').value});
                    if (publicReason) overrides.params.append('reason', publicReason);
                    if (internalReason) overrides.params.append('internalReason', internalReason);
                }
                dispatchVersionAction(form, success, overrides);
            });
        } else if (window.confirm('Are you sure you want to delete ' + label + '?')) {
            dispatchVersionAction(form, success, {});
        }
    });

    on('.package .hide-version', 'submit', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        var form = this;
        var label = getVersionLabel(form);
        promptDeletionReasons({title: 'Hide version ' + label, confirmLabel: 'Hide'}, function (publicReason, internalReason) {
            var params = formParams(form);
            if (publicReason) params.append('reason', publicReason);
            if (internalReason) params.append('internalReason', internalReason);
            dispatchVersionAction(form, function (resp) {
                applyVersionDeleteResponse(form, resp, 'Version hidden.', 'Version hidden.');
            }, {params: params});
        });
    });

    on('.package', 'click', function (e) {
        if (e.target.matches('.requireme input')) {
            e.target.select();
        }
    });
    if (document.querySelector('.package')?.dataset.forceCrawl) {
        forceUpdatePackage(null, true);
    }

    on('.readme a', 'click', function (e) {
        var targetEl,
            href = e.target.getAttribute('href');

        if (href.substr(0, 1) === '#') {
            targetEl = document.getElementById(href.substring(1));
            if (targetEl) {
                window.scrollTo(0, targetEl.getBoundingClientRect().top + window.scrollY - 70);
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        }
    });

    var versionsList = document.querySelector('.package .versions');
    if (versionsList && versionsList.offsetHeight < versionsList.scrollHeight) {
        all('.package .versions-expander').forEach(function (el) {
            el.classList.remove('d-none');
            el.addEventListener('click', function () {
                el.classList.add('d-none');
                versionsList.style.maxHeight = 'inherit';
            });
        });
    }

    // Handle add/remove buttons for transfer package form
    on('.add-maintainer-item', 'click', function (e) {
        e.preventDefault();

        var list = document.querySelector('.maintainers-list');
        var index = all('li', list).length + 1;

        var newItem = document.createElement('li');
        newItem.innerHTML = list.dataset.prototype.replace(/__name__/g, index);
        addMaintainerRemoveButton(newItem);
        list.append(newItem);
    });

    all('.maintainers-list li').forEach(addMaintainerRemoveButton);

    function addMaintainerRemoveButton(item) {
        var removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'btn btn-danger btn-sm';
        removeButton.innerHTML = '<i class="bi bi-x-lg"></i>';
        removeButton.addEventListener('click', function (e) {
            e.preventDefault();

            if (all('.maintainers-list li').length === 1) {
                return;
            }

            item.remove();
        });
        item.append(removeButton);
    }
};

if (document.querySelector('#view-package-page')) {
    init();
}
