<?php
// Set 403 status code
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>403 Forbidden</title>
    <style>
        body { font-family: Arial, sans-serif; background: #fff; color: #333; text-align: center; padding: 60px; }
        .error { font-size: 48px; color: #c00; }
        .message { font-size: 20px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="error">403 Forbidden</div>
    <div class="message">You do not have permission to access this page.</div>
</body>
</html>
