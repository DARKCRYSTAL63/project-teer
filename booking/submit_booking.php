<?php
// File: project-teer/booking/submit_booking.php
session_name("USER_SESSION"); // Set session name for user
session_start(); // ENSURE THIS IS THE VERY FIRST LINE, NO SPACES OR NEWLINES BEFORE IT
header('Content-Type: application/json');

include '../db_connect.php'; // Path to your database connection file

$response = ["success" => false, "message" => "An unknown error occurred."];
$user_id = null;

// --- DEBUGGING SESSION START ---
error_log("submit_booking.php: Session ID: " . session_id());
error_log("submit_booking.php: SESSION_ARRAY: " . print_r($_SESSION, true));
// --- DEBUGGING SESSION END ---

$conn->begin_transaction(); // Start a transaction for atomicity

try {
    // Check if user is logged in (using loggedin_user_id consistently)
    if (!isset($_SESSION['loggedin_user_id'])) {
        $response = ["success" => false, "message" => "User not logged in. Please refresh and try again after logging in.", "redirect" => "login/login.html"];
        error_log("submit_booking.php: User not logged in. Redirecting.");
        echo json_encode($response);
        exit();
    }
    $user_id = $_SESSION['loggedin_user_id']; // Use the correct session variable
    error_log("submit_booking.php: User ID from session: " . $user_id);


    $booking_type = filter_input(INPUT_POST, 'booking_type', FILTER_SANITIZE_STRING); // e.g., 'single', 'forecast', 'house', 'ending', 'pair', 'night_teer'
    $total_amount = filter_input(INPUT_POST, 'total_amount', FILTER_VALIDATE_FLOAT);
    $bets_json = $_POST['bets'] ?? null; // Raw JSON string from frontend

    if (empty($booking_type) || $total_amount === false || $total_amount <= 0 || empty($bets_json)) {
        throw new Exception("Invalid input provided for booking.");
    }

    $bets = json_decode($bets_json, true);
    if ($bets === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON for bets data: " . json_last_error_msg());
    }
    if (!is_array($bets) || empty($bets)) {
        throw new Exception("Bets data is empty or not an array.");
    }

    // Fetch current user balance
    $stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        throw new Exception("User not found.");
    }
    $current_balance = $user['balance'];

    if ($current_balance < $total_amount) {
        throw new Exception("Insufficient balance. Your current balance is ₹" . number_format($current_balance, 2));
    }

    // Deduct amount from user's balance
    $new_balance = $current_balance - $total_amount;
    $stmt = $conn->prepare("UPDATE users SET balance = ? WHERE id = ?");
    $stmt->bind_param("di", $new_balance, $user_id);
    if (!$stmt->execute()) {
        throw new Exception("Failed to update user balance: " . $stmt->error);
    }
    $stmt->close();

    // Prepare for inserting individual bets
    $insert_bet_sql = "INSERT INTO bookings (user_id, booking_type, round_type, amount_per_unit, total_amount, numbers_booked_json) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($insert_bet_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare bet insertion statement: " . $conn->error);
    }

    foreach ($bets as $bet) {
        $bet_round_type = $bet['round_type']; 
        $bet_amount_per_unit = $bet['amount_per_unit'];
        $bet_numbers_json = null;
        $calculated_bet_total_amount = 0; 

        switch ($booking_type) {
            case 'single':
            case 'night_teer':
                if (!isset($bet['number']) || !is_numeric($bet['number'])) {
                    throw new Exception("Invalid number for single/night teer booking.");
                }
                $bet_numbers_json = json_encode([['number' => (string)$bet['number'], 'round' => $bet_round_type]]); 
                $calculated_bet_total_amount = $bet_amount_per_unit;
                break;

            case 'forecast':
                if (!isset($bet['number_fr']) && !isset($bet['number_sr'])) {
                     throw new Exception("Missing numbers for forecast booking.");
                }
                $forecast_parts = [];
                if (isset($bet['number_fr'])) {
                    $forecast_parts[] = ['round' => 'first_round', 'number' => (string)$bet['number_fr']];
                }
                if (isset($bet['number_sr'])) {
                    $forecast_parts[] = ['round' => 'second_round', 'number' => (string)$bet['number_sr']];
                }
                $bet_numbers_json = json_encode($forecast_parts);
                $calculated_bet_total_amount = $bet_amount_per_unit;
                break;

            case 'house':
            case 'ending':
                if (!isset($bet['single_digit']) || !is_numeric($bet['single_digit'])) {
                    throw new Exception("Invalid digit for house/ending booking.");
                }
                $bet_numbers_json = json_encode([['digit' => (string)$bet['single_digit'], 'round' => $bet_round_type]]); 
                $calculated_bet_total_amount = $bet_amount_per_unit * 10; 
                break;

            case 'pair':
                $fixed_pair_numbers = ["00", "11", "22", "33", "44", "55", "66", "77", "88", "99"];
                $bet_numbers_json = json_encode([['numbers' => $fixed_pair_numbers, 'round' => $bet_round_type]]);
                $calculated_bet_total_amount = $bet_amount_per_unit * 10; 
                break;

            default:
                throw new Exception("Unsupported booking type: " . $booking_type);
        }
        
        $stmt->bind_param("issdis", 
            $user_id, 
            $booking_type, 
            $bet_round_type, 
            $bet_amount_per_unit, 
            $calculated_bet_total_amount, 
            $bet_numbers_json
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to insert bet: " . $stmt->error);
        }
    }
    $stmt->close();

    $conn->commit(); 
    $response = ["success" => true, "message" => "Booking successful!", "new_balance" => $new_balance];

} catch (Exception $e) {
    $conn->rollback(); 
    error_log("Booking error: " . $e->getMessage());
    $response = ["success" => false, "message" => $e->getMessage()];
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();