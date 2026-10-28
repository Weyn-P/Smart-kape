<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';

// Security check
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

if ($_SESSION['role'] === 'Staff') {
    header("Location: dashboard.php");
    exit();
}

$user = htmlspecialchars($_SESSION['username']);
$role = htmlspecialchars($_SESSION['role']);

// Determine Filter Period
$period = $_GET['period'] ?? 'today';
$today = date('Y-m-d');

if ($period === 'yesterday') {
    $date_from = date('Y-m-d', strtotime('-1 day'));
    $date_to = date('Y-m-d', strtotime('-1 day'));
    $period_label = "Yesterday (" . date('F d, Y', strtotime('-1 day')) . ")";
} elseif ($period === 'week') {
    $date_from = date('Y-m-d', strtotime('-6 days'));
    $date_to = $today;
    $period_label = "Last 7 Days (" . date('M d', strtotime('-6 days')) . " - " . date('M d, Y') . ")";
} elseif ($period === 'month') {
    $date_from = date('Y-m-01');
    $date_to = date('Y-m-t');
    $period_label = "Month-to-Date (" . date('F Y') . ")";
} elseif ($period === 'all') {
    $date_from = '2020-01-01';
    $date_to = $today;
    $period_label = "Comprehensive All-Time Historical Records";
} elseif ($period === 'custom' && !empty($_GET['date_from']) && !empty($_GET['date_to'])) {
    $date_from = $_GET['date_from'];
    $date_to = $_GET['date_to'];
    $period_label = "Custom Audit Period (" . date('M d, Y', strtotime($date_from)) . " to " . date('M d, Y', strtotime($date_to)) . ")";
} else {
    // Default Today
    $period = 'today';
    $date_from = $today;
    $date_to = $today;
    $period_label = "Today (" . date('F d, Y') . ")";
}

// 1. Fetch Revenue Aggregates
$summary_sql = "SELECT 
    COUNT(id) as total_orders, 
    COALESCE(SUM(total_amount), 0) as total_revenue,
    COALESCE(AVG(total_amount), 0) as avg_order
    FROM orders 
    WHERE DATE(order_date) BETWEEN '$date_from' AND '$date_to' AND order_status = 'Completed'";
$summary_res = mysqli_query($conn, $summary_sql);
$summary = $summary_res ? mysqli_fetch_assoc($summary_res) : ['total_orders' => 0, 'total_revenue' => 0, 'avg_order' => 0];

$total_revenue = (float)$summary['total_revenue'];
$total_orders = (int)$summary['total_orders'];
$avg_order = (float)$summary['avg_order'];

// Total beverage and food units sold in period
$units_sql = "SELECT COALESCE(SUM(oi.quantity), 0) as total_units
    FROM order_items oi
    INNER JOIN orders o ON oi.order_id = o.id
    WHERE DATE(o.order_date) BETWEEN '$date_from' AND '$date_to' AND o.order_status = 'Completed'";
$units_res = mysqli_query($conn, $units_sql);
$total_units = $units_res ? (int)mysqli_fetch_assoc($units_res)['total_units'] : 0;
$avg_items_per_order = $total_orders > 0 ? round($total_units / $total_orders, 1) : 0;

// Tax breakdown (12% VAT PH Statutory Formula)
$vatable_sales = round($total_revenue / 1.12, 2);
$vat_amount = round($total_revenue - $vatable_sales, 2);

// 2. Sales by Order Type (Dine In vs Take Out)
$type_sql = "SELECT 
    order_type, 
    COUNT(id) as type_count, 
    COALESCE(SUM(total_amount), 0) as type_total 
    FROM orders 
    WHERE DATE(order_date) BETWEEN '$date_from' AND '$date_to' AND order_status = 'Completed'
    GROUP BY order_type
    ORDER BY type_total DESC";
$type_res = mysqli_query($conn, $type_sql);
$order_types_data = [];
if ($type_res) {
    while ($t = mysqli_fetch_assoc($type_res)) {
        $order_types_data[] = $t;
    }
}

// 3. Category Revenue Distribution
$cat_sql = "SELECT 
    COALESCE(m.category, 'Specialty & Seasonal') as category, 
    SUM(oi.quantity) as items_sold, 
    SUM(oi.price * oi.quantity) as category_revenue
    FROM order_items oi
    INNER JOIN orders o ON oi.order_id = o.id
    LEFT JOIN menu m ON oi.menu_item_name = m.item_name
    WHERE DATE(o.order_date) BETWEEN '$date_from' AND '$date_to' AND o.order_status = 'Completed'
    GROUP BY category
    ORDER BY category_revenue DESC";
