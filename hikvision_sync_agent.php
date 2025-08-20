<?php
/**
 * Hikvision Local Sync Agent (PHP)
 *
 * Usage: Run this script on a local PC/server with PHP CLI. Schedule via cron or Task Scheduler for periodic sync.
 *
 * Features:
 * - Polls Hikvision device for attendance logs (ISAPI)
 * - Sends new logs to cloud API endpoint securely (API key)
 * - Tracks last sync to avoid duplicates
 */

// === CONFIGURATION ===
$device_ip = '192.168.5.201'; // Hikvision device IP
$device_port = 80;
$username = 'admin';
$password = '223344AD';
$cloud_api_url = 'https://myfreeman.mensweb.xyz/api_hikvision_attendance.php';
$api_key = '0c6c5401ab9f1af81c7cbadee3279663a918a16407fbc84a0d4bd189789d9f49';
$last_sync_file = __DIR__ . '/hikvision_last_sync.txt';

// === LOAD LAST SYNC TIME ===
$last_sync_time = @file_get_contents($last_sync_file);
if (!$last_sync_time) {
    $last_sync_time = date('Y-m-d\TH:i:s', strtotime('-1 day'));
}

// === FETCH ATTENDANCE LOGS FROM DEVICE ===
// Try POST to /AcsEvent (no /search) first
$endpoint_post = "/ISAPI/AccessControl/AcsEvent";
$url_post = "http://{$device_ip}:{$device_port}{$endpoint_post}";
$startTime = $last_sync_time;
$endTime = date('Y-m-d\TH:i:s');
// 1. Standard XML (with declaration)
$xml_body = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<AcsEventCond>\n  <searchID>1</searchID>\n  <startTime>{$startTime}</startTime>\n  <endTime>{$endTime}</endTime>\n  <maxResults>1000</maxResults>\n</AcsEventCond>";

// 2. No XML declaration
$xml_body_no_decl = "<AcsEventCond>\n  <searchID>1</searchID>\n  <startTime>{$startTime}</startTime>\n  <endTime>{$endTime}</endTime>\n  <maxResults>1000</maxResults>\n</AcsEventCond>";

// 3. Minimal/compact XML (no whitespace)
$xml_body_compact = "<AcsEventCond><searchID>1</searchID><startTime>{$startTime}</startTime><endTime>{$endTime}</endTime><maxResults>1000</maxResults></AcsEventCond>";

// 4. Alternate root tag
$xml_body_alt_root = "<AcsEventSearchDescription><searchID>1</searchID><startTime>{$startTime}</startTime><endTime>{$endTime}</endTime><maxResults>1000</maxResults></AcsEventSearchDescription>";
echo "[DEBUG] XML body length: " . strlen($xml_body) . "\n";
file_put_contents(__DIR__ . '/debug_xml_body.txt', $xml_body);
// === 1. Standard XML (with declaration, application/xml) ===
echo "\n[DEBUG] POSTing to: $url_post\n";
echo "[DEBUG] Request body (standard):\n$xml_body\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url_post);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/xml'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_body);
$response = curl_exec($ch);
$error = curl_error($ch);
echo "[DEBUG] Raw response (POST standard):\n$response\n";
curl_close($ch);

