<?php
// File: project-teer/admin/get_todays_winners_list.php
// Fetches a list of today's winners and a summary of all processed bookings for the admin panel.
session_name("ADMIN_SESSION"); // Set session name for admin
session_start();

header('Content-Type: application/json');

include '../db_connect.php'; // Path to db_connect.php

$response = ["success" => false, "message" => "An unknown error occurred.", "summary" => [], "winners" => []];
$stmt_summary = null; // Initialize all statement variables to null
$stmt_winners = null;

try {
    // Basic admin session check (optional, but good practice for admin panel)
    // if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    //     $response = ["success" => false, "message" => "Unauthorized access."];
    //     echo json_encode($response);
    //     exit();
    // }

    $today_date = date('Y-m-d');

    // --- Fetch all bookings for today (won, lost, and still active) for summary ---
    $summary_sql = "SELECT status, COUNT(id) AS count, SUM(total_amount) AS total_bet_amount, SUM(CASE WHEN status = 'won' THEN (amount_per_unit * CASE 
                        WHEN round_type IN ('first_round', 'night_teer_fr') AND booking_type = 'single' THEN 80
                        WHEN round_type IN ('second_round', 'night_teer_sr') AND booking_type = 'single' THEN 60
                        WHEN booking_type = 'forecast' THEN 4000
                        WHEN round_type IN ('first_round', 'night_teer_fr') AND booking_type = 'house' THEN 80
                        WHEN round_type IN ('second_round', 'night_teer_sr') AND booking_type = 'house' THEN 60
                        WHEN round_type IN ('first_round', 'night_teer_fr') AND booking_type = 'ending' THEN 90
                        WHEN round_type IN ('second_round', 'night_teer_sr') AND booking_type = 'ending' THEN 70
                        WHEN round_type IN ('first_round', 'night_teer_fr') AND booking_type = 'pair' THEN 80
                        WHEN round_type IN ('second_round', 'night_teer_sr') AND booking_type = 'pair' THEN 60
                        ELSE 0 -- Default for cases not explicitly handled in win calculation
                    END
                ) ELSE 0 END) AS total_winnings_for_status
                FROM bookings 
                WHERE DATE(booked_at) = ?
                GROUP BY status";

    $stmt_summary = $conn->prepare($summary_sql);
    if (!$stmt_summary) {
        throw new Exception("Summary SQL prepare failed: " . $conn->error);
    }
    $stmt_summary->bind_param("s", $today_date);
    $stmt_summary->execute();
    $summary_result = $stmt_summary->get_result();

    $summary_data = [
        'total_bookings_processed' => 0, // This will be the sum of won + lost + active
        'winners_count' => 0,
        'losers_count' => 0,
        'active_count' => 0, // Bookings not yet processed for today
        'total_winnings_distributed' => 0.00,
        'total_bet_amount_processed' => 0.00 // Total amount placed on processed bets (won/lost)
    ];

    while ($row = $summary_result->fetch_assoc()) {
        if ($row['status'] === 'won') {
            $summary_data['winners_count'] = (int)$row['count'];
            $summary_data['total_winnings_distributed'] = (float)$row['total_winnings_for_status'];
            // total_bet_amount_processed represents the amount from bets that HAVE a status (won/lost)
            $summary_data['total_bet_amount_processed'] += (float)$row['total_bet_amount'];
        } elseif ($row['status'] === 'lost') {
            $summary_data['losers_count'] = (int)$row['count'];
            $summary_data['total_bet_amount_processed'] += (float)$row['total_bet_amount'];
        } elseif ($row['status'] === 'active') {
             $summary_data['active_count'] = (int)$row['count'];
             // Active bookings also contribute to overall processed count, but not "processed_bet_amount" conceptually here
        }
    }
    // Calculate total bookings processed (including active as "to be processed")
    $summary_data['total_bookings_processed'] = $summary_data['winners_count'] + $summary_data['losers_count'] + $summary_data['active_count'];

    // REMOVED: $stmt_summary->close(); // Close statement moved to finally

    // --- Fetch individual winning bookings for today ---
    $winners_sql = "SELECT b.id AS booking_id, b.user_id, b.booking_type, b.round_type, b.amount_per_unit, u.username,
                    (b.amount_per_unit * CASE 
                        WHEN b.round_type IN ('first_round', 'night_teer_fr') AND b.booking_type = 'single' THEN 80
                        WHEN b.round_type IN ('second_round', 'night_teer_sr') AND b.booking_type = 'single' THEN 60
                        WHEN b.booking_type = 'forecast' THEN 4000
                        WHEN b.round_type IN ('first_round', 'night_teer_fr') AND b.booking_type = 'house' THEN 80
                        WHEN b.round_type IN ('second_round', 'night_teer_sr') AND b.booking_type = 'house' THEN 60
                        WHEN b.round_type IN ('first_round', 'night_teer_fr') AND b.booking_type = 'ending' THEN 90
                        WHEN b.round_type IN ('second_round', 'night_teer_sr') AND b.booking_type = 'ending' THEN 70
                        WHEN b.round_type IN ('first_round', 'night_teer_fr') AND b.booking_type = 'pair' THEN 80
                        WHEN b.round_type IN ('second_round', 'night_teer_sr') AND b.booking_type = 'pair' THEN 60
                        ELSE 0
                    END) AS win_amount
                    FROM bookings b
                    JOIN users u ON b.user_id = u.id
                    WHERE DATE(b.booked_at) = ? AND b.status = 'won'
                    ORDER BY b.booked_at DESC";
    
    $stmt_winners = $conn->prepare($winners_sql);
    if (!$stmt_winners) {
        throw new Exception("Winners SQL prepare failed: " . $conn->error);
    }
    $stmt_winners->bind_param("s", $today_date);
    $stmt_winners->execute();
    $winners_result = $stmt_winners->get_result();

    $winners_list = [];
    while ($row = $winners_result->fetch_assoc()) {
        $winners_list[] = $row;
    }
    // REMOVED: $stmt_winners->close(); // Close statement moved to finally

    $response = ["success" => true, "message" => "Winners and summary fetched successfully.", "summary" => $summary_data, "winners" => $winners_list];

} catch (Exception $e) {
    error_log("Error in get_todays_winners_list.php: " . $e->getMessage());
    $response = ["success" => false, "message" => "Failed to fetch winners: " . $e->getMessage()];
} finally {
    // Ensure all prepared statements are closed correctly
    if (isset($stmt_summary) && $stmt_summary instanceof mysqli_stmt) {
        $stmt_summary->close();
    }
    if (isset($stmt_winners) && $stmt_winners instanceof mysqli_stmt) {
        $stmt_winners->close();
    }
    
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

echo json_encode($response);
exit();