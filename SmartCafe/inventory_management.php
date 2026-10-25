<?php
session_start();
require_once 'config.php';

// Access Control: Only logged-in users with Admin or Manager roles can access
if (!isset($_SESSION['username']) || !in_array($_SESSION['role'], ['Admin', 'Manager'])) {
    header("Location: index.php");
    exit();
}

$message = "";
$message_type = "";

// Handle Form Submissions (Add, Update Stock, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Add New Inventory Item
    if (isset($_POST['action']) && $_POST['action'] === 'add_item') {
        $item_name = trim($_POST['item_name']);
        $category = trim($_POST['category']);
        $quantity = (int)$_POST['quantity'];
        $unit = trim($_POST['unit']);
        $min_threshold = (int)$_POST['min_threshold'];

        if (!empty($item_name)) {
            $stmt = $conn->prepare("INSERT INTO inventory (item_name, category, quantity, unit, min_threshold) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssisi", $item_name, $category, $quantity, $unit, $min_threshold);
            if ($stmt->execute()) {
                $message = "New raw material added successfully!";
                $message_type = "success";
            } else {
                $message = "Failed to add item: " . $conn->error;
                $message_type = "danger";
            }
            $stmt->close();
        }
    }

    // Quick Update Stock Quantity
    if (isset($_POST['action']) && $_POST['action'] === 'update_stock') {
        $item_id = (int)$_POST['item_id'];
        $new_quantity = (int)$_POST['quantity'];

        $stmt = $conn->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_quantity, $item_id);
        if ($stmt->execute()) {
            $message = "Stock level updated successfully!";
            $message_type = "success";
        } else {
            $message = "Failed to update stock.";
            $message_type = "danger";
        }
        $stmt->close();
    }

    // Delete Item
    if (isset($_POST['action']) && $_POST['action'] === 'delete_item') {
        $item_id = (int)$_POST['item_id'];
        $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
        $stmt->bind_param("i", $item_id);
        if ($stmt->execute()) {
            $message = "Item removed from inventory.";
            $message_type = "success";
        }
        $stmt->close();
    }
}

// Fetch Inventory Records
$inventory_query = mysqli_query($conn, "SELECT * FROM inventory ORDER BY item_name ASC");

// Fetch Summary Stats
$total_items = mysqli_query($conn, "SELECT COUNT(*) as count FROM inventory");
$total_items_count = mysqli_fetch_assoc($total_items)['count'] ?? 0;

$low_stock_query = mysqli_query($conn, "SELECT COUNT(*) as count FROM inventory WHERE quantity <= min_threshold");
$low_stock_count = mysqli_fetch_assoc($low_stock_query)['count'] ?? 0;

