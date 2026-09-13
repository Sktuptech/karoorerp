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

/** @return array<string, mixed> */
function karoor_sales_input(): array
{
    try {
        return Helpers::requestData();
    } catch (RuntimeException) {
        Response::error('The request body is invalid.', [], 400);
    }
}

function karoor_sales_money(mixed $value): float
{
    return round((float) $value, 4);
}

function karoor_sales_valid_date(mixed $value): bool
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
function karoor_sales_validate(array $input): array
{
    $validator = new Validator($input, [
        'warehouse_id' => 'required|integer|min:1',
        'customer_id' => 'sometimes|nullable|integer|min:1',
        'salesperson_id' => 'sometimes|nullable|integer|min:1',
        'sale_date' => ['sometimes', 'nullable', static function (mixed $value): ?string {
            if ($value === null || $value === '') {
                return null;
            }
            return karoor_sales_valid_date($value) ? null : 'Sale date must use Y-m-d or Y-m-d H:i:s format.';
        }],
        'status' => 'sometimes|in:DRAFT,COMPLETED',
        'payment_method' => 'sometimes|in:CASH,BANK,MOBILE_MONEY,CREDIT,OTHER',
        'account_id' => 'sometimes|nullable|integer|min:1',
        'paid_amount' => 'sometimes|numeric|min:0|max:999999999999',
        'order_discount_amount' => 'sometimes|numeric|min:0|max:999999999999',
        'notes' => 'sometimes|nullable|string|max_length:5000',
        'items' => 'required|list|min:1|max:200',
        'items.*.product_id' => 'required|integer|min:1',
        'items.*.quantity' => 'required|numeric|min:0.0001|max:999999999',
        'items.*.unit_price' => 'sometimes|nullable|numeric|min:0|max:999999999999',
        'items.*.discount_amount' => 'sometimes|numeric|min:0|max:999999999999',
    ]);
    if (!$validator->passes()) {
        Response::validation($validator->errors());
    }
    return $validator->validated();
}

/** @return array<string, mixed> */
function karoor_sales_scope(
    Database $database,
    int $companyId,
    int $warehouseId,
    ?int $customerId,
    int $salespersonId
): array {
    $warehouse = $database->fetchOne(
        'SELECT id, branch_id FROM warehouses
         WHERE id = :id AND company_id = :company_id AND is_active = 1 AND deleted_at IS NULL',
        ['id' => $warehouseId, 'company_id' => $companyId]
    );
    if ($warehouse === null) {
        throw new DomainException('The selected warehouse is unavailable.');
    }
    if ($customerId !== null && $database->fetchOne(
        'SELECT id FROM customers WHERE id = :id AND company_id = :company_id AND is_active = 1 AND deleted_at IS NULL',
        ['id' => $customerId, 'company_id' => $companyId]
    ) === null) {
        throw new DomainException('The selected customer is unavailable.');
    }
    if ($database->fetchOne(
        'SELECT id FROM users WHERE id = :id AND company_id = :company_id AND status = \'ACTIVE\' AND deleted_at IS NULL',
        ['id' => $salespersonId, 'company_id' => $companyId]
    ) === null) {
        throw new DomainException('The selected salesperson is unavailable.');
    }
    return $warehouse;
}

/** @param list<array<string, mixed>> $requestedItems
 *  @return array{items: list<array<string, mixed>>, subtotal: float, discount: float, tax: float, total: float, cost: float}
 */
