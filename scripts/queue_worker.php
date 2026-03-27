<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/core.php';

// This worker picks pending jobs and executes lightweight async operations.
try {
    $pdo = getDb();
    $processed = 0;

    while (true) {
        $job = claimNextJob($pdo);
        if ($job === null) {
            break;
        }

        try {
            $payload = json_decode((string)$job['payload_json'], true);
            if (!is_array($payload)) {
                $payload = [];
            }

            runJob($pdo, (int)$job['id'], (string)$job['job_type'], $payload);
            completeJob($pdo, (int)$job['id']);
            $processed++;
        } catch (Throwable $jobError) {
            failJob($pdo, (int)$job['id'], $jobError->getMessage());
        }
    }

    echo '[CaterFlow] Queue worker completed. Jobs processed: ' . $processed . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[CaterFlow] Queue worker failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function claimNextJob(PDO $pdo): ?array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query(
            'SELECT id, job_type, payload_json
             FROM job_queue
             WHERE status = "pending"
               AND run_after <= CURRENT_TIMESTAMP
             ORDER BY id ASC
             LIMIT 1
             FOR UPDATE'
        );
        $job = $stmt->fetch();
        if (!$job) {
            $pdo->commit();
            return null;
        }

        $update = $pdo->prepare(
            'UPDATE job_queue
             SET status = "processing", attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $update->execute([':id' => (int)$job['id']]);
        $pdo->commit();

        return $job;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function completeJob(PDO $pdo, int $jobId): void
{
    $stmt = $pdo->prepare('UPDATE job_queue SET status = "completed", updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $stmt->execute([':id' => $jobId]);
}

function failJob(PDO $pdo, int $jobId, string $error): void
{
    $stmt = $pdo->prepare(
        'UPDATE job_queue
         SET status = "failed", last_error = :last_error, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $stmt->execute([
        ':id' => $jobId,
        ':last_error' => mb_substr($error, 0, 255)
    ]);
}

function runJob(PDO $pdo, int $jobId, string $jobType, array $payload): void
{
    if ($jobType === 'dispatch_reminders') {
        dispatchUpcomingReminders($pdo);
        return;
    }

    if (str_starts_with($jobType, 'export_')) {
        // Mark as completed; export endpoints can generate files on demand.
        return;
    }

    if ($jobType === 'send_notifications') {
        processNotificationOutbox($pdo);
        return;
    }

    throw new RuntimeException('Unsupported job type: ' . $jobType);
}

function processNotificationOutbox(PDO $pdo): void
{
    $stmt = $pdo->query(
        'SELECT id, channel, recipient, subject, message
         FROM notification_outbox
         WHERE status = "pending"
         ORDER BY id ASC
         LIMIT 50'
    );

    $update = $pdo->prepare(
        'UPDATE notification_outbox
         SET status = :status,
             attempts = attempts + 1,
             provider_response = :provider_response,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );

    foreach ($stmt->fetchAll() as $row) {
        $ok = fakeDelivery((string)$row['channel'], (string)$row['recipient'], (string)$row['subject'], (string)$row['message']);
        $update->execute([
            ':id' => (int)$row['id'],
            ':status' => $ok ? 'sent' : 'failed',
            ':provider_response' => $ok ? 'Delivered via mock driver' : 'Mock delivery failed'
        ]);
    }
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

function fakeDelivery(string $channel, string $recipient, string $subject, string $message): bool
{
    if ($recipient === '') {
        return false;
    }

    $logLine = sprintf("[%s] %s => %s | %s | %s\n", date('c'), strtoupper($channel), $recipient, $subject, $message);
    $logFile = dirname(__DIR__) . '/database/notification_mock.log';

    return file_put_contents($logFile, $logLine, FILE_APPEND) !== false;
}
