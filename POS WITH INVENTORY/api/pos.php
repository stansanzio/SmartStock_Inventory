<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const POS_TAX_RATE_PERCENT = 12;
const POS_ELIGIBLE_DISCOUNT_PERCENT = 20;
const POS_MAX_TOTAL_CENTS = 999999999999;

$employee = requireEmployee();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

try {
    $pdo = getDatabaseConnection();
    ensurePosSchema($pdo);

    if ($method === 'GET') {
        ensureAvatarSchema($pdo);
        $avatarStatement = $pdo->prepare('SELECT avatar_id FROM users WHERE id = :id');
        $avatarStatement->execute(['id' => $employee['id']]);
        $avatarId = (int) $avatarStatement->fetchColumn();
        $employeeOfMonth = getEmployeeOfMonth($pdo);
        $products = $pdo->query(
            "SELECT id, product_name, sku, price, stock, image_url, category
             FROM products
             WHERE status = 'active'
             ORDER BY product_name, id"
        )->fetchAll();
        $categories = $pdo->query(
            "SELECT DISTINCT category FROM products
             WHERE status = 'active' AND category <> ''
             ORDER BY category"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($products as &$product) {
            $product['id'] = (int) $product['id'];
            $product['stock'] = (int) $product['stock'];
            $product['category'] = trim((string) $product['category']) ?: 'Uncategorized';
        }
        unset($product);

        sendJson([
            'products' => $products,
            'categories' => $categories,
            'taxRatePercent' => POS_TAX_RATE_PERCENT,
            'employee' => [
                'displayName' => $employee['displayName'],
                'username' => $employee['username'],
                'role' => $employee['role'] === 'owner' ? 'Owner' : 'Employee',
                'isOwner' => $employee['role'] === 'owner',
                'isEmployeeOfMonth' => $employeeOfMonth !== null
                    && $employeeOfMonth['id'] === $employee['id'],
                'avatarUrl' => '../api/avatar-image.php?id=' . $avatarId,
            ],
            'csrfToken' => (string) ($_SESSION['csrf_token'] ?? ''),
        ]);
    }

    if ($method !== 'POST') {
        sendJson(['error' => 'Method not allowed.'], 405);
    }

    requireCsrfToken();
    $input = readJsonBody();
    $items = $input['items'] ?? null;
    $discountTypeInput = $input['discountType'] ?? '';
    $paymentMethod = $input['paymentMethod'] ?? null;
    $cashTenderedInput = $input['cashTendered'] ?? null;
    $cardTypeInput = $input['cardType'] ?? '';
    $cardLastFourInput = $input['cardLastFour'] ?? '';
    $authorizationReferenceInput = $input['authorizationReference'] ?? '';

    if (!is_array($items) || count($items) < 1 || count($items) > 100) {
        sendJson(['error' => 'Add between 1 and 100 products before checkout.'], 422);
    }
    if (!is_string($discountTypeInput) || !in_array($discountTypeInput, ['', 'pwd', 'senior'], true)) {
        sendJson(['error' => 'Select a valid PWD or Senior Citizen discount.'], 422);
    }
    $discountType = $discountTypeInput === '' ? null : $discountTypeInput;
    if (!is_string($paymentMethod) || !in_array($paymentMethod, ['cash', 'card'], true)) {
        sendJson(['error' => 'Choose cash or card as the payment method.'], 422);
    }
    if ($paymentMethod === 'card') {
        if (!is_string($cardTypeInput) || !is_string($cardLastFourInput) ||
            !is_string($authorizationReferenceInput)) {
            sendJson(['error' => 'The card terminal details are invalid.'], 422);
        }
        $cardType = trim($cardTypeInput);
        $cardLastFour = trim($cardLastFourInput);
        $authorizationReference = trim($authorizationReferenceInput);
        if (!in_array($cardType, ['Visa', 'Mastercard', 'American Express', 'JCB', 'Other'], true)) {
            sendJson(['error' => 'Select a valid card type.'], 422);
        }
        if (!preg_match('/^\d{4}$/', $cardLastFour)) {
            sendJson(['error' => 'Enter only the last four digits of the card.'], 422);
        }
        if ($authorizationReference === '' || strlen($authorizationReference) > 100 ||
            preg_match('/[\x00-\x1F\x7F]/', $authorizationReference)) {
            sendJson(['error' => 'Enter a valid approval or authorization reference from the card terminal.'], 422);
        }
    } elseif (!is_string($cashTenderedInput) ||
        !preg_match('/^\d+(?:\.\d{1,2})?$/', $cashTenderedInput)) {
        sendJson(['error' => 'Enter the cash received as an amount with up to two decimal places.'], 422);
    }

    $quantities = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            sendJson(['error' => 'One of the order items is invalid.'], 422);
        }
        $productId = filter_var($item['productId'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$productId || $productId < 1 || !$quantity || $quantity < 1 || $quantity > 2147483647) {
            sendJson(['error' => 'Each order item must have a valid product and positive whole-number quantity.'], 422);
        }
        $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
        if ($quantities[$productId] > 2147483647) {
            sendJson(['error' => 'An item quantity is too large to process.'], 422);
        }
    }
    ksort($quantities, SORT_NUMERIC);
    $productIds = array_keys($quantities);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));

    $pdo->beginTransaction();
    try {
        $productStatement = $pdo->prepare(
            "SELECT id, product_name, sku, category, price, stock, status
             FROM products WHERE id IN ($placeholders) ORDER BY id FOR UPDATE"
        );
        $productStatement->execute($productIds);
        $productsById = [];
        foreach ($productStatement->fetchAll() as $product) {
            $productsById[(int) $product['id']] = $product;
        }

        $saleItems = [];
        $subtotalCents = 0;
        foreach ($quantities as $productId => $quantity) {
            $product = $productsById[$productId] ?? null;
            if (!$product) {
                $pdo->rollBack();
                sendJson(['error' => 'A product in this order no longer exists. Refresh the products and try again.'], 409);
            }
            if ($product['status'] !== 'active') {
                $pdo->rollBack();
                sendJson(['error' => $product['product_name'] . ' is no longer available. Refresh the products and try again.'], 409);
            }
            if ((int) $product['stock'] < $quantity) {
                $pdo->rollBack();
                sendJson([
                    'error' => $product['product_name'] . ' has only ' . (int) $product['stock'] . ' left in stock. Refresh the products and adjust the order.',
                ], 409);
            }

            $unitPriceCents = moneyStringToCents((string) $product['price']);
            if ($unitPriceCents !== 0 && $quantity > intdiv(POS_MAX_TOTAL_CENTS, $unitPriceCents)) {
                $pdo->rollBack();
                sendJson(['error' => 'The order total exceeds the maximum supported amount.'], 422);
            }
            $lineTotalCents = $unitPriceCents * $quantity;
            if ($subtotalCents > POS_MAX_TOTAL_CENTS - $lineTotalCents) {
                $pdo->rollBack();
                sendJson(['error' => 'The order total exceeds the maximum supported amount.'], 422);
            }
            $subtotalCents += $lineTotalCents;
            $saleItems[] = [
                'productId' => (int) $productId,
                'productName' => (string) $product['product_name'],
                'sku' => (string) $product['sku'],
                'category' => trim((string) $product['category']) ?: 'Uncategorized',
                'quantity' => $quantity,
                'unitPriceCents' => $unitPriceCents,
                'lineTotalCents' => $lineTotalCents,
            ];
        }

        $discountAmountCents = $discountType === null
            ? 0
            : intdiv($subtotalCents * POS_ELIGIBLE_DISCOUNT_PERCENT + 50, 100);
        $taxCents = $discountType === null
            ? intdiv($subtotalCents * POS_TAX_RATE_PERCENT + 50, 100)
            : 0;
        $taxRatePercent = $discountType === null ? POS_TAX_RATE_PERCENT : 0;
        $totalCents = $subtotalCents - $discountAmountCents + $taxCents;
        if ($totalCents > POS_MAX_TOTAL_CENTS) {
            $pdo->rollBack();
            sendJson(['error' => 'The order total exceeds the maximum supported amount.'], 422);
        }
        $cashTenderedCents = null;
        $changeGivenCents = null;
        if ($paymentMethod === 'cash') {
            $cashTenderedCents = moneyStringToCents($cashTenderedInput);
            if ($cashTenderedCents > POS_MAX_TOTAL_CENTS) {
                $pdo->rollBack();
                sendJson(['error' => 'The cash received amount exceeds the maximum supported amount.'], 422);
            }
            if ($cashTenderedCents < $totalCents) {
                $pdo->rollBack();
                sendJson(['error' => 'Cash received must be equal to or greater than the order total.'], 422);
            }
            $changeGivenCents = $cashTenderedCents - $totalCents;
        }
        $insertSale = $pdo->prepare(
            'INSERT INTO sales
             (subtotal, discount_type, discount_percent, discount_amount, tax_rate, tax_amount, total, payment_method, cash_received, change_given,
              card_type, card_last_four, authorization_reference, cashier_name, cashier_avatar_id, cashier_id)
             VALUES
             (:subtotal, :discount_type, :discount_percent, :discount_amount, :tax_rate, :tax_amount, :total, :payment_method, :cash_received, :change_given,
              :card_type, :card_last_four, :authorization_reference, :cashier_name, :cashier_avatar_id, :cashier_id)'
        );
        $insertSale->execute([
            'subtotal' => centsToMoneyString($subtotalCents),
            'discount_type' => $discountType,
            'discount_percent' => number_format($discountType === null ? 0 : POS_ELIGIBLE_DISCOUNT_PERCENT, 2, '.', ''),
            'discount_amount' => centsToMoneyString($discountAmountCents),
            'tax_rate' => number_format($taxRatePercent, 2, '.', ''),
            'tax_amount' => centsToMoneyString($taxCents),
            'total' => centsToMoneyString($totalCents),
            'payment_method' => $paymentMethod,
            'cash_received' => $cashTenderedCents === null ? null : centsToMoneyString($cashTenderedCents),
            'change_given' => $changeGivenCents === null ? null : centsToMoneyString($changeGivenCents),
            'card_type' => $paymentMethod === 'card' ? $cardType : null,
            'card_last_four' => $paymentMethod === 'card' ? $cardLastFour : null,
            'authorization_reference' => $paymentMethod === 'card' ? $authorizationReference : null,
            'cashier_name' => $employee['displayName'],
            'cashier_avatar_id' => $employee['avatarId'],
            'cashier_id' => $employee['id'],
        ]);
        $saleId = (int) $pdo->lastInsertId();

        $insertItem = $pdo->prepare(
            'INSERT INTO sale_items
             (sale_id, product_id, product_name, sku, category, quantity, unit_price, line_total)
             VALUES (:sale_id, :product_id, :product_name, :sku, :category, :quantity, :unit_price, :line_total)'
        );
        $decrementStock = $pdo->prepare(
            'UPDATE products
             SET stock = stock - :decrement_quantity
             WHERE id = :id AND stock >= :minimum_quantity'
        );
        foreach ($saleItems as $saleItem) {
            $decrementStock->execute([
                'decrement_quantity' => $saleItem['quantity'],
                'id' => $saleItem['productId'],
                'minimum_quantity' => $saleItem['quantity'],
            ]);
            if ($decrementStock->rowCount() !== 1) {
                throw new RuntimeException('Inventory changed while this order was being processed.');
            }
            $insertItem->execute([
                'sale_id' => $saleId,
                'product_id' => $saleItem['productId'],
                'product_name' => $saleItem['productName'],
                'sku' => $saleItem['sku'],
                'category' => $saleItem['category'],
                'quantity' => $saleItem['quantity'],
                'unit_price' => centsToMoneyString($saleItem['unitPriceCents']),
                'line_total' => centsToMoneyString($saleItem['lineTotalCents']),
            ]);
        }

        $pdo->commit();
        $createdAtStatement = $pdo->prepare('SELECT created_at FROM sales WHERE id = :id');
        $createdAtStatement->execute(['id' => $saleId]);
        $saleCreatedAt = $createdAtStatement->fetchColumn();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    sendJson([
        'ok' => true,
        'sale' => [
            'id' => $saleId,
            'cashierName' => $employee['displayName'],
            'createdAt' => $saleCreatedAt,
            'subtotal' => centsToMoneyString($subtotalCents),
            'discountType' => $discountType,
            'discountPercent' => $discountType === null ? 0 : POS_ELIGIBLE_DISCOUNT_PERCENT,
            'discountAmount' => centsToMoneyString($discountAmountCents),
            'taxRatePercent' => $taxRatePercent,
            'taxAmount' => centsToMoneyString($taxCents),
            'total' => centsToMoneyString($totalCents),
            'paymentMethod' => $paymentMethod,
            'cashTendered' => $cashTenderedCents === null ? null : centsToMoneyString($cashTenderedCents),
            'changeGiven' => $changeGivenCents === null ? null : centsToMoneyString($changeGivenCents),
            'cardType' => $paymentMethod === 'card' ? $cardType : null,
            'cardLastFour' => $paymentMethod === 'card' ? $cardLastFour : null,
            'authorizationReference' => $paymentMethod === 'card' ? $authorizationReference : null,
            'items' => array_map(
                static fn(array $item): array => [
                    'productName' => $item['productName'],
                    'quantity' => $item['quantity'],
                    'unitPriceCents' => $item['unitPriceCents'],
                    'lineTotalCents' => $item['lineTotalCents'],
                ],
                $saleItems
            ),
        ],
    ], 201);
} catch (Throwable $exception) {
    handleApiFailure($exception);
}

function moneyStringToCents(string $amount): int
{
    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
        throw new RuntimeException('A product price in the database is invalid.');
    }
    return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
}

function centsToMoneyString(int $amount): string
{
    return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
}
