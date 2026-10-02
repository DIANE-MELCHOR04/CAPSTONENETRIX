<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/requests.php';

function db_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table;
    if (!array_key_exists($key, $cache)) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        $cache[$key] = (int) $stmt->fetchColumn() > 0;
    }
    return $cache[$key];
}

function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table . ':' . $column;
    if (!array_key_exists($key, $cache)) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$table, $column]);
        $cache[$key] = (int) $stmt->fetchColumn() > 0;
    }
    return $cache[$key];
}

function require_flow_upgrade(PDO $pdo, array $requirements = []): void
{
    foreach ($requirements as $requirement) {
        if (is_string($requirement)) {
            if (!db_table_exists($pdo, $requirement)) {
                throw new RuntimeException('Database upgrade required. Import database/flow_accountability_upgrade.sql in phpMyAdmin, then retry.');
            }
            continue;
        }
        if (is_array($requirement) && count($requirement) === 2) {
            if (!db_column_exists($pdo, (string) $requirement[0], (string) $requirement[1])) {
                throw new RuntimeException('Database upgrade required. Import database/flow_accountability_upgrade.sql in phpMyAdmin, then retry.');
            }
        }
    }
}

function site_stock_location_id(PDO $pdo, int $siteId): int
{
    $stmt = $pdo->prepare("SELECT id FROM locations WHERE site_id = ? AND type = 'site' AND is_active = 1 ORDER BY is_default DESC, id LIMIT 1");
    $stmt->execute([$siteId]);
    $id = (int) $stmt->fetchColumn();
    if ($id <= 0) {
        throw new RuntimeException('This project site has no active Site location/stockroom. Create one under Sites / Locations first.');
    }
    return $id;
}

function location_belongs_to_site(PDO $pdo, int $locationId, int $siteId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM locations WHERE id = ? AND site_id = ? AND is_active = 1');
    $stmt->execute([$locationId, $siteId]);
    return (int) $stmt->fetchColumn() === 1;
}

function open_receipt_discrepancy_count(PDO $pdo, ?int $siteId = null): int
{
    if (!db_table_exists($pdo, 'receipt_discrepancies')) {
        return 0;
    }
    if ($siteId === null) {
        return (int) $pdo->query("SELECT COUNT(*) FROM receipt_discrepancies WHERE status = 'open'")->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM receipt_discrepancies d JOIN material_releases rel ON rel.id=d.release_id JOIN material_requests r ON r.id=rel.request_id WHERE d.status='open' AND r.site_id=?");
    $stmt->execute([$siteId]);
    return (int) $stmt->fetchColumn();
}

function reserve_replacement_quantity(PDO $pdo, int $requestItemId, int $materialId, float $quantity, int $actorId, ?int $preferredLocationId = null): float
{
    $quantity = max(0.0, $quantity);
    if ($quantity <= 0) {
        return 0.0;
    }

    $sql = "SELECT l.id, l.is_default,
                   GREATEST(COALESCE(b.quantity_on_hand,0)-COALESCE(b.reserved_quantity,0)-COALESCE(b.damaged_quantity,0),0) AS available_qty
            FROM locations l
            LEFT JOIN inventory_balances b ON b.location_id=l.id AND b.material_id=?
            WHERE l.type='warehouse' AND l.is_active=1
            ORDER BY CASE WHEN l.id=? THEN 0 ELSE 1 END, l.is_default DESC, available_qty DESC, l.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$materialId, $preferredLocationId ?? 0]);
    $remaining = $quantity;
    foreach ($stmt->fetchAll() as $location) {
        if ($remaining <= 0.00001) break;
        $available = max(0.0, (float)$location['available_qty']);
        if ($available <= 0) continue;
        $reserve = min($available, $remaining);
        $balance = ensure_inventory_balance($pdo, $materialId, (int)$location['id']);
        $pdo->prepare('UPDATE inventory_balances SET reserved_quantity=reserved_quantity+? WHERE id=?')->execute([$reserve,(int)$balance['id']]);
        $pdo->prepare("INSERT INTO material_reservations (request_item_id,material_id,location_id,reserved_qty,created_by) VALUES (?,?,?,?,?)")
            ->execute([$requestItemId,$materialId,(int)$location['id'],$reserve,$actorId]);
        $remaining -= $reserve;
    }
    return max(0.0, $remaining);
}

