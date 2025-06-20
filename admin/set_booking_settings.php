<?php
// File: project-teer/admin/set_booking_settings.php
session_start(); // Start the session, crucial for admin authentication

header('Content-Type: application/json'); // Respond with JSON

// Include your database connection file
include '../db_connect.php'; 

$response = ["success" => false, "message" => "An unknown error occurred."];

// --- DEBUGGING SESSION START ---
error_log("set_booking_settings.php: Session ID: " . session_id());
error_log("set_booking_settings.php: SESSION_ARRAY: " . print_r($_SESSION, true));
// --- DEBUGGING SESSION END ---

// IMPORTANT: Implement proper admin authentication here
// For now, we'll proceed if it's a POST request, but in production,
// you MUST verify $_SESSION['is_admin'] or $_SESSION['admin_user_id']
/*
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    $response["message"] = "Unauthorized access.";
    echo json_encode($response);
    exit();
}
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $setting_key = filter_input(INPUT_POST, 'setting_key', FILTER_SANITIZE_STRING);
    $setting_value = filter_input(INPUT_POST, 'setting_value', FILTER_SANITIZE_STRING);

    error_log("set_booking_settings.php: Received setting_key: " . $setting_key . ", setting_value: " . $setting_value);

    // Validate inputs
    if (empty($setting_key) || ($setting_value !== 'true' && $setting_value !== 'false')) {
        $response["message"] = "Invalid setting key or value provided.";
        error_log("set_booking_settings.php: Validation failed. Key: " . $setting_key . ", Value: " . $setting_value);
        echo json_encode($response);
        exit();
    }

    // Prepend 'booking_enabled_' to the key to match database structure
    $full_setting_key = 'booking_enabled_' . $setting_key;

    $conn->begin_transaction(); // Start transaction

    try {
        // UPSERT (Update or Insert) the setting
        // Check if the setting exists
        $stmt = $conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $stmt->bind_param("s", $full_setting_key);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        if ($count > 0) {
            // Update existing setting
            $stmt = $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            if (!$stmt) {
                throw new Exception("SQL prepare failed (update): " . $conn->error);
            }
            $stmt->bind_param("ss", $setting_value, $full_setting_key);
            error_log("set_booking_settings.php: Updating existing setting: " . $full_setting_key . " to " . $setting_value);
        } else {
            // Insert new setting
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
            if (!$stmt) {
                throw new Exception("SQL prepare failed (insert): " . $conn->error);
            }
            $stmt->bind_param("ss", $full_setting_key, $setting_value);
            error_log("set_booking_settings.php: Inserting new setting: " . $full_setting_key . " with value " . $setting_value);
        }

        if (!$stmt->execute()) {
            throw new Exception("Failed to execute statement: " . $stmt->error);
        }
        $stmt->close();

        $conn->commit(); // Commit the transaction
        $response["success"] = true;
        $response["message"] = "Setting for " . str_replace('_', ' ', $setting_key) . " updated successfully to '" . $setting_value . "'.";
        error_log("set_booking_settings.php: Setting updated/inserted successfully.");

    } catch (Exception $e) {
        $conn->rollback(); // Rollback on error
        error_log("set_booking_settings.php: Database error: " . $e->getMessage());
        $response["message"] = "Database error: " . $e->getMessage();
    } finally {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->close();
        }
    }
} else {
    $response["message"] = "Invalid request method.";
    error_log("set_booking_settings.php: Invalid request method. Expected POST.");
}

echo json_encode($response);
exit();
?>