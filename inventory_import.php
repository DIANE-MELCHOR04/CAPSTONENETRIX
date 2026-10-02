<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/inventory_import.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/config/db.php';

require_login();
$user = current_user();
$userId = (int) ($user['id'] ?? 0);
$action = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['action'] ?? 'preview')
    : (string) ($_GET['action'] ?? 'preview');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'template') {
    $batchType = (string) ($_GET['type'] ?? '');
    try {
        $permission = inventory_import_permission($batchType);
    } catch (InvalidArgumentException $exception) {
        http_response_code(400);
        die('Unsupported inventory import type.');
    }
    require_permission($permission);
    $temporary = tempnam(sys_get_temp_dir(), 'stock-template-');
    if ($temporary === false) {
        http_response_code(500);
        die('The spreadsheet template could not be prepared.');
    }
    try {
        write_stock_import_template($temporary);
        $downloadName = $batchType === 'stock_in' ? 'stock-in-import-template.xlsx' : 'stock-out-import-template.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string) filesize($temporary));
        readfile($temporary);
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $returnPage = 'inventory.php';
    try {
        if ($action === 'upload') {
            $batchType = (string) ($_POST['batch_type'] ?? '');
            $returnPage = inventory_import_return_page($batchType);
            require_permission(inventory_import_permission($batchType));
            $result = stage_inventory_import($pdo, $batchType, $_FILES['import_file'] ?? [], $userId);
            flash(
                $result['status'] === 'validated' ? 'success' : 'warning',
                $result['status'] === 'validated'
                    ? 'Spreadsheet validated. Review every row before posting.'
                    : 'The spreadsheet needs attention. Review the import results.'
            );
            redirect('inventory_import.php?batch=' . (int) $result['batch_id']);
        }

        $batchId = (int) ($_POST['batch_id'] ?? 0);
        $batch = get_owned_inventory_import($pdo, $batchId, $userId);
        $returnPage = inventory_import_return_page((string) $batch['batch_type']);
        require_permission(inventory_import_permission((string) $batch['batch_type']));

        if ($action === 'confirm') {
            $token = (string) ($_SESSION['inventory_import_tokens'][(string) $batchId] ?? '');
            $result = confirm_inventory_import($pdo, $batchId, $userId, $token);
            flash('success', $result['already_posted'] ? 'This import was already posted.' : number_format((int) $result['movement_count']) . ' inventory movement(s) posted.');
            redirect($returnPage);
        }
        if ($action === 'cancel') {
            cancel_inventory_import($pdo, $batchId, $userId);
            flash('success', 'The inventory import was cancelled.');
            redirect($returnPage);
        }
        throw new DomainException('Unsupported inventory import action.');
    } catch (Throwable $exception) {
        $message = import_safe_exception_message($exception, 'The inventory import could not be completed.');
        if (!($exception instanceof DomainException) && !($exception instanceof InvalidArgumentException)) {
            error_log('Inventory import controller error: ' . $exception->getMessage());
        }
        flash('danger', $message);
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        redirect($batchId > 0 ? 'inventory_import.php?batch=' . $batchId : $returnPage);
    }
}

$batchId = (int) ($_GET['batch'] ?? 0);
if ($batchId <= 0) {
    redirect('inventory.php');
}

try {
    $batch = get_owned_inventory_import($pdo, $batchId, $userId);
    require_permission(inventory_import_permission((string) $batch['batch_type']));
    $rows = inventory_import_rows($pdo, $batchId);
} catch (Throwable $exception) {
    flash('danger', import_safe_exception_message($exception, 'The inventory import could not be loaded.'));
    redirect('inventory.php');
}

$summary = $batch['summary'];
$validRows = (int) ($summary['valid_rows'] ?? 0);
$invalidRows = (int) ($summary['invalid_rows'] ?? 0);
$warningRows = (int) ($summary['warning_rows'] ?? 0);
$totalQuantity = (string) ($summary['total_quantity'] ?? '0.00');
$fileError = trim((string) ($summary['file_error'] ?? ''));
$isStockIn = $batch['batch_type'] === 'stock_in';
$returnPage = inventory_import_return_page((string) $batch['batch_type']);
$activePage = $isStockIn ? 'stock_in' : 'stock_out';
$title = ($isStockIn ? 'Stock-In' : 'Stock-Out') . ' Import Preview';
$eyebrow = 'INVENTORY IMPORT';
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
            <?= ui_page_intro('Inventory import', $title, 'Review the source rows and validation results before changing inventory.') ?>

            <section class="content-card ops-section">
                <div class="card-heading import-preview-heading">
                    <div>
                        <span class="eyebrow">BATCH #<?= (int) $batch['id'] ?></span>
                        <h2><?= e($batch['file_name']) ?></h2>
                        <p><?= $isStockIn ? 'Incoming stock' : 'Outgoing stock' ?> spreadsheet</p>
                    </div>
                    <span class="workflow-badge import-status-<?= e($batch['status']) ?>"><?= e(ucfirst((string) $batch['status'])) ?></span>
                </div>

                <div class="import-summary-grid">
                    <div><span>Total rows</span><strong><?= number_format((int) $batch['row_count']) ?></strong></div>
                    <div><span>Valid</span><strong><?= number_format($validRows) ?></strong></div>
                    <div><span>Invalid</span><strong><?= number_format($invalidRows) ?></strong></div>
                    <div><span>Warnings</span><strong><?= number_format($warningRows) ?></strong></div>
                    <div><span>Total quantity</span><strong><?= e(money_or_qty($totalQuantity)) ?></strong></div>
                </div>

                <?php if ($warningRows > 0 && $batch['status'] === 'validated'): ?>
                    <div class="import-warning-box" role="status"><strong>Review duplicate-reference warnings</strong><span>Warnings do not block posting because the same document reference can legitimately contain different materials. Exact duplicate transactions are blocked.</span></div>
                <?php endif; ?>

                <?php if ($fileError !== ''): ?>
                    <div class="import-row-error" role="alert"><strong>File issue</strong><span><?= e($fileError) ?></span></div>
                <?php endif; ?>

                <div class="table-wrap import-preview-table">
                    <table class="data modern-table">
                        <thead><tr><th>Row</th><th>Material</th><th>Location</th><th>Quantity</th><th>Reference</th><th>Remarks</th><th>Result</th></tr></thead>
                        <tbody>
                        <?php if (!$rows): ?><tr><td colspan="7" class="muted">No data rows were staged.</td></tr><?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= (int) $row['row_number'] ?></td>
                                <td><b><?= e($row['material_code']) ?></b><small><?= e($row['material_name'] ?? '') ?></small></td>
                                <td><b><?= e($row['location_code']) ?></b><small><?= e($row['location_name'] ?? '') ?></small></td>
                                <td><?= e(money_or_qty($row['quantity'])) ?></td>
                                <td><?= e($row['reference_no'] ?? '') ?></td>
                                <td><?= e($row['remarks'] ?? '') ?></td>
                                <td>
                                    <span class="workflow-badge import-status-<?= e($row['validation_status']) ?>"><?= e(ucfirst((string) $row['validation_status'])) ?></span>
                                    <?php if (!empty($row['error_message'])): ?><small class="<?= str_starts_with((string)$row['error_message'], 'Warning:') ? 'import-row-warning-text' : 'import-row-error-text' ?>"><?= e($row['error_message']) ?></small><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-actions ops-sticky-actions">
                    <a class="btn secondary" href="<?= e($returnPage) ?>">Back</a>
                    <?php if ($batch['status'] !== 'posted'): ?>
                        <form method="post" data-confirm="Cancel this unposted import?" data-confirm-title="Cancel import">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
                            <button class="btn secondary" type="submit">Cancel Batch</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($batch['status'] === 'validated'): ?>
                        <form method="post" data-block-on-submit="Posting inventory import" data-confirm="Post every valid row in this import?" data-confirm-title="Confirm inventory import">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="confirm">
                            <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
                            <button class="btn primary" type="submit">Confirm Import</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
