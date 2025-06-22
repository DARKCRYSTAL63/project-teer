<?php
// File: project-teer/admin/admin_logout.php
// Destroys the admin session.
session_name("ADMIN_SESSION"); // Set session name for admin
session_start(); // MUST BE THE FIRST EXECUTABLE LINE AFTER <?php

header('Content-Type: application/json');

// Unset only admin-specific session variables if a user session should persist.
// If you want to clear ALL session data regardless of role, use $_SESSION = array(); then session_destroy();
// For a logout, clearing all is usually the desired behavior.
$_SESSION = array(); // Clears all session variables
session_destroy(); // Destroys the session data on the server

// Simply confirm logout.
echo json_encode(["success" => true, "message" => "Logged out successfully."]);
exit();