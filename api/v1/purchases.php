<?php

declare(strict_types=1);

use Karoor\Core\Database;
use Karoor\Core\Helpers;
use Karoor\Core\Response;
use Karoor\Core\Validator;

if (!defined('KAROOR_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

function karoor_purchase_money(mixed $value): float
{
    return round((float) $value, 4);
}

function karoor_purchase_valid_date(mixed $value): bool
{
    foreach (['!Y-m-d', '!Y-m-d H:i:s'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, (string) $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->format(substr($format, 1)) === (string) $value;
        }
    }
    return false;
}

/** @return array<string, mixed> */
function karoor_purchase_input(): array
{
    try {
        return Helpers::requestData();
    } catch (RuntimeException) {
        Response::error('The request body is invalid.', [], 400);
    }
}

/** @return array<string, mixed> */
function karoor_purchase_validate(array $input): array
{
    $validator = new Validator($input, [
        'warehouse_id' => 'required|integer|min:1',
        'supplier_id' => 'required|integer|min:1',
        'supplier_invoice_number' => 'sometimes|nullable|string|max_length:100',
        'purchase_date' => ['sometimes', 'nullable', static function (mixed $value): ?string {
            if ($value === null || $value === '') {
                return null;
            }
            return karoor_purchase_valid_date($value) ? null : 'Purchase date must use Y-m-d or Y-m-d H:i:s format.';
        }],
        'status' => 'sometimes|in:DRAFT,ORDERED,RECEIVED',
        'payment_method' => 'sometimes|in:CASH,BANK,MOBILE_MONEY,CREDIT,OTHER',
        'account_id' => 'sometimes|nullable|integer|min:1',
        'paid_amount' => 'sometimes|numeric|min:0|max:999999999999',
        'order_discount_amount' => 'sometimes|numeric|min:0|max:999999999999',
        'notes' => 'sometimes|nullable|string|max_length:5000',
        'items' => 'required|list|min:1|max:200',
        'items.*.product_id' => 'required|integer|min:1',
        'items.*.quantity' => 'required|numeric|min:0.0001|max:999999999',
        'items.*.unit_cost' => 'sometimes|nullable|numeric|min:0|max:999999999999',
        'items.*.discount_amount' => 'sometimes|numeric|min:0|max:999999999999',
    ]);
    if (!$validator->passes()) {
        Response::validation($validator->errors());
    }
    return $validator->validated();
}

/** @return array<string, mixed> */
function karoor_purchase_scope(Database $database, int $companyId, int $warehouseId, int $supplierId): array
{
    $warehouse = $database->fetchOne(
        'SELECT id, branch_id FROM warehouses
         WHERE id = :id AND company_id = :company_id AND is_active = 1 AND deleted_at IS NULL',
        ['id' => $warehouseId, 'company_id' => $companyId]
    );
    if ($warehouse === null) {
        throw new DomainException('The selected warehouse is unavailable.');
    }
    if ($database->fetchOne(
        'SELECT id FROM suppliers WHERE id = :id AND company_id = :company_id AND is_active = 1 AND deleted_at IS NULL',
        ['id' => $supplierId, 'company_id' => $companyId]
    ) === null) {
        throw new DomainException('The selected supplier is unavailable.');
    }
    return $warehouse;
}

/** @param list<array<string, mixed>> $requestedItems
 *  @return array{items: list<array<string, mixed>>, subtotal: float, discount: float, tax: float, total: float, inventory_cost: float, expense_cost: float}
 */
function karoor_purchase_calculate(
    Database $database,
    int $companyId,
    int $warehouseId,
    array $requestedItems,
    float $orderDiscount,
    bool $lockInventory = false
): array {
    $ids = array_map(static fn (array $item): int => (int) $item['product_id'], $requestedItems);
    if (count($ids) !== count(array_unique($ids))) {
        throw new DomainException('Each product may appear only once in a purchase.');
    }
    $parameters = ['company_id' => $companyId, 'warehouse_id' => $warehouseId];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $key = 'product_' . $index;
        $placeholders[] = ':' . $key;
        $parameters[$key] = $id;
    }
    if ($lockInventory) {
        $lockParameters = ['warehouse_id' => $warehouseId];
        foreach ($ids as $index => $id) {
            $lockParameters['product_' . $index] = $id;
        }
        $database->fetchAll(
            'SELECT id FROM warehouse_inventory
             WHERE warehouse_id = :warehouse_id AND product_id IN (' . implode(', ', $placeholders) . ')
             FOR UPDATE',
            $lockParameters
        );
    }
    $rows = $database->fetchAll(
        'SELECT p.id, p.name, p.sku, p.product_type, p.purchase_price, p.track_inventory,
                COALESCE(t.rate, 0) AS tax_rate, COALESCE(t.is_inclusive, 0) AS tax_inclusive,
                wi.id AS inventory_id, COALESCE(wi.quantity, 0) AS stock_quantity,
                COALESCE(wi.average_cost, 0) AS average_cost
         FROM products p
         LEFT JOIN taxes t ON t.id = p.tax_id AND t.company_id = p.company_id AND t.is_active = 1 AND t.deleted_at IS NULL
         LEFT JOIN warehouse_inventory wi ON wi.product_id = p.id AND wi.warehouse_id = :warehouse_id
         WHERE p.company_id = :company_id AND p.is_active = 1 AND p.deleted_at IS NULL
           AND p.id IN (' . implode(', ', $placeholders) . ')',
        $parameters
    );
    if (count($rows) !== count($ids)) {
        throw new DomainException('One or more selected products are unavailable.');
    }
    $products = [];
    foreach ($rows as $row) {
        $products[(int) $row['id']] = $row;
    }

    $items = [];
    $subtotal = 0.0;
    $lineDiscountTotal = 0.0;
    $discountable = 0.0;
    foreach ($requestedItems as $requested) {
        $product = $products[(int) $requested['product_id']];
        $quantity = karoor_purchase_money($requested['quantity']);
        $unitCost = array_key_exists('unit_cost', $requested) && $requested['unit_cost'] !== null
            ? karoor_purchase_money($requested['unit_cost'])
            : karoor_purchase_money($product['purchase_price']);
        $gross = karoor_purchase_money($quantity * $unitCost);
        $lineDiscount = karoor_purchase_money($requested['discount_amount'] ?? 0);
        if ($lineDiscount > $gross) {
            throw new DomainException('A line discount cannot exceed its product total.');
        }
        $discounted = karoor_purchase_money($gross - $lineDiscount);
        $rate = karoor_purchase_money($product['tax_rate']) / 100;
        $inclusive = (bool) $product['tax_inclusive'];
        $taxBeforeOrder = $inclusive && $rate > 0
            ? karoor_purchase_money($discounted - ($discounted / (1 + $rate)))
            : karoor_purchase_money($discounted * $rate);
        $netBeforeOrder = $inclusive ? karoor_purchase_money($discounted - $taxBeforeOrder) : $discounted;
        $subtotal = karoor_purchase_money($subtotal + $gross);
        $lineDiscountTotal = karoor_purchase_money($lineDiscountTotal + $lineDiscount);
        $discountable = karoor_purchase_money($discountable + $discounted);
        $items[] = [
            'product' => $product,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'line_discount' => $lineDiscount,
            'discountable' => $discounted,
            'tax_rate' => karoor_purchase_money($product['tax_rate']),
            'tax_before_order' => $taxBeforeOrder,
            'net_before_order' => $netBeforeOrder,
        ];
    }
    if ($orderDiscount > $discountable) {
        throw new DomainException('The order discount cannot exceed the purchase subtotal.');
    }
    $ratio = $discountable > 0 ? ($discountable - $orderDiscount) / $discountable : 0.0;
    $allocated = 0.0;
    $taxTotal = 0.0;
    $total = 0.0;
    $inventoryCost = 0.0;
    $expenseCost = 0.0;
    foreach ($items as $index => &$item) {
        $orderShare = $index === array_key_last($items)
            ? karoor_purchase_money($orderDiscount - $allocated)
            : karoor_purchase_money($orderDiscount * ($item['discountable'] / max($discountable, 0.0001)));
        $allocated = karoor_purchase_money($allocated + $orderShare);
        $tax = karoor_purchase_money($item['tax_before_order'] * $ratio);
        $net = karoor_purchase_money($item['net_before_order'] * $ratio);
        $lineTotal = karoor_purchase_money($net + $tax);
        $netUnitCost = karoor_purchase_money($net / $item['quantity']);
        $item['discount_amount'] = karoor_purchase_money($item['line_discount'] + $orderShare);
        $item['tax_amount'] = $tax;
        $item['line_total'] = $lineTotal;
        $item['net_cost'] = $net;
        $item['net_unit_cost'] = $netUnitCost;
        $taxTotal = karoor_purchase_money($taxTotal + $tax);
        $total = karoor_purchase_money($total + $lineTotal);
        if ((bool) $item['product']['track_inventory']) {
            $inventoryCost = karoor_purchase_money($inventoryCost + $net);
        } else {
            $expenseCost = karoor_purchase_money($expenseCost + $net);
        }
    }
    unset($item);
    return [
        'items' => $items,
        'subtotal' => $subtotal,
        'discount' => karoor_purchase_money($lineDiscountTotal + $orderDiscount),
        'tax' => $taxTotal,
        'total' => $total,
        'inventory_cost' => $inventoryCost,
        'expense_cost' => $expenseCost,
    ];
}

function karoor_purchase_coa(Database $database, int $companyId, string $subtype): int
{
    $row = $database->fetchOne(
        'SELECT id FROM chart_of_accounts
         WHERE company_id = :company_id AND account_subtype = :subtype AND is_active = 1 AND deleted_at IS NULL
         ORDER BY is_system DESC, id LIMIT 1',
        ['company_id' => $companyId, 'subtype' => $subtype]
    );
    if ($row === null) {
        throw new DomainException("The {$subtype} accounting account is not configured.");
    }
    return (int) $row['id'];
}

/** @return array<string, mixed>|null */
function karoor_purchase_payment_account(Database $database, int $companyId, string $method, ?int $accountId, float $paid): ?array
{
    if ($paid <= 0) {
        return null;
    }
    $kind = match ($method) {
        'CASH' => 'CASH', 'BANK' => 'BANK', 'MOBILE_MONEY' => 'MOBILE_MONEY', default => 'OTHER',
    };
    if ($accountId !== null) {
        $row = $database->fetchOne(
            'SELECT id, chart_account_id FROM accounts
             WHERE id = :id AND company_id = :company_id AND is_active = 1 AND deleted_at IS NULL',
            ['id' => $accountId, 'company_id' => $companyId]
        );
    } else {
        $row = $database->fetchOne(
            'SELECT id, chart_account_id FROM accounts
             WHERE company_id = :company_id AND account_kind = :kind AND is_active = 1 AND deleted_at IS NULL
             ORDER BY is_default DESC, id LIMIT 1',
            ['company_id' => $companyId, 'kind' => $kind]
        );
    }
    if ($row === null) {
        throw new DomainException("No active {$kind} payment account is configured.");
    }
    return $row;
}

/** @param list<array<string, mixed>> $items */
function karoor_purchase_insert_items(Database $database, int $purchaseId, array $items, bool $received): void
{
    foreach ($items as $item) {
        $database->insert('purchase_items', [
            'purchase_id' => $purchaseId,
            'product_id' => (int) $item['product']['id'],
            'product_name' => (string) $item['product']['name'],
            'sku' => (string) $item['product']['sku'],
            'quantity' => $item['quantity'],
            'received_quantity' => $received ? $item['quantity'] : 0,
            'unit_cost' => $item['unit_cost'],
            'discount_amount' => $item['discount_amount'],
            'tax_rate' => $item['tax_rate'],
            'tax_amount' => $item['tax_amount'],
            'line_total' => $item['line_total'],
        ]);
    }
}

/** @param array<string, mixed> $purchase
 *  @param array{items: list<array<string, mixed>>, subtotal: float, discount: float, tax: float, total: float, inventory_cost: float, expense_cost: float} $calculation
 */
function karoor_purchase_receive(
    Database $database,
    array $user,
    array $purchase,
    array $calculation,
    string $paymentMethod,
    ?int $accountId,
    float $paid
): int {
    $companyId = (int) $user['company_id'];
    $purchaseId = (int) $purchase['id'];
    $supplierId = (int) $purchase['supplier_id'];
    $due = karoor_purchase_money($calculation['total'] - $paid);
    $paymentAccount = karoor_purchase_payment_account($database, $companyId, $paymentMethod, $accountId, $paid);
    foreach ($calculation['items'] as $item) {
        if (!(bool) $item['product']['track_inventory']) {
            continue;
        }
        $before = karoor_purchase_money($item['product']['stock_quantity']);
        $after = karoor_purchase_money($before + $item['quantity']);
        $oldValue = karoor_purchase_money($before * (float) $item['product']['average_cost']);
        $averageCost = $after > 0 ? karoor_purchase_money(($oldValue + $item['net_cost']) / $after) : $item['net_unit_cost'];
        if ($item['product']['inventory_id'] === null) {
            $database->insert('warehouse_inventory', [
                'warehouse_id' => (int) $purchase['warehouse_id'],
                'product_id' => (int) $item['product']['id'],
                'quantity' => $after,
                'reserved_quantity' => 0,
                'average_cost' => $averageCost,
                'last_movement_at' => $purchase['purchase_date'],
            ]);
        } else {
            $database->execute(
                'UPDATE warehouse_inventory SET quantity = :quantity, average_cost = :average_cost,
                 last_movement_at = :movement_at WHERE id = :id',
                ['quantity' => $after, 'average_cost' => $averageCost, 'movement_at' => $purchase['purchase_date'], 'id' => (int) $item['product']['inventory_id']]
            );
        }
        $database->insert('stock_movements', [
            'company_id' => $companyId,
            'warehouse_id' => (int) $purchase['warehouse_id'],
            'product_id' => (int) $item['product']['id'],
            'movement_type' => 'PURCHASE',
            'quantity' => $item['quantity'],
            'quantity_before' => $before,
            'quantity_after' => $after,
            'unit_cost' => $item['net_unit_cost'],
            'reference_type' => 'PURCHASE',
            'reference_id' => $purchaseId,
            'notes' => 'Purchase ' . $purchase['purchase_number'],
            'movement_at' => $purchase['purchase_date'],
            'created_by' => (int) $user['id'],
        ]);
    }

    $entryNumber = Helpers::nextDocumentNumber($database, $companyId, $user['branch_id'] === null ? null : (int) $user['branch_id'], 'JOURNAL', new DateTimeImmutable((string) $purchase['purchase_date']));
    $journalId = $database->insert('journal_entries', [
        'company_id' => $companyId,
        'branch_id' => $purchase['branch_id'],
        'entry_number' => $entryNumber,
        'entry_date' => substr((string) $purchase['purchase_date'], 0, 10),
        'reference_type' => 'PURCHASE',
        'reference_id' => $purchaseId,
        'description' => 'Purchase ' . $purchase['purchase_number'],
        'status' => 'POSTED',
        'posted_at' => $purchase['purchase_date'],
        'posted_by' => (int) $user['id'],
        'created_by' => (int) $user['id'],
    ]);
    $lines = [];
    if ($calculation['inventory_cost'] > 0) {
        $lines[] = [karoor_purchase_coa($database, $companyId, 'INVENTORY'), $calculation['inventory_cost'], 0.0, 'Inventory purchased'];
    }
    if ($calculation['expense_cost'] > 0) {
        $lines[] = [karoor_purchase_coa($database, $companyId, 'GENERAL_EXPENSE'), $calculation['expense_cost'], 0.0, 'Services purchased'];
    }
    if ($calculation['tax'] > 0) {
        $lines[] = [karoor_purchase_coa($database, $companyId, 'TAX_RECEIVABLE'), $calculation['tax'], 0.0, 'Input tax'];
    }
    if ($paid > 0 && $paymentAccount !== null) {
        $lines[] = [(int) $paymentAccount['chart_account_id'], 0.0, $paid, 'Payment made'];
    }
    if ($due > 0) {
        $lines[] = [karoor_purchase_coa($database, $companyId, 'ACCOUNTS_PAYABLE'), 0.0, $due, 'Accounts payable'];
    }
    foreach ($lines as [$chartAccountId, $debit, $credit, $description]) {
        $database->insert('journal_entry_lines', [
            'journal_entry_id' => $journalId,
            'chart_account_id' => $chartAccountId,
            'customer_id' => null,
            'supplier_id' => $supplierId,
            'description' => $description,
            'debit' => $debit,
            'credit' => $credit,
        ]);
    }
    if ($paid > 0 && $paymentAccount !== null) {
        $paymentNumber = Helpers::nextDocumentNumber($database, $companyId, $purchase['branch_id'] === null ? null : (int) $purchase['branch_id'], 'PAYMENT', new DateTimeImmutable((string) $purchase['purchase_date']));
        $paymentId = $database->insert('payments', [
            'company_id' => $companyId,
            'branch_id' => $purchase['branch_id'],
            'account_id' => (int) $paymentAccount['id'],
            'payment_number' => $paymentNumber,
            'direction' => 'OUT',
            'payment_method' => $paymentMethod,
            'amount' => $paid,
            'payment_date' => $purchase['purchase_date'],
            'reference_number' => $purchase['purchase_number'],
            'party_type' => 'SUPPLIER',
            'party_id' => $supplierId,
            'description' => 'Payment for purchase ' . $purchase['purchase_number'],
            'journal_entry_id' => $journalId,
            'created_by' => (int) $user['id'],
        ]);
        $database->insert('purchase_payments', ['purchase_id' => $purchaseId, 'payment_id' => $paymentId, 'amount_applied' => $paid]);
    }
    $database->insert('supplier_transactions', [
        'company_id' => $companyId,
        'supplier_id' => $supplierId,
        'transaction_date' => $purchase['purchase_date'],
        'transaction_type' => 'PURCHASE',
        'reference_type' => 'PURCHASE',
        'reference_id' => $purchaseId,
        'debit' => 0,
        'credit' => $calculation['total'],
        'description' => 'Purchase ' . $purchase['purchase_number'],
        'created_by' => (int) $user['id'],
    ]);
    if ($paid > 0) {
        $database->insert('supplier_transactions', [
            'company_id' => $companyId,
            'supplier_id' => $supplierId,
            'transaction_date' => $purchase['purchase_date'],
            'transaction_type' => 'PAYMENT',
            'reference_type' => 'PURCHASE',
            'reference_id' => $purchaseId,
            'debit' => $paid,
            'credit' => 0,
            'description' => 'Payment for ' . $purchase['purchase_number'],
            'created_by' => (int) $user['id'],
        ]);
    }
    $database->execute(
        'UPDATE purchases SET status = \'RECEIVED\', received_at = :received_at, paid_amount = :paid,
         due_amount = :due, journal_entry_id = :journal_id, updated_by = :user_id WHERE id = :id',
        ['received_at' => $purchase['purchase_date'], 'paid' => $paid, 'due' => $due, 'journal_id' => $journalId, 'user_id' => (int) $user['id'], 'id' => $purchaseId]
    );
    return $journalId;
}

/** @return array<string, mixed> */
function karoor_purchase_detail(Database $database, int $companyId, int $id): array
{
    $purchase = $database->fetchOne(
        'SELECT p.*, s.name AS supplier_name, w.name AS warehouse_name, u.full_name AS created_by_name
         FROM purchases p INNER JOIN suppliers s ON s.id = p.supplier_id
         INNER JOIN warehouses w ON w.id = p.warehouse_id INNER JOIN users u ON u.id = p.created_by
         WHERE p.id = :id AND p.company_id = :company_id AND p.deleted_at IS NULL',
        ['id' => $id, 'company_id' => $companyId]
    );
    if ($purchase === null) {
        throw new DomainException('The purchase was not found.');
    }
    $purchase['items'] = $database->fetchAll('SELECT * FROM purchase_items WHERE purchase_id = :id ORDER BY id', ['id' => $id]);
    $purchase['payments'] = $database->fetchAll(
        'SELECT p.id, p.payment_number, p.payment_method, p.amount, p.payment_date, pp.amount_applied
         FROM purchase_payments pp INNER JOIN payments p ON p.id = pp.payment_id
         WHERE pp.purchase_id = :id AND p.deleted_at IS NULL ORDER BY p.payment_date, p.id',
        ['id' => $id]
    );
    return $purchase;
}

$purchaseMethod = Helpers::requestMethod();
$purchaseUser = $auth->requireAuth();
$purchaseCompanyId = (int) $purchaseUser['company_id'];
$purchaseId = isset($apiSegments[0]) && ctype_digit((string) $apiSegments[0]) ? (int) $apiSegments[0] : null;
$purchaseSubAction = $purchaseId === null ? null : ($apiSegments[1] ?? null);

if ($purchaseMethod === 'GET' && $purchaseId === null) {
    $auth->requirePermission('purchases.view');
    $pagination = Helpers::pagination();
    $where = ['p.company_id = :company_id', 'p.deleted_at IS NULL'];
    $parameters = ['company_id' => $purchaseCompanyId];
    foreach (['status' => 'p.status', 'warehouse_id' => 'p.warehouse_id', 'supplier_id' => 'p.supplier_id'] as $key => $column) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            $where[] = $column . ' = :' . $key;
            $parameters[$key] = $_GET[$key];
        }
    }
    if (isset($_GET['search']) && is_string($_GET['search']) && trim($_GET['search']) !== '') {
        $search = '%' . trim($_GET['search']) . '%';
        $where[] = '(p.purchase_number LIKE :number_search OR p.supplier_invoice_number LIKE :invoice_search OR s.name LIKE :supplier_search)';
        $parameters['number_search'] = $search;
        $parameters['invoice_search'] = $search;
        $parameters['supplier_search'] = $search;
    }
    $whereSql = implode(' AND ', $where);
    $count = $database->fetchOne('SELECT COUNT(*) AS total FROM purchases p INNER JOIN suppliers s ON s.id = p.supplier_id WHERE ' . $whereSql, $parameters);
    $rows = $database->fetchAll(
        'SELECT p.id, p.purchase_number, p.supplier_invoice_number, p.purchase_date, p.status,
                p.subtotal, p.discount_amount, p.tax_amount, p.total_amount, p.paid_amount, p.due_amount,
                s.name AS supplier_name, w.name AS warehouse_name
         FROM purchases p INNER JOIN suppliers s ON s.id = p.supplier_id
         INNER JOIN warehouses w ON w.id = p.warehouse_id WHERE ' . $whereSql . '
         ORDER BY p.purchase_date DESC, p.id DESC
         LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
        $parameters
    );
    Response::success($rows, 'Purchases loaded.', Helpers::paginationMeta((int) ($count['total'] ?? 0), $pagination['page'], $pagination['per_page']));
}

