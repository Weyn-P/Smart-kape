<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';

// Auto-detect status column name
$status_col = 'order_status';
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM orders LIKE 'order_status'");
if (!$col_check || mysqli_num_rows($col_check) === 0) {
    $status_col = 'status';
}

// Helper to format queue tag (#001, #002)
function formatStatusBoardQueueTag($num) {
    $n = (int)$num;
    return '#' . str_pad($n > 0 ? $n : 1, 3, '0', STR_PAD_LEFT);
}


// Query Active Orders (ordered by time)
$orders_query = "
    SELECT id, queue_number, `$status_col` AS order_status 
    FROM orders 
    WHERE LOWER(TRIM(`$status_col`)) IN ('in process', 'in-process', 'in_process', 'preparing', 'pending', 'ready', 'ready for pickup', 'ready_for_pickup', 'picked up', 'picked_up', 'pickedup') 
    ORDER BY order_date ASC";

$orders_result = mysqli_query($conn, $orders_query);
$initial_preparing = [];
$initial_ready = [];

if ($orders_result) {
    while ($order = mysqli_fetch_assoc($orders_result)) {
        $order_id = (int)$order['id'];
        $q_num = !empty($order['queue_number']) ? (int)$order['queue_number'] : $order_id;
        $order['queue_tag'] = formatStatusBoardQueueTag($q_num);

        $st = strtolower(trim($order['order_status']));
        if (in_array($st, ['ready', 'ready for pickup', 'ready_for_pickup', 'picked up', 'picked_up', 'pickedup'], true)) {
            $initial_ready[] = $order;
        } elseif ($st === 'preparing') {
            $initial_preparing[] = $order;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartCafe | Order Status Board</title>
    <?php include_once __DIR__ . '/pwa_head.php'; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            padding: 24px 32px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .kds-container {
            width: 100%;
            max-width: 1840px;
            margin: 0 auto;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        /* Top Header matching KDS */
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
            margin-bottom: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.03);
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
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

        /* Nav Links (ONLY Fullscreen Button) */
        .nav-links {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-fullscreen {
            color: #334155;
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
            gap: 8px;
            cursor: pointer;
            font-family: inherit;
        }

        .btn-fullscreen:hover {
            background: rgba(255, 255, 255, 0.9);
            color: #0f172a;
            border-color: rgba(255, 255, 255, 1);
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
        }

        .btn-fullscreen:active {
            transform: scale(0.97);
        }

        /* Split-Screen Grid: Preparing vs Ready for Pickup */
        .board-split-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
            align-items: stretch;
            flex: 1;
        }

        /* Status Panel Container matching KDS Glass Card (Without top black bar) */
        .status-panel {
            background: rgba(255, 255, 255, 0.58);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.85);
            box-shadow: 0 16px 36px rgba(31, 38, 135, 0.06);
            overflow: hidden;
            min-height: calc(100vh - 180px);
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        /* Panel Headers */
        .panel-header {
            padding: 24px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.25);
        }

        .panel-header h2 {
            margin: 0;
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .panel-count {
            font-family: Consolas, 'Courier New', monospace;
            font-size: 16px;
            font-weight: 800;
            padding: 5px 16px;
            border-radius: 20px;
            color: #0f172a;
            background: rgba(15, 23, 42, 0.07);
            border: 1px solid rgba(15, 23, 42, 0.14);
        }

        /* Order Numbers Grid Base */
        .numbers-grid {
            padding: 28px 32px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 18px;
            align-content: start;
            flex-grow: 1;
            max-height: calc(100vh - 280px);
            overflow-y: auto;
        }

        .numbers-grid::-webkit-scrollbar {
            width: 6px;
        }

        .numbers-grid::-webkit-scrollbar-thumb {
            background: rgba(15, 23, 42, 0.15);
            border-radius: 4px;
        }

        .numbers-grid::-webkit-scrollbar-thumb:hover {
            background: rgba(15, 23, 42, 0.28);
        }

        /* Compact & Organized Preparing Numbers Grid */
        .panel-preparing .numbers-grid {
            padding: 24px 28px;
            grid-template-columns: repeat(auto-fill, minmax(105px, 1fr));
            gap: 12px;
            max-height: calc(100vh - 280px);
            overflow-y: auto;
        }

        /* Ready Numbers Grid (Generous & Prominent) */
        .panel-ready .numbers-grid {
            padding: 28px 32px;
            grid-template-columns: repeat(auto-fill, minmax(155px, 1fr));
            gap: 20px;
            max-height: calc(100vh - 280px);
            overflow-y: auto;
        }

        /* Order Number Cards (Only the Order Number) */
        .order-num-card {
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-radius: 20px;
            padding: 24px 16px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.03);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .order-num-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
        }

        /* Compact Preparing Number Cards */
        .order-num-card.num-preparing {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.85);
            border-radius: 14px;
            padding: 12px 10px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
        }

        .order-num-val {
            font-family: Consolas, 'Courier New', monospace;
            font-size: 34px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.5px;
        }

        /* Small, Crisp Preparing Number */
        .num-preparing .order-num-val {
            font-size: 21px;
            font-weight: 700;
            color: #334155;
            letter-spacing: -0.2px;
        }

        /* Ready Number Tone (Glowing Emerald) */
        .num-ready {
            background: rgba(209, 250, 229, 0.6);
            border: 1.5px solid rgba(167, 243, 208, 0.95);
            box-shadow: 0 6px 18px rgba(16, 185, 129, 0.15);
            animation: readyGlow 2.5s infinite alternate ease-in-out;
        }

        .num-ready .order-num-val {
            color: #047857;
            font-size: 40px;
        }

        @keyframes readyGlow {
            0% {
                box-shadow: 0 4px 14px rgba(16, 185, 129, 0.12);
                border-color: rgba(167, 243, 208, 0.8);
            }
            100% {
                box-shadow: 0 8px 25px rgba(16, 185, 129, 0.28);
                border-color: rgba(16, 185, 129, 0.5);
            }
        }

        /* Empty State */
        .empty-panel {
            grid-column: 1 / -1;
            text-align: center;
            padding: 80px 20px;
            color: #94a3b8;
            font-size: 15px;
            font-weight: 600;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .empty-panel i {
            font-size: 32px;
            opacity: 0.4;
        }

        /* Fullscreen View Enhancements */
        :fullscreen body,
        :-webkit-full-screen body {
            padding: 16px 24px;
            height: 100vh;
            overflow: hidden;
        }

        :fullscreen .kds-container,
        :-webkit-full-screen .kds-container {
            max-width: 100%;
            width: 100%;
            height: 100%;
        }

        :fullscreen .kds-header,
        :-webkit-full-screen .kds-header {
            margin-bottom: 14px;
            padding: 14px 24px;
        }

        :fullscreen .board-split-grid,
        :-webkit-full-screen .board-split-grid {
            gap: 24px;
            height: calc(100vh - 105px);
        }

        :fullscreen .status-panel,
        :-webkit-full-screen .status-panel {
            min-height: auto;
            height: 100%;
        }

        :fullscreen .panel-preparing .numbers-grid,
        :-webkit-full-screen .panel-preparing .numbers-grid {
            max-height: calc(100vh - 190px);
            grid-template-columns: repeat(auto-fill, minmax(115px, 1fr));
            gap: 14px;
            padding: 24px 28px;
        }

        :fullscreen .panel-ready .numbers-grid,
        :-webkit-full-screen .panel-ready .numbers-grid {
            max-height: calc(100vh - 190px);
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 22px;
            padding: 28px 32px;
        }

        :fullscreen .num-ready .order-num-val,
        :-webkit-full-screen .num-ready .order-num-val {
            font-size: 44px;
        }

        :fullscreen .num-preparing .order-num-val,
        :-webkit-full-screen .num-preparing .order-num-val {
            font-size: 23px;
        }

        @media (max-width: 768px) {
            body {
                padding: 16px;
            }
            .kds-header {
                padding: 16px 20px;
                margin-bottom: 20px;
            }
            .board-split-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .panel-preparing .numbers-grid {
                grid-template-columns: repeat(auto-fill, minmax(75px, 1fr));
                padding: 14px;
                gap: 8px;
            }
            .panel-ready .numbers-grid {
                grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
                padding: 18px;
                gap: 12px;
            }
            .num-preparing .order-num-val {
                font-size: 17px;
            }
            .num-ready .order-num-val {
                font-size: 32px;
            }
        }
    </style>
</head>
<body>

<div class="kds-container">
    <!-- Header with only the Fullscreen button -->
    <div class="kds-header">
        <div class="header-brand">
            <h1>Order Status Board</h1>
        </div>
        <div class="nav-links">
            <button type="button" id="btn-fullscreen" class="btn-fullscreen" title="Toggle Fullscreen">
                <i class="fa-solid fa-expand"></i> Fullscreen
            </button>
        </div>
    </div>

    <!-- Split Board: Preparing & Ready for Pickup -->
    <div class="board-split-grid">
        
        <!-- PREPARING SECTION -->
        <div class="status-panel panel-preparing">
            <div class="panel-header">
                <h2><i class="fa-solid fa-fire-burner"></i> Preparing</h2>
                <span class="panel-count" id="count-preparing"><?php echo count($initial_preparing); ?></span>
            </div>
            <div class="numbers-grid" id="grid-preparing">
                <?php if (!empty($initial_preparing)): ?>
                    <?php foreach ($initial_preparing as $ord): ?>
                        <div class="order-num-card num-preparing" data-order-id="<?php echo (int)$ord['id']; ?>">
                            <span class="order-num-val"><?php echo htmlspecialchars($ord['queue_tag']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-panel">
                        <i class="fa-solid fa-mug-saucer"></i>
                        <span>No orders preparing</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- READY FOR PICKUP SECTION -->
        <div class="status-panel panel-ready">
            <div class="panel-header">
                <h2><i class="fa-solid fa-bell"></i> Ready for Pickup</h2>
                <span class="panel-count" id="count-ready"><?php echo count($initial_ready); ?></span>
            </div>
            <div class="numbers-grid" id="grid-ready">
                <?php if (!empty($initial_ready)): ?>
                    <?php foreach ($initial_ready as $ord): ?>
                        <div class="order-num-card num-ready" data-order-id="<?php echo (int)$ord['id']; ?>">
                            <span class="order-num-val"><?php echo htmlspecialchars($ord['queue_tag']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-panel">
                        <i class="fa-solid fa-bell-slash"></i>
                        <span>No orders ready for pickup</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Fullscreen Toggle
    const btnFullscreen = document.getElementById('btn-fullscreen');
    if (btnFullscreen) {
        btnFullscreen.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().then(() => {
                    btnFullscreen.innerHTML = '<i class="fa-solid fa-compress"></i> Exit Fullscreen';
                }).catch(err => {
                    console.warn('Fullscreen error:', err);
                });
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen().then(() => {
                        btnFullscreen.innerHTML = '<i class="fa-solid fa-expand"></i> Fullscreen';
                    }).catch(err => {
                        console.warn('Exit fullscreen error:', err);
                    });
                }
            }
        });

        document.addEventListener('fullscreenchange', () => {
            if (!document.fullscreenElement) {
                btnFullscreen.innerHTML = '<i class="fa-solid fa-expand"></i> Fullscreen';
            }
        });
    }

    // 2. Real-Time Status Board Poller (Polls every 3 seconds)
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    async function refreshStatusBoard() {
        try {
            const response = await fetch('api_get_orders.php?type=status_board', {
                headers: { 'Cache-Control': 'no-cache' }
            });
            if (!response.ok) return;

            const data = await response.json();
            const preparingOrders = data.preparing || [];
            const readyOrders = data.ready || [];

            // Update Counts
            const prepCountElem = document.getElementById('count-preparing');
            const readyCountElem = document.getElementById('count-ready');
            if (prepCountElem) prepCountElem.textContent = preparingOrders.length;
            if (readyCountElem) readyCountElem.textContent = readyOrders.length;

            const prepGrid = document.getElementById('grid-preparing');
            const readyGrid = document.getElementById('grid-ready');

            // Render Preparing Column
            const currentPrepIds = Array.from(prepGrid.querySelectorAll('.order-num-card'))
                .map(c => c.dataset.orderId).join(',');
            const newPrepIds = preparingOrders.map(o => String(o.id)).join(',');

            if (currentPrepIds !== newPrepIds) {
                if (preparingOrders.length === 0) {
                    prepGrid.innerHTML = `
                        <div class="empty-panel">
                            <i class="fa-solid fa-mug-saucer"></i>
                            <span>No orders preparing</span>
                        </div>
                    `;
                } else {
                    let prepHtml = '';
                    preparingOrders.forEach(ord => {
                        const qtag = escapeHtml(ord.queue_tag || ('#' + String(ord.queue_number || ord.id).padStart(3, '0')));
                        prepHtml += `
                            <div class="order-num-card num-preparing" data-order-id="${ord.id}">
                                <span class="order-num-val">${qtag}</span>
                            </div>
                        `;
                    });
                    prepGrid.innerHTML = prepHtml;
                }
            }

            // Render Ready Column
            const currentReadyIds = Array.from(readyGrid.querySelectorAll('.order-num-card'))
                .map(c => c.dataset.orderId).join(',');
            const newReadyIds = readyOrders.map(o => String(o.id)).join(',');

            if (currentReadyIds !== newReadyIds) {
                if (readyOrders.length === 0) {
                    readyGrid.innerHTML = `
                        <div class="empty-panel">
                            <i class="fa-solid fa-bell-slash"></i>
                            <span>No orders ready for pickup</span>
                        </div>
                    `;
                } else {
                    let readyHtml = '';
                    readyOrders.forEach(ord => {
                        const qtag = escapeHtml(ord.queue_tag || ('#' + String(ord.queue_number || ord.id).padStart(3, '0')));
                        readyHtml += `
                            <div class="order-num-card num-ready" data-order-id="${ord.id}">
                                <span class="order-num-val">${qtag}</span>
                            </div>
                        `;
                    });
                    readyGrid.innerHTML = readyHtml;
                }
            }

        } catch (err) {
            console.warn('Status board auto-refresh error:', err);
        }
    }

    setInterval(refreshStatusBoard, 3000);
});
</script>

</body>
</html>
