<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_reports_dashboard')) {
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
$can_view = true; // Already validated above

ob_start();

$report_categories = [
    'Membership Reports' => [
        [
            'file' => BASE_URL.'/views/reports/details/age_bracket_report.php',
            'title' => 'Age Bracket Report',
            'icon' => 'fa-user-clock',
            'desc' => 'Distribution of members by age bracket.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/organisational_member_report.php',
            'title' => 'Organisational Member Report',
            'icon' => 'fa-users',
            'desc' => 'Members by organization.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/marital_status_report.php',
            'title' => 'Marital Status Report',
            'icon' => 'fa-ring',
            'desc' => 'Members by marital status.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/employment_status_report.php',
            'title' => 'Employment Status Report',
            'icon' => 'fa-briefcase',
            'desc' => 'Members by employment status.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/baptism_report.php',
            'title' => 'Baptism Report',
            'icon' => 'fa-water',
            'desc' => 'Baptized vs non-baptized members.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/confirmation_report.php',
            'title' => 'Confirmation Report',
            'icon' => 'fa-certificate',
            'desc' => 'Confirmed vs non-confirmed members.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/membership_status_report.php',
            'title' => 'Membership Status Report',
            'icon' => 'fa-id-badge',
            'desc' => 'Full, catechumen, adherent, junior members.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/profession_report.php',
            'title' => 'Profession Report',
            'icon' => 'fa-user-tie',
            'desc' => 'Members by profession.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/role_of_service_report.php',
            'title' => 'Role of Service Report',
            'icon' => 'fa-hands-helping',
            'desc' => 'Members by role of service.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/gender_report.php',
            'title' => 'Gender Report',
            'icon' => 'fa-venus-mars',
            'desc' => 'Distribution of members by gender.'
        ]
    ],
    'Payment Reports' => [
        [
            'file' => BASE_URL.'/views/reports/details/bibleclass_payment_report.php',
            'title' => 'Bible Class Payment Report',
            'icon' => 'fa-chalkboard-teacher',
            'desc' => 'Payments grouped by Bible Class.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/organisation_payment_report.php',
            'title' => 'Organisation Payment Report',
            'icon' => 'fa-hand-holding-usd',
            'desc' => 'Payments made by organizations.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/day_born_payment_report.php',
            'title' => 'Day Born Payment Report',
            'icon' => 'fa-calendar-day',
            'desc' => 'Payments by members grouped by day born.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/age_bracket_payment_report.php',
            'title' => 'Age Bracket Payment Report',
            'icon' => 'fa-money-bill-wave',
            'desc' => 'Payments by age bracket.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/individual_payment_report.php',
            'title' => 'Individual Payment Report',
            'icon' => 'fa-user-check',
            'desc' => 'Payments by individual members.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/accumulated_payment_type_report.php',
            'title' => 'Accumulated Payment Type Report',
            'icon' => 'fa-coins',
            'desc' => 'Total payments by type.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/zero_payment_type_report.php',
            'title' => 'Zero Payment Type Report',
            'icon' => 'fa-ban',
            'desc' => 'Members with zero payments for each type.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/payment_made_report.php',
            'title' => 'Payment Made Report',
            'icon' => 'fa-cash-register',
            'desc' => 'All payments made.'
        ]
    ],
    'Health Reports' => [
        [
            'file' => BASE_URL.'/views/reports/details/health_type_report.php',
            'title' => 'Health Type Report',
            'icon' => 'fa-heartbeat',
            'desc' => 'Health data by type.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/class_health_report.php',
            'title' => 'Class Health Report',
            'icon' => 'fa-users-class',
            'desc' => 'Health by class.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/organisational_health_report.php',
            'title' => 'Organisational Health Report',
            'icon' => 'fa-building-heart',
            'desc' => 'Health by organization.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/individual_health_report.php',
            'title' => 'Individual Health Report',
            'icon' => 'fa-user-md',
            'desc' => 'Health of individual members.'
        ]
    ],
    'Date & Registration Reports' => [
        [
            'file' => BASE_URL.'/views/reports/details/registered_by_date_report.php',
            'title' => 'Registered By Date Report',
            'icon' => 'fa-calendar-plus',
            'desc' => 'Members registered by date.'
        ],
        [
            'file' => BASE_URL.'/views/reports/details/date_of_birth_report.php',
            'title' => 'Date of Birth Report',
            'icon' => 'fa-birthday-cake',
            'desc' => 'Members by date of birth.'
        ]
    ]
        ];
?>
<div class="container-fluid mt-4">
  <h2 class="mb-4 font-weight-bold"><i class="fas fa-chart-bar mr-2"></i>Reports Dashboard</h2>
  <?php foreach ($report_categories as $category => $reports): ?>
    <h4 class="mt-4 mb-3 text-primary font-weight-bold"><i class="fas fa-folder-open mr-2"></i><?php echo htmlspecialchars($category); ?></h4>
    <div class="row">
      <?php foreach ($reports as $report): ?>
        <div class="col-lg-4 col-md-6 mb-4">
          <a href="<?php echo htmlspecialchars($report['file']); ?>" class="text-decoration-none">
            <div class="card shadow-sm h-100 border-primary report-card">
              <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                  <span class="mr-3"><i class="fas <?php echo $report['icon']; ?> fa-2x text-primary"></i></span>
                  <span class="h5 mb-0 text-dark"> <?php echo htmlspecialchars($report['title']); ?> </span>
                </div>
                <div class="text-muted small"> <?php echo htmlspecialchars($report['desc']); ?> </div>
              </div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
<style>
.report-card:hover {
  border-color: #0056b3 !important;
  box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25) !important;
  transform: translateY(-2px) scale(1.01);
  transition: all 0.15s;
}
</style>
<?php $page_content = ob_get_clean(); include __DIR__.'/../includes/layout.php'; ?>
