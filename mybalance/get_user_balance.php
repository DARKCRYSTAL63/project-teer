<?php
// File: project-teer/mybalance/get_user_balance.php
session_name("USER_SESSION"); // Set session name for user
session_start(); // ENSURE THIS IS THE VERY FIRST LINE
header('Content-Type: application/json');

include '../db_connect.php'; // Adjust path if necessary

$response = ["success" => false, "message" => "An unknown error occurred."];

// --- DEBUGGING SESSION START ---
error_log("get_user_balance.php: Session ID: " . session_id());
error_log("get_user_balance.php: SESSION_ARRAY: " . print_r($_SESSION, true));
// --- DEBUGGING SESSION END ---

if (!isset($_SESSION['loggedin_user_id'])) {
    $response = ["success" => false, "message" => "User not logged in.", "redirect" => "login/login.html"];
    error_log("get_user_balance.php: User not logged in. Session loggedin_user_id not set.");
    echo json_encode($response);
    exit();
}

$user_id = $_SESSION['loggedin_user_id'];

try {
    // 1. Fetch user balance
    $stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        throw new Exception("User not found in database.");
    }

    // 2. Fetch all bank accounts for this user
    $bank_accounts = [];
    $stmt = $conn->prepare("SELECT id, bank_name, account_holder, account_number, ifsc_code FROM user_bank_accounts WHERE user_id = ? ORDER BY id ASC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result_accounts = $stmt->get_result();
    while ($row = $result_accounts->fetch_assoc()) {
        $bank_accounts[] = $row;
    }
    $stmt->close();

    $response = [
        "success" => true,
        "balance" => $user['balance'],
        "bank_accounts" => $bank_accounts // Return an array of bank accounts
    ];
    error_log("get_user_balance.php: Details fetched for user ID: " . $user_id . ", Balance: " . $user['balance'] . ", Bank Accounts Count: " . count($bank_accounts));

} catch (Exception $e) {
    error_log("get_user_balance.php: Database error: " . $e->getMessage());
    $response = ["success" => false, "message" => "Database error: " . $e->getMessage()];
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
?>