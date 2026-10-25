<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

$display_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? "User";
$user_role    = $_SESSION['role'] ?? 'Staff';

// Fetch Today's Orders
$order_count = 0;
$query = "SELECT COUNT(*) as total FROM orders WHERE DATE(order_date) = CURDATE()";
if ($stmt = mysqli_prepare($conn, $query)) {
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($res)) { $order_count = $row['total']; }
    mysqli_stmt_close($stmt);
}

// Fetch Low Stock Count (Optional Stat display)
$low_stock_count = 0;
$stock_query = "SELECT COUNT(*) as low_items FROM inventory WHERE quantity <= min_threshold";
if ($s_stmt = mysqli_prepare($conn, $stock_query)) {
    if (mysqli_stmt_execute($s_stmt)) {
        $s_res = mysqli_stmt_get_result($s_stmt);
        if ($s_row = mysqli_fetch_assoc($s_res)) { $low_stock_count = $s_row['low_items']; }
    }
    mysqli_stmt_close($s_stmt);
}

// Fetch Active In-Queue Count
$active_queue_count = 0;
$q_res = mysqli_query($conn, "SELECT COUNT(*) as active_q FROM orders WHERE LOWER(TRIM(order_status)) IN ('in process', 'in-process', 'in_process', 'preparing', 'pending', 'ready', 'ready for pickup')");
if ($q_res && $q_row = mysqli_fetch_assoc($q_res)) {
    $active_queue_count = (int)$q_row['active_q'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartCafe | Dashboard</title>
    <?php include_once __DIR__ . '/pwa_head.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-canvas: #e2e8f0;
            --glass-card: rgba(255, 255, 255, 0.45);
            --glass-card-hover: rgba(255, 255, 255, 0.75);
            --glass-border: rgba(255, 255, 255, 0.8);
            --glass-border-hover: rgba(255, 255, 255, 1);
            --glass-blur: blur(25px);
            --text-main: #0f172a;
            --text-muted: #475569;
            --border-radius: 22px;
            --transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body { 
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-canvas);
            background-image: 
                radial-gradient(at 10% 10%, #cbd5e1 0px, transparent 55%),
                radial-gradient(at 90% 90%, #94a3b8 0px, transparent 55%),
                radial-gradient(at 50% 50%, #f1f5f9 0px, transparent 100%);
            background-attachment: fixed;
            color: var(--text-main);
            line-height: 1.5;
            min-height: 100vh;
            padding: 20px 0 40px 0;
        }

        .dashboard-container { 
            width: 92%; 
            max-width: 1320px; 
            margin: 0 auto; 
        }

        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 20px 0 30px 0;
            gap: 16px;
        }

        .brand-logo {
            height: 80px;
            width: auto;
            object-fit: contain;
        }

        .brand-title {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 12px;
            letter-spacing: -0.02em;
        }

        .brand-subtitle {
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 4px;
        }

        .role-badge {
            background: rgba(15, 23, 42, 0.1);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .time-pill {
            background: rgba(255, 255, 255, 0.5);
            border: 1px solid var(--glass-border);
            backdrop-filter: var(--glass-blur);
            padding: 10px 18px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 13px;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn { 
            background: rgba(255, 255, 255, 0.5); 
            color: var(--text-main); 
            padding: 12px 24px; 
            text-decoration: none; 
            border-radius: 14px; 
            font-weight: 700; 
            font-size: 14px;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border: 1px solid var(--glass-border);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            white-space: nowrap;
        }

        .logout-btn:hover { 
            background: #0f172a !important; 
            color: #ffffff !important;
            border-color: #0f172a !important;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(15, 23, 42, 0.3);
        }

        .hero-banner { 
            background: var(--glass-card); 
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--glass-border);
            padding: 36px 44px; 
            border-radius: var(--border-radius); 
            margin-bottom: 32px; 
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 24px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.04);
        }

        .hero-banner::before {   
            display: none;
        }

        .hero-text h2 {
            font-size: 26px;
            font-weight: 800;
            color: var(--text-main);
            margin-bottom: 6px;
            letter-spacing: -0.01em;
        }

        .hero-text p {
            font-size: 16px;
            color: var(--text-muted);
            font-weight: 500;
            margin-bottom: 16px;
        }

        .quick-order-btn {
            background: #0f172a;
            color: #ffffff;
            padding: 12px 22px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }

        .quick-order-btn:hover {
            background: #1e293b;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.25);
        }

        .stats-group {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .hero-stat-card {
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            padding: 18px 28px;
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            text-align: center;
            min-width: 150px;
            box-shadow: inset 0 1px 2px rgba(255, 255, 255, 0.05), 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .hero-stat-card .stat-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #94a3b8;
            font-weight: 800;
            margin-bottom: 4px;
            display: block;
        }

        .hero-stat-card .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: #38bdf8;
            line-height: 1.1;
        }

        .hero-stat-card.warning .stat-value {
            color: #f87171;
        }

        .grid-container {   
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); 
            gap: 24px; 
        }

        .card-link { 
            text-decoration: none; 
            color: inherit; 
            display: block;
        }
        
        .card { 
            background: var(--glass-card); 
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--glass-border);
            padding: 28px; 
            border-radius: var(--border-radius); 
            height: 100%; 
            min-height: 160px;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.03);
        }

        .card:hover { 
            background: var(--glass-card-hover);
            border-color: var(--glass-border-hover);
            transform: translateY(-5px); 
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }

        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.65);
            border: 1px solid var(--glass-border);
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: var(--transition);
            flex-shrink: 0;
        }

        .card:hover .card-icon {
            background: #ffffff;
            color: #000000;
            box-shadow: 0 4px 15px rgba(255, 255, 255, 0.5);
        }

        .card h3 { 
            font-size: 18px; 
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.01em;
        }

        .feature-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: auto;
            padding-top: 10px;
        }

        .feature-tag {
            background: rgba(255, 255, 255, 0.45);
            color: var(--text-muted);
            font-size: 12px;
            padding: 6px 12px;
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            font-weight: 600;
        }

        .card:hover .feature-tag {
            background: rgba(255, 255, 255, 0.85);
            color: var(--text-main);
            border-color: #ffffff;
        }

        @media (max-width: 768px) {
            .header { flex-direction: column; align-items: flex-start; }
            .header-actions { width: 100%; justify-content: space-between; }
            .hero-banner { flex-direction: column; align-items: flex-start; padding: 24px; }
            .stats-group { width: 100%; }
            .hero-stat-card { flex: 1; }
            .grid-container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body> 

<div class="dashboard-container">
<div class="header">
    <div>
        <h1 class="brand-title">
            <img src="logo.png" alt="BesKape Logo" class="brand-logo">
            BesKape
        </h1>
        <p class="brand-subtitle">
            Point of Sale & Management Portal 
            <span class="role-badge"><?php echo htmlspecialchars($user_role); ?></span>
        </p>
    </div>
        <div class="header-actions">
            <div class="time-pill">
                <i class="fa-regular fa-clock"></i> <span id="live-clock">--:--:--</span>
            </div>
            <a href="logout.php" class="logout-btn">
                <i class="fa-solid fa-right-from-bracket"></i> Log Out
            </a>
        </div>
    </div>

    <div class="hero-banner">
        <div class="hero-text">
            <h2>Welcome back, <?php echo htmlspecialchars($display_name); ?>!</h2>
            <p>Select a portal module below to begin daily operations.</p>
            <?php if (in_array($user_role, ['Admin', 'Manager', 'Staff'])): ?>
                <a href="order_management.php" class="quick-order-btn">
                    <i class="fa-solid fa-bolt"></i> Launch POS Cashier
                </a>
            <?php endif; ?>
        </div>
        <div class="stats-group">
            <div class="hero-stat-card">
                <span class="stat-label">Orders Today</span>
                <span class="stat-value"><?php echo $order_count; ?></span>
            </div>
            <div class="hero-stat-card">
                <span class="stat-label">In Kitchen Queue</span>
                <span class="stat-value" style="color: #0284c7;"><?php echo $active_queue_count; ?></span>
            </div>
            <?php if (in_array($user_role, ['Admin', 'Manager'])): ?>
            <div class="hero-stat-card <?php echo $low_stock_count > 0 ? 'warning' : ''; ?>">
                <span class="stat-label">Low Stock Items</span>
                <span class="stat-value"><?php echo $low_stock_count; ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid-container">
        <?php if (in_array($user_role, ['Admin', 'Manager', 'Staff'])): ?>
        <a href="order_management.php" class="card-link">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon"><i class="fa-solid fa-cash-register"></i></div>
                    <h3>Order Management</h3>
                </div>
                <div class="feature-tags">
                    <span class="feature-tag">POS Cashier</span>
                    <span class="feature-tag">Queue Ticket #</span>
                    <span class="feature-tag">Transactions</span>
                </div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (in_array($user_role, ['Admin', 'Manager', 'Staff'])): ?>
        <a href="kds.php" class="card-link">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon"><i class="fa-solid fa-kitchen-set"></i></div>
                    <h3>Kitchen Display (KDS)</h3>
                </div>
                <div class="feature-tags">
                    <span class="feature-tag">Live Order Tickets</span>
                    <span class="feature-tag">Order Numbering</span>
                    <span class="feature-tag">Status Controls</span>
                </div>
            </div>
        </a>
        <?php endif; ?>

        <a href="menu_management.php" class="card-link">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon"><i class="fa-solid fa-utensils"></i></div>
                    <h3>Menu Management</h3>
                </div>
                <div class="feature-tags">
                    <span class="feature-tag">Item Pricing</span>
                    <span class="feature-tag">Availability</span>
                </div>
            </div>
        </a>

        <?php if (in_array($user_role, ['Admin', 'Manager'])): ?>
        <a href="inventory_management.php" class="card-link">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                    <h3>Inventory Management</h3>
                </div>
                <div class="feature-tags">
                    <span class="feature-tag">Raw Stock</span>
                    <span class="feature-tag">Low Stock Alerts</span>
                </div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (in_array($user_role, ['Admin', 'Manager'])): ?>
        <a href="sales_monitoring.php" class="card-link">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon"><i class="fa-solid fa-chart-line"></i></div>
                    <h3>Sales Monitoring</h3>
                </div>
                <div class="feature-tags">
                    <span class="feature-tag">Revenue Logs</span>
                    <span class="feature-tag">Analytics</span>
                </div>
            </div>
        </a>
        <?php endif; ?>

        <a href="customer_records.php" class="card-link">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon"><i class="fa-solid fa-users"></i></div>
                    <h3>Customer Order Records</h3>
                </div>
                <div class="feature-tags">
                    <span class="feature-tag">Order History</span>
                    <span class="feature-tag">Customer Logs</span>
                </div>
            </div>
        </a>

        <?php if ($user_role === 'Admin'): ?>
        <a href="user_management.php" class="card-link">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon"><i class="fa-solid fa-user-gear"></i></div>
                    <h3>User Management</h3>
                </div>
                <div class="feature-tags">
                    <span class="feature-tag">Manage Staff</span>
                    <span class="feature-tag">Roles & Access</span>
                </div>
            </div>
        </a>
        <?php endif; ?>

        <?php if (in_array($user_role, ['Admin', 'Manager'])): ?>
        <a href="staff_tracking.php" class="card-link">
            <div class="card">
                <div class="card-header">
                    <div class="card-icon"><i class="fa-solid fa-user-clock"></i></div>
                    <h3>Staff Tracking</h3>
                </div>
                <div class="feature-tags">
                    <span class="feature-tag">Shift Logs</span>
                    <span class="feature-tag">Activity Tracker</span>
                </div>
            </div>
        </a>
        <?php endif; ?>
    </div>
</div>

<script>
    function updateClock() {
        const now = new Date();
        const timeString = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        document.getElementById('live-clock').textContent = timeString;
    }
    setInterval(updateClock, 1000);
    updateClock();
</script>

</body>
</html>