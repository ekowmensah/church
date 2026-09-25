<?php

/**
 * Shared branding for browser, Excel-compatible HTML, print and PDF exports.
 * This helper deliberately falls back to safe defaults when Phase 0023 has
 * not yet been deployed, allowing application files to be uploaded first.
 */

function report_export_branding_defaults(): array
{
    return [
        'church_id' => 0,
        'church_name' => 'MyFreeman Church Portal',
        'primary_color' => '#173F5F',
        'accent_color' => '#236B45',
        'header_text_color' => '#FFFFFF',
        'footer_line_one' => 'Powered By: MyFreeman Digital NetWorks - Evangelism, Through Digitalization!',
        'footer_line_two' => 'FDN: Extenditque Manum Omni Membri, Ubique!!',
        'logo_url' => defined('BASE_URL') ? BASE_URL . '/uploads/logo.png' : 'uploads/logo.png',
        'logo_data_uri' => '',
    ];
}

function report_export_branding_hex($value, string $fallback): string
{
    $value = strtoupper(trim((string) $value));
    return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : $fallback;
}

function report_export_branding_is_super_admin(mysqli $conn): bool
{
    if (!empty($_SESSION['is_super_admin'])
        || (int) ($_SESSION['role_id'] ?? 0) === 1
        || in_array(1, array_map('intval', (array) ($_SESSION['role_ids'] ?? [])), true)) {
        return true;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) return false;
    $statement = $conn->prepare(
        "SELECT 1 FROM user_roles user_role
         JOIN roles role ON role.id = user_role.role_id
         WHERE user_role.user_id = ?
           AND (role.id = 1 OR LOWER(TRIM(role.name)) IN ('super admin', 'super administrator'))
         LIMIT 1"
    );
    $statement->bind_param('i', $userId);
    $statement->execute();
    $isSuper = (bool) $statement->get_result()->fetch_row();
    $statement->close();
    return $isSuper;
}

function report_export_branding_resolve_church_id(mysqli $conn, ?int $requestedChurchId = null): int
{
    $requestedChurchId = (int) ($requestedChurchId ?? 0);

    $memberId = (int) ($_SESSION['member_id'] ?? 0);
    if ($memberId < 1 && (int) ($_SESSION['user_id'] ?? 0) > 0) {
        $statement = $conn->prepare('SELECT member_id FROM users WHERE id = ? LIMIT 1');
        $userId = (int) $_SESSION['user_id'];
        $statement->bind_param('i', $userId);
        $statement->execute();
        $memberId = (int) ($statement->get_result()->fetch_assoc()['member_id'] ?? 0);
        $statement->close();
    }
    $actorChurchId = 0;
    if ($memberId > 0) {
        $statement = $conn->prepare('SELECT church_id FROM members WHERE id = ? LIMIT 1');
        $statement->bind_param('i', $memberId);
        $statement->execute();
        $actorChurchId = (int) ($statement->get_result()->fetch_assoc()['church_id'] ?? 0);
        $statement->close();
    }

    if ($requestedChurchId > 0
        && ($requestedChurchId === $actorChurchId || report_export_branding_is_super_admin($conn))) {
        return $requestedChurchId;
    }
    return $actorChurchId;
}

function report_export_branding_logo(string $storedLogo): array
{
    $uploadDirectory = realpath(__DIR__ . '/../uploads');
    if ($uploadDirectory === false) {
        return ['', ''];
    }

    $filename = basename(trim($storedLogo));
    $candidate = $filename !== '' ? $uploadDirectory . DIRECTORY_SEPARATOR . $filename : '';
    if ($candidate === '' || !is_file($candidate)) {
        $filename = 'logo.png';
        $candidate = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
    }
    $resolved = realpath($candidate);
    if ($resolved === false || strpos($resolved, $uploadDirectory . DIRECTORY_SEPARATOR) !== 0
        || !is_file($resolved) || filesize($resolved) > 2 * 1024 * 1024) {
        return ['', ''];
    }

    $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
    $mimeTypes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];
    if (!isset($mimeTypes[$extension])) {
        return ['', ''];
    }
    $contents = file_get_contents($resolved);
    if ($contents === false) {
        return ['', ''];
    }
    $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    return [
        $baseUrl . '/uploads/' . rawurlencode($filename),
        'data:' . $mimeTypes[$extension] . ';base64,' . base64_encode($contents),
    ];
}

