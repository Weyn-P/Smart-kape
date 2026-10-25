<?php
session_start();
require_once 'config.php';

// Auth Guard
if (!isset($_SESSION['username'])) { 
    header("Location: index.php"); 
    exit(); 
}

// Fetch Orders and Group Item Names safely using a non-HTML delimiter
$query = "SELECT 
            orders.id, 
            orders.queue_number,
            orders.customer_name,
            orders.order_type,
            orders.order_date, 
            orders.total_amount, 
            GROUP_CONCAT(CONCAT(order_items.quantity, 'x ', order_items.menu_item_name) SEPARATOR ' ||| ') AS items_list,
            SUM(order_items.quantity) AS total_items_count
          FROM orders 
          LEFT JOIN order_items ON orders.id = order_items.order_id 
          GROUP BY orders.id, orders.queue_number, orders.customer_name, orders.order_type, orders.order_date, orders.total_amount
          ORDER BY orders.order_date DESC";

$records = mysqli_query($conn, $query);

// Summary Data Calculations
$summary_query = "SELECT 
                    COUNT(id) AS total_orders, 
                    COALESCE(SUM(total_amount), 0) AS total_revenue 
                  FROM orders";
$summary_res = mysqli_query($conn, $summary_query);
$summary = mysqli_fetch_assoc($summary_res);

