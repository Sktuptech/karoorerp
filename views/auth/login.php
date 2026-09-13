<?php

declare(strict_types=1);

use Karoor\Core\Helpers;
use Karoor\Core\Validator;

$loginError = null;
$loginIdentifier = '';
$nextPath = isset($_GET['next']) && is_string($_GET['next']) ? $_GET['next'] : '';

if (Helpers::requestMethod() === 'POST') {
    $loginIdentifier = is_string($_POST['identifier'] ?? null) ? trim($_POST['identifier']) : '';
    $nextPath = is_string($_POST['next'] ?? null) ? $_POST['next'] : '';

    if (!karoor_verify_csrf(is_string($_POST['_csrf_token'] ?? null) ? $_POST['_csrf_token'] : null)) {
        http_response_code(419);
        $loginError = 'Your session expired. Refresh the page and try again.';
    } else {
        $validator = new Validator($_POST, [
            'identifier' => 'required|string|max_length:190',
            'password' => 'required|string|max_length:4096',
            'remember' => 'sometimes|boolean',
        ], ['identifier' => 'Email or username']);

        if (!$validator->passes()) {
            http_response_code(422);
            $loginError = $validator->firstError() ?? 'Please check your login details.';
        } elseif ($auth->login(
            $loginIdentifier,
            (string) $_POST['password'],
            filter_var($_POST['remember'] ?? false, FILTER_VALIDATE_BOOL)
        )) {
            $signedInUser = $auth->requireAuth();
            $parsedNext = parse_url($nextPath);
            $safeNext = !(bool) $signedInUser['force_password_change'] && is_array($parsedNext)
                && !isset($parsedNext['scheme'], $parsedNext['host'])
                && str_starts_with($nextPath, '/')
                && !str_starts_with($nextPath, '//')
                ? $nextPath
                : Helpers::appPath(
                    $config,
                    (bool) $signedInUser['force_password_change'] ? '/change-password' : '/dashboard'
                );
            Helpers::safeRedirect($safeNext);
        } else {
            http_response_code(401);
            $loginError = 'Invalid login credentials or too many attempts. Please try again later.';
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
    <meta name="csrf-token" content="<?= Helpers::escape(karoor_csrf_token()) ?>">
    <title>Sign in · <?= Helpers::escape((string) $config['app']['name']) ?></title>
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
        <section class="login-showcase" aria-label="Karoor ERP overview">
            <div class="login-grid" aria-hidden="true"></div>
            <div class="showcase-content">
                <a class="brand login-brand" href="<?= Helpers::escape(Helpers::appPath($config, '/login')) ?>">
                    <span class="brand-mark">K</span>
                    <span class="brand-copy"><strong>Karoor ERP</strong><small>Business operating system</small></span>
                </a>
                <div class="showcase-message">
                    <span class="showcase-kicker"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Built for growing businesses</span>
                    <h1>Run every part of your business with clarity.</h1>
                    <p>Sales, inventory, finance, people, and reporting—connected in one secure workspace.</p>
                    <div class="showcase-features">
                        <span><i class="fa-solid fa-chart-line" aria-hidden="true"></i> Real-time insights</span>
                        <span><i class="fa-solid fa-warehouse" aria-hidden="true"></i> Multi-warehouse control</span>
                        <span><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Role-based security</span>
                    </div>
                </div>
                <p class="showcase-footnote"><i class="fa-solid fa-lock" aria-hidden="true"></i> Your session is encrypted when served over HTTPS.</p>
            </div>
        </section>

        <section class="login-panel">
            <div class="login-card">
                <div class="login-mobile-brand">
                    <span class="brand-mark" aria-hidden="true">K</span>
                    <strong>Karoor ERP</strong>
                </div>
                <div class="login-heading">
                    <span class="login-welcome">Welcome back</span>
                    <h2>Sign in to your workspace</h2>
                    <p>Use your email address or username to continue.</p>
                </div>

<?php if ($loginError !== null): ?>
                    <div class="login-alert" role="alert">
                        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                        <span><?= Helpers::escape($loginError) ?></span>
                    </div>
<?php endif; ?>

                <form class="login-form" method="post" action="<?= Helpers::escape(Helpers::appPath($config, '/login')) ?>" novalidate>
                    <input type="hidden" name="_csrf_token" value="<?= Helpers::escape(karoor_csrf_token()) ?>">
                    <input type="hidden" name="next" value="<?= Helpers::escape($nextPath) ?>">
                    <div class="form-field">
                        <label for="loginIdentifier">Email or username</label>
                        <div class="input-shell">
                            <i class="fa-regular fa-user" aria-hidden="true"></i>
                            <input id="loginIdentifier" name="identifier" type="text" value="<?= Helpers::escape($loginIdentifier) ?>" placeholder="you@company.com" maxlength="190" autocomplete="username" required autofocus>
                        </div>
                    </div>
                    <div class="form-field">
                        <label for="loginPassword">Password</label>
                        <div class="input-shell">
                            <i class="fa-solid fa-key" aria-hidden="true"></i>
                            <input id="loginPassword" name="password" type="password" placeholder="Enter your password" maxlength="4096" autocomplete="current-password" required>
                        </div>
                    </div>
                    <label class="remember-control">
                        <input type="checkbox" name="remember" value="1">
                        <span>Keep me signed in on this device</span>
                    </label>
                    <button class="login-submit" type="submit">
                        <span>Sign in securely</span>
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </button>
                </form>

                <p class="login-help">Need access? Contact your Karoor ERP administrator.</p>
            </div>
            <p class="login-copyright">&copy; <?= date('Y') ?> <?= Helpers::escape((string) $config['app']['name']) ?>. All rights reserved.</p>
        </section>
    </main>
    <script type="module" src="<?= Helpers::escape(Helpers::appPath($config, '/assets/js/app.js')) ?>"></script>
</body>
</html>
