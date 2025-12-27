<?php
/**
 * Admin Panel - Header Include
 * Common <head> section and meta tags
 */

// Prevent direct access
if (!defined('INCLUDE_CHECK')) {
    define('INCLUDE_CHECK', true);
}

// Load unified path helpers (single source of truth)
require_once __DIR__ . '/../config-paths.php';

// Default title if not set
if (!isset($page_title)) {
    $page_title = "Admin Panel - AI Automation";
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>

    <?php
    $version = time();
    inject_base_path();
    ?>

    <!-- Load CSS immediately (no JavaScript - prevents flash) -->
    <link rel="stylesheet" href="<?php echo asset('css/style.css'); ?>?v=<?php echo $version; ?>">
    <link rel="stylesheet" href="<?php echo asset('css/modal-fixes.css'); ?>?v=<?php echo $version; ?>">
    <link rel="stylesheet" href="<?php echo asset('css/responsive-fixes.css'); ?>?v=<?php echo $version; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Load path-config.js for API endpoints -->
    <script src="<?php echo asset('js/path-config.js'); ?>?v=<?php echo $version; ?>"></script>

    <!-- Load auth.js for apiCall() and requireAuth() functions -->
    <script src="<?php echo asset('js/auth.js'); ?>?v=<?php echo $version; ?>"></script>

    <!-- Load admin.js for admin-specific utilities -->
    <script src="<?php echo asset('js/admin.js'); ?>?v=<?php echo $version; ?>"></script>
</head>

<body>
