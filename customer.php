<?php

declare(strict_types=1);

session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer' || !isset($_SESSION['user_id'])) {
    header('Location: index.html');
    exit;
}
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
                                <th>Status</th>
                                <th>Amount</th>
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
            </article>
        </section>
    </div>

    <script>
        const API_URL = 'customer_api.php';
        const DEFAULT_PACKAGE_IMAGE = 'uploads/packages/placeholder-food.svg';

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
        const selectedPackageNote = document.getElementById('selectedPackageNote');
        const orderSubmitBtn = document.getElementById('orderSubmitBtn');

        const profileForm = document.getElementById('profileForm');
        const profileFullname = document.getElementById('profileFullname');
        const profileEmail = document.getElementById('profileEmail');
        const profilePhone = document.getElementById('profilePhone');
        const profileSaveBtn = document.getElementById('profileSaveBtn');

        const upcomingText = document.getElementById('upcomingText');
        let packageOptions = [];
        let selectedPackageId = null;

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
                    <td>${escapeHtml(order.packageName || 'Custom')}</td>
                    <td>${escapeHtml(order.title)}</td>
                    <td>${escapeHtml(order.eventDate)}</td>
                    <td><span class="status ${escapeHtml(order.status)}">${escapeHtml(order.status)}</span></td>
                    <td>${formatMoney(order.totalAmount)}</td>
                </tr>
            `).join('');
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
            element.addEventListener('input', () => loadOrders().catch((error) => alert(error.message || 'Unable to load orders')));
            element.addEventListener('change', () => loadOrders().catch((error) => alert(error.message || 'Unable to load orders')));
        });

        orderForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const packageId = newOrderPackage.value || String(selectedPackageId || '');
            const eventDate = newOrderDate.value;

            if (!packageId || !eventDate) {
                alert('Please complete all order fields.');
                return;
            }

            try {
                orderSubmitBtn.disabled = true;
                orderSubmitBtn.textContent = 'Placing...';

                const result = await apiPost('create-order', {
                    package_id: packageId,
                    event_date: eventDate
                });

                orderForm.reset();
                selectedPackageId = null;
                await Promise.all([loadOverview(), loadOrders(), loadUpcoming(), loadPackages()]);
                alert(`Order placed successfully${result?.data?.orderCode ? ` (${result.data.orderCode})` : ''}.`);
            } catch (error) {
                alert(error.message || 'Unable to place order');
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
                alert('Full name is required.');
                return;
            }

            try {
                profileSaveBtn.disabled = true;
                profileSaveBtn.textContent = 'Saving...';
                await apiPost('update-profile', { fullname, phone });
                await loadProfile();
                alert('Profile updated.');
            } catch (error) {
                alert(error.message || 'Unable to save profile');
            } finally {
                profileSaveBtn.disabled = false;
                profileSaveBtn.textContent = 'Save Profile';
            }
        });

        (async () => {
            try {
                await Promise.all([loadOverview(), loadOrders(), loadProfile(), loadUpcoming(), loadPackages()]);
            } catch (error) {
                alert(error.message || 'Unable to load customer dashboard');
            }
        })();
    </script>
</body>
</html>
