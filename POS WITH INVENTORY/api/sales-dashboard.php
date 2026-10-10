<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

requireAdmin();
$range = (string) ($_GET['range'] ?? '12');
$ranges = ['12', '30', '7', '24'];
if (!in_array($range, $ranges, true)) {
    sendJson(['error' => 'Select a valid sales chart range.'], 422);
}

try {
    $pdo = getDatabaseConnection();
    ensurePosSchema($pdo);
    $employeeOfMonth = getEmployeeOfMonth($pdo);
    $now = new DateTimeImmutable('now');

    if ($range === '12') {
        $start = $now->modify('first day of this month')->setTime(0, 0)->modify('-11 months');
        $end = $now->modify('first day of next month')->setTime(0, 0);
        $periodExpression = "DATE_FORMAT(created_at, '%Y-%m')";
        $labels = [];
        for ($index = 0; $index < 12; $index++) {
            $period = $start->modify('+' . $index . ' months');
            $labels[$period->format('Y-m')] = $period->format('M');
        }
        $caption = 'Last 12 months';
    } elseif ($range === '30') {
        $start = $now->setTime(0, 0)->modify('-29 days');
        $end = $now->modify('+1 day')->setTime(0, 0);
        $periodExpression = 'DATE(created_at)';
        $labels = [];
        for ($index = 0; $index < 30; $index++) {
            $period = $start->modify('+' . $index . ' days');
            $labels[$period->format('Y-m-d')] = $period->format('j');
        }
        $caption = 'Last 30 days';
    } elseif ($range === '7') {
        $start = $now->setTime(0, 0)->modify('-6 days');
        $end = $now->modify('+1 day')->setTime(0, 0);
        $periodExpression = 'DATE(created_at)';
        $labels = [];
        for ($index = 0; $index < 7; $index++) {
            $period = $start->modify('+' . $index . ' days');
            $labels[$period->format('Y-m-d')] = $period->format('D');
        }
        $caption = 'Last 7 days';
    } else {
        $start = $now->setTime((int) $now->format('H'), 0)->modify('-23 hours');
        $end = $start->modify('+24 hours');
        $periodExpression = "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')";
        $labels = [];
        for ($index = 0; $index < 24; $index++) {
            $period = $start->modify('+' . $index . ' hours');
            $labels[$period->format('Y-m-d H:00:00')] = $period->format('g A');
        }
        $caption = 'Last 24 hours';
    }

    $chartStatement = $pdo->prepare(
        "SELECT $periodExpression AS period, SUM(total) AS revenue, COUNT(*) AS orders
         FROM sales
         WHERE created_at >= :start_at AND created_at < :end_at
         GROUP BY period"
    );
    $chartStatement->execute([
        'start_at' => $start->format('Y-m-d H:i:s'),
        'end_at' => $end->format('Y-m-d H:i:s'),
    ]);
    $periods = [];
    foreach ($chartStatement->fetchAll() as $row) {
        $periods[(string) $row['period']] = [
            'revenue' => (float) $row['revenue'],
            'orders' => (int) $row['orders'],
        ];
    }

    $chart = [];
    foreach ($labels as $key => $label) {
        $chart[] = [
            'label' => $label,
            'revenue' => $periods[$key]['revenue'] ?? 0,
            'orders' => $periods[$key]['orders'] ?? 0,
        ];
    }

    $totalsStatement = $pdo->prepare(
        'SELECT COUNT(*) AS order_count, COALESCE(SUM(total), 0) AS revenue
         FROM sales WHERE created_at >= :start_at AND created_at < :end_at'
    );
    $totalsStatement->execute([
        'start_at' => $start->format('Y-m-d H:i:s'),
        'end_at' => $end->format('Y-m-d H:i:s'),
    ]);
    $totals = $totalsStatement->fetch();

    $currentMonthStart = $now->modify('first day of this month')->setTime(0, 0);
    $previousMonthStart = $currentMonthStart->modify('-1 month');
    $monthTotalsStatement = $pdo->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN created_at >= :current_start THEN total ELSE 0 END), 0) AS current_revenue,
            COALESCE(SUM(CASE WHEN created_at >= :previous_start AND created_at < :current_start_again THEN total ELSE 0 END), 0) AS previous_revenue
         FROM sales
         WHERE created_at >= :previous_start_filter AND created_at < :next_month'
    );
    $monthTotalsStatement->execute([
        'current_start' => $currentMonthStart->format('Y-m-d H:i:s'),
        'current_start_again' => $currentMonthStart->format('Y-m-d H:i:s'),
        'previous_start' => $previousMonthStart->format('Y-m-d H:i:s'),
        'previous_start_filter' => $previousMonthStart->format('Y-m-d H:i:s'),
        'next_month' => $currentMonthStart->modify('+1 month')->format('Y-m-d H:i:s'),
    ]);
    $monthTotals = $monthTotalsStatement->fetch();
    $currentMonthRevenue = (float) $monthTotals['current_revenue'];
    $previousMonthRevenue = (float) $monthTotals['previous_revenue'];
    $revenueChangePercent = $previousMonthRevenue > 0
        ? (($currentMonthRevenue - $previousMonthRevenue) / $previousMonthRevenue) * 100
        : null;

    $productStatement = $pdo->prepare(
        "SELECT si.product_id, si.product_name, SUM(si.quantity) AS units_sold,
                SUM(si.line_total) AS revenue, COALESCE(p.image_url, '') AS image_url
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         LEFT JOIN products p ON p.id = si.product_id
         WHERE s.created_at >= :start_at AND s.created_at < :end_at
         GROUP BY si.product_id, si.product_name, p.image_url
         ORDER BY units_sold DESC, revenue DESC, si.product_name
         LIMIT 5"
    );
    $productStatement->execute([
        'start_at' => $start->format('Y-m-d H:i:s'),
        'end_at' => $end->format('Y-m-d H:i:s'),
    ]);

    $products = $productStatement->fetchAll();
    foreach ($products as &$product) {
        $product['units_sold'] = (int) $product['units_sold'];
        $product['revenue'] = (float) $product['revenue'];
        $product['image_url'] = preg_match(
            '~^\.\./images/products/[a-f0-9]{32}\.(?:jpg|png|gif|webp)$~',
            (string) $product['image_url']
        ) === 1 ? $product['image_url'] : '';
    }
    unset($product);

    $productRevenueStatement = $pdo->prepare(
        'SELECT COALESCE(SUM(si.line_total), 0)
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         WHERE s.created_at >= :start_at AND s.created_at < :end_at'
    );
    $productRevenueStatement->execute([
        'start_at' => $start->format('Y-m-d H:i:s'),
        'end_at' => $end->format('Y-m-d H:i:s'),
    ]);
    $productRevenueTotal = (float) $productRevenueStatement->fetchColumn();
    $productUnitsStatement = $pdo->prepare(
        'SELECT COALESCE(SUM(si.quantity), 0)
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         WHERE s.created_at >= :start_at AND s.created_at < :end_at'
    );
    $productUnitsStatement->execute([
        'start_at' => $start->format('Y-m-d H:i:s'),
        'end_at' => $end->format('Y-m-d H:i:s'),
    ]);
    $topFiveUnits = array_sum(array_column($products, 'units_sold'));
    $otherProductUnits = max(0, (int) $productUnitsStatement->fetchColumn() - $topFiveUnits);
    $topFiveRevenue = array_sum(array_column($products, 'revenue'));
    $otherProductRevenue = max(0, $productRevenueTotal - $topFiveRevenue);

    sendJson([
        'range' => $range,
        'caption' => $caption,
        'chart' => $chart,
        'revenue' => (float) $totals['revenue'],
        'orderCount' => (int) $totals['order_count'],
        'averageOrderValue' => (int) $totals['order_count'] > 0
            ? (float) $totals['revenue'] / (int) $totals['order_count']
            : 0,
        'currentMonthRevenue' => $currentMonthRevenue,
        'previousMonthRevenue' => $previousMonthRevenue,
        'monthOverMonthChangePercent' => $revenueChangePercent,
        'products' => $products,
        'productRevenueTotal' => $productRevenueTotal,
        'otherProductUnits' => $otherProductUnits,
        'otherProductRevenue' => $otherProductRevenue,
        'employeeOfMonth' => $employeeOfMonth,
        'updatedAt' => date(DATE_ATOM),
    ]);
} catch (Throwable $exception) {
    handleApiFailure($exception);
}
