<?php

declare(strict_types=1);

require_once __DIR__ . '/core.php';
applySecurityHeaders(true);

header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/db.php';

const ADMIN_ORDER_STATUSES = ['pending', 'confirmed', 'preparing', 'out_for_delivery', 'completed', 'cancelled'];
const ORDER_TRANSITIONS = [
    'pending' => ['confirmed', 'cancelled'],
    'confirmed' => ['preparing', 'cancelled'],
    'preparing' => ['out_for_delivery', 'cancelled'],
    'out_for_delivery' => ['completed', 'cancelled'],
    'completed' => [],
    'cancelled' => []
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

if ($action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing action']);
    exit;
}

try {
    $pdo = getDb();
    $currentRole = (string)($_SESSION['role'] ?? '');
    $requiredPermission = requiredPermissionForAdminAction($action);
    if (!userHasPermission($pdo, $currentRole, $requiredPermission)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Insufficient permission']);
        exit;
    }

    if ($method === 'POST') {
        $csrfToken = (string)($_POST['csrf_token'] ?? '');
        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        if ($csrfToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrfToken)) {
            http_response_code(419);
            echo json_encode(['success' => false, 'message' => 'Session expired. Please refresh and try again.']);
            exit;
        }
    }

    if ($method === 'GET') {
        if ($action === 'overview') {
            echo json_encode(['success' => true, 'data' => buildOverview($pdo)]);
            exit;
        }

        if ($action === 'analytics') {
            echo json_encode(['success' => true, 'data' => buildAnalytics($pdo)]);
            exit;
        }

        if ($action === 'orders') {
            $query = trim((string)($_GET['query'] ?? ''));
            $status = trim((string)($_GET['status'] ?? 'all'));
            echo json_encode(['success' => true, 'data' => getOrders($pdo, $query, $status)]);
            exit;
        }

        if ($action === 'customers') {
            $query = trim((string)($_GET['query'] ?? ''));
            $status = trim((string)($_GET['status'] ?? 'all'));
            echo json_encode(['success' => true, 'data' => getCustomers($pdo, $query, $status)]);
            exit;
        }

        if ($action === 'staff') {
            $query = trim((string)($_GET['query'] ?? ''));
            $status = trim((string)($_GET['status'] ?? 'all'));
            echo json_encode(['success' => true, 'data' => getStaff($pdo, $query, $status)]);
            exit;
        }

        if ($action === 'reports') {
            echo json_encode(['success' => true, 'data' => getReports($pdo)]);
            exit;
        }

        if ($action === 'calendar') {
            $startDate = trim((string)($_GET['start_date'] ?? date('Y-m-d')));
            $endDate = trim((string)($_GET['end_date'] ?? date('Y-m-d', strtotime('+30 days'))));
            echo json_encode(['success' => true, 'data' => getCalendarWorkload($pdo, $startDate, $endDate)]);
            exit;
        }

        if ($action === 'export') {
            $type = trim((string)($_GET['type'] ?? 'orders'));
            exportCsv($pdo, $type);
            exit;
        }

        if ($action === 'job-status') {
            $jobId = (int)($_GET['job_id'] ?? 0);
            if ($jobId <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid job id']);
                exit;
            }
            echo json_encode(['success' => true, 'data' => getJobStatus($pdo, $jobId)]);
            exit;
        }

        if ($action === 'audit-logs') {
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 40)));
            echo json_encode(['success' => true, 'data' => getAuditLogs($pdo, $limit)]);
            exit;
        }

        if ($action === 'packages') {
            $query = trim((string)($_GET['query'] ?? ''));
            $status = trim((string)($_GET['status'] ?? 'all'));
            echo json_encode(['success' => true, 'data' => getPackages($pdo, $query, $status)]);
            exit;
        }

        if ($action === 'settings') {
            echo json_encode(['success' => true, 'data' => getSettings($pdo)]);
            exit;
        }

        if ($action === 'users') {
            $query = trim((string)($_GET['query'] ?? ''));
            $status = trim((string)($_GET['status'] ?? 'all'));
            $role = trim((string)($_GET['role'] ?? 'all'));
            echo json_encode(['success' => true, 'data' => getUsers($pdo, $query, $status, $role)]);
            exit;
        }

        if ($action === 'permissions') {
            echo json_encode(['success' => true, 'data' => getPermissionsMatrix($pdo)]);
            exit;
        }

        if ($action === 'user') {
            $userId = (int)($_GET['id'] ?? 0);
            if ($userId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid user id']);
                exit;
            }

            $stmt = $pdo->prepare('SELECT id, fullname, username, email, role, status, phone, address, city, province, created_at, last_active FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found']);
                exit;
            }

            echo json_encode(['success' => true, 'data' => $user]);
            exit;
        }
    }

    if ($method === 'POST') {
        if ($action === 'create-user') {
            $fullname = trim((string)($_POST['fullname'] ?? ''));
            $username = trim((string)($_POST['username'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $role = trim((string)($_POST['role'] ?? 'customer'));
            $status = trim((string)($_POST['status'] ?? 'active'));
            $password = (string)($_POST['password'] ?? '');

            $validRoles = ['admin', 'staff', 'customer'];
            $validStatuses = ['active', 'pending', 'locked'];

            if ($fullname === '' || mb_strlen($fullname) < 2 || mb_strlen($fullname) > 100) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Full name must be 2-100 characters']);
                exit;
            }
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Username must be 3-20 chars and only letters, numbers, underscore']);
                exit;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Valid email is required']);
                exit;
            }
            if (!in_array($role, $validRoles, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid role']);
                exit;
            }
            if (!in_array($status, $validStatuses, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid status']);
                exit;
            }
            if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Password must be 8+ chars with upper, lower, number']);
                exit;
            }

            $exists = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
            $exists->execute([':username' => $username, ':email' => $email]);
            if ($exists->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Username or email already exists']);
                exit;
            }

            $insert = $pdo->prepare('INSERT INTO users (fullname, username, email, role, status, password_hash, created_at, updated_at, last_active) VALUES (:fullname, :username, :email, :role, :status, :password_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
            $insert->execute([
                ':fullname' => $fullname,
                ':username' => $username,
                ':email' => $email,
                ':role' => $role,
                ':status' => $status,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT)
            ]);

            echo json_encode(['success' => true, 'message' => 'User created']);
            exit;
        }

        if ($action === 'update-user') {
            $userId = (int)($_POST['id'] ?? 0);
            $fullname = trim((string)($_POST['fullname'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));

            if ($userId <= 0 || $fullname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid input']);
                exit;
            }

            $exists = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
            $exists->execute([':email' => $email, ':id' => $userId]);
            if ($exists->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Email already in use']);
                exit;
            }

            $update = $pdo->prepare('UPDATE users SET fullname = :fullname, email = :email, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $update->execute([':fullname' => $fullname, ':email' => $email, ':id' => $userId]);

            echo json_encode(['success' => true, 'message' => 'User updated']);
            exit;
        }

        if ($action === 'set-role') {
            $userId = (int)($_POST['id'] ?? 0);
            $role = trim((string)($_POST['role'] ?? ''));
            $validRoles = ['admin', 'staff', 'customer'];
            if ($userId <= 0 || !in_array($role, $validRoles, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid role update']);
                exit;
            }

            $update = $pdo->prepare('UPDATE users SET role = :role, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $update->execute([':role' => $role, ':id' => $userId]);
            echo json_encode(['success' => true, 'message' => 'Role updated']);
            exit;
        }

        if ($action === 'set-permission') {
            $role = trim((string)($_POST['role'] ?? ''));
            $permission = trim((string)($_POST['permission'] ?? ''));
            $isAllowed = (string)($_POST['is_allowed'] ?? '1') === '1';
            if (!in_array($role, ['admin', 'staff', 'customer'], true) || $permission === '') {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid permission update']);
                exit;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO role_permissions (role, permission, is_allowed, created_at)
                 VALUES (:role, :permission, :is_allowed, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed)'
            );
            $stmt->execute([
                ':role' => $role,
                ':permission' => $permission,
                ':is_allowed' => $isAllowed ? 1 : 0
            ]);

            auditLog($pdo, (int)($_SESSION['user_id'] ?? 0), 'rbac.permission_update', 'role_permission', null, null, [
                'role' => $role,
                'permission' => $permission,
                'isAllowed' => $isAllowed
            ]);
            echo json_encode(['success' => true, 'message' => 'Permission updated']);
            exit;
        }

        if ($action === 'set-status') {
            $userId = (int)($_POST['id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? ''));
            $validStatuses = ['active', 'pending', 'locked'];
            if ($userId <= 0 || !in_array($status, $validStatuses, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid status update']);
                exit;
            }

            $update = $pdo->prepare('UPDATE users SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $update->execute([':status' => $status, ':id' => $userId]);
            echo json_encode(['success' => true, 'message' => 'Status updated']);
            exit;
        }

        if ($action === 'archive-user') {
            $userId = (int)($_POST['id'] ?? 0);
            $currentUserId = (int)($_SESSION['user_id'] ?? 0);
            if ($userId <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid user']);
                exit;
            }
            if ($userId === $currentUserId) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'You cannot archive your own account']);
                exit;
            }

            $stmt = $pdo->prepare('UPDATE users SET archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([':id' => $userId]);
            auditLog($pdo, $currentUserId, 'user.archive', 'user', $userId, null, ['archived' => true]);
            echo json_encode(['success' => true, 'message' => 'User archived']);
            exit;
        }

        if ($action === 'restore-user') {
            $userId = (int)($_POST['id'] ?? 0);
            if ($userId <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid user']);
                exit;
            }

            $stmt = $pdo->prepare('UPDATE users SET archived_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([':id' => $userId]);
            auditLog($pdo, (int)($_SESSION['user_id'] ?? 0), 'user.restore', 'user', $userId, null, ['archived' => false]);
            echo json_encode(['success' => true, 'message' => 'User restored']);
            exit;
        }

        if ($action === 'update-order-status') {
            $orderId = (int)($_POST['id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? ''));
            $validStatuses = ADMIN_ORDER_STATUSES;
            $changedBy = (int)($_SESSION['user_id'] ?? 0);
            if ($orderId <= 0 || !in_array($status, $validStatuses, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid order status update']);
                exit;
            }

            $existingStmt = $pdo->prepare('SELECT status, customer_id, order_code FROM orders WHERE id = :id LIMIT 1');
            $existingStmt->execute([':id' => $orderId]);
            $existingOrder = $existingStmt->fetch();
            if (!$existingOrder) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Order not found']);
                exit;
            }

            $oldStatus = (string)$existingOrder['status'];
            if (!canTransitionOrderStatus($oldStatus, $status)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid status transition']);
                exit;
            }
            $update = $pdo->prepare('UPDATE orders SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $update->execute([':status' => $status, ':id' => $orderId]);
            auditLog($pdo, $changedBy, 'order.status_update', 'order', $orderId, ['status' => $oldStatus], ['status' => $status]);

            if ($oldStatus !== $status && $changedBy > 0) {
                $logStmt = $pdo->prepare(
                    'INSERT INTO order_activity (order_id, changed_by, old_status, new_status, created_at)
                     VALUES (:order_id, :changed_by, :old_status, :new_status, CURRENT_TIMESTAMP)'
                );
                $logStmt->execute([
                    ':order_id' => $orderId,
                    ':changed_by' => $changedBy,
                    ':old_status' => $oldStatus,
                    ':new_status' => $status
                ]);

                $customerId = (int)($existingOrder['customer_id'] ?? 0);
                if ($customerId > 0) {
                    createCustomerNotification(
                        $pdo,
                        $customerId,
                        $orderId,
                        'Order Status Updated',
                        sprintf('Order %s is now %s.', (string)$existingOrder['order_code'], humanizeOrderStatus($status))
                    );
                }
            }

            echo json_encode(['success' => true, 'message' => 'Order status updated']);
            exit;
        }

        if ($action === 'save-settings') {
            $allowedKeys = [
                'company_name',
                'support_email',
                'default_currency',
                'notifications_enabled',
                'maintenance_mode',
                'max_events_per_day',
                'max_guests_per_day',
                'booking_window_days',
                'allow_overbooking',
                'payment_provider',
                'payment_webhook_secret',
                'payment_checkout_base_url',
                'alerts_email'
            ];
            $upsert = $pdo->prepare(
                'INSERT INTO app_settings (`key`, `value`, updated_at)
                 VALUES (:key, :value, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = CURRENT_TIMESTAMP'
            );

            foreach ($allowedKeys as $key) {
                if (!array_key_exists($key, $_POST)) {
                    continue;
                }
                $value = trim((string)$_POST[$key]);
                if (in_array($key, ['notifications_enabled', 'maintenance_mode'], true)) {
                    $value = $value === '1' ? '1' : '0';
                }
                if (in_array($key, ['max_events_per_day', 'max_guests_per_day', 'booking_window_days'], true)) {
                    $value = (string)max(1, (int)$value);
                }
                $upsert->execute([':key' => $key, ':value' => $value]);
            }

            echo json_encode(['success' => true, 'message' => 'Settings saved']);
            exit;
        }

        if ($action === 'queue-export') {
            $type = trim((string)($_POST['type'] ?? 'orders'));
            if (!in_array($type, ['orders', 'payments', 'activity'], true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid export type']);
                exit;
            }

            $jobId = queueJob($pdo, 'export_' . $type, [
                'requestedBy' => (int)($_SESSION['user_id'] ?? 0),
                'type' => $type
            ]);
            echo json_encode(['success' => true, 'message' => 'Export queued', 'data' => ['jobId' => $jobId]]);
            exit;
        }

        if ($action === 'queue-reminders') {
            $jobId = queueJob($pdo, 'dispatch_reminders', [
                'requestedBy' => (int)($_SESSION['user_id'] ?? 0)
            ]);
            echo json_encode(['success' => true, 'message' => 'Reminder job queued', 'data' => ['jobId' => $jobId]]);
            exit;
        }

        if ($action === 'create-invoice') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $dueDate = trim((string)($_POST['due_date'] ?? ''));
            if ($orderId <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid order id']);
                exit;
            }

            $stmt = $pdo->prepare('SELECT customer_id, total_amount FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Order not found']);
                exit;
            }

            $invoiceNo = generateInvoiceNo($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO invoices (order_id, customer_id, invoice_no, amount, currency, due_date, status, notes, created_at, updated_at)
                 VALUES (:order_id, :customer_id, :invoice_no, :amount, :currency, :due_date, "sent", :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE due_date = VALUES(due_date), status = VALUES(status), updated_at = CURRENT_TIMESTAMP'
            );
            $insert->execute([
                ':order_id' => $orderId,
                ':customer_id' => (int)$order['customer_id'],
                ':invoice_no' => $invoiceNo,
                ':amount' => (float)$order['total_amount'],
                ':currency' => getSetting($pdo, 'default_currency', 'USD'),
                ':due_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) ? $dueDate : null,
                ':notes' => 'Auto generated invoice'
            ]);

            auditLog($pdo, (int)($_SESSION['user_id'] ?? 0), 'invoice.create', 'order', $orderId, null, ['invoiceNo' => $invoiceNo]);
            echo json_encode(['success' => true, 'message' => 'Invoice generated', 'data' => ['invoiceNo' => $invoiceNo]]);
            exit;
        }

        if ($action === 'create-contract') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $termsText = trim((string)($_POST['terms_text'] ?? ''));
            if ($orderId <= 0 || $termsText === '') {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Order and terms are required']);
                exit;
            }

            $stmt = $pdo->prepare('SELECT customer_id FROM orders WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Order not found']);
                exit;
            }

            $contractNo = generateContractNo($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO contracts (order_id, customer_id, contract_no, terms_text, status, created_at, updated_at)
                 VALUES (:order_id, :customer_id, :contract_no, :terms_text, "sent", CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE terms_text = VALUES(terms_text), status = "sent", updated_at = CURRENT_TIMESTAMP'
            );
            $insert->execute([
                ':order_id' => $orderId,
                ':customer_id' => (int)$order['customer_id'],
                ':contract_no' => $contractNo,
                ':terms_text' => $termsText
            ]);

            auditLog($pdo, (int)($_SESSION['user_id'] ?? 0), 'contract.create', 'order', $orderId, null, ['contractNo' => $contractNo]);
            echo json_encode(['success' => true, 'message' => 'Contract generated', 'data' => ['contractNo' => $contractNo]]);
            exit;
        }

        if ($action === 'create-package') {
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $price = (float)($_POST['price'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'active'));

            if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Package name must be 2-120 characters']);
                exit;
            }
            if ($price <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Package price must be greater than 0']);
                exit;
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid package status']);
                exit;
            }

            $imagePath = null;
            if (isset($_FILES['image']) && is_array($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $imagePath = handlePackageImageUpload($_FILES['image']);
            }

            $insert = $pdo->prepare(
                'INSERT INTO packages (name, description, price, status, image_path, created_at, updated_at)
                 VALUES (:name, :description, :price, :status, :image_path, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                ':name' => $name,
                ':description' => $description,
                ':price' => $price,
                ':status' => $status,
                ':image_path' => $imagePath
            ]);

            echo json_encode(['success' => true, 'message' => 'Package created']);
            exit;
        }

        if ($action === 'update-package') {
            $packageId = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $price = (float)($_POST['price'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'active'));

            if ($packageId <= 0 || $name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid package details']);
                exit;
            }
            if ($price <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Package price must be greater than 0']);
                exit;
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid package status']);
                exit;
            }

            $currentStmt = $pdo->prepare('SELECT image_path FROM packages WHERE id = :id LIMIT 1');
            $currentStmt->execute([':id' => $packageId]);
            $currentPackage = $currentStmt->fetch();
            if (!$currentPackage) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Package not found']);
                exit;
            }

            $imagePath = (string)($currentPackage['image_path'] ?? '');
            if (isset($_FILES['image']) && is_array($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $uploadedPath = handlePackageImageUpload($_FILES['image']);
                if ($uploadedPath !== null) {
                    $imagePath = $uploadedPath;
                }
            }

            $update = $pdo->prepare(
                'UPDATE packages
                 SET name = :name, description = :description, price = :price, status = :status, image_path = :image_path, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $update->execute([
                ':name' => $name,
                ':description' => $description,
                ':price' => $price,
                ':status' => $status,
                ':image_path' => $imagePath,
                ':id' => $packageId
            ]);

            echo json_encode(['success' => true, 'message' => 'Package updated']);
            exit;
        }

        if ($action === 'archive-package') {
            $packageId = (int)($_POST['id'] ?? 0);
            if ($packageId <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid package']);
                exit;
            }

            $stmt = $pdo->prepare('UPDATE packages SET archived_at = CURRENT_TIMESTAMP, status = "inactive", updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([':id' => $packageId]);
            echo json_encode(['success' => true, 'message' => 'Package archived']);
            exit;
        }

        if ($action === 'restore-package') {
            $packageId = (int)($_POST['id'] ?? 0);
            if ($packageId <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid package']);
                exit;
            }

            $stmt = $pdo->prepare('UPDATE packages SET archived_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([':id' => $packageId]);
            echo json_encode(['success' => true, 'message' => 'Package restored']);
            exit;
        }

        if ($action === 'dispatch-reminders') {
            $count = dispatchUpcomingReminders($pdo);
            echo json_encode(['success' => true, 'message' => 'Reminder dispatch complete', 'data' => ['created' => $count]]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported action']);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

function buildAnalytics(PDO $pdo): array
{
    $totalRevenue = (float)$pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status IN ("confirmed", "preparing", "out_for_delivery", "completed")')->fetchColumn();
    $ordersCount = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $avgOrder = $ordersCount > 0 ? $totalRevenue / $ordersCount : 0.0;
    $activeCustomers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND status = 'active'")->fetchColumn();

    return [
        'kpis' => [
            'totalRevenue' => round($totalRevenue, 2),
            'totalOrders' => $ordersCount,
            'avgOrderValue' => round($avgOrder, 2),
            'activeCustomers' => $activeCustomers
        ],
        'monthlyTrend' => [58, 64, 62, 76, 88, 84, 96, 102, 98, 112, 118, 124],
        'conversion' => [
            'leadToClient' => 42,
            'repeatClients' => 38,
            'onTimeDelivery' => 93
        ]
    ];
}

function getOrders(PDO $pdo, string $query, string $status): array
{
    $sql = 'SELECT o.id, o.order_code, o.title, o.event_date, o.guest_count, o.venue_address, o.status, o.total_amount, u.fullname AS customer_name
            FROM orders o
            LEFT JOIN users u ON u.id = o.customer_id
            WHERE 1=1';
    $params = [];

    if ($query !== '') {
        $sql .= ' AND (LOWER(o.order_code) LIKE :query OR LOWER(o.title) LIKE :query OR LOWER(COALESCE(u.fullname, "")) LIKE :query)';
        $params[':query'] = '%' . mb_strtolower($query) . '%';
    }

    if ($status !== 'all') {
        $sql .= ' AND o.status = :status';
        $params[':status'] = $status;
    }

    $sql .= ' ORDER BY o.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $summary = [
        'pending' => 0,
        'confirmed' => 0,
        'preparing' => 0,
        'out_for_delivery' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'revenue' => 0.0
    ];

    foreach ($rows as $row) {
        $statusKey = (string)$row['status'];
        if (array_key_exists($statusKey, $summary)) {
            $summary[$statusKey]++;
        }
        if (in_array($statusKey, ['confirmed', 'preparing', 'out_for_delivery', 'completed'], true)) {
            $summary['revenue'] += (float)$row['total_amount'];
        }
    }

    return [
        'summary' => $summary,
        'rows' => array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'code' => (string)$row['order_code'],
                'title' => (string)$row['title'],
                'customer' => (string)($row['customer_name'] ?: 'Unassigned'),
                'eventDate' => (string)($row['event_date'] ?: 'N/A'),
                'guestCount' => (int)($row['guest_count'] ?? 0),
                'venueAddress' => (string)($row['venue_address'] ?? ''),
                'status' => (string)$row['status'],
                'amount' => (float)$row['total_amount']
            ];
        }, $rows)
    ];
}

function getCustomers(PDO $pdo, string $query, string $status): array
{
    return getPeopleByRole($pdo, 'customer', $query, $status);
}

function getStaff(PDO $pdo, string $query, string $status): array
{
    return getPeopleByRole($pdo, 'staff', $query, $status);
}

function getPeopleByRole(PDO $pdo, string $role, string $query, string $status): array
{
    $includeArchived = isset($_GET['include_archived']) && (string)$_GET['include_archived'] === '1';
    $sql = 'SELECT id, fullname, username, email, phone, status, last_active, created_at
            FROM users
            WHERE role = :role';
    $params = [':role' => $role];

    if (!$includeArchived) {
        $sql .= ' AND archived_at IS NULL';
    }

    if ($query !== '') {
        $sql .= ' AND (LOWER(fullname) LIKE :query OR LOWER(email) LIKE :query OR LOWER(username) LIKE :query)';
        $params[':query'] = '%' . mb_strtolower($query) . '%';
    }

    if ($status !== 'all') {
        $sql .= ' AND status = :status';
        $params[':status'] = $status;
    }

    $sql .= ' ORDER BY id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return [
        'totals' => [
            'all' => count($rows),
            'active' => count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'active')),
            'pending' => count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'pending')),
            'locked' => count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'locked'))
        ],
        'rows' => array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'name' => (string)$row['fullname'],
                'username' => (string)$row['username'],
                'email' => (string)$row['email'],
                'phone' => (string)($row['phone'] ?? ''),
                'status' => (string)$row['status'],
                'createdAt' => (string)$row['created_at'],
                'lastActive' => humanizeDateTime((string)($row['last_active'] ?? ''))
            ];
        }, $rows)
    ];
}

function getReports(PDO $pdo): array
{
    $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $totalOrders = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $completedOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'completed'")->fetchColumn();
    $cancelledOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'cancelled'")->fetchColumn();
    $revenue = (float)$pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status IN ("confirmed", "preparing", "out_for_delivery", "completed")')->fetchColumn();

    return [
        'summary' => [
            'totalUsers' => $totalUsers,
            'totalOrders' => $totalOrders,
            'completedOrders' => $completedOrders,
            'cancelledOrders' => $cancelledOrders,
            'revenue' => round($revenue, 2)
        ],
        'generatedAt' => date('c'),
        'highlights' => [
            'Best performing month: November',
            'Customer retention improved by 7%',
            'On-time completion rate remains above 90%'
        ]
    ];
}

function createCustomerNotification(PDO $pdo, int $customerId, int $orderId, string $title, string $message): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO customer_notifications (customer_id, order_id, title, message, is_read, created_at)
         VALUES (:customer_id, :order_id, :title, :message, 0, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        ':customer_id' => $customerId,
        ':order_id' => $orderId,
        ':title' => $title,
        ':message' => $message
    ]);
}

