<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$reportCentreIsSuperAdmin = is_super_admin();
if (!$reportCentreIsSuperAdmin && !has_permission('view_reports_dashboard')) {
    http_response_code(403);
    $forbiddenPage = __DIR__ . '/errors/403.php';
    if (is_file($forbiddenPage)) {
        include $forbiddenPage;
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access the report centre.</p></div>';
    }
    exit;
}

function report_centre_can_open(array $permissions, bool $isSuperAdmin): bool
{
    if ($isSuperAdmin) {
        return true;
    }

    foreach ($permissions as $permission) {
        if (has_permission($permission)) {
            return true;
        }
    }

    return false;
}

function report_centre_url(string $path): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

$reportCatalogue = [
    'core' => [
        'title' => 'Operational reports',
        'description' => 'Church-wide registers, activity summaries and oversight reports used in day-to-day administration.',
        'icon' => 'fas fa-chart-line',
        'reports' => [
            ['title' => 'Membership Report', 'description' => 'Review the membership register using church, class, organization and profile filters.', 'path' => 'views/reports/membership_report.php', 'icon' => 'fas fa-address-book', 'permissions' => ['view_membership_report'], 'label' => 'Core'],
            ['title' => 'Attendance Report', 'description' => 'Analyse member and Sunday School attendance across sessions and reporting categories.', 'path' => 'views/reports/attendance_report.php', 'icon' => 'fas fa-calendar-check', 'permissions' => ['view_attendance_report'], 'label' => 'Core'],
            ['title' => 'Payment Report', 'description' => 'Filter, reconcile and export payment activity by date and reporting period.', 'path' => 'views/reports/payment_report.php', 'icon' => 'fas fa-receipt', 'permissions' => ['view_payment_report'], 'label' => 'Core'],
            ['title' => 'Event Report', 'description' => 'Track church events, registration activity and attendance outcomes.', 'path' => 'views/reports/event_report.php', 'icon' => 'fas fa-calendar-alt', 'permissions' => ['view_event_report'], 'label' => 'Operations'],
            ['title' => 'Visitor Report', 'description' => 'Monitor visitor records, follow-up activity and conversion progress.', 'path' => 'views/reports/visitor_report.php', 'icon' => 'fas fa-user-friends', 'permissions' => ['view_visitor_report'], 'label' => 'Operations'],
            ['title' => 'Health Report', 'description' => 'Review authorized health screening activity and summary measures.', 'path' => 'views/reports/health_report.php', 'icon' => 'fas fa-heartbeat', 'permissions' => ['view_health_report'], 'label' => 'Sensitive'],
            ['title' => 'SMS Delivery Report', 'description' => 'Inspect message delivery results, failures and provider evidence.', 'path' => 'views/reports/sms_report.php', 'icon' => 'fas fa-sms', 'permissions' => ['view_sms_report'], 'label' => 'Operations'],
            ['title' => 'Feedback Report', 'description' => 'Review submitted feedback and its response status.', 'path' => 'views/reports/feedback_report.php', 'icon' => 'fas fa-comment-dots', 'permissions' => ['view_feedback_report'], 'label' => 'Operations'],
            ['title' => 'Audit Report', 'description' => 'Inspect authorized system activity and accountability records.', 'path' => 'views/reports/audit_report.php', 'icon' => 'fas fa-shield-alt', 'permissions' => ['view_audit_report'], 'label' => 'Governance'],
            ['title' => 'Statistical Events Register', 'description' => 'Record and review naming, transfer, marriage and death statistics used in church reporting.', 'path' => 'views/church_statistical_events.php', 'icon' => 'fas fa-clipboard-list', 'permissions' => ['manage_church_statistical_events'], 'label' => 'Governance'],
        ],
    ],
    'membership' => [
        'title' => 'Membership and demographics',
        'description' => 'Understand the composition, status and ministry participation of the congregation.',
        'icon' => 'fas fa-users',
        'reports' => [
            ['title' => 'Age Bracket Report', 'description' => 'Member distribution across configured age ranges.', 'path' => 'views/reports/details/age_bracket_report.php', 'icon' => 'fas fa-chart-pie', 'permissions' => ['view_age_bracket_report']],
            ['title' => 'Organizational Member Report', 'description' => 'Membership assignments grouped by organization.', 'path' => 'views/reports/details/organisational_member_report.php', 'icon' => 'fas fa-sitemap', 'permissions' => ['view_organisational_member_report']],
            ['title' => 'Bible Class Members Report', 'description' => 'Members grouped by their current Bible Class.', 'path' => 'views/reports/details/bibleclass_members_report.php', 'icon' => 'fas fa-book-open', 'permissions' => ['view_bibleclass_members_report']],
            ['title' => 'Gender Report', 'description' => 'Membership distribution by recorded gender.', 'path' => 'views/reports/details/gender_report.php', 'icon' => 'fas fa-venus-mars', 'permissions' => ['view_gender_report']],
            ['title' => 'Marital Status Report', 'description' => 'Membership distribution by marital status.', 'path' => 'views/reports/details/marital_status_report.php', 'icon' => 'fas fa-ring', 'permissions' => ['view_marital_status_report']],
            ['title' => 'Employment Status Report', 'description' => 'Employment status and workforce composition.', 'path' => 'views/reports/details/employment_status_report.php', 'icon' => 'fas fa-briefcase', 'permissions' => ['view_employment_status_report']],
            ['title' => 'Profession Report', 'description' => 'Members grouped by recorded profession.', 'path' => 'views/reports/details/profession_report.php', 'icon' => 'fas fa-user-tie', 'permissions' => ['view_profession_report']],
            ['title' => 'Membership Status Report', 'description' => 'Full, catechumen, adherent and junior membership totals.', 'path' => 'views/reports/details/membership_status_report.php', 'icon' => 'fas fa-id-badge', 'permissions' => ['view_membership_status_report']],
            ['title' => 'Baptism Report', 'description' => 'Baptism status across the membership register.', 'path' => 'views/reports/details/baptism_report.php', 'icon' => 'fas fa-water', 'permissions' => ['view_baptism_report']],
            ['title' => 'Confirmation Report', 'description' => 'Confirmation status across the membership register.', 'path' => 'views/reports/details/confirmation_report.php', 'icon' => 'fas fa-certificate', 'permissions' => ['view_confirmation_report']],
            ['title' => 'Role of Serving Report', 'description' => 'Current role holders and ministry participation.', 'path' => 'views/reports/details/role_of_service_report.php', 'icon' => 'fas fa-hands-helping', 'permissions' => ['view_role_of_service_report']],
        ],
    ],
    'payments' => [
        'title' => 'Payments and stewardship',
        'description' => 'Detailed financial analysis with reporting-period, beneficiary and collection dimensions.',
        'icon' => 'fas fa-coins',
        'reports' => [
            ['title' => 'Payment Made Report', 'description' => 'Detailed ledger of posted payments and their reporting periods.', 'path' => 'views/reports/details/payment_made_report.php', 'icon' => 'fas fa-cash-register', 'permissions' => ['view_payment_made_report']],
            ['title' => 'Individual Payment Report', 'description' => 'Statements and payment history for individual beneficiaries.', 'path' => 'views/reports/details/individual_payment_report.php', 'icon' => 'fas fa-user-check', 'permissions' => ['view_individual_payment_report']],
            ['title' => 'Bible Class Payment Report', 'description' => 'Payment performance grouped by Bible Class.', 'path' => 'views/reports/details/bibleclass_payment_report.php', 'icon' => 'fas fa-book', 'permissions' => ['view_bibleclass_payment_report']],
            ['title' => 'Organization Payment Report', 'description' => 'Payment performance grouped by organization.', 'path' => 'views/reports/details/organisation_payment_report.php', 'icon' => 'fas fa-building', 'permissions' => ['view_organisation_payment_report']],
            ['title' => 'Accumulated Payment Type Report', 'description' => 'Accumulated totals grouped by payment type.', 'path' => 'views/reports/details/accumulated_payment_type_report.php', 'icon' => 'fas fa-layer-group', 'permissions' => ['view_accumulated_payment_type_report']],
            ['title' => 'Zero Payment Type Report', 'description' => 'Members with no qualifying payment for selected types and periods.', 'path' => 'views/reports/details/zero_payment_type_report.php', 'icon' => 'fas fa-user-slash', 'permissions' => ['view_zero_payment_type_report']],
            ['title' => 'Age Bracket Payment Report', 'description' => 'Payment totals analysed by member age bracket.', 'path' => 'views/reports/details/age_bracket_payment_report.php', 'icon' => 'fas fa-chart-bar', 'permissions' => ['view_age_bracket_payment_report']],
            ['title' => 'Day Born Payment Report', 'description' => 'Payment totals grouped by members’ day born.', 'path' => 'views/reports/details/day_born_payment_report.php', 'icon' => 'fas fa-calendar-day', 'permissions' => ['view_day_born_payment_report']],
            ['title' => 'Payments by User Report', 'description' => 'Posted payment activity grouped by the responsible user.', 'path' => 'views/reports/details/payments_by_user_report.php', 'icon' => 'fas fa-user-circle', 'permissions' => ['view_payments_by_user_report', 'view_payment_list']],
            ['title' => 'Payment Mode vs Type', 'description' => 'Cross-analysis of payment channels and payment types.', 'path' => 'views/reports/details/payment_mode_vs_payment_type_report.php', 'icon' => 'fas fa-table', 'permissions' => ['view_payment_report', 'view_payment_made_report', 'view_payment_list']],
        ],
    ],
    'health' => [
        'title' => 'Health and wellbeing',
        'description' => 'Permission-scoped health summaries for individuals, classes and organizations.',
        'icon' => 'fas fa-heart',
        'reports' => [
            ['title' => 'Health Type Report', 'description' => 'Health records grouped by screening or record type.', 'path' => 'views/reports/details/health_type_report.php', 'icon' => 'fas fa-notes-medical', 'permissions' => ['view_health_type_report']],
            ['title' => 'Class Health Report', 'description' => 'Health activity grouped by Bible Class.', 'path' => 'views/reports/details/class_health_report.php', 'icon' => 'fas fa-user-friends', 'permissions' => ['view_class_health_report']],
            ['title' => 'Organizational Health Report', 'description' => 'Health activity grouped by organization.', 'path' => 'views/reports/details/organisational_health_report.php', 'icon' => 'fas fa-clinic-medical', 'permissions' => ['view_organisational_health_report']],
            ['title' => 'Individual Health Report', 'description' => 'Authorized longitudinal health records for an individual.', 'path' => 'views/reports/details/individual_health_report.php', 'icon' => 'fas fa-user-md', 'permissions' => ['view_individual_health_report']],
        ],
    ],
    'registration' => [
        'title' => 'Dates and registration',
        'description' => 'Registration timing, birthdays and date-oriented membership analysis.',
        'icon' => 'fas fa-calendar',
        'reports' => [
            ['title' => 'Registered by Date Report', 'description' => 'Members registered within a selected date range.', 'path' => 'views/reports/details/registered_by_date_report.php', 'icon' => 'fas fa-calendar-plus', 'permissions' => ['view_registered_by_date_report']],
            ['title' => 'Date of Birth Report', 'description' => 'Birthdays and date-of-birth records within a selected period.', 'path' => 'views/reports/details/date_of_birth_report.php', 'icon' => 'fas fa-birthday-cake', 'permissions' => ['view_date_of_birth_report']],
        ],
    ],
];

