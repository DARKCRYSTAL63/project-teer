<?php
// register.php
// Handles new user registration.

session_start(); // Added session_start for consistency; can be removed if not directly using sessions in registration flow.

include '../db_connect.php'; // Ensure this file exists and connects to your database

header('Content-Type: application/json'); // Respond with JSON

$response = ["success" => false, "message" => "An unknown error occurred."]; // Default response

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $phone_number = isset($_POST['phone']) ? $conn->real_escape_string($_POST['phone']) : '';
    $username = isset($_POST['username']) ? $conn->real_escape_string($_POST['username']) : '';

    if (empty($phone_number) || empty($username)) {
        $response = ["success" => false, "message" => "Phone number and username are required."];
        echo json_encode($response);
        exit(); // Crucial: Exit immediately after sending response
    }

    // Sanitize phone number (remove non-digits)
    $phone_number_clean = preg_replace('/[^0-9]/', '', $phone_number);

    // Auto-generate password: username + last 5 digits of phone number
    $last_five_digits = substr($phone_number_clean, -5);
    $auto_generated_password = $username . $last_five_digits;

    // Hash the password for security (NEVER store plain text passwords!)
    $password_hash = password_hash($auto_generated_password, PASSWORD_DEFAULT);

    $stmt_check = null; // Initialize statements
    $stmt_insert = null;

    try {
        // Check if phone number or username already exists
        $check_sql = "SELECT id FROM users WHERE phone_number = ? OR username = ?";
        $stmt_check = $conn->prepare($check_sql);
        if (!$stmt_check) {
            throw new Exception("SQL prepare (check) failed: " . $conn->error);
        }
        $stmt_check->bind_param("ss", $phone_number_clean, $username);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows > 0) {
            $response = ["success" => false, "message" => "Phone number or username already exists. Please choose a different one."];
            echo json_encode($response);
            exit(); // Crucial: Exit immediately after sending response
        }
        $stmt_check->close(); // Close after use

        // Insert new user into the database
        // Assuming initial balance is 0.00 and default role is 'user'
        $insert_sql = "INSERT INTO users (phone_number, username, password_hash, balance, role) VALUES (?, ?, ?, 0.00, 'user')";
        $stmt_insert = $conn->prepare($insert_sql);
        if (!$stmt_insert) {
            throw new Exception("SQL prepare (insert) failed: " . $conn->error);
        }
        // 'sss' for string, string, string (phone_number, username, password_hash)
        $stmt_insert->bind_param("sss", $phone_number_clean, $username, $password_hash);

        if ($stmt_insert->execute()) {
            $response = ["success" => true, "message" => "Registration successful! Please save your password.", "password" => $auto_generated_password];
        } else {
            throw new Exception("Registration failed: " . $stmt_insert->error);
        }
    } catch (Exception $e) {
        error_log("Error in register.php: " . $e->getMessage());
        $response = ["success" => false, "message" => "An error occurred during registration: " . $e->getMessage()];
    } finally {
        if ($stmt_check) $stmt_check->close(); // Ensure statements are closed if they were prepared
        if ($stmt_insert) $stmt_insert->close();
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->close();
        }
    }
} else {
    $response = ["success" => false, "message" => "Invalid request method."];
}

echo json_encode($response);
exit();