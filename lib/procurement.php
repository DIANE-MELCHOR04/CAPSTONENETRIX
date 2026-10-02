<?php
declare(strict_types=1);

require_once __DIR__ . '/duplicate_guard.php';

require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/requests.php';

function purchase_request_status(array $itemStatuses): string
{
    if (!$itemStatuses) {
        return 'draft';
    }

    $unique = array_values(array_unique($itemStatuses));
    if (count($unique) === 1) {
        return $unique[0];
    }

    if (count(array_filter($itemStatuses, static fn($status) => $status !== 'completed')) === 0) {
        return 'completed';
    }
    if (in_array('partially_received', $itemStatuses, true)) {
        return 'partially_received';
    }
    if (in_array('completed', $itemStatuses, true) && in_array('ordered', $itemStatuses, true)) {
        return 'partially_received';
    }
    if (in_array('ordered', $itemStatuses, true)) {
        return 'partially_ordered';
    }
    if (in_array('approved', $itemStatuses, true)) {
        return 'approved';
    }
    if (in_array('submitted', $itemStatuses, true)) {
        return 'submitted';
    }

    return 'draft';
}

function purchase_order_status(float $orderedQty, float $receivedQty): string
{
    if ($receivedQty <= 0) {
        return 'open';
    }
    if ($receivedQty < $orderedQty) {
        return 'partially_received';
    }
    return 'received';
}

function delivery_status(array $itemStatuses): string
{
    if (!$itemStatuses) {
        return 'partial';
    }

    return count(array_filter($itemStatuses, static fn($status) => $status !== 'received')) === 0 ? 'completed' : 'partial';
}

function refresh_purchase_request_status(PDO $pdo, int $purchaseRequestId): string
{
    $stmt = $pdo->prepare('SELECT status FROM purchase_request_items WHERE purchase_request_id = ?');
    $stmt->execute([$purchaseRequestId]);
    $status = purchase_request_status(array_column($stmt->fetchAll(), 'status'));
    $pdo->prepare('UPDATE purchase_requests SET status = ? WHERE id = ?')->execute([$status, $purchaseRequestId]);

    return $status;
}

function refresh_purchase_order_status(PDO $pdo, int $purchaseOrderId): string
{
    $stmt = $pdo->prepare('SELECT SUM(ordered_qty) AS ordered_total, SUM(received_qty) AS received_total FROM purchase_order_items WHERE purchase_order_id = ?');
    $stmt->execute([$purchaseOrderId]);
    $totals = $stmt->fetch() ?: ['ordered_total' => 0, 'received_total' => 0];
    $status = purchase_order_status((float) $totals['ordered_total'], (float) $totals['received_total']);
    $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?')->execute([$status, $purchaseOrderId]);

    return $status;
}

function active_procurement_links_for_request_items(PDO $pdo, array $requestItemIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $requestItemIds))));
    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT pri.source_item_id, pri.id AS purchase_request_item_id, pri.status AS item_status,
                pri.requested_qty AS procurement_requested_qty, pri.ordered_qty AS procurement_ordered_qty, pri.received_qty AS procurement_received_qty,
                pr.id AS purchase_request_id, pr.pr_no, pr.status AS purchase_request_status,
                (SELECT po.po_no FROM purchase_order_items poi JOIN purchase_orders po ON po.id=poi.purchase_order_id WHERE poi.purchase_request_item_id=pri.id AND po.status NOT IN ('rejected','cancelled') ORDER BY po.id DESC LIMIT 1) AS po_no,
                (SELECT po.expected_delivery FROM purchase_order_items poi JOIN purchase_orders po ON po.id=poi.purchase_order_id WHERE poi.purchase_request_item_id=pri.id AND po.status NOT IN ('rejected','cancelled') ORDER BY po.id DESC LIMIT 1) AS expected_delivery,
                (SELECT s.company FROM purchase_order_items poi JOIN purchase_orders po ON po.id=poi.purchase_order_id JOIN suppliers s ON s.id=po.supplier_id WHERE poi.purchase_request_item_id=pri.id AND po.status NOT IN ('rejected','cancelled') ORDER BY po.id DESC LIMIT 1) AS supplier_name
         FROM purchase_request_items pri
         JOIN purchase_requests pr ON pr.id = pri.purchase_request_id
         WHERE pri.source_type = 'material_request'
           AND pri.source_item_id IN ({$placeholders})
           AND pri.status NOT IN ('rejected','cancelled','completed')
           AND pr.status NOT IN ('rejected','cancelled','completed')
         ORDER BY pr.id DESC"
    );
    $stmt->execute($ids);

    $links = [];
    foreach ($stmt->fetchAll() as $row) {
        $sourceItemId = (int) $row['source_item_id'];
        if (!isset($links[$sourceItemId])) {
            $links[$sourceItemId] = $row;
        }
    }
    return $links;
}

