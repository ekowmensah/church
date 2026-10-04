(function ($, window) {
    'use strict';

    $(function () {
        var config = window.MemberPaymentDrilldownConfig;
        if (!config || !config.endpoint || !$('#memberPaymentsModal').length) return;

        var activeMemberId = 0;
        var activePerPage = 25;
        var filters = config.filters || {};

        function showError(xhr) {
            var response = xhr && xhr.responseText ? String(xhr.responseText) : '';
            var message = response && response.indexOf('<') === -1
                ? response
                : 'Payment history could not be loaded.';
            $('#memberPaymentsBody').html($('<div class="alert alert-danger mb-0">').text(message));
        }

        function loadMemberPayments(page) {
            if (!activeMemberId) return;
            $('#memberPaymentsBody').html(
                '<div class="text-center text-muted py-5">' +
                '<span class="spinner-border text-primary mb-2" aria-hidden="true"></span>' +
                '<div>Loading payment history...</div></div>'
            );
            $.get(config.endpoint, $.extend({}, filters, {
                member_id: activeMemberId,
                page: page || 1,
                per_page: activePerPage
            })).done(function (html) {
                $('#memberPaymentsBody').html(html);
            }).fail(showError);
        }

        $(document).on('click.memberPaymentDrilldown', '.view-member-payments', function () {
            activeMemberId = Number($(this).data('member-id')) || 0;
            activePerPage = 25;
            $('#memberPaymentsTitle').text($(this).data('member-name') + ' - payment history');
            $('#memberPaymentsModal').modal('show');
            loadMemberPayments(1);
        });

        $('#memberPaymentsBody').on('click.memberPaymentDrilldown', '.member-payment-page-link', function (event) {
            event.preventDefault();
            if (!$(this).closest('.page-item').hasClass('disabled')) {
                loadMemberPayments(Number($(this).data('page')) || 1);
            }
        }).on('change.memberPaymentDrilldown', '.member-payment-page-size', function () {
            activePerPage = Number(this.value) || 25;
            loadMemberPayments(1);
        });
    });
})(window.jQuery, window);
