<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
$userId = (int)$_SESSION['user_id'];

if ($action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing action']);
    exit;
}

try {
    $pdo = getDb();

    if ($method === 'GET') {
        if ($action === 'overview') {
            echo json_encode(['success' => true, 'data' => getOverview($pdo, $userId)]);
            exit;
        }

        if ($action === 'orders') {
            $query = trim((string)($_GET['query'] ?? ''));
            $status = trim((string)($_GET['status'] ?? 'all'));
            echo json_encode(['success' => true, 'data' => getOrders($pdo, $userId, $query, $status)]);
            exit;
        }

        if ($action === 'packages') {
            echo json_encode(['success' => true, 'data' => getPackages($pdo)]);
            exit;
        }

        if ($action === 'profile') {
            echo json_encode(['success' => true, 'data' => getProfile($pdo, $userId)]);
            exit;
        }

        if ($action === 'upcoming') {
            echo json_encode(['success' => true, 'data' => getUpcoming($pdo, $userId)]);
            exit;
        }
    }

    if ($method === 'POST') {
        if ($action === 'create-order') {
            $packageId = (int)($_POST['package_id'] ?? 0);
            $eventDate = trim((string)($_POST['event_date'] ?? ''));

            if ($packageId <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Please select a package']);
                exit;
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Valid event date is required']);
                exit;
            }

            $packageStmt = $pdo->prepare('SELECT id, name, price FROM packages WHERE id = :id AND status = "active" LIMIT 1');
            $packageStmt->execute([':id' => $packageId]);
            $package = $packageStmt->fetch();
            if (!$package) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Selected package is not available']);
                exit;
            }

            $title = (string)$package['name'];
            $totalAmount = (float)$package['price'];

            $orderCode = generateOrderCode($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO orders (order_code, customer_id, package_id, title, event_date, status, total_amount, created_at, updated_at)
                 VALUES (:order_code, :customer_id, :package_id, :title, :event_date, "pending", :total_amount, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                ':order_code' => $orderCode,
                ':customer_id' => $userId,
                ':package_id' => $packageId,
                ':title' => $title,
                ':event_date' => $eventDate,
                ':total_amount' => $totalAmount
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Order placed successfully',
                'data' => ['orderCode' => $orderCode]
            ]);
            exit;
        }

        if ($action === 'update-profile') {
            $fullname = trim((string)($_POST['fullname'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));

            if ($fullname === '' || mb_strlen($fullname) < 2 || mb_strlen($fullname) > 100) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Full name must be 2-100 characters']);
                exit;
            }

            if ($phone !== '' && (!preg_match('/^[0-9\-\+\(\)\s]+$/', $phone) || strlen(preg_replace('/\D/', '', $phone)) < 7)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid phone number']);
                exit;
            }

            $stmt = $pdo->prepare('UPDATE users SET fullname = :fullname, phone = :phone, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND role = "customer"');
            $stmt->execute([
                ':fullname' => $fullname,
                ':phone' => $phone,
                ':id' => $userId
            ]);

            echo json_encode(['success' => true, 'message' => 'Profile updated']);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

function getOverview(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END), 0) AS pending_orders,
            COALESCE(SUM(CASE WHEN status = "confirmed" THEN 1 ELSE 0 END), 0) AS confirmed_orders,
            COALESCE(SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END), 0) AS completed_orders,
            COALESCE(SUM(CASE WHEN status IN ("confirmed", "completed") THEN total_amount ELSE 0 END), 0) AS total_spent
         FROM orders
         WHERE customer_id = :customer_id'
    );
    $stmt->execute([':customer_id' => $userId]);
    $row = $stmt->fetch() ?: [];

    return [
        'totalOrders' => (int)($row['total_orders'] ?? 0),
        'pendingOrders' => (int)($row['pending_orders'] ?? 0),
        'confirmedOrders' => (int)($row['confirmed_orders'] ?? 0),
        'completedOrders' => (int)($row['completed_orders'] ?? 0),
        'totalSpent' => (float)($row['total_spent'] ?? 0)
    ];
}

function getOrders(PDO $pdo, int $userId, string $query, string $status): array
{
    $sql = 'SELECT o.id, o.order_code, o.title, o.event_date, o.status, o.total_amount,
                   p.name AS package_name
            FROM orders
            o LEFT JOIN packages p ON p.id = o.package_id
            WHERE o.customer_id = :customer_id';
    $params = [':customer_id' => $userId];

    if ($query !== '') {
        $sql .= ' AND (LOWER(o.order_code) LIKE :query OR LOWER(o.title) LIKE :query OR LOWER(COALESCE(p.name, "")) LIKE :query)';
        $params[':query'] = '%' . mb_strtolower($query) . '%';
    }

    if ($status !== 'all') {
        $sql .= ' AND o.status = :status';
        $params[':status'] = $status;
    }

    $sql .= ' ORDER BY o.id DESC LIMIT 30';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'orderCode' => (string)$row['order_code'],
            'title' => (string)$row['title'],
            'packageName' => (string)($row['package_name'] ?: 'Custom'),
            'eventDate' => (string)($row['event_date'] ?: 'N/A'),
            'status' => (string)$row['status'],
            'totalAmount' => (float)$row['total_amount']
        ];
    }, $rows);
}

function getPackages(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name, description, price, image_path FROM packages WHERE status = "active" ORDER BY name ASC');
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'description' => (string)($row['description'] ?? ''),
            'price' => (float)$row['price'],
            'imagePath' => (string)($row['image_path'] ?? '')
        ];
    }, $rows);
}

function getProfile(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT fullname, email, phone FROM users WHERE id = :id AND role = "customer" LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();

    if (!$row) {
        return [
            'fullname' => '',
            'email' => '',
            'phone' => ''
        ];
    }

    return [
        'fullname' => (string)$row['fullname'],
        'email' => (string)$row['email'],
        'phone' => (string)($row['phone'] ?? '')
    ];
}

function getUpcoming(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT title, order_code, event_date
         FROM orders
         WHERE customer_id = :customer_id
           AND event_date IS NOT NULL
           AND event_date >= :today
         ORDER BY event_date ASC
         LIMIT 1'
    );
    $stmt->execute([
        ':customer_id' => $userId,
        ':today' => date('Y-m-d')
    ]);

    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'title' => (string)$row['title'],
        'orderCode' => (string)$row['order_code'],
        'eventDate' => (string)$row['event_date']
    ];
}

function generateOrderCode(PDO $pdo): string
{
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = 'CF-' . date('ymd') . '-' . str_pad((string)random_int(1, 999), 3, '0', STR_PAD_LEFT);
        $exists = $pdo->prepare('SELECT id FROM orders WHERE order_code = :code LIMIT 1');
        $exists->execute([':code' => $code]);
        if (!$exists->fetch()) {
            return $code;
        }
    }

    return 'CF-' . date('ymd') . '-' . time();
}
