<?php
session_start();
require_once 'config.php';

// Auth Guard: Only Admin and Manager can access
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Manager'], true)) {
    header("Location: menu_management.php?error=no_permission");
    exit();
}

$defined_categories = [
    'Coffee',
    'Non Coffee',
    'Matcha',
    'Fruit Soda',
    'Hot Drinks',
    'Rice Meals',
    'Snacks',
    'Add Ons'
];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: menu_management.php");
    exit();
}

// 1. Get current item details
$stmt = mysqli_prepare($conn, "SELECT * FROM menu WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$item = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$item) {
    header("Location: menu_management.php?error=not_found");
    exit();
}

$error_msg = '';

// 2. Handle the Update request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $name = trim($_POST['item_name'] ?? '');
    $cat = trim($_POST['category'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $status = trim($_POST['status'] ?? 'Available');

    $allowed_statuses = ['Available', 'Out of Stock', 'Unavailable'];
    if (!in_array($status, $allowed_statuses, true)) {
        $status = 'Available';
    }

    if (!empty($name) && in_array($cat, $defined_categories, true) && $price >= 0) {
        $update_stmt = mysqli_prepare($conn, "UPDATE menu SET item_name = ?, category = ?, price = ?, status = ? WHERE id = ?");
        mysqli_stmt_bind_param($update_stmt, "ssdsi", $name, $cat, $price, $status, $id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            mysqli_stmt_close($update_stmt);

            // RECORD LOG: Manual Edit for Staff Activity Tracking
            $user = $_SESSION['username'] ?? 'User';
            $role = $_SESSION['role'] ?? 'Staff';
            $token = $_SESSION['session_token'] ?? null;
            $log_action = "Updated menu item #$id: $name (Category: $cat, Price: $price, Status: $status)";

            $log_stmt = mysqli_prepare($conn, "INSERT INTO staff_logs (username, role, session_token, action) VALUES (?, ?, ?, ?)");
            if ($log_stmt) {
                mysqli_stmt_bind_param($log_stmt, "ssss", $user, $role, $token, $log_action);
                mysqli_stmt_execute($log_stmt);
                mysqli_stmt_close($log_stmt);
            }

            header("Location: menu_management.php?updated=1");
            exit();
        } else {
            $error_msg = "Database update failed. Please try again.";
        }
    } else {
        $error_msg = "Please provide valid item details.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Menu Item | SmartCafe</title>
    <?php include_once __DIR__ . '/pwa_head.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #dbe4ee 0%, #e8edf3 50%, #d8e2ed 100%);
            --glass-card: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.85);
            --text-main: #0f172a;
            --text-muted: #64748b;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: var(--bg-gradient);
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            margin: 0;
            color: var(--text-main);
        }

        .edit-container { 
            width: 100%; 
            max-width: 520px; 
            margin: 20px auto; 
            background: var(--glass-card); 
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 36px 32px; 
            border-radius: 24px; 
            border: 1px solid var(--glass-border); 
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08); 
        }

        .back-link {
            text-decoration: none;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            transition: color 0.2s;
        }

        .back-link:hover { color: var(--text-main); }

        .edit-header {
            margin-bottom: 24px;
        }

        .edit-header h2 {
            color: var(--text-main);
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 6px 0;
        }

        .edit-header p {
            color: var(--text-muted);
            font-size: 13px;
            margin: 0;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: #b91c1c;
            border: 1px solid rgba(239, 68, 68, 0.25);
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .input-group { 
            margin-bottom: 20px; 
        }

        .input-group label { 
            display: block; 
            margin-bottom: 8px; 
            font-weight: 700; 
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #475569;
        }

        input, select { 
            width: 100%; 
            padding: 13px 16px; 
            border: 1px solid rgba(203, 213, 225, 0.9); 
            border-radius: 12px; 
            background: rgba(255, 255, 255, 0.9);
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-main);
            outline: none;
            transition: all 0.2s;
            box-sizing: border-box;
        }

        input:focus, select:focus {
            border-color: #0f172a;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.1);
        }

        .btn-save { 
            background: #0f172a; 
            color: white; 
            border: none; 
            padding: 14px 24px; 
            border-radius: 14px; 
            cursor: pointer; 
            font-weight: 700; 
            font-size: 14px;
            transition: all 0.2s; 
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.2);
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 26px;
        }

        .btn-save:hover { 
            background: #1e293b; 
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.25);
        }

        @media (max-width: 480px) {
            .edit-container {
                padding: 24px 20px;
                border-radius: 20px;
            }
            .edit-header h2 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
<div class="edit-container">
    <a href="menu_management.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Menu</a>
    <div class="edit-header">
        <h2><i class="fa-solid fa-pen-to-square"></i> Edit Menu Item</h2>
        <p>Modify item details, pricing, and availability status</p>
    </div>

    <?php if (!empty($error_msg)): ?>
        <div class="alert-error">
            <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error_msg); ?>
        </div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="input-group">
            <label>Item Name</label>
            <input type="text" name="item_name" value="<?php echo htmlspecialchars($item['item_name']); ?>" required>
        </div>
        <div class="input-group">
            <label>Category</label>
            <select name="category" required>
                <?php foreach ($defined_categories as $cat_option): ?>
                    <option value="<?php echo htmlspecialchars($cat_option); ?>" <?php if ($item['category'] === $cat_option) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($cat_option); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="input-group">
            <label>Price (₱)</label>
            <input type="number" step="0.01" min="0" name="price" value="<?php echo htmlspecialchars($item['price']); ?>" required>
        </div>
        <div class="input-group">
            <label>Availability Status</label>
            <select name="status" required>
                <option value="Available" <?php if ($item['status'] === 'Available') echo 'selected'; ?>>Available</option>
                <option value="Out of Stock" <?php if ($item['status'] === 'Out of Stock') echo 'selected'; ?>>Out of Stock</option>
                <option value="Unavailable" <?php if ($item['status'] === 'Unavailable') echo 'selected'; ?>>Unavailable</option>
            </select>
        </div>
        <button type="submit" name="update_item" class="btn-save">
            <i class="fa-solid fa-floppy-disk"></i> Save Changes
        </button>
    </form>
</div>
</body>
</html>