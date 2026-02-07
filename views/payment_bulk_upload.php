<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
$is_super_admin = (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('view_payment_bulk')) {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>';
    exit;
}

ob_start();
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="font-weight-bold">
            <i class="fas fa-upload mr-2 text-primary"></i>Bulk Payment Upload
            <small class="text-muted d-block" style="font-size: 0.6em;">Balance Brought Forward & Historical Payments</small>
        </h2>
        <div>
            <a href="payment_form.php" class="btn btn-outline-secondary mr-2">
                <i class="fas fa-arrow-left"></i> Back to Payments
            </a>
            <button type="button" class="btn btn-info" data-toggle="modal" data-target="#templateModal">
                <i class="fas fa-download"></i> Download Template
            </button>
        </div>
    </div>

    <!-- Upload Form -->
    <div class="card shadow-lg mb-4">
        <div class="card-header bg-gradient-primary text-white">
            <h5 class="mb-0"><i class="fas fa-file-csv mr-2"></i>Upload Payment Data</h5>
        </div>
        <div class="card-body">
            <form id="bulkUploadForm" enctype="multipart/form-data">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="church_id" class="font-weight-bold">Church <span class="text-danger">*</span></label>
                            <select class="form-control form-control-lg" id="church_id" name="church_id" required>
                                <option value="">-- Select Church --</option>
                                <?php
                                $churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");
                                if ($churches && $churches->num_rows > 0):
                                    while($church = $churches->fetch_assoc()): ?>
                                        <option value="<?= $church['id'] ?>"><?= htmlspecialchars($church['name']) ?></option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="payment_type_id" class="font-weight-bold">Default Payment Type</label>
                            <select class="form-control form-control-lg" id="payment_type_id" name="payment_type_id">
                                <option value="">-- Select Payment Type (Optional) --</option>
                                <?php
                                $types = $conn->query("SELECT id, name FROM payment_types WHERE active=1 ORDER BY name ASC");
                                if ($types && $types->num_rows > 0):
                                    while($type = $types->fetch_assoc()): ?>
                                        <option value="<?= $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                                <?php endwhile; endif; ?>
                            </select>
                            <small class="form-text text-muted">Used only when CSV payment_type column is empty</small>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="payment_date" class="font-weight-bold">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-lg" id="payment_date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                            <small class="form-text text-muted">Default date for payments without specific dates in CSV</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="payment_period" class="font-weight-bold">Payment Period</label>
                            <select class="form-control form-control-lg" id="payment_period" name="payment_period">
                                <option value="">-- Same as Payment Date --</option>
                                <?php
                                // Generate last 12 months
                                for ($i = 0; $i < 12; $i++) {
                                    $date = date('Y-m-01', strtotime("-$i months"));
                                    $label = date('F Y', strtotime($date));
                                    echo "<option value='$date'>$label</option>";
                                }
                                ?>
                            </select>
                            <small class="form-text text-muted">For balance brought forward from previous periods</small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="csv_file" class="font-weight-bold">CSV File <span class="text-danger">*</span></label>
                    <div class="custom-file">
                        <input type="file" class="custom-file-input" id="csv_file" name="csv_file" accept=".csv,.xlsx,.xls" required>
                        <label class="custom-file-label" for="csv_file">Choose CSV/Excel file...</label>
                    </div>
                    <small class="form-text text-muted">
                        Supported formats: CSV, Excel (.xlsx, .xls). Maximum file size: 5MB
                    </small>
                </div>

                <div class="form-group">
                    <label for="description" class="font-weight-bold">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="2" placeholder="e.g., Balance brought forward from December 2024"></textarea>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="validate_only" name="validate_only">
                    <label class="form-check-label" for="validate_only">
                        <strong>Validate Only</strong> - Check data without saving payments
                    </label>
                </div>

                <div class="text-center">
                    <button type="submit" class="btn btn-primary btn-lg px-5">
                        <i class="fas fa-upload mr-2"></i>
                        <span class="btn-text">Process Upload</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Progress Section -->
    <div id="progress-section" class="card shadow mb-4 d-none">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="fas fa-cog fa-spin mr-2"></i>Processing...</h5>
        </div>
        <div class="card-body">
            <div class="progress mb-3" style="height: 25px;">
                <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" 
                     role="progressbar" style="width: 0%">0%</div>
            </div>
            <div id="progress-text" class="text-center">
                <strong>Preparing upload...</strong>
            </div>
        </div>
    </div>

    <!-- Results Section -->
    <div id="results-section" class="d-none">
        <div class="card shadow">
            <div class="card-header" id="results-header">
                <h5 class="mb-0"><i class="fas fa-check-circle mr-2"></i>Upload Results</h5>
            </div>
            <div class="card-body" id="results-body">
                <!-- Results will be populated here -->
            </div>
        </div>
    </div>
</div>


<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
?>

<!-- Template Modal -->
<div class="modal fade" id="templateModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-file-csv mr-2"></i>CSV Template Format</h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <h6 class="font-weight-bold">Required Columns:</h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="thead-light">
                            <tr>
                                <th>Column</th>
                                <th>Description</th>
                                <th>Example</th>
                                <th>Required</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>crn</code></td>
                                <td>Member CRN (Church Registration Number)</td>
                                <td>ABC1234DE</td>
                                <td><span class="badge badge-danger">Yes</span></td>
                            </tr>
                            <tr>
                                <td><code>amount</code></td>
                                <td>Payment amount</td>
                                <td>100.00</td>
                                <td><span class="badge badge-danger">Yes</span></td>
                            </tr>
                            <tr>
                                <td><code>payment_type</code></td>
                                <td>Payment type name (optional, uses default if empty)</td>
                                <td>Tithe</td>
                                <td><span class="badge badge-secondary">No</span></td>
                            </tr>
                            <tr>
                                <td><code>payment_date</code></td>
                                <td>Payment date (optional, uses default if empty)</td>
                                <td>2024-12-15</td>
                                <td><span class="badge badge-secondary">No</span></td>
                            </tr>
                            <tr>
                                <td><code>description</code></td>
                                <td>Payment description/notes</td>
                                <td>Balance brought forward</td>
                                <td><span class="badge badge-secondary">No</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <h6 class="font-weight-bold mt-4">Sample CSV Content:</h6>
                <pre class="bg-light p-3 rounded"><code>crn,amount,payment_type,payment_date,description
