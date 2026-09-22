import jQuery from "jquery";

/**
 * Select-all and per-vendor toggles for the support package pickers.
 *
 * The toggles are built here rather than rendered in Twig so the pickers can keep using the shared
 * templates/support/request.html.twig shell, which renders form_widget() wholesale. Without
 * JavaScript the plain checkboxes still work, which is the whole feature minus the shortcut.
 *
 * The checkboxes carry data-bulk-select and data-bulk-select-group from PackagePickerType, the same
 * vocabulary filterListAdmin.js uses.
 */
const init = function ($, form) {
    "use strict";

    const checkboxes = form.find('[data-bulk-select]');
    if (checkboxes.length < 2) {
        return;
    }

    const vendors = [];
    checkboxes.each(function () {
        const vendor = $(this).data('bulk-select-group');
        if (vendor && vendors.indexOf(vendor) === -1) {
            vendors.push(vendor);
        }
    });

    const bar = $('<div class="mb-2 package-picker-toggles"></div>');
    const toggles = [];

    const addToggle = function (label, group) {
        const id = 'bulk-select-' + (group === null ? 'all' : group).replace(/[^a-zA-Z0-9-]/g, '-');
        const wrapper = $('<div class="form-check form-check-inline"></div>');
        const box = $('<input type="checkbox" class="form-check-input" data-bulk-select-all />').attr('id', id);
        wrapper.append(box).append($('<label class="form-check-label"></label>').attr('for', id).text(label));
        bar.append(wrapper);

        const members = group === null ? checkboxes : checkboxes.filter(function () {
            return $(this).data('bulk-select-group') === group;
        });

        box.on('change', function () {
            members.prop('checked', box.prop('checked')).trigger('change.packagePicker');
        });

        toggles.push({ box: box, members: members });
    };

    addToggle('Select all', null);
    // Only worth offering per vendor when there is more than one to tell apart.
    if (vendors.length > 1) {
        vendors.forEach(function (vendor) {
            addToggle(vendor, vendor);
        });
    }

    const refresh = function () {
        toggles.forEach(function (toggle) {
            const checked = toggle.members.filter(':checked').length;
            toggle.box.prop('checked', checked > 0 && checked === toggle.members.length);
            toggle.box.prop('indeterminate', checked > 0 && checked < toggle.members.length);
        });
    };

    checkboxes.first().closest('.mb-3, fieldset, div').first().before(bar);
    checkboxes.on('change change.packagePicker', refresh);
    refresh();
};

jQuery('[id$="-picker"]').each(function () {
    init(jQuery, jQuery(this));
});
