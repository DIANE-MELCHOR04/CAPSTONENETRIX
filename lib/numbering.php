<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

function master_code_definitions(): array
{
    return [
        'material' => ['setting' => 'material_code_prefix', 'default' => 'MAT', 'width' => 6, 'sequence' => 'master.material'],
        'site' => ['setting' => 'site_code_prefix', 'default' => 'SITE', 'width' => 4, 'sequence' => 'master.site'],
        'location' => ['setting' => 'location_code_prefix', 'default' => 'LOC', 'width' => 4, 'sequence' => 'master.location'],
        'unit' => ['setting' => 'unit_code_prefix', 'default' => 'UOM', 'width' => 3, 'sequence' => 'master.unit'],
    ];
}

function code_prefix_setting_keys(): array
{
    return array_column(master_code_definitions(), 'setting');
}

function normalize_code_prefix(string $value): string
{
    return strtoupper(trim($value));
}

function validate_code_prefixes(array $values): array
{
    $errors = [];
    $normalized = [];
    foreach (master_code_definitions() as $entity => $definition) {
        $key = (string) $definition['setting'];
        $prefix = normalize_code_prefix((string) ($values[$key] ?? ''));
        $normalized[$key] = $prefix;
        if (!preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $prefix)) {
            $errors[] = ucfirst($entity) . ' code prefix must be 2 to 10 letters or digits and begin with a letter.';
        }
    }

    if (count(array_unique(array_values($normalized))) !== count($normalized)) {
        $errors[] = 'Code prefixes must be different from one another.';
    }

    return $errors;
}

function format_sequence_code(string $prefix, int $value, int $width): string
{
    $prefix = normalize_code_prefix($prefix);
    if (!preg_match('/^[A-Z][A-Z0-9]{1,11}$/', $prefix)) {
        throw new InvalidArgumentException('Unsupported code prefix.');
    }
    if ($value < 1 || $width < 1 || $width > 12) {
        throw new InvalidArgumentException('Unsupported sequence value or width.');
    }

    return $prefix . '-' . str_pad((string) $value, $width, '0', STR_PAD_LEFT);
}

function assert_sequence_transaction(PDO $pdo): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Sequence allocation requires an active transaction.');
    }
}

function validate_sequence_identity(string $sequenceKey, string $scopeKey): void
{
    if (!preg_match('/^[a-z0-9._-]{2,80}$/', $sequenceKey) || !preg_match('/^[A-Za-z0-9._-]{1,80}$/', $scopeKey)) {
        throw new InvalidArgumentException('Unsupported sequence key or scope.');
    }
}

function next_sequence_value(PDO $pdo, string $sequenceKey, string $scopeKey = 'global'): int
{
    assert_sequence_transaction($pdo);
    validate_sequence_identity($sequenceKey, $scopeKey);

    $pdo->prepare(
        'INSERT IGNORE INTO number_sequences (sequence_key, scope_key, last_value) VALUES (?, ?, 0)'
    )->execute([$sequenceKey, $scopeKey]);

    $stmt = $pdo->prepare(
        'SELECT last_value FROM number_sequences WHERE sequence_key = ? AND scope_key = ? FOR UPDATE'
    );
    $stmt->execute([$sequenceKey, $scopeKey]);
    $current = $stmt->fetchColumn();
    if ($current === false) {
        throw new RuntimeException('The number sequence could not be initialized.');
    }

    $next = ((int) $current) + 1;
    $pdo->prepare(
        'UPDATE number_sequences SET last_value = ? WHERE sequence_key = ? AND scope_key = ?'
    )->execute([$next, $sequenceKey, $scopeKey]);

    return $next;
}

function seed_sequence_floor(PDO $pdo, string $sequenceKey, string $scopeKey, int $floor): void
{
    assert_sequence_transaction($pdo);
    validate_sequence_identity($sequenceKey, $scopeKey);
    $floor = max(0, $floor);

    $pdo->prepare(
        'INSERT INTO number_sequences (sequence_key, scope_key, last_value)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE last_value = GREATEST(last_value, VALUES(last_value))'
    )->execute([$sequenceKey, $scopeKey, $floor]);
}

