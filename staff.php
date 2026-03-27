<?php

declare(strict_types=1);

require_once __DIR__ . '/core.php';
applySecurityHeaders(false);

session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header('Location: index.html');
    exit;
}

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - CaterFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0b1220;
            --panel: rgba(15, 23, 42, 0.78);
            --line: rgba(148, 163, 184, 0.28);
            --text: #f8fafc;
            --muted: #94a3b8;
            --warn: #f59e0b;
            --ok: #10b981;
            --info: #38bdf8;
            --radius: 16px;
            --shadow: 0 22px 60px rgba(2, 6, 23, 0.5);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            color: var(--text);
            background:
                radial-gradient(circle at 0% 0%, rgba(34, 211, 238, 0.18), transparent 40%),
                radial-gradient(circle at 100% 20%, rgba(124, 58, 237, 0.18), transparent 35%),
                var(--bg);
            line-height: 1.5;
        }

        .wrap {
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px;
            display: grid;
            gap: 20px;
        }

        .top {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--panel);
            padding: 20px;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .title {
            font-size: 1.5rem;
            font-weight: 800;
        }

        .sub {
            color: var(--muted);
            font-size: 0.9rem;
            margin-top: 4px;
        }

        .logout {
            text-decoration: none;
            border: 1px solid var(--line);
            color: var(--text);
            border-radius: 12px;
            padding: 10px 14px;
            background: rgba(15, 23, 42, 0.9);
            font-weight: 600;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }

        .card {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--panel);
            padding: 14px;
        }

        .label { color: var(--muted); font-size: 0.82rem; }
        .value { font-size: 1.7rem; font-weight: 800; margin-top: 6px; }

        .pill {
            display: inline-flex;
            margin-top: 8px;
            font-size: 0.75rem;
            font-weight: 700;
            border-radius: 999px;
            padding: 4px 8px;
        }

        .pill.pending { color: #fde68a; background: rgba(245, 158, 11, 0.2); }
        .pill.confirmed { color: #a5f3fc; background: rgba(34, 211, 238, 0.2); }
        .pill.completed { color: #86efac; background: rgba(16, 185, 129, 0.2); }

        .grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 14px;
        }

        .panel {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--panel);
            box-shadow: var(--shadow);
            padding: 16px;
        }

        .panel h2 {
            font-size: 1rem;
            margin-bottom: 12px;
        }

        .tools {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .control {
            border: 1px solid var(--line);
            border-radius: 10px;
            background: rgba(15, 23, 42, 0.8);
            color: var(--text);
            padding: 8px 10px;
            font-size: 0.88rem;
        }

        .btn {
            border: 1px solid var(--line);
            border-radius: 10px;
            background: rgba(15, 23, 42, 0.9);
            color: var(--text);
            padding: 7px 10px;
            font-size: 0.82rem;
            cursor: pointer;
        }

        .table-wrap {
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 12px;
        }

        table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 1px solid var(--line);
            font-size: 0.88rem;
        }

        th { color: var(--muted); font-weight: 600; }

        .status {
            display: inline-flex;
            border-radius: 999px;
            padding: 3px 8px;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: capitalize;
        }

        .status.pending { color: #fde68a; background: rgba(245, 158, 11, 0.2); }
        .status.confirmed { color: #a5f3fc; background: rgba(34, 211, 238, 0.2); }
        .status.preparing { color: #bfdbfe; background: rgba(59, 130, 246, 0.2); }
        .status.out_for_delivery { color: #f0abfc; background: rgba(217, 70, 239, 0.2); }
        .status.completed { color: #86efac; background: rgba(16, 185, 129, 0.2); }
        .status.cancelled { color: #fca5a5; background: rgba(239, 68, 68, 0.2); }

        .list {
            display: grid;
            gap: 10px;
        }

        .list-item {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px;
            background: rgba(15, 23, 42, 0.55);
        }

        .list-item strong { font-size: 0.9rem; }
        .list-item p { font-size: 0.82rem; color: var(--muted); margin-top: 4px; }

        .empty {
            color: var(--muted);
            border: 1px dashed var(--line);
            border-radius: 10px;
            padding: 12px;
            font-size: 0.88rem;
        }

        @media (max-width: 1024px) {
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 700px) {
            .stats { grid-template-columns: 1fr; }
            .wrap { padding: 16px; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <header class="top">
            <div>
                <h1 class="title">Staff Dashboard</h1>
                <p class="sub">Operational view for orders and customer activity.</p>
            </div>
            <a class="logout" href="logout.php">Log Out</a>
        </header>

        <section class="stats" aria-label="Staff metrics">
            <article class="card">
                <div class="label">Pending Orders</div>
                <div class="value" id="pendingOrdersValue">0</div>
                <span class="pill pending">Needs action</span>
            </article>
            <article class="card">
                <div class="label">Confirmed Orders</div>
                <div class="value" id="confirmedOrdersValue">0</div>
                <span class="pill confirmed">Scheduled</span>
            </article>
            <article class="card">
                <div class="label">Completed Orders</div>
                <div class="value" id="completedOrdersValue">0</div>
                <span class="pill completed">Delivered</span>
            </article>
            <article class="card">
                <div class="label">Today's Events</div>
                <div class="value" id="todayEventsValue">0</div>
                <span class="pill confirmed">Calendar</span>
            </article>
        </section>

        <section class="grid">
            <article class="panel">
                <h2>Order Operations</h2>
                <div class="tools">
                    <input class="control" id="orderQuery" type="search" placeholder="Search code, title, customer">
                    <select class="control" id="orderStatusFilter">
                        <option value="all">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="preparing">Preparing</option>
                        <option value="out_for_delivery">Out for Delivery</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Order Code</th>
                                <th>Title</th>
                                <th>Customer</th>
                                <th>Event Date</th>
                                <th>Guests</th>
                                <th>Venue</th>
                                <th>Status</th>
                                <th>Amount</th>
                                <th>Update</th>
                            </tr>
                        </thead>
                        <tbody id="ordersTableBody"></tbody>
                    </table>
                </div>
                <div class="empty" id="ordersEmpty" hidden>No orders found for this filter.</div>
            </article>

            <article class="panel">
                <h2>Latest Customers</h2>
                <div class="list" id="customersList"></div>

                <h2 style="margin-top:14px;">Activity Log</h2>
                <div class="tools">
                    <input class="control" id="activityQuery" type="search" placeholder="Search order, staff, status">
                    <input class="control" id="activityDate" type="date">
                </div>
                <div class="list" id="activityList"></div>

                <h2 style="margin-top:14px;">Calendar Load</h2>
                <div class="list" id="calendarList"></div>

                <h2 style="margin-top:14px;">Kitchen Tasks</h2>
                <form class="tools" id="kitchenTaskForm" style="grid-template-columns:1fr;">
                    <input class="control" id="kitchenOrderId" type="number" min="1" placeholder="Order ID" required>
                    <input class="control" id="kitchenTaskTitle" type="text" placeholder="Task title" required>
                    <button class="btn" type="submit" id="kitchenTaskSaveBtn">Create Task</button>
                </form>
                <div class="list" id="kitchenTaskList"></div>
            </article>
        </section>
    </div>

    <script>
        const API_URL = 'staff_api.php';
        const CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        const pendingOrdersValue = document.getElementById('pendingOrdersValue');
        const confirmedOrdersValue = document.getElementById('confirmedOrdersValue');
        const completedOrdersValue = document.getElementById('completedOrdersValue');
        const todayEventsValue = document.getElementById('todayEventsValue');

        const orderQuery = document.getElementById('orderQuery');
        const orderStatusFilter = document.getElementById('orderStatusFilter');
        const ordersTableBody = document.getElementById('ordersTableBody');
        const ordersEmpty = document.getElementById('ordersEmpty');
        const customersList = document.getElementById('customersList');
        const activityQuery = document.getElementById('activityQuery');
        const activityDate = document.getElementById('activityDate');
        const activityList = document.getElementById('activityList');
        const calendarList = document.getElementById('calendarList');
        const kitchenTaskForm = document.getElementById('kitchenTaskForm');
        const kitchenOrderId = document.getElementById('kitchenOrderId');
        const kitchenTaskTitle = document.getElementById('kitchenTaskTitle');
        const kitchenTaskSaveBtn = document.getElementById('kitchenTaskSaveBtn');
        const kitchenTaskList = document.getElementById('kitchenTaskList');

        async function apiGet(action, params = {}) {
            const query = new URLSearchParams({ action, ...params });
            const response = await fetch(`${API_URL}?${query.toString()}`, { credentials: 'same-origin' });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Request failed');
            }
            return result.data;
        }

        async function apiPost(action, payload = {}) {
            const formData = new FormData();
            formData.set('action', action);
            formData.set('csrf_token', CSRF_TOKEN);
            Object.entries(payload).forEach(([key, value]) => formData.set(key, String(value)));

            const response = await fetch(API_URL, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Request failed');
            }
            return result;
        }

        function formatMoney(amount) {
            return '$' + Number(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');
        }

        async function loadOverview() {
            const data = await apiGet('overview');
            pendingOrdersValue.textContent = Number(data.pendingOrders || 0).toLocaleString();
            confirmedOrdersValue.textContent = Number(data.confirmedOrders || 0).toLocaleString();
            completedOrdersValue.textContent = Number(data.completedOrders || 0).toLocaleString();
            todayEventsValue.textContent = Number(data.todayEvents || 0).toLocaleString();
        }

        async function loadOrders() {
            const rows = await apiGet('orders', {
                query: orderQuery.value.trim(),
                status: orderStatusFilter.value
            });

            if (!rows.length) {
                ordersTableBody.innerHTML = '';
                ordersEmpty.hidden = false;
                return;
            }

            ordersEmpty.hidden = true;
            ordersTableBody.innerHTML = rows.map((order) => `
                <tr>
                    <td>${escapeHtml(order.orderCode)}</td>
                    <td>${escapeHtml(order.title)}</td>
                    <td>${escapeHtml(order.customerName)}</td>
                    <td>${escapeHtml(order.eventDate)}</td>
                    <td>${escapeHtml(order.guestCount || '-')}</td>
                    <td>${escapeHtml(order.venueAddress || '-')}</td>
                    <td><span class="status ${escapeHtml(order.status)}">${escapeHtml(order.status)}</span></td>
                    <td>${formatMoney(order.totalAmount)}</td>
                    <td>
                        <select class="control" data-order-select="${Number(order.id)}" style="padding:6px 8px;">
                            <option value="pending" ${order.status === 'pending' ? 'selected' : ''}>Pending</option>
                            <option value="confirmed" ${order.status === 'confirmed' ? 'selected' : ''}>Confirmed</option>
                            <option value="preparing" ${order.status === 'preparing' ? 'selected' : ''}>Preparing</option>
                            <option value="out_for_delivery" ${order.status === 'out_for_delivery' ? 'selected' : ''}>Out for Delivery</option>
                            <option value="completed" ${order.status === 'completed' ? 'selected' : ''}>Completed</option>
                            <option value="cancelled" ${order.status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                        </select>
                        <button class="btn" type="button" data-order-save="${Number(order.id)}">Save</button>
                    </td>
                </tr>
            `).join('');

            ordersTableBody.querySelectorAll('[data-order-save]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const orderId = Number(button.getAttribute('data-order-save'));
                    const select = ordersTableBody.querySelector(`[data-order-select="${orderId}"]`);
                    const status = select ? select.value : 'pending';
                    try {
                        await apiPost('update-order-status', { id: orderId, status });
                        await Promise.all([loadOrders(), loadOverview(), loadActivity()]);
                    } catch (error) {
                        alert(error.message || 'Unable to update order');
                    }
                });
            });
        }

        async function loadCustomers() {
            const rows = await apiGet('customers');
            if (!rows.length) {
                customersList.innerHTML = '<div class="empty">No customer data available.</div>';
                return;
            }

            customersList.innerHTML = rows.map((customer) => `
                <div class="list-item">
                    <strong>${escapeHtml(customer.fullname)}</strong>
                    <p>${escapeHtml(customer.email)}</p>
                    <p>${escapeHtml(customer.phone)}</p>
                </div>
            `).join('');
        }

        async function loadActivity() {
            const rows = await apiGet('activity', {
                query: activityQuery.value.trim(),
                date: activityDate.value
            });
            if (!rows.length) {
                activityList.innerHTML = '<div class="empty">No status changes recorded yet.</div>';
                return;
            }

            activityList.innerHTML = rows.map((log) => `
                <div class="list-item">
                    <strong>${escapeHtml(log.orderCode)} • ${escapeHtml(log.orderTitle)}</strong>
                    <p>${escapeHtml(log.staffName)} changed ${escapeHtml(log.oldStatus)} → ${escapeHtml(log.newStatus)}</p>
                    <p>${escapeHtml(log.createdAt)}</p>
                </div>
            `).join('');
        }

        async function loadCalendar() {
            const rows = await apiGet('calendar');
            if (!Array.isArray(rows) || !rows.length) {
                calendarList.innerHTML = '<div class="empty">No calendar data available.</div>';
                return;
            }

            calendarList.innerHTML = rows.slice(0, 10).map((row) => `
                <div class="list-item">
                    <strong>${escapeHtml(row.eventDate)}</strong>
                    <p>Events: ${Number(row.events || 0)} • Guests: ${Number(row.guests || 0)}</p>
                </div>
            `).join('');
        }

        async function loadKitchenTasks() {
            const rows = await apiGet('kitchen-tasks');
            if (!Array.isArray(rows) || !rows.length) {
                kitchenTaskList.innerHTML = '<div class="empty">No kitchen tasks yet.</div>';
                return;
            }

            kitchenTaskList.innerHTML = rows.slice(0, 12).map((task) => `
                <div class="list-item">
                    <strong>${escapeHtml(task.title)} (${escapeHtml(task.orderCode || ('Order #' + task.orderId))})</strong>
                    <p>Status: ${escapeHtml(task.status)}${task.assignedName ? ' • Assigned: ' + escapeHtml(task.assignedName) : ''}</p>
                    <div class="tools" style="margin-top:6px;">
                        <button class="btn" type="button" data-kitchen-status="todo" data-kitchen-id="${Number(task.id)}">To Do</button>
                        <button class="btn" type="button" data-kitchen-status="in_progress" data-kitchen-id="${Number(task.id)}">In Progress</button>
                        <button class="btn" type="button" data-kitchen-status="done" data-kitchen-id="${Number(task.id)}">Done</button>
                    </div>
                </div>
            `).join('');

            kitchenTaskList.querySelectorAll('[data-kitchen-id]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const taskId = Number(button.getAttribute('data-kitchen-id'));
                    const status = String(button.getAttribute('data-kitchen-status') || 'todo');
                    try {
                        await apiPost('update-kitchen-task-status', { id: taskId, status });
                        await loadKitchenTasks();
                    } catch (error) {
                        alert(error.message || 'Unable to update task');
                    }
                });
            });
        }

        [orderQuery, orderStatusFilter].forEach((element) => {
            element.addEventListener('input', () => loadOrders().catch((error) => alert(error.message || 'Unable to load orders')));
            element.addEventListener('change', () => loadOrders().catch((error) => alert(error.message || 'Unable to load orders')));
        });

        [activityQuery, activityDate].forEach((element) => {
            element.addEventListener('input', () => loadActivity().catch((error) => alert(error.message || 'Unable to load activity')));
            element.addEventListener('change', () => loadActivity().catch((error) => alert(error.message || 'Unable to load activity')));
        });

        kitchenTaskForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const orderId = Number(kitchenOrderId.value || 0);
            const title = kitchenTaskTitle.value.trim();
            if (orderId <= 0 || title === '') {
                alert('Order ID and task title are required.');
                return;
            }

            try {
                kitchenTaskSaveBtn.disabled = true;
                kitchenTaskSaveBtn.textContent = 'Saving...';
                await apiPost('create-kitchen-task', { order_id: orderId, title });
                kitchenTaskForm.reset();
                await loadKitchenTasks();
            } catch (error) {
                alert(error.message || 'Unable to create kitchen task');
            } finally {
                kitchenTaskSaveBtn.disabled = false;
                kitchenTaskSaveBtn.textContent = 'Create Task';
            }
        });

        (async () => {
            try {
                await Promise.all([loadOverview(), loadOrders(), loadCustomers(), loadActivity(), loadCalendar(), loadKitchenTasks()]);
            } catch (error) {
                alert(error.message || 'Unable to load staff dashboard');
            }
        })();
    </script>
</body>
</html>