function allocate_supplier_delivery_to_original_request(
    PDO $pdo,
    int $purchaseRequestItemId,
    int $locationId,
    float $receivedQty,
    int $actorId
): ?array {
    if ($purchaseRequestItemId <= 0 || $receivedQty <= 0) {
        return null;
    }

    $sourceStmt = $pdo->prepare(
        "SELECT * FROM purchase_request_items
         WHERE id = ? AND source_type = 'material_request' AND source_item_id IS NOT NULL
         FOR UPDATE"
    );
    $sourceStmt->execute([$purchaseRequestItemId]);
    $source = $sourceStmt->fetch();
    if (!$source) {
        return null;
    }

    $requestItemStmt = $pdo->prepare('SELECT * FROM material_request_items WHERE id = ? FOR UPDATE');
    $requestItemStmt->execute([(int) $source['source_item_id']]);
    $requestItem = $requestItemStmt->fetch();
    if (!$requestItem) {
        return null;
    }

    $shortage = max(0.0, (float) $requestItem['shortage_qty']);
    if ($shortage <= 0) {
        return null;
    }

    $balance = ensure_inventory_balance($pdo, (int) $requestItem['material_id'], $locationId);
    $available = available_stock(
        (float) $balance['quantity_on_hand'],
        (float) $balance['reserved_quantity'],
        (float) $balance['damaged_quantity']
    );
    $allocateQty = min($receivedQty, $shortage, $available);
    if ($allocateQty <= 0) {
        return null;
    }

    $pdo->prepare('UPDATE inventory_balances SET reserved_quantity = reserved_quantity + ? WHERE id = ?')
        ->execute([$allocateQty, (int) $balance['id']]);
    $pdo->prepare(
        "INSERT INTO material_reservations (request_item_id, material_id, location_id, reserved_qty, created_by)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([
        (int) $requestItem['id'],
        (int) $requestItem['material_id'],
        $locationId,
        $allocateQty,
        $actorId,
    ]);

    $newReserved = (float) $requestItem['reserved_qty'] + $allocateQty;
    $newShortage = max(0.0, (float) $requestItem['approved_qty'] - $newReserved);
    $newStatus = request_item_status(
        (float) $requestItem['requested_qty'],
        (float) $requestItem['approved_qty'],
        $newReserved,
        (float) $requestItem['released_qty'],
        (float) $requestItem['received_qty']
    );
    $pdo->prepare('UPDATE material_request_items SET reserved_qty = ?, shortage_qty = ?, item_status = ? WHERE id = ?')
        ->execute([$newReserved, $newShortage, $newStatus, (int) $requestItem['id']]);

    return [
        'request_id' => (int) $requestItem['request_id'],
        'request_item_id' => (int) $requestItem['id'],
        'allocated_qty' => $allocateQty,
        'shortage_remaining' => $newShortage,
    ];
}

