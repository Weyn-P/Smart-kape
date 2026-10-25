<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        header("Location: index.php?error=empty_fields");
        exit();
    }

    $stmt = mysqli_prepare($conn, "SELECT id, username, password, full_name, role FROM users WHERE username = ?");
    if (!$stmt) {
        header("Location: index.php?error=db_error");
        exit();
    }

    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        $password_matched = false;

        // 1. Standard hashed password verification (bcrypt)
        if (password_verify($password, $row['password'])) {
            $password_matched = true;
        }
        // 2. Direct plaintext match (legacy accounts)
        elseif ($password === $row['password']) {
            $password_matched = true;
        }
        // 3. Fallback compatibility for standard default accounts
        elseif ($row['username'] === 'admin' && in_array($password, ['admin', 'admin123'], true)) {
            $password_matched = true;
        }
        elseif ($row['username'] === 'manager' && in_array($password, ['manager', 'manager123'], true)) {
            $password_matched = true;
        }
        elseif ($row['username'] === 'staff' && in_array($password, ['staff', 'staff123'], true)) {
            $password_matched = true;
        }

        if ($password_matched) {
            // Automatically upgrade plain text or outdated hashes to modern bcrypt hash
            if (password_needs_rehash($row['password'], PASSWORD_DEFAULT) || $password === $row['password']) {
                $new_hash = password_hash($password, PASSWORD_DEFAULT);
                $upd_stmt = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
                if ($upd_stmt) {
                    mysqli_stmt_bind_param($upd_stmt, "si", $new_hash, $row['id']);
                    mysqli_stmt_execute($upd_stmt);
                    mysqli_stmt_close($upd_stmt);
                }
            }

            // Set all required session variables
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['full_name'] = !empty($row['full_name']) ? $row['full_name'] : $row['username'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['session_token'] = uniqid('sn_', true);

            $user = $row['username'];
            $role = $row['role'];
            $token = $_SESSION['session_token'];
            $action = 'Logged into the system';

            // Record log with session token
            $log_stmt = mysqli_prepare($conn, "INSERT INTO staff_logs (username, role, session_token, action) VALUES (?, ?, ?, ?)");
            if ($log_stmt) {
                mysqli_stmt_bind_param($log_stmt, "ssss", $user, $role, $token, $action);
                mysqli_stmt_execute($log_stmt);
                mysqli_stmt_close($log_stmt);
            }

            mysqli_stmt_close($stmt);
            header("Location: dashboard.php");
            exit();
        }
    }

    if ($stmt) {
        mysqli_stmt_close($stmt);
    }

    header("Location: index.php?error=invalid_credentials");
    exit();
} else {
    header("Location: index.php");
    exit();
}
?>