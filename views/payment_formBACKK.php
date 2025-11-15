<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

// Check if editing or creating
$editing = isset($_GET['id']) && is_numeric($_GET['id']);
$required_permission = $editing ? 'edit_payment' : 'create_payment';

if (!$is_super_admin && !has_permission($required_permission)) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_add = $is_super_admin || has_permission('create_payment');
$can_edit = $is_super_admin || has_permission('edit_payment');
$can_view = true; // Already validated above

// Fetch payment types
$types = $conn->query("SELECT id, name FROM payment_types WHERE active=1 ORDER BY name");

$error = '';
$success = '';

$modal_html = '';
ob_start();
?>

<!-- Modern Payment Form Styles -->
<style>
.payment-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem 0;
    margin-bottom: 2rem;
    border-radius: 0.5rem;
}

.payment-card {
    border: none;
    box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.1);
    border-radius: 1rem;
    overflow: hidden;
    transition: all 0.3s ease;
}

.payment-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 1rem 2rem rgba(0, 0, 0, 0.15);
}

.modern-tab {
    border: none;
    border-radius: 0.75rem 0.75rem 0 0;
    background: linear-gradient(45deg, #f8f9fa, #e9ecef);
}

.modern-tab .nav-link {
    border: none;
    border-radius: 0.75rem 0.75rem 0 0;
    padding: 1rem 2rem;
    font-weight: 600;
    color: #6c757d;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.modern-tab .nav-link:hover {
    background: linear-gradient(45deg, #e3f2fd, #f3e5f5);
    color: #495057;
    transform: translateY(-2px);
}

.modern-tab .nav-link.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
}

.modern-tab .nav-link.active::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #ffd700, #ff6b6b);
}

.form-control-modern {
    border: 2px solid #e9ecef;
    border-radius: 0.75rem;
    padding: 0.75rem 1rem;
    font-size: 1rem;
    transition: all 0.3s ease;
    background: #f8f9fa;
}

.form-control-modern:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
    background: white;
    transform: translateY(-1px);
}

.btn-modern {
    border-radius: 0.75rem;
    padding: 0.75rem 2rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: all 0.3s ease;
    border: none;
    position: relative;
    overflow: hidden;
}

.btn-modern::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
    transition: left 0.5s;
}

.btn-modern:hover::before {
    left: 100%;
}

.btn-primary-modern {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-success-modern {
    background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
    color: white;
}

.btn-info-modern {
    background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%);
    color: white;
}

.member-card {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: 2px solid #dee2e6;
    border-radius: 1rem;
    transition: all 0.3s ease;
}

.member-card.found {
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    border-color: #28a745;
}

.payment-summary-card {
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    border: 2px solid #ffc107;
    border-radius: 1rem;
}

.table-modern {
    border-radius: 0.75rem;
    overflow: hidden;
    box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.1);
}

.table-modern thead th {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 1rem;
}

.table-modern tbody tr {
    transition: all 0.3s ease;
}

.table-modern tbody tr:hover {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    transform: scale(1.01);
}

.search-section {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-radius: 1rem;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 0.25rem 1rem rgba(0, 0, 0, 0.1);
}

.payment-section {
    background: white;
    border-radius: 1rem;
    overflow: hidden;
    box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.1);
}

@media (max-width: 768px) {
    .payment-header {
        padding: 1rem 0;
        margin-bottom: 1rem;
    }
    
    .modern-tab .nav-link {
        padding: 0.75rem 1rem;
        font-size: 0.9rem;
    }
    
    .btn-modern {
        padding: 0.6rem 1.5rem;
        font-size: 0.9rem;
    }
}
</style>

