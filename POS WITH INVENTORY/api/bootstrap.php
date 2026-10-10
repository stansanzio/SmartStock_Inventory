<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function startAppSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Strict',
            'path' => '/',
        ]);
        session_start();
    }
}

function sendJson(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonBody(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function requireUserRole(string $requiredRole): array
{
    startAppSession();

    if (empty($_SESSION['user_id'])) {
        sendJson(['error' => 'Please sign in to continue.'], 401);
    }

    try {
        $pdo = getDatabaseConnection();
        ensureOwnerRoleSchema($pdo);
        $statement = $pdo->prepare(
            'SELECT id, display_name, username, role, avatar_id FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => (int) $_SESSION['user_id']]);
        $user = $statement->fetch();
    } catch (Throwable $exception) {
        handleApiFailure($exception);
    }

    if (!$user || ($user['role'] !== $requiredRole && $user['role'] !== 'owner')) {
        $_SESSION = [];
        session_destroy();
        sendJson(['error' => 'Your account does not have access to this page. Please sign in again.'], 401);
    }

    $_SESSION['display_name'] = (string) $user['display_name'];
    $_SESSION['username'] = (string) $user['username'];

    return [
        'id' => (int) $user['id'],
        'displayName' => (string) $user['display_name'],
        'username' => (string) $user['username'],
        'role' => (string) $user['role'],
        'avatarId' => $user['avatar_id'] === null ? null : (int) $user['avatar_id'],
    ];
}

function requireAdmin(): array
{
    return requireUserRole('admin');
}

function requireEmployee(): array
{
    return requireUserRole('employee');
}

function requireSignedInUser(): array
{
    startAppSession();

    if (empty($_SESSION['user_id'])) {
        sendJson(['error' => 'Please sign in to continue.'], 401);
    }

    try {
        $statement = getDatabaseConnection()->prepare(
            'SELECT id, display_name, username, role FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => (int) $_SESSION['user_id']]);
        $user = $statement->fetch();
    } catch (Throwable $exception) {
        handleApiFailure($exception);
    }

    if (!$user || !in_array($user['role'], ['admin', 'employee', 'owner'], true)) {
        $_SESSION = [];
        session_destroy();
        sendJson(['error' => 'Please sign in to continue.'], 401);
    }

    return [
        'id' => (int) $user['id'],
        'displayName' => (string) $user['display_name'],
        'username' => (string) $user['username'],
        'role' => (string) $user['role'],
    ];
}

function ensureOwnerRoleSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $roleColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if (!$roleColumn || !str_contains((string) $roleColumn['Type'], "'owner'")) {
        $pdo->exec(
            "ALTER TABLE users
             MODIFY role ENUM('admin', 'employee', 'owner') NOT NULL DEFAULT 'admin'"
        );
    }

    $ready = true;
}

function getEmployeeOfMonth(PDO $pdo): ?array
{
    $start = new DateTimeImmutable('first day of this month');
    $start = $start->setTime(0, 0, 0);
    $end = $start->modify('+1 month');
    $statement = $pdo->prepare(
        "SELECT u.id, u.display_name, SUM(si.quantity) AS units_sold
         FROM users u
         INNER JOIN sales s ON s.cashier_id = u.id
         INNER JOIN sale_items si ON si.sale_id = s.id
         WHERE u.role = 'employee'
           AND s.created_at >= :start_at
           AND s.created_at < :end_at
         GROUP BY u.id, u.display_name
         ORDER BY units_sold DESC, u.display_name ASC, u.id ASC
         LIMIT 1"
    );
    $statement->execute([
        'start_at' => $start->format('Y-m-d H:i:s'),
        'end_at' => $end->format('Y-m-d H:i:s'),
    ]);
    $leader = $statement->fetch();

    if (!$leader) {
        return null;
    }

    return [
        'id' => (int) $leader['id'],
        'displayName' => (string) $leader['display_name'],
        'unitsSold' => (int) $leader['units_sold'],
        'month' => $start->format('Y-m'),
    ];
}

function ensurePosSchema(PDO $pdo): void
{
    $categoryColumn = $pdo->query("SHOW COLUMNS FROM products LIKE 'category'")->fetch();
    if (!$categoryColumn) {
        $pdo->exec(
            "ALTER TABLE products
             ADD COLUMN category VARCHAR(80) NOT NULL DEFAULT 'Uncategorized' AFTER image_url"
        );
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sales (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subtotal DECIMAL(12, 2) UNSIGNED NOT NULL,
            discount_type ENUM(\'pwd\', \'senior\') NULL,
            discount_percent DECIMAL(5, 2) UNSIGNED NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(12, 2) UNSIGNED NOT NULL DEFAULT 0.00,
            tax_rate DECIMAL(5, 2) UNSIGNED NOT NULL,
            tax_amount DECIMAL(12, 2) UNSIGNED NOT NULL,
            total DECIMAL(12, 2) UNSIGNED NOT NULL,
            payment_method ENUM(\'cash\', \'card\') NOT NULL,
            cash_received DECIMAL(12, 2) UNSIGNED NULL,
            change_given DECIMAL(12, 2) UNSIGNED NULL,
            card_type VARCHAR(30) NULL,
            card_last_four CHAR(4) NULL,
            authorization_reference VARCHAR(100) NULL,
            cashier_name VARCHAR(100) NULL,
            cashier_avatar_id TINYINT UNSIGNED NULL,
            cashier_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sales_created_at_idx (created_at),
            CONSTRAINT sales_cashier_fk
                FOREIGN KEY (cashier_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $salePaymentColumns = [
        'cash_received' => 'DECIMAL(12, 2) UNSIGNED NULL',
        'change_given' => 'DECIMAL(12, 2) UNSIGNED NULL',
        'card_type' => 'VARCHAR(30) NULL',
        'card_last_four' => 'CHAR(4) NULL',
        'authorization_reference' => 'VARCHAR(100) NULL',
        'cashier_name' => 'VARCHAR(100) NULL',
        'cashier_avatar_id' => 'TINYINT UNSIGNED NULL',
        'discount_type' => "ENUM('pwd', 'senior') NULL",
        'discount_percent' => 'DECIMAL(5, 2) UNSIGNED NOT NULL DEFAULT 0.00',
        'discount_amount' => 'DECIMAL(12, 2) UNSIGNED NOT NULL DEFAULT 0.00',
    ];
    foreach ($salePaymentColumns as $column => $definition) {
        $columnExists = $pdo->query("SHOW COLUMNS FROM sales LIKE '$column'")->fetch();
        if (!$columnExists) {
            $pdo->exec("ALTER TABLE sales ADD COLUMN $column $definition");
        }
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sale_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sale_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NULL,
            product_name VARCHAR(150) NOT NULL,
            sku VARCHAR(50) NOT NULL,
            category VARCHAR(80) NOT NULL DEFAULT \'Uncategorized\',
            quantity INT UNSIGNED NOT NULL,
            unit_price DECIMAL(10, 2) UNSIGNED NOT NULL,
            line_total DECIMAL(12, 2) UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            KEY sale_items_sale_idx (sale_id),
            KEY sale_items_product_idx (product_id),
            CONSTRAINT sale_items_sale_fk
                FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE,
            CONSTRAINT sale_items_product_fk
                FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $saleItemCategoryColumn = $pdo->query("SHOW COLUMNS FROM sale_items LIKE 'category'")->fetch();
    if (!$saleItemCategoryColumn) {
        $pdo->exec(
            "ALTER TABLE sale_items
             ADD COLUMN category VARCHAR(80) NOT NULL DEFAULT 'Uncategorized' AFTER sku"
        );
        $pdo->exec(
            "UPDATE sale_items si
             INNER JOIN products p ON p.id = si.product_id
             SET si.category = p.category"
        );
    }
}

function ensureAvatarSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS profile_avatars (
            id TINYINT UNSIGNED NOT NULL,
            label VARCHAR(60) NOT NULL,
            mime_type VARCHAR(30) NOT NULL,
            image_size INT UNSIGNED NOT NULL DEFAULT 0,
            image_data MEDIUMBLOB NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS profile_avatar_chunks (
            avatar_id TINYINT UNSIGNED NOT NULL,
            chunk_index SMALLINT UNSIGNED NOT NULL,
            image_data VARBINARY(32768) NOT NULL,
            PRIMARY KEY (avatar_id, chunk_index),
            CONSTRAINT profile_avatar_chunks_avatar_fk
                FOREIGN KEY (avatar_id) REFERENCES profile_avatars (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $imageSizeColumn = $pdo->query("SHOW COLUMNS FROM profile_avatars LIKE 'image_size'")->fetch();
    if (!$imageSizeColumn) {
        $pdo->exec(
            'ALTER TABLE profile_avatars
             ADD COLUMN image_size INT UNSIGNED NOT NULL DEFAULT 0 AFTER mime_type'
        );
    }

    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar_id'")->fetch();
    if (!$column) {
        $pdo->exec('ALTER TABLE users ADD COLUMN avatar_id TINYINT UNSIGNED NULL AFTER role');
    }

    $avatars = [
        [1, 'Avatar 1', 'avatar-01.png'],
        [2, 'Avatar 2', 'avatar-02.png'],
        [3, 'Avatar 3', 'avatar-03.png'],
        [4, 'Avatar 4', 'avatar-04.png'],
        [5, 'Avatar 5', 'avatar-05.png'],
        [6, 'Avatar 6', 'avatar-06.png'],
        [7, 'Avatar 7', 'avatar-07.png'],
    ];
    $existingRows = $pdo->query('SELECT id, label, image_size FROM profile_avatars')->fetchAll();
    $existingSizes = [];
    $existingLabels = [];
    foreach ($existingRows as $existingRow) {
        $existingSizes[(int) $existingRow['id']] = (int) $existingRow['image_size'];
        $existingLabels[(int) $existingRow['id']] = (string) $existingRow['label'];
    }
    $chunkRows = $pdo->query(
        'SELECT avatar_id, COUNT(*) AS chunk_count, SUM(OCTET_LENGTH(image_data)) AS total_size
         FROM profile_avatar_chunks GROUP BY avatar_id'
    )->fetchAll();
    $existingChunks = [];
    foreach ($chunkRows as $chunkRow) {
        $existingChunks[(int) $chunkRow['avatar_id']] = [
            'count' => (int) $chunkRow['chunk_count'],
            'size' => (int) $chunkRow['total_size'],
        ];
    }
    $insertAvatar = $pdo->prepare(
        'INSERT INTO profile_avatars (id, label, mime_type, image_size, image_data)
         VALUES (:id, :label, :mime_type, 0, :image_data)
         ON DUPLICATE KEY UPDATE
           label = VALUES(label),
           mime_type = VALUES(mime_type),
           image_size = 0,
           image_data = VALUES(image_data)'
    );
    $deleteChunks = $pdo->prepare('DELETE FROM profile_avatar_chunks WHERE avatar_id = :id');
    $insertChunk = $pdo->prepare(
        'INSERT INTO profile_avatar_chunks (avatar_id, chunk_index, image_data)
         VALUES (:avatar_id, :chunk_index, :image_data)'
    );
    $finishAvatar = $pdo->prepare('UPDATE profile_avatars SET image_size = :image_size WHERE id = :id');

    foreach ($avatars as [$id, $label, $filename]) {
        $path = __DIR__ . '/../images/avatars/' . $filename;
        $sourceSize = filesize($path);
        if ($sourceSize === false) {
            throw new RuntimeException('An avatar image is missing: ' . $filename);
        }
        $chunkCount = (int) ceil($sourceSize / 32768);
        $chunksMatch = ($existingChunks[$id]['count'] ?? 0) === $chunkCount
            && ($existingChunks[$id]['size'] ?? 0) === $sourceSize;
        if (($existingLabels[$id] ?? null) !== $label) {
            $updateLabel = $pdo->prepare('UPDATE profile_avatars SET label = :label WHERE id = :id');
            $updateLabel->execute(['label' => $label, 'id' => $id]);
        }
        if (($existingSizes[$id] ?? 0) === $sourceSize && $chunksMatch) {
            continue;
        }

        $image = file_get_contents($path);
        if (
            $image === false
            || strlen($image) !== $sourceSize
            || $sourceSize > 16777215
            || !str_starts_with($image, "\x89PNG\r\n\x1a\n")
        ) {
            throw new RuntimeException('An avatar image is missing or invalid: ' . $filename);
        }
        $imageSize = $sourceSize;

        $pdo->beginTransaction();
        try {
            $insertAvatar->bindValue(':id', $id, PDO::PARAM_INT);
            $insertAvatar->bindValue(':label', $label);
            $insertAvatar->bindValue(':mime_type', 'image/png');
            $insertAvatar->bindValue(':image_data', '', PDO::PARAM_LOB);
            $insertAvatar->execute();
            $deleteChunks->execute(['id' => $id]);

            // Small chunks avoid exceeding MySQL's configured max_allowed_packet.
            for ($chunkIndex = 0, $offset = 0; $offset < $imageSize; $chunkIndex++, $offset += 32768) {
                $insertChunk->bindValue(':avatar_id', $id, PDO::PARAM_INT);
                $insertChunk->bindValue(':chunk_index', $chunkIndex, PDO::PARAM_INT);
                $insertChunk->bindValue(':image_data', substr($image, $offset, 32768), PDO::PARAM_LOB);
                $insertChunk->execute();
            }

            $finishAvatar->bindValue(':image_size', $imageSize, PDO::PARAM_INT);
            $finishAvatar->bindValue(':id', $id, PDO::PARAM_INT);
            $finishAvatar->execute();
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    $unassignedUsers = $pdo->query('SELECT 1 FROM users WHERE avatar_id IS NULL LIMIT 1')->fetchColumn();
    if ($unassignedUsers !== false) {
        $pdo->exec('UPDATE users SET avatar_id = 1 WHERE avatar_id IS NULL');
    }
    $foreignKey = $pdo->query(
        "SELECT CONSTRAINT_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'avatar_id'
           AND REFERENCED_TABLE_NAME = 'profile_avatars'
         LIMIT 1"
    )->fetchColumn();
    if ($foreignKey === false) {
        $pdo->exec(
            'ALTER TABLE users
             ADD CONSTRAINT users_avatar_fk
             FOREIGN KEY (avatar_id) REFERENCES profile_avatars (id) ON DELETE SET NULL'
        );
    }

    $ready = true;
}

function requireCsrfToken(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
        sendJson(['error' => 'Your session has expired. Refresh the page and try again.'], 403);
    }
}

function handleApiFailure(Throwable $exception): never
{
    error_log($exception->__toString());
    sendJson(['error' => 'The server could not complete the request. Check the XAMPP database setup and try again.'], 500);
}
