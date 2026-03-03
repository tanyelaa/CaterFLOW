<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
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

        if ($action === 'update-order-status') {
            $orderId = (int)($_POST['id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? ''));
            $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
            if ($orderId <= 0 || !in_array($status, $validStatuses, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid order status update']);
                exit;
            }

            $update = $pdo->prepare('UPDATE orders SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $update->execute([':status' => $status, ':id' => $orderId]);
            echo json_encode(['success' => true, 'message' => 'Order status updated']);
            exit;
        }

        if ($action === 'save-settings') {
            $allowedKeys = ['company_name', 'support_email', 'default_currency', 'notifications_enabled', 'maintenance_mode'];
            $upsert = $pdo->prepare(
                'INSERT INTO app_settings (key, value, updated_at)
                 VALUES (:key, :value, CURRENT_TIMESTAMP)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP'
            );

            foreach ($allowedKeys as $key) {
                if (!array_key_exists($key, $_POST)) {
                    continue;
                }
                $value = trim((string)$_POST[$key]);
                if (in_array($key, ['notifications_enabled', 'maintenance_mode'], true)) {
                    $value = $value === '1' ? '1' : '0';
                }
                $upsert->execute([':key' => $key, ':value' => $value]);
            }

            echo json_encode(['success' => true, 'message' => 'Settings saved']);
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
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

function buildAnalytics(PDO $pdo): array
{
    $totalRevenue = (float)$pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status IN ("confirmed", "completed")')->fetchColumn();
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

    $sql .= ' ORDER BY o.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $summary = [
        'pending' => 0,
        'confirmed' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'revenue' => 0.0
    ];

    foreach ($rows as $row) {
        $statusKey = (string)$row['status'];
        if (array_key_exists($statusKey, $summary)) {
            $summary[$statusKey]++;
        }
        if (in_array($statusKey, ['confirmed', 'completed'], true)) {
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
    $sql = 'SELECT id, fullname, username, email, phone, status, last_active, created_at
            FROM users
            WHERE role = :role';
    $params = [':role' => $role];

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
    $revenue = (float)$pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status IN ("confirmed", "completed")')->fetchColumn();

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

function getPackages(PDO $pdo, string $query, string $status): array
{
    $sql = 'SELECT id, name, description, price, status, image_path, created_at, updated_at
            FROM packages
            WHERE 1=1';
    $params = [];

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
    $stmt = $pdo->query('SELECT key, value FROM app_settings ORDER BY key ASC');
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
    $sql = 'SELECT id, fullname, username, email, role, status, last_active FROM users WHERE 1=1';
    $params = [];

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
