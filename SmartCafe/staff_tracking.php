<?php
session_start();
require_once 'config.php';

// Auth Guard & Authorization Check (Admin & Manager Only)
if (!isset($_SESSION['username']) || !in_array($_SESSION['role'] ?? '', ['Admin', 'Manager'], true)) { 
    header("Location: dashboard.php"); 
    exit(); 
}

// Pagination Logic
$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Fetch total count for pagination links
$count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM staff_logs");
$total_rows = ($count_query) ? mysqli_fetch_assoc($count_query)['total'] : 0;
$total_pages = max(1, ceil($total_rows / $limit));

// Prepared Statement with LIMIT/OFFSET
$stmt = mysqli_prepare($conn, "SELECT log_time, username, role, action FROM staff_logs ORDER BY log_time DESC LIMIT ? OFFSET ?");
mysqli_stmt_bind_param($stmt, "ii", $limit, $offset);
mysqli_stmt_execute($stmt);
$logs = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs | SmartCafe</title>
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

        .log-container {
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

        /* Glass Card Wrapper */
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

        /* Modern Table */
        .log-table { 
            width: 100%; 
            border-collapse: collapse; 
        }

        .log-table th, 
        .log-table td { 
            padding: 18px 28px; 
            text-align: left; 
            vertical-align: middle; 
        }

        .log-table th { 
            background: rgba(241, 245, 249, 0.6); 
            color: var(--text-muted); 
            font-size: 12px; 
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700; 
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }

        .log-table td { 
            border-bottom: 1px solid rgba(226, 232, 240, 0.5); 
            font-size: 14px; 
            font-weight: 500;
        }

        .log-table tr:last-child td {
            border-bottom: none;
        }

        .log-table tr:hover td {
            background: rgba(255, 255, 255, 0.4);
        }

        .time-cell {
            color: var(--text-muted);
            font-weight: 600;
            white-space: nowrap;
        }

        /* Role Badges */
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

        .action-text {
            color: var(--text-dark);
            line-height: 1.5;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .pagination a {
            padding: 10px 16px;
            background: var(--glass-card);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            color: var(--text-dark);
            text-decoration: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 13px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            transition: all 0.2s ease;
        }

        .pagination a.active {
            background: #1e293b;
            color: #ffffff;
            border-color: #1e293b;
            box-shadow: 0 4px 12px rgba(30, 41, 59, 0.2);
        }

        .pagination a:hover:not(.active) {
            background: #ffffff;
            transform: translateY(-2px);
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
            .card-box {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border-radius: 18px;
            }
            .log-table th, .log-table td {
                padding: 12px 10px;
                font-size: 13px;
            }
            .pagination {
                gap: 4px;
            }
            .pagination a {
                padding: 8px 12px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>

<div class="log-container">
    
    <!-- Glass Header -->
    <div class="header">
        <div class="header-title">
            <h1><i class="fa-solid fa-list-check"></i> Activity Logs</h1>
            <p>System audit trail and staff action monitoring</p>
        </div>
        <div>
            <a href="dashboard.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        </div>
    </div>

    <!-- Activity Table Card -->
    <div class="card-box">
        <div class="card-header">
            <i class="fa-solid fa-clock-rotate-left"></i> Recorded Actions
        </div>

        <table class="log-table">
            <thead>
                <tr>
                    <th style="width: 22%;">Time</th>
                    <th style="width: 20%;">Employee Name</th>
                    <th style="width: 15%;">Role</th>
                    <th style="width: 43%;">Action Recorded</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs && mysqli_num_rows($logs) > 0): ?>
                    <?php while($row = mysqli_fetch_assoc($logs)): 
                        $role_class = '';
                        if ($row['role'] === 'Admin') {
                            $role_class = 'role-admin';
                        } elseif ($row['role'] === 'Manager') {
                            $role_class = 'role-manager';
                        }
                    ?>
                    <tr>
                        <td class="time-cell">
                            <i class="fa-regular fa-clock" style="margin-right: 6px; font-size: 12px;"></i>
                            <?php 
                            $time_str = strtotime($row['log_time']);
                            echo $time_str ? date('M d, Y - h:i A', $time_str) : htmlspecialchars($row['log_time']); 
                            ?>
                        </td>
                        <td><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                        <td>
                            <span class="role-badge <?php echo $role_class; ?>">
                                <?php echo htmlspecialchars($row['role']); ?>
                            </span>
                        </td>
                        <td class="action-text"><?php echo htmlspecialchars($row['action']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 35px; color: var(--text-muted); font-style: italic;">
                            No activity logs recorded yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Glass Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>"><i class="fa-solid fa-chevron-left"></i> Prev</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?>" class="<?php echo ($i === $page) ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?>">Next <i class="fa-solid fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

</body>
</html>