$total_orders = $summary['total_orders'] ?? 0;
$total_revenue = $summary['total_revenue'] ?? 0;
$avg_order_val = $total_orders > 0 ? ($total_revenue / $total_orders) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartCafe | Customer Records</title>
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

        .rec-container {
            max-width: 1250px;
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

        /* Dark Back Button */
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
            background: var(--dark-btn);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 4px 12px rgba(45, 55, 72, 0.2);
        }

        .btn-back:hover {
            background: var(--dark-btn-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(45, 55, 72, 0.3);
            color: #ffffff;
        }

        /* Summary Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--glass-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 20px 24px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: rgba(30, 41, 59, 0.08);
            color: #1e293b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-info h4 {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            font-weight: 700;
        }

        .stat-info p {
            font-size: 22px;
            font-weight: 800;
            color: var(--text-dark);
            margin-top: 2px;
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
            gap: 10px;
            border-bottom: 1px solid #334155;
            flex-wrap: wrap;
        }

        .card-header-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Search Filter Input */
        .search-box {
            display: flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 12px;
            padding: 6px 14px;
            gap: 8px;
            width: 280px;
        }

        .search-box i {
            color: #94a3b8;
            font-size: 13px;
        }

        .search-box input {
            border: none;
            background: transparent;
            outline: none;
            color: #ffffff;
            font-family: inherit;
            font-size: 13px;
            font-weight: 500;
            width: 100%;
        }

        .search-box input::placeholder {
            color: #94a3b8;
        }

        /* Table Wrapper */
        .table-wrapper {
            overflow-x: auto;
        }

        /* Modern Table */
        .rec-table { 
            width: 100%; 
            border-collapse: collapse; 
        }

        .rec-table th, .rec-table td { 
            padding: 18px 28px; 
            text-align: left; 
            vertical-align: middle;
        }

        .rec-table th { 
            background: rgba(241, 245, 249, 0.6); 
            color: var(--text-muted); 
            font-size: 12px; 
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700; 
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }

        .rec-table td { 
            border-bottom: 1px solid rgba(226, 232, 240, 0.5); 
            font-size: 14px; 
            font-weight: 500;
        }

        .rec-table tr:last-child td {
            border-bottom: none;
        }

        .rec-table tr:hover td {
            background: rgba(255, 255, 255, 0.4);
        }

        .order-badge {
            display: inline-block;
            background: rgba(30, 41, 59, 0.08);
            border: 1px solid rgba(30, 41, 59, 0.15);
            color: var(--text-dark);
            padding: 4px 10px;
            border-radius: 8px;
            font-weight: 800;
            font-size: 13px;
        }

        .date-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .date-main {
            font-weight: 700;
            color: var(--text-dark);
        }

        .total-cell { 
            font-weight: 800; 
            color: #16a34a; 
            font-size: 15px;
        }

        /* Item Tag Formatting */
        .items-flex {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .item-tag {
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(203, 213, 225, 0.8);
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-dark);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
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
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .search-box {
                width: 100%;
                margin-top: 10px;
                flex-direction: column;
            }
            .card-box {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border-radius: 18px;
            }
            .rec-table th, .rec-table td {
                padding: 12px 10px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>

<div class="rec-container">
    
    <!-- Glass Header -->
    <div class="header">
        <div class="header-title">
            <h1><i class="fa-solid fa-folder-open"></i> Customer Order Records</h1>
            <p>View complete purchase history and itemized logs</p>
        </div>
        <div>
            <a href="dashboard.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        </div>
    </div>

    <!-- Metrics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
            <div class="stat-info">
                <h4>Total Orders</h4>
                <p><?php echo number_format($total_orders); ?></p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-peso-sign"></i></div>
            <div class="stat-info">
                <h4>Total Sales</h4>
                <p>₱<?php echo number_format((float)$total_revenue, 2); ?></p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fa-solid fa-chart-line"></i></div>
            <div class="stat-info">
                <h4>Avg. Order Value</h4>
                <p>₱<?php echo number_format((float)$avg_order_val, 2); ?></p>
            </div>
        </div>
    </div>

    <!-- Customer Records Table Card -->
    <div class="card-box">
        <div class="card-header">
            <div class="card-header-title">
                <i class="fa-solid fa-clock-rotate-left"></i> Transaction History
            </div>
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="orderSearch" onkeyup="filterOrders()" placeholder="Search Order ID, Item, or Date...">
            </div>
        </div>

        <div class="table-wrapper">
            <table class="rec-table" id="ordersTable">
                <thead>
                    <tr>
                        <th>Queue #</th>
                        <th>Order ID</th>
                        <th>Customer / Type</th>
                        <th>Date & Time</th>
                        <th>Items Purchased</th>
                        <th>Total Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($records && mysqli_num_rows($records) > 0): ?>
                        <?php while($row = mysqli_fetch_assoc($records)): 
                            $q_num = (int)($row['queue_number'] ?? $row['id']);
                            $q_tag = '#' . str_pad($q_num > 0 ? $q_num : 1, 3, '0', STR_PAD_LEFT);
                            $cust_name = htmlspecialchars(!empty($row['customer_name']) ? $row['customer_name'] : 'Guest');
                            $ord_type = htmlspecialchars(!empty($row['order_type']) ? $row['order_type'] : 'Dine In');
                        ?>
                            <tr class="order-row">
                                <td>
                                    <span style="font-family: 'Space Mono', monospace; font-weight: 800; font-size: 13px; background: rgba(15, 23, 42, 0.08); padding: 4px 8px; border-radius: 6px;"><?php echo $q_tag; ?></span>
                                </td>
                                <td>
                                    <span class="order-badge">#<?php echo (int)$row['id']; ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 700; font-size: 13px; color: var(--text-dark);"><?php echo $cust_name; ?></div>
                                    <span style="font-size: 11px; font-weight: 600; color: #0284c7; text-transform: uppercase;"><?php echo $ord_type; ?></span>
                                </td>
                                <td>
                                    <div class="date-text">
                                        <span class="date-main">
                                            <?php 
                                             $date_timestamp = strtotime($row['order_date']);
                                            echo $date_timestamp ? date('M d, Y', $date_timestamp) : 'N/A'; 
                                            ?>
                                        </span>
                                        <span style="font-size: 12px; color: var(--text-muted);">
                                            <?php echo $date_timestamp ? date('h:i A', $date_timestamp) : ''; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="items-flex">
                                        <?php 
                                        if (!empty($row['items_list'])) {
                                            $items_array = explode(' ||| ', $row['items_list']);
                                            foreach ($items_array as $single_item) {
                                                echo '<span class="item-tag">' . htmlspecialchars($single_item) . '</span>';
                                            }
                                        } else {
                                            echo '<span style="color: var(--text-muted); font-style:italic;">No items recorded</span>';
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td class="total-cell">₱<?php echo number_format((float)$row['total_amount'], 2); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding: 35px; color: var(--text-muted); font-style: italic;">
                                No customer records found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function filterOrders() {
    const input = document.getElementById("orderSearch");
    const filter = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll("#ordersTable .order-row");

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(filter)) {
            row.style.display = "";
        } else {
            row.style.display = "none";
        }
    });
}
</script>

</body>
</html>