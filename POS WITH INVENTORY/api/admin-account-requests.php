<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

requireAdmin();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

try {
    $pdo = getDatabaseConnection();
    ensureAdminAccountRequestSchema($pdo);

    if ($method === 'GET') {
        $requests = $pdo->query(
            'SELECT id, display_name, username, created_at
             FROM admin_account_requests
             ORDER BY created_at, id'
        )->fetchAll();
        sendJson(['requests' => $requests]);
    }

    if ($method !== 'POST') {
        sendJson(['error' => 'Method not allowed.'], 405);
    }

    requireCsrfToken();
    $input = readJsonBody();
    $action = (string) ($input['action'] ?? '');
    $requestId = filter_var($input['requestId'] ?? null, FILTER_VALIDATE_INT);
    if (!in_array($action, ['approve', 'reject'], true) || !$requestId || $requestId < 1) {
        sendJson(['error' => 'Select a valid administrator request and action.'], 422);
    }

    if ($action === 'reject') {
        $statement = $pdo->prepare('DELETE FROM admin_account_requests WHERE id = :id');
        $statement->execute(['id' => $requestId]);
        if ($statement->rowCount() === 0) {
            sendJson(['error' => 'That administrator request no longer exists. Refresh and try again.'], 404);
        }
        sendJson(['ok' => true]);
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT display_name, username, password_hash
             FROM admin_account_requests WHERE id = :id FOR UPDATE'
        );
        $statement->execute(['id' => $requestId]);
        $request = $statement->fetch();
        if (!$request) {
            $pdo->rollBack();
            sendJson(['error' => 'That administrator request no longer exists. Refresh and try again.'], 404);
        }

        $statement = $pdo->prepare(
            'INSERT INTO users (display_name, username, password_hash, role)
             VALUES (:display_name, :username, :password_hash, "admin")'
        );
        $statement->execute([
            'display_name' => $request['display_name'],
            'username' => $request['username'],
            'password_hash' => $request['password_hash'],
        ]);
        $statement = $pdo->prepare('DELETE FROM admin_account_requests WHERE id = :id');
        $statement->execute(['id' => $requestId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    sendJson(['ok' => true]);
} catch (PDOException $exception) {
    if ($method === 'POST' && $exception->getCode() === '23000') {
        sendJson(['error' => 'That username is already in use. The request was not approved.'], 409);
    }
    handleApiFailure($exception);
} catch (Throwable $exception) {
    handleApiFailure($exception);
}