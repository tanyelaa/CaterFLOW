<?php

declare(strict_types=1);

function getDb(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $database = getenv('DB_NAME') ?: 'caterflow';
    $username = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASS') ?: '';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    initializeDatabase($pdo);

    $enableAutoSeed = getenv('CF_ENABLE_AUTO_SEED') === '1';
    if ($enableAutoSeed) {
        seedDefaultUsers($pdo);

        if (getenv('CF_ENABLE_DEMO_DATA') === '1') {
            seedDemoData($pdo);
        }
    }

    return $pdo;
}

function initializeDatabase(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fullname VARCHAR(100) NOT NULL,
            username VARCHAR(20) NOT NULL UNIQUE,
            email VARCHAR(255) NOT NULL UNIQUE,
            phone VARCHAR(50) NULL,
            address VARCHAR(255) NULL,
            city VARCHAR(100) NULL,
            province VARCHAR(100) NULL,
            role ENUM("admin", "staff", "customer") NOT NULL,
            status ENUM("active", "pending", "locked") NOT NULL DEFAULT "active",
            password_hash VARCHAR(255) NOT NULL,
            archived_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            last_active DATETIME NULL
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS packages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            description TEXT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            image_path VARCHAR(255) NULL,
            archived_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_packages_status (status),
            KEY idx_packages_name (name)
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_code VARCHAR(32) NOT NULL UNIQUE,
            customer_id INT UNSIGNED NULL,
            package_id INT UNSIGNED NULL,
            title VARCHAR(150) NOT NULL,
            event_date DATE NULL,
            event_time TIME NULL,
            guest_count SMALLINT UNSIGNED NULL,
            venue_address VARCHAR(255) NULL,
            special_requests TEXT NULL,
            dietary_notes TEXT NULL,
            status ENUM("pending", "confirmed", "preparing", "out_for_delivery", "completed", "cancelled") NOT NULL,
            payment_status ENUM("unpaid", "partial", "paid") NOT NULL DEFAULT "unpaid",
            amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            cancellation_reason VARCHAR(255) NULL,
            cancelled_at DATETIME NULL,
            rescheduled_at DATETIME NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_orders_customer (customer_id),
            KEY idx_orders_package (package_id),
            KEY idx_orders_status_event (status, event_date),
            CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_orders_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_activity (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            changed_by INT UNSIGNED NOT NULL,
            old_status ENUM("pending", "confirmed", "preparing", "out_for_delivery", "completed", "cancelled") NULL,
            new_status ENUM("pending", "confirmed", "preparing", "out_for_delivery", "completed", "cancelled") NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_order_activity_order (order_id),
            KEY idx_order_activity_changed_by (changed_by),
            KEY idx_order_activity_created (created_at),
            CONSTRAINT fk_order_activity_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_order_activity_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            `key` VARCHAR(100) PRIMARY KEY,
            `value` TEXT NOT NULL,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS customer_notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            order_id INT UNSIGNED NULL,
            title VARCHAR(120) NOT NULL,
            message VARCHAR(255) NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_customer_notifications_customer (customer_id, is_read, created_at),
            KEY idx_customer_notifications_order (order_id),
            CONSTRAINT fk_customer_notifications_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_customer_notifications_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS payment_receipts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            customer_id INT UNSIGNED NOT NULL,
            receipt_no VARCHAR(40) NOT NULL UNIQUE,
            amount DECIMAL(12,2) NOT NULL,
            method VARCHAR(40) NOT NULL DEFAULT "manual",
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_payment_receipts_order (order_id),
            KEY idx_payment_receipts_customer (customer_id),
            CONSTRAINT fk_payment_receipts_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_payment_receipts_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            first_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            locked_until DATETIME NULL,
            UNIQUE KEY uq_login_attempt_identifier_ip (identifier, ip_address),
            KEY idx_login_attempt_locked_until (locked_until)
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS login_audit (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            identifier VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            reason VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_login_audit_identifier (identifier),
            KEY idx_login_audit_created (created_at),
            CONSTRAINT fk_login_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS reminder_dispatch_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            reminder_key VARCHAR(40) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_reminder_dispatch (order_id, reminder_key),
            KEY idx_reminder_dispatch_created (created_at),
            CONSTRAINT fk_reminder_dispatch_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS role_permissions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            role ENUM("admin", "staff", "customer") NOT NULL,
            permission VARCHAR(80) NOT NULL,
            is_allowed TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_role_permission (role, permission)
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id BIGINT NULL,
            before_json JSON NULL,
            after_json JSON NULL,
            meta_json JSON NULL,
            ip_address VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_audit_user (user_id),
            KEY idx_audit_action_created (action, created_at),
            CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS job_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_type VARCHAR(60) NOT NULL,
            payload_json JSON NOT NULL,
            status ENUM("pending", "processing", "completed", "failed") NOT NULL DEFAULT "pending",
            run_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_job_queue_status_run_after (status, run_after)
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notification_preferences (
            user_id INT UNSIGNED PRIMARY KEY,
            email_enabled TINYINT(1) NOT NULL DEFAULT 1,
            sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
            inapp_enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS notification_outbox (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            channel ENUM("email", "sms", "inapp") NOT NULL,
            recipient VARCHAR(255) NOT NULL,
            subject VARCHAR(160) NOT NULL,
            message TEXT NOT NULL,
            status ENUM("pending", "sent", "failed") NOT NULL DEFAULT "pending",
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            provider_response TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_notification_outbox_status (status, created_at),
            CONSTRAINT fk_notification_outbox_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS payment_transactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            customer_id INT UNSIGNED NOT NULL,
            provider VARCHAR(40) NOT NULL,
            transaction_ref VARCHAR(64) NOT NULL UNIQUE,
            provider_txn_id VARCHAR(100) NULL,
            currency VARCHAR(10) NOT NULL DEFAULT "USD",
            amount DECIMAL(12,2) NOT NULL,
            status ENUM("pending", "paid", "failed", "cancelled") NOT NULL DEFAULT "pending",
            checkout_url VARCHAR(255) NULL,
            request_payload JSON NULL,
            webhook_payload JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_payment_txn_order (order_id),
            KEY idx_payment_txn_customer (customer_id),
            CONSTRAINT fk_payment_txn_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_payment_txn_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS webhook_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(40) NOT NULL,
            event_id VARCHAR(120) NOT NULL,
            signature VARCHAR(255) NULL,
            payload JSON NOT NULL,
            processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_webhook_provider_event (provider, event_id)
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS invoices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL UNIQUE,
            customer_id INT UNSIGNED NOT NULL,
            invoice_no VARCHAR(40) NOT NULL UNIQUE,
            amount DECIMAL(12,2) NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT "USD",
            due_date DATE NULL,
            status ENUM("draft", "sent", "paid", "overdue", "cancelled") NOT NULL DEFAULT "draft",
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_invoices_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS contracts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL UNIQUE,
            customer_id INT UNSIGNED NOT NULL,
            contract_no VARCHAR(40) NOT NULL UNIQUE,
            terms_text TEXT NOT NULL,
            status ENUM("draft", "sent", "signed", "cancelled") NOT NULL DEFAULT "draft",
            signed_name VARCHAR(120) NULL,
            signed_ip VARCHAR(45) NULL,
            signed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_contracts_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_contracts_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS kitchen_tasks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            title VARCHAR(150) NOT NULL,
            details VARCHAR(255) NULL,
            status ENUM("todo", "in_progress", "done") NOT NULL DEFAULT "todo",
            assigned_to INT UNSIGNED NULL,
            due_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_kitchen_tasks_order_status (order_id, status),
            CONSTRAINT fk_kitchen_tasks_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_kitchen_tasks_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB'
    );

    ensureColumn($pdo, 'orders', 'package_id', 'INT UNSIGNED NULL');
    ensureColumn($pdo, 'orders', 'event_time', 'TIME NULL');
    ensureColumn($pdo, 'orders', 'guest_count', 'SMALLINT UNSIGNED NULL');
    ensureColumn($pdo, 'orders', 'venue_address', 'VARCHAR(255) NULL');
    ensureColumn($pdo, 'orders', 'special_requests', 'TEXT NULL');
    ensureColumn($pdo, 'orders', 'dietary_notes', 'TEXT NULL');
    ensureColumn($pdo, 'orders', 'payment_status', 'ENUM("unpaid", "partial", "paid") NOT NULL DEFAULT "unpaid"');
    ensureColumn($pdo, 'orders', 'amount_paid', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    ensureColumn($pdo, 'orders', 'deposit_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
    ensureColumn($pdo, 'orders', 'cancellation_reason', 'VARCHAR(255) NULL');
    ensureColumn($pdo, 'orders', 'cancelled_at', 'DATETIME NULL');
    ensureColumn($pdo, 'orders', 'rescheduled_at', 'DATETIME NULL');
    ensureColumn($pdo, 'packages', 'image_path', 'VARCHAR(255) NULL');
    ensureColumn($pdo, 'users', 'archived_at', 'DATETIME NULL');
    ensureColumn($pdo, 'packages', 'archived_at', 'DATETIME NULL');

    ensureIndex($pdo, 'orders', 'idx_orders_event_date_only', 'event_date');
    ensureIndex($pdo, 'orders', 'idx_orders_guest_count', 'guest_count');
    ensureIndex($pdo, 'orders', 'idx_orders_venue_address', 'venue_address');
    ensureIndex($pdo, 'users', 'idx_users_archived_at', 'archived_at');
    ensureIndex($pdo, 'packages', 'idx_packages_archived_at', 'archived_at');

    $pdo->exec('ALTER TABLE orders MODIFY COLUMN status ENUM("pending", "confirmed", "preparing", "out_for_delivery", "completed", "cancelled") NOT NULL');
    $pdo->exec('ALTER TABLE order_activity MODIFY COLUMN old_status ENUM("pending", "confirmed", "preparing", "out_for_delivery", "completed", "cancelled") NULL');
    $pdo->exec('ALTER TABLE order_activity MODIFY COLUMN new_status ENUM("pending", "confirmed", "preparing", "out_for_delivery", "completed", "cancelled") NOT NULL');

    seedDefaultRolePermissions($pdo);
    ensureBaseSettings($pdo);
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column'
    );
    $stmt->execute([
        ':table' => $table,
        ':column' => $column
    ]);

    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}

function ensureIndex(PDO $pdo, string $table, string $indexName, string $column): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND INDEX_NAME = :index_name'
    );
    $stmt->execute([
        ':table' => $table,
        ':index_name' => $indexName
    ]);

    if ((int)$stmt->fetchColumn() > 0) {
        return;
    }

    $pdo->exec("ALTER TABLE {$table} ADD INDEX {$indexName} ({$column})");
}

function seedDefaultUsers(PDO $pdo): void
{
    $seedUsers = [];

    $adminPassword = (string)(getenv('CF_ADMIN_PASSWORD') ?: '');
    if ($adminPassword !== '') {
        $seedUsers[] = [
            'fullname' => (string)(getenv('CF_ADMIN_FULLNAME') ?: 'System Administrator'),
            'username' => (string)(getenv('CF_ADMIN_USERNAME') ?: 'admin'),
            'email' => (string)(getenv('CF_ADMIN_EMAIL') ?: 'admin@caterflow.local'),
            'role' => 'admin',
            'password' => $adminPassword
        ];
    }

    $staffPassword = (string)(getenv('CF_STAFF_PASSWORD') ?: '');
    if ($staffPassword !== '') {
        $seedUsers[] = [
            'fullname' => (string)(getenv('CF_STAFF_FULLNAME') ?: 'Staff Account'),
            'username' => (string)(getenv('CF_STAFF_USERNAME') ?: 'staff'),
            'email' => (string)(getenv('CF_STAFF_EMAIL') ?: 'staff@caterflow.local'),
            'role' => 'staff',
            'password' => $staffPassword
        ];
    }

    if ($seedUsers === []) {
        return;
    }

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
    $demoCustomerPassword = (string)(getenv('CF_DEMO_CUSTOMER_PASSWORD') ?: '');
    if ($demoCustomerPassword === '') {
        return;
    }

    $settingsDefaults = [
        'company_name' => 'CaterFlow',
        'support_email' => 'support@caterflow.local',
        'default_currency' => 'USD',
        'notifications_enabled' => '1',
        'maintenance_mode' => '0',
        'max_events_per_day' => '5',
        'max_guests_per_day' => '800',
        'booking_window_days' => '365',
        'allow_overbooking' => '0',
        'payment_provider' => 'mock',
        'payment_webhook_secret' => 'change-me',
        'payment_checkout_base_url' => 'https://example.test/pay',
        'alerts_email' => 'ops@caterflow.local'
    ];

    $settingsInsert = $pdo->prepare(
        'INSERT INTO app_settings (`key`, `value`, updated_at)
         VALUES (:key, :value, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = CURRENT_TIMESTAMP'
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
                ':password_hash' => password_hash($demoCustomerPassword, PASSWORD_DEFAULT)
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

function seedDefaultRolePermissions(PDO $pdo): void
{
    $defaults = [
        'admin' => [
            'users.manage',
            'packages.manage',
            'orders.manage',
            'settings.manage',
            'exports.generate',
            'reports.view',
            'payments.manage',
            'contracts.manage',
            'kitchen.manage',
            'queue.manage'
        ],
        'staff' => [
            'orders.manage',
            'kitchen.manage',
            'reports.view'
        ],
        'customer' => [
            'orders.create',
            'orders.view_own',
            'orders.manage_own',
            'payments.create_own',
            'contracts.sign_own',
            'notifications.manage_own'
        ]
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO role_permissions (role, permission, is_allowed, created_at)
         VALUES (:role, :permission, 1, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed)'
    );

    foreach ($defaults as $role => $permissions) {
        foreach ($permissions as $permission) {
            $stmt->execute([
                ':role' => $role,
                ':permission' => $permission
            ]);
        }
    }
}

function ensureBaseSettings(PDO $pdo): void
{
    $settings = [
        'company_name' => 'CaterFlow',
        'support_email' => 'support@caterflow.local',
        'default_currency' => 'USD',
        'notifications_enabled' => '1',
        'maintenance_mode' => '0',
        'max_events_per_day' => '5',
        'max_guests_per_day' => '800',
        'booking_window_days' => '365',
        'allow_overbooking' => '0',
        'payment_provider' => 'mock',
        'payment_webhook_secret' => 'change-me',
        'payment_checkout_base_url' => 'https://example.test/pay',
        'alerts_email' => 'ops@caterflow.local'
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (`key`, `value`, updated_at)
         VALUES (:key, :value, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE `value` = `value`'
    );

    foreach ($settings as $key => $value) {
        $stmt->execute([
            ':key' => $key,
            ':value' => $value
        ]);
    }
}
