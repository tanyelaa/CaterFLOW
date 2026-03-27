<?php

declare(strict_types=1);

require_once __DIR__ . '/core.php';
applySecurityHeaders(true);

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

const ORDER_STATUSES = ['pending', 'confirmed', 'preparing', 'out_for_delivery', 'completed', 'cancelled'];
const PAYMENT_STATUSES = ['unpaid', 'partial', 'paid'];
const SELF_SERVICE_LOCK_HOURS = 48;
const CAPACITY_ACTIVE_STATUSES = ['pending', 'confirmed', 'preparing', 'out_for_delivery'];

if ($action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing action']);
    exit;
}

try {
    $pdo = getDb();
    $currentRole = (string)($_SESSION['role'] ?? '');
    $requiredPermission = requiredPermissionForCustomerAction($action);
    if (!userHasPermission($pdo, $currentRole, $requiredPermission)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Insufficient permission']);
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

    if ($method === 'GET') {
        if ($action === 'overview') {
            echo json_encode(['success' => true, 'data' => getOverview($pdo, $userId)]);
            exit;
        }

        if ($action === 'orders') {
            $query = trim((string)($_GET['query'] ?? ''));
            $status = trim((string)($_GET['status'] ?? 'all'));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $pageSize = (int)($_GET['pageSize'] ?? 8);
            echo json_encode(['success' => true, 'data' => getOrders($pdo, $userId, $query, $status, $page, $pageSize)]);
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

        if ($action === 'availability') {
            $startDate = trim((string)($_GET['start_date'] ?? date('Y-m-d')));
            $endDate = trim((string)($_GET['end_date'] ?? date('Y-m-d', strtotime('+30 days'))));
            echo json_encode(['success' => true, 'data' => getAvailability($pdo, $startDate, $endDate)]);
            exit;
        }

        if ($action === 'order-details') {
            $orderId = (int)($_GET['id'] ?? 0);
            if ($orderId <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid order id']);
                exit;
            }
            echo json_encode(['success' => true, 'data' => getOrderDetails($pdo, $userId, $orderId)]);
            exit;
        }

        if ($action === 'notifications') {
            echo json_encode(['success' => true, 'data' => getNotifications($pdo, $userId)]);
            exit;
        }

        if ($action === 'receipts') {
            $orderId = (int)($_GET['order_id'] ?? 0);
            echo json_encode(['success' => true, 'data' => getReceipts($pdo, $userId, $orderId)]);
            exit;
        }

        if ($action === 'invoices') {
            echo json_encode(['success' => true, 'data' => getInvoices($pdo, $userId)]);
            exit;
        }

        if ($action === 'contracts') {
            echo json_encode(['success' => true, 'data' => getContracts($pdo, $userId)]);
            exit;
        }

        if ($action === 'notification-preferences') {
            echo json_encode(['success' => true, 'data' => getNotificationPreferences($pdo, $userId)]);
            exit;
        }
    }

    if ($method === 'POST') {
        if ($action === 'create-order') {
            $packageId = (int)($_POST['package_id'] ?? 0);
            $eventDate = trim((string)($_POST['event_date'] ?? ''));
            $eventTime = trim((string)($_POST['event_time'] ?? ''));
            $guestCountRaw = trim((string)($_POST['guest_count'] ?? ''));
            $venueAddress = trim((string)($_POST['venue_address'] ?? ''));
            $specialRequests = trim((string)($_POST['special_requests'] ?? ''));
            $dietaryNotes = trim((string)($_POST['dietary_notes'] ?? ''));

            if ($guestCountRaw === '') {
                $guestCount = 1;
            } else {
                $guestCountNormalized = preg_replace('/[^0-9]/', '', $guestCountRaw);
                $guestCount = (int)($guestCountNormalized ?? '0');
            }

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

            if ($eventTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $eventTime)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid event time']);
                exit;
            }

            if ($guestCount < 1 || $guestCount > 3000) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Guest count must be between 1 and 3000']);
                exit;
            }

            if ($venueAddress === '' || mb_strlen($venueAddress) < 6 || mb_strlen($venueAddress) > 255) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Venue address must be 6-255 characters']);
                exit;
            }

            if (mb_strlen($specialRequests) > 1000 || mb_strlen($dietaryNotes) > 1000) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Special request fields must be under 1000 characters']);
                exit;
            }

            if (strtotime($eventDate) < strtotime(date('Y-m-d'))) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Event date cannot be in the past']);
                exit;
            }

            assertDateCapacityAvailable($pdo, $eventDate, $guestCount, null);

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
            $depositAmount = round($totalAmount * 0.3, 2);

            $orderCode = generateOrderCode($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO orders (
                    order_code, customer_id, package_id, title, event_date, event_time, guest_count,
                    venue_address, special_requests, dietary_notes, status, payment_status,
                    amount_paid, deposit_amount, total_amount, created_at, updated_at
                 ) VALUES (
                    :order_code, :customer_id, :package_id, :title, :event_date, :event_time, :guest_count,
                    :venue_address, :special_requests, :dietary_notes, "pending", "unpaid",
                    0, :deposit_amount, :total_amount, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 )'
            );
            $insert->execute([
                ':order_code' => $orderCode,
                ':customer_id' => $userId,
                ':package_id' => $packageId,
                ':title' => $title,
                ':event_date' => $eventDate,
                ':event_time' => $eventTime !== '' ? $eventTime : null,
                ':guest_count' => $guestCount,
                ':venue_address' => $venueAddress,
                ':special_requests' => $specialRequests !== '' ? $specialRequests : null,
                ':dietary_notes' => $dietaryNotes !== '' ? $dietaryNotes : null,
                ':deposit_amount' => $depositAmount,
                ':total_amount' => $totalAmount
            ]);

            $orderId = (int)$pdo->lastInsertId();

            createNotification(
                $pdo,
                $userId,
                $orderId,
                'Order Placed',
                sprintf('Your order %s is now pending confirmation.', $orderCode)
            );
            auditLog($pdo, $userId, 'order.create', 'order', $orderId, null, ['orderCode' => $orderCode]);

            echo json_encode([
                'success' => true,
                'message' => 'Order placed successfully',
                'data' => ['orderCode' => $orderCode, 'orderId' => $orderId]
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

        if ($action === 'reschedule-order') {
            $orderId = (int)($_POST['id'] ?? 0);
            $eventDate = trim((string)($_POST['event_date'] ?? ''));
            $eventTime = trim((string)($_POST['event_time'] ?? ''));

            if ($orderId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Valid order and date are required']);
                exit;
            }

            if ($eventTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $eventTime)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid event time']);
                exit;
            }

            if (strtotime($eventDate) < strtotime(date('Y-m-d'))) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Event date cannot be in the past']);
                exit;
            }

            $order = getOwnedOrderForAction($pdo, $userId, $orderId);
            enforceSelfServiceWindow($order['eventDate']);
            ensureOrderEditable($order['status']);

            $guestCountStmt = $pdo->prepare('SELECT guest_count FROM orders WHERE id = :id AND customer_id = :customer_id LIMIT 1');
            $guestCountStmt->execute([':id' => $orderId, ':customer_id' => $userId]);
            $existingGuestCount = (int)($guestCountStmt->fetchColumn() ?: 1);
            assertDateCapacityAvailable($pdo, $eventDate, max(1, $existingGuestCount), $orderId);

            $stmt = $pdo->prepare(
                'UPDATE orders
                 SET event_date = :event_date,
                     event_time = :event_time,
                     rescheduled_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND customer_id = :customer_id'
            );
            $stmt->execute([
                ':event_date' => $eventDate,
                ':event_time' => $eventTime !== '' ? $eventTime : null,
                ':id' => $orderId,
                ':customer_id' => $userId
            ]);

            createNotification(
                $pdo,
                $userId,
                $orderId,
                'Order Rescheduled',
                sprintf('Order %s was rescheduled to %s.', $order['orderCode'], $eventDate)
            );
            auditLog($pdo, $userId, 'order.reschedule', 'order', $orderId, ['eventDate' => $order['eventDate']], ['eventDate' => $eventDate]);

            echo json_encode(['success' => true, 'message' => 'Order rescheduled']);
            exit;
        }

        if ($action === 'cancel-order') {
            $orderId = (int)($_POST['id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));

            if ($orderId <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid order id']);
                exit;
            }

            if ($reason !== '' && mb_strlen($reason) > 255) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Cancellation reason must be 255 characters or less']);
                exit;
            }

            $order = getOwnedOrderForAction($pdo, $userId, $orderId);
            enforceSelfServiceWindow($order['eventDate']);
            ensureOrderEditable($order['status']);

            $stmt = $pdo->prepare(
                'UPDATE orders
                 SET status = "cancelled",
                     cancellation_reason = :reason,
                     cancelled_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND customer_id = :customer_id'
            );
            $stmt->execute([
                ':reason' => $reason !== '' ? $reason : null,
                ':id' => $orderId,
                ':customer_id' => $userId
            ]);

            logOrderActivity($pdo, $orderId, $userId, $order['status'], 'cancelled');
            createNotification(
                $pdo,
                $userId,
                $orderId,
                'Order Cancelled',
                sprintf('Order %s has been cancelled.', $order['orderCode'])
            );
            auditLog($pdo, $userId, 'order.cancel', 'order', $orderId, ['status' => $order['status']], ['status' => 'cancelled']);

            echo json_encode(['success' => true, 'message' => 'Order cancelled']);
            exit;
        }

        if ($action === 'make-payment') {
            $orderId = (int)($_POST['id'] ?? 0);
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $method = trim((string)($_POST['method'] ?? 'manual'));

            if ($orderId <= 0 || $amount <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Valid order and amount are required']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'SELECT id, order_code, total_amount, amount_paid
                     FROM orders
                     WHERE id = :id AND customer_id = :customer_id
                     LIMIT 1
                     FOR UPDATE'
                );
                $stmt->execute([':id' => $orderId, ':customer_id' => $userId]);
                $order = $stmt->fetch();
                if (!$order) {
                    $pdo->rollBack();
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Order not found']);
                    exit;
                }

                $remaining = max(0, (float)$order['total_amount'] - (float)$order['amount_paid']);
                if ($amount > $remaining) {
                    $pdo->rollBack();
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Amount exceeds remaining balance']);
                    exit;
                }

                if ((float)$order['amount_paid'] <= 0 && $remaining > 0) {
                    $minimumFirstPayment = min($remaining, max(0, (float)($order['total_amount'] * 0.3)));
                    if ($amount < $minimumFirstPayment) {
                        $pdo->rollBack();
                        http_response_code(422);
                        echo json_encode([
                            'success' => false,
                            'message' => 'First payment must be at least ' . formatCurrency($minimumFirstPayment)
                        ]);
                        exit;
                    }
                }

                $newAmountPaid = round((float)$order['amount_paid'] + $amount, 2);
                $newPaymentStatus = $newAmountPaid <= 0
                    ? 'unpaid'
                    : ($newAmountPaid >= (float)$order['total_amount'] ? 'paid' : 'partial');

                $update = $pdo->prepare(
                    'UPDATE orders
                     SET amount_paid = :amount_paid,
                         payment_status = :payment_status,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND customer_id = :customer_id'
                );
                $update->execute([
                    ':amount_paid' => $newAmountPaid,
                    ':payment_status' => $newPaymentStatus,
                    ':id' => $orderId,
                    ':customer_id' => $userId
                ]);

                $receiptNo = generateReceiptNo($pdo);
                $receiptStmt = $pdo->prepare(
                    'INSERT INTO payment_receipts (order_id, customer_id, receipt_no, amount, method, notes, created_at)
                     VALUES (:order_id, :customer_id, :receipt_no, :amount, :method, :notes, CURRENT_TIMESTAMP)'
                );
                $receiptStmt->execute([
                    ':order_id' => $orderId,
                    ':customer_id' => $userId,
                    ':receipt_no' => $receiptNo,
                    ':amount' => $amount,
                    ':method' => $method !== '' ? $method : 'manual',
                    ':notes' => 'Customer dashboard payment'
                ]);

                createNotification(
                    $pdo,
                    $userId,
                    $orderId,
                    'Payment Received',
                    sprintf('Payment of %s recorded for order %s.', formatCurrency($amount), (string)$order['order_code'])
                );

                auditLog($pdo, $userId, 'payment.manual_record', 'order', $orderId, null, ['amount' => $amount, 'method' => $method]);

                $pdo->commit();
            } catch (Throwable $paymentError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $paymentError;
            }

            echo json_encode([
                'success' => true,
                'message' => 'Payment recorded',
                'data' => [
                    'receiptNo' => $receiptNo,
                    'amountPaid' => $newAmountPaid,
                    'paymentStatus' => $newPaymentStatus
                ]
            ]);
            exit;
        }

        if ($action === 'create-payment-intent') {
            $orderId = (int)($_POST['order_id'] ?? 0);
            if ($orderId <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid order']);
                exit;
            }

            $intent = createPaymentIntent($pdo, $userId, $orderId);
            auditLog($pdo, $userId, 'payment.intent_created', 'order', $orderId, null, ['transactionRef' => $intent['transactionRef']]);
            echo json_encode(['success' => true, 'data' => $intent]);
            exit;
        }

        if ($action === 'mark-notification-read') {
            $notificationId = (int)($_POST['id'] ?? 0);
            if ($notificationId <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid notification id']);
                exit;
            }

            $stmt = $pdo->prepare(
                'UPDATE customer_notifications
                 SET is_read = 1
                 WHERE id = :id AND customer_id = :customer_id'
            );
            $stmt->execute([':id' => $notificationId, ':customer_id' => $userId]);
            echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
            exit;
        }

        if ($action === 'update-notification-preferences') {
            $emailEnabled = (string)($_POST['email_enabled'] ?? '1') === '1';
            $smsEnabled = (string)($_POST['sms_enabled'] ?? '0') === '1';
            $inappEnabled = (string)($_POST['inapp_enabled'] ?? '1') === '1';

            $stmt = $pdo->prepare(
                'INSERT INTO notification_preferences (user_id, email_enabled, sms_enabled, inapp_enabled, updated_at)
                 VALUES (:user_id, :email_enabled, :sms_enabled, :inapp_enabled, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                    email_enabled = VALUES(email_enabled),
                    sms_enabled = VALUES(sms_enabled),
                    inapp_enabled = VALUES(inapp_enabled),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':email_enabled' => $emailEnabled ? 1 : 0,
                ':sms_enabled' => $smsEnabled ? 1 : 0,
                ':inapp_enabled' => $inappEnabled ? 1 : 0
            ]);

            auditLog($pdo, $userId, 'notification.preferences_update', 'user', $userId, null, [
                'email' => $emailEnabled,
                'sms' => $smsEnabled,
                'inapp' => $inappEnabled
            ]);

            echo json_encode(['success' => true, 'message' => 'Preferences updated']);
            exit;
        }

        if ($action === 'sign-contract') {
            $contractId = (int)($_POST['id'] ?? 0);
            $signedName = trim((string)($_POST['signed_name'] ?? ''));
            if ($contractId <= 0 || $signedName === '') {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Contract and signer name are required']);
                exit;
            }

            $stmt = $pdo->prepare(
                'UPDATE contracts
                 SET status = "signed",
                     signed_name = :signed_name,
                     signed_ip = :signed_ip,
                     signed_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND customer_id = :customer_id AND status IN ("draft", "sent")'
            );
            $stmt->execute([
                ':signed_name' => $signedName,
                ':signed_ip' => getClientIpAddress(),
                ':id' => $contractId,
                ':customer_id' => $userId
            ]);

            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('Contract not found or already signed');
            }

            auditLog($pdo, $userId, 'contract.sign', 'contract', $contractId, null, ['signedName' => $signedName]);
            echo json_encode(['success' => true, 'message' => 'Contract signed']);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported action']);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
            COALESCE(SUM(CASE WHEN status IN ("preparing", "out_for_delivery") THEN 1 ELSE 0 END), 0) AS inprogress_orders,
            COALESCE(SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END), 0) AS completed_orders,
            COALESCE(SUM(CASE WHEN status IN ("confirmed", "preparing", "out_for_delivery", "completed") THEN total_amount ELSE 0 END), 0) AS total_spent,
            COALESCE(SUM(total_amount), 0) AS booked_amount,
            COALESCE(SUM(amount_paid), 0) AS amount_paid,
            COALESCE(SUM(total_amount - amount_paid), 0) AS amount_due
         FROM orders
         WHERE customer_id = :customer_id'
    );
    $stmt->execute([':customer_id' => $userId]);
    $row = $stmt->fetch() ?: [];

    return [
        'totalOrders' => (int)($row['total_orders'] ?? 0),
        'pendingOrders' => (int)($row['pending_orders'] ?? 0),
        'confirmedOrders' => (int)($row['confirmed_orders'] ?? 0),
        'inProgressOrders' => (int)($row['inprogress_orders'] ?? 0),
        'completedOrders' => (int)($row['completed_orders'] ?? 0),
        'totalSpent' => (float)($row['total_spent'] ?? 0),
        'bookedAmount' => (float)($row['booked_amount'] ?? 0),
        'amountPaid' => (float)($row['amount_paid'] ?? 0),
        'amountDue' => max(0, (float)($row['amount_due'] ?? 0))
    ];
}