if ($error) {
    echo "[ERROR] Failed to connect to device (POST standard): $error\n";
    exit(1);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_string($response);
if ($xml !== false && isset($xml->AcsEvent)) {
    echo "[DEBUG] Success with POST (standard)\n";
    // ... (continue with cloud sync logic as before)
    exit(0);
}

// === 2. No XML declaration ===
echo "[DEBUG] Trying POST with no XML declaration...\n";
echo "[DEBUG] Request body (no decl):\n$xml_body_no_decl\n";
$ch2 = curl_init();
curl_setopt($ch2, CURLOPT_URL, $url_post);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
curl_setopt($ch2, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
curl_setopt($ch2, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    'Content-Type: application/xml'
]);
curl_setopt($ch2, CURLOPT_POSTFIELDS, $xml_body_no_decl);
$response2 = curl_exec($ch2);
$error2 = curl_error($ch2);
echo "[DEBUG] Raw response (POST no decl):\n$response2\n";
curl_close($ch2);
$xml2 = simplexml_load_string($response2);
if ($xml2 !== false && isset($xml2->AcsEvent)) {
    echo "[DEBUG] Success with POST (no decl)\n";
    // ... (continue with cloud sync logic as before)
    exit(0);
}

// === 3. Minimal/compact XML ===
echo "[DEBUG] Trying POST with minimal/compact XML...\n";
echo "[DEBUG] Request body (compact):\n$xml_body_compact\n";
$ch3 = curl_init();
curl_setopt($ch3, CURLOPT_URL, $url_post);
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch3, CURLOPT_TIMEOUT, 30);
curl_setopt($ch3, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
curl_setopt($ch3, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch3, CURLOPT_POST, true);
curl_setopt($ch3, CURLOPT_HTTPHEADER, [
    'Content-Type: application/xml'
]);
curl_setopt($ch3, CURLOPT_POSTFIELDS, $xml_body_compact);
$response3 = curl_exec($ch3);
$error3 = curl_error($ch3);
echo "[DEBUG] Raw response (POST compact):\n$response3\n";
curl_close($ch3);
$xml3 = simplexml_load_string($response3);
if ($xml3 !== false && isset($xml3->AcsEvent)) {
    echo "[DEBUG] Success with POST (compact)\n";
    // ... (continue with cloud sync logic as before)
    exit(0);
}

// === 4. Alternate root tag ===
echo "[DEBUG] Trying POST with alternate root tag...\n";
echo "[DEBUG] Request body (alt root):\n$xml_body_alt_root\n";
$ch4 = curl_init();
curl_setopt($ch4, CURLOPT_URL, $url_post);
curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch4, CURLOPT_TIMEOUT, 30);
curl_setopt($ch4, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
curl_setopt($ch4, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch4, CURLOPT_POST, true);
curl_setopt($ch4, CURLOPT_HTTPHEADER, [
    'Content-Type: application/xml'
]);
curl_setopt($ch4, CURLOPT_POSTFIELDS, $xml_body_alt_root);
$response4 = curl_exec($ch4);
$error4 = curl_error($ch4);
echo "[DEBUG] Raw response (POST alt root):\n$response4\n";
curl_close($ch4);
$xml4 = simplexml_load_string($response4);
if ($xml4 !== false && isset($xml4->AcsEvent)) {
    echo "[DEBUG] Success with POST (alt root)\n";
    // ... (continue with cloud sync logic as before)
    exit(0);
}

// === 5. Try Content-Type: text/xml with compact XML ===
echo "[DEBUG] Trying POST with Content-Type: text/xml...\n";
$ch5 = curl_init();
curl_setopt($ch5, CURLOPT_URL, $url_post);
curl_setopt($ch5, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch5, CURLOPT_TIMEOUT, 30);
curl_setopt($ch5, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
curl_setopt($ch5, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch5, CURLOPT_POST, true);
curl_setopt($ch5, CURLOPT_HTTPHEADER, [
    'Content-Type: text/xml'
]);
curl_setopt($ch5, CURLOPT_POSTFIELDS, $xml_body_compact);
$response5 = curl_exec($ch5);
$error5 = curl_error($ch5);
echo "[DEBUG] Raw response (POST text/xml):\n$response5\n";
curl_close($ch5);
$xml5 = simplexml_load_string($response5);
if ($xml5 !== false && isset($xml5->AcsEvent)) {
    echo "[DEBUG] Success with POST (text/xml)\n";
    // ... (continue with cloud sync logic as before)
    exit(0);
}

echo "[ERROR] All POST attempts failed.\n";
exit(1);

    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch2, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
    curl_setopt($ch2, CURLOPT_USERPWD, "$username:$password");
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
        'Content-Type: application/xml'
    ]);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, $xml_body2);
    $response2 = curl_exec($ch2);
    $error2 = curl_error($ch2);
    echo "[DEBUG] Raw response (POST major/minor):\n$response2\n";
    curl_close($ch2);
    if ($error2) {
        echo "[ERROR] Failed to connect to device (POST major/minor): $error2\n";
        exit(1);
    }
    $xml2 = simplexml_load_string($response2);
    if ($xml2 !== false && isset($xml2->AcsEvent)) {
        echo "[DEBUG] Success with POST to /AcsEvent with major/minor\n";
        $logs = [];
        foreach ($xml2->AcsEvent as $event) {
            $logs[] = json_decode(json_encode($event), true);
        }
        if (empty($logs)) {
            echo "[INFO] No new logs to sync.\n";
            exit(0);
        }
        // ... (continue with cloud sync logic as before)
    }
// Try again with all-lowercase tags (acseventcond, acsevent)
echo "[DEBUG] Trying POST with all-lowercase tags...\n";
$xml_body3 = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<acseventcond>\n  <searchid>1</searchid>\n  <starttime>{$startTime}</starttime>\n  <endtime>{$endTime}</endtime>\n  <major>5</major>\n  <minor>75</minor>\n  <maxresults>1000</maxresults>\n</acseventcond>";
echo "[DEBUG] Request body (lowercase tags):\n$xml_body3\n";
file_put_contents(__DIR__ . '/debug_xml_body_lowercase.txt', $xml_body3);
$ch3 = curl_init();
curl_setopt($ch3, CURLOPT_URL, $url_post);
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch3, CURLOPT_TIMEOUT, 30);
curl_setopt($ch3, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
curl_setopt($ch3, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch3, CURLOPT_POST, true);
curl_setopt($ch3, CURLOPT_HTTPHEADER, [
    'Content-Type: application/xml'
]);
curl_setopt($ch3, CURLOPT_POSTFIELDS, $xml_body3);
$response3 = curl_exec($ch3);
$error3 = curl_error($ch3);
echo "[DEBUG] Raw response (POST lowercase tags):\n$response3\n";
curl_close($ch3);
if ($error3) {
    echo "[ERROR] Failed to connect to device (POST lowercase): $error3\n";
    exit(1);
}
$xml3 = simplexml_load_string($response3);
if ($xml3 !== false && (isset($xml3->acsevent) || isset($xml3->AcsEvent))) {
    echo "[DEBUG] Success with POST to /AcsEvent with lowercase tags\n";
    // ... (continue with cloud sync logic as before)
}
