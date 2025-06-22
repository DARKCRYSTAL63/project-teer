<?php
// File: project-teer/admin/get_bookings.php
// Fetches booking data with pagination and filters for the admin panel.
session_name("ADMIN_SESSION"); // Set session name for admin
session_start();

header('Content-Type: application/json');

include '../db_connect.php'; // Path to db_connect.php

$response = ["success" => false, "message" => "An unknown error occurred.", "bookings" => [], "total_records" => 0, "total_pages" => 0];
$stmt = null;

try {
    // Admin authentication check
    if (!isset($_SESSION['loggedin_admin_id']) || !isset($_SESSION['loggedin_admin_role']) || $_SESSION['loggedin_admin_role'] !== 'admin') {
        $response = ["success" => false, "message" => "Unauthorized access. Please log in as admin."];
        echo json_encode($response);
        exit();
    }

    // Pagination parameters
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    // Calculate offset for SQL LIMIT clause
    $offset = ($page - 1) * $limit;

    // Filter parameters from GET request
    $booking_type = isset($_GET['booking_type']) ? $_GET['booking_type'] : '';
    $round_type = isset($_GET['round_type']) ? $_GET['round_type'] : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $user_search = isset($_GET['user_search']) ? trim($_GET['user_search']) : '';
    $date_filter = isset($_GET['date']) ? $_GET['date'] : ''; // Single date filter (YYYY-MM-DD)

    // Array to hold WHERE clause conditions
    $where_clauses = [];
    // Array to hold parameters for binding (in the correct order)
    $params = [];
    // String to hold parameter types for bind_param (e.g., 'ssi' for two strings and one integer)
    $param_types = '';

    // 1. Date Filter
    if (!empty($date_filter)) {
        // Add condition to filter by date (using DATE() function for YYYY-MM-DD comparison)
        $where_clauses[] = "DATE(b.booked_at) = ?";
        $params[] = $date_filter; // Add date to parameters
        $param_types .= 's';    // 's' for string type
    }

    // 2. Booking Type Filter
    if (!empty($booking_type)) {
        $where_clauses[] = "b.booking_type = ?";
        $params[] = $booking_type;
        $param_types .= 's';
    }

    // 3. Round Type Filter
    if (!empty($round_type)) {
        $where_clauses[] = "b.round_type = ?";
        $params[] = $round_type;
        $param_types .= 's';
    }

    // 4. Status Filter
    if (!empty($status)) {
        $where_clauses[] = "b.status = ?";
        $params[] = $status;
        $param_types .= 's';
    }

    // 5. User Search Filter (by User ID, Username, or Phone Number)
    if (!empty($user_search)) {
        // Check if user_search is numeric (can be a user ID)
        if (is_numeric($user_search)) {
            // If numeric, search by ID OR by username/phone number using LIKE
            $where_clauses[] = "(u.id = ? OR u.username LIKE ? OR u.phone_number LIKE ?)";
            $params[] = $user_search;
            $params[] = '%' . $user_search . '%'; // Add wildcards for LIKE
            $params[] = '%' . $user_search . '%';
            $param_types .= 'iss'; // 'i' for integer ID, 's' for username, 's' for phone_number
        } else {
            // If not numeric, only search by username or phone number using LIKE
            $where_clauses[] = "(u.username LIKE ? OR u.phone_number LIKE ?)";
            $params[] = '%' . $user_search . '%';
            $params[] = '%' . $user_search . '%';
            $param_types .= 'ss'; // 's' for username, 's' for phone_number
        }
    }

    // Combine all WHERE clauses with 'AND'
    $where_sql = '';
    if (!empty($where_clauses)) {
        $where_sql = " WHERE " . implode(" AND ", $where_clauses);
    }

    // --- Step 1: Get the total number of records matching the filters for pagination ---
    $count_sql = "SELECT COUNT(b.id) AS total_records
                  FROM bookings b
                  JOIN users u ON b.user_id = u.id" . $where_sql; // Join with users to allow user search filter
    
    $stmt_count = $conn->prepare($count_sql);
    if (!$stmt_count) {
        throw new Exception("Count SQL prepare failed: " . $conn->error);
    }

    // If there are parameters for the WHERE clause, bind them to the count query
    if (!empty($params)) {
        // Use call_user_func_array to bind parameters as bind_param requires separate arguments
        // We only bind the WHERE clause parameters for the count query, not limit/offset yet
        $bind_params_for_count = array_merge([$param_types], $params);
        call_user_func_array([$stmt_count, 'bind_param'], refValues($bind_params_for_count));
    }
    $stmt_count->execute();
    $count_result = $stmt_count->get_result();
    $total_records = $count_result->fetch_assoc()['total_records'];
    $stmt_count->close(); // Close the count statement

    // Calculate total pages
    $total_pages = ceil($total_records / $limit);

    // --- Step 2: Fetch the actual bookings data with pagination and filters ---
    $sql = "SELECT
                b.id AS booking_id,
                b.user_id,
                u.username,             -- Include username for display
                b.booking_type,
                b.round_type,
                b.numbers_booked_json,
                b.amount_per_unit,
                b.total_amount,
                b.status,
                b.booked_at
            FROM bookings b
            JOIN users u ON b.user_id = u.id" // Join users table to allow filtering by username/ID
            . $where_sql .
            " ORDER BY b.booked_at DESC LIMIT ?, ?"; // Add LIMIT and OFFSET for pagination
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Bookings SQL prepare failed: " . $conn->error);
    }

    // Append pagination parameters (offset and limit) to the existing filter parameters
    $params[] = $offset;
    $params[] = $limit;
    $param_types .= 'ii'; // Add 'ii' for the two integer parameters (offset, limit)

    // Bind all parameters for the main query
    // Using call_user_func_array to dynamically bind parameters
    call_user_func_array([$stmt, 'bind_param'], refValues(array_merge([$param_types], $params)));

    $stmt->execute();
    $result = $stmt->get_result();

    $bookings_data = [];
    while ($row = $result->fetch_assoc()) {
        $bookings_data[] = $row;
    }

    // Set success response
    $response["success"] = true;
    $response["message"] = "Bookings fetched successfully.";
    $response["bookings"] = $bookings_data;
    $response["total_records"] = $total_records;
    $response["total_pages"] = $total_pages;

} catch (Exception $e) {
    // Log the error for server-side debugging
    error_log("Error in get_bookings.php: " . $e->getMessage());
    // Set an error response
    $response = [
        "success" => false,
        "message" => "Database error: " . $e->getMessage(),
        "bookings" => [],
        "total_records" => 0,
        "total_pages" => 0
    ];
} finally {
    // Ensure the statement is closed if it was prepared
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    // Ensure the database connection is closed
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

// Helper function to pass parameters by reference for bind_param
// This is necessary because call_user_func_array with bind_param requires references
function refValues($arr){
    if (strnatcmp(phpversion(),'5.3') >= 0) //Reference is required for PHP 5.3+
    {
        $refs = array();
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
    return $arr;
}

echo json_encode($response);
exit(); // Always exit after echoing JSON