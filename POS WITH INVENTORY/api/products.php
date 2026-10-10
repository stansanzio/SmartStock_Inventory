<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

requireAdmin();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

try {
    $pdo = getDatabaseConnection();
    ensurePosSchema($pdo);
    $productImageDirectory = __DIR__ . '/../images/products';
    if (!is_dir($productImageDirectory) && !mkdir($productImageDirectory, 0750, true) && !is_dir($productImageDirectory)) {
        throw new RuntimeException('Product image storage could not be created.');
    }

    if ($method === 'GET') {
        $statement = $pdo->query(
            'SELECT id, product_name, sku, price, stock, status, image_url, category, created_at, updated_at
             FROM products
             ORDER BY product_name, id'
        );
        sendJson(['products' => $statement->fetchAll()]);
    }

    requireCsrfToken();

    if ($method === 'POST') {
        $input = readJsonBody();
        $id = !array_key_exists('id', $input) || $input['id'] === null || $input['id'] === ''
            ? null
            : filter_var($input['id'], FILTER_VALIDATE_INT);
        $name = trim((string) ($input['name'] ?? ''));
        $sku = trim((string) ($input['sku'] ?? ''));
        $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $stock = filter_var($input['stock'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($input['status'] ?? '');
        $imageUrl = trim((string) ($input['imageUrl'] ?? ''));
        $category = trim((string) ($input['category'] ?? 'Uncategorized'));
        $imagePath = null;

        if ($id === false || ($id !== null && $id < 1)) {
            sendJson(['error' => 'Select a valid product to update.'], 422);
        }
        if ($name === '' || mb_strlen($name) > 150) {
            sendJson(['error' => 'Enter a product name up to 150 characters long.'], 422);
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,50}$/', $sku)) {
            sendJson(['error' => 'SKU must be 1–50 characters and use only letters, numbers, dots, underscores, or hyphens.'], 422);
        }
        if ($price === false || !is_finite((float) $price) || $price < 0 || $price > 99999999.99) {
            sendJson(['error' => 'Enter a valid price between 0 and 99,999,999.99.'], 422);
        }
        if ($stock === false || $stock < 0 || $stock > 2147483647) {
            sendJson(['error' => 'Stock must be a whole number between 0 and 2,147,483,647.'], 422);
        }
        if (!in_array($status, ['active', 'discontinued'], true)) {
            sendJson(['error' => 'Select Active or Discontinued for the product status.'], 422);
        }
        if ($category === '' || mb_strlen($category) > 80) {
            sendJson(['error' => 'Enter a product category up to 80 characters long.'], 422);
        }
        if (mb_strlen($imageUrl) > 2048) {
            sendJson(['error' => 'Image URL must be 2,048 characters or fewer.'], 422);
        }
        if ($imageUrl !== '' && !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            sendJson(['error' => 'Enter a valid HTTP or HTTPS image URL.'], 422);
        }
        if ($imageUrl !== '') {
            $imageBytes = fetchProductImage($imageUrl);
            $imagePath = storeProductImage($imageBytes, $productImageDirectory);
        }

        try {
            if ($id !== null) {
                $statement = $pdo->prepare(
                    'SELECT image_url FROM products WHERE id = :id'
                );
                $statement->execute(['id' => $id]);
                $oldImagePath = $statement->fetchColumn();
                if ($oldImagePath === false) {
                    removeManagedProductImage($imagePath, $productImageDirectory);
                    sendJson(['error' => 'That product no longer exists. Refresh the list and try again.'], 404);
                }

                $statement = $pdo->prepare(
                    'UPDATE products
                     SET product_name = :product_name, sku = :sku, price = :price, stock = :stock, status = :status,
                         image_url = :image_url, category = :category
                     WHERE id = :id'
                );
                $statement->execute([
                    'product_name' => $name,
                    'sku' => $sku,
                    'price' => number_format((float) $price, 2, '.', ''),
                    'stock' => $stock,
                    'status' => $status,
                    'image_url' => $imagePath ?? $oldImagePath,
                    'category' => $category,
                    'id' => $id,
                ]);

                if ($imagePath !== null) {
                    removeManagedProductImage($oldImagePath, $productImageDirectory);
                }
                sendJson(['ok' => true, 'id' => $id]);
            }

            $statement = $pdo->prepare(
                'INSERT INTO products (product_name, sku, price, stock, status, image_url, category)
                 VALUES (:product_name, :sku, :price, :stock, :status, :image_url, :category)'
            );
            $statement->execute([
                'product_name' => $name,
                'sku' => $sku,
                'price' => number_format((float) $price, 2, '.', ''),
                'stock' => $stock,
                'status' => $status,
                'image_url' => $imagePath,
                'category' => $category,
            ]);

            sendJson(['ok' => true, 'id' => (int) $pdo->lastInsertId()], 201);
        } catch (Throwable $exception) {
            removeManagedProductImage($imagePath, $productImageDirectory);
            throw $exception;
        }
    }

    if ($method === 'DELETE') {
        $input = readJsonBody();
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            sendJson(['error' => 'Select a valid product to delete.'], 422);
        }

        $statement = $pdo->prepare('SELECT image_url FROM products WHERE id = :id');
        $statement->execute(['id' => $id]);
        $imagePath = $statement->fetchColumn();
        if ($imagePath === false) {
            sendJson(['error' => 'That product no longer exists. Refresh the list and try again.'], 404);
        }

        $statement = $pdo->prepare('DELETE FROM products WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            sendJson(['error' => 'That product no longer exists. Refresh the list and try again.'], 404);
        }
        removeManagedProductImage($imagePath, $productImageDirectory);

        sendJson(['ok' => true]);
    }

    sendJson(['error' => 'Method not allowed.'], 405);
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        sendJson(['error' => 'That SKU is already in use. Choose a unique SKU.'], 409);
    }
    handleApiFailure($exception);
} catch (Throwable $exception) {
    handleApiFailure($exception);
}

