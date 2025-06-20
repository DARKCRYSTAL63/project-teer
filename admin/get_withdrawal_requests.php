<?php
// File: project-teer/admin/get_withdrawal_requests.php
// Fetches withdrawal requests for the admin panel.

session_start(); // THIS MUST BE THE FIRST EXECUTABLE LINE AFTER <?php

include '../db_connect.php'; 

header('Content-Type: application/json');

// --- SINGLE, ROBUST ADMIN AUTHENTICATION ---
// Check if user is logged in AND has the 'admin' role using new session variables
if (!isset($_SESSION['loggedin_admin_id']) || !isset($_SESSION['loggedin_admin_role']) || $_SESSION['loggedin_admin_role'] !== 'admin') {
    echo json_encode(["success" => false, "message" => "Unauthorized access. Please log in as admin."]);
    exit(); // Crucial: Exit immediately after sending response
}

$response = ["success" => false, "message" => "An unknown error occurred."]; // Default response
$stmt = null;

try {
    // Using $conn (mysqli object) as per your db_connect.php
    $sql = "SELECT wr.id, u.username, u.phone_number, wr.amount, wr.bank_name, wr.account_holder, wr.account_number, wr.ifsc_code, wr.status, wr.requested_at
            FROM withdrawal_requests wr
            JOIN users u ON wr.user_id = u.id
            ORDER BY wr.requested_at DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error);
    }

    $stmt->execute();
    $result = $stmt->get_result(); // Get result for mysqli
    $requests = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) { // Fetch associative array
            $requests[] = $row;
        }
        $response = ["success" => true, "requests" => $requests, "message" => "Withdrawal requests fetched successfully."];
    } else {
        $response = ["success" => true, "requests" => [], "message" => "No withdrawal requests found."]; // Success even if no requests
    }
    $stmt->close(); 

} catch (Exception $e) { 
    error_log("Error fetching withdrawal requests: " . $e->getMessage());
    $response = ["success" => false, "message" => "Database error: " . $e->getMessage()];
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();