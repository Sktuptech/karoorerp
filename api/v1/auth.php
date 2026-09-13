<?php

declare(strict_types=1);

use Karoor\Core\Helpers;
use Karoor\Core\Response;
use Karoor\Core\Validator;

if (!defined('KAROOR_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

$method = Helpers::requestMethod();
$action = $apiAction === 'index' ? ($method === 'POST' ? 'login' : 'me') : $apiAction;

if ($action === 'login' && $method === 'POST') {
    try {
        $input = Helpers::requestData();
    } catch (RuntimeException) {
        Response::error('The request body is invalid.', [], 400);
    }

    $validator = new Validator($input, [
        'identifier' => 'required|string|max_length:190',
        'password' => 'required|string|max_length:4096',
        'remember' => 'sometimes|boolean',
        'company_id' => 'sometimes|nullable|integer|min:1',
    ], ['identifier' => 'Email or username']);
    if (!$validator->passes()) {
        Response::validation($validator->errors());
    }
    $data = $validator->validated();
    $companyId = isset($data['company_id']) && $data['company_id'] !== null ? (int) $data['company_id'] : null;
    if (!$auth->login(
        (string) $data['identifier'],
        (string) $data['password'],
        (bool) ($data['remember'] ?? false),
        $companyId
    )) {
        Response::error('Invalid login credentials or too many attempts. Please try again later.', [], 401);
    }

    $user = $auth->requireAuth();
    Response::success([
        'user' => $user,
        'roles' => $auth->roles(),
        'permissions' => $auth->permissions(),
        'csrf_token' => karoor_csrf_token(),
        'redirect' => Helpers::appPath(
            $config,
            (bool) $user['force_password_change'] ? '/change-password' : '/dashboard'
        ),
    ], 'Signed in successfully.');
}

if ($action === 'me' && $method === 'GET') {
    $user = $auth->requireAuth();
    Response::success([
        'user' => $user,
        'roles' => $auth->roles(),
        'permissions' => $auth->permissions(),
        'csrf_token' => karoor_csrf_token(),
    ]);
}

if ($action === 'logout' && $method === 'POST') {
    $auth->requireAuth();
    $auth->logout();
    Response::success(null, 'Signed out successfully.');
}

if ($action === 'change-password' && $method === 'POST') {
    $auth->requireAuth();
    try {
        $input = Helpers::requestData();
    } catch (RuntimeException) {
        Response::error('The request body is invalid.', [], 400);
    }

    $validator = new Validator($input, [
        'current_password' => 'required|string|max_length:4096',
        'new_password' => 'required|string|min_length:12|max_length:4096|different:current_password',
        'new_password_confirmation' => 'required|string|same:new_password',
    ], [
        'current_password' => 'Current password',
        'new_password' => 'New password',
        'new_password_confirmation' => 'Password confirmation',
    ]);
    if (!$validator->passes()) {
        Response::validation($validator->errors());
    }
    $data = $validator->validated();
    if (!$auth->changePassword((string) $data['current_password'], (string) $data['new_password'])) {
        Response::error('The current password is incorrect.', ['current_password' => ['The current password is incorrect.']], 422);
    }
    Response::success(['csrf_token' => karoor_csrf_token()], 'Password changed successfully.');
}

header('Allow: GET, POST');
Response::error('The requested authentication action is not available.', [], 405);
