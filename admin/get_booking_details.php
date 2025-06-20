<?php
// File: project-teer/admin/get_booking_details.php
// Fetches detailed information for a single booking for the admin panel modal.

session_start(); // Start the session

header('Content-Type: application/json'); // Set content type to JSON

include '../db_connect.php'; // Path to your db_connect.php (one level up from 'admin' folder)

$response = ["success" => false, "message" => "An unknown error occurred.", "booking" => null];
$stmt = null;

try {
    // Check if admin is logged in (optional, but good practice for admin panel)
    // if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    //     $response = ["success" => false, "message" => "Unauthorized access. Please log in as admin."];
    //     echo json_encode($response);
    //     exit();
    // }

    $booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($booking_id <= 0) {
        $response = ["success" => false, "message" => "Invalid booking ID."];
        echo json_encode($response);
        exit();
    }

    $sql = "SELECT b.*, u.username FROM bookings b JOIN users u ON b.user_id = u.id WHERE b.id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error);
    }

    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $booking = $result->fetch_assoc();
        $response = ["success" => true, "message" => "Booking details fetched successfully.", "booking" => $booking];
    } else {
        $response = ["success" => false, "message" => "Booking not found."];
    }

} catch (Exception $e) {
    error_log("Error in get_booking_details.php: " . $e->getMessage());
    $response = ["success" => false, "message" => "Failed to fetch booking details: " . $e->getMessage()];
} finally {
    if ($stmt) $stmt->close();
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();