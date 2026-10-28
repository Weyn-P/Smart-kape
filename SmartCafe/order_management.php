<?php
session_start();
require_once 'config.php';

// Auth Guard: User must be logged in
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

// Handle Void Logic
if (isset($_GET['void_id']) && isset($_SESSION['role']) && in_array($_SESSION['role'], ['Admin', 'Manager'], true)) {
    $void_id = (int)$_GET['void_id'];
    if ($void_id > 0) {
        $user = $_SESSION['username'] ?? 'User';
        $role = $_SESSION['role'] ?? 'Staff';
        $token = $_SESSION['session_token'] ?? null;
        $log_action = "VOIDED Order #$void_id";
        $log_stmt = mysqli_prepare($conn, "INSERT INTO staff_logs (username, role, session_token, action) VALUES (?, ?, ?, ?)");
        if ($log_stmt) {
            mysqli_stmt_bind_param($log_stmt, "ssss", $user, $role, $token, $log_action);
            mysqli_stmt_execute($log_stmt);
            mysqli_stmt_close($log_stmt);
        }

        mysqli_query($conn, "DELETE FROM order_items WHERE order_id = '$void_id'");
        mysqli_query($conn, "DELETE FROM orders WHERE id = '$void_id'");
    }
    header("Location: sales_monitoring.php?msg=voided");
    exit();
}

// Handle Status Toggle (Supports standard redirect and AJAX calls)
if (isset($_GET['toggle_id']) && isset($_GET['current_status'])) {
    $order_id = mysqli_real_escape_string($conn, $_GET['toggle_id']);
    $new_status = ($_GET['current_status'] === 'Completed') ? 'In Process' : 'Completed';
    
    mysqli_query($conn, "UPDATE orders SET order_status = '$new_status' WHERE id = '$order_id'");
    
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'new_status' => $new_status]);
        exit();
    }

    header("Location: order_management.php");
    exit();
}

// Initialize Cart
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// AJAX API: Handle Add to Cart with Quantity Aggregation
if (isset($_POST['ajax_add_to_cart'])) {
    header('Content-Type: application/json');
    $item_id = mysqli_real_escape_string($conn, $_POST['item_id']);
    $qty = (int)$_POST['quantity'];

    $res = mysqli_query($conn, "SELECT * FROM menu WHERE id=$item_id AND status='Available'");
    if ($item_data = mysqli_fetch_assoc($res)) {
        $found = false;
        
        foreach ($_SESSION['cart'] as &$cart_item) {
            if ($cart_item['id'] == $item_id) {
                $cart_item['qty'] += $qty;
                $cart_item['subtotal'] = $cart_item['qty'] * $cart_item['price'];
                $found = true;
                break;
            }
        }
        unset($cart_item);

        if (!$found) {
            $_SESSION['cart'][] = [
                'id' => $item_id,
                'name' => $item_data['item_name'],
                'price' => (float)$item_data['price'],
                'qty' => $qty,
                'subtotal' => (float)$item_data['price'] * $qty
            ];
        }

        echo json_encode(['success' => true, 'cart' => array_values($_SESSION['cart'])]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Item unavailable']);
    }
    exit();
}

// AJAX API: Handle Quantity Update / Item Removal
if (isset($_POST['ajax_update_cart'])) {
    header('Content-Type: application/json');
    $item_id = $_POST['item_id'];
    $action = $_POST['action'];

    foreach ($_SESSION['cart'] as $index => &$cart_item) {
        if ($cart_item['id'] == $item_id) {
            if ($action === 'increase') {
                $cart_item['qty'] += 1;
                $cart_item['subtotal'] = $cart_item['qty'] * $cart_item['price'];
            } elseif ($action === 'decrease') {
                $cart_item['qty'] -= 1;
                if ($cart_item['qty'] <= 0) {
                    unset($_SESSION['cart'][$index]);
                } else {
                    $cart_item['subtotal'] = $cart_item['qty'] * $cart_item['price'];
                }
            } elseif ($action === 'remove') {
                unset($_SESSION['cart'][$index]);
            }
            break;
        }
    }
    unset($cart_item);

    $_SESSION['cart'] = array_values($_SESSION['cart']);

    echo json_encode(['success' => true, 'cart' => $_SESSION['cart']]);
    exit();
}

// Handle Clearing Cart
if (isset($_GET['clear'])) {
    $_SESSION['cart'] = [];

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'cart' => []]);
        exit();
    }

    header("Location: order_management.php");
    exit();
}

