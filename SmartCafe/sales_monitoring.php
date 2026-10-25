<?php
session_start();
require_once 'config.php';

// Security: Check if user is logged in
if (!isset($_SESSION['username'])) { 
    header("Location: index.php"); 
    exit(); 
}

// Security: Only Admin and Manager can access Sales Monitoring
if ($_SESSION['role'] === 'Staff') {
    header("Location: dashboard.php");
    exit();
}

// -------------------------------------------------------------
// AJAX VOID HANDLER (Processes in background without page refresh)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'void_order') {
    header('Content-Type: application/json');
    
    if (!in_array($_SESSION['role'], ['Admin', 'Manager'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        exit();
    }

    $void_id = (int)($_POST['void_id'] ?? 0);
    if ($void_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
        exit();
    }

    // 1. Restore menu item availability if previously marked out of stock
    $items_res = mysqli_query($conn, "SELECT menu_item_name FROM order_items WHERE order_id = '$void_id'");
    if ($items_res) {
        while($item = mysqli_fetch_assoc($items_res)) {
            $item_name = mysqli_real_escape_string($conn, $item['menu_item_name']);
            mysqli_query($conn, "UPDATE menu SET status = 'Available' WHERE item_name = '$item_name' AND status = 'Out of Stock'");
        }
    }

    // 2. Record Log
    $user = mysqli_real_escape_string($conn, $_SESSION['username']);
    $role = mysqli_real_escape_string($conn, $_SESSION['role']);
    $log_stmt = mysqli_prepare($conn, "INSERT INTO staff_logs (username, role, action) VALUES (?, ?, ?)");
    if ($log_stmt) {
        $log_action = "VOIDED Order #$void_id";
        mysqli_stmt_bind_param($log_stmt, "sss", $user, $role, $log_action);
        mysqli_stmt_execute($log_stmt);
        mysqli_stmt_close($log_stmt);
    }

    // 3. Delete order items & order
    mysqli_query($conn, "DELETE FROM order_items WHERE order_id = '$void_id'");
    $deleted = mysqli_query($conn, "DELETE FROM orders WHERE id = '$void_id'");

    if ($deleted) {
        echo json_encode(['success' => true, 'id' => $void_id]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit();
}

// -------------------------------------------------------------
// 1. TODAY'S QUICK OVERVIEW STATS
// -------------------------------------------------------------
$today = date('Y-m-d');
$today_query = mysqli_query($conn, "SELECT SUM(total_amount) as total, COUNT(*) as count FROM orders WHERE DATE(order_date) = '$today' AND order_status='Completed'");
$today_data = $today_query ? mysqli_fetch_assoc($today_query) : ['total' => 0, 'count' => 0];
$today_revenue = $today_data['total'] ?? 0;
$today_orders = $today_data['count'] ?? 0;

// -------------------------------------------------------------
// 2. REVENUE SUMMARIES (DAILY, WEEKLY, MONTHLY)
// -------------------------------------------------------------
$daily_breakdown = mysqli_query($conn, "
    SELECT 
        DATE(order_date) as sales_day, 
        COUNT(id) as transaction_count, 
        SUM(total_amount) as daily_total,
        AVG(total_amount) as daily_average
    FROM orders 
    WHERE order_status = 'Completed'
    GROUP BY DATE(order_date) 
    ORDER BY sales_day DESC 
    LIMIT 30
");

$weekly_breakdown = mysqli_query($conn, "
    SELECT 
        YEAR(order_date) as sales_year,
        WEEK(order_date, 1) as sales_week,
        MIN(DATE(order_date)) as week_start,
        MAX(DATE(order_date)) as week_end,
        COUNT(id) as transaction_count, 
        SUM(total_amount) as weekly_total,
        AVG(total_amount) as weekly_average
    FROM orders 
    WHERE order_status = 'Completed'
    GROUP BY YEAR(order_date), WEEK(order_date, 1) 
    ORDER BY sales_year DESC, sales_week DESC 
    LIMIT 12
");

$monthly_breakdown = mysqli_query($conn, "
    SELECT 
        DATE_FORMAT(order_date, '%Y-%m') as sales_month, 
        COUNT(id) as transaction_count, 
        SUM(total_amount) as monthly_total,
        AVG(total_amount) as monthly_average
    FROM orders 
    WHERE order_status = 'Completed'
    GROUP BY DATE_FORMAT(order_date, '%Y-%m') 
    ORDER BY sales_month DESC 
    LIMIT 12
");

// Build Arrays for Chart.js Rendering
$daily_data_rows = [];
if ($daily_breakdown) {
    while($row = mysqli_fetch_assoc($daily_breakdown)) {
        $daily_data_rows[] = $row;
    }
}

$weekly_data_rows = [];
if ($weekly_breakdown) {
    while($row = mysqli_fetch_assoc($weekly_breakdown)) {
        $weekly_data_rows[] = $row;
    }
}

$monthly_data_rows = [];
if ($monthly_breakdown) {
    while($row = mysqli_fetch_assoc($monthly_breakdown)) {
        $monthly_data_rows[] = $row;
    }
}

// Prepare chronological arrays for charts
$chart_daily_labels = array_reverse(array_map(function($d) { return date('M d', strtotime($d['sales_day'])); }, $daily_data_rows));
$chart_daily_totals = array_reverse(array_map(function($d) { return (float)$d['daily_total']; }, $daily_data_rows));

$chart_weekly_labels = array_reverse(array_map(function($w) { return 'Wk ' . $w['sales_week'] . ' (' . date('M d', strtotime($w['week_start'])) . ')'; }, $weekly_data_rows));
$chart_weekly_totals = array_reverse(array_map(function($w) { return (float)$w['weekly_total']; }, $weekly_data_rows));

$chart_monthly_labels = array_reverse(array_map(function($m) { return date('M Y', strtotime($m['sales_month'] . '-01')); }, $monthly_data_rows));
$chart_monthly_totals = array_reverse(array_map(function($m) { return (float)$m['monthly_total']; }, $monthly_data_rows));

// -------------------------------------------------------------
// 3. FETCH DETAILED SALES HISTORY
// -------------------------------------------------------------
$sales_history = mysqli_query($conn, "SELECT * FROM orders ORDER BY order_date DESC LIMIT 100");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartCafe | Sales & Customer Monitoring</title>
    <?php include_once __DIR__ . '/pwa_head.php'; ?>

    <!-- Google Fonts & Font Awesome Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js Engine -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    

    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #dbe4ee 0%, #e8edf3 50%, #d8e2ed 100%);
            --glass-card: rgba(255, 255, 255, 0.65);
            --glass-border: rgba(255, 255, 255, 0.8);
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --dark-btn: #2d3748;
            --dark-btn-hover: #1a202c;
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

        .sales-container {
            max-width: 1300px;
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

        .nav-links {
            display: flex;
            gap: 12px;
        }

        .btn-back, .btn-logout {
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
        }

        .btn-back {
            background: rgba(255, 255, 255, 0.8);
            color: var(--text-dark);
            border: 1px solid var(--glass-border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .btn-back:hover {
            background: #ffffff;
            transform: translateY(-2px);
        }

        .btn-logout {
            background: var(--dark-btn);
            color: #ffffff;
            border: none;
            box-shadow: 0 4px 12px rgba(45, 55, 72, 0.2);
        }

        .btn-logout:hover {
            background: var(--dark-btn-hover);
            transform: translateY(-2px);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--glass-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            padding: 22px 28px;
            border-radius: 20px;
            box-shadow: var(--shadow);
            transition: transform 0.25s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card p {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-card h2 {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-dark);
            margin-top: 8px;
        }

        /* Interactive Chart Container */
        .chart-box {
            background: var(--glass-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 24px 28px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .chart-header h4 {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-controls {
            display: flex;
            gap: 6px;
            background: rgba(241, 245, 249, 0.6);
            padding: 4px;
            border-radius: 12px;
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .chart-btn {
            padding: 6px 14px;
            border: none;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            border-radius: 8px;
            font-weight: 700;
            font-size: 12px;
            transition: all 0.2s ease;
        }

        .chart-btn.active {
            background: var(--dark-btn);
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(45, 55, 72, 0.2);
        }

        .canvas-container {
            position: relative;
            width: 100%;
            height: 320px;
        }

        /* Section Titles */
        .section-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Card Container Styling */
        .card-box {
            background: var(--glass-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }

        /* Dark Card Header */
        .card-header {
            background: #1e293b;
            padding: 18px 28px;
            font-size: 16px;
            font-weight: 800;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #334155;
        }

        /* Tabs Navigation */
        .summary-tabs {
            display: flex;
            gap: 8px;
            background: rgba(241, 245, 249, 0.6);
            padding: 6px;
            border-radius: 16px;
            margin-bottom: 20px;
            border: 1px solid rgba(226, 232, 240, 0.8);
            width: fit-content;
        }

        .tab-btn {
            padding: 10px 20px;
            border: none;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            border-radius: 12px;
            font-weight: 700;
            font-size: 13px;
            transition: all 0.25s ease;
        }

        .tab-btn.active {
            background: var(--dark-btn);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(45, 55, 72, 0.2);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Modern Glass Tables */
        .sales-table {
            width: 100%;
            border-collapse: collapse;
        }

        .sales-table th, .sales-table td {
            padding: 16px 28px;
            text-align: left;
        }

        .sales-table th {
            background: rgba(241, 245, 249, 0.6);
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }

        .sales-table td {
            border-bottom: 1px solid rgba(226, 232, 240, 0.5);
            font-size: 14px;
            font-weight: 500;
        }

        .sales-table tr:last-child td {
            border-bottom: none;
        }

        .sales-table tr:hover td {
            background: rgba(255, 255, 255, 0.4);
        }

        .avg-text {
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
        }

        /* Status Badges */
        .status-pill {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .status-completed {
            background: rgba(220, 252, 231, 0.9);
            color: #15803d;
            border: 1px solid rgba(187, 247, 208, 0.8);
        }

        .status-process {
            background: rgba(254, 243, 199, 0.9);
            color: #b45309;
            border: 1px solid rgba(253, 230, 138, 0.8);
        }

        /* Search Controls */
        .search-box {
            display: flex;
            gap: 12px;
            padding: 20px 28px;
            background: rgba(255, 255, 255, 0.4);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }

        .search-box input[type="text"] {
            padding: 12px 18px;
            width: 340px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(203, 213, 225, 0.8);
            color: var(--text-dark);
            outline: none;
            font-family: inherit;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .search-box input[type="text"]:focus {
            border-color: #94a3b8;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.15);
        }

        .search-box .btn-reset {
            padding: 12px 20px;
            border: none;
            background: rgba(255, 255, 255, 0.8);
            color: var(--text-dark);
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
            border: 1px solid var(--glass-border);
            transition: all 0.2s ease;
        }

        .search-box .btn-reset:hover {
            background: #ffffff;
        }

        /* Buttons */
        .btn-void {
            background: rgba(254, 226, 226, 0.8);
            color: #dc2626;
            border: none;
            padding: 8px 14px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            font-size: 12px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-void:hover {
            background: rgba(254, 202, 202, 1);
        }

        @media (max-width: 768px) {
            body { padding: 16px 8px; }
            .header {
                flex-direction: column;
                align-items: stretch;
                padding: 18px 16px;
                gap: 14px;
            }
            .nav-links {
                flex-wrap: wrap;
                width: 100%;
            }
            .nav-links a {
                flex: 1 1 auto;
                text-align: center;
                justify-content: center;
            }
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .chart-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .chart-controls {
                width: 100%;
                display: flex;
            }
            .chart-btn {
                flex: 1;
                text-align: center;
            }
            .summary-tabs {
                display: flex;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                padding-bottom: 6px;
                gap: 8px;
            }
            .tab-btn {
                white-space: nowrap;
                flex-shrink: 0;
            }
            .card-box {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border-radius: 18px;
            }
            .sales-table th, .sales-table td {
                padding: 12px 14px;
                font-size: 13px;
            }
            .search-box {
                flex-direction: column;
                padding: 16px;
            }
            .search-box input[type="text"] {
                width: 100%;
            }
            .search-box .btn-reset {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="sales-container">
    
    <!-- Glass Header -->
    <div class="header">
        <div class="header-title">
            <h1><i class="fa-solid fa-chart-line"></i> Sales & Customer Monitoring</h1>
            <p>Track revenue, performance summaries, and detailed transaction logs</p>
        </div>
        <div class="nav-links">
            <a href="dashboard.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Log Out</a>
        </div>
    </div>

    <!-- Top Quick Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <p>Today's Revenue</p>
            <h2>₱<?php echo number_format($today_revenue, 2); ?></h2>
        </div>
        <div class="stat-card">
            <p>Today's Transactions</p>
            <h2><?php echo $today_orders; ?></h2>
        </div>
        <div class="stat-card">
            <p>Today's Avg / Order</p>
            <h2>₱<?php echo ($today_orders > 0) ? number_format($today_revenue / $today_orders, 2) : "0.00"; ?></h2>
        </div>
    </div>

    <!-- Revenue Analytics Chart -->
    <div class="chart-box">
        <div class="chart-header">
            <h4><i class="fa-solid fa-chart-area"></i> Revenue Growth & Trends</h4>
            <div class="chart-controls">
                <button class="chart-btn active" onclick="switchChart('daily', this)">Daily</button>
                <button class="chart-btn" onclick="switchChart('weekly', this)">Weekly</button>
                <button class="chart-btn" onclick="switchChart('monthly', this)">Monthly</button>
            </div>
        </div>
        <div class="canvas-container">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>

    <!-- Revenue Summaries Section -->
    <h3 class="section-title"><i class="fa-solid fa-chart-pie"></i> Revenue Summaries</h3>
    
    <div class="summary-tabs">
        <button class="tab-btn active" onclick="openTab(event, 'daily-tab')">Daily Summary</button>
        <button class="tab-btn" onclick="openTab(event, 'weekly-tab')">Weekly Summary</button>
        <button class="tab-btn" onclick="openTab(event, 'monthly-tab')">Monthly Summary</button>
    </div>

    <div class="card-box">
        <!-- Daily Summary Tab -->
        <div id="daily-tab" class="tab-content active">
            <table class="sales-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Transactions</th>
                        <th>Total Revenue</th>
                        <th>Avg/Order</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($daily_data_rows)): ?>
                        <?php foreach($daily_data_rows as $day): ?>
                        <tr>
                            <td><strong><?php echo date('F d, Y', strtotime($day['sales_day'])); ?></strong></td>
                            <td><?php echo $day['transaction_count']; ?> orders</td>
                            <td><strong>₱<?php echo number_format($day['daily_total'], 2); ?></strong></td>
                            <td><span class="avg-text">₱<?php echo number_format($day['daily_average'], 2); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align:center; color: var(--text-muted); font-style: italic; padding: 35px;">No daily records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Weekly Summary Tab -->
        <div id="weekly-tab" class="tab-content">
            <table class="sales-table">
                <thead>
                    <tr>
                        <th>Week Period</th>
                        <th>Transactions</th>
                        <th>Total Revenue</th>
                        <th>Avg/Order</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($weekly_data_rows)): ?>
                        <?php foreach($weekly_data_rows as $week): ?>
                        <tr>
                            <td>
                                <strong>Week <?php echo $week['sales_week']; ?></strong> 
                                <span style="color: var(--text-muted); font-size: 13px;">(<?php echo date('M d', strtotime($week['week_start'])); ?> - <?php echo date('M d, Y', strtotime($week['week_end'])); ?>)</span>
                            </td>
                            <td><?php echo $week['transaction_count']; ?> orders</td>
                            <td><strong>₱<?php echo number_format($week['weekly_total'], 2); ?></strong></td>
                            <td><span class="avg-text">₱<?php echo number_format($week['weekly_average'], 2); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align:center; color: var(--text-muted); font-style: italic; padding: 35px;">No weekly records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Monthly Summary Tab -->
        <div id="monthly-tab" class="tab-content">
            <table class="sales-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Transactions</th>
                        <th>Total Revenue</th>
                        <th>Avg/Order</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($monthly_data_rows)): ?>
                        <?php foreach($monthly_data_rows as $month): ?>
                        <tr>
                            <td><strong><?php echo date('F Y', strtotime($month['sales_month'] . '-01')); ?></strong></td>
                            <td><?php echo $month['transaction_count']; ?> orders</td>
                            <td><strong>₱<?php echo number_format($month['monthly_total'], 2); ?></strong></td>
                            <td><span class="avg-text">₱<?php echo number_format($month['monthly_average'], 2); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" style="text-align:center; color: var(--text-muted); font-style: italic; padding: 35px;">No monthly records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Customer Order History Section -->
    <h3 class="section-title"><i class="fa-solid fa-clock-rotate-left"></i> Customer Order History & Detailed Logs</h3>

    <div class="card-box">
        <div class="card-header">
            <span><i class="fa-solid fa-receipt"></i> Recent Order Transactions</span>
        </div>

        <!-- Instant Search Bar -->
        <div class="search-box">
            <input type="text" id="searchInput" onkeyup="filterLogTable()" placeholder="Search by exact Order ID or customer name...">
            <button type="button" class="btn-reset" onclick="clearSearch()"><i class="fa-solid fa-xmark"></i> Clear Search</button>
        </div>

        <table class="sales-table">
            <thead>
                <tr>
                    <th>Queue #</th>
                    <th>Order ID</th>
                    <th>Customer & Type</th>
                    <th>Staff / Cashier</th>
                    <th>Date & Time</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <?php if ($_SESSION['role'] === 'Manager' || $_SESSION['role'] === 'Admin'): ?>
                        <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="historyTableBody">
                <?php 
                if ($sales_history && mysqli_num_rows($sales_history) > 0):
                    while($row = mysqli_fetch_assoc($sales_history)): 
                        $status = !empty($row['order_status']) ? $row['order_status'] : 'In Process';
                        $customer_name = !empty($row['customer_name']) ? htmlspecialchars($row['customer_name']) : 'Default Customer';
                        $q_num = (int)($row['queue_number'] ?? $row['id']);
                        $q_tag = '#' . str_pad($q_num > 0 ? $q_num : 1, 3, '0', STR_PAD_LEFT);
                        $ord_type = htmlspecialchars(!empty($row['order_type']) ? $row['order_type'] : 'Dine In');
                ?>
                <tr id="order-row-<?php echo $row['id']; ?>">
                    <td><span style="font-family: 'Space Mono', monospace; font-weight: 800; font-size: 13px; background: rgba(15, 23, 42, 0.08); padding: 4px 8px; border-radius: 6px;"><?php echo $q_tag; ?></span></td>
                    <td><strong>#<?php echo $row['id']; ?></strong></td>
                    <td>
                        <strong><?php echo $customer_name; ?></strong>
                        <div style="font-size: 11px; font-weight: 700; color: #0284c7; text-transform: uppercase;"><?php echo $ord_type; ?></div>
                    </td>
                    <td><?php echo htmlspecialchars($row['staff_username'] ?? 'N/A'); ?></td>
                    <td><?php echo date('M d, Y - h:i A', strtotime($row['order_date'])); ?></td>
                    <td><strong>₱<?php echo number_format($row['total_amount'], 2); ?></strong></td>
                    <td>
                        <?php if ($status === 'Completed'): ?>
                            <span class="status-pill status-completed"><i class="fa-solid fa-circle-check"></i> Completed</span>
                        <?php else: ?>
                            <span class="status-pill status-process"><i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars($status); ?></span>
                        <?php endif; ?>
                    </td>
                    
                    <?php if ($_SESSION['role'] === 'Manager' || $_SESSION['role'] === 'Admin'): ?>
                    <td>
                        <button type="button" class="btn-void" onclick="voidOrder(<?php echo $row['id']; ?>)">
                           <i class="fa-solid fa-ban"></i> Void Order
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php 
                    endwhile;
                else:
                ?>
                <tr id="noDataRow">
                    <td colspan="8" style="text-align: center; color: var(--text-muted); font-style: italic; padding: 35px;">No transaction history found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
    // Tab Switcher
    function openTab(evt, tabName) {
        var i, tabcontent, tablinks;
        tabcontent = document.getElementsByClassName("tab-content");
        for (i = 0; i < tabcontent.length; i++) {
            tabcontent[i].classList.remove("active");
        }
        tablinks = document.getElementsByClassName("tab-btn");
        for (i = 0; i < tablinks.length; i++) {
            tablinks[i].classList.remove("active");
        }
        document.getElementById(tabName).classList.add("active");
        evt.currentTarget.classList.add("active");
    }

    // Chart Data Preparation from PHP
    const chartDataSets = {
        daily: {
            labels: <?php echo json_encode($chart_daily_labels); ?>,
            totals: <?php echo json_encode($chart_daily_totals); ?>
        },
        weekly: {
            labels: <?php echo json_encode($chart_weekly_labels); ?>,
            totals: <?php echo json_encode($chart_weekly_totals); ?>
        },
        monthly: {
            labels: <?php echo json_encode($chart_monthly_labels); ?>,
            totals: <?php echo json_encode($chart_monthly_totals); ?>
        }
    };

    let salesChart;

    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('revenueChart').getContext('2d');
        
        // Match the application dark accent theme
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(45, 55, 72, 0.35)');
        gradient.addColorStop(1, 'rgba(45, 55, 72, 0.0)');

        salesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartDataSets.daily.labels,
                datasets: [{
                    label: 'Revenue (₱)',
                    data: chartDataSets.daily.totals,
                    borderColor: '#2d3748',
                    borderWidth: 3,
                    backgroundColor: gradient,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#2d3748',
                    pointHoverRadius: 7,
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        padding: 12,
                        titleFont: { family: 'Plus Jakarta Sans', size: 13, weight: '700' },
                        bodyFont: { family: 'Plus Jakarta Sans', size: 14, weight: '600' },
                        callbacks: {
                            label: function(context) {
                                return ' Revenue: ₱' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' }, color: '#64748b' }
                    },
                    y: {
                        grid: { color: 'rgba(226, 232, 240, 0.7)' },
                        ticks: {
                            font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' },
                            color: '#64748b',
                            callback: function(value) { return '₱' + value.toLocaleString(); }
                        }
                    }
                }
            }
        });
    });

    function switchChart(period, btn) {
        document.querySelectorAll('.chart-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        if (salesChart && chartDataSets[period]) {
            salesChart.data.labels = chartDataSets[period].labels;
            salesChart.data.datasets[0].data = chartDataSets[period].totals;
            salesChart.update();
        }
    }

    // Exact Match Search for Numbers
    function filterLogTable() {
        var rawInput = document.getElementById('searchInput').value.toLowerCase().trim();
        var rows = document.querySelectorAll('#historyTableBody tr');

        var cleanInput = rawInput.replace('#', '');
        var isNumericSearch = cleanInput !== '' && !isNaN(cleanInput);

        rows.forEach(function(row) {
            if (row.id === 'noDataRow') return;

            var orderIdText = row.cells[0] ? row.cells[0].textContent.toLowerCase().trim() : '';
            var cleanOrderId = orderIdText.replace('#', '');
            var customer = row.cells[1] ? row.cells[1].textContent.toLowerCase() : '';
            var staff = row.cells[2] ? row.cells[2].textContent.toLowerCase() : '';

            if (rawInput === '') {
                row.style.display = '';
            } else if (isNumericSearch) {
                if (cleanOrderId === cleanInput) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            } else {
                if (customer.includes(rawInput) || staff.includes(rawInput)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        });
    }

    function clearSearch() {
        document.getElementById('searchInput').value = '';
        filterLogTable();
    }

    // AJAX Order Voiding
    function voidOrder(orderId) {
        if (!confirm('Are you sure you want to void order #' + orderId + '?')) {
            return;
        }

        var formData = new FormData();
        formData.append('action', 'void_order');
        formData.append('void_id', orderId);

        fetch('sales_monitoring.php', {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(result) {
            if (result.success) {
                var targetRow = document.getElementById('order-row-' + orderId);
                if (targetRow) {
                    targetRow.style.transition = 'opacity 0.4s ease';
                    targetRow.style.opacity = '0';
                    setTimeout(function() {
                        targetRow.remove();
                    }, 400);
                }
            } else {
                alert('Failed to void order: ' + (result.message || 'Unknown error'));
            }
        })
        .catch(function(error) {
            alert('Server error occurred while voiding the order.');
            console.error(error);
        });
    }
</script>

</body>
</html>