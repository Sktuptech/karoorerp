<?php

declare(strict_types=1);

use Karoor\Core\Helpers;

$pageTitle = 'Point of Sale';
$pageDescription = 'Fast, touch-friendly checkout with live warehouse stock.';
$breadcrumbs = ['Operations', 'POS'];
$companyId = (int) $currentUser['company_id'];
$currencyCode = (string) ($currentUser['currency_code'] ?? $config['app']['currency']);
$registers = $database->fetchAll(
    'SELECT pr.id, pr.code, pr.name, pr.warehouse_id, w.name AS warehouse_name
     FROM pos_registers pr INNER JOIN warehouses w ON w.id = pr.warehouse_id
     WHERE pr.company_id = :company_id AND pr.is_active = 1 AND pr.deleted_at IS NULL
       AND w.is_active = 1 AND w.deleted_at IS NULL ORDER BY pr.name',
    ['company_id' => $companyId]
);
$customers = $database->fetchAll(
    'SELECT id, customer_code, name FROM customers
     WHERE company_id = :company_id AND is_active = 1 AND deleted_at IS NULL ORDER BY name LIMIT 500',
    ['company_id' => $companyId]
);
$categories = $database->fetchAll(
    'SELECT id, name FROM categories
     WHERE company_id = :company_id AND is_active = 1 AND deleted_at IS NULL ORDER BY sort_order, name',
    ['company_id' => $companyId]
);
$openSession = $database->fetchOne(
    'SELECT ps.*, pr.name AS register_name, pr.code AS register_code, pr.warehouse_id, w.name AS warehouse_name
     FROM pos_sessions ps
     INNER JOIN pos_registers pr ON pr.id = ps.pos_register_id
     INNER JOIN warehouses w ON w.id = pr.warehouse_id
     WHERE ps.cashier_id = :cashier_id AND pr.company_id = :company_id AND ps.status = \'OPEN\'
     ORDER BY ps.opened_at DESC LIMIT 1',
    ['cashier_id' => (int) $currentUser['id'], 'company_id' => $companyId]
);

$pageActions = static function () use ($openSession, $auth): void {
    if ($openSession !== null && $auth->can('pos.close')) {
        echo '<button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#closeRegisterModal"><i class="fa-solid fa-lock" aria-hidden="true"></i> Close register</button>';
    }
};

require __DIR__ . '/../layouts/header.php';
?>

