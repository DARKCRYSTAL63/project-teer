<?php
// File: project-teer/mybalance/add_bank_account.php
session_name("USER_SESSION"); // Set session name for user
session_start(); // ENSURE THIS IS THE VERY FIRST LINE
header('Content-Type: application/json');

include '../db_connect.php'; 

$response = ["success" => false, "message" => "An unknown error occurred."];

if (!isset($_SESSION['loggedin_user_id'])) {
    $response["message"] = "User not logged in.";
    echo json_encode($response);
    exit();
}

$user_id = $_SESSION['loggedin_user_id'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $bank_name = filter_input(INPUT_POST, 'bank_name', FILTER_SANITIZE_STRING);
    $account_holder = filter_input(INPUT_POST, 'account_holder', FILTER_SANITIZE_STRING);
    $account_number = filter_input(INPUT_POST, 'account_number', FILTER_SANITIZE_STRING);
    $ifsc_code = filter_input(INPUT_POST, 'ifsc_code', FILTER_SANITIZE_STRING);

    if (empty($bank_name) || empty($account_holder) || empty($account_number) || empty($ifsc_code)) {
        $response["message"] = "All bank details fields are required.";
        echo json_encode($response);
        exit();
    }

    // Basic validation
    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6,7}$/', $ifsc_code)) {
        $response["message"] = "Invalid IFSC Code format. (e.g., ABCD0123456 or ABCD01234567)";
        echo json_encode($response);
        exit();
    }
    if (!preg_match('/^[0-9]{5,20}$/', $account_number)) {
        $response["message"] = "Invalid Account Number format. Must be 5-20 digits.";
        echo json_encode($response);
        exit();
    }

    try {
        // Optional: Check for duplicate account number for the same user (if desired)
        $stmt_check = $conn->prepare("SELECT COUNT(*) FROM user_bank_accounts WHERE user_id = ? AND account_number = ?");
        $stmt_check->bind_param("is", $user_id, $account_number);
        $stmt_check->execute();
        $stmt_check->bind_result($count);
        $stmt_check->fetch();
        $stmt_check->close();

        if ($count > 0) {
            $response["message"] = "This account number is already saved for your profile.";
            echo json_encode($response);
            exit();
        }

        $stmt = $conn->prepare("INSERT INTO user_bank_accounts (user_id, bank_name, account_holder, account_number, ifsc_code) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("SQL prepare failed: " . $conn->error);
        }
        $stmt->bind_param("issss", $user_id, $bank_name, $account_holder, $account_number, $ifsc_code);

        if ($stmt->execute()) {
            $response["success"] = true;
            $response["message"] = "Bank account added successfully!";
        } else {
            throw new Exception("Failed to add bank account: " . $stmt->error);
        }
        $stmt->close();

    } catch (Exception $e) {
        error_log("Error adding bank account: " . $e->getMessage());
        $response["message"] = "Database error: " . $e->getMessage();
    } finally {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->close();
        }
    }
} else {
    $response["message"] = "Invalid request method.";
}

echo json_encode($response);
exit();