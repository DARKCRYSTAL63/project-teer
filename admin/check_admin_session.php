<?php
// File: project-teer/admin/check_admin_session.php
// Checks if an admin session is active.
session_name("ADMIN_SESSION"); // Set session name for admin
session_start(); // MUST BE THE FIRST EXECUTABLE LINE AFTER <?php

header('Content-Type: application/json');

// This script's purpose is to simply report the admin status.
if (isset($_SESSION['loggedin_admin_id']) && isset($_SESSION['loggedin_admin_role']) && $_SESSION['loggedin_admin_role'] === 'admin') {
    // Session is valid, return the expected structure for admin.html
    echo json_encode([
        "loggedin" => true,
        "role" => $_SESSION['loggedin_admin_role'], // Explicitly return the role
        "admin_id" => $_SESSION['loggedin_admin_id'],
        "username" => $_SESSION['loggedin_admin_username']
    ]);
} else {
    // Session is not valid for an admin
    echo json_encode([
        "loggedin" => false,
        "role" => "guest", // Or any other non-admin role
        "message" => "Admin session not found or invalid."
    ]);
}
exit();