function create_purchase_request_from_shortages(PDO $pdo, array $requestItemIds, int $createdBy): int
{
    $ids = array_values(array_unique(array_map('intval', $requestItemIds)));
    if (!$ids) {
        throw new InvalidArgumentException('Select at least one shortage item.');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT i.id, i.material_id, i.shortage_qty, i.remarks, r.request_no
         FROM material_request_items i
         JOIN material_requests r ON r.id = i.request_id
         WHERE i.id IN ({$placeholders}) AND i.shortage_qty > 0
         FOR UPDATE"
    );
    $stmt->execute($ids);
    $items = $stmt->fetchAll();
    if (!$items) {
        throw new RuntimeException('No shortage quantities were found.');
    }

    $activeLinks = active_procurement_links_for_request_items($pdo, array_column($items, 'id'));
    if ($activeLinks) {
        $first = reset($activeLinks);
        throw new RuntimeException(
            'Duplicate procurement blocked. This shortage is already under procurement in ' .
            (string) ($first['pr_no'] ?? 'an active purchase request') . '.'
        );
    }

    $prNo = next_named_document_no($pdo, 'purchase_requests', 'pr_no', setting_value($pdo, 'purchase_request_prefix', 'PR'));
    $pdo->prepare(
        "INSERT INTO purchase_requests (pr_no, requested_by, needed_by, priority, source_type, status, remarks)
         VALUES (?, ?, CURDATE(), 'normal', 'material_request', 'submitted', ?)"
    )->execute([$prNo, $createdBy, 'Created from approved material request shortage.']);
    $purchaseRequestId = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare(
        "INSERT INTO purchase_request_items
            (purchase_request_id, material_id, requested_qty, source_type, source_item_id, status, remarks)
         VALUES (?, ?, ?, 'material_request', ?, 'submitted', ?)"
    );

    foreach ($items as $item) {
        $insert->execute([
            $purchaseRequestId,
            (int) $item['material_id'],
            (float) $item['shortage_qty'],
            (int) $item['id'],
            'Shortage from ' . (string) $item['request_no'],
        ]);
    }

    write_audit($pdo, $createdBy, 'create', 'purchase_request', $purchaseRequestId, 'Purchase request created from shortage.');
    notify_role($pdo, 'president', 'Purchase request ready for approval', $prNo . ' is awaiting executive approval.', 'purchase_request_view.php?id=' . $purchaseRequestId);

    return $purchaseRequestId;
}

function approve_purchase_request(PDO $pdo, int $purchaseRequestId, int $approvedBy, string $remarks): void
{
    $stmt = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = ? FOR UPDATE");
    $stmt->execute([$purchaseRequestId]);
    $request = $stmt->fetch();
    if (!$request || !in_array((string) $request['status'], ['draft', 'submitted'], true)) {
        throw new RuntimeException('This purchase request cannot be approved.');
    }

    $pdo->prepare(
        "UPDATE purchase_requests
         SET status = 'approved', approved_by = ?, approved_at = NOW(), approval_remarks = ?
         WHERE id = ?"
    )->execute([$approvedBy, $remarks, $purchaseRequestId]);
    $pdo->prepare("UPDATE purchase_request_items SET status = 'approved' WHERE purchase_request_id = ? AND status IN ('draft','submitted')")->execute([$purchaseRequestId]);

    write_audit($pdo, $approvedBy, 'approve', 'purchase_request', $purchaseRequestId, 'Purchase request approved by the president.');
    notify_role($pdo, 'procurement', 'Purchase request approved', (string) $request['pr_no'] . ' is ready for ordering.', 'purchase_request_view.php?id=' . $purchaseRequestId);
}

function reject_purchase_request(PDO $pdo, int $purchaseRequestId, int $rejectedBy, string $remarks): void
{
    $stmt = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = ? FOR UPDATE");
    $stmt->execute([$purchaseRequestId]);
    $request = $stmt->fetch();
    if (!$request || !in_array((string) $request['status'], ['draft', 'submitted'], true)) {
        throw new RuntimeException('This purchase request cannot be rejected.');
    }

    $pdo->prepare(
        "UPDATE purchase_requests
         SET status = 'rejected', approved_by = ?, approved_at = NOW(), approval_remarks = ?
         WHERE id = ?"
    )->execute([$rejectedBy, $remarks, $purchaseRequestId]);
    $pdo->prepare("UPDATE purchase_request_items SET status = 'rejected' WHERE purchase_request_id = ?")->execute([$purchaseRequestId]);

    write_audit($pdo, $rejectedBy, 'reject', 'purchase_request', $purchaseRequestId, 'Purchase request rejected by the president.');
}