function humanizeOrderStatus(string $status): string
{
    return match ($status) {
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'preparing' => 'Preparing',
        'out_for_delivery' => 'Out for Delivery',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => 'Pending'
    };
}

function getPackages(PDO $pdo, string $query, string $status): array
{
    $includeArchived = isset($_GET['include_archived']) && (string)$_GET['include_archived'] === '1';
    $sql = 'SELECT id, name, description, price, status, image_path, created_at, updated_at
            FROM packages
            WHERE 1=1';
    $params = [];

    if (!$includeArchived) {
        $sql .= ' AND archived_at IS NULL';
    }

    if ($query !== '') {
        $sql .= ' AND (LOWER(name) LIKE :query OR LOWER(COALESCE(description, "")) LIKE :query)';
        $params[':query'] = '%' . mb_strtolower($query) . '%';
    }

    if ($status !== 'all') {
        $sql .= ' AND status = :status';
        $params[':status'] = $status;
    }

    $sql .= ' ORDER BY id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return [
        'summary' => [
            'all' => count($rows),
            'active' => count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'active')),
            'inactive' => count(array_filter($rows, static fn(array $row): bool => $row['status'] === 'inactive'))
        ],
        'rows' => array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'description' => (string)($row['description'] ?? ''),
                'price' => (float)$row['price'],
                'status' => (string)$row['status'],
                'imagePath' => (string)($row['image_path'] ?? ''),
                'updatedAt' => humanizeDateTime((string)($row['updated_at'] ?? $row['created_at'] ?? ''))
            ];
        }, $rows)
    ];
}

