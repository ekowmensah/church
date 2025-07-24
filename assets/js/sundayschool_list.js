$(function() {
    var table = $('#sundayschoolTable').DataTable({
        paging: true,
        pageLength: 10,
        lengthChange: true,
        searching: true,
        ordering: true,
        info: true,
        autoWidth: false,
        responsive: true,
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'csv',
                text: 'Export CSV',
                className: 'btn btn-sm btn-outline-success mr-1'
            },
            {
                extend: 'excel',
                text: 'Export Excel',
                className: 'btn btn-sm btn-outline-primary mr-1'
            },
            {
                extend: 'pdf',
                text: 'Export PDF',
                className: 'btn btn-sm btn-outline-danger mr-1'
            }
        ]
    });
    table.buttons().container().appendTo('#sundayschoolTable_wrapper .col-md-6:eq(0)');

    // Payment button click
    $(document).on('click', '.btn-pay', function(e) {
        e.preventDefault();
        var srn = $(this).data('srn');
        var url = BASE_URL + '/views/payment_form.php?srn=' + encodeURIComponent(srn);
        window.location.href = url;
    });
});
