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

$auth->requirePermission('pos.use');
$posUser = $auth->requireAuth();
$posCompanyId = (int) $posUser['company_id'];
$posMethod = Helpers::requestMethod();
$posAction = $apiAction === 'index' ? 'session' : $apiAction;

/** @return array<string, mixed> */
function karoor_pos_input(): array
{
    try {
        return Helpers::requestData();
    } catch (RuntimeException) {
        Response::error('The request body is invalid.', [], 400);
    }
}

if ($posAction === 'products' && $posMethod === 'GET') {
    $warehouseId = filter_var($_GET['warehouse_id'] ?? $posUser['default_warehouse_id'] ?? null, FILTER_VALIDATE_INT);
    if ($warehouseId === false || $warehouseId < 1 || $database->fetchOne(
        'SELECT id FROM warehouses WHERE id = :id AND company_id = :company_id AND is_active = 1 AND deleted_at IS NULL',
        ['id' => $warehouseId, 'company_id' => $posCompanyId]
    ) === null) {
        Response::validation(['warehouse_id' => ['Select an active warehouse.']]);
    }
    $pagination = Helpers::pagination(40, 100);
    $where = ['p.company_id = :company_id', 'p.is_active = 1', 'p.deleted_at IS NULL'];
    $parameters = ['company_id' => $posCompanyId, 'warehouse_id' => $warehouseId];
    if (isset($_GET['category_id']) && $_GET['category_id'] !== '') {
        $categoryId = filter_var($_GET['category_id'], FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId < 1) {
            Response::validation(['category_id' => ['Category must be a positive integer.']]);
        }
        $where[] = 'p.category_id = :category_id';
        $parameters['category_id'] = $categoryId;
    }
    if (isset($_GET['search']) && is_string($_GET['search']) && trim($_GET['search']) !== '') {
        $search = trim($_GET['search']);
        $where[] = '(p.name LIKE :search_name OR p.sku LIKE :search_sku OR p.barcode = :barcode)';
        $parameters['search_name'] = '%' . $search . '%';
        $parameters['search_sku'] = '%' . $search . '%';
        $parameters['barcode'] = $search;
    }
    $whereSql = implode(' AND ', $where);
    $count = $database->fetchOne('SELECT COUNT(*) AS total FROM products p WHERE ' . $whereSql, array_diff_key($parameters, ['warehouse_id' => true]));
    $products = $database->fetchAll(
        'SELECT p.id, p.name, p.sku, p.barcode, p.product_type, p.selling_price,
                p.wholesale_price, p.image_path AS image_url, c.name AS category_name,
                u.precision_scale, (u.precision_scale > 0) AS allows_decimal,
                COALESCE(t.rate, 0) AS tax_rate, COALESCE(t.is_inclusive, 0) AS tax_inclusive,
                CASE WHEN p.track_inventory = 1 THEN COALESCE(wi.quantity - wi.reserved_quantity, 0) ELSE NULL END AS stock_quantity
         FROM products p
         INNER JOIN units u ON u.id = p.unit_id
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN taxes t ON t.id = p.tax_id AND t.is_active = 1 AND t.deleted_at IS NULL
         LEFT JOIN warehouse_inventory wi ON wi.product_id = p.id AND wi.warehouse_id = :warehouse_id
         WHERE ' . $whereSql . '
         ORDER BY p.name, p.id
         LIMIT ' . $pagination['per_page'] . ' OFFSET ' . $pagination['offset'],
        $parameters
    );
    foreach ($products as &$product) {
        if (is_string($product['image_url']) && $product['image_url'] !== '') {
            $product['image_url'] = Helpers::appPath($config, '/' . ltrim($product['image_url'], '/'));
        }
    }
    unset($product);
    Response::success($products, 'Products loaded.', Helpers::paginationMeta((int) ($count['total'] ?? 0), $pagination['page'], $pagination['per_page']));
}

if ($posAction === 'categories' && $posMethod === 'GET') {
    $categories = $database->fetchAll(
        'SELECT c.id, c.parent_id, c.name,
                COUNT(p.id) AS product_count
         FROM categories c
         LEFT JOIN products p ON p.category_id = c.id AND p.is_active = 1 AND p.deleted_at IS NULL
         WHERE c.company_id = :company_id AND c.is_active = 1 AND c.deleted_at IS NULL
         GROUP BY c.id, c.parent_id, c.name, c.sort_order
         ORDER BY c.sort_order, c.name',
        ['company_id' => $posCompanyId]
    );
    Response::success($categories);
}

