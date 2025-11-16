<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/member_auth.php';

if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$member_id = intval($_SESSION['member_id']);

// Fetch all organizations
$orgs = [];
$res = $conn->query('SELECT id, name FROM organizations ORDER BY name');
while ($row = $res->fetch_assoc()) $orgs[] = $row;

// Fetch joined orgs
$joined = [];
$stmt = $conn->prepare('SELECT organization_id FROM member_organizations WHERE member_id = ?');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $joined[] = $row['organization_id'];
$stmt->close();

// Fetch pending/rejected org requests
$pending = $rejected = [];
$stmt = $conn->prepare('SELECT organization_id, status FROM organization_membership_approvals WHERE member_id = ? AND status IN ("pending","rejected")');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if ($row['status'] === 'pending') $pending[] = $row['organization_id'];
    if ($row['status'] === 'rejected') $rejected[] = $row['organization_id'];
}
$stmt->close();

$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['organizations'])) {
    $selected = array_map('intval', $_POST['organizations']);
    $inserted = 0;
    foreach ($selected as $org_id) {
        if (in_array($org_id, $joined) || in_array($org_id, $pending)) continue;
        $stmt = $conn->prepare('INSERT INTO organization_membership_approvals (member_id, organization_id, status, requested_at) VALUES (?, ?, "pending", NOW())');
        $stmt->bind_param('ii', $member_id, $org_id);
        if ($stmt->execute()) $inserted++;
        $stmt->close();
    }
    if ($inserted > 0) {
        $success = "Your request(s) have been submitted for approval.";
    } else {
        $error = "No new requests submitted. You may have already requested or joined these organizations.";
    }
    // Refresh pending/joined lists
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

ob_start();
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow">
                <div class="card-header bg-info text-white">
                    <i class="fas fa-users-cog mr-2"></i>Join Organization(s)
                </div>
                <div class="card-body">
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <?php elseif ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <div class="form-group">
                            <label for="organizations">Select Organizations to Join:</label>
                            <div class="row">
                                <?php foreach ($orgs as $org):
                                    $disabled = in_array($org['id'], $joined) || in_array($org['id'], $pending);
                                    $status = in_array($org['id'], $joined) ? '<span class=\'badge badge-success ml-2\'>Joined</span>' : (in_array($org['id'], $pending) ? '<span class=\'badge badge-warning ml-2\'>Pending</span>' : (in_array($org['id'], $rejected) ? '<span class=\'badge badge-danger ml-2\'>Rejected</span>' : ''));
                                ?>
                                <div class="col-md-6 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="organizations[]" value="<?= $org['id'] ?>" id="org<?= $org['id'] ?>" <?= $disabled ? 'disabled' : '' ?>>
                                        <label class="form-check-label" for="org<?= $org['id'] ?>">
                                            <?= htmlspecialchars($org['name']) ?> <?= $status ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-info">Submit Request</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