function karoor_sales_calculate(
    Database $database,
    int $companyId,
    int $warehouseId,
    array $requestedItems,
    float $orderDiscount,
    bool $lockInventory = false
): array {
    $productIds = array_map(static fn (array $item): int => (int) $item['product_id'], $requestedItems);
    if (count($productIds) !== count(array_unique($productIds))) {
        throw new DomainException('Each product may appear only once in a sale.');
    }
    $placeholders = [];
    $parameters = ['company_id' => $companyId, 'warehouse_id' => $warehouseId];
    foreach ($productIds as $index => $productId) {
        $key = 'product_' . $index;
        $placeholders[] = ':' . $key;
        $parameters[$key] = $productId;
    }
    if ($lockInventory) {
        $lockParameters = ['warehouse_id' => $warehouseId];
        foreach ($productIds as $index => $productId) {
            $lockParameters['product_' . $index] = $productId;
        }
        $database->fetchAll(
            'SELECT id FROM warehouse_inventory
             WHERE warehouse_id = :warehouse_id AND product_id IN (' . implode(', ', $placeholders) . ')
             FOR UPDATE',
            $lockParameters
        );
    }
    $rows = $database->fetchAll(
        'SELECT p.id, p.name, p.sku, p.product_type, p.selling_price, p.purchase_price,
                p.track_inventory, COALESCE(t.rate, 0) AS tax_rate,
                COALESCE(t.is_inclusive, 0) AS tax_inclusive,
                wi.id AS inventory_id, COALESCE(wi.quantity, 0) AS stock_quantity,
                COALESCE(wi.reserved_quantity, 0) AS reserved_quantity,
                COALESCE(wi.average_cost, p.purchase_price) AS average_cost
         FROM products p
         LEFT JOIN taxes t ON t.id = p.tax_id AND t.company_id = p.company_id AND t.is_active = 1 AND t.deleted_at IS NULL
         LEFT JOIN warehouse_inventory wi ON wi.product_id = p.id AND wi.warehouse_id = :warehouse_id
         WHERE p.company_id = :company_id AND p.is_active = 1 AND p.deleted_at IS NULL
           AND p.id IN (' . implode(', ', $placeholders) . ')',
        $parameters
    );
    if (count($rows) !== count($productIds)) {
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
        $quantity = karoor_sales_money($requested['quantity']);
        $unitPrice = array_key_exists('unit_price', $requested) && $requested['unit_price'] !== null
            ? karoor_sales_money($requested['unit_price'])
            : karoor_sales_money($product['selling_price']);
        $gross = karoor_sales_money($quantity * $unitPrice);
        $lineDiscount = karoor_sales_money($requested['discount_amount'] ?? 0);
        if ($lineDiscount > $gross) {
            throw new DomainException('A line discount cannot exceed its product total.');
        }
        $afterLineDiscount = karoor_sales_money($gross - $lineDiscount);
        $taxRate = karoor_sales_money($product['tax_rate']);
        $rate = $taxRate / 100;
        $inclusive = (bool) $product['tax_inclusive'];
        $taxBeforeOrder = $inclusive && $rate > 0
            ? karoor_sales_money($afterLineDiscount - ($afterLineDiscount / (1 + $rate)))
            : karoor_sales_money($afterLineDiscount * $rate);
        $netBeforeOrder = $inclusive ? karoor_sales_money($afterLineDiscount - $taxBeforeOrder) : $afterLineDiscount;
        $subtotal = karoor_sales_money($subtotal + $gross);
        $lineDiscountTotal = karoor_sales_money($lineDiscountTotal + $lineDiscount);
        $discountable = karoor_sales_money($discountable + $afterLineDiscount);
        $items[] = [
            'product' => $product,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'gross' => $gross,
            'line_discount' => $lineDiscount,
            'discountable' => $afterLineDiscount,
            'tax_rate' => $taxRate,
            'tax_before_order' => $taxBeforeOrder,
            'net_before_order' => $netBeforeOrder,
        ];
    }
    if ($orderDiscount > $discountable) {
        throw new DomainException('The order discount cannot exceed the sale subtotal.');
    }
    $ratio = $discountable > 0 ? ($discountable - $orderDiscount) / $discountable : 0.0;
    $taxTotal = 0.0;
    $total = 0.0;
    $costTotal = 0.0;
    $allocatedOrder = 0.0;
    foreach ($items as $index => &$item) {
        $isLast = $index === array_key_last($items);
        $itemOrderDiscount = $isLast
            ? karoor_sales_money($orderDiscount - $allocatedOrder)
            : karoor_sales_money($orderDiscount * ($item['discountable'] / max($discountable, 0.0001)));
        $allocatedOrder = karoor_sales_money($allocatedOrder + $itemOrderDiscount);
        $tax = karoor_sales_money($item['tax_before_order'] * $ratio);
        $lineTotal = karoor_sales_money(($item['net_before_order'] + $item['tax_before_order']) * $ratio);
        $costPrice = karoor_sales_money($item['product']['average_cost']);
        $item['discount_amount'] = karoor_sales_money($item['line_discount'] + $itemOrderDiscount);
        $item['tax_amount'] = $tax;
        $item['line_total'] = $lineTotal;
        $item['cost_price'] = $costPrice;
        $taxTotal = karoor_sales_money($taxTotal + $tax);
        $total = karoor_sales_money($total + $lineTotal);
        if ((bool) $item['product']['track_inventory']) {
            $costTotal = karoor_sales_money($costTotal + ($costPrice * $item['quantity']));
        }
    }
    unset($item);
    return [
        'items' => $items,
        'subtotal' => $subtotal,
        'discount' => karoor_sales_money($lineDiscountTotal + $orderDiscount),
        'tax' => $taxTotal,
        'total' => $total,
        'cost' => $costTotal,
    ];
}

function karoor_sales_coa(Database $database, int $companyId, string $subtype): int
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
function karoor_sales_payment_account(
    Database $database,
    int $companyId,
    string $method,
    ?int $accountId,
    float $paidAmount
): ?array {
    if ($paidAmount <= 0) {
        return null;
    }
    $kind = match ($method) {
        'CASH' => 'CASH',
        'BANK' => 'BANK',
        'MOBILE_MONEY' => 'MOBILE_MONEY',
        default => 'OTHER',
    };
    if ($accountId !== null) {
        $account = $database->fetchOne(
            'SELECT id, chart_account_id, account_kind FROM accounts
             WHERE id = :id AND company_id = :company_id AND is_active = 1 AND deleted_at IS NULL',
            ['id' => $accountId, 'company_id' => $companyId]
        );
        if ($account === null) {
            throw new DomainException('The selected payment account is unavailable.');
        }
        return $account;
    }
    $account = $database->fetchOne(
        'SELECT id, chart_account_id, account_kind FROM accounts
         WHERE company_id = :company_id AND account_kind = :kind AND is_active = 1 AND deleted_at IS NULL
         ORDER BY is_default DESC, id LIMIT 1',
        ['company_id' => $companyId, 'kind' => $kind]
    );
    if ($account === null) {
        throw new DomainException("No active {$kind} payment account is configured.");
    }
    return $account;
}