if ($purchaseMethod === 'GET' && $purchaseId !== null && $purchaseSubAction === null) {
    $auth->requirePermission('purchases.view');
    try {
        Response::success(karoor_purchase_detail($database, $purchaseCompanyId, $purchaseId));
    } catch (DomainException $exception) {
        Response::notFound($exception->getMessage());
    }
}

if ($purchaseMethod === 'POST' && $purchaseId === null && in_array($apiAction, ['index', 'create'], true)) {
    $auth->requirePermission('purchases.create');
    $data = karoor_purchase_validate(karoor_purchase_input());
    $status = (string) ($data['status'] ?? 'DRAFT');
    $warehouseId = (int) $data['warehouse_id'];
    $supplierId = (int) $data['supplier_id'];
    $purchaseDate = isset($data['purchase_date']) && $data['purchase_date'] !== null
        ? (strlen((string) $data['purchase_date']) === 10 ? $data['purchase_date'] . ' ' . date('H:i:s') : (string) $data['purchase_date'])
        : date('Y-m-d H:i:s');
    $paymentMethod = (string) ($data['payment_method'] ?? 'CASH');
    try {
        $createdId = $database->transaction(function (Database $db) use ($data, $status, $warehouseId, $supplierId, $purchaseDate, $paymentMethod, $purchaseUser, $purchaseCompanyId, $auditLogger): int {
            $warehouse = karoor_purchase_scope($db, $purchaseCompanyId, $warehouseId, $supplierId);
            $calculation = karoor_purchase_calculate($db, $purchaseCompanyId, $warehouseId, $data['items'], karoor_purchase_money($data['order_discount_amount'] ?? 0), $status === 'RECEIVED');
            $paid = karoor_purchase_money($data['paid_amount'] ?? 0);
            if ($paid > $calculation['total']) {
                throw new DomainException('Paid amount cannot exceed the purchase total.');
            }
            if ($paymentMethod === 'CREDIT' && $paid > 0) {
                throw new DomainException('Credit purchases cannot include an immediate payment.');
            }
            $number = Helpers::nextDocumentNumber($db, $purchaseCompanyId, $warehouse['branch_id'] === null ? null : (int) $warehouse['branch_id'], 'PURCHASE', new DateTimeImmutable($purchaseDate));
            $id = $db->insert('purchases', [
                'company_id' => $purchaseCompanyId,
                'branch_id' => $warehouse['branch_id'],
                'warehouse_id' => $warehouseId,
                'supplier_id' => $supplierId,
                'purchase_number' => $number,
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'purchase_date' => $purchaseDate,
                'status' => $status === 'ORDERED' ? 'ORDERED' : 'DRAFT',
                'subtotal' => $calculation['subtotal'],
                'discount_amount' => $calculation['discount'],
                'tax_amount' => $calculation['tax'],
                'total_amount' => $calculation['total'],
                'paid_amount' => 0,
                'due_amount' => $calculation['total'],
                'notes' => $data['notes'] ?? null,
                'created_by' => (int) $purchaseUser['id'],
            ]);
            karoor_purchase_insert_items($db, $id, $calculation['items'], $status === 'RECEIVED');
            if ($status === 'RECEIVED') {
                karoor_purchase_receive($db, $purchaseUser, [
                    'id' => $id, 'branch_id' => $warehouse['branch_id'], 'warehouse_id' => $warehouseId,
                    'supplier_id' => $supplierId, 'purchase_number' => $number, 'purchase_date' => $purchaseDate,
                ], $calculation, $paymentMethod, isset($data['account_id']) ? (int) $data['account_id'] : null, $paid);
            }
            $auditLogger->log('CREATE', 'purchases', $id, null, ['purchase_number' => $number, 'status' => $status, 'total_amount' => $calculation['total']], 'purchases', (int) $purchaseUser['id'], $purchaseCompanyId);
            return $id;
        });
    } catch (DomainException $exception) {
        Response::error($exception->getMessage(), [], 422);
    }
    Response::success(karoor_purchase_detail($database, $purchaseCompanyId, $createdId), $status === 'RECEIVED' ? 'Purchase received successfully.' : 'Purchase created.', [], 201);
}

