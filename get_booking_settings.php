<?php
// File: project-teer/get_booking_settings.php
header('Content-Type: application/json');

include 'db_connect.php'; // Adjust path if necessary

$response = ["success" => false, "message" => "An unknown error occurred.", "settings" => []];

try {
    // Fetch all booking_enabled settings
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'booking_enabled_%'");
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $settings = [];
    while ($row = $result->fetch_assoc()) {
        // Extract the actual setting name (e.g., 'first_round' from 'booking_enabled_first_round')
        $key_parts = explode('_', $row['setting_key']);
        // The relevant part starts from index 2 for 'booking_enabled_X'
        $clean_key = implode('_', array_slice($key_parts, 2)); 
        
        // Convert string 'true'/'false' to actual boolean values
        $settings[$clean_key] = ($row['setting_value'] === 'true');
    }
    $stmt->close();

    $response["success"] = true;
    $response["settings"] = $settings;

} catch (Exception $e) {
    error_log("Error in get_booking_settings.php: " . $e->getMessage());
    $response["message"] = "Database error: " . $e->getMessage();
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
?>