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

// Enhanced report categories with color themes and additional metadata
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
        ],
        [
            'file' => BASE_URL.'/views/reports/details/bibleclass_members_report.php',
            'title' => 'Bible Class Members Report',
            'icon' => 'fa-chalkboard-teacher',
            'desc' => 'Members by Bible Class.'
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
<!-- Modern Reports Dashboard -->
<div class="reports-dashboard">
  <!-- Hero Section -->
  <div class="dashboard-hero">
    <div class="container-fluid">
      <div class="row align-items-center">
        <div class="col-lg-8">
          <h1 class="hero-title">
            <i class="fas fa-chart-line hero-icon"></i>
            Reports Dashboard
          </h1>
          <p class="hero-subtitle">Comprehensive analytics and insights for your church management</p>
        </div>
        <div class="col-lg-4 text-right d-none d-lg-block">
          <div class="hero-stats">
            <div class="stat-item">
              <span class="stat-number"><?php echo count($report_categories); ?></span>
              <span class="stat-label">Categories</span>
            </div>
            <div class="stat-item">
              <span class="stat-number"><?php echo array_sum(array_map('count', $report_categories)); ?></span>
              <span class="stat-label">Reports</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Reports Grid -->
  <div class="container-fluid reports-content">
    <?php 
    $category_colors = [
      'Membership Reports' => ['primary' => '#ff6b6b', 'secondary' => '#feca57', 'accent' => '#ff9ff3', 'icon' => 'fa-users'],
      'Payment Reports' => ['primary' => '#5f27cd', 'secondary' => '#00d2d3', 'accent' => '#ff9ff3', 'icon' => 'fa-credit-card'],
      'Health Reports' => ['primary' => '#00d2d3', 'secondary' => '#ff9f43', 'accent' => '#54a0ff', 'icon' => 'fa-heartbeat'],
      'Date & Registration Reports' => ['primary' => '#1dd1a1', 'secondary' => '#feca57', 'accent' => '#ff6b6b', 'icon' => 'fa-calendar-alt']
    ];
    
    foreach ($report_categories as $category => $reports): 
      $colors = $category_colors[$category] ?? ['primary' => '#667eea', 'secondary' => '#764ba2', 'icon' => 'fa-folder'];
    ?>
      <!-- Category Section -->
      <div class="category-section" data-aos="fade-up">
        <div class="category-header">
          <div class="category-icon" style="background: linear-gradient(135deg, <?php echo $colors['primary']; ?>, <?php echo $colors['secondary']; ?>);">
            <i class="fas <?php echo $colors['icon']; ?>"></i>
          </div>
          <div class="category-info">
            <h3 class="category-title"><?php echo htmlspecialchars($category); ?></h3>
            <p class="category-count"><?php echo count($reports); ?> reports available</p>
          </div>
        </div>
        
        <div class="reports-grid">
          <?php foreach ($reports as $index => $report): ?>
            <div class="report-card-wrapper" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
              <a href="<?php echo htmlspecialchars($report['file']); ?>" class="report-card-link">
                <div class="report-card">
                  <div class="card-gradient" style="background: linear-gradient(135deg, <?php echo $colors['primary']; ?>15, <?php echo $colors['secondary']; ?>15);"></div>
                  <div class="card-content">
                    <div class="card-icon">
                      <i class="fas <?php echo $report['icon']; ?>" style="color: <?php echo $colors['primary']; ?>;"></i>
                    </div>
                    <h4 class="card-title"><?php echo htmlspecialchars($report['title']); ?></h4>
                    <p class="card-description"><?php echo htmlspecialchars($report['desc']); ?></p>
                    <div class="card-action">
                      <span class="action-text">View Report</span>
                      <i class="fas fa-arrow-right action-icon"></i>
                    </div>
                  </div>
                  <div class="card-hover-effect"></div>
                </div>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<!-- Modern Styles -->
<style>
/* Reports Dashboard Styles */
.reports-dashboard {
  min-height: 100vh;
  background: linear-gradient(135deg, #ff6b6b 0%, #feca57 25%, #48dbfb 50%, #ff9ff3 75%, #54a0ff 100%);
  background-size: 400% 400%;
  animation: gradientShift 15s ease infinite;
  position: relative;
}

.reports-dashboard::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 350px;
  background: linear-gradient(135deg, #ff6b6b 0%, #5f27cd 25%, #00d2d3 50%, #1dd1a1 75%, #feca57 100%);
  background-size: 400% 400%;
  animation: gradientShift 20s ease infinite;
  z-index: -1;
}

@keyframes gradientShift {
  0% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
  100% { background-position: 0% 50%; }
}

/* Hero Section */
.dashboard-hero {
  padding: 2rem 0 3rem;
  color: white;
  position: relative;
  z-index: 1;
}

.hero-title {
  font-size: 2.5rem;
  font-weight: 700;
  margin-bottom: 0.5rem;
  text-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.hero-icon {
  background: rgba(255,255,255,0.2);
  padding: 0.5rem;
  border-radius: 12px;
  margin-right: 1rem;
}

.hero-subtitle {
  font-size: 1.1rem;
  opacity: 0.9;
  margin-bottom: 0;
}

.hero-stats {
  display: flex;
  gap: 2rem;
}

.stat-item {
  text-align: center;
}

.stat-number {
  display: block;
  font-size: 2rem;
  font-weight: 700;
  line-height: 1;
}

.stat-label {
  font-size: 0.9rem;
  opacity: 0.8;
}

/* Reports Content */
.reports-content {
  padding: 2rem 0;
  position: relative;
  z-index: 2;
}

/* Category Section */
.category-section {
  margin-bottom: 3rem;
  background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.9) 100%);
  border-radius: 25px;
  padding: 2.5rem;
  box-shadow: 0 15px 40px rgba(0,0,0,0.15), 0 0 0 1px rgba(255,255,255,0.1);
  backdrop-filter: blur(20px);
  border: 3px solid;
  border-image: linear-gradient(45deg, #ff6b6b, #feca57, #48dbfb, #ff9ff3, #54a0ff, #1dd1a1) 1;
  position: relative;
  overflow: hidden;
}

.category-section::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 5px;
  background: linear-gradient(90deg, #ff6b6b, #feca57, #48dbfb, #ff9ff3, #54a0ff, #1dd1a1);
  background-size: 300% 100%;
  animation: rainbowMove 3s linear infinite;
}

@keyframes rainbowMove {
  0% { background-position: 0% 50%; }
  100% { background-position: 300% 50%; }
}

.category-header {
  display: flex;
  align-items: center;
  margin-bottom: 2rem;
  padding-bottom: 1rem;
  border-bottom: 2px solid #f8f9fa;
}

.category-icon {
  width: 60px;
  height: 60px;
  border-radius: 15px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 1.5rem;
  box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

.category-icon i {
  font-size: 1.5rem;
  color: white;
}

.category-title {
  font-size: 1.5rem;
  font-weight: 600;
  margin-bottom: 0.25rem;
  color: #2d3748;
}

.category-count {
  color: #718096;
  margin-bottom: 0;
  font-size: 0.9rem;
}

/* Reports Grid */
.reports-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 1.5rem;
}

.report-card-wrapper {
  position: relative;
}

.report-card-link {
  text-decoration: none;
  color: inherit;
  display: block;
}

.report-card {
  background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.85) 100%);
  border-radius: 20px;
  padding: 1.75rem;
  position: relative;
  overflow: hidden;
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  border: 2px solid transparent;
  background-clip: padding-box;
  height: 100%;
  cursor: pointer;
  box-shadow: 0 8px 25px rgba(0,0,0,0.1), 0 0 0 1px rgba(255,255,255,0.1);
}