// AJAX API: Handle Saving Transaction with Daily Queue Number Assignment
if (isset($_POST['ajax_place_order']) && !empty($_SESSION['cart'])) {
    header('Content-Type: application/json');
    $staff = $_SESSION['username'] ?? 'Staff';

    // 1. Separate Customer Name input
    $customer_name = trim($_POST['customer_name'] ?? '');
    if ($customer_name === '') {
        $customer_name = 'Walk-in Guest';
    }

    // 2. Separate Order Type input
    $order_type = trim($_POST['order_type'] ?? 'Dine In');
    if (!in_array($order_type, ['Dine In', 'Take Out'], true)) {
        $order_type = 'Dine In';
    }

    $customer_name_esc = mysqli_real_escape_string($conn, $customer_name);
    $order_type_esc = mysqli_real_escape_string($conn, $order_type);

    // 3. Separate Order Number input (uses manual input if provided > 0, otherwise auto-increments daily queue)
    $manual_order_num = isset($_POST['order_number']) ? (int)$_POST['order_number'] : 0;
    if ($manual_order_num > 0) {
        $queue_num = $manual_order_num;
    } else {
        $q_res = mysqli_query($conn, "SELECT COALESCE(MAX(queue_number), 0) + 1 AS next_q FROM orders WHERE DATE(order_date) = CURDATE()");
        $queue_num = 1;
        if ($q_res && $q_row = mysqli_fetch_assoc($q_res)) {
            $queue_num = (int)$q_row['next_q'];
        }
    }
    $queue_tag = '#' . str_pad($queue_num, 3, '0', STR_PAD_LEFT);

    // Compute next daily queue number
    $next_q_calc_res = mysqli_query($conn, "SELECT GREATEST(COALESCE(MAX(queue_number), 0), $queue_num) + 1 AS next_q FROM orders WHERE DATE(order_date) = CURDATE()");
    $next_q_calc = $queue_num + 1;
    if ($next_q_calc_res && $nq_row = mysqli_fetch_assoc($next_q_calc_res)) {
        $next_q_calc = (int)$nq_row['next_q'];
    }
    $next_queue_tag = '#' . str_pad($next_q_calc, 3, '0', STR_PAD_LEFT);

    $grand_total = 0;
    foreach ($_SESSION['cart'] as $item) { 
        $grand_total += $item['subtotal']; 
    }

    $order_sql = "INSERT INTO orders (queue_number, staff_username, customer_name, order_type, total_amount, order_status) 
                  VALUES ('$queue_num', '$staff', '$customer_name_esc', '$order_type_esc', '$grand_total', 'In Process')";
    
    if (mysqli_query($conn, $order_sql)) {
        $order_id = mysqli_insert_id($conn);
        $saved_items = [];

        foreach ($_SESSION['cart'] as $item) {
            $item_id = $item['id'];
            $name = mysqli_real_escape_string($conn, $item['name']);
            $qty = $item['qty'];
            $price = $item['price'];

            mysqli_query($conn, "INSERT INTO order_items (order_id, menu_item_name, quantity, price) VALUES ('$order_id', '$name', '$qty', '$price')");
            
            $saved_items[] = [
                'name' => $item['name'],
                'qty' => $qty,
                'price' => $price,
                'subtotal' => $item['subtotal']
            ];
        }

        $_SESSION['cart'] = [];
        
        // RECORD LOG: Order placed by staff with Queue Tag
        $token = $_SESSION['session_token'] ?? null;
        $role = $_SESSION['role'] ?? 'Staff';
        $log_action = "Placed Queue $queue_tag (Order #$order_id - $order_type, Total: ₱" . number_format($grand_total, 2) . ")";
        $log_stmt = mysqli_prepare($conn, "INSERT INTO staff_logs (username, role, session_token, action) VALUES (?, ?, ?, ?)");
        if ($log_stmt) {
            mysqli_stmt_bind_param($log_stmt, "ssss", $staff, $role, $token, $log_action);
            mysqli_stmt_execute($log_stmt);
            mysqli_stmt_close($log_stmt);
        }
        
        echo json_encode([
            'success' => true,
            'order_id' => $order_id,
            'queue_number' => $queue_num,
            'queue_tag' => $queue_tag,
            'next_queue_number' => $next_q_calc,
            'next_queue_tag' => $next_queue_tag,
            'customer_name' => $customer_name,
            'order_type' => $order_type,
            'staff' => $staff,
            'total' => $grand_total,
            'date' => date('Y-m-d H:i:s'),
            'items' => $saved_items
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit();
}

// Category List
$defined_categories = [
    'All',
    'Coffee',
    'Non Coffee',
    'Matcha',
    'Fruit Soda',
    'Hot Drinks',
    'Rice Meals',
    'Snacks',
    'Add Ons'
];

$menu_query = mysqli_query($conn, "SELECT * FROM menu WHERE status='Available' ORDER BY category ASC, item_name ASC");

// Query current next queue tag for display
$next_q_res = mysqli_query($conn, "SELECT COALESCE(MAX(queue_number), 0) + 1 AS next_q FROM orders WHERE DATE(order_date) = CURDATE()");
$current_next_q = 1;
if ($next_q_res && $nq = mysqli_fetch_assoc($next_q_res)) {
    $current_next_q = (int)$nq['next_q'];
}
$current_next_q_tag = '#' . str_pad($current_next_q, 3, '0', STR_PAD_LEFT);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartCafe | Order Management</title>
    <?php include_once __DIR__ . '/pwa_head.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --bg-canvas: #e2e8f0;
            --glass-card: rgba(255, 255, 255, 0.5);
            --glass-card-hover: rgba(255, 255, 255, 0.8);
            --glass-border: rgba(255, 255, 255, 0.85);
            --glass-blur: blur(20px);
            
            --text-main: #0f172a;
            --text-muted: #475569;
            --border-radius: 20px;
            --transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            --primary-accent: #0f172a;
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
            min-height: 100vh;
            padding: 30px 20px;
        }

        .order-container { 
            max-width: 1300px; 
            margin: 0 auto; 
            display: flex; 
            gap: 24px; 
            flex-wrap: wrap; 
            align-items: stretch;
        }

        .top-nav {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .btn-back {
            text-decoration: none;
            color: #ffffff;
            font-weight: 700;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            transition: var(--transition);
        }

        .btn-back:hover {
            background: #1e293b;
            color: #ffffff;
            transform: translateX(-3px);
        }

        .page-title {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .form-section { 
            flex: 1.2; 
            min-width: 320px; 
            background: var(--glass-card); 
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--glass-border);
            padding: 28px; 
            border-radius: var(--border-radius); 
            box-shadow: 0 15px 35px rgba(0,0,0,0.03); 
        }

        .cart-section { 
            flex: 1.5; 
            min-width: 380px; 
            background: var(--glass-card); 
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--glass-border);
            padding: 28px; 
            border-radius: var(--border-radius); 
            box-shadow: 0 15px 35px rgba(0,0,0,0.03); 
            display: flex; 
            flex-direction: column; 
            justify-content: space-between; 
            position: relative;
            min-height: 100%;
        }

        .cart-main-content {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
        }

        .section-header {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Category Tabs */
        .category-tabs {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 8px;
            margin-bottom: 18px;
            scrollbar-width: thin;
        }

        .tab-btn {
            background: rgba(255, 255, 255, 0.5);
            border: 1px solid var(--glass-border);
            padding: 8px 16px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            font-family: inherit;
            color: var(--text-muted);
            white-space: nowrap;
            transition: var(--transition);
        }

        .tab-btn:hover { 
            background: rgba(255, 255, 255, 0.85); 
            color: var(--text-main);
        }

        .tab-btn.active {
            background: var(--text-main);
            color: #ffffff;
            border-color: var(--text-main);
        }

        /* Menu Grid */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 14px;
            max-height: 380px;
            overflow-y: auto;
            padding-right: 4px;
            margin-bottom: 22px;
        }

        .menu-radio { display: none; }
        .menu-item-wrapper { display: block; }

        .menu-card {
            background: rgba(255, 255, 255, 0.4);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            height: 100%;
        }

        .menu-card:hover {
            background: rgba(255, 255, 255, 0.85);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.04);
        }

        .menu-card img {
            width: 100%;
            height: 85px;
            object-fit: cover;
            border-radius: 10px;
            margin-bottom: 8px;
        }

        .card-title { 
            font-size: 13px; 
            font-weight: 700; 
            color: var(--text-main); 
            margin-bottom: 4px; 
            line-height: 1.3; 
        }

        .card-price { 
            font-size: 13px; 
            color: var(--text-muted); 
            font-weight: 800; 
        }

        .menu-radio:checked + .menu-card {
            background: rgba(255, 255, 255, 0.95);
            border-color: var(--text-main);
            box-shadow: 0 0 0 2px var(--text-main);
        }

        /* Controls Layout */
        .controls-row {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            margin-bottom: 20px;
            flex-wrap: wrap;
            background: rgba(255, 255, 255, 0.3);
            padding: 16px;
            border-radius: 16px;
            border: 1px solid var(--glass-border);
        }

        .control-label {
            display: block; 
            margin-bottom: 6px; 
            font-weight: 700; 
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
        }

        /* Quantity Picker */
        .qty-picker {
            display: inline-flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            overflow: hidden;
            height: 40px;
        }

        .btn-picker {
            background: transparent;
            border: none;
            width: 36px;
            height: 100%;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            color: var(--text-main);
            transition: var(--transition);
        }

        .btn-picker:hover { background: rgba(255, 255, 255, 0.8); }

        .qty-picker-input {
            width: 40px;
            text-align: center;
            border: none;
            font-size: 15px;
            font-weight: 700;
            font-family: inherit;
            outline: none;
            background: transparent;
            color: var(--text-main);
        }

        /* Size Filter Buttons */
        .size-buttons { display: flex; gap: 6px; }

        .size-btn {
            padding: 10px 16px;
            border: 1px solid var(--glass-border);
            background: rgba(255, 255, 255, 0.5);
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
            font-family: inherit;
            color: var(--text-muted);
            transition: var(--transition);
        }

        .size-btn:hover { background: rgba(255, 255, 255, 0.85); color: var(--text-main); }
        .size-btn.active {
            background: var(--text-main);
            color: #ffffff;
            border-color: var(--text-main);
        }

        /* Buttons */
        .btn-add { 
            background: var(--text-main); 
            color: #ffffff; 
            border: none; 
            padding: 16px; 
            width: 100%; 
            cursor: pointer; 
            border-radius: 14px; 
            font-weight: 700; 
            font-size: 15px; 
            font-family: inherit;
            transition: var(--transition);
        }

        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .btn-preview {
            background: rgba(255, 255, 255, 0.6);
            color: var(--text-main);
            border: 1px solid var(--glass-border);
            padding: 16px;
            width: 100%;
            cursor: pointer;
            border-radius: 14px;
            font-size: 15px;
            margin-top: 10px;
            font-weight: 700;
            font-family: inherit;
            transition: var(--transition);
        }

        .btn-preview:hover:not(:disabled) {
            background: rgba(255, 255, 255, 0.9);
            transform: translateY(-2px);
        }

        .btn-preview:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            background: rgba(255, 255, 255, 0.3);
            border-color: transparent;
        }

        .btn-save { 
            background: var(--text-main); 
            color: #ffffff; 
            border: none; 
            padding: 16px; 
            width: 100%; 
            cursor: pointer; 
            border-radius: 14px; 
            font-size: 16px; 
            margin-top: 10px; 
            font-weight: 700; 
            font-family: inherit;
            transition: var(--transition);
        }

        .btn-save:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .btn-save:disabled {
            background: rgba(0, 0, 0, 0.15);
            color: rgba(0, 0, 0, 0.3);
            cursor: not-allowed;
        }

        .btn-clear { 
            color: #ef4444; 
            border: none;
            font-size: 13px; 
            font-weight: 700; 
            padding: 6px 12px;
            background: rgba(239, 68, 68, 0.1);
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-clear:hover { background: rgba(239, 68, 68, 0.2); }

        .cart-scroll-container {
            flex: 1;
            min-height: 200px;
            max-height: 320px;
            overflow-y: auto;
            overflow-x: hidden;
            border-radius: 14px;
            border: 1px solid var(--glass-border);
            background: rgba(255, 255, 255, 0.3);
            scrollbar-width: thin;
            scrollbar-color: rgba(15, 23, 42, 0.25) rgba(255, 255, 255, 0.1);
        }

        .cart-scroll-container::-webkit-scrollbar { width: 6px; }
        .cart-scroll-container::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.1); border-radius: 10px; }
        .cart-scroll-container::-webkit-scrollbar-thumb { background: rgba(15, 23, 42, 0.25); border-radius: 10px; }
        .cart-scroll-container::-webkit-scrollbar-thumb:hover { background: rgba(15, 23, 42, 0.45); }

        table { width: 100%; border-collapse: collapse; }

        th, td { 
            padding: 12px 10px; 
            text-align: left; 
            font-size: 13px; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.4);
        }

        th {
            background: #0f172a;
            color: #ffffff;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            position: sticky;
            top: 0;
            z-index: 2;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .qty-controls {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .btn-qty {
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid var(--glass-border);
            width: 24px;
            height: 24px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 700;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .btn-qty:hover { background: #ffffff; }

        .btn-remove-item {
            background: rgba(239, 68, 68, 0.15);
            color: #dc2626;
            border: none;
            width: 24px;
            height: 24px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 700;
            transition: var(--transition);
        }

        .btn-remove-item:hover { background: #dc2626; color: #ffffff; }

        /* OUTSIDE TOP RIGHT FLOATING THERMAL PRINTER BOX */
        .printer-side-container {
            position: absolute;
            top: 0;
            right: -260px;
            width: 240px;
            display: flex;
            flex-direction: column;
            align-items: center;
            background: var(--glass-card);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 16px 12px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.03);
            z-index: 20;
        }

        .printer-header-badge {
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 12px;
            z-index: 10;
        }

        .printer-slot {
            width: 210px;
            height: 10px;
            background: #1e293b;
            border-radius: 5px;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.8), 0 0 0 2px rgba(255,255,255,0.8);
            position: relative;
            z-index: 9;
        }

        .receipt-paper-wrapper {
            position: relative;
            width: 200px;
            height: 0px;
            overflow: hidden;
            margin-top: -5px;
            z-index: 5;
            transition: height 0.05s linear;
        }

        .receipt-paper {
            width: 200px;
            background: #ffffff;
            color: #1e293b;
            font-family: 'Space Mono', monospace;
            padding: 16px 12px 20px 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            font-size: 10px;
            line-height: 1.3;
            border-bottom: 4px dashed #cbd5e1;
            position: absolute;
            top: 0;
            left: 0;
        }

        .receipt-header {
            text-align: center;
            margin-bottom: 8px;
            border-bottom: 1px dashed #94a3b8;
            padding-bottom: 6px;
        }

        .receipt-title {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .receipt-table {
            width: 100%;
            margin: 6px 0;
            font-size: 9px;
            border-collapse: collapse;
        }

        .receipt-table td, .receipt-table th {
            padding: 2px 0;
            border: none;
            background: transparent;
            color: #1e293b;
            font-family: 'Space Mono', monospace;
        }

        .receipt-divider {
            border-top: 1px dashed #94a3b8;
            margin: 6px 0;
        }

        .receipt-footer {
            text-align: center;
            margin-top: 10px;
            font-size: 8px;
            color: #64748b;
        }

        .badge { 
            padding: 6px 14px; 
            border-radius: 20px; 
            font-size: 12px; 
            font-weight: 700; 
            display: inline-block; 
            border: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .badge-completed { background: rgba(16, 185, 129, 0.15); color: #047857; }
        .badge-process { background: rgba(245, 158, 11, 0.15); color: #b45309; }

        .transactions-card {
            width: 100%; 
            margin-top: 24px;
            background: var(--glass-card); 
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--glass-border);
            padding: 28px; 
            border-radius: var(--border-radius); 
            box-shadow: 0 15px 35px rgba(0,0,0,0.03); 
        }

        .table-responsive {
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--glass-border);
            background: rgba(255, 255, 255, 0.3);
            margin-top: 14px;
        }

        /* RECEIPT PREVIEW MODAL STYLES (ENLARGED) */
        .receipt-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .receipt-modal-content {
            background: var(--glass-card);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: 36px 32px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.2);
            display: flex;
            flex-direction: column;
            align-items: center;
            max-height: 90vh;
            width: 90%;
            max-width: 450px;
            overflow-y: auto;
            position: relative;
        }

        .btn-close-modal {
            position: absolute;
            top: 16px;
            right: 20px;
            background: transparent;
            border: none;
            font-size: 24px;
            font-weight: 700;
            cursor: pointer;
            color: var(--text-muted);
        }

        #receiptModal .receipt-paper {
            width: 320px !important;
            position: relative;
            top: auto;
            left: auto;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            border-bottom: 6px dashed #cbd5e1;
            padding: 24px 20px 28px 20px;
            font-size: 13px;
            line-height: 1.4;
        }

        #receiptModal .receipt-title {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        #receiptModal #modal-receipt-meta {
            font-size: 11px !important;
        }

        #receiptModal .receipt-table {
            width: 100%;
            margin: 12px 0;
            font-size: 12px;
            border-collapse: collapse;
        }

        #receiptModal .receipt-table td, 
        #receiptModal .receipt-table th {
            padding: 6px 0;
        }

        #receiptModal .receipt-footer {
            text-align: center;
            margin-top: 16px;
            font-size: 11px;
            color: #64748b;
        }

        @media (max-width: 1550px) {
            .printer-side-container {
                position: static;
                width: 100%;
                margin-top: 16px;
            }
        }

        /* iPad & Tablets (Landscape & Portrait: 768px - 1024px) */
        @media (max-width: 1024px) {
            .order-container {
                flex-direction: column;
                gap: 20px;
                width: 100%;
                max-width: 100%;
            }
            .form-section, .cart-section {
                flex: none;
                width: 100%;
                min-width: 0;
            }
            .top-nav {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }
            .top-nav > div {
                display: flex;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 8px;
            }
        }

        /* Mobile Phones (under 640px) */
        @media (max-width: 640px) {
            body {
                padding: 12px 6px;
            }
            .form-section, .cart-section, .transactions-card {
                padding: 16px 12px;
                border-radius: 16px;
            }
            .page-title {
                font-size: 20px;
                text-align: center;
            }
            .btn-back {
                padding: 8px 12px;
                font-size: 12px;
            }
            .cart-scroll-container {
                min-height: 180px;
                max-height: 320px;
            }
            th, td {
                padding: 8px 6px;
                font-size: 12px;
            }
            .btn-qty {
                width: 30px;
                height: 30px;
                font-size: 14px;
            }
            .btn-remove-item {
                width: 30px;
                height: 30px;
            }
            #receiptModal .receipt-modal-content {
                width: 95%;
                padding: 20px 14px;
            }
            #receiptModal .receipt-paper {
                width: 100% !important;
                max-width: 290px;
            }
        }

        /* POS QUEUE SYSTEM STYLES */
        .pos-next-q-pill {
            background: rgba(15, 23, 42, 0.85);
            color: #38bdf8;
            font-family: 'Space Mono', monospace;
            font-size: 13px;
            font-weight: 700;
            padding: 8px 14px;
            border-radius: 12px;
            border: 1px solid rgba(56, 189, 248, 0.35);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        .pos-queue-box {
            background: rgba(255, 255, 255, 0.55);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 14px;
            padding: 12px;
            margin-bottom: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .type-toggle-group {
            display: flex;
            gap: 8px;
        }

        .type-toggle-btn {
            flex: 1;
            padding: 9px 12px;
            border-radius: 10px;
            border: 1.5px solid rgba(203, 213, 225, 0.8);
            background: #ffffff;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-muted);
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .type-toggle-btn.active {
            background: #0f172a;
            color: #ffffff;
            border-color: #0f172a;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        .cust-input-field {
            width: 100%;
            padding: 9px 12px;
            border-radius: 10px;
            border: 1.5px solid rgba(203, 213, 225, 0.8);
            background: #ffffff;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
            outline: none;
            transition: var(--transition);
            box-sizing: border-box;
        }

        .cust-input-field:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .pos-inputs-grid {
            display: grid;
            grid-template-columns: 130px 1fr;
            gap: 10px;
        }

        @media (max-width: 480px) {
            .pos-inputs-grid {
                grid-template-columns: 1fr;
            }
        }

        .input-wrapper-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .input-sub-label {
            font-size: 11px;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .order-num-input {
            width: 100%;
            padding: 9px 12px;
            border-radius: 10px;
            border: 1.5px solid rgba(2, 132, 199, 0.4);
            background: #ffffff;
            font-family: 'Space Mono', monospace;
            font-size: 15px;
            font-weight: 800;
            color: #0284c7;
            outline: none;
            transition: var(--transition);
            box-sizing: border-box;
        }

        .order-num-input:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.2);
        }

        .queue-num-badge {
            font-family: 'Space Mono', monospace;
            font-weight: 800;
            font-size: 13px;
            padding: 3px 8px;
            border-radius: 6px;
            background: rgba(15, 23, 42, 0.08);
            color: #0f172a;
            display: inline-block;
        }

        .receipt-queue-ticket {
            border: 1.5px dashed #0f172a;
            background: #f8fafc;
            border-radius: 6px;
            padding: 8px 4px;
            margin: 6px 0 10px 0;
            text-align: center;
        }

        .receipt-queue-ticket .q-label {
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
        }

        .receipt-queue-ticket .q-number {
            font-family: 'Space Mono', monospace;
            font-size: 24px;
            font-weight: 900;
            color: #0f172a;
            line-height: 1.1;
            margin: 2px 0;
        }

        .receipt-queue-ticket .q-meta {
            font-size: 9px;
            font-weight: 700;
            color: #0284c7;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

<div class="order-container">
    <div class="top-nav">
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <a href="dashboard.php" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i> Dashboard
            </a>
            <!-- KDS Button -->
            <a href="kds.php" class="btn-back" style="background: linear-gradient(135deg, #0284c7, #0369a1); border-color: rgba(255,255,255,0.3); color: #fff;">
                <i class="fa-solid fa-kitchen-set"></i> Kitchen Display
            </a>
        </div>
        <h1 class="page-title"><i class="fa-solid fa-cash-register"></i> Order Management</h1>
    </div>

    <div class="form-section">
        <div class="section-header">Select Menu Item</div>
        
        <form id="add-item-form">
            <input type="hidden" name="ajax_add_to_cart" value="1">

            <div class="category-tabs">
                <?php foreach ($defined_categories as $index => $cat): ?>
                    <button type="button" 
                            class="tab-btn <?php echo ($index === 0) ? 'active' : ''; ?>" 
                            onclick="filterCategory('<?php echo htmlspecialchars($cat); ?>', this)">
                        <?php echo htmlspecialchars($cat); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="menu-grid">
                <?php while($item = mysqli_fetch_assoc($menu_query)): 
                    $image_src = !empty($item['image_path']) ? htmlspecialchars($item['image_path']) : 'https://via.placeholder.com/120x80?text=No+Image';
                    $item_cat = htmlspecialchars($item['category'] ?? '');
                    
                    $raw_name = $item['item_name'];
                    $name_lower = strtolower($raw_name);
                    $item_size = 'none';
                    if (strpos($name_lower, '16oz') !== false || strpos($name_lower, '16 oz') !== false) {
                        $item_size = '16oz';
                    } elseif (strpos($name_lower, '22oz') !== false || strpos($name_lower, '22 oz') !== false) {
                        $item_size = '22oz';
                    }

                    $base_name = trim(preg_replace('/\s*\(?\s*(16|22)\s*oz\s*\)?/i', '', $raw_name));
                ?>
                    <label class="menu-item-wrapper" data-category="<?php echo $item_cat; ?>" data-size="<?php echo $item_size; ?>" data-base-name="<?php echo htmlspecialchars($base_name); ?>">
                        <input type="radio" name="item_id" value="<?php echo $item['id']; ?>" class="menu-radio" required 
                               data-name="<?php echo htmlspecialchars($item['item_name']); ?>" 
                               data-price="<?php echo $item['price']; ?>"
                               onchange="updateSelectedDisplay(this)">
                        <div class="menu-card">
                            <img src="<?php echo $image_src; ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>">
                            <div class="card-title"><?php echo htmlspecialchars($item['item_name']); ?></div>
                            <div class="card-price">₱<?php echo number_format($item['price'], 2); ?></div>
                        </div>
                    </label>
                <?php endwhile; ?>
            </div>

            <div class="controls-row">
                <div>
                    <label class="control-label">Quantity</label>
                    <div class="qty-picker">
                        <button type="button" class="btn-picker" onclick="changeFormQty(-1)">-</button>
                        <input type="number" id="item_qty" name="quantity" min="1" value="1" readonly class="qty-picker-input" required>
                        <button type="button" class="btn-picker" onclick="changeFormQty(1)">+</button>
                    </div>
                </div>

                <div>
                    <label class="control-label">Size</label>
                    <div class="size-buttons">
                        <button type="button" class="size-btn" onclick="filterSize('16oz', this)">16oz</button>
                        <button type="button" class="size-btn" onclick="filterSize('22oz', this)">22oz</button>
                    </div>
                </div>

                <div style="margin-left: auto; text-align: right;">
                    <div style="font-size: 13px; color: var(--text-muted); font-weight: 600;">Price: <strong style="color: var(--text-main); font-size: 15px;">₱<span id="selected_price">0.00</span></strong></div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">Subtotal: <strong style="color: var(--text-main);">₱<span id="selected_subtotal">0.00</span></strong></div>
                </div>
            </div>
            
            <button type="submit" class="btn-add"><i class="fa-solid fa-plus"></i> Add to Order</button>
        </form>
    </div>

    <!-- CART SECTION -->
    <div class="cart-section">
        <!-- THERMAL RECEIPT PRINTER OUTSIDE UPPER RIGHT -->
        <div class="printer-side-container">
            <div class="printer-header-badge">
                <i class="fa-solid fa-print"></i> <span id="printer-status-text">Receipt Printer</span>
            </div>
            <div class="printer-slot"></div>
            <div id="receipt-paper-wrapper" class="receipt-paper-wrapper">
                <div id="receipt-paper" class="receipt-paper">
                    <div class="receipt-header">
                        <div class="receipt-title">BESKAPE COFFEE</div>
                        <div style="font-size: 8px; margin-top: 1px;">Official Receipt</div>
                        <div style="margin-top: 4px; font-size: 8px;" id="receipt-meta">Order Ready</div>
                    </div>

                    <!-- Thermal Receipt Daily Queue Ticket -->
                    <div class="receipt-queue-ticket">
                        <div class="q-label">Daily Queue Number</div>
                        <div class="q-number" id="receipt-queue-tag">#---</div>
                        <div class="q-meta" id="receipt-queue-meta">Dine In • Guest</div>
                    </div>

                    <table class="receipt-table">
                        <thead>
                            <tr style="border-bottom: 1px dashed #94a3b8;">
                                <th style="text-align: left;">QTY</th>
                                <th style="text-align: left;">ITEM</th>
                                <th style="text-align: right;">AMT</th>
                            </tr>
                        </thead>
                        <tbody id="receipt-items-body"></tbody>
                    </table>

                    <div class="receipt-divider"></div>
                    <div style="display: flex; justify-content: space-between; font-weight: 700; font-size: 11px;">
                        <span>TOTAL:</span>
                        <span id="receipt-total">₱0.00</span>
                    </div>

                    <div class="receipt-footer">
                        Thank you for visiting BesKape!<br>Have a great day ahead.
                    </div>
                </div>
            </div>
        </div>

        <div class="cart-main-content">
            <!-- ORDER TYPE & CUSTOMER (QUEUE DETAILS) -->
            <div class="pos-queue-box">
                <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center;">
                    <span><i class="fa-solid fa-utensils"></i> Order Details</span>
                    <span style="color: #0284c7; font-weight: 700;">Queue Ticket Info</span>
                </div>
                <div class="type-toggle-group">
                    <button type="button" class="type-toggle-btn active" id="btn-type-dinein" onclick="selectOrderType('Dine In', this)">
                        <i class="fa-solid fa-mug-hot"></i> Dine In
                    </button>
                    <button type="button" class="type-toggle-btn" id="btn-type-takeout" onclick="selectOrderType('Take Out', this)">
                        <i class="fa-solid fa-bag-shopping"></i> Take Out
                    </button>
                </div>
                <input type="hidden" id="pos_order_type" value="Dine In">

                <!-- SEPARATED ORDER NUMBER AND CUSTOMER NAME INPUTS -->
                <div class="pos-inputs-grid">
                    <div class="input-wrapper-field">
                        <label for="pos_order_number" class="input-sub-label">
                            <i class="fa-solid fa-ticket"></i> Order #
                        </label>
                        <input type="number" 
                               id="pos_order_number" 
                               name="order_number" 
                               min="1" 
                               value="<?php echo $current_next_q; ?>" 
                               placeholder="<?php echo $current_next_q; ?>" 
                               class="order-num-input" 
                               title="Daily queue order number (auto-assigned, editable)"
                               oninput="this.dataset.userEdited = 'true'">
                    </div>
                    <div class="input-wrapper-field">
                        <label for="pos_customer_name" class="input-sub-label">
                            <i class="fa-solid fa-user"></i> Customer Name
                        </label>
                        <input type="text" 
                               id="pos_customer_name" 
                               name="customer_name"
                               class="cust-input-field" 
                               placeholder="Customer / Table # (Optional)" 
                               maxlength="60">
                    </div>
                </div>
            </div>

            <div class="section-header">
                <span>Current Order List</span>
                <button type="button" onclick="clearCart()" class="btn-clear"><i class="fa-solid fa-trash-can"></i> Clear All</button>
            </div>

            <!-- ORDER TABLE -->
            <div class="cart-scroll-container">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Subtotal</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="cart-table-body">
                        <?php 
                        $total_order = 0;
                        foreach($_SESSION['cart'] as $item): 
                            $total_order += $item['subtotal'];
                        ?>
                        <tr>
                            <td style="font-weight: 600;"><?php echo htmlspecialchars($item['name']); ?></td>
                            <td>
                                <div class="qty-controls">
                                    <button class="btn-qty" onclick="modifyQty(<?php echo $item['id']; ?>, 'decrease')">-</button>
                                    <span style="font-weight: 700; width: 16px; text-align: center;"><?php echo $item['qty']; ?></span>
                                    <button class="btn-qty" onclick="modifyQty(<?php echo $item['id']; ?>, 'increase')">+</button>
                                </div>
                            </td>
                            <td style="font-weight: 700;">₱<?php echo number_format($item['subtotal'], 2); ?></td>
                            <td>
                                <button class="btn-remove-item" onclick="modifyQty(<?php echo $item['id']; ?>, 'remove')">✕</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($_SESSION['cart'])): ?>
                            <tr id="empty-cart-row"><td colspan="4" style="text-align:center; padding: 24px; color: var(--text-muted); font-weight: 600;">No items added yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="margin-top: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 16px; font-weight: 700; color: var(--text-muted);">Total Amount</span>
                <h2 style="font-size: 28px; font-weight: 800; color: var(--text-main);">₱<span id="grand-total"><?php echo number_format($total_order, 2); ?></span></h2>
            </div>
            
            <button type="button" id="preview-btn" onclick="openReceiptPreview()" class="btn-preview" disabled>
                <i class="fa-solid fa-eye"></i> Preview Receipt
            </button>

            <button type="button" id="save-btn" onclick="processOrderWithAnimation()" class="btn-save" <?php echo empty($_SESSION['cart']) ? 'disabled' : ''; ?>>
                Save Transaction
            </button>
        </div>
    </div>

    <div class="transactions-card">
        <div class="section-header">Recent Transactions</div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Queue #</th>
                        <th>Order ID</th>
                        <th>Type / Guest</th>
                        <th>Staff</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody id="recent-transactions-body">
                    <?php
                    $recent = mysqli_query($conn, "SELECT * FROM orders ORDER BY order_date DESC LIMIT 10");
                    while($row = mysqli_fetch_assoc($recent)):
                        $status = !empty($row['order_status']) ? $row['order_status'] : 'In Process';
                        $is_complete = ($status === 'Completed');
                        $q_num = (int)($row['queue_number'] ?? $row['id']);
                        $q_tag = '#' . str_pad($q_num > 0 ? $q_num : 1, 3, '0', STR_PAD_LEFT);
                        $ord_type = htmlspecialchars($row['order_type'] ?? 'Dine In');
                        $cust = htmlspecialchars(!empty($row['customer_name']) ? $row['customer_name'] : 'Guest');
                    ?>
                    <tr>
                        <td><span class="queue-num-badge"><?php echo $q_tag; ?></span></td>
                        <td style="font-weight: 700;">#<?php echo $row['id']; ?></td>
                        <td>
                            <div style="font-weight: 700; font-size: 13px;"><?php echo $cust; ?></div>
                            <div style="font-size: 11px; color: var(--text-muted); font-weight: 600;"><?php echo $ord_type; ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($row['staff_username']); ?></td>
                        <td style="font-weight: 700;">₱<?php echo number_format($row['total_amount'], 2); ?></td>
                        <td>
                            <button type="button" 
                                    class="badge <?php echo $is_complete ? 'badge-completed' : 'badge-process'; ?>" 
                                    onclick="toggleStatus(<?php echo $row['id']; ?>, '<?php echo $status; ?>', this)">
                                <?php echo $status; ?>
                            </button>
                        </td>
                        <td style="color: var(--text-muted); font-size: 13px;"><?php echo $row['order_date']; ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- RECEIPT PREVIEW MODAL -->
<div id="receiptModal" class="receipt-modal-overlay">
    <div class="receipt-modal-content">
        <button class="btn-close-modal" onclick="closeReceiptPreview()">✕</button>
        <div style="font-size: 16px; font-weight: 700; color: var(--text-muted); margin-bottom: 18px;">
            <i class="fa-solid fa-camera"></i> Customer Receipt Preview
        </div>
        
        <div class="receipt-paper">
            <div class="receipt-header">
                <div class="receipt-title">BESKAPE COFFEE</div>
                <div style="font-size: 10px; margin-top: 2px;">Official Receipt</div>
                <div style="margin-top: 6px;" id="modal-receipt-meta">Order Ready</div>
            </div>

            <!-- Modal Queue Ticket -->
            <div class="receipt-queue-ticket" style="padding: 10px 6px;">
                <div class="q-label" style="font-size: 10px;">Daily Queue Number</div>
                <div class="q-number" id="modal-receipt-queue-tag" style="font-size: 28px;">#---</div>
                <div class="q-meta" id="modal-receipt-queue-meta" style="font-size: 11px;">Dine In • Guest</div>
            </div>

            <table class="receipt-table">
                <thead>
                    <tr style="border-bottom: 1px dashed #94a3b8;">
                        <th style="text-align: left;">QTY</th>
                        <th style="text-align: left;">ITEM</th>
                        <th style="text-align: right;">AMT</th>
                    </tr>
                </thead>
                <tbody id="modal-receipt-items"></tbody>
            </table>

            <div class="receipt-divider"></div>
            <div style="display: flex; justify-content: space-between; font-weight: 700; font-size: 14px; margin-top: 6px;">
                <span>TOTAL:</span>
                <span id="modal-receipt-total">₱0.00</span>
            </div>

            <div class="receipt-footer">
                Thank you for visiting BesKape!<br>Have a great day ahead.
            </div>
        </div>
    </div>
</div>

<script>
let currentCategory = 'All';
let currentSize = 'All';
let selectedUnitPrice = 0;
let lastPrintedOrder = null;

function filterCategory(category, btnElement) {
    const buttons = document.querySelectorAll('.category-tabs .tab-btn');
    buttons.forEach(btn => btn.classList.remove('active'));
    btnElement.classList.add('active');
    currentCategory = category;
    applyMenuFilters();
}

function filterSize(size, btnElement) {
    const buttons = document.querySelectorAll('.size-btn');
    
    const currentlyChecked = document.querySelector('.menu-radio:checked');
    let currentBaseName = null;
    if (currentlyChecked) {
        const parentWrapper = currentlyChecked.closest('.menu-item-wrapper');
        if (parentWrapper) {
            currentBaseName = parentWrapper.getAttribute('data-base-name');
        }
    }

    if (btnElement.classList.contains('active')) {
        btnElement.classList.remove('active');
        currentSize = 'All';
    } else {
        buttons.forEach(btn => btn.classList.remove('active'));
        btnElement.classList.add('active');
        currentSize = size;
    }

    applyMenuFilters();

    if (currentBaseName && currentSize !== 'All') {
        const wrappers = Array.from(document.querySelectorAll('.menu-item-wrapper'));
        const targetWrapper = wrappers.find(wrapper => 
            wrapper.getAttribute('data-base-name') === currentBaseName &&
            wrapper.getAttribute('data-size') === currentSize &&
            wrapper.style.display !== 'none'
        );

        if (targetWrapper) {
            const targetRadio = targetWrapper.querySelector('.menu-radio');
            if (targetRadio) {
                targetRadio.checked = true;
                updateSelectedDisplay(targetRadio);
            }
        }
    }
}

function applyMenuFilters() {
    const items = document.querySelectorAll('.menu-item-wrapper');
    items.forEach(item => {
        const itemCategory = item.getAttribute('data-category');
        const itemSize = item.getAttribute('data-size');

        const matchesCategory = (currentCategory === 'All' || itemCategory === currentCategory);
        
        let matchesSize = true;
        if (currentSize !== 'All') {
            if (itemSize !== 'none') {
                matchesSize = (itemSize === currentSize);
            } else {
                matchesSize = true;
            }
        }

        if (matchesCategory && matchesSize) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

function updateSelectedDisplay(radio) {
    const priceSpan = document.getElementById('selected_price');
    selectedUnitPrice = parseFloat(radio.getAttribute('data-price')) || 0;
    
    if (priceSpan) {
        priceSpan.innerText = selectedUnitPrice.toFixed(2);
    }
    calculateSelectedItemTotal();
}

function changeFormQty(delta) {
    const qtyInput = document.getElementById('item_qty');
    let val = parseInt(qtyInput.value) || 1;
    val += delta;
    if (val < 1) val = 1;
    qtyInput.value = val;
    calculateSelectedItemTotal();
}

function calculateSelectedItemTotal() {
    const qty = parseInt(document.getElementById('item_qty').value) || 1;
    const subtotal = selectedUnitPrice * qty;
    const subtotalSpan = document.getElementById('selected_subtotal');
    if (subtotalSpan) {
        subtotalSpan.innerText = subtotal.toFixed(2);
    }
}

document.getElementById('add-item-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);

    fetch('order_management.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCartUI(data.cart);
            document.getElementById('item_qty').value = 1;
            calculateSelectedItemTotal();
        } else {
            alert(data.message || 'Error adding item.');
        }
    })
    .catch(err => console.error('Error:', err));
});

function modifyQty(itemId, action) {
    const formData = new FormData();
    formData.append('ajax_update_cart', '1');
    formData.append('item_id', itemId);
    formData.append('action', action);

    fetch('order_management.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCartUI(data.cart);
        }
    })
    .catch(err => console.error('Error:', err));
}

