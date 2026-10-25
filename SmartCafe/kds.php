<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

$display_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? "Kitchen Staff";

// 1. Auto-detect status column name in 'orders' table ('order_status' vs 'status')
$status_col = 'order_status';
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM orders LIKE 'order_status'");
if (!$col_check || mysqli_num_rows($col_check) === 0) {
    $status_col = 'status';
}

// 2. Auto-detect item name column in 'order_items' table ('menu_item_name', 'item_name', or 'product_name')
$item_name_col = 'menu_item_name';
$item_check = mysqli_query($conn, "SHOW COLUMNS FROM order_items LIKE 'menu_item_name'");
if (!$item_check || mysqli_num_rows($item_check) === 0) {
    $item_check2 = mysqli_query($conn, "SHOW COLUMNS FROM order_items LIKE 'item_name'");
    $item_name_col = ($item_check2 && mysqli_num_rows($item_check2) > 0) ? 'item_name' : 'product_name';
}

// 3. Handle Status Updates from Kitchen Screen (Supports both AJAX and standard POST)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['update_status'])) {
    $order_id = intval($_POST['order_id']);
    $new_status = mysqli_real_escape_string($conn, $_POST['new_status']);

    $update_query = "UPDATE orders SET `$status_col` = '$new_status' WHERE id = '$order_id'";
    $success = mysqli_query($conn, $update_query);

    // If request comes from JavaScript Fetch API, return JSON response without page reload
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => (bool)$success, 'order_id' => $order_id, 'new_status' => $new_status]);
        exit();
    }

    // Fallback for standard form submits
    header("Location: kds.php");
    exit();
}

// 4. Query Today's Next Queue Number
$next_q_res = mysqli_query($conn, "SELECT COALESCE(MAX(queue_number), 0) + 1 AS next_queue FROM orders WHERE DATE(order_date) = CURDATE()");
$next_queue_num = 1;
if ($next_q_res && $nq_row = mysqli_fetch_assoc($next_q_res)) {
    $next_queue_num = (int)$nq_row['next_queue'];
}
$next_queue_tag = '#' . str_pad($next_queue_num, 3, '0', STR_PAD_LEFT);

// 5. Query Active Kitchen Orders
$orders_query = "
    SELECT id, queue_number, customer_name, order_type, order_date, 
        COALESCE(staff_username, 'admin') AS staff_username, 
        total_amount, 
        `$status_col` AS order_status 
    FROM orders 
    WHERE LOWER(TRIM(`$status_col`)) IN ('in process', 'in-process', 'in_process', 'preparing', 'pending', 'ready', 'ready for pickup', 'ready_for_pickup') 
    ORDER BY 
        CASE 
            WHEN LOWER(TRIM(`$status_col`)) = 'preparing' THEN 0
            WHEN LOWER(TRIM(`$status_col`)) IN ('ready', 'ready for pickup', 'ready_for_pickup') THEN 1
            ELSE 2
        END ASC,
        order_date ASC";

$orders_result = mysqli_query($conn, $orders_query);

