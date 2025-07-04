<?php
// File: project-teer/mybalance/submit_withdrawal_request.php
// Handles submission of withdrawal requests from the user.
session_name("USER_SESSION"); // Set session name for user
session_start(); // THIS MUST BE THE FIRST EXECUTABLE LINE AFTER <?php

include '../db_connect.php'; // Correct path: Go up one level to reach db_connect.php

header('Content-Type: application/json');

$response = ["success" => false, "message" => "An unknown error occurred."]; // Default response
$stmt = null; // Initialize statement

try {
    // Check if user is logged in using the new session variable names
    if (!isset($_SESSION['loggedin_user_id']) || !isset($_SESSION['loggedin_username']) || !isset($_SESSION['loggedin_phone_number'])) {
        $response = ["success" => false, "message" => "Please log in to submit a withdrawal request.", "redirect" => "/project-teer/login/login.html"];
        echo json_encode($response);
        exit(); // Crucial: Exit immediately after sending response
    }

    $user_id = $_SESSION['loggedin_user_id']; // Use the new session variable name
    $username = $_SESSION['loggedin_username']; // Use the new session variable name
    $phone_number = $_SESSION['loggedin_phone_number']; // Use the new session variable name
    // $current_balance = isset($_SESSION['loggedin_balance']) ? floatval($_SESSION['loggedin_balance']) : 0.00; // REMOVED: No longer relying on session balance

    // Get data from POST request
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    $bank_name = isset($_POST['bank_name']) ? $conn->real_escape_string($_POST['bank_name']) : '';
    $account_holder = isset($_POST['account_holder']) ? $conn->real_escape_string($_POST['account_holder']) : '';
    $account_number = isset($_POST['account_number']) ? $conn->real_escape_string($_POST['account_number']) : '';
    $ifsc_code = isset($_POST['ifsc_code']) ? $conn->real_escape_string($_POST['ifsc_code']) : '';

    // Basic validation
    if ($amount <= 0) {
        $response = ["success" => false, "message" => "Invalid withdrawal amount."];
        echo json_encode($response);
        exit(); // Crucial: Exit immediately after sending response
    }
    
    // --- IMPORTANT CHANGE: Fetch current balance directly from the database ---
    $stmt_balance = $conn->prepare("SELECT balance FROM users WHERE id = ?");
    if (!$stmt_balance) {
        throw new Exception("SQL prepare failed (fetch balance): " . $conn->error);
    }
    $stmt_balance->bind_param("i", $user_id);
    $stmt_balance->execute();
    $result_balance = $stmt_balance->get_result();
    $user_data = $result_balance->fetch_assoc();
    $stmt_balance->close();

    if (!$user_data) {
        throw new Exception("User not found in database while checking balance.");
    }
    $current_actual_balance = floatval($user_data['balance']);

    // Now compare against the actual balance from the database
    if ($amount > $current_actual_balance) {
        $response = ["success" => false, "message" => "Withdrawal amount exceeds your current balance. Actual balance: ₹" . number_format($current_actual_balance, 2)];
        echo json_encode($response);
        exit(); // Crucial: Exit immediately after sending response
    }
    // --- END IMPORTANT CHANGE ---

    if (empty($bank_name) || empty($account_holder) || empty($account_number) || empty($ifsc_code)) {
        $response = ["success" => false, "message" => "Please fill all bank details."];
        echo json_encode($response);
        exit(); // Crucial: Exit immediately after sending response
    }

    // Start a transaction for atomicity
    $conn->begin_transaction();

    // 1. Insert the withdrawal request into the database
    // Ensure all these columns (username, phone_number, bank_name, account_holder, account_number, ifsc_code) exist in your withdrawal_requests table
    $sql = "INSERT INTO withdrawal_requests (user_id, username, phone_number, amount, bank_name, account_holder, account_number, ifsc_code, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("SQL prepare failed (insert withdrawal): " . $conn->error); // Changed error message
    }

    $stmt->bind_param("issdssss", $user_id, $username, $phone_number, $amount, $bank_name, $account_holder, $account_number, $ifsc_code);

    if ($stmt->execute()) {
        // 2. Deduct the amount from the user's balance ONLY AFTER successful insertion of request
        $sql_deduct = "UPDATE users SET balance = balance - ? WHERE id = ?";
        $stmt_deduct = $conn->prepare($sql_deduct);
        if (!$stmt_deduct) {
            throw new Exception("SQL prepare failed (deduct balance): " . $conn->error);
        }
        $stmt_deduct->bind_param("di", $amount, $user_id);
        if (!$stmt_deduct->execute()) {
            throw new Exception("Failed to deduct amount from user balance: " . $stmt_deduct->error);
        }
        $stmt_deduct->close();

        $conn->commit(); // Commit transaction if all successful
        $response = ["success" => true, "message" => "Withdrawal request submitted successfully and balance updated. Pending admin approval."];
    } else {
        throw new Exception("Failed to submit withdrawal request: " . $stmt->error);
    }
} catch (Exception $e) {
    if ($conn) $conn->rollback(); // Rollback transaction on error
    error_log("submit_withdrawal_request.php: Exception caught! Rolling back. Error: " . $e->getMessage());
    $response = ["success" => false, "message" => "Error: " . $e->getMessage()];
} finally {
    if ($stmt) $stmt->close();
    // if ($stmt_balance) $stmt_balance->close(); // Already closed above
    // if ($stmt_deduct) $stmt_deduct->close(); // Already closed above
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();