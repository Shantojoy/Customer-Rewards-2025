<?php
// Database configuration
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'rewards_system';

// Default login credentials
$defaultAdminUsername = 'admin';
$defaultAdminPassword = 'admin123';

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
?>
