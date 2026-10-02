<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/numbering.php';

function available_stock(float $quantityOnHand, float $reservedQuantity, float $damagedQuantity = 0): float
{
    return max(0.0, round($quantityOnHand - $reservedQuantity - $damagedQuantity, 2));
}

function validate_reservation(float $available, float $requested): bool
{
    return $requested >= 0 && $requested <= $available;
}

function validate_release_quantity(float $reserved, float $release): bool
{
    return $release > 0 && $release <= $reserved;
}

function assert_operational_material(PDO $pdo, int $materialId): void
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM materials WHERE id = ? AND approval_status = 'approved' AND is_active = 1 LIMIT 1"
    );
    $stmt->execute([$materialId]);
    if ($stmt->fetchColumn() === false) {
        throw new DomainException('The selected material is not approved for operational use.');
    }
}

function next_document_no(PDO $pdo, string $prefix): string
{
    return match ($prefix) {
        'MOV' => next_internal_document_no($pdo, 'inventory_movement'),
        'REL' => next_internal_document_no($pdo, 'material_release'),
        default => throw new InvalidArgumentException('Unsupported inventory document prefix.'),
    };
}

function ensure_inventory_balance(PDO $pdo, int $materialId, int $locationId): array
{
    assert_operational_material($pdo, $materialId);
    $stmt = $pdo->prepare('SELECT * FROM inventory_balances WHERE material_id = ? AND location_id = ? FOR UPDATE');
    $stmt->execute([$materialId, $locationId]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    $pdo->prepare('INSERT INTO inventory_balances (material_id, location_id) VALUES (?, ?)')->execute([$materialId, $locationId]);
    $stmt->execute([$materialId, $locationId]);
    return $stmt->fetch();
}

function locked_available_stock(PDO $pdo, int $materialId, int $locationId): float
{
    $balance = ensure_inventory_balance($pdo, $materialId, $locationId);
    return available_stock(
        (float) $balance['quantity_on_hand'],
        (float) $balance['reserved_quantity'],
        (float) $balance['damaged_quantity']
    );
}

function post_inventory_movement(PDO $pdo, array $movement): int
{
    $materialId = (int) $movement['material_id'];
    $locationId = (int) $movement['location_id'];
    $qtyIn = (float) ($movement['quantity_in'] ?? 0);
    $qtyOut = (float) ($movement['quantity_out'] ?? 0);

    assert_operational_material($pdo, $materialId);
    if ($qtyIn < 0 || $qtyOut < 0 || ($qtyIn <= 0 && $qtyOut <= 0)) {
        throw new InvalidArgumentException('Movement quantity must be positive.');
    }

    $balance = ensure_inventory_balance($pdo, $materialId, $locationId);
    $newBalance = (float) $balance['quantity_on_hand'] + $qtyIn - $qtyOut;
    if ($newBalance < 0) {
        throw new RuntimeException('The transaction would make inventory negative.');
    }

    $pdo->prepare('UPDATE inventory_balances SET quantity_on_hand = ? WHERE id = ?')->execute([$newBalance, (int) $balance['id']]);

    $movementNo = $movement['movement_no'] ?? next_document_no($pdo, 'MOV');
    $stmt = $pdo->prepare(
        "INSERT INTO inventory_movements
            (movement_no, movement_type, material_id, location_id, quantity_in, quantity_out, balance_after, reference_type, reference_id, remarks, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $movementNo,
        (string) $movement['movement_type'],
        $materialId,
        $locationId,
        $qtyIn,
        $qtyOut,
        $newBalance,
        $movement['reference_type'] ?? null,
        $movement['reference_id'] ?? null,
        $movement['remarks'] ?? null,
        (int) $movement['created_by'],
    ]);

    return (int) $pdo->lastInsertId();
}

function inventory_options(PDO $pdo): array
{
    return [
        'materials' => $pdo->query("SELECT m.id, CONCAT(m.material_code, ' - ', m.material_name, ' (', u.name, ')') AS label FROM materials m JOIN units u ON u.id = m.unit_id WHERE m.is_active = 1 AND m.approval_status = 'approved' ORDER BY m.material_name")->fetchAll(),
        'locations' => $pdo->query("SELECT id, name AS label FROM locations WHERE is_active = 1 ORDER BY type, name")->fetchAll(),
    ];
}