if (!$orders_result) {
    die("<div style='color:#ef4444; background:rgba(255,255,255,0.8); backdrop-filter:blur(10px); padding:20px; font-family:sans-serif; border-radius:16px; margin:20px;'>
            <h3 style='margin-top:0;'>Database Query Failed</h3>
            <p><strong>Error:</strong> " . mysqli_error($conn) . "</p>
        </div>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartCafe | Kitchen Display System</title>
    <?php include_once __DIR__ . '/pwa_head.php'; ?>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #dce4ed 0%, #c8d5e3 50%, #e2e9f0 100%);
            background-attachment: fixed;
            color: #1e293b;
            font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            margin: 0;
            padding: 30px;
            min-height: 100vh;
        }

        .kds-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Top Header Navigation */
        .kds-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.45);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: 20px;
            padding: 20px 28px;
            margin-bottom: 28px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.03);
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .kds-header h1 {
            color: #0f172a;
            margin: 0;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .role-badge {
            background: rgba(203, 213, 225, 0.6);
            color: #475569;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-queue-next {
            background: rgba(16, 185, 129, 0.15);
            color: #047857;
            border: 1px solid rgba(16, 185, 129, 0.3);
            font-family: Consolas, 'Courier New', monospace;
            font-size: 12px;
        }

        .user-info {
            color: #64748b;
            font-size: 13px;
            margin-top: 4px;
        }

        /* Fixed Navigation Styles */
        .nav-links {
            display: flex;
            gap: 10px;
        }

        .nav-links a {
            color: #334155;
            text-decoration: none;
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            letter-spacing: -0.2px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            display: inline-flex;
            align-items: center;
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.9);
            color: #0f172a;
            border-color: rgba(255, 255, 255, 1);
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
        }

        /* Glassmorphic Red Logout Button */
        .nav-links a.btn-logout {
            background: rgba(239, 68, 68, 0.1);
            color: #dc2626;
            border-color: rgba(239, 68, 68, 0.25);
        }

        .nav-links a.btn-logout:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #b91c1c;
            border-color: rgba(239, 68, 68, 0.4);
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(239, 68, 68, 0.15);
        }

        .kds-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
            gap: 22px;
        }

        /* Glassmorphism Ticket Card */
        .ticket-card {
            background: rgba(255, 255, 255, 0.55);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 12px 32px rgba(31, 38, 135, 0.05);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.3s ease;
        }

        .ticket-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 36px rgba(31, 38, 135, 0.08);
        }

        .ticket-header {
            padding: 18px 20px 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .ticket-id {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
        }

        .ticket-id-sub {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            margin-left: 6px;
        }

        .ticket-details {
            padding: 0 20px 14px 20px;
            font-size: 12px;
            color: #64748b;
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid rgba(226, 232, 240, 0.6);
        }

        /* Items List */
        .ticket-items {
            list-style: none;
            padding: 16px 20px;
            margin: 0;
            flex-grow: 1;
        }

        .ticket-items li {
            padding: 10px 0;
            border-bottom: 1px dashed rgba(203, 213, 225, 0.5);
            font-size: 15px;
            font-weight: 600;
            color: #334155;
            display: flex;
            align-items: center;
        }

        .ticket-items li:last-child {
            border-bottom: none;
        }

        .item-qty {
            font-weight: 800;
            font-size: 14px;
            color: #0f172a;
            background: rgba(255, 255, 255, 0.8);
            padding: 4px 10px;
            border-radius: 12px;
            margin-right: 12px;
            border: 1px solid rgba(255, 255, 255, 0.9);
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
        }

        /* Actions Footer */
        .ticket-actions {
            padding: 16px 20px;
            background: rgba(255, 255, 255, 0.3);
            border-top: 1px solid rgba(255, 255, 255, 0.6);
        }

        .btn-status {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 14px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            letter-spacing: 0.3px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .btn-status:active {
            transform: scale(0.97);
        }

        .btn-start {
            background: #1e293b;
            color: #ffffff;
        }

        .btn-start:hover {
            background: #0f172a;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.25);
        }

        .btn-ready {
            background: #10b981;
            color: #ffffff;
        }

        .btn-ready:hover {
            background: #059669;
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
        }

        /* Pill Badges */
        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.9);
        }

        .badge-in-process, .badge-in_process, .badge-pending { 
            color: #d97706; 
            background: rgba(254, 243, 199, 0.8);
            border-color: rgba(253, 230, 138, 0.9);
        }

        .badge-preparing { 
            color: #0284c7; 
            background: rgba(224, 242, 254, 0.8);
            border-color: rgba(186, 230, 253, 0.9);
        }

        .badge-ready, .badge-ready-for-pickup, .badge-ready_for_pickup {
            color: #047857; 
            background: rgba(209, 250, 229, 0.8);
            border-color: rgba(167, 243, 208, 0.9);
        }

        /* Empty State Screen */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 80px 20px;
            background: rgba(255, 255, 255, 0.45);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.7);
            color: #64748b;
        }

        .empty-state h2 {
            margin-top: 0;
            color: #0f172a;
            font-size: 22px;
            font-weight: 700;
        }

        @media (max-width: 1024px) {
            body { padding: 18px 12px; }
            .kds-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 16px;
            }
        }

        @media (max-width: 768px) {
            body { padding: 12px 6px; }
            .kds-header {
                flex-direction: column;
                align-items: stretch;
                padding: 16px;
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
                padding: 8px 12px;
                font-size: 13px;
            }
            .kds-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="kds-container">
    <div class="kds-header">
        <div>
            <div class="header-brand">
                <h1>Kitchen Display System</h1>
                <span class="role-badge">KDS</span>
                <span class="role-badge badge-queue-next">Next: <?php echo $next_queue_tag; ?></span>
            </div>
            <div class="user-info">Logged in as: <strong><?php echo htmlspecialchars($display_name); ?></strong></div>
        </div>
        <div class="nav-links">
            <a href="order_management.php">Order Management</a>
            <a href="dashboard.php">Dashboard</a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </div>

    <div class="kds-grid" id="kds-grid">
        <?php if ($orders_result && mysqli_num_rows($orders_result) > 0): ?>
            <?php while ($order = mysqli_fetch_assoc($orders_result)): ?>
                <?php 
                    $order_id = (int)$order['id'];
                    $raw_status = $order['order_status'];
                    $status_slug = strtolower(str_replace([' ', '_'], '-', trim($raw_status)));
                    $q_num = !empty($order['queue_number']) ? (int)$order['queue_number'] : $order_id;
                    $queue_tag = '#' . str_pad($q_num, 3, '0', STR_PAD_LEFT);
                ?>
                <div class="ticket-card <?php echo htmlspecialchars($status_slug); ?>" data-order-id="<?php echo $order_id; ?>">
                    <div>
                        <div class="ticket-header">
                            <div style="display: flex; align-items: baseline; gap: 8px;">
                                <span class="ticket-id">Order <?php echo $queue_tag; ?></span>
                                <span class="ticket-id-sub">(#<?php echo $order_id; ?>)</span>
                            </div>
                            <span class="badge badge-<?php echo htmlspecialchars($status_slug); ?>">
                                <?php echo htmlspecialchars($raw_status); ?>
                            </span>
                        </div>

                        <div class="ticket-details">
                            <span>
                                <strong>Staff:</strong> <?php echo htmlspecialchars($order['staff_username']); ?>
                                <?php if (!empty($order['customer_name'])): ?> &bull; <strong>Cust:</strong> <?php echo htmlspecialchars($order['customer_name']); ?><?php endif; ?>
                                <?php if (!empty($order['order_type'])): ?> <span style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase;">(<?php echo htmlspecialchars($order['order_type']); ?>)</span><?php endif; ?>
                            </span>
                            <span><strong>Time:</strong> <?php echo date('h:i A', strtotime($order['order_date'])); ?></span>
                        </div>

                        <ul class="ticket-items">
                            <?php
                            $items_query = "SELECT `$item_name_col` AS item_name, quantity FROM order_items WHERE order_id = '$order_id'";
                            $items_result = mysqli_query($conn, $items_query);

                            if ($items_result && mysqli_num_rows($items_result) > 0):
                                while ($item = mysqli_fetch_assoc($items_result)):
                            ?>
                                <li>
                                    <span class="item-qty"><?php echo (int)$item['quantity']; ?>x</span>
                                    <span><?php echo htmlspecialchars($item['item_name']); ?></span>
                                </li>
                            <?php 
                                endwhile;
                            else:
                                echo "<li style='color:#94a3b8; font-style:italic;'>No items found in order</li>";
                            endif; 
                            ?>
                        </ul>
                    </div>

                    <div class="ticket-actions">
                        <form method="POST" action="kds.php" class="kds-status-form">
                            <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                            <input type="hidden" name="update_status" value="1">

                            <?php if (strtolower(trim($raw_status)) === 'preparing' || strtolower(trim($raw_status)) === 'ready' || strtolower(trim($raw_status)) === 'ready for pickup'): ?>
                                <input type="hidden" name="new_status" value="Completed">
                                <button type="submit" class="btn-status btn-ready">Mark Completed</button>
                            <?php else: ?>
                                <input type="hidden" name="new_status" value="Preparing">
                                <button type="submit" class="btn-status btn-start">Start Preparing</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <h2>No Active Kitchen Orders</h2>
                <p>New orders placed in Order Management will automatically appear here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const grid = document.getElementById('kds-grid');

    grid.addEventListener('submit', async (e) => {
        if (!e.target.classList.contains('kds-status-form')) return;
        
        e.preventDefault(); // Prevents full page reload

        const form = e.target;
        const formData = new FormData(form);
        const card = form.closest('.ticket-card');

        try {
            const response = await fetch('kds.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                const badge = card.querySelector('.badge');
                const newStatus = data.new_status;

                if (newStatus.toLowerCase() === 'preparing') {
                    // Update badge text and style dynamically
                    badge.textContent = 'Preparing';
                    badge.className = 'badge badge-preparing';
                    
                    // Toggle form inputs to prepare for "Completed" action
                    form.querySelector('input[name="new_status"]').value = 'Completed';
                    const btn = form.querySelector('button');
                    btn.className = 'btn-status btn-ready';
                    btn.textContent = 'Mark Completed';
                } else if (newStatus.toLowerCase() === 'completed') {
                    // Smoothly animate out completed card without reloading
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.9)';
                    setTimeout(() => {
                        card.remove();
                        // Show empty state if no active cards remain
                        if (grid.querySelectorAll('.ticket-card').length === 0) {
                            grid.innerHTML = `
                                <div class="empty-state">
                                    <h2>No Active Kitchen Orders</h2>
                                    <p>New orders placed in Order Management will automatically appear here.</p>
                                </div>`;
                        }
                    }, 300);
                }
            }
        } catch (err) {
            console.error('Failed to update status:', err);
        }
    });

    // Real-time Polling for new orders
    setInterval(async () => {
        try {
            const res = await fetch('api_get_orders.php?type=kds');
            const data = await res.json();
            
            if (data.orders) {
                updateKDSGrid(data.orders);
            }
        } catch (err) {
            console.error('Failed to fetch real-time orders:', err);
        }
    }, 4000); // Check every 4 seconds

    function updateKDSGrid(orders) {
        if (orders.length === 0) {
            if (grid.querySelectorAll('.ticket-card').length !== 0) {
                grid.innerHTML = `
                    <div class="empty-state">
                        <h2>No Active Kitchen Orders</h2>
                        <p>New orders placed in Order Management will automatically appear here.</p>
                    </div>`;
            }
            return;
        }

        // Get existing cards state (ID + Status)
        const existingCards = Array.from(grid.querySelectorAll('.ticket-card'));
        const existingState = existingCards.map(c => {
            const id = c.getAttribute('data-order-id');
            const badge = c.querySelector('.badge');
            const status = badge ? badge.textContent.trim() : '';
            return id + '_' + status;
        }).join('|');
        
        let newStateArray = [];
        let newHtml = '';

        orders.forEach(order => {
            const rawStatus = order.order_status;
            const statusSlug = rawStatus.trim().toLowerCase().replace(/[\s_]+/g, '-');
            const qNum = order.queue_number ? '#' + String(order.queue_number).padStart(3, '0') : (order.queue_tag || '#' + String(order.id).padStart(3, '0'));
            
            newStateArray.push(order.id + '_' + rawStatus.trim());

            // Format time
            const dateObj = new Date(order.order_date.replace(/-/g, "/"));
            const timeString = isNaN(dateObj.getTime()) ? '' : dateObj.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });

            let itemsHtml = '';
            if (order.items && order.items.length > 0) {
                order.items.forEach(item => {
                    itemsHtml += `
                        <li>
                            <span class="item-qty">${parseInt(item.quantity)}x</span>
                            <span>${escapeHtml(item.item_name)}</span>
                        </li>`;
                });
            } else {
                itemsHtml = `<li style='color:#94a3b8; font-style:italic;'>No items found in order</li>`;
            }

            let actionsHtml = '';
            if (rawStatus.trim().toLowerCase() === 'preparing' || rawStatus.trim().toLowerCase() === 'ready' || rawStatus.trim().toLowerCase() === 'ready for pickup') {
                actionsHtml = `
                    <input type="hidden" name="new_status" value="Completed">
                    <button type="submit" class="btn-status btn-ready">Mark Completed</button>`;
            } else {
                actionsHtml = `
                    <input type="hidden" name="new_status" value="Preparing">
                    <button type="submit" class="btn-status btn-start">Start Preparing</button>`;
            }

            let custDetails = '';
            if (order.customer_name && order.customer_name !== 'Guest') {
                custDetails += ` &bull; <strong>Cust:</strong> ${escapeHtml(order.customer_name)}`;
            }
            if (order.order_type) {
                custDetails += ` <span style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase;">(${escapeHtml(order.order_type)})</span>`;
            }

            const cardHtml = `
                <div class="ticket-card ${statusSlug}" data-order-id="${order.id}">
                    <div>
                        <div class="ticket-header">
                            <div style="display: flex; align-items: baseline; gap: 8px;">
                                <span class="ticket-id">Order ${qNum}</span>
                                <span class="ticket-id-sub">(#${order.id})</span>
                            </div>
                            <span class="badge badge-${statusSlug}">${escapeHtml(rawStatus)}</span>
                        </div>

                        <div class="ticket-details">
                            <span><strong>Staff:</strong> ${escapeHtml(order.staff_username)}${custDetails}</span>
                            <span><strong>Time:</strong> ${timeString}</span>
                        </div>

                        <ul class="ticket-items">
                            ${itemsHtml}
                        </ul>
                    </div>

                    <div class="ticket-actions">
                        <form method="POST" action="kds.php" class="kds-status-form">
                            <input type="hidden" name="order_id" value="${order.id}">
                            <input type="hidden" name="update_status" value="1">
                            ${actionsHtml}
                        </form>
                    </div>
                </div>`;
            
            newHtml += cardHtml;
        });

        const newState = newStateArray.join('|');

        // Only update DOM if there are actual changes
        if (existingState !== newState) {
            grid.innerHTML = newHtml;
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.toString().replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }
});
</script>

</body>
</html>