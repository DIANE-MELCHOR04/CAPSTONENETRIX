<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function validate_material_input(array $data): array
{
    $errors = [];
    if (trim((string) ($data['material_name'] ?? '')) === '') {
        $errors[] = 'Material name is required.';
    }
    if ((int) ($data['unit_id'] ?? 0) < 1) {
        $errors[] = 'Unit is required.';
    }

    $stockFields = ['min_stock', 'max_stock', 'reorder_point', 'safety_stock'];
    foreach ($stockFields as $field) {
        if ((float) ($data[$field] ?? 0) < 0) {
            $errors[] = 'Stock settings cannot be negative.';
            break;
        }
    }
    if ((int) ($data['lead_time_days'] ?? 0) < 0) {
        $errors[] = 'Lead time cannot be negative.';
    }

    return $errors;
}

function active_option_source(string $table, string $labelColumn = 'name'): ?array
{
    $sources = [
        'material_categories' => ['labels' => ['name'], 'predicate' => 'is_active = 1'],
        'units' => ['labels' => ['name'], 'predicate' => 'is_active = 1'],
        'suppliers' => ['labels' => ['company'], 'predicate' => 'is_active = 1'],
        'sites' => ['labels' => ['name'], 'predicate' => "status = 'active'"],
        'locations' => ['labels' => ['name'], 'predicate' => 'is_active = 1'],
        'materials' => ['labels' => ['material_name'], 'predicate' => "is_active = 1 AND approval_status = 'approved'"],
    ];

    $source = $sources[$table] ?? null;
    if (!$source || !in_array($labelColumn, $source['labels'], true)) {
        return null;
    }

    return $source;
}

function active_options(PDO $pdo, string $table, string $labelColumn = 'name'): array
{
    $source = active_option_source($table, $labelColumn);
    if (!$source) {
        return [];
    }

    $predicate = $source['predicate'];
    $stmt = $pdo->query("SELECT id, {$labelColumn} AS label FROM {$table} WHERE {$predicate} ORDER BY {$labelColumn}");
    return $stmt->fetchAll();
}

function bool_badge($value): string
{
    return (int) $value === 1 ? '<span class="workflow-badge completed">Active</span>' : '<span class="workflow-badge cancelled">Inactive</span>';
}
