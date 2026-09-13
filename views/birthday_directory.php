<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../services/BirthdayDirectoryService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!is_super_admin() && !has_permission('view_birthdays')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$bucket = (string) ($_GET['group'] ?? 'today');
if (!in_array($bucket, ['yesterday', 'today', 'tomorrow', 'month'], true)) $bucket = 'today';
$service = BirthdayDirectoryService::fromSession($conn);
$summary = $service->getSummary();
$members = $service->getMembers($bucket);
$labels = [
    'yesterday' => "Yesterday's Birthdays",
    'today' => "Today's Birthdays",
    'tomorrow' => "Tomorrow's Birthdays",
    'month' => 'Birthdays This Month',
];
$icons = [
    'yesterday' => 'fas fa-history',
    'today' => 'fas fa-birthday-cake',
    'tomorrow' => 'fas fa-calendar-day',
    'month' => 'fas fa-calendar-alt',
];

$page_title = 'Birthday Directory';
ob_start();
?>
<style>
.birthday-page{background:#f6f7fb;min-height:calc(100vh - 70px);padding:1rem 0 2rem}.birthday-hero{background:linear-gradient(135deg,#6d28d9,#db2777);color:#fff;border-radius:16px;padding:1.35rem 1.5rem;box-shadow:0 10px 25px rgba(109,40,217,.2)}.birthday-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem}.birthday-summary-card{display:block;background:#fff;border:1px solid rgba(30,41,59,.08);border-radius:14px;padding:1rem;color:#1e293b;box-shadow:0 5px 16px rgba(30,41,59,.07);transition:.15s}.birthday-summary-card:hover,.birthday-summary-card.active{color:#6d28d9;text-decoration:none;transform:translateY(-2px);border-color:#a78bfa}.birthday-summary-card .count{font-size:1.75rem;font-weight:800}.birthday-member{border:0;border-radius:14px;box-shadow:0 5px 16px rgba(30,41,59,.08)}.birthday-member .card-body{min-width:0}.birthday-photo{width:72px;height:72px;flex:0 0 72px;border-radius:50%;object-fit:cover;background:#ede9fe;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.13)}.birthday-avatar{display:flex;align-items:center;justify-content:center;color:#6d28d9;font-size:1.4rem;font-weight:800}.birthday-details{min-width:0}.birthday-details h5,.birthday-meta div{overflow-wrap:anywhere}.birthday-meta{color:#64748b;font-size:.9rem}.birthday-age{flex:0 0 auto;background:#fce7f3;color:#9d174d;border-radius:999px;padding:.3rem .65rem;font-weight:700}@media(max-width:767.98px){.birthday-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:419.98px){.birthday-summary{grid-template-columns:1fr}.birthday-photo{width:58px;height:58px;flex-basis:58px}.birthday-member .card-body{padding:1rem}}
</style>
<div class="birthday-page"><div class="container-fluid">
  <div class="birthday-hero mb-4 d-flex flex-wrap justify-content-between align-items-center">
    <div><h2 class="mb-1"><i class="fas fa-birthday-cake mr-2"></i>Birthday Directory</h2><div>Permission-scoped birthday information for the members you are authorized to serve.</div></div>
    <?php if (is_super_admin() || has_permission('send_sms')): ?><a class="btn btn-light btn-sm mt-2 mt-md-0" href="birthday_sms_manager.php"><i class="fas fa-sms mr-1"></i>Birthday Messages</a><?php endif; ?>
  </div>

  <div class="birthday-summary mb-4">
    <?php foreach ($labels as $key => $label): ?>
      <a class="birthday-summary-card <?= $bucket === $key ? 'active' : '' ?>" href="?group=<?= urlencode($key) ?>">
        <div class="small text-uppercase font-weight-bold mb-1"><i class="<?= $icons[$key] ?> mr-1"></i><?= htmlspecialchars($label) ?></div>
        <div class="count"><?= number_format((int) $summary[$key]) ?></div>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0"><?= htmlspecialchars($labels[$bucket]) ?></h4><span class="badge badge-primary px-3 py-2"><?= number_format(count($members)) ?> member<?= count($members) === 1 ? '' : 's' ?></span></div>
  <?php if (!$members): ?>
    <div class="card birthday-member"><div class="card-body text-center text-muted py-5"><i class="fas fa-birthday-cake fa-3x mb-3"></i><h5>No birthdays in this group</h5><p class="mb-0">Only active members within your authorized Bible Class, organization, group, or church scope are included.</p></div></div>
  <?php else: ?>
    <div class="row">
      <?php foreach ($members as $member):
        $photo = trim((string) $member['photo']);
        $photoUrl = $photo !== '' && file_exists(__DIR__ . '/../uploads/members/' . $photo)
            ? BASE_URL . '/uploads/members/' . rawurlencode($photo)
            : '';
        $initials = strtoupper(substr((string) $member['full_name'], 0, 1));
      ?>
        <div class="col-xl-4 col-md-6 mb-3"><div class="card birthday-member h-100"><div class="card-body d-flex">
          <?php if ($photoUrl): ?><img class="birthday-photo mr-3" src="<?= htmlspecialchars($photoUrl) ?>" alt="<?= htmlspecialchars($member['full_name']) ?> profile picture"><?php else: ?><div class="birthday-photo birthday-avatar mr-3" aria-hidden="true"><?= htmlspecialchars($initials ?: '?') ?></div><?php endif; ?>
          <div class="birthday-details flex-grow-1"><div class="d-flex justify-content-between align-items-start"><h5 class="mb-1 mr-2"><?= htmlspecialchars($member['full_name']) ?></h5><span class="birthday-age"><?= (int) $member['current_age'] ?> yrs</span></div>
            <div class="birthday-meta"><div><i class="far fa-calendar mr-1"></i><?= date('F j, Y', strtotime($member['dob'])) ?></div><div><i class="fas fa-id-card mr-1"></i><?= htmlspecialchars((string) ($member['crn'] ?: 'No CRN')) ?></div><div><i class="fas fa-book-open mr-1"></i><?= htmlspecialchars((string) ($member['class_name'] ?: 'No Bible Class')) ?></div><div><i class="fas fa-users mr-1"></i><?= htmlspecialchars((string) ($member['organizations'] ?: 'No organization')) ?></div></div>
          </div>
        </div></div></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div></div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
