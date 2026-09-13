<?php

declare(strict_types=1);

use Karoor\Core\Helpers;

$pageTitle = 'Dashboard';
$pageDescription = 'Live business performance, cash position, and operational trends.';
$breadcrumbs = ['Dashboard'];
$currencyCode = (string) ($currentUser['currency_code'] ?? $config['app']['currency']);

$pageActions = static function () use ($auth, $config): void {
    if ($auth->can('pos.use')) {
        echo '<a class="btn btn-primary" href="' . Helpers::escape(Helpers::appPath($config, '/pos')) . '"><i class="fa-solid fa-cash-register" aria-hidden="true"></i> Open POS</a>';
    }
};

require __DIR__ . '/../layouts/header.php';

$kpis = [
    'today_sales' => ['Today\'s Sales', 'fa-arrow-trend-up', 'primary', true],
    'today_purchases' => ['Today\'s Purchases', 'fa-cart-shopping', 'purple', true],
    'today_expenses' => ['Today\'s Expenses', 'fa-receipt', 'warning', true],
    'net_profit' => ['Net Profit', 'fa-chart-line', 'success', true],
    'receivables' => ['Total Receivables', 'fa-hand-holding-dollar', 'primary', true],
    'payables' => ['Total Payables', 'fa-file-invoice-dollar', 'danger', true],
    'stock_value' => ['Total Stock Value', 'fa-boxes-stacked', 'purple', true],
    'low_stock_items' => ['Low Stock Items', 'fa-triangle-exclamation', 'warning', false],
];
?>

<section class="kpi-grid" aria-label="Business key performance indicators" data-dashboard-kpis data-currency="<?= Helpers::escape($currencyCode) ?>">
    <?php foreach ($kpis as $key => [$label, $icon, $tone, $isMoney]): ?>
        <article class="kpi-card" data-kpi="<?= Helpers::escape($key) ?>" data-kpi-money="<?= $isMoney ? 'true' : 'false' ?>" aria-busy="true">
            <span class="kpi-icon text-<?= Helpers::escape($tone) ?>"><i class="fa-solid <?= Helpers::escape($icon) ?>" aria-hidden="true"></i></span>
            <div>
                <span class="kpi-label"><?= Helpers::escape($label) ?></span>
                <strong class="kpi-value" data-kpi-value>—</strong>
                <span class="trend" data-kpi-trend>Loading comparison…</span>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="surface-card mt-4" aria-labelledby="quickActionsTitle">
    <div class="card-header-row">
        <div><h2 id="quickActionsTitle">Quick actions</h2><p>Start a common business workflow.</p></div>
    </div>
    <div class="card-body-pad d-flex flex-wrap gap-2" data-dashboard-actions>
        <span class="text-secondary">Loading available actions…</span>
    </div>
</section>

<section class="row g-4 mt-1" aria-label="Business charts">
    <div class="col-12 col-xl-8">
        <article class="surface-card h-100">
            <div class="card-header-row flex-wrap gap-3">
                <div><h2>Sales vs Purchases</h2><p>Completed sales and received purchases.</p></div>
                <div class="btn-group btn-group-sm" role="group" aria-label="Chart period">
                    <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $period => $label): ?>
                        <button class="btn btn-outline-secondary<?= $period === 'daily' ? ' active' : '' ?>" type="button" data-chart-period="<?= $period ?>" data-chart-target="salesPurchasesChart"><?= $label ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-body-pad chart-wrap"><canvas id="salesPurchasesChart" data-chart-endpoint="dashboard/sales-purchases" data-chart-type="line" aria-label="Sales and purchases trend"></canvas></div>
        </article>
    </div>
    <div class="col-12 col-xl-4">
        <article class="surface-card h-100">
            <div class="card-header-row"><div><h2>Top Selling Products</h2><p>Units sold in the last 30 days.</p></div></div>
            <div class="card-body-pad chart-wrap"><canvas id="topProductsChart" data-chart-endpoint="dashboard/top-products" data-chart-type="doughnut" aria-label="Top selling products"></canvas></div>
        </article>
    </div>
    <div class="col-12 col-xl-7">
        <article class="surface-card h-100">
            <div class="card-header-row"><div><h2>Revenue vs Net Profit</h2><p>Revenue after cost of goods and expenses.</p></div></div>
            <div class="card-body-pad chart-wrap"><canvas id="revenueProfitChart" data-chart-endpoint="dashboard/revenue-profit" data-chart-type="bar" aria-label="Revenue and net profit"></canvas></div>
        </article>
    </div>
    <div class="col-12 col-md-6 col-xl-5">
        <article class="surface-card h-100">
            <div class="card-header-row"><div><h2>Sales by Category</h2><p>Revenue mix by product category.</p></div></div>
            <div class="card-body-pad chart-wrap"><canvas id="salesCategoryChart" data-chart-endpoint="dashboard/sales-category" data-chart-type="doughnut" aria-label="Sales by category"></canvas></div>
        </article>
    </div>
    <div class="col-12 col-md-6">
        <article class="surface-card h-100">
            <div class="card-header-row"><div><h2>Warehouse Stock</h2><p>Inventory value across active warehouses.</p></div></div>
            <div class="card-body-pad chart-wrap"><canvas id="warehouseStockChart" data-chart-endpoint="dashboard/stock-distribution" data-chart-type="bar" data-chart-horizontal="true" aria-label="Warehouse stock distribution"></canvas></div>
        </article>
    </div>
    <div class="col-12 col-md-6">
        <article class="surface-card h-100">
            <div class="card-header-row"><div><h2>Expenses</h2><p>Expense distribution for the current period.</p></div></div>
            <div class="card-body-pad chart-wrap"><canvas id="expensesChart" data-chart-endpoint="dashboard/expenses" data-chart-type="polarArea" aria-label="Expenses by category"></canvas></div>
        </article>
    </div>
</section>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
