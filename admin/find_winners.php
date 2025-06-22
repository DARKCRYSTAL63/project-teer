<?php
// File: project-teer/admin/find_winners.php
// This script calculates winners based on today's results and updates booking statuses and user balances.
// It is now designed to be called after a round result is updated.

session_name("ADMIN_SESSION"); // Set session name for admin
session_start();

header('Content-Type: application/json');

include '../db_connect.php'; // Path to db_connect.php (one level up from 'admin' folder)

// Configure error logging for this script
error_reporting(E_ALL); // Report all errors
ini_set('display_errors', 0); // Do NOT display errors in the browser output
ini_set('log_errors', 1); // Log errors
ini_set('error_log', 'C:/xampp/apache/logs/error.log'); // Explicitly log to Apache's main error.log

$response = ["success" => false, "message" => "An unknown error occurred.", "summary" => []];
$stmt_results = null; // Initialize all statements to null
$stmt_bookings = null;
$stmt_update_booking = null;
$stmt_update_user_balance = null;

try {
    // Basic admin session check
    if (!isset($_SESSION['loggedin_admin_id']) || !isset($_SESSION['loggedin_admin_role']) || $_SESSION['loggedin_admin_role'] !== 'admin') {
        $response = ["success" => false, "message" => "Unauthorized access. Please log in as admin."];
        error_log("find_winners.php: Unauthorized access attempt.");
        echo json_encode($response);
        exit();
    }

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
        // Ensure result numbers are treated as strings for consistent comparison later
        $today_results[$row['round_type']] = (string)$row['result_number']; 
    }
    error_log("find_winners.php: Fetched today's results: " . print_r($today_results, true));

    // --- 2. Fetch Active Bookings for Today ---
    // Fetch bookings that are still 'active' to determine winners
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
        'pending_count' => 0, // Track bookings that remain active
        'total_winnings_distributed' => 0.00,
        'individual_winners' => []
    ];

    // Start a transaction for safe balance and status updates
    $conn->begin_transaction();
    error_log("find_winners.php: Transaction started.");

    // Prepare statements for updating bookings and user balances
    $update_booking_sql = "UPDATE bookings SET status = ?, win_amount = ? WHERE id = ?"; 
    $stmt_update_booking = $conn->prepare($update_booking_sql);
    if (!$stmt_update_booking) {
        throw new Exception("Update booking status SQL prepare failed: " . $conn->error);
    }

    $update_user_balance_sql = "UPDATE users SET balance = balance + ? WHERE id = ?";
    $stmt_update_user_balance = $conn->prepare($update_user_balance_sql);
    if (!$stmt_update_user_balance) {
        throw new Exception("Update user balance SQL prepare failed: " . $conn->error);
    }
    
    // Check if there are no active bookings to process.
    // We don't exit here immediately if no active bookings, as results might have been updated.
    // The loop below will handle zero rows gracefully.

    while ($booking = $active_bookings_data->fetch_assoc()) {
        $processed_summary['total_bookings_processed']++;
        $is_winner = false;
        $win_amount = 0.00; 
        $booking_status = 'active'; // Default status is active, changed to 'won' or 'lost' if processed

        error_log("--- Processing booking ID: " . $booking['booking_id'] . " (User: " . $booking['username'] . ") ---");
        error_log("Booking Type: " . $booking['booking_type'] . ", Round: " . $booking['round_type'] . ", Amount Per Unit: " . $booking['amount_per_unit']);
        error_log("Numbers JSON: " . $booking['numbers_booked_json']);

        $booked_numbers = json_decode($booking['numbers_booked_json'], true);
        if ($booked_numbers === null && json_last_error() !== JSON_ERROR_NONE) {
            error_log("find_winners.php: Error decoding JSON for booking ID " . $booking['booking_id'] . ": " . json_last_error_msg());
            $booking_status = 'error_json'; // Mark as error due to data error
            // Update booking status in DB immediately for data integrity issue, then continue
            $stmt_update_booking->bind_param("sdi", $booking_status, $win_amount, $booking['booking_id']);
            $stmt_update_booking->execute();
            continue; 
        }
        if (!is_array($booked_numbers)) {
             error_log("find_winners.php: numbers_booked_json for booking ID " . $booking['booking_id'] . " is not an array after decode. Value: " . print_r($booked_numbers, true));
             $booking_status = 'error_json'; // Mark as error due to data error
             // Update booking status in DB immediately for data integrity issue, then continue
             $stmt_update_booking->bind_param("sdi", $booking_status, $win_amount, $booking['booking_id']);
             $stmt_update_booking->execute();
             continue; 
        }
        error_log("find_winners.php: Decoded booked numbers: " . print_r($booked_numbers, true));

        // Get all relevant results for today
        $fr_result = isset($today_results['first_round']) ? $today_results['first_round'] : null;
        $sr_result = isset($today_results['second_round']) ? $today_results['second_round'] : null;
        $night_teer_fr_result = isset($today_results['night_teer_fr']) ? $today_results['night_teer_fr'] : null;
        $night_teer_sr_result = isset($today_results['night_teer_sr']) ? $today_results['night_teer_sr'] : null;

        error_log("find_winners.php: Current results in memory - FR: " . ($fr_result ?? 'N/A') . ", SR: " . ($sr_result ?? 'N/A') . ", NT FR: " . ($night_teer_fr_result ?? 'N/A') . ", NT SR: " . ($night_teer_sr_result ?? 'N/A'));

        switch ($booking['booking_type']) {
            case 'single':
            case 'night_teer':
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

                error_log("find_winners.php: Single/Night Teer - Target round result for " . $booking['round_type'] . ": '" . ($target_round_result ?? 'N/A') . "'");

                // Only process if the target result for this specific round is available
                if ($target_round_result === null) {
                    $booking_status = 'active'; // Keep active if result not yet declared
                    $processed_summary['pending_count']++;
                    error_log("find_winners.php: Booking ID " . $booking['booking_id'] . " (" . $booking['booking_type'] . " " . $booking['round_type'] . ") - Result not yet available. Keeping active.");
                    goto update_booking_status; 
                }

                // Correctly extract the booked number from the nested array
                $booked_single_number = null;
                if (isset($booked_numbers[0]) && is_array($booked_numbers[0]) && isset($booked_numbers[0]['number'])) {
                    $booked_single_number = (string)$booked_numbers[0]['number']; 
                }
                
                error_log("find_winners.php: Single/Night Teer Logic - Booked: '" . ($booked_single_number ?? 'N/A') . "', Result: '" . ($target_round_result ?? 'N/A') . "'");
                
                if ($booked_single_number !== null && $booked_single_number === $target_round_result) {
                    $is_winner = true;
                    if ($booking['round_type'] === 'first_round' || $booking['round_type'] === 'night_teer_fr') {
                        $win_amount = $booking['amount_per_unit'] * 80; 
                    } elseif ($booking['round_type'] === 'second_round' || $booking['round_type'] === 'night_teer_sr') {
                        $win_amount = $booking['amount_per_unit'] * 60; 
                    }
                    error_log("find_winners.php: Single/Night Teer - WINNER! Amount: " . $win_amount);
                } else {
                    $booking_status = 'lost';
                    error_log("find_winners.php: Single/Night Teer - No match: booked '" . ($booked_single_number ?? 'N/A') . "' vs result '" . ($target_round_result ?? 'N/A') . "'");
                }
                break;

            case 'forecast':
                error_log("find_winners.php: Forecast Logic - Booked: " . print_r($booked_numbers, true) . ", FR Result: " . ($fr_result ?? 'N/A') . ", SR Result: " . ($sr_result ?? 'N/A'));
                $fr_match = false;
                $sr_match = false;

                // Forecast only wins if *both* First Round and Second Round results are available
                if ($fr_result === null || $sr_result === null) {
                    $booking_status = 'active'; // Keep active if either result not yet declared
                    $processed_summary['pending_count']++;
                    error_log("find_winners.php: Forecast Booking ID " . $booking['booking_id'] . " - Missing FR or SR result. Keeping active.");
                    goto update_booking_status;
                }

                foreach ($booked_numbers as $fn) {
                    if (is_array($fn) && isset($fn['round']) && isset($fn['number'])) { 
                        $booked_num_str = (string)$fn['number']; 
                        if ($fn['round'] === 'first_round' && $booked_num_str === $fr_result) {
                            $fr_match = true;
                            error_log("find_winners.php: Forecast - FR booked '" . $booked_num_str . "' matches FR result '" . $fr_result . "'");
                        }
                        if ($fn['round'] === 'second_round' && $booked_num_str === $sr_result) {
                            $sr_match = true;
                            error_log("find_winners.php: Forecast - SR booked '" . $booked_num_str . "' matches SR result '" . $sr_result . "'");
                        }
                    } else {
                        error_log("find_winners.php: Forecast - Booked number entry missing 'round' or 'number' or not an array for booking ID " . $booking['booking_id'] . ": " . print_r($fn, true));
                        $booking_status = 'error_json'; // Data error in booked numbers
                        goto update_booking_status;
                    }
                }
                
                if ($fr_match && $sr_match) {
                    $is_winner = true;
                    $win_amount = $booking['amount_per_unit'] * 4000;
                    error_log("find_winners.php: Forecast - WINNER! Amount: " . $win_amount);
                } else {
                    $booking_status = 'lost';
                    error_log("find_winners.php: Forecast - No full match (FR match: " . ($fr_match ? 'true' : 'false') . ", SR match: " . ($sr_match ? 'true' : 'false') . ").");
                }
                break;

            case 'house':
            case 'ending':
            case 'pair':
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

                error_log("find_winners.php: " . $booking['booking_type'] . " - Target round result for " . $booking['round_type'] . ": '" . ($target_round_result ?? 'N/A') . "'");

                // Only process if the target result for this specific round is available
                if ($target_round_result === null) {
                    $booking_status = 'active'; // Keep active if result not yet declared
                    $processed_summary['pending_count']++;
                    error_log("find_winners.php: Booking ID " . $booking['booking_id'] . " (" . $booking['booking_type'] . " " . $booking['round_type'] . ") - Result not yet available. Keeping active.");
                    goto update_booking_status;
                }

                if (strlen($target_round_result) !== 2) {
                    $booking_status = 'lost'; // Result malformed, cannot match
                    error_log("find_winners.php: " . $booking['booking_type'] . " - Target result '" . ($target_round_result ?? 'N/A') . "' is not 2 digits. Marking as lost.");
                    break;
                }

                if ($booking['booking_type'] === 'house') {
                    $booked_house_digit = null;
                    if (isset($booked_numbers[0]) && is_array($booked_numbers[0]) && isset($booked_numbers[0]['digit'])) {
                        $booked_house_digit = (string)$booked_numbers[0]['digit'];
                    }
                    $result_first_digit = substr($target_round_result, 0, 1); 
                    error_log("find_winners.php: House - Booked Digit: '" . ($booked_house_digit ?? 'N/A') . "' vs Result First Digit: '" . $result_first_digit . "'");
                    if ($booked_house_digit !== null && $booked_house_digit === $result_first_digit) {
                        $is_winner = true;
                        if ($booking['round_type'] === 'first_round' || $booking['round_type'] === 'night_teer_fr') {
                            $win_amount = $booking['amount_per_unit'] * 80;
                        } elseif ($booking['round_type'] === 'second_round' || $booking['round_type'] === 'night_teer_sr') {
                            $win_amount = $booking['amount_per_unit'] * 60;
                        }
                        error_log("find_winners.php: House - WINNER! Amount: " . $win_amount);
                    } else {
                        $booking_status = 'lost';
                        error_log("find_winners.php: House - No match.");
                    }

                } elseif ($booking['booking_type'] === 'ending') {
                    $booked_ending_digit = null;
                    if (isset($booked_numbers[0]) && is_array($booked_numbers[0]) && isset($booked_numbers[0]['digit'])) {
                        $booked_ending_digit = (string)$booked_numbers[0]['digit'];
                    }
                    $result_second_digit = substr($target_round_result, 1, 1); 
                    error_log("find_winners.php: Ending - Booked Digit: '" . ($booked_ending_digit ?? 'N/A') . "' vs Result Second Digit: '" . $result_second_digit . "'");
                    if ($booked_ending_digit !== null && $booked_ending_digit === $result_second_digit) {
                        $is_winner = true;
                        if ($booking['round_type'] === 'first_round' || $booking['round_type'] === 'night_teer_fr') {
                            $win_amount = $booking['amount_per_unit'] * 90;
                        } elseif ($booking['round_type'] === 'second_round' || $booking['round_type'] === 'night_teer_sr') {
                            $win_amount = $booking['amount_per_unit'] * 70;
                        }
                        error_log("find_winners.php: Ending - WINNER! Amount: " . $win_amount);
                    } else {
                        $booking_status = 'lost';
                        error_log("find_winners.php: Ending - No match.");
                    }

                } elseif ($booking['booking_type'] === 'pair') {
                    // Assuming 'pair' means betting on *any* double digit result
                    // The booked_numbers content for 'pair' is not strictly used for the win condition,
                    // but it can still be logged for debugging if needed.
                    error_log("find_winners.php: Pair Logic - Result: '" . ($target_round_result ?? 'N/A') . "'");
                    if (in_array($target_round_result, ["00", "11", "22", "33", "44", "55", "66", "77", "88", "99"])) { 
                        $is_winner = true;
                        if ($booking['round_type'] === 'first_round' || $booking['round_type'] === 'night_teer_fr') {
                            $win_amount = $booking['amount_per_unit'] * 80; 
                        } elseif ($booking['round_type'] === 'second_round' || $booking['round_type'] === 'night_teer_sr') {
                            $win_amount = $booking['amount_per_unit'] * 60; 
                        }
                        error_log("find_winners.php: Pair - WINNER! Amount: " . $win_amount);
                    } else {
                        $booking_status = 'lost';
                        error_log("find_winners.php: Pair - No match: result '" . ($target_round_result ?? 'N/A') . "' is not a double digit.");
                    }
                }
                break;
            
            default:
                $booking_status = 'lost'; // Unknown type, mark as lost
                error_log("find_winners.php: Unknown booking type encountered: " . $booking['booking_type'] . " for booking ID " . $booking['booking_id'] . ". Marking as lost.");
                break;
        }

        // --- Common Update Booking Status and User Balance Logic ---
        update_booking_status: // Label for goto

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
            // Only increment losers count if status was determined as 'lost'
            if ($booking_status === 'lost' || $booking_status === 'error_json') {
                $processed_summary['losers_count']++;
            }
            error_log("find_winners.php: Booking ID " . $booking['booking_id'] . " processed as " . $booking_status . ".");
        }

        // Always update booking status (whether won, lost, active, or error_json)
        $stmt_update_booking->bind_param("sdi", $booking_status, $win_amount, $booking['booking_id']); 
        if (!$stmt_update_booking->execute()) {
            throw new Exception("Failed to update booking status for booking ID " . $booking['booking_id'] . ": " . $stmt_update_booking->error);
        }
        error_log("find_winners.php: Booking ID " . $booking['booking_id'] . " final status set to '" . $booking_status . "'. Win Amount stored: " . $win_amount);
    }

    $conn->commit();
    error_log("find_winners.php: Transaction committed successfully.");
    $response = ["success" => true, "message" => "Winner calculation completed successfully!", "summary" => $processed_summary];

} catch (Exception $e) {
    if ($conn) {
        $conn->rollback();
        error_log("find_winners.php: Transaction rolled back due to error. Details: " . $e->getMessage());
    }
    error_log("find_winners.php: Error during winner calculation (outer catch): " . $e->getMessage());
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