function next_master_code(PDO $pdo, string $entity): string
{
    $definitions = master_code_definitions();
    if (!isset($definitions[$entity])) {
        throw new InvalidArgumentException('Unsupported master code entity.');
    }

    $definition = $definitions[$entity];
    $prefix = setting_value($pdo, (string) $definition['setting'], (string) $definition['default']);
    $prefixErrors = validate_code_prefixes(array_merge(
        array_combine(
            code_prefix_setting_keys(),
            array_map(
                static fn(array $item): string => setting_value($pdo, (string) $item['setting'], (string) $item['default']),
                array_values($definitions)
            )
        ) ?: [],
        [(string) $definition['setting'] => $prefix]
    ));
    if ($prefixErrors !== []) {
        throw new RuntimeException('Code prefix settings are invalid.');
    }

    $value = next_sequence_value($pdo, (string) $definition['sequence'], 'global');
    return format_sequence_code($prefix, $value, (int) $definition['width']);
}

function document_number_definitions(): array
{
    return [
        'material_request' => ['setting' => 'request_prefix', 'default' => 'MR', 'scope' => 'year', 'date' => 'Y', 'width' => 6],
        'inventory_movement' => ['setting' => 'movement_prefix', 'default' => 'MOV', 'scope' => 'date', 'date' => 'Ymd', 'width' => 4],
        'material_release' => ['setting' => 'release_prefix', 'default' => 'REL', 'scope' => 'date', 'date' => 'Ymd', 'width' => 4],
        'purchase_request' => ['setting' => 'purchase_request_prefix', 'default' => 'PR', 'scope' => 'date', 'date' => 'Ymd', 'width' => 4],
        'purchase_order' => ['setting' => 'purchase_order_prefix', 'default' => 'PO', 'scope' => 'date', 'date' => 'Ymd', 'width' => 4],
        'supplier_delivery' => ['setting' => 'delivery_prefix', 'default' => 'DEL', 'scope' => 'date', 'date' => 'Ymd', 'width' => 4],
        'material_transfer' => ['setting' => 'transfer_prefix', 'default' => 'TR', 'scope' => 'date', 'date' => 'Ymd', 'width' => 4],
        'material_return' => ['setting' => 'return_prefix', 'default' => 'RET', 'scope' => 'date', 'date' => 'Ymd', 'width' => 4],
        'damage_report' => ['setting' => 'damage_prefix', 'default' => 'DMG', 'scope' => 'date', 'date' => 'Ymd', 'width' => 4],
        'inventory_adjustment' => ['setting' => 'adjustment_prefix', 'default' => 'ADJ', 'scope' => 'date', 'date' => 'Ymd', 'width' => 4],
        'stock_count' => ['setting' => 'stock_count_prefix', 'default' => 'SC', 'scope' => 'date', 'date' => 'Ymd', 'width' => 4],
        'backup' => ['setting' => 'backup_prefix', 'default' => 'BACKUP', 'scope' => 'date', 'date' => 'Ymd', 'width' => 4],
        'forecast_run' => ['setting' => null, 'default' => 'FC', 'scope' => 'date', 'date' => 'Ymd', 'width' => 4],
    ];
}

function next_internal_document_no(PDO $pdo, string $documentType, ?DateTimeInterface $date = null): string
{
    $definitions = document_number_definitions();
    if (!isset($definitions[$documentType])) {
        throw new InvalidArgumentException('Unsupported internal document type.');
    }

    $definition = $definitions[$documentType];
    $date ??= new DateTimeImmutable('now');
    $datePart = $date->format((string) $definition['date']);
    $prefix = $definition['setting'] === null
        ? (string) $definition['default']
        : setting_value($pdo, (string) $definition['setting'], (string) $definition['default']);
    $prefix = normalize_code_prefix($prefix);
    if (!preg_match('/^[A-Z][A-Z0-9]{1,11}$/', $prefix)) {
        throw new RuntimeException('Document prefix setting is invalid.');
    }

    $value = next_sequence_value($pdo, 'document.' . $documentType, $datePart);
    return $prefix . '-' . $datePart . '-' . str_pad((string) $value, (int) $definition['width'], '0', STR_PAD_LEFT);
}
