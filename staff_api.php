<?php

declare(strict_types=1);

require_once __DIR__ . '/core.php';
applySecurityHeaders(true);

header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/db.php';

const STAFF_ORDER_STATUSES = ['pending', 'confirmed', 'preparing', 'out_for_delivery', 'completed', 'cancelled'];
const STAFF_ORDER_TRANSITIONS = [
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
    $requiredPermission = requiredPermissionForStaffAction($action);
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
            echo json_encode(['success' => true, 'data' => getOverview($pdo)]);
            exit;
        }

        if ($action === 'orders') {
            $query = trim((string)($_GET['query'] ?? ''));
            $status = trim((string)($_GET['status'] ?? 'all'));
            echo json_encode(['success' => true, 'data' => getOrders($pdo, $query, $status)]);
            exit;
        }

        if ($action === 'customers') {
            echo json_encode(['success' => true, 'data' => getLatestCustomers($pdo)]);
            exit;
        }

        if ($action === 'activity') {
            $query = trim((string)($_GET['query'] ?? ''));
            $date = trim((string)($_GET['date'] ?? ''));
            echo json_encode(['success' => true, 'data' => getActivityLogs($pdo, $query, $date)]);
            exit;
        }

        if ($action === 'calendar') {
            $startDate = trim((string)($_GET['start_date'] ?? date('Y-m-d')));
            $endDate = trim((string)($_GET['end_date'] ?? date('Y-m-d', strtotime('+14 days'))));
            echo json_encode(['success' => true, 'data' => getStaffCalendar($pdo, $startDate, $endDate)]);
            exit;
        }

        if ($action === 'kitchen-tasks') {
            $orderId = (int)($_GET['order_id'] ?? 0);
            echo json_encode(['success' => true, 'data' => getKitchenTasks($pdo, $orderId)]);
            exit;
        }
    }

    if ($method === 'POST') {
        if ($action === 'update-order-status') {
            $orderId = (int)($_POST['id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? ''));
            $validStatuses = STAFF_ORDER_STATUSES;
            $changedBy = (int)($_SESSION['user_id'] ?? 0);

            if ($orderId <= 0 || $changedBy <= 0 || !in_array($status, $validStatuses, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid order update']);
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

            $currentStatus = (string)$existingOrder['status'];
            $customerId = (int)($existingOrder['customer_id'] ?? 0);
            $orderCode = (string)($existingOrder['order_code'] ?? '');

            if (!canTransitionOrderStatus($currentStatus, $status)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid status transition']);
                exit;
            }

            $stmt = $pdo->prepare('UPDATE orders SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([
                ':status' => $status,
                ':id' => $orderId
            ]);

            if ((string)$currentStatus !== $status) {
                $logStmt = $pdo->prepare(
                    'INSERT INTO order_activity (order_id, changed_by, old_status, new_status, created_at)
                     VALUES (:order_id, :changed_by, :old_status, :new_status, CURRENT_TIMESTAMP)'
                );
                $logStmt->execute([
                    ':order_id' => $orderId,
                    ':changed_by' => $changedBy,
                    ':old_status' => (string)$currentStatus,
                    ':new_status' => $status
                ]);

                auditLog($pdo, $changedBy, 'order.status_update', 'order', $orderId, ['status' => $currentStatus], ['status' => $status]);

                if ($customerId > 0) {
                    createCustomerNotification(
                        $pdo,
                        $customerId,
                        $orderId,
                        'Order Status Updated',
                        sprintf('Order %s is now %s.', $orderCode, humanizeStatus($status))
                    );
                }
            }

            echo json_encode(['success' => true, 'message' => 'Order updated']);
            exit;
        }

        if ($action === 'create-kitchen-task') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $details = trim((string)($_POST['details'] ?? ''));
            $assignedTo = (int)($_POST['assigned_to'] ?? 0);
            $dueAt = trim((string)($_POST['due_at'] ?? ''));

            if ($orderId <= 0 || $title === '') {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Order and title are required']);
                exit;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO kitchen_tasks (order_id, title, details, status, assigned_to, due_at, created_at, updated_at)
                 VALUES (:order_id, :title, :details, "todo", :assigned_to, :due_at, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                ':order_id' => $orderId,
                ':title' => $title,
                ':details' => $details !== '' ? $details : null,
                ':assigned_to' => $assignedTo > 0 ? $assignedTo : null,
                ':due_at' => $dueAt !== '' ? $dueAt : null
            ]);

            $taskId = (int)$pdo->lastInsertId();
            auditLog($pdo, (int)($_SESSION['user_id'] ?? 0), 'kitchen.task_create', 'kitchen_task', $taskId, null, ['orderId' => $orderId, 'title' => $title]);
            echo json_encode(['success' => true, 'message' => 'Kitchen task created', 'data' => ['id' => $taskId]]);
            exit;
        }

        if ($action === 'update-kitchen-task-status') {
            $taskId = (int)($_POST['id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'todo'));
            if ($taskId <= 0 || !in_array($status, ['todo', 'in_progress', 'done'], true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid task update']);
                exit;
            }

            $beforeStmt = $pdo->prepare('SELECT status FROM kitchen_tasks WHERE id = :id LIMIT 1');
            $beforeStmt->execute([':id' => $taskId]);
            $oldStatus = (string)($beforeStmt->fetchColumn() ?: 'todo');

            $stmt = $pdo->prepare(
                'UPDATE kitchen_tasks
                 SET status = :status,
                     completed_at = CASE WHEN :status = "done" THEN CURRENT_TIMESTAMP ELSE NULL END,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmt->execute([':status' => $status, ':id' => $taskId]);

            auditLog($pdo, (int)($_SESSION['user_id'] ?? 0), 'kitchen.task_status', 'kitchen_task', $taskId, ['status' => $oldStatus], ['status' => $status]);
            echo json_encode(['success' => true, 'message' => 'Kitchen task updated']);
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

function getOverview(PDO $pdo): array
{
    $pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
    $confirmedOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'confirmed'")->fetchColumn();
    $completedOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'completed'")->fetchColumn();

    $todayStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE event_date = :today');
    $todayStmt->execute([':today' => date('Y-m-d')]);
    $todayEvents = (int)$todayStmt->fetchColumn();

    return [
        'pendingOrders' => $pendingOrders,
        'confirmedOrders' => $confirmedOrders,
        'completedOrders' => $completedOrders,
        'todayEvents' => $todayEvents
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

    $sql .= ' ORDER BY
                CASE o.status
                    WHEN "pending" THEN 1
                    WHEN "confirmed" THEN 2
                    WHEN "preparing" THEN 3
                    WHEN "out_for_delivery" THEN 4
                    WHEN "completed" THEN 5
                    ELSE 6
                END,
                o.event_date ASC,
                o.id DESC
              LIMIT 50';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'orderCode' => (string)$row['order_code'],
            'title' => (string)$row['title'],
            'customerName' => (string)($row['customer_name'] ?: 'Unassigned'),
            'eventDate' => (string)($row['event_date'] ?: 'N/A'),
            'guestCount' => (int)($row['guest_count'] ?? 0),
            'venueAddress' => (string)($row['venue_address'] ?? ''),
            'status' => (string)$row['status'],
            'totalAmount' => (float)$row['total_amount']
        ];
    }, $rows);
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

function humanizeStatus(string $status): string
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

function getLatestCustomers(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT fullname, email, phone
         FROM users
         WHERE role = 'customer'
         ORDER BY id DESC
         LIMIT 8"
    );
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'fullname' => (string)$row['fullname'],
            'email' => (string)$row['email'],
            'phone' => (string)($row['phone'] ?: 'No phone')
        ];
    }, $rows);
}

function getActivityLogs(PDO $pdo, string $query = '', string $date = ''): array
{
    $sql = 'SELECT oa.created_at, oa.old_status, oa.new_status,
                o.order_code, o.title,
                u.fullname AS staff_name
         FROM order_activity oa
         LEFT JOIN orders o ON o.id = oa.order_id
         LEFT JOIN users u ON u.id = oa.changed_by
         WHERE 1=1';
    $params = [];

    if ($query !== '') {
        $sql .= ' AND (
            LOWER(COALESCE(o.order_code, "")) LIKE :query
            OR LOWER(COALESCE(o.title, "")) LIKE :query
            OR LOWER(COALESCE(u.fullname, "")) LIKE :query
            OR LOWER(COALESCE(oa.old_status, "")) LIKE :query
            OR LOWER(COALESCE(oa.new_status, "")) LIKE :query
        )';
        $params[':query'] = '%' . mb_strtolower($query) . '%';
    }

    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $sql .= ' AND date(oa.created_at) = :date';
        $params[':date'] = $date;
    }

    $sql .= ' ORDER BY oa.id DESC LIMIT 20';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'orderCode' => (string)($row['order_code'] ?: 'N/A'),
            'orderTitle' => (string)($row['title'] ?: 'Order'),
            'oldStatus' => (string)($row['old_status'] ?: 'N/A'),
            'newStatus' => (string)($row['new_status'] ?: 'N/A'),
            'staffName' => (string)($row['staff_name'] ?: 'Staff'),
            'createdAt' => formatDateTime((string)($row['created_at'] ?? ''))
        ];
    }, $rows);
}

