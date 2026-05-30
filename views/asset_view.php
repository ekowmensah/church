<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

if (!asset_is_super_admin() && !has_permission('view_asset_detail') && !has_permission('view_asset_register')) {
    asset_require_permission('view_asset_detail');
}

$isSuper = asset_is_super_admin();
$hasLifecycle = asset_can_use_lifecycle($conn);
$hasMaintenanceFields = asset_can_use_maintenance_fields($conn);
$canTransfer = $isSuper || has_permission('transfer_asset');
$canEdit = $isSuper || has_permission('edit_asset');
$canUploadDoc = $isSuper || has_permission('upload_asset_document');
$canDeleteDoc = $isSuper || has_permission('delete_asset_document');
$canDownloadDoc = $isSuper || has_permission('download_asset_document');
$canRequestApproval = $isSuper || has_permission('request_asset_approval') || has_permission('approve_asset_request');
$canApprove = asset_user_can_approve_requests();

$assetId = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($assetId <= 0) {
    header('Location: asset_list.php');
    exit;
}

$tab = trim((string) ($_GET['tab'] ?? 'overview'));
if (!in_array($tab, ['overview', 'movements', 'audit', 'financial', 'documents', 'approvals'], true)) {
    $tab = 'overview';
}

$scopeChurchId = $isSuper ? null : asset_current_church_id($conn);
$sql = "
    SELECT a.*, d.name AS department_name, c.name AS church_name
    FROM assets a
    LEFT JOIN asset_departments d ON d.id = a.department_id
    LEFT JOIN churches c ON c.id = a.church_id
    WHERE a.id = ?
";
if (!$isSuper) {
    $sql .= ' AND a.church_id = ?';
}
$sql .= ' LIMIT 1';

$stmt = $conn->prepare($sql);
if ($isSuper) {
    $stmt->bind_param('i', $assetId);
} else {
    $stmt->bind_param('ii', $assetId, $scopeChurchId);
}
$stmt->execute();
$asset = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$asset) {
    http_response_code(404);
    exit('Asset not found.');
}

