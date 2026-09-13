<?php

declare(strict_types=1);

use Karoor\Core\Helpers;

if (!isset($config) || !is_array($config)) {
    throw new RuntimeException('The application footer requires application configuration.');
}
?>
            </div>
        </main>
        <footer class="app-footer">
            <span>&copy; <?= date('Y') ?> <?= Helpers::escape((string) $config['app']['name']) ?></span>
            <span class="footer-status"><i class="fa-solid fa-circle" aria-hidden="true"></i> Secure session</span>
        </footer>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer" aria-live="polite" aria-atomic="true"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script type="module" src="<?= Helpers::escape(Helpers::appPath($config, '/assets/js/app.js')) ?>"></script>
</body>
</html>