if ($purchaseId !== null && $purchaseSubAction === 'receive' && $purchaseMethod === 'POST') {
    $auth->requirePermission('purchases.edit');
    $input = karoor_purchase_input();
    $validator = new Validator($input, [
        'payment_method' => 'sometimes|in:CASH,BANK,MOBILE_MONEY,CREDIT,OTHER',
        'account_id' => 'sometimes|nullable|integer|min:1',
        'paid_amount' => 'sometimes|numeric|min:0|max:999999999999',
    ]);
    if (!$validator->passes()) {
        Response::validation($validator->errors());
    }
    $input = $validator->validated();
    try {
        $database->transaction(function (Database $db) use ($purchaseId, $purchaseCompanyId, $purchaseUser, $input, $auditLogger): void {
            $purchase = $db->fetchOne('SELECT * FROM purchases WHERE id = :id AND company_id = :company_id AND status IN (\'DRAFT\', \'ORDERED\') AND deleted_at IS NULL FOR UPDATE', ['id' => $purchaseId, 'company_id' => $purchaseCompanyId]);
            if ($purchase === null) {
                throw new DomainException('Only a draft or ordered purchase can be received.');
            }
            $stored = $db->fetchAll('SELECT product_id, quantity, unit_cost, discount_amount FROM purchase_items WHERE purchase_id = :id', ['id' => $purchaseId]);
            $calculation = karoor_purchase_calculate($db, $purchaseCompanyId, (int) $purchase['warehouse_id'], $stored, 0, true);
            $paid = karoor_purchase_money($input['paid_amount'] ?? 0);
            if ($paid > $calculation['total']) {
                throw new DomainException('Paid amount cannot exceed the purchase total.');
            }
            $db->execute('DELETE FROM purchase_items WHERE purchase_id = :id', ['id' => $purchaseId]);
            karoor_purchase_insert_items($db, $purchaseId, $calculation['items'], true);
            $db->execute('UPDATE purchases SET subtotal = :subtotal, discount_amount = :discount, tax_amount = :tax, total_amount = :total, due_amount = :due WHERE id = :id', ['subtotal' => $calculation['subtotal'], 'discount' => $calculation['discount'], 'tax' => $calculation['tax'], 'total' => $calculation['total'], 'due' => $calculation['total'], 'id' => $purchaseId]);
            $purchase['purchase_date'] = date('Y-m-d H:i:s');
            karoor_purchase_receive($db, $purchaseUser, $purchase, $calculation, (string) ($input['payment_method'] ?? 'CASH'), isset($input['account_id']) ? (int) $input['account_id'] : null, $paid);
            $auditLogger->log('UPDATE', 'purchases', $purchaseId, ['status' => $purchase['status']], ['status' => 'RECEIVED'], 'purchases', (int) $purchaseUser['id'], $purchaseCompanyId);
        });
    } catch (DomainException $exception) {
        Response::error($exception->getMessage(), [], 422);
    }
    Response::success(karoor_purchase_detail($database, $purchaseCompanyId, $purchaseId), 'Purchase received successfully.');
}

