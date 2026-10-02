<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/numbering.php';

function request_item_status(float $requested, float $approved, float $reserved, float $released, float $received): string
{
    if ($approved <= 0) {
        return 'rejected';
    }
    if ($received >= $approved && $approved > 0) {
        return 'completed';
    }
    if ($released >= $approved && $approved > 0) {
        return 'released';
    }
    if ($released > 0) {
        return 'partially_released';
    }

    // Reservation state is more useful operationally than the approval state once
    // stock checking has happened. A partially reserved line must remain visibly
    // releasable while Procurement works on the shortage.
    if ($reserved >= $approved && $approved > 0) {
        return 'reserved';
    }
    if ($reserved > 0 && $reserved < $approved) {
        return 'partially_reserved';
    }
    if ($approved < $requested) {
        return 'partially_approved';
    }
    return 'for_procurement';
}

function aggregate_request_status(array $itemStatuses): string
{
    if (!$itemStatuses) {
        return 'draft';
    }
    if (count(array_unique($itemStatuses)) === 1) {
        $only = (string) $itemStatuses[0];
        return $only === 'reserved' ? 'ready_for_release' : $only;
    }
    if (in_array('completed', $itemStatuses, true) && count(array_filter($itemStatuses, static fn($s) => $s !== 'completed')) === 0) {
        return 'completed';
    }
    if (in_array('partially_released', $itemStatuses, true) || in_array('released', $itemStatuses, true)) {
        return 'partially_released';
    }
    if (in_array('partially_reserved', $itemStatuses, true)) {
        return 'partially_reserved';
    }
    if (in_array('for_procurement', $itemStatuses, true) && in_array('reserved', $itemStatuses, true)) {
        return 'partially_reserved';
    }
    if (in_array('for_procurement', $itemStatuses, true)) {
        return 'for_procurement';
    }
    if (in_array('partially_approved', $itemStatuses, true)) {
        return 'partially_approved';
    }
    if (in_array('reserved', $itemStatuses, true)) {
        return 'partially_reserved';
    }
    return 'under_review';
}

function can_release_reserved(float $reserved, float $release): bool
{
    return validate_release_quantity($reserved, $release);
}

function request_has_releasable_reservations(PDO $pdo, int $requestId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM material_reservations mr
         JOIN material_request_items i ON i.id = mr.request_item_id
         WHERE i.request_id = ?
           AND mr.status <> 'cancelled'
           AND mr.reserved_qty > mr.released_qty
         LIMIT 1"
    );
    $stmt->execute([$requestId]);
    return $stmt->fetchColumn() !== false;
}

function request_releasable_locations(PDO $pdo, int $requestId): array
{
    $stmt = $pdo->prepare(
        "SELECT l.id, l.name AS label, SUM(mr.reserved_qty - mr.released_qty) AS releasable_qty
         FROM material_reservations mr
         JOIN material_request_items i ON i.id = mr.request_item_id
         JOIN locations l ON l.id = mr.location_id
         WHERE i.request_id = ?
           AND mr.status <> 'cancelled'
           AND mr.reserved_qty > mr.released_qty
           AND l.is_active = 1
         GROUP BY l.id, l.name
         ORDER BY l.name"
    );
    $stmt->execute([$requestId]);
    return $stmt->fetchAll();
}

function next_request_no(PDO $pdo): string
{
    return next_internal_document_no($pdo, 'material_request');
}

function refresh_request_status(PDO $pdo, int $requestId): string
{
    $stmt = $pdo->prepare('SELECT item_status FROM material_request_items WHERE request_id = ?');
    $stmt->execute([$requestId]);
    $status = aggregate_request_status(array_column($stmt->fetchAll(), 'item_status'));
    $pdo->prepare('UPDATE material_requests SET status = ? WHERE id = ?')->execute([$status, $requestId]);
    return $status;
}

