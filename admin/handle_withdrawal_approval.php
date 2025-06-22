<?php
// File: project-teer/admin/handle_withdrawal_approval.php
// Handles approval or rejection of withdrawal requests by admin.
session_name("ADMIN_SESSION"); // Set session name for admin
session_start(); // THIS MUST BE THE FIRST EXECUTABLE LINE AFTER <?php

include '../db_connect.php'; 

header('Content-Type: application/json');

// --- ROBUST ADMIN AUTHENTICATION ---
// Check if user is logged in AND has the 'admin' role using new session variables
if (!isset($_SESSION['loggedin_admin_id']) || !isset($_SESSION['loggedin_admin_role']) || $_SESSION['loggedin_admin_role'] !== 'admin') {
    echo json_encode(["success" => false, "message" => "Unauthorized access. Please log in as admin."]);
    exit(); // Exit immediately after unauthorized response
}

$response = ["success" => false, "message" => "Invalid request."]; // Initialize $response

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = filter_input(INPUT_POST, 'request_id', FILTER_SANITIZE_NUMBER_INT);
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);

    if ($request_id <= 0 || !in_array($action, ['approve', 'reject'])) {
        $response = ["success" => false, "message" => "Invalid request ID or action."];
        echo json_encode($response);
        exit(); // Exit immediately after invalid input response
    }

    // Start a transaction for atomicity
    $conn->begin_transaction();

    try {
        // Get current request details
        $stmt = $conn->prepare("SELECT user_id, amount, status FROM withdrawal_requests WHERE id = ?");
        if (!$stmt) {
            throw new Exception("SQL prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $request = $result->fetch_assoc();
        $stmt->close(); 

        if (!$request) {
            $response = ["success" => false, "message" => "Withdrawal request not found."];
            $conn->rollback();
            echo json_encode($response);
            exit(); // Exit immediately
        }

        if ($request['status'] !== 'pending') {
            $response = ["success" => false, "message" => "Request already " . $request['status'] . "."];
            $conn->rollback();
            echo json_encode($response);
            exit(); // Exit immediately
        }

        if ($action === 'approve') {
            // Update withdrawal request status
            $stmt_update_withdrawal = $conn->prepare("UPDATE withdrawal_requests SET status = 'approved', approved_at = NOW() WHERE id = ?");
            if (!$stmt_update_withdrawal) {
                throw new Exception("SQL prepare failed (update withdrawal): " . $conn->error);
            }
            $stmt_update_withdrawal->bind_param("i", $request_id);
            $stmt_update_withdrawal->execute();
            $stmt_update_withdrawal->close();

            // Deduct balance from user (if not already done at withdrawal request creation)
            // Assumed: balance is deducted *when approved* rather than when requested.
            $stmt_update_user_balance = $conn->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
            if (!$stmt_update_user_balance) {
                throw new Exception("SQL prepare failed (update user balance): " . $conn->error);
            }
            $stmt_update_user_balance->bind_param("di", $request['amount'], $request['user_id']);
            $stmt_update_user_balance->execute();
            $stmt_update_user_balance->close();
            
            $response = ["success" => true, "message" => "Withdrawal request approved successfully!"];

        } elseif ($action === 'reject') {
            // Update withdrawal request status
            $stmt_update_withdrawal = $conn->prepare("UPDATE withdrawal_requests SET status = 'rejected', approved_at = NOW() WHERE id = ?");
            if (!$stmt_update_withdrawal) {
                throw new Exception("SQL prepare failed (update withdrawal): " . $conn->error);
            }
            $stmt_update_withdrawal->bind_param("i", $request_id);
            $stmt_update_withdrawal->execute();
            $stmt_update_withdrawal->close();

            // No balance refund needed here if deduction happens only on approval.
            
            $response = ["success" => true, "message" => "Withdrawal request rejected."];
        }

        $conn->commit(); // Commit transaction if all successful

    } catch (Exception $e) { 
        if ($conn) $conn->rollback(); // Rollback transaction on error
        error_log("Error handling withdrawal approval: " . $e->getMessage());
        $response = ["success" => false, "message" => "Database error: " . $e->getMessage()];
    } finally {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->close();
        }
    }
} else {
    $response = ["success" => false, "message" => "Invalid request method."];
}

echo json_encode($response);
exit();