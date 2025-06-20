<?php
// File: project-teer/admin/check_admin_session.php
// Checks if an admin session is active.

session_start(); // MUST BE THE FIRST EXECUTABLE LINE AFTER <?php

header('Content-Type: application/json');

// This script's purpose is to simply report the admin status using the new session variable names.
if (isset($_SESSION['loggedin_admin_id']) && isset($_SESSION['loggedin_admin_role']) && $_SESSION['loggedin_admin_role'] === 'admin') {
    echo json_encode(["is_admin" => true]);
} else {
    echo json_encode(["is_admin" => false]);
}
exit();