function reserve_approved_request(PDO $pdo, int $requestId): void
{
    // Source stock from every active warehouse before creating a procurement shortage.
    // Default warehouse is used first, followed by the warehouses with the most
    // available stock for each material.
    $items = $pdo->prepare('SELECT * FROM material_request_items WHERE request_id = ? FOR UPDATE');
    $items->execute([$requestId]);
    $shortageCount = 0;

    foreach ($items->fetchAll() as $item) {
        $approvedQty = (float) $item['approved_qty'];
        if ($approvedQty <= 0) {
            $pdo->prepare("UPDATE material_request_items SET item_status = 'rejected' WHERE id = ?")->execute([(int) $item['id']]);
            continue;
        }

        // Do not silently create duplicate reservations if an already-processed
        // request is reached through a stale page/action URL.
        $existingStmt = $pdo->prepare("SELECT COALESCE(SUM(reserved_qty),0) FROM material_reservations WHERE request_item_id=? AND status <> 'cancelled'");
        $existingStmt->execute([(int) $item['id']]);
        if ((float) $existingStmt->fetchColumn() > 0 || (float) $item['reserved_qty'] > 0) {
            throw new RuntimeException('This request already has stock reservations and cannot be reserved again.');
        }

        $locationStmt = $pdo->prepare(
            "SELECT l.id, l.is_default,
                    COALESCE(b.quantity_on_hand,0) AS quantity_on_hand,
                    COALESCE(b.reserved_quantity,0) AS reserved_quantity,
                    COALESCE(b.damaged_quantity,0) AS damaged_quantity,
                    GREATEST(COALESCE(b.quantity_on_hand,0)-COALESCE(b.reserved_quantity,0)-COALESCE(b.damaged_quantity,0),0) AS available_qty
             FROM locations l
             LEFT JOIN inventory_balances b ON b.location_id=l.id AND b.material_id=?
             WHERE l.type='warehouse' AND l.is_active=1
             ORDER BY l.is_default DESC, available_qty DESC, l.id"
        );
        $locationStmt->execute([(int) $item['material_id']]);
        $locations = $locationStmt->fetchAll();
        if (!$locations) {
            throw new RuntimeException('No active warehouse is configured for stock reservation.');
        }

        $remaining = $approvedQty;
        $reservedTotal = 0.0;
        foreach ($locations as $location) {
            if ($remaining <= 0.00001) {
                break;
            }
            $available = max(0.0, (float) $location['available_qty']);
            if ($available <= 0) {
                continue;
            }
            $reserveQty = min($available, $remaining);
            $balance = ensure_inventory_balance($pdo, (int) $item['material_id'], (int) $location['id']);
            $pdo->prepare('UPDATE inventory_balances SET reserved_quantity = reserved_quantity + ? WHERE id = ?')
                ->execute([$reserveQty, (int) $balance['id']]);
            $pdo->prepare(
                "INSERT INTO material_reservations (request_item_id, material_id, location_id, reserved_qty, created_by)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([(int) $item['id'], (int) $item['material_id'], (int) $location['id'], $reserveQty, (int) current_user()['id']]);
            $reservedTotal += $reserveQty;
            $remaining -= $reserveQty;
        }

        $shortageQty = max(0.0, $approvedQty - $reservedTotal);
        $status = $reservedTotal >= $approvedQty - 0.00001 ? 'reserved' : ($reservedTotal > 0 ? 'partially_reserved' : 'for_procurement');
        if ($shortageQty > 0) {
            $shortageCount++;
        }
        $pdo->prepare('UPDATE material_request_items SET reserved_qty = ?, shortage_qty = ?, item_status = ? WHERE id = ?')
            ->execute([$reservedTotal, $shortageQty, $status, (int) $item['id']]);
    }

    refresh_request_status($pdo, $requestId);
    if ($shortageCount > 0) {
        notify_role(
            $pdo,
            'procurement',
            'True material shortage ready for procurement',
            $shortageCount . ' request line(s) remain short after checking all active warehouses.',
            'purchase_request_create.php?request_id=' . $requestId
        );
    }
}