function getOrders(PDO $pdo, int $userId, string $query, string $status, int $page, int $pageSize): array
{
    $pageSize = max(5, min(20, $pageSize));

    $whereSql = 'FROM orders
            o LEFT JOIN packages p ON p.id = o.package_id
            WHERE o.customer_id = :customer_id';
    $params = [':customer_id' => $userId];

    if ($query !== '') {
        $whereSql .= ' AND (
            LOWER(o.order_code) LIKE :query
            OR LOWER(o.title) LIKE :query
            OR LOWER(COALESCE(p.name, "")) LIKE :query
            OR LOWER(COALESCE(o.venue_address, "")) LIKE :query
        )';
        $params[':query'] = '%' . mb_strtolower($query) . '%';
    }

    if ($status !== 'all') {
        $status = normalizeStatus($status);
        if (!in_array($status, ORDER_STATUSES, true)) {
            $status = 'all';
        }
    }

    if ($status !== 'all') {
        $whereSql .= ' AND o.status = :status';
        $params[':status'] = $status;
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) ' . $whereSql);
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalRows / $pageSize));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $pageSize;

    $sql = 'SELECT o.id, o.order_code, o.title, o.event_date, o.event_time, o.status, o.payment_status,
                   o.total_amount, o.amount_paid, o.deposit_amount, o.guest_count, o.venue_address,
                   o.special_requests, o.dietary_notes, o.cancellation_reason,
                   p.name AS package_name
            ' . $whereSql . '
            ORDER BY o.event_date ASC, o.id DESC
            LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    return [
        'rows' => array_map(static function (array $row): array {
            $remainingBalance = max(0, (float)$row['total_amount'] - (float)$row['amount_paid']);
            $eventDate = (string)($row['event_date'] ?: '');

            return [
                'id' => (int)$row['id'],
                'orderCode' => (string)$row['order_code'],
                'title' => (string)$row['title'],
                'packageName' => (string)($row['package_name'] ?: 'Custom'),
                'eventDate' => $eventDate !== '' ? $eventDate : 'N/A',
                'eventTime' => (string)($row['event_time'] ?: ''),
                'status' => normalizeStatus((string)$row['status']),
                'statusLabel' => statusLabel((string)$row['status']),
                'paymentStatus' => normalizePaymentStatus((string)($row['payment_status'] ?? 'unpaid')),
                'paymentStatusLabel' => paymentStatusLabel((string)($row['payment_status'] ?? 'unpaid')),
                'totalAmount' => (float)$row['total_amount'],
                'amountPaid' => (float)$row['amount_paid'],
                'remainingBalance' => $remainingBalance,
                'depositAmount' => (float)($row['deposit_amount'] ?? 0),
                'guestCount' => (int)($row['guest_count'] ?? 0),
                'venueAddress' => (string)($row['venue_address'] ?? ''),
                'specialRequests' => (string)($row['special_requests'] ?? ''),
                'dietaryNotes' => (string)($row['dietary_notes'] ?? ''),
                'cancellationReason' => (string)($row['cancellation_reason'] ?? ''),
                'canSelfManage' => canSelfManageOrder((string)$row['status'], $eventDate),
                'selfManageReason' => getSelfManageReason((string)$row['status'], $eventDate)
            ];
        }, $rows),
        'pagination' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'totalRows' => $totalRows,
            'totalPages' => $totalPages
        ]
    ];
}

