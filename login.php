<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/db.php';

$identifier = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($identifier === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Username/email and password are required.']);
    exit;
}

try {
    $pdo = getDb();
    $stmt = $pdo->prepare(
        'SELECT id, fullname, username, email, role, status, password_hash
         FROM users
         WHERE username = :identifier OR email = :identifier
         LIMIT 1'
    );
    $stmt->execute([':identifier' => $identifier]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid credentials.']);
        exit;
    }

    if (($user['status'] ?? 'active') === 'locked') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This account is locked. Please contact admin.']);
        exit;
    }

    if (($user['status'] ?? 'active') === 'pending') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This account is pending approval.']);
        exit;
    }

    $updateStmt = $pdo->prepare('UPDATE users SET last_active = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $updateStmt->execute([':id' => (int)$user['id']]);

    session_start();
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];

    echo json_encode([
        'success' => true,
        'message' => 'Login successful.',
        'user' => [
            'fullname' => $user['fullname'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'status' => $user['status']
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
