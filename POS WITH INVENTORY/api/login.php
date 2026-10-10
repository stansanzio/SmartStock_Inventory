<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['error' => 'Method not allowed.'], 405);
}

$input = readJsonBody();
$username = trim((string) ($input['username'] ?? ''));
$password = (string) ($input['password'] ?? '');
$role = (string) ($input['role'] ?? 'admin');

if ($username === '' || $password === '' || !in_array($role, ['admin', 'employee'], true)) {
    sendJson(['error' => 'Enter your username and password.'], 422);
}

try {
    $pdo = getDatabaseConnection();
    ensureOwnerRoleSchema($pdo);
    $statement = $pdo->prepare(
        'SELECT id, display_name, username, password_hash, role FROM users WHERE username = :username LIMIT 1'
    );
    $statement->execute(['username' => $username]);
    $user = $statement->fetch();

    if (
        !$user
        || !password_verify($password, $user['password_hash'])
        || ($user['role'] !== $role && $user['role'] !== 'owner')
    ) {
        sendJson(['error' => $role === 'admin' ? 'Invalid Admin ID or password.' : 'Invalid employee ID or password.'], 401);
    }

    startAppSession();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['display_name'] = (string) $user['display_name'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    sendJson([
        'user' => [
            'displayName' => (string) $user['display_name'],
            'username' => (string) $user['username'],
            'role' => (string) $user['role'],
        ],
    ]);
} catch (Throwable $exception) {
    handleApiFailure($exception);
}
