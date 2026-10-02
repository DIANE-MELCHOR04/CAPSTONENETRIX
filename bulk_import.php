<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bulk_import.php';
require_once __DIR__ . '/config/db.php';

require_login();
$user = current_user();
$userId = (int) ($user['id'] ?? 0);
$action = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['action'] ?? 'preview')
    : (string) ($_GET['action'] ?? 'preview');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'template') {
    $type = (string) ($_GET['type'] ?? '');
    try {
        $definition = bulk_import_definition($type);
        require_bulk_import_permission($type);
        $path = __DIR__ . '/templates/' . $type . '_import_template.xlsx';
        if (!is_file($path)) {
            http_response_code(404);
            die('The import template is unavailable.');
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $type . '-import-template.xlsx"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
    } catch (Throwable $e) {
        http_response_code(400);
        die($e instanceof DomainException || $e instanceof InvalidArgumentException ? e($e->getMessage()) : 'The import template could not be prepared.');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $returnPage = 'dashboard.php';
    try {
        if ($action === 'upload') {
            $type = (string) ($_POST['import_type'] ?? '');
            $definition = bulk_import_definition($type);
            $returnPage = (string) $definition['return'];
            require_bulk_import_permission($type);
            $result = stage_bulk_import($pdo, $type, $_FILES['import_file'] ?? [], $userId);
            flash(
                $result['status'] === 'validated' ? 'success' : 'warning',
                $result['status'] === 'validated'
                    ? 'Spreadsheet validated. Review the rows before confirming the import.'
                    : 'The spreadsheet needs attention. Review the validation results and upload a corrected file.'
            );
            redirect('bulk_import.php?batch=' . (int) $result['batch_id']);
        }

        $batchId = (int) ($_POST['batch_id'] ?? 0);
        $batch = get_owned_bulk_import($pdo, $batchId, $userId);
        $definition = bulk_import_definition((string) $batch['batch_type']);
        $returnPage = (string) $definition['return'];
        require_bulk_import_permission((string) $batch['batch_type']);

        if ($action === 'confirm') {
            $result = confirm_bulk_import($pdo, $batchId, $userId);
            flash('success', $result['already_posted']
                ? 'This import was already posted.'
                : number_format((int) $result['created']) . ' record group(s) created from the spreadsheet.');
            redirect($returnPage);
        }
        if ($action === 'cancel') {
            cancel_bulk_import($pdo, $batchId, $userId);
            flash('success', 'The bulk import was cancelled.');
            redirect($returnPage);
        }
        throw new DomainException('Unsupported bulk import action.');
    } catch (Throwable $e) {
        if (!($e instanceof DomainException) && !($e instanceof InvalidArgumentException)) {
            error_log('Bulk import controller error: ' . $e->getMessage());
        }
        flash('danger', import_safe_exception_message($e, 'The bulk import could not be completed.'));
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        redirect($batchId > 0 ? 'bulk_import.php?batch=' . $batchId : $returnPage);
    }
}

$batchId = (int) ($_GET['batch'] ?? 0);
if ($batchId <= 0) redirect('dashboard.php');

try {
    $batch = get_owned_bulk_import($pdo, $batchId, $userId);
    $definition = bulk_import_definition((string) $batch['batch_type']);
    require_bulk_import_permission((string) $batch['batch_type']);
    $rows = bulk_import_rows($pdo, $batchId);
} catch (Throwable $e) {
    flash('danger', import_safe_exception_message($e, 'The bulk import could not be loaded.'));
    redirect('dashboard.php');
}

$summary = $batch['summary'];
$validRows = (int) ($summary['valid_rows'] ?? 0);
$invalidRows = (int) ($summary['invalid_rows'] ?? 0);
$returnPage = (string) $definition['return'];
$title = (string) $definition['title'] . ' Import Preview';
$eyebrow = 'BULK IMPORT';
$headers = (array) $definition['headers'];
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
            <?= ui_page_intro('Bulk import', $title, 'Review every spreadsheet row and validation message before creating records.') ?>

            <section class="content-card ops-section">
                <div class="card-heading import-preview-heading">
                    <div>
                        <span class="eyebrow">BATCH #<?= (int) $batch['id'] ?></span>
                        <h2><?= e($batch['file_name']) ?></h2>
                        <p><?= e((string) $definition['description']) ?></p>
                    </div>
                    <span class="workflow-badge import-status-<?= e((string) $batch['status']) ?>"><?= e(ucfirst((string) $batch['status'])) ?></span>
                </div>

                <div class="import-summary-grid">
                    <div><span>Total rows</span><strong><?= number_format((int) $batch['row_count']) ?></strong></div>
                    <div><span>Valid</span><strong><?= number_format($validRows) ?></strong></div>
                    <div><span>Needs attention</span><strong><?= number_format($invalidRows) ?></strong></div>
                    <div><span>Import type</span><strong><?= e((string) $definition['title']) ?></strong></div>
                </div>

                <?php if ($invalidRows > 0): ?>
                    <div class="alert warning">This batch cannot be confirmed yet. Correct the rows marked <strong>Invalid</strong> in the spreadsheet and upload the corrected file again.</div>
                <?php endif; ?>

                <div class="table-wrap import-preview-table">
                    <table class="data modern-table">
                        <thead><tr><th>Row</th><?php foreach ($headers as $header): ?><th><?= e(ucwords(str_replace('_', ' ', $header))) ?></th><?php endforeach; ?><th>Result</th></tr></thead>
                        <tbody>
                        <?php if (!$rows): ?><tr><td colspan="<?= count($headers) + 2 ?>" class="muted">No staged rows.</td></tr><?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= (int) $row['row_number'] ?></td>
                                <?php foreach ($headers as $header): ?><td><?= e((string) ($row['data'][$header] ?? '')) ?></td><?php endforeach; ?>
                                <td>
                                    <span class="workflow-badge import-status-<?= e((string) $row['validation_status']) ?>"><?= e(ucfirst((string) $row['validation_status'])) ?></span>
                                    <?php if (!empty($row['error_message'])): ?><small class="import-row-error-text"><?= e((string) $row['error_message']) ?></small><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-actions ops-sticky-actions">
                    <a class="btn secondary" href="<?= e($returnPage) ?>">Back</a>
                    <?php if ($batch['status'] !== 'posted' && $batch['status'] !== 'failed'): ?>
                        <form method="post" data-confirm="Cancel this unposted import?" data-confirm-title="Cancel import">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
                            <button class="btn secondary" type="submit">Cancel Batch</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($batch['status'] === 'validated'): ?>
                        <form method="post" data-block-on-submit="Creating imported records" data-confirm="Create the validated records from this spreadsheet?" data-confirm-title="Confirm bulk import">
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