<?php if ($openSession === null): ?>
    <section class="surface-card mx-auto" style="max-width:680px" aria-labelledby="openRegisterTitle">
        <div class="card-header-row">
            <div><h2 id="openRegisterTitle">Open a cash register</h2><p>A register session is required before accepting sales.</p></div>
            <span class="kpi-icon"><i class="fa-solid fa-cash-register" aria-hidden="true"></i></span>
        </div>
        <form class="card-body-pad row g-3" data-api-form data-endpoint="pos/open" data-method="POST" data-success-redirect="<?= Helpers::escape(Helpers::appPath($config, '/pos')) ?>">
            <div class="col-12">
                <label class="form-label" for="registerId">Register</label>
                <select class="form-select" id="registerId" name="register_id" required <?= $registers === [] ? 'disabled' : '' ?>>
                    <option value="">Select register</option>
                    <?php foreach ($registers as $register): ?>
                        <option value="<?= (int) $register['id'] ?>"><?= Helpers::escape((string) $register['name']) ?> · <?= Helpers::escape((string) $register['warehouse_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($registers === []): ?><div class="form-text text-warning">No active register is configured. Ask an administrator to configure one.</div><?php endif; ?>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label" for="openingCash">Opening cash (<?= Helpers::escape($currencyCode) ?>)</label>
                <input class="form-control" id="openingCash" name="opening_cash" type="number" min="0" step="0.01" value="0" required>
            </div>
            <div class="col-12">
                <label class="form-label" for="openingNotes">Opening notes</label>
                <textarea class="form-control" id="openingNotes" name="notes" rows="3" maxlength="500"></textarea>
            </div>
            <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary" type="submit" <?= $registers === [] ? 'disabled' : '' ?>>Open register</button></div>
        </form>
    </section>
<?php else: ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <span class="status-badge status-success"><i class="fa-solid fa-circle" aria-hidden="true"></i> Register open</span>
        <span class="text-secondary"><?= Helpers::escape((string) $openSession['register_name']) ?> · <?= Helpers::escape((string) $openSession['warehouse_name']) ?> · opened <?= Helpers::escape(date('M j, H:i', strtotime((string) $openSession['opened_at']))) ?></span>
    </div>

    <section class="pos-layout" data-pos data-products-endpoint="pos/products" data-checkout-endpoint="pos/sales" data-currency="<?= Helpers::escape($currencyCode) ?>" data-storage-key="karoor-pos-cart-<?= (int) $openSession['id'] ?>">
        <button class="pos-cart-launch d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#posCartPanel" aria-controls="posCartPanel">
            <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>
            <span>Cart <span class="badge text-bg-light" data-pos-count>0</span></span>
            <strong data-pos-total>0</strong>
        </button>
        <article class="surface-card">
            <div class="card-body-pad">
                <div class="row g-2 mb-3">
                    <div class="col-12 col-md-7">
                        <label class="visually-hidden" for="posSearch">Search products</label>
                        <div class="input-group"><span class="input-group-text"><i class="fa-solid fa-barcode" aria-hidden="true"></i></span><input class="form-control" id="posSearch" data-pos-search type="search" placeholder="Search or scan barcode…" autofocus autocomplete="off"></div>
                    </div>
                    <div class="col-12 col-md-5">
                        <label class="visually-hidden" for="posCategory">Category</label>
                        <select class="form-select" id="posCategory" data-pos-category><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= Helpers::escape((string) $category['name']) ?></option><?php endforeach; ?></select>
                    </div>
                </div>
                <div class="pos-product-grid" data-pos-products aria-live="polite"></div>
            </div>
        </article>

        <aside class="surface-card offcanvas-lg offcanvas-end pos-cart-panel" id="posCartPanel" tabindex="-1" aria-labelledby="posCartTitle">
            <div class="card-header-row"><div><h2 id="posCartTitle">Current sale</h2><p><span data-pos-count>0</span> product lines · <span data-pos-quantity>0</span> units</p></div><div class="d-flex align-items-center gap-2"><button class="btn btn-sm btn-outline-danger" type="button" data-pos-clear><i class="fa-solid fa-trash" aria-hidden="true"></i> Clear</button><button class="btn-close d-lg-none" type="button" data-bs-dismiss="offcanvas" data-bs-target="#posCartPanel" aria-label="Close cart"></button></div></div>
            <div class="card-body-pad">
                <input type="hidden" data-pos-warehouse value="<?= (int) $openSession['warehouse_id'] ?>">
                <div class="table-responsive responsive-table stack-mobile mb-3">
                    <table class="table align-middle"><thead><tr><th>Product</th><th>Price</th><th>Qty</th><th>Discount</th><th>Total</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody data-pos-cart></tbody></table>
                </div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="posCustomer">Customer</label>
                        <select class="form-select" id="posCustomer" data-pos-customer><option value="">Walk-in customer</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id'] ?>"><?= Helpers::escape((string) $customer['name']) ?> (<?= Helpers::escape((string) $customer['customer_code']) ?>)</option><?php endforeach; ?></select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="posDiscount">Order discount</label>
                        <input class="form-control" id="posDiscount" data-pos-order-discount type="number" min="0" step="0.01" value="0">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="posPayment">Payment</label>
                        <select class="form-select" id="posPayment" data-pos-payment-method><option value="CASH">Cash</option><option value="BANK">Bank</option><option value="MOBILE_MONEY">Mobile money</option><option value="CREDIT">Credit</option></select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="posTendered">Amount tendered</label>
                        <input class="form-control form-control-lg" id="posTendered" data-pos-tendered type="number" min="0" step="0.01" value="0">
                    </div>
                </div>
                <hr>
                <dl class="row mb-3">
                    <dt class="col-7 text-secondary fw-normal">Subtotal</dt><dd class="col-5 text-end" data-pos-subtotal>0</dd>
                    <dt class="col-7 text-secondary fw-normal">Discount</dt><dd class="col-5 text-end" data-pos-discount>0</dd>
                    <dt class="col-7 text-secondary fw-normal">Tax</dt><dd class="col-5 text-end" data-pos-tax>0</dd>
                    <dt class="col-7 fs-5">Total</dt><dd class="col-5 text-end fs-5 fw-bold" data-pos-total>0</dd>
                    <dt class="col-7 text-secondary fw-normal">Due</dt><dd class="col-5 text-end" data-pos-due>0</dd>
                    <dt class="col-7 text-secondary fw-normal">Change</dt><dd class="col-5 text-end text-success fw-semibold" data-pos-change>0</dd>
                </dl>
                <button class="btn btn-primary btn-lg w-100" type="button" data-pos-checkout disabled><i class="fa-solid fa-check" aria-hidden="true"></i> Complete sale</button>
            </div>
        </aside>
    </section>

    <?php if ($auth->can('pos.close')): ?>
        <div class="modal fade" id="closeRegisterModal" tabindex="-1" aria-labelledby="closeRegisterTitle" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="closeRegisterTitle">Close register</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><form data-api-form data-endpoint="pos/close" data-method="POST" data-success-redirect="<?= Helpers::escape(Helpers::appPath($config, '/pos')) ?>"><div class="modal-body"><input type="hidden" name="session_id" value="<?= (int) $openSession['id'] ?>"><label class="form-label" for="closingCash">Counted closing cash</label><input class="form-control" id="closingCash" name="closing_cash" type="number" min="0" step="0.01" required><label class="form-label mt-3" for="closingNotes">Notes</label><textarea class="form-control" id="closingNotes" name="notes" rows="3" maxlength="500"></textarea></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit">Close register</button></div></form></div></div></div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../layouts/footer.php'; ?>
