<?php
// File: project-teer/admin/update_booking_status_setting.php
// Updates a specific booking setting (e.g., enable/disable first round booking).
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

    $setting_key = filter_input(INPUT_POST, 'setting_key', FILTER_SANITIZE_STRING); // e.g., 'booking_enabled_first_round'
    $setting_value = filter_input(INPUT_POST, 'setting_value', FILTER_SANITIZE_STRING); // 'true' or 'false'

    // Input validation
    if (empty($setting_key) || !in_array($setting_value, ['true', 'false'])) {
        $response = ["success" => false, "message" => "Invalid setting key or value."];
        echo json_encode($response);
        exit();
    }
    
    // Ensure the key is one we expect to modify
    $allowed_keys = [
        'booking_enabled_first_round', 
        'booking_enabled_second_round',
        'booking_enabled_night_teer_fr',
        'booking_enabled_night_teer_sr',
        'booking_enabled_forecast' // NEW: Added forecast setting key
    ];
    if (!in_array($setting_key, $allowed_keys)) {
        $response = ["success" => false, "message" => "Invalid setting key provided."];
        echo json_encode($response);
        exit();
    }

    // Use INSERT ... ON DUPLICATE KEY UPDATE to handle existing entries
    $sql = "INSERT INTO settings (setting_key, setting_value) 
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = CURRENT_TIMESTAMP";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error);
    }

    $stmt->bind_param("sss", $setting_key, $setting_value, $setting_value);
    
    if ($stmt->execute()) {
        $action = ($setting_value === 'true') ? 'enabled' : 'disabled';
        // Improved message for forecast
        $display_key = str_replace('_', ' ', $setting_key);
        if ($setting_key === 'booking_enabled_forecast') {
            $display_key = 'Forecast Booking';
        } else {
            $display_key = ucwords(str_replace(['booking_enabled_', '_fr', '_sr'], ['', ' FR', ' SR'], $setting_key));
        }

        $response = ["success" => true, "message" => $display_key . " successfully " . $action . "."];
    } else {
        throw new Exception("Failed to update setting: " . $stmt->error);
    }

} catch (Exception $e) {
    error_log("Error in update_booking_status_setting.php: " . $e->getMessage());
    $response = ["success" => false, "message" => "Error updating setting: " . $e->getMessage()];
} finally {
    if ($stmt) $stmt->close();
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();