function create_purchase_order(PDO $pdo, int $purchaseRequestId, int $supplierId, int $receivingLocationId, string $expectedDelivery, int $createdBy): int
{
    $locationStmt = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE id=? AND type='warehouse' AND is_active=1");
    $locationStmt->execute([$receivingLocationId]);
    if ((int) $locationStmt->fetchColumn() !== 1) {
        throw new RuntimeException('Select an active warehouse as the intended receiving location.');
    }

    $stmt = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = ? FOR UPDATE");
    $stmt->execute([$purchaseRequestId]);
    $request = $stmt->fetch();
    if (!$request || !in_array((string) $request['status'], ['approved', 'partially_ordered'], true)) {
        throw new RuntimeException('This purchase request is not ready for ordering.');
    }

    $itemsStmt = $pdo->prepare(
        "SELECT *
         FROM purchase_request_items
         WHERE purchase_request_id = ? AND status IN ('approved','partially_ordered')
         FOR UPDATE"
    );
    $itemsStmt->execute([$purchaseRequestId]);
    $items = $itemsStmt->fetchAll();
    if (!$items) {
        throw new RuntimeException('No approved purchase request items are available.');
    }

    $poNo = next_named_document_no($pdo, 'purchase_orders', 'po_no', setting_value($pdo, 'purchase_order_prefix', 'PO'));
    $pdo->prepare(
        "INSERT INTO purchase_orders (po_no, purchase_request_id, supplier_id, receiving_location_id, ordered_by, expected_delivery, status, remarks)
         VALUES (?, ?, ?, ?, ?, ?, 'submitted', ?)"
    )->execute([$poNo, $purchaseRequestId, $supplierId, $receivingLocationId, $createdBy, $expectedDelivery ?: null, 'Created from purchase request ' . (string) $request['pr_no']]);
    $purchaseOrderId = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare(
        'INSERT INTO purchase_order_items (purchase_order_id, purchase_request_item_id, material_id, ordered_qty)
         VALUES (?, ?, ?, ?)'
    );

    foreach ($items as $item) {
        $remaining = (float) $item['requested_qty'] - (float) $item['ordered_qty'];
        if ($remaining <= 0) {
            continue;
        }
        $insert->execute([$purchaseOrderId, (int) $item['id'], (int) $item['material_id'], $remaining]);
        $pdo->prepare("UPDATE purchase_request_items SET ordered_qty = ordered_qty + ?, status = 'ordered' WHERE id = ?")
            ->execute([$remaining, (int) $item['id']]);
    }

    refresh_purchase_request_status($pdo, $purchaseRequestId);
    write_audit($pdo, $createdBy, 'create', 'purchase_order', $purchaseOrderId, 'Purchase order submitted for executive approval.');
    notify_role($pdo, 'president', 'Purchase order ready for approval', $poNo . ' is awaiting executive approval.', 'purchase_order_view.php?id=' . $purchaseOrderId);

    return $purchaseOrderId;
}

function approve_purchase_order(PDO $pdo, int $purchaseOrderId, int $approvedBy, string $remarks): void
{
    $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE");
    $stmt->execute([$purchaseOrderId]);
    $order = $stmt->fetch();
    if (!$order || (string) $order['status'] !== 'submitted') {
        throw new RuntimeException('This purchase order cannot be approved.');
    }

    $pdo->prepare(
        "UPDATE purchase_orders
         SET status = 'open', approved_by = ?, approved_at = NOW(), approval_remarks = ?
         WHERE id = ?"
    )->execute([$approvedBy, $remarks, $purchaseOrderId]);

    write_audit($pdo, $approvedBy, 'approve', 'purchase_order', $purchaseOrderId, 'Purchase order approved by the president.');
    notify_role($pdo, 'procurement', 'Purchase order approved', (string) $order['po_no'] . ' is approved for supplier processing.', 'purchase_order_view.php?id=' . $purchaseOrderId);
}

function reject_purchase_order(PDO $pdo, int $purchaseOrderId, int $rejectedBy, string $remarks): void
{
    $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE");
    $stmt->execute([$purchaseOrderId]);
    $order = $stmt->fetch();
    if (!$order || (string) $order['status'] !== 'submitted') {
        throw new RuntimeException('This purchase order cannot be rejected.');
    }

    $items = $pdo->prepare('SELECT * FROM purchase_order_items WHERE purchase_order_id = ? FOR UPDATE');
    $items->execute([$purchaseOrderId]);
    foreach ($items->fetchAll() as $item) {
        if (!empty($item['purchase_request_item_id'])) {
            $requestItem = $pdo->prepare('SELECT * FROM purchase_request_items WHERE id = ? FOR UPDATE');
            $requestItem->execute([(int) $item['purchase_request_item_id']]);
            $row = $requestItem->fetch();
            if ($row) {
                $newOrdered = max(0, (float) $row['ordered_qty'] - (float) $item['ordered_qty']);
                $newStatus = $newOrdered <= 0 ? 'approved' : ($newOrdered < (float) $row['requested_qty'] ? 'partially_ordered' : 'ordered');
                $pdo->prepare('UPDATE purchase_request_items SET ordered_qty = ?, status = ? WHERE id = ?')
                    ->execute([$newOrdered, $newStatus, (int) $row['id']]);
            }
        }
    }

    $pdo->prepare("UPDATE purchase_order_items SET status = 'cancelled' WHERE purchase_order_id = ?")
        ->execute([$purchaseOrderId]);
    $pdo->prepare(
        "UPDATE purchase_orders
         SET status = 'rejected', approved_by = ?, approved_at = NOW(), approval_remarks = ?
         WHERE id = ?"
    )->execute([$rejectedBy, $remarks, $purchaseOrderId]);

    if (!empty($order['purchase_request_id'])) {
        refresh_purchase_request_status($pdo, (int) $order['purchase_request_id']);
    }

    write_audit($pdo, $rejectedBy, 'reject', 'purchase_order', $purchaseOrderId, 'Purchase order rejected by the president.');
    notify_role($pdo, 'procurement', 'Purchase order rejected', (string) $order['po_no'] . ' requires procurement review.', 'purchase_order_view.php?id=' . $purchaseOrderId);
}