.report-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: linear-gradient(45deg, #ff6b6b, #feca57, #48dbfb, #ff9ff3, #54a0ff, #1dd1a1);
  background-size: 400% 400%;
  animation: gradientShift 8s ease infinite;
  z-index: -1;
  margin: -2px;
  border-radius: 22px;
  opacity: 0;
  transition: opacity 0.3s ease;
}

.card-gradient {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  border-radius: 16px 16px 0 0;
}

.card-content {
  position: relative;
  z-index: 2;
}

.card-icon {
  width: 55px;
  height: 55px;
  border-radius: 15px;
  background: linear-gradient(135deg, #ff9ff3 0%, #54a0ff 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1rem;
  transition: all 0.4s ease;
  box-shadow: 0 4px 15px rgba(255, 159, 243, 0.3);
  position: relative;
  overflow: hidden;
}

.card-icon::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: linear-gradient(45deg, #ff6b6b, #feca57, #48dbfb, #ff9ff3, #54a0ff, #1dd1a1);
  background-size: 400% 400%;
  animation: gradientShift 6s ease infinite;
  opacity: 0;
  transition: opacity 0.3s ease;
}

.card-icon i {
  color: white;
  position: relative;
  z-index: 2;
}

.card-icon i {
  font-size: 1.25rem;
  transition: all 0.3s ease;
}

.card-title {
  font-size: 1.1rem;
  font-weight: 600;
  margin-bottom: 0.5rem;
  color: #2d3748;
  line-height: 1.4;
}

.card-description {
  color: #718096;
  font-size: 0.9rem;
  line-height: 1.5;
  margin-bottom: 1rem;
}

.card-action {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 1rem;
  border-top: 1px solid #f1f5f9;
}

.action-text {
  font-weight: 500;
  color: #4a5568;
  font-size: 0.9rem;
}

.action-icon {
  font-size: 0.8rem;
  color: #a0aec0;
  transition: all 0.3s ease;
}

.card-hover-effect {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(118, 75, 162, 0.05));
  opacity: 0;
  transition: all 0.3s ease;
  border-radius: 16px;
}