ABC1234DE,100.00,Tithe,2024-12-15,Balance brought forward
XYZ5678FG,50.00,Offertory,,December offering
DEF9012HI,200.00,,,Balance from previous system</code></pre>
                
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Tips:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Ensure CRNs exist in the system</li>
                        <li>Use exact payment type names as they appear in the system</li>
                        <li>Date format should be YYYY-MM-DD</li>
                        <li>Empty cells will use default values from the form</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" onclick="downloadTemplate()">
                    <i class="fas fa-download mr-2"></i>Download Template
                </button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
$(document).ready(function() {
    // File input label update
    $('.custom-file-input').on('change', function() {
        const fileName = $(this).val().split('\\').pop();
        $(this).siblings('.custom-file-label').addClass('selected').html(fileName);
    });

    // Form submission
    $('#bulkUploadForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const validateOnly = $('#validate_only').is(':checked');
        
        // Show progress
        $('#progress-section').removeClass('d-none');
        $('#results-section').addClass('d-none');
        updateProgress(0, 'Uploading file...');
        
        // Disable form
        $(this).find('button[type="submit"]').prop('disabled', true);
        $(this).find('.btn-text').text(validateOnly ? 'Validating...' : 'Processing...');
        
        $.ajax({
            url: 'ajax_process_bulk_upload.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                const xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(evt) {
                    if (evt.lengthComputable) {
                        const percentComplete = (evt.loaded / evt.total) * 100;
                        updateProgress(Math.round(percentComplete), 'Uploading file...');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                updateProgress(100, 'Processing complete!');
                console.log('Server response:', response);
                
                // Handle both JSON and string responses
                let parsedResponse;
                if (typeof response === 'string') {
                    try {
                        parsedResponse = JSON.parse(response);
                    } catch (e) {
                        parsedResponse = {
                            success: false,
                            error: 'Invalid server response: ' + response
                        };
                    }
                } else {
                    parsedResponse = response;
                }
                
                showResults(parsedResponse);
            },
            error: function(xhr, status, error) {
                updateProgress(0, 'Upload failed!');
                console.log('AJAX Error:', {xhr: xhr, status: status, error: error});
                console.log('Response Text:', xhr.responseText);
                
                let errorMessage = 'Upload failed: ' + error;
                if (xhr.responseText) {
                    try {
                        const errorResponse = JSON.parse(xhr.responseText);
                        errorMessage = errorResponse.error || errorMessage;
                    } catch (e) {
                        errorMessage = 'Server error: ' + xhr.responseText;
                    }
                }
                
                showResults({
                    success: false,
                    error: errorMessage
                });
            },
            complete: function() {
                // Re-enable form
                $('#bulkUploadForm').find('button[type="submit"]').prop('disabled', false);
                $('#bulkUploadForm').find('.btn-text').text('Process Upload');
            }
        });
    });
});

function updateProgress(percent, text) {
    $('#progress-bar').css('width', percent + '%').text(percent + '%');
    $('#progress-text').html('<strong>' + text + '</strong>');
}

function showResults(response) {
    $('#results-section').removeClass('d-none');
    
    if (response.success) {
        $('#results-header').html('<h5 class="mb-0 text-success"><i class="fas fa-check-circle mr-2"></i>Upload Successful</h5>');
        
        let html = '<div class="row">';
        html += '<div class="col-md-4"><div class="card bg-success text-white"><div class="card-body text-center">';
        html += '<h3>' + response.stats.successful + '</h3><p>Successful</p></div></div></div>';
        html += '<div class="col-md-4"><div class="card bg-warning text-white"><div class="card-body text-center">';
        html += '<h3>' + response.stats.failed + '</h3><p>Failed</p></div></div></div>';
        html += '<div class="col-md-4"><div class="card bg-info text-white"><div class="card-body text-center">';
        html += '<h3>' + response.stats.total + '</h3><p>Total</p></div></div></div>';
        html += '</div>';
        
        if (response.errors && response.errors.length > 0) {
            html += '<div class="mt-3"><h6>Errors:</h6><ul class="list-group">';
            response.errors.forEach(function(error) {
                html += '<li class="list-group-item list-group-item-danger">' + error + '</li>';
            });
            html += '</ul></div>';
        }
        
        $('#results-body').html(html);
    } else {
        $('#results-header').html('<h5 class="mb-0 text-danger"><i class="fas fa-exclamation-circle mr-2"></i>Upload Failed</h5>');
        $('#results-body').html('<div class="alert alert-danger">' + response.error + '</div>');
    }
}

function downloadTemplate() {
    const csvContent = 'crn,amount,payment_type,payment_date,description\n' +
                      'ABC1234DE,100.00,Tithe,2024-12-15,Balance brought forward\n' +
                      'XYZ5678FG,50.00,Offertory,,December offering\n' +
                      'DEF9012HI,200.00,,,Balance from previous system';
    
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.setAttribute('hidden', '');
    a.setAttribute('href', url);
    a.setAttribute('download', 'bulk_payment_template.csv');
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>
