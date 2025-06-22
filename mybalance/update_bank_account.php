<?php
// File: project-teer/mybalance/update_bank_account.php
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
    $account_id = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);
    $bank_name = filter_input(INPUT_POST, 'bank_name', FILTER_SANITIZE_STRING);
    $account_holder = filter_input(INPUT_POST, 'account_holder', FILTER_SANITIZE_STRING);
    $account_number = filter_input(INPUT_POST, 'account_number', FILTER_SANITIZE_STRING);
    $ifsc_code = filter_input(INPUT_POST, 'ifsc_code', FILTER_SANITIZE_STRING);

    if (empty($account_id) || empty($bank_name) || empty($account_holder) || empty($account_number) || empty($ifsc_code)) {
        $response["message"] = "Missing required fields for update.";
        echo json_encode($response);
        exit();
    }

    // Basic validation
    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6,7}$/', $ifsc_code)) {
        $response["message"] = "Invalid IFSC Code format.";
        echo json_encode($response);
        exit();
    }
    if (!preg_match('/^[0-9]{5,20}$/', $account_number)) {
        $response["message"] = "Invalid Account Number format.";
        echo json_encode($response);
        exit();
    }

    try {
        // Ensure the account belongs to the logged-in user
        $stmt_check = $conn->prepare("SELECT COUNT(*) FROM user_bank_accounts WHERE id = ? AND user_id = ?");
        $stmt_check->bind_param("ii", $account_id, $user_id);
        $stmt_check->execute();
        $stmt_check->bind_result($count);
        $stmt_check->fetch();
        $stmt_check->close();

        if ($count == 0) {
            $response["message"] = "Account not found or does not belong to you.";
            echo json_encode($response);
            exit();
        }

        $stmt = $conn->prepare("UPDATE user_bank_accounts SET bank_name = ?, account_holder = ?, account_number = ?, ifsc_code = ? WHERE id = ? AND user_id = ?");
        if (!$stmt) {
            throw new Exception("SQL prepare failed: " . $conn->error);
        }
        $stmt->bind_param("ssssii", $bank_name, $account_holder, $account_number, $ifsc_code, $account_id, $user_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $response["success"] = true;
                $response["message"] = "Bank account updated successfully!";
            } else {
                $response["message"] = "No changes made to bank account or account not found.";
            }
        } else {
            throw new Exception("Failed to update bank account: " . $stmt->error);
        }
        $stmt->close();

    } catch (Exception $e) {
        error_log("Error updating bank account: " . $e->getMessage());
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