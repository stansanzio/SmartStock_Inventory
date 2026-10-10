<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['error' => 'Method not allowed.'], 405);
}

requireSignedInUser();
requireCsrfToken();

$_SESSION = [];
$parameters = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires' => time() - 42000,
    'path' => $parameters['path'],
    'secure' => $parameters['secure'],
    'httponly' => $parameters['httponly'],
    'samesite' => $parameters['samesite'],
]);
session_destroy();

sendJson(['ok' => true]);
