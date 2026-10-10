<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    requireSignedInUser();
    $pdo = getDatabaseConnection();
    ensureAvatarSchema($pdo);

    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        http_response_code(404);
        exit;
    }

    $statement = $pdo->prepare('SELECT mime_type, image_size FROM profile_avatars WHERE id = :id');
    $statement->execute(['id' => $id]);
    $avatar = $statement->fetch();
    if (!$avatar) {
        http_response_code(404);
        exit;
    }

    $chunks = $pdo->prepare(
        'SELECT image_data FROM profile_avatar_chunks WHERE avatar_id = :id ORDER BY chunk_index'
    );
    $chunks->execute(['id' => $id]);
    $imageData = $chunks->fetchAll(PDO::FETCH_COLUMN);
    $image = implode('', $imageData);
    if (strlen($image) !== (int) $avatar['image_size']) {
        throw new RuntimeException('The stored avatar image is incomplete.');
    }

    header('Content-Type: ' . $avatar['mime_type']);
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($image));
    echo $image;
} catch (Throwable $exception) {
    handleApiFailure($exception);
}
