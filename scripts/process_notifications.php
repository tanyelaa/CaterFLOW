<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

try {
    $pdo = getDb();
    $stmt = $pdo->query(
        'SELECT id, channel, recipient, subject, message
         FROM notification_outbox
         WHERE status = "pending"
         ORDER BY id ASC
         LIMIT 100'
    );

    $update = $pdo->prepare(
        'UPDATE notification_outbox
         SET status = :status,
             attempts = attempts + 1,
             provider_response = :provider_response,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );

    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        $recipient = (string)($row['recipient'] ?? '');
        $ok = $recipient !== '';

        $logLine = sprintf(
            "[%s] %s => %s | %s | %s\n",
            date('c'),
            strtoupper((string)$row['channel']),
            $recipient,
            (string)$row['subject'],
            (string)$row['message']
        );
        file_put_contents(dirname(__DIR__) . '/database/notification_mock.log', $logLine, FILE_APPEND);

        $update->execute([
            ':id' => (int)$row['id'],
            ':status' => $ok ? 'sent' : 'failed',
            ':provider_response' => $ok ? 'Delivered via mock driver' : 'Missing recipient'
        ]);
        $count++;
    }

    echo '[CaterFlow] Notifications processed: ' . $count . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[CaterFlow] Notification processor failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
