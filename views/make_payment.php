<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/member_auth.php';

if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$memberId = (int) $_SESSION['member_id'];


/*
|--------------------------------------------------------------------------
| Load Member
|--------------------------------------------------------------------------
*/

$memberStmt = $conn->prepare(
    "SELECT 
        member.id,
        member.crn,
        member.church_id,
        member.phone,
        TRIM(
            CONCAT_WS(
                ' ',
                member.first_name,
                member.middle_name,
                member.last_name
            )
        ) AS full_name,
        bible_class.name AS class_name
    FROM members member
    LEFT JOIN bible_classes bible_class
        ON bible_class.id = member.class_id
    WHERE member.id = ?
      AND member.is_archived = 0
    LIMIT 1"
);

$memberStmt->bind_param('i', $memberId);
$memberStmt->execute();

$member = $memberStmt
    ->get_result()
    ->fetch_assoc();

$memberStmt->close();


if (!$member) {
    http_response_code(403);
    exit('Your member profile is unavailable.');
}


$crn       = (string) ($member['crn'] ?? '');
$fullName  = (string) ($member['full_name'] ?? '');
$className = (string) ($member['class_name'] ?? '');
$phone     = (string) ($member['phone'] ?? '');
$churchId  = (int) ($member['church_id'] ?? 0);


/*
|--------------------------------------------------------------------------
| Payment Types
|--------------------------------------------------------------------------
*/

$paymentTypes = $conn->query(
    'SELECT id, name 
     FROM payment_types 
     WHERE active = 1 
     ORDER BY name'
)->fetch_all(MYSQLI_ASSOC);


/*
|--------------------------------------------------------------------------
| Start Page Content Buffer
|--------------------------------------------------------------------------
*/

ob_start();
?>