if ($posAction === 'registers' && $posMethod === 'GET') {
    $registers = $database->fetchAll(
        'SELECT pr.id, pr.code, pr.name, pr.warehouse_id, w.name AS warehouse_name
         FROM pos_registers pr INNER JOIN warehouses w ON w.id = pr.warehouse_id
         WHERE pr.company_id = :company_id AND pr.is_active = 1 AND pr.deleted_at IS NULL
           AND w.is_active = 1 AND w.deleted_at IS NULL
         ORDER BY pr.name',
        ['company_id' => $posCompanyId]
    );
    Response::success($registers);
}

if ($posAction === 'session' && $posMethod === 'GET') {
    $session = $database->fetchOne(
        'SELECT ps.*, pr.code AS register_code, pr.name AS register_name,
                pr.warehouse_id, w.name AS warehouse_name
         FROM pos_sessions ps
         INNER JOIN pos_registers pr ON pr.id = ps.pos_register_id
         INNER JOIN warehouses w ON w.id = pr.warehouse_id
         WHERE ps.cashier_id = :cashier_id AND pr.company_id = :company_id AND ps.status = \'OPEN\'
         ORDER BY ps.opened_at DESC LIMIT 1',
        ['cashier_id' => (int) $posUser['id'], 'company_id' => $posCompanyId]
    );
    Response::success(['session' => $session]);
}

if ($posAction === 'open' && $posMethod === 'POST') {
    $auth->requirePermission('pos.open');
    $input = karoor_pos_input();
    $validator = new Validator($input, [
        'register_id' => 'required|integer|min:1',
        'opening_cash' => 'required|numeric|min:0|max:999999999999',
        'notes' => 'sometimes|nullable|string|max_length:500',
    ]);
    if (!$validator->passes()) {
        Response::validation($validator->errors());
    }
    $data = $validator->validated();
    try {
        $sessionId = $database->transaction(function (Database $db) use ($data, $posUser, $posCompanyId, $auditLogger): int {
            $existing = $db->fetchOne(
                'SELECT ps.id FROM pos_sessions ps INNER JOIN pos_registers pr ON pr.id = ps.pos_register_id
                 WHERE ps.cashier_id = :cashier_id AND pr.company_id = :company_id AND ps.status = \'OPEN\' FOR UPDATE',
                ['cashier_id' => (int) $posUser['id'], 'company_id' => $posCompanyId]
            );
            if ($existing !== null) {
                throw new DomainException('Close the current POS session before opening another.');
            }
            $register = $db->fetchOne(
                'SELECT id FROM pos_registers
                 WHERE id = :id AND company_id = :company_id AND is_active = 1 AND deleted_at IS NULL FOR UPDATE',
                ['id' => (int) $data['register_id'], 'company_id' => $posCompanyId]
            );
            if ($register === null) {
                throw new DomainException('The selected POS register is unavailable.');
            }
            $inUse = $db->fetchOne(
                'SELECT id FROM pos_sessions WHERE pos_register_id = :register_id AND status = \'OPEN\' FOR UPDATE',
                ['register_id' => (int) $register['id']]
            );
            if ($inUse !== null) {
                throw new DomainException('The selected POS register is already in use.');
            }
            $sessionId = $db->insert('pos_sessions', [
                'pos_register_id' => (int) $register['id'],
                'cashier_id' => (int) $posUser['id'],
                'opened_at' => date('Y-m-d H:i:s'),
                'opening_cash' => round((float) $data['opening_cash'], 4),
                'status' => 'OPEN',
                'opening_notes' => $data['notes'] ?? null,
            ]);
            $auditLogger->log('POS_OPEN', 'pos', $sessionId, null, ['register_id' => (int) $register['id']], 'pos_sessions', (int) $posUser['id'], $posCompanyId);
            return $sessionId;
        });
    } catch (DomainException $exception) {
        Response::error($exception->getMessage(), [], 422);
    }
    $session = $database->fetchOne('SELECT * FROM pos_sessions WHERE id = :id', ['id' => $sessionId]);
    Response::success($session, 'POS session opened.', [], 201);
}

