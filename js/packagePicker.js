/**
 * Select-all and per-vendor toggles for the support package pickers (PackagePickerType).
 * Built here so the shared request.html.twig shell can keep rendering form_widget() wholesale.
 */
const init = function (form) {
    "use strict";

    const checkboxes = Array.from(form.querySelectorAll('[data-bulk-select]'));
    if (checkboxes.length < 2) {
        return;
    }

    const vendors = [];
    checkboxes.forEach((el) => {
        const vendor = el.getAttribute('data-bulk-select-group');
        if (vendor && !vendors.includes(vendor)) {
            vendors.push(vendor);
        }
    });

    const bar = document.createElement('div');
    bar.className = 'mb-2 package-picker-toggles';
    const toggles = [];

    const refresh = function () {
        toggles.forEach((toggle) => {
            const checked = toggle.members.filter((el) => el.checked).length;
            toggle.box.checked = checked > 0 && checked === toggle.members.length;
            toggle.box.indeterminate = checked > 0 && checked < toggle.members.length;
        });
    };

    const addToggle = function (label, group) {
        const id = form.id + '-toggle-' + toggles.length;
        const wrapper = document.createElement('div');
        wrapper.className = 'form-check form-check-inline';
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.className = 'form-check-input';
        box.id = id;
        box.setAttribute('data-bulk-select-all', '');
        const labelEl = document.createElement('label');
        labelEl.className = 'form-check-label';
        labelEl.htmlFor = id;
        labelEl.textContent = label;
        wrapper.append(box, labelEl);
        bar.append(wrapper);

        const members = group === null ? checkboxes : checkboxes.filter((el) => el.getAttribute('data-bulk-select-group') === group);

        box.addEventListener('change', () => {
            members.forEach((el) => { el.checked = box.checked; });
            refresh();
        });

        toggles.push({ box, members });
    };

    addToggle('Select all', null);
    // Only worth offering per vendor when there is more than one to tell apart.
    if (vendors.length > 1) {
        vendors.forEach((vendor) => addToggle(vendor, vendor));
    }

    checkboxes[0].closest('.mb-3, fieldset, div').before(bar);
    checkboxes.forEach((el) => el.addEventListener('change', refresh));
    refresh();
};

document.querySelectorAll('[id$="-picker"]').forEach(init);
