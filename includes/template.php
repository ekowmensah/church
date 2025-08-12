<?php
/**
 * Main Template File for Church Management System
 * This template provides a consistent layout structure for all pages
 */

// Ensure we have required variables
if (!isset($content)) {
    $content = '';
}

// Set default page title if not provided
if (!isset($page_title)) {
    $page_title = 'Church Management System';
}

// Include the main layout with our content
$page_content = $content;
include __DIR__.'/layout.php';
?>
