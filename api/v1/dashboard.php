<?php

declare(strict_types=1);

use Karoor\Core\Helpers;
use Karoor\Core\Response;

if (!defined('KAROOR_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

$auth->requirePermission('dashboard.view');
if (Helpers::requestMethod() !== 'GET') {
    header('Allow: GET');
    Response::error('The dashboard API only accepts GET requests.', [], 405);
}

$dashboardUser = $auth->requireAuth();
$dashboardCompanyId = (int) $dashboardUser['company_id'];

/** @return array{start: string, end: string, period: string, group: string} */
function karoor_dashboard_range(): array
{
    $period = is_string($_GET['period'] ?? null) ? strtolower($_GET['period']) : 'daily';
    if (!in_array($period, ['daily', 'weekly', 'monthly', 'yearly', 'custom'], true)) {
        Response::validation(['period' => ['Period must be daily, weekly, monthly, yearly, or custom.']]);
    }
    $today = new DateTimeImmutable('today');
    $start = match ($period) {
        'daily' => $today->modify('-29 days'),
        'weekly' => $today->modify('monday this week')->modify('-11 weeks'),
        'monthly' => $today->modify('first day of this month')->modify('-11 months'),
        'yearly' => $today->modify('first day of january')->modify('-4 years'),
        default => null,
    };
    $end = $today;
    if ($period === 'custom') {
        $startValue = is_string($_GET['start_date'] ?? null) ? $_GET['start_date'] : '';
        $endValue = is_string($_GET['end_date'] ?? null) ? $_GET['end_date'] : '';
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startValue) ?: null;
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endValue) ?: null;
        if ($start === null || $end === null || $start->format('Y-m-d') !== $startValue || $end->format('Y-m-d') !== $endValue) {
            Response::validation(['date_range' => ['A valid start_date and end_date are required for a custom period.']]);
        }
        $days = (int) $start->diff($end)->format('%r%a');
        if ($days < 0 || $days > 731) {
            Response::validation(['date_range' => ['The date range must be chronological and no longer than two years.']]);
        }
    }
    $days = (int) $start->diff($end)->format('%a');
    $group = match ($period) {
        'weekly' => "DATE_FORMAT(DATE_SUB(DATE(%s), INTERVAL WEEKDAY(%s) DAY), '%%Y-%%m-%%d')",
        'monthly' => "DATE_FORMAT(%s, '%%Y-%%m')",
        'yearly' => "DATE_FORMAT(%s, '%%Y')",
        'custom' => $days > 92 ? "DATE_FORMAT(%s, '%%Y-%%m')" : "DATE_FORMAT(%s, '%%Y-%%m-%%d')",
        default => "DATE_FORMAT(%s, '%%Y-%%m-%%d')",
    };
    return [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'period' => $period,
        'group' => $group,
    ];
}

/** @param array<string, float> ...$series
 *  @return list<string>
 */
function karoor_dashboard_labels(array ...$series): array
{
    $labels = [];
    foreach ($series as $values) {
        $labels = array_merge($labels, array_keys($values));
    }
    $labels = array_values(array_unique($labels));
    sort($labels);
    return $labels;
}

/** @param list<array<string, mixed>> $rows
 *  @return array<string, float>
 */
function karoor_dashboard_series(array $rows): array
{
    $series = [];
    foreach ($rows as $row) {
        $series[(string) $row['label']] = round((float) $row['value'], 4);
    }
    return $series;
}

/** @param array<string, float> $series
 *  @param list<string> $labels
 *  @return list<float>
 */
function karoor_dashboard_values(array $series, array $labels): array
{
    return array_map(static fn (string $label): float => $series[$label] ?? 0.0, $labels);
}