if ($posAction === 'close' && $posMethod === 'POST') {
    $auth->requirePermission('pos.close');
    $input = karoor_pos_input();
    $validator = new Validator($input, [
        'session_id' => 'required|integer|min:1',
        'closing_cash' => 'required|numeric|min:0|max:999999999999',
        'notes' => 'sometimes|nullable|string|max_length:500',
    ]);
    if (!$validator->passes()) {
        Response::validation($validator->errors());
    }
    $data = $validator->validated();
    try {
        $result = $database->transaction(function (Database $db) use ($data, $posUser, $posCompanyId, $auditLogger): array {
            $session = $db->fetchOne(
                'SELECT ps.* FROM pos_sessions ps INNER JOIN pos_registers pr ON pr.id = ps.pos_register_id
                 WHERE ps.id = :id AND ps.cashier_id = :cashier_id AND pr.company_id = :company_id AND ps.status = \'OPEN\' FOR UPDATE',
                ['id' => (int) $data['session_id'], 'cashier_id' => (int) $posUser['id'], 'company_id' => $posCompanyId]
            );
            if ($session === null) {
                throw new DomainException('The open POS session was not found.');
            }
            $cashRow = $db->fetchOne(
                'SELECT COALESCE(SUM(sp.amount_applied), 0) AS cash_sales
                 FROM sales s
                 INNER JOIN sale_payments sp ON sp.sale_id = s.id
                 INNER JOIN payments p ON p.id = sp.payment_id
                 WHERE s.pos_session_id = :session_id AND s.status = \'COMPLETED\'
                   AND p.direction = \'IN\' AND p.payment_method = \'CASH\' AND p.deleted_at IS NULL',
                ['session_id' => (int) $session['id']]
            );
            $expected = round((float) $session['opening_cash'] + (float) ($cashRow['cash_sales'] ?? 0), 4);
            $closing = round((float) $data['closing_cash'], 4);
            $difference = round($closing - $expected, 4);
            $db->execute(
                'UPDATE pos_sessions SET closed_at = NOW(), closing_cash = :closing_cash,
                 expected_cash = :expected_cash, cash_difference = :difference,
                 status = \'CLOSED\', closing_notes = :notes WHERE id = :id',
                ['closing_cash' => $closing, 'expected_cash' => $expected, 'difference' => $difference, 'notes' => $data['notes'] ?? null, 'id' => (int) $session['id']]
            );
            $auditLogger->log('POS_CLOSE', 'pos', (int) $session['id'], ['status' => 'OPEN'], ['status' => 'CLOSED', 'cash_difference' => $difference], 'pos_sessions', (int) $posUser['id'], $posCompanyId);
            return ['id' => (int) $session['id'], 'expected_cash' => $expected, 'closing_cash' => $closing, 'cash_difference' => $difference, 'status' => 'CLOSED'];
        });
    } catch (DomainException $exception) {
        Response::error($exception->getMessage(), [], 422);
    }
    Response::success($result, 'POS session closed.');
}

if ($posAction === 'sales' && $posMethod === 'POST') {
    $input = karoor_pos_input();
    $warehouseId = filter_var($input['warehouse_id'] ?? null, FILTER_VALIDATE_INT);
    $posSession = $database->fetchOne(
        'SELECT ps.id, pr.warehouse_id
         FROM pos_sessions ps INNER JOIN pos_registers pr ON pr.id = ps.pos_register_id
         WHERE ps.cashier_id = :cashier_id AND pr.company_id = :company_id
           AND ps.status = \'OPEN\' AND pr.warehouse_id = :warehouse_id
         ORDER BY ps.opened_at DESC LIMIT 1',
        ['cashier_id' => (int) $posUser['id'], 'company_id' => $posCompanyId, 'warehouse_id' => $warehouseId === false ? 0 : $warehouseId]
    );
    if ($posSession === null) {
        Response::error('Open the POS register for the selected warehouse before completing a sale.', [], 422);
    }
    $posMode = true;
    $posInput = $input;
    $apiAction = 'create';
    $apiSegments = [];
    require __DIR__ . '/sales.php';
}

header('Allow: GET, POST');
Response::notFound('The requested POS endpoint was not found.');
