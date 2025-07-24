<?php
// Returns payment types for Select2 AJAX search (id, text)
require_once __DIR__.'/../config/config.php';
header('Content-Type: application/json');
$term = trim($_GET['term'] ?? '');
$out = ['results'=>[]];
if ($term !== '') {
    $like = "%" . $term . "%";
    $q = $conn->prepare('SELECT id, name FROM payment_types WHERE active=1 AND LOWER(name) LIKE LOWER(?) ORDER BY name ASC LIMIT 20');
    if ($q) {
        $q->bind_param('s', $like);
        $q->execute();
        $res = $q->get_result();
        while($row = $res->fetch_assoc()) {
            $out['results'][] = [
                'id' => $row['id'],
                'text' => $row['name']
            ];
        }
        $q->close();
    } else {
        $out['results'] = [];
    }
} else {
    // Return top 20 payment types if no term
    $res = $conn->query('SELECT id, name FROM payment_types WHERE active=1 ORDER BY name ASC LIMIT 20');
    while($row = $res->fetch_assoc()) {
        $out['results'][] = [
            'id' => $row['id'],
            'text' => $row['name']
        ];
    }
}
echo json_encode($out);