function productExists(PDO $pdo, int $id): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM products WHERE id = :id');
    $statement->execute(['id' => $id]);
    return (bool) $statement->fetchColumn();
}

function fetchProductImage(string $url): string
{
    $parts = parse_url($url);
    if (
        !is_array($parts)
        || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        sendJson(['error' => 'Image URL must use HTTP or HTTPS without embedded credentials.'], 422);
    }

    $scheme = strtolower($parts['scheme']);
    $host = strtolower(rtrim($parts['host'], '.'));
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
        sendJson(['error' => 'Image URLs can only use the standard HTTP or HTTPS ports.'], 422);
    }
    if (
        $host === ''
        || $host === 'localhost'
        || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.local')
        || str_ends_with($host, '.internal')
    ) {
        sendJson(['error' => 'Image URLs must point to a public internet host.'], 422);
    }

    $addresses = resolvePublicAddresses($host);
    if ($addresses === []) {
        sendJson(['error' => 'The image host could not be resolved to a public address.'], 422);
    }

    $imageBytes = '';
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('The image download could not be started.');
    }

    $resolveHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
    $resolveAddress = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_PROXY => '',
        CURLOPT_RESOLVE => [$resolveHost . ':' . $port . ':' . $resolveAddress],
        CURLOPT_USERAGENT => 'SmartStockPOS-ImageImporter/1.0',
        CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/jpeg,image/gif'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$imageBytes): int {
            if (strlen($imageBytes) + strlen($chunk) > 5 * 1024 * 1024) {
                return 0;
            }
            $imageBytes .= $chunk;
            return strlen($chunk);
        },
    ]);

    $downloaded = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    $downloadError = curl_error($curl);
    curl_close($curl);

    if ($downloaded === false) {
        if (strlen($imageBytes) >= 5 * 1024 * 1024) {
            sendJson(['error' => 'Image downloads must be 5 MB or smaller.'], 422);
        }
        error_log('Product image download failed: ' . $downloadError);
        sendJson(['error' => 'The image could not be downloaded. Check the URL and try again.'], 422);
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        sendJson(['error' => 'The image host returned HTTP ' . $httpStatus . '. Use a direct public image link.'], 422);
    }

    $imageInfo = getimagesizefromstring($imageBytes);
    $allowedTypes = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_GIF => ['image/gif', 'gif'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
    ];
    if ($imageBytes === '' || $imageInfo === false || !isset($allowedTypes[$imageInfo[2]])) {
        sendJson(['error' => 'The URL must point directly to a JPEG, PNG, GIF, or WebP image.'], 422);
    }

    $expectedMime = $allowedTypes[$imageInfo[2]][0];
    $responseMime = strtolower(trim(explode(';', $contentType)[0]));
    if (
        $responseMime !== ''
        && $responseMime !== $expectedMime
        && !in_array($responseMime, ['application/octet-stream', 'binary/octet-stream'], true)
    ) {
        sendJson(['error' => 'The image host returned a mismatched content type. Use a direct image URL.'], 422);
    }

    return $imageBytes;
}

function resolvePublicAddresses(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $addresses = [$host];
    } else {
        $addresses = [];
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }
    }

    if ($addresses === []) {
        return [];
    }
    foreach ($addresses as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            sendJson(['error' => 'Image URLs cannot resolve to private or reserved network addresses.'], 422);
        }
    }
    return array_values(array_unique($addresses));
}

function storeProductImage(string $imageBytes, string $directory): string
{
    $imageInfo = getimagesizefromstring($imageBytes);
    $extensions = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    if ($imageInfo === false || !isset($extensions[$imageInfo[2]])) {
        throw new RuntimeException('The downloaded image format is not supported.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$imageInfo[2]];
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    if (file_put_contents($path, $imageBytes, LOCK_EX) !== strlen($imageBytes)) {
        if (is_file($path)) {
            unlink($path);
        }
        throw new RuntimeException('The downloaded image could not be stored.');
    }
    return '../images/products/' . $filename;
}

function isManagedProductImage(mixed $imagePath): bool
{
    return is_string($imagePath)
        && preg_match('~^\.\./images/products/[a-f0-9]{32}\.(?:jpg|png|gif|webp)$~', $imagePath) === 1;
}

function removeManagedProductImage(mixed $imagePath, string $directory): void
{
    if (!isManagedProductImage($imagePath)) {
        return;
    }

    $path = $directory . DIRECTORY_SEPARATOR . basename($imagePath);
    if (is_file($path) && !unlink($path)) {
        error_log('Failed to remove managed product image: ' . basename($imagePath));
    }
}
