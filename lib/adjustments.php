<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/settings.php';

function adjustment_after_quantity(float $beforeQty, float $adjustmentQty): float
{
    $after = round($beforeQty + $adjustmentQty, 2);
    if ($after < 0) {
        throw new InvalidArgumentException('Adjustment would make inventory negative.');
    }

    return $after;
}

function stock_count_variance(float $systemQty, float $countedQty): float
{
    return round($countedQty - $systemQty, 2);
}

function adjustment_status(array $itemStatuses): string
{
    if (!$itemStatuses) {
        return 'draft';
    }
    $unique = array_values(array_unique($itemStatuses));
    if (count($unique) === 1) {
        return $unique[0];
    }
    if (in_array('approved', $itemStatuses, true) && in_array('rejected', $itemStatuses, true)) {
        return 'partially_approved';
    }
    if (in_array('approved', $itemStatuses, true)) {
        return 'approved';
    }

    return $itemStatuses[0] ?? 'draft';
}

function approve_adjustment(PDO $pdo, int $adjustmentId, int $approvedBy, string $remarks): void
{
    $stmt = $pdo->prepare("SELECT * FROM inventory_adjustments WHERE id = ? FOR UPDATE");
    $stmt->execute([$adjustmentId]);
    $adjustment = $stmt->fetch();
    if (!$adjustment || (string) $adjustment['status'] !== 'submitted') {
        throw new RuntimeException('This adjustment cannot be approved.');
    }

    $items = $pdo->prepare("SELECT * FROM inventory_adjustment_items WHERE adjustment_id = ? AND status = 'submitted' FOR UPDATE");
    $items->execute([$adjustmentId]);
    $rows = $items->fetchAll();
    if (!$rows) {
        throw new RuntimeException('No adjustment items are available for approval.');
    }

    foreach ($rows as $item) {
        $qty = (float) $item['adjustment_qty'];
        if ($qty == 0.0) {
            $pdo->prepare("UPDATE inventory_adjustment_items SET status = 'approved' WHERE id = ?")->execute([(int) $item['id']]);
            continue;
        }

        post_inventory_movement($pdo, [
            'movement_type' => 'adjustment',
            'material_id' => (int) $item['material_id'],
            'location_id' => (int) $adjustment['location_id'],
            'quantity_in' => $qty > 0 ? $qty : 0,
            'quantity_out' => $qty < 0 ? abs($qty) : 0,
            'reference_type' => 'adjustment',
            'reference_id' => $adjustmentId,
            'remarks' => $remarks ?: (string) $adjustment['reason'],
            'created_by' => $approvedBy,
        ]);
        $pdo->prepare("UPDATE inventory_adjustment_items SET status = 'approved' WHERE id = ?")->execute([(int) $item['id']]);
    }

    $pdo->prepare("UPDATE inventory_adjustments SET status = 'approved', approved_by = ?, approved_at = NOW(), approval_remarks = ? WHERE id = ?")
        ->execute([$approvedBy, $remarks, $adjustmentId]);
    write_audit($pdo, $approvedBy, 'approve', 'inventory_adjustment', $adjustmentId, 'Inventory adjustment approved.');
}

function reject_adjustment(PDO $pdo, int $adjustmentId, int $rejectedBy, string $remarks): void
{
    $stmt = $pdo->prepare("SELECT * FROM inventory_adjustments WHERE id = ? FOR UPDATE");
    $stmt->execute([$adjustmentId]);
    $adjustment = $stmt->fetch();
    if (!$adjustment || (string) $adjustment['status'] !== 'submitted') {
        throw new RuntimeException('This adjustment cannot be rejected.');
    }

    $pdo->prepare("UPDATE inventory_adjustments SET status = 'rejected', approved_by = ?, approved_at = NOW(), approval_remarks = ? WHERE id = ?")
        ->execute([$rejectedBy, $remarks, $adjustmentId]);
    $pdo->prepare("UPDATE inventory_adjustment_items SET status = 'rejected' WHERE adjustment_id = ?")->execute([$adjustmentId]);
    write_audit($pdo, $rejectedBy, 'reject', 'inventory_adjustment', $adjustmentId, 'Inventory adjustment rejected.');
}

