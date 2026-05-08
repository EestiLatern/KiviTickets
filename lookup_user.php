<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

require_role(['conductor', 'admin']);

header('Content-Type: application/json');

$code = trim($_GET['code'] ?? '');
if($code === '') {
    echo json_encode(['found' => false]);
    exit;
}

$stmt = mysqli_prepare($connection,
    "SELECT full_name, username, role FROM users WHERE public_code = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $code);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if($user) {
    $isStaff = in_array($user['role'], ['conductor', 'admin']);
    echo json_encode([
        'found'    => true,
        'name'     => $user['full_name'] ?: $user['username'],
        'is_staff' => $isStaff,
    ]);
} else {
    echo json_encode(['found' => false]);
}
