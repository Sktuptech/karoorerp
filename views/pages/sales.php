<?php

declare(strict_types=1);

use Karoor\Core\Helpers;

$pageTitle = 'Sales';
$pageDescription = 'Create invoices and track payments, balances, cancellations, and refunds.';
$breadcrumbs = ['Operations', 'Sales'];
$companyId = (int) $currentUser['company_id'];
$warehouses = $database->fetchAll('SELECT id, name FROM warehouses WHERE company_id=:company AND is_active=1 AND deleted_at IS NULL ORDER BY name', ['company' => $companyId]);
$customers = $database->fetchAll('SELECT id, customer_code, name FROM customers WHERE company_id=:company AND is_active=1 AND deleted_at IS NULL ORDER BY name LIMIT 500', ['company' => $companyId]);
$products = $database->fetchAll('SELECT id, sku, name, selling_price FROM products WHERE company_id=:company AND is_active=1 AND deleted_at IS NULL ORDER BY name LIMIT 1000', ['company' => $companyId]);
$accounts = $database->fetchAll('SELECT id, name FROM accounts WHERE company_id=:company AND is_active=1 AND deleted_at IS NULL ORDER BY name', ['company' => $companyId]);
$productsJson = json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$pageActions = static function () use ($auth): void {
    if ($auth->can('sales.create')) echo '<button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#newSaleModal"><i class="fa-solid fa-plus" aria-hidden="true"></i> New sale</button>';
};
require __DIR__ . '/../layouts/header.php';
?>

<section class="surface-card">
    <div class="card-header-row flex-wrap gap-3">
        <div><h2>Sales invoices</h2><p><span data-table-total="salesTable">0</span> matching records</p></div>
        <div class="input-group" style="max-width:360px"><span class="input-group-text"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span><label class="visually-hidden" for="salesSearch">Search invoices</label><input class="form-control" id="salesSearch" data-table-search="salesTable" type="search" placeholder="Invoice or customer…"></div>
    </div>
    <form class="card-body-pad border-bottom row g-2 align-items-end" data-table-filters="salesTable">
        <div class="col-6 col-lg-2"><label class="form-label" for="saleStatus">Status</label><select class="form-select" id="saleStatus" name="status"><option value="">All</option><?php foreach (['DRAFT','COMPLETED','CANCELLED','REFUNDED'] as $status): ?><option><?= $status ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-lg-3"><label class="form-label" for="saleWarehouse">Warehouse</label><select class="form-select" id="saleWarehouse" name="warehouse_id"><option value="">All</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>"><?= Helpers::escape((string) $warehouse['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-lg-2"><label class="form-label" for="saleStart">From</label><input class="form-control" id="saleStart" name="start_date" type="date"></div>
        <div class="col-6 col-lg-2"><label class="form-label" for="saleEnd">To</label><input class="form-control" id="saleEnd" name="end_date" type="date"></div>
        <div class="col-12 col-lg-3 d-flex gap-2"><button class="btn btn-outline-primary" type="submit">Apply</button><button class="btn btn-outline-secondary" type="reset">Reset</button></div>
    </form>
    <div class="table-responsive"><table class="table align-middle mb-0" id="salesTable" data-api-table data-endpoint="sales" data-sort="sale_date" data-direction="desc" data-print-url="<?= Helpers::escape(Helpers::appPath($config, '/print/invoice')) ?>"><thead><tr><th data-field="invoice_number" data-sortable="true">Invoice</th><th data-field="sale_date" data-format="datetime" data-sortable="true">Date</th><th data-field="customer_name">Customer</th><th data-field="warehouse_name">Warehouse</th><th data-field="total_amount" data-format="currency" data-sortable="true">Total</th><th data-field="paid_amount" data-format="currency">Paid</th><th data-field="due_amount" data-format="currency">Due</th><th data-field="status" data-format="status" data-sortable="true">Status</th><th data-field="id">Actions</th></tr></thead><tbody></tbody></table></div>
    <div class="card-body-pad d-flex justify-content-between align-items-center"><span class="text-secondary">Page <span data-table-current="salesTable">1</span> of <span data-table-last="salesTable">1</span></span><div class="btn-group"><button class="btn btn-outline-secondary" type="button" data-table-page="salesTable" data-page="previous">Previous</button><button class="btn btn-outline-secondary" type="button" data-table-page="salesTable" data-page="next">Next</button></div></div>
</section>

<?php if ($auth->can('sales.create')): ?>
<div class="modal fade" id="newSaleModal" tabindex="-1" aria-labelledby="newSaleTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><form id="saleCreateForm"><div class="modal-header"><h2 class="modal-title fs-5" id="newSaleTitle">Create sale</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="row g-3">
    <div class="col-md-4"><label class="form-label" for="newSaleWarehouse">Warehouse</label><select class="form-select" id="newSaleWarehouse" name="warehouse_id" required><option value="">Select warehouse</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $activeWarehouseId ? 'selected' : '' ?>><?= Helpers::escape((string) $warehouse['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label" for="newSaleCustomer">Customer</label><select class="form-select" id="newSaleCustomer" name="customer_id"><option value="">Walk-in / cash customer</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id'] ?>"><?= Helpers::escape((string) $customer['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label" for="newSaleDate">Sale date</label><input class="form-control" id="newSaleDate" name="sale_date" type="date" value="<?= date('Y-m-d') ?>" required></div>
</div><div class="d-flex justify-content-between align-items-center mt-4 mb-2"><h3 class="fs-6 mb-0">Items</h3><button class="btn btn-sm btn-outline-primary" id="addSaleLine" type="button"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add line</button></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Product</th><th style="width:130px">Quantity</th><th style="width:150px">Unit price</th><th style="width:150px">Discount</th><th style="width:60px"><span class="visually-hidden">Remove</span></th></tr></thead><tbody id="saleLines"></tbody></table></div><div class="row g-3 mt-1">
    <div class="col-md-3"><label class="form-label" for="saleOrderDiscount">Order discount</label><input class="form-control" id="saleOrderDiscount" name="order_discount_amount" type="number" min="0" step="0.01" value="0"></div>
    <div class="col-md-3"><label class="form-label" for="salePaymentMethod">Payment method</label><select class="form-select" id="salePaymentMethod" name="payment_method"><option value="CASH">Cash</option><option value="BANK">Bank</option><option value="MOBILE_MONEY">Mobile money</option><option value="CREDIT">Credit</option></select></div>
    <div class="col-md-3"><label class="form-label" for="saleAccount">Payment account</label><select class="form-select" id="saleAccount" name="account_id"><option value="">Automatic</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= Helpers::escape((string) $account['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="salePaid">Paid amount</label><input class="form-control" id="salePaid" name="paid_amount" type="number" min="0" step="0.01" value="0"></div>
    <div class="col-md-3"><label class="form-label" for="saleCreateStatus">Save as</label><select class="form-select" id="saleCreateStatus" name="status"><option value="DRAFT">Draft</option><option value="COMPLETED">Completed</option></select></div>
    <div class="col-md-9"><label class="form-label" for="saleNotes">Notes</label><input class="form-control" id="saleNotes" name="notes" maxlength="5000"></div>
</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save sale</button></div></form></div></div></div>
<script type="application/json" id="saleProductData"><?= $productsJson ?></script>
<?php endif; ?>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
