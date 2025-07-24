<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Check authentication
if (!is_logged_in()) {
    header('Location: /myfreeman/login.php');
    exit;
}

// Permission check with super admin bypass
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) {
    // Super admin access
} elseif (!has_permission('manage_menu_items')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/../views/errors/403.php')) {
        include __DIR__.'/../views/errors/403.php';
    } else {
        echo '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>';
    }
    exit;
}

// Fetch menu items
$stmt = $conn->prepare("SELECT * FROM menu_items ORDER BY menu_group, sort_order");
$stmt->execute();
$menu_items = $stmt->get_result();
$stmt->close();

// Fetch permissions for dropdown
$stmt = $conn->prepare("SELECT id, name FROM permissions ORDER BY name");
$stmt->execute();
$permissions = $stmt->get_result();
$stmt->close();

ob_start();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0 text-gray-800"><i class="fas fa-bars mr-2"></i>Menu Management</h1>
        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addMenuModal">
            <i class="fas fa-plus mr-1"></i> Add Menu Item
        </button>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="menuTable" class="table table-bordered table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Label</th>
                            <th>URL</th>
                            <th>Icon</th>
                            <th>Group</th>
                            <th>Permission</th>
                            <th>Sort Order</th>
                            <th>Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($item = $menu_items->fetch_assoc()): ?>
                        <tr>
                            <td><?= $item['id'] ?></td>
                            <td><?= htmlspecialchars($item['label']) ?></td>
                            <td><?= htmlspecialchars($item['url']) ?></td>
                            <td><i class="<?= htmlspecialchars($item['icon']) ?>"></i> <?= htmlspecialchars($item['icon']) ?></td>
                            <td><?= htmlspecialchars($item['menu_group']) ?></td>
                            <td><?= htmlspecialchars($item['permission_required']) ?></td>
                            <td><?= $item['sort_order'] ?></td>
                            <td>
                                <span class="badge badge-<?= $item['is_active'] ? 'success' : 'secondary' ?>">
                                    <?= $item['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-warning edit-btn" 
                                        data-id="<?= $item['id'] ?>"
                                        data-label="<?= htmlspecialchars($item['label']) ?>"
                                        data-url="<?= htmlspecialchars($item['url']) ?>"
                                        data-icon="<?= htmlspecialchars($item['icon']) ?>"
                                        data-group="<?= htmlspecialchars($item['menu_group']) ?>"
                                        data-permission="<?= htmlspecialchars($item['permission_required']) ?>"
                                        data-sort="<?= $item['sort_order'] ?>"
                                        data-active="<?= $item['is_active'] ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-danger delete-btn" data-id="<?= $item['id'] ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Menu Modal -->
<div class="modal fade" id="addMenuModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Menu Item</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="addMenuForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Label</label>
                        <input type="text" class="form-control" name="label" required>
                    </div>
                    <div class="form-group">
                        <label>URL</label>
                        <input type="text" class="form-control" name="url" required>
                    </div>
                    <div class="form-group">
                        <label>Icon (FontAwesome class)</label>
                        <input type="text" class="form-control" name="icon" placeholder="fas fa-home">
                    </div>
                    <div class="form-group">
                        <label>Menu Group</label>
                        <input type="text" class="form-control" name="menu_group" required>
                    </div>
                    <div class="form-group">
                        <label>Permission Required</label>
                        <select class="form-control" name="permission_required">
                            <option value="">No permission required</option>
                            <?php 
                            $permissions->data_seek(0);
                            while ($perm = $permissions->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($perm['name']) ?>"><?= htmlspecialchars($perm['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sort Order</label>
                        <input type="number" class="form-control" name="sort_order" value="0">
                    </div>
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="is_active" value="1" checked>
                            <label class="form-check-label">Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Menu Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Menu Modal -->
<div class="modal fade" id="editMenuModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Menu Item</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form id="editMenuForm">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Label</label>
                        <input type="text" class="form-control" name="label" id="edit_label" required>
                    </div>
                    <div class="form-group">
                        <label>URL</label>
                        <input type="text" class="form-control" name="url" id="edit_url" required>
                    </div>
                    <div class="form-group">
                        <label>Icon (FontAwesome class)</label>
                        <input type="text" class="form-control" name="icon" id="edit_icon">
                    </div>
                    <div class="form-group">
                        <label>Menu Group</label>
                        <input type="text" class="form-control" name="menu_group" id="edit_group" required>
                    </div>
                    <div class="form-group">
                        <label>Permission Required</label>
                        <select class="form-control" name="permission_required" id="edit_permission">
                            <option value="">No permission required</option>
                            <?php 
                            $permissions->data_seek(0);
                            while ($perm = $permissions->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($perm['name']) ?>"><?= htmlspecialchars($perm['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sort Order</label>
                        <input type="number" class="form-control" name="sort_order" id="edit_sort">
                    </div>
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="is_active" id="edit_active" value="1">
                            <label class="form-check-label">Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Menu Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#menuTable').DataTable({
        "order": [[ 4, "asc" ], [ 6, "asc" ]]
    });

    // Add menu form
    $('#addMenuForm').on('submit', function(e) {
        e.preventDefault();
        $.ajax({
            url: '../controllers/menu_api.php',
            method: 'POST',
            data: $(this).serialize() + '&action=create',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert('Menu item added successfully');
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function() {
                alert('Error adding menu item');
            }
        });
    });

    // Edit button click
    $('.edit-btn').on('click', function() {
        $('#edit_id').val($(this).data('id'));
        $('#edit_label').val($(this).data('label'));
        $('#edit_url').val($(this).data('url'));
        $('#edit_icon').val($(this).data('icon'));
        $('#edit_group').val($(this).data('group'));
        $('#edit_permission').val($(this).data('permission'));
        $('#edit_sort').val($(this).data('sort'));
        $('#edit_active').prop('checked', $(this).data('active') == 1);
        $('#editMenuModal').modal('show');
    });

    // Edit menu form
    $('#editMenuForm').on('submit', function(e) {
        e.preventDefault();
        $.ajax({
            url: '../controllers/menu_api.php',
            method: 'POST',
            data: $(this).serialize() + '&action=update',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert('Menu item updated successfully');
                    location.reload();
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function() {
                alert('Error updating menu item');
            }
        });
    });

    // Delete button click
    $('.delete-btn').on('click', function() {
        if (confirm('Are you sure you want to delete this menu item?')) {
            var id = $(this).data('id');
            $.ajax({
                url: '../controllers/menu_api.php',
                method: 'POST',
                data: {action: 'delete', id: id},
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert('Menu item deleted successfully');
                        location.reload();
                    } else {
                        alert('Error: ' + response.message);
                    }
                },
                error: function() {
                    alert('Error deleting menu item');
                }
            });
        }
    });
});
</script>

<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
?>
