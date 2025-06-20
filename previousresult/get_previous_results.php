<?php
// File: project-teer/previousresults/get_previous_results.php
session_start(); // Start session, though not strictly required for public results, good practice
header('Content-Type: application/json');

include '../db_connect.php'; // Adjust path if necessary

$response = ["success" => false, "message" => "An unknown error occurred."];

try {
    // Fetch all results, ordered by date descending (newest first)
    $stmt = $conn->prepare("SELECT result_date, first_round_result, second_round_result FROM daily_results ORDER BY result_date DESC");
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $previous_results = [];
    while ($row = $result->fetch_assoc()) {
        $previous_results[] = $row;
    }
    $stmt->close();

    $response = [
        "success" => true,
        "message" => "Previous results fetched successfully.",
        "results" => $previous_results
    ];

} catch (Exception $e) {
    error_log("get_previous_results.php: Database error: " . $e->getMessage());
    $response = ["success" => false, "message" => "Database error: " . $e->getMessage()];
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
?>
