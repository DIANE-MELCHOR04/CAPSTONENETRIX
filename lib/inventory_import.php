<?php
declare(strict_types=1);

require_once __DIR__ . '/spreadsheet_import.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/duplicate_guard.php';

function inventory_import_permission(string $batchType): string
{
    return match ($batchType) {
        'stock_in' => 'post_stock_in',
        'stock_out' => 'post_stock_out',
        default => throw new InvalidArgumentException('Unsupported inventory import type.'),
    };
}

function inventory_import_return_page(string $batchType): string
{
    return match ($batchType) {
        'stock_in' => 'stock_in.php',
        'stock_out' => 'stock_out.php',
        default => throw new InvalidArgumentException('Unsupported inventory import type.'),
    };
}

function inventory_import_storage_directory(): string
{
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'imports';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The private import directory could not be created.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('The private import directory is not writable.');
    }
    return $directory;
}

function inventory_import_source_path(?string $storageName): ?string
{
    if ($storageName === null || $storageName === '') {
        return null;
    }
    if (basename($storageName) !== $storageName) {
        throw new DomainException('The stored import file path is invalid.');
    }
    return inventory_import_storage_directory() . DIRECTORY_SEPARATOR . $storageName;
}

function assert_inventory_import_permission(PDO $pdo, int $userId, string $batchType): void
{
    $permission = inventory_import_permission($batchType);
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();
    if (!is_string($role) || !role_can($role, $permission)) {
        throw new DomainException('You do not have permission to perform this inventory import.');
    }
}

function inventory_import_safe_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? 'spreadsheet';
    $name = trim($name);
    return substr($name === '' ? 'spreadsheet' : $name, 0, 190);
}

function inventory_import_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function inventory_import_stage_text(string $value, int $limit): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
}

function inventory_import_audit_details(array $batch, int $rowCount, string $totalQuantity, ?string $error = null): string
{
    return (string) json_encode([
        'batch_id' => (int) $batch['id'],
        'batch_type' => (string) $batch['batch_type'],
        'file_name' => (string) $batch['file_name'],
        'row_count' => $rowCount,
        'total_quantity' => $totalQuantity,
        'error' => $error,
    ], JSON_UNESCAPED_SLASHES);
}

function inventory_import_store_upload(array $upload): array
{
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new DomainException($error === UPLOAD_ERR_NO_FILE ? 'Select a spreadsheet to import.' : 'The spreadsheet upload did not complete.');
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_file($temporaryPath)) {
        throw new DomainException('The uploaded spreadsheet could not be found.');
    }
    $size = filesize($temporaryPath);
    if ($size === false || $size <= 0 || $size > STOCK_IMPORT_MAX_BYTES) {
        throw new DomainException('The uploaded file must be between 1 byte and 5 MB.');
    }

    $fileName = inventory_import_safe_filename((string) ($upload['name'] ?? 'spreadsheet'));
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $testMode = PHP_SAPI === 'cli' && ($upload['test_mode'] ?? false) === true;
    $mime = (string) ($upload['type'] ?? 'application/octet-stream');
    if (!$testMode && class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($temporaryPath);
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }
    assert_stock_import_upload_type($temporaryPath, $extension, $mime);

    if (!$testMode && !is_uploaded_file($temporaryPath)) {
        throw new DomainException('The upload could not be verified.');
    }

    $storageName = bin2hex(random_bytes(24)) . '.' . $extension;
    $destination = inventory_import_source_path($storageName);
    $stored = $testMode ? copy($temporaryPath, $destination) : move_uploaded_file($temporaryPath, $destination);
    if (!$stored || !is_file($destination)) {
        throw new RuntimeException('The spreadsheet could not be moved to private storage.');
    }
    $storedSize = filesize($destination);
    if ($storedSize === false || $storedSize !== $size || $storedSize > STOCK_IMPORT_MAX_BYTES) {
        @unlink($destination);
        throw new DomainException('The stored spreadsheet failed its size verification.');
    }

    return [
        'file_name' => $fileName,
        'extension' => $extension,
        'source_path' => $storageName,
        'absolute_path' => $destination,
        'file_sha256' => hash_file('sha256', $destination),
    ];
}

