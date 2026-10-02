<?php
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/documents.php';
require_once __DIR__ . '/lib/audit.php';

function backup_file_name(string $prefix, DateTimeInterface $date): string
{
    return sprintf('%s-%s.sql', strtoupper($prefix), $date->format('Ymd-His'));
}

function create_database_backup(PDO $pdo, int $createdBy): array
{
    $prefix = setting_value($pdo, 'backup_prefix', 'BACKUP');
    $fileName = backup_file_name($prefix, new DateTimeImmutable());
    $directory = __DIR__ . '/storage/backups';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Backup directory could not be prepared.');
    }

    $path = $directory . '/' . $fileName;
    $command = 'C:\\xampp\\mysql\\bin\\mysqldump.exe -u root inventory_forecasting_system';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    $pdo->beginTransaction();
    try {
        $backupNo = next_named_document_no($pdo, 'backup_logs', 'backup_no', $prefix);
        if ($exitCode !== 0) {
            $pdo->prepare('INSERT INTO backup_logs (backup_no, file_name, stored_path, status, message, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$backupNo, $fileName, $path, 'failed', 'Database backup command failed.', $createdBy]);
            $pdo->commit();
            throw new RuntimeException('Database backup could not be completed.');
        }

        if (file_put_contents($path, implode(PHP_EOL, $output) . PHP_EOL) === false) {
            throw new RuntimeException('Database backup file could not be written.');
        }
        $pdo->prepare('INSERT INTO backup_logs (backup_no, file_name, stored_path, status, message, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$backupNo, $fileName, $path, 'completed', 'Database backup completed.', $createdBy]);
        write_audit($pdo, $createdBy, 'create', 'backup', null, 'Database backup created.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return ['backup_no' => $backupNo, 'file_name' => $fileName, 'path' => $path];
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require_once __DIR__ . '/lib/auth.php';
    require_once __DIR__ . '/lib/search_filters.php';
    require_permission('manage_backups');
    require_once __DIR__ . '/config/db.php';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        try {
            create_database_backup($pdo, (int) current_user()['id']);
            flash('success', 'Database backup created.');
        } catch (Throwable $exception) {
            flash('danger', $exception->getMessage());
        }
        redirect('backups.php');
    }

    $backupStatuses = ['completed','failed'];
    $q = filter_text($_GET, 'q');
    $status = filter_choice($_GET, 'status', $backupStatuses);
    $from = filter_date($_GET, 'from');
    $to = filter_date($_GET, 'to');
    $where = [];
    $params = [];
    filter_where_like($where, $params, ['b.backup_no', 'b.file_name', 'b.message', 'u.full_name'], $q);
    filter_where_equals($where, $params, 'b.status', $status);
    filter_where_between_dates($where, $params, 'b.created_at', $from, $to);
    $whereSql = filter_where_sql($where);
    $stmt = $pdo->prepare(
        "SELECT b.*, u.full_name
         FROM backup_logs b
         JOIN users u ON u.id = b.created_by
         {$whereSql}
         ORDER BY b.created_at DESC
         LIMIT 80"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $activePage = 'backups';
    $title = 'Backups';
    $eyebrow = 'ADMINISTRATION';
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title><?php require __DIR__ . '/partials/assets.php'; ?></head><body><div class="app-layout"><?php require __DIR__ . '/partials/sidebar.php'; ?><main class="app-main"><?php require __DIR__ . '/partials/header.php'; ?><div class="page-content">
    <section class="monitoring-top-card"><div><span class="eyebrow light">DATABASE BACKUPS</span><h2>Manual backup records and backup history</h2><p>Create and review database backup files stored on the local XAMPP machine.</p></div><form method="post" data-block-on-submit="Creating backup"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="btn glass" type="submit" data-confirm="Create a database backup now?" data-confirm-title="Create backup">Create Backup</button></form></section>
    <form class="filter-card" method="get" data-filter-form><label class="filter-search">Search<input name="q" value="<?= e($q) ?>" placeholder="Backup no., file, user, message"></label><label>Status<select name="status"><?php render_status_options($backupStatuses, $status); ?></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><div class="filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn secondary" href="backups.php" data-clear-filters>Clear</a></div><?= filter_badge(count($rows)) ?></form>
    <section class="content-card"><div class="table-wrap"><table class="data modern-table"><thead><tr><th>No.</th><th>File</th><th>Status</th><th>Created By</th><th>Date</th><th>Message</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="6" class="muted">No backup records found.</td></tr><?php endif; ?><?php foreach ($rows as $row): ?><tr><td><?= e($row['backup_no']) ?></td><td><?= e($row['file_name']) ?></td><td><span class="workflow-badge <?= e($row['status']) ?>"><?= e($row['status']) ?></span></td><td><?= e($row['full_name']) ?></td><td><?= e(date('M d, Y h:i A', strtotime($row['created_at']))) ?></td><td><?= e($row['message']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    </div></main></div></body></html>
    <?php
}
