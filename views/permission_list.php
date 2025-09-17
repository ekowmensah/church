<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
ob_start();
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
    <div>
        <h1 class="h4 mb-1 text-primary font-weight-bold"><i class="fas fa-key mr-2"></i>Permissions</h1>
        <small class="text-muted">View and manage all system permissions below.</small>
    </div>
    <button class="btn btn-success mt-2 mt-md-0 shadow-sm" data-toggle="modal" data-target="#addPermissionModal"><i class="fas fa-plus mr-1"></i> Add Permission</button>
</div>
<div class="mb-3">
    <input type="text" class="form-control form-control-lg" id="permissionSearch" placeholder="Search permissions..." onkeyup="filterPermissions()">
</div>
<div class="card shadow mb-4">
    <div class="card-header py-3 bg-gradient-primary text-white d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold"><i class="fas fa-list-ul mr-2"></i>Permission List</h6>
        <span class="badge badge-light text-primary font-weight-bold" id="permissionCount">Loading...</span>
    </div>
    <div class="card-body">
        <div id="permissionTableContainer">
            <div class="text-center py-5" id="loadingState">
                <i class="fas fa-spinner fa-2x fa-spin mb-2"></i><br>
                <span>Loading permissions...</span>
            </div>
            <div class="text-center py-5" id="errorState" style="display: none;">
                <i class="fas fa-exclamation-circle fa-2x mb-2"></i><br>
                <span>Error loading permissions.</span>
            </div>
            <table class="table table-sm table-bordered table-hover mb-0" id="permissionTable">
                <thead class="thead-light">
                    <tr>
                        <th style="width:60px">ID</th>
                        <th>Name</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="permissionTableBody">
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once __DIR__.'/../config/config.php';
$parsed = parse_url(BASE_URL);
$AJAX_BASE = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
?>
<script>
const BASE_URL = "<?= $AJAX_BASE ?>";
    $(document).ready(function() {
        loadPermissions();
        
        // Attach event handlers after DOM is fully loaded (including modals)
        $('#addPermissionBtn').on('click', function() {
            var permissionName = $('#permissionName').val();
            if (!permissionName.trim()) {
                alert('Please enter a permission name');
                return;
            }
            
            $.ajax({
                type: 'POST',
                url: BASE_URL + '/controllers/permission_api.php',
                data: {name: permissionName},
                success: function(data) {
                    loadPermissions();
                    $('#addPermissionModal').modal('hide');
                    $('#addPermissionForm')[0].reset();
                },
                error: function() {
                    alert('Error adding permission');
                }
            });
        });

        $('#editPermissionBtn').on('click', function() {
            var permissionId = $('#editPermissionId').val();
            var permissionName = $('#editPermissionName').val();
            
            if (!permissionName.trim()) {
                alert('Please enter a permission name');
                return;
            }
            
            $.ajax({
                type: 'PUT',
                url: BASE_URL + '/controllers/permission_api.php',
                data: {id: permissionId, name: permissionName},
                success: function(data) {
                    loadPermissions();
                    $('#editPermissionModal').modal('hide');
                },
                error: function() {
                    alert('Error updating permission');
                }
            });
        });

        $('#deletePermissionBtn').on('click', function() {
            var permissionId = $('#deletePermissionId').val();
            $.ajax({
                type: 'DELETE',
                url: BASE_URL + '/controllers/permission_api.php',
                data: {id: permissionId},
                success: function(data) {
                    loadPermissions();
                    $('#deletePermissionModal').modal('hide');
                },
                error: function() {
                    alert('Error deleting permission');
                }
            });
        });
    });

    function loadPermissions() {
        $.ajax({
            type: 'GET',
            url: BASE_URL + '/controllers/permission_api.php',
            dataType: 'json',
            success: function(data) {
                $('#loadingState').hide();
                $('#permissionTableBody').empty();
                $.each(data.permissions, function(index, permission) {
                    $('#permissionTableBody').append('<tr>' +
                        '<td>' + permission.id + '</td>' +
                        '<td>' + permission.name + '</td>' +
                        '<td>' +
                            '<button class="btn btn-sm btn-warning mr-1" onclick="editPermission(' + permission.id + ', \'' + permission.name + '\')">Edit</button>' +
                            '<button class="btn btn-sm btn-danger" onclick="deletePermission(' + permission.id + ')">Delete</button>' +
                        '</td>' +
                    '</tr>');
                });

                // --- Static reference: Ensure these permissions exist in the DB for all report pages ---
                // Main Reports
                const reportPermissions = [
                    'view_attendance',
                    'view_payment',
                    'view_feedback',
                    'view_health',
                    'view_sms_logs',
                    'view_visitor',
                    'view_event',
                    'view_audit',
                    'manage_members', // For membership_report
                    // Detail Reports (suggested convention: view_<report_file>_report)
                    'view_age_bracket_report',
                    'view_organisational_member_report',
                    'view_marital_status_report',
                    'view_employment_status_report',
                    'view_baptism_report',
                    'view_confirmation_report',
                    'view_membership_status_report',
                    'view_date_of_birth_report',
                    'view_role_of_service_report',
                    'view_registered_by_date_report',
                    'view_profession_report',
                    'view_gender_report',
                    'view_class_health_report',
                    'view_health_type_report',
                    'view_individual_health_report',
                    'view_individual_payment_report',
                    'view_organisation_payment_report',
                    'view_organisational_health_report',
                    'view_payment_made_report',
                    'view_day_born_payment_report',
                    'view_bibleclass_payment_report',
                    'view_zero_payment_type_report',
                    'view_accumulated_payment_type_report',
                    'view_payment_types_today',
                    'view_payment_total_today'
                ];
                // Optionally, display these as a reference for admins:
                $('#permissionTableBody').append('<tr class="table-info"><td colspan="3"><strong>Reference: Ensure the following permissions exist for all reports:</strong><br>' + reportPermissions.join(', ') + '</td></tr>');
                $('#permissionCount').text(data.length);
            },
            error: function(xhr, status, error) {
                $('#loadingState').hide();
                $('#errorState').show();
            }
        });
    }

    function filterPermissions() {
        var input = document.getElementById('permissionSearch');
        var filter = input.value.toLowerCase();
        var rows = document.querySelectorAll('#permissionTableBody tr');
        rows.forEach(function(row) {
            if (row.innerText.toLowerCase().indexOf(filter) > -1) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function editPermission(id, name) {
        $('#editPermissionId').val(id);
        $('#editPermissionName').val(name);
        $('#editPermissionModal').modal('show');
    }

    function deletePermission(id) {
        $('#deletePermissionId').val(id);
        $('#deletePermissionModal').modal('show');
    }
</script>

<?php
$page_content = ob_get_clean();

// Define modals outside output buffer
$modal_html = '
<!-- Add Permission Modal -->
<div class="modal fade" id="addPermissionModal" tabindex="-1" role="dialog" aria-labelledby="addPermissionModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addPermissionModalLabel">Add Permission</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="addPermissionForm">
                    <div class="form-group">
                        <label for="permissionName">Permission Name</label>
                        <input type="text" class="form-control" id="permissionName" name="permissionName" required>
                        <small class="form-text text-muted">Enter a unique permission name (e.g., view_dashboard, create_user)</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="addPermissionBtn">Add Permission</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Permission Modal -->
<div class="modal fade" id="editPermissionModal" tabindex="-1" role="dialog" aria-labelledby="editPermissionModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editPermissionModalLabel">Edit Permission</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="editPermissionForm">
                    <input type="hidden" id="editPermissionId">
                    <div class="form-group">
                        <label for="editPermissionName">Permission Name</label>
                        <input type="text" class="form-control" id="editPermissionName" name="editPermissionName" required>
                        <small class="form-text text-muted">Update the permission name</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="editPermissionBtn">Update Permission</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Permission Modal -->
<div class="modal fade" id="deletePermissionModal" tabindex="-1" role="dialog" aria-labelledby="deletePermissionModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deletePermissionModalLabel">Delete Permission</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="deletePermissionId">
                <p>Are you sure you want to delete this permission? This action cannot be undone.</p>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Warning:</strong> Deleting a permission may affect users and roles that depend on it.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="deletePermissionBtn">Delete Permission</button>
            </div>
        </div>
    </div>
</div>';

require_once __DIR__.'/../includes/layout.php';
?>