function link_ai_recommendation_to_purchase_request(PDO $pdo, int $recommendationId, int $purchaseRequestId, int $linkedBy): void
{
    $recommendationStmt = $pdo->prepare(
        'SELECT ar.*, pr.id AS linked_request_id, pr.pr_no AS linked_request_no
         FROM ai_recommendations ar
         LEFT JOIN purchase_requests pr ON pr.ai_recommendation_id = ar.id
         WHERE ar.id = ?
         FOR UPDATE'
    );
    $recommendationStmt->execute([$recommendationId]);
    $recommendation = $recommendationStmt->fetch();
    if (!$recommendation) {
        throw new RuntimeException('AI recommendation was not found.');
    }
    if (!empty($recommendation['linked_request_id'])) {
        throw new RuntimeException('This recommendation is already linked to ' . (string) $recommendation['linked_request_no'] . '.');
    }

    $requestStmt = $pdo->prepare(
        "SELECT * FROM purchase_requests
         WHERE id = ? AND status NOT IN ('rejected','completed','cancelled')
         FOR UPDATE"
    );
    $requestStmt->execute([$purchaseRequestId]);
    $request = $requestStmt->fetch();
    if (!$request) {
        throw new RuntimeException('Select an active purchase request.');
    }
    if (!empty($request['ai_recommendation_id'])) {
        throw new RuntimeException('The selected purchase request is already linked to another AI recommendation.');
    }

    $materialStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM purchase_request_items WHERE purchase_request_id = ? AND material_id = ?'
    );
    $materialStmt->execute([$purchaseRequestId, (int) $recommendation['material_id']]);
    if ((int) $materialStmt->fetchColumn() === 0) {
        throw new RuntimeException('The selected purchase request does not contain the recommended material.');
    }

    $pdo->prepare('UPDATE purchase_requests SET ai_recommendation_id = ? WHERE id = ?')
        ->execute([$recommendationId, $purchaseRequestId]);
    $pdo->prepare("UPDATE ai_recommendations SET status = 'converted', reviewed_at = NOW() WHERE id = ?")
        ->execute([$recommendationId]);

    write_audit(
        $pdo,
        $linkedBy,
        'convert',
        'ai_recommendation',
        $recommendationId,
        'Converted to purchase request ' . (string) $request['pr_no'] . '.'
    );
}

