<?php
// SMS Template utility for MyFreeman
require_once __DIR__.'/../config/config.php';

function get_sms_template($name, $conn) {
    $stmt = $conn->prepare('SELECT * FROM sms_templates WHERE name = ? LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function fill_sms_template($body, $vars) {
    foreach ($vars as $key => $value) {
        $body = str_replace('{' . $key . '}', $value, $body);
    }
    return $body;
}
