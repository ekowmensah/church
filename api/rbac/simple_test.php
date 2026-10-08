<?php
// Retired: the former browser test mutated authentication session state and
// could impersonate a fixed privileged account. Use the CLI test suite.
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => false, 'error' => 'This diagnostic route has been retired.']);
