<?php

declare(strict_types=1);

require_once __DIR__ . '/core.php';
applySecurityHeaders(true);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/db.php';

$identifier = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$normalizedIdentifier = mb_strtolower($identifier);
$ipAddress = getLoginClientIpAddress();

if ($identifier === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Username/email and password are required.']);
    exit;
}

try {
    $pdo = getDb();

    $lockState = getLoginLockState($pdo, $normalizedIdentifier, $ipAddress);
    if ($lockState['locked']) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Too many login attempts. Try again in ' . $lockState['secondsRemaining'] . ' seconds.'
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT id, fullname, username, email, role, status, password_hash
         FROM users
         WHERE username = :username OR email = :email
         LIMIT 1'
    );
    $stmt->execute([
        ':username' => $identifier,
        ':email' => $identifier
    ]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        registerFailedLogin($pdo, $normalizedIdentifier, $ipAddress);
        insertLoginAudit($pdo, null, $normalizedIdentifier, $ipAddress, false, 'invalid_credentials');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid credentials.']);
        exit;
    }

    if (($user['status'] ?? 'active') === 'locked') {
        insertLoginAudit($pdo, (int)$user['id'], $normalizedIdentifier, $ipAddress, false, 'account_locked');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This account is locked. Please contact admin.']);
        exit;
    }

    if (($user['status'] ?? 'active') === 'pending') {
        insertLoginAudit($pdo, (int)$user['id'], $normalizedIdentifier, $ipAddress, false, 'account_pending');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This account is pending approval.']);
        exit;
    }

    if (!empty($user['archived_at'])) {
        insertLoginAudit($pdo, (int)$user['id'], $normalizedIdentifier, $ipAddress, false, 'account_archived');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This account is archived. Please contact admin.']);
        exit;
    }

    $updateStmt = $pdo->prepare('UPDATE users SET last_active = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $updateStmt->execute([':id' => (int)$user['id']]);

    clearLoginAttempts($pdo, $normalizedIdentifier, $ipAddress);
    insertLoginAudit($pdo, (int)$user['id'], $normalizedIdentifier, $ipAddress, true, null);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

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

function getLoginClientIpAddress(): string
{
    $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $candidate = trim((string)($parts[0] ?? ''));
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '127.0.0.1';
}

function getLoginLockState(PDO $pdo, string $identifier, string $ipAddress): array
{
    $stmt = $pdo->prepare(
        'SELECT locked_until
         FROM login_attempts
         WHERE identifier = :identifier AND ip_address = :ip_address
         LIMIT 1'
    );
    $stmt->execute([
        ':identifier' => $identifier,
        ':ip_address' => $ipAddress
    ]);

    $lockedUntil = (string)($stmt->fetchColumn() ?: '');
    if ($lockedUntil === '') {
        return ['locked' => false, 'secondsRemaining' => 0];
    }

    $lockedTs = strtotime($lockedUntil);
    if ($lockedTs === false || $lockedTs <= time()) {
        return ['locked' => false, 'secondsRemaining' => 0];
    }

    return ['locked' => true, 'secondsRemaining' => max(1, $lockedTs - time())];
}

function registerFailedLogin(PDO $pdo, string $identifier, string $ipAddress): void
{
    $pdo->prepare(
        'INSERT INTO login_attempts (
            identifier, ip_address, attempt_count, first_attempt_at, last_attempt_at, locked_until
         ) VALUES (
            :identifier, :ip_address, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL
         )
         ON DUPLICATE KEY UPDATE
            attempt_count = CASE
                WHEN last_attempt_at < (NOW() - INTERVAL 15 MINUTE) THEN 1
                ELSE attempt_count + 1
            END,
            first_attempt_at = CASE
                WHEN last_attempt_at < (NOW() - INTERVAL 15 MINUTE) THEN CURRENT_TIMESTAMP
                ELSE first_attempt_at
            END,
            last_attempt_at = CURRENT_TIMESTAMP,
            locked_until = CASE
                WHEN (
                    CASE
                        WHEN last_attempt_at < (NOW() - INTERVAL 15 MINUTE) THEN 1
                        ELSE attempt_count + 1
                    END
                ) >= 5 THEN (NOW() + INTERVAL 15 MINUTE)
                ELSE locked_until
            END'
    )->execute([
        ':identifier' => $identifier,
        ':ip_address' => $ipAddress
    ]);
}

function clearLoginAttempts(PDO $pdo, string $identifier, string $ipAddress): void
{
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE identifier = :identifier AND ip_address = :ip_address');
    $stmt->execute([
        ':identifier' => $identifier,
        ':ip_address' => $ipAddress
    ]);
}

function insertLoginAudit(PDO $pdo, ?int $userId, string $identifier, string $ipAddress, bool $success, ?string $reason): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO login_audit (user_id, identifier, ip_address, success, reason, created_at)
         VALUES (:user_id, :identifier, :ip_address, :success, :reason, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        ':user_id' => $userId,
        ':identifier' => $identifier,
        ':ip_address' => $ipAddress,
        ':success' => $success ? 1 : 0,
        ':reason' => $reason
    ]);
}
