<?php

declare(strict_types=1);

function applySecurityHeaders(bool $isApiResponse = true): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    if ($isApiResponse) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}

function getClientIpAddress(): string
{
    $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $candidate = trim((string)($parts[0] ?? ''));
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '127.0.0.1';
}

function getRolePermissions(PDO $pdo, string $role): array
{
    $stmt = $pdo->prepare('SELECT permission, is_allowed FROM role_permissions WHERE role = :role');
    $stmt->execute([':role' => $role]);
    $rows = $stmt->fetchAll();

    if ($rows === []) {
        return getDefaultRolePermissions($role);
    }

    $permissions = [];
    foreach ($rows as $row) {
        if ((int)($row['is_allowed'] ?? 0) === 1) {
            $permissions[] = (string)$row['permission'];
        }
    }

    return $permissions;
}

function getDefaultRolePermissions(string $role): array
{
    $map = [
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

    return $map[$role] ?? [];
}

function userHasPermission(PDO $pdo, string $role, string $permission): bool
{
    if ($permission === '') {
        return true;
    }

    return in_array($permission, getRolePermissions($pdo, $role), true);
}

function auditLog(PDO $pdo, ?int $userId, string $action, string $entityType, ?int $entityId, ?array $before, ?array $after, ?array $meta = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (
            user_id, action, entity_type, entity_id, before_json, after_json, meta_json, ip_address, created_at
         ) VALUES (
            :user_id, :action, :entity_type, :entity_id, :before_json, :after_json, :meta_json, :ip_address, CURRENT_TIMESTAMP
         )'
    );

    $stmt->execute([
        ':user_id' => $userId,
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => $entityId,
        ':before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_SLASHES) : null,
        ':after_json' => $after !== null ? json_encode($after, JSON_UNESCAPED_SLASHES) : null,
        ':meta_json' => $meta !== null ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
        ':ip_address' => getClientIpAddress()
    ]);
}

function queueJob(PDO $pdo, string $type, array $payload): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO job_queue (job_type, payload_json, status, run_after, created_at, updated_at)
         VALUES (:job_type, :payload_json, "pending", CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        ':job_type' => $type,
        ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES)
    ]);

    return (int)$pdo->lastInsertId();
}

function queueNotification(PDO $pdo, int $userId, string $channel, string $subject, string $message): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO notification_outbox (
            user_id, channel, recipient, subject, message, status, attempts, created_at, updated_at
         ) VALUES (
            :user_id, :channel, :recipient, :subject, :message, "pending", 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
         )'
    );

    $recipient = resolveNotificationRecipient($pdo, $userId, $channel);
    $stmt->execute([
        ':user_id' => $userId,
        ':channel' => $channel,
        ':recipient' => $recipient,
        ':subject' => $subject,
        ':message' => $message
    ]);

    return (int)$pdo->lastInsertId();
}

function resolveNotificationRecipient(PDO $pdo, int $userId, string $channel): string
{
    $stmt = $pdo->prepare('SELECT email, phone FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch() ?: [];

    if ($channel === 'sms') {
        return (string)($row['phone'] ?? '');
    }

    return (string)($row['email'] ?? '');
}

function getSetting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function settingBool(PDO $pdo, string $key, bool $default = false): bool
{
    return getSetting($pdo, $key, $default ? '1' : '0') === '1';
}

function settingInt(PDO $pdo, string $key, int $default = 0): int
{
    return (int)getSetting($pdo, $key, (string)$default);
}
