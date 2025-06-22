<?php
// File: project-teer/admin/get_dashboard_stats.php
// Fetches dashboard statistics for the admin panel.
session_name("ADMIN_SESSION"); // Set session name for admin
session_start(); // THIS MUST BE THE FIRST EXECUTABLE LINE AFTER <?php

include '../db_connect.php'; // Correct path: Go up one level to reach db_connect.php

header('Content-Type: application/json');

// --- ROBUST ADMIN AUTHENTICATION ---
// Check if user is logged in AND has the 'admin' role
if (!isset($_SESSION['loggedin_admin_id']) || !isset($_SESSION['loggedin_admin_role']) || $_SESSION['loggedin_admin_role'] !== 'admin') {
    echo json_encode(["success" => false, "message" => "Unauthorized access. Please log in as admin."]);
    exit(); // Crucial: Exit immediately after sending response
}

$response = ["success" => false, "message" => "An unknown error occurred."];
$total_approved_recharge = 0.00;
$total_users = 0;
$stmt = null;

try {
    // 1. Get Total Approved Recharge Amount
    $sql_recharge = "SELECT SUM(amount) AS total_amount FROM recharge_requests WHERE status = 'approved'";
    $result_recharge = $conn->query($sql_recharge);
    
    if ($result_recharge && $row_recharge = $result_recharge->fetch_assoc()) {
        $total_approved_recharge = floatval($row_recharge['total_amount'] ?? 0.00); // Use ?? for null coalescing
        $result_recharge->free();
    } else {
        error_log("Error fetching total approved recharge: " . $conn->error);
    }

    // 2. Get Total Registered Users Count
    $sql_users = "SELECT COUNT(id) AS total_count FROM users";
    $result_users = $conn->query($sql_users);

    if ($result_users && $row_users = $result_users->fetch_assoc()) {
        $total_users = intval($row_users['total_count'] ?? 0); // Use ?? for null coalescing
        $result_users->free();
    } else {
        error_log("Error fetching total users count: " . $conn->error);
    }

    $response = [
        "success" => true,
        "message" => "Dashboard stats fetched successfully.",
        "total_approved_recharge" => $total_approved_recharge,
        "total_users" => $total_users
    ];

} catch (Exception $e) {
    error_log("Exception in get_dashboard_stats.php: " . $e->getMessage());
    $response = ["success" => false, "message" => "Database error: " . $e->getMessage()];
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();