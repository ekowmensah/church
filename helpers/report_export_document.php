<?php

require_once __DIR__ . '/report_export_branding.php';

/**
 * Server-side branded document exports for endpoints that cannot use the
 * browser DataTables exporter. The PDF writer intentionally uses only PHP
 * core/GD so shared-hosting deployments do not require Composer packages.
 */

function report_export_document_text($value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    return trim(preg_replace('/\s+/u', ' ', (string) $value));
}

function report_export_document_html($value): string
{
    return htmlspecialchars(report_export_document_text($value), ENT_QUOTES, 'UTF-8');
}

function report_export_build_excel_html(
    array $branding,
    string $title,
    string $subtitle,
    array $headers,
    array $rows
): string {
    $primary = report_export_branding_hex($branding['primary_color'] ?? '', '#173F5F');
    $accent = report_export_branding_hex($branding['accent_color'] ?? '', '#236B45');
    $headerText = report_export_branding_hex($branding['header_text_color'] ?? '', '#FFFFFF');
    $columnCount = max(1, count($headers));
    $logo = trim((string) ($branding['logo_data_uri'] ?? ''));
    if ($logo === '') {
        $logo = trim((string) ($branding['logo_url'] ?? ''));
    }
    $logoHtml = $logo !== ''
        ? '<img src="' . htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') . '" alt="Church logo" style="max-height:54px;max-width:76px">'
        : '';

    $html = '<!DOCTYPE html><html xmlns:x="urn:schemas-microsoft-com:office:excel"><head>'
        . '<meta charset="UTF-8"><style>'
        . 'body{font-family:Arial,sans-serif;color:#202830}.brand td{border:0;padding:5px}'
        . '.church{font-size:14pt;font-weight:bold;color:' . $primary . '}.subtitle{font-size:9pt;color:#55616b}'
        . '.title{text-align:right;font-size:16pt;font-weight:bold;font-style:italic;color:' . $primary . '}'
        . 'table.data{border-collapse:collapse}.data th{background:' . $primary . ';color:' . $headerText . ';font-weight:bold;border:1px solid ' . $accent . ';padding:6px}'
        . '.data td{border:1px solid #cbd4da;padding:5px;mso-number-format:"\\@"}.data tr.even td{background:#F3F7F9}'
        . '.footer{text-align:center;color:' . $accent . ';font-size:9pt;border:0;padding-top:12px}'
        . '</style></head><body><table class="brand"><tr><td>' . $logoHtml . '</td><td colspan="'
        . max(1, $columnCount - 2) . '"><div class="church">' . report_export_document_html($branding['church_name'] ?? '')
        . '</div><div class="subtitle">' . report_export_document_html($subtitle) . '</div></td><td class="title">'
        . report_export_document_html($title) . '</td></tr></table><table class="data"><thead><tr>';

    foreach ($headers as $header) {
        $html .= '<th>' . report_export_document_html($header) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach (array_values($rows) as $index => $row) {
        $html .= '<tr class="' . ($index % 2 === 1 ? 'even' : 'odd') . '">';
        foreach ($headers as $columnIndex => $_header) {
            $html .= '<td>' . report_export_document_html($row[$columnIndex] ?? '') . '</td>';
        }
        $html .= '</tr>';
    }
    if (!$rows) {
        $html .= '<tr><td colspan="' . $columnCount . '">No records matched the selected filters.</td></tr>';
    }
    $html .= '</tbody></table><table><tr><td colspan="' . $columnCount . '" class="footer"><strong>'
        . report_export_document_html($branding['footer_line_one'] ?? '') . '</strong><br>'
        . report_export_document_html($branding['footer_line_two'] ?? '') . '</td></tr></table></body></html>';
    return "\xEF\xBB\xBF" . $html;
}

function report_export_download_excel(
    array $branding,
    string $title,
    string $subtitle,
    array $headers,
    array $rows,
    string $filename
): void {
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'report.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo report_export_build_excel_html($branding, $title, $subtitle, $headers, $rows);
}

function report_export_pdf_rgb(string $hex, string $fallback): array
{
    $hex = ltrim(report_export_branding_hex($hex, $fallback), '#');
    return [
        hexdec(substr($hex, 0, 2)) / 255,
        hexdec(substr($hex, 2, 2)) / 255,
        hexdec(substr($hex, 4, 2)) / 255,
    ];
}

function report_export_pdf_number(float $number): string
{
    return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
}

function report_export_pdf_color(array $rgb, string $operator = 'rg'): string
{
    return report_export_pdf_number((float) $rgb[0]) . ' '
        . report_export_pdf_number((float) $rgb[1]) . ' '
        . report_export_pdf_number((float) $rgb[2]) . ' ' . $operator;
}

function report_export_pdf_string($value): string
{
    $text = report_export_document_text($value);
    $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
    if ($encoded === false) {
        $encoded = preg_replace('/[^\x20-\x7E]/', '?', $text);
    }
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encoded);
}

