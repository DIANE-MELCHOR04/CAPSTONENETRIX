<?php
declare(strict_types=1);

/**
 * Duplicate-protection helpers shared by manual inventory entry and spreadsheet imports.
 * These helpers intentionally use the existing remarks/reference storage so the upgrade
 * does not require a destructive database migration.
 */

function duplicate_guard_reference(string $reference): string
{
    return trim((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $reference));
}


/**
 * Keep import_batches compatible with older installed databases.
 * Existing installations may have an earlier import_batches definition even
 * when the PHP files have been upgraded.
 */
function import_enable_mariadb_compat(PDO $pdo): void
{
    static $enabled = [];
    $id = spl_object_id($pdo);
    if (isset($enabled[$id])) {
        return;
    }

    try {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // Hostinger commonly runs MariaDB 10.4. Emulated prepares avoid
            // older server-side PREPARE grammar edge-cases while PDO still
            // quotes every bound value safely.
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        }
    } catch (Throwable $ignored) {
        // Some PDO builds may not expose this attribute. The importer can still
        // proceed with the driver's default behavior.
    }

    $enabled[$id] = true;
}

function import_schema_columns(PDO $pdo, string $table): array
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new InvalidArgumentException('Invalid import schema table name.');
    }

    import_enable_mariadb_compat($pdo);

    // SHOW COLUMNS is supported by MariaDB 10.4 and avoids relying on DDL or
    // newer information_schema behavior. The table name is validated above.
    try {
        $rows = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll();
    } catch (PDOException $error) {
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        if ($driverCode === 1146) {
            return [];
        }
        throw $error;
    }

    $columns = [];
    foreach ($rows as $row) {
        $name = (string) ($row['Field'] ?? '');
        if ($name !== '') {
            $columns[$name] = true;
        }
    }
    return $columns;
}

function import_schema_selectable(PDO $pdo, string $table, array $columns): bool
{
    foreach ($columns as $column) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new InvalidArgumentException('Invalid import schema column name.');
        }
    }

    $available = import_schema_columns($pdo, $table);
    if ($available === []) {
        return false;
    }
    foreach ($columns as $column) {
        if (!isset($available[$column])) {
            return false;
        }
    }
    return true;
}

/**
 * Verify the schema only. Database migrations are intentionally never executed
 * by normal import requests on shared hosting.
 */
function ensure_import_batch_schema(PDO $pdo): void
{
    static $ready = [];
    $id = spl_object_id($pdo);
    if (isset($ready[$id])) {
        return;
    }

    import_enable_mariadb_compat($pdo);

    $requiredColumns = [
        'id', 'batch_type', 'file_name', 'status', 'row_count', 'created_by',
        'notes', 'source_path', 'file_sha256', 'confirm_token_hash',
        'validated_at', 'posted_at', 'posted_by', 'created_at',
    ];

    if (!import_schema_selectable($pdo, 'import_batches', $requiredColumns)) {
        throw new DomainException(
            'The import_batches table is missing required import columns. Run database/import_repair_upgrade.sql in phpMyAdmin on the same database used by this website.'
        );
    }

    $ready[$id] = true;
}

function duplicate_guard_reference_prefix(string $reference): string
{
    return 'Reference: ' . duplicate_guard_reference($reference);
}

function duplicate_guard_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function duplicate_guard_like_prefix(string $reference): string
{
    $reference = duplicate_guard_reference($reference);
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], 'Reference: ' . $reference) . '%';
}

function inventory_reference_usage(PDO $pdo, string $movementType, string $reference): array
{
    $reference = duplicate_guard_reference($reference);
    if ($reference === '') {
        return ['count' => 0, 'movement_nos' => []];
    }

    import_enable_mariadb_compat($pdo);
    $prefix = duplicate_guard_reference_prefix($reference);
    $stmt = $pdo->prepare(
        "SELECT movement_no, remarks
         FROM inventory_movements
         WHERE movement_type = ?
         ORDER BY id DESC
         LIMIT 250"
    );
    $stmt->execute([$movementType]);

    $movementNos = [];
    foreach ($stmt->fetchAll() as $row) {
        if (str_starts_with((string) ($row['remarks'] ?? ''), $prefix)) {
            $movementNos[] = (string) ($row['movement_no'] ?? '');
            if (count($movementNos) >= 10) {
                break;
            }
        }
    }
    $movementNos = array_values(array_filter($movementNos));
    return ['count' => count($movementNos), 'movement_nos' => $movementNos];
}

