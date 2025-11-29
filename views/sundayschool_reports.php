<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Authentication check
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
if (!has_permission('view_sundayschool_list')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}

// Get filter parameters
$church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;
$gender = isset($_GET['gender']) ? $_GET['gender'] : '';
$age_from = isset($_GET['age_from']) ? intval($_GET['age_from']) : 0;
$age_to = isset($_GET['age_to']) ? intval($_GET['age_to']) : 0;
$baptized = isset($_GET['baptized']) ? $_GET['baptized'] : '';
$education_level = isset($_GET['education_level']) ? $_GET['education_level'] : '';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';

// Build WHERE clause
$where_conditions = [];
$params = [];
$types = '';

if ($church_id > 0) {
    $where_conditions[] = "ss.church_id = ?";
    $params[] = $church_id;
    $types .= 'i';
}

if ($gender) {
    $where_conditions[] = "ss.gender = ?";
    $params[] = $gender;
    $types .= 's';
}

if ($baptized) {
    $where_conditions[] = "ss.baptized = ?";
    $params[] = $baptized;
    $types .= 's';
}

if ($education_level) {
    $where_conditions[] = "ss.education_level = ?";
    $params[] = $education_level;
    $types .= 's';
}

// Age filter
if ($age_from > 0 || $age_to > 0) {
    if ($age_from > 0 && $age_to > 0) {
        $where_conditions[] = "TIMESTAMPDIFF(YEAR, ss.dob, CURDATE()) BETWEEN ? AND ?";
        $params[] = $age_from;
        $params[] = $age_to;
        $types .= 'ii';
    } elseif ($age_from > 0) {
        $where_conditions[] = "TIMESTAMPDIFF(YEAR, ss.dob, CURDATE()) >= ?";
        $params[] = $age_from;
        $types .= 'i';
    } elseif ($age_to > 0) {
        $where_conditions[] = "TIMESTAMPDIFF(YEAR, ss.dob, CURDATE()) <= ?";
        $params[] = $age_to;
        $types .= 'i';
    }
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get summary statistics
$summary_sql = "SELECT 
    COUNT(*) as total_students,
    COUNT(CASE WHEN gender = 'male' THEN 1 END) as male_count,
    COUNT(CASE WHEN gender = 'female' THEN 1 END) as female_count,
    COUNT(CASE WHEN baptized = 'yes' THEN 1 END) as baptized_count,
    COUNT(CASE WHEN baptized = 'no' THEN 1 END) as not_baptized_count,
    COUNT(CASE WHEN education_level = 'basic' THEN 1 END) as basic_education,
    COUNT(CASE WHEN education_level = 'senior_high' THEN 1 END) as senior_high_education,
    COUNT(CASE WHEN education_level = 'tertiary' THEN 1 END) as tertiary_education,
    AVG(TIMESTAMPDIFF(YEAR, dob, CURDATE())) as avg_age,
    MIN(TIMESTAMPDIFF(YEAR, dob, CURDATE())) as min_age,
    MAX(TIMESTAMPDIFF(YEAR, dob, CURDATE())) as max_age
FROM sunday_school ss
$where_clause";

$summary_stmt = $conn->prepare($summary_sql);
if (!empty($params)) {
    $summary_stmt->bind_param($types, ...$params);
}
$summary_stmt->execute();
$summary = $summary_stmt->get_result()->fetch_assoc();
$summary_stmt->close();

// Get church distribution
$church_dist_sql = "SELECT 
    c.name as church_name,
    COUNT(ss.id) as student_count,
    COUNT(CASE WHEN ss.gender = 'male' THEN 1 END) as male_count,
    COUNT(CASE WHEN ss.gender = 'female' THEN 1 END) as female_count
FROM sunday_school ss
LEFT JOIN churches c ON ss.church_id = c.id
$where_clause
GROUP BY c.id, c.name
ORDER BY student_count DESC";

$church_dist_stmt = $conn->prepare($church_dist_sql);
if (!empty($params)) {
    $church_dist_stmt->bind_param($types, ...$params);
}
$church_dist_stmt->execute();
$church_distribution = $church_dist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$church_dist_stmt->close();

// Get age distribution
$age_dist_sql = "SELECT 
    CASE 
        WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) < 5 THEN '0-4 years'
        WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 5 AND 9 THEN '5-9 years'
        WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 10 AND 14 THEN '10-14 years'
        WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 15 AND 19 THEN '15-19 years'
        ELSE '20+ years'
    END as age_group,
    COUNT(*) as count
