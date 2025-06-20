<?php
// File: project-teer/mytickets/get_user_tickets.php
session_start(); // ENSURE THIS IS THE VERY FIRST LINE
header('Content-Type: application/json');

include '../db_connect.php'; // Adjust path if necessary

$response = ["success" => false, "message" => "An unknown error occurred."];

if (!isset($_SESSION['loggedin_user_id'])) {
    $response = ["success" => false, "message" => "User not logged in.", "redirect" => "../login/login.html"];
    error_log("get_user_tickets.php: User not logged in. Session loggedin_user_id not set.");
    echo json_encode($response);
    exit();
}

$user_id = $_SESSION['loggedin_user_id'];
$today_date = date('Y-m-d'); // Get today's date inYYYY-MM-DD format for comparison

try {
    $stmt = $conn->prepare("SELECT id, booking_type, round_type, amount_per_unit, total_amount, numbers_booked_json, status, booked_at, win_amount FROM bookings WHERE user_id = ? ORDER BY booked_at DESC");
    if (!$stmt) {
        throw new Exception("SQL prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $today_purchased_tickets = [];
    $previous_purchased_tickets = [];
    $winning_tickets = [];

    while ($booking = $result->fetch_assoc()) {
        $booking_date = date('Y-m-d', strtotime($booking['booked_at']));
        
        // Ensure win_amount is a float, default to 0.00 if null or not numeric
        // Using floatval for robust conversion
        $booking['win_amount'] = floatval($booking['win_amount']); 

        $formatted_numbers = 'N/A'; // Default value
        try {
            $numbers_data = json_decode($booking['numbers_booked_json'], true);
            
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($numbers_data)) { // Check if decode failed or not an array
                error_log("JSON Decode/Structure Error for booking ID " . $booking['id'] . " (" . $booking['booking_type'] . "): " . json_last_error_msg() . ". Raw JSON: " . $booking['numbers_booked_json']);
                $formatted_numbers = "JSON Error / Bad Structure"; 
            } else { // Valid array, proceed with type-specific parsing
                switch ($booking['booking_type']) {
                    case 'single':
                    case 'night_teer':
                        // Expected structure: ["12"], ["50"] OR [{"number":"12"},{"number":"34"}]
                        $extracted_numbers = [];
                        foreach ($numbers_data as $item) {
                            if (is_string($item) && strlen($item) > 0) { // If it's a direct string like "12"
                                $extracted_numbers[] = $item;
                            } elseif (is_array($item) && isset($item['number']) && is_string($item['number']) && strlen($item['number']) > 0) { // If it's {"number":"12"}
                                $extracted_numbers[] = $item['number'];
                            }
                        }
                        $formatted_numbers = !empty($extracted_numbers) ? implode(', ', $extracted_numbers) : 'N/A';
                        break;

                    case 'forecast':
                        // Expected structure: [{"round":"first_round","number":"34"},{"round":"second_round","number":"58"}]
                        $forecast_display = [];
                        foreach ($numbers_data as $item) {
                            if (is_array($item) && isset($item['round']) && isset($item['number']) && is_string($item['number']) && strlen($item['number']) > 0) {
                                $round_display_short = ($item['round'] === 'first_round') ? 'FR' : 'SR';
                                $forecast_display[] = "$round_display_short: " . $item['number'];
                            }
                        }
                        $formatted_numbers = !empty($forecast_display) ? implode(', ', $forecast_display) : 'N/A';
                        break;

                    case 'house':
                    case 'ending':
                        // Expected structure: ["1"] OR [{"digit":"1"},{"digit":"5"}]
                        $extracted_digits = [];
                        foreach ($numbers_data as $item) {
                            if (is_string($item) && strlen($item) > 0) { // If it's a direct string like "1"
                                $extracted_digits[] = $item;
                            } elseif (is_array($item) && isset($item['digit']) && is_string($item['digit']) && strlen($item['digit']) > 0) { // If it's {"digit":"1"}
                                $extracted_digits[] = $item['digit'];
                            }
                        }
                        $formatted_numbers = !empty($extracted_digits) ? implode(', ', $extracted_digits) : 'N/A';
                        break;

                    case 'pair':
                        // Expected structure: [{"numbers":["00","11",...],"round":"first_round"}]
                        if (!empty($numbers_data) && isset($numbers_data[0]['numbers']) && is_array($numbers_data[0]['numbers'])) {
                            $display_count = min(count($numbers_data[0]['numbers']), 10);
                            $displayed_pairs = array_slice($numbers_data[0]['numbers'], 0, $display_count);
                            $formatted_numbers = implode(', ', $displayed_pairs);
                            if (count($numbers_data[0]['numbers']) > $display_count) {
                                $formatted_numbers .= '...';
                            }
                        } else {
                            $formatted_numbers = "All Pairs (00-99)"; // Fallback
                        }
                        break;

                    default:
                        $formatted_numbers = "Unknown Type (Check Log)";
                        error_log("Unknown booking_type encountered: " . $booking['booking_type'] . ". JSON: " . $booking['numbers_booked_json']);
                        break;
                }
            }
        } catch (Exception $e) {
            error_log("Exception during number parsing for booking ID " . $booking['id'] . ": " . $e->getMessage() . ". Raw JSON: " . $booking['numbers_booked_json']);
            $formatted_numbers = "Error (Check Log)";
        }
        $booking['formatted_numbers'] = $formatted_numbers;


        if ($booking_date === $today_date) {
            $today_purchased_tickets[] = $booking;
        } else {
            $previous_purchased_tickets[] = $booking;
        }

        // --- WINNING TICKET DEBUGGING ADDITION HERE ---
        error_log("Winning Ticket Debug: ID: " . $booking['id'] . ", Status: '" . $booking['status'] . "', Raw Win Amount: '" . (isset($booking['win_amount']) ? $booking['win_amount'] : 'NULL/UNDEFINED') . "', Cast to float: " . floatval($booking['win_amount']));

        if ($booking['status'] === 'won' && floatval($booking['win_amount']) > 0) { // Explicitly cast to float again
            error_log("Winning Ticket Debug: ADDING booking ID " . $booking['id'] . " to winning_tickets array.");
            $winning_tickets[] = $booking;
        } else {
            error_log("Winning Ticket Debug: SKIPPING booking ID " . $booking['id'] . ". Condition failed.");
        }
        // --- END WINNING TICKET DEBUGGING ADDITION ---
    }
    $stmt->close();

    $response = [
        "success" => true,
        "message" => "Tickets fetched successfully.",
        "today_purchased_tickets" => $today_purchased_tickets,
        "previous_purchased_tickets" => $previous_purchased_tickets,
        "winning_tickets" => $winning_tickets 
    ];

} catch (Exception $e) {
    error_log("get_user_tickets.php: Database error: " . $e->getMessage());
    $response = ["success" => false, "message" => "Database error: " . $e->getMessage()];
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
?>