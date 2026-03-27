CREATE DATABASE IF NOT EXISTS caterflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE caterflow;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(100) NOT NULL,
    username VARCHAR(20) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    province VARCHAR(100) NULL,
    role ENUM('admin', 'staff', 'customer') NOT NULL,
    status ENUM('active', 'pending', 'locked') NOT NULL DEFAULT 'active',
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    last_active DATETIME NULL,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_status (role, status),
    KEY idx_users_last_active (last_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS packages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    image_path VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_packages_status (status),
    KEY idx_packages_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_code VARCHAR(32) NOT NULL,
    customer_id INT UNSIGNED NULL,
    package_id INT UNSIGNED NULL,
    title VARCHAR(150) NOT NULL,
    event_date DATE NULL,
    event_time TIME NULL,
    guest_count SMALLINT UNSIGNED NULL,
    venue_address VARCHAR(255) NULL,
    special_requests TEXT NULL,
    dietary_notes TEXT NULL,
    status ENUM('pending', 'confirmed', 'preparing', 'out_for_delivery', 'completed', 'cancelled') NOT NULL,
    payment_status ENUM('unpaid', 'partial', 'paid') NOT NULL DEFAULT 'unpaid',
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cancellation_reason VARCHAR(255) NULL,
    cancelled_at DATETIME NULL,
    rescheduled_at DATETIME NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_orders_order_code (order_code),
    KEY idx_orders_customer (customer_id),
    KEY idx_orders_package (package_id),
    KEY idx_orders_status_event (status, event_date),
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_orders_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_activity (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    changed_by INT UNSIGNED NOT NULL,
    old_status ENUM('pending', 'confirmed', 'preparing', 'out_for_delivery', 'completed', 'cancelled') NULL,
    new_status ENUM('pending', 'confirmed', 'preparing', 'out_for_delivery', 'completed', 'cancelled') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_order_activity_order (order_id),
    KEY idx_order_activity_changed_by (changed_by),
    KEY idx_order_activity_created (created_at),
    CONSTRAINT fk_order_activity_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_order_activity_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_notifications (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    receipt_no VARCHAR(40) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    method VARCHAR(40) NOT NULL DEFAULT 'manual',
    notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_receipts_no (receipt_no),
    KEY idx_payment_receipts_order (order_id),
    KEY idx_payment_receipts_customer (customer_id),
    CONSTRAINT fk_payment_receipts_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_receipts_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

ALTER TABLE orders
    MODIFY COLUMN status ENUM('pending', 'confirmed', 'preparing', 'out_for_delivery', 'completed', 'cancelled') NOT NULL,
    ADD COLUMN IF NOT EXISTS event_time TIME NULL AFTER event_date,
    ADD COLUMN IF NOT EXISTS guest_count SMALLINT UNSIGNED NULL AFTER event_time,
    ADD COLUMN IF NOT EXISTS venue_address VARCHAR(255) NULL AFTER guest_count,
    ADD COLUMN IF NOT EXISTS special_requests TEXT NULL AFTER venue_address,
    ADD COLUMN IF NOT EXISTS dietary_notes TEXT NULL AFTER special_requests,
    ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid', 'partial', 'paid') NOT NULL DEFAULT 'unpaid' AFTER status,
    ADD COLUMN IF NOT EXISTS amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER payment_status,
    ADD COLUMN IF NOT EXISTS deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount_paid,
    ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(255) NULL AFTER deposit_amount,
    ADD COLUMN IF NOT EXISTS cancelled_at DATETIME NULL AFTER cancellation_reason,
    ADD COLUMN IF NOT EXISTS rescheduled_at DATETIME NULL AFTER cancelled_at;

ALTER TABLE order_activity
    MODIFY COLUMN old_status ENUM('pending', 'confirmed', 'preparing', 'out_for_delivery', 'completed', 'cancelled') NULL,
    MODIFY COLUMN new_status ENUM('pending', 'confirmed', 'preparing', 'out_for_delivery', 'completed', 'cancelled') NOT NULL;

ALTER TABLE orders
    ADD INDEX IF NOT EXISTS idx_orders_event_date_only (event_date),
    ADD INDEX IF NOT EXISTS idx_orders_guest_count (guest_count),
    ADD INDEX IF NOT EXISTS idx_orders_venue_address (venue_address);

CREATE TABLE IF NOT EXISTS app_settings (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT NOT NULL,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO app_settings (`key`, `value`, updated_at)
VALUES
    ('company_name', 'CaterFlow', NOW()),
    ('support_email', 'support@caterflow.local', NOW()),
    ('default_currency', 'USD', NOW()),
    ('notifications_enabled', '1', NOW()),
    ('maintenance_mode', '0', NOW());

INSERT IGNORE INTO packages (name, description, price, status, created_at, updated_at)
VALUES
    ('Classic Buffet', 'Standard buffet service for small gatherings', 1499.00, 'active', NOW(), NOW()),
    ('Premium Wedding', 'Full-service wedding catering package', 5499.00, 'active', NOW(), NOW()),
    ('Corporate Pro', 'Corporate events with staff and premium setup', 3299.00, 'active', NOW(), NOW());
