<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

requireAdmin();

function salesHistoryDate(string $value, string $field): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ||
        $date->format('Y-m-d') !== $value) {
        sendJson(['error' => 'Enter a valid ' . $field . ' date.'], 422);
    }
    return $date;
}

try {
    $pdo = getDatabaseConnection();
    ensurePosSchema($pdo);

    $saleId = filter_var($_GET['saleId'] ?? null, FILTER_VALIDATE_INT);
    if ($saleId !== false && $saleId !== null) {
        if ($saleId < 1) {
            sendJson(['error' => 'Select a valid sale.'], 422);
        }

        $saleStatement = $pdo->prepare(
                'SELECT s.id, s.subtotal, s.discount_type, s.discount_percent, s.discount_amount,
                    s.tax_rate, s.tax_amount, s.total, s.payment_method,
                    s.cash_received, s.change_given, s.card_type, s.card_last_four,
                    s.authorization_reference, s.created_at,
                    COALESCE(s.cashier_name, u.display_name) AS cashier_name,
                    COALESCE(s.cashier_avatar_id, u.avatar_id) AS cashier_avatar_id
             FROM sales s
             LEFT JOIN users u ON u.id = s.cashier_id
             WHERE s.id = :sale_id
             LIMIT 1'
        );
        $saleStatement->execute(['sale_id' => $saleId]);
        $sale = $saleStatement->fetch();
        if (!$sale) {
            sendJson(['error' => 'The requested sale could not be found.'], 404);
        }

        $itemStatement = $pdo->prepare(
            'SELECT product_name, sku, category, quantity, unit_price, line_total
             FROM sale_items
             WHERE sale_id = :sale_id
             ORDER BY id'
        );
        $itemStatement->execute(['sale_id' => $saleId]);
        sendJson([
            'sale' => [
                'id' => (int) $sale['id'],
                'subtotal' => (string) $sale['subtotal'],
                'discountType' => $sale['discount_type'],
                'discountPercent' => (string) $sale['discount_percent'],
                'discountAmount' => (string) $sale['discount_amount'],
                'taxRate' => (string) $sale['tax_rate'],
                'taxAmount' => (string) $sale['tax_amount'],
                'total' => (string) $sale['total'],
                'paymentMethod' => (string) $sale['payment_method'],
                'cashReceived' => $sale['cash_received'] === null ? null : (string) $sale['cash_received'],
                'changeGiven' => $sale['change_given'] === null ? null : (string) $sale['change_given'],
                'cardType' => $sale['card_type'],
                'cardLastFour' => $sale['card_last_four'],
                'authorizationReference' => $sale['authorization_reference'],
                'createdAt' => (string) $sale['created_at'],
                'cashierName' => $sale['cashier_name'] ?: 'Unknown employee',
                'cashierAvatarUrl' => $sale['cashier_avatar_id']
                    ? '../api/avatar-image.php?id=' . (int) $sale['cashier_avatar_id']
                    : null,
                'items' => $itemStatement->fetchAll(),
            ],
        ]);
    }

    foreach (['product', 'cashier', 'paymentMethod', 'from', 'to'] as $queryField) {
        if (isset($_GET[$queryField]) && !is_string($_GET[$queryField])) {
            sendJson(['error' => 'Sales history filters must be single values.'], 422);
        }
    }
    $productSearch = trim($_GET['product'] ?? '');
    $cashierSearch = trim($_GET['cashier'] ?? '');
    $paymentMethod = $_GET['paymentMethod'] ?? '';
    $fromDate = trim($_GET['from'] ?? '');
    $toDate = trim($_GET['to'] ?? '');

    if (strlen($productSearch) > 150 || strlen($cashierSearch) > 100) {
        sendJson(['error' => 'Search text is too long.'], 422);
    }
    if ($paymentMethod !== '' && !in_array($paymentMethod, ['cash', 'card'], true)) {
        sendJson(['error' => 'Select a valid payment method.'], 422);
    }
    if (($fromDate === '') !== ($toDate === '')) {
        sendJson(['error' => 'Choose both a start and end date.'], 422);
    }

    $conditions = [];
    $parameters = [];
    if ($productSearch !== '') {
        $conditions[] = '(si.product_name LIKE :product_name OR si.sku LIKE :sku)';
        $parameters['product_name'] = '%' . $productSearch . '%';
        $parameters['sku'] = '%' . $productSearch . '%';
    }
    if ($cashierSearch !== '') {
        $conditions[] = 'COALESCE(s.cashier_name, u.display_name, \'Unknown employee\') LIKE :cashier_name';
        $parameters['cashier_name'] = '%' . $cashierSearch . '%';
    }
    if ($paymentMethod !== '') {
        $conditions[] = 's.payment_method = :payment_method';
        $parameters['payment_method'] = $paymentMethod;
    }
    if ($fromDate !== '' && $toDate !== '') {
        $from = salesHistoryDate($fromDate, 'start');
        $to = salesHistoryDate($toDate, 'end');
        if ($to < $from) {
            sendJson(['error' => 'The end date cannot be earlier than the start date.'], 422);
        }
        $conditions[] = 's.created_at >= :start_at AND s.created_at < :end_at';
        $parameters['start_at'] = $from->format('Y-m-d H:i:s');
        $parameters['end_at'] = $to->modify('+1 day')->format('Y-m-d H:i:s');
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $statement = $pdo->prepare(
        "SELECT si.id AS item_id, s.id AS sale_id, si.product_name, si.sku, si.category,
                si.quantity, si.unit_price, si.line_total, s.total AS sale_total,
                s.payment_method, s.cash_received, s.change_given, s.card_type,
                s.card_last_four, s.authorization_reference, s.created_at,
                COALESCE(s.cashier_name, u.display_name) AS cashier_name,
                COALESCE(s.cashier_avatar_id, u.avatar_id) AS cashier_avatar_id
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         LEFT JOIN users u ON u.id = s.cashier_id
         $where
         ORDER BY s.created_at DESC, s.id DESC, si.id
         LIMIT 501"
    );
    $statement->execute($parameters);
    $rows = $statement->fetchAll();
    $limited = count($rows) > 500;
    if ($limited) {
        array_pop($rows);
    }

    foreach ($rows as &$row) {
        $row['item_id'] = (int) $row['item_id'];
        $row['sale_id'] = (int) $row['sale_id'];
        $row['quantity'] = (int) $row['quantity'];
        $row['cashier_name'] = $row['cashier_name'] ?: 'Unknown employee';
        $row['avatar_url'] = $row['cashier_avatar_id']
            ? '../api/avatar-image.php?id=' . (int) $row['cashier_avatar_id']
            : null;
    }
    unset($row);

    sendJson([
        'records' => $rows,
        'limit' => 500,
        'truncated' => $limited,
        'updatedAt' => date(DATE_ATOM),
    ]);
} catch (Throwable $exception) {
    handleApiFailure($exception);
}
