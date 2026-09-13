<?php
declare(strict_types=1);
use Karoor\Core\Helpers;
$pageTitle='Recycle Bin';$pageDescription='Restore archived records or permanently remove them with a full audit trail.';$breadcrumbs=[['label'=>'System'],'Recycle Bin'];require __DIR__.'/../layouts/header.php';
?>
<div class="alert alert-warning d-flex gap-3" role="alert"><i class="fa-solid fa-triangle-exclamation mt-1" aria-hidden="true"></i><div><strong>Permanent deletion cannot be undone.</strong><br>Restore records whenever possible. Both actions are written to the audit log.</div></div>
<section class="surface-card"><div class="card-header-row"><div><h2>Archived records</h2><p>Core business and HR records that were soft-deleted.</p></div></div><div class="table-responsive"><table class="table align-middle mb-0" id="recycleTable" data-api-table data-endpoint="system/recycle-bin"><thead><tr><th data-field="record_type">Record type</th><th data-field="record_name">Record</th><th data-field="deleted_by">Deleted by (ID)</th><th data-field="deleted_at" data-format="datetime">Deleted at</th><th data-field="id" data-format="actions">Actions</th></tr></thead><tbody></tbody></table></div></section>
<?php require __DIR__.'/../layouts/footer.php'; ?>
