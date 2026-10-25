<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['username'])) { 
    header("Location: index.php"); 
    exit(); 
}

$can_edit = ($_SESSION['role'] === 'Admin' ||$_SESSION['role'] === 'Manager');

// --- AJAX STATUS UPDATE ---
if (isset($_POST['ajax_update']) && $can_edit) {
    $id = (int)$_POST['item_id'];
    $new_status = mysqli_real_escape_string($conn, $_POST['new_status']);
    
    if (mysqli_query($conn, "UPDATE menu SET status = '$new_status' WHERE id = '$id'")) {
        // RECORD LOG: Status Change
        $user = $_SESSION['username'] ?? 'User';
        $role = $_SESSION['role'] ?? 'Staff';
        $token = $_SESSION['session_token'] ?? null;
        $log_action = "Changed item ID #$id status to $new_status";
        $log_stmt = mysqli_prepare($conn, "INSERT INTO staff_logs (username, role, session_token, action) VALUES (?, ?, ?, ?)");
        if ($log_stmt) {
            mysqli_stmt_bind_param($log_stmt, "ssss", $user, $role, $token, $log_action);
            mysqli_stmt_execute($log_stmt);
            mysqli_stmt_close($log_stmt);
        }
        echo "success";
    } else {
        echo "error";
    }
    exit(); 
}

// --- DELETE LOGIC ---
if (isset($_GET['delete_id']) && $can_edit) {
    $id = (int)$_GET['delete_id'];
    
    // Record log before deleting
    $user = $_SESSION['username'] ?? 'User';
    $role = $_SESSION['role'] ?? 'Staff';
    $token = $_SESSION['session_token'] ?? null;
    $log_action = "Deleted menu item ID #$id";
    $log_stmt = mysqli_prepare($conn, "INSERT INTO staff_logs (username, role, session_token, action) VALUES (?, ?, ?, ?)");
    if ($log_stmt) {
        mysqli_stmt_bind_param($log_stmt, "ssss", $user, $role, $token, $log_action);
        mysqli_stmt_execute($log_stmt);
        mysqli_stmt_close($log_stmt);
    }

    $sql = "DELETE FROM menu WHERE id = '$id'";
    mysqli_query($conn, $sql);
    header("Location: menu_management.php");
    exit();
}

// --- ADD LOGIC ---
if (isset($_POST['add_item']) && $can_edit) {
    $name = mysqli_real_escape_string($conn, trim($_POST['item_name'] ?? ''));
    $cat = mysqli_real_escape_string($conn, trim($_POST['category'] ?? ''));
    $price = (float)($_POST['price'] ?? 0);
    $status = mysqli_real_escape_string($conn, trim($_POST['status'] ?? 'Available'));

    $sql = "INSERT INTO menu (item_name, category, price, status) VALUES ('$name', '$cat', '$price', '$status')";
    if (mysqli_query($conn, $sql)) {
        // RECORD LOG: New Item
        $user = $_SESSION['username'] ?? 'User';
        $role = $_SESSION['role'] ?? 'Staff';
        $token = $_SESSION['session_token'] ?? null;
        $log_action = "Added new menu item: $name";
        $log_stmt = mysqli_prepare($conn, "INSERT INTO staff_logs (username, role, session_token, action) VALUES (?, ?, ?, ?)");
        if ($log_stmt) {
            mysqli_stmt_bind_param($log_stmt, "ssss", $user, $role, $token, $log_action);
            mysqli_stmt_execute($log_stmt);
            mysqli_stmt_close($log_stmt);
        }
    }
    header("Location: menu_management.php");
    exit();
}

// --- FETCH & GROUP ITEMS BY CATEGORY ---
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

$menu_by_category = array_fill_keys($defined_categories, []);

