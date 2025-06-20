<?php
// db_connect.php - Database connection setup for ProjectTeer

$servername = "localhost";
$dbusername = "root";       // Your MySQL username
$dbpassword = "newpassword"; // Updated: Your MySQL password
$dbname = "project-teer";   // Your database name
$dbport = 3307;             // Updated: Your MySQL port

// Create connection
// For mysqli, the port is the fifth argument in the constructor
$conn = new mysqli($servername, $dbusername, $dbpassword, $dbname, $dbport);

// Check connection
if ($conn->connect_error) {
    error_log("Database Connection Failed: " . $conn->connect_error);
    // In AJAX contexts, it's better to return a JSON error
    http_response_code(500); // Indicate server error
    echo json_encode(["success" => false, "message" => "Database connection error."]);
    exit(); // Crucial to stop script execution
}