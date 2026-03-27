<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

try {
    $pdo = getDb();
    $created = dispatchUpcomingReminders($pdo);
    echo '[CaterFlow] Reminder dispatch completed. Created notifications: ' . $created . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[CaterFlow] Reminder dispatch failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
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
