<?php
// get_users.php
// This script fetches all registered users from the database.
session_name("ADMIN_SESSION"); // Set session name for admin
session_start(); // THIS MUST BE THE FIRST EXECUTABLE LINE AFTER <?php

include '../db_connect.php'; 

header('Content-Type: application/json');

// --- ROBUST ADMIN AUTHENTICATION ---
// Check if user is logged in AND has the 'admin' role using new session variables
if (!isset($_SESSION['loggedin_admin_id']) || !isset($_SESSION['loggedin_admin_role']) || $_SESSION['loggedin_admin_role'] !== 'admin') {
    echo json_encode(["success" => false, "message" => "Unauthorized access. Please log in as admin."]);
    exit(); // Crucial: Exit immediately after sending response
}

$users = [];
$success = false;
$message = "";

try {
    // === IMPORTANT: Ensure 'role' is selected here ===
    $sql = "SELECT id, phone_number, username, balance, role, status FROM users ORDER BY username ASC";
    
    $result = $conn->query($sql);

    if ($result) {
        if ($result->num_rows > 0) {
            $success = true;
            while($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            $message = "Users fetched successfully.";
        } else {
            $success = true; // Still a success, just no users yet
            $message = "No users found in the database.";
        }
        $result->free(); // Free the result set
    } else {
        $message = "Database query failed: " . $conn->error;
        error_log("Failed to fetch users: " . $conn->error);
    }
} catch (Exception $e) {
    $message = "An error occurred: " . $e->getMessage();
    error_log("Exception in get_users.php: " . $e->getMessage());
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode(["success" => $success, "message" => $message, "users" => $users]);
exit();