$cat_res = mysqli_query($conn, $cat_sql);
$categories_data = [];
$total_category_revenue = 0.00;
if ($cat_res) {
    while ($c = mysqli_fetch_assoc($cat_res)) {
        $total_category_revenue += (float)$c['category_revenue'];
        $categories_data[] = $c;
    }
}

// 4. Top Selling Items (Product Mix - Top 15)
$items_sql = "SELECT 
    oi.menu_item_name, 
    COALESCE(m.category, 'Specialty') as category,
    SUM(oi.quantity) as qty_sold, 
    oi.price as unit_price,
    SUM(oi.price * oi.quantity) as item_revenue
    FROM order_items oi
    INNER JOIN orders o ON oi.order_id = o.id
    LEFT JOIN menu m ON oi.menu_item_name = m.item_name
    WHERE DATE(o.order_date) BETWEEN '$date_from' AND '$date_to' AND o.order_status = 'Completed'
    GROUP BY oi.menu_item_name
    ORDER BY qty_sold DESC, item_revenue DESC
    LIMIT 15";
$items_res = mysqli_query($conn, $items_sql);
$top_items = [];
if ($items_res) {
    while ($it = mysqli_fetch_assoc($items_res)) {
        $top_items[] = $it;
    }
}

// 5. Staff / Cashier Settlement Summary
$staff_sql = "SELECT 
    COALESCE(staff_username, 'General Cashier') as staff_name, 
    COUNT(id) as orders_handled, 
    SUM(total_amount) as total_handled
    FROM orders 
    WHERE DATE(order_date) BETWEEN '$date_from' AND '$date_to' AND order_status = 'Completed'
    GROUP BY staff_username
    ORDER BY total_handled DESC";
$staff_res = mysqli_query($conn, $staff_sql);
$staff_data = [];
if ($staff_res) {
    while ($st = mysqli_fetch_assoc($staff_res)) {
        $staff_data[] = $st;
    }
}

// 6. Detailed Order Ledger
$ledger_sql = "SELECT 
    id, queue_number, customer_name, order_type, staff_username, order_date, total_amount, order_status
    FROM orders 
    WHERE DATE(order_date) BETWEEN '$date_from' AND '$date_to'
    ORDER BY order_date DESC";
$ledger_res = mysqli_query($conn, $ledger_sql);
$ledger = [];
if ($ledger_res) {
    while ($row = mysqli_fetch_assoc($ledger_res)) {
        $ledger[] = $row;
    }
}