function post_supplier_delivery(PDO $pdo, int $purchaseOrderId, int $locationId, array $receivedQtyByOrderItemId, int $receivedBy, string $remarks, string $deliveryReference = ''): int
{
    $orderStmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
    $orderStmt->execute([$purchaseOrderId]);
    $order = $orderStmt->fetch();
    if (!$order || !in_array((string) $order['status'], ['open', 'partially_received'], true)) {
        throw new RuntimeException('This purchase order cannot receive deliveries.');
    }
    if (!empty($order['receiving_location_id']) && (int) $order['receiving_location_id'] !== $locationId) {
        throw new RuntimeException('This purchase order is locked to its intended receiving warehouse.');
    }

    $deliveryReference = trim($deliveryReference);
    assert_unique_supplier_delivery_reference($pdo, $purchaseOrderId, $deliveryReference);

    $deliveryNo = next_named_document_no($pdo, 'supplier_deliveries', 'delivery_no', setting_value($pdo, 'delivery_prefix', 'DEL'));
    $pdo->prepare(
        'INSERT INTO supplier_deliveries (delivery_no, purchase_order_id, location_id, received_by, delivery_reference, status, remarks)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$deliveryNo, $purchaseOrderId, $locationId, $receivedBy, $deliveryReference !== '' ? $deliveryReference : null, 'partial', $remarks]);
    $deliveryId = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare(
        'INSERT INTO supplier_delivery_items (delivery_id, purchase_order_item_id, material_id, received_qty)
         VALUES (?, ?, ?, ?)'
    );

    $postedAny = false;
    $affectedRequestIds = [];
    $allocatedToRequests = [];
    foreach ($receivedQtyByOrderItemId as $orderItemId => $receivedQty) {
        $receivedQty = (float) $receivedQty;
        if ($receivedQty <= 0) {
            continue;
        }

        $itemStmt = $pdo->prepare('SELECT * FROM purchase_order_items WHERE id = ? AND purchase_order_id = ? FOR UPDATE');
        $itemStmt->execute([(int) $orderItemId, $purchaseOrderId]);
        $item = $itemStmt->fetch();
        if (!$item) {
            throw new RuntimeException('Delivery item was not found.');
        }

        $remaining = (float) $item['ordered_qty'] - (float) $item['received_qty'];
        if ($receivedQty > $remaining) {
            throw new RuntimeException('Received quantity exceeds remaining order quantity.');
        }

        $newReceived = (float) $item['received_qty'] + $receivedQty;
        $status = purchase_order_status((float) $item['ordered_qty'], $newReceived);
        $insert->execute([$deliveryId, (int) $item['id'], (int) $item['material_id'], $receivedQty]);
        $pdo->prepare('UPDATE purchase_order_items SET received_qty = ?, status = ? WHERE id = ?')
            ->execute([$newReceived, $status, (int) $item['id']]);

        if (!empty($item['purchase_request_item_id'])) {
            $prStatus = $status === 'received' ? 'completed' : 'partially_received';
            $pdo->prepare('UPDATE purchase_request_items SET received_qty = received_qty + ?, status = ? WHERE id = ?')
                ->execute([$receivedQty, $prStatus, (int) $item['purchase_request_item_id']]);
        }

        post_inventory_movement($pdo, [
            'movement_type' => 'supplier_delivery',
            'material_id' => (int) $item['material_id'],
            'location_id' => $locationId,
            'quantity_in' => $receivedQty,
            'quantity_out' => 0,
            'reference_type' => 'supplier_delivery',
            'reference_id' => $deliveryId,
            'remarks' => $remarks ?: 'Supplier delivery received',
            'created_by' => $receivedBy,
        ]);

        if (!empty($item['purchase_request_item_id'])) {
            $allocation = allocate_supplier_delivery_to_original_request(
                $pdo,
                (int) $item['purchase_request_item_id'],
                $locationId,
                $receivedQty,
                $receivedBy
            );
            if ($allocation) {
                $requestId = (int) $allocation['request_id'];
                $affectedRequestIds[$requestId] = $requestId;
                $allocatedToRequests[] = $allocation;
            }
        }
        $postedAny = true;
    }

    if (!$postedAny) {
        throw new RuntimeException('Enter at least one received quantity.');
    }

    $orderStatus = refresh_purchase_order_status($pdo, $purchaseOrderId);
    $deliveryStatus = $orderStatus === 'received' ? 'completed' : 'partial';
    $pdo->prepare('UPDATE supplier_deliveries SET status = ? WHERE id = ?')->execute([$deliveryStatus, $deliveryId]);

    if (!empty($order['purchase_request_id'])) {
        refresh_purchase_request_status($pdo, (int) $order['purchase_request_id']);
    }

    foreach ($affectedRequestIds as $requestId) {
        refresh_request_status($pdo, (int) $requestId);
    }
    if ($allocatedToRequests) {
        notify_role(
            $pdo,
            'warehouse_personnel',
            'Purchased shortage is ready for release',
            count($allocatedToRequests) . ' delivered shortage line(s) were automatically reserved to their original Material Request.',
            'requests.php'
        );
    }

    write_audit($pdo, $receivedBy, 'receive', 'supplier_delivery', $deliveryId, 'Supplier delivery posted; linked Material Request shortages were allocated where applicable.');

    return $deliveryId;
}