function stage_inventory_import(PDO $pdo, string $batchType, array $upload, int $userId): array
{
    import_enable_mariadb_compat($pdo);
    assert_inventory_import_permission($pdo, $userId, $batchType);
    ensure_import_batch_schema($pdo);
    $stored = inventory_import_store_upload($upload);
    $prior = duplicate_import_file_batch($pdo, $batchType, (string) $stored['file_sha256'], $userId);
    if ($prior) {
        @unlink($stored['absolute_path']);
        throw new DomainException('This exact spreadsheet was already posted as import batch #' . (int) $prior['id'] . '. Duplicate re-import blocked.');
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO import_batches (batch_type, file_name, status, row_count, created_by, notes, source_path, file_sha256)
             VALUES (?, ?, 'draft', 0, ?, ?, ?, ?)"
        );
        $stmt->execute([$batchType, $stored['file_name'], $userId, '{}', $stored['source_path'], $stored['file_sha256']]);
        $batchId = (int) $pdo->lastInsertId();
    } catch (Throwable $exception) {
        @unlink($stored['absolute_path']);
        throw $exception;
    }

    $batch = ['id' => $batchId, 'batch_type' => $batchType, 'file_name' => $stored['file_name']];
    try {
        $parsed = parse_stock_import_file($stored['absolute_path'], $stored['extension']);
    } catch (Throwable $exception) {
        $safeError = $exception instanceof DomainException ? $exception->getMessage() : 'The spreadsheet could not be parsed.';
        $notes = json_encode(['file_error' => $safeError], JSON_UNESCAPED_SLASHES);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE import_batches SET status = 'failed', notes = ?, confirm_token_hash = NULL WHERE id = ?")
                ->execute([$notes, $batchId]);
            write_audit($pdo, $userId, 'inventory_import.validation_failed', 'inventory_import', $batchId, inventory_import_audit_details($batch, 0, '0.00', $safeError));
            $pdo->commit();
        } catch (Throwable $auditException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $auditException;
        }
        return ['batch_id' => $batchId, 'status' => 'failed', 'valid_rows' => 0, 'invalid_rows' => 0, 'total_quantity' => '0.00'];
    }

    $materialStmt = $pdo->prepare(
        "SELECT id, material_name FROM materials
         WHERE material_code = ? AND approval_status = 'approved' AND is_active = 1 LIMIT 1"
    );
    $locationStmt = $pdo->prepare('SELECT id, name FROM locations WHERE code = ? AND is_active = 1 LIMIT 1');
    $balanceStmt = $pdo->prepare(
        'SELECT quantity_on_hand, reserved_quantity, damaged_quantity FROM inventory_balances WHERE material_id = ? AND location_id = ? LIMIT 1'
    );
    $insertRow = $pdo->prepare(
        'INSERT INTO inventory_import_rows
            (`import_batch_id`, `row_number`, `material_code`, `location_code`, `quantity`, `reference_no`, `remarks`, `material_id`, `location_id`, `validation_status`, `error_message`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $validRows = 0;
    $invalidRows = 0;
    $totalQuantity = 0.0;
    $requiredByPair = [];
    $seenExactRows = [];
    $seenReferences = [];
    $warningRows = 0;

    $pdo->beginTransaction();
    try {
        foreach ($parsed['rows'] as $row) {
            $errors = [];
            $warnings = [];
            $material = false;
            $location = false;
            $quantity = null;
            if (!empty($row['quantity_error'])) {
                $shownQuantity = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', inventory_import_stage_text((string) $row['quantity'], 80));
                $errors[] = 'Quantity "' . $shownQuantity . '": ' . (string) $row['quantity_error'];
            } else {
                $quantity = (float) $row['quantity'];
                $totalQuantity = round($totalQuantity + $quantity, 2);
            }
            if (inventory_import_text_length($row['material_code']) > 60) {
                $errors[] = 'Material code exceeds 60 characters.';
            } else {
                $materialStmt->execute([$row['material_code']]);
                $material = $materialStmt->fetch();
                if (!$material) {
                    $errors[] = 'Material code is unknown, inactive, or not approved.';
                }
            }
            if (inventory_import_text_length($row['location_code']) > 30) {
                $errors[] = 'Location code exceeds 30 characters.';
            } else {
                $locationStmt->execute([$row['location_code']]);
                $location = $locationStmt->fetch();
                if (!$location) {
                    $errors[] = 'Location code is unknown or inactive.';
                }
            }
            if (inventory_import_text_length($row['reference_no']) > 120) {
                $errors[] = 'Reference number exceeds 120 characters.';
            }
            if (inventory_import_text_length($row['remarks']) > 500) {
                $errors[] = 'Remarks exceed 500 characters.';
            }

            $reference = duplicate_guard_reference((string) $row['reference_no']);
            if ($reference !== '' && $quantity !== null && $material && $location) {
                $exactKey = strtolower($reference) . '|' . (int) $material['id'] . '|' . (int) $location['id'] . '|' . number_format($quantity, 2, '.', '');
                if (isset($seenExactRows[$exactKey])) {
                    $errors[] = 'Exact duplicate row found in this spreadsheet (same reference, material, location, and quantity).';
                } else {
                    $seenExactRows[$exactKey] = (int) $row['row_number'];
                }

                $existingExact = inventory_exact_reference_duplicate($pdo, $batchType, (int) $material['id'], (int) $location['id'], $quantity, $reference);
                if ($existingExact) {
                    $errors[] = 'Exact duplicate already posted as ' . (string) $existingExact['movement_no'] . '.';
                } else {
                    $usage = inventory_reference_usage($pdo, $batchType, $reference);
                    if ((int) $usage['count'] > 0) {
                        $warnings[] = 'Reference ' . $reference . ' was used before for a different line/item; review before confirming.';
                    }
                }

                $referenceKey = strtolower($reference);
                if (isset($seenReferences[$referenceKey]) && !isset($seenExactRows[$exactKey . '|warning'])) {
                    $warnings[] = 'Reference ' . $reference . ' appears on multiple rows in this spreadsheet. This is allowed for different items, but review the lines.';
                }
                $seenReferences[$referenceKey] = true;
            }

            if ($batchType === 'stock_out' && $quantity !== null && $material && $location) {
                $pair = (int) $material['id'] . ':' . (int) $location['id'];
                $requiredByPair[$pair] = round(($requiredByPair[$pair] ?? 0.0) + $quantity, 2);
                $balanceStmt->execute([(int) $material['id'], (int) $location['id']]);
                $balance = $balanceStmt->fetch() ?: ['quantity_on_hand' => 0, 'reserved_quantity' => 0, 'damaged_quantity' => 0];
                $available = available_stock((float) $balance['quantity_on_hand'], (float) $balance['reserved_quantity'], (float) $balance['damaged_quantity']);
                if ($requiredByPair[$pair] > $available) {
                    $shortage = round($requiredByPair[$pair] - $available, 2);
                    $errors[] = 'Available ' . money_or_qty($available) . ', cumulative requested ' . money_or_qty($requiredByPair[$pair]) . ', shortage ' . money_or_qty($shortage) . '.';
                }
            }

            $warnings = array_values(array_unique($warnings));
            if ($warnings !== []) {
                $warningRows++;
            }
            $status = $errors === [] ? 'valid' : 'invalid';
            $status === 'valid' ? $validRows++ : $invalidRows++;
            $rowMessage = $errors !== [] ? implode(' ', array_values(array_unique(array_merge($errors, $warnings)))) : ($warnings !== [] ? 'Warning: ' . implode(' ', $warnings) : null);
            $insertRow->execute([
                $batchId,
                $row['row_number'],
                inventory_import_stage_text($row['material_code'], 60),
                inventory_import_stage_text($row['location_code'], 30),
                $quantity === null ? null : $row['quantity'],
                $row['reference_no'] !== '' ? inventory_import_stage_text($row['reference_no'], 120) : null,
                $row['remarks'] !== '' ? inventory_import_stage_text($row['remarks'], 500) : null,
                $material ? (int) $material['id'] : null,
                $location ? (int) $location['id'] : null,
                $status,
                $rowMessage,
            ]);
        }

        $rowCount = count($parsed['rows']);
        $isValid = $rowCount > 0 && $invalidRows === 0;
        $status = $isValid ? 'validated' : 'failed';
        $token = $isValid ? bin2hex(random_bytes(32)) : null;
        $tokenHash = $token === null ? null : hash('sha256', $token);
        $summary = json_encode([
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'warning_rows' => $warningRows,
            'total_quantity' => number_format($totalQuantity, 2, '.', ''),
            'file_error' => $rowCount === 0 ? 'The spreadsheet contains no data rows.' : null,
        ], JSON_UNESCAPED_SLASHES);
        $pdo->prepare(
            "UPDATE import_batches
             SET status = ?, row_count = ?, notes = ?, confirm_token_hash = ?, validated_at = ?
             WHERE id = ?"
        )->execute([$status, $rowCount, $summary, $tokenHash, $isValid ? date('Y-m-d H:i:s') : null, $batchId]);

        $action = $isValid ? 'inventory_import.validated' : 'inventory_import.validation_failed';
        write_audit($pdo, $userId, $action, 'inventory_import', $batchId, inventory_import_audit_details($batch, $rowCount, number_format($totalQuantity, 2, '.', ''), $isValid ? null : 'One or more rows failed validation.'));
        $pdo->commit();

        if ($token !== null) {
            $_SESSION['inventory_import_tokens'][(string) $batchId] = $token;
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'batch_id' => $batchId,
        'status' => $status,
        'valid_rows' => $validRows,
        'invalid_rows' => $invalidRows,
        'total_quantity' => number_format($totalQuantity, 2, '.', ''),
    ];
}

function get_owned_inventory_import(PDO $pdo, int $batchId, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM import_batches WHERE id = ? AND created_by = ? LIMIT 1');
    $stmt->execute([$batchId, $userId]);
    $batch = $stmt->fetch();
    if (!$batch) {
        throw new DomainException('The inventory import batch could not be found.');
    }
    $summary = json_decode((string) ($batch['notes'] ?? '{}'), true);
    $batch['summary'] = is_array($summary) ? $summary : [];
    return $batch;
}

function inventory_import_rows(PDO $pdo, int $batchId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, m.material_name, l.name AS location_name
         FROM inventory_import_rows r
         LEFT JOIN materials m ON m.id = r.material_id
         LEFT JOIN locations l ON l.id = r.location_id
         WHERE r.import_batch_id = ?
         ORDER BY r.`row_number`'
    );
    $stmt->execute([$batchId]);
    return $stmt->fetchAll();
}

function inventory_import_movement_remarks(array $row): ?string
{
    $parts = [];
    $reference = trim((string) ($row['reference_no'] ?? ''));
    $remarks = trim((string) ($row['remarks'] ?? ''));
    if ($reference !== '') {
        $parts[] = 'Reference: ' . preg_replace('/[\x00-\x1F\x7F]/', ' ', $reference);
    }
    if ($remarks !== '') {
        $parts[] = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $remarks);
    }
    return $parts === [] ? null : implode(' / ', $parts);
}