function handlePackageImageUpload(array $file): ?string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed');
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Invalid uploaded image');
    }

    $maxBytes = 5 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Image must be 5MB or smaller');
    }

    $mimeType = (string)(mime_content_type($tmpName) ?: '');
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];
    if (!array_key_exists($mimeType, $allowed)) {
        throw new RuntimeException('Unsupported image format');
    }

    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'packages';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to prepare upload folder');
    }

    $filename = 'pkg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mimeType];
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Failed to store uploaded image');
    }

    return 'uploads/packages/' . $filename;
}

function getSettings(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT `key`, `value` FROM app_settings ORDER BY `key` ASC');
    $rows = $stmt->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[(string)$row['key']] = (string)$row['value'];
    }

    return $settings;
}

function buildOverview(PDO $pdo): array
{
    $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $activeStaff = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'staff' AND status = 'active'")->fetchColumn();
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
    $activeUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();

    $rolesStmt = $pdo->query("SELECT role, COUNT(*) AS count FROM users GROUP BY role");
    $roleCounts = ['admin' => 0, 'staff' => 0, 'customer' => 0];
    foreach ($rolesStmt->fetchAll() as $row) {
        $role = (string)($row['role'] ?? '');
        if (array_key_exists($role, $roleCounts)) {
            $roleCounts[$role] = (int)$row['count'];
        }
    }

    $totalRoles = max(1, array_sum($roleCounts));
    $composition = [
        ['label' => 'Corporate (Staff)', 'value' => (int)round(($roleCounts['staff'] / $totalRoles) * 100), 'color' => '#7c3aed'],
        ['label' => 'Weddings (Customers)', 'value' => (int)round(($roleCounts['customer'] / $totalRoles) * 100), 'color' => '#22d3ee'],
        ['label' => 'Admin', 'value' => (int)round(($roleCounts['admin'] / $totalRoles) * 100), 'color' => '#f59e0b']
    ];

    $sumComp = array_sum(array_column($composition, 'value'));
    if ($sumComp !== 100) {
        $composition[0]['value'] += (100 - $sumComp);
    }

    return [
        'stats' => [
            'revenue' => [
                'value' => max(50000, $activeUsers * 1450),
                'growth' => '+12.4%'
            ],
            'newClients' => [
                'value' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn(),
                'growth' => '+8.1%'
            ],
            'pendingOrders' => [
                'value' => $pendingCount,
                'growth' => $pendingCount > 0 ? '+1.3%' : '-2.9%'
            ],
            'csat' => [
                'value' => min(99, 85 + (int)round(($activeUsers / max(1, $totalUsers)) * 10)),
                'growth' => '+1.6%'
            ],
            'activeStaff' => $activeStaff,
            'totalUsers' => $totalUsers
        ],
        'revenueTrend' => [58, 64, 62, 76, 88, 84, 96, 102, 98, 112, 118, 124],
        'orderComposition' => $composition
    ];
}