function report_export_branding_context(mysqli $conn, ?int $requestedChurchId = null, ?string $knownChurchName = null): array
{
    $branding = report_export_branding_defaults();
    try {
        $churchId = report_export_branding_resolve_church_id($conn, $requestedChurchId);
        if ($churchId < 1) {
            return $branding;
        }

        $statement = $conn->prepare('SELECT id, name, logo FROM churches WHERE id = ? LIMIT 1');
        $statement->bind_param('i', $churchId);
        $statement->execute();
        $church = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!$church) {
            return $branding;
        }

        $branding['church_id'] = (int) $church['id'];
        $branding['church_name'] = trim((string) ($knownChurchName ?: $church['name'])) ?: $branding['church_name'];
        [$branding['logo_url'], $branding['logo_data_uri']] = report_export_branding_logo((string) ($church['logo'] ?? ''));

        $tableExists = $conn->query(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() " .
            "AND TABLE_NAME = 'report_export_branding' LIMIT 1"
        );
        if (!$tableExists || $tableExists->num_rows === 0) {
            return $branding;
        }

        $statement = $conn->prepare(
            'SELECT primary_color, accent_color, header_text_color, footer_line_one, footer_line_two, use_church_logo '
            . 'FROM report_export_branding WHERE church_id = ? AND is_active = 1 LIMIT 1'
        );
        $statement->bind_param('i', $churchId);
        $statement->execute();
        $configured = $statement->get_result()->fetch_assoc();
        $statement->close();
        if ($configured) {
            $branding['primary_color'] = report_export_branding_hex($configured['primary_color'], $branding['primary_color']);
            $branding['accent_color'] = report_export_branding_hex($configured['accent_color'], $branding['accent_color']);
            $branding['header_text_color'] = report_export_branding_hex($configured['header_text_color'], $branding['header_text_color']);
            $branding['footer_line_one'] = trim((string) $configured['footer_line_one']) ?: $branding['footer_line_one'];
            $branding['footer_line_two'] = trim((string) $configured['footer_line_two']) ?: $branding['footer_line_two'];
            if (!(int) $configured['use_church_logo']) {
                $branding['logo_url'] = '';
                $branding['logo_data_uri'] = '';
            }
        }
    } catch (Throwable $exception) {
        error_log('Report export branding fallback: ' . $exception->getMessage());
    }
    return $branding;
}

function report_export_branding_css(array $branding): string
{
    $primary = report_export_branding_hex($branding['primary_color'] ?? '', '#173F5F');
    $accent = report_export_branding_hex($branding['accent_color'] ?? '', '#236B45');
    $text = report_export_branding_hex($branding['header_text_color'] ?? '', '#FFFFFF');
    return ".mf-report-header{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:3px solid {$accent};padding-bottom:12px;margin-bottom:18px}"
        . ".mf-report-brand{display:flex;align-items:center;gap:10px}.mf-report-logo{display:block;max-height:58px;max-width:80px}"
        . ".mf-report-title{text-align:right;font-size:22px;font-style:italic;font-weight:700;color:{$primary};margin:0}"
        . "table thead th{background:{$primary};color:{$text}}table tbody tr:nth-child(even) td{background:#F3F7F9}"
        . ".mf-report-footer{text-align:center;color:{$accent};margin-top:20px;font-size:10px;line-height:1.5}"
        . "@media print{.mf-report-header{break-after:avoid}.mf-report-footer{break-inside:avoid}}";
}

function report_export_branding_header_html(array $branding, string $title, string $subtitle = ''): string
{
    $logo = !empty($branding['logo_url'])
        ? '<img class="mf-report-logo" src="' . htmlspecialchars($branding['logo_url'], ENT_QUOTES, 'UTF-8') . '" alt="Church logo">'
        : '';
    return '<div class="mf-report-header"><div class="mf-report-brand">' . $logo . '<div><strong>'
        . htmlspecialchars((string) $branding['church_name'], ENT_QUOTES, 'UTF-8') . '</strong>'
        . ($subtitle !== '' ? '<div>' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</div>' : '')
        . '</div></div><h1 class="mf-report-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1></div>';
}

function report_export_branding_footer_html(array $branding): string
{
    return '<div class="mf-report-footer"><strong>'
        . htmlspecialchars((string) $branding['footer_line_one'], ENT_QUOTES, 'UTF-8')
        . '</strong><br>' . htmlspecialchars((string) $branding['footer_line_two'], ENT_QUOTES, 'UTF-8') . '</div>';
}
