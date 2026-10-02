<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/member_auth.php';

if (empty($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$page_title = 'My Service Roles';
$member_id = (int) $_SESSION['member_id'];

$memberStmt = $conn->prepare(
    'SELECT member.first_name, member.middle_name, member.last_name,
            member.crn, member.photo, church.name AS church_name
       FROM members member
       LEFT JOIN churches church ON church.id = member.church_id
      WHERE member.id = ?
      LIMIT 1'
);
$memberStmt->bind_param('i', $member_id);
$memberStmt->execute();
$member = $memberStmt->get_result()->fetch_assoc();
$memberStmt->close();

if (!$member) {
    http_response_code(404);
    exit('Member record not found.');
}

$roleStmt = $conn->prepare(
    'SELECT serving_role.id, serving_role.name, serving_role.description
       FROM member_roles_of_serving member_role
       JOIN roles_of_serving serving_role ON serving_role.id = member_role.role_id
      WHERE member_role.member_id = ?
      ORDER BY CASE WHEN LOWER(TRIM(serving_role.name)) = \'none\' THEN 1 ELSE 0 END,
               serving_role.name'
);
$roleStmt->bind_param('i', $member_id);
$roleStmt->execute();
$roles = $roleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$roleStmt->close();

$memberName = trim(implode(' ', array_filter([
    $member['first_name'] ?? '',
    $member['middle_name'] ?? '',
    $member['last_name'] ?? '',
])));
$photoUrl = null;
$photoName = basename((string) ($member['photo'] ?? ''));
if ($photoName !== '' && file_exists(__DIR__ . '/../uploads/members/' . $photoName)) {
    $photoUrl = BASE_URL . '/uploads/members/' . rawurlencode($photoName);
}

ob_start();
?>
<div class="container-fluid py-3 service-role-page">
    <section class="service-role-hero mb-4">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
            <div class="d-flex align-items-center">
                <div class="service-role-avatar mr-3">
                    <?php if ($photoUrl): ?>
                        <img src="<?= htmlspecialchars($photoUrl) ?>" alt="<?= htmlspecialchars($memberName) ?>">
                    <?php else: ?>
                        <i class="fas fa-user-tie" aria-hidden="true"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="service-role-kicker">Church Life</div>
                    <h1 class="h3 mb-1">My Service Roles</h1>
                    <div class="service-role-subtitle">
                        <?= htmlspecialchars($memberName) ?>
                        <?php if (!empty($member['crn'])): ?>
                            <span class="mx-1">&middot;</span><?= htmlspecialchars($member['crn']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/views/member_profile.php" class="btn btn-light mt-3 mt-md-0">
                <i class="fas fa-user mr-1"></i> View Profile
            </a>
        </div>
    </section>

    <div class="row">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm service-role-card">
                <div class="card-header bg-white border-0 d-flex align-items-center justify-content-between">
                    <div>
                        <h2 class="h5 mb-1">Current assignments</h2>
                        <div class="text-muted small">Roles assigned by authorized church administrators.</div>
                    </div>
                    <span class="badge badge-pill badge-primary px-3 py-2"><?= count($roles) ?></span>
                </div>
                <div class="card-body">
                    <?php if (!$roles): ?>
                        <div class="service-role-empty text-center py-5">
                            <div class="service-role-empty-icon mb-3"><i class="fas fa-id-badge"></i></div>
                            <h3 class="h5">No service role assigned</h3>
                            <p class="text-muted mb-0">You can still use member self-service. Contact the church office if a role should appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($roles as $role): ?>
                                <?php $isNone = strtolower(trim((string) $role['name'])) === 'none'; ?>
                                <div class="col-md-6 mb-3">
                                    <article class="service-role-item h-100 <?= $isNone ? 'service-role-none' : '' ?>">
                                        <div class="service-role-item-icon">
                                            <i class="fas <?= $isNone ? 'fa-minus-circle' : 'fa-hands-helping' ?>"></i>
                                        </div>
                                        <div>
                                            <h3 class="h6 mb-1"><?= htmlspecialchars($isNone ? 'No current office' : $role['name']) ?></h3>
                                            <p class="text-muted small mb-0">
                                                <?= htmlspecialchars(trim((string) ($role['description'] ?? '')) ?: ($isNone
                                                    ? 'No church office or service responsibility is currently assigned.'
                                                    : 'An active Role of Serving assignment.')) ?>
                                            </p>
                                        </div>
                                    </article>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-4 mt-4 mt-xl-0">
            <aside class="card border-0 shadow-sm service-role-card">
                <div class="card-body">
                    <div class="service-role-info-icon mb-3"><i class="fas fa-shield-alt"></i></div>
                    <h2 class="h5">About role assignments</h2>
                    <p class="text-muted small">Service roles describe an office or responsibility within <?= htmlspecialchars($member['church_name'] ?: 'your church') ?>.</p>
                    <p class="text-muted small mb-0">For account safety, this page is read-only. A role can be added or corrected only by authorized staff.</p>
                </div>
            </aside>
        </div>
    </div>
</div>

<style>
.service-role-hero {
    background: linear-gradient(135deg, #173f5f 0%, #236b45 100%);
    border-radius: 18px;
    color: #fff;
    padding: 1.75rem;
    box-shadow: 0 16px 40px rgba(23, 63, 95, .18);
}
.service-role-kicker { font-size: .75rem; font-weight: 700; letter-spacing: .12em; opacity: .75; text-transform: uppercase; }
.service-role-subtitle { color: rgba(255,255,255,.82); }
.service-role-avatar { align-items: center; background: rgba(255,255,255,.16); border: 2px solid rgba(255,255,255,.35); border-radius: 50%; display: flex; height: 72px; justify-content: center; overflow: hidden; width: 72px; }
.service-role-avatar img { height: 100%; object-fit: cover; width: 100%; }
.service-role-avatar i { font-size: 1.8rem; }
.service-role-card { border-radius: 16px; overflow: hidden; }
.service-role-item { align-items: flex-start; background: #f7faf9; border: 1px solid #dcebe4; border-radius: 14px; display: flex; gap: .9rem; padding: 1rem; }
.service-role-item-icon, .service-role-info-icon, .service-role-empty-icon { align-items: center; background: rgba(35,107,69,.12); border-radius: 12px; color: #236b45; display: flex; flex: 0 0 auto; height: 44px; justify-content: center; width: 44px; }
.service-role-none { background: #f8f9fa; border-color: #e4e7ea; }
.service-role-none .service-role-item-icon { background: #e9ecef; color: #6c757d; }
.service-role-info-icon { background: rgba(23,63,95,.1); color: #173f5f; }
.service-role-empty-icon { height: 58px; margin-left: auto; margin-right: auto; width: 58px; }
@media (max-width: 575.98px) {
    .service-role-hero { border-radius: 14px; padding: 1.25rem; }
    .service-role-avatar { height: 58px; width: 58px; }
}
</style>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