<div class="container-fluid px-4 py-3">
    <!-- Modern Header -->
    <div class="payment-header text-center">
        <div class="container">
            <h1 class="display-4 font-weight-bold mb-2">
                <i class="fas fa-credit-card mr-3"></i>Payment Processing
            </h1>
            <p class="lead mb-0">Secure and efficient payment management system</p>
        </div>
    </div>

    <!-- Navigation -->
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-transparent">
                    <li class="breadcrumb-item">
                        <a href="<?= BASE_URL ?>/index.php" class="text-decoration-none">
                            <i class="fas fa-home mr-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="breadcrumb-item">
                        <a href="payment_list.php" class="text-decoration-none">
                            <i class="fas fa-list mr-1"></i>Payments
                        </a>
                    </li>
                    <li class="breadcrumb-item active">New Payment</li>
                </ol>
            </nav>
        </div>
    </div>

    <!-- Main Content -->
    <div class="row justify-content-center">
        <div class="col-12 col-xl-10">
            <!-- Member Search Section -->
            <div class="search-section">
                <div class="text-center mb-4">
                    <h3 class="font-weight-bold text-primary">
                        <i class="fas fa-search-plus mr-2"></i>Find Member or Student
                    </h3>
                    <p class="text-muted mb-0">Enter CRN for members or SRN for Sunday School students</p>
                </div>
                
                <form id="searchMemberForm" autocomplete="off">
                    <div class="row align-items-end">
                        <div class="col-lg-8 col-md-7 mb-3">
                            <label for="crn" class="font-weight-bold text-dark">
                                <i class="fas fa-id-card mr-1 text-primary"></i>
                                CRN/SRN <span class="text-danger">*</span>
                            </label>
                            <input type="text" 
                                   class="form-control form-control-modern form-control-lg" 
                                   id="crn" 
                                   name="crn" 
                                   maxlength="50" 
                                   placeholder="Enter CRN (e.g., FMC001) or SRN (e.g., SS001)" 
                                   required 
                                   autocomplete="off">
                        </div>
                        <div class="col-lg-4 col-md-5 mb-3">
                            <button type="submit" 
                                    class="btn btn-modern btn-info-modern btn-lg w-100" 
                                    id="findMemberBtn">
                                <i class="fas fa-search mr-2"></i>Search Member
                            </button>
                            <div id="crn-spinner" 
                                 class="text-center mt-2 d-none">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="sr-only">Searching...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="crn-feedback" class="mt-3"></div>
                </form>
                
                <div id="member-summary" class="mt-4 d-none"></div>
            </div>
            <!-- Payment Processing Section -->
            <div id="payment-panels" class="payment-section d-none animate__animated animate__fadeIn">
                <ul class="nav nav-tabs modern-tab border-0" id="paymentTab" role="tablist">
                    <li class="nav-item flex-fill">
                        <a class="nav-link text-center" id="single-tab" data-toggle="tab" href="#singlePanel" role="tab" aria-controls="singlePanel" aria-selected="false">
                            <i class="fas fa-money-bill-wave fa-lg mb-2 d-block"></i>
                            <span class="font-weight-bold">Single Payment</span>
                            <small class="d-block text-muted">Process one payment</small>
                        </a>
                    </li>
                    <li class="nav-item flex-fill">
                        <a class="nav-link active text-center" id="bulk-tab" data-toggle="tab" href="#bulkPanel" role="tab" aria-controls="bulkPanel" aria-selected="true">
                            <i class="fas fa-layer-group fa-lg mb-2 d-block"></i>
                            <span class="font-weight-bold">Multiple Payments</span>
                            <small class="d-block text-muted">Process multiple payments</small>
                        </a>
                    </li>
                </ul> 
                <div class="tab-content bg-white" id="paymentTabContent">
                    <!-- Single Payment Panel -->
                    <div class="tab-pane fade p-4" id="singlePanel" role="tabpanel" aria-labelledby="single-tab">
                        <div class="text-center mb-4">
                            <h4 class="font-weight-bold text-primary">
                                <i class="fas fa-money-bill-wave mr-2"></i>Single Payment Entry
                            </h4>
                            <p class="text-muted mb-0">Process a single payment transaction</p>
                        </div>
                        
                        <form id="singlePaymentForm" autocomplete="off">
                            <input type="hidden" name="member_id" id="single_member_id">
                            <input type="hidden" name="sundayschool_id" id="single_sundayschool_id">
                            
                            <!-- Payment Details Row -->
                            <div class="row">
                                <div class="col-lg-4 col-md-6 mb-3">
                                    <label for="single_payment_type_id" class="font-weight-bold text-dark">
                                        <i class="fas fa-tags mr-1 text-primary"></i>
                                        Payment Type <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-control form-control-modern form-control-lg" id="single_payment_type_id" name="payment_type_id" required>
                                        <option value="">-- Select Payment Type --</option>
                                        <?php if ($types && $types->num_rows > 0): while($t = $types->fetch_assoc()): ?>
                                            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                        <?php endwhile; endif; ?>
                                    </select>
                                </div>
                                
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <label for="single_amount" class="font-weight-bold text-dark">
                                        <i class="fas fa-dollar-sign mr-1 text-success"></i>
                                        Amount (₵) <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" 
                                           step="0.01" 
                                           min="0" 
                                           class="form-control form-control-modern form-control-lg" 
                                           id="single_amount" 
                                           name="amount" 
                                           placeholder="0.00" 
                                           required>
                                </div>
                                
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <label for="single_mode" class="font-weight-bold text-dark">
                                        <i class="fas fa-credit-card mr-1 text-info"></i>
                                        Payment Mode <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-control form-control-modern form-control-lg" id="single_mode" name="mode" required>
                                        <option value="">-- Select Mode --</option>
                                        <option value="Cash">💵 Cash</option>
                                        <option value="Cheque">📝 Cheque</option>
                                    </select>
                                </div>
                                
                                <div class="col-lg-2 col-md-6 mb-3">
                                    <label for="single_payment_date" class="font-weight-bold text-dark">
                                        <i class="fas fa-calendar mr-1 text-warning"></i>
                                        Date <span class="text-danger">*</span>
                                    </label>
                                    <input type="date" 
                                           class="form-control form-control-modern form-control-lg" 
                                           id="single_payment_date" 
                                           name="payment_date" 
                                           value="<?= date('Y-m-d') ?>" 
                                           readonly 
                                           required>
                                </div>
                            </div>
                            
                            <!-- Period and Description Row -->
                            <div class="row">
                                <div class="col-lg-6 col-md-12 mb-3">
                                    <label for="single_payment_period" class="font-weight-bold text-dark">
                                        <i class="fas fa-calendar-alt mr-1 text-secondary"></i>
                                        Payment Period <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-control form-control-modern form-control-lg" id="single_payment_period" name="payment_period" required>
                                        <option value="">-- Select Period --</option>
                                        <?php
                                        // Generate payment period options (current month and previous 12 months)
                                        for ($i = 0; $i < 12; $i++) {
                                            $date = date('Y-m-01', strtotime("-$i months"));
                                            $display = date('F Y', strtotime($date));
                                            $selected = ($i == 0) ? 'selected' : ''; // Default to current month
                                            echo "<option value=\"$date\" $selected>$display</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                
                                <div class="col-lg-6 col-md-12 mb-3">
                                    <label for="single_description" class="font-weight-bold text-dark">
                                        <i class="fas fa-comment mr-1 text-muted"></i>
                                        Description <small class="text-muted">(Optional)</small>
                                    </label>
                                    <input type="text" 
                                           class="form-control form-control-modern form-control-lg" 
                                           id="single_description" 
                                           name="description" 
                                           placeholder="Payment description..." 
                                           autocomplete="off">
                                </div>
                            </div>
                            
                            <!-- Submit Button -->
                            <div class="row">
                                <div class="col-12 text-center mt-4">
                                    <button type="button" 
                                            class="btn btn-modern btn-success-modern btn-lg px-5 shadow-lg" 
                                            id="submitSinglePaymentBtn">
                                        <i class="fas fa-check-circle mr-2"></i>Process Payment
                                    </button>
                                </div>
                            </div>
                            
                            <div id="single-payment-feedback" class="mt-4"></div>
                        </form>
                    </div>
<?php ob_start(); ?>
<!-- Single Payment Confirmation Modal -->
<div class="modal fade" id="singlePaymentConfirmModal" tabindex="-1" role="dialog" aria-labelledby="singlePaymentConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="singlePaymentConfirmModalLabel"><i class="fas fa-question-circle mr-2"></i>Confirm Payment</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div class="mb-2">Are you sure you want to submit this payment?</div>
        <ul class="list-group mb-2">
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>Type</span>
            <span id="confirmSingleType" class="font-weight-bold"></span>
          </li>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>Amount</span>
            <span id="confirmSingleAmount" class="font-weight-bold"></span>
          </li>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>Mode</span>
            <span id="confirmSingleMode" class="font-weight-bold"></span>
          </li>
        </ul>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="confirmSinglePaymentBtn"><i class="fas fa-check-circle mr-1"></i>Confirm & Submit</button>
      </div>
    </div>
  </div>
</div>
<?php $modal_html .= ob_get_clean(); ?>
                        </form>
                    </div>
                    <!-- Multiple Payment Panel -->
                    <div class="tab-pane fade show active p-4" id="bulkPanel" role="tabpanel" aria-labelledby="bulk-tab">
                        <div class="text-center mb-4">
                            <h4 class="font-weight-bold text-primary">
                                <i class="fas fa-layer-group mr-2"></i>Multiple Payment Entry
                            </h4>
                            <p class="text-muted mb-0">Add multiple payments to process in batch</p>
                        </div>
                        
                        <!-- Payment Entry Form -->
                        <div class="payment-summary-card p-4 mb-4">
                            <h5 class="font-weight-bold text-dark mb-3">
                                <i class="fas fa-plus-circle mr-2 text-success"></i>Add Payment to Batch
                            </h5>
                            
                            <form id="bulkPaymentEntryForm" autocomplete="off" onsubmit="return false;">
                                <div class="row">
                                    <div class="col-lg-4 col-md-6 mb-3">
                                        <label for="bulk_payment_type_id" class="font-weight-bold text-dark">
                                            <i class="fas fa-tags mr-1 text-primary"></i>
                                            Payment Type <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-control form-control-modern form-control-lg" id="bulk_payment_type_id" name="bulk_payment_type_id">
                                            <option value="">-- Select Payment Type --</option>
                                            <?php $types2 = $conn->query("SELECT id, name FROM payment_types ORDER BY name");
                                            if ($types2 && $types2->num_rows > 0): while($t = $types2->fetch_assoc()): ?>
                                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                            <?php endwhile; endif; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-lg-2 col-md-6 mb-3">
                                        <label for="bulk_amount" class="font-weight-bold text-dark">
                                            <i class="fas fa-dollar-sign mr-1 text-success"></i>
                                            Amount (₵) <span class="text-danger">*</span>
                                        </label>
                                        <input type="number" 
                                               step="0.01" 
                                               min="0" 
                                               class="form-control form-control-modern form-control-lg" 
                                               id="bulk_amount" 
                                               name="bulk_amount" 
                                               placeholder="0.00">
                                    </div>
                                    
                                    <div class="col-lg-2 col-md-6 mb-3">
                                        <label for="bulk_mode" class="font-weight-bold text-dark">
                                            <i class="fas fa-credit-card mr-1 text-info"></i>
                                            Mode <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-control form-control-modern form-control-lg" id="bulk_mode" name="bulk_mode">
                                            <option value="">-- Select Mode --</option>
                                            <option value="Cash">💵 Cash</option>
                                            <option value="Cheque">📝 Cheque</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-lg-2 col-md-6 mb-3">
                                        <label for="bulk_payment_date" class="font-weight-bold text-dark">
                                            <i class="fas fa-calendar mr-1 text-warning"></i>
                                            Date <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" 
                                               class="form-control form-control-modern form-control-lg" 
                                               id="bulk_payment_date" 
                                               name="bulk_payment_date" 
                                               value="<?= date('Y-m-d') ?>" 
                                               readonly 
                                               required>
                                    </div>
                                    
                                    <div class="col-lg-2 col-md-12 mb-3">
                                        <label for="bulk_payment_period" class="font-weight-bold text-dark">
                                            <i class="fas fa-calendar-alt mr-1 text-secondary"></i>
                                            Period <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-control form-control-modern form-control-lg" id="bulk_payment_period" name="bulk_payment_period" required>
                                            <option value="">-- Select Period --</option>
                                            <?php
                                            // Generate payment period options (current month and previous 12 months)
                                            for ($i = 0; $i < 12; $i++) {
                                                $date = date('Y-m-01', strtotime("-$i months"));
                                                $display = date('F Y', strtotime($date));
                                                $selected = ($i == 0) ? 'selected' : ''; // Default to current month
                                                echo "<option value=\"$date\" $selected>$display</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- Add to Batch Button -->
                                <div class="row">
                                    <div class="col-12 text-center mt-3">
                                        <button type="button" 
                                                class="btn btn-modern btn-success-modern btn-lg px-4" 
                                                id="addToBulkBtn">
                                            <i class="fas fa-plus-circle mr-2"></i>Add to Batch
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Payment Batch Table -->
                        <div class="payment-card mb-4">
                            <div class="card-header bg-gradient-primary text-white py-3">
                                <h5 class="mb-0 font-weight-bold">
                                    <i class="fas fa-list-alt mr-2"></i>Payment Batch
                                    <span class="badge badge-light ml-2" id="batchCount">0 items</span>
                                </h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-modern table-hover mb-0" id="bulkPaymentsTable">
                                        <thead>
                                            <tr>
                                                <th class="text-center">#</th>
                                                <th><i class="fas fa-tags mr-1"></i>Type</th>
                                                <th class="text-right"><i class="fas fa-dollar-sign mr-1"></i>Amount</th>
                                                <th class="text-center"><i class="fas fa-credit-card mr-1"></i>Mode</th>
                                                <th class="text-center"><i class="fas fa-calendar mr-1"></i>Date</th>
                                                <th class="text-center"><i class="fas fa-calendar-alt mr-1"></i>Period</th>
                                                <th class="text-center"><i class="fas fa-cog mr-1"></i>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr id="emptyBatchRow">
                                                <td colspan="7" class="text-center py-5 text-muted">
                                                    <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                                    <h5>No payments added yet</h5>
                                                    <p class="mb-0">Use the form above to add payments to this batch</p>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Batch Summary and Submit -->
                        <div class="payment-summary-card p-4" id="bulkPaymentsFooter">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <h4 class="font-weight-bold text-dark mb-0">
                                        <i class="fas fa-calculator mr-2 text-success"></i>
                                        Batch Total: <span class="text-success" id="bulkPaymentsTotal">₵0.00</span>
                                    </h4>
                                    <small class="text-muted">Total amount for all payments in batch</small>
                                </div>
                                <div class="col-md-6 text-right">
                                    <button type="button" 
                                            class="btn btn-modern btn-primary-modern btn-lg px-5 shadow-lg" 
                                            id="submitBulkPaymentsBtn" 
                                            disabled>
                                        <i class="fas fa-paper-plane mr-2"></i>Process All Payments
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div id="bulk-payment-feedback" class="mt-4"></div>
<?php ob_start(); ?>
<!-- Bulk Payment Confirmation Modal -->
<div class="modal fade" id="bulkPaymentConfirmModal" tabindex="-1" role="dialog" aria-labelledby="bulkPaymentConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="bulkPaymentConfirmModalLabel"><i class="fas fa-question-circle mr-2"></i>Confirm Bulk Payments</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div class="mb-2">Are you sure you want to submit all these payments?</div>
        <div class="table-responsive">
          <table class="table table-bordered table-sm mb-0" id="bulkConfirmTable">
            <thead class="thead-light">
              <tr>
                <th>#</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Mode</th>
                <th>Date</th>
                <th>Description</th>
              </tr>
            </thead>
            <tbody></tbody>
            <tfoot>
              <tr>
                <td colspan="2" class="text-right font-weight-bold">Total</td>
                <td colspan="4" class="font-weight-bold" id="bulkConfirmTotal"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="confirmBulkPaymentBtn"><i class="fas fa-check-circle mr-1"></i>Confirm & Submit</button>
      </div>
    </div>
  </div>
</div>
<?php $modal_html .= ob_get_clean(); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/js/all.min.js"></script>
<script src="payment_form_multi.js"></script>
<script>
$(function(){
    // Auto-populate description field
    function updateDescriptionField() {
        var paymentType = $('#single_payment_type_id option:selected').text();
        var periodVal = $('#single_payment_period').val();
        var periodText = $('#single_payment_period option:selected').text();
        
        if (paymentType && paymentType !== '-- Select --' && periodText && periodText !== '-- Select Period --') {
            $('#single_description').val('Payment for ' + periodText + ' ' + paymentType);
        } else {
            $('#single_description').val('');
        }
    }
    $('#single_payment_type_id, #single_payment_period').on('change', updateDescriptionField);
    // Initial auto-populate on page load (if both fields have value)
    updateDescriptionField();

    // --- Member Search ---
    $('#searchMemberForm').on('submit', function(e) {
        e.preventDefault();
        var crn = $('#crn').val().trim();
        if (!crn) {
            $('#crn-feedback').html('<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i>Please enter a CRN or SRN.</div>');
            $('#member-summary').addClass('d-none').empty();
            $('#payment-panels').addClass('d-none');
            return;
        }
        $('#findMemberBtn').prop('disabled', true);
        $('#crn-spinner').removeClass('d-none');
        $('#crn-feedback').html('<div class="alert alert-info"><i class="fas fa-search mr-2"></i>Searching for member...</div>');
        console.log('Making AJAX request to ajax_get_person_by_id.php with ID:', crn);
        $.get('ajax_get_person_by_id.php', {id: crn}, function(resp) {
            console.log('AJAX response received:', resp);
            $('#findMemberBtn').prop('disabled', false);
            $('#crn-spinner').addClass('d-none');
            if (resp.success) {
                let summaryHtml = '';
                let m = resp.data;
                if (resp.type === 'member') {
                    summaryHtml = `<div class="card border-success shadow-sm mb-2 animate__animated animate__fadeIn member-summary-card">
  <div class="card-body py-2">
    <div class="d-flex align-items-center mb-2">
      <span class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mr-2 icon"><i class="fas fa-check"></i></span>
      <span class="h6 mb-0 font-weight-bold text-success">Member Found</span>
      <span class="badge badge-primary ml-3">Member</span>
    </div>
    <div class="member-details-row row justify-content-center py-2">
      <div class="details-item text-center col-6 col-md-auto mb-2 mb-md-0 mr-md-3">
        <div class="info-label"><i class="fas fa-id-card text-primary mr-1 icon"></i>CRN</div>
        <div class="info-value">${m.crn}</div>
      </div>
      <span class="divider d-none d-md-inline mx-2"></span>
      <div class="details-item text-center col-6 col-md-auto mb-2 mb-md-0 mr-md-3">
        <div class="info-label"><i class="fas fa-user text-secondary mr-1 icon"></i>Name</div>
        <div class="info-value">${m.first_name} ${m.last_name}</div>
      </div>
      <span class="divider d-none d-md-inline mx-2"></span>
      <div class="details-item text-center col-6 col-md-auto mb-2 mb-md-0 mr-md-3">
        <div class="info-label"><i class="fas fa-phone-alt text-info mr-1 icon"></i>Phone</div>
        <div class="info-value">${m.phone ? m.phone : '-'}</div>
      </div>
      <span class="divider d-none d-md-inline mx-2"></span>
      <div class="details-item text-center col-6 col-md-auto">
        <div class="info-label"><i class="fas fa-chalkboard-teacher text-success mr-1 icon"></i>Class</div>
        <div class="info-value">${m.class_name ? m.class_name : '-'}</div>
      </div>
    </div>
  </div>
</div>`;
                    $('#single_member_id').val(m.id);
                    $('#single_sundayschool_id').val('');
                } else if (resp.type === 'sundayschool') {
                    summaryHtml = `<div class="card border-success shadow-sm mb-2 animate__animated animate__fadeIn member-summary-card">
  <div class="card-body py-2">
    <div class="d-flex align-items-center mb-2">
      <span class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mr-2 icon"><i class="fas fa-check"></i></span>
      <span class="h6 mb-0 font-weight-bold text-success">Child Found</span>
      <span class="badge badge-warning ml-3">Sunday School</span>
    </div>
    <div class="member-details-row row justify-content-center py-2">
      <div class="details-item text-center col-6 col-md-auto mb-2 mb-md-0 mr-md-3">
        <div class="info-label"><i class="fas fa-id-card text-primary mr-1 icon"></i>SRN</div>
        <div class="info-value">${m.srn}</div>
      </div>
      <span class="divider d-none d-md-inline mx-2"></span>
      <div class="details-item text-center col-6 col-md-auto mb-2 mb-md-0 mr-md-3">
        <div class="info-label"><i class="fas fa-user text-secondary mr-1 icon"></i>Name</div>
        <div class="info-value">${m.first_name} ${m.last_name}</div>
      </div>
      <span class="divider d-none d-md-inline mx-2"></span>
      <div class="details-item text-center col-6 col-md-auto mb-2 mb-md-0 mr-md-3">
        <div class="info-label"><i class="fas fa-phone-alt text-info mr-1 icon"></i>Phone</div>
        <div class="info-value">${m.contact ? m.contact : '-'}</div>
      </div>
      <span class="divider d-none d-md-inline mx-2"></span>
      <div class="details-item text-center col-6 col-md-auto">
        <div class="info-label"><i class="fas fa-chalkboard-teacher text-success mr-1 icon"></i>Class</div>
        <div class="info-value">${m.class_id ? m.class_id : '-'}</div>
      </div>
    </div>
  </div>
</div>`;
                    $('#single_member_id').val('');
                    $('#single_sundayschool_id').val(m.id);
                }
                $('#member-summary').removeClass('d-none').html(summaryHtml);
                $('#payment-panels').removeClass('d-none');
                window.setBulkMember && window.setBulkMember(m, resp.type); // For bulk, pass type
                $('#crn-feedback').html('<div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i>Member found successfully!</div>');
            } else {
                $('#crn-feedback').html('<div class="alert alert-danger"><i class="fas fa-user-times mr-2"></i>' + (resp.msg||'Member not found.') + '</div>');
                $('#member-summary').addClass('d-none').empty();
                $('#payment-panels').addClass('d-none');
            }
        }, 'json').fail(function(xhr, status, error){
            console.log('AJAX request failed:', {xhr: xhr, status: status, error: error});
            console.log('Response text:', xhr.responseText);
            $('#findMemberBtn').prop('disabled', false);
            $('#crn-spinner').addClass('d-none');
            $('#crn-feedback').html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle mr-2"></i>Network or server error occurred.</div>');
        });
    });

    // --- Single Payment Submission ---
    // Confirmation modal logic for single payment
    let singlePaymentConfirmed = false;
    $('#submitSinglePaymentBtn').on('click', function(e){
        // Validate fields
        var member_id = $('#single_member_id').val();
        var sundayschool_id = $('#single_sundayschool_id').val();
        var type_id = $('#single_payment_type_id').val();
        var amount = $('#single_amount').val();
        var mode = $('#single_mode').val();
        var date = $('#single_payment_date').val();
        var desc = $('#single_description').val();
        if ((!member_id && !sundayschool_id) || !type_id || !amount || !mode || !date) {
            $('#single-payment-feedback').html('<div class="alert alert-danger">Please fill all required fields.</div>');
            return;
        }
        // Populate modal summary
        $('#confirmSingleType').text($('#single_payment_type_id option:selected').text());
        $('#confirmSingleAmount').text('₵' + parseFloat(amount).toLocaleString(undefined, {minimumFractionDigits:2}));
        $('#confirmSingleMode').text($('#single_mode option:selected').text());
        // Show modal
        $('#singlePaymentConfirmModal').modal('show');
    });
    // Modal confirm button
    $('#confirmSinglePaymentBtn').on('click', function(){
        if ($(this).prop('disabled')) return;
        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...');
        $('#single-payment-feedback').html('<div class="alert alert-info"><i class="fas fa-clock mr-2"></i>Processing payment and sending notifications...</div>');
        var member_id = $('#single_member_id').val();
        var sundayschool_id = $('#single_sundayschool_id').val();
        var type_id = $('#single_payment_type_id').val();
        var amount = $('#single_amount').val();
        var mode = $('#single_mode').val();
        var date = $('#single_payment_date').val();
        var desc = $('#single_description').val();
        var period = $('#single_payment_period').val();
        var period_text = $('#single_payment_period option:selected').text();
        var payload = {payments: [{type_id: type_id, amount: amount, mode: mode, date: date, desc: desc, period: period, period_text: period_text, type_text: $('#single_payment_type_id option:selected').text()}]};
        if (member_id) payload.member_id = member_id;
        if (sundayschool_id) payload.sundayschool_id = sundayschool_id;
        console.log('Making AJAX request to ajax_bulk_payments_single_member.php with payload:', payload);
        $.ajax({
            url: 'ajax_bulk_payments_single_member.php',
            type: 'POST',
            data: JSON.stringify(payload),
            contentType: 'application/json',
            dataType: 'json',
            timeout: 30000, // 30 second timeout for SMS processing
            success: function(resp){
                console.log('Payment AJAX response received:', resp);
                let typeMap = {};
                $('#single_payment_type_id option').each(function(){
                    if ($(this).val()) typeMap[$(this).val()] = $(this).text();
                });
                if (resp.success) {
                    let successMsg = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Payment saved successfully.';
                    if (resp.sms_sent) {
                        successMsg += ' <i class="fas fa-sms text-info" title="SMS notification sent"></i>';
                    }
                    successMsg += '</div>';
                    $('#single-payment-feedback').html(successMsg);
                    setTimeout(function(){ window.location.href = 'payment_list.php?added=1'; }, 1500);
                } else {
                    let msg = resp.msg || 'Error saving payment.';
                    if (resp.failed && Array.isArray(resp.failed)) {
                        msg += '<br><br>Failed payments:';
                        resp.failed.forEach(function(f){
                            let typeName = typeMap[f.type_id] || ('Type ID ' + f.type_id);
                            let reason = f.reason.replace(/type ID (\d+)/i, typeName);
                            msg += `<br>- ${typeName}: ${reason}`;
                        });
                    }
                    $('#single-payment-feedback').html('<div class="alert alert-danger">'+msg+'</div>');
                }
            },
            error: function(xhr, status, err){
                console.log('Payment AJAX request failed:', {xhr: xhr, status: status, error: err});
                console.log('Response text:', xhr.responseText);
                console.log('Response status:', xhr.status);
                let msg = 'Network/server error.';
                if (status === 'timeout') {
                    msg = 'Payment processing is taking longer than expected. Please check the payment list to verify if your payment was recorded.';
                } else if (xhr && xhr.responseText) {
                    try {
                        let resp = JSON.parse(xhr.responseText);
                        msg = resp.msg || msg;
                    } catch(e) {
                        msg = 'Server response error. Please check if payment was recorded.';
                    }
                }
                $('#single-payment-feedback').html('<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i>'+msg+'</div>');
            },
            complete: function(){
                $('#submitSinglePaymentBtn').prop('disabled', false).text('Submit Payment');
                $('#confirmSinglePaymentBtn').prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i>Confirm & Submit');
                $('#singlePaymentConfirmModal').modal('hide');
            }
        });
    });
    // Prevent form submit on Enter
    $('#singlePaymentForm').on('submit', function(e){ e.preventDefault(); });

    // --- Tab switching resets feedback ---
    $('a[data-toggle="tab"]').on('shown.bs.tab', function(e){
        $('#single-payment-feedback').empty();
        $('#bulk-payment-feedback').empty();
    });
});
</script>
<style>
.member-summary-card .icon {
  width: 1.5rem; height: 1.5rem; font-size: 1rem;
}
.member-summary-card .info-label {
  font-size: 0.95rem; color: #888;
}
.member-summary-card .info-value {
  font-size: 1.25rem; font-weight: bold; color: #222;
  line-height: 1.2;
}
.member-summary-card .card-body { padding: 0.9rem 1rem; }
.member-details-row .details-item {
  min-width: 110px;
  display: flex;
  flex-direction: column;
  align-items: center;
}
@media (max-width: 767.98px) {
  .member-details-row {
    flex-direction: row !important;
    flex-wrap: wrap;
    text-align: center;
  }
  .member-details-row .divider {
    display: none !important;
  }
  .member-details-row .details-item {
    margin-right: 0 !important;
    margin-bottom: 0.7rem !important;
  }
}

.member-details-row .divider {
  border-left: 1.5px solid #e0e0e0;
  height: 2.2em;
  margin: 0 8px;
}
.member-details-row .info-label {
  font-size: 0.95rem;
  color: #888;
  font-weight: 600;
  line-height: 1.1;
}
.member-details-row .info-value {
  font-size: 1.15rem;
  font-weight: 500;
  color: #222;
  line-height: 1.2;
}
.member-summary-card .fa-phone-alt { color: #17a2b8; }
</style>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