function clearCart() {
    fetch('order_management.php?clear=1', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCartUI([]);
        }
    })
    .catch(err => console.error('Error clearing cart:', err));
}

function toggleStatus(orderId, currentStatus, btnElement) {
    fetch(`order_management.php?toggle_id=${orderId}&current_status=${encodeURIComponent(currentStatus)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const isCompleted = (data.new_status === 'Completed');
            btnElement.textContent = data.new_status;
            
            if (isCompleted) {
                btnElement.classList.remove('badge-process');
                btnElement.classList.add('badge-completed');
            } else {
                btnElement.classList.remove('badge-completed');
                btnElement.classList.add('badge-process');
            }
            
            btnElement.setAttribute('onclick', `toggleStatus(${orderId}, '${data.new_status}', this)`);
        }
    })
    .catch(err => console.error('Error toggling status:', err));
}

function updateCartUI(cart) {
    const tbody = document.getElementById('cart-table-body');
    const totalSpan = document.getElementById('grand-total');
    const saveBtn = document.getElementById('save-btn');

    tbody.innerHTML = '';
    let total = 0;

    if (cart.length === 0) {
        tbody.innerHTML = '<tr id="empty-cart-row"><td colspan="4" style="text-align:center; padding: 24px; color: var(--text-muted); font-weight: 600;">No items added yet.</td></tr>';
        saveBtn.disabled = true;
    } else {
        cart.forEach(item => {
            total += parseFloat(item.subtotal);
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="font-weight: 600;">${escapeHtml(item.name)}</td>
                <td>
                    <div class="qty-controls">
                        <button class="btn-qty" onclick="modifyQty(${item.id}, 'decrease')">-</button>
                        <span style="font-weight: 700; width: 16px; text-align: center;">${item.qty}</span>
                        <button class="btn-qty" onclick="modifyQty(${item.id}, 'increase')">+</button>
                    </div>
                </td>
                <td style="font-weight: 700;">₱${parseFloat(item.subtotal).toFixed(2)}</td>
                <td>
                    <button class="btn-remove-item" onclick="modifyQty(${item.id}, 'remove')">✕</button>
                </td>
            `;
            tbody.appendChild(tr);
        });

        saveBtn.disabled = false;
        const scrollContainer = document.querySelector('.cart-scroll-container');
        if (scrollContainer) {
            scrollContainer.scrollTop = scrollContainer.scrollHeight;
        }
    }

    totalSpan.innerText = total.toFixed(2);
}