/** @return array{value: float, previous: ?float, change_percent: ?float, trend: string, comparison_available: bool} */
function karoor_dashboard_kpi(float $value, ?float $previous): array
{
    $change = null;
    if ($previous !== null && abs($previous) > 0.00001) {
        $change = round((($value - $previous) / abs($previous)) * 100, 2);
    } elseif ($previous !== null && abs($value) < 0.00001) {
        $change = 0.0;
    }
    return [
        'value' => round($value, 4),
        'previous' => $previous === null ? null : round($previous, 4),
        'change_percent' => $change,
        'trend' => $previous === null || abs($value - $previous) < 0.00001 ? 'stable' : ($value > $previous ? 'up' : 'down'),
        'comparison_available' => $previous !== null,
    ];
}

/** @return array<string, mixed> */
function karoor_dashboard_chart_meta(array $range): array
{
    return ['period' => $range['period'], 'start_date' => $range['start'], 'end_date' => $range['end']];
}

$action = $apiAction === 'index' ? 'summary' : $apiAction;
if ($action === 'summary') {
    $row = $database->fetchOne(
        'SELECT
            COALESCE(SUM(CASE WHEN DATE(sale_date) = CURRENT_DATE AND status = \'COMPLETED\' THEN total_amount ELSE 0 END), 0) AS sales_today,
            COALESCE(SUM(CASE WHEN DATE(sale_date) = CURRENT_DATE - INTERVAL 1 DAY AND status = \'COMPLETED\' THEN total_amount ELSE 0 END), 0) AS sales_previous
         FROM sales
         WHERE company_id = :company_id AND deleted_at IS NULL AND sale_date >= CURRENT_DATE - INTERVAL 1 DAY',
        ['company_id' => $dashboardCompanyId]
    ) ?? [];
    $purchaseRow = $database->fetchOne(
        'SELECT
            COALESCE(SUM(CASE WHEN DATE(purchase_date) = CURRENT_DATE AND status = \'RECEIVED\' THEN total_amount ELSE 0 END), 0) AS purchases_today,
            COALESCE(SUM(CASE WHEN DATE(purchase_date) = CURRENT_DATE - INTERVAL 1 DAY AND status = \'RECEIVED\' THEN total_amount ELSE 0 END), 0) AS purchases_previous
         FROM purchases
         WHERE company_id = :company_id AND deleted_at IS NULL AND purchase_date >= CURRENT_DATE - INTERVAL 1 DAY',
        ['company_id' => $dashboardCompanyId]
    ) ?? [];
    $expenseRow = $database->fetchOne(
        'SELECT
            COALESCE(SUM(CASE WHEN expense_date = CURRENT_DATE THEN amount ELSE 0 END), 0) AS expenses_today,
            COALESCE(SUM(CASE WHEN expense_date = CURRENT_DATE - INTERVAL 1 DAY THEN amount ELSE 0 END), 0) AS expenses_previous
         FROM expenses
         WHERE company_id = :company_id AND deleted_at IS NULL AND expense_date >= CURRENT_DATE - INTERVAL 1 DAY',
        ['company_id' => $dashboardCompanyId]
    ) ?? [];
    $profitRow = $database->fetchOne(
        'SELECT
            COALESCE(SUM(CASE WHEN DATE(s.sale_date) = CURRENT_DATE THEN (si.line_total - si.tax_amount) - (si.quantity * si.cost_price) ELSE 0 END), 0) AS gross_today,
            COALESCE(SUM(CASE WHEN DATE(s.sale_date) = CURRENT_DATE - INTERVAL 1 DAY THEN (si.line_total - si.tax_amount) - (si.quantity * si.cost_price) ELSE 0 END), 0) AS gross_previous
         FROM sales s
         INNER JOIN sale_items si ON si.sale_id = s.id
         WHERE s.company_id = :company_id AND s.status = \'COMPLETED\' AND s.deleted_at IS NULL
           AND s.sale_date >= CURRENT_DATE - INTERVAL 1 DAY',
        ['company_id' => $dashboardCompanyId]
    ) ?? [];
    $snapshots = $database->fetchOne(
        'SELECT
            (SELECT COALESCE(SUM(due_amount), 0) FROM sales WHERE company_id = :sales_company AND status = \'COMPLETED\' AND deleted_at IS NULL) AS receivables,
            (SELECT COALESCE(SUM(due_amount), 0) FROM purchases WHERE company_id = :purchase_company AND status = \'RECEIVED\' AND deleted_at IS NULL) AS payables,
            (SELECT COALESCE(SUM(wi.quantity * wi.average_cost), 0)
             FROM warehouse_inventory wi INNER JOIN warehouses w ON w.id = wi.warehouse_id
             WHERE w.company_id = :stock_company AND w.deleted_at IS NULL) AS stock_value,
            (SELECT COUNT(*) FROM (
                SELECT p.id
                FROM products p
                LEFT JOIN warehouse_inventory wi ON wi.product_id = p.id
                LEFT JOIN warehouses w ON w.id = wi.warehouse_id AND w.company_id = p.company_id AND w.deleted_at IS NULL
                WHERE p.company_id = :low_company AND p.track_inventory = 1 AND p.is_active = 1 AND p.deleted_at IS NULL
                GROUP BY p.id, p.minimum_stock
                HAVING COALESCE(SUM(CASE WHEN w.id IS NOT NULL THEN wi.quantity - wi.reserved_quantity ELSE 0 END), 0) <= p.minimum_stock
            ) low_items) AS low_stock',
        [
            'sales_company' => $dashboardCompanyId,
            'purchase_company' => $dashboardCompanyId,
            'stock_company' => $dashboardCompanyId,
            'low_company' => $dashboardCompanyId,
        ]
    ) ?? [];

    $profitToday = (float) ($profitRow['gross_today'] ?? 0) - (float) ($expenseRow['expenses_today'] ?? 0);
    $profitPrevious = (float) ($profitRow['gross_previous'] ?? 0) - (float) ($expenseRow['expenses_previous'] ?? 0);
    $quickActions = [];
    foreach ([
        ['permission' => 'sales.create', 'label' => 'New Sale', 'url' => '/sales?new=1'],
        ['permission' => 'purchases.create', 'label' => 'New Purchase', 'url' => '/purchases?new=1'],
        ['permission' => 'inventory.create', 'label' => 'Add Product', 'url' => '/inventory?view=products&new=1'],
        ['permission' => 'customers.create', 'label' => 'Add Customer', 'url' => '/contacts?view=customers&new=1'],
        ['permission' => 'suppliers.create', 'label' => 'Add Supplier', 'url' => '/contacts?view=suppliers&new=1'],
        ['permission' => 'inventory.transfer', 'label' => 'Stock Transfer', 'url' => '/inventory?view=transfers&new=1'],
        ['permission' => 'expenses.create', 'label' => 'Add Expense', 'url' => '/expenses?new=1'],
    ] as $quickAction) {
        if ($auth->can($quickAction['permission'])) {
            $quickActions[] = ['label' => $quickAction['label'], 'url' => Helpers::appPath($config, $quickAction['url'])];
        }
    }
    Response::success([
        'kpis' => [
            'today_sales' => karoor_dashboard_kpi((float) ($row['sales_today'] ?? 0), (float) ($row['sales_previous'] ?? 0)),
            'today_purchases' => karoor_dashboard_kpi((float) ($purchaseRow['purchases_today'] ?? 0), (float) ($purchaseRow['purchases_previous'] ?? 0)),
            'today_expenses' => karoor_dashboard_kpi((float) ($expenseRow['expenses_today'] ?? 0), (float) ($expenseRow['expenses_previous'] ?? 0)),
            'net_profit' => karoor_dashboard_kpi($profitToday, $profitPrevious),
            'receivables' => karoor_dashboard_kpi((float) ($snapshots['receivables'] ?? 0), null),
            'payables' => karoor_dashboard_kpi((float) ($snapshots['payables'] ?? 0), null),
            'stock_value' => karoor_dashboard_kpi((float) ($snapshots['stock_value'] ?? 0), null),
            'low_stock_items' => karoor_dashboard_kpi((float) ($snapshots['low_stock'] ?? 0), null),
        ],
        'quick_actions' => $quickActions,
        'currency' => (string) $dashboardUser['currency_code'],
        'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    ]);
}

$range = karoor_dashboard_range();
$parameters = ['company_id' => $dashboardCompanyId, 'start_date' => $range['start'], 'end_date' => $range['end']];

if ($action === 'sales-purchases') {
    $salesGroup = sprintf($range['group'], 'sale_date', 'sale_date');
    $purchaseGroup = sprintf($range['group'], 'purchase_date', 'purchase_date');
    $sales = karoor_dashboard_series($database->fetchAll(
        "SELECT {$salesGroup} AS label, COALESCE(SUM(total_amount), 0) AS value
         FROM sales WHERE company_id = :company_id AND status = 'COMPLETED' AND deleted_at IS NULL
           AND sale_date >= :start_date AND sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
         GROUP BY label ORDER BY label",
        $parameters
    ));
    $purchases = karoor_dashboard_series($database->fetchAll(
        "SELECT {$purchaseGroup} AS label, COALESCE(SUM(total_amount), 0) AS value
         FROM purchases WHERE company_id = :company_id AND status = 'RECEIVED' AND deleted_at IS NULL
           AND purchase_date >= :start_date AND purchase_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
         GROUP BY label ORDER BY label",
        $parameters
    ));
    $labels = karoor_dashboard_labels($sales, $purchases);
    Response::success(['labels' => $labels, 'datasets' => [
        ['label' => 'Sales', 'data' => karoor_dashboard_values($sales, $labels)],
        ['label' => 'Purchases', 'data' => karoor_dashboard_values($purchases, $labels)],
    ]], 'Chart data loaded.', karoor_dashboard_chart_meta($range));
}

if ($action === 'revenue-profit') {
    $saleGroup = sprintf($range['group'], 's.sale_date', 's.sale_date');
    $expenseGroup = sprintf($range['group'], 'e.expense_date', 'e.expense_date');
    $rows = $database->fetchAll(
        "SELECT {$saleGroup} AS label,
                COALESCE(SUM(si.line_total - si.tax_amount), 0) AS revenue,
                COALESCE(SUM(si.quantity * si.cost_price), 0) AS cost
         FROM sales s INNER JOIN sale_items si ON si.sale_id = s.id
         WHERE s.company_id = :company_id AND s.status = 'COMPLETED' AND s.deleted_at IS NULL
           AND s.sale_date >= :start_date AND s.sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
         GROUP BY label ORDER BY label",
        $parameters
    );
    $revenue = [];
    $profit = [];
    foreach ($rows as $row) {
        $revenue[(string) $row['label']] = (float) $row['revenue'];
        $profit[(string) $row['label']] = (float) $row['revenue'] - (float) $row['cost'];
    }
    $expenseRows = $database->fetchAll(
        "SELECT {$expenseGroup} AS label, COALESCE(SUM(e.amount), 0) AS value
         FROM expenses e WHERE e.company_id = :company_id AND e.deleted_at IS NULL
           AND e.expense_date >= :start_date AND e.expense_date <= :end_date
         GROUP BY label ORDER BY label",
        $parameters
    );
    foreach (karoor_dashboard_series($expenseRows) as $label => $value) {
        $profit[$label] = ($profit[$label] ?? 0.0) - $value;
    }
    $labels = karoor_dashboard_labels($revenue, $profit);
    Response::success(['labels' => $labels, 'datasets' => [
        ['label' => 'Revenue', 'data' => karoor_dashboard_values($revenue, $labels)],
        ['label' => 'Net Profit', 'data' => karoor_dashboard_values($profit, $labels)],
    ]], 'Chart data loaded.', karoor_dashboard_chart_meta($range));
}

if ($action === 'top-products') {
    $limit = filter_var($_GET['limit'] ?? 8, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]) ?: 8;
    $rows = $database->fetchAll(
        "SELECT si.product_name AS label, COALESCE(SUM(si.quantity), 0) AS value
         FROM sale_items si INNER JOIN sales s ON s.id = si.sale_id
         WHERE s.company_id = :company_id AND s.status = 'COMPLETED' AND s.deleted_at IS NULL
           AND s.sale_date >= :start_date AND s.sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
         GROUP BY si.product_id, si.product_name ORDER BY value DESC LIMIT {$limit}",
        $parameters
    );
    Response::success([
        'labels' => array_column($rows, 'label'),
        'datasets' => [['label' => 'Quantity Sold', 'data' => array_map('floatval', array_column($rows, 'value'))]],
    ], 'Chart data loaded.', karoor_dashboard_chart_meta($range));
}

if ($action === 'sales-category') {
    $rows = $database->fetchAll(
        "SELECT COALESCE(c.name, 'Uncategorized') AS label, COALESCE(SUM(si.line_total), 0) AS value
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         INNER JOIN products p ON p.id = si.product_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE s.company_id = :company_id AND s.status = 'COMPLETED' AND s.deleted_at IS NULL
           AND s.sale_date >= :start_date AND s.sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)
         GROUP BY c.id, c.name ORDER BY value DESC",
        $parameters
    );
    Response::success([
        'labels' => array_column($rows, 'label'),
        'datasets' => [['label' => 'Sales', 'data' => array_map('floatval', array_column($rows, 'value'))]],
    ], 'Chart data loaded.', karoor_dashboard_chart_meta($range));
}

if ($action === 'stock-distribution') {
    $rows = $database->fetchAll(
        'SELECT w.name AS label, COALESCE(SUM(wi.quantity * wi.average_cost), 0) AS value
         FROM warehouses w LEFT JOIN warehouse_inventory wi ON wi.warehouse_id = w.id
         WHERE w.company_id = :company_id AND w.is_active = 1 AND w.deleted_at IS NULL
         GROUP BY w.id, w.name ORDER BY value DESC',
        ['company_id' => $dashboardCompanyId]
    );
    Response::success([
        'labels' => array_column($rows, 'label'),
        'datasets' => [['label' => 'Stock Value', 'data' => array_map('floatval', array_column($rows, 'value'))]],
    ], 'Chart data loaded.', ['snapshot_at' => (new DateTimeImmutable())->format(DATE_ATOM)]);
}

if ($action === 'expenses') {
    $rows = $database->fetchAll(
        'SELECT ec.name AS label, COALESCE(SUM(e.amount), 0) AS value
         FROM expenses e INNER JOIN expense_categories ec ON ec.id = e.expense_category_id
         WHERE e.company_id = :company_id AND e.deleted_at IS NULL
           AND e.expense_date >= :start_date AND e.expense_date <= :end_date
         GROUP BY ec.id, ec.name ORDER BY value DESC',
        $parameters
    );
    Response::success([
        'labels' => array_column($rows, 'label'),
        'datasets' => [['label' => 'Expenses', 'data' => array_map('floatval', array_column($rows, 'value'))]],
    ], 'Chart data loaded.', karoor_dashboard_chart_meta($range));
}

if ($action === 'payroll-distribution') {
    $rows = $database->fetchAll(
        "SELECT COALESCE(d.name, 'No Department') AS label, COALESCE(SUM(pr.net_salary), 0) AS value
         FROM payroll pr
         INNER JOIN employees e ON e.id = pr.employee_id
         LEFT JOIN departments d ON d.id = e.department_id
         WHERE pr.company_id = :company_id AND pr.status IN ('APPROVED', 'PAID') AND pr.deleted_at IS NULL
           AND pr.payroll_month >= :start_date AND pr.payroll_month <= :end_date
         GROUP BY d.id, d.name ORDER BY value DESC",
        $parameters
    );
    Response::success([
        'labels' => array_column($rows, 'label'),
        'datasets' => [['label' => 'Net Payroll', 'data' => array_map('floatval', array_column($rows, 'value'))]],
    ], 'Chart data loaded.', karoor_dashboard_chart_meta($range));
}

Response::notFound('The requested dashboard metric was not found.');