function getOrderDetails(PDO $pdo, int $userId, int $orderId): array
{
    $stmt = $pdo->prepare(
        'SELECT o.id, o.order_code, o.title, o.event_date, o.event_time, o.status,
                o.payment_status, o.total_amount, o.amount_paid, o.deposit_amount,
                o.guest_count, o.venue_address, o.special_requests, o.dietary_notes,
                o.cancellation_reason, o.created_at, o.updated_at,
                p.name AS package_name
         FROM orders o
         LEFT JOIN packages p ON p.id = o.package_id
         WHERE o.id = :id AND o.customer_id = :customer_id
         LIMIT 1'
    );
    $stmt->execute([':id' => $orderId, ':customer_id' => $userId]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new RuntimeException('Order not found');
    }

    return [
        'order' => [
            'id' => (int)$order['id'],
            'orderCode' => (string)$order['order_code'],
            'title' => (string)$order['title'],
            'packageName' => (string)($order['package_name'] ?: 'Custom'),
            'eventDate' => (string)($order['event_date'] ?: ''),
            'eventTime' => (string)($order['event_time'] ?: ''),
            'status' => normalizeStatus((string)$order['status']),
            'statusLabel' => statusLabel((string)$order['status']),
            'paymentStatus' => normalizePaymentStatus((string)$order['payment_status']),
            'paymentStatusLabel' => paymentStatusLabel((string)$order['payment_status']),
            'totalAmount' => (float)$order['total_amount'],
            'amountPaid' => (float)$order['amount_paid'],
            'remainingBalance' => max(0, (float)$order['total_amount'] - (float)$order['amount_paid']),
            'depositAmount' => (float)$order['deposit_amount'],
            'guestCount' => (int)($order['guest_count'] ?? 0),
            'venueAddress' => (string)($order['venue_address'] ?? ''),
            'specialRequests' => (string)($order['special_requests'] ?? ''),
            'dietaryNotes' => (string)($order['dietary_notes'] ?? ''),
            'cancellationReason' => (string)($order['cancellation_reason'] ?? ''),
            'createdAt' => formatDateTime((string)($order['created_at'] ?? '')),
            'updatedAt' => formatDateTime((string)($order['updated_at'] ?? '')),
            'canSelfManage' => canSelfManageOrder((string)$order['status'], (string)($order['event_date'] ?? '')),
            'selfManageReason' => getSelfManageReason((string)$order['status'], (string)($order['event_date'] ?? ''))
        ],
        'timeline' => getOrderTimeline($pdo, $orderId)
    ];
}