function inventory_exact_reference_duplicate(
    PDO $pdo,
    string $movementType,
    int $materialId,
    int $locationId,
    float $quantity,
    string $reference
): ?array {
    $reference = duplicate_guard_reference($reference);
    if ($reference === '') {
        return null;
    }

    if (!in_array($movementType, ['stock_in', 'stock_out'], true)) {
        throw new InvalidArgumentException('Unsupported movement type for duplicate checking.');
    }

    import_enable_mariadb_compat($pdo);
    $quantityColumn = $movementType === 'stock_out' ? 'quantity_out' : 'quantity_in';
    $minQuantity = $quantity - 0.00001;
    $maxQuantity = $quantity + 0.00001;
    $prefix = duplicate_guard_reference_prefix($reference);

    // quantityColumn is chosen from a hard-coded allow-list above. Reference
    // matching is performed in PHP to avoid PREPARE grammar differences in
    // older MariaDB builds involving LEFT()/CHAR_LENGTH() parameters.
    $sql = "SELECT id, movement_no, created_at, remarks
            FROM inventory_movements
            WHERE movement_type = ?
              AND material_id = ?
              AND location_id = ?
              AND {$quantityColumn} BETWEEN ? AND ?
            ORDER BY id DESC
            LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$movementType, $materialId, $locationId, $minQuantity, $maxQuantity]);
    foreach ($stmt->fetchAll() as $row) {
        if (str_starts_with((string) ($row['remarks'] ?? ''), $prefix)) {
            return $row;
        }
    }
    return null;
}

function assert_manual_inventory_not_duplicate(
    PDO $pdo,
    string $movementType,
    int $materialId,
    int $locationId,
    float $quantity,
    string $reference,
    bool $confirmReferenceReuse = false
): void {
    $reference = duplicate_guard_reference($reference);
    if ($reference === '') {
        return;
    }

    $exact = inventory_exact_reference_duplicate($pdo, $movementType, $materialId, $locationId, $quantity, $reference);
    if ($exact) {
        throw new DomainException(
            'Exact duplicate blocked. Reference ' . $reference . ' was already posted as ' . (string) $exact['movement_no'] .
            ' for the same material, location, and quantity.'
        );
    }

    $usage = inventory_reference_usage($pdo, $movementType, $reference);
    if ((int) $usage['count'] > 0 && !$confirmReferenceReuse) {
        $example = $usage['movement_nos'][0] ?? '';
        throw new DomainException(
            'This reference number was already used' . ($example !== '' ? ' in ' . $example : '') .
            '. If this is a different line/item under the same document, tick “Confirm intentional reference reuse” and submit again.'
        );
    }
}

function assert_unique_site_name(PDO $pdo, string $name): void
{
    $stmt = $pdo->prepare('SELECT code FROM sites WHERE LOWER(TRIM(name)) = ? LIMIT 1');
    $stmt->execute([strtolower(trim($name))]);
    $code = $stmt->fetchColumn();
    if ($code !== false) {
        throw new DomainException('Duplicate site blocked. This site already exists as ' . (string) $code . '.');
    }
}

function assert_unique_location_name(PDO $pdo, string $name): void
{
    $stmt = $pdo->prepare('SELECT code FROM locations WHERE LOWER(TRIM(name)) = ? LIMIT 1');
    $stmt->execute([strtolower(trim($name))]);
    $code = $stmt->fetchColumn();
    if ($code !== false) {
        throw new DomainException('Duplicate location blocked. This location already exists as ' . (string) $code . '.');
    }
}

