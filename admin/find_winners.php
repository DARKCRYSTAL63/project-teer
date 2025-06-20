<?php
// File: project-teer/admin/find_winners.php
// This script calculates winners based on today's results and updates booking statuses and user balances.

session_start();

header('Content-Type: application/json');

include '../db_connect.php'; // Path to db_connect.php (one level up from 'admin' folder)

$response = ["success" => false, "message" => "An unknown error occurred.", "summary" => []];
$stmt_results = null; // Initialize all statements to null
$stmt_bookings = null;
$stmt_update_booking = null;
$stmt_update_user_balance = null;


try {
    // Basic admin session check (uncomment if you have this in place)
    // if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    //     $response = ["success" => false, "message" => "Unauthorized access."];
    //     echo json_encode($response);
    //     exit();
    // }

    $today_date = date('Y-m-d'); // Get current date for today's results and bookings
    error_log("find_winners.php: Starting winner calculation for date: " . $today_date);

    // --- 1. Fetch Today's Results ---
    $results_sql = "SELECT round_type, result_number FROM results WHERE result_date = ?";
    $stmt_results = $conn->prepare($results_sql);
    if (!$stmt_results) {
        throw new Exception("Results SQL prepare failed: " . $conn->error);
    }
    $stmt_results->bind_param("s", $today_date);
    $stmt_results->execute();
    $results_data = $stmt_results->get_result();

    $today_results = [];
    while ($row = $results_data->fetch_assoc()) {
        $today_results[$row['round_type']] = $row['result_number'];
    }
    error_log("find_winners.php: Fetched today's results: " . print_r($today_results, true));

    // Check if any results are available to proceed
    if (empty($today_results['first_round']) && empty($today_results['second_round']) && empty($today_results['night_teer_fr']) && empty($today_results['night_teer_sr'])) {
        $response = ["success" => false, "message" => "No First Round, Second Round, or Night Teer results available for today. Please update results first."];
        error_log("find_winners.php: No main results found for today.");
        echo json_encode($response);
        exit(); // Exit here, finally block will close statements
    }


    // --- 2. Fetch Active Bookings for Today ---
    $bookings_sql = "SELECT b.id AS booking_id, b.user_id, b.booking_type, b.round_type, b.amount_per_unit, b.total_amount, b.numbers_booked_json, u.username, u.balance AS user_balance
                     FROM bookings b
                     JOIN users u ON b.user_id = u.id
                     WHERE DATE(b.booked_at) = ? AND b.status = 'active'";
    $stmt_bookings = $conn->prepare($bookings_sql);
    if (!$stmt_bookings) {
        throw new Exception("Bookings SQL prepare failed: " . $conn->error);
    }
    $stmt_bookings->bind_param("s", $today_date);
    $stmt_bookings->execute();
    $active_bookings_data = $stmt_bookings->get_result();

    $processed_summary = [
        'total_bookings_processed' => 0,
        'winners_count' => 0,
        'losers_count' => 0,
        'total_winnings_distributed' => 0.00,
        'individual_winners' => []
    ];

    // Start a transaction for safe balance and status updates
    $conn->begin_transaction();
    error_log("find_winners.php: Transaction started.");

    // Prepare statements for updating bookings and user balances
    $update_booking_sql = "UPDATE bookings SET status = ? WHERE id = ?";
    $stmt_update_booking = $conn->prepare($update_booking_sql);
    if (!$stmt_update_booking) {
        throw new Exception("Update booking status SQL prepare failed: " . $conn->error);
    }

    $update_user_balance_sql = "UPDATE users SET balance = balance + ? WHERE id = ?";
    $stmt_update_user_balance = $conn->prepare($update_user_balance_sql);
    if (!$stmt_update_user_balance) {
        throw new Exception("Update user balance SQL prepare failed: " . $conn->error);
    }

    if ($active_bookings_data->num_rows === 0) {
        $conn->commit(); // Commit empty transaction if no bookings
        $response = ["success" => true, "message" => "No active bookings to process for today.", "summary" => $processed_summary];
        error_log("find_winners.php: No active bookings found.");
        echo json_encode($response);
        exit(); // Exit here, finally block will close statements
    }


    while ($booking = $active_bookings_data->fetch_assoc()) {
        $processed_summary['total_bookings_processed']++;
        $is_winner = false;
        $win_amount = 0;
        $booking_status = 'lost'; // Default to lost

        error_log("find_winners.php: Processing booking ID: " . $booking['booking_id'] . ", Type: " . $booking['booking_type'] . ", Round: " . $booking['round_type'] . ", Numbers JSON: " . $booking['numbers_booked_json']);

        $booked_numbers = json_decode($booking['numbers_booked_json'], true);
        if ($booked_numbers === null && json_last_error() !== JSON_ERROR_NONE) {
            error_log("find_winners.php: Error decoding JSON for booking ID " . $booking['booking_id'] . ": " . json_last_error_msg());
            $booking_status = 'cancelled'; // Mark as cancelled due to data error
            $stmt_update_booking->bind_param("si", $booking_status, $booking['booking_id']);
            $stmt_update_booking->execute();
            continue;
        }
        if (!is_array($booked_numbers)) {
             error_log("find_winners.php: numbers_booked_json for booking ID " . $booking['booking_id'] . " is not an array after decode. Value: " . print_r($booked_numbers, true));
             $booking_status = 'cancelled'; // Mark as cancelled due to data error
             $stmt_update_booking->bind_param("si", $booking_status, $booking['booking_id']);
             $stmt_update_booking->execute();
             continue;
        }
        error_log("find_winners.php: Decoded booked numbers: " . print_r($booked_numbers, true));


        $fr_result = isset($today_results['first_round']) ? $today_results['first_round'] : null;
        $sr_result = isset($today_results['second_round']) ? $today_results['second_round'] : null;
        $night_teer_fr_result = isset($today_results['night_teer_fr']) ? $today_results['night_teer_fr'] : null;
        $night_teer_sr_result = isset($today_results['night_teer_sr']) ? $today_results['night_teer_sr'] : null;

        error_log("find_winners.php: Current results in memory - FR: " . ($fr_result ?? 'N/A') . ", SR: " . ($sr_result ?? 'N/A') . ", NT FR: " . ($night_teer_fr_result ?? 'N/A') . ", NT SR: " . ($night_teer_sr_result ?? 'N/A'));

        // Determine the actual round result to check against
        $target_round_result = null;
        if ($booking['round_type'] === 'first_round') {
            $target_round_result = $fr_result;
        } elseif ($booking['round_type'] === 'second_round') {
            $target_round_result = $sr_result;
        } elseif ($booking['round_type'] === 'night_teer_fr') {
            $target_round_result = $night_teer_fr_result;
        } elseif ($booking['round_type'] === 'night_teer_sr') {
            $target_round_result = $night_teer_sr_result;
        }
        error_log("find_winners.php: Target round result for booking ID " . $booking['booking_id'] . " (" . $booking['round_type'] . "): '" . ($target_round_result ?? 'N/A') . "' (Type: " . gettype($target_round_result) . ")");


        switch ($booking['booking_type']) {
            case 'single':
            case 'night_teer': // Stored as 'night_teer' in DB, but round_type specifies which round
                $booked_single_number = isset($booked_numbers[0]) ? (string)$booked_numbers[0] : null; // Ensure string type for comparison
                error_log("find_winners.php: Single/Night Teer - Booked number: '" . ($booked_single_number ?? 'N/A') . "' (Type: " . gettype($booked_single_number) . "), Target Result: '" . ($target_round_result ?? 'N/A') . "' (Type: " . gettype($target_round_result) . ")");
                
                if ($target_round_result === null) {
                    $is_winner = false; // Cannot win if result is missing for this round
                    error_log("find_winners.php: Single/Night Teer - No target result, not a winner.");
                    break;
                }
                
                // Rule: booked 2-digit number must exactly match the 2-digit result
                if ($booked_single_number === $target_round_result) { // Strict comparison (type and value)
                    $is_winner = true;
                    if ($booking['round_type'] === 'first_round' || $booking['round_type'] === 'night_teer_fr') {
                        $win_amount = $booking['amount_per_unit'] * 80; 
                    } elseif ($booking['round_type'] === 'second_round' || $booking['round_type'] === 'night_teer_sr') {
                        $win_amount = $booking['amount_per_unit'] * 60; 
                    }
                    error_log("find_winners.php: Single/Night Teer - WINNER! Amount: " . $win_amount);
                } else {
                    error_log("find_winners.php: Single/Night Teer - No match: booked '" . $booked_single_number . "' vs result '" . $target_round_result . "'");
                }
                break;

            case 'forecast':
                error_log("find_winners.php: Forecast - Booked: " . print_r($booked_numbers, true) . ", FR Result: " . ($fr_result ?? 'N/A') . ", SR Result: " . ($sr_result ?? 'N/A'));
                $fr_match = false;
                $sr_match = false;

                if ($fr_result === null || $sr_result === null) {
                    error_log("find_winners.php: Forecast - Missing FR or SR result, not a winner.");
                    break; 
                }

                foreach ($booked_numbers as $fn) {
                    if (isset($fn['round']) && isset($fn['number'])) {
                        $booked_num_str = (string)$fn['number']; // Ensure string for comparison
                        if ($fn['round'] === 'first_round' && $booked_num_str === $fr_result) {
                            $fr_match = true;
                            error_log("find_winners.php: Forecast - FR booked '" . $booked_num_str . "' matches FR result '" . $fr_result . "'");
                        }
                        if ($fn['round'] === 'second_round' && $booked_num_str === $sr_result) {
                            $sr_match = true;
                            error_log("find_winners.php: Forecast - SR booked '" . $booked_num_str . "' matches SR result '" . $sr_result . "'");
                        }
                    } else {
                        error_log("find_winners.php: Forecast - Booked number entry missing 'round' or 'number': " . print_r($fn, true));
                    }
                }
                // Rule: Both F/R and S/R must match
                if ($fr_match && $sr_match) {
                    $is_winner = true;
                    $win_amount = $booking['amount_per_unit'] * 4000;
                    error_log("find_winners.php: Forecast - WINNER! Amount: " . $win_amount);
                } else {
                    error_log("find_winners.php: Forecast - No full match (FR match: " . ($fr_match ? 'true' : 'false') . ", SR match: " . ($sr_match ? 'true' : 'false') . ").");
                }
                break;

            case 'house':
                error_log("find_winners.php: House - Booked numbers JSON: " . print_r($booked_numbers, true) . ", Target Result: " . ($target_round_result ?? 'N/A'));
                if ($target_round_result === null || strlen($target_round_result) !== 2) {
                    $is_winner = false;
                    error_log("find_winners.php: House - No target result or invalid result format, not a winner.");
                    break;
                }
                
                // Rule: booked 1-digit must match the FIRST digit of the 2-digit result
                $booked_house_digit = isset($booked_numbers[0]) ? (string)$booked_numbers[0] : null; // Expected to be "1" (single digit string)
                $result_first_digit = substr($target_round_result, 0, 1); // Get first digit of result (e.g., "3" from "34")

                error_log("find_winners.php: House - Booked Digit: '" . ($booked_house_digit ?? 'N/A') . "' (Type: " . gettype($booked_house_digit) . "), Result First Digit: '" . $result_first_digit . "' (Type: " . gettype($result_first_digit) . ")");

                if ($booked_house_digit !== null && $booked_house_digit === $result_first_digit) {
                    $is_winner = true;
                    if ($booking['round_type'] === 'first_round' || $booking['round_type'] === 'night_teer_fr') {
                        $win_amount = $booking['amount_per_unit'] * 80;
                    } elseif ($booking['round_type'] === 'second_round' || $booking['round_type'] === 'night_teer_sr') {
                        $win_amount = $booking['amount_per_unit'] * 60;
                    }
                    error_log("find_winners.php: House - WINNER! Amount: " . $win_amount);
                } else {
                    error_log("find_winners.php: House - No match.");
                }
                break;

            case 'ending':
                error_log("find_winners.php: Ending - Booked numbers JSON: " . print_r($booked_numbers, true) . ", Target Result: " . ($target_round_result ?? 'N/A'));
                if ($target_round_result === null || strlen($target_round_result) !== 2) {
                    $is_winner = false;
                    error_log("find_winners.php: Ending - No target result or invalid result format, not a winner.");
                    break;
                }
                
                // Rule: booked 1-digit must match the SECOND digit of the 2-digit result
                $booked_ending_digit = isset($booked_numbers[0]) ? (string)$booked_numbers[0] : null; // Expected to be "3" (single digit string)
                $result_second_digit = substr($target_round_result, 1, 1); // Get second digit of result (e.g., "4" from "34")

                error_log("find_winners.php: Ending - Booked Digit: '" . ($booked_ending_digit ?? 'N/A') . "' (Type: " . gettype($booked_ending_digit) . "), Result Second Digit: '" . $result_second_digit . "' (Type: " . gettype($result_second_digit) . ")");

                if ($booked_ending_digit !== null && $booked_ending_digit === $result_second_digit) {
                    $is_winner = true;
                    if ($booking['round_type'] === 'first_round' || $booking['round_type'] === 'night_teer_fr') {
                        $win_amount = $booking['amount_per_unit'] * 90;
                    } elseif ($booking['round_type'] === 'second_round' || $booking['round_type'] === 'night_teer_sr') {
                        $win_amount = $booking['amount_per_unit'] * 70;
                    }
                    error_log("find_winners.php: Ending - WINNER! Amount: " . $win_amount);
                } else {
                    error_log("find_winners.php: Ending - No match.");
                }
                break;

            case 'pair':
                error_log("find_winners.php: Pair - Booked numbers JSON: " . print_r($booked_numbers, true) . ", Target Result: " . ($target_round_result ?? 'N/A'));
                if ($target_round_result === null) {
                    $is_winner = false;
                    error_log("find_winners.php: Pair - No target result, not a winner.");
                    break;
                }
                // Rule: Result must be one of the 'pair' numbers (00, 11, ..., 99)
                // Assuming $booked_numbers for 'pair' is always `["00", "11", ..., "99"]` from submit_booking.php
                if (in_array($target_round_result, ["00", "11", "22", "33", "44", "55", "66", "77", "88", "99"])) { // Hardcode for clarity
                    $is_winner = true;
                    if ($booking['round_type'] === 'first_round' || $booking['round_type'] === 'night_teer_fr') {
                        $win_amount = $booking['amount_per_unit'] * 80; 
                    } elseif ($booking['round_type'] === 'second_round' || $booking['round_type'] === 'night_teer_sr') {
                        $win_amount = $booking['amount_per_unit'] * 60; 
                    }
                    error_log("find_winners.php: Pair - WINNER! Amount: " . $win_amount);
                } else {
                    error_log("find_winners.php: Pair - No match: result '" . $target_round_result . "' is not a double digit.");
                }
                break;
            
            default:
                $is_winner = false;
                error_log("find_winners.php: Unknown booking type encountered: " . $booking['booking_type'] . " for booking ID " . $booking['booking_id']);
                break;
        }

        // --- Update Booking Status and User Balance ---
        if ($is_winner) {
            $booking_status = 'won';
            $processed_summary['winners_count']++;
            $processed_summary['total_winnings_distributed'] += $win_amount;

            $stmt_update_user_balance->bind_param("di", $win_amount, $booking['user_id']);
            if (!$stmt_update_user_balance->execute()) {
                throw new Exception("Failed to update user balance for user ID " . $booking['user_id'] . ": " . $stmt_update_user_balance->error);
            }
            error_log("find_winners.php: User ID " . $booking['user_id'] . " balance updated by " . $win_amount);
            
            $processed_summary['individual_winners'][] = [
                'booking_id' => $booking['booking_id'],
                'user_id' => $booking['user_id'],
                'username' => $booking['username'],
                'booking_type' => $booking['booking_type'],
                'round_type' => $booking['round_type'],
                'amount_per_unit' => $booking['amount_per_unit'],
                'win_amount' => $win_amount
            ];
        } else {
            $processed_summary['losers_count']++;
            error_log("find_winners.php: Booking ID " . $booking['booking_id'] . " is a LOSER.");
        }

        $stmt_update_booking->bind_param("si", $booking_status, $booking['booking_id']);
        if (!$stmt_update_booking->execute()) {
            throw new Exception("Failed to update booking status for booking ID " . $booking['booking_id'] . ": " . $stmt_update_booking->error);
        }
        error_log("find_winners.php: Booking ID " . $booking['booking_id'] . " status set to " . $booking_status);
    }

    $conn->commit();
    error_log("find_winners.php: Transaction committed successfully.");
    $response = ["success" => true, "message" => "Winner calculation completed successfully!", "summary" => $processed_summary];

} catch (Exception $e) {
    if ($conn) {
        $conn->rollback();
        error_log("find_winners.php: Transaction rolled back due to error.");
    }
    error_log("find_winners.php: Error during winner calculation: " . $e->getMessage());
    $response = ["success" => false, "message" => "Error during winner calculation: " . $e->getMessage()];
} finally {
    if (isset($stmt_results) && $stmt_results instanceof mysqli_stmt) $stmt_results->close();
    if (isset($stmt_bookings) && $stmt_bookings instanceof mysqli_stmt) $stmt_bookings->close();
    if (isset($stmt_update_booking) && $stmt_update_booking instanceof mysqli_stmt) $stmt_update_booking->close();
    if (isset($stmt_update_user_balance) && $stmt_update_user_balance instanceof mysqli_stmt) $stmt_update_user_balance->close();
    
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    error_log("find_winners.php: Script finished.");
}

echo json_encode($response);
exit();