function getUsers(PDO $pdo, string $query, string $status, string $role): array
{
    $includeArchived = isset($_GET['include_archived']) && (string)$_GET['include_archived'] === '1';
    $sql = 'SELECT id, fullname, username, email, role, status, last_active FROM users WHERE 1=1';
    $params = [];

    if (!$includeArchived) {
        $sql .= ' AND archived_at IS NULL';
    }

    if ($query !== '') {
        $sql .= ' AND (LOWER(fullname) LIKE :query OR LOWER(email) LIKE :query OR LOWER(username) LIKE :query)';
        $params[':query'] = '%' . mb_strtolower($query) . '%';
    }

    if ($status !== 'all') {
        $sql .= ' AND status = :status';
        $params[':status'] = $status;
    }

    if ($role !== 'all') {
        $sql .= ' AND role = :role';
        $params[':role'] = $role;
    }

    $sql .= ' ORDER BY id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    return array_map(static function (array $user): array {
        return [
            'id' => (int)$user['id'],
            'name' => (string)$user['fullname'],
            'username' => (string)$user['username'],
            'email' => (string)$user['email'],
            'role' => (string)$user['role'],
            'status' => (string)$user['status'],
            'lastActive' => humanizeDateTime((string)($user['last_active'] ?? ''))
        ];
    }, $users);
}

