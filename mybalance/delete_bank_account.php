<?php
// File: project-teer/mybalance/delete_bank_account.php
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

    if (empty($account_id)) {
        $response["message"] = "Missing account ID.";
        echo json_encode($response);
        exit();
    }

    try {
        // Ensure the account belongs to the logged-in user before deleting
        $stmt = $conn->prepare("DELETE FROM user_bank_accounts WHERE id = ? AND user_id = ?");
        if (!$stmt) {
            throw new Exception("SQL prepare failed: " . $conn->error);
        }
        $stmt->bind_param("ii", $account_id, $user_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $response["success"] = true;
                $response["message"] = "Bank account deleted successfully!";
            } else {
                $response["message"] = "Bank account not found or does not belong to you.";
            }
        } else {
            throw new Exception("Failed to delete bank account: " . $stmt->error);
        }
        $stmt->close();

    } catch (Exception $e) {
        error_log("Error deleting bank account: " . $e->getMessage());
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