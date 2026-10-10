<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(['error' => 'Method not allowed.'], 405);
}

try {
    $user = requireAdmin();
    $pdo = getDatabaseConnection();
    ensureAvatarSchema($pdo);
    ensurePosSchema($pdo);
    $statement = $pdo->prepare('SELECT avatar_id FROM users WHERE id = :id');
    $statement->execute(['id' => $user['id']]);
    $user['avatarId'] = (int) $statement->fetchColumn();
    $user['avatarUrl'] = '../api/avatar-image.php?id=' . $user['avatarId'];
    $employeeOfMonth = getEmployeeOfMonth($pdo);
    $user['isEmployeeOfMonth'] = $employeeOfMonth !== null && $employeeOfMonth['id'] === $user['id'];
    $user['employeeOfMonth'] = $employeeOfMonth;
    sendJson([
        'user' => $user,
        'csrfToken' => (string) $_SESSION['csrf_token'],
    ]);
} catch (Throwable $exception) {
    handleApiFailure($exception);
}
