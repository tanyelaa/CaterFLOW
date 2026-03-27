<?php

declare(strict_types=1);

require_once __DIR__ . '/core.php';
applySecurityHeaders(false);

session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
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
    <title>Admin Dashboard - CaterFlow</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --space-1: 8px;
            --space-2: 16px;
            --space-3: 24px;
            --space-4: 32px;

            --bg: #0b1220;
            --bg-panel: rgba(16, 24, 40, 0.7);
            --line: rgba(148, 163, 184, 0.28);
            --line-strong: rgba(148, 163, 184, 0.45);
            --text-main: #f8fafc;
            --text-muted: #cbd5e1;
            --text-soft: #94a3b8;
            --brand: #7c3aed;
            --brand-2: #22d3ee;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --shadow: 0 24px 60px rgba(2, 6, 23, 0.55);
            --radius-lg: 20px;
            --radius-md: 14px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            color: var(--text-main);
            background:
                radial-gradient(circle at 20% 0%, rgba(124, 58, 237, 0.22), transparent 40%),
                radial-gradient(circle at 100% 20%, rgba(34, 211, 238, 0.16), transparent 35%),
                var(--bg);
            line-height: 1.5;
        }

        .app {
            display: grid;
            grid-template-columns: 280px 1fr;
            min-height: 100vh;
        }

        .sidebar {
            background: linear-gradient(180deg, rgba(10, 15, 26, 0.98) 0%, rgba(10, 15, 26, 0.9) 100%);
            border-right: 1px solid rgba(148, 163, 184, 0.22);
            padding: var(--space-3);
            display: flex;
            flex-direction: column;
            gap: var(--space-3);
            position: sticky;
            top: 0;
            height: 100vh;
            backdrop-filter: blur(14px);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            padding-bottom: var(--space-2);
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }

        .brand-badge {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--brand), var(--brand-2));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            color: #fff;
        }

        .brand-title { font-size: 1rem; font-weight: 700; }
        .brand-subtitle { font-size: 0.75rem; color: var(--text-soft); }

        .menu { display: grid; gap: 6px; }

        .menu a {
            text-decoration: none;
            color: var(--text-muted);
            font-size: 0.92rem;
            font-weight: 500;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }

        .menu a:hover {
            color: var(--text-main);
            background: rgba(148, 163, 184, 0.12);
            border-color: rgba(148, 163, 184, 0.25);
            transform: translateX(2px);
        }

        .menu a.active {
            color: #fff;
            border-color: rgba(167, 139, 250, 0.58);
            background: linear-gradient(90deg, rgba(124, 58, 237, 0.36), rgba(34, 211, 238, 0.24));
            box-shadow: inset 4px 0 0 rgba(248, 250, 252, 0.82);
        }

        .sidebar-bottom { margin-top: auto; }

        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            text-decoration: none;
            color: var(--text-muted);
            border: 1px solid var(--line);
            background: rgba(15, 23, 42, 0.7);
            border-radius: 12px;
            padding: 10px 12px;
            transition: all 0.2s ease;
        }

        .back-link:hover {
            border-color: var(--line-strong);
            transform: translateY(-1px);
            background: rgba(30, 41, 59, 0.85);
        }

        .main {
            padding: var(--space-3);
            display: grid;
            gap: var(--space-3);
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: var(--space-2);
            border: 1px solid var(--line);
            background: var(--bg-panel);
            backdrop-filter: blur(16px);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            padding: var(--space-2);
        }

        .topbar h1 { font-size: 1.45rem; font-weight: 700; }
        .topbar p { color: var(--text-soft); font-size: 0.92rem; }

        .top-actions { display: flex; align-items: center; gap: 12px; }

        .search {
            border: 1px solid var(--line);
            background: rgba(15, 23, 42, 0.65);
            color: var(--text-main);
            border-radius: 12px;
            padding: 10px 12px;
            min-width: 240px;
        }

        .search::placeholder { color: var(--text-soft); }

        .chip {
            border: 1px solid var(--line);
            background: rgba(15, 23, 42, 0.7);
            color: var(--text-muted);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 0.82rem;
            font-weight: 600;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: var(--space-2);
        }

        .card {
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0.62));
            backdrop-filter: blur(12px);
            padding: var(--space-2);
            position: relative;
            overflow: hidden;
            transition: all 0.2s ease;
        }

        .card:hover {
            transform: translateY(-4px);
            border-color: rgba(167, 139, 250, 0.58);
        }

        .card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .card-label { font-size: 0.84rem; color: var(--text-soft); font-weight: 600; }

        .badge {
            font-size: 0.78rem;
            font-weight: 700;
            border-radius: 999px;
            padding: 4px 8px;
            border: 1px solid;
        }

        .badge.up {
            color: #86efac;
            border-color: rgba(16, 185, 129, 0.4);
            background: rgba(16, 185, 129, 0.12);
        }

        .badge.down {
            color: #fca5a5;
            border-color: rgba(239, 68, 68, 0.35);
            background: rgba(239, 68, 68, 0.12);
        }

        .metric { font-size: 1.8rem; font-weight: 800; margin-bottom: 6px; }
        .meta { font-size: 0.8rem; color: var(--text-soft); }

        .layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: var(--space-2);
        }

        .panel {
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            background: var(--bg-panel);
            backdrop-filter: blur(14px);
            box-shadow: var(--shadow);
            padding: var(--space-2);
        }

        .panel-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-2);
        }

        .panel-title { font-size: 1rem; font-weight: 700; }
        .panel-sub { font-size: 0.84rem; color: var(--text-soft); }

        .chart-wrap {
            height: 260px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: var(--radius-md);
            background: rgba(15, 23, 42, 0.55);
            padding: 12px;
        }

        #lineChart { width: 100%; height: 100%; display: block; }

        .donut-box { display: grid; gap: var(--space-2); }

        .donut {
            width: 180px;
            height: 180px;
            margin: 0 auto;
            border-radius: 50%;
            background: conic-gradient(var(--brand) 0deg, var(--brand-2) 0deg, rgba(15, 23, 42, 0.9) 0deg);
            display: grid;
            place-items: center;
            position: relative;
        }

        .donut::before {
            content: '';
            width: 112px;
            height: 112px;
            border-radius: 50%;
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid rgba(148, 163, 184, 0.22);
            position: absolute;
        }

        .donut-value { position: relative; z-index: 2; font-size: 1.5rem; font-weight: 800; }

        .legend { display: grid; gap: 8px; }

        .legend-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--text-muted);
            font-size: 0.88rem;
        }

        .dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .table-tools {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: var(--space-1);
            flex-wrap: wrap;
            margin-bottom: var(--space-2);
        }

        .tools-left {
            display: flex;
            gap: var(--space-1);
            flex-wrap: wrap;
        }

        .control {
            border: 1px solid var(--line);
            background: rgba(15, 23, 42, 0.68);
            color: var(--text-main);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.9rem;
        }

        .btn {
            border: 1px solid var(--line-strong);
            background: rgba(30, 41, 59, 0.8);
            color: var(--text-main);
            border-radius: 10px;
            padding: 10px 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn:hover { background: rgba(51, 65, 85, 0.95); }
        .btn:active { transform: translateY(1px) scale(0.99); }

        .btn.primary {
            border: none;
            color: #fff;
            background: linear-gradient(135deg, var(--brand), var(--brand-2));
        }

        .table-wrap {
            overflow: auto;
            border: 1px solid rgba(148, 163, 184, 0.26);
            border-radius: var(--radius-md);
        }

        table {
            width: 100%;
            min-width: 860px;
            border-collapse: collapse;
            background: rgba(15, 23, 42, 0.55);
        }

        thead { background: rgba(15, 23, 42, 0.95); }

        th, td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.22);
            font-size: 0.9rem;
        }

        th { color: var(--text-soft); font-weight: 600; }

        tbody tr:hover { background: rgba(51, 65, 85, 0.45); }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 4px 10px;
            border: 1px solid;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .status.active { color: #86efac; border-color: rgba(16, 185, 129, 0.35); background: rgba(16, 185, 129, 0.15); }
        .status.pending { color: #fde68a; border-color: rgba(245, 158, 11, 0.35); background: rgba(245, 158, 11, 0.15); }
        .status.locked { color: #fca5a5; border-color: rgba(239, 68, 68, 0.32); background: rgba(239, 68, 68, 0.15); }
        .status.confirmed { color: #a5f3fc; border-color: rgba(34, 211, 238, 0.35); background: rgba(34, 211, 238, 0.15); }
        .status.preparing { color: #bfdbfe; border-color: rgba(59, 130, 246, 0.35); background: rgba(59, 130, 246, 0.15); }
        .status.out_for_delivery { color: #f0abfc; border-color: rgba(217, 70, 239, 0.35); background: rgba(217, 70, 239, 0.15); }
        .status.completed { color: #86efac; border-color: rgba(16, 185, 129, 0.35); background: rgba(16, 185, 129, 0.15); }
        .status.cancelled { color: #fca5a5; border-color: rgba(239, 68, 68, 0.32); background: rgba(239, 68, 68, 0.15); }

        .actions { position: relative; }

        .menu-btn {
            border: 1px solid var(--line);
            background: rgba(15, 23, 42, 0.75);
            color: var(--text-muted);
            border-radius: 10px;
            padding: 6px 10px;
            cursor: pointer;
        }

        .dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 160px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(15, 23, 42, 0.96);
            box-shadow: 0 18px 35px rgba(2, 6, 23, 0.5);
            overflow: hidden;
            transform: scale(0.95);
            transform-origin: top right;
            opacity: 0;
            pointer-events: none;
            transition: transform 0.2s ease, opacity 0.2s ease;
            z-index: 20;
        }

        .dropdown.show {
            transform: scale(1);
            opacity: 1;
            pointer-events: auto;
        }

        .dropdown button {
            width: 100%;
            border: none;
            text-align: left;
            cursor: pointer;
            background: transparent;
            color: var(--text-muted);
            padding: 10px 12px;
            font-size: 0.88rem;
        }

        .dropdown button:hover {
            background: rgba(148, 163, 184, 0.12);
            color: var(--text-main);
        }

        .empty {
            display: none;
            text-align: center;
            color: var(--text-soft);
            padding: var(--space-3);
            font-size: 0.92rem;
        }

        .feature-grid {
            display: grid;
            gap: var(--space-2);
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-bottom: var(--space-2);
        }

        .feature-card {
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(15, 23, 42, 0.55);
            padding: 12px;
        }

        .feature-label {
            font-size: 0.8rem;
            color: var(--text-soft);
            margin-bottom: 4px;
        }

        .feature-value {
            font-size: 1.25rem;
            font-weight: 700;
        }

        .feature-list {
            margin: 0;
            padding-left: 18px;
            color: var(--text-muted);
            display: grid;
            gap: 8px;
        }

        [hidden] {
            display: none !important;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, 0.7);
            backdrop-filter: blur(4px);
            display: grid;
            place-items: center;
            z-index: 100;
            padding: 20px;
        }

        .modal-card {
            width: min(520px, 100%);
            border: 1px solid var(--line-strong);
            border-radius: var(--radius-lg);
            background: rgba(15, 23, 42, 0.98);
            box-shadow: var(--shadow);
            padding: var(--space-2);
            display: grid;
            gap: 12px;
        }

        .modal-grid {
            display: grid;
            gap: 10px;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 4px;
        }

        .image-preview {
            width: 100%;
            max-width: 220px;
            border-radius: 12px;
            border: 1px solid var(--line);
            display: none;
        }

        .package-thumb {
            width: 56px;
            height: 56px;
            border-radius: 10px;
            object-fit: cover;
            border: 1px solid var(--line);
            background: rgba(15, 23, 42, 0.6);
        }

        button:focus-visible,
        a:focus-visible,
        input:focus-visible,
        select:focus-visible {
            outline: 2px solid var(--brand-2);
            outline-offset: 2px;
        }

        @media (max-width: 1200px) {
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .layout { grid-template-columns: 1fr; }
            .feature-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 900px) {
            .app { grid-template-columns: 1fr; }
            .sidebar { position: static; height: auto; }
            .stats { grid-template-columns: 1fr; }
            .feature-grid { grid-template-columns: 1fr; }
            .topbar { flex-direction: column; align-items: stretch; }
            .top-actions { flex-wrap: wrap; }
            .search { width: 100%; min-width: 0; }
        }
    </style>
</head>
<body>
    <div class="app">
        <aside class="sidebar" aria-label="Primary navigation">
            <div class="brand">
                <div>
                    <div class="brand-title">CaterFlow Admin</div>
                    <div class="brand-subtitle">Management System</div>
                </div>
            </div>

            <nav class="menu">
                <a href="#" class="active" aria-current="page" data-view="dashboard">Dashboard</a>
                <a href="#" data-view="analytics">Analytics</a>
                <a href="#" data-view="orders">Orders</a>
                <a href="#" data-view="packages">Packages</a>
                <a href="#" data-view="customers">Customers</a>
                <a href="#" data-view="staff">Staff</a>
                <a href="#" data-view="reports">Reports</a>
                <a href="#" data-view="calendar">Calendar</a>
                <a href="#" data-view="permissions">Permissions</a>
                <a href="#" data-view="queue">Queue</a>
                <a href="#" data-view="audit">Audit</a>
                <a href="#" data-view="settings">Settings</a>
            </nav>

            <div class="sidebar-bottom">
                <a class="back-link" href="logout.php"><span>Log Out</span><span>⇠</span></a>
            </div>
        </aside>

        <main class="main">
            <header class="topbar">
                <div>
                    <h1>Enterprise Overview</h1>
                    <p>Performance summary, team activity, and operational insights.</p>
                </div>
                <div class="top-actions">
                    <input class="search" type="search" placeholder="Quick search dashboards, users, reports..." aria-label="Quick search">
                    <span class="chip">Role: Administrator</span>
                </div>
            </header>

            <section class="stats" id="dashboardStats" aria-label="Key metrics">
                <article class="card">
                    <div class="card-head"><span class="card-label">Revenue</span><span class="badge up" data-growth-key="revenue">+0%</span></div>
                    <div class="metric" data-key="revenue" data-target="0">0</div>
                    <div class="meta">vs previous month</div>
                </article>
                <article class="card">
                    <div class="card-head"><span class="card-label">New Clients</span><span class="badge up" data-growth-key="newClients">+0%</span></div>
                    <div class="metric" data-key="newClients" data-target="0">0</div>
                    <div class="meta">qualified onboarded users</div>
                </article>
                <article class="card">
                    <div class="card-head"><span class="card-label">Pending Orders</span><span class="badge down" data-growth-key="pendingOrders">-0%</span></div>
                    <div class="metric" data-key="pendingOrders" data-target="0">0</div>
                    <div class="meta">awaiting assignment</div>
                </article>
                <article class="card">
                    <div class="card-head"><span class="card-label">CSAT Score</span><span class="badge up" data-growth-key="csat">+0%</span></div>
                    <div class="metric" data-key="csat" data-target="0">0</div>
                    <div class="meta">customer satisfaction index</div>
                </article>
            </section>

            <section class="layout" id="dashboardLayout" aria-label="Charts and insights">
                <article class="panel">
                    <div class="panel-head">
                        <div class="panel-title">Revenue Trend</div>
                        <div class="panel-sub">Last 12 months</div>
                    </div>
                    <div class="chart-wrap"><canvas id="lineChart" aria-label="Revenue line chart" role="img"></canvas></div>
                </article>

                <article class="panel donut-box">
                    <div class="panel-head">
                        <div class="panel-title">Order Composition</div>
                        <div class="panel-sub">Current quarter</div>
                    </div>
                    <div class="donut" id="donutChart"><div class="donut-value" id="donutValue">0%</div></div>
                    <div class="legend" id="donutLegend"></div>
                </article>
            </section>

            <section class="panel" id="dashboardUsers" aria-label="User management table">
                <div class="panel-head">
                    <div class="panel-title">User Management</div>
                    <div class="panel-sub" id="tableMeta">Showing 0 users</div>
                </div>

                <div class="table-tools">
                    <div class="tools-left">
                        <input class="control" id="tableSearch" type="search" placeholder="Search by name or email" aria-label="Search users">
                        <select class="control" id="statusFilter" aria-label="Filter by status">
                            <option value="all">All Status</option>
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="locked">Locked</option>
                        </select>
                        <select class="control" id="roleFilter" aria-label="Filter by role">
                            <option value="all">All Roles</option>
                            <option value="admin">Admin</option>
                            <option value="staff">Staff</option>
                            <option value="customer">Customer</option>
                        </select>
                    </div>
                    <button class="btn primary" type="button" id="addUserBtn">+ Add User</button>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last Active</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody"></tbody>
                    </table>
                </div>

                <div class="empty" id="emptyState">No users match your current filter.</div>
            </section>

            <section class="panel" id="featurePanel" hidden aria-live="polite">
                <div class="panel-head">
                    <div class="panel-title" id="featureTitle">Feature View</div>
                    <div class="panel-sub" id="featureMeta">Live data</div>
                </div>
                <div id="featureContent"></div>
            </section>
        </main>
    </div>

    <div class="modal-backdrop" id="staffModal" hidden>
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="staffModalTitle">
            <div class="panel-head" style="margin-bottom:0;">
                <div class="panel-title" id="staffModalTitle">Add Staff Account</div>
                <button class="btn" type="button" id="staffModalClose">Close</button>
            </div>
            <form id="staffForm" class="modal-grid">
                <input class="control" type="text" id="staffFullname" name="fullname" placeholder="Full name" required>
                <input class="control" type="text" id="staffUsername" name="username" placeholder="Username (letters/numbers/underscore)" required>
                <input class="control" type="email" id="staffEmail" name="email" placeholder="Email" required>
                <select class="control" id="staffStatus" name="status" required>
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                    <option value="locked">Locked</option>
                </select>
                <input class="control" type="password" id="staffPassword" name="password" placeholder="Temporary password" minlength="8" required>
                <div class="modal-actions">
                    <button class="btn" type="button" id="staffModalCancel">Cancel</button>
                    <button class="btn primary" type="submit" id="staffModalSave">Create Staff</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-backdrop" id="packageModal" hidden>
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="packageModalTitle">
            <div class="panel-head" style="margin-bottom:0;">
                <div class="panel-title" id="packageModalTitle">Add Package</div>
                <button class="btn" type="button" id="packageModalClose">Close</button>
            </div>
            <form id="packageForm" class="modal-grid">
                <input class="control" type="text" id="packageName" name="name" placeholder="Package name" required>
                <textarea class="control" id="packageDescription" name="description" rows="3" placeholder="Package description"></textarea>
                <input class="control" type="number" id="packagePrice" name="price" min="1" step="0.01" placeholder="Price" required>
                <select class="control" id="packageStatus" name="status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <input class="control" type="file" id="packageImage" name="image" accept="image/png,image/jpeg,image/webp,image/gif">
                <img id="packageImagePreview" class="image-preview" alt="Package image preview">
                <div class="modal-actions">
                    <button class="btn" type="button" id="packageModalCancel">Cancel</button>
                    <button class="btn primary" type="submit" id="packageModalSave">Create Package</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_URL = 'admin_api.php';
        const DEFAULT_PACKAGE_IMAGE = 'uploads/packages/placeholder-food.svg';
        const CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        const metricElements = document.querySelectorAll('.metric');
        const growthElements = document.querySelectorAll('[data-growth-key]');
        const chartCanvas = document.getElementById('lineChart');
        const chartCtx = chartCanvas.getContext('2d');
        const donutEl = document.getElementById('donutChart');
        const donutValueEl = document.getElementById('donutValue');
        const donutLegendEl = document.getElementById('donutLegend');

        const tableBody = document.getElementById('userTableBody');
        const tableSearch = document.getElementById('tableSearch');
        const statusFilter = document.getElementById('statusFilter');
        const roleFilter = document.getElementById('roleFilter');
        const tableMeta = document.getElementById('tableMeta');
        const emptyState = document.getElementById('emptyState');
        const addUserBtn = document.getElementById('addUserBtn');
        const menuLinks = document.querySelectorAll('.menu a[data-view]');
        const dashboardStats = document.getElementById('dashboardStats');
        const dashboardLayout = document.getElementById('dashboardLayout');
        const dashboardUsers = document.getElementById('dashboardUsers');
        const featurePanel = document.getElementById('featurePanel');
        const featureTitle = document.getElementById('featureTitle');
        const featureMeta = document.getElementById('featureMeta');
        const featureContent = document.getElementById('featureContent');
        const staffModal = document.getElementById('staffModal');
        const staffModalClose = document.getElementById('staffModalClose');
        const staffModalCancel = document.getElementById('staffModalCancel');
        const staffForm = document.getElementById('staffForm');
        const staffFullname = document.getElementById('staffFullname');
        const staffUsername = document.getElementById('staffUsername');
        const staffEmail = document.getElementById('staffEmail');
        const staffStatus = document.getElementById('staffStatus');
        const staffPassword = document.getElementById('staffPassword');
        const staffModalSave = document.getElementById('staffModalSave');
        const packageModal = document.getElementById('packageModal');
        const packageModalClose = document.getElementById('packageModalClose');
        const packageModalCancel = document.getElementById('packageModalCancel');
        const packageForm = document.getElementById('packageForm');
        const packageName = document.getElementById('packageName');
        const packageDescription = document.getElementById('packageDescription');
        const packagePrice = document.getElementById('packagePrice');
        const packageStatus = document.getElementById('packageStatus');
        const packageImage = document.getElementById('packageImage');
        const packageImagePreview = document.getElementById('packageImagePreview');
        const packageModalSave = document.getElementById('packageModalSave');
        const packageModalTitle = document.getElementById('packageModalTitle');

        let chartData = [58, 64, 62, 76, 88, 84, 96, 102, 98, 112, 118, 124];
        let chartProgress = 0;
        let donutData = [];
        let currentView = 'dashboard';
        let staffFilters = { query: '', status: 'all' };
        let packageFilters = { query: '', status: 'all' };
        let editingPackageId = null;
        let editingPackageImagePath = '';

        async function apiGet(action, params = {}) {
            const query = new URLSearchParams({ action, ...params });
            const response = await fetch(`${API_URL}?${query.toString()}`, { credentials: 'same-origin' });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Request failed');
            }
            return result.data;
        }

        async function apiPost(action, data = {}) {
            const formData = new FormData();
            formData.set('action', action);
            formData.set('csrf_token', CSRF_TOKEN);
            Object.entries(data).forEach(([key, value]) => {
                if (value instanceof File) {
                    formData.set(key, value);
                    return;
                }

                if (value === null || value === undefined) {
                    return;
                }

                formData.set(key, String(value));
            });

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

        function animateValue(element, target, duration = 900) {
            const stepTime = 16;
            const totalSteps = Math.ceil(duration / stepTime);
            const increment = target / totalSteps;
            let current = 0;

            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    current = target;
                    clearInterval(timer);
                }

                if (element.dataset.key === 'revenue') {
                    element.textContent = '$' + Math.round(current).toLocaleString();
                } else if (element.dataset.key === 'csat') {
                    element.textContent = Math.round(current) + '%';
                } else {
                    element.textContent = Math.round(current).toLocaleString();
                }
            }, stepTime);
        }

        function applyGrowthBadge(badgeEl, value) {
            const normalized = String(value || '').trim();
            badgeEl.textContent = normalized || '0%';
            badgeEl.classList.remove('up', 'down');
            if (normalized.startsWith('-')) {
                badgeEl.classList.add('down');
            } else {
                badgeEl.classList.add('up');
            }
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');
        }

        function formatMoney(value) {
            return '$' + Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
        }

        function getPackageImageSrc(path) {
            const normalized = String(path || '').trim();
            return normalized !== '' ? normalized : DEFAULT_PACKAGE_IMAGE;
        }

        function setActiveMenu(view) {
            menuLinks.forEach((link) => {
                const active = link.dataset.view === view;
                link.classList.toggle('active', active);
                if (active) {
                    link.setAttribute('aria-current', 'page');
                } else {
                    link.removeAttribute('aria-current');
                }
            });
        }

        function setDashboardVisible(visible) {
            dashboardStats.hidden = !visible;
            dashboardLayout.hidden = !visible;
            dashboardUsers.hidden = !visible;
            featurePanel.hidden = visible;
        }

        function openStaffModal() {
            staffForm.reset();
            staffStatus.value = 'active';
            staffModal.hidden = false;
            staffFullname.focus();
        }

        function closeStaffModal() {
            staffModal.hidden = true;
            staffModalSave.disabled = false;
            staffModalSave.textContent = 'Create Staff';
        }

        function openPackageModal(packageData = null) {
            packageForm.reset();
            editingPackageId = packageData ? Number(packageData.id) : null;
            editingPackageImagePath = packageData ? String(packageData.imagePath || '') : '';

            if (packageData) {
                packageModalTitle.textContent = 'Edit Package';
                packageName.value = packageData.name || '';
                packageDescription.value = packageData.description || '';
                packagePrice.value = String(packageData.price || '');
                packageStatus.value = packageData.status || 'active';
                packageImagePreview.src = getPackageImageSrc(packageData.imagePath);
                packageImagePreview.style.display = 'block';
                packageModalSave.textContent = 'Update Package';
            } else {
                packageModalTitle.textContent = 'Add Package';
                packageStatus.value = 'active';
                packageImagePreview.src = DEFAULT_PACKAGE_IMAGE;
                packageImagePreview.style.display = 'block';
                packageModalSave.textContent = 'Create Package';
            }

            packageModalSave.disabled = false;
            packageModal.hidden = false;
            packageName.focus();
        }

        function closePackageModal() {
            editingPackageId = null;
            editingPackageImagePath = '';
            packageModal.hidden = true;
            packageModalTitle.textContent = 'Add Package';
            packageModalSave.disabled = false;
            packageModalSave.textContent = 'Create Package';
        }

        function renderFeatureLoading(title, meta = 'Loading...') {
            featureTitle.textContent = title;
            featureMeta.textContent = meta;
            featureContent.innerHTML = '<div class="empty" style="display:block">Loading data...</div>';
        }

        function renderAnalytics(data) {
            const kpis = data.kpis || {};
            const conversion = data.conversion || {};
            const trend = Array.isArray(data.monthlyTrend) ? data.monthlyTrend : [];

            featureTitle.textContent = 'Analytics';
            featureMeta.textContent = `Monthly points: ${trend.length}`;
            featureContent.innerHTML = `
                <div class="feature-grid">
                    <div class="feature-card"><div class="feature-label">Total Revenue</div><div class="feature-value">${formatMoney(kpis.totalRevenue)}</div></div>
                    <div class="feature-card"><div class="feature-label">Total Orders</div><div class="feature-value">${Number(kpis.totalOrders || 0).toLocaleString()}</div></div>
                    <div class="feature-card"><div class="feature-label">Average Order</div><div class="feature-value">${formatMoney(kpis.avgOrderValue)}</div></div>
                    <div class="feature-card"><div class="feature-label">Active Customers</div><div class="feature-value">${Number(kpis.activeCustomers || 0).toLocaleString()}</div></div>
                </div>
                <div class="table-wrap" style="margin-bottom:16px;">
                    <table>
                        <thead><tr><th>Metric</th><th>Value</th></tr></thead>
                        <tbody>
                            <tr><td>Lead to Client</td><td>${Number(conversion.leadToClient || 0)}%</td></tr>
                            <tr><td>Repeat Clients</td><td>${Number(conversion.repeatClients || 0)}%</td></tr>
                            <tr><td>On-time Delivery</td><td>${Number(conversion.onTimeDelivery || 0)}%</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="panel-sub">Trend: ${trend.join(' • ') || 'No trend data'}</div>
            `;
        }

        function renderOrders(data, query = '', status = 'all') {
            const summary = data.summary || {};
            const rows = Array.isArray(data.rows) ? data.rows : [];

            featureTitle.textContent = 'Orders';
            featureMeta.textContent = `Showing ${rows.length} orders`;
            featureContent.innerHTML = `
                <div class="table-tools">
                    <div class="tools-left">
                        <input class="control" id="ordersQuery" type="search" placeholder="Search order code, title, customer" value="${escapeHtml(query)}">
                        <select class="control" id="ordersStatus">
                            <option value="all" ${status === 'all' ? 'selected' : ''}>All Status</option>
                            <option value="pending" ${status === 'pending' ? 'selected' : ''}>Pending</option>
                            <option value="confirmed" ${status === 'confirmed' ? 'selected' : ''}>Confirmed</option>
                            <option value="preparing" ${status === 'preparing' ? 'selected' : ''}>Preparing</option>
                            <option value="out_for_delivery" ${status === 'out_for_delivery' ? 'selected' : ''}>Out for Delivery</option>
                            <option value="completed" ${status === 'completed' ? 'selected' : ''}>Completed</option>
                            <option value="cancelled" ${status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="feature-grid">
                    <div class="feature-card"><div class="feature-label">Pending</div><div class="feature-value">${Number(summary.pending || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Confirmed</div><div class="feature-value">${Number(summary.confirmed || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Preparing</div><div class="feature-value">${Number(summary.preparing || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Out for Delivery</div><div class="feature-value">${Number(summary.out_for_delivery || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Completed</div><div class="feature-value">${Number(summary.completed || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Revenue</div><div class="feature-value">${formatMoney(summary.revenue || 0)}</div></div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Code</th><th>Title</th><th>Customer</th><th>Event Date</th><th>Guests</th><th>Venue</th><th>Status</th><th>Amount</th><th>Update</th></tr></thead>
                        <tbody>
                            ${rows.map((row) => `
                                <tr>
                                    <td>${escapeHtml(row.code)}</td>
                                    <td>${escapeHtml(row.title)}</td>
                                    <td>${escapeHtml(row.customer)}</td>
                                    <td>${escapeHtml(row.eventDate)}</td>
                                    <td>${escapeHtml(row.guestCount || '-')}</td>
                                    <td>${escapeHtml(row.venueAddress || '-')}</td>
                                    <td><span class="status ${escapeHtml(row.status)}">${escapeHtml(row.status)}</span></td>
                                    <td>${formatMoney(row.amount)}</td>
                                    <td>
                                        <select class="control order-status-select" data-order-id="${Number(row.id)}" style="padding:6px 8px;">
                                            <option value="pending" ${row.status === 'pending' ? 'selected' : ''}>Pending</option>
                                            <option value="confirmed" ${row.status === 'confirmed' ? 'selected' : ''}>Confirmed</option>
                                            <option value="preparing" ${row.status === 'preparing' ? 'selected' : ''}>Preparing</option>
                                            <option value="out_for_delivery" ${row.status === 'out_for_delivery' ? 'selected' : ''}>Out for Delivery</option>
                                            <option value="completed" ${row.status === 'completed' ? 'selected' : ''}>Completed</option>
                                            <option value="cancelled" ${row.status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                                        </select>
                                        <button class="btn" type="button" data-order-update="${Number(row.id)}">Save</button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                ${rows.length ? '' : '<div class="empty" style="display:block">No orders found.</div>'}
            `;

            const ordersQueryEl = document.getElementById('ordersQuery');
            const ordersStatusEl = document.getElementById('ordersStatus');
            ordersQueryEl?.addEventListener('input', () => loadOrders(ordersQueryEl.value, ordersStatusEl?.value || 'all'));
            ordersStatusEl?.addEventListener('change', () => loadOrders(ordersQueryEl?.value || '', ordersStatusEl.value));

            featureContent.querySelectorAll('[data-order-update]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const orderId = Number(button.getAttribute('data-order-update'));
                    const selectEl = featureContent.querySelector(`.order-status-select[data-order-id="${orderId}"]`);
                    const nextStatus = selectEl ? selectEl.value : 'pending';
                    try {
                        await apiPost('update-order-status', { id: orderId, status: nextStatus });
                        await Promise.all([loadOrders(ordersQueryEl?.value || '', ordersStatusEl?.value || 'all'), loadOverview()]);
                    } catch (error) {
                        alert(error.message || 'Unable to update order');
                    }
                });
            });
        }

        function renderPeople(data, label, query = '', status = 'all') {
            const totals = data.totals || {};
            const rows = Array.isArray(data.rows) ? data.rows : [];
            const isStaffView = label.toLowerCase() === 'staff';

            featureTitle.textContent = label;
            featureMeta.textContent = `Showing ${rows.length} records`;
            featureContent.innerHTML = `
                <div class="table-tools">
                    <div class="tools-left">
                        <input class="control" id="peopleQuery" type="search" placeholder="Search name, username, email" value="${escapeHtml(query)}">
                        <select class="control" id="peopleStatus">
                            <option value="all" ${status === 'all' ? 'selected' : ''}>All Status</option>
                            <option value="active" ${status === 'active' ? 'selected' : ''}>Active</option>
                            <option value="pending" ${status === 'pending' ? 'selected' : ''}>Pending</option>
                            <option value="locked" ${status === 'locked' ? 'selected' : ''}>Locked</option>
                        </select>
                    </div>
                    ${isStaffView ? '<button class="btn primary" type="button" id="addStaffBtn">+ Add Staff</button>' : ''}
                </div>
                <div class="feature-grid">
                    <div class="feature-card"><div class="feature-label">Total</div><div class="feature-value">${Number(totals.all || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Active</div><div class="feature-value">${Number(totals.active || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Pending</div><div class="feature-value">${Number(totals.pending || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Locked</div><div class="feature-value">${Number(totals.locked || 0)}</div></div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Phone</th><th>Status</th><th>Last Active</th></tr></thead>
                        <tbody>
                            ${rows.map((row) => `
                                <tr>
                                    <td>${escapeHtml(row.name)}</td>
                                    <td>${escapeHtml(row.username)}</td>
                                    <td>${escapeHtml(row.email)}</td>
                                    <td>${escapeHtml(row.phone || 'N/A')}</td>
                                    <td><span class="status ${escapeHtml(row.status)}">${escapeHtml(row.status)}</span></td>
                                    <td>${escapeHtml(row.lastActive)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                ${rows.length ? '' : `<div class="empty" style="display:block">No ${label.toLowerCase()} found.</div>`}
            `;

            const queryEl = document.getElementById('peopleQuery');
            const statusEl = document.getElementById('peopleStatus');
            const loader = label.toLowerCase() === 'customers' ? loadCustomers : loadStaff;
            queryEl?.addEventListener('input', () => loader(queryEl.value, statusEl?.value || 'all'));
            statusEl?.addEventListener('change', () => loader(queryEl?.value || '', statusEl.value));

            if (isStaffView) {
                const addStaffBtn = document.getElementById('addStaffBtn');
                addStaffBtn?.addEventListener('click', openStaffModal);
            }
        }

        function renderReports(data) {
            const summary = data.summary || {};
            const highlights = Array.isArray(data.highlights) ? data.highlights : [];

            featureTitle.textContent = 'Reports';
            featureMeta.textContent = `Generated: ${new Date(data.generatedAt || Date.now()).toLocaleString()}`;
            featureContent.innerHTML = `
                <div class="feature-grid">
                    <div class="feature-card"><div class="feature-label">Total Users</div><div class="feature-value">${Number(summary.totalUsers || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Total Orders</div><div class="feature-value">${Number(summary.totalOrders || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Completed Orders</div><div class="feature-value">${Number(summary.completedOrders || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Revenue</div><div class="feature-value">${formatMoney(summary.revenue || 0)}</div></div>
                </div>
                <div class="feature-card">
                    <div class="feature-label">Highlights</div>
                    <ul class="feature-list">${highlights.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>
                </div>
            `;
        }

        function renderSettings(settings) {
            const notificationsEnabled = String(settings.notifications_enabled || '1') === '1';
            const maintenanceMode = String(settings.maintenance_mode || '0') === '1';

            featureTitle.textContent = 'Settings';
            featureMeta.textContent = 'Application configuration';
            featureContent.innerHTML = `
                <form id="settingsForm" class="tools-left" style="display:grid;gap:12px;max-width:560px;">
                    <input class="control" type="text" name="company_name" placeholder="Company name" value="${escapeHtml(settings.company_name || '')}">
                    <input class="control" type="email" name="support_email" placeholder="Support email" value="${escapeHtml(settings.support_email || '')}">
                    <input class="control" type="text" name="default_currency" placeholder="Currency (e.g. USD)" value="${escapeHtml(settings.default_currency || '')}">
                    <input class="control" type="number" min="1" name="max_events_per_day" placeholder="Max events per day" value="${escapeHtml(settings.max_events_per_day || '5')}">
                    <input class="control" type="number" min="1" name="max_guests_per_day" placeholder="Max guests per day" value="${escapeHtml(settings.max_guests_per_day || '800')}">
                    <input class="control" type="number" min="30" name="booking_window_days" placeholder="Booking window days" value="${escapeHtml(settings.booking_window_days || '365')}">
                    <input class="control" type="text" name="payment_provider" placeholder="Payment provider" value="${escapeHtml(settings.payment_provider || 'mock')}">
                    <input class="control" type="text" name="payment_checkout_base_url" placeholder="Checkout base URL" value="${escapeHtml(settings.payment_checkout_base_url || '')}">
                    <input class="control" type="text" name="payment_webhook_secret" placeholder="Webhook secret" value="${escapeHtml(settings.payment_webhook_secret || '')}">
                    <label class="panel-sub"><input type="checkbox" name="notifications_enabled" ${notificationsEnabled ? 'checked' : ''}> Enable notifications</label>
                    <label class="panel-sub"><input type="checkbox" name="maintenance_mode" ${maintenanceMode ? 'checked' : ''}> Maintenance mode</label>
                    <label class="panel-sub"><input type="checkbox" name="allow_overbooking" ${String(settings.allow_overbooking || '0') === '1' ? 'checked' : ''}> Allow overbooking</label>
                    <button class="btn primary" type="submit">Save Settings</button>
                </form>
            `;

            const form = document.getElementById('settingsForm');
            form?.addEventListener('submit', async (event) => {
                event.preventDefault();
                const formData = new FormData(form);
                try {
                    await apiPost('save-settings', {
                        company_name: formData.get('company_name') || '',
                        support_email: formData.get('support_email') || '',
                        default_currency: formData.get('default_currency') || '',
                        max_events_per_day: formData.get('max_events_per_day') || '5',
                        max_guests_per_day: formData.get('max_guests_per_day') || '800',
                        booking_window_days: formData.get('booking_window_days') || '365',
                        payment_provider: formData.get('payment_provider') || 'mock',
                        payment_checkout_base_url: formData.get('payment_checkout_base_url') || '',
                        payment_webhook_secret: formData.get('payment_webhook_secret') || '',
                        notifications_enabled: formData.get('notifications_enabled') ? '1' : '0',
                        maintenance_mode: formData.get('maintenance_mode') ? '1' : '0',
                        allow_overbooking: formData.get('allow_overbooking') ? '1' : '0'
                    });
                    alert('Settings saved successfully.');
                } catch (error) {
                    alert(error.message || 'Unable to save settings');
                }
            });
        }

        function renderCalendar(rows) {
            const data = Array.isArray(rows) ? rows : [];
            featureTitle.textContent = 'Calendar';
            featureMeta.textContent = `Days: ${data.length}`;
            featureContent.innerHTML = `
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Date</th><th>Events</th><th>Guests</th><th>Capacity</th><th>Conflict</th></tr></thead>
                        <tbody>
                            ${data.map((row) => `
                                <tr>
                                    <td>${escapeHtml(row.date)}</td>
                                    <td>${Number(row.events || 0)} / ${Number(row.eventsCapacity || 0)}</td>
                                    <td>${Number(row.guests || 0)} / ${Number(row.guestsCapacity || 0)}</td>
                                    <td>${Number(row.eventsCapacity || 0)} events, ${Number(row.guestsCapacity || 0)} guests</td>
                                    <td>${row.isConflict ? '<span class="status locked">Conflict</span>' : '<span class="status active">OK</span>'}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }

        function renderPermissions(data) {
            const matrix = data || {};
            const roles = ['admin', 'staff', 'customer'];

            featureTitle.textContent = 'Permissions';
            featureMeta.textContent = 'Role based matrix';
            featureContent.innerHTML = roles.map((role) => {
                const rows = Array.isArray(matrix[role]) ? matrix[role] : [];
                return `
                    <div class="panel-sub" style="margin:10px 0 6px;text-transform:capitalize;">${role}</div>
                    <div class="table-wrap" style="margin-bottom:12px;">
                        <table>
                            <thead><tr><th>Permission</th><th>Allowed</th><th>Save</th></tr></thead>
                            <tbody>
                                ${rows.map((row) => {
                                    const permission = escapeHtml(row.permission);
                                    const roleAttr = escapeHtml(role);
                                    return `
                                        <tr>
                                            <td>${permission}</td>
                                            <td><input type="checkbox" data-perm-role="${roleAttr}" data-perm-name="${permission}" ${row.isAllowed ? 'checked' : ''}></td>
                                            <td><button class="btn" type="button" data-perm-save="${roleAttr}|${permission}">Update</button></td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }).join('');

            featureContent.querySelectorAll('[data-perm-save]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const key = String(button.getAttribute('data-perm-save') || '');
                    const parts = key.split('|');
                    if (parts.length !== 2) {
                        return;
                    }

                    const role = parts[0];
                    const permission = parts[1];
                    const input = featureContent.querySelector(`[data-perm-role="${role}"][data-perm-name="${permission}"]`);
                    const isAllowed = input instanceof HTMLInputElement && input.checked ? '1' : '0';
                    try {
                        await apiPost('set-permission', { role, permission, is_allowed: isAllowed });
                        alert('Permission updated.');
                    } catch (error) {
                        alert(error.message || 'Unable to update permission');
                    }
                });
            });
        }

        function renderQueue() {
            featureTitle.textContent = 'Queue';
            featureMeta.textContent = 'Async jobs';
            featureContent.innerHTML = `
                <div class="tools-left" style="display:grid;gap:10px;max-width:480px;">
                    <button class="btn" type="button" id="queueExportOrdersBtn">Queue Orders Export</button>
                    <button class="btn" type="button" id="queueExportPaymentsBtn">Queue Payments Export</button>
                    <button class="btn" type="button" id="queueRemindersBtn">Queue Reminder Dispatch</button>
                    <input class="control" type="number" min="1" id="queueJobId" placeholder="Job ID for status">
                    <button class="btn primary" type="button" id="queueCheckBtn">Check Job Status</button>
                    <div class="panel-sub" id="queueStatusOutput">No job checked yet.</div>
                </div>
            `;

            const queueStatusOutput = document.getElementById('queueStatusOutput');
            const queueJobId = document.getElementById('queueJobId');
            document.getElementById('queueExportOrdersBtn')?.addEventListener('click', async () => {
                const result = await apiPost('queue-export', { type: 'orders' });
                queueStatusOutput.textContent = `Orders export queued. Job ID: ${result?.data?.jobId || 'N/A'}`;
            });
            document.getElementById('queueExportPaymentsBtn')?.addEventListener('click', async () => {
                const result = await apiPost('queue-export', { type: 'payments' });
                queueStatusOutput.textContent = `Payments export queued. Job ID: ${result?.data?.jobId || 'N/A'}`;
            });
            document.getElementById('queueRemindersBtn')?.addEventListener('click', async () => {
                const result = await apiPost('queue-reminders');
                queueStatusOutput.textContent = `Reminders queued. Job ID: ${result?.data?.jobId || 'N/A'}`;
            });
            document.getElementById('queueCheckBtn')?.addEventListener('click', async () => {
                const id = Number(queueJobId?.value || 0);
                if (id <= 0) {
                    queueStatusOutput.textContent = 'Enter a valid job ID.';
                    return;
                }

                try {
                    const data = await apiGet('job-status', { job_id: id });
                    queueStatusOutput.textContent = `Job ${data.id}: ${data.status} (attempts: ${data.attempts})${data.lastError ? ' - ' + data.lastError : ''}`;
                } catch (error) {
                    queueStatusOutput.textContent = error.message || 'Unable to fetch job status';
                }
            });
        }

        function renderAudit(rows) {
            const data = Array.isArray(rows) ? rows : [];
            featureTitle.textContent = 'Audit Logs';
            featureMeta.textContent = `Recent events: ${data.length}`;
            featureContent.innerHTML = `
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>ID</th><th>Action</th><th>Entity</th><th>Actor</th><th>IP</th><th>Time</th></tr></thead>
                        <tbody>
                            ${data.map((row) => `
                                <tr>
                                    <td>${Number(row.id)}</td>
                                    <td>${escapeHtml(row.action)}</td>
                                    <td>${escapeHtml(row.entityType)}${row.entityId ? ' #' + Number(row.entityId) : ''}</td>
                                    <td>${escapeHtml(row.actorName || 'System')}</td>
                                    <td>${escapeHtml(row.ipAddress || '')}</td>
                                    <td>${escapeHtml(row.createdAt || '')}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }

        function renderPackages(data, query = '', status = 'all') {
            const summary = data.summary || {};
            const rows = Array.isArray(data.rows) ? data.rows : [];

            featureTitle.textContent = 'Packages';
            featureMeta.textContent = `Showing ${rows.length} packages`;
            featureContent.innerHTML = `
                <div class="table-tools">
                    <div class="tools-left">
                        <input class="control" id="packagesQuery" type="search" placeholder="Search package name or description" value="${escapeHtml(query)}">
                        <select class="control" id="packagesStatus">
                            <option value="all" ${status === 'all' ? 'selected' : ''}>All Status</option>
                            <option value="active" ${status === 'active' ? 'selected' : ''}>Active</option>
                            <option value="inactive" ${status === 'inactive' ? 'selected' : ''}>Inactive</option>
                        </select>
                    </div>
                    <button class="btn primary" type="button" id="addPackageBtn">+ Add Package</button>
                </div>
                <div class="feature-grid">
                    <div class="feature-card"><div class="feature-label">Total</div><div class="feature-value">${Number(summary.all || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Active</div><div class="feature-value">${Number(summary.active || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Inactive</div><div class="feature-value">${Number(summary.inactive || 0)}</div></div>
                    <div class="feature-card"><div class="feature-label">Avg Price</div><div class="feature-value">${rows.length ? formatMoney(rows.reduce((sum, item) => sum + Number(item.price || 0), 0) / rows.length) : '$0'}</div></div>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Image</th><th>Name</th><th>Description</th><th>Price</th><th>Status</th><th>Updated</th><th>Actions</th></tr></thead>
                        <tbody>
                            ${rows.map((row) => `
                                <tr>
                                    <td><img class="package-thumb" src="${escapeHtml(getPackageImageSrc(row.imagePath))}" alt="${escapeHtml(row.name)}"></td>
                                    <td>${escapeHtml(row.name)}</td>
                                    <td>${escapeHtml(row.description || 'N/A')}</td>
                                    <td>${formatMoney(row.price)}</td>
                                    <td><span class="status ${row.status === 'active' ? 'active' : 'locked'}">${escapeHtml(row.status)}</span></td>
                                    <td>${escapeHtml(row.updatedAt || 'N/A')}</td>
                                    <td><button class="btn" type="button" data-package-edit="${Number(row.id)}">Edit</button></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                ${rows.length ? '' : '<div class="empty" style="display:block">No packages found.</div>'}
            `;

            const queryEl = document.getElementById('packagesQuery');
            const statusEl = document.getElementById('packagesStatus');
            const addBtn = document.getElementById('addPackageBtn');

            queryEl?.addEventListener('input', () => loadPackages(queryEl.value, statusEl?.value || 'all'));
            statusEl?.addEventListener('change', () => loadPackages(queryEl?.value || '', statusEl.value));
            addBtn?.addEventListener('click', openPackageModal);

            featureContent.querySelectorAll('[data-package-edit]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const packageId = Number(button.getAttribute('data-package-edit'));
                    const existing = rows.find((item) => Number(item.id) === packageId);
                    if (!existing) return;

                    openPackageModal(existing);
                });
            });
        }

        async function loadPackages(query = '', status = 'all') {
            packageFilters = { query, status };
            renderFeatureLoading('Packages', 'Loading packages...');
            const data = await apiGet('packages', { query, status });
            renderPackages(data, query, status);
        }

        async function loadOrders(query = '', status = 'all') {
            renderFeatureLoading('Orders', 'Loading orders...');
            const data = await apiGet('orders', { query, status });
            renderOrders(data, query, status);
        }

        async function loadCustomers(query = '', status = 'all') {
            renderFeatureLoading('Customers', 'Loading customers...');
            const data = await apiGet('customers', { query, status });
            renderPeople(data, 'Customers', query, status);
        }

        async function loadStaff(query = '', status = 'all') {
            staffFilters = { query, status };
            renderFeatureLoading('Staff', 'Loading staff...');
            const data = await apiGet('staff', { query, status });
            renderPeople(data, 'Staff', query, status);
        }

        async function loadFeatureView(view) {
            if (view === 'analytics') {
                renderFeatureLoading('Analytics', 'Loading analytics...');
                const data = await apiGet('analytics');
                renderAnalytics(data);
                return;
            }

            if (view === 'orders') {
                await loadOrders();
                return;
            }

            if (view === 'packages') {
                await loadPackages();
                return;
            }

            if (view === 'customers') {
                await loadCustomers();
                return;
            }

            if (view === 'staff') {
                await loadStaff();
                return;
            }

            if (view === 'reports') {
                renderFeatureLoading('Reports', 'Generating report...');
                const data = await apiGet('reports');
                renderReports(data);
                return;
            }

            if (view === 'calendar') {
                renderFeatureLoading('Calendar', 'Loading workload...');
                const data = await apiGet('calendar');
                renderCalendar(data);
                return;
            }

            if (view === 'permissions') {
                renderFeatureLoading('Permissions', 'Loading matrix...');
                const data = await apiGet('permissions');
                renderPermissions(data);
                return;
            }

            if (view === 'queue') {
                renderQueue();
                return;
            }

            if (view === 'audit') {
                renderFeatureLoading('Audit Logs', 'Loading logs...');
                const data = await apiGet('audit-logs', { limit: 60 });
                renderAudit(data);
                return;
            }

            if (view === 'settings') {
                renderFeatureLoading('Settings', 'Loading settings...');
                const data = await apiGet('settings');
                renderSettings(data);
                return;
            }
        }

        async function switchView(view) {
            currentView = view;
            setActiveMenu(view);

            if (view === 'dashboard') {
                setDashboardVisible(true);
                await Promise.all([loadOverview(), loadUsers()]);
                return;
            }

            setDashboardVisible(false);
            await loadFeatureView(view);
        }

        function resizeCanvas() {
            const rect = chartCanvas.getBoundingClientRect();
            chartCanvas.width = rect.width * window.devicePixelRatio;
            chartCanvas.height = rect.height * window.devicePixelRatio;
            chartCtx.setTransform(window.devicePixelRatio, 0, 0, window.devicePixelRatio, 0, 0);
            drawChart(chartProgress);
        }

        function drawChart(progress) {
            const width = chartCanvas.clientWidth;
            const height = chartCanvas.clientHeight;
            chartCtx.clearRect(0, 0, width, height);

            const padding = 28;
            const chartWidth = width - padding * 2;
            const chartHeight = height - padding * 2;

            chartCtx.strokeStyle = 'rgba(148, 163, 184, 0.18)';
            chartCtx.lineWidth = 1;
            for (let i = 0; i <= 4; i += 1) {
                const y = padding + (chartHeight / 4) * i;
                chartCtx.beginPath();
                chartCtx.moveTo(padding, y);
                chartCtx.lineTo(width - padding, y);
                chartCtx.stroke();
            }

            const maxVal = Math.max(...chartData);
            const minVal = Math.min(...chartData) - 10;
            const points = chartData.map((value, index) => {
                const x = padding + (chartWidth / (chartData.length - 1)) * index;
                const normalized = (value - minVal) / (maxVal - minVal);
                const y = padding + chartHeight - normalized * chartHeight;
                return { x, y };
            });

            const visibleCount = Math.max(1, Math.floor(points.length * progress));
            const visiblePoints = points.slice(0, visibleCount);

            if (visiblePoints.length > 1) {
                const gradient = chartCtx.createLinearGradient(0, padding, 0, height - padding);
                gradient.addColorStop(0, 'rgba(124, 58, 237, 0.35)');
                gradient.addColorStop(1, 'rgba(124, 58, 237, 0.02)');

                chartCtx.beginPath();
                chartCtx.moveTo(visiblePoints[0].x, visiblePoints[0].y);
                visiblePoints.forEach((point) => chartCtx.lineTo(point.x, point.y));
                chartCtx.lineTo(visiblePoints[visiblePoints.length - 1].x, height - padding);
                chartCtx.lineTo(visiblePoints[0].x, height - padding);
                chartCtx.closePath();
                chartCtx.fillStyle = gradient;
                chartCtx.fill();

                chartCtx.beginPath();
                chartCtx.moveTo(visiblePoints[0].x, visiblePoints[0].y);
                visiblePoints.forEach((point) => chartCtx.lineTo(point.x, point.y));
                chartCtx.strokeStyle = '#22d3ee';
                chartCtx.lineWidth = 3;
                chartCtx.stroke();
            }
        }

        function animateChart() {
            chartProgress += 0.02;
            if (chartProgress > 1) chartProgress = 1;
            drawChart(chartProgress);
            if (chartProgress < 1) requestAnimationFrame(animateChart);
        }

        function renderDonut(progress) {
            let angle = 0;
            const activeTotal = Math.round(100 * progress);

            const segments = donutData.map((item) => {
                const segmentAngle = (item.value / 100) * 360 * progress;
                const start = angle;
                angle += segmentAngle;
                return `${item.color} ${start}deg ${angle}deg`;
            });

            donutEl.style.background = `conic-gradient(${segments.join(', ')}, rgba(15, 23, 42, 0.9) ${angle}deg 360deg)`;
            donutValueEl.textContent = `${activeTotal}%`;
        }

        function animateDonut() {
            let progress = 0;
            (function frame() {
                progress += 0.02;
                if (progress > 1) progress = 1;
                renderDonut(progress);
                if (progress < 1) requestAnimationFrame(frame);
            })();
        }

        function renderDonutLegend() {
            donutLegendEl.innerHTML = donutData
                .map((item) => `<div class="legend-row"><span><span class="dot" style="background:${item.color}"></span>${item.label}</span><strong>${item.value}%</strong></div>`)
                .join('');
        }

        async function loadOverview() {
            const data = await apiGet('overview');
            const stats = data.stats || {};

            metricElements.forEach((element) => {
                const key = element.dataset.key;
                const value = Number(stats[key]?.value ?? 0);
                element.dataset.target = String(value);
                animateValue(element, value);
            });

            growthElements.forEach((badge) => {
                const key = badge.dataset.growthKey;
                applyGrowthBadge(badge, stats[key]?.growth || '0%');
            });

            chartData = Array.isArray(data.revenueTrend) && data.revenueTrend.length ? data.revenueTrend : chartData;
            donutData = Array.isArray(data.orderComposition) && data.orderComposition.length
                ? data.orderComposition
                : [
                    { label: 'Corporate', value: 46, color: '#7c3aed' },
                    { label: 'Weddings', value: 34, color: '#22d3ee' },
                    { label: 'Private Events', value: 20, color: '#f59e0b' }
                ];

            chartProgress = 0;
            resizeCanvas();
            animateChart();

            renderDonutLegend();
            animateDonut();
        }

        function closeAllMenus() {
            document.querySelectorAll('.dropdown.show').forEach((menu) => menu.classList.remove('show'));
        }

        async function loadUsers() {
            const rows = await apiGet('users', {
                query: tableSearch.value.trim(),
                status: statusFilter.value,
                role: roleFilter.value
            });

            tableMeta.textContent = `Showing ${rows.length} user${rows.length === 1 ? '' : 's'}`;

            if (!rows.length) {
                tableBody.innerHTML = '';
                emptyState.style.display = 'block';
                return;
            }

            emptyState.style.display = 'none';
            tableBody.innerHTML = rows.map((user) => `
                <tr>
                    <td>${user.name}</td>
                    <td>${user.email}</td>
                    <td style="text-transform:capitalize">${user.role}</td>
                    <td><span class="status ${user.status}">${user.status}</span></td>
                    <td>${user.lastActive}</td>
                    <td>
                        <div class="actions">
                            <button class="menu-btn" type="button" data-user-id="${user.id}">Actions ▾</button>
                            <div class="dropdown" id="menu-${user.id}">
                                <button type="button" data-action="view" data-user-id="${user.id}">View profile</button>
                                <button type="button" data-action="edit" data-user-id="${user.id}">Edit user</button>
                                <button type="button" data-action="role" data-user-id="${user.id}">Change role</button>
                                <button type="button" data-action="status" data-user-id="${user.id}">Change status</button>
                            </div>
                        </div>
                    </td>
                </tr>
            `).join('');

            document.querySelectorAll('.menu-btn').forEach((btn) => {
                btn.addEventListener('click', (event) => {
                    event.stopPropagation();
                    const id = btn.dataset.userId;
                    const menu = document.getElementById(`menu-${id}`);
                    const isOpen = menu.classList.contains('show');
                    closeAllMenus();
                    if (!isOpen) menu.classList.add('show');
                });
            });
        }

        async function handleRowAction(action, userId) {
            try {
                if (action === 'view') {
                    const user = await apiGet('user', { id: userId });
                    alert(`Name: ${user.fullname}\nUsername: ${user.username}\nEmail: ${user.email}\nRole: ${user.role}\nStatus: ${user.status}`);
                    return;
                }

                if (action === 'edit') {
                    const user = await apiGet('user', { id: userId });
                    const fullname = prompt('Full name:', user.fullname || '');
                    if (fullname === null) return;
                    const email = prompt('Email:', user.email || '');
                    if (email === null) return;
                    await apiPost('update-user', { id: userId, fullname, email });
                    await loadUsers();
                    return;
                }

                if (action === 'role') {
                    const role = prompt('New role (admin/staff/customer):', 'staff');
                    if (!role) return;
                    await apiPost('set-role', { id: userId, role: role.toLowerCase() });
                    await loadUsers();
                    return;
                }

                if (action === 'status') {
                    const status = prompt('New status (active/pending/locked):', 'active');
                    if (!status) return;
                    await apiPost('set-status', { id: userId, status: status.toLowerCase() });
                    await loadUsers();
                }
            } catch (error) {
                alert(error.message || 'Action failed');
            }
        }

        addUserBtn.addEventListener('click', async () => {
            try {
                const fullname = prompt('Full name:');
                if (!fullname) return;
                const username = prompt('Username (letters/numbers/underscore):');
                if (!username) return;
                const email = prompt('Email:');
                if (!email) return;
                const role = prompt('Role (admin/staff/customer):', 'staff');
                if (!role) return;
                const status = prompt('Status (active/pending/locked):', 'active');
                if (!status) return;
                const password = prompt('Temporary password (8+ chars with upper/lower/number):');
                if (!password) return;

                await apiPost('create-user', {
                    fullname,
                    username,
                    email,
                    role: role.toLowerCase(),
                    status: status.toLowerCase(),
                    password
                });

                await Promise.all([loadUsers(), loadOverview()]);
            } catch (error) {
                alert(error.message || 'Unable to create user');
            }
        });

        [tableSearch, statusFilter, roleFilter].forEach((element) => {
            element.addEventListener('input', () => loadUsers().catch((error) => alert(error.message)));
            element.addEventListener('change', () => loadUsers().catch((error) => alert(error.message)));
        });

        tableBody.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;
            const action = target.getAttribute('data-action');
            const userId = target.getAttribute('data-user-id');
            if (!action || !userId) return;
            closeAllMenus();
            handleRowAction(action, Number(userId));
        });

        document.addEventListener('click', closeAllMenus);
        window.addEventListener('resize', resizeCanvas);

        staffModalClose.addEventListener('click', closeStaffModal);
        staffModalCancel.addEventListener('click', closeStaffModal);
        staffModal.addEventListener('click', (event) => {
            if (event.target === staffModal) {
                closeStaffModal();
            }
        });

        packageModalClose.addEventListener('click', closePackageModal);
        packageModalCancel.addEventListener('click', closePackageModal);
        packageModal.addEventListener('click', (event) => {
            if (event.target === packageModal) {
                closePackageModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !staffModal.hidden) {
                closeStaffModal();
                return;
            }

            if (event.key === 'Escape' && !packageModal.hidden) {
                closePackageModal();
            }
        });

        staffFullname.addEventListener('input', () => {
            const suggestedUsername = staffFullname.value
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9\s_]/g, '')
                .replace(/\s+/g, '_')
                .slice(0, 20);

            if (!staffUsername.value.trim()) {
                staffUsername.value = suggestedUsername;
            }
        });

        staffForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const fullname = staffFullname.value.trim();
            const username = staffUsername.value.trim();
            const email = staffEmail.value.trim();
            const statusValue = staffStatus.value;
            const password = staffPassword.value;

            if (!fullname || !username || !email || !statusValue || !password) {
                alert('Please complete all staff form fields.');
                return;
            }

            try {
                staffModalSave.disabled = true;
                staffModalSave.textContent = 'Creating...';

                await apiPost('create-user', {
                    fullname,
                    username,
                    email,
                    role: 'staff',
                    status: statusValue,
                    password
                });

                closeStaffModal();
                await Promise.all([
                    loadStaff(staffFilters.query, staffFilters.status),
                    loadOverview(),
                    loadUsers()
                ]);
            } catch (error) {
                staffModalSave.disabled = false;
                staffModalSave.textContent = 'Create Staff';
                alert(error.message || 'Unable to create staff');
            }
        });

        packageForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const name = packageName.value.trim();
            const description = packageDescription.value.trim();
            const price = packagePrice.value;
            const statusValue = packageStatus.value;
            const imageFile = packageImage.files && packageImage.files[0] ? packageImage.files[0] : null;

            if (!name || !price || !statusValue) {
                alert('Please complete all package form fields.');
                return;
            }

            try {
                packageModalSave.disabled = true;
                packageModalSave.textContent = editingPackageId ? 'Updating...' : 'Creating...';

                if (editingPackageId) {
                    await apiPost('update-package', {
                        id: editingPackageId,
                        name,
                        description,
                        price,
                        status: statusValue,
                        image: imageFile
                    });
                } else {
                    await apiPost('create-package', {
                        name,
                        description,
                        price,
                        status: statusValue,
                        image: imageFile
                    });
                }

                closePackageModal();
                await loadPackages(packageFilters.query, packageFilters.status);
            } catch (error) {
                packageModalSave.disabled = false;
                packageModalSave.textContent = editingPackageId ? 'Update Package' : 'Create Package';
                alert(error.message || 'Unable to save package');
            }
        });

        packageImage.addEventListener('change', () => {
            const file = packageImage.files && packageImage.files[0] ? packageImage.files[0] : null;
            if (!file) {
                packageImagePreview.src = getPackageImageSrc(editingPackageImagePath);
                packageImagePreview.style.display = 'block';
                return;
            }

            const reader = new FileReader();
            reader.onload = () => {
                packageImagePreview.src = String(reader.result || '');
                packageImagePreview.style.display = 'block';
            };
            reader.readAsDataURL(file);
        });

        menuLinks.forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                const view = link.dataset.view || 'dashboard';
                switchView(view).catch((error) => alert(error.message || 'Unable to load view'));
            });
        });

        (async () => {
            try {
                await switchView(currentView);
            } catch (error) {
                alert(error.message || 'Failed to load admin dashboard');
            }
        })();
    </script>
</body>
</html>