function getOrderTimeline(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare(
        'SELECT created_at FROM orders WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $orderId]);
    $createdAt = (string)($stmt->fetchColumn() ?: '');

    $activityStmt = $pdo->prepare(
        'SELECT oa.new_status, oa.created_at, u.fullname AS actor
         FROM order_activity oa
         LEFT JOIN users u ON u.id = oa.changed_by
         WHERE oa.order_id = :order_id
         ORDER BY oa.id ASC'
    );
    $activityStmt->execute([':order_id' => $orderId]);
    $rows = $activityStmt->fetchAll();

    $timeline = [];
    if ($createdAt !== '') {
        $timeline[] = [
            'status' => 'pending',
            'statusLabel' => statusLabel('pending'),
            'description' => 'Order created',
            'createdAt' => formatDateTime($createdAt)
        ];
    }

    foreach ($rows as $row) {
        $status = normalizeStatus((string)$row['new_status']);
        $actor = trim((string)($row['actor'] ?? 'Team'));
        $timeline[] = [
            'status' => $status,
            'statusLabel' => statusLabel($status),
            'description' => $actor . ' changed status',
            'createdAt' => formatDateTime((string)($row['created_at'] ?? ''))
        ];
    }

    return $timeline;
}

function getNotifications(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, order_id, title, message, is_read, created_at
         FROM customer_notifications
         WHERE customer_id = :customer_id
         ORDER BY id DESC
         LIMIT 20'
    );
    $stmt->execute([':customer_id' => $userId]);
    $rows = $stmt->fetchAll();

    $unreadCount = 0;
    $notifications = array_map(static function (array $row) use (&$unreadCount): array {
        $isRead = (int)$row['is_read'] === 1;
        if (!$isRead) {
            $unreadCount++;
        }

        return [
            'id' => (int)$row['id'],
            'orderId' => $row['order_id'] !== null ? (int)$row['order_id'] : null,
            'title' => (string)$row['title'],
            'message' => (string)$row['message'],
            'isRead' => $isRead,
            'createdAt' => formatDateTime((string)$row['created_at'])
        ];
    }, $rows);

    return [
        'rows' => $notifications,
        'unreadCount' => $unreadCount
    ];
}

