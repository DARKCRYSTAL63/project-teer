<?php
// File: project-teer/login/logout.php
session_name("USER_SESSION"); // Set session name for user
session_start(); // Start the session

// Unset all session variables
$_SESSION = array();

// Destroy the session.
session_destroy();

// Redirect to the login page
header("Location: login.html"); // Assumes login.html is in the same directory as logout.php
exit();