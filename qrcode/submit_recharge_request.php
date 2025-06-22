<?php
// File: project-teer/mybalance/submit_recharge_request.php (or /qrcode/submit_recharge_request.php)
// Handles submission of recharge requests from the user side.
session_name("USER_SESSION"); // Set session name for user
session_start(); // MUST BE THE FIRST EXECUTABLE LINE AFTER <?php

include '../db_connect.php'; // Correct path: Go up one level to reach db_connect.php

header('Content-Type: application/json');

$response = ["success" => false, "message" => "An unknown error occurred."]; // Default response
$stmt = null; // Initialize statement

try {
    // Check for user login using the new session variable names
    if (!isset($_SESSION['loggedin_user_id']) || !isset($_SESSION['loggedin_phone_number'])) { 
        $response = ["success" => false, "message" => "Please log in to submit a recharge request.", "redirect" => "/project-teer/login/login.html"];
        echo json_encode($response);
        exit(); // Crucial: Exit immediately after sending response
    }

    $user_id = $_SESSION['loggedin_user_id']; // Use the new session variable name
    $user_phone_number = $_SESSION['loggedin_phone_number']; // Use the new session variable name
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;

    if ($amount <= 0) {
        $response = ["success" => false, "message" => "Invalid recharge amount."];
        echo json_encode($response);
        exit(); // Crucial: Exit immediately after sending response
    }

    // Start a transaction for atomicity
    $conn->begin_transaction();

    // SQL: Includes phone_number column
    $sql = "INSERT INTO recharge_requests (user_id, phone_number, amount, status) VALUES (?, ?, ?, 'pending')";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error);
    }
    $stmt->bind_param("isd", $user_id, $user_phone_number, $amount); 

    if ($stmt->execute()) {
        $conn->commit(); // Commit transaction
        $response = ["success" => true, "message" => "Recharge request submitted successfully. Pending admin approval.", "request_id" => $conn->insert_id];
    } else {
        throw new Exception("Failed to submit recharge request: " . $stmt->error);
    }
} catch (Exception $e) {
    if ($conn) $conn->rollback(); // Rollback on error
    error_log("Error in submit_recharge_request.php: " . $e->getMessage());
    $response = ["success" => false, "message" => "Error: " . $e->getMessage()];
} finally {
    if ($stmt) $stmt->close();
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();