if ($purchaseId !== null && $purchaseSubAction === 'cancel' && $purchaseMethod === 'POST') {
    $auth->requirePermission('purchases.edit');
    $updated = $database->execute(
        'UPDATE purchases SET status = \'CANCELLED\', updated_by = :user_id
         WHERE id = :id AND company_id = :company_id AND status IN (\'DRAFT\', \'ORDERED\') AND deleted_at IS NULL',
        ['user_id' => (int) $purchaseUser['id'], 'id' => $purchaseId, 'company_id' => $purchaseCompanyId]
    );
    if ($updated !== 1) {
        Response::error('Only draft or ordered purchases can be cancelled.', [], 422);
    }
    $auditLogger->log('UPDATE', 'purchases', $purchaseId, null, ['status' => 'CANCELLED'], 'purchases', (int) $purchaseUser['id'], $purchaseCompanyId);
    Response::success(karoor_purchase_detail($database, $purchaseCompanyId, $purchaseId), 'Purchase cancelled.');
}

if ($purchaseId !== null && $purchaseSubAction === null && $purchaseMethod === 'DELETE') {
    $auth->requirePermission('purchases.delete');
    $updated = $database->execute(
        'UPDATE purchases SET deleted_at = NOW(), deleted_by = :user_id
         WHERE id = :id AND company_id = :company_id AND status IN (\'DRAFT\', \'CANCELLED\') AND deleted_at IS NULL',
        ['user_id' => (int) $purchaseUser['id'], 'id' => $purchaseId, 'company_id' => $purchaseCompanyId]
    );
    if ($updated !== 1) {
        Response::error('Only draft or cancelled purchases can be archived.', [], 422);
    }
    $auditLogger->log('DELETE', 'purchases', $purchaseId, null, ['deleted_at' => date('Y-m-d H:i:s')], 'purchases', (int) $purchaseUser['id'], $purchaseCompanyId);
    Response::success(null, 'Purchase moved to the recycle bin.');
}

Response::notFound('The requested purchase endpoint was not found.');
