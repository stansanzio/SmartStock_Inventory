<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(['error' => 'Method not allowed.'], 405);
}

try {
    requireAdmin();
    $pdo = getDatabaseConnection();
    ensurePosSchema($pdo);
    sendJson(['employeeOfMonth' => getEmployeeOfMonth($pdo)]);
} catch (Throwable $exception) {
    handleApiFailure($exception);
}
