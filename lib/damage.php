<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/settings.php';

function damage_report_status(array $itemStatuses): string
{
    if (!$itemStatuses) {
        return 'draft';
    }
    $unique = array_values(array_unique($itemStatuses));
    if (count($unique) === 1) {
        return $unique[0];
    }
    if (in_array('approved', $itemStatuses, true)) {
        return 'approved';
    }
    if (in_array('submitted', $itemStatuses, true)) {
        return 'submitted';
    }

    return 'draft';
}

function approve_damage_report(PDO $pdo, int $damageReportId, int $approvedBy, string $remarks): void
{
    $stmt = $pdo->prepare("SELECT * FROM damage_reports WHERE id = ? FOR UPDATE");
    $stmt->execute([$damageReportId]);
    $report = $stmt->fetch();
    if (!$report || (string) $report['status'] !== 'submitted') {
        throw new RuntimeException('This damage report cannot be approved.');
    }

    $items = $pdo->prepare("SELECT * FROM damage_report_items WHERE damage_report_id = ? AND status = 'submitted' FOR UPDATE");
    $items->execute([$damageReportId]);
    $rows = $items->fetchAll();
    if (!$rows) {
        throw new RuntimeException('No damage items are available for approval.');
    }

    foreach ($rows as $item) {
        $qty = (float) $item['decision_qty'] > 0 ? (float) $item['decision_qty'] : (float) $item['damaged_qty'];
        $balance = ensure_inventory_balance($pdo, (int) $item['material_id'], (int) $report['location_id']);
        $available = available_stock((float) $balance['quantity_on_hand'], (float) $balance['reserved_quantity'], (float) $balance['damaged_quantity']);
        if ($qty > $available) {
            throw new RuntimeException('Damage quantity exceeds available stock.');
        }

        post_inventory_movement($pdo, [
            'movement_type' => 'damage',
            'material_id' => (int) $item['material_id'],
            'location_id' => (int) $report['location_id'],
            'quantity_in' => 0,
            'quantity_out' => $qty,
            'reference_type' => 'damage_report',
            'reference_id' => $damageReportId,
            'remarks' => $remarks ?: 'Damaged material approved',
            'created_by' => $approvedBy,
        ]);
        $pdo->prepare("UPDATE damage_report_items SET decision_qty = ?, status = 'approved' WHERE id = ?")
            ->execute([$qty, (int) $item['id']]);
    }

    $pdo->prepare("UPDATE damage_reports SET status = 'approved', approved_by = ?, approved_at = NOW(), approval_remarks = ? WHERE id = ?")
        ->execute([$approvedBy, $remarks, $damageReportId]);
    write_audit($pdo, $approvedBy, 'approve', 'damage_report', $damageReportId, 'Damage report approved.');
}

function reject_damage_report(PDO $pdo, int $damageReportId, int $rejectedBy, string $remarks): void
{
    $stmt = $pdo->prepare("SELECT * FROM damage_reports WHERE id = ? FOR UPDATE");
    $stmt->execute([$damageReportId]);
    $report = $stmt->fetch();
    if (!$report || (string) $report['status'] !== 'submitted') {
        throw new RuntimeException('This damage report cannot be rejected.');
    }

    $pdo->prepare("UPDATE damage_reports SET status = 'rejected', approved_by = ?, approved_at = NOW(), approval_remarks = ? WHERE id = ?")
        ->execute([$rejectedBy, $remarks, $damageReportId]);
    $pdo->prepare("UPDATE damage_report_items SET status = 'rejected' WHERE damage_report_id = ?")->execute([$damageReportId]);
    write_audit($pdo, $rejectedBy, 'reject', 'damage_report', $damageReportId, 'Damage report rejected.');
}