function formatDateTime(string $datetime): string
{
    if ($datetime === '') {
        return 'N/A';
    }

    $time = strtotime($datetime);
    if ($time === false) {
        return $datetime;
    }

    return date('M d, Y H:i', $time);
}

function canTransitionOrderStatus(string $fromStatus, string $toStatus): bool
{
    if ($fromStatus === $toStatus) {
        return true;
    }

    $from = trim(mb_strtolower($fromStatus));
    $to = trim(mb_strtolower($toStatus));
    if (!array_key_exists($from, STAFF_ORDER_TRANSITIONS)) {
        return false;
    }

    return in_array($to, STAFF_ORDER_TRANSITIONS[$from], true);
}

function requiredPermissionForStaffAction(string $action): string
{
    return match ($action) {
        'overview', 'orders', 'customers', 'activity', 'calendar' => 'reports.view',
        'update-order-status' => 'orders.manage',
        'kitchen-tasks', 'create-kitchen-task', 'update-kitchen-task-status' => 'kitchen.manage',
        default => ''
    };
}

function getStaffCalendar(PDO $pdo, string $startDate, string $endDate): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        throw new RuntimeException('Invalid date range');
    }

    $stmt = $pdo->prepare(
        'SELECT event_date, COUNT(*) AS events_count, COALESCE(SUM(guest_count), 0) AS guests_count
         FROM orders
         WHERE event_date BETWEEN :start_date AND :end_date
           AND status IN ("pending", "confirmed", "preparing", "out_for_delivery")
         GROUP BY event_date
         ORDER BY event_date ASC'
    );
    $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);

    return array_map(static function (array $row): array {
        return [
            'eventDate' => (string)$row['event_date'],
            'events' => (int)($row['events_count'] ?? 0),
            'guests' => (int)($row['guests_count'] ?? 0)
        ];
    }, $stmt->fetchAll());
}

function getKitchenTasks(PDO $pdo, int $orderId): array
{
    $sql =
        'SELECT kt.id, kt.order_id, kt.title, kt.details, kt.status, kt.assigned_to, kt.due_at, kt.completed_at,
                o.order_code, u.fullname AS assigned_name
         FROM kitchen_tasks kt
         LEFT JOIN orders o ON o.id = kt.order_id
         LEFT JOIN users u ON u.id = kt.assigned_to
         WHERE 1=1';
    $params = [];

    if ($orderId > 0) {
        $sql .= ' AND kt.order_id = :order_id';
        $params[':order_id'] = $orderId;
    }

    $sql .= ' ORDER BY kt.id DESC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'orderId' => (int)$row['order_id'],
            'orderCode' => (string)($row['order_code'] ?? ''),
            'title' => (string)$row['title'],
            'details' => (string)($row['details'] ?? ''),
            'status' => (string)$row['status'],
            'assignedTo' => $row['assigned_to'] !== null ? (int)$row['assigned_to'] : null,
            'assignedName' => (string)($row['assigned_name'] ?? ''),
            'dueAt' => (string)($row['due_at'] ?? ''),
            'completedAt' => (string)($row['completed_at'] ?? '')
        ];
    }, $stmt->fetchAll());
}
