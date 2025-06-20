<?php
// File: project-teer/admin/get_bookings.php
// Fetches booking data for the admin panel with filtering and pagination.

session_start(); // Start the session

header('Content-Type: application/json'); // Set content type to JSON

include '../db_connect.php'; // Path to your db_connect.php (one level up from 'admin' folder)

$response = ["success" => false, "message" => "An unknown error occurred.", "bookings" => [], "total_records" => 0];
$stmt = null; // Initialize $stmt to null for error handling

try {
    // Check if admin is logged in (optional, but good practice for admin panel)
    // if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    //     $response = ["success" => false, "message" => "Unauthorized access. Please log in as admin."];
    //     echo json_encode($response);
    //     exit();
    // }

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10; // Default 10 bookings per page
    $offset = ($page - 1) * $limit;

    $booking_type = isset($_GET['booking_type']) ? $_GET['booking_type'] : '';
    $round_type = isset($_GET['round_type']) ? $_GET['round_type'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $user_search = isset($_GET['user_search']) ? $_GET['user_search'] : ''; // Can be ID or username
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : ''; // YYYY-MM-DD
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';     // YYYY-MM-DD

    // Build the WHERE clause and parameters for filtering
    $where_clauses = [];
    $params = [];
    $param_types = "";

    // Booking Type
    if (!empty($booking_type)) {
        $where_clauses[] = "b.booking_type = ?";
        $param_types .= "s";
        $params[] = $booking_type;
    }

    // Round Type
    if (!empty($round_type)) {
        $where_clauses[] = "b.round_type = ?";
        $param_types .= "s";
        $params[] = $round_type;
    }

    // Status
    if (!empty($status)) {
        $where_clauses[] = "b.status = ?";
        $param_types .= "s";
        $params[] = $status;
    }

    // User Search (by ID or Username)
    if (!empty($user_search)) {
        // Try to convert to int for ID search, otherwise search by username
        if (is_numeric($user_search)) {
            $where_clauses[] = "(u.id = ? OR u.username LIKE ?)";
            $param_types .= "is";
            $params[] = (int)$user_search;
            $params[] = "%" . $user_search . "%";
        } else {
            $where_clauses[] = "u.username LIKE ?";
            $param_types .= "s";
            $params[] = "%" . $user_search . "%";
        }
    }

    // Date Range
    if (!empty($start_date)) {
        $where_clauses[] = "DATE(b.booked_at) >= ?";
        $param_types .= "s";
        $params[] = $start_date;
    }
    if (!empty($end_date)) {
        $where_clauses[] = "DATE(b.booked_at) <= ?";
        $param_types .= "s";
        $params[] = $end_date;
    }

    $where_sql = '';
    if (!empty($where_clauses)) {
        $where_sql = " WHERE " . implode(" AND ", $where_clauses);
    }

    // --- First, get total records count for pagination ---
    $count_sql = "SELECT COUNT(b.id) AS total_records 
                  FROM bookings b 
                  JOIN users u ON b.user_id = u.id" . $where_sql;
    
    $stmt = $conn->prepare($count_sql);
    if (!$stmt) {
        throw new Exception("Count SQL prepare failed: " . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $count_result = $stmt->get_result()->fetch_assoc();
    $total_records = $count_result['total_records'];
    $stmt->close(); // Close count statement

    // --- Now, fetch the actual bookings ---
    $sql = "SELECT b.*, u.username 
            FROM bookings b 
            JOIN users u ON b.user_id = u.id"
            . $where_sql . 
            " ORDER BY b.booked_at DESC LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Fetch SQL prepare failed: " . $conn->error);
    }

    // Append limit and offset parameters
    $param_types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    // Use a reference array for bind_param if the parameter types string is built dynamically
    // This is safer than directly using ...$params with dynamically built $param_types
    $bind_args = [];
    $bind_args[] = &$param_types; // First argument is the type string
    for ($i = 0; $i < count($params); $i++) {
        $bind_args[] = &$params[$i]; // Subsequent arguments are references to values
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_args);

    $stmt->execute();
    $result = $stmt->get_result();

    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }

    $response = ["success" => true, "message" => "Bookings fetched successfully.", "bookings" => $bookings, "total_records" => $total_records];

} catch (Exception $e) {
    error_log("Error in get_bookings.php: " . $e->getMessage());
    $response = ["success" => false, "message" => "Failed to fetch bookings: " . $e->getMessage(), "error_details" => $e->getMessage()];
} finally {
    if ($stmt) $stmt->close();
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();