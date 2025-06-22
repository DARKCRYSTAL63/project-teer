<?php
// File: project-teer/admin/get_recharge_requests.php
// Fetches recharge requests for the admin panel, with optional date filter.
session_name("ADMIN_SESSION"); // Set session name for admin
session_start();

include '../db_connect.php'; // Correct path: Go up one level to reach db_connect.php

header('Content-Type: application/json');

// --- SINGLE, ROBUST ADMIN AUTHENTICATION ---\n// Check if user is logged in AND has the 'admin' role using new session variables
if (!isset($_SESSION['loggedin_admin_id']) || !isset($_SESSION['loggedin_admin_role']) || $_SESSION['loggedin_admin_role'] !== 'admin') {
    echo json_encode(["success" => false, "message" => "Unauthorized access. Please log in as admin."]);
    exit(); // Crucial: Exit immediately after sending response
}

$requests = [];
$success = false;
$message = "";

try {
    $filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d'); // Default to today

    // SQL: Selects phone_number from the 'users' table (u.phone_number) and filters by date
    $sql = "SELECT rr.id, u.username, u.phone_number, rr.amount, rr.status, rr.requested_at
            FROM recharge_requests rr
            JOIN users u ON rr.user_id = u.id
            WHERE DATE(rr.requested_at) = ?
            ORDER BY rr.requested_at DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error);
    }
    $stmt->bind_param("s", $filter_date);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        if ($result->num_rows > 0) {
            $success = true;
            while($row = $result->fetch_assoc()) {
                $requests[] = $row;
            }
            $message = "Recharge requests fetched successfully.";
        } else {
            $success = true; // Still a success, just no requests
            $message = "No recharge requests found for the selected date.";
        }
        $result->free(); // Free result set
    } else {
        $message = "Database query failed: " . $conn->error;
        error_log("Failed to fetch recharge requests: " . $conn->error);
    }
} catch (Exception $e) {
    $message = "An error occurred: " . $e->getMessage();
    error_log("Exception in get_recharge_requests.php: " . $e->getMessage());
} finally {
    if (isset($stmt)) $stmt->close();
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode(["success" => $success, "message" => $message, "requests" => $requests]);
exit();