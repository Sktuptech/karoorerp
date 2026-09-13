<?php

declare(strict_types=1);

use Karoor\Core\Helpers;
use Karoor\Core\Validator;

$passwordUser = $auth->requireAuth();
$passwordError = null;

if (Helpers::requestMethod() === 'POST') {
    if (!karoor_verify_csrf(is_string($_POST['_csrf_token'] ?? null) ? $_POST['_csrf_token'] : null)) {
        http_response_code(419);
        $passwordError = 'Your session expired. Refresh the page and try again.';
    } else {
        $validator = new Validator($_POST, [
            'current_password' => 'required|string|max_length:4096',
            'new_password' => 'required|string|min_length:12|max_length:4096|different:current_password',
            'new_password_confirmation' => 'required|string|same:new_password',
        ], [
            'current_password' => 'Current password',
            'new_password' => 'New password',
            'new_password_confirmation' => 'Password confirmation',
        ]);

        if (!$validator->passes()) {
            http_response_code(422);
            $passwordError = $validator->firstError() ?? 'Please check the password fields.';
        } else {
            $data = $validator->validated();
            if (!$auth->changePassword((string) $data['current_password'], (string) $data['new_password'])) {
                http_response_code(422);
                $passwordError = 'The current password is incorrect.';
            } else {
                Helpers::safeRedirect(Helpers::appPath($config, '/dashboard'));
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b1120">
    <title>Secure your account · <?= Helpers::escape((string) $config['app']['name']) ?></title>
    <script src="<?= Helpers::escape(Helpers::appPath($config, '/assets/js/theme.js')) ?>"></script>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="<?= Helpers::escape(Helpers::appPath($config, '/assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="login-body">
    <button class="icon-button auth-theme-toggle" type="button" data-theme-toggle aria-label="Switch to light mode" title="Switch to light mode"><i class="fa-solid fa-sun" data-theme-icon aria-hidden="true"></i></button>
    <main class="login-shell">
        <section class="login-showcase" aria-label="Account security">
            <div class="login-grid" aria-hidden="true"></div>
            <div class="showcase-content">
                <span class="brand login-brand">
                    <span class="brand-mark">K</span>
                    <span class="brand-copy"><strong>Karoor ERP</strong><small>Secure workspace</small></span>
                </span>
                <div class="showcase-message">
                    <span class="showcase-kicker"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Required security step</span>
                    <h1>Protect your administrator account.</h1>
                    <p>Replace the one-time bootstrap password before accessing company data or administration tools.</p>
                </div>
            </div>
        </section>

        <section class="login-panel">
            <div class="login-card">
                <div class="login-heading">
                    <span class="login-welcome">Signed in as <?= Helpers::escape((string) $passwordUser['username']) ?></span>
                    <h2>Create a new password</h2>
                    <p>Use at least 12 characters and do not reuse the bootstrap password.</p>
                </div>

<?php if ($passwordError !== null): ?>
                <div class="login-alert" role="alert">
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                    <span><?= Helpers::escape($passwordError) ?></span>
                </div>
<?php endif; ?>

                <form class="login-form" method="post" action="<?= Helpers::escape(Helpers::appPath($config, '/change-password')) ?>">
                    <input type="hidden" name="_csrf_token" value="<?= Helpers::escape(karoor_csrf_token()) ?>">
                    <div class="form-field">
                        <label for="currentPassword">Current password</label>
                        <div class="input-shell"><i class="fa-solid fa-key" aria-hidden="true"></i><input id="currentPassword" name="current_password" type="password" maxlength="4096" autocomplete="current-password" required autofocus></div>
                    </div>
                    <div class="form-field">
                        <label for="newPassword">New password</label>
                        <div class="input-shell"><i class="fa-solid fa-lock" aria-hidden="true"></i><input id="newPassword" name="new_password" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required></div>
                    </div>
                    <div class="form-field">
                        <label for="confirmPassword">Confirm new password</label>
                        <div class="input-shell"><i class="fa-solid fa-lock" aria-hidden="true"></i><input id="confirmPassword" name="new_password_confirmation" type="password" minlength="12" maxlength="4096" autocomplete="new-password" required></div>
                    </div>
                    <button class="login-submit" type="submit"><span>Update password</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
                </form>
            </div>
        </section>
    </main>
    <script type="module" src="<?= Helpers::escape(Helpers::appPath($config, '/assets/js/app.js')) ?>"></script>
</body>
</html>