// ORDER TYPE SELECTOR
function selectOrderType(type, btnElement) {
    document.querySelectorAll('.type-toggle-btn').forEach(btn => btn.classList.remove('active'));
    btnElement.classList.add('active');
    document.getElementById('pos_order_type').value = type;
}

// RECEIPT PREVIEW MODAL FUNCTIONS
function openReceiptPreview() {
    if (!lastPrintedOrder) return;

    const modal = document.getElementById('receiptModal');
    const itemsBody = document.getElementById('modal-receipt-items');
    const totalSpan = document.getElementById('modal-receipt-total');
    const metaSpan = document.getElementById('modal-receipt-meta');
    const qTagSpan = document.getElementById('modal-receipt-queue-tag');
    const qMetaSpan = document.getElementById('modal-receipt-queue-meta');

    const qTag = lastPrintedOrder.queue_tag || '#' + String(lastPrintedOrder.order_id).padStart(3, '0');
    const ordType = lastPrintedOrder.order_type || 'Dine In';
    const custName = lastPrintedOrder.customer_name || 'Guest';

    if (qTagSpan) qTagSpan.textContent = qTag;
    if (qMetaSpan) qMetaSpan.textContent = `${ordType} • ${custName}`;

    metaSpan.innerHTML = `Order #${lastPrintedOrder.order_id} | Staff: ${escapeHtml(lastPrintedOrder.staff)}<br>${lastPrintedOrder.date}`;
    itemsBody.innerHTML = '';
    
    lastPrintedOrder.items.forEach(item => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${item.qty}x</td>
            <td>${escapeHtml(item.name)}</td>
            <td style="text-align: right;">₱${parseFloat(item.subtotal).toFixed(2)}</td>
        `;
        itemsBody.appendChild(row);
    });

    totalSpan.innerText = `₱${parseFloat(lastPrintedOrder.total).toFixed(2)}`;
    modal.style.display = 'flex';
}

function closeReceiptPreview() {
    document.getElementById('receiptModal').style.display = 'none';
}

// PRINTING ANIMATION & ORDER SUBMISSION
function processOrderWithAnimation() {
    const saveBtn = document.getElementById('save-btn');
    const previewBtn = document.getElementById('preview-btn');

    saveBtn.disabled = true;
    previewBtn.disabled = true;

    const orderNumInput = document.getElementById('pos_order_number');
    const custNameInput = document.getElementById('pos_customer_name');
    const orderTypeInput = document.getElementById('pos_order_type');

    const formData = new FormData();
    formData.append('ajax_place_order', '1');
    formData.append('order_number', orderNumInput ? orderNumInput.value.trim() : '');
    formData.append('customer_name', custNameInput ? custNameInput.value.trim() : '');
    formData.append('order_type', orderTypeInput ? orderTypeInput.value : 'Dine In');

    fetch('order_management.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            updateCartUI([]);
            if (custNameInput) custNameInput.value = '';
            if (orderNumInput && data.next_queue_number) {
                orderNumInput.value = data.next_queue_number;
                orderNumInput.placeholder = data.next_queue_number;
                orderNumInput.dataset.userEdited = 'false';
            }
            

            triggerSidePrintingAnimation(data);
            addNewTransactionToTable(data);
        } else {
            alert(data.message || 'Failed to place order.');
            saveBtn.disabled = false;
        }
    })
    .catch(err => {
        console.error('Error saving order:', err);
        saveBtn.disabled = false;
    });
}

function triggerSidePrintingAnimation(orderData) {
    const wrapper = document.getElementById('receipt-paper-wrapper');
    const paper = document.getElementById('receipt-paper');
    const meta = document.getElementById('receipt-meta');
    const itemsBody = document.getElementById('receipt-items-body');
    const totalSpan = document.getElementById('receipt-total');
    const statusText = document.getElementById('printer-status-text');
    const previewBtn = document.getElementById('preview-btn');
    const receiptQTag = document.getElementById('receipt-queue-tag');
    const receiptQMeta = document.getElementById('receipt-queue-meta');

    const qTag = orderData.queue_tag || '#' + String(orderData.order_id).padStart(3, '0');
    const ordType = orderData.order_type || 'Dine In';
    const custName = orderData.customer_name || 'Guest';

    if (receiptQTag) receiptQTag.textContent = qTag;
    if (receiptQMeta) receiptQMeta.textContent = `${ordType} • ${custName}`;

    statusText.innerText = "Printing...";
    meta.innerHTML = `Order #${orderData.order_id} | Staff: ${escapeHtml(orderData.staff)}<br>${orderData.date}`;
    itemsBody.innerHTML = '';
    
    orderData.items.forEach(item => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${item.qty}x</td>
            <td>${escapeHtml(item.name)}</td>
            <td style="text-align: right;">₱${parseFloat(item.subtotal).toFixed(2)}</td>
        `;
        itemsBody.appendChild(row);
    });

    totalSpan.innerText = `₱${parseFloat(orderData.total).toFixed(2)}`;

    wrapper.style.height = '0px';

    const fullHeight = paper.offsetHeight;
    let currentHeight = 0;
    const speed = fullHeight / 60;

    const printInterval = setInterval(() => {
        currentHeight += speed;
        if (currentHeight >= fullHeight) {
            currentHeight = fullHeight;
            clearInterval(printInterval);
            statusText.innerText = "Printed!";
            
            // RESET & STORE PRINTED RECEIPT, THEN ENABLE PREVIEW
            lastPrintedOrder = orderData;
            previewBtn.disabled = false;
        }
        wrapper.style.height = `${currentHeight}px`;
    }, 50);
}

function addNewTransactionToTable(orderData) {
    const tbody = document.getElementById('recent-transactions-body');
    const tr = document.createElement('tr');
    const qTag = orderData.queue_tag || '#' + String(orderData.order_id).padStart(3, '0');
    const ordType = orderData.order_type || 'Dine In';
    const custName = orderData.customer_name || 'Guest';

    tr.innerHTML = `
        <td><span class="queue-num-badge">${qTag}</span></td>
        <td style="font-weight: 700;">#${orderData.order_id}</td>
        <td>
            <div style="font-weight: 700; font-size: 13px;">${escapeHtml(custName)}</div>
            <div style="font-size: 11px; color: var(--text-muted); font-weight: 600;">${escapeHtml(ordType)}</div>
        </td>
        <td>${escapeHtml(orderData.staff)}</td>
        <td style="font-weight: 700;">₱${parseFloat(orderData.total).toFixed(2)}</td>
        <td>
            <button type="button" 
                    class="badge badge-process" 
                    onclick="toggleStatus(${orderData.order_id}, 'In Process', this)">
                In Process
            </button>
        </td>
        <td style="color: var(--text-muted); font-size: 13px;">${orderData.date}</td>
    `;
    tbody.insertBefore(tr, tbody.firstChild);
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString().replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}

// Real-time Polling for recent transactions and daily next queue
setInterval(async () => {
    try {
        const res = await fetch('api_get_orders.php?type=recent');
        const data = await res.json();
        
        if (data.orders) {
            updateRecentTransactionsUI(data.orders);
        }

        const nextRes = await fetch('api_get_orders.php?type=next_queue');
        const nextData = await nextRes.json();
        if (nextData.next_queue_number) {
            const orderNumEl = document.getElementById('pos_order_number');
            if (orderNumEl && document.activeElement !== orderNumEl && orderNumEl.dataset.userEdited !== 'true') {
                orderNumEl.value = nextData.next_queue_number;
                orderNumEl.placeholder = nextData.next_queue_number;
            }
        }
    } catch (err) {
        console.warn('Real-time sync warn:', err);
    }
}, 4500); // Check every 4.5 seconds

function updateRecentTransactionsUI(orders) {
    const tbody = document.getElementById('recent-transactions-body');
    
    // Check if there are changes by comparing IDs and Statuses
    const existingRows = Array.from(tbody.querySelectorAll('tr'));
    let existingDataStr = existingRows.map(row => {
        const idCell = row.querySelector('td:nth-child(2)');
        const statusBtn = row.querySelector('td:nth-child(6) button');
        return idCell ? (idCell.textContent.trim() + '_' + (statusBtn ? statusBtn.textContent.trim() : '')) : '';
    }).join('|');

    let newDataStr = orders.map(order => `#${order.id}_${order.order_status}`).join('|');

    if (existingDataStr === newDataStr) {
        return; // No changes
    }

    // Update DOM
    let newHtml = '';
    orders.forEach(order => {
        const rawStatus = order.order_status || 'In Process';
        const isComplete = (rawStatus === 'Completed');
        const badgeClass = isComplete ? 'badge-completed' : 'badge-process';
        const qTag = order.queue_tag || '#' + String(order.queue_number || order.id).padStart(3, '0');
        const ordType = order.order_type || 'Dine In';
        const custName = order.customer_name || 'Guest';

        newHtml += `
            <tr>
                <td><span class="queue-num-badge">${qTag}</span></td>
                <td style="font-weight: 700;">#${order.id}</td>
                <td>
                    <div style="font-weight: 700; font-size: 13px;">${escapeHtml(custName)}</div>
                    <div style="font-size: 11px; color: var(--text-muted); font-weight: 600;">${escapeHtml(ordType)}</div>
                </td>
                <td>${escapeHtml(order.staff_username)}</td>
                <td style="font-weight: 700;">₱${parseFloat(order.total_amount).toFixed(2)}</td>
                <td>
                    <button type="button" 
                            class="badge ${badgeClass}" 
                            onclick="toggleStatus(${order.id}, '${escapeHtml(rawStatus)}', this)">
                        ${escapeHtml(rawStatus)}
                    </button>
                </td>
                <td style="color: var(--text-muted); font-size: 13px;">${escapeHtml(order.order_date)}</td>
            </tr>
        `;
    });

    tbody.innerHTML = newHtml;
}
</script>

</body>
</html>