function humanizeDateTime(string $datetime): string
{
    if ($datetime === '') {
        return 'N/A';
    }

    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return $datetime;
    }

    $diff = time() - $timestamp;
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';

    return date('M d, Y', $timestamp);
}

function canTransitionOrderStatus(string $fromStatus, string $toStatus): bool
{
    if ($fromStatus === $toStatus) {
        return true;
    }

    $from = trim(mb_strtolower($fromStatus));
    $to = trim(mb_strtolower($toStatus));
    if (!array_key_exists($from, ORDER_TRANSITIONS)) {
        return false;
    }

    return in_array($to, ORDER_TRANSITIONS[$from], true);
}

function exportCsv(PDO $pdo, string $type): void
{
    $type = trim(mb_strtolower($type));
    $filenameSuffix = date('Ymd_His');

    if ($type === 'orders') {
        $stmt = $pdo->query(
            'SELECT o.order_code, o.title, o.event_date, o.status, o.payment_status, o.total_amount, o.amount_paid, u.fullname AS customer_name
             FROM orders o
             LEFT JOIN users u ON u.id = o.customer_id
             ORDER BY o.id DESC'
        );
        outputCsv(
            'orders_' . $filenameSuffix . '.csv',
            ['Order Code', 'Title', 'Event Date', 'Status', 'Payment Status', 'Total Amount', 'Amount Paid', 'Customer'],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            static fn(array $r): array => [
                (string)($r['order_code'] ?? ''),
                (string)($r['title'] ?? ''),
                (string)($r['event_date'] ?? ''),
                (string)($r['status'] ?? ''),
                (string)($r['payment_status'] ?? ''),
                (string)($r['total_amount'] ?? '0'),
                (string)($r['amount_paid'] ?? '0'),
                (string)($r['customer_name'] ?? '')
            ]
        );
        return;
    }

    if ($type === 'payments') {
        $stmt = $pdo->query(
            'SELECT pr.receipt_no, pr.amount, pr.method, pr.created_at, o.order_code, u.fullname AS customer_name
             FROM payment_receipts pr
             LEFT JOIN orders o ON o.id = pr.order_id
             LEFT JOIN users u ON u.id = pr.customer_id
             ORDER BY pr.id DESC'
        );
        outputCsv(
            'payments_' . $filenameSuffix . '.csv',
            ['Receipt No', 'Amount', 'Method', 'Created At', 'Order Code', 'Customer'],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            static fn(array $r): array => [
                (string)($r['receipt_no'] ?? ''),
                (string)($r['amount'] ?? '0'),
                (string)($r['method'] ?? ''),
                (string)($r['created_at'] ?? ''),
                (string)($r['order_code'] ?? ''),
                (string)($r['customer_name'] ?? '')
            ]
        );
        return;
    }

    if ($type === 'activity') {
        $stmt = $pdo->query(
            'SELECT oa.created_at, o.order_code, oa.old_status, oa.new_status, u.fullname AS changed_by
             FROM order_activity oa
             LEFT JOIN orders o ON o.id = oa.order_id
             LEFT JOIN users u ON u.id = oa.changed_by
             ORDER BY oa.id DESC'
        );
        outputCsv(
            'activity_' . $filenameSuffix . '.csv',
            ['Created At', 'Order Code', 'Old Status', 'New Status', 'Changed By'],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            static fn(array $r): array => [
                (string)($r['created_at'] ?? ''),
                (string)($r['order_code'] ?? ''),
                (string)($r['old_status'] ?? ''),
                (string)($r['new_status'] ?? ''),
                (string)($r['changed_by'] ?? '')
            ]
        );
        return;
    }

    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unsupported export type']);
}

