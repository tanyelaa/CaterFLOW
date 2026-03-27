<?php

declare(strict_types=1);

require_once __DIR__ . '/core.php';
applySecurityHeaders(false);

session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer' || !isset($_SESSION['user_id'])) {
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
    <title>Customer Dashboard - CaterFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0f172a;
            --panel: rgba(15, 23, 42, 0.8);
            --line: rgba(148, 163, 184, 0.3);
            --text: #f8fafc;
            --muted: #94a3b8;
            --brand: #d6b35a;
            --radius: 16px;
            --shadow: 0 24px 60px rgba(2, 6, 23, 0.52);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            color: var(--text);
            background:
                radial-gradient(circle at 10% 0%, rgba(214, 179, 90, 0.18), transparent 35%),
                radial-gradient(circle at 90% 10%, rgba(56, 189, 248, 0.15), transparent 35%),
                var(--bg);
            line-height: 1.5;
        }

        .wrap {
            max-width: 1180px;
            margin: 0 auto;
            padding: 28px;
            display: grid;
            gap: 18px;
        }

        .top {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--panel);
            box-shadow: var(--shadow);
            padding: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .title { font-size: 1.5rem; font-weight: 800; }
        .sub { color: var(--muted); font-size: 0.9rem; margin-top: 4px; }

        .logout {
            text-decoration: none;
            color: var(--text);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 14px;
            background: rgba(15, 23, 42, 0.9);
            font-weight: 600;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .card {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--panel);
            padding: 14px;
        }

        .label { color: var(--muted); font-size: 0.82rem; }
        .value { font-size: 1.65rem; font-weight: 800; margin-top: 6px; }

        .pill {
            display: inline-flex;
            margin-top: 8px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 700;
            color: #fef3c7;
            background: rgba(214, 179, 90, 0.2);
        }

        .grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 12px;
        }

        .panel {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--panel);
            box-shadow: var(--shadow);
            padding: 16px;
        }

        .panel h2 { font-size: 1rem; margin-bottom: 12px; }

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
            padding: 8px 10px;
            font-size: 0.82rem;
            cursor: pointer;
        }

        .btn.primary {
            border: none;
            color: #111827;
            font-weight: 700;
            background: linear-gradient(135deg, #d6b35a, #fef3c7);
        }

        .order-form {
            display: grid;
            gap: 8px;
            margin-bottom: 10px;
        }

        .order-form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
        }

        .package-note {
            color: var(--muted);
            font-size: 0.8rem;
            margin-top: -2px;
        }

        .package-cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .package-card {
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(15, 23, 42, 0.75);
            padding: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: left;
            color: var(--text);
        }

        .package-card:hover {
            border-color: rgba(214, 179, 90, 0.7);
            transform: translateY(-1px);
        }

        .package-card.selected {
            border-color: rgba(214, 179, 90, 0.9);
            box-shadow: 0 0 0 1px rgba(214, 179, 90, 0.5) inset;
        }

        .package-image {
            width: 100%;
            height: 120px;
            border-radius: 10px;
            object-fit: cover;
            border: 1px solid var(--line);
            margin-bottom: 8px;
            background: rgba(15, 23, 42, 0.6);
        }

        .package-name {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .package-desc {
            font-size: 0.78rem;
            color: var(--muted);
            min-height: 36px;
        }

        .package-price {
            font-size: 0.84rem;
            font-weight: 700;
            margin-top: 6px;
            color: #fef3c7;
        }

        .table-wrap {
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 12px;
        }

        table {
            width: 100%;
            min-width: 680px;
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
        .status.confirmed { color: #bae6fd; background: rgba(56, 189, 248, 0.2); }
        .status.completed { color: #86efac; background: rgba(16, 185, 129, 0.2); }
        .status.cancelled { color: #fca5a5; background: rgba(239, 68, 68, 0.2); }

        .profile-form {
            display: grid;
            gap: 10px;
        }

        .upcoming {
            margin-top: 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px;
            background: rgba(15, 23, 42, 0.55);
            font-size: 0.88rem;
        }

        .empty {
            color: var(--muted);
            font-size: 0.9rem;
            padding: 14px;
            border: 1px dashed var(--line);
            border-radius: 10px;
        }

        .toast-wrap {
            position: fixed;
            right: 16px;
            bottom: 16px;
            display: grid;
            gap: 8px;
            z-index: 9999;
            width: min(320px, calc(100vw - 24px));
        }

        .toast {
            border: 1px solid var(--line);
            background: rgba(15, 23, 42, 0.95);
            color: var(--text);
            border-radius: 10px;
            padding: 10px 12px;
            box-shadow: var(--shadow);
            font-size: 0.84rem;
        }

        .toast.success { border-color: rgba(16, 185, 129, 0.45); }
        .toast.error { border-color: rgba(239, 68, 68, 0.45); }

        @media (max-width: 1024px) {
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .grid { grid-template-columns: 1fr; }
            .package-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 700px) {
            .stats { grid-template-columns: 1fr; }
            .wrap { padding: 16px; }
            .order-form-grid { grid-template-columns: 1fr; }
            .package-cards { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <header class="top">
            <div>
                <h1 class="title">Customer Dashboard</h1>
                <p class="sub">Welcome back, <span id="customerName">Customer</span>.</p>
            </div>
            <a class="logout" href="logout.php">Log Out</a>
        </header>

        <section class="stats" aria-label="Customer summary">
            <article class="card">
                <div class="label">Total Orders</div>
                <div class="value" id="totalOrdersValue">0</div>
                <span class="pill">History</span>
            </article>
            <article class="card">
                <div class="label">Pending</div>
                <div class="value" id="pendingOrdersValue">0</div>
                <span class="pill">In queue</span>
            </article>
            <article class="card">
                <div class="label">Confirmed</div>
                <div class="value" id="confirmedOrdersValue">0</div>
                <span class="pill">Planned</span>
            </article>
            <article class="card">
                <div class="label">Total Spent</div>
                <div class="value" id="totalSpentValue">$0.00</div>
                <span class="pill">Confirmed + Completed</span>
            </article>
        </section>

        <section class="grid">
            <article class="panel">
                <h2>Your Orders</h2>
                <form class="order-form" id="orderForm">
                    <div class="order-form-grid">
                        <div class="package-cards" id="packageCards"></div>
                        <input type="hidden" id="newOrderPackage" name="package_id" required>
                        <input class="control" id="newOrderDate" name="event_date" type="date" required>
                        <input class="control" id="newOrderGuestCount" name="guest_count" type="number" min="1" max="3000" value="1" required>
                        <input class="control" id="newOrderVenue" name="venue_address" type="text" placeholder="Venue address" required>
                    </div>
                    <div class="package-note" id="selectedPackageNote">Select a package to view details.</div>
                    <button class="btn primary" type="submit" id="orderSubmitBtn">Place Order</button>
                </form>
                <div class="tools">
                    <input class="control" id="orderQuery" type="search" placeholder="Search by code or title">
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
                                <th>Package</th>
                                <th>Title</th>
                                <th>Event Date</th>
                                <th>Guests</th>
                                <th>Venue</th>
                                <th>Status</th>
                                <th>Amount</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="ordersTableBody"></tbody>
                    </table>
                </div>
                <div class="empty" id="ordersEmpty" hidden>No orders found.</div>
            </article>

            <article class="panel">
                <h2>Profile</h2>
                <form class="profile-form" id="profileForm">
                    <input class="control" id="profileFullname" name="fullname" type="text" placeholder="Full name" required>
                    <input class="control" id="profileEmail" name="email" type="email" readonly>
                    <input class="control" id="profilePhone" name="phone" type="text" placeholder="Phone number">
                    <button class="btn primary" type="submit" id="profileSaveBtn">Save Profile</button>
                </form>

                <div class="upcoming" id="upcomingBlock">
                    <strong>Upcoming Event:</strong><br>
                    <span id="upcomingText">No upcoming event scheduled.</span>
                </div>

                <h2 style="margin-top:14px;">Billing and Documents</h2>
                <div class="tools">
                    <button class="btn" type="button" id="refreshDocsBtn">Refresh</button>
                </div>
                <div class="list" id="invoiceList"></div>
                <div class="list" id="contractList"></div>

                <h2 style="margin-top:14px;">Payment Tracker</h2>
                <div class="tools">
                    <input class="control" id="paymentOrderId" type="number" min="1" placeholder="Order ID">
                    <button class="btn" type="button" id="paymentStatusBtn">Check Status</button>
                    <button class="btn" type="button" id="paymentRetryBtn">Retry Payment Link</button>
                </div>
                <div class="list" id="paymentStatusList"></div>

                <h2 style="margin-top:14px;">Notification Preferences</h2>
                <form class="profile-form" id="notificationPrefForm">
                    <label><input type="checkbox" id="prefEmail"> Email</label>
                    <label><input type="checkbox" id="prefSms"> SMS</label>
                    <label><input type="checkbox" id="prefInapp" checked> In-app</label>
                    <button class="btn" type="submit">Save Preferences</button>
                </form>
            </article>
        </section>
    </div>

    <div class="toast-wrap" id="toastWrap" aria-live="polite"></div>

    <script>
        const API_URL = 'customer_api.php';
        const PAYMENTS_API_URL = 'payments_api.php';
        const DEFAULT_PACKAGE_IMAGE = 'uploads/packages/placeholder-food.svg';
        const CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        const customerName = document.getElementById('customerName');
        const totalOrdersValue = document.getElementById('totalOrdersValue');
        const pendingOrdersValue = document.getElementById('pendingOrdersValue');
        const confirmedOrdersValue = document.getElementById('confirmedOrdersValue');
        const totalSpentValue = document.getElementById('totalSpentValue');

        const orderQuery = document.getElementById('orderQuery');
        const orderStatusFilter = document.getElementById('orderStatusFilter');
        const ordersTableBody = document.getElementById('ordersTableBody');
        const ordersEmpty = document.getElementById('ordersEmpty');
        const orderForm = document.getElementById('orderForm');
        const packageCards = document.getElementById('packageCards');
        const newOrderPackage = document.getElementById('newOrderPackage');
        const newOrderDate = document.getElementById('newOrderDate');
        const newOrderGuestCount = document.getElementById('newOrderGuestCount');
        const newOrderVenue = document.getElementById('newOrderVenue');
        const selectedPackageNote = document.getElementById('selectedPackageNote');
        const orderSubmitBtn = document.getElementById('orderSubmitBtn');

        const profileForm = document.getElementById('profileForm');
        const profileFullname = document.getElementById('profileFullname');
        const profileEmail = document.getElementById('profileEmail');
        const profilePhone = document.getElementById('profilePhone');
        const profileSaveBtn = document.getElementById('profileSaveBtn');

        const upcomingText = document.getElementById('upcomingText');
        const invoiceList = document.getElementById('invoiceList');
        const contractList = document.getElementById('contractList');
        const refreshDocsBtn = document.getElementById('refreshDocsBtn');
        const paymentOrderId = document.getElementById('paymentOrderId');
        const paymentStatusBtn = document.getElementById('paymentStatusBtn');
        const paymentRetryBtn = document.getElementById('paymentRetryBtn');
        const paymentStatusList = document.getElementById('paymentStatusList');
        const notificationPrefForm = document.getElementById('notificationPrefForm');
        const prefEmail = document.getElementById('prefEmail');
        const prefSms = document.getElementById('prefSms');
        const prefInapp = document.getElementById('prefInapp');
        const toastWrap = document.getElementById('toastWrap');
        let packageOptions = [];
        let selectedPackageId = null;

        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;
            toastWrap.appendChild(toast);
            setTimeout(() => toast.remove(), 3200);
        }

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

        async function paymentApiGet(action, params = {}) {
            const query = new URLSearchParams({ action, ...params });
            const response = await fetch(`${PAYMENTS_API_URL}?${query.toString()}`, { credentials: 'same-origin' });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Payment request failed');
            }
            return result.data;
        }

        async function paymentApiPost(action, payload = {}) {
            const formData = new FormData();
            formData.set('action', action);
            formData.set('csrf_token', CSRF_TOKEN);
            Object.entries(payload).forEach(([key, value]) => formData.set(key, String(value)));

            const response = await fetch(PAYMENTS_API_URL, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Payment request failed');
            }
            return result;
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');
        }

        function formatMoney(amount) {
            return '$' + Number(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function getPackageImage(path) {
            const normalized = String(path || '').trim();
            return normalized !== '' ? normalized : DEFAULT_PACKAGE_IMAGE;
        }

        async function loadOverview() {
            const data = await apiGet('overview');
            totalOrdersValue.textContent = Number(data.totalOrders || 0).toLocaleString();
            pendingOrdersValue.textContent = Number(data.pendingOrders || 0).toLocaleString();
            confirmedOrdersValue.textContent = Number(data.confirmedOrders || 0).toLocaleString();
            totalSpentValue.textContent = formatMoney(data.totalSpent || 0);
        }

        async function loadOrders() {
            const data = await apiGet('orders', {
                query: orderQuery.value.trim(),
                status: orderStatusFilter.value
            });
            const rows = Array.isArray(data) ? data : (Array.isArray(data?.rows) ? data.rows : []);

            if (!rows.length) {
                ordersTableBody.innerHTML = '';
                ordersEmpty.hidden = false;
                return;
            }

            ordersEmpty.hidden = true;
            ordersTableBody.innerHTML = rows.map((order) => `
                <tr>
                    <td>${escapeHtml(order.orderCode)}</td>
                    <td>${escapeHtml(order.packageName || 'Custom')}</td>
                    <td>${escapeHtml(order.title)}</td>
                    <td>${escapeHtml(order.eventDate)}</td>
                    <td>${escapeHtml(order.guestCount || '-')}</td>
                    <td>${escapeHtml(order.venueAddress || '-')}</td>
                    <td><span class="status ${escapeHtml(order.status)}">${escapeHtml(order.status)}</span></td>
                    <td>${formatMoney(order.totalAmount)}</td>
                    <td>
                        ${Number(order.remainingBalance || 0) > 0 ? `<button class="btn" type="button" data-pay-intent="${Number(order.id)}">Pay Link</button>` : '<span class="pill">Paid</span>'}
                        <button class="btn" type="button" data-track-payment="${Number(order.id)}">Track</button>
                    </td>
                </tr>
            `).join('');

            ordersTableBody.querySelectorAll('[data-pay-intent]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const orderId = Number(button.getAttribute('data-pay-intent'));
                    try {
                        const result = await paymentApiPost('create-intent', { order_id: orderId });
                        const checkoutUrl = result?.data?.checkoutUrl || '';
                        paymentOrderId.value = String(orderId);
                        if (checkoutUrl) {
                            window.open(checkoutUrl, '_blank', 'noopener');
                        }
                        await loadPaymentStatus(orderId);
                        showToast('Payment link generated.', 'success');
                    } catch (error) {
                        showToast(error.message || 'Unable to generate payment link', 'error');
                    }
                });
            });

            ordersTableBody.querySelectorAll('[data-track-payment]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const orderId = Number(button.getAttribute('data-track-payment'));
                    try {
                        paymentOrderId.value = String(orderId);
                        await loadPaymentStatus(orderId);
                        showToast('Payment status loaded.', 'success');
                    } catch (error) {
                        showToast(error.message || 'Unable to track payment', 'error');
                    }
                });
            });
        }

        async function loadPaymentStatus(orderId) {
            const normalizedOrderId = Number(orderId || 0);
            if (normalizedOrderId <= 0) {
                paymentStatusList.innerHTML = '<div class="empty">Enter an order ID to check payment status.</div>';
                return;
            }

            const rows = await paymentApiGet('status', { order_id: normalizedOrderId });
            if (!Array.isArray(rows) || !rows.length) {
                paymentStatusList.innerHTML = '<div class="empty">No payment transactions found for this order.</div>';
                return;
            }

            paymentStatusList.innerHTML = rows.map((row) => `
                <div class="list-item">
                    <strong>${escapeHtml(row.transaction_ref || 'N/A')} • ${escapeHtml(row.status || 'pending')}</strong>
                    <p>${escapeHtml(row.provider || 'provider')} • ${formatMoney(row.amount || 0)} ${escapeHtml(row.currency || '')}</p>
                    <p>Created: ${escapeHtml(row.created_at || 'N/A')}</p>
                </div>
            `).join('');
        }

        async function retryPaymentLink(orderId) {
            const normalizedOrderId = Number(orderId || 0);
            if (normalizedOrderId <= 0) {
                showToast('Please enter a valid order ID.', 'error');
                return;
            }

            const result = await paymentApiPost('create-intent', { order_id: normalizedOrderId });
            const checkoutUrl = result?.data?.checkoutUrl || '';
            if (checkoutUrl) {
                window.open(checkoutUrl, '_blank', 'noopener');
            }
            await loadPaymentStatus(normalizedOrderId);
            showToast('New payment link generated.', 'success');
        }

        async function loadInvoicesAndContracts() {
            const [invoices, contracts] = await Promise.all([apiGet('invoices'), apiGet('contracts')]);

            if (!Array.isArray(invoices) || !invoices.length) {
                invoiceList.innerHTML = '<div class="empty">No invoices yet.</div>';
            } else {
                invoiceList.innerHTML = invoices.slice(0, 5).map((invoice) => `
                    <div class="list-item">
                        <strong>${escapeHtml(invoice.invoiceNo)} • ${escapeHtml(invoice.status)}</strong>
                        <p>Amount: ${formatMoney(invoice.amount)} • Due: ${escapeHtml(invoice.dueDate || 'N/A')}</p>
                    </div>
                `).join('');
            }

            if (!Array.isArray(contracts) || !contracts.length) {
                contractList.innerHTML = '<div class="empty">No contracts yet.</div>';
            } else {
                contractList.innerHTML = contracts.slice(0, 5).map((contract) => `
                    <div class="list-item">
                        <strong>${escapeHtml(contract.contractNo)} • ${escapeHtml(contract.status)}</strong>
                        <p>${escapeHtml((contract.termsText || '').slice(0, 120))}${(contract.termsText || '').length > 120 ? '...' : ''}</p>
                        ${contract.status === 'sent' || contract.status === 'draft'
                            ? `<button class="btn" type="button" data-sign-contract="${Number(contract.id)}">Sign</button>`
                            : ''}
                    </div>
                `).join('');

                contractList.querySelectorAll('[data-sign-contract]').forEach((button) => {
                    button.addEventListener('click', async () => {
                        const id = Number(button.getAttribute('data-sign-contract'));
                        const signedName = prompt('Enter your full name to sign:');
                        if (!signedName) {
                            return;
                        }

                        try {
                            await apiPost('sign-contract', { id, signed_name: signedName.trim() });
                            await loadInvoicesAndContracts();
                            showToast('Contract signed successfully.', 'success');
                        } catch (error) {
                            showToast(error.message || 'Unable to sign contract', 'error');
                        }
                    });
                });
            }
        }

        async function loadNotificationPreferences() {
            const prefs = await apiGet('notification-preferences');
            prefEmail.checked = !!prefs.emailEnabled;
            prefSms.checked = !!prefs.smsEnabled;
            prefInapp.checked = prefs.inappEnabled !== false;
        }

        function selectPackage(packageId) {
            selectedPackageId = Number(packageId);
            newOrderPackage.value = String(selectedPackageId);

            packageCards.querySelectorAll('.package-card').forEach((card) => {
                const cardId = Number(card.getAttribute('data-package-id'));
                card.classList.toggle('selected', cardId === selectedPackageId);
            });

            const selected = packageOptions.find((pkg) => Number(pkg.id) === selectedPackageId);
            if (!selected) {
                selectedPackageNote.textContent = 'Select a package to view details.';
                return;
            }

            const description = selected.description ? ` • ${selected.description}` : '';
            selectedPackageNote.textContent = `${selected.name}${description} • Price: ${formatMoney(selected.price)}`;
        }

        function renderPackageCards() {
            if (!packageOptions.length) {
                packageCards.innerHTML = '<div class="empty">No active packages available.</div>';
                selectedPackageId = null;
                newOrderPackage.value = '';
                selectedPackageNote.textContent = 'No package available right now.';
                return;
            }

            packageCards.innerHTML = packageOptions.map((pkg) => `
                <button type="button" class="package-card" data-package-id="${Number(pkg.id)}">
                    <img class="package-image" src="${escapeHtml(getPackageImage(pkg.imagePath))}" alt="${escapeHtml(pkg.name)}">
                    <div class="package-name">${escapeHtml(pkg.name)}</div>
                    <div class="package-desc">${escapeHtml(pkg.description || 'No description')}</div>
                    <div class="package-price">${formatMoney(pkg.price)}</div>
                </button>
            `).join('');

            packageCards.querySelectorAll('.package-card').forEach((card) => {
                card.addEventListener('click', () => {
                    const packageId = Number(card.getAttribute('data-package-id'));
                    selectPackage(packageId);
                });
            });

            selectPackage(Number(packageOptions[0].id));
        }

        async function loadPackages() {
            packageOptions = await apiGet('packages');
            renderPackageCards();
        }

        async function loadProfile() {
            const profile = await apiGet('profile');
            customerName.textContent = profile.fullname || 'Customer';
            profileFullname.value = profile.fullname || '';
            profileEmail.value = profile.email || '';
            profilePhone.value = profile.phone || '';
        }

        async function loadUpcoming() {
            const upcoming = await apiGet('upcoming');
            if (!upcoming) {
                upcomingText.textContent = 'No upcoming event scheduled.';
                return;
            }

            upcomingText.textContent = `${upcoming.title} (${upcoming.orderCode}) — Date: ${upcoming.eventDate}`;
        }

        [orderQuery, orderStatusFilter].forEach((element) => {
            element.addEventListener('input', () => loadOrders().catch((error) => showToast(error.message || 'Unable to load orders', 'error')));
            element.addEventListener('change', () => loadOrders().catch((error) => showToast(error.message || 'Unable to load orders', 'error')));
        });

        orderForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const packageId = newOrderPackage.value || String(selectedPackageId || '');
            const eventDate = newOrderDate.value;
            const guestCount = Number(newOrderGuestCount.value || 0);
            const venueAddress = newOrderVenue.value.trim();

            if (!packageId || !eventDate || guestCount < 1 || guestCount > 3000 || venueAddress === '') {
                showToast('Please complete all order fields.', 'error');
                return;
            }

            try {
                orderSubmitBtn.disabled = true;
                orderSubmitBtn.textContent = 'Placing...';

                const result = await apiPost('create-order', {
                    package_id: packageId,
                    event_date: eventDate,
                    guest_count: guestCount,
                    venue_address: venueAddress
                });

                orderForm.reset();
                selectedPackageId = null;
                await Promise.all([loadOverview(), loadOrders(), loadUpcoming(), loadPackages()]);
                showToast(`Order placed successfully${result?.data?.orderCode ? ` (${result.data.orderCode})` : ''}.`, 'success');
            } catch (error) {
                showToast(error.message || 'Unable to place order', 'error');
            } finally {
                orderSubmitBtn.disabled = false;
                orderSubmitBtn.textContent = 'Place Order';
            }
        });

        profileForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const fullname = profileFullname.value.trim();
            const phone = profilePhone.value.trim();
            if (!fullname) {
                showToast('Full name is required.', 'error');
                return;
            }

            try {
                profileSaveBtn.disabled = true;
                profileSaveBtn.textContent = 'Saving...';
                await apiPost('update-profile', { fullname, phone });
                await loadProfile();
                showToast('Profile updated.', 'success');
            } catch (error) {
                showToast(error.message || 'Unable to save profile', 'error');
            } finally {
                profileSaveBtn.disabled = false;
                profileSaveBtn.textContent = 'Save Profile';
            }
        });

        refreshDocsBtn.addEventListener('click', () => {
            loadInvoicesAndContracts().catch((error) => showToast(error.message || 'Unable to load documents', 'error'));
        });

        paymentStatusBtn.addEventListener('click', () => {
            loadPaymentStatus(paymentOrderId.value).catch((error) => showToast(error.message || 'Unable to load payment status', 'error'));
        });

        paymentRetryBtn.addEventListener('click', () => {
            retryPaymentLink(paymentOrderId.value).catch((error) => showToast(error.message || 'Unable to generate payment link', 'error'));
        });

        notificationPrefForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                await apiPost('update-notification-preferences', {
                    email_enabled: prefEmail.checked ? '1' : '0',
                    sms_enabled: prefSms.checked ? '1' : '0',
                    inapp_enabled: prefInapp.checked ? '1' : '0'
                });
                showToast('Preferences updated.', 'success');
            } catch (error) {
                showToast(error.message || 'Unable to save preferences', 'error');
            }
        });

        (async () => {
            try {
                await Promise.all([loadOverview(), loadOrders(), loadProfile(), loadUpcoming(), loadPackages()]);
                await Promise.all([loadInvoicesAndContracts(), loadNotificationPreferences()]);
            } catch (error) {
                showToast(error.message || 'Unable to load customer dashboard', 'error');
            }
        })();
    </script>
</body>
</html>