$churchId = (int) $asset['church_id'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['asset_action'] ?? ''));
    $assetIdPost = (int) ($_POST['id'] ?? 0);
    if ($assetIdPost !== $assetId) {
        $error = 'Invalid asset request.';
    } else {
        if ($action === 'upload_document') {
            if (!$canUploadDoc) {
                $error = 'You do not have permission to upload documents.';
            } elseif (!asset_table_exists($conn, 'asset_documents')) {
                $error = 'Documents table not found. Run phase 2-5 patch first.';
            } elseif (!isset($_FILES['asset_document']) || (int) ($_FILES['asset_document']['error'] ?? 1) !== 0) {
                $error = 'Please choose a valid file.';
            } else {
                $docCategory = trim((string) ($_POST['doc_category'] ?? 'other'));
                $categories = array_keys(asset_document_categories());
                if (!in_array($docCategory, $categories, true)) {
                    $docCategory = 'other';
                }

                $tmpName = (string) $_FILES['asset_document']['tmp_name'];
                $origName = trim((string) ($_FILES['asset_document']['name'] ?? 'document'));
                $size = (int) ($_FILES['asset_document']['size'] ?? 0);
                $maxSize = 8 * 1024 * 1024;

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
                if ($finfo) {
                    finfo_close($finfo);
                }
                if ($mime === '') {
                    $mime = 'application/octet-stream';
                }

                if ($size <= 0 || $size > $maxSize) {
                    $error = 'Document size must be between 1 byte and 8MB.';
                } elseif (!in_array($mime, asset_document_allowed_mimes(), true)) {
                    $error = 'File type is not allowed.';
                } elseif (!asset_ensure_documents_dir()) {
                    $error = 'Unable to prepare document storage directory.';
                } else {
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
                    if ($safeName === '' || $safeName === null) {
                        $safeName = 'document';
                    }
                    $stored = 'asset_' . $assetId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . ($ext !== '' ? '.' . $ext : '');
                    $target = asset_documents_upload_dir() . DIRECTORY_SEPARATOR . $stored;

                    if (!move_uploaded_file($tmpName, $target)) {
                        $error = 'Failed to store uploaded file.';
                    } else {
                        $uploadedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
                        $stmt = $conn->prepare(
                            'INSERT INTO asset_documents (church_id, asset_id, doc_category, file_name, stored_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->bind_param('iissssii', $churchId, $assetId, $docCategory, $safeName, $stored, $mime, $size, $uploadedBy);
                        $ok = $stmt->execute();
                        $docId = (int) $conn->insert_id;
                        $stmt->close();

                        if (!$ok) {
                            @unlink($target);
                            $error = 'Failed to save document record.';
                        } else {
                            asset_log_action('asset_document_upload', 'asset_document', $docId, [
                                'asset_id' => $assetId,
                                'asset_code' => $asset['asset_code'],
                                'church_id' => $churchId,
                                'file_name' => $safeName,
                                'doc_category' => $docCategory,
                            ]);
                            header('Location: asset_view.php?id=' . $assetId . '&tab=documents&doc_saved=1');
                            exit;
                        }
                    }
                }
            }
        } elseif ($action === 'request_transfer' || $action === 'request_status_change' || $action === 'request_dispose') {
            if (!$canRequestApproval) {
                $error = 'You do not have permission to submit approval requests.';
            } elseif (!asset_table_exists($conn, 'asset_approval_requests')) {
                $error = 'Approval workflow table not found. Run phase 2-5 patch first.';
            } else {
                $requestType = $action === 'request_transfer' ? 'transfer' : ($action === 'request_dispose' ? 'dispose' : 'status_change');
                $payload = [];

                if ($requestType === 'transfer') {
                    $toDepartmentId = (int) ($_POST['to_department_id'] ?? 0);
                    $note = trim((string) ($_POST['request_note'] ?? ''));
                    if ($toDepartmentId <= 0 || $toDepartmentId === (int) ($asset['department_id'] ?? 0)) {
                        $error = 'Select a valid destination department.';
                    } else {
                        $payload = [
                            'from_department_id' => (int) ($asset['department_id'] ?? 0),
                            'to_department_id' => $toDepartmentId,
                            'note' => $note,
                        ];
                    }
                } elseif ($requestType === 'dispose') {
                    $note = trim((string) ($_POST['request_note'] ?? ''));
                    $payload = [
                        'new_status' => 'disposed',
                        'new_lifecycle_status' => 'disposed',
                        'note' => $note,
                    ];
                } else {
                    $newStatus = trim((string) ($_POST['new_status'] ?? 'active'));
                    $newLifecycle = trim((string) ($_POST['new_lifecycle_status'] ?? 'in_use'));
                    $note = trim((string) ($_POST['request_note'] ?? ''));
                    if (!in_array($newStatus, ['active', 'disposed'], true)) {
                        $error = 'Invalid status selected.';
                    } elseif ($hasLifecycle && !in_array($newLifecycle, asset_lifecycle_options(), true)) {
                        $error = 'Invalid lifecycle selected.';
                    } else {
                        $payload = [
                            'new_status' => $newStatus,
                            'new_lifecycle_status' => $hasLifecycle ? $newLifecycle : asset_default_lifecycle($newStatus, (string) ($asset['condition_status'] ?? 'Good')),
                            'note' => $note,
                        ];
                    }
                }

                if ($error === '') {
                    $requestId = asset_create_approval_request($conn, $churchId, $assetId, $requestType, $payload);
                    asset_log_action('asset_approval_requested', 'asset_approval_request', $requestId, [
                        'asset_id' => $assetId,
                        'asset_code' => $asset['asset_code'],
                        'church_id' => $churchId,
                        'request_type' => $requestType,
                    ], [], $payload);
                    header('Location: asset_view.php?id=' . $assetId . '&tab=approvals&request_saved=1');
                    exit;
                }
            }
        }
    }
}

