<?php
$url = "https://webhook.site/a18ece58-f7c9-4d20-add5-53eedad65c2c";

// Initialize cURL session
$ch = curl_init($url);

// Set cURL options
curl_setopt($ch, CURLOPT_POST, true);         // Use POST method
curl_setopt($ch, CURLOPT_POSTFIELDS, "");     // Empty POST body
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the response

// Execute the request
$response = curl_exec($ch);

// Optional: check for errors
if (curl_errno($ch)) {
    echo 'cURL error: ' . curl_error($ch);
} else {
    echo 'POST request sent successfully!';
}

// Close cURL session
curl_close($ch);
?>
