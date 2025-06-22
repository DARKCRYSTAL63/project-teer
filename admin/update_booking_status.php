<?php
// File: project-teer/admin/update_booking_status.php
// Updates the status of a specific booking.
session_name("ADMIN_SESSION"); // Set session name for admin
session_start(); // Start the session

header('Content-Type: application/json'); // Set content type to JSON

include '../db_connect.php'; // Path to your db_connect.php (one level up from 'admin' folder)

$response = ["success" => false, "message" => "An unknown error occurred."];
$stmt = null;

try {
    // Check if admin is logged in (optional, but good practice for admin panel)
    // if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    //     $response = ["success" => false, "message" => "Unauthorized access. Please log in as admin."];
    //     echo json_encode($response);
    //     exit();
    // }

    $booking_id = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
    $new_status = isset($_POST['new_status']) ? $_POST['new_status'] : '';

    // Validate inputs
    if ($booking_id <= 0 || empty($new_status)) {
        $response = ["success" => false, "message" => "Invalid booking ID or new status provided."];
        echo json_encode($response);
        exit();
    }

    // Define allowed statuses to prevent arbitrary updates
    $allowed_statuses = ['active', 'won', 'lost', 'cancelled'];
    if (!in_array($new_status, $allowed_statuses)) {
        $response = ["success" => false, "message" => "Invalid status value."];
        echo json_encode($response);
        exit();
    }

    $sql = "UPDATE bookings SET status = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error);
    }

    $stmt->bind_param("si", $new_status, $booking_id);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $response = ["success" => true, "message" => "Booking status updated successfully to " . ucfirst($new_status) . "."];
        } else {
            $response = ["success" => false, "message" => "Booking not found or status already " . ucfirst($new_status) . "."];
        }
    } else {
        throw new Exception("Failed to update booking status: " . $stmt->error);
    }

} catch (Exception $e) {
    error_log("Error in update_booking_status.php: " . $e->getMessage());
    $response = ["success" => false, "message" => "Error updating booking status: " . $e->getMessage()];
} finally {
    if ($stmt) $stmt->close();
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();