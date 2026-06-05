import jQuery from "jquery";

const init = function ($) {
    "use strict";

    const form = $('#filter-list-bulk-form');
    const selectAll = form.find('[data-bulk-select-all]');
    const checkboxes = form.find('[data-bulk-select]');

    function refresh() {
        const checked = checkboxes.filter(':checked').length;
        selectAll.prop('checked', checked > 0 && checked === checkboxes.length);
        selectAll.prop('indeterminate', checked > 0 && checked < checkboxes.length);
    }

    selectAll.on('change', function () {
        checkboxes.prop('checked', selectAll.prop('checked'));
        refresh();
    });

    checkboxes.on('change', refresh);

    refresh();
};

if (document.querySelector('#filter-list-bulk-form')) {
    init(jQuery);
}
