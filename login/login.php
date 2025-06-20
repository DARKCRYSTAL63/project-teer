<?php
// File: project-teer/login/login.php
// Handles user login with phone number and password.

session_start(); // THIS MUST BE THE FIRST EXECUTABLE LINE AFTER <?php

include '../db_connect.php'; // Correct path: Go up one level to reach db_connect.php

header('Content-Type: application/json'); // Respond with JSON

$response = ["success" => false, "message" => "An unknown error occurred."]; // Default response
$stmt = null; // Initialize statement

// --- DEBUGGING SESSION START ---
error_log("login.php: Session ID: " . session_id());
error_log("login.php: SESSION_ARRAY (Before Login Logic): " . print_r($_SESSION, true));
// --- DEBUGGING SESSION END ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $raw_phone_number = isset($_POST['phone']) ? $_POST['phone'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($raw_phone_number) || empty($password)) {
        $response = ["success" => false, "message" => "Phone number and password are required."];
        echo json_encode($response);
        exit(); // Crucial: Exit immediately after sending response
    }

    // Sanitize phone number by removing all non-digit characters
    $phone_number_clean = preg_replace('/[^0-9]/', '', $raw_phone_number);

    // Retrieve user from database by the CLEANED phone number
    $sql = "SELECT id, phone_number, username, password_hash, balance, role FROM users WHERE phone_number = ?"; 
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("SQL prepare failed: " . $conn->error);
        }
        $stmt->bind_param("s", $phone_number_clean); 
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();

            // Verify the provided password against the hashed password
            if (password_verify($password, $user['password_hash'])) {
                // Login successful!
                // Clear any existing admin session variables (if any)
                unset($_SESSION['loggedin_admin_id']);
                unset($_SESSION['loggedin_admin_username']);
                unset($_SESSION['loggedin_admin_role']);
                
                // IMPORTANT: Clear the old 'user_id' if it exists to prevent conflict
                unset($_SESSION['user_id']); 

                // Set user-specific session variables using 'loggedin_user_id'
                $_SESSION['loggedin_user_id'] = $user['id']; 
                $_SESSION['loggedin_phone_number'] = $user['phone_number'];
                $_SESSION['loggedin_username'] = $user['username']; 
                $_SESSION['loggedin_balance'] = $user['balance']; 
                $_SESSION['loggedin_user_role'] = $user['role']; 

                $response = ["success" => true, "message" => "Login successful!", "username" => $user['username']];
                error_log("login.php: Login SUCCESS for user ID: " . $user['id']);
                error_log("login.php: SESSION_ARRAY (After Login Success): " . print_r($_SESSION, true));

            } else {
                $response = ["success" => false, "message" => "Incorrect password."];
                error_log("login.php: Incorrect password for phone: " . $phone_number_clean);
            }
        } else {
            $response = ["success" => false, "message" => "Phone number not found. Please register."];
            error_log("login.php: Phone number not found: " . $phone_number_clean);
        }
    } catch (Exception $e) {
        $response = ["success" => false, "message" => "Database error during login: " . $e->getMessage()];
        error_log("Login Attempt: DB Exception: " . $e->getMessage());
    } finally {
        if ($stmt) $stmt->close();
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->close();
        }
    }
} else {
    $response = ["success" => false, "message" => "Invalid request method."];
    error_log("login.php: Invalid request method.");
}

echo json_encode($response);
exit();