/** @param list<array{account_id: int, debit: float, credit: float, description: string, customer_id?: int|null}> $lines */
function karoor_sales_journal(
    Database $database,
    array $user,
    int $saleId,
    string $invoiceNumber,
    string $saleDate,
    array $lines
): int {
    $entryNumber = Helpers::nextDocumentNumber(
        $database,
        (int) $user['company_id'],
        $user['branch_id'] === null ? null : (int) $user['branch_id'],
        'JOURNAL',
        new DateTimeImmutable($saleDate)
    );
    $journalId = $database->insert('journal_entries', [
        'company_id' => (int) $user['company_id'],
        'branch_id' => $user['branch_id'],
        'entry_number' => $entryNumber,
        'entry_date' => substr($saleDate, 0, 10),
        'reference_type' => 'SALE',
        'reference_id' => $saleId,
        'description' => 'Sale ' . $invoiceNumber,
        'status' => 'POSTED',
        'posted_at' => $saleDate,
        'posted_by' => (int) $user['id'],
        'created_by' => (int) $user['id'],
    ]);
    foreach ($lines as $line) {
        if ($line['debit'] <= 0 && $line['credit'] <= 0) {
            continue;
        }
        $database->insert('journal_entry_lines', [
            'journal_entry_id' => $journalId,
            'chart_account_id' => $line['account_id'],
            'customer_id' => $line['customer_id'] ?? null,
            'supplier_id' => null,
            'description' => $line['description'],
            'debit' => $line['debit'],
            'credit' => $line['credit'],
        ]);
    }
    return $journalId;
}

/** @param array<string, mixed> $sale
 *  @param array{items: list<array<string, mixed>>, subtotal: float, discount: float, tax: float, total: float, cost: float} $calculation
 */
function karoor_sales_post(
    Database $database,
    array $user,
    array $sale,
    array $calculation,
    string $paymentMethod,
    ?int $accountId,
    float $paidAmount
): int {
    $companyId = (int) $user['company_id'];
    $saleId = (int) $sale['id'];
    $customerId = $sale['customer_id'] === null ? null : (int) $sale['customer_id'];
    $dueAmount = karoor_sales_money($calculation['total'] - $paidAmount);
    $paymentAccount = karoor_sales_payment_account($database, $companyId, $paymentMethod, $accountId, $paidAmount);

    foreach ($calculation['items'] as $item) {
        if (!(bool) $item['product']['track_inventory']) {
            continue;
        }
        $inventoryId = $item['product']['inventory_id'];
        $available = karoor_sales_money((float) $item['product']['stock_quantity'] - (float) $item['product']['reserved_quantity']);
        if ($inventoryId === null || $available < $item['quantity']) {
            throw new DomainException('Insufficient stock for ' . $item['product']['name'] . '.');
        }
        $before = karoor_sales_money($item['product']['stock_quantity']);
        $after = karoor_sales_money($before - $item['quantity']);
        $database->execute(
            'UPDATE warehouse_inventory
             SET quantity = :quantity, last_movement_at = :movement_at
             WHERE id = :id',
            ['quantity' => $after, 'movement_at' => $sale['sale_date'], 'id' => (int) $inventoryId]
        );
        $database->insert('stock_movements', [
            'company_id' => $companyId,
            'warehouse_id' => (int) $sale['warehouse_id'],
            'product_id' => (int) $item['product']['id'],
            'movement_type' => 'SALE',
            'quantity' => $item['quantity'],
            'quantity_before' => $before,
            'quantity_after' => $after,
            'unit_cost' => $item['cost_price'],
            'reference_type' => 'SALE',
            'reference_id' => $saleId,
            'notes' => 'Sale ' . $sale['invoice_number'],
            'movement_at' => $sale['sale_date'],
            'created_by' => (int) $user['id'],
        ]);
    }

    $lines = [];
    if ($paidAmount > 0 && $paymentAccount !== null) {
        $lines[] = ['account_id' => (int) $paymentAccount['chart_account_id'], 'debit' => $paidAmount, 'credit' => 0.0, 'description' => 'Payment received', 'customer_id' => $customerId];
    }
    if ($dueAmount > 0) {
        $lines[] = ['account_id' => karoor_sales_coa($database, $companyId, 'ACCOUNTS_RECEIVABLE'), 'debit' => $dueAmount, 'credit' => 0.0, 'description' => 'Accounts receivable', 'customer_id' => $customerId];
    }
    $revenue = karoor_sales_money($calculation['total'] - $calculation['tax']);
    if ($revenue > 0) {
        $lines[] = ['account_id' => karoor_sales_coa($database, $companyId, 'SALES'), 'debit' => 0.0, 'credit' => $revenue, 'description' => 'Sales revenue'];
    }
    if ($calculation['tax'] > 0) {
        $lines[] = ['account_id' => karoor_sales_coa($database, $companyId, 'TAX_PAYABLE'), 'debit' => 0.0, 'credit' => $calculation['tax'], 'description' => 'Output tax'];
    }
    if ($calculation['cost'] > 0) {
        $lines[] = ['account_id' => karoor_sales_coa($database, $companyId, 'COST_OF_GOODS_SOLD'), 'debit' => $calculation['cost'], 'credit' => 0.0, 'description' => 'Cost of goods sold'];
        $lines[] = ['account_id' => karoor_sales_coa($database, $companyId, 'INVENTORY'), 'debit' => 0.0, 'credit' => $calculation['cost'], 'description' => 'Inventory issued'];
    }
    $journalId = karoor_sales_journal($database, $user, $saleId, (string) $sale['invoice_number'], (string) $sale['sale_date'], $lines);

    if ($paidAmount > 0 && $paymentAccount !== null) {
        $paymentNumber = Helpers::nextDocumentNumber($database, $companyId, $user['branch_id'] === null ? null : (int) $user['branch_id'], 'PAYMENT', new DateTimeImmutable((string) $sale['sale_date']));
        $paymentId = $database->insert('payments', [
            'company_id' => $companyId,
            'branch_id' => $user['branch_id'],
            'account_id' => (int) $paymentAccount['id'],
            'payment_number' => $paymentNumber,
            'direction' => 'IN',
            'payment_method' => $paymentMethod,
            'amount' => $paidAmount,
            'payment_date' => $sale['sale_date'],
            'reference_number' => $sale['invoice_number'],
            'party_type' => $customerId === null ? null : 'CUSTOMER',
            'party_id' => $customerId,
            'description' => 'Payment for sale ' . $sale['invoice_number'],
            'journal_entry_id' => $journalId,
            'created_by' => (int) $user['id'],
        ]);
        $database->insert('sale_payments', ['sale_id' => $saleId, 'payment_id' => $paymentId, 'amount_applied' => $paidAmount]);
    }
    if ($customerId !== null) {
        if ($calculation['total'] > 0) {
            $database->insert('customer_transactions', [
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'transaction_date' => $sale['sale_date'],
                'transaction_type' => 'SALE',
                'reference_type' => 'SALE',
                'reference_id' => $saleId,
                'debit' => $calculation['total'],
                'credit' => 0,
                'description' => 'Sale ' . $sale['invoice_number'],
                'created_by' => (int) $user['id'],
            ]);
        }
        if ($paidAmount > 0) {
            $database->insert('customer_transactions', [
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'transaction_date' => $sale['sale_date'],
                'transaction_type' => 'PAYMENT',
                'reference_type' => 'SALE',
                'reference_id' => $saleId,
                'debit' => 0,
                'credit' => $paidAmount,
                'description' => 'Payment for ' . $sale['invoice_number'],
                'created_by' => (int) $user['id'],
            ]);
        }
    }
    $database->execute(
        'UPDATE sales SET status = \'COMPLETED\', paid_amount = :paid, due_amount = :due,
                completed_at = :completed_at, journal_entry_id = :journal_id, updated_by = :updated_by
         WHERE id = :id',
        [
            'paid' => $paidAmount,
            'due' => $dueAmount,
            'completed_at' => $sale['sale_date'],
            'journal_id' => $journalId,
            'updated_by' => (int) $user['id'],
            'id' => $saleId,
        ]
    );
    return $journalId;
}