function report_export_pdf_ellipsize($value, float $width, float $fontSize): string
{
    $text = report_export_document_text($value);
    $maxCharacters = max(1, (int) floor(($width - 6) / max(1, $fontSize * 0.49)));
    if (mb_strlen($text, 'UTF-8') <= $maxCharacters) {
        return $text;
    }
    if ($maxCharacters <= 3) {
        return mb_substr($text, 0, $maxCharacters, 'UTF-8');
    }
    return mb_substr($text, 0, $maxCharacters - 3, 'UTF-8') . '...';
}

function report_export_pdf_text_command(
    string $text,
    float $x,
    float $y,
    float $size,
    string $font = 'F1',
    ?array $color = null
): string {
    $command = 'BT ';
    if ($color !== null) {
        $command .= report_export_pdf_color($color) . ' ';
    }
    return $command . '/' . $font . ' ' . report_export_pdf_number($size) . ' Tf '
        . report_export_pdf_number($x) . ' ' . report_export_pdf_number($y)
        . ' Td (' . report_export_pdf_string($text) . ") Tj ET\n";
}

function report_export_pdf_approximate_width(string $text, float $fontSize): float
{
    return strlen(report_export_pdf_string($text)) * $fontSize * 0.49;
}

function report_export_pdf_logo(array $branding): ?array
{
    $dataUri = trim((string) ($branding['logo_data_uri'] ?? ''));
    if ($dataUri === '' || !preg_match('#^data:image/(?:png|jpe?g|gif);base64,(.+)$#is', $dataUri, $match)) {
        return null;
    }
    $source = base64_decode($match[1], true);
    if ($source === false || strlen($source) > 2 * 1024 * 1024 || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $image = @imagecreatefromstring($source);
    if ($image === false) {
        return null;
    }
    $width = imagesx($image);
    $height = imagesy($image);
    $flattened = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($flattened, 255, 255, 255);
    imagefill($flattened, 0, 0, $white);
    imagecopy($flattened, $image, 0, 0, 0, 0, $width, $height);
    ob_start();
    imagejpeg($flattened, null, 88);
    $jpeg = ob_get_clean();
    imagedestroy($image);
    imagedestroy($flattened);
    if (!is_string($jpeg) || $jpeg === '') {
        return null;
    }
    return ['data' => $jpeg, 'width' => $width, 'height' => $height];
}

function report_export_build_pdf(
    array $branding,
    string $title,
    string $subtitle,
    array $headers,
    array $rows,
    array $columnWidths = []
): string {
    if (!$headers) {
        throw new InvalidArgumentException('A PDF export requires at least one column.');
    }

    $pageWidth = 841.89;
    $pageHeight = 595.28;
    $margin = 34.0;
    $usableWidth = $pageWidth - ($margin * 2);
    $rowHeight = 18.0;
    $tableTop = 472.0;
    $tableBottom = 52.0;
    $rowsPerPage = max(1, (int) floor(($tableTop - $tableBottom - $rowHeight) / $rowHeight));
    $columnCount = count($headers);

    if (count($columnWidths) !== $columnCount || array_sum($columnWidths) <= 0) {
        $columnWidths = array_fill(0, $columnCount, $usableWidth / $columnCount);
    } else {
        $scale = $usableWidth / array_sum($columnWidths);
        $columnWidths = array_map(static fn($width) => max(10, (float) $width * $scale), $columnWidths);
    }

    $rows = array_values($rows);
    $pages = $rows ? array_chunk($rows, $rowsPerPage) : [[]];
    $primary = report_export_pdf_rgb((string) ($branding['primary_color'] ?? ''), '#173F5F');
    $accent = report_export_pdf_rgb((string) ($branding['accent_color'] ?? ''), '#236B45');
    $headerText = report_export_pdf_rgb((string) ($branding['header_text_color'] ?? ''), '#FFFFFF');
    $dark = [0.12, 0.16, 0.19];
    $muted = [0.34, 0.39, 0.43];
    $stripe = [0.953, 0.969, 0.976];
    $grid = [0.77, 0.82, 0.85];
    $logo = report_export_pdf_logo($branding);

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique >>';
    $nextObject = 6;
    $imageObject = null;
    if ($logo !== null) {
        $imageObject = $nextObject++;
        $objects[$imageObject] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $logo['width']
            . ' /Height ' . (int) $logo['height']
            . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '
            . strlen($logo['data']) . ">>\nstream\n" . $logo['data'] . "\nendstream";
    }

    $pageObjectNumbers = [];
    $pageTotal = count($pages);
    foreach ($pages as $pageIndex => $pageRows) {
        $content = "q\n";
        if ($logo !== null) {
            $boxWidth = 42.0;
            $boxHeight = 42.0;
            $ratio = min($boxWidth / $logo['width'], $boxHeight / $logo['height']);
            $drawWidth = $logo['width'] * $ratio;
            $drawHeight = $logo['height'] * $ratio;
            $content .= 'q ' . report_export_pdf_number($drawWidth) . ' 0 0 ' . report_export_pdf_number($drawHeight)
                . ' ' . report_export_pdf_number($margin) . ' '
                . report_export_pdf_number(526 + (($boxHeight - $drawHeight) / 2)) . " cm /Im1 Do Q\n";
        }
        $brandX = $margin + ($logo !== null ? 50 : 0);
        $content .= report_export_pdf_text_command((string) ($branding['church_name'] ?? ''), $brandX, 556, 12, 'F2', $primary);
        $content .= report_export_pdf_text_command($subtitle, $brandX, 539, 8, 'F1', $muted);
        $titleWidth = report_export_pdf_approximate_width($title, 15);
        $content .= report_export_pdf_text_command($title, max($margin, $pageWidth - $margin - $titleWidth), 548, 15, 'F3', $primary);
        $content .= report_export_pdf_color($accent) . ' ' . report_export_pdf_number($margin) . ' 519 '
            . report_export_pdf_number($usableWidth) . " 2 re f\n";

        $x = $margin;
        foreach ($headers as $columnIndex => $header) {
            $width = $columnWidths[$columnIndex];
            $content .= report_export_pdf_color($primary) . ' ' . report_export_pdf_number($x) . ' '
                . report_export_pdf_number($tableTop - $rowHeight) . ' ' . report_export_pdf_number($width) . ' '
                . report_export_pdf_number($rowHeight) . " re f\n";
            $headerValue = report_export_pdf_ellipsize($header, $width, 6.7);
            $content .= report_export_pdf_text_command($headerValue, $x + 3, $tableTop - 12, 6.7, 'F2', $headerText);
            $x += $width;
        }

        $y = $tableTop - $rowHeight;
        foreach ($pageRows as $rowIndex => $row) {
            $y -= $rowHeight;
            if ($rowIndex % 2 === 1) {
                $content .= report_export_pdf_color($stripe) . ' ' . report_export_pdf_number($margin) . ' '
                    . report_export_pdf_number($y) . ' ' . report_export_pdf_number($usableWidth) . ' '
                    . report_export_pdf_number($rowHeight) . " re f\n";
            }
            $x = $margin;
            foreach ($headers as $columnIndex => $_header) {
                $width = $columnWidths[$columnIndex];
                $content .= report_export_pdf_color($grid, 'RG') . ' 0.35 w '
                    . report_export_pdf_number($x) . ' ' . report_export_pdf_number($y) . ' '
                    . report_export_pdf_number($width) . ' ' . report_export_pdf_number($rowHeight) . " re S\n";
                $value = report_export_pdf_ellipsize($row[$columnIndex] ?? '', $width, 6.5);
                $content .= report_export_pdf_text_command($value, $x + 3, $y + 6, 6.5, 'F1', $dark);
                $x += $width;
            }
        }
        if (!$pageRows) {
            $content .= report_export_pdf_text_command('No records matched the selected filters.', $margin + 4, $tableTop - 31, 8, 'F1', $muted);
        }

        $footerOne = (string) ($branding['footer_line_one'] ?? '');
        $footerTwo = (string) ($branding['footer_line_two'] ?? '');
        $content .= report_export_pdf_text_command(
            $footerOne,
            max($margin, ($pageWidth - report_export_pdf_approximate_width($footerOne, 7.2)) / 2),
            28,
            7.2,
            'F2',
            $accent
        );
        $content .= report_export_pdf_text_command(
            $footerTwo,
            max($margin, ($pageWidth - report_export_pdf_approximate_width($footerTwo, 7.0)) / 2),
            16,
            7.0,
            'F1',
            $accent
        );
        $content .= report_export_pdf_text_command('Page ' . ($pageIndex + 1) . ' of ' . $pageTotal, $pageWidth - $margin - 52, 16, 7, 'F1', $muted);
        $content .= "Q\n";

        $contentObject = $nextObject++;
        $pageObject = $nextObject++;
        $objects[$contentObject] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . 'endstream';
        $xObjects = $imageObject !== null ? ' /XObject << /Im1 ' . $imageObject . ' 0 R >>' : '';
        $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '
            . report_export_pdf_number($pageWidth) . ' ' . report_export_pdf_number($pageHeight)
            . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >>' . $xObjects
            . ' >> /Contents ' . $contentObject . ' 0 R >>';
        $pageObjectNumbers[] = $pageObject;
    }

    $objects[2] = '<< /Type /Pages /Count ' . count($pageObjectNumbers) . ' /Kids ['
        . implode(' ', array_map(static fn($number) => $number . ' 0 R', $pageObjectNumbers)) . '] >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $number => $object) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $objectCount = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($objectCount + 1) . "\n0000000000 65535 f \n";
    for ($number = 1; $number <= $objectCount; $number++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$number]) . "\n";
    }
    $pdf .= 'trailer << /Size ' . ($objectCount + 1) . " /Root 1 0 R >>\nstartxref\n"
        . $xrefOffset . "\n%%EOF\n";
    return $pdf;
}

function report_export_download_pdf(
    array $branding,
    string $title,
    string $subtitle,
    array $headers,
    array $rows,
    string $filename,
    array $columnWidths = []
): void {
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'report.pdf';
    $pdf = report_export_build_pdf($branding, $title, $subtitle, $headers, $rows, $columnWidths);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
}
