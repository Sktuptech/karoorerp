<?php

declare(strict_types=1);

use Karoor\Core\Helpers;

$pageTitle = 'Purchases';
$pageDescription = 'Record supplier orders, receive stock, and monitor outstanding payables.';
$breadcrumbs = ['Operations', 'Purchases'];
$companyId = (int) $currentUser['company_id'];
$warehouses = $database->fetchAll('SELECT id, name FROM warehouses WHERE company_id=:company AND is_active=1 AND deleted_at IS NULL ORDER BY name', ['company' => $companyId]);
$suppliers = $database->fetchAll('SELECT id, supplier_code, name FROM suppliers WHERE company_id=:company AND is_active=1 AND deleted_at IS NULL ORDER BY name LIMIT 500', ['company' => $companyId]);
$products = $database->fetchAll('SELECT id, sku, name, purchase_price FROM products WHERE company_id=:company AND is_active=1 AND deleted_at IS NULL ORDER BY name LIMIT 1000', ['company' => $companyId]);
$accounts = $database->fetchAll('SELECT id, name FROM accounts WHERE company_id=:company AND is_active=1 AND deleted_at IS NULL ORDER BY name', ['company' => $companyId]);
$productsJson = json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$pageActions = static function () use ($auth): void {
    if ($auth->can('purchases.create')) echo '<button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#newPurchaseModal"><i class="fa-solid fa-plus" aria-hidden="true"></i> New purchase</button>';
};
require __DIR__ . '/../layouts/header.php';
?>

<section class="surface-card">
    <div class="card-header-row flex-wrap gap-3"><div><h2>Purchase orders</h2><p><span data-table-total="purchasesTable">0</span> matching records</p></div><div class="input-group" style="max-width:360px"><span class="input-group-text"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span><label class="visually-hidden" for="purchaseSearch">Search purchases</label><input class="form-control" id="purchaseSearch" data-table-search="purchasesTable" type="search" placeholder="Number, invoice, or supplier…"></div></div>
    <form class="card-body-pad border-bottom row g-2 align-items-end" data-table-filters="purchasesTable"><div class="col-6 col-md-3"><label class="form-label" for="purchaseStatus">Status</label><select class="form-select" id="purchaseStatus" name="status"><option value="">All</option><?php foreach (['DRAFT','ORDERED','RECEIVED','CANCELLED'] as $status): ?><option><?= $status ?></option><?php endforeach; ?></select></div><div class="col-6 col-md-4"><label class="form-label" for="purchaseWarehouse">Warehouse</label><select class="form-select" id="purchaseWarehouse" name="warehouse_id"><option value="">All</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>"><?= Helpers::escape((string) $warehouse['name']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-5 d-flex gap-2"><button class="btn btn-outline-primary" type="submit">Apply</button><button class="btn btn-outline-secondary" type="reset">Reset</button></div></form>
    <div class="table-responsive"><table class="table align-middle mb-0" id="purchasesTable" data-api-table data-endpoint="purchases"><thead><tr><th data-field="purchase_number">Purchase</th><th data-field="supplier_invoice_number">Supplier invoice</th><th data-field="purchase_date" data-format="datetime">Date</th><th data-field="supplier_name">Supplier</th><th data-field="warehouse_name">Warehouse</th><th data-field="total_amount" data-format="currency">Total</th><th data-field="paid_amount" data-format="currency">Paid</th><th data-field="due_amount" data-format="currency">Due</th><th data-field="status" data-format="status">Status</th><th data-field="id">Actions</th></tr></thead><tbody></tbody></table></div>
    <div class="card-body-pad d-flex justify-content-between align-items-center"><span class="text-secondary">Page <span data-table-current="purchasesTable">1</span> of <span data-table-last="purchasesTable">1</span></span><div class="btn-group"><button class="btn btn-outline-secondary" type="button" data-table-page="purchasesTable" data-page="previous">Previous</button><button class="btn btn-outline-secondary" type="button" data-table-page="purchasesTable" data-page="next">Next</button></div></div>
</section>

<?php if ($auth->can('purchases.create')): ?>
<div class="modal fade" id="newPurchaseModal" tabindex="-1" aria-labelledby="newPurchaseTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><form id="purchaseCreateForm"><div class="modal-header"><h2 class="modal-title fs-5" id="newPurchaseTitle">Create purchase</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="row g-3">
    <div class="col-md-3"><label class="form-label" for="newPurchaseWarehouse">Warehouse</label><select class="form-select" id="newPurchaseWarehouse" name="warehouse_id" required><option value="">Select warehouse</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>" <?= (int) $warehouse['id'] === $activeWarehouseId ? 'selected' : '' ?>><?= Helpers::escape((string) $warehouse['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="newPurchaseSupplier">Supplier</label><select class="form-select" id="newPurchaseSupplier" name="supplier_id" required><option value="">Select supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>"><?= Helpers::escape((string) $supplier['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="supplierInvoice">Supplier invoice</label><input class="form-control" id="supplierInvoice" name="supplier_invoice_number" maxlength="100"></div>
    <div class="col-md-3"><label class="form-label" for="purchaseDate">Purchase date</label><input class="form-control" id="purchaseDate" name="purchase_date" type="date" value="<?= date('Y-m-d') ?>" required></div>
</div><div class="d-flex justify-content-between align-items-center mt-4 mb-2"><h3 class="fs-6 mb-0">Items</h3><button class="btn btn-sm btn-outline-primary" id="addPurchaseLine" type="button"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add line</button></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Product</th><th style="width:130px">Quantity</th><th style="width:150px">Unit cost</th><th style="width:150px">Discount</th><th style="width:60px"><span class="visually-hidden">Remove</span></th></tr></thead><tbody id="purchaseLines"></tbody></table></div><div class="row g-3 mt-1">
    <div class="col-md-3"><label class="form-label" for="purchaseOrderDiscount">Order discount</label><input class="form-control" id="purchaseOrderDiscount" name="order_discount_amount" type="number" min="0" step="0.01" value="0"></div>
    <div class="col-md-3"><label class="form-label" for="purchasePaymentMethod">Payment method</label><select class="form-select" id="purchasePaymentMethod" name="payment_method"><option value="CASH">Cash</option><option value="BANK">Bank</option><option value="MOBILE_MONEY">Mobile money</option><option value="CREDIT">Credit</option></select></div>
    <div class="col-md-3"><label class="form-label" for="purchaseAccount">Payment account</label><select class="form-select" id="purchaseAccount" name="account_id"><option value="">Automatic</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= Helpers::escape((string) $account['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="purchasePaid">Paid amount</label><input class="form-control" id="purchasePaid" name="paid_amount" type="number" min="0" step="0.01" value="0"></div>
    <div class="col-md-3"><label class="form-label" for="purchaseCreateStatus">Save as</label><select class="form-select" id="purchaseCreateStatus" name="status"><option value="DRAFT">Draft</option><option value="ORDERED">Ordered</option><option value="RECEIVED">Received</option></select></div>
    <div class="col-md-9"><label class="form-label" for="purchaseNotes">Notes</label><input class="form-control" id="purchaseNotes" name="notes" maxlength="5000"></div>
</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save purchase</button></div></form></div></div></div>
<script type="application/json" id="purchaseProductData"><?= $productsJson ?></script>
<?php endif; ?>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
