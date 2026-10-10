<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$admin = requireAdmin();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

try {
    $pdo = getDatabaseConnection();

    if ($method === 'GET') {
        $orderId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (isset($_GET['id']) && (!$orderId || $orderId < 1)) {
            sendJson(['error' => 'Select a valid purchase order.'], 422);
        }

        $sql = 'SELECT po.id, po.supplier_name, po.order_date, po.status, po.created_at,
                       COALESCE(SUM(poi.quantity * poi.unit_cost), 0) AS total_amount
                FROM purchase_orders po
                LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id';
        if ($orderId) {
            $sql .= ' WHERE po.id = :id';
        }
        $sql .= ' GROUP BY po.id ORDER BY po.order_date DESC, po.id DESC';
        $statement = $pdo->prepare($sql);
        $statement->execute($orderId ? ['id' => $orderId] : []);
        $orders = $statement->fetchAll();

        if ($orderId && !$orders) {
            sendJson(['error' => 'That purchase order does not exist.'], 404);
        }

        if ($orders) {
            $orderIds = array_map(static fn(array $order): int => (int) $order['id'], $orders);
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $itemsStatement = $pdo->prepare(
                "SELECT id, purchase_order_id, product_id, product_name, sku, quantity, unit_cost
                 FROM purchase_order_items
                 WHERE purchase_order_id IN ($placeholders)
                 ORDER BY id"
            );
            $itemsStatement->execute($orderIds);
            $itemsByOrder = [];
            foreach ($itemsStatement->fetchAll() as $item) {
                $item['id'] = (int) $item['id'];
                $item['purchase_order_id'] = (int) $item['purchase_order_id'];
                $item['product_id'] = $item['product_id'] === null ? null : (int) $item['product_id'];
                $item['quantity'] = (int) $item['quantity'];
                $itemsByOrder[$item['purchase_order_id']][] = $item;
            }

            foreach ($orders as &$order) {
                $order['id'] = (int) $order['id'];
                $order['items'] = $itemsByOrder[$order['id']] ?? [];
            }
            unset($order);
        }

        sendJson($orderId ? ['order' => $orders[0]] : ['orders' => $orders]);
    }

    requireCsrfToken();

    if ($method === 'POST') {
        $input = readJsonBody();
        $action = (string) ($input['action'] ?? '');

        if (in_array($action, ['receive', 'cancel'], true)) {
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) {
                sendJson(['error' => 'Select a valid purchase order.'], 422);
            }

            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare('SELECT id, status FROM purchase_orders WHERE id = :id FOR UPDATE');
                $statement->execute(['id' => $id]);
                $order = $statement->fetch();
                if (!$order) {
                    $pdo->rollBack();
                    sendJson(['error' => 'That purchase order does not exist.'], 404);
                }
                if ($order['status'] !== 'in_transit') {
                    $pdo->rollBack();
                    sendJson(['error' => 'Only an order in transit can be received or cancelled.'], 409);
                }

                if ($action === 'receive') {
                    $itemsStatement = $pdo->prepare(
                        'SELECT product_id, quantity FROM purchase_order_items
                         WHERE purchase_order_id = :id AND product_id IS NOT NULL'
                    );
                    $itemsStatement->execute(['id' => $id]);
                    $updateStock = $pdo->prepare(
                        'UPDATE products SET stock = stock + :quantity WHERE id = :product_id'
                    );
                    foreach ($itemsStatement->fetchAll() as $item) {
                        $updateStock->execute([
                            'quantity' => (int) $item['quantity'],
                            'product_id' => (int) $item['product_id'],
                        ]);
                        if ($updateStock->rowCount() !== 1) {
                            throw new RuntimeException('A product in this order could not be updated.');
                        }
                    }
                    $newStatus = 'received';
                } else {
                    $newStatus = 'cancelled';
                }

                $updateOrder = $pdo->prepare('UPDATE purchase_orders SET status = :status WHERE id = :id');
                $updateOrder->execute(['status' => $newStatus, 'id' => $id]);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }

            sendJson(['ok' => true, 'status' => $newStatus]);
        }

        $id = !array_key_exists('id', $input) || $input['id'] === null || $input['id'] === ''
            ? null
            : filter_var($input['id'], FILTER_VALIDATE_INT);
        $supplier = trim((string) ($input['supplierName'] ?? ''));
        $orderDate = (string) ($input['orderDate'] ?? '');
        $items = $input['items'] ?? null;

        if ($id === false || ($id !== null && $id < 1)) {
            sendJson(['error' => 'Select a valid purchase order to edit.'], 422);
        }
        if ($supplier === '' || mb_strlen($supplier) > 150) {
            sendJson(['error' => 'Enter a supplier name up to 150 characters long.'], 422);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $orderDate);
        if (!$date || $date->format('Y-m-d') !== $orderDate) {
            sendJson(['error' => 'Enter a valid order date.'], 422);
        }
        if (!is_array($items) || count($items) < 1 || count($items) > 100) {
            sendJson(['error' => 'Add between 1 and 100 products to the purchase order.'], 422);
        }

        $normalizedItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                sendJson(['error' => 'One of the purchase order items is invalid.'], 422);
            }
            $productId = filter_var($item['productId'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            $unitCost = filter_var($item['unitCost'] ?? null, FILTER_VALIDATE_FLOAT);
            if (!$productId || $productId < 1 || !$quantity || $quantity < 1 || $quantity > 2147483647) {
                sendJson(['error' => 'Each line must have a product and a quantity greater than zero.'], 422);
            }
            if ($unitCost === false || !is_finite((float) $unitCost) || $unitCost < 0 || $unitCost > 99999999.99) {
                sendJson(['error' => 'Enter a valid unit cost between 0 and 99,999,999.99.'], 422);
            }
            $normalizedItems[] = [
                'productId' => $productId,
                'quantity' => $quantity,
                'unitCost' => number_format((float) $unitCost, 2, '.', ''),
            ];
        }

        $pdo->beginTransaction();
        try {
            if ($id !== null) {
                $statement = $pdo->prepare('SELECT status FROM purchase_orders WHERE id = :id FOR UPDATE');
                $statement->execute(['id' => $id]);
                $order = $statement->fetch();
                if (!$order) {
                    $pdo->rollBack();
                    sendJson(['error' => 'That purchase order does not exist.'], 404);
                }
                if ($order['status'] !== 'in_transit') {
                    $pdo->rollBack();
                    sendJson(['error' => 'Only orders in transit can be edited.'], 409);
                }

                $update = $pdo->prepare(
                    'UPDATE purchase_orders SET supplier_name = :supplier_name, order_date = :order_date WHERE id = :id'
                );
                $update->execute(['supplier_name' => $supplier, 'order_date' => $orderDate, 'id' => $id]);
                $deleteItems = $pdo->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = :id');
                $deleteItems->execute(['id' => $id]);
                $purchaseOrderId = $id;
            } else {
                $insertOrder = $pdo->prepare(
                    'INSERT INTO purchase_orders (supplier_name, order_date, created_by)
                     VALUES (:supplier_name, :order_date, :created_by)'
                );
                $insertOrder->execute([
                    'supplier_name' => $supplier,
                    'order_date' => $orderDate,
                    'created_by' => $admin['id'],
                ]);
                $purchaseOrderId = (int) $pdo->lastInsertId();
            }

            $productStatement = $pdo->prepare(
                'SELECT product_name, sku FROM products WHERE id = :id'
            );
            $insertItem = $pdo->prepare(
                'INSERT INTO purchase_order_items
                 (purchase_order_id, product_id, product_name, sku, quantity, unit_cost)
                 VALUES (:purchase_order_id, :product_id, :product_name, :sku, :quantity, :unit_cost)'
            );
            foreach ($normalizedItems as $item) {
                $productStatement->execute(['id' => $item['productId']]);
                $product = $productStatement->fetch();
                if (!$product) {
                    $pdo->rollBack();
                    sendJson(['error' => 'A selected product no longer exists. Refresh the page and try again.'], 409);
                }
                $insertItem->execute([
                    'purchase_order_id' => $purchaseOrderId,
                    'product_id' => $item['productId'],
                    'product_name' => $product['product_name'],
                    'sku' => $product['sku'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unitCost'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        sendJson(['ok' => true, 'id' => $purchaseOrderId], $id === null ? 201 : 200);
    }

    sendJson(['error' => 'Method not allowed.'], 405);
} catch (Throwable $exception) {
    handleApiFailure($exception);
}
