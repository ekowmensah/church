<?php
// includes/report_ui_helpers.php
// Shared UI components and helpers for payment reports

function render_report_filter_bar($date, $btnClass = 'btn-primary') {
    ?>
    <form method="get" class="form-inline bg-white rounded shadow-sm px-3 py-2 mb-3 animate__animated animate__fadeInDown">
        <label for="date" class="mr-2 font-weight-bold"><i class="fas fa-calendar-alt"></i> Date:</label>
        <input type="date" class="form-control mr-2" id="date" name="date" value="<?= htmlspecialchars($date) ?>" max="<?= date('Y-m-d') ?>">
        <button type="submit" class="btn <?= $btnClass ?>" data-toggle="tooltip" title="Filter by date"><i class="fas fa-filter"></i> Filter</button>
    </form>
    <?php
}

function render_summary_card($label, $value, $icon, $color = 'primary', $sub = '') {
    ?>
    <div class="card border-left-<?= $color ?> shadow h-100 py-2 animate__animated animate__fadeInLeft mb-2">
        <div class="card-body d-flex align-items-center">
            <div class="mr-3"><i class="fas <?= $icon ?> fa-2x text-<?= $color ?>"></i></div>
            <div>
                <div class="text-xs font-weight-bold text-<?= $color ?> text-uppercase mb-1"><?= $label ?></div>
                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $value ?></div>
                <?php if ($sub): ?><div class="small text-muted mt-1"><?= $sub ?></div><?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

function include_datatables_scripts() {
    ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
    <?php
}

function datatables_init_script($tableId, $options = []) {
    $default = [
        'dom' => 'Bfrtip',
        'buttons' => [
            [ 'extend' => 'copy', 'className' => 'btn btn-sm btn-outline-secondary' ],
            [ 'extend' => 'csv', 'className' => 'btn btn-sm btn-outline-primary' ],
            [ 'extend' => 'excel', 'className' => 'btn btn-sm btn-outline-success' ],
            [ 'extend' => 'pdf', 'className' => 'btn btn-sm btn-outline-danger' ],
            [ 'extend' => 'print', 'className' => 'btn btn-sm btn-outline-dark' ]
        ],
        'responsive' => true,
        'pageLength' => 10,
        'order' => [[0, 'desc']]
    ];
    $opts = array_merge($default, $options);
    $json = json_encode($opts);
    ?>
    <script>
        $(function() {
            $('#<?= $tableId ?>').DataTable(<?= $json ?>);
            $('[data-toggle="tooltip"]').tooltip();
        });
    </script>
    <?php
}

function render_print_button($label = 'Print report') {
    ?>
    <button class="btn btn-light btn-sm" onclick="window.print()" data-toggle="tooltip" title="<?= htmlspecialchars($label) ?>"><i class="fas fa-print"></i> Print</button>
    <?php
}
