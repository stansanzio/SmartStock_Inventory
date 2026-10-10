<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$admin = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

try {
    $pdo = getDatabaseConnection();
    ensureAvatarSchema($pdo);
    ensureAdminAccountRequestSchema($pdo);

    if ($method === 'GET') {
        ensurePosSchema($pdo);
        $employeeOfMonth = getEmployeeOfMonth($pdo);
        $statement = $pdo->query(
            'SELECT id, display_name, username, role, avatar_id, created_at FROM users ORDER BY display_name, id'
        );
        $users = $statement->fetchAll();
        foreach ($users as &$user) {
            $user['avatarId'] = (int) $user['avatar_id'];
            $user['avatarUrl'] = '../api/avatar-image.php?id=' . $user['avatarId'];
            $user['isEmployeeOfMonth'] = $employeeOfMonth !== null
                && (int) $user['id'] === $employeeOfMonth['id'];
            unset($user['avatar_id']);
        }
        unset($user);
        $avatars = $pdo->query('SELECT id, label FROM profile_avatars ORDER BY id')->fetchAll();
        foreach ($avatars as &$avatar) {
            $avatar['id'] = (int) $avatar['id'];
            $avatar['imageUrl'] = '../api/avatar-image.php?id=' . $avatar['id'];
        }
        unset($avatar);
        sendJson([
            'users' => $users,
            'avatars' => $avatars,
            'employeeOfMonth' => $employeeOfMonth,
        ]);
    }

    requireCsrfToken();

    if ($method === 'POST') {
        $input = readJsonBody();
        $displayName = trim((string) ($input['displayName'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $role = (string) ($input['role'] ?? '');
        $avatarId = filter_var($input['avatarId'] ?? 1, FILTER_VALIDATE_INT);

        if ($displayName === '' || mb_strlen($displayName) > 100) {
            sendJson(['error' => 'Enter a name up to 100 characters long.'], 422);
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            sendJson(['error' => 'Username must be 3–50 characters and use only letters, numbers, dots, underscores, or hyphens.'], 422);
        }
        if (strlen($password) < 10 || strlen($password) > 4096) {
            sendJson(['error' => 'Password must be at least 10 characters long.'], 422);
        }
        if (!in_array($role, ['admin', 'employee'], true)) {
            sendJson(['error' => 'Select Admin or Employee for the user role.'], 422);
        }
        $pendingUsername = $pdo->prepare(
            'SELECT 1 FROM admin_account_requests WHERE username = :username LIMIT 1'
        );
        $pendingUsername->execute(['username' => $username]);
        if ($pendingUsername->fetchColumn()) {
            sendJson(['error' => 'That username is reserved by a pending administrator request.'], 409);
        }
        if (!$avatarId || $avatarId < 1) {
            sendJson(['error' => 'Select a valid profile avatar.'], 422);
        }
        $avatarExists = $pdo->prepare('SELECT 1 FROM profile_avatars WHERE id = :id');
        $avatarExists->execute(['id' => $avatarId]);
        if (!$avatarExists->fetchColumn()) {
            sendJson(['error' => 'Select a valid profile avatar.'], 422);
        }

        $statement = $pdo->prepare(
            'INSERT INTO users (display_name, username, password_hash, role, avatar_id)
             VALUES (:display_name, :username, :password_hash, :role, :avatar_id)'
        );
        $statement->execute([
            'display_name' => $displayName,
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'avatar_id' => $avatarId,
        ]);

        sendJson(['ok' => true, 'id' => (int) $pdo->lastInsertId()], 201);
    }

    if ($method === 'PUT') {
        $input = readJsonBody();
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            sendJson(['error' => 'Select a valid user to update.'], 422);
        }

        if (
            array_key_exists('avatarId', $input)
            && !array_key_exists('displayName', $input)
            && !array_key_exists('username', $input)
            && !array_key_exists('password', $input)
        ) {
            $avatarId = filter_var($input['avatarId'], FILTER_VALIDATE_INT);
            if (!$avatarId || $avatarId < 1) {
                sendJson(['error' => 'Select a valid profile avatar.'], 422);
            }
            $avatarExists = $pdo->prepare('SELECT 1 FROM profile_avatars WHERE id = :id');
            $avatarExists->execute(['id' => $avatarId]);
            if (!$avatarExists->fetchColumn()) {
                sendJson(['error' => 'Select a valid profile avatar.'], 422);
            }
            $statement = $pdo->prepare('UPDATE users SET avatar_id = :avatar_id WHERE id = :id');
            $statement->execute(['avatar_id' => $avatarId, 'id' => $id]);
            if ($statement->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM users WHERE id = :id');
                $exists->execute(['id' => $id]);
                if (!$exists->fetchColumn()) {
                    sendJson(['error' => 'That user no longer exists. Refresh the list and try again.'], 404);
                }
            }
            sendJson(['ok' => true]);
        }

        $displayName = trim((string) ($input['displayName'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($displayName === '' || mb_strlen($displayName) > 100) {
            sendJson(['error' => 'Enter a name up to 100 characters long.'], 422);
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            sendJson(['error' => 'Username must be 3–50 characters and use only letters, numbers, dots, underscores, or hyphens.'], 422);
        }
        if ($password !== '' && (strlen($password) < 10 || strlen($password) > 4096)) {
            sendJson(['error' => 'A new password must be at least 10 characters long.'], 422);
        }

        $statement = $pdo->prepare('SELECT role FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $role = $statement->fetchColumn();
        if ($role === false) {
            sendJson(['error' => 'That employee no longer exists. Refresh the list and try again.'], 404);
        }
        if ($role !== 'employee') {
            sendJson(['error' => 'Only employee accounts can be edited here.'], 409);
        }

        if ($password === '') {
            $statement = $pdo->prepare(
                'UPDATE users SET display_name = :display_name, username = :username WHERE id = :id AND role = :role'
            );
            $statement->execute([
                'display_name' => $displayName,
                'username' => $username,
                'id' => $id,
                'role' => 'employee',
            ]);
        } else {
            $statement = $pdo->prepare(
                'UPDATE users
                 SET display_name = :display_name, username = :username, password_hash = :password_hash
                 WHERE id = :id AND role = :role'
            );
            $statement->execute([
                'display_name' => $displayName,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $id,
                'role' => 'employee',
            ]);
        }

        sendJson(['ok' => true]);
    }

    if ($method === 'DELETE') {
        $input = readJsonBody();
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

        if (!$id || $id < 1) {
            sendJson(['error' => 'Select a valid user to delete.'], 422);
        }
        if ($id === $admin['id']) {
            sendJson(['error' => 'You cannot delete the account you are currently using.'], 409);
        }

        $pdo->beginTransaction();
        try {
            $lockedUsers = $pdo->query('SELECT id, role FROM users FOR UPDATE')->fetchAll();
            if (count($lockedUsers) <= 1) {
                $pdo->rollBack();
                sendJson(['error' => 'The last user account cannot be deleted.'], 409);
            }

            $targetUser = null;
            $adminCount = 0;
            foreach ($lockedUsers as $lockedUser) {
                if ((int) $lockedUser['id'] === $id) {
                    $targetUser = $lockedUser;
                }
                if ($lockedUser['role'] === 'admin') {
                    $adminCount++;
                }
            }
            if ($targetUser === null) {
                $pdo->rollBack();
                sendJson(['error' => 'That user no longer exists. Refresh the list and try again.'], 404);
            }
            if ($targetUser['role'] === 'owner') {
                $pdo->rollBack();
                sendJson(['error' => 'Owner accounts cannot be deleted from User Management.'], 409);
            }
            if ($targetUser['role'] === 'admin' && $adminCount <= 1) {
                $pdo->rollBack();
                sendJson(['error' => 'The last administrator account cannot be deleted.'], 409);
            }

            $statement = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $statement->execute(['id' => $id]);

            if ($statement->rowCount() === 0) {
                $pdo->rollBack();
                sendJson(['error' => 'That user no longer exists. Refresh the list and try again.'], 404);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        sendJson(['ok' => true]);
    }

    sendJson(['error' => 'Method not allowed.'], 405);
} catch (PDOException $exception) {
    if (in_array($method, ['POST', 'PUT'], true) && $exception->getCode() === '23000') {
        sendJson(['error' => 'That username is already in use.'], 409);
    }
    handleApiFailure($exception);
} catch (Throwable $exception) {
    handleApiFailure($exception);
}
