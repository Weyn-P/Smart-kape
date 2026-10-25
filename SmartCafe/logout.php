<?php
session_start();
require_once 'config.php';

if (isset($_SESSION['username'])) {
    $user = $_SESSION['username'];
    $role = $_SESSION['role'] ?? 'Staff';
    $token = $_SESSION['session_token'] ?? null;
    
    // RECORD LOG: Logout activity
    $log_stmt = mysqli_prepare($conn, "INSERT INTO staff_logs (username, role, session_token, action) VALUES (?, ?, ?, 'Logged out')");
    if ($log_stmt) {
        mysqli_stmt_bind_param($log_stmt, "sss", $user, $role, $token);
        mysqli_stmt_execute($log_stmt);
        mysqli_stmt_close($log_stmt);
    }
}

$_SESSION = [];
session_destroy();
header("Location: index.php?msg=logged_out");
exit();
?>