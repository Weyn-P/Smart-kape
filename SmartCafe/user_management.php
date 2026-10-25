<?php
session_start();
require_once 'config.php';

// SECURITY: Only Admin can access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}

// Get the logged-in user ID safely
$current_admin_id = (int)($_SESSION['user_id'] ?? 0);
$msg = '';
$error_msg = '';

// --- LOGIC: CREATE NEW USER (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $un = trim($_POST['username'] ?? '');
    $pw = $_POST['password'] ?? '';
    $rl = $_POST['role'] ?? '';
    $fn = trim($_POST['full_name'] ?? '');

    $allowed_roles = ['Staff', 'Manager', 'Admin'];

    if (!empty($un) && !empty($pw) && !empty($fn) && in_array($rl, $allowed_roles, true)) {
        
        // Check for existing username
        $check_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ?");
        mysqli_stmt_bind_param($check_stmt, "s", $un);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);

        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $error_msg = "Error: Username already exists.";
        } else {
            // Hash password securely
            $hashed_pw = password_hash($pw, PASSWORD_DEFAULT);

            $insert_stmt = mysqli_prepare($conn, "INSERT INTO users (username, password, role, full_name) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($insert_stmt, "ssss", $un, $hashed_pw, $rl, $fn);

            if (mysqli_stmt_execute($insert_stmt)) {
                // RECORD LOG: User Creation
                $admin_user = $_SESSION['username'] ?? 'Admin';
                $admin_role = $_SESSION['role'] ?? 'Admin';
                $token = $_SESSION['session_token'] ?? 'N/A';
                $action = "Created new {$rl} account: {$un}";

                $log_stmt = mysqli_prepare($conn, "INSERT INTO staff_logs (username, role, session_token, action) VALUES (?, ?, ?, ?)");
                mysqli_stmt_bind_param($log_stmt, "ssss", $admin_user, $admin_role, $token, $action);
                mysqli_stmt_execute($log_stmt);

                $msg = "User created successfully!";
            } else {
                $error_msg = "Error: Failed to create user.";
            }
        }
    } else {
        $error_msg = "Error: Please fill in all fields with valid information.";
    }
}

// --- LOGIC: DELETE USER (POST Action) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
    $delete_id = (int)$_POST['delete_user_id'];

    // Check role of target user
    $role_stmt = mysqli_prepare($conn, "SELECT role FROM users WHERE id = ?");
    mysqli_stmt_bind_param($role_stmt, "i", $delete_id);
    mysqli_stmt_execute($role_stmt);
    $res = mysqli_stmt_get_result($role_stmt);
    $user_to_delete = mysqli_fetch_assoc($res);

    // PROTECTION: Ensure admin doesn't delete themselves OR any user with 'Admin' role
    if ($delete_id !== $current_admin_id && $user_to_delete && $user_to_delete['role'] !== 'Admin') {
        
        // RECORD LOG: User Deletion
        $admin_user = $_SESSION['username'] ?? 'Admin';
        $admin_role = $_SESSION['role'] ?? 'Admin';
        $token = $_SESSION['session_token'] ?? 'N/A';
        $action = "Deleted user account ID: {$delete_id}";

        $log_stmt = mysqli_prepare($conn, "INSERT INTO staff_logs (username, role, session_token, action) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($log_stmt, "ssss", $admin_user, $admin_role, $token, $action);
        mysqli_stmt_execute($log_stmt);

        // Execute Delete
        $del_stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
        mysqli_stmt_bind_param($del_stmt, "i", $delete_id);
        mysqli_stmt_execute($del_stmt);

        header("Location: user_management.php?msg=deleted");
        exit();
    } else {
        header("Location: user_management.php?error=cannot_delete_admin");
        exit();
    }
}

// Optimized query using a subquery to avoid N+1 database queries in the display loop
$users_query = "SELECT u.id, u.username, u.full_name, u.role,
                (SELECT sl.action 
                 FROM staff_logs sl 
                 WHERE sl.username = u.username 
                 ORDER BY sl.log_time DESC 
                 LIMIT 1) AS last_action
                FROM users u
                ORDER BY u.id ASC";