function getReceipts(PDO $pdo, int $userId, int $orderId): array
{
    $sql = 'SELECT id, order_id, receipt_no, amount, method, notes, created_at
            FROM payment_receipts
            WHERE customer_id = :customer_id';
    $params = [':customer_id' => $userId];

    if ($orderId > 0) {
        $sql .= ' AND order_id = :order_id';
        $params[':order_id'] = $orderId;
    }

    $sql .= ' ORDER BY id DESC LIMIT 30';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'orderId' => (int)$row['order_id'],
            'receiptNo' => (string)$row['receipt_no'],
            'amount' => (float)$row['amount'],
            'method' => (string)($row['method'] ?: 'manual'),
            'notes' => (string)($row['notes'] ?? ''),
            'createdAt' => formatDateTime((string)$row['created_at'])
        ];
    }, $rows);
}

function getPackages(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name, description, price, image_path FROM packages WHERE status = "active" AND archived_at IS NULL ORDER BY name ASC');
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
                'SELECT title, order_code, event_date, event_time, status
         FROM orders
         WHERE customer_id = :customer_id
           AND event_date IS NOT NULL
                     AND status != "cancelled"
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
        'eventDate' => (string)$row['event_date'],
        'eventTime' => (string)($row['event_time'] ?? ''),
        'status' => normalizeStatus((string)$row['status']),
        'statusLabel' => statusLabel((string)$row['status'])
    ];
}