function approve_stock_count(PDO $pdo, int $stockCountId, int $approvedBy, string $remarks): void
{
    $stmt = $pdo->prepare("SELECT * FROM stock_counts WHERE id = ? FOR UPDATE");
    $stmt->execute([$stockCountId]);
    $count = $stmt->fetch();
    if (!$count || (string) $count['status'] !== 'submitted') {
        throw new RuntimeException('This stock count cannot be approved.');
    }

    $items = $pdo->prepare("SELECT * FROM stock_count_items WHERE stock_count_id = ? FOR UPDATE");
    $items->execute([$stockCountId]);
    foreach ($items->fetchAll() as $item) {
        $variance = stock_count_variance((float) $item['system_qty'], (float) $item['counted_qty']);
        $pdo->prepare("UPDATE stock_count_items SET variance_qty = ?, status = 'approved' WHERE id = ?")
            ->execute([$variance, (int) $item['id']]);
    }

    $pdo->prepare("UPDATE stock_counts SET status = 'approved', approved_by = ?, approved_at = NOW(), approval_remarks = ? WHERE id = ?")
        ->execute([$approvedBy, $remarks, $stockCountId]);
    write_audit($pdo, $approvedBy, 'approve', 'stock_count', $stockCountId, 'Stock count approved.');
}


function reject_stock_count(PDO $pdo, int $stockCountId, int $rejectedBy, string $remarks): void
{
    $stmt = $pdo->prepare("SELECT * FROM stock_counts WHERE id = ? FOR UPDATE");
    $stmt->execute([$stockCountId]);
    $count = $stmt->fetch();
    if (!$count || (string) $count['status'] !== 'submitted') {
        throw new RuntimeException('This stock count cannot be returned for recount.');
    }
    if (trim($remarks) === '') {
        throw new RuntimeException('Explain why a recount is required.');
    }
    $pdo->prepare("UPDATE stock_counts SET status='rejected', approved_by=?, approved_at=NOW(), approval_remarks=? WHERE id=?")
        ->execute([$rejectedBy, $remarks, $stockCountId]);
    $pdo->prepare("UPDATE stock_count_items SET status='rejected' WHERE stock_count_id=?")->execute([$stockCountId]);
    write_audit($pdo, $rejectedBy, 'reject_recount', 'stock_count', $stockCountId, 'Stock count returned for recount.');
    if (!empty($count['counted_by'])) {
        notify_user($pdo, (int) $count['counted_by'], 'Stock count requires recount', (string) $count['count_no'] . ' was returned for recount.', 'stock_count_view.php?id=' . $stockCountId);
    }
}

function create_adjustment_from_stock_count(PDO $pdo, int $stockCountId, int $createdBy): int
{
    $stmt = $pdo->prepare('SELECT * FROM stock_counts WHERE id = ? FOR UPDATE');
    $stmt->execute([$stockCountId]);
    $count = $stmt->fetch();
    if (!$count || (string) $count['status'] !== 'approved') {
        throw new RuntimeException('Approve the stock count before creating an adjustment.');
    }

    $items = $pdo->prepare('SELECT * FROM stock_count_items WHERE stock_count_id = ? AND variance_qty <> 0 FOR UPDATE');
    $items->execute([$stockCountId]);
    $rows = $items->fetchAll();
    if (!$rows) {
        throw new RuntimeException('No stock count variance needs adjustment.');
    }

    $adjustmentNo = next_named_document_no($pdo, 'inventory_adjustments', 'adjustment_no', setting_value($pdo, 'adjustment_prefix', 'ADJ'));
    $pdo->prepare('INSERT INTO inventory_adjustments (adjustment_no, location_id, adjustment_type, requested_by, status, reason) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$adjustmentNo, (int) $count['location_id'], 'physical_count', $createdBy, 'submitted', 'Stock count variance correction']);
    $adjustmentId = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare('INSERT INTO inventory_adjustment_items (adjustment_id, material_id, before_qty, adjustment_qty, after_qty, status, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as $item) {
        $after = adjustment_after_quantity((float) $item['system_qty'], (float) $item['variance_qty']);
        $insert->execute([$adjustmentId, (int) $item['material_id'], (float) $item['system_qty'], (float) $item['variance_qty'], $after, 'submitted', 'Generated from stock count']);
        $pdo->prepare("UPDATE stock_count_items SET status = 'adjustment_created' WHERE id = ?")->execute([(int) $item['id']]);
    }
    $pdo->prepare("UPDATE stock_counts SET status = 'adjustment_created' WHERE id = ?")->execute([$stockCountId]);
    write_audit($pdo, $createdBy, 'create', 'inventory_adjustment', $adjustmentId, 'Adjustment created from stock count.');

    return $adjustmentId;
}

