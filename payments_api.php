<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/core.php';

applySecurityHeaders(true);
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

if ($action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing action']);
    exit;
}

try {
    $pdo = getDb();

    if ($action === 'webhook') {
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }

        $rawBody = file_get_contents('php://input');
        $signature = (string)($_SERVER['HTTP_X_CF_SIGNATURE'] ?? '');
        if ($rawBody === false || $signature === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid webhook request']);
            exit;
        }

        $secret = getSetting($pdo, 'payment_webhook_secret', 'change-me');
        $expected = hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expected, $signature)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid signature']);
            exit;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
            exit;
        }

        handleWebhook($pdo, $payload, $signature);
        echo json_encode(['success' => true, 'message' => 'Webhook processed']);
        exit;
    }

    session_start();
    if (!isset($_SESSION['user_id'], $_SESSION['role']) || (string)$_SESSION['role'] !== 'customer') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
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

    $userId = (int)$_SESSION['user_id'];

    if ($action === 'create-intent') {
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }

        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid order']);
            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT id, order_code, total_amount, amount_paid, payment_status
             FROM orders
             WHERE id = :id AND customer_id = :customer_id
             LIMIT 1'
        );
        $stmt->execute([':id' => $orderId, ':customer_id' => $userId]);
        $order = $stmt->fetch();
        if (!$order) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }

        $remaining = max(0.0, (float)$order['total_amount'] - (float)$order['amount_paid']);
        if ($remaining <= 0.0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Order is already fully paid']);
            exit;
        }

        $provider = getSetting($pdo, 'payment_provider', 'mock');
        $currency = getSetting($pdo, 'default_currency', 'USD');
        $checkoutBase = rtrim(getSetting($pdo, 'payment_checkout_base_url', 'https://example.test/pay'), '/');

        $transactionRef = generateTransactionRef($pdo);
        $checkoutUrl = $checkoutBase . '?ref=' . rawurlencode($transactionRef);

        $insert = $pdo->prepare(
            'INSERT INTO payment_transactions (
                order_id, customer_id, provider, transaction_ref, currency, amount, status, checkout_url, request_payload, created_at, updated_at
             ) VALUES (
                :order_id, :customer_id, :provider, :transaction_ref, :currency, :amount, "pending", :checkout_url, :request_payload, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $insert->execute([
            ':order_id' => $orderId,
            ':customer_id' => $userId,
            ':provider' => $provider,
            ':transaction_ref' => $transactionRef,
            ':currency' => $currency,
            ':amount' => round($remaining, 2),
            ':checkout_url' => $checkoutUrl,
            ':request_payload' => json_encode([
                'orderCode' => (string)$order['order_code'],
                'requestedAmount' => round($remaining, 2)
            ], JSON_UNESCAPED_SLASHES)
        ]);

        auditLog($pdo, $userId, 'payment.intent_created', 'payment_transaction', (int)$pdo->lastInsertId(), null, [
            'orderId' => $orderId,
            'transactionRef' => $transactionRef,
            'amount' => round($remaining, 2)
        ]);

        echo json_encode([
            'success' => true,
            'data' => [
                'provider' => $provider,
                'transactionRef' => $transactionRef,
                'amount' => round($remaining, 2),
                'currency' => $currency,
                'checkoutUrl' => $checkoutUrl
            ]
        ]);
        exit;
    }

    if ($action === 'status') {
        $orderId = (int)($_GET['order_id'] ?? 0);
        if ($orderId <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid order']);
            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT id, transaction_ref, provider, amount, currency, status, created_at, updated_at
             FROM payment_transactions
             WHERE customer_id = :customer_id AND order_id = :order_id
             ORDER BY id DESC
             LIMIT 10'
        );
        $stmt->execute([':customer_id' => $userId, ':order_id' => $orderId]);
        $rows = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

function handleWebhook(PDO $pdo, array $payload, string $signature): void
{
    $eventId = trim((string)($payload['event_id'] ?? ''));
    $transactionRef = trim((string)($payload['transaction_ref'] ?? ''));
    $status = trim(mb_strtolower((string)($payload['status'] ?? '')));
    $providerTxnId = trim((string)($payload['provider_txn_id'] ?? ''));
    $amount = round((float)($payload['amount'] ?? 0), 2);

    if ($eventId === '' || $transactionRef === '' || !in_array($status, ['paid', 'failed', 'cancelled'], true)) {
        throw new RuntimeException('Invalid webhook payload');
    }

    $pdo->beginTransaction();
    try {
        $insertWebhook = $pdo->prepare(
            'INSERT INTO webhook_events (provider, event_id, signature, payload, processed_at)
             VALUES (:provider, :event_id, :signature, :payload, CURRENT_TIMESTAMP)'
        );

        try {
            $insertWebhook->execute([
                ':provider' => 'mock',
                ':event_id' => $eventId,
                ':signature' => $signature,
                ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES)
            ]);
        } catch (Throwable $e) {
            // Duplicate webhook event, treat as idempotent success.
            $pdo->rollBack();
            return;
        }

        $txnStmt = $pdo->prepare(
            'SELECT id, order_id, customer_id, amount, status
             FROM payment_transactions
             WHERE transaction_ref = :transaction_ref
             LIMIT 1
             FOR UPDATE'
        );
        $txnStmt->execute([':transaction_ref' => $transactionRef]);
        $transaction = $txnStmt->fetch();
        if (!$transaction) {
            $pdo->commit();
            return;
        }

        $updateTxn = $pdo->prepare(
            'UPDATE payment_transactions
             SET provider_txn_id = :provider_txn_id,
                 status = :status,
                 webhook_payload = :webhook_payload,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $updateTxn->execute([
            ':provider_txn_id' => $providerTxnId !== '' ? $providerTxnId : null,
            ':status' => $status,
            ':webhook_payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            ':id' => (int)$transaction['id']
        ]);

        if ($status === 'paid' && (string)$transaction['status'] !== 'paid') {
            $orderStmt = $pdo->prepare(
                'SELECT id, order_code, total_amount, amount_paid
                 FROM orders
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $orderStmt->execute([':id' => (int)$transaction['order_id']]);
            $order = $orderStmt->fetch();
            if ($order) {
                $remaining = max(0.0, (float)$order['total_amount'] - (float)$order['amount_paid']);
                $paymentAmount = min($remaining, $amount > 0 ? $amount : (float)$transaction['amount']);

                if ($paymentAmount > 0) {
                    $newPaid = round((float)$order['amount_paid'] + $paymentAmount, 2);
                    $paymentStatus = $newPaid >= (float)$order['total_amount'] ? 'paid' : 'partial';

                    $updateOrder = $pdo->prepare(
                        'UPDATE orders
                         SET amount_paid = :amount_paid,
                             payment_status = :payment_status,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE id = :id'
                    );
                    $updateOrder->execute([
                        ':amount_paid' => $newPaid,
                        ':payment_status' => $paymentStatus,
                        ':id' => (int)$order['id']
                    ]);

                    $receiptNo = 'RCPT-' . date('ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
                    $receiptStmt = $pdo->prepare(
                        'INSERT INTO payment_receipts (order_id, customer_id, receipt_no, amount, method, notes, created_at)
                         VALUES (:order_id, :customer_id, :receipt_no, :amount, :method, :notes, CURRENT_TIMESTAMP)'
                    );
                    $receiptStmt->execute([
                        ':order_id' => (int)$order['id'],
                        ':customer_id' => (int)$transaction['customer_id'],
                        ':receipt_no' => $receiptNo,
                        ':amount' => $paymentAmount,
                        ':method' => 'gateway',
                        ':notes' => 'Auto-captured from webhook'
                    ]);

                    $notif = $pdo->prepare(
                        'INSERT INTO customer_notifications (customer_id, order_id, title, message, is_read, created_at)
                         VALUES (:customer_id, :order_id, :title, :message, 0, CURRENT_TIMESTAMP)'
                    );
                    $notif->execute([
                        ':customer_id' => (int)$transaction['customer_id'],
                        ':order_id' => (int)$order['id'],
                        ':title' => 'Payment Confirmed',
                        ':message' => sprintf('Payment for order %s has been confirmed.', (string)$order['order_code'])
                    ]);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function generateTransactionRef(PDO $pdo): string
{
    for ($i = 0; $i < 5; $i++) {
        $ref = 'TXN-' . date('ymd') . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('SELECT id FROM payment_transactions WHERE transaction_ref = :ref LIMIT 1');
        $stmt->execute([':ref' => $ref]);
        if (!$stmt->fetch()) {
            return $ref;
        }
    }

    return 'TXN-' . date('ymd') . '-' . time();
}
