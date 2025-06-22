<?php
// File: project-teer/admin/handle_recharge_approval.php
// Handles approval or rejection of recharge requests by admin.
session_name("ADMIN_SESSION"); // Set session name for admin
session_start(); // THIS MUST BE THE FIRST EXECUTABLE LINE AFTER <?php

include '../db_connect.php'; // Correct path: Go up one level to reach db_connect.php

header('Content-Type: application/json');

// --- ROBUST ADMIN AUTHENTICATION ---
// Check if user is logged in AND has the 'admin' role using new session variables
if (!isset($_SESSION['loggedin_admin_id']) || !isset($_SESSION['loggedin_admin_role']) || $_SESSION['loggedin_admin_role'] !== 'admin') {
    echo json_encode(["success" => false, "message" => "Unauthorized access. Please log in as admin."]);
    exit(); // Exit immediately after unauthorized response
}

$response = ["success" => false, "message" => "An unknown error occurred."]; // Initialize $response at the top

$request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
$action = isset($_POST['action']) ? $_POST['action'] : ''; // 'approve' or 'reject'

if ($request_id <= 0 || !in_array($action, ['approve', 'reject'])) {
    $response = ["success" => false, "message" => "Invalid request ID or action."];
    echo json_encode($response);
    exit(); // Exit immediately after invalid input response
}

// Start transaction for atomicity
$conn->begin_transaction();

try {
    // 1. Get the current status and user_id/amount of the request
    $sql_fetch_request = "SELECT user_id, amount, status FROM recharge_requests WHERE id = ?"; 
    $stmt_fetch = $conn->prepare($sql_fetch_request);
    if (!$stmt_fetch) {
        throw new Exception("SQL prepare failed (fetch): " . $conn->error);
    }
    $stmt_fetch->bind_param("i", $request_id);
    $stmt_fetch->execute();
    $result_fetch = $stmt_fetch->get_result();

    if ($result_fetch->num_rows === 0) {
        throw new Exception("Recharge request not found.");
    }
    $request = $result_fetch->fetch_assoc();
    $stmt_fetch->close(); // Close statement after fetching results

    if ($request['status'] !== 'pending') {
        throw new Exception("Recharge request has already been " . $request['status'] . ".");
    }

    $user_id_to_update = $request['user_id'];
    $recharge_amount = $request['amount'];
    $new_status = ($action === 'approve') ? 'approved' : 'rejected';

    // 2. Update the recharge_requests table
    // Ensure you have 'approved_rejected_at' column in your recharge_requests table.
    $sql_update_request = "UPDATE recharge_requests SET status = ?, approved_rejected_at = NOW() WHERE id = ?";
    $stmt_update_request = $conn->prepare($sql_update_request);
    if (!$stmt_update_request) {
        throw new Exception("SQL prepare failed (update request): " . $conn->error);
    }
    $stmt_update_request->bind_param("si", $new_status, $request_id); 
    if (!$stmt_update_request->execute()) {
        throw new Exception("Failed to update recharge request status: " . $stmt_update_request->error);
    }
    $stmt_update_request->close();

    // 3. If approved, update the user's balance
    if ($action === 'approve') {
        $sql_update_balance = "UPDATE users SET balance = balance + ? WHERE id = ?";
        $stmt_update_balance = $conn->prepare($sql_update_balance);
        if (!$stmt_update_balance) {
            throw new Exception("SQL prepare failed (update balance): " . $conn->error);
        }
        $stmt_update_balance->bind_param("di", $recharge_amount, $user_id_to_update);
        if (!$stmt_update_balance->execute()) {
            throw new Exception("Failed to update user balance: " . $stmt_update_balance->error);
        }
        $stmt_update_balance->close();
    }

    $conn->commit();
    $response = ["success" => true, "message" => "Recharge request " . $new_status . " successfully."]; // Set success response here

} catch (Exception $e) {
    if ($conn) $conn->rollback(); // Rollback on error
    error_log("Error handling recharge approval: " . $e->getMessage()); // Log the error for server-side debugging
    $response = ["success" => false, "message" => "Error handling request: " . $e->getMessage()]; // Set error response here
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();