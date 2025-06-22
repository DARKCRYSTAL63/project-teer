<?php
// File: project-teer/mybalance/get_user_withdrawal_requests.php
// Fetches withdrawal requests for the logged-in user.
session_name("USER_SESSION"); // Set session name for user
session_start();
header('Content-Type: application/json');

include '../db_connect.php'; // Correct path: Go up one level to reach db_connect.php

$response = ["success" => false, "message" => "An unknown error occurred.", "requests" => []];

// Check if user is logged in
if (!isset($_SESSION['loggedin_user_id'])) {
    $response = ["success" => false, "message" => "Please log in to view withdrawal history.", "redirect" => "../login/login.html"];
    echo json_encode($response);
    exit();
}

$user_id = $_SESSION['loggedin_user_id'];

try {
    // Select user's own withdrawal requests
    // NOTE: This query does NOT include bank details as they are sensitive and typically not displayed in client history.
    $sql = "SELECT id, amount, status, requested_at
            FROM withdrawal_requests
            WHERE user_id = ?
            ORDER BY requested_at DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $requests = [];
    while($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    $stmt->close();

    $response = ["success" => true, "message" => "Withdrawal history fetched successfully.", "requests" => $requests];

} catch (Exception $e) {
    error_log("get_user_withdrawal_requests.php: Exception: " . $e->getMessage());
    $response = ["success" => false, "message" => "Database error: " . $e->getMessage()];
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();