function outputCsv(string $filename, array $header, array $rows, callable $rowMapper): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('Unable to stream export');
    }

    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, $rowMapper($row));
    }
    fclose($out);
}

function dispatchUpcomingReminders(PDO $pdo): int
{
    $targets = [
        ['days' => 7, 'key' => 'd7', 'title' => 'Event Reminder (7 days)'],
        ['days' => 1, 'key' => 'd1', 'title' => 'Event Reminder (tomorrow)']
    ];

    $selectStmt = $pdo->prepare(
        'SELECT o.id, o.order_code, o.event_date, o.customer_id
         FROM orders o
         WHERE o.event_date = :event_date
           AND o.status IN ("pending", "confirmed", "preparing", "out_for_delivery")
           AND o.customer_id IS NOT NULL'
    );

    $checkLogStmt = $pdo->prepare(
        'SELECT id FROM reminder_dispatch_log WHERE order_id = :order_id AND reminder_key = :reminder_key LIMIT 1'
    );
    $insertLogStmt = $pdo->prepare(
        'INSERT INTO reminder_dispatch_log (order_id, reminder_key, created_at) VALUES (:order_id, :reminder_key, CURRENT_TIMESTAMP)'
    );
    $insertNotifStmt = $pdo->prepare(
        'INSERT INTO customer_notifications (customer_id, order_id, title, message, is_read, created_at)
         VALUES (:customer_id, :order_id, :title, :message, 0, CURRENT_TIMESTAMP)'
    );

    $created = 0;
    foreach ($targets as $target) {
        $eventDate = date('Y-m-d', strtotime('+' . (int)$target['days'] . ' days'));
        $selectStmt->execute([':event_date' => $eventDate]);
        foreach ($selectStmt->fetchAll() as $order) {
            $orderId = (int)$order['id'];
            $customerId = (int)$order['customer_id'];
            if ($orderId <= 0 || $customerId <= 0) {
                continue;
            }

            $checkLogStmt->execute([
                ':order_id' => $orderId,
                ':reminder_key' => (string)$target['key']
            ]);
            if ($checkLogStmt->fetch()) {
                continue;
            }

            $insertNotifStmt->execute([
                ':customer_id' => $customerId,
                ':order_id' => $orderId,
                ':title' => (string)$target['title'],
                ':message' => sprintf('Reminder: Order %s is scheduled on %s.', (string)$order['order_code'], (string)$order['event_date'])
            ]);
            $insertLogStmt->execute([
                ':order_id' => $orderId,
                ':reminder_key' => (string)$target['key']
            ]);
            $created++;
        }
    }

    return $created;
}