function assert_unique_supplier_company(PDO $pdo, string $company): void
{
    $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE LOWER(TRIM(company)) = ? LIMIT 1');
    $stmt->execute([strtolower(trim($company))]);
    if ($stmt->fetchColumn() !== false) {
        throw new DomainException('Duplicate supplier blocked. A supplier with this company name already exists.');
    }
}

function assert_unique_supplier_delivery_reference(PDO $pdo, int $purchaseOrderId, string $deliveryReference): void
{
    $deliveryReference = trim($deliveryReference);
    if ($deliveryReference === '') {
        return;
    }
    $stmt = $pdo->prepare(
        "SELECT delivery_no FROM supplier_deliveries WHERE purchase_order_id = ? AND LOWER(TRIM(delivery_reference)) = ? LIMIT 1"
    );
    $stmt->execute([$purchaseOrderId, strtolower(trim($deliveryReference))]);
    $deliveryNo = $stmt->fetchColumn();
    if ($deliveryNo !== false) {
        throw new DomainException('Duplicate supplier delivery blocked. Reference ' . $deliveryReference . ' is already used by ' . (string) $deliveryNo . ' for this purchase order.');
    }
}

function duplicate_import_file_batch(PDO $pdo, string $batchType, string $sha256, int $userId): ?array
{
    ensure_import_batch_schema($pdo);
    if ($sha256 === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT id, status, file_name, created_at
         FROM import_batches
         WHERE batch_type = ? AND file_sha256 = ? AND created_by = ? AND status = 'posted'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$batchType, $sha256, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}


function import_safe_exception_message(Throwable $error, string $fallback): string
{
    if ($error instanceof DomainException || $error instanceof InvalidArgumentException) {
        return $error->getMessage();
    }

    if ($error instanceof PDOException) {
        $sqlState = (string) $error->getCode();
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        $message = strtolower($error->getMessage());

        if (in_array($driverCode, [1054, 1146], true) || str_contains($message, 'unknown column') || str_contains($message, "doesn't exist") || str_contains($message, 'does not exist')) {
            return 'The import database structure is incomplete. Run database/import_repair_upgrade.sql in the SAME database used by this website, then retry. MySQL error: ' . ($driverCode ?: $sqlState) . '.';
        }

        if ($sqlState === '23000') {
            return 'The import conflicts with an existing database record or unique value. Review the duplicate warnings and try again.';
        }

        // MySQL/MariaDB permission-related driver codes. Do not treat every
        // SQLSTATE 42000 as a permission problem because 42000 also includes
        // ordinary SQL syntax/access-rule errors.
        if (in_array($driverCode, [1044, 1045, 1142, 1143, 1227], true)
            || str_contains($message, 'command denied')
            || str_contains($message, 'access denied')) {
            return 'The website database account does not have permission for a required database operation. If database/import_repair_upgrade.sql was already imported in phpMyAdmin, confirm the website is connected to that same database. MySQL error: ' . ($driverCode ?: $sqlState) . '.';
        }

        if ($driverCode === 1064 || str_contains($message, 'syntax error')) {
            $driverMessage = (string) ($error->errorInfo[2] ?? $error->getMessage());
            $driverMessage = preg_replace('/\s+/', ' ', strip_tags($driverMessage)) ?? '';
            $near = '';
            if (preg_match("/near\\s+'([^']{1,180})'/i", $driverMessage, $match)) {
                $near = trim((string) $match[1]);
            }
            if ($near !== '') {
                return 'MariaDB rejected one import query near: ' . $near . '. This build includes MariaDB 10.4 compatibility fallbacks; send this exact message if it continues.';
            }
            return 'MariaDB rejected one import query (1064). Details: ' . substr($driverMessage, 0, 220);
        }

        return $fallback . ' Database error: ' . ($driverCode ?: $sqlState ?: 'unknown') . '.';
    }

    $message = strtolower($error->getMessage());
    if (str_contains($message, 'ziparchive') || str_contains($message, 'xmlreader') || str_contains($message, 'simplexml')) {
        return 'XLSX import needs the PHP ZIP, XMLReader, and SimpleXML extensions. Enable them for this PHP version, or import a CSV file instead.';
    }

    return $fallback;
}