<div class="container py-4" style="max-width:980px;">

    <!-- MEMBER INFORMATION -->
    <div class="card mb-4 shadow-sm border-0 bg-light">

        <div class="card-body">

            <div
                class="font-weight-bold text-primary"
                style="font-size:1.2rem;"
            >
                Welcome,
                <?= htmlspecialchars(
                    $fullName,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>

            <div class="small text-muted">

                CRN:
                <strong>
                    <?= htmlspecialchars(
                        $crn,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </strong>

                <span class="mx-1">|</span>

                Class:
                <strong>
                    <?= htmlspecialchars(
                        $className ?: 'Not assigned',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </strong>

            </div>

            <div class="small text-muted">

                Phone:
                <strong>
                    <?= htmlspecialchars(
                        $phone,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </strong>

            </div>

        </div>

    </div>


    <!-- PAYMENT CARD -->
    <div class="card border-primary shadow-sm mb-3">

        <div
            class="
                card-header
                bg-primary
                text-white
                py-3
                d-flex
                flex-wrap
                justify-content-between
                align-items-center
            "
        >

            <span style="font-size:1.25rem">

                <i class="fas fa-credit-card mr-2"></i>

                Payment

            </span>

            <span
                class="
                    badge
                    badge-light
                    text-primary
                    px-3
                    py-2
                    mt-2
                    mt-sm-0
                "
            >
                Add one or more payment lines
            </span>

        </div>


        <div class="card-body bg-light">

            <input
                type="hidden"
                id="payment_method"
                value="hubtel"
            >


            <!-- ADD PAYMENT LINE FORM -->
            <form
                id="paymentLineForm"
                autocomplete="off"
                onsubmit="return false;"
            >

                <div class="form-row align-items-end">

                    <!-- PAYMENT TYPE -->
                    <div class="form-group col-md-4">

                        <label
                            for="payment_type_id"
                            class="font-weight-bold"
                        >
                            Payment Type

                            <span class="text-danger">
                                *
                            </span>
                        </label>

                        <select
                            class="
                                form-control
                                form-control-lg
                                border-primary
                            "
                            id="payment_type_id"
                        >

                            <option value="">
                                -- Select Type --
                            </option>

                            <?php foreach ($paymentTypes as $paymentType): ?>

                                <option
                                    value="<?= (int) $paymentType['id'] ?>"
                                >
                                    <?= htmlspecialchars(
                                        $paymentType['name'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- AMOUNT -->
                    <div class="form-group col-md-3">

                        <label
                            for="payment_amount"
                            class="font-weight-bold"
                        >
                            Amount (GH&#8373;)

                            <span class="text-danger">
                                *
                            </span>
                        </label>

                        <input
                            type="number"
                            step="0.01"
                            min="1"
                            class="
                                form-control
                                form-control-lg
                                border-primary
                            "
                            id="payment_amount"
                            placeholder="e.g. 100.00"
                        >

                    </div>


                    <!-- REPORTING PERIOD -->
                    <div class="form-group col-md-4">

                        <label
                            for="payment_period"
                            class="font-weight-bold"
                        >
                            Reporting Period

                            <span class="text-danger">
                                *
                            </span>
                        </label>

                        <select
                            class="
                                form-control
                                form-control-lg
                                border-primary
                            "
                            id="payment_period"
                        >

                            <option value="">
                                -- Select Period --
                            </option>

                            <?php
                            for (
                                $monthOffset = 0;
                                $monthOffset < 13;
                                $monthOffset++
                            ):

                                $periodValue = date(
                                    'Y-m-01',
                                    strtotime(
                                        "-{$monthOffset} months"
                                    )
                                );

                                $periodLabel = date(
                                    'F Y',
                                    strtotime($periodValue)
                                );
                            ?>

                                <option
                                    value="<?= $periodValue ?>"
                                    <?= $monthOffset === 0
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= $periodLabel ?>
                                </option>

                            <?php endfor; ?>

                        </select>

                    </div>


                    <!-- ADD BUTTON -->
                    <div class="form-group col-md-1">

                        <button
                            type="button"
                            class="
                                btn
                                btn-success
                                btn-lg
                                btn-block
                            "
                            id="addPaymentLineBtn"
                            title="Add payment line"
                            aria-label="Add payment line"
                        >

                            <i class="fas fa-plus-circle"></i>

                        </button>

                    </div>

                </div>

            </form>


            <!-- PAYMENT LINES -->
            <div class="table-responsive">

                <table
                    class="
                        table
                        table-bordered
                        table-hover
                        table-sm
                        bg-white
                    "
                    id="paymentLinesTable"
                >

                    <thead class="thead-light">

                        <tr>

                            <th style="width:48px">
                                #
                            </th>

                            <th>
                                Payment Type
                            </th>

                            <th style="width:140px">
                                Amount
                            </th>

                            <th style="width:190px">
                                Reporting Period
                            </th>

                            <th>
                                Description
                            </th>

                            <th style="width:54px"></th>

                        </tr>

                    </thead>

                    <tbody></tbody>

                </table>

            </div>


            <!-- TOTAL / REVIEW -->
            <div
                class="
                    d-flex
                    flex-wrap
                    justify-content-between
                    align-items-center
                    mt-3
                "
            >

                <strong class="mb-2 mb-sm-0">

                    Total:

                    <span id="paymentLinesTotal">
                        GH&#8373;0.00
                    </span>

                </strong>


                <button
                    type="button"
                    class="
                        btn
                        btn-primary
                        btn-lg
                        px-4
                    "
                    id="reviewPaymentsBtn"
                    disabled
                >

                    <i class="fas fa-check mr-1"></i>

                    Review and Submit

                </button>

            </div>


            <div
                id="payment-feedback"
                class="mt-3"
                aria-live="polite"
            ></div>

        </div>

    </div>

</div>



<!--
|--------------------------------------------------------------------------
| PAYMENT REVIEW MODAL
|--------------------------------------------------------------------------
|
| This is initially captured inside page_content because of ob_start().
| JavaScript below moves it directly underneath <body>.
|
-->

<div
    class="modal fade"
    id="paymentReviewModal"
    tabindex="-1"
    role="dialog"
    aria-labelledby="paymentReviewModalLabel"
    aria-hidden="true"
>

    <div
        class="
            modal-dialog
            modal-lg
            modal-dialog-centered
        "
        role="document"
    >

        <div class="modal-content">

            <!-- HEADER -->
            <div class="modal-header bg-primary text-white">

                <h5
                    class="modal-title"
                    id="paymentReviewModalLabel"
                >

                    <i
                        class="
                            fas
                            fa-question-circle
                            mr-2
                        "
                    ></i>

                    Confirm Payments

                </h5>


                <button
                    type="button"
                    class="close text-white"
                    data-dismiss="modal"
                    aria-label="Close"
                >

                    <span aria-hidden="true">
                        &times;
                    </span>

                </button>

            </div>


            <!-- BODY -->
            <div class="modal-body">

                <p>
                    Review these payment lines before continuing
                    to the payment gateway.
                </p>


                <div class="table-responsive">

                    <table
                        class="
                            table
                            table-bordered
                            table-sm
                            mb-0
                        "
                        id="paymentReviewTable"
                    >

                        <thead class="thead-light">

                            <tr>

                                <th>#</th>

                                <th>Type</th>

                                <th>Amount</th>

                                <th>
                                    Reporting Period
                                </th>

                                <th>
                                    Description
                                </th>

                            </tr>

                        </thead>


                        <tbody></tbody>


                        <tfoot>

                            <tr>

                                <td
                                    colspan="2"
                                    class="
                                        text-right
                                        font-weight-bold
                                    "
                                >
                                    Total
                                </td>

                                <td
                                    colspan="3"
                                    class="font-weight-bold"
                                    id="paymentReviewTotal"
                                ></td>

                            </tr>

                        </tfoot>

                    </table>

                </div>

            </div>


            <!-- FOOTER -->
            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-dismiss="modal"
                >
                    Cancel
                </button>


                <button
                    type="button"
                    class="btn btn-success"
                    id="confirmPaymentsBtn"
                >

                    <i
                        class="
                            fas
                            fa-check-circle
                            mr-1
                        "
                    ></i>

                    Confirm and Continue

                </button>

            </div>

        </div>

    </div>

</div>



<?php

/*
|--------------------------------------------------------------------------
| Paystack Email Prompt Modal
|--------------------------------------------------------------------------
*/

include __DIR__ . '/bulk_paystack_email_prompt.php';

?>



<style>

#paymentLinesTable th,
#paymentLinesTable td {
    vertical-align: middle;
}


#paymentLinesTable tbody tr:hover {
    background: #e3f2fd;
}


#paymentLineForm .form-control-lg {
    font-size: 1.05rem;
}


#paymentLineForm label {
    margin-bottom: .25rem;
}


/*
|--------------------------------------------------------------------------
| Bootstrap Modal Protection
|--------------------------------------------------------------------------
*/

#paymentReviewModal {
    z-index: 1060 !important;
}


#paymentReviewModal .modal-dialog,
#paymentReviewModal .modal-content {
    position: relative;
    pointer-events: auto !important;
}


.modal-backdrop {
    z-index: 1050 !important;
}

</style>



<script>

(function ($) {

    'use strict';


    /*
    |--------------------------------------------------------------------------
    | PAGE VARIABLES
    |--------------------------------------------------------------------------
    */

    const baseUrl =
        <?= json_encode(
            BASE_URL,
            JSON_UNESCAPED_SLASHES
        ) ?>;


    const customerName =
        <?= json_encode(
            $fullName,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) ?>;


    const customerPhone =
        <?= json_encode(
            $phone,
            JSON_UNESCAPED_SLASHES
        ) ?>;


    const memberId =
        <?= $memberId ?>;


    const churchId =
        <?= $churchId ?>;


    const customerEmail =
        <?= json_encode(
            (string) ($_SESSION['email'] ?? ''),
            JSON_UNESCAPED_SLASHES
        ) ?>;


    const paymentDate =
        <?= json_encode(
            date('Y-m-d')
        ) ?>;


    const paymentLines = [];


    /*
    |--------------------------------------------------------------------------
    | IMPORTANT MODAL FIX
    |--------------------------------------------------------------------------
    |
    | Because this entire page is captured with ob_start(), the layout.php
    | file may put page_content inside a wrapper such as:
    |
    | <main>
    |     <?= $page_content ?>
    | </main>
    |
    | If that wrapper has transform, position, overflow or z-index,
    | Bootstrap's backdrop can cover the modal.
    |
    | Therefore move the modals DIRECTLY underneath BODY.
    |
    */

    function moveModalsToBody() {

        const $reviewModal =
            $('#paymentReviewModal');


        if (
            $reviewModal.length &&
            !$reviewModal.parent().is('body')
        ) {

            $reviewModal.appendTo(
                document.body
            );

        }


        const $emailModal =
            $('#paystackEmailPromptModal');


        if (
            $emailModal.length &&
            !$emailModal.parent().is('body')
        ) {

            $emailModal.appendTo(
                document.body
            );

        }

    }



    /*
    |--------------------------------------------------------------------------
    | MONEY FORMAT
    |--------------------------------------------------------------------------
    */

    function money(value) {

        return 'GH₵' +
            Number(value || 0)
                .toLocaleString(
                    undefined,
                    {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }
                );

    }



    /*
    |--------------------------------------------------------------------------
    | AUTOMATIC DESCRIPTION
    |--------------------------------------------------------------------------
    */

    function cleanText(value) {

        return String(value || '')
            .replace(/\s+/g, ' ')
            .trim();

    }


    function descriptionFor(line) {

        return (
            'Payment for ' +
            cleanText(line.periodText) +
            ' ' +
            cleanText(line.typeName)
        );

    }



    /*
    |--------------------------------------------------------------------------
    | RENDER PAYMENT LINES
    |--------------------------------------------------------------------------
    */

    function renderLines() {

        const $body =
            $('#paymentLinesTable tbody')
                .empty();


        let total = 0;


        paymentLines.forEach(
            function (line, index) {

                total +=
                    Number(line.amount);


                const $row =
                    $('<tr>')
                        .attr(
                            'data-index',
                            index
                        );


                $('<td>')
                    .text(index + 1)
                    .appendTo($row);


                /*
                 * Payment type dropdown
                 */
                const $type =
                    $('#payment_type_id')
                        .clone(false)
                        .removeAttr('id')
                        .removeClass(
                            'form-control-lg border-primary'
                        )
                        .addClass(
                            'form-control-sm line-type'
                        )
                        .val(
                            line.typeId
                        );


                $('<td>')
                    .append($type)
                    .appendTo($row);


                /*
                 * Amount
                 */
                $('<td>')
                    .append(
                        $('<input>', {

                            type: 'number',

                            min: '1',

                            step: '0.01',

                            class:
                                'form-control ' +
                                'form-control-sm ' +
                                'line-amount',

                            value:
                                line.amount

                        })
                    )
                    .appendTo($row);


                /*
                 * Reporting period
                 */
                const $period =
                    $('#payment_period')
                        .clone(false)
                        .removeAttr('id')
                        .removeClass(
                            'form-control-lg border-primary'
                        )
                        .addClass(
                            'form-control-sm line-period'
                        )
                        .val(
                            line.period
                        );


                $('<td>')
                    .append($period)
                    .appendTo($row);


                /*
                 * Description
                 */
                $('<td>')
                    .append(
                        $('<input>', {

                            type: 'text',

                            class:
                                'form-control ' +
                                'form-control-sm ' +
                                'line-description',

                            value:
                                line.description,

                            readonly:
                                true,

                            'aria-label':
                                'Automatically generated payment description',

                            maxlength:
                                255

                        })
                    )
                    .appendTo($row);


                /*
                 * Remove button
                 */
                $('<td>')
                    .append(
                        $('<button>', {

                            type:
                                'button',

                            class:
                                'btn btn-sm btn-outline-danger remove-line',

                            title:
                                'Remove line',

                            'aria-label':
                                'Remove line'

                        })
                        .html('&times;')
                    )
                    .appendTo($row);


                $body.append($row);

            }
        );


        $('#paymentLinesTotal')
            .text(
                money(total)
            );


        $('#reviewPaymentsBtn')
            .prop(
                'disabled',
                paymentLines.length === 0
            );

    }



    /*
    |--------------------------------------------------------------------------
    | ADD PAYMENT LINE
    |--------------------------------------------------------------------------
    */

    $('#addPaymentLineBtn')
        .on(
            'click',
            function () {

                const typeId =
                    $('#payment_type_id')
                        .val();


                const typeName =
                    $('#payment_type_id option:selected')
                        .text()
                        .trim();


                const amount =
                    Number(
                        $('#payment_amount')
                            .val()
                    );


                const period =
                    $('#payment_period')
                        .val();


                const periodText =
                    $('#payment_period option:selected')
                        .text()
                        .trim();


                if (
                    !typeId ||
                    !Number.isFinite(amount) ||
                    amount < 1 ||
                    !period
                ) {

                    $('#payment-feedback')
                        .html(
                            '<div class="alert alert-danger">' +
                            'Select a payment type and reporting period, ' +
                            'then enter an amount of at least GH&#8373;1.00.' +
                            '</div>'
                        );

                    return;

                }


                const line = {

                    typeId:
                        typeId,

                    typeName:
                        typeName,

                    amount:
                        amount,

                    period:
                        period,

                    periodText:
                        periodText

                };


                line.description =
                    descriptionFor(line);


                paymentLines.push(
                    line
                );


                $('#payment_type_id')
                    .val('');


                $('#payment_amount')
                    .val('');


                $('#payment-feedback')
                    .empty();


                renderLines();

            }
        );



    /*
    |--------------------------------------------------------------------------
    | EDIT TYPE / PERIOD
    |--------------------------------------------------------------------------
    */

    $('#paymentLinesTable')
        .on(
            'change',
            '.line-type, .line-period',
            function () {

                const index =
                    Number(
                        $(this)
                            .closest('tr')
                            .attr('data-index')
                    );


                const $row =
                    $(this)
                        .closest('tr');


                paymentLines[index].typeId =
                    $row
                        .find('.line-type')
                        .val();


                paymentLines[index].typeName =
                    cleanText(
                        $row
                            .find(
                                '.line-type option:selected'
                            )
                            .text()
                    );


                paymentLines[index].period =
                    $row
                        .find('.line-period')
                        .val();


                paymentLines[index].periodText =
                    cleanText(
                        $row
                            .find(
                                '.line-period option:selected'
                            )
                            .text()
                    );


                paymentLines[index].description =
                    descriptionFor(
                        paymentLines[index]
                    );


                $row
                    .find(
                        '.line-description'
                    )
                    .val(
                        paymentLines[index]
                            .description
                    );

            }
        );



    /*
    |--------------------------------------------------------------------------
    | EDIT AMOUNT
    |--------------------------------------------------------------------------
    */

    $('#paymentLinesTable')
        .on(
            'input',
            '.line-amount',
            function () {

                const index =
                    Number(
                        $(this)
                            .closest('tr')
                            .attr('data-index')
                    );


                paymentLines[index].amount =
                    Number(
                        $(this).val()
                    );


                const total =
                    paymentLines.reduce(
                        function (
                            sum,
                            line
                        ) {

                            return (
                                sum +
                                (
                                    Number(
                                        line.amount
                                    ) || 0
                                )
                            );

                        },
                        0
                    );


                $('#paymentLinesTotal')
                    .text(
                        money(total)
                    );

            }
        );



    /*
    |--------------------------------------------------------------------------
    | REMOVE PAYMENT LINE
    |--------------------------------------------------------------------------
    */

    $('#paymentLinesTable')
        .on(
            'click',
            '.remove-line',
            function () {

                const index =
                    Number(
                        $(this)
                            .closest('tr')
                            .attr('data-index')
                    );


                paymentLines.splice(
                    index,
                    1
                );


                renderLines();

            }
        );



    /*
    |--------------------------------------------------------------------------
    | REVIEW PAYMENTS
    |--------------------------------------------------------------------------
    */

    $('#reviewPaymentsBtn')
        .on(
            'click',
            function () {


                paymentLines.forEach(
                    function (line) {

                        line.typeName =
                            cleanText(line.typeName);

                        line.periodText =
                            cleanText(line.periodText);

                        const normalizedDescription =
                            cleanText(line.description);

                        line.description =
                            normalizedDescription ||
                            descriptionFor(line);

                    }
                );


                const invalidLine =
                    paymentLines.some(
                        function (line) {

                            return (

                                !line.typeId ||

                                !line.period ||

                                !Number.isFinite(
                                    Number(
                                        line.amount
                                    )
                                ) ||

                                Number(
                                    line.amount
                                ) < 1 ||

                                !line.description

                            );

                        }
                    );


                if (
                    !paymentLines.length ||
                    invalidLine
                ) {

                    $('#payment-feedback')
                        .html(
                            '<div class="alert alert-danger">' +
                            'Correct every payment line before continuing.' +
                            '</div>'
                        );

                    return;

                }



                renderLines();


                /*
                 * Clear previous review
                 */
                const $body =
                    $('#paymentReviewTable tbody')
                        .empty();


                let total = 0;



                /*
                 * Generate review table
                 */
                paymentLines.forEach(
                    function (
                        line,
                        index
                    ) {

                        total +=
                            Number(
                                line.amount
                            );


                        const $row =
                            $('<tr>');


                        $('<td>')
                            .text(
                                index + 1
                            )
                            .appendTo($row);


                        $('<td>')
                            .text(
                                line.typeName
                            )
                            .appendTo($row);


                        $('<td>')
                            .text(
                                money(
                                    line.amount
                                )
                            )
                            .appendTo($row);


                        $('<td>')
                            .text(
                                line.periodText
                            )
                            .appendTo($row);


                        $('<td>')
                            .text(
                                line.description
                            )
                            .appendTo($row);


                        $body.append(
                            $row
                        );

                    }
                );


                $('#paymentReviewTotal')
                    .text(
                        money(total)
                    );



                /*
                 * IMPORTANT:
                 * Make sure modal is outside the
                 * ob_start/layout content wrapper.
                 */
                moveModalsToBody();



                /*
                 * Remove any stale Bootstrap
                 * backdrop from a previous modal.
                 */
                $('.modal-backdrop')
                    .remove();


                $('body')
                    .removeClass(
                        'modal-open'
                    )
                    .css(
                        'padding-right',
                        ''
                    );



                /*
                 * Show modal
                 */
                $('#paymentReviewModal')
                    .modal({

                        backdrop:
                            true,

                        keyboard:
                            true,

                        show:
                            true

                    });

            }
        );



    /*
    |--------------------------------------------------------------------------
    | SUBMIT PAYMENT TO PAYMENT GATEWAY
    |--------------------------------------------------------------------------
    */

    function submitPayment(email) {

        const method =
            $('#payment_method')
                .val();


        const endpoint =
            method === 'paystack'

                ? baseUrl +
                  '/views/ajax_paystack_checkout.php'

                : baseUrl +
                  '/views/ajax_hubtel_checkout.php';



        const total =
            paymentLines.reduce(
                function (
                    sum,
                    line
                ) {

                    return (
                        sum +
                        Number(
                            line.amount
                        )
                    );

                },
                0
            );



        const payload = {

            amount:
                total,


            description:
                'Payment [' +

                paymentLines
                    .map(
                        function (line) {

                            return (
                                line.description
                            );

                        }
                    )
                    .join('; ') +

                ']',


            customerName:
                customerName,


            customerPhone:
                customerPhone,


            member_id:
                memberId,


            church_id:
                churchId,


            bulk_items:
                paymentLines.map(
                    function (line) {

                        return {

                            member_id:
                                memberId,

                            church_id:
                                churchId,

                            typeId:
                                line.typeId,

                            payment_type_id:
                                line.typeId,

                            typeName:
                                line.typeName,

                            amount:
                                Number(
                                    line.amount
                                ),

                            date:
                                paymentDate,

                            period:
                                line.period,

                            payment_period:
                                line.period,

                            periodText:
                                line.periodText,

                            payment_period_description:
                                line.periodText,

                            desc:
                                line.description

                        };

                    }
                )

        };



        /*
         * Paystack requires email
         */
        if (
            method === 'paystack'
        ) {

            payload.customerEmail =
                email;

        }



        /*
         * Show loading feedback
         */
        $('#payment-feedback')
            .html(
                '<div class="alert alert-info">' +
                '<i class="fas fa-spinner fa-spin mr-2"></i>' +
                'Contacting the payment gateway...' +
                '</div>'
            );


        $('#confirmPaymentsBtn')
            .prop(
                'disabled',
                true
            );



        /*
         * Initiate gateway checkout
         */
        $.post(
            endpoint,
            payload,
            null,
            'json'
        )
        .done(
            function (response) {


                if (
                    response &&
                    response.success &&
                    response.checkoutUrl
                ) {

                    window.location.href =
                        response.checkoutUrl;

                    return;

                }


                $('#confirmPaymentsBtn')
                    .prop(
                        'disabled',
                        false
                    );


                $('#payment-feedback')
                    .html(
                        $('<div>', {
                            class:
                                'alert alert-danger'
                        })
                        .text(
                            (
                                response &&
                                response.error
                            )
                                ? response.error
                                : 'Could not initiate payment. Please try again.'
                        )
                    );

            }
        )
        .fail(
            function (
                xhr
            ) {

                $('#confirmPaymentsBtn')
                    .prop(
                        'disabled',
                        false
                    );


                let message =
                    'The payment gateway could not be reached. Please try again.';


                /*
                 * Display server response when available.
                 * Very useful when debugging PHP errors.
                 */
                if (
                    xhr.responseJSON &&
                    xhr.responseJSON.error
                ) {

                    message =
                        xhr.responseJSON.error;

                }


                $('#payment-feedback')
                    .html(
                        $('<div>', {
                            class:
                                'alert alert-danger'
                        })
                        .text(
                            message
                        )
                    );

            }
        );

    }



    /*
    |--------------------------------------------------------------------------
    | CONFIRM AND CONTINUE
    |--------------------------------------------------------------------------
    */

    $('#confirmPaymentsBtn')
        .on(
            'click',
            function () {


                const method =
                    $('#payment_method')
                        .val();


                /*
                 * Disable immediately to prevent
                 * double-clicks.
                 */
                $('#confirmPaymentsBtn')
                    .prop(
                        'disabled',
                        true
                    );



                /*
                 * HUBTEL
                 */
                if (
                    method !== 'paystack'
                ) {

                    $('#paymentReviewModal')
                        .one(
                            'hidden.bs.modal',
                            function () {

                                submitPayment(
                                    ''
                                );

                            }
                        );


                    $('#paymentReviewModal')
                        .modal(
                            'hide'
                        );


                    return;

                }



                /*
                 * PAYSTACK:
                 * Email already available
                 */
                if (
                    customerEmail
                ) {

                    $('#paymentReviewModal')
                        .one(
                            'hidden.bs.modal',
                            function () {

                                submitPayment(
                                    customerEmail
                                );

                            }
                        );


                    $('#paymentReviewModal')
                        .modal(
                            'hide'
                        );


                    return;

                }



                /*
                 * PAYSTACK:
                 * Ask member for email
                 */
                $('#paymentReviewModal')
                    .one(
                        'hidden.bs.modal',
                        function () {


                            moveModalsToBody();


                            $('#confirmPaymentsBtn')
                                .prop(
                                    'disabled',
                                    false
                                );


                            $('#paystackEmailPromptModal')
                                .modal({

                                    backdrop:
                                        true,

                                    keyboard:
                                        true,

                                    show:
                                        true

                                });

                        }
                    );


                $('#paymentReviewModal')
                    .modal(
                        'hide'
                    );

            }
        );



    /*
    |--------------------------------------------------------------------------
    | PAYSTACK EMAIL SUBMISSION
    |--------------------------------------------------------------------------
    */

    $(document)
        .off(
            'click.memberPayment',
            '#paystackBulkEmailSubmitBtn'
        )
        .on(
            'click.memberPayment',
            '#paystackBulkEmailSubmitBtn',
            function () {


                const email =
                    $('#paystackBulkEmailInput')
                        .val()
                        .trim();



                if (
                    !/^\S+@\S+\.\S+$/
                        .test(email)
                ) {

                    $('#paystackBulkEmailError')
                        .text(
                            'Enter a valid email address.'
                        );

                    return;

                }



                $('#paystackBulkEmailError')
                    .text('');


                $('#paystackBulkEmailSubmitBtn')
                    .prop(
                        'disabled',
                        true
                    );



                $('#paystackEmailPromptModal')
                    .one(
                        'hidden.bs.modal',
                        function () {

                            $('#paystackBulkEmailSubmitBtn')
                                .prop(
                                    'disabled',
                                    false
                                );


                            submitPayment(
                                email
                            );

                        }
                    );


                $('#paystackEmailPromptModal')
                    .modal(
                        'hide'
                    );

            }
        );



    /*
    |--------------------------------------------------------------------------
    | CLEANUP AFTER ANY MODAL
    |--------------------------------------------------------------------------
    */

    $('#paymentReviewModal')
        .on(
            'hidden.bs.modal',
            function () {

                /*
                 * Bootstrap normally removes
                 * the backdrop itself.
                 *
                 * This delayed check only removes
                 * orphaned backdrops.
                 */
                setTimeout(
                    function () {

                        if (
                            $('.modal.show')
                                .length === 0
                        ) {

                            $('.modal-backdrop')
                                .remove();


                            $('body')
                                .removeClass(
                                    'modal-open'
                                )
                                .css(
                                    'padding-right',
                                    ''
                                );

                        }

                    },
                    100
                );

            }
        );



    /*
    |--------------------------------------------------------------------------
    | INITIALISE
    |--------------------------------------------------------------------------
    */

    $(function () {


        /*
         * CRITICAL FIX
         *
         * Do this after the page has been
         * inserted by layout.php.
         */
        moveModalsToBody();


        renderLines();

    });


})(jQuery);

</script>


<?php

/*
|--------------------------------------------------------------------------
| Finish Content Buffer
|--------------------------------------------------------------------------
*/

$page_content = ob_get_clean();

$page_title = 'Payment';


/*
|--------------------------------------------------------------------------
| Render Main Layout
|--------------------------------------------------------------------------
*/

include __DIR__ . '/../includes/layout.php';