function getOwnedOrderForAction(PDO $pdo, int $userId, int $orderId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, order_code, status, event_date
         FROM orders
         WHERE id = :id AND customer_id = :customer_id
         LIMIT 1'
    );
    $stmt->execute([':id' => $orderId, ':customer_id' => $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Order not found');
    }

    return [
        'id' => (int)$row['id'],
        'orderCode' => (string)$row['order_code'],
        'status' => normalizeStatus((string)$row['status']),
        'eventDate' => (string)($row['event_date'] ?? '')
    ];
}

function enforceSelfServiceWindow(string $eventDate): void
{
    if ($eventDate === '') {
        return;
    }

    $eventTs = strtotime($eventDate . ' 00:00:00');
    if ($eventTs === false) {
        return;
    }

    $hoursDiff = ($eventTs - time()) / 3600;
    if ($hoursDiff < SELF_SERVICE_LOCK_HOURS) {
        throw new RuntimeException('Self-service is locked within 48 hours of the event date');
    }
}

function ensureOrderEditable(string $status): void
{
    $status = normalizeStatus($status);
    if (in_array($status, ['completed', 'cancelled'], true)) {
        throw new RuntimeException('This order can no longer be modified');
    }
}

function logOrderActivity(PDO $pdo, int $orderId, int $changedBy, string $oldStatus, string $newStatus): void
{
    $oldStatus = normalizeStatus($oldStatus);
    $newStatus = normalizeStatus($newStatus);

    if ($oldStatus === $newStatus) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO order_activity (order_id, changed_by, old_status, new_status, created_at)
         VALUES (:order_id, :changed_by, :old_status, :new_status, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        ':order_id' => $orderId,
        ':changed_by' => $changedBy,
        ':old_status' => $oldStatus,
        ':new_status' => $newStatus
    ]);
}

function createNotification(PDO $pdo, int $customerId, ?int $orderId, string $title, string $message): void
{
    $preferences = getNotificationPreferences($pdo, $customerId);

    if (!($preferences['inappEnabled'] ?? true)) {
        if ($preferences['emailEnabled'] ?? false) {
            queueNotification($pdo, $customerId, 'email', $title, $message);
        }
        if ($preferences['smsEnabled'] ?? false) {
            queueNotification($pdo, $customerId, 'sms', $title, $message);
        }
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO customer_notifications (customer_id, order_id, title, message, is_read, created_at)
         VALUES (:customer_id, :order_id, :title, :message, 0, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        ':customer_id' => $customerId,
        ':order_id' => $orderId,
        ':title' => $title,
        ':message' => $message
    ]);

    if ($preferences['emailEnabled'] ?? false) {
        queueNotification($pdo, $customerId, 'email', $title, $message);
    }
    if ($preferences['smsEnabled'] ?? false) {
        queueNotification($pdo, $customerId, 'sms', $title, $message);
    }
}

function normalizeStatus(string $status): string
{
    $normalized = trim(mb_strtolower($status));
    return in_array($normalized, ORDER_STATUSES, true) ? $normalized : 'pending';
}

function normalizePaymentStatus(string $status): string
{
    $normalized = trim(mb_strtolower($status));
    return in_array($normalized, PAYMENT_STATUSES, true) ? $normalized : 'unpaid';
}

function statusLabel(string $status): string
{
    return match (normalizeStatus($status)) {
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'preparing' => 'Preparing',
        'out_for_delivery' => 'Out for Delivery',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => 'Pending'
    };
}

