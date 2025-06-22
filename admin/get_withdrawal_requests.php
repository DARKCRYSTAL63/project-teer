<?php
// File: project-teer/admin/get_recharge_requests.php
// Fetches recharge requests for the admin panel.
session_name("ADMIN_SESSION"); // Set session name for admin
session_start(); // THIS MUST BE THE FIRST EXECUTABLE LINE AFTER <?php

include '../db_connect.php'; // Correct path: Go up one level to reach db_connect.php

header('Content-Type: application/json');

// --- SINGLE, ROBUST ADMIN AUTHENTICATION ---\
// Check if user is logged in AND has the 'admin' role using new session variables
if (!isset($_SESSION['loggedin_admin_id']) || !isset($_SESSION['loggedin_admin_role']) || $_SESSION['loggedin_admin_role'] !== 'admin') {
    echo json_encode(["success" => false, "message" => "Unauthorized access. Please log in as admin."]);
    exit(); // Crucial: Exit immediately after sending response
}

$requests = [];
$success = false;
$message = "";

try {
    // Corrected SQL query to include u.phone_number and u.username
    // Ensure `account_holder` is selected directly from `withdrawal_requests` table
    $sql = "SELECT rr.id, u.username, u.phone_number, rr.amount, rr.bank_name, rr.account_holder, rr.account_number, rr.ifsc_code, rr.status, rr.requested_at
            FROM withdrawal_requests rr
            JOIN users u ON rr.user_id = u.id";
    
    // Add date filter
    $date_filter = isset($_GET['date']) ? $_GET['date'] : '';
    if (!empty($date_filter)) {
        $sql .= " WHERE DATE(rr.requested_at) = ?";
    }

    $sql .= " ORDER BY rr.requested_at DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error);
    }
    
    if (!empty($date_filter)) {
        $stmt->bind_param("s", $date_filter);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        if ($result->num_rows > 0) {
            $success = true;
            while($row = $result->fetch_assoc()) {
                $requests[] = $row;
            }
            $message = "Withdrawal requests fetched successfully.";
        } else {
            $success = true; // Still a success, just no requests
            $message = "No withdrawal requests found for the selected date.";
        }
        $result->free(); // Free result set
    } else {
        $message = "Database query failed: " . $conn->error;
        error_log("Failed to fetch withdrawal requests: " . $conn->error);
    }
} catch (Exception $e) {
    $message = "An error occurred: " . $e->getMessage();
    error_log("Exception in get_withdrawal_requests.php: " . $e->getMessage());
} finally {
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode(["success" => $success, "message" => $message, "requests" => $requests]);
exit();