<?php
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/helpers/auth.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: '.BASE_URL.'/login.php');
    exit;
}

echo "<h2>Hubtel Callback Configuration</h2>";

// Get the actual callback URL
$callback_url = BASE_URL . '/views/hubtel_callback.php';
$return_url = BASE_URL . '/views/make_payment.php?hubtel_return=1';

echo "<div class='alert alert-info'>";
echo "<h4>📋 Callback URLs for Hubtel Account Activation</h4>";
echo "<p>Provide these URLs to Hubtel for account activation:</p>";
echo "</div>";

echo "<div class='card mb-4'>";
echo "<div class='card-header'><strong>Callback URL (Webhook)</strong></div>";
echo "<div class='card-body'>";
echo "<code style='font-size: 14px; background: #f8f9fa; padding: 10px; display: block; border: 1px solid #dee2e6;'>";
echo htmlspecialchars($callback_url);
echo "</code>";
echo "<small class='text-muted'>This URL receives payment status updates from Hubtel</small>";
echo "</div>";
echo "</div>";

echo "<div class='card mb-4'>";
echo "<div class='card-header'><strong>Return URL (Redirect)</strong></div>";
echo "<div class='card-body'>";
echo "<code style='font-size: 14px; background: #f8f9fa; padding: 10px; display: block; border: 1px solid #dee2e6;'>";
echo htmlspecialchars($return_url);
echo "</code>";
echo "<small class='text-muted'>Users are redirected here after payment completion</small>";
echo "</div>";
echo "</div>";

echo "<div class='alert alert-warning'>";
echo "<h5>⚠️ Important Notes:</h5>";
echo "<ul>";
echo "<li><strong>SSL Required:</strong> Hubtel requires HTTPS URLs for production</li>";
echo "<li><strong>Public Access:</strong> These URLs must be publicly accessible (not localhost)</li>";
echo "<li><strong>Method:</strong> Callback URL receives POST requests with JSON payload</li>";
echo "<li><strong>Response:</strong> Callback should return HTTP 200 for successful processing</li>";
echo "</ul>";
echo "</div>";

// Test callback accessibility
echo "<h3>🔍 Callback Accessibility Test</h3>";

$test_results = [];

// Test if callback URL is accessible
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $callback_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For testing
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    $test_results[] = "❌ Callback URL Error: " . $curl_error;
} else {
    $test_results[] = "✅ Callback URL Accessible (HTTP $http_code)";
}

// Test return URL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $return_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    $test_results[] = "❌ Return URL Error: " . $curl_error;
} else {
    $test_results[] = "✅ Return URL Accessible (HTTP $http_code)";
}

echo "<div class='alert alert-secondary'>";
foreach ($test_results as $result) {
    echo "<div>$result</div>";
}
echo "</div>";

// Show current configuration
echo "<h3>📋 Current Configuration</h3>";
echo "<table class='table table-bordered'>";
echo "<tr><th>Setting</th><th>Value</th></tr>";
echo "<tr><td>Base URL</td><td>" . htmlspecialchars(BASE_URL) . "</td></tr>";
echo "<tr><td>Environment</td><td>" . (strpos(BASE_URL, 'localhost') !== false ? 'Development (localhost)' : 'Production') . "</td></tr>";
echo "<tr><td>SSL Enabled</td><td>" . (strpos(BASE_URL, 'https://') === 0 ? '✅ Yes' : '❌ No (Required for production)') . "</td></tr>";
echo "</table>";

if (strpos(BASE_URL, 'localhost') !== false) {
    echo "<div class='alert alert-danger'>";
    echo "<h5>🚨 Development Environment Detected</h5>";
    echo "<p>You're using localhost URLs. For Hubtel account activation, you need:</p>";
    echo "<ul>";
    echo "<li>A public domain (e.g., yourdomain.com)</li>";
    echo "<li>HTTPS SSL certificate</li>";
    echo "<li>Update BASE_URL in your config to the public domain</li>";
    echo "</ul>";
    echo "</div>";
}

echo "<h3>📧 Submit to Hubtel</h3>";
echo "<div class='alert alert-success'>";
echo "<p><strong>Send these details to Hubtel for account activation:</strong></p>";
echo "<ul>";
echo "<li><strong>Callback URL:</strong> " . htmlspecialchars($callback_url) . "</li>";
echo "<li><strong>Return URL:</strong> " . htmlspecialchars($return_url) . "</li>";
echo "<li><strong>Business Name:</strong> Freeman Methodist Church</li>";
echo "<li><strong>Integration Type:</strong> Checkout API</li>";
echo "</ul>";
echo "</div>";

?>

<div class="mt-4">
    <a href="<?php echo BASE_URL; ?>/test_hubtel_callback.php" class="btn btn-primary">Test Callback</a>
    <a href="<?php echo BASE_URL; ?>/views/make_payment.php" class="btn btn-secondary">Back to Payments</a>
</div>