$accessibleReportCount = 0;
foreach ($reportCatalogue as $categoryKey => &$category) {
    $category['reports'] = array_values(array_filter(
        $category['reports'],
        static function (array $report) use ($reportCentreIsSuperAdmin): bool {
            return report_centre_can_open($report['permissions'], $reportCentreIsSuperAdmin);
        }
    ));
    $category['count'] = count($category['reports']);
    $accessibleReportCount += $category['count'];
}
unset($category);
$reportCatalogue = array_filter($reportCatalogue, static fn(array $category): bool => $category['count'] > 0);

$page_title = 'Report Centre';
ob_start();
?>
<div class="report-centre">
    <header class="report-centre-hero">
        <div class="report-centre-hero-copy">
            <span class="report-centre-eyebrow"><i class="fas fa-chart-pie" aria-hidden="true"></i> Reporting workspace</span>
            <h1>Report Centre</h1>
            <p>Find operational reports, explore trends and open only the information authorized for your role.</p>
        </div>
        <div class="report-centre-summary" aria-label="Report centre summary">
            <div><strong><?= (int) $accessibleReportCount ?></strong><span>Available reports</span></div>
            <div><strong><?= count($reportCatalogue) ?></strong><span>Categories</span></div>
        </div>
    </header>

    <section class="report-centre-toolbar" aria-label="Find a report">
        <div class="report-centre-search">
            <i class="fas fa-search" aria-hidden="true"></i>
            <label class="sr-only" for="reportCentreSearch">Search reports</label>
            <input id="reportCentreSearch" type="search" autocomplete="off" placeholder="Search by report name or purpose...">
            <button type="button" id="reportCentreClear" aria-label="Clear report search" hidden><i class="fas fa-times" aria-hidden="true"></i></button>
        </div>
        <div class="report-centre-filters" role="group" aria-label="Filter reports by category">
            <button type="button" class="active" data-report-filter="all" aria-pressed="true">All <span><?= (int) $accessibleReportCount ?></span></button>
            <?php foreach ($reportCatalogue as $categoryKey => $category): ?>
                <button type="button" data-report-filter="<?= htmlspecialchars($categoryKey, ENT_QUOTES, 'UTF-8') ?>" aria-pressed="false">
                    <?= htmlspecialchars($category['title'], ENT_QUOTES, 'UTF-8') ?> <span><?= (int) $category['count'] ?></span>
                </button>
            <?php endforeach; ?>
        </div>
        <p class="report-centre-result-count" id="reportCentreResultCount" aria-live="polite">
            Showing <?= (int) $accessibleReportCount ?> authorized reports
        </p>
    </section>

    <div id="reportCentreSections">
        <?php foreach ($reportCatalogue as $categoryKey => $category): ?>
            <section class="report-centre-section" data-report-category="<?= htmlspecialchars($categoryKey, ENT_QUOTES, 'UTF-8') ?>">
                <div class="report-centre-section-heading">
                    <span class="report-centre-section-icon"><i class="<?= htmlspecialchars($category['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i></span>
                    <div>
                        <h2><?= htmlspecialchars($category['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <p><?= htmlspecialchars($category['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <span class="report-centre-section-count"><?= (int) $category['count'] ?></span>
                </div>
                <div class="report-centre-grid">
                    <?php foreach ($category['reports'] as $report):
                        $searchText = strtolower($report['title'] . ' ' . $report['description'] . ' ' . $category['title']);
                    ?>
                        <a class="report-centre-card"
                           href="<?= htmlspecialchars(report_centre_url($report['path']), ENT_QUOTES, 'UTF-8') ?>"
                           data-report-card
                           data-report-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">
                            <span class="report-centre-card-icon"><i class="<?= htmlspecialchars($report['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i></span>
                            <span class="report-centre-card-copy">
                                <span class="report-centre-card-topline">
                                    <strong><?= htmlspecialchars($report['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if (!empty($report['label'])): ?><small><?= htmlspecialchars($report['label'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?>
                                </span>
                                <span class="report-centre-card-description"><?= htmlspecialchars($report['description'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="report-centre-card-action">Open report <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="report-centre-empty" id="reportCentreEmpty" hidden>
        <span><i class="fas fa-search" aria-hidden="true"></i></span>
        <h2>No reports found</h2>
        <p>Try another keyword or return to all report categories.</p>
        <button type="button" class="btn btn-primary" id="reportCentreReset">Show all reports</button>
    </div>
</div>

<style>
.report-centre{--rc-navy:#173f5f;--rc-green:#236b45;--rc-ink:#183044;--rc-muted:#647789;--rc-line:#dfe7ed;--rc-soft:#f4f7f9;padding:24px;max-width:1540px;margin:0 auto;color:var(--rc-ink)}
.report-centre-hero{position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:28px;padding:32px 36px;border-radius:22px;background:linear-gradient(125deg,#153b59 0%,#1c5367 56%,#236b45 100%);color:#fff;box-shadow:0 18px 42px rgba(23,63,95,.18)}
.report-centre-hero:after{content:"";position:absolute;right:-75px;top:-115px;width:300px;height:300px;border:52px solid rgba(255,255,255,.07);border-radius:50%}
.report-centre-hero-copy{position:relative;z-index:1;max-width:750px}.report-centre-eyebrow{display:inline-flex;align-items:center;gap:8px;margin-bottom:12px;font-size:.76rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#d4ede2}.report-centre-hero h1{margin:0 0 8px;font-size:2.15rem;font-weight:750;letter-spacing:-.035em}.report-centre-hero p{max-width:700px;margin:0;color:rgba(255,255,255,.82);font-size:1rem;line-height:1.65}
.report-centre-summary{position:relative;z-index:1;display:flex;align-items:stretch;min-width:300px;border:1px solid rgba(255,255,255,.16);border-radius:16px;background:rgba(255,255,255,.09);backdrop-filter:blur(6px)}.report-centre-summary div{flex:1;padding:18px 22px}.report-centre-summary div+div{border-left:1px solid rgba(255,255,255,.15)}.report-centre-summary strong,.report-centre-summary span{display:block}.report-centre-summary strong{font-size:1.7rem;line-height:1.1}.report-centre-summary span{margin-top:5px;color:rgba(255,255,255,.75);font-size:.76rem;white-space:nowrap}
.report-centre-toolbar{margin:20px 0 26px;padding:18px;border:1px solid var(--rc-line);border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(23,63,95,.06)}.report-centre-search{position:relative}.report-centre-search>i{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:#75889a}.report-centre-search input{width:100%;height:48px;padding:0 48px;border:1px solid #cfdbe3;border-radius:12px;background:#f9fbfc;color:var(--rc-ink);font-size:.95rem;outline:none;transition:border-color .18s,box-shadow .18s,background .18s}.report-centre-search input:focus{border-color:#3f7b99;background:#fff;box-shadow:0 0 0 3px rgba(63,123,153,.14)}.report-centre-search button{position:absolute;right:8px;top:8px;width:32px;height:32px;border:0;border-radius:8px;background:transparent;color:#6c7e8d}.report-centre-search button:hover{background:#eaf0f4;color:var(--rc-ink)}
.report-centre-filters{display:flex;gap:8px;margin-top:14px;padding-bottom:2px;overflow-x:auto}.report-centre-filters button{flex:0 0 auto;border:1px solid #d8e2e8;border-radius:999px;padding:8px 12px;background:#fff;color:#52697b;font-size:.8rem;font-weight:650;transition:.18s}.report-centre-filters button span{display:inline-flex;align-items:center;justify-content:center;min-width:21px;height:21px;margin-left:5px;padding:0 6px;border-radius:999px;background:#edf2f5;font-size:.7rem}.report-centre-filters button:hover{border-color:#93acba;color:var(--rc-navy)}.report-centre-filters button.active{border-color:var(--rc-navy);background:var(--rc-navy);color:#fff}.report-centre-filters button.active span{background:rgba(255,255,255,.18)}.report-centre-result-count{margin:12px 2px 0;color:var(--rc-muted);font-size:.8rem}
.report-centre-section{margin-bottom:28px;padding:23px;border:1px solid var(--rc-line);border-radius:20px;background:#fff;box-shadow:0 9px 28px rgba(29,61,82,.055)}.report-centre-section-heading{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:14px;margin-bottom:19px}.report-centre-section-icon{display:inline-flex;align-items:center;justify-content:center;width:46px;height:46px;border-radius:13px;background:#eaf2f5;color:var(--rc-navy);font-size:1.05rem}.report-centre-section-heading h2{margin:0 0 3px;font-size:1.18rem;font-weight:750}.report-centre-section-heading p{margin:0;color:var(--rc-muted);font-size:.86rem}.report-centre-section-count{min-width:34px;padding:5px 9px;border-radius:999px;background:#eef5f1;color:var(--rc-green);font-size:.75rem;font-weight:750;text-align:center}
.report-centre-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px}.report-centre-card{display:flex;gap:14px;min-height:166px;padding:18px;border:1px solid #e1e8ed;border-radius:15px;background:#fff;color:inherit;text-decoration:none!important;transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease}.report-centre-card:hover,.report-centre-card:focus{transform:translateY(-3px);border-color:#9bb7c6;color:inherit;box-shadow:0 12px 26px rgba(23,63,95,.11);outline:0}.report-centre-card-icon{display:inline-flex;flex:0 0 auto;align-items:center;justify-content:center;width:43px;height:43px;border-radius:12px;background:var(--rc-soft);color:var(--rc-navy);font-size:1rem;transition:.18s}.report-centre-card:hover .report-centre-card-icon,.report-centre-card:focus .report-centre-card-icon{background:var(--rc-navy);color:#fff}.report-centre-card-copy{display:flex;min-width:0;flex:1;flex-direction:column}.report-centre-card-topline{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}.report-centre-card-topline strong{font-size:.95rem;line-height:1.35}.report-centre-card-topline small{flex:0 0 auto;padding:3px 7px;border-radius:999px;background:#e8f2ec;color:var(--rc-green);font-size:.61rem;font-weight:750;text-transform:uppercase;letter-spacing:.035em}.report-centre-card-description{display:block;margin-top:7px;color:var(--rc-muted);font-size:.79rem;line-height:1.52}.report-centre-card-action{display:flex;align-items:center;gap:7px;margin-top:auto;padding-top:13px;color:var(--rc-navy);font-size:.75rem;font-weight:750}.report-centre-card-action i{font-size:.66rem;transition:transform .18s}.report-centre-card:hover .report-centre-card-action i{transform:translateX(3px)}
.report-centre-empty{padding:55px 20px;border:1px dashed #bdcbd4;border-radius:20px;background:#fff;text-align:center}.report-centre-empty>span{display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;background:#edf3f6;color:var(--rc-navy);font-size:1.2rem}.report-centre-empty h2{margin:14px 0 5px;font-size:1.2rem}.report-centre-empty p{color:var(--rc-muted)}
@media(max-width:1199.98px){.report-centre-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:767.98px){.report-centre{padding:14px}.report-centre-hero{align-items:flex-start;flex-direction:column;padding:25px 22px;border-radius:17px}.report-centre-hero h1{font-size:1.7rem}.report-centre-summary{width:100%;min-width:0}.report-centre-toolbar{padding:13px}.report-centre-section{padding:17px}.report-centre-section-heading{align-items:start}.report-centre-section-heading p{line-height:1.45}.report-centre-grid{grid-template-columns:1fr}}
@media(max-width:420px){.report-centre-card{min-height:0}.report-centre-summary div{padding:14px}.report-centre-summary strong{font-size:1.35rem}.report-centre-section-count{display:none}}
@media(prefers-reduced-motion:reduce){.report-centre-card,.report-centre-card-icon,.report-centre-card-action i{transition:none}.report-centre-card:hover,.report-centre-card:focus{transform:none}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var search = document.getElementById('reportCentreSearch');
    var clear = document.getElementById('reportCentreClear');
    var reset = document.getElementById('reportCentreReset');
    var empty = document.getElementById('reportCentreEmpty');
    var resultCount = document.getElementById('reportCentreResultCount');
    var filterButtons = Array.prototype.slice.call(document.querySelectorAll('[data-report-filter]'));
    var sections = Array.prototype.slice.call(document.querySelectorAll('[data-report-category]'));
    var activeFilter = 'all';

    function normalize(value) {
        return String(value || '').toLocaleLowerCase().trim();
    }

    function applyFilters() {
        var term = normalize(search.value);
        var visibleTotal = 0;

        sections.forEach(function (section) {
            var categoryMatches = activeFilter === 'all' || section.getAttribute('data-report-category') === activeFilter;
            var visibleInSection = 0;
            Array.prototype.forEach.call(section.querySelectorAll('[data-report-card]'), function (card) {
                var matches = categoryMatches && (!term || normalize(card.getAttribute('data-report-search')).indexOf(term) !== -1);
                card.hidden = !matches;
                if (matches) visibleInSection += 1;
            });
            section.hidden = visibleInSection === 0;
            visibleTotal += visibleInSection;
        });

        clear.hidden = term === '';
        empty.hidden = visibleTotal !== 0;
        resultCount.textContent = 'Showing ' + visibleTotal + (visibleTotal === 1 ? ' authorized report' : ' authorized reports');
    }

    filterButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            activeFilter = button.getAttribute('data-report-filter') || 'all';
            filterButtons.forEach(function (candidate) {
                var selected = candidate === button;
                candidate.classList.toggle('active', selected);
                candidate.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            applyFilters();
        });
    });

    search.addEventListener('input', applyFilters);
    clear.addEventListener('click', function () {
        search.value = '';
        search.focus();
        applyFilters();
    });
    reset.addEventListener('click', function () {
        search.value = '';
        activeFilter = 'all';
        filterButtons.forEach(function (button) {
            var selected = button.getAttribute('data-report-filter') === 'all';
            button.classList.toggle('active', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        applyFilters();
        search.focus();
    });
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
