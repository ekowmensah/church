(function (window, $) {
    'use strict';

    if (!$ || !$.fn || !$.fn.DataTable) {
        return;
    }

    var pageSizes = [[25, 50, 100], [25, 50, 100]];

    // Every explicitly configured report table inherits the same sensible
    // defaults. Individual reports can still override ordering or searching.
    $.extend(true, $.fn.dataTable.defaults, {
        paging: true,
        pageLength: 25,
        lengthMenu: pageSizes,
        processing: false,
        responsive: true,
        autoWidth: false,
        info: true,
        searching: true,
        language: {
            lengthMenu: 'Show _MENU_ rows',
            search: 'Search:',
            searchPlaceholder: 'Search this report...',
            info: 'Showing _START_ to _END_ of _TOTAL_ rows',
            infoEmpty: 'No rows to display',
            zeroRecords: 'No matching records found',
            paginate: {
                previous: 'Previous',
                next: 'Next'
            }
        }
    });

    function containsDataRow(table) {
        var rows = table.tBodies.length ? table.tBodies[0].rows : [];
        for (var index = 0; index < rows.length; index += 1) {
            // Colspan-only empty-state rows are intentionally left untouched;
            // DataTables cannot reliably infer their column structure.
            if (rows[index].cells.length > 1 || !rows[index].cells[0].hasAttribute('colspan')) {
                return true;
            }
        }
        return false;
    }

    $(function () {
        if (!document.body.classList.contains('report-workspace')) {
            return;
        }

        $('.content-wrapper table.table').each(function () {
            var table = this;
            var paginationMode = table.getAttribute('data-report-pagination');

            if (paginationMode === 'server' || paginationMode === 'off'
                || $.fn.DataTable.isDataTable(table)
                || !table.tHead || !table.tBodies.length
                || !containsDataRow(table)) {
                return;
            }

            $(table).DataTable({
                order: [],
                dom: '<"row align-items-center mb-2"<"col-md-6"l><"col-md-6"f>>rt<"row align-items-center mt-2"<"col-md-6"i><"col-md-6"p>>'
            });
        });
    });
})(window, window.jQuery);
