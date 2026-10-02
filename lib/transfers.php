<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/settings.php';

function validate_transfer_locations(int $sourceLocationId, int $destinationLocationId): bool
{
    return $sourceLocationId > 0 && $destinationLocationId > 0 && $sourceLocationId !== $destinationLocationId;
}

function transfer_item_status(float $requested, float $dispatched, float $received, float $discrepancy): string
{
    if ($discrepancy > 0) {
        return 'discrepancy';
    }
    if ($dispatched <= 0) {
        return 'approved';
    }
    if ($received >= $dispatched && $dispatched > 0) {
        return 'received';
    }
    if ($received > 0) {
        return 'partially_received';
    }

    return 'in_transit';
}

function transfer_status(array $itemStatuses): string
{
    if (!$itemStatuses) {
        return 'draft';
    }
    if (in_array('discrepancy', $itemStatuses, true)) {
        return 'discrepancy';
    }
    if (count(array_filter($itemStatuses, static fn($status) => $status !== 'received')) === 0) {
        return 'received';
    }
    if (in_array('partially_received', $itemStatuses, true)) {
        return 'partially_received';
    }
    if (in_array('in_transit', $itemStatuses, true)) {
        return 'in_transit';
    }
    if (in_array('approved', $itemStatuses, true)) {
        return 'approved';
    }
    if (in_array('submitted', $itemStatuses, true)) {
        return 'submitted';
    }

    return 'draft';
}

function refresh_transfer_status(PDO $pdo, int $transferId): string
{
    $stmt = $pdo->prepare('SELECT status FROM material_transfer_items WHERE transfer_id = ?');
    $stmt->execute([$transferId]);
    $status = transfer_status(array_column($stmt->fetchAll(), 'status'));
    $pdo->prepare('UPDATE material_transfers SET status = ? WHERE id = ?')->execute([$status, $transferId]);

    return $status;
}

function adjust_in_transit(PDO $pdo, int $materialId, int $locationId, float $delta): void
{
    $balance = ensure_inventory_balance($pdo, $materialId, $locationId);
    $newInTransit = (float) $balance['in_transit_quantity'] + $delta;
    if ($newInTransit < 0) {
        throw new RuntimeException('In-transit quantity cannot be negative.');
    }
    $pdo->prepare('UPDATE inventory_balances SET in_transit_quantity = ? WHERE id = ?')->execute([$newInTransit, (int) $balance['id']]);
}

function approve_transfer(PDO $pdo, int $transferId, int $approvedBy, string $remarks): void
{
    $stmt = $pdo->prepare("SELECT * FROM material_transfers WHERE id = ? FOR UPDATE");
    $stmt->execute([$transferId]);
    $transfer = $stmt->fetch();
    if (!$transfer || !in_array((string) $transfer['status'], ['draft', 'submitted'], true)) {
        throw new RuntimeException('This transfer cannot be approved.');
    }

    $pdo->prepare("UPDATE material_transfers SET status = 'approved', approved_by = ?, approved_at = NOW(), approval_remarks = ? WHERE id = ?")
        ->execute([$approvedBy, $remarks, $transferId]);
    $pdo->prepare("UPDATE material_transfer_items SET status = 'approved' WHERE transfer_id = ?")->execute([$transferId]);
    write_audit($pdo, $approvedBy, 'approve', 'material_transfer', $transferId, 'Transfer approved.');
}

function reject_transfer(PDO $pdo, int $transferId, int $rejectedBy, string $remarks): void
{
    $stmt = $pdo->prepare("SELECT * FROM material_transfers WHERE id = ? FOR UPDATE");
    $stmt->execute([$transferId]);
    $transfer = $stmt->fetch();
    if (!$transfer || !in_array((string) $transfer['status'], ['draft', 'submitted'], true)) {
        throw new RuntimeException('This transfer cannot be rejected.');
    }

    $pdo->prepare("UPDATE material_transfers SET status = 'rejected', approved_by = ?, approved_at = NOW(), approval_remarks = ? WHERE id = ?")
        ->execute([$rejectedBy, $remarks, $transferId]);
    $pdo->prepare("UPDATE material_transfer_items SET status = 'rejected' WHERE transfer_id = ?")->execute([$transferId]);
    write_audit($pdo, $rejectedBy, 'reject', 'material_transfer', $transferId, 'Transfer rejected.');
}

