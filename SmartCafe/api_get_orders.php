<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';

header('Content-Type: application/json');

$type = $_GET['type'] ?? 'kds';

// Allow public access for KDS, queue boards, status board, and next queue counter
$public_types = ['kds', 'queue', 'next_queue', 'status_board'];
if (!in_array($type, $public_types, true) && !isset($_SESSION['username'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Auto-detect status column name
$status_col = 'order_status';
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM orders LIKE 'order_status'");
if (!$col_check || mysqli_num_rows($col_check) === 0) {
    $status_col = 'status';
}

// Handle Status Updates (Staff Only)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (!isset($_SESSION['username'])) {
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    $order_id = intval($_POST['order_id'] ?? 0);
    $new_status = mysqli_real_escape_string($conn, trim($_POST['new_status'] ?? ''));
    if ($order_id > 0 && !empty($new_status)) {
        $update = mysqli_query($conn, "UPDATE orders SET `$status_col` = '$new_status' WHERE id = '$order_id'");
        echo json_encode(['success' => (bool)$update, 'order_id' => $order_id, 'new_status' => $new_status]);
        exit();
    }
    echo json_encode(['error' => 'Invalid parameters']);
    exit();
}

// Helper to format queue tag (#001, #002)
function formatApiQueueTag($num) {
    $n = (int)$num;
    return '#' . str_pad($n > 0 ? $n : 1, 3, '0', STR_PAD_LEFT);
}

// 1. Next Queue Number API
if ($type === 'next_queue') {
    $nq_res = mysqli_query($conn, "SELECT COALESCE(MAX(queue_number), 0) + 1 AS next_queue FROM orders WHERE DATE(order_date) = CURDATE()");
    $next_q = 1;
    if ($nq_res && $nq_row = mysqli_fetch_assoc($nq_res)) {
        $next_q = (int)$nq_row['next_queue'];
    }
    echo json_encode([
        'next_queue_number' => $next_q,
        'next_queue_tag' => formatApiQueueTag($next_q)
    ]);
    exit();
}

// 2. KDS, Queue, and Status Board Orders API
if ($type === 'kds' || $type === 'queue' || $type === 'status_board') {
    // Auto-detect item name column
    $item_name_col = 'menu_item_name';
    $item_check = mysqli_query($conn, "SHOW COLUMNS FROM order_items LIKE 'menu_item_name'");
    if (!$item_check || mysqli_num_rows($item_check) === 0) {
        $item_check2 = mysqli_query($conn, "SHOW COLUMNS FROM order_items LIKE 'item_name'");
        $item_name_col = ($item_check2 && mysqli_num_rows($item_check2) > 0) ? 'item_name' : 'product_name';
    }

    $orders_query = "
        SELECT id, queue_number, customer_name, order_type, order_date, 
            COALESCE(staff_username, 'admin') AS staff_username, 
            total_amount, 
            `$status_col` AS order_status 
        FROM orders 
        WHERE LOWER(TRIM(`$status_col`)) IN ('in process', 'in-process', 'in_process', 'preparing', 'pending', 'ready', 'ready for pickup', 'ready_for_pickup', 'picked up', 'picked_up', 'pickedup') 
        ORDER BY 
            CASE 
                WHEN LOWER(TRIM(`$status_col`)) IN ('ready', 'ready for pickup', 'ready_for_pickup', 'picked up', 'picked_up', 'pickedup') THEN 0
                WHEN LOWER(TRIM(`$status_col`)) = 'preparing' THEN 1
                ELSE 2
            END ASC,
            order_date ASC";

    $orders_result = mysqli_query($conn, $orders_query);
    $active_orders = [];
    $preparing_orders = [];
    $ready_orders = [];

    if ($orders_result) {
        while ($order = mysqli_fetch_assoc($orders_result)) {
            $order_id = $order['id'];
            $order['queue_tag'] = formatApiQueueTag($order['queue_number'] ?? $order['id']);
            $order['customer_name'] = !empty($order['customer_name']) ? $order['customer_name'] : 'Guest';
            $order['order_type'] = !empty($order['order_type']) ? $order['order_type'] : 'Dine In';

            $items = [];
            $items_query = "SELECT `$item_name_col` AS item_name, quantity FROM order_items WHERE order_id = '$order_id'";
            $items_result = mysqli_query($conn, $items_query);
            if ($items_result) {
                while ($item = mysqli_fetch_assoc($items_result)) {
                    $items[] = $item;
                }
            }
            $order['items'] = $items;
            $active_orders[] = $order;

            $status_clean = strtolower(trim($order['order_status']));
            if (in_array($status_clean, ['ready', 'ready for pickup', 'ready_for_pickup', 'picked up', 'picked_up', 'pickedup'], true)) {
                $ready_orders[] = $order;
            } elseif ($status_clean === 'preparing') {
                $preparing_orders[] = $order;
            }
        }
    }

    // Recently completed orders for ticker (today only)
    $completed_orders = [];
    $comp_query = "
        SELECT id, queue_number, customer_name, order_type, order_date, 
            COALESCE(staff_username, 'admin') AS staff_username, 
            total_amount, 
            `$status_col` AS order_status 
        FROM orders 
        WHERE LOWER(TRIM(`$status_col`)) = 'completed' AND DATE(order_date) = CURDATE() 
        ORDER BY order_date DESC 
        LIMIT 10";
    $comp_res = mysqli_query($conn, $comp_query);
    if ($comp_res) {
        while ($cord = mysqli_fetch_assoc($comp_res)) {
            $cord['queue_tag'] = formatApiQueueTag($cord['queue_number'] ?? $cord['id']);
            $cord['customer_name'] = !empty($cord['customer_name']) ? $cord['customer_name'] : 'Guest';
            $cord['order_type'] = !empty($cord['order_type']) ? $cord['order_type'] : 'Dine In';
            $completed_orders[] = $cord;
        }
    }

    // Total completed today count
    $comp_count_res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM orders WHERE LOWER(TRIM(`$status_col`)) = 'completed' AND DATE(order_date) = CURDATE()");
    $comp_count = 0;
    if ($comp_count_res && $c_row = mysqli_fetch_assoc($comp_count_res)) {
        $comp_count = (int)$c_row['total'];
    }

    echo json_encode([
        'orders' => $active_orders,
        'preparing' => $preparing_orders,
        'ready' => $ready_orders,
        'completed' => $completed_orders,
        'counts' => [
            'preparing' => count($preparing_orders),
            'ready' => count($ready_orders),
            'completed' => $comp_count,
            'total_active' => count($active_orders)
        ],
        'server_time' => date('Y-m-d H:i:s')
    ]);
    exit();
}

// 3. Recent Transactions API (for POS)
if ($type === 'recent') {
    $recent = mysqli_query($conn, "
        SELECT id, queue_number, customer_name, order_type, staff_username, total_amount, `$status_col` AS order_status, order_date 
        FROM orders 
        ORDER BY order_date DESC 
        LIMIT 10
    ");
    $recent_orders = [];
    if ($recent) {
        while ($row = mysqli_fetch_assoc($recent)) {
            $row['queue_tag'] = formatApiQueueTag($row['queue_number'] ?? $row['id']);
            $row['customer_name'] = !empty($row['customer_name']) ? $row['customer_name'] : 'Guest';
            $row['order_type'] = !empty($row['order_type']) ? $row['order_type'] : 'Dine In';
            $recent_orders[] = $row;
        }
    }
    echo json_encode(['orders' => $recent_orders]);
    exit();
}
