<?php
// File: project-teer/get_todays_results.php
// Fetches today's First Round and Second Round results from the 'results' table.

header('Content-Type: application/json');

include 'db_connect.php'; // Path to db_connect.php (same directory)

$response = ["success" => false, "message" => "No results found for today.", "results" => []];
$stmt = null;

try {
    $today_date = date('Y-m-d');

    // Fetch First Round and Second Round results for today
    $sql = "SELECT round_type, result_number 
            FROM results 
            WHERE result_date = ? 
            AND (round_type = 'first_round' OR round_type = 'second_round' OR round_type = 'night_teer_fr' OR round_type = 'night_teer_sr')
            ORDER BY updated_at DESC"; // Order to get latest if multiple updates somehow occurred
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error);
    }
    $stmt->bind_param("s", $today_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $results_data = [];
    while ($row = $result->fetch_assoc()) {
        $results_data[] = $row;
    }

    if (!empty($results_data)) {
        $response = ["success" => true, "message" => "Today's results fetched successfully.", "results" => $results_data];
    }

} catch (Exception $e) {
    error_log("Error in get_todays_results.php: " . $e->getMessage());
    $response = ["success" => false, "message" => "Database error: " . $e->getMessage()];
} finally {
    if ($stmt) $stmt->close();
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();