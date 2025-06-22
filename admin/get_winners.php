<?php
// File: project-teer/admin/get_winners.php
// Fetches a list of winners and a summary of all processed bookings for the admin panel.
session_name("ADMIN_SESSION"); // Set session name for admin
session_start();

header('Content-Type: application/json');

include '../db_connect.php'; // Path to db_connect.php

$response = ["success" => false, "message" => "An unknown error occurred.", "summary" => [], "winners" => [], "total_winnings" => 0]; // Initialize with default values
$stmt_summary = null; // Initialize all statement variables to null
$stmt_winners = null;

try {
    // Basic admin session check
    if (!isset($_SESSION['loggedin_admin_id']) || !isset($_SESSION['loggedin_admin_role']) || $_SESSION['loggedin_admin_role'] !== 'admin') {
        $response = ["success" => false, "message" => "Unauthorized access. Please log in as admin."];
        echo json_encode($response);
        exit(); // Crucial: Exit immediately after sending response
    }

    // Get date filter from query parameter, default to today
    $today_date = date('Y-m-d');
    $filter_date = isset($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : $today_date;

    error_log("get_winners.php: Fetching winners and summary for date: " . $filter_date);

    // --- Fetch summary data for all bookings for the filtered date (won, lost, and still active) ---
    $summary_sql = "
        SELECT 
            status, 
            COUNT(id) AS count, 
            SUM(total_amount) AS total_bet_amount, 
            SUM(CASE 
                WHEN status = 'won' THEN (amount_per_unit * CASE 
                        WHEN round_type IN ('first_round', 'night_teer_fr') THEN 80
                        WHEN round_type IN ('second_round', 'night_teer_sr') THEN 80
                        ELSE 0
                    END
                )
                ELSE 0 
            END) AS total_win_amount
        FROM bookings
        WHERE DATE(booked_at) = ?
        GROUP BY status"; // Group by status to get counts for each
    
    $stmt_summary = $conn->prepare($summary_sql);
    if (!$stmt_summary) {
        throw new Exception("Summary SQL prepare failed: " . $conn->error);
    }
    $stmt_summary->bind_param("s", $filter_date);
    $stmt_summary->execute();
    $summary_result = $stmt_summary->get_result();

    $summary_data = [
        "total_bookings_processed" => 0,
        "winners_count" => 0,
        "losers_count" => 0,
        "active_bookings_count" => 0,
        "total_winnings_distributed" => 0.00,
        "total_bets_placed" => 0.00
    ];

    while ($row = $summary_result->fetch_assoc()) {
        $summary_data['total_bookings_processed'] += $row['count'];
        $summary_data['total_bets_placed'] += $row['total_bet_amount'];

        if ($row['status'] === 'won') {
            $summary_data['winners_count'] = $row['count'];
            $summary_data['total_winnings_distributed'] += $row['total_win_amount'];
        } elseif ($row['status'] === 'lost') {
            $summary_data['losers_count'] = $row['count'];
        } elseif ($row['status'] === 'active') { // Assuming 'active' for unprocessed
            $summary_data['active_bookings_count'] = $row['count'];
        }
    }
    // Update the main response summary fields based on the fetched summary data
    $response["summary"] = $summary_data;
    $response["total_winnings"] = $summary_data['total_winnings_distributed'];


    // --- Fetch details of 'won' bookings for the filtered date ---
    $winners_sql = "
        SELECT 
            b.id AS booking_id, 
            b.user_id, 
            u.username, 
            u.phone_number,         -- Added phone_number
            b.booking_type, 
            b.round_type, 
            b.numbers_booked_json,  -- Added numbers_booked_json
            b.amount_per_unit, 
            (b.amount_per_unit * CASE 
                    WHEN b.round_type IN ('first_round', 'night_teer_fr') THEN 80
                    WHEN b.round_type IN ('second_round', 'night_teer_sr') THEN 80
                    ELSE 0
                END
            ) AS win_amount,
            b.status,
            b.booked_at
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        WHERE DATE(b.booked_at) = ? AND b.status = 'won'
        ORDER BY b.booked_at DESC";
    
    $stmt_winners = $conn->prepare($winners_sql);
    if (!$stmt_winners) {
        throw new Exception("Winners SQL prepare failed: " . $conn->error);
    }
    $stmt_winners->bind_param("s", $filter_date);
    $stmt_winners->execute();
    $winners_result = $stmt_winners->get_result();

    $winners_list = [];
    while ($row = $winners_result->fetch_assoc()) {
        $winners_list[] = $row;
    }
    
    $response["success"] = true;
    $response["message"] = "Winners and summary fetched successfully.";
    $response["winners"] = $winners_list;

} catch (Exception $e) {
    error_log("Error in get_winners.php: " . $e->getMessage());
    $response = [
        "success" => false, 
        "message" => "Failed to fetch winners: " . $e->getMessage(),
        "summary" => [], // Ensure summary is empty on error
        "winners" => [], // Ensure winners are empty on error
        "total_winnings" => 0 // Ensure total_winnings is 0 on error
    ];
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