/** @param list<array<string, mixed>> $items */
function karoor_sales_insert_items(Database $database, int $saleId, array $items): void
{
    foreach ($items as $item) {
        $database->insert('sale_items', [
            'sale_id' => $saleId,
            'product_id' => (int) $item['product']['id'],
            'product_name' => (string) $item['product']['name'],
            'sku' => (string) $item['product']['sku'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'cost_price' => $item['cost_price'],
            'discount_amount' => $item['discount_amount'],
            'tax_rate' => $item['tax_rate'],
            'tax_amount' => $item['tax_amount'],
            'line_total' => $item['line_total'],
        ]);
    }
}

/** @param array<string, mixed> $sale */
function karoor_sales_refund(Database $database, array $user, array $sale): void
{
    $companyId = (int) $user['company_id'];
    $saleId = (int) $sale['id'];
    $refundedAt = date('Y-m-d H:i:s');
    $items = $database->fetchAll(
        'SELECT si.product_id, si.product_name, si.quantity, si.cost_price, p.track_inventory
         FROM sale_items si INNER JOIN products p ON p.id = si.product_id
         WHERE si.sale_id = :sale_id',
        ['sale_id' => $saleId]
    );
    foreach ($items as $item) {
        if (!(bool) $item['track_inventory']) {
            continue;
        }
        $inventory = $database->fetchOne(
            'SELECT id, quantity, average_cost FROM warehouse_inventory
             WHERE warehouse_id = :warehouse_id AND product_id = :product_id FOR UPDATE',
            ['warehouse_id' => (int) $sale['warehouse_id'], 'product_id' => (int) $item['product_id']]
        );
        if ($inventory === null) {
            throw new DomainException('Inventory record is missing for ' . $item['product_name'] . '.');
        }
        $before = karoor_sales_money($inventory['quantity']);
        $after = karoor_sales_money($before + (float) $item['quantity']);
        $averageCost = $after > 0
            ? karoor_sales_money((($before * (float) $inventory['average_cost']) + ((float) $item['quantity'] * (float) $item['cost_price'])) / $after)
            : 0.0;
        $database->execute(
            'UPDATE warehouse_inventory SET quantity = :quantity, average_cost = :average_cost,
             last_movement_at = :movement_at WHERE id = :id',
            ['quantity' => $after, 'average_cost' => $averageCost, 'movement_at' => $refundedAt, 'id' => (int) $inventory['id']]
        );
        $database->insert('stock_movements', [
            'company_id' => $companyId,
            'warehouse_id' => (int) $sale['warehouse_id'],
            'product_id' => (int) $item['product_id'],
            'movement_type' => 'RETURN',
            'quantity' => $item['quantity'],
            'quantity_before' => $before,
            'quantity_after' => $after,
            'unit_cost' => $item['cost_price'],
            'reference_type' => 'SALE_REFUND',
            'reference_id' => $saleId,
            'notes' => 'Refund ' . $sale['invoice_number'],
            'movement_at' => $refundedAt,
            'created_by' => (int) $user['id'],
        ]);
    }

    $journal = $database->fetchOne(
        'SELECT * FROM journal_entries WHERE id = :id AND company_id = :company_id AND status = \'POSTED\' FOR UPDATE',
        ['id' => (int) $sale['journal_entry_id'], 'company_id' => $companyId]
    );
    if ($journal === null) {
        throw new DomainException('The posted accounting entry for this sale is unavailable.');
    }
    $entryNumber = Helpers::nextDocumentNumber($database, $companyId, $sale['branch_id'] === null ? null : (int) $sale['branch_id'], 'JOURNAL', new DateTimeImmutable($refundedAt));
    $reversalId = $database->insert('journal_entries', [
        'company_id' => $companyId,
        'branch_id' => $sale['branch_id'],
        'entry_number' => $entryNumber,
        'entry_date' => substr($refundedAt, 0, 10),
        'reference_type' => 'SALE_REFUND',
        'reference_id' => $saleId,
        'description' => 'Refund ' . $sale['invoice_number'],
        'status' => 'POSTED',
        'posted_at' => $refundedAt,
        'posted_by' => (int) $user['id'],
        'created_by' => (int) $user['id'],
    ]);
    $journalLines = $database->fetchAll('SELECT * FROM journal_entry_lines WHERE journal_entry_id = :id', ['id' => (int) $journal['id']]);
    foreach ($journalLines as $line) {
        $database->insert('journal_entry_lines', [
            'journal_entry_id' => $reversalId,
            'chart_account_id' => (int) $line['chart_account_id'],
            'customer_id' => $line['customer_id'],
            'supplier_id' => $line['supplier_id'],
            'description' => 'Reversal: ' . (string) $line['description'],
            'debit' => $line['credit'],
            'credit' => $line['debit'],
        ]);
    }
    $database->execute(
        'UPDATE journal_entries SET status = \'REVERSED\', reversed_entry_id = :reversal_id WHERE id = :id',
        ['reversal_id' => $reversalId, 'id' => (int) $journal['id']]
    );

    $payments = $database->fetchAll(
        'SELECT p.*, sp.amount_applied FROM sale_payments sp INNER JOIN payments p ON p.id = sp.payment_id
         WHERE sp.sale_id = :sale_id AND p.deleted_at IS NULL FOR UPDATE',
        ['sale_id' => $saleId]
    );
    foreach ($payments as $payment) {
        $paymentNumber = Helpers::nextDocumentNumber($database, $companyId, $sale['branch_id'] === null ? null : (int) $sale['branch_id'], 'PAYMENT', new DateTimeImmutable($refundedAt));
        $database->insert('payments', [
            'company_id' => $companyId,
            'branch_id' => $sale['branch_id'],
            'account_id' => (int) $payment['account_id'],
            'payment_number' => $paymentNumber,
            'direction' => 'OUT',
            'payment_method' => $payment['payment_method'],
            'amount' => $payment['amount_applied'],
            'payment_date' => $refundedAt,
            'reference_number' => $sale['invoice_number'],
            'party_type' => $sale['customer_id'] === null ? null : 'CUSTOMER',
            'party_id' => $sale['customer_id'],
            'description' => 'Refund for sale ' . $sale['invoice_number'],
            'journal_entry_id' => $reversalId,
            'created_by' => (int) $user['id'],
        ]);
    }
    if ($sale['customer_id'] !== null && (float) $sale['total_amount'] > 0) {
        $database->insert('customer_transactions', [
            'company_id' => $companyId,
            'customer_id' => (int) $sale['customer_id'],
            'transaction_date' => $refundedAt,
            'transaction_type' => 'REFUND',
            'reference_type' => 'SALE_REFUND',
            'reference_id' => $saleId,
            'debit' => 0,
            'credit' => (float) $sale['total_amount'],
            'description' => 'Refund ' . $sale['invoice_number'],
            'created_by' => (int) $user['id'],
        ]);
        if ((float) $sale['paid_amount'] > 0) {
            $database->insert('customer_transactions', [
                'company_id' => $companyId,
                'customer_id' => (int) $sale['customer_id'],
                'transaction_date' => $refundedAt,
                'transaction_type' => 'REFUND',
                'reference_type' => 'SALE_REFUND',
                'reference_id' => $saleId,
                'debit' => (float) $sale['paid_amount'],
                'credit' => 0,
                'description' => 'Payment returned for ' . $sale['invoice_number'],
                'created_by' => (int) $user['id'],
            ]);
        }
    }
    $database->execute(
        'UPDATE sales SET status = \'REFUNDED\', due_amount = 0, updated_by = :user_id WHERE id = :id',
        ['user_id' => (int) $user['id'], 'id' => $saleId]
    );
}

/** @return array<string, mixed> */
function karoor_sales_detail(Database $database, int $companyId, int $saleId): array
{
    $sale = $database->fetchOne(
        'SELECT s.*, c.name AS customer_name, w.name AS warehouse_name, u.full_name AS salesperson_name
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         INNER JOIN warehouses w ON w.id = s.warehouse_id
         INNER JOIN users u ON u.id = s.salesperson_id
         WHERE s.id = :id AND s.company_id = :company_id AND s.deleted_at IS NULL',
        ['id' => $saleId, 'company_id' => $companyId]
    );
    if ($sale === null) {
        throw new DomainException('The sale was not found.');
    }
    $sale['items'] = $database->fetchAll('SELECT * FROM sale_items WHERE sale_id = :sale_id ORDER BY id', ['sale_id' => $saleId]);
    $sale['payments'] = $database->fetchAll(
        'SELECT p.id, p.payment_number, p.payment_method, p.amount, p.payment_date, sp.amount_applied
         FROM sale_payments sp INNER JOIN payments p ON p.id = sp.payment_id
         WHERE sp.sale_id = :sale_id AND p.deleted_at IS NULL ORDER BY p.payment_date, p.id',
        ['sale_id' => $saleId]
    );
    return $sale;
}

$salesMethod = Helpers::requestMethod();
$salesUser = $auth->requireAuth();
$salesCompanyId = (int) $salesUser['company_id'];
$salesId = isset($apiSegments[0]) && ctype_digit((string) $apiSegments[0]) ? (int) $apiSegments[0] : null;
$salesSubAction = $salesId !== null ? ($apiSegments[1] ?? null) : null;

if ($salesMethod === 'GET' && $salesId === null) {
    if (!$auth->canAny(['sales.view', 'sales.view_own'])) {
        Response::forbidden();
    }
    $pagination = Helpers::pagination();
    $where = ['s.company_id = :company_id', 's.deleted_at IS NULL'];
    $parameters = ['company_id' => $salesCompanyId];
    if (!$auth->can('sales.view')) {
        $where[] = 's.salesperson_id = :salesperson_id';
        $parameters['salesperson_id'] = (int) $salesUser['id'];
    }
    foreach (['status' => 's.status', 'warehouse_id' => 's.warehouse_id', 'customer_id' => 's.customer_id'] as $parameter => $column) {
        if (isset($_GET[$parameter]) && $_GET[$parameter] !== '') {
            $where[] = $column . ' = :' . $parameter;
            $parameters[$parameter] = $_GET[$parameter];
        }
    }
    if (isset($_GET['start_date']) && is_string($_GET['start_date']) && $_GET['start_date'] !== '') {
        $where[] = 's.sale_date >= :start_date';
        $parameters['start_date'] = $_GET['start_date'];
    }
    if (isset($_GET['end_date']) && is_string($_GET['end_date']) && $_GET['end_date'] !== '') {
        $where[] = 's.sale_date < DATE_ADD(:end_date, INTERVAL 1 DAY)';
        $parameters['end_date'] = $_GET['end_date'];
    }
    if (isset($_GET['search']) && is_string($_GET['search']) && trim($_GET['search']) !== '') {
        $search = '%' . trim($_GET['search']) . '%';
        $where[] = '(s.invoice_number LIKE :search_invoice OR c.name LIKE :search_customer)';
        $parameters['search_invoice'] = $search;
        $parameters['search_customer'] = $search;
    }
    $sortColumns = ['sale_date' => 's.sale_date', 'invoice_number' => 's.invoice_number', 'total_amount' => 's.total_amount', 'status' => 's.status'];
    $sort = is_string($_GET['sort'] ?? null) && isset($sortColumns[$_GET['sort']]) ? $sortColumns[$_GET['sort']] : 's.sale_date';
    $direction = strtolower((string) ($_GET['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $whereSql = implode(' AND ', $where);
    $count = $database->fetchOne(
        'SELECT COUNT(*) AS total FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE ' . $whereSql,
        $parameters
    );
    $rows = $database->fetchAll(
        'SELECT s.id, s.invoice_number, s.sale_date, s.status, s.subtotal, s.discount_amount,
                s.tax_amount, s.total_amount, s.paid_amount, s.due_amount,
                c.name AS customer_name, w.name AS warehouse_name, u.full_name AS salesperson_name
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         INNER JOIN warehouses w ON w.id = s.warehouse_id
         INNER JOIN users u ON u.id = s.salesperson_id
         WHERE ' . $whereSql . " ORDER BY {$sort} {$direction}, s.id {$direction}
         LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
        $parameters
    );
    Response::success($rows, 'Sales loaded.', Helpers::paginationMeta((int) ($count['total'] ?? 0), $pagination['page'], $pagination['per_page']));
}

if ($salesMethod === 'GET' && $salesId !== null && $salesSubAction === null) {
    if (!$auth->canAny(['sales.view', 'sales.view_own'])) {
        Response::forbidden();
    }
    try {
        $sale = karoor_sales_detail($database, $salesCompanyId, $salesId);
    } catch (DomainException $exception) {
        Response::notFound($exception->getMessage());
    }
    if (!$auth->can('sales.view') && (int) $sale['salesperson_id'] !== (int) $salesUser['id']) {
        Response::forbidden();
    }
    Response::success($sale);
}

if ($salesMethod === 'POST' && $salesId === null && in_array($apiAction, ['index', 'create'], true)) {
    $auth->requirePermission('sales.create');
    $requestInput = isset($posInput) && is_array($posInput) ? $posInput : karoor_sales_input();
    $data = karoor_sales_validate($requestInput);
    $isPosSale = isset($posMode) && $posMode === true;
    if ($isPosSale) {
        $data['status'] = 'COMPLETED';
    }
    $status = (string) ($data['status'] ?? 'DRAFT');
    $warehouseId = (int) $data['warehouse_id'];
    $customerId = isset($data['customer_id']) && $data['customer_id'] !== null ? (int) $data['customer_id'] : null;
    $salespersonId = isset($data['salesperson_id']) && $data['salesperson_id'] !== null
        ? (int) $data['salesperson_id']
        : (int) $salesUser['id'];
    if ($salespersonId !== (int) $salesUser['id'] && !$auth->can('sales.view')) {
        Response::forbidden('You cannot create a sale for another salesperson.');
    }
    $saleDate = isset($data['sale_date']) && $data['sale_date'] !== null
        ? (strlen((string) $data['sale_date']) === 10 ? $data['sale_date'] . ' ' . date('H:i:s') : (string) $data['sale_date'])
        : date('Y-m-d H:i:s');
    $paymentMethod = (string) ($data['payment_method'] ?? 'CASH');
    $accountId = isset($data['account_id']) && $data['account_id'] !== null ? (int) $data['account_id'] : null;
    $salesPosSession = isset($posSession) && is_array($posSession) ? $posSession : null;
    if ($isPosSale && $salesPosSession === null) {
        Response::error('An open POS session is required.', [], 422);
    }
    try {
        $saleId = $database->transaction(function (Database $db) use (
            $salesUser,
            $salesCompanyId,
            $data,
            $status,
            $warehouseId,
            $customerId,
            $salespersonId,
            $saleDate,
            $paymentMethod,
            $accountId,
            $isPosSale,
            $auditLogger,
            $salesPosSession
        ): int {
            $warehouse = karoor_sales_scope($db, $salesCompanyId, $warehouseId, $customerId, $salespersonId);
            $calculation = karoor_sales_calculate(
                $db,
                $salesCompanyId,
                $warehouseId,
                $data['items'],
                karoor_sales_money($data['order_discount_amount'] ?? 0),
                $status === 'COMPLETED'
            );
            $paidAmount = karoor_sales_money($data['paid_amount'] ?? 0);
            if ($paidAmount > $calculation['total']) {
                throw new DomainException('Paid amount cannot exceed the sale total.');
            }
            if ($calculation['total'] - $paidAmount > 0 && $customerId === null) {
                throw new DomainException('A customer is required for a sale with an outstanding balance.');
            }
            if ($paymentMethod === 'CREDIT' && $paidAmount > 0) {
                throw new DomainException('Credit sales cannot include an immediate payment.');
            }
            $invoiceNumber = Helpers::nextDocumentNumber($db, $salesCompanyId, $warehouse['branch_id'] === null ? null : (int) $warehouse['branch_id'], 'SALE', new DateTimeImmutable($saleDate));
            $saleId = $db->insert('sales', [
                'company_id' => $salesCompanyId,
                'branch_id' => $warehouse['branch_id'],
                'warehouse_id' => $warehouseId,
                'customer_id' => $customerId,
                'salesperson_id' => $salespersonId,
                'pos_session_id' => $isPosSale ? (int) $salesPosSession['id'] : null,
                'invoice_number' => $invoiceNumber,
                'sale_date' => $saleDate,
                'status' => 'DRAFT',
                'subtotal' => $calculation['subtotal'],
                'discount_amount' => $calculation['discount'],
                'tax_amount' => $calculation['tax'],
                'total_amount' => $calculation['total'],
                'paid_amount' => 0,
                'due_amount' => $calculation['total'],
                'notes' => $data['notes'] ?? null,
                'created_by' => (int) $salesUser['id'],
            ]);
            karoor_sales_insert_items($db, $saleId, $calculation['items']);
            $sale = [
                'id' => $saleId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $customerId,
                'invoice_number' => $invoiceNumber,
                'sale_date' => $saleDate,
            ];
            if ($status === 'COMPLETED') {
                karoor_sales_post($db, $salesUser, $sale, $calculation, $paymentMethod, $accountId, $paidAmount);
            }
            $auditLogger->log('CREATE', 'sales', $saleId, null, [
                'invoice_number' => $invoiceNumber,
                'status' => $status,
                'total_amount' => $calculation['total'],
            ], 'sales', (int) $salesUser['id'], $salesCompanyId);
            return $saleId;
        });
    } catch (DomainException $exception) {
        Response::error($exception->getMessage(), [], 422);
    }
    $sale = karoor_sales_detail($database, $salesCompanyId, $saleId);
    $sale['receipt_url'] = Helpers::appPath($config, '/print/receipt?id=' . $saleId);
    Response::success($sale, $status === 'COMPLETED' ? 'Sale completed successfully.' : 'Sale draft created.', [], 201);
}

if ($salesId !== null && $salesSubAction === 'complete' && $salesMethod === 'POST') {
    $auth->requirePermission('sales.edit');
    $input = karoor_sales_input();
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
        $database->transaction(function (Database $db) use ($salesId, $salesCompanyId, $salesUser, $input, $auditLogger): void {
            $sale = $db->fetchOne('SELECT * FROM sales WHERE id = :id AND company_id = :company_id AND deleted_at IS NULL FOR UPDATE', ['id' => $salesId, 'company_id' => $salesCompanyId]);
            if ($sale === null || $sale['status'] !== 'DRAFT') {
                throw new DomainException('Only a draft sale can be completed.');
            }
            $storedItems = $db->fetchAll('SELECT product_id, quantity, unit_price, discount_amount FROM sale_items WHERE sale_id = :sale_id', ['sale_id' => $salesId]);
            $calculation = karoor_sales_calculate($db, $salesCompanyId, (int) $sale['warehouse_id'], $storedItems, 0, true);
            $paidAmount = karoor_sales_money($input['paid_amount'] ?? 0);
            if ($paidAmount > $calculation['total']) {
                throw new DomainException('Paid amount cannot exceed the sale total.');
            }
            if ($calculation['total'] - $paidAmount > 0 && $sale['customer_id'] === null) {
                throw new DomainException('A customer is required for a sale with an outstanding balance.');
            }
            $db->execute('DELETE FROM sale_items WHERE sale_id = :sale_id', ['sale_id' => $salesId]);
            karoor_sales_insert_items($db, $salesId, $calculation['items']);
            $db->execute(
                'UPDATE sales SET subtotal = :subtotal, discount_amount = :discount, tax_amount = :tax,
                 total_amount = :total, due_amount = :total_due WHERE id = :id',
                ['subtotal' => $calculation['subtotal'], 'discount' => $calculation['discount'], 'tax' => $calculation['tax'], 'total' => $calculation['total'], 'total_due' => $calculation['total'], 'id' => $salesId]
            );
            $sale['sale_date'] = date('Y-m-d H:i:s');
            karoor_sales_post(
                $db,
                $salesUser,
                $sale,
                $calculation,
                (string) ($input['payment_method'] ?? 'CASH'),
                isset($input['account_id']) ? (int) $input['account_id'] : null,
                $paidAmount
            );
            $auditLogger->log('UPDATE', 'sales', $salesId, ['status' => 'DRAFT'], ['status' => 'COMPLETED'], 'sales', (int) $salesUser['id'], $salesCompanyId);
        });
    } catch (DomainException $exception) {
        Response::error($exception->getMessage(), [], 422);
    }
    Response::success(karoor_sales_detail($database, $salesCompanyId, $salesId), 'Sale completed successfully.');
}

if ($salesId !== null && $salesSubAction === 'cancel' && $salesMethod === 'POST') {
    $auth->requirePermission('sales.edit');
    $updated = $database->execute(
        'UPDATE sales SET status = \'CANCELLED\', updated_by = :user_id
         WHERE id = :id AND company_id = :company_id AND status = \'DRAFT\' AND deleted_at IS NULL',
        ['user_id' => (int) $salesUser['id'], 'id' => $salesId, 'company_id' => $salesCompanyId]
    );
    if ($updated !== 1) {
        Response::error('Only a draft sale can be cancelled.', [], 422);
    }
    $auditLogger->log('UPDATE', 'sales', $salesId, ['status' => 'DRAFT'], ['status' => 'CANCELLED'], 'sales', (int) $salesUser['id'], $salesCompanyId);
    Response::success(karoor_sales_detail($database, $salesCompanyId, $salesId), 'Sale cancelled.');
}

if ($salesId !== null && $salesSubAction === 'refund' && $salesMethod === 'POST') {
    $auth->requirePermission('sales.refund');
    try {
        $database->transaction(function (Database $db) use ($salesId, $salesCompanyId, $salesUser, $auditLogger): void {
            $sale = $db->fetchOne(
                'SELECT * FROM sales WHERE id = :id AND company_id = :company_id
                 AND status = \'COMPLETED\' AND deleted_at IS NULL FOR UPDATE',
                ['id' => $salesId, 'company_id' => $salesCompanyId]
            );
            if ($sale === null) {
                throw new DomainException('Only a completed sale can be refunded.');
            }
            karoor_sales_refund($db, $salesUser, $sale);
            $auditLogger->log('REFUND', 'sales', $salesId, ['status' => 'COMPLETED'], ['status' => 'REFUNDED'], 'sales', (int) $salesUser['id'], $salesCompanyId);
        });
    } catch (DomainException $exception) {
        Response::error($exception->getMessage(), [], 422);
    }
    Response::success(karoor_sales_detail($database, $salesCompanyId, $salesId), 'Sale refunded successfully.');
}

if ($salesId !== null && $salesSubAction === null && $salesMethod === 'DELETE') {
    $auth->requirePermission('sales.delete');
    $updated = $database->execute(
        'UPDATE sales SET deleted_at = NOW(), deleted_by = :user_id
         WHERE id = :id AND company_id = :company_id AND status IN (\'DRAFT\', \'CANCELLED\') AND deleted_at IS NULL',
        ['user_id' => (int) $salesUser['id'], 'id' => $salesId, 'company_id' => $salesCompanyId]
    );
    if ($updated !== 1) {
        Response::error('Only draft or cancelled sales can be archived.', [], 422);
    }
    $auditLogger->log('DELETE', 'sales', $salesId, null, ['deleted_at' => date('Y-m-d H:i:s')], 'sales', (int) $salesUser['id'], $salesCompanyId);
    Response::success(null, 'Sale moved to the recycle bin.');
}

Response::notFound('The requested sales endpoint was not found.');
