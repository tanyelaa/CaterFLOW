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

$fullname = trim((string)($_POST['fullname'] ?? ''));
$username = trim((string)($_POST['username'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$city = trim((string)($_POST['city'] ?? ''));
$province = trim((string)($_POST['province'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

$requirements = [];

if ($fullname === '' || mb_strlen($fullname) < 2 || mb_strlen($fullname) > 100) {
    $requirements[] = 'Full name must be 2-100 characters';
}

if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
    $requirements[] = 'Username must be 3-20 chars and only letters, numbers, underscore';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $requirements[] = 'Valid email is required';
}

$digitsOnlyPhone = preg_replace('/\D+/', '', $phone ?? '');
if ($phone === '' || !preg_match('/^[0-9\-\+\(\)\s]+$/', $phone) || strlen((string)$digitsOnlyPhone) < 7) {
    $requirements[] = 'Valid phone number is required';
}

if (strlen($password) < 8 ||
    !preg_match('/[A-Z]/', $password) ||
    !preg_match('/[a-z]/', $password) ||
    !preg_match('/[0-9]/', $password)) {
    $requirements[] = 'Password must be at least 8 chars and include upper, lower, number';
}

if ($password !== $confirmPassword) {
    $requirements[] = 'Passwords do not match';
}

if (!empty($requirements)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Validation failed.',
        'requirements' => $requirements
    ]);
    exit;
}

try {
    $pdo = getDb();

    $check = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
    $check->execute([
        ':username' => $username,
        ':email' => $email
    ]);

    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Username or email already exists.'
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
           'INSERT INTO users (fullname, username, email, phone, address, city, province, role, status, password_hash, created_at, updated_at, last_active)
            VALUES (:fullname, :username, :email, :phone, :address, :city, :province, :role, :status, :password_hash, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    );

    $stmt->execute([
        ':fullname' => $fullname,
        ':username' => $username,
        ':email' => $email,
        ':phone' => $phone,
        ':address' => $address,
        ':city' => $city,
        ':province' => $province,
        ':role' => 'customer',
        ':status' => 'active',
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT)
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully.'
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
