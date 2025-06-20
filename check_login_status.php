<?php
// File: project-teer/check_login_status.php
session_start(); // Start the session
header('Content-Type: application/json'); // Respond with JSON

$isLoggedIn = false;
$username = null;

if (isset($_SESSION['loggedin_user_id']) && !empty($_SESSION['loggedin_user_id'])) {
    $isLoggedIn = true;
    $username = $_SESSION['loggedin_username'] ?? 'User'; // Get username if available
}

echo json_encode([
    'isLoggedIn' => $isLoggedIn,
    'username' => $username
]);
exit();