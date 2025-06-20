<?php
// File: project-teer/admin/admin_authenticate.php
// Handles admin login authentication.

session_start(); // MUST BE THE FIRST EXECUTABLE LINE AFTER <?php

include '../db_connect.php'; // Correct path: Go up one level to reach db_connect.php

header('Content-Type: application/json'); // Respond with JSON

$response = ["success" => false, "message" => "An unknown error occurred."]; // Default response
$stmt = null; // Initialize statement

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password']; // Plain text password from form

    if (empty($username) || empty($password)) {
        $response = ["success" => false, "message" => "Username and password are required."];
        echo json_encode($response);
        exit(); // Crucial: Exit immediately after sending response
    }

    // Retrieve admin user from database by username and role 'admin'
    $sql = "SELECT id, username, password_hash, role FROM users WHERE username = ? AND role = 'admin'";
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("SQL prepare failed: " . $conn->error);
        }
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $admin_user = $result->fetch_assoc();
            // Verify the provided password against the hashed password
            if (password_verify($password, $admin_user['password_hash'])) {
                // Admin login successful!
                // Clear any existing user session variables (if any)
                unset($_SESSION['loggedin_user_id']);
                unset($_SESSION['loggedin_username']);
                unset($_SESSION['loggedin_phone_number']);
                unset($_SESSION['loggedin_balance']);
                unset($_SESSION['loggedin_user_role']);

                // Set admin-specific session variables
                $_SESSION['loggedin_admin_id'] = $admin_user['id'];
                $_SESSION['loggedin_admin_username'] = $admin_user['username'];
                $_SESSION['loggedin_admin_role'] = $admin_user['role']; // Should be 'admin'

                $response = ["success" => true, "message" => "Admin login successful!"];
            } else {
                $response = ["success" => false, "message" => "Incorrect password."];
            }
        } else {
            $response = ["success" => false, "message" => "Admin user not found or invalid credentials."];
        }
    } catch (Exception $e) {
        error_log("Error in admin_authenticate.php: " . $e->getMessage());
        $response = ["success" => false, "message" => "An error occurred: " . $e->getMessage()];
    } finally {
        if ($stmt) $stmt->close();
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->close();
        }
    }
} else {
    $response = ["success" => false, "message" => "Invalid request method."];
}

echo json_encode($response);
exit();