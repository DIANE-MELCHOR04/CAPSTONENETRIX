<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/flow_upgrade.php';

function return_item_effect(string $conditionStatus): array
{
    return match ($conditionStatus) {
        'reusable' => ['usable_in' => true, 'damaged_in' => false],
        'damaged', 'partially_damaged', 'for_inspection' => ['usable_in' => false, 'damaged_in' => true],
        default => throw new InvalidArgumentException('Unsupported return condition.'),
    };
}

function return_status(array $itemStatuses): string
{
    if (!$itemStatuses) {
        return 'draft';
    }
    if (count(array_filter($itemStatuses, static fn($status) => $status !== 'posted')) === 0) {
        return 'posted';
    }
    if (in_array('posted', $itemStatuses, true)) {
        return 'submitted';
    }

    return $itemStatuses[0] ?? 'draft';
}

function post_material_return(PDO $pdo, int $returnId, int $postedBy): void
{
    $stmt = $pdo->prepare('SELECT * FROM material_returns WHERE id = ? FOR UPDATE');
    $stmt->execute([$returnId]);
    $return = $stmt->fetch();
    if (!$return || !in_array((string) $return['status'], ['draft', 'submitted'], true)) {
        throw new RuntimeException('This return cannot be posted again.');
    }

    $items = $pdo->prepare("SELECT * FROM material_return_items WHERE return_id = ? AND status IN ('draft','submitted') FOR UPDATE");
    $items->execute([$returnId]);
    $rows = $items->fetchAll();
    if (!$rows) {
        throw new RuntimeException('No return items are available for posting.');
    }

    $sourceLocationId = site_stock_location_id($pdo, (int) $return['source_site_id']);

    foreach ($rows as $item) {
        $qty = (float) $item['returned_qty'];
        $effect = return_item_effect((string) $item['condition_status']);
        $sourceBalance = ensure_inventory_balance($pdo, (int) $item['material_id'], $sourceLocationId);
        $sourceAvailable = available_stock((float) $sourceBalance['quantity_on_hand'], (float) $sourceBalance['reserved_quantity'], (float) $sourceBalance['damaged_quantity']);
        if ($qty > $sourceAvailable) {
            throw new RuntimeException('Return quantity exceeds the available project-site stock.');
        }
        post_inventory_movement($pdo, [
            'movement_type' => 'stock_out',
            'material_id' => (int) $item['material_id'],
            'location_id' => $sourceLocationId,
            'quantity_in' => 0,
            'quantity_out' => $qty,
            'reference_type' => 'return_dispatch',
            'reference_id' => $returnId,
            'remarks' => 'Material returned from project site to warehouse',
            'created_by' => $postedBy,
        ]);
        post_inventory_movement($pdo, [
            'movement_type' => 'return',
            'material_id' => (int) $item['material_id'],
            'location_id' => (int) $return['receiving_location_id'],
            'quantity_in' => $qty,
            'quantity_out' => 0,
            'reference_type' => 'return',
            'reference_id' => $returnId,
            'remarks' => (string) $item['condition_status'] . ' material return',
            'created_by' => $postedBy,
        ]);

        if ($effect['damaged_in']) {
            $balance = ensure_inventory_balance($pdo, (int) $item['material_id'], (int) $return['receiving_location_id']);
            $pdo->prepare('UPDATE inventory_balances SET damaged_quantity = damaged_quantity + ? WHERE id = ?')
                ->execute([$qty, (int) $balance['id']]);
        }

        $pdo->prepare("UPDATE material_return_items SET status = 'posted' WHERE id = ?")->execute([(int) $item['id']]);
    }

    $pdo->prepare("UPDATE material_returns SET status = 'posted', posted_by = ?, posted_at = NOW() WHERE id = ?")
        ->execute([$postedBy, $returnId]);
    write_audit($pdo, $postedBy, 'post', 'material_return', $returnId, 'Material return posted.');
}

