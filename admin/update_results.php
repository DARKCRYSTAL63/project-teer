<?php
// File: project-teer/admin/update_results.php
// Handles updating First Round and Second Round results from the admin panel.
session_name("ADMIN_SESSION"); // Set session name for admin
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

    $round_type = filter_input(INPUT_POST, 'round_type', FILTER_SANITIZE_STRING); // 'first_round', 'second_round', etc.
    $result_number = filter_input(INPUT_POST, 'result_number', FILTER_SANITIZE_STRING); // The 2-digit number
    $result_date = date('Y-m-d'); // Today's date

    // Input validation
    if (empty($round_type) || empty($result_number)) {
        $response = ["success" => false, "message" => "Missing round type or result number."];
        echo json_encode($response);
        exit();
    }
    if (!in_array($round_type, ['first_round', 'second_round', 'night_teer_fr', 'night_teer_sr'])) {
        $response = ["success" => false, "message" => "Invalid round type specified."];
        echo json_encode($response);
        exit();
    }
    if (!preg_match('/^\d{2}$/', $result_number) || intval($result_number) < 0 || intval($result_number) > 99) {
        $response = ["success" => false, "message" => "Result number must be 2 digits (00-99)."];
        echo json_encode($response);
        exit();
    }

    // Use INSERT ... ON DUPLICATE KEY UPDATE to handle existing entries
    $sql = "INSERT INTO results (result_date, round_type, result_number) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE result_number = ?, updated_at = CURRENT_TIMESTAMP";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error);
    }

    $stmt->bind_param("ssss", $result_date, $round_type, $result_number, $result_number);
    
    if ($stmt->execute()) {
        $response = ["success" => true, "message" => ucfirst(str_replace('_', ' ', $round_type)) . " result updated successfully to " . $result_number . "."];
    } else {
        throw new Exception("Failed to update result: " . $stmt->error);
    }

} catch (Exception $e) {
    error_log("Error in update_results.php: " . $e->getMessage());
    $response = ["success" => false, "message" => "Error updating result: " . $e->getMessage()];
} finally {
    if ($stmt) $stmt->close();
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();