function resolve_receipt_discrepancy(PDO $pdo, int $discrepancyId, string $resolution, int $actorId, string $remarks): void
{
    require_flow_upgrade($pdo, ['receipt_discrepancies']);
    $allowed = ['resolved_received','resolved_returned','resolved_damage','resolved_writeoff'];
    if (!in_array($resolution, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported discrepancy resolution.');
    }
    $stmt = $pdo->prepare("SELECT d.*, rel.request_id, rel.source_location_id, r.site_id FROM receipt_discrepancies d JOIN material_releases rel ON rel.id=d.release_id JOIN material_requests r ON r.id=rel.request_id WHERE d.id=? FOR UPDATE");
    $stmt->execute([$discrepancyId]);
    $row = $stmt->fetch();
    if (!$row || (string) $row['status'] !== 'open') {
        throw new RuntimeException('This discrepancy is no longer open.');
    }

    $variance = (float) $row['variance_qty'];
    if ($resolution === 'resolved_received' && $variance > 0) {
        $siteLocationId = site_stock_location_id($pdo, (int) $row['site_id']);
        post_inventory_movement($pdo, [
            'movement_type' => 'receipt',
            'material_id' => (int) $row['material_id'],
            'location_id' => $siteLocationId,
            'quantity_in' => $variance,
            'quantity_out' => 0,
            'reference_type' => 'release_discrepancy',
            'reference_id' => $discrepancyId,
            'remarks' => $remarks !== '' ? $remarks : 'Receipt discrepancy resolved as subsequently received',
            'created_by' => $actorId,
        ]);
        $pdo->prepare('UPDATE material_release_items SET received_qty = LEAST(released_qty, received_qty + ?) WHERE id = ?')->execute([$variance, (int) $row['release_item_id']]);
        $pdo->prepare('UPDATE material_request_items SET received_qty = LEAST(approved_qty, received_qty + ?) WHERE id = ?')->execute([$variance, (int) $row['request_item_id']]);
    }

    if ($resolution === 'resolved_returned' && $variance > 0) {
        // The material physically came back. Restore it to the source warehouse,
        // reduce the request's net-issued quantity, and reserve the returned stock
        // again so Warehouse Personnel can make a replacement release.
        post_inventory_movement($pdo, [
            'movement_type' => 'return',
            'material_id' => (int) $row['material_id'],
            'location_id' => (int) $row['source_location_id'],
            'quantity_in' => $variance,
            'quantity_out' => 0,
            'reference_type' => 'release_discrepancy',
            'reference_id' => $discrepancyId,
            'remarks' => $remarks !== '' ? $remarks : 'Unreceived release quantity returned to source warehouse',
            'created_by' => $actorId,
        ]);
        $pdo->prepare('UPDATE material_request_items SET released_qty=GREATEST(0,released_qty-?) WHERE id=?')
            ->execute([$variance,(int)$row['request_item_id']]);
        $remainingReplacement = reserve_replacement_quantity($pdo,(int)$row['request_item_id'],(int)$row['material_id'],$variance,$actorId,(int)$row['source_location_id']);
        if ($remainingReplacement > 0) {
            $pdo->prepare('UPDATE material_request_items SET shortage_qty=shortage_qty+? WHERE id=?')
                ->execute([$remainingReplacement,(int)$row['request_item_id']]);
        }
    } elseif (in_array($resolution, ['resolved_damage','resolved_writeoff'], true) && $variance > 0) {
        // Damaged/lost material already left warehouse stock when the release was
        // posted, so no second inventory deduction is made. Instead, turn the
        // missing quantity back into demand and source a replacement from any
        // available warehouse before asking Procurement for the true remainder.
        $pdo->prepare('UPDATE material_request_items SET released_qty=GREATEST(0,released_qty-?) WHERE id=?')
            ->execute([$variance,(int)$row['request_item_id']]);
        $remainingReplacement = reserve_replacement_quantity($pdo,(int)$row['request_item_id'],(int)$row['material_id'],$variance,$actorId,null);
        if ($remainingReplacement > 0) {
            $pdo->prepare('UPDATE material_request_items SET shortage_qty=shortage_qty+? WHERE id=?')
                ->execute([$remainingReplacement,(int)$row['request_item_id']]);
            notify_role($pdo,'procurement','Replacement shortage requires procurement','A receipt discrepancy created a replacement shortage of '.money_or_qty($remainingReplacement).'.','request_view.php?id='.(int)$row['request_id'],'warning');
        }
    }

    // Keep request item and request-level statuses synchronized after resolution.
    $itemStmt = $pdo->prepare('SELECT * FROM material_request_items WHERE id = ? FOR UPDATE');
    $itemStmt->execute([(int) $row['request_item_id']]);
    $item = $itemStmt->fetch();
    if ($item) {
        $itemStatus = request_item_status(
            (float) $item['requested_qty'],
            (float) $item['approved_qty'],
            (float) $item['reserved_qty'],
            (float) $item['released_qty'],
            (float) $item['received_qty']
        );
        $pdo->prepare('UPDATE material_request_items SET item_status = ? WHERE id = ?')
            ->execute([$itemStatus, (int) $row['request_item_id']]);
        refresh_request_status($pdo, (int) $row['request_id']);
    }

    $pdo->prepare('UPDATE receipt_discrepancies SET status=?, resolved_by=?, resolved_at=NOW(), resolution_remarks=? WHERE id=?')
        ->execute([$resolution, $actorId, $remarks, $discrepancyId]);

    $remaining = $pdo->prepare("SELECT COUNT(*) FROM receipt_discrepancies WHERE release_id=? AND status='open'");
    $remaining->execute([(int) $row['release_id']]);
    if ((int) $remaining->fetchColumn() === 0) {
        $incomplete = $pdo->prepare('SELECT COUNT(*) FROM material_release_items WHERE release_id=? AND received_qty < released_qty');
        $incomplete->execute([(int) $row['release_id']]);
        $status = (int) $incomplete->fetchColumn() === 0 ? 'received' : 'partially_received';
        $pdo->prepare('UPDATE material_releases SET status=? WHERE id=?')->execute([$status, (int) $row['release_id']]);
    }
    write_audit($pdo, $actorId, 'resolve_discrepancy', 'material_release', (int) $row['release_id'], 'Receipt discrepancy resolved: ' . $resolution . '.');
}
