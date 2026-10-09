<?php

if (!function_exists('asset_workspace_url')) {
    function asset_workspace_url(string $path, ?int $churchId = null): string
    {
        return $path . ($churchId ? '?church_id=' . $churchId : '');
    }
}

if (!function_exists('render_asset_workspace_nav')) {
    function render_asset_workspace_nav(string $active, ?int $churchId = null): void
    {
        $super = asset_is_super_admin();
        $items = [
            ['register', 'asset_list.php', 'fa-boxes', 'Register', $super || has_permission('view_asset_register')],
            ['custody', 'asset_request_list.php', 'fa-hand-holding', 'Lending & Returns', isset($_SESSION['member_id']) || asset_user_can_view_use_requests()],
            ['approvals', 'asset_approval_list.php', 'fa-clipboard-check', 'Approval Queue', $super || has_permission('approve_asset_request') || has_permission('request_asset_approval') || has_permission('approve_asset_use_request')],
            ['movements', 'asset_movement_list.php', 'fa-exchange-alt', 'Movements', $super || has_permission('view_asset_movements')],
            ['maintenance', 'asset_maintenance_list.php', 'fa-tools', 'Maintenance', $super || has_permission('view_asset_maintenance') || has_permission('manage_asset_maintenance')],
            ['reports', 'asset_reports.php', 'fa-chart-line', 'Reports', $super || has_permission('view_asset_reports')],
            ['audit', 'asset_audit_list.php', 'fa-shield-alt', 'Audit', $super || has_permission('view_asset_audit')],
        ];
        ?>
        <nav class="asset-workspace-nav" aria-label="Asset lifecycle">
            <?php foreach ($items as [$key, $path, $icon, $label, $visible]): ?>
                <?php if (!$visible) continue; ?>
                <a class="asset-workspace-link <?= $active === $key ? 'is-active' : '' ?>"
                   href="<?= htmlspecialchars(asset_workspace_url($path, $churchId), ENT_QUOTES, 'UTF-8') ?>"
                   <?= $active === $key ? 'aria-current="page"' : '' ?>>
                    <i class="fas <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i>
                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }
}

if (!function_exists('render_asset_workspace_hero')) {
    function render_asset_workspace_hero(
        string $eyebrow,
        string $title,
        string $description,
        string $icon = 'fa-boxes',
        string $actionsHtml = ''
    ): void {
        ?>
        <section class="asset-hero p-4 mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center">
                <div>
                    <div class="eyebrow"><?= htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8') ?></div>
                    <h2 class="mb-1"><i class="fas <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> mr-2"></i><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="mb-0"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <?php if ($actionsHtml !== ''): ?><div class="asset-hero-actions mt-3 mt-md-0"><?= $actionsHtml ?></div><?php endif; ?>
            </div>
        </section>
        <?php
    }
}