$raw_items = mysqli_query($conn, "SELECT * FROM menu ORDER BY item_name ASC");
if ($raw_items) {
    while ($row = mysqli_fetch_assoc($raw_items)) {
        $cat =$row['category'];
        if (!isset($menu_by_category[$cat])) {
            $menu_by_category[$cat] = [];
        }
        $menu_by_category[$cat][] =$row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Management | SmartCafe</title>
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
            --pill-bg: rgba(255, 255, 255, 0.85);
            --pill-border: rgba(226, 232, 240, 0.8);
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

        /* Search Filter Section */
        .search-container {
            background: var(--glass-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 16px 24px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .search-container i {
            color: var(--text-muted);
            font-size: 16px;
        }

        .search-input {
            width: 100%;
            border: none;
            background: transparent;
            outline: none;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .search-input::placeholder {
            color: var(--text-muted);
            font-weight: 500;
        }

        /* Category Quick-Pills (With Dark Hover Highlight) */
        .category-nav {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }

        .category-pill {
            background: var(--pill-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--pill-border);
            color: var(--text-dark);
            padding: 10px 20px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.25s ease;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .category-pill:hover {
            background: #1e293b;
            color: #ffffff;
            border-color: #1e293b;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(30, 41, 59, 0.25);
        }

        /* Form */
        .add-form {
            background: var(--glass-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            padding: 26px 32px;
            border-radius: 24px;
            margin-bottom: 35px;
            box-shadow: var(--shadow);
        }

        .add-form h3 {
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 20px;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .input-row {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .input-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-width: 180px;
        }

        .input-group label {
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

        .btn-add {
            background: var(--dark-btn);
            color: #ffffff;
            border: none;
            padding: 12px 28px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 12px rgba(45, 55, 72, 0.2);
            height: 45px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-add:hover {
            background: var(--dark-btn-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(45, 55, 72, 0.3);
        }

        /* Category Card Section */
        .category-section {
            margin-bottom: 35px;
            scroll-margin-top: 25px;
            background: var(--glass-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        /* Dark Header for Category */
        .category-header {
            background: #1e293b;
            padding: 20px 32px;
            font-size: 18px;
            font-weight: 800;
            color: #ffffff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #334155;
        }

        .item-count-badge {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #f1f5f9;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        /* Table Design */
        .menu-table {
            width: 100%;
            border-collapse: collapse;
        }

        .menu-table th, .menu-table td {
            padding: 18px 32px;
            text-align: left;
        }

        .menu-table th {
            background: rgba(241, 245, 249, 0.6);
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }

        .menu-table td {
            border-bottom: 1px solid rgba(226, 232, 240, 0.5);
            font-size: 14px;
            font-weight: 500;
        }

        .menu-table tr:last-child td {
            border-bottom: none;
        }

        .menu-table tr:hover td {
            background: rgba(255, 255, 255, 0.4);
        }

        /* Status Dropdown & Badges */
        .status-select {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            border: 1px solid transparent;
            outline: none;
            transition: all 0.25s ease;
        }

        .status-available {
            background: rgba(220, 252, 231, 0.9);
            color: #15803d;
            border-color: rgba(187, 247, 208, 0.8);
        }

        .status-out {
            background: rgba(254, 226, 226, 0.9);
            color: #b91c1c;
            border-color: rgba(254, 202, 202, 0.8);
        }

        .save-indicator {
            font-size: 12px;
            margin-left: 10px;
            color: #16a34a;
            opacity: 0;
            transition: opacity 0.3s ease;
            font-weight: 700;
        }

        .show-save {
            opacity: 1;
        }

        .no-items {
            padding: 35px;
            text-align: center;
            color: var(--text-muted);
            font-style: italic;
            font-size: 14px;
        }

        /* Actions styling */
        .action-link {
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            padding: 8px 14px;
            border-radius: 10px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .action-edit {
            color: #2563eb;
            background: rgba(219, 234, 254, 0.6);
            margin-right: 8px;
        }

        .action-edit:hover {
            background: rgba(191, 219, 254, 0.9);
        }

        .action-delete {
            color: #dc2626;
            background: rgba(254, 226, 226, 0.6);
        }

        .action-delete:hover {
            background: rgba(254, 202, 202, 0.9);
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
            .category-header {
                padding: 14px 16px;
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .category-section {
                border-radius: 18px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .menu-table th, .menu-table td {
                padding: 10px 8px;
                font-size: 13px;
            }
            .input-row {
                flex-direction: column;
                align-items: stretch;
            }
            .btn-add {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<div class="container">
    
    <!-- Header -->
    <div class="header">
        <div class="header-title">
            <h1><i class="fa-solid fa-utensils"></i> Menu Management</h1>
            <p>Manage categories, items, and pricing for BesKape</p>
        </div>
        <div class="nav-links">
            <a href="dashboard.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Log Out</a>
        </div>
    </div>

    <!-- Live Search Filter -->
    <div class="search-container">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="menuSearchInput" class="search-input" placeholder="Search menu items by name or price across all categories..." onkeyup="filterMenuItems()">
    </div>

    <!-- Category Quick-Pills -->
    <div class="category-nav">
        <?php foreach ($defined_categories as$category_name): ?>
            <a href="#cat-<?php echo urlencode($category_name); ?>" class="category-pill">
                <i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($category_name); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($can_edit): ?>
        <div class="add-form">
            <h3><i class="fa-solid fa-plus-circle"></i> Add New Menu Item</h3>
            <form method="POST" class="input-row">
                <div class="input-group">
                    <label>Item Name</label>
                    <input type="text" name="item_name" placeholder="e.g. Salted Caramel (16oz)" required>
                </div>
                <div class="input-group">
                    <label>Category</label>
                    <select name="category" required>
                        <?php foreach ($defined_categories as$category_option): ?>
                            <option value="<?php echo htmlspecialchars($category_option); ?>">
                                <?php echo htmlspecialchars($category_option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <label>Price</label>
                    <input type="number" step="0.01" name="price" placeholder="0.00" required>
                </div>
                <div class="input-group">
                    <label>Initial Status</label>
                    <select name="status">
                        <option value="Available">Available</option>
                        <option value="Out of Stock">Out of Stock</option>
                        <option value="Unavailable">Unavailable</option>
                    </select>
                </div>
                <button type="submit" name="add_item" class="btn-add"><i class="fa-solid fa-plus"></i> Add Item</button>
            </form>
        </div>
    <?php endif; ?>

    <!-- Category Tables -->
    <?php foreach ($menu_by_category as $category_name =>$items): ?>
        <div class="category-section" id="cat-<?php echo urlencode($category_name); ?>">
            <div class="category-header">
                <span><i class="fa-solid fa-layer-group"></i> <?php echo htmlspecialchars($category_name); ?></span>
                <span class="item-count-badge"><?php echo count($items); ?> Items</span>
            </div>

            <?php if (empty($items)): ?>
                <div class="no-items">No items found in this category.</div>
            <?php else: ?>
                <table class="menu-table">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Price</th>
                            <th>Status</th>
                            <?php if ($can_edit): ?><th>Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as$row): ?>
                        <tr class="menu-item-row">
                            <td class="item-name-cell"><strong><?php echo htmlspecialchars($row['item_name']); ?></strong></td>
                            <td class="item-price-cell">₱<?php echo number_format($row['price'], 2); ?></td>
                            <td>
                                <?php if ($can_edit): ?>
                                    <select class="status-select <?php echo ($row['status'] == 'Available') ? 'status-available' : 'status-out'; ?>" 
                                            onchange="updateStatus(<?php echo $row['id']; ?>, this)">
                                        <option value="Available" <?php if($row['status'] == 'Available') echo 'selected'; ?>>Available</option>
                                        <option value="Out of Stock" <?php if($row['status'] == 'Out of Stock') echo 'selected'; ?>>Out of Stock</option>
                                        <option value="Unavailable" <?php if($row['status'] == 'Unavailable') echo 'selected'; ?>>Unavailable</option>
                                    </select>
                                    <span class="save-indicator" id="save-<?php echo $row['id']; ?>"><i class="fa-solid fa-check"></i> Saved</span>
                                <?php else: ?>
                                    <span class="status-select <?php echo ($row['status'] == 'Available') ? 'status-available' : 'status-out'; ?>">
                                        <?php echo htmlspecialchars($row['status']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <?php if ($can_edit): ?>
                            <td>
                                <a href="edit_menu.php?id=<?php echo $row['id']; ?>" class="action-link action-edit"><i class="fa-solid fa-pen"></i> Edit</a>
                                <a href="menu_management.php?delete_id=<?php echo $row['id']; ?>" 
                                class="action-link action-delete" onclick="return confirm('Delete this item?');"><i class="fa-solid fa-trash"></i> Delete</a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<script>
async function updateStatus(itemId, selectElement) {
    const newStatus = selectElement.value;
    const saveLabel = document.getElementById('save-' + itemId);
    const oldClass = selectElement.className;
    
    // Optimistic Update
    selectElement.className = "status-select " + (newStatus === "Available" ? "status-available" : "status-out");

    try {
        const response = await fetch("menu_management.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: `ajax_update=1&item_id=${itemId}&new_status=${encodeURIComponent(newStatus)}`
        }); 

        const result = await response.text();

        if (result.trim() === "success") {
            saveLabel.classList.add('show-save');
            setTimeout(() => saveLabel.classList.remove('show-save'), 1500);
        } else {
            throw new Error("Failed to save");
        }
    } catch (error) {
        alert("Server error. Reverting status.");
        selectElement.className = oldClass;
        selectElement.value = (newStatus === "Available" ? "Out of Stock" : "Available");
    }
}

// Live Search Filter Across All Categories
function filterMenuItems() {
    const query = document.getElementById('menuSearchInput').value.toLowerCase().trim();
    const sections = document.querySelectorAll('.category-section');

    sections.forEach(section => {
        const rows = section.querySelectorAll('.menu-item-row');
        let visibleInSection = 0;

        rows.forEach(row => {
            const name = row.querySelector('.item-name-cell')?.textContent.toLowerCase() || '';
            const price = row.querySelector('.item-price-cell')?.textContent.toLowerCase() || '';

            if (name.includes(query) || price.includes(query)) {
                row.style.display = '';
                visibleInSection++;
            } else {
                row.style.display = 'none';
            }
        });

        if (query !== '' && visibleInSection === 0) {
            section.style.display = 'none';
        } else {
            section.style.display = '';
        }
    });
}
</script>

</body>
</html>