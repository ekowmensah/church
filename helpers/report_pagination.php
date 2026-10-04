<?php

if (!function_exists('report_pagination_page_size')) {
    function report_pagination_page_size($requested = null, array $allowed = [25, 50, 100], $default = 25)
    {
        $size = (int) ($requested ?? ($_GET['per_page'] ?? $default));
        return in_array($size, $allowed, true) ? $size : $default;
    }
}

if (!function_exists('report_pagination_url')) {
    function report_pagination_url($page, $perPage)
    {
        $query = $_GET;
        $query['page'] = max(1, (int) $page);
        $query['per_page'] = (int) $perPage;
        return '?' . http_build_query($query);
    }
}

if (!function_exists('report_render_server_pagination')) {
    function report_render_server_pagination($totalRows, $page, $perPage, $ariaLabel = 'Report pages')
    {
        $totalRows = max(0, (int) $totalRows);
        $perPage = max(1, (int) $perPage);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min(max(1, (int) $page), $totalPages);
        $start = $totalRows === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $end = min($totalRows, $page * $perPage);

        $pages = [1, $totalPages];
        for ($candidate = max(1, $page - 2); $candidate <= min($totalPages, $page + 2); $candidate++) {
            $pages[] = $candidate;
        }
        $pages = array_values(array_unique($pages));
        sort($pages);

        echo '<div class="report-server-pagination d-flex flex-wrap align-items-center justify-content-between mt-3" style="gap:10px">';
        echo '<div class="d-flex flex-wrap align-items-center" style="gap:12px"><small class="text-muted">Showing '
            . number_format($start) . '&ndash;' . number_format($end) . ' of ' . number_format($totalRows) . ' rows</small>';
        echo '<form method="get" class="form-inline report-page-size-form">';
        foreach ($_GET as $key => $value) {
            if ($key === 'page' || $key === 'per_page' || is_array($value)) {
                continue;
            }
            echo '<input type="hidden" name="' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8')
                . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">';
        }
        echo '<label class="small text-muted mb-0">Rows <select name="per_page" class="custom-select custom-select-sm ml-1" onchange="this.form.submit()">';
        foreach ([25, 50, 100] as $size) {
            echo '<option value="' . $size . '"' . ($size === $perPage ? ' selected' : '') . '>' . $size . '</option>';
        }
        echo '</select></label></form></div>';

        if ($totalPages > 1) {
            echo '<nav aria-label="' . htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') . '"><ul class="pagination pagination-sm">';
            echo '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="'
                . htmlspecialchars(report_pagination_url($page - 1, $perPage), ENT_QUOTES, 'UTF-8') . '">Previous</a></li>';

            $previous = null;
            foreach ($pages as $number) {
                if ($previous !== null && $number > $previous + 1) {
                    echo '<li class="page-item disabled" aria-hidden="true"><span class="page-link">&hellip;</span></li>';
                }
                echo '<li class="page-item ' . ($number === $page ? 'active' : '') . '"><a class="page-link" href="'
                    . htmlspecialchars(report_pagination_url($number, $perPage), ENT_QUOTES, 'UTF-8') . '">'
                    . number_format($number) . '</a></li>';
                $previous = $number;
            }

            echo '<li class="page-item ' . ($page >= $totalPages ? 'disabled' : '') . '"><a class="page-link" href="'
                . htmlspecialchars(report_pagination_url($page + 1, $perPage), ENT_QUOTES, 'UTF-8') . '">Next</a></li>';
            echo '</ul></nav>';
        }

        echo '</div>';
    }
}

if (!function_exists('report_paginate_query')) {
    /**
     * Execute one filtered SELECT with an SQL-level COUNT and LIMIT/OFFSET.
     * The input SQL must not end in a semicolon and must not already contain
     * LIMIT/OFFSET. Placeholder types and values are reused for the count.
     */
    function report_paginate_query(mysqli $conn, $sql, $types = '', array $params = [], $defaultPerPage = 25)
    {
        $sql = rtrim(trim((string) $sql), "; \t\n\r\0\x0B");
        if (stripos($sql, 'select') !== 0 || preg_match('/\blimit\b/i', $sql)) {
            throw new InvalidArgumentException('Report pagination requires an unbounded SELECT statement.');
        }

        $countSql = 'SELECT COUNT(*) AS total_rows FROM (' . $sql . ') report_page_source';
        $countStmt = $conn->prepare($countSql);
        if (!$countStmt) {
            throw new RuntimeException('Unable to prepare report count query: ' . $conn->error);
        }
        if ($types !== '') {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalRows = (int) (($countResult->fetch_assoc()['total_rows'] ?? 0));
        $countStmt->close();

        $perPage = report_pagination_page_size(null, [25, 50, 100], $defaultPerPage);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
        $offset = ($page - 1) * $perPage;

        $dataSql = $sql . ' LIMIT ? OFFSET ?';
        $dataStmt = $conn->prepare($dataSql);
        if (!$dataStmt) {
            throw new RuntimeException('Unable to prepare paginated report query: ' . $conn->error);
        }
        $dataTypes = $types . 'ii';
        $dataParams = array_merge($params, [$perPage, $offset]);
        $dataStmt->bind_param($dataTypes, ...$dataParams);
        $dataStmt->execute();

        return [
            'statement' => $dataStmt,
            'result' => $dataStmt->get_result(),
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'page' => $page,
            'per_page' => $perPage,
            'offset' => $offset,
        ];
    }
}

