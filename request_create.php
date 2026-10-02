<?php
require_once __DIR__ . '/lib/requests.php';
require_once __DIR__ . '/lib/master_data.php';
require_permission('create_requests');
require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $siteId = current_user_site_id() ?? 0;
    if ($siteId <= 0) { flash('danger', 'Your account must be assigned to a site before creating a request.'); redirect('request_create.php'); }
    $submit = (string) ($_POST['action'] ?? 'draft') === 'submit';
    $materials = $_POST['material_id'] ?? [];
    $qtys = $_POST['quantity'] ?? [];

    try {
        $pdo->beginTransaction();
        $requestNo = next_request_no($pdo);
        $pdo->prepare(
            "INSERT INTO material_requests (request_no, requested_by, site_id, request_date, date_needed, priority, remarks, status, submitted_at)
             VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)"
        )->execute([
            $requestNo,
            (int) current_user()['id'],
            $siteId,
            (string) $_POST['date_needed'],
            (string) $_POST['priority'],
            trim((string) ($_POST['remarks'] ?? '')),
            $submit ? 'submitted' : 'draft',
            $submit ? date('Y-m-d H:i:s') : null,
        ]);
        $requestId = (int) $pdo->lastInsertId();
        $seenMaterials = [];
        $itemStmt = $pdo->prepare('INSERT INTO material_request_items (request_id, material_id, requested_qty, item_status) VALUES (?, ?, ?, ?)');
        foreach ($materials as $index => $materialId) {
            $qty = (float) ($qtys[$index] ?? 0);
            if ((int) $materialId > 0 && $qty > 0) {
                $materialKey = (int) $materialId;
                if (isset($seenMaterials[$materialKey])) { throw new DomainException('Duplicate material line blocked. Add each material only once per request.'); }
                $seenMaterials[$materialKey] = true;
                assert_operational_material($pdo, (int) $materialId);
                $itemStmt->execute([$requestId, (int) $materialId, $qty, $submit ? 'submitted' : 'draft']);
            }
        }
        $pdo->commit();
        flash('success', 'Material request saved.');
        redirect('request_view.php?id=' . $requestId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception->getMessage());
        redirect('request_create.php');
    }
}

$siteOptions = array_values(array_filter(active_options($pdo, 'sites'), static fn(array $site): bool => (int) $site['id'] === (int) (current_user_site_id() ?? 0)));
$materialOptions = active_options($pdo, 'materials', 'material_name');
$activePage = 'request_create';
$title = 'Create Material Request';
$eyebrow = 'MATERIAL REQUEST';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <?php require __DIR__ . '/partials/assets.php'; ?>
</head>
<body>
<div class="app-layout">
    <?php require __DIR__ . '/partials/sidebar.php'; ?>
    <main class="app-main">
        <?php require __DIR__ . '/partials/header.php'; ?>
        <div class="page-content ops-guided-page">
            <?= ui_page_intro('Material request', 'Create Material Request', 'Define the site need, add material quantities, then review before saving or submitting.') ?>

            <form method="post" class="ops-guided-form" data-guided-form data-block-on-submit="Saving request">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="ops-guided-progress" aria-label="Request creation progress">
                    <span data-guided-progress>Step 1 of 3</span>
                    <ol>
                        <li class="active" data-guided-indicator="0"><b>1</b><span>Request details</span></li>
                        <li data-guided-indicator="1"><b>2</b><span>Material lines</span></li>
                        <li data-guided-indicator="2"><b>3</b><span>Review</span></li>
                    </ol>
                </div>

                <fieldset class="ops-form-step" data-form-step="details">
                    <div class="ops-form-step-heading"><span>Step 1</span><h2>Request details</h2><p>Choose the destination, needed date, and urgency for this material request.</p></div>
                    <div class="form-grid three">
                        <label>Site<select name="site_id" required><?php foreach ($siteOptions as $site): ?><option value="<?= (int) $site['id'] ?>" <?= (int) ($_SESSION['site_id'] ?? 0) === (int) $site['id'] ? 'selected' : '' ?>><?= e($site['label']) ?></option><?php endforeach; ?></select></label>
                        <label>Date Needed<input type="date" name="date_needed" required value="<?= e(date('Y-m-d', strtotime('+7 days'))) ?>"></label>
                        <label>Priority<select name="priority"><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option><option value="low">Low</option></select></label>
                        <label class="full">Remarks<textarea name="remarks" placeholder="Project activity, delivery instructions, or other context"></textarea></label>
                    </div>
                    <div class="ops-step-actions"><a class="btn secondary" href="requests.php">Cancel</a><button class="btn primary" type="button" data-step-next>Continue to Materials</button></div>
                </fieldset>

                <fieldset class="ops-form-step" data-form-step="materials">
                    <div class="ops-form-step-heading"><span>Step 2</span><h2>Material lines</h2><p>Add each required material and its requested quantity.</p></div>
                    <div class="ops-section-heading">
                        <div><span class="eyebrow">Request lines</span><h2>Materials</h2></div>
                        <button class="btn secondary compact" type="button" data-add-row="#requestRows" data-template="#requestRowTemplate">Add Row</button>
                    </div>
                    <div class="ops-table-shell"><div class="table-wrap">
                        <table class="data modern-table ops-table">
                            <thead><tr><th>Material</th><th>Quantity</th></tr></thead>
                            <tbody id="requestRows"><tr><td><select name="material_id[]" required><?php foreach ($materialOptions as $material): ?><option value="<?= (int) $material['id'] ?>"><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" min="0.01" step="0.01" name="quantity[]" required></td></tr></tbody>
                        </table>
                    </div></div>
                    <div class="ops-step-actions"><button class="btn secondary" type="button" data-step-back>Back</button><button class="btn primary" type="button" data-step-next>Review Request</button></div>
                </fieldset>

                <fieldset class="ops-form-step" data-form-step="review">
                    <div class="ops-form-step-heading"><span>Step 3</span><h2>Review and submit</h2><p>Confirm the site, timing, and all material quantities before sending the request for approval.</p></div>
                    <div class="ops-review-notice">
                        <strong>Choose the next state</strong>
                        <p>Save Draft keeps the request editable. Submit Request sends it into the approval and stock-check workflow.</p>
                    </div>
                    <div class="ops-step-actions ops-sticky-actions"><button class="btn secondary" type="button" data-step-back>Back</button><button class="btn secondary" name="action" value="draft" type="submit">Save Draft</button><button class="btn primary" name="action" value="submit" type="submit" data-confirm="Submit this material request for approval?" data-confirm-title="Submit request">Submit Request</button></div>
                </fieldset>
            </form>

            <template id="requestRowTemplate"><tr><td><select name="material_id[]" required><?php foreach ($materialOptions as $material): ?><option value="<?= (int) $material['id'] ?>"><?= e($material['label']) ?></option><?php endforeach; ?></select></td><td><input type="number" min="0.01" step="0.01" name="quantity[]" required></td></tr></template>
        </div>
    </main>
</div>
</body>
</html>
