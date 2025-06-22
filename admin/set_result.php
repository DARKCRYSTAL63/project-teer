<?php
// File: project-teer/admin/set_result.php
// Handles setting/updating a result for a specific round and then triggers winner calculation.

session_name("ADMIN_SESSION"); // Use the admin session name
session_start();

header('Content-Type: application/json');

include '../db_connect.php'; // Path to db_connect.php (one level up)

// Configure error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/apache/logs/error.log'); // Log to Apache's main error.log

$response = ["success" => false, "message" => "An unknown error occurred."];

try {
    // --- Admin Authentication Check ---
    if (!isset($_SESSION['loggedin_admin_id']) || $_SESSION['loggedin_admin_role'] !== 'admin') {
        $response = ["success" => false, "message" => "Unauthorized access. Please log in as admin."];
        error_log("set_result.php: Unauthorized access attempt.");
        echo json_encode($response);
        exit();
    }

    $round_type = isset($_POST['round_type']) ? $_POST['round_type'] : '';
    $result_number = isset($_POST['result_number']) ? $_POST['result_number'] : '';
    $result_date = date('Y-m-d'); // Always set results for today's date

    // Validate inputs
    if (empty($round_type) || empty($result_number)) {
        $response = ["success" => false, "message" => "Round type and result number are required."];
        echo json_encode($response);
        exit();
    }

    // Basic validation for result_number (e.g., must be 2 digits)
    if (!preg_match('/^\d{2}$/', $result_number)) {
        $response = ["success" => false, "message" => "Result number must be exactly 2 digits."];
        echo json_encode($response);
        exit();
    }

    // Validate round_type
    $valid_round_types = ['first_round', 'second_round', 'night_teer_fr', 'night_teer_sr'];
    if (!in_array($round_type, $valid_round_types)) {
        $response = ["success" => false, "message" => "Invalid round type provided."];
        echo json_encode($response);
        exit();
    }

    $conn->begin_transaction(); // Start transaction

    // Check if a result for this round and date already exists
    $stmt_check = $conn->prepare("SELECT id FROM results WHERE result_date = ? AND round_type = ?");
    if (!$stmt_check) {
        throw new Exception("Check result SQL prepare failed: " . $conn->error);
    }
    $stmt_check->bind_param("ss", $result_date, $round_type);
    $stmt_check->execute();
    $existing_result = $stmt_check->get_result();
    $stmt_check->close();

    if ($existing_result->num_rows > 0) {
        // Result exists, update it
        $stmt_update = $conn->prepare("UPDATE results SET result_number = ?, updated_at = NOW() WHERE result_date = ? AND round_type = ?");
        if (!$stmt_update) {
            throw new Exception("Update result SQL prepare failed: " . $conn->error);
        }
        $stmt_update->bind_param("sss", $result_number, $result_date, $round_type);
        if (!$stmt_update->execute()) {
            throw new Exception("Failed to update result: " . $stmt_update->error);
        }
        $stmt_update->close();
        error_log("set_result.php: Updated result for " . $round_type . " on " . $result_date . " to " . $result_number);
    } else {
        // Result does not exist, insert it
        $stmt_insert = $conn->prepare("INSERT INTO results (result_date, round_type, result_number) VALUES (?, ?, ?)");
        if (!$stmt_insert) {
            throw new Exception("Insert result SQL prepare failed: " . $conn->error);
        }
        $stmt_insert->bind_param("sss", $result_date, $round_type, $result_number);
        if (!$stmt_insert->execute()) {
            throw new Exception("Failed to insert result: " . $stmt_insert->error);
        }
        $stmt_insert->close();
        error_log("set_result.php: Inserted new result for " . $round_type . " on " . $result_date . " as " . $result_number);
    }

    $conn->commit(); // Commit result update

    // --- Trigger Winner Calculation ---
    // Instead of duplicating logic, include find_winners.php and let it run
    // All relevant data ($conn, $_SESSION) is already available.
    // Ensure find_winners.php is structured to be safely included (no direct 'exit()' unless fatal error)
    error_log("set_result.php: Triggering find_winners.php after result update.");
    // Temporarily suppress header sending if find_winners.php also sends headers
    ob_start();
    include 'find_winners.php'; // Include the winner calculation script
    $find_winners_output = ob_get_clean(); // Capture any output
    error_log("set_result.php: find_winners.php output: " . $find_winners_output);

    // Assuming find_winners.php echoes a JSON response, we might parse it
    // or just assume it completed its work if no error was thrown.
    // For now, if no exception, assume success for this script.
    
    $response = ["success" => true, "message" => "Result updated and winners calculation triggered for " . $round_type . "."];

} catch (Exception $e) {
    if ($conn) $conn->rollback(); // Rollback on error
    error_log("set_result.php: Error setting result or calculating winners: " . $e->getMessage());
    $response = ["success" => false, "message" => "Error: " . $e->getMessage()];
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    error_log("set_result.php: Script finished.");
}

echo json_encode($response);
exit();