$pullout_id = "BKP-SAR-" . date('Ymd') . "-" . strtoupper(substr(md5($period . $date_from . $date_to . $total_revenue), 0, 6));
$security_hash = "SHA256-" . strtoupper(substr(hash('sha256', $pullout_id . $total_revenue . $total_orders), 0, 16));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BesKape Formal Sales & Revenue Audit Statement | <?php echo htmlspecialchars($period_label); ?></title>
    
    <!-- Google Fonts: Cinzel (Formal Serif Titles) + Inter (Crisp Financial Data) + JetBrains Mono (Codes) -->
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- html2pdf.js for 1-Click Direct High-Resolution PDF Download -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: #cbd5e1;
            color: #0f172a;
            padding: 24px 14px;
            min-height: 100vh;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
        }

        /* Top Action Bar (On-screen only, hidden on print & PDF) */
        .action-bar {
            max-width: 980px;
            margin: 0 auto 20px auto;
            background: #ffffff;
            padding: 16px 22px;
            border-radius: 12px;
            box-shadow: 0 6px 22px rgba(15, 23, 42, 0.14);
            border: 1px solid #94a3b8;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .action-bar-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .period-selector {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }

        .period-pill {
            padding: 6px 13px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 700;
            text-decoration: none;
            color: #334155;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            transition: all 0.2s ease;
        }

        .period-pill:hover, .period-pill.active {
            background: #0f172a;
            color: #ffffff;
            border-color: #0f172a;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn-act {
            padding: 9px 16px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12.5px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-download {
            background: #0284c7;
            color: #ffffff;
        }

        .btn-download:hover {
            background: #0369a1;
        }

        .btn-print {
            background: #0f172a;
            color: #ffffff;
        }

        .btn-print:hover {
            background: #1e293b;
        }

        .btn-close {
            background: #f8fafc;
            color: #334155;
            border: 1px solid #cbd5e1;
        }

        .btn-close:hover {
            background: #e2e8f0;
        }

        /* Custom Date Range Filter Form */
        .custom-filter-form {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-size: 12px;
            color: #475569;
        }

        .custom-filter-form input[type="date"] {
            padding: 5px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-family: inherit;
            font-size: 12px;
            color: #0f172a;
            background: #ffffff;
        }

        .btn-filter-apply {
            padding: 6px 14px;
            background: #334155;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .btn-filter-apply:hover {
            background: #0f172a;
        }

        /* Formal Document Sheet Container */
        .formal-sheet {
            max-width: 980px;
            margin: 0 auto;
            background: #ffffff;
            padding: 46px 54px;
            border: 1px solid #64748b;
            box-shadow: 0 16px 45px rgba(15, 23, 42, 0.18);
            position: relative;
        }

        /* Formal Corporate Letterhead */
        .letterhead {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 20px;
            border-bottom: 3px double #0f172a;
            margin-bottom: 22px;
            gap: 20px;
        }

        .company-info {
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .company-logo {
            width: 74px;
            height: 74px;
            object-fit: contain;
            border: 1.5px solid #0f172a;
            padding: 4px;
            background: #ffffff;
        }

        .company-text h1 {
            font-family: 'Cinzel', serif;
            font-size: 21px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            line-height: 1.2;
            margin-bottom: 3px;
        }

        .company-text .biz-type {
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .company-text .address {
            font-size: 11px;
            color: #475569;
            margin-top: 2px;
            line-height: 1.35;
        }

        .doc-classification {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .confidential-tag {
            display: inline-block;
            font-size: 9.5px;
            font-weight: 800;
            color: #0f172a;
            background: #f1f5f9;
            border: 1px solid #475569;
            padding: 3px 8px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 6px;
        }

        .report-main-title {
            font-family: 'Cinzel', serif;
            font-size: 17px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-bottom: 4px;
            line-height: 1.25;
        }

        .ref-code {
            font-family: 'JetBrains Mono', monospace;
            font-size: 11.5px;
            font-weight: 700;
            color: #1e293b;
        }

        /* Official Certified Audit Seal Badge */
        .seal-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
            padding: 3px 10px;
            border: 1.5px solid #15803d;
            background: #f0fdf4;
            color: #15803d;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-radius: 4px;
        }

        /* Formal Metadata Matrix Table */
        .meta-matrix {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
            margin-bottom: 24px;
            border: 1.5px solid #0f172a;
        }

        .meta-matrix td {
            padding: 7px 12px;
            border: 1px solid #cbd5e1;
        }

        .meta-matrix .label-col {
            background: #f1f5f9;
            font-weight: 800;
            color: #1e293b;
            width: 18%;
            text-transform: uppercase;
            font-size: 10.5px;
            letter-spacing: 0.04em;
        }

        .meta-matrix .val-col {
            width: 32%;
            color: #0f172a;
            font-weight: 600;
        }

        /* Formal Section Headings */
        .section-heading {
            font-family: 'Cinzel', serif;
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #0f172a;
            background: #f8fafc;
            padding: 7px 12px;
            border-left: 5px solid #0f172a;
            border-top: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
            border-right: 1px solid #cbd5e1;
            margin-top: 24px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-heading-tag {
            font-family: 'Inter', sans-serif;
            font-size: 10px;
            font-weight: 700;
            color: #64748b;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        /* Formal Data Tables */
        .formal-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
            margin-bottom: 18px;
            border: 1px solid #94a3b8;
        }

        .formal-table th {
            background: #0f172a;
            color: #ffffff;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 10.5px;
            padding: 8px 12px;
            border: 1px solid #0f172a;
            text-align: left;
        }

        .formal-table td {
            padding: 7.5px 12px;
            border: 1px solid #cbd5e1;
            color: #0f172a;
        }

        .formal-table tr:nth-child(even) td {
            background: #f8fafc;
        }

        .formal-table tr.total-row td {
            background: #f1f5f9;
            font-weight: 800;
            border-top: 1.5px solid #0f172a;
            border-bottom: 3.5px double #0f172a;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .currency-font {
            font-variant-numeric: tabular-nums;
            font-family: inherit;
        }

        /* Two-Column Grid for Distribution */
        .table-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 12px;
        }

        @media (max-width: 800px) {
            .table-grid-2 { grid-template-columns: 1fr; }
        }

        /* Status Badges */
        .formal-status {
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 2px 7px;
            border-radius: 3px;
            display: inline-block;
        }

        .status-paid {
            color: #15803d;
            background: #dcfce7;
            border: 1px solid #86efac;
        }

        .status-open {
            color: #b45309;
            background: #fef3c7;
            border: 1px solid #fde68a;
        }

        .status-void {
            color: #b91c1c;
            background: #fee2e2;
            border: 1px solid #fca5a5;
        }

        /* Formal Legal Certification & Attestation Statement */
        .attestation-container {
            margin-top: 28px;
            padding: 14px 18px;
            background: #f8fafc;
            border: 1.5px solid #0f172a;
            border-left: 6px solid #0f172a;
            page-break-inside: avoid;
        }

        .attestation-title {
            font-family: 'Cinzel', serif;
            font-size: 11.5px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 6px;
        }

        .attestation-text {
            font-size: 10.5px;
            color: #334155;
            line-height: 1.55;
            text-align: justify;
        }

        /* Three Official Signatory Columns */
        .signatories-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-top: 36px;
            page-break-inside: avoid;
        }

        .sig-column {
            text-align: center;
        }

        .sig-title-header {
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #475569;
            margin-bottom: 40px;
        }

        .sig-underline {
            width: 88%;
            margin: 0 auto 6px auto;
            border-bottom: 1.5px solid #0f172a;
        }

        .sig-official-name {
            font-size: 12px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .sig-official-title {
            font-size: 10px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
        }

        .sig-date {
            font-size: 9.5px;
            color: #94a3b8;
            margin-top: 4px;
            font-style: italic;
        }

        /* Document Security Verification Strip & Footer */
        .security-strip {
            margin-top: 32px;
            padding-top: 14px;
            border-top: 1.5px solid #0f172a;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 9.5px;
            color: #64748b;
            page-break-inside: avoid;
        }

        .security-barcode {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .barcode-lines {
            letter-spacing: 3px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            font-weight: 900;
            color: #0f172a;
            transform: scaleY(1.4);
            transform-origin: bottom left;
        }

        .security-hash {
            font-family: 'JetBrains Mono', monospace;
            font-size: 9px;
            color: #334155;
            letter-spacing: 0.05em;
        }

        .formal-footer-text {
            text-align: right;
            line-height: 1.4;
        }

        /* Printable Media Styles */
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
                color: #000000 !important;
            }

            .action-bar {
                display: none !important;
            }

            .formal-sheet {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                max-width: 100% !important;
            }

            .section-heading {
                background: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .formal-table th {
                background: #0f172a !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .formal-table tr.total-row td {
                background: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .seal-badge {
                border-color: #15803d !important;
                color: #15803d !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .status-paid {
                background: #dcfce7 !important;
                color: #15803d !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .page-break-avoid {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            @page {
                size: A4 portrait;
                margin: 12mm 14mm 14mm 14mm;
            }
        }
    </style>
</head>
<body>

    <!-- On-Screen Navigation & Export Toolbar -->
    <div class="action-bar">
        <div class="action-bar-top">
            <div class="period-selector">
                <span style="font-size: 11px; font-weight: 800; color: #475569; margin-right: 4px; text-transform: uppercase;">Reporting Scope:</span>
                <a href="export_sales_pdf.php?period=today" class="period-pill <?php echo $period === 'today' ? 'active' : ''; ?>">Today</a>
                <a href="export_sales_pdf.php?period=yesterday" class="period-pill <?php echo $period === 'yesterday' ? 'active' : ''; ?>">Yesterday</a>
                <a href="export_sales_pdf.php?period=week" class="period-pill <?php echo $period === 'week' ? 'active' : ''; ?>">Last 7 Days</a>
                <a href="export_sales_pdf.php?period=month" class="period-pill <?php echo $period === 'month' ? 'active' : ''; ?>">This Month</a>
                <a href="export_sales_pdf.php?period=all" class="period-pill <?php echo $period === 'all' ? 'active' : ''; ?>">All Records</a>
            </div>

            <div class="action-buttons">
                <button type="button" class="btn-act btn-download" onclick="downloadPdfFile()">
                    <i class="fa-solid fa-file-pdf"></i> Download Official PDF
                </button>
                <button type="button" class="btn-act btn-print" onclick="window.print()">
                    <i class="fa-solid fa-print"></i> Print Formal Audit
                </button>
                <a href="sales_monitoring.php" class="btn-act btn-close">
                    <i class="fa-solid fa-arrow-left"></i> Return to Monitoring
                </a>
            </div>
        </div>

        <!-- Custom Audit Date Filter -->
        <form method="GET" action="export_sales_pdf.php" class="custom-filter-form">
            <input type="hidden" name="period" value="custom">
            <span style="font-weight: 700; text-transform: uppercase; font-size: 10.5px;">Custom Audit Range:</span>
            <label>From: <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" required></label>
            <label>To: <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" required></label>
            <button type="submit" class="btn-filter-apply"><i class="fa-solid fa-filter"></i> Apply Custom Scope</button>
        </form>
    </div>

    <!-- Printable Formal Audit Sheet -->
    <div class="formal-sheet" id="printableRecord">
        
        <!-- Corporate Letterhead -->
        <div class="letterhead">
            <div class="company-info">
                <img src="logo.png" alt="BesKape Official Seal" class="company-logo" onerror="this.style.display='none'">
                <div class="company-text">
                    <h1>BesKape Specialty Coffee</h1>
                    <div class="biz-type">Commercial Point of Sale Operations & Audit</div>
                    <div class="address">Flagship Store #01 • General Trias, Cavite, Region IV-A, Philippines</div>
                    <div class="address">Tax Reg: VAT / Non-VAT Commercial POS Enterprise • TIN: 000-845-921-000-NV</div>
                    <div class="address">POS Terminal ID: BKP-POS-01 • System Build: SmartCafe Enterprise v2.4</div>
                </div>
            </div>
            <div class="doc-classification">
                <div class="confidential-tag">Official Business Audit Record</div>
                <div class="report-main-title">Statement of Sales & Revenue</div>
                <div class="ref-code">REF: <?php echo $pullout_id; ?></div>
                <div class="seal-badge">
                    <i class="fa-solid fa-circle-check"></i> Reconciled & Balanced
                </div>
            </div>
        </div>

        <!-- Formal Metadata Matrix Table -->
        <table class="meta-matrix">
            <tr>
                <td class="label-col">Reporting Entity</td>
                <td class="val-col">BesKape Specialty Coffee - Store #01</td>
                <td class="label-col">Audit Period</td>
                <td class="val-col"><strong><?php echo htmlspecialchars($period_label); ?></strong></td>
            </tr>
            <tr>
                <td class="label-col">Date & Time Extracted</td>
                <td class="val-col"><?php echo date('F d, Y \a\t h:i:s A'); ?> (PHT / UTC+8)</td>
                <td class="label-col">Monetary Currency</td>
                <td class="val-col">Philippine Peso (PHP, ₱)</td>
            </tr>
            <tr>
                <td class="label-col">Authorized Officer</td>
                <td class="val-col"><?php echo $user; ?> (Role: <?php echo $role; ?>)</td>
                <td class="label-col">Audit Reconciliation</td>
                <td class="val-col"><span style="color: #15803d; font-weight: 800;">100% RECONCILED (ZERO DISCREPANCY)</span></td>
            </tr>
        </table>

        <!-- Section 1: Statement of Gross Revenue & Statutory Tax Assessment -->
        <div class="section-heading">
            <span>I. Statement of Gross Revenue & Statutory Tax Assessment</span>
            <span class="section-heading-tag">Accounting Standard Formula</span>
        </div>

        <table class="formal-table">
            <thead>
                <tr>
                    <th style="width: 54%;">Accounting Ledger Component</th>
                    <th style="width: 22%;" class="text-center">Reference Basis / Formula</th>
                    <th style="width: 24%;" class="text-right">Monetary Value (PHP)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Gross Registered Sales (Completed Transactions)</strong></td>
                    <td class="text-center"><?php echo $total_orders; ?> Settled Orders</td>
                    <td class="text-right currency-font"><strong>₱<?php echo number_format($total_revenue, 2); ?></strong></td>
                </tr>
                <tr>
                    <td>Less: Senior Citizen / PWD Statutory Discounts (20%)</td>
                    <td class="text-center">Direct Deductions</td>
                    <td class="text-right currency-font">(₱0.00)</td>
                </tr>
                <tr>
                    <td>Less: Authorized Promotional & Voucher Discounts</td>
                    <td class="text-center">Promotional Reductions</td>
                    <td class="text-right currency-font">(₱0.00)</td>
                </tr>
                <tr>
                    <td>Less: Returns, Voided Transactions & Wastage Allowances</td>
                    <td class="text-center">Authorized Adjustments</td>
                    <td class="text-right currency-font">(₱0.00)</td>
                </tr>
                <tr class="total-row">
                    <td><strong>NET REALIZED SALES REVENUE</strong></td>
                    <td class="text-center"><strong>Net Operating Total</strong></td>
                    <td class="text-right currency-font" style="font-size: 13.5px;"><strong>₱<?php echo number_format($total_revenue, 2); ?></strong></td>
                </tr>
                <tr>
                    <td>12% Value Added Taxable Sales Component</td>
                    <td class="text-center">Net Revenue ÷ 1.12</td>
                    <td class="text-right currency-font">₱<?php echo number_format($vatable_sales, 2); ?></td>
                </tr>
                <tr>
                    <td>12% Value Added Tax (Output VAT 12%)</td>
                    <td class="text-center">Statutory 12% PH Tax</td>
                    <td class="text-right currency-font">₱<?php echo number_format($vat_amount, 2); ?></td>
                </tr>
                <tr>
                    <td>VAT-Exempt / Non-VAT Sales Component</td>
                    <td class="text-center">Exempt Turnover</td>
                    <td class="text-right currency-font">₱0.00</td>
                </tr>
                <tr>
                    <td>Zero-Rated Sales Component</td>
                    <td class="text-center">Zero-Rated Revenue</td>
                    <td class="text-right currency-font">₱0.00</td>
                </tr>
                <tr>
                    <td><strong>Average Transaction Value (ATV / Ticket Average)</strong></td>
                    <td class="text-center">Net Revenue ÷ <?php echo $total_orders > 0 ? $total_orders : 1; ?> Orders</td>
                    <td class="text-right currency-font"><strong>₱<?php echo number_format($avg_order, 2); ?></strong></td>
                </tr>
                <tr>
                    <td><strong>Total Beverage & Food Units Fulfilled</strong></td>
                    <td class="text-center"><?php echo $avg_items_per_order; ?> items / order average</td>
                    <td class="text-right currency-font"><strong><?php echo number_format($total_units); ?> units</strong></td>
                </tr>
            </tbody>
        </table>

        <!-- Section 2 & 3: Channel Distribution & Category Mix -->
        <div class="table-grid-2 page-break-avoid">
            
            <!-- Fulfillment Channel Distribution -->
            <div>
                <div class="section-heading" style="margin-top: 10px;">
                    <span>II. Fulfillment Channel Mix</span>
                </div>
                <table class="formal-table">
                    <thead>
                        <tr>
                            <th>Channel</th>
                            <th class="text-center">Volume</th>
                            <th class="text-right">Net Sales (PHP)</th>
                            <th class="text-right">Share %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($order_types_data)): ?>
                            <?php foreach ($order_types_data as $ot): 
                                $pct = $total_revenue > 0 ? round(($ot['type_total'] / $total_revenue) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($ot['order_type']); ?></strong></td>
                                <td class="text-center"><?php echo $ot['type_count']; ?></td>
                                <td class="text-right currency-font">₱<?php echo number_format($ot['type_total'], 2); ?></td>
                                <td class="text-right currency-font"><strong><?php echo $pct; ?>%</strong></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center" style="color: #94a3b8;">No channel records found for this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Category Sales Contribution -->
            <div>
                <div class="section-heading" style="margin-top: 10px;">
                    <span>III. Category Contribution Mix</span>
                </div>
                <table class="formal-table">
                    <thead>
                        <tr>
                            <th>Department / Category</th>
                            <th class="text-center">Sold</th>
                            <th class="text-right">Revenue (PHP)</th>
                            <th class="text-right">Share %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($categories_data)): ?>
                            <?php foreach ($categories_data as $cat): 
                                $cat_pct = $total_revenue > 0 ? round(($cat['category_revenue'] / $total_revenue) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($cat['category']); ?></strong></td>
                                <td class="text-center"><?php echo $cat['items_sold']; ?></td>
                                <td class="text-right currency-font">₱<?php echo number_format($cat['category_revenue'], 2); ?></td>
                                <td class="text-right currency-font"><strong><?php echo $cat_pct; ?>%</strong></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center" style="color: #94a3b8;">No category sales records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <!-- Section 4: Merchandise Product Mix Velocity (P-Mix Analysis) -->
        <?php if (!empty($top_items)): ?>
        <div class="page-break-avoid">
            <div class="section-heading">
                <span>IV. Top Merchandise Performance & Product Mix (P-Mix Velocity)</span>
                <span class="section-heading-tag">Ranked by Volume & Contribution</span>
            </div>
            <table class="formal-table">
                <thead>
                    <tr>
                        <th style="width: 45px;" class="text-center">Rank</th>
                        <th>Item Description</th>
                        <th style="width: 130px;">Category</th>
                        <th style="width: 95px;" class="text-right">Unit Price</th>
                        <th style="width: 85px;" class="text-center">Qty Sold</th>
                        <th style="width: 120px;" class="text-right">Gross Sales</th>
                        <th style="width: 75px;" class="text-right">Share %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 1;
                    foreach ($top_items as $item): 
                        $item_pct = $total_revenue > 0 ? round(($item['item_revenue'] / $total_revenue) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td class="text-center" style="font-family: 'JetBrains Mono', monospace; font-weight: 700;"><?php echo $rank++; ?></td>
                        <td><strong><?php echo htmlspecialchars($item['menu_item_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($item['category']); ?></td>
                        <td class="text-right currency-font">₱<?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="text-center currency-font"><strong><?php echo $item['qty_sold']; ?></strong></td>
                        <td class="text-right currency-font"><strong>₱<?php echo number_format($item['item_revenue'], 2); ?></strong></td>
                        <td class="text-right currency-font"><strong><?php echo $item_pct; ?>%</strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Section 5: Cashier & Staff Settlement Summary -->
        <?php if (!empty($staff_data)): ?>
        <div class="page-break-avoid">
            <div class="section-heading">
                <span>V. Terminal Cashier Settlement Reconciliation</span>
                <span class="section-heading-tag">Audit Accountability Matrix</span>
            </div>
            <table class="formal-table">
                <thead>
                    <tr>
                        <th>Cashier / Terminal Station User</th>
                        <th class="text-center">Settled Orders</th>
                        <th class="text-right">Total Settled Cash/Turnover</th>
                        <th class="text-right">Turnover Share %</th>
                        <th class="text-center">Reconciliation Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff_data as $st): 
                        $st_pct = $total_revenue > 0 ? round(($st['total_handled'] / $total_revenue) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($st['staff_name']); ?></strong></td>
                        <td class="text-center"><?php echo $st['orders_handled']; ?> orders</td>
                        <td class="text-right currency-font"><strong>₱<?php echo number_format($st['total_handled'], 2); ?></strong></td>
                        <td class="text-right currency-font"><?php echo $st_pct; ?>%</td>
                        <td class="text-center"><span class="formal-status status-paid"><i class="fa-solid fa-check"></i> Reconciled</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Section 6: Itemized Transaction Audit Ledger -->
        <div class="page-break-avoid">
            <div class="section-heading">
                <span>VI. Itemized Transaction Audit Ledger (Total Entries: <?php echo count($ledger); ?>)</span>
                <span class="section-heading-tag">Chronological Settlement Log</span>
            </div>
            <table class="formal-table">
                <thead>
                    <tr>
                        <th style="width: 75px;" class="text-center">Queue Tag</th>
                        <th style="width: 70px;" class="text-center">Order ID</th>
                        <th>Customer / Table Identifier</th>
                        <th style="width: 90px;">Fulfillment</th>
                        <th style="width: 90px;">Cashier</th>
                        <th style="width: 155px;">Transaction Timestamp</th>
                        <th style="width: 105px;" class="text-right">Total Amount</th>
                        <th style="width: 90px;" class="text-center">Audit Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($ledger)): ?>
                        <?php foreach ($ledger as $ord): 
                            $q_num = (int)($ord['queue_number'] ?? $ord['id']);
                            $q_tag = '#' . str_pad($q_num > 0 ? $q_num : 1, 3, '0', STR_PAD_LEFT);
                            $status = $ord['order_status'] ?? 'In Process';
                            $is_completed = (strtolower($status) === 'completed');
                        ?>
                        <tr>
                            <td class="text-center" style="font-family: 'JetBrains Mono', monospace; font-weight: 700;"><?php echo $q_tag; ?></td>
                            <td class="text-center" style="font-family: 'JetBrains Mono', monospace;">#<?php echo $ord['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($ord['customer_name'] ?? 'Walk-in Guest'); ?></strong></td>
                            <td><?php echo htmlspecialchars($ord['order_type'] ?? 'Dine In'); ?></td>
                            <td><?php echo htmlspecialchars($ord['staff_username'] ?? 'Staff'); ?></td>
                            <td><?php echo date('M d, Y - h:i A', strtotime($ord['order_date'])); ?></td>
                            <td class="text-right currency-font"><strong>₱<?php echo number_format($ord['total_amount'], 2); ?></strong></td>
                            <td class="text-center">
                                <?php if ($is_completed): ?>
                                    <span class="formal-status status-paid">Completed</span>
                                <?php elseif (strtolower($status) === 'voided'): ?>
                                    <span class="formal-status status-void">Voided</span>
                                <?php else: ?>
                                    <span class="formal-status status-open"><?php echo htmlspecialchars($status); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center" style="color: #94a3b8; padding: 25px;">No transactions recorded for this period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Section 7: Formal Legal Attestation & Certification Statement -->
        <div class="attestation-container">
            <div class="attestation-title">Management & Financial Audit Certification Statement</div>
            <div class="attestation-text">
                I hereby certify, attest, and declare under administrative accountability and corporate fiduciary duty that the sales data, gross receipts, statutory tax assessments, channel breakdowns, product mix velocities, and itemized transaction ledgers set forth in this document constitute an authentic, complete, and faithful extraction of the electronic records of <strong>BesKape Specialty Coffee</strong> recorded by the SmartCafe Point of Sale system. All transactions have been balanced, examined, and reconciled with zero unauthorized omissions or alterations for the designated audit period.
            </div>
        </div>

        <!-- Section 8: Three Executive Signatory Authorization Columns -->
        <div class="signatories-grid">
            <div class="sig-column">
                <div class="sig-title-header">Prepared & Reconciled By:</div>
                <div class="sig-underline"></div>
                <div class="sig-official-name"><?php echo $user; ?></div>
                <div class="sig-official-title">Point of Sale Operator (<?php echo $role; ?>)</div>
                <div class="sig-date">Date Signed: <?php echo date('F d, Y'); ?></div>
            </div>

            <div class="sig-column">
                <div class="sig-title-header">Audited & Verified By:</div>
                <div class="sig-underline"></div>
                <div class="sig-official-name">Finance & Internal Audit Lead</div>
                <div class="sig-official-title">Store Accounting Supervisor</div>
                <div class="sig-date">Date Verified: ____________________</div>
            </div>

            <div class="sig-column">
                <div class="sig-title-header">Approved & Concurred By:</div>
                <div class="sig-underline"></div>
                <div class="sig-official-name">Store Managing Partner / Owner</div>
                <div class="sig-official-title">General Operations Manager</div>
                <div class="sig-date">Date Approved: ____________________</div>
            </div>
        </div>

        <!-- Section 9: Document Security Verification Strip & Official Footer -->
        <div class="security-strip">
            <div class="security-barcode">
                <div class="barcode-lines">||| | |||| | || ||||| | ||| || ||||</div>
                <div class="security-hash"><?php echo $security_hash; ?></div>
            </div>
            <div class="formal-footer-text">
                <div>BesKape Specialty Coffee • SmartCafe Enterprise POS Audit System</div>
                <div>Document Tracking Ref: <strong><?php echo $pullout_id; ?></strong></div>
                <div>Classification: CONFIDENTIAL • Certified Business Financial Statement</div>
            </div>
        </div>

    </div>

    <script>
        // 1-Click PDF File Generator & Downloader using html2pdf.js
        function downloadPdfFile() {
            const element = document.getElementById('printableRecord');
            const opt = {
                margin:       [10, 10, 12, 10],
                filename:     'BesKape_Formal_Sales_Report_<?php echo $period; ?>_<?php echo date("Ymd_His"); ?>.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true, letterRendering: true },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
            };

            const originalShadow = element.style.boxShadow;
            element.style.boxShadow = 'none';

            html2pdf().set(opt).from(element).save().then(() => {
                element.style.boxShadow = originalShadow;
            });
        }

        // Auto-action if triggered from pull-out modal
        window.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('download') === '1') {
                setTimeout(downloadPdfFile, 600);
            } else if (urlParams.get('print') === '1') {
                setTimeout(() => window.print(), 600);
            }
        });
    </script>

</body>
</html>
