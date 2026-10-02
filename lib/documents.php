<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/numbering.php';

function next_named_document_no(PDO $pdo, string $table, string $column, string $prefix): string
{
    $allowed = [
        'purchase_requests' => ['column' => 'pr_no', 'type' => 'purchase_request'],
        'purchase_orders' => ['column' => 'po_no', 'type' => 'purchase_order'],
        'supplier_deliveries' => ['column' => 'delivery_no', 'type' => 'supplier_delivery'],
        'material_transfers' => ['column' => 'transfer_no', 'type' => 'material_transfer'],
        'material_returns' => ['column' => 'return_no', 'type' => 'material_return'],
        'damage_reports' => ['column' => 'damage_no', 'type' => 'damage_report'],
        'inventory_adjustments' => ['column' => 'adjustment_no', 'type' => 'inventory_adjustment'],
        'stock_counts' => ['column' => 'count_no', 'type' => 'stock_count'],
        'backup_logs' => ['column' => 'backup_no', 'type' => 'backup'],
    ];

    if (!isset($allowed[$table]) || $allowed[$table]['column'] !== $column) {
        throw new InvalidArgumentException('Unsupported document source.');
    }

    if (!preg_match('/^[A-Z0-9]{2,12}$/', $prefix)) {
        throw new InvalidArgumentException('Unsupported document prefix.');
    }

    return next_internal_document_no($pdo, (string) $allowed[$table]['type']);
}
