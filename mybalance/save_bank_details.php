<?php
// File: project-teer/mybalance/save_bank_details.php
session_name("USER_SESSION"); // Set session name for user
session_start(); // ENSURE THIS IS THE VERY FIRST LINE
header('Content-Type: application/json');

include '../db_connect.php'; // Path to your database connection file

$response = ["success" => false, "message" => "An unknown error occurred."];

// Check for the user's preferred session variable name
if (!isset($_SESSION['loggedin_user_id'])) {
    $response["message"] = "User not logged in.";
    echo json_encode($response);
    exit();
}

$user_id = $_SESSION['loggedin_user_id']; // Use the correct session variable

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $bank_name = filter_input(INPUT_POST, 'bank_name', FILTER_SANITIZE_STRING);
    $account_holder = filter_input(INPUT_POST, 'account_holder', FILTER_SANITIZE_STRING);
    $account_number = filter_input(INPUT_POST, 'account_number', FILTER_SANITIZE_STRING);
    $ifsc_code = filter_input(INPUT_POST, 'ifsc_code', FILTER_SANITIZE_STRING);

    // Basic validation
    if (empty($bank_name) || empty($account_holder) || empty($account_number) || empty($ifsc_code)) {
        $response["message"] = "All bank details fields are required.";
        echo json_encode($response);
        exit();
    }

    // More robust validation (e.g., regex for IFSC, account number length/format)
    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6,7}$/', $ifsc_code)) { 
        $response["message"] = "Invalid IFSC Code format. (e.g., ABCD0123456 or ABCD01234567)";
        echo json_encode($response);
        exit();
    }
    if (!preg_match('/^[0-9]{5,20}$/', $account_number)) { // 5 to 20 digits
        $response["message"] = "Invalid Account Number format. Must be 5-20 digits.";
        echo json_encode($response);
        exit();
    }

    try {
        $stmt = $conn->prepare("UPDATE users SET bank_name = ?, account_holder = ?, account_number = ?, ifsc_code = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception("SQL prepare failed: " . $conn->error);
        }
        $stmt->bind_param("ssssi", $bank_name, $account_holder, $account_number, $ifsc_code, $user_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $response["success"] = true;
                $response["message"] = "Bank details saved successfully!";
            } else {
                $response["message"] = "No changes made (details already up-to-date) or user not found.";
            }
        } else {
            throw new Exception("Failed to update bank details: " . $stmt->error);
        }
        $stmt->close();

    } catch (Exception $e) {
        error_log("Error saving bank details: " . $e->getMessage());
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