$total_bottles_query = mysqli_query($conn, "SELECT SUM(quantity) as total FROM inventory");
$total_bottles_count = mysqli_fetch_assoc($total_bottles_query)['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartCafe | Inventory Management</title>
    <?php include_once __DIR__ . '/pwa_head.php'; ?>

    <!-- Google Fonts & Font Awesome Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --bg-gradient: linear-gradient(135deg, #dbe4ee 0%, #e8edf3 50%, #d8e2ed 100%);
            --glass-card: rgba(255, 255, 255, 0.65);
            --glass-border: rgba(255, 255, 255, 0.8);
            --glass-header: rgba(255, 255, 255, 0.45);
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
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: var(--bg-gradient);
            background-attachment: fixed;
            color: var(--text-dark);
            min-height: 100vh;
            padding: 30px 20px;
        }

        .container {
            max-width: 1300px;
            margin: 0 auto;
        }

        /* Header */
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

        /* Alert Banners */
        .alert-msg {
            padding: 14px 20px;
            border-radius: 16px;
            margin-bottom: 25px;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            animation: fadeIn 0.3s ease;
        }

        .alert-success {
            background: rgba(220, 252, 231, 0.9);
            color: #15803d;
            border: 1px solid rgba(187, 247, 208, 0.8);
        }

        .alert-danger {
            background: rgba(254, 226, 226, 0.9);
            color: #b91c1c;
            border: 1px solid rgba(254, 202, 202, 0.8);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 35px rgba(148, 163, 184, 0.25);
        }

        .stat-card h4 {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-card p {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-dark);
            margin-top: 8px;
        }

        .stat-card.alert p {
            color: #dc2626;
        }

        /* Main Grid Layout */
        .main-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 25px;
            align-items: start;
        }

        @media (max-width: 992px) {
            .main-layout {
                grid-template-columns: 1fr;
            }
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
        }

        /* Dark Card Header */
        .card-header {
            background: #1e293b;
            padding: 20px 28px;
            font-size: 16px;
            font-weight: 800;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            border-bottom: 1px solid #334155;
        }

        .card-header-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 26px 28px;
        }

        /* Live Search Bar */
        .search-box {
            display: flex;
            align-items: center;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(203, 213, 225, 0.8);
            border-radius: 12px;
            padding: 8px 14px;
            gap: 8px;
            margin: 20px 28px 0;
        }

        .search-box i {
            color: var(--text-muted);
            font-size: 14px;
        }

        .search-box input {
            border: none;
            background: transparent;
            outline: none;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dark);
            width: 100%;
        }

        /* Form Inputs */
        .form-group {
            margin-bottom: 18px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
        }

        select, input[type="text"], input[type="number"] {
            padding: 12px 16px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.75);
            border: 1px solid rgba(203, 213, 225, 0.8);
            color: var(--text-dark);
            outline: none;
            font-family: inherit;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        select option {
            background-color: #ffffff;
            color: var(--text-dark);
        }

        select:focus, input:focus {
            border-color: #94a3b8;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.15);
        }

        .btn-submit {
            width: 100%;
            background: var(--dark-btn);
            color: #ffffff;
            border: none;
            padding: 14px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 4px 12px rgba(45, 55, 72, 0.2);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
        }

        .btn-submit:hover {
            background: var(--dark-btn-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(45, 55, 72, 0.3);
        }

        /* Table Styling */
        .table-wrapper {
            overflow-x: auto;
        }

        .inventory-table {
            width: 100%;
            border-collapse: collapse;
        }

        .inventory-table th, .inventory-table td {
            padding: 18px 28px;
            text-align: left;
        }

        .inventory-table th {
            background: rgba(241, 245, 249, 0.6);
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }

        .inventory-table td {
            border-bottom: 1px solid rgba(226, 232, 240, 0.5);
            font-size: 14px;
            font-weight: 500;
        }

        .inventory-table tr:last-child td {
            border-bottom: none;
        }

        .inventory-table tr:hover td {
            background: rgba(255, 255, 255, 0.4);
        }

        /* Status Badges */
        .badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-success {
            background: rgba(220, 252, 231, 0.9);
            color: #15803d;
            border: 1px solid rgba(187, 247, 208, 0.8);
        }

        .badge-warning {
            background: rgba(254, 226, 226, 0.9);
            color: #b91c1c;
            border: 1px solid rgba(254, 202, 202, 0.8);
        }

        /* Inline Forms & Buttons */
        .inline-qty-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .inline-qty-form input[type="number"] {
            width: 75px;
            padding: 8px 10px;
            text-align: center;
        }

        .btn-update {
            background: rgba(219, 234, 254, 0.8);
            color: #2563eb;
            border: none;
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            font-size: 12px;
            transition: all 0.2s ease;
        }

        .btn-update:hover {
            background: rgba(191, 219, 254, 1);
            transform: translateY(-1px);
        }

        .btn-delete {
            background: rgba(254, 226, 226, 0.8);
            color: #dc2626;
            border: none;
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            font-size: 12px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-delete:hover {
            background: rgba(254, 202, 202, 1);
            transform: translateY(-1px);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
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
            .input-row {
                flex-direction: column;
                align-items: stretch;
            }
            .btn-add {
                width: 100%;
                justify-content: center;
            }
            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border-radius: 18px;
            }
            .inventory-table th, .inventory-table td {
                padding: 10px 8px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="header-title">
            <h1><i class="fa-solid fa-boxes-stacked"></i> Raw Material Inventory</h1>
            <p>Track bottles and stock levels for BesKape ingredients</p>
        </div>
        <div class="nav-links">
            <a href="dashboard.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Log Out</a>
        </div>
    </div>

    <!-- Feedback Message -->
    <?php if (!empty($message)): ?>
        <div class="alert-msg alert-<?php echo $message_type; ?>">
            <i class="fa-solid <?php echo ($message_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Summary Widgets -->
    <div class="stats-grid">
        <div class="stat-card">
            <h4>Total Items Listed</h4>
            <p><?php echo $total_items_count; ?></p>
        </div>
        <div class="stat-card">
            <h4>Total Bottles in Stock</h4>
            <p><?php echo $total_bottles_count ?? 0; ?></p>
        </div>
        <div class="stat-card alert">
            <h4>Low Stock Alerts</h4>
            <p><?php echo $low_stock_count; ?></p>
        </div>
    </div>

    <!-- Main Content Layout -->
    <div class="main-layout">
        
        <!-- Add New Raw Material Form -->
        <div class="card-box">
            <div class="card-header">
                <div class="card-header-title">
                    <i class="fa-solid fa-plus-circle"></i> Add New Material
                </div>
            </div>
            <div class="card-body">
                <form action="inventory_management.php" method="POST">
                    <input type="hidden" name="action" value="add_item">
                    
                    <div class="form-group">
                        <label>Item Name</label>
                        <input type="text" name="item_name" placeholder="e.g., Fresh Whole Milk" required>
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" required>
                            <option value="Dairy">Dairy</option>
                            <option value="Syrup">Syrup</option>
                            <option value="Coffee">Coffee</option>
                            <option value="Sauce">Sauce</option>
                            <option value="Packaging">Packaging</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Current Stock Quantity</label>
                        <input type="number" name="quantity" min="0" value="0" required>
                    </div>

                    <div class="form-group">
                        <label>Unit Measurement</label>
                        <select name="unit">
                            <option value="bottle">Bottle(s)</option>
                            <option value="pack">Pack(s)</option>
                            <option value="box">Box(es)</option>
                            <option value="kg">Kilogram (kg)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Low Stock Warning Threshold</label>
                        <input type="number" name="min_threshold" min="1" value="5" required>
                    </div>

                    <button type="submit" class="btn-submit"><i class="fa-solid fa-plus"></i> Add Stock Item</button>
                </form>
            </div>
        </div>

        <!-- Inventory List Table -->
        <div class="card-box">
            <div class="card-header">
                <div class="card-header-title">
                    <i class="fa-solid fa-list-check"></i> Current Stock List
                </div>
            </div>

            <!-- Interactive Filter -->
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="inventorySearch" placeholder="Search materials or categories in real time..." onkeyup="filterInventory()">
            </div>

            <div class="table-wrapper">
                <table class="inventory-table" id="inventoryTable">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($inventory_query) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($inventory_query)): ?>
                                <?php $is_low = $row['quantity'] <= $row['min_threshold']; ?>
                                <tr class="inventory-row">
                                    <td class="item-name"><strong><?php echo htmlspecialchars($row['item_name']); ?></strong></td>
                                    <td class="item-cat"><?php echo htmlspecialchars($row['category']); ?></td>
                                    <td>
                                        <form action="inventory_management.php" method="POST" class="inline-qty-form">
                                            <input type="hidden" name="action" value="update_stock">
                                            <input type="hidden" name="item_id" value="<?php echo $row['id']; ?>">
                                            <input type="number" name="quantity" value="<?php echo $row['quantity']; ?>" min="0">
                                            <span style="font-size:13px; color: var(--text-muted);"><?php echo htmlspecialchars($row['unit']); ?>(s)</span>
                                            <button type="submit" class="btn-update">Save</button>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if ($is_low): ?>
                                            <span class="badge badge-warning"><i class="fa-solid fa-triangle-exclamation"></i> Low Stock</span>
                                        <?php else: ?>
                                            <span class="badge badge-success"><i class="fa-solid fa-check"></i> In Stock</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form action="inventory_management.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this item?');" style="margin:0;">
                                            <input type="hidden" name="action" value="delete_item">
                                            <input type="hidden" name="item_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" class="btn-delete"><i class="fa-solid fa-trash"></i> Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; color: var(--text-muted); font-style: italic; padding: 35px;">No raw materials added yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
function filterInventory() {
    const input = document.getElementById("inventorySearch");
    const filter = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll("#inventoryTable .inventory-row");

    rows.forEach(row => {
        const nameText = row.querySelector(".item-name") ? row.querySelector(".item-name").textContent.toLowerCase() : "";
        const catText = row.querySelector(".item-cat") ? row.querySelector(".item-cat").textContent.toLowerCase() : "";

        if (nameText.includes(filter) || catText.includes(filter)) {
            row.style.display = "";
        } else {
            row.style.display = "none";
        }
    });
}
</script>

</body>
</html>