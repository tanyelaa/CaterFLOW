<?php

declare(strict_types=1);

function getDb(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $databasePath = __DIR__ . DIRECTORY_SEPARATOR . 'data.sqlite';
    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    initializeDatabase($pdo);
    seedDefaultUsers($pdo);
    seedDemoData($pdo);

    return $pdo;
}

function initializeDatabase(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fullname TEXT NOT NULL,
            username TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL UNIQUE,
            phone TEXT,
            address TEXT,
            city TEXT,
            province TEXT,
            role TEXT NOT NULL CHECK(role IN ("admin", "staff", "customer")),
            status TEXT NOT NULL DEFAULT "active" CHECK(status IN ("active", "pending", "locked")),
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            last_active TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_code TEXT NOT NULL UNIQUE,
            customer_id INTEGER,
            title TEXT NOT NULL,
            event_date TEXT,
            status TEXT NOT NULL CHECK(status IN ("pending", "confirmed", "completed", "cancelled")),
            total_amount REAL NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(customer_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS packages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT,
            price REAL NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT "active" CHECK(status IN ("active", "inactive")),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_activity (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            changed_by INTEGER NOT NULL,
            old_status TEXT,
            new_status TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(order_id) REFERENCES orders(id),
            FOREIGN KEY(changed_by) REFERENCES users(id)
        )'
    );

    ensureColumn($pdo, 'users', 'status', 'TEXT NOT NULL DEFAULT "active" CHECK(status IN ("active", "pending", "locked"))');
    ensureColumn($pdo, 'users', 'updated_at', 'TEXT');
    ensureColumn($pdo, 'users', 'last_active', 'TEXT');
    ensureColumn($pdo, 'orders', 'package_id', 'INTEGER');
    ensureColumn($pdo, 'packages', 'image_path', 'TEXT');
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query("PRAGMA table_info($table)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        if (($col['name'] ?? '') === $column) {
            return;
        }
    }

    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

function seedDefaultUsers(PDO $pdo): void
{
    $seedUsers = [
        [
            'fullname' => 'System Administrator',
            'username' => 'admin',
            'email' => 'admin@caterflow.local',
            'role' => 'admin',
            'password' => 'admin123'
        ],
        [
            'fullname' => 'Staff Account',
            'username' => 'staff',
            'email' => 'staff@caterflow.local',
            'role' => 'staff',
            'password' => 'staff123'
        ]
    ];

    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $insertStmt = $pdo->prepare(
           'INSERT INTO users (fullname, username, email, role, status, password_hash, created_at, updated_at, last_active)
            VALUES (:fullname, :username, :email, :role, :status, :password_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );

    foreach ($seedUsers as $user) {
        $checkStmt->execute([':username' => $user['username']]);
        if ($checkStmt->fetch()) {
            continue;
        }

        $insertStmt->execute([
            ':fullname' => $user['fullname'],
            ':username' => $user['username'],
            ':email' => $user['email'],
            ':role' => $user['role'],
            ':status' => 'active',
            ':password_hash' => password_hash($user['password'], PASSWORD_DEFAULT)
        ]);
    }

    $pdo->exec("UPDATE users SET status = COALESCE(status, 'active') WHERE status IS NULL OR status = ''");
    $pdo->exec('UPDATE users SET updated_at = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)');
    $pdo->exec('UPDATE users SET last_active = COALESCE(last_active, created_at, CURRENT_TIMESTAMP)');
}

function seedDemoData(PDO $pdo): void
{
    $settingsDefaults = [
        'company_name' => 'CaterFlow',
        'support_email' => 'support@caterflow.local',
        'default_currency' => 'USD',
        'notifications_enabled' => '1',
        'maintenance_mode' => '0'
    ];

    $settingsInsert = $pdo->prepare(
        'INSERT OR IGNORE INTO app_settings (key, value, updated_at)
         VALUES (:key, :value, CURRENT_TIMESTAMP)'
    );
    foreach ($settingsDefaults as $key => $value) {
        $settingsInsert->execute([
            ':key' => $key,
            ':value' => $value
        ]);
    }

    $packageCount = (int)$pdo->query('SELECT COUNT(*) FROM packages')->fetchColumn();
    if ($packageCount === 0) {
        $insertPackage = $pdo->prepare(
            'INSERT INTO packages (name, description, price, status, created_at, updated_at)
             VALUES (:name, :description, :price, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );

        $defaultPackages = [
            ['Classic Buffet', 'Standard buffet service for small gatherings', 1499.00, 'active'],
            ['Premium Wedding', 'Full-service wedding catering package', 5499.00, 'active'],
            ['Corporate Pro', 'Corporate events with staff and premium setup', 3299.00, 'active']
        ];

        foreach ($defaultPackages as $package) {
            $insertPackage->execute([
                ':name' => $package[0],
                ':description' => $package[1],
                ':price' => $package[2],
                ':status' => $package[3]
            ]);
        }
    }

    $orderCount = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    if ($orderCount > 0) {
        return;
    }

    $customerStmt = $pdo->query("SELECT id FROM users WHERE role = 'customer' ORDER BY id ASC");
    $customerIds = array_map(static fn(array $row): int => (int)$row['id'], $customerStmt->fetchAll());

    if (empty($customerIds)) {
        $insertCustomer = $pdo->prepare(
            'INSERT INTO users (fullname, username, email, role, status, password_hash, created_at, updated_at, last_active)
             VALUES (:fullname, :username, :email, :role, :status, :password_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );

        for ($i = 1; $i <= 4; $i++) {
            $insertCustomer->execute([
                ':fullname' => "Demo Customer $i",
                ':username' => "customer$i",
                ':email' => "customer$i@caterflow.local",
                ':role' => 'customer',
                ':status' => 'active',
                ':password_hash' => password_hash('Customer123', PASSWORD_DEFAULT)
            ]);
            $customerIds[] = (int)$pdo->lastInsertId();
        }
    }

    $sampleOrders = [
        ['CF-1001', 'Corporate Lunch Buffet', '2026-03-10', 'pending', 1250.00],
        ['CF-1002', 'Wedding Reception Catering', '2026-03-16', 'confirmed', 5200.00],
        ['CF-1003', 'Birthday Party Package', '2026-03-18', 'completed', 890.00],
        ['CF-1004', 'Office Snack Delivery', '2026-03-19', 'pending', 430.00],
        ['CF-1005', 'VIP Cocktail Event', '2026-03-21', 'confirmed', 2100.00],
        ['CF-1006', 'Community Fundraiser Meal', '2026-03-23', 'cancelled', 760.00]
    ];

    $insertOrder = $pdo->prepare(
        'INSERT INTO orders (order_code, customer_id, title, event_date, status, total_amount, created_at, updated_at)
         VALUES (:order_code, :customer_id, :title, :event_date, :status, :total_amount, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );

    foreach ($sampleOrders as $index => $order) {
        $customerId = $customerIds[$index % count($customerIds)];
        $insertOrder->execute([
            ':order_code' => $order[0],
            ':customer_id' => $customerId,
            ':title' => $order[1],
            ':event_date' => $order[2],
            ':status' => $order[3],
            ':total_amount' => $order[4]
        ]);
    }
}