$movements = [];
$documents = [];
$auditRows = [];
$approvalRows = [];
$departments = asset_fetch_departments($conn, $churchId, false);

if ($tab === 'movements' || $tab === 'overview') {
    $stmt = $conn->prepare("
        SELECT am.*, d1.name AS from_department_name, d2.name AS to_department_name, u.name AS moved_by_name
        FROM asset_movements am
        LEFT JOIN asset_departments d1 ON d1.id = am.from_department_id
        LEFT JOIN asset_departments d2 ON d2.id = am.to_department_id
        LEFT JOIN users u ON u.id = am.moved_by
        WHERE am.asset_id = ?
        ORDER BY am.moved_at DESC
        LIMIT 50
    ");
    $stmt->bind_param('i', $assetId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $movements[] = $row;
    }
    $stmt->close();
}

if (($tab === 'documents' || $tab === 'overview') && asset_table_exists($conn, 'asset_documents')) {
    $stmt = $conn->prepare("
        SELECT ad.*, u.name AS uploaded_by_name
        FROM asset_documents ad
        LEFT JOIN users u ON u.id = ad.uploaded_by
        WHERE ad.asset_id = ? AND ad.is_active = 1
        ORDER BY ad.uploaded_at DESC
    ");
    $stmt->bind_param('i', $assetId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $documents[] = $row;
    }
    $stmt->close();
}

if (($tab === 'audit' || $tab === 'overview') && asset_table_exists($conn, 'asset_audit_log')) {
    $stmt = $conn->prepare("
        SELECT aal.*, u.name AS performed_by_name
        FROM asset_audit_log aal
        LEFT JOIN users u ON u.id = aal.performed_by
        WHERE aal.asset_id = ?
        ORDER BY aal.performed_at DESC
        LIMIT 100
    ");
    $stmt->bind_param('i', $assetId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $auditRows[] = $row;
    }
    $stmt->close();
}

if (($tab === 'approvals' || $tab === 'overview') && asset_table_exists($conn, 'asset_approval_requests')) {
    $stmt = $conn->prepare("
        SELECT aar.*, u1.name AS requested_by_name, u2.name AS reviewed_by_name
        FROM asset_approval_requests aar
        LEFT JOIN users u1 ON u1.id = aar.requested_by
        LEFT JOIN users u2 ON u2.id = aar.reviewed_by
        WHERE aar.asset_id = ?
        ORDER BY aar.requested_at DESC
        LIMIT 100
    ");
    $stmt->bind_param('i', $assetId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $approvalRows[] = $row;
    }
    $stmt->close();
}

$purchaseAmount = $asset['amount'] !== null ? (float) $asset['amount'] : 0.0;
$bookValue = $purchaseAmount;
$depreciationRate = 0.2;
if (!empty($asset['purchase_date']) && $purchaseAmount > 0) {
    $purchaseTs = strtotime((string) $asset['purchase_date']);
    if ($purchaseTs !== false) {
        $years = max(0, floor((time() - $purchaseTs) / (365 * 24 * 3600)));
        $bookValue = max(0, $purchaseAmount * (1 - min(1, $years * $depreciationRate)));
    }
}

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-1"><i class="fas fa-cube mr-2"></i>Asset Workspace</h2>
            <small class="text-muted"><?= htmlspecialchars((string) $asset['asset_code']) ?> - <?= htmlspecialchars((string) $asset['item_name']) ?></small>
        </div>
        <div>
            <a href="asset_list.php<?= $churchId ? '?church_id=' . $churchId : '' ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i> Back</a>
            <?php if ($canEdit): ?>
                <a href="asset_form.php?id=<?= $assetId ?>" class="btn btn-warning ml-1"><i class="fas fa-edit mr-1"></i> Edit</a>
            <?php endif; ?>
            <?php if ($canTransfer): ?>
                <a href="asset_transfer.php?id=<?= $assetId ?>" class="btn btn-primary ml-1"><i class="fas fa-exchange-alt mr-1"></i> Transfer</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['doc_saved'])): ?><div class="alert alert-success">Document uploaded successfully.</div><?php endif; ?>
    <?php if (isset($_GET['request_saved'])): ?><div class="alert alert-success">Approval request submitted.</div><?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <ul class="nav nav-pills">
                <?php
                $tabs = [
                    'overview' => 'Overview',
                    'movements' => 'Movements',
                    'audit' => 'Audit',
                    'financial' => 'Financial',
                    'documents' => 'Documents',
                    'approvals' => 'Approvals',
                ];
                foreach ($tabs as $key => $label):
                ?>
                    <li class="nav-item mr-2 mb-2">
                        <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="asset_view.php?id=<?= $assetId ?>&tab=<?= urlencode($key) ?>"><?= htmlspecialchars($label) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <?php if ($tab === 'overview'): ?>
        <div class="row">
            <div class="col-lg-7 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header"><strong>Asset Profile</strong></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-2"><strong>Code:</strong> <?= htmlspecialchars((string) $asset['asset_code']) ?></div>
                            <div class="col-md-6 mb-2"><strong>Department:</strong> <?= htmlspecialchars((string) ($asset['department_name'] ?? '-')) ?></div>
                            <div class="col-md-6 mb-2"><strong>Condition:</strong> <?= htmlspecialchars((string) ($asset['condition_status'] ?? '-')) ?></div>
                            <div class="col-md-6 mb-2"><strong>Status:</strong> <?= htmlspecialchars((string) ($asset['status'] ?? '-')) ?></div>
                            <?php if ($hasLifecycle): ?>
                                <div class="col-md-6 mb-2"><strong>Lifecycle:</strong> <?= htmlspecialchars(asset_lifecycle_label((string) ($asset['lifecycle_status'] ?? asset_default_lifecycle((string) $asset['status'], (string) $asset['condition_status'])))) ?></div>
                            <?php endif; ?>
                            <div class="col-md-6 mb-2"><strong>Qty:</strong> <?= (int) ($asset['quantity'] ?? 0) ?></div>
                            <div class="col-md-6 mb-2"><strong>Purchase Date:</strong> <?= htmlspecialchars((string) ($asset['purchase_date'] ?? '-')) ?></div>
                            <div class="col-md-6 mb-2"><strong>Amount:</strong> <?= $asset['amount'] !== null ? number_format((float) $asset['amount'], 2) : '-' ?></div>
                            <?php if ($hasMaintenanceFields): ?>
                                <div class="col-md-6 mb-2"><strong>Next Maintenance:</strong> <?= htmlspecialchars((string) ($asset['next_maintenance_date'] ?? '-')) ?></div>
                                <div class="col-md-6 mb-2"><strong>Warranty Expiry:</strong> <?= htmlspecialchars((string) ($asset['warranty_expiry_date'] ?? '-')) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 mb-3">
                <div class="card shadow-sm h-100">
                    <div class="card-header"><strong>Quick Actions</strong></div>
                    <div class="card-body">
                        <?php if ($canRequestApproval): ?>
                            <form method="post" class="mb-3">
                                <input type="hidden" name="id" value="<?= $assetId ?>">
                                <input type="hidden" name="asset_action" value="request_dispose">
                                <div class="form-group">
                                    <label>Dispose Request Note</label>
                                    <input type="text" name="request_note" class="form-control" maxlength="255" placeholder="Reason for disposal">
                                </div>
                                <button type="submit" class="btn btn-outline-danger btn-sm">Request Disposal</button>
                            </form>

                            <form method="post">
                                <input type="hidden" name="id" value="<?= $assetId ?>">
                                <input type="hidden" name="asset_action" value="request_status_change">
                                <div class="form-group">
                                    <label>Request Status Change</label>
                                    <select name="new_status" class="form-control mb-2">
                                        <option value="active">Active</option>
                                        <option value="disposed">Disposed</option>
                                    </select>
                                    <?php if ($hasLifecycle): ?>
                                        <select name="new_lifecycle_status" class="form-control mb-2">
                                            <?php foreach (asset_lifecycle_options() as $opt): ?>
                                                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars(asset_lifecycle_label($opt)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                    <input type="text" name="request_note" class="form-control" maxlength="255" placeholder="Reason for status change">
                                </div>
                                <button type="submit" class="btn btn-outline-primary btn-sm">Submit Status Request</button>
                            </form>
                        <?php else: ?>
                            <div class="text-muted">No approval actions available for your role.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($tab === 'movements'): ?>
        <div class="card shadow-sm">
            <div class="card-body table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="thead-light">
                        <tr><th>Moved At</th><th>From</th><th>To</th><th>By</th><th>Notes</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $m['moved_at']) ?></td>
                                <td><?= htmlspecialchars((string) ($m['from_department_name'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string) ($m['to_department_name'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string) ($m['moved_by_name'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string) ($m['notes'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($movements)): ?><tr><td colspan="5" class="text-center">No movement records.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ($tab === 'audit'): ?>
        <div class="card shadow-sm">
            <div class="card-body table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="thead-light">
                        <tr><th>When</th><th>Action</th><th>By</th><th>Before</th><th>After</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auditRows as $a): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($a['performed_at'] ?? '')) ?></td>
                                <td><span class="badge badge-info"><?= htmlspecialchars((string) ($a['action'] ?? '')) ?></span></td>
                                <td><?= htmlspecialchars((string) ($a['performed_by_name'] ?? '-')) ?></td>
                                <td><pre class="mb-0" style="font-size:11px;max-width:300px;white-space:pre-wrap;"><?= htmlspecialchars((string) ($a['before_json'] ?? '')) ?></pre></td>
                                <td><pre class="mb-0" style="font-size:11px;max-width:300px;white-space:pre-wrap;"><?= htmlspecialchars((string) ($a['after_json'] ?? '')) ?></pre></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($auditRows)): ?><tr><td colspan="5" class="text-center">No audit records.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ($tab === 'financial'): ?>
        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="card shadow-sm"><div class="card-body"><div class="text-muted">Purchase Amount</div><div class="h4 mb-0"><?= number_format($purchaseAmount, 2) ?></div></div></div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card shadow-sm"><div class="card-body"><div class="text-muted">Estimated Book Value</div><div class="h4 mb-0"><?= number_format($bookValue, 2) ?></div></div></div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card shadow-sm"><div class="card-body"><div class="text-muted">Quantity</div><div class="h4 mb-0"><?= (int) ($asset['quantity'] ?? 0) ?></div></div></div>
            </div>
        </div>
    <?php elseif ($tab === 'documents'): ?>
        <div class="row">
            <div class="col-lg-4 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header"><strong>Upload Document</strong></div>
                    <div class="card-body">
                        <?php if ($canUploadDoc && asset_table_exists($conn, 'asset_documents')): ?>
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="id" value="<?= $assetId ?>">
                                <input type="hidden" name="asset_action" value="upload_document">
                                <div class="form-group">
                                    <label>Category</label>
                                    <select name="doc_category" class="form-control">
                                        <?php foreach (asset_document_categories() as $catKey => $catLabel): ?>
                                            <option value="<?= htmlspecialchars($catKey) ?>"><?= htmlspecialchars($catLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>File</label>
                                    <input type="file" name="asset_document" class="form-control-file" required>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm">Upload</button>
                            </form>
                            <small class="text-muted d-block mt-2">Allowed: PDF, images, Office docs, TXT. Max 8MB.</small>
                        <?php else: ?>
                            <div class="text-muted">You cannot upload documents.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-8 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header"><strong>Documents</strong></div>
                    <div class="card-body table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="thead-light">
                                <tr><th>When</th><th>Category</th><th>File</th><th>By</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $doc): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $doc['uploaded_at']) ?></td>
                                        <td><?= htmlspecialchars((string) ucfirst((string) $doc['doc_category'])) ?></td>
                                        <td><?= htmlspecialchars((string) $doc['file_name']) ?></td>
                                        <td><?= htmlspecialchars((string) ($doc['uploaded_by_name'] ?? '-')) ?></td>
                                        <td class="text-nowrap">
                                            <?php if ($canDownloadDoc): ?>
                                                <a class="btn btn-sm btn-outline-primary" href="asset_document_download.php?id=<?= (int) $doc['id'] ?>"><i class="fas fa-download"></i></a>
                                            <?php endif; ?>
                                            <?php if ($canDeleteDoc): ?>
                                                <a class="btn btn-sm btn-outline-danger" href="asset_document_delete.php?id=<?= (int) $doc['id'] ?>&asset_id=<?= $assetId ?>" onclick="return confirm('Delete document?');"><i class="fas fa-trash"></i></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($documents)): ?><tr><td colspan="5" class="text-center">No documents uploaded.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($tab === 'approvals'): ?>
        <div class="row">
            <div class="col-lg-4 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header"><strong>New Transfer Request</strong></div>
                    <div class="card-body">
                        <?php if ($canRequestApproval): ?>
                            <form method="post">
                                <input type="hidden" name="id" value="<?= $assetId ?>">
                                <input type="hidden" name="asset_action" value="request_transfer">
                                <div class="form-group">
                                    <label>To Department</label>
                                    <select name="to_department_id" class="form-control" required>
                                        <option value="">-- Select Department --</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <?php if ((int) $dept['id'] === (int) ($asset['department_id'] ?? 0)) continue; ?>
                                            <option value="<?= (int) $dept['id'] ?>"><?= htmlspecialchars((string) $dept['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Note</label>
                                    <input type="text" name="request_note" class="form-control" maxlength="255" placeholder="Reason for transfer">
                                </div>
                                <button type="submit" class="btn btn-outline-primary btn-sm">Submit Request</button>
                            </form>
                        <?php else: ?>
                            <div class="text-muted">No permission to submit requests.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-8 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header"><strong>Request History</strong></div>
                    <div class="card-body table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="thead-light">
                                <tr><th>When</th><th>Type</th><th>Status</th><th>Requested By</th><th>Reviewed By</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($approvalRows as $req): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $req['requested_at']) ?></td>
                                        <td><?= htmlspecialchars((string) ucfirst((string) $req['request_type'])) ?></td>
                                        <td><span class="badge badge-<?= $req['status'] === 'approved' ? 'success' : ($req['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= htmlspecialchars((string) $req['status']) ?></span></td>
                                        <td><?= htmlspecialchars((string) ($req['requested_by_name'] ?? '-')) ?></td>
                                        <td><?= htmlspecialchars((string) ($req['reviewed_by_name'] ?? '-')) ?></td>
                                        <td class="text-nowrap">
                                            <?php if ($canApprove && (string) $req['status'] === 'pending'): ?>
                                                <a href="asset_approval_action.php?id=<?= (int) $req['id'] ?>&decision=approve" class="btn btn-sm btn-success" onclick="return confirm('Approve this request?');">Approve</a>
                                                <a href="asset_approval_action.php?id=<?= (int) $req['id'] ?>&decision=reject" class="btn btn-sm btn-danger" onclick="return confirm('Reject this request?');">Reject</a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($approvalRows)): ?><tr><td colspan="6" class="text-center">No requests found.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
