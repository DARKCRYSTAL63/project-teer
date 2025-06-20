<?php
// File: project-teer/admin/reset_results.php
// Clears today's First Round, Second Round, and Night Teer results from the 'results' table.

session_start();

header('Content-Type: application/json');

include '../db_connect.php'; // Path to db_connect.php

$response = ["success" => false, "message" => "An unknown error occurred."];
$stmt = null;

try {
    // Basic admin session check (uncomment if you have this in place)
    // if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    //     $response = ["success" => false, "message" => "Unauthorized access."];
    //     echo json_encode($response);
    //     exit();
    // }

    $today_date = date('Y-m-d');

    // Delete all results for today
    $sql = "DELETE FROM results WHERE result_date = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error);
    }

    $stmt->bind_param("s", $today_date);
    
    if ($stmt->execute()) {
        $affected_rows = $stmt->affected_rows;
        $response = ["success" => true, "message" => "Successfully reset " . $affected_rows . " result(s) for today."];
    } else {
        throw new Exception("Failed to reset results: " . $stmt->error);
    }

} catch (Exception $e) {
    error_log("Error in reset_results.php: " . $e->getMessage());
    $response = ["success" => false, "message" => "Error resetting results: " . $e->getMessage()];
} finally {
    if ($stmt) $stmt->close();
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();