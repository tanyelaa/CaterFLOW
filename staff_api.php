<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

if ($action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing action']);
    exit;
}

try {
    $pdo = getDb();

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
    }

    if ($method === 'POST') {
        if ($action === 'update-order-status') {
            $orderId = (int)($_POST['id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? ''));
            $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
            $changedBy = (int)($_SESSION['user_id'] ?? 0);

            if ($orderId <= 0 || $changedBy <= 0 || !in_array($status, $validStatuses, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid order update']);
                exit;
            }

            $existingStmt = $pdo->prepare('SELECT status FROM orders WHERE id = :id LIMIT 1');
            $existingStmt->execute([':id' => $orderId]);
            $currentStatus = $existingStmt->fetchColumn();
            if ($currentStatus === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Order not found']);
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
            }

            echo json_encode(['success' => true, 'message' => 'Order updated']);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported action']);
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
    $sql = 'SELECT o.id, o.order_code, o.title, o.event_date, o.status, o.total_amount, u.fullname AS customer_name
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
                    WHEN "completed" THEN 3
                    ELSE 4
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
            'status' => (string)$row['status'],
            'totalAmount' => (float)$row['total_amount']
        ];
    }, $rows);
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
