<?php
$url = "https://webhook.site/0c2ddd29-1658-4a48-abfc-21f2d038e79a";

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