function dispatch_transfer(PDO $pdo, int $transferId, int $dispatchedBy): void
{
    $stmt = $pdo->prepare("SELECT * FROM material_transfers WHERE id = ? FOR UPDATE");
    $stmt->execute([$transferId]);
    $transfer = $stmt->fetch();
    if (!$transfer || (string) $transfer['status'] !== 'approved') {
        throw new RuntimeException('This transfer is not ready for dispatch.');
    }
    $scopeStmt = $pdo->prepare('SELECT u.site_id AS user_site_id, l.site_id AS location_site_id FROM users u JOIN locations l ON l.id = ? WHERE u.id = ?');
    $scopeStmt->execute([(int) $transfer['source_location_id'], $dispatchedBy]);
    $scope = $scopeStmt->fetch();
    if ($scope && $scope['user_site_id'] !== null && (int) $scope['user_site_id'] !== (int) $scope['location_site_id']) {
        throw new RuntimeException('Only personnel assigned to the source site can dispatch this transfer.');
    }

    $items = $pdo->prepare("SELECT * FROM material_transfer_items WHERE transfer_id = ? AND status = 'approved' FOR UPDATE");
    $items->execute([$transferId]);
    $rows = $items->fetchAll();
    if (!$rows) {
        throw new RuntimeException('No transfer items are ready for dispatch.');
    }

    foreach ($rows as $item) {
        $materialId = (int) $item['material_id'];
        $sourceLocationId = (int) $transfer['source_location_id'];
        $qty = (float) $item['requested_qty'];
        $balance = ensure_inventory_balance($pdo, $materialId, $sourceLocationId);
        $available = available_stock((float) $balance['quantity_on_hand'], (float) $balance['reserved_quantity'], (float) $balance['damaged_quantity']);
        if ($qty > $available) {
            throw new RuntimeException('Transfer quantity exceeds available stock.');
        }

        post_inventory_movement($pdo, [
            'movement_type' => 'transfer_dispatch',
            'material_id' => $materialId,
            'location_id' => $sourceLocationId,
            'quantity_in' => 0,
            'quantity_out' => $qty,
            'reference_type' => 'transfer',
            'reference_id' => $transferId,
            'remarks' => 'Transfer dispatched',
            'created_by' => $dispatchedBy,
        ]);
        adjust_in_transit($pdo, $materialId, $sourceLocationId, $qty);
        $pdo->prepare("UPDATE material_transfer_items SET dispatched_qty = ?, status = 'in_transit' WHERE id = ?")
            ->execute([$qty, (int) $item['id']]);
    }

    $pdo->prepare("UPDATE material_transfers SET status = 'in_transit', dispatched_by = ?, dispatched_at = NOW() WHERE id = ?")
        ->execute([$dispatchedBy, $transferId]);
    write_audit($pdo, $dispatchedBy, 'dispatch', 'material_transfer', $transferId, 'Transfer dispatched.');
}

function receive_transfer(PDO $pdo, int $transferId, array $receivedQtyByItemId, int $receivedBy, string $remarks): void
{
    $stmt = $pdo->prepare("SELECT * FROM material_transfers WHERE id = ? FOR UPDATE");
    $stmt->execute([$transferId]);
    $transfer = $stmt->fetch();
    if (!$transfer || !in_array((string) $transfer['status'], ['in_transit', 'partially_received', 'discrepancy'], true)) {
        throw new RuntimeException('This transfer cannot receive materials.');
    }
    if ((int) ($transfer['dispatched_by'] ?? 0) === $receivedBy) {
        throw new RuntimeException('The person who dispatched a transfer cannot also receive it.');
    }
    $scopeStmt = $pdo->prepare('SELECT u.site_id AS user_site_id, l.site_id AS location_site_id FROM users u JOIN locations l ON l.id = ? WHERE u.id = ?');
    $scopeStmt->execute([(int) $transfer['destination_location_id'], $receivedBy]);
    $scope = $scopeStmt->fetch();
    if ($scope && $scope['user_site_id'] !== null && (int) $scope['user_site_id'] !== (int) $scope['location_site_id']) {
        throw new RuntimeException('Only personnel assigned to the destination site can receive this transfer.');
    }

    $receivedAny = false;
    foreach ($receivedQtyByItemId as $itemId => $qty) {
        $qty = (float) $qty;
        if ($qty <= 0) {
            continue;
        }

        $itemStmt = $pdo->prepare('SELECT * FROM material_transfer_items WHERE id = ? AND transfer_id = ? FOR UPDATE');
        $itemStmt->execute([(int) $itemId, $transferId]);
        $item = $itemStmt->fetch();
        if (!$item) {
            throw new RuntimeException('Transfer item was not found.');
        }

        $remaining = (float) $item['dispatched_qty'] - (float) $item['received_qty'];
        if ($qty > $remaining) {
            throw new RuntimeException('Received quantity exceeds dispatched transfer quantity.');
        }

        $newReceived = (float) $item['received_qty'] + $qty;
        $discrepancy = max(0, (float) $item['dispatched_qty'] - $newReceived);
        if ($discrepancy > 0 && trim($remarks) === '') {
            $discrepancy = 0;
        }
        $status = transfer_item_status((float) $item['requested_qty'], (float) $item['dispatched_qty'], $newReceived, $discrepancy);

        post_inventory_movement($pdo, [
            'movement_type' => 'transfer_receipt',
            'material_id' => (int) $item['material_id'],
            'location_id' => (int) $transfer['destination_location_id'],
            'quantity_in' => $qty,
            'quantity_out' => 0,
            'reference_type' => 'transfer',
            'reference_id' => $transferId,
            'remarks' => $remarks ?: 'Transfer received',
            'created_by' => $receivedBy,
        ]);
        adjust_in_transit($pdo, (int) $item['material_id'], (int) $transfer['source_location_id'], -$qty);
        $pdo->prepare('UPDATE material_transfer_items SET received_qty = ?, discrepancy_qty = ?, status = ? WHERE id = ?')
            ->execute([$newReceived, $discrepancy, $status, (int) $item['id']]);
        $receivedAny = true;
    }

    if (!$receivedAny) {
        throw new RuntimeException('Enter at least one received quantity.');
    }

    $status = refresh_transfer_status($pdo, $transferId);
    $pdo->prepare('UPDATE material_transfers SET status = ?, received_by = ?, received_at = NOW(), receipt_remarks = ? WHERE id = ?')
        ->execute([$status, $receivedBy, $remarks, $transferId]);
    write_audit($pdo, $receivedBy, 'receive', 'material_transfer', $transferId, 'Transfer receipt recorded.');
}