function requiredPermissionForAdminAction(string $action): string
{
    return match ($action) {
        'permissions', 'set-permission' => 'settings.manage',
        'users', 'user', 'create-user', 'update-user', 'set-role', 'set-status', 'archive-user', 'restore-user' => 'users.manage',
        'packages', 'create-package', 'update-package', 'archive-package', 'restore-package' => 'packages.manage',
        'orders', 'update-order-status', 'calendar' => 'orders.manage',
        'reports', 'analytics', 'overview' => 'reports.view',
        'settings', 'save-settings' => 'settings.manage',
        'export', 'queue-export' => 'exports.generate',
        'job-status' => 'queue.manage',
        'create-invoice' => 'payments.manage',
        'create-contract' => 'contracts.manage',
        'dispatch-reminders', 'queue-reminders' => 'queue.manage',
        'audit-logs' => 'reports.view',
        default => ''
    };
}

function getPermissionsMatrix(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT role, permission, is_allowed FROM role_permissions ORDER BY role ASC, permission ASC');
    $rows = $stmt->fetchAll();

    $matrix = [
        'admin' => [],
        'staff' => [],
        'customer' => []
    ];

    foreach ($rows as $row) {
        $role = (string)($row['role'] ?? '');
        if (!array_key_exists($role, $matrix)) {
            continue;
        }

        $matrix[$role][] = [
            'permission' => (string)$row['permission'],
            'isAllowed' => (int)($row['is_allowed'] ?? 0) === 1
        ];
    }

    return $matrix;
}