function paymentStatusLabel(string $status): string
{
    return match (normalizePaymentStatus($status)) {
        'unpaid' => 'Unpaid',
        'partial' => 'Partially Paid',
        'paid' => 'Paid',
        default => 'Unpaid'
    };
}

function canSelfManageOrder(string $status, string $eventDate): bool
{
    $status = normalizeStatus($status);
    if (in_array($status, ['completed', 'cancelled'], true)) {
        return false;
    }

    if ($eventDate === '') {
        return true;
    }

    $eventTs = strtotime($eventDate . ' 00:00:00');
    if ($eventTs === false) {
        return true;
    }

    return (($eventTs - time()) / 3600) >= SELF_SERVICE_LOCK_HOURS;
}

function getSelfManageReason(string $status, string $eventDate): string
{
    $status = normalizeStatus($status);
    if (in_array($status, ['completed', 'cancelled'], true)) {
        return 'Order is already finalized.';
    }

    if ($eventDate !== '') {
        $eventTs = strtotime($eventDate . ' 00:00:00');
        if ($eventTs !== false && (($eventTs - time()) / 3600) < SELF_SERVICE_LOCK_HOURS) {
            return 'Changes are locked within 48 hours before the event.';
        }
    }

    return '';
}

function generateReceiptNo(PDO $pdo): string
{
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = 'RCPT-' . date('ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare('SELECT id FROM payment_receipts WHERE receipt_no = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }

    return 'RCPT-' . date('ymd') . '-' . time();
}

function formatCurrency(float $amount): string
{
    return '$' . number_format($amount, 2);
}

function formatDateTime(string $datetime): string
{
    if ($datetime === '') {
        return 'N/A';
    }

    $ts = strtotime($datetime);
    if ($ts === false) {
        return $datetime;
    }

    return date('M d, Y H:i', $ts);
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

function getAvailability(PDO $pdo, string $startDate, string $endDate): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        throw new RuntimeException('Invalid start date');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        throw new RuntimeException('Invalid end date');
    }

    $startTs = strtotime($startDate);
    $endTs = strtotime($endDate);
    if ($startTs === false || $endTs === false || $endTs < $startTs) {
        throw new RuntimeException('Invalid date range');
    }

    $maxRangeDays = 62;
    if ((($endTs - $startTs) / 86400) > $maxRangeDays) {
        throw new RuntimeException('Date range is too large');
    }

    $settings = getCapacitySettings($pdo);

    $stmt = $pdo->prepare(
        'SELECT event_date, COUNT(*) AS events_count, COALESCE(SUM(guest_count), 0) AS guests_count
         FROM orders
         WHERE event_date BETWEEN :start_date AND :end_date
           AND status IN ("pending", "confirmed", "preparing", "out_for_delivery")
         GROUP BY event_date'
    );
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string)$row['event_date']] = [
            'events' => (int)($row['events_count'] ?? 0),
            'guests' => (int)($row['guests_count'] ?? 0)
        ];
    }

    $rows = [];
    for ($cursor = $startTs; $cursor <= $endTs; $cursor += 86400) {
        $date = date('Y-m-d', $cursor);
        $used = $map[$date] ?? ['events' => 0, 'guests' => 0];
        $remainingEvents = max(0, $settings['maxEventsPerDay'] - $used['events']);
        $remainingGuests = max(0, $settings['maxGuestsPerDay'] - $used['guests']);
        $isFull = !$settings['allowOverbooking'] && ($remainingEvents <= 0 || $remainingGuests <= 0);

        $rows[] = [
            'date' => $date,
            'bookedEvents' => $used['events'],
            'bookedGuests' => $used['guests'],
            'remainingEvents' => $remainingEvents,
            'remainingGuests' => $remainingGuests,
            'isFull' => $isFull
        ];
    }

    return [
        'settings' => $settings,
        'rows' => $rows
    ];
}

function assertDateCapacityAvailable(PDO $pdo, string $eventDate, int $guestCount, ?int $excludeOrderId): void
{
    $settings = getCapacitySettings($pdo);
    if ($settings['allowOverbooking']) {
        return;
    }

    $windowLimit = date('Y-m-d', strtotime('+' . $settings['bookingWindowDays'] . ' days'));
    if ($eventDate > $windowLimit) {
        throw new RuntimeException('Event date is outside the booking window');
    }

    $sql =
        'SELECT COUNT(*) AS events_count, COALESCE(SUM(guest_count), 0) AS guests_count
         FROM orders
         WHERE event_date = :event_date
           AND status IN ("pending", "confirmed", "preparing", "out_for_delivery")';
    $params = [':event_date' => $eventDate];

    if ($excludeOrderId !== null && $excludeOrderId > 0) {
        $sql .= ' AND id != :exclude_id';
        $params[':exclude_id'] = $excludeOrderId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];

    $events = (int)($row['events_count'] ?? 0);
    $guests = (int)($row['guests_count'] ?? 0);

    if (($events + 1) > $settings['maxEventsPerDay']) {
        throw new RuntimeException('Selected date is fully booked for events');
    }
    if (($guests + max(1, $guestCount)) > $settings['maxGuestsPerDay']) {
        throw new RuntimeException('Selected date has reached guest capacity');
    }
}