$users_list = mysqli_query($conn, $users_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | SmartCafe</title>
    <?php include_once __DIR__ . '/pwa_head.php'; ?>

    <!-- Google Fonts & Font Awesome Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    

    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #dbe4ee 0%, #e8edf3 50%, #d8e2ed 100%);
            --glass-card: rgba(255, 255, 255, 0.65);
            --glass-border: rgba(255, 255, 255, 0.8);
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --dark-header: #1e293b;
            --shadow: 0 10px 30px rgba(148, 163, 184, 0.18);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: var(--bg-gradient);
            background-attachment: fixed;
            color: var(--text-dark);
            min-height: 100vh;
            padding: 30px 20px;
        }

        .admin-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Glass Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--glass-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 24px 32px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-title h1 {
            color: var(--text-dark);
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-title p {
            margin-top: 4px;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 500;
        }

        .btn-back {
            padding: 10px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            backdrop-filter: blur(10px);
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.8);
            color: var(--text-dark);
            border: 1px solid var(--glass-border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .btn-back:hover {
            background: #ffffff;
            transform: translateY(-2px);
        }

        /* Glass Cards */
        .card-box {
            background: var(--glass-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow);
            margin-bottom: 25px;
        }

        .card-header {
            background: var(--dark-header);
            padding: 18px 28px;
            font-size: 16px;
            font-weight: 800;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #334155;
        }

        .card-body {
            padding: 28px;
        }

        /* Form Controls */
        .input-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .input-group {
            flex: 1;
            min-width: 200px;
        }

        .input-group label {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-dark);
            display: block;
            margin-bottom: 8px;
        }

        .input-group input, 
        .input-group select {
            width: 100%;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(203, 213, 225, 0.8);
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            color: var(--text-dark);
            outline: none;
            transition: all 0.2s ease;
        }

        .input-group input:focus, 
        .input-group select:focus {
            background: #ffffff;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .btn-create {
            background: #1e293b;
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 12px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 45px;
        }

        .btn-create:hover {
            background: #0f172a;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        /* Tables */
        .user-table {
            width: 100%;
            border-collapse: collapse;
        }

        .user-table th, 
        .user-table td {
            padding: 18px 28px;
            text-align: left;
            vertical-align: middle;
        }

        .user-table th {
            background: rgba(241, 245, 249, 0.6);
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }

        .user-table td {
            border-bottom: 1px solid rgba(226, 232, 240, 0.5);
            font-size: 14px;
            font-weight: 500;
        }

        .user-table tr:last-child td {
            border-bottom: none;
        }

        .user-table tr:hover td {
            background: rgba(255, 255, 255, 0.4);
        }

        /* Badges & Status */
        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            background: rgba(226, 232, 240, 0.8);
            color: #334155;
            border: 1px solid rgba(203, 213, 225, 0.8);
        }

        .role-admin {
            background: rgba(238, 242, 255, 0.9);
            color: #4338ca;
            border-color: rgba(199, 210, 254, 0.8);
        }

        .role-manager {
            background: rgba(240, 253, 244, 0.9);
            color: #15803d;
            border-color: rgba(187, 247, 208, 0.8);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .status-pill.online {
            background: rgba(220, 252, 231, 0.8);
            color: #15803d;
        }

        .status-pill.offline {
            background: rgba(254, 226, 226, 0.8);
            color: #b91c1c;
        }

        .status-dot {
            height: 8px;
            width: 8px;
            border-radius: 50%;
        }

        .online .status-dot { background-color: #22c55e; }
        .offline .status-dot { background-color: #ef4444; }

        .btn-del-btn {
            background: rgba(254, 226, 226, 0.6);
            border: 1px solid rgba(252, 165, 165, 0.5);
            color: #dc2626;
            padding: 6px 14px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-del-btn:hover {
            background: #dc2626;
            color: #ffffff;
        }

        .active-user {
            color: var(--text-muted);
            font-style: italic;
            font-weight: 600;
            font-size: 13px;
        }

        .protected-user {
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Notifications */
        .alert-message {
            padding: 16px 20px;
            border-radius: 16px;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }

        .alert-danger {
            background: rgba(254, 226, 226, 0.9);
            color: #991b1b;
            border: 1px solid rgba(252, 165, 165, 0.8);
        }

        .alert-success {
            background: rgba(220, 252, 231, 0.9);
            color: #166534;
            border: 1px solid rgba(187, 247, 208, 0.8);
        }

        @media (max-width: 768px) {
            body { padding: 16px 8px; }
            .header {
                flex-direction: column;
                align-items: stretch;
                padding: 18px 16px;
                gap: 14px;
            }
            .header .btn-back {
                width: 100%;
                justify-content: center;
            }
            .input-row {
                flex-direction: column;
                align-items: stretch;
            }
            .btn-create {
                width: 100%;
                justify-content: center;
            }
            .card-box {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border-radius: 18px;
            }
            .user-table th, .user-table td {
                padding: 12px 10px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>

<div class="admin-container">
    
    <!-- Glass Header -->
    <div class="header">
        <div class="header-title">
            <h1><i class="fa-solid fa-users-gear"></i> User Management</h1>
            <p>Control system access, user roles, and monitor account activity</p>
        </div>
        <div>
            <a href="dashboard.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_GET['error']) && $_GET['error'] === 'cannot_delete_admin'): ?>
        <div class="alert-message alert-danger">
            <i class="fa-solid fa-circle-exclamation"></i> Error: Admin accounts are protected and cannot be deleted.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
        <div class="alert-message alert-success">
            <i class="fa-solid fa-circle-check"></i> User account deleted successfully.
        </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="alert-message alert-danger">
            <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error_msg); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($msg)): ?>
        <div class="alert-message alert-success">
            <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <!-- User Creation Card -->
    <div class="card-box">
        <div class="card-header">
            <i class="fa-solid fa-user-plus"></i> Create New System User
        </div>
        <div class="card-body">
            <form method="POST" class="input-row">
                <input type="hidden" name="create_user" value="1">
                <div class="input-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" placeholder="e.g. John Doe" required autocomplete="off">
                </div>
                <div class="input-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="e.g. jdoe" required autocomplete="off">
                </div>
                <div class="input-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
                <div class="input-group">
                    <label>Role</label>
                    <select name="role">
                        <option value="Staff">Staff</option>
                        <option value="Manager">Manager</option>
                        <option value="Admin">Admin</option>
                    </select>
                </div>
                <button type="submit" class="btn-create"><i class="fa-solid fa-plus"></i> Add User</button>
            </form>
        </div>
    </div>

    <!-- System Users Table Card -->
    <div class="card-box">
        <div class="card-header">
            <i class="fa-solid fa-users"></i> System Users
        </div>
        <table class="user-table">
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users_list && mysqli_num_rows($users_list) > 0): ?>
                    <?php while($row = mysqli_fetch_assoc($users_list)): 
                        $is_online = ($row['last_action'] === 'Logged into the system');
                        
                        $role_class = '';
                        if ($row['role'] === 'Admin') {
                            $role_class = 'role-admin';
                        } elseif ($row['role'] === 'Manager') {
                            $role_class = 'role-manager';
                        }
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                        <td style="color: var(--text-muted);"><?php echo htmlspecialchars($row['username']); ?></td>
                        <td>
                            <span class="role-badge <?php echo $role_class; ?>">
                                <?php echo htmlspecialchars($row['role']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($is_online): ?>
                                <span class="status-pill online">
                                    <span class="status-dot"></span> Online
                                </span>
                            <?php else: ?>
                                <span class="status-pill offline">
                                    <span class="status-dot"></span> Offline
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['role'] === 'Admin'): ?>
                                <?php if ($row['id'] == $current_admin_id): ?>
                                    <span class="active-user"><i class="fa-solid fa-user-check"></i> Active (You)</span>
                                <?php else: ?>
                                    <span class="protected-user"><i class="fa-solid fa-shield-halved"></i> Protected</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <form method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                    <input type="hidden" name="delete_user_id" value="<?php echo (int)$row['id']; ?>">
                                    <button type="submit" class="btn-del-btn"><i class="fa-solid fa-trash"></i> Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" style="text-align:center; padding:35px; color: var(--text-muted);">No users found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>