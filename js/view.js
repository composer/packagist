import jQuery from "jquery";
import notifier from './notifier';

const init = function ($) {
    "use strict";

    var versionCache = {},
        ongoingRequest = false;

    const togglePackageForm = function (selector) {
        $('#remove-maintainer-form').addClass('hidden');
        $('#add-maintainer-form').addClass('hidden');
        $('#transfer-package-form').addClass('hidden');
        $(selector).removeClass('hidden');
    }

    $('#add-maintainer').on('click', function (e) {
        togglePackageForm('#add-maintainer-form');
        e.preventDefault();
    });
    $('#remove-maintainer').on('click', function (e) {
        togglePackageForm('#remove-maintainer-form');
        e.preventDefault();
    });
    $('#transfer-package').on('click', function (e) {
        togglePackageForm('#transfer-package-form');
        e.preventDefault();
    });

    $('.package .details-toggler').on('click', function () {
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
                    $('.package .version-details')
                        .html(data.content);
                },
                complete: () => {
                    ongoingRequest = false;
                    $('.package .version-details')
                        .removeClass('loading')
                }
            });
        }

        $('.package .versions .open').removeClass('open');
        target.addClass('open');
    });

    // initializer for #<version-id> present on page load
    (function () {
        var hash = document.location.hash;
        if (hash.length > 1) {
            hash = hash.substring(1);
            $('.package .details-toggler[data-version-id="'+hash+'"]').click();
        }
    }());

    function dispatchAjaxForm(form, success, className, extraData) {
        var data = $(form).serializeArray();
        if (extraData) {
            data = data.concat(extraData);
        }
        var options = {
            cache: false,
            success: success,
            data: data,
            type: $(form).attr('method'),
            url: $(form).attr('action')
        };
        if ($(form).is('.' + className)) {
            return;
        }
        $.ajax(options).then(() => { $(form).removeClass(className); });
        $(form).addClass(className);
    }

    // Show the shared deletion-reason modal to capture an optional public + internal (admin-only) reason.
    // Calls onConfirm(publicReason, internalReason) on confirm; aborts silently on cancel/dismiss.
    // Falls back to a plain confirm() if the modal markup is absent on the page.
    function promptDeletionReasons(opts, onConfirm) {
        var modal = $('#deletion-reason-modal');
        if (!modal.length) {
            if (window.confirm(opts.title + '?')) {
                onConfirm('', '');
            }
            return;
        }
        modal.find('.modal-title').text(opts.title);
        modal.find('.deletion-reason-confirm').text(opts.confirmLabel || 'Confirm');
        modal.find('.deletion-reason-public').val('');
        modal.find('.deletion-reason-internal').val('');
        modal.find('.deletion-reason-confirm').off('click').on('click', function () {
            var publicReason = modal.find('.deletion-reason-public').val().trim();
            var internalReason = modal.find('.deletion-reason-internal').val().trim();
            modal.modal('hide');
            onConfirm(publicReason, internalReason);
        });
        modal.modal('show');
    }

    function forceUpdatePackage(e, updateAll) {
        var submit = $('input[type=submit], .force-update-trigger', '.package .force-update'), data;
        var showOutput = e && e.shiftKey;
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
        data.push({name: 'manualUpdate', value: '1'});

        $.ajax({
            url: $('.package .force-update').attr('action'),
            dataType: 'json',
            cache: false,
            data: data,
            type: $('.package .force-update').attr('method'),
            success: function (data) {
                if (data.job) {
                    var checkJobStatus = function () {
                        $.ajax({
                            url: '/jobs/' + data.job,
                            cache: false,
                            success: function (data) {
                                if (data.status == 'completed' || data.status == 'errored' || data.status == 'failed' || data.status == 'package_deleted') {
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

                                    submit.removeClass('loading');

                                    return;
                                }

                                setTimeout(checkJobStatus, 1000);
                            }
                        });
                    };

                    setTimeout(checkJobStatus, 1000);
                }
            },
            context: $('.package .force-update')[0]
        });
        submit.addClass('loading');
    }
    $('.package .force-update').on('submit', forceUpdatePackage);
    $('.package .force-update').on('click', forceUpdatePackage);
    $('.package .mark-favorite').on('click', function (e) {
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
        $.ajax(options).then(function () { $(this).removeClass('loading'); });
        $(this).addClass('loading');
    });
    $('.package .delete').on('submit', function (e) {
        e.preventDefault();
        var form = this;
        var success = function () {
            notifier.log('Package successfully deleted');
            setTimeout(function () {
                document.location.href = document.location.href.replace(/\/[^\/]+$/, '/');
            })
        };

        if ($(form).data('admin')) {
            promptDeletionReasons({title: 'Delete package', confirmLabel: 'Delete'}, function (publicReason, internalReason) {
                var extra = [];
                if (publicReason) extra.push({name: 'reason', value: publicReason});
                if (internalReason) extra.push({name: 'internalReason', value: internalReason});
                dispatchAjaxForm(form, success, 'request-sent', extra);
            });
        } else if (window.confirm('Are you sure you want to delete this package?')) {
            dispatchAjaxForm(form, success, 'request-sent');
        }
    });
    $('.package .view-log').on('click', function (e) {
        e.preventDefault();
        const message = e.target.dataset.msg;
        const details = e.target.dataset.details;
        notifier.log(message, {}, details);
    });
    $('.package .delete-version .submit, .package .recover-version .submit, .package .hide-version .submit').on('click', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        $(e.target).closest('form').submit();
    });

    function getVersionLabel(form) {
        return $(form).closest('.version').find('.version-number').text().trim();
    }

    function applyVersionDeleteResponse(form, data, deletedToast) {
        var row = $(form).closest('.version');
        if (data && data.softDeleted) {
            notifier.log('Version soft-deleted. Reload the page to access the recovery action.', {timeout: 4000});
            row.addClass('version-soft-deleted');
            if (!row.find('.deletion-alert').length) {
                var icon = data.deletionIcon || 'glyphicon-trash';
                var alert = $('<span class="action-alert deletion-alert"><i class="glyphicon"></i></span>');
                alert.find('i').addClass(icon);
                alert.attr('title', data.deletionTitle || 'Deleted');
                alert.insertBefore(row.find('form').first());
            }
            row.find('.delete-version, .hide-version').remove();
        } else {
            notifier.log(deletedToast, {timeout: 3000});
            row.remove();
        }
    }

    // Submit a version-action form via ajax, guarding against duplicate submits.
    // `overrides` may carry {url, type, data} to override the form's defaults (used by the
    // admin-reason fallthrough in .delete-version which retargets to admin_delete_version).
    // Returns the jqXHR, or null if the request-sent guard tripped.
    function dispatchVersionAction(form, onSuccess, overrides) {
        if ($(form).is('.request-sent')) {
            return null;
        }
        overrides = overrides || {};
        $(form).addClass('request-sent');
        return $.ajax({
            url: overrides.url || $(form).attr('action'),
            type: overrides.type || $(form).attr('method'),
            cache: false,
            dataType: 'json',
            data: overrides.data || $(form).serializeArray(),
            success: onSuccess,
            complete: function () { $(form).removeClass('request-sent'); }
        });
    }

    $('.package .recover-version').on('submit', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        var form = this;
        dispatchVersionAction(form, function () {
            notifier.log('Version recovered. Reload the page to see the active version.', {timeout: 3000});
            var row = $(form).closest('.version');
            row.removeClass('version-soft-deleted');
            row.find('.deletion-alert').remove();
            $(form).remove();
        });
    });

    $('.package .delete-version').on('submit', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        var form = this;
        var label = getVersionLabel(form);
        var success = function (data) {
            applyVersionDeleteResponse(form, data, 'Version successfully deleted');
        };

        if ($(form).data('admin')) {
            promptDeletionReasons({title: 'Remove version ' + label, confirmLabel: 'Remove'}, function (publicReason, internalReason) {
                var overrides = {};
                // Only retarget to the admin route when a reason is given; otherwise fall through to the
                // ordinary delete (soft-delete stable / hard-delete dev), preserving prior behavior.
                if (publicReason || internalReason) {
                    overrides.url = $(form).data('admin-url');
                    overrides.type = 'POST';
                    overrides.data = [{name: '_token', value: $(form).find('input[name="_token"]').val()}];
                    if (publicReason) overrides.data.push({name: 'reason', value: publicReason});
                    if (internalReason) overrides.data.push({name: 'internalReason', value: internalReason});
                }
                dispatchVersionAction(form, success, overrides);
            });
        } else if (window.confirm('Are you sure you want to delete ' + label + '?')) {
            dispatchVersionAction(form, success, {});
        }
    });

    $('.package .hide-version').on('submit', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        var form = this;
        var label = getVersionLabel(form);
        promptDeletionReasons({title: 'Hide version ' + label, confirmLabel: 'Hide'}, function (publicReason, internalReason) {
            var data = $(form).serializeArray();
            if (publicReason) data.push({name: 'reason', value: publicReason});
            if (internalReason) data.push({name: 'internalReason', value: internalReason});
            dispatchVersionAction(form, function (resp) {
                applyVersionDeleteResponse(form, resp, 'Version hidden');
            }, {data: data});
        });
    });

    $('.package').on('click', '.requireme input', function () {
        this.select();
    });
    if ($('.package').data('force-crawl')) {
        forceUpdatePackage(null, true);
    }

    $('.readme a').on('click', function (e) {
        var targetEl,
            href = e.target.getAttribute('href');

        if (href.substr(0, 1) === '#') {
            targetEl = $(href);
            if (targetEl.length) {
                window.scrollTo(0, targetEl.offset().top - 70);
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        }
    });

    var versionsList = $('.package .versions')[0];
    if (versionsList && versionsList.offsetHeight < versionsList.scrollHeight) {
        $('.package .versions-expander').removeClass('hidden').on('click', function () {
            $(this).addClass('hidden');
            $(versionsList).css('max-height', 'inherit');
        });
    }

    // Handle add/remove buttons for transfer package form
    $('.add-maintainer-item').on('click', function (e) {
        e.preventDefault();

        var list = $('.maintainers-list');
        var prototype = list.data('prototype');
        var index = list.find('li').length + 1;

        var newForm = prototype.replace(/__name__/g, index);
        var newItem = $('<li></li>').append(newForm);
        addMaintainerRemoveButton(newItem);
        list.append(newItem);
    });

    $('.maintainers-list').find('li').each(function(index) {
        addMaintainerRemoveButton($(this));
    });

    function addMaintainerRemoveButton(item) {
        var removeButton = $('<button type="button" class="btn btn-danger btn-sm"><i class="glyphicon glyphicon-remove"></i></button>');
        removeButton.on('click', function(e) {
            e.preventDefault();

            if ($('.maintainers-list').find('li').length === 1) {
                return;
            }

            item.remove();
        });
        item.append(removeButton);
    }
};

if (document.querySelector('#view-package-page')) {
    init(jQuery);
}