function inventory_import_locked_batch(PDO $pdo, int $batchId, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM import_batches WHERE id = ? AND created_by = ? FOR UPDATE');
    $stmt->execute([$batchId, $userId]);
    $batch = $stmt->fetch();
    if (!$batch) {
        throw new DomainException('The inventory import batch could not be found.');
    }
    return $batch;
}

function confirm_inventory_import(PDO $pdo, int $batchId, int $userId, string $token): array
{
    import_enable_mariadb_compat($pdo);
    $pdo->beginTransaction();
    try {
        $batch = inventory_import_locked_batch($pdo, $batchId, $userId);
        assert_inventory_import_permission($pdo, $userId, (string) $batch['batch_type']);
        if ($batch['status'] === 'posted') {
            $pdo->commit();
            return ['status' => 'posted', 'already_posted' => true, 'movement_count' => 0];
        }
        if ($batch['status'] !== 'validated') {
            throw new DomainException('Only a validated import batch can be posted.');
        }
        $expectedHash = (string) ($batch['confirm_token_hash'] ?? '');
        if ($token === '' || $expectedHash === '' || !hash_equals($expectedHash, hash('sha256', $token))) {
            throw new DomainException('The import confirmation expired. Upload the spreadsheet again.');
        }

        $rows = inventory_import_rows($pdo, $batchId);
        if ($rows === []) {
            throw new DomainException('The import batch has no rows to post.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $movementCount = 0;
    try {
        foreach ($rows as $row) {
            if ($row['validation_status'] !== 'valid' || $row['material_id'] === null || $row['location_id'] === null) {
                throw new DomainException('The import batch contains a row that is no longer valid.');
            }
            $quantity = (float) $row['quantity'];
            $reference = trim((string) ($row['reference_no'] ?? ''));
            $existingExact = inventory_exact_reference_duplicate($pdo, (string) $batch['batch_type'], (int) $row['material_id'], (int) $row['location_id'], $quantity, $reference);
            if ($existingExact) {
                throw new DomainException('Row ' . (int) $row['row_number'] . ' became a duplicate of already-posted movement ' . (string) $existingExact['movement_no'] . '. Posting was stopped.');
            }
            if ($batch['batch_type'] === 'stock_out') {
                $available = locked_available_stock($pdo, (int) $row['material_id'], (int) $row['location_id']);
                if ($quantity > $available) {
                    throw new DomainException(
                        'Available stock is ' . money_or_qty($available) . ' but row ' . (int) $row['row_number'] .
                        ' requests ' . money_or_qty($quantity) . '.'
                    );
                }
            }

            $movementId = post_inventory_movement($pdo, [
                'movement_type' => $batch['batch_type'],
                'material_id' => (int) $row['material_id'],
                'location_id' => (int) $row['location_id'],
                'quantity_in' => $batch['batch_type'] === 'stock_in' ? $quantity : 0,
                'quantity_out' => $batch['batch_type'] === 'stock_out' ? $quantity : 0,
                'reference_type' => $batch['batch_type'] . '_import',
                'reference_id' => $batchId,
                'remarks' => inventory_import_movement_remarks($row),
                'created_by' => $userId,
            ]);
            $pdo->prepare("UPDATE inventory_import_rows SET validation_status = 'posted', movement_id = ? WHERE id = ?")
                ->execute([$movementId, (int) $row['id']]);
            $movementCount++;
        }

        $pdo->prepare(
            "UPDATE import_batches
             SET status = 'posted', posted_at = NOW(), posted_by = ?, confirm_token_hash = NULL
             WHERE id = ?"
        )->execute([$userId, $batchId]);
        $postedSummary = json_decode((string) ($batch['notes'] ?? '{}'), true);
        $postedTotal = is_array($postedSummary) ? (string) ($postedSummary['total_quantity'] ?? '0.00') : '0.00';
        write_audit(
            $pdo,
            $userId,
            'inventory_import.posted',
            'inventory_import',
            $batchId,
            inventory_import_audit_details($batch, count($rows), $postedTotal)
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE import_batches SET status = 'failed', confirm_token_hash = NULL WHERE id = ? AND status = 'validated'")
                ->execute([$batchId]);
            write_audit($pdo, $userId, 'inventory_import.post_failed', 'inventory_import', $batchId, inventory_import_audit_details($batch, count($rows), '0.00', 'Posting failed.'));
            $pdo->commit();
        } catch (Throwable $auditException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Inventory import failure audit failed for batch ' . $batchId . ': ' . $auditException->getMessage());
        }
        unset($_SESSION['inventory_import_tokens'][(string) $batchId]);
        if ($exception instanceof DomainException) {
            throw $exception;
        }
        error_log('Inventory import posting failed for batch ' . $batchId . ': ' . $exception->getMessage());
        throw new DomainException('The inventory import could not be posted. No stock was changed.');
    }

    unset($_SESSION['inventory_import_tokens'][(string) $batchId]);
    $sourcePath = inventory_import_source_path($batch['source_path'] !== null ? (string) $batch['source_path'] : null);
    if ($sourcePath !== null && is_file($sourcePath) && !unlink($sourcePath)) {
        error_log('Inventory import source cleanup failed after posting batch ' . $batchId . '.');
    }

    return ['status' => 'posted', 'already_posted' => false, 'movement_count' => $movementCount];
}

function cancel_inventory_import(PDO $pdo, int $batchId, int $userId): void
{
    $pdo->beginTransaction();
    try {
        $batch = inventory_import_locked_batch($pdo, $batchId, $userId);
        assert_inventory_import_permission($pdo, $userId, (string) $batch['batch_type']);
        if ($batch['status'] === 'posted') {
            throw new DomainException('A posted inventory import cannot be cancelled.');
        }
        $summary = json_decode((string) ($batch['notes'] ?? '{}'), true);
        $total = is_array($summary) ? (string) ($summary['total_quantity'] ?? '0.00') : '0.00';
        write_audit($pdo, $userId, 'inventory_import.cancelled', 'inventory_import', null, inventory_import_audit_details($batch, (int) $batch['row_count'], $total));
        $pdo->prepare('DELETE FROM import_batches WHERE id = ?')->execute([$batchId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    unset($_SESSION['inventory_import_tokens'][(string) $batchId]);
    $sourcePath = inventory_import_source_path($batch['source_path'] !== null ? (string) $batch['source_path'] : null);
    if ($sourcePath !== null && is_file($sourcePath) && !unlink($sourcePath)) {
        error_log('Inventory import source cleanup failed after cancelling batch ' . $batchId . '.');
    }
}

function cleanup_abandoned_inventory_imports(PDO $pdo, int $days = 7): array
{
    $days = max(1, min(3650, $days));
    // MariaDB 10.4 compatibility: do not bind a parameter into INTERVAL syntax.
    $stmt = $pdo->query(
        "SELECT id, source_path FROM import_batches
         WHERE status IN ('draft','failed') AND created_at < DATE_SUB(NOW(), INTERVAL " . $days . " DAY)"
    );
    $batches = $stmt->fetchAll();
    $deleted = 0;
    $fileFailures = 0;
    foreach ($batches as $batch) {
        $delete = $pdo->prepare("DELETE FROM import_batches WHERE id = ? AND status IN ('draft','failed')");
        $delete->execute([(int) $batch['id']]);
        $deleted += $delete->rowCount();
        $path = inventory_import_source_path($batch['source_path'] !== null ? (string) $batch['source_path'] : null);
        if ($path !== null && is_file($path) && !unlink($path)) {
            $fileFailures++;
            error_log('Abandoned inventory import source cleanup failed for batch ' . (int) $batch['id'] . '.');
        }
    }
    return ['deleted_batches' => $deleted, 'file_failures' => $fileFailures];
}