/* Hover Effects */
.report-card:hover {
  transform: translateY(-12px) scale(1.03);
  box-shadow: 0 25px 50px rgba(0,0,0,0.2), 0 0 30px rgba(255, 107, 107, 0.3);
  border-color: transparent;
}

.report-card:hover::before {
  opacity: 1;
}

.report-card:hover .card-hover-effect {
  opacity: 1;
  background: linear-gradient(135deg, rgba(255, 107, 107, 0.1), rgba(254, 202, 87, 0.1), rgba(72, 219, 251, 0.1));
}

.report-card:hover .card-icon {
  transform: scale(1.15) rotate(5deg);
  box-shadow: 0 8px 25px rgba(255, 159, 243, 0.5);
}

.report-card:hover .card-icon::before {
  opacity: 1;
}

.report-card:hover .action-icon {
  transform: translateX(8px) scale(1.2);
  color: #ff6b6b;
}

.report-card:hover .action-text {
  color: #ff6b6b;
  font-weight: 600;
}

.report-card:hover .card-title {
  background: linear-gradient(45deg, #ff6b6b, #feca57, #48dbfb);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

/* Responsive Design */
@media (max-width: 768px) {
  .hero-title {
    font-size: 2rem;
  }
  
  .hero-stats {
    display: none;
  }
  
  .category-section {
    padding: 1.5rem;
    margin-bottom: 2rem;
  }
  
  .category-header {
    flex-direction: column;
    text-align: center;
    gap: 1rem;
  }
  
  .category-icon {
    margin-right: 0;
  }
  
  .reports-grid {
    grid-template-columns: 1fr;
    gap: 1rem;
  }
  
  .report-card {
    padding: 1.25rem;
  }
}

@media (max-width: 576px) {
  .dashboard-hero {
    padding: 1.5rem 0 2rem;
  }
  
  .hero-title {
    font-size: 1.75rem;
  }
  
  .category-section {
    padding: 1rem;
    border-radius: 15px;
  }
}

/* Animation Classes */
[data-aos] {
  opacity: 0;
  transition-property: opacity, transform;
}

[data-aos].aos-animate {
  opacity: 1;
}

[data-aos="fade-up"] {
  transform: translateY(30px);
}

[data-aos="fade-up"].aos-animate {
  transform: translateY(0);
}

/* Loading Animation */
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

.loading {
  animation: pulse 2s infinite;
}
</style>

<!-- AOS Animation Library -->
<link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    AOS.init({
      duration: 600,
      easing: 'ease-out-cubic',
      once: true,
      offset: 50
    });
  });
</script>
<?php $page_content = ob_get_clean(); include __DIR__.'/../includes/layout.php'; ?>
