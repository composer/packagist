const init = function (form) {
    "use strict";

    const selectAll = form.querySelector('[data-bulk-select-all]');
    const checkboxes = form.querySelectorAll('[data-bulk-select]');

    function refresh() {
        const checked = Array.from(checkboxes).filter((el) => el.checked).length;
        selectAll.checked = checked > 0 && checked === checkboxes.length;
        selectAll.indeterminate = checked > 0 && checked < checkboxes.length;
    }

    selectAll.addEventListener('change', function () {
        checkboxes.forEach((el) => { el.checked = selectAll.checked; });
        refresh();
    });

    checkboxes.forEach((el) => el.addEventListener('change', refresh));

    refresh();
};

const form = document.querySelector('#filter-list-bulk-form');
if (form) {
    init(form);
}