function getCapacitySettings(PDO $pdo): array
{
    $keys = ['max_events_per_day', 'max_guests_per_day', 'booking_window_days', 'allow_overbooking'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare('SELECT `key`, `value` FROM app_settings WHERE `key` IN (' . $placeholders . ')');
    $stmt->execute($keys);

    $raw = [
        'max_events_per_day' => '5',
        'max_guests_per_day' => '800',
        'booking_window_days' => '365',
        'allow_overbooking' => '0'
    ];

    foreach ($stmt->fetchAll() as $row) {
        $key = (string)$row['key'];
        if (array_key_exists($key, $raw)) {
            $raw[$key] = (string)$row['value'];
        }
    }

    return [
        'maxEventsPerDay' => max(1, (int)$raw['max_events_per_day']),
        'maxGuestsPerDay' => max(1, (int)$raw['max_guests_per_day']),
        'bookingWindowDays' => max(30, (int)$raw['booking_window_days']),
        'allowOverbooking' => $raw['allow_overbooking'] === '1'
    ];
}

function requiredPermissionForCustomerAction(string $action): string
{
    return match ($action) {
        'overview', 'orders', 'packages', 'profile', 'upcoming', 'availability', 'order-details', 'receipts', 'invoices', 'contracts' => 'orders.view_own',
        'create-order' => 'orders.create',
        'update-profile', 'reschedule-order', 'cancel-order', 'mark-notification-read' => 'orders.manage_own',
        'make-payment', 'create-payment-intent' => 'payments.create_own',
        'notifications', 'notification-preferences', 'update-notification-preferences' => 'notifications.manage_own',
        'sign-contract' => 'contracts.sign_own',
        default => ''
    };
}

function getInvoices(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, order_id, invoice_no, amount, currency, due_date, status, notes, created_at, updated_at
         FROM invoices
         WHERE customer_id = :customer_id
         ORDER BY id DESC'
    );
    $stmt->execute([':customer_id' => $userId]);

    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'orderId' => (int)$row['order_id'],
            'invoiceNo' => (string)$row['invoice_no'],
            'amount' => (float)$row['amount'],
            'currency' => (string)$row['currency'],
            'dueDate' => (string)($row['due_date'] ?? ''),
            'status' => (string)$row['status'],
            'notes' => (string)($row['notes'] ?? ''),
            'createdAt' => (string)$row['created_at'],
            'updatedAt' => (string)$row['updated_at']
        ];
    }, $stmt->fetchAll());
}

function getContracts(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, order_id, contract_no, terms_text, status, signed_name, signed_at, created_at, updated_at
         FROM contracts
         WHERE customer_id = :customer_id
         ORDER BY id DESC'
    );
    $stmt->execute([':customer_id' => $userId]);

    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'orderId' => (int)$row['order_id'],
            'contractNo' => (string)$row['contract_no'],
            'termsText' => (string)$row['terms_text'],
            'status' => (string)$row['status'],
            'signedName' => (string)($row['signed_name'] ?? ''),
            'signedAt' => (string)($row['signed_at'] ?? ''),
            'createdAt' => (string)$row['created_at'],
            'updatedAt' => (string)$row['updated_at']
        ];
    }, $stmt->fetchAll());
}

function getNotificationPreferences(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT email_enabled, sms_enabled, inapp_enabled
         FROM notification_preferences
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute([':user_id' => $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return [
            'emailEnabled' => true,
            'smsEnabled' => false,
            'inappEnabled' => true
        ];
    }

    return [
        'emailEnabled' => (int)($row['email_enabled'] ?? 1) === 1,
        'smsEnabled' => (int)($row['sms_enabled'] ?? 0) === 1,
        'inappEnabled' => (int)($row['inapp_enabled'] ?? 1) === 1
    ];
}

function createPaymentIntent(PDO $pdo, int $userId, int $orderId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, order_code, total_amount, amount_paid
         FROM orders
         WHERE id = :id AND customer_id = :customer_id
         LIMIT 1'
    );
    $stmt->execute([':id' => $orderId, ':customer_id' => $userId]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new RuntimeException('Order not found');
    }

    $remaining = max(0.0, (float)$order['total_amount'] - (float)$order['amount_paid']);
    if ($remaining <= 0) {
        throw new RuntimeException('Order is already fully paid');
    }

    $provider = getSetting($pdo, 'payment_provider', 'mock');
    $currency = getSetting($pdo, 'default_currency', 'USD');
    $checkoutBase = rtrim(getSetting($pdo, 'payment_checkout_base_url', 'https://example.test/pay'), '/');

    $transactionRef = 'TXN-' . date('ymd') . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
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
        ':amount' => $remaining,
        ':checkout_url' => $checkoutUrl,
        ':request_payload' => json_encode(['orderCode' => (string)$order['order_code']], JSON_UNESCAPED_SLASHES)
    ]);

    return [
        'provider' => $provider,
        'transactionRef' => $transactionRef,
        'amount' => round($remaining, 2),
        'currency' => $currency,
        'checkoutUrl' => $checkoutUrl
    ];
}
