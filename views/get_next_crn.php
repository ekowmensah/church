<?php
// AJAX endpoint to generate the next CRN for a class/church
require_once __DIR__.'/../config/config.php';

$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;

if (!$class_id || !$church_id) {
    echo '';
    exit;
}

// Get class code and validate it belongs to the selected church
$stmt = $conn->prepare('SELECT code FROM bible_classes WHERE id = ? AND church_id = ? LIMIT 1');
$stmt->bind_param('ii', $class_id, $church_id);
$stmt->execute();
$result = $stmt->get_result();
$class = $result->fetch_assoc();
if (!$class) {
    echo '';
    exit;
}
$class_code = $class['code'];

// Get church code and circuit/location code
$stmt = $conn->prepare('SELECT church_code, circuit_code FROM churches WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $church_id);
$stmt->execute();
$result = $stmt->get_result();
$church = $result->fetch_assoc();
$church_code = $church ? $church['church_code'] : '';
$circuit_code = $church ? $church['circuit_code'] : '';

// Get max sequence number used in CRN/SRN for this church/class in both tables
$max_seq = 0;
$pattern = $church_code . '-' . $class_code . '([0-9]+)-' . $circuit_code;

// Check members
$stmt = $conn->prepare('SELECT crn FROM members WHERE class_id = ? AND church_id = ?');
$stmt->bind_param('ii', $class_id, $church_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if (preg_match('/'.preg_quote($church_code.'-'.$class_code, '/').'([0-9]+)-'.preg_quote($circuit_code, '/').'/i', $row['crn'], $m)) {
        $num = intval($m[1]);
        if ($num > $max_seq) $max_seq = $num;
    }
}
// Check sunday_school
$stmt = $conn->prepare('SELECT srn FROM sunday_school WHERE class_id = ? AND church_id = ?');
$stmt->bind_param('ii', $class_id, $church_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if (preg_match('/'.preg_quote($church_code.'-'.$class_code, '/').'([0-9]+)-'.preg_quote($circuit_code, '/').'/i', $row['srn'], $m)) {
        $num = intval($m[1]);
        if ($num > $max_seq) $max_seq = $num;
    }
}
$seq = str_pad($max_seq + 1, 2, '0', STR_PAD_LEFT);

// Compose CRN
$crn = $church_code . '-' . $class_code . $seq . '-' . $circuit_code;
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    echo "DEBUG: class_id=$class_id, church_id=$church_id, church_code=$church_code, class_code=$class_code, seq=$seq, circuit_code=$circuit_code\n";
}
echo $crn;