function getCalendarWorkload(PDO $pdo, string $startDate, string $endDate): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        throw new RuntimeException('Invalid date range');
    }

    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    if ($startTs === false || $endTs === false || $endTs < $startTs) {
        throw new RuntimeException('Invalid date range');
    }

    $stmt = $pdo->prepare(
        'SELECT event_date, COUNT(*) AS events_count, COALESCE(SUM(guest_count), 0) AS guests_count
         FROM orders
         WHERE event_date BETWEEN :start_date AND :end_date
           AND status IN ("pending", "confirmed", "preparing", "out_for_delivery")
         GROUP BY event_date'
    );
    $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string)$row['event_date']] = [
            'events' => (int)($row['events_count'] ?? 0),
            'guests' => (int)($row['guests_count'] ?? 0)
        ];
    }

    $eventCap = max(1, (int)getSetting($pdo, 'max_events_per_day', '5'));
    $guestCap = max(1, (int)getSetting($pdo, 'max_guests_per_day', '800'));

    $rows = [];
    for ($cursor = $startTs; $cursor <= $endTs; $cursor += 86400) {
        $date = date('Y-m-d', $cursor);
        $usage = $map[$date] ?? ['events' => 0, 'guests' => 0];

        $rows[] = [
            'date' => $date,
            'events' => $usage['events'],
            'guests' => $usage['guests'],
            'eventsCapacity' => $eventCap,
            'guestsCapacity' => $guestCap,
            'isConflict' => ($usage['events'] > $eventCap) || ($usage['guests'] > $guestCap)
        ];
    }

    return $rows;
}

function getJobStatus(PDO $pdo, int $jobId): array
{
    $stmt = $pdo->prepare('SELECT id, job_type, status, attempts, last_error, created_at, updated_at FROM job_queue WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $jobId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Job not found');
    }

    return [
        'id' => (int)$row['id'],
        'type' => (string)$row['job_type'],
        'status' => (string)$row['status'],
        'attempts' => (int)$row['attempts'],
        'lastError' => (string)($row['last_error'] ?? ''),
        'createdAt' => (string)$row['created_at'],
        'updatedAt' => (string)$row['updated_at']
    ];
}

function getAuditLogs(PDO $pdo, int $limit): array
{
    $stmt = $pdo->prepare(
        'SELECT al.id, al.action, al.entity_type, al.entity_id, al.ip_address, al.created_at, u.fullname AS actor_name
         FROM audit_logs al
         LEFT JOIN users u ON u.id = al.user_id
         ORDER BY al.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'action' => (string)$row['action'],
            'entityType' => (string)$row['entity_type'],
            'entityId' => $row['entity_id'] !== null ? (int)$row['entity_id'] : null,
            'actorName' => (string)($row['actor_name'] ?? 'System'),
            'ipAddress' => (string)$row['ip_address'],
            'createdAt' => (string)$row['created_at']
        ];
    }, $stmt->fetchAll());
}

function generateInvoiceNo(PDO $pdo): string
{
    for ($i = 0; $i < 5; $i++) {
        $code = 'INV-' . date('ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('SELECT id FROM invoices WHERE invoice_no = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }

    return 'INV-' . date('ymd') . '-' . time();
}

function generateContractNo(PDO $pdo): string
{
    for ($i = 0; $i < 5; $i++) {
        $code = 'CTR-' . date('ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('SELECT id FROM contracts WHERE contract_no = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }

    return 'CTR-' . date('ymd') . '-' . time();
}