FROM sunday_school ss
$where_clause
GROUP BY age_group
ORDER BY age_group";

$age_dist_stmt = $conn->prepare($age_dist_sql);
if (!empty($params)) {
    $age_dist_stmt->bind_param($types, ...$params);
}
$age_dist_stmt->execute();
$age_distribution = $age_dist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$age_dist_stmt->close();

// Get detailed list - always fetch for table
$detailed_sql = "SELECT 
    ss.*,
    c.name as church_name,
    bc.name as class_name,
    TIMESTAMPDIFF(YEAR, ss.dob, CURDATE()) as age,
    CONCAT(fm.last_name, ' ', fm.first_name) as father_member_name,
    CONCAT(mm.last_name, ' ', mm.first_name) as mother_member_name
FROM sunday_school ss
LEFT JOIN churches c ON ss.church_id = c.id
LEFT JOIN bible_classes bc ON ss.class_id = bc.id
LEFT JOIN members fm ON ss.father_member_id = fm.id
LEFT JOIN members mm ON ss.mother_member_id = mm.id
$where_clause
ORDER BY ss.last_name, ss.first_name";

$detailed_stmt = $conn->prepare($detailed_sql);
if (!empty($params)) {
    $detailed_stmt->bind_param($types, ...$params);
}
$detailed_stmt->execute();
$students = $detailed_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$detailed_stmt->close();

// Get parent statistics
$parent_stats_sql = "SELECT 
    COUNT(CASE WHEN father_is_member = 'yes' THEN 1 END) as fathers_are_members,
    COUNT(CASE WHEN father_is_member = 'no' THEN 1 END) as fathers_not_members,
    COUNT(CASE WHEN mother_is_member = 'yes' THEN 1 END) as mothers_are_members,
    COUNT(CASE WHEN mother_is_member = 'no' THEN 1 END) as mothers_not_members,
    COUNT(CASE WHEN father_is_member = 'yes' AND mother_is_member = 'yes' THEN 1 END) as both_parents_members
FROM sunday_school ss
$where_clause";

$parent_stats_stmt = $conn->prepare($parent_stats_sql);
if (!empty($params)) {
    $parent_stats_stmt->bind_param($types, ...$params);
}
$parent_stats_stmt->execute();
$parent_stats = $parent_stats_stmt->get_result()->fetch_assoc();
$parent_stats_stmt->close();

ob_start();
?>

<style>
.report-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 15px;
    padding: 25px;
    color: white;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    margin-bottom: 20px;
    transition: transform 0.3s ease;
}

.report-card:hover {
    transform: translateY(-5px);
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    margin-bottom: 20px;
    transition: all 0.3s ease;
}

.stat-card:hover {
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    transform: translateY(-3px);
}

.stat-number {
    font-size: 2.5rem;
    font-weight: 700;
    color: #667eea;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 0.9rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.chart-container {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    margin-bottom: 20px;
}

.filter-section {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
}

.export-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.table-responsive {
    border-radius: 12px;
    overflow-x: auto;
    overflow-y: visible;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    -webkit-overflow-scrolling: touch;
}

.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter,
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_paginate {
    padding: 10px;
}

.dt-buttons {
    margin-bottom: 15px;
}

.dt-button {
    margin-right: 5px;
    padding: 8px 15px;
    border-radius: 5px;
    border: none;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.buttons-excel {
    background: #28a745;
    color: white;
}

.buttons-csv {
    background: #17a2b8;
    color: white;
}

.buttons-pdf {
    background: #dc3545;
    color: white;
}

.buttons-print {
    background: #6c757d;
    color: white;
}

.dt-button:hover {
    opacity: 0.8;
    transform: translateY(-2px);
}

#studentsTable {
    width: 100% !important;
    white-space: nowrap;
}

#studentsTable th,
#studentsTable td {
    white-space: nowrap;
    padding: 12px 15px;
}

.report-table {
    margin-bottom: 0;
}

.report-table thead {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.report-table thead th {
    border: none;
    padding: 15px;
    font-weight: 600;
}

.report-table tbody tr:hover {
    background-color: #f8f9fa;
}

.badge-custom {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
}

@media print {
    .no-print {
        display: none !important;
    }
    
    .report-card, .stat-card, .chart-container {
        box-shadow: none;
        page-break-inside: avoid;
    }
}
</style>

<div class="container-fluid px-4">
    <!-- Header -->
    <div class="report-card">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2 class="mb-2"><i class="fas fa-chart-bar"></i> Sunday School Reports</h2>
                <p class="mb-0 opacity-75">Comprehensive analytics and insights</p>
            </div>
            <div class="no-print">
                <button onclick="window.print()" class="btn btn-light btn-lg">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
        </div>
    </div>

    <!-- Main Filter Section -->
    <div class="filter-section no-print">
        <h5 class="mb-3"><i class="fas fa-filter"></i> Filter Students</h5>
        <div class="row">
            <div class="col-md-3">
                <label class="form-label"><i class="fas fa-church"></i> Church</label>
                <select id="mainChurchFilter" class="form-control">
                    <option value="">All Churches</option>
                    <?php
                    $churches = $conn->query("SELECT id, name FROM churches ORDER BY name");
                    while($c = $churches->fetch_assoc()):
                    ?>
                        <option value="<?=htmlspecialchars($c['name'])?>"><?=htmlspecialchars($c['name'])?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-venus-mars"></i> Gender</label>
                <select id="mainGenderFilter" class="form-control">
                    <option value="">All Genders</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-birthday-cake"></i> Age From</label>
                <input type="number" id="mainAgeFrom" class="form-control" placeholder="Min age" min="0" max="100">
            </div>
            <div class="col-md-2">
                <label class="form-label"><i class="fas fa-birthday-cake"></i> Age To</label>
                <input type="number" id="mainAgeTo" class="form-control" placeholder="Max age" min="0" max="100">
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="fas fa-water"></i> Baptism Status</label>
                <select id="mainBaptizedFilter" class="form-control">
                    <option value="">All</option>
                    <option value="Yes">Baptized</option>
                    <option value="No">Not Baptized</option>
                </select>
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-md-3">
                <label class="form-label"><i class="fas fa-graduation-cap"></i> Education Level</label>
                <select id="mainEducationFilter" class="form-control">
                    <option value="">All Levels</option>
                    <option value="Basic">Basic</option>
                    <option value="Senior High">Senior High</option>
                    <option value="Tertiary">Tertiary</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><i class="fas fa-school"></i> School Location</label>
                <input type="text" id="mainSchoolLocation" class="form-control" placeholder="Search school location...">
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <button type="button" id="applyMainFilters" class="btn btn-primary me-2">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                <button type="button" id="resetMainFilters" class="btn btn-secondary">
                    <i class="fas fa-redo"></i> Reset All
                </button>
            </div>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div class="row">
        <div class="col-md-3">
            <div class="stat-card text-center">
                <div class="stat-number"><?=number_format($summary['total_students'])?></div>
                <div class="stat-label">Total Students</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card text-center">
                <div class="stat-number text-primary"><?=number_format($summary['male_count'])?></div>
                <div class="stat-label">Male Students</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card text-center">
                <div class="stat-number text-danger"><?=number_format($summary['female_count'])?></div>
                <div class="stat-label">Female Students</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card text-center">
                <div class="stat-number text-success"><?=round($summary['avg_age'], 1)?></div>
                <div class="stat-label">Average Age</div>
            </div>
        </div>
    </div>


    <!-- Detailed Student List with DataTables -->
    <?php if(!empty($students)): ?>
    <div class="row">
        <div class="col-md-12">
            <div class="chart-container">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="mb-0"><i class="fas fa-table text-dark"></i> Student Data Table</h5>
                    <div class="text-muted">
                        <small><i class="fas fa-info-circle"></i> <?=number_format(count($students))?> students total</small>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="studentsTable" class="table report-table table-striped table-hover" style="width:100%">
                        <thead>
                            <tr>
                                <th>SRN</th>
                                <th>Name</th>
                                <th>Age</th>
                                <th>Gender</th>
                                <th>Church</th>
                                <th>Contact</th>
                                <th>School</th>
                                <th>School Location</th>
                                <th>Baptized</th>
                                <th>Baptism Date</th>
                                <th>Education</th>
                                <th>Father</th>
                                <th>Father Contact</th>
                                <th>Mother</th>
                                <th>Mother Contact</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($students as $student): 
                                $father_name = $student['father_is_member'] === 'yes' && $student['father_member_name'] 
                                    ? $student['father_member_name'] : $student['father_name'];
                                $mother_name = $student['mother_is_member'] === 'yes' && $student['mother_member_name'] 
                                    ? $student['mother_member_name'] : $student['mother_name'];
                            ?>
                            <tr>
                                <td><?=htmlspecialchars($student['srn'])?></td>
                                <td><strong><?=htmlspecialchars($student['last_name'].' '.$student['first_name'])?></strong></td>
                                <td data-order="<?=$student['age']?>"><?=$student['age']?> yrs</td>
                                <td>
                                    <?php if($student['gender'] == 'male'): ?>
                                        <span class="badge bg-primary">Male</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Female</span>
                                    <?php endif; ?>
                                </td>
                                <td><?=htmlspecialchars($student['church_name'])?></td>
                                <td><?=htmlspecialchars($student['contact'])?></td>
                                <td><?=htmlspecialchars($student['school_attend'])?></td>
                                <td><?=htmlspecialchars($student['school_location'])?></td>
                                <td>
                                    <?php if($student['baptized'] == 'yes'): ?>
                                        <span class="badge bg-success">Yes</span>
                                    <?php elseif($student['baptized'] == 'no'): ?>
                                        <span class="badge bg-warning">No</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td><?=$student['baptism_date'] ? date('M d, Y', strtotime($student['baptism_date'])) : 'N/A'?></td>
                                <td>
                                    <?php if($student['education_level']): ?>
                                        <span class="badge badge-custom bg-info"><?=ucwords(str_replace('_', ' ', $student['education_level']))?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($student['father_is_member'] == 'yes'): ?>
                                        <small class="text-success"><i class="fas fa-check-circle"></i> <?=htmlspecialchars($father_name)?></small>
                                    <?php else: ?>
                                        <small><?=htmlspecialchars($father_name)?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?=htmlspecialchars($student['father_contact'])?></td>
                                <td>
                                    <?php if($student['mother_is_member'] == 'yes'): ?>
                                        <small class="text-success"><i class="fas fa-check-circle"></i> <?=htmlspecialchars($mother_name)?></small>
                                    <?php else: ?>
                                        <small><?=htmlspecialchars($mother_name)?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?=htmlspecialchars($student['mother_contact'])?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap4.min.css">

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.colVis.min.js"></script>

<script>
// Initialize DataTable
$(document).ready(function() {
    var table = $('#studentsTable').DataTable({
        "pageLength": 25,
        "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        "order": [[1, "asc"]], // Sort by name by default
        "scrollX": true,
        "scrollCollapse": true,
        "autoWidth": false,
        "dom": '<"row"<"col-sm-12 col-md-6"B><"col-sm-12 col-md-6"f>>' +
               '<"row"<"col-sm-12"tr>>' +
               '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        "buttons": [
            {
                extend: 'excelHtml5',
                text: '<i class="fas fa-file-excel"></i> Excel',
                className: 'btn btn-success btn-sm',
                title: 'Sunday School Students Report',
                filename: 'sunday_school_students_' + new Date().toISOString().slice(0,10),
                exportOptions: {
                    columns: ':visible',
                    modifier: {
                        search: 'applied',
                        order: 'applied'
                    }
                }
            },
            {
                extend: 'csvHtml5',
                text: '<i class="fas fa-file-csv"></i> CSV',
                className: 'btn btn-info btn-sm',
                title: 'Sunday School Students Report',
                filename: 'sunday_school_students_' + new Date().toISOString().slice(0,10),
                exportOptions: {
                    columns: ':visible',
                    modifier: {
                        search: 'applied',
                        order: 'applied'
                    }
                }
            },
            {
                extend: 'pdfHtml5',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-danger btn-sm',
                title: 'Sunday School Students Report',
                filename: 'sunday_school_students_' + new Date().toISOString().slice(0,10),
                orientation: 'landscape',
                pageSize: 'A4',
                exportOptions: {
                    columns: ':visible',
                    modifier: {
                        search: 'applied',
                        order: 'applied'
                    }
                },
                customize: function(doc) {
                    doc.defaultStyle.fontSize = 8;
                    doc.styles.tableHeader.fontSize = 9;
                    doc.styles.title.fontSize = 14;
                }
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Print',
                className: 'btn btn-secondary btn-sm',
                title: 'Sunday School Students Report',
                exportOptions: {
                    columns: ':visible',
                    modifier: {
                        search: 'applied',
                        order: 'applied'
                    }
                }
            },
            {
                extend: 'colvis',
                text: '<i class="fas fa-columns"></i> Columns',
                className: 'btn btn-primary btn-sm'
            }
        ],
        "language": {
            "search": "Search:",
            "lengthMenu": "Show _MENU_ entries",
            "info": "Showing _START_ to _END_ of _TOTAL_ students",
            "infoEmpty": "No students found",
            "infoFiltered": "(filtered from _MAX_ total students)",
            "zeroRecords": "No matching students found",
            "paginate": {
                "first": "First",
                "last": "Last",
                "next": "Next",
                "previous": "Previous"
            },
            "buttons": {
                "colvis": "Show/Hide Columns"
            }
        },
        "columnDefs": [
            { "orderable": true, "targets": "_all" }
        ]
    });


    // Update info text when filtering
    table.on('draw', function() {
        var info = table.page.info();
        console.log('Showing ' + info.recordsDisplay + ' of ' + info.recordsTotal + ' students');
    });

    // Main Filter Section - Apply Filters Button
    $('#applyMainFilters').on('click', function() {
        applyAllMainFilters();
    });

    // Main Filter Section - Reset Button
    $('#resetMainFilters').on('click', function() {
        // Clear all main filter inputs
        $('#mainChurchFilter').val('');
        $('#mainGenderFilter').val('');
        $('#mainAgeFrom').val('');
        $('#mainAgeTo').val('');
        $('#mainBaptizedFilter').val('');
        $('#mainEducationFilter').val('');
        $('#mainSchoolLocation').val('');
        
        // Reset table - clear all searches and filters
        table.search('').columns().search('').draw();
        
        // Clear any custom search filters
        $.fn.dataTable.ext.search = [];
    });

    // Function to apply all main filters
    function applyAllMainFilters() {
        // Church filter (column 4)
        var churchVal = $('#mainChurchFilter').val();
        table.column(4).search(churchVal ? '^' + churchVal + '$' : '', true, false);

        // Gender filter (column 3)
        var genderVal = $('#mainGenderFilter').val();
        table.column(3).search(genderVal ? '^' + genderVal + '$' : '', true, false);

        // Age range filter (column 2)
        var ageFrom = parseInt($('#mainAgeFrom').val()) || 0;
        var ageTo = parseInt($('#mainAgeTo').val()) || 999;
        
        $.fn.dataTable.ext.search.push(
            function(settings, data, dataIndex) {
                var age = parseInt(data[2]) || 0; // Age is in column 2
                if ((age >= ageFrom && age <= ageTo)) {
                    return true;
                }
                return false;
            }
        );

        // Baptized filter (column 8)
        var baptizedVal = $('#mainBaptizedFilter').val();
        table.column(8).search(baptizedVal ? '^' + baptizedVal + '$' : '', true, false);

        // Education filter (column 10)
        var educationVal = $('#mainEducationFilter').val();
        table.column(10).search(educationVal ? educationVal : '', true, false);

        // School Location filter (column 7)
        var schoolLocationVal = $('#mainSchoolLocation').val();
        table.column(7).search(schoolLocationVal, false, false);

        // Apply all filters
        table.draw();

        // Remove age filter after drawing
        $.fn.dataTable.ext.search.pop();
    }

    // Allow Enter key to apply filters
    $('#mainAgeFrom, #mainAgeTo, #mainSchoolLocation').on('keypress', function(e) {
        if (e.which === 13) {
            applyAllMainFilters();
        }
    });

    // Auto-apply on select change (optional - comment out if you want manual apply only)
    /*
    $('#mainChurchFilter, #mainGenderFilter, #mainBaptizedFilter, #mainEducationFilter').on('change', function() {
        applyAllMainFilters();
    });
    */
});
</script>

<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
