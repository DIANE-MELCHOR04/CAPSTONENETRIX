<?php
declare(strict_types=1);

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/high_value.php';

function notification_qty($value): string
{
    return rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
}

function notification_add_rule_count(array &$summary, string $rule, int $created): void
{
    $summary['created'] += $created;
    $summary['rules'][$rule] = ($summary['rules'][$rule] ?? 0) + $created;
}

function generate_low_stock_notifications(PDO $pdo, array &$summary): void
{
    $rows = $pdo->query(
        "SELECT b.id, m.material_code, m.material_name, u.name AS unit_name, l.name AS location_name,
                ROUND(GREATEST(b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity, 0), 2) AS available_qty,
                m.reorder_point
         FROM inventory_balances b
         JOIN materials m ON m.id = b.material_id
         JOIN units u ON u.id = m.unit_id
         JOIN locations l ON l.id = b.location_id
         WHERE m.is_active = 1 AND m.approval_status = 'approved'
           AND l.is_active = 1
           AND GREATEST(b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity, 0) <= m.reorder_point
         ORDER BY (m.reorder_point - GREATEST(b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity, 0)) DESC
         LIMIT 40"
    )->fetchAll();

    foreach ($rows as $row) {
        $title = 'Low stock: ' . (string) $row['material_code'];
        $message = (string) $row['location_name'] . ' has ' . notification_qty($row['available_qty']) . ' ' . (string) $row['unit_name']
            . ' available. Reorder point is ' . notification_qty($row['reorder_point']) . '.';
        $key = 'system:low_stock:' . (int) $row['id'];
        $severity = ((float) $row['available_qty']) <= 0 ? 'danger' : 'warning';
        notification_add_rule_count($summary, 'low_stock', notify_role_once($pdo, 'warehouse_manager', $key, $title, $message, 'reports_low_stock.php', $severity));
        notification_add_rule_count($summary, 'low_stock', notify_role_once($pdo, 'procurement', $key, $title, $message, 'reports_low_stock.php', $severity));
    }
}

function generate_request_notifications(PDO $pdo, array &$summary): void
{
    $approvalRows = $pdo->query(
        "SELECT id, request_no, priority, date_needed
         FROM material_requests
         WHERE status IN ('submitted','under_review')
         ORDER BY FIELD(priority, 'urgent', 'high', 'normal', 'low'), date_needed
         LIMIT 30"
    )->fetchAll();

    foreach ($approvalRows as $row) {
        $created = notify_role_once(
            $pdo,
            'warehouse_manager',
            'system:request_approval:' . (int) $row['id'],
            'Request needs approval: ' . (string) $row['request_no'],
            'A ' . (string) $row['priority'] . ' material request is waiting for manager review.',
            'request_view.php?id=' . (int) $row['id'],
            in_array((string) $row['priority'], ['urgent', 'high'], true) ? 'warning' : 'info'
        );
        notification_add_rule_count($summary, 'request_approval', $created);
    }

    $releaseRows = $pdo->query(
        "SELECT id, request_no, status
         FROM material_requests
         WHERE status IN ('reserved','ready_for_release','partially_released')
         ORDER BY updated_at DESC
         LIMIT 30"
    )->fetchAll();

    foreach ($releaseRows as $row) {
        $created = notify_role_once(
            $pdo,
            'warehouse_personnel',
            'system:request_release:' . (int) $row['id'],
            'Request ready for release: ' . (string) $row['request_no'],
            'Reserved material is ready for warehouse release.',
            'request_view.php?id=' . (int) $row['id'],
            'info'
        );
        notification_add_rule_count($summary, 'request_release', $created);
    }

    $receiptRows = $pdo->query(
        "SELECT rel.id, rel.release_no, req.requested_by
         FROM material_releases rel
         JOIN material_requests req ON req.id = rel.request_id
         WHERE rel.status IN ('released','partially_received')
         ORDER BY rel.release_date DESC
         LIMIT 30"
    )->fetchAll();

    foreach ($receiptRows as $row) {
        if (empty($row['requested_by'])) {
            continue;
        }
        $created = notify_user_once(
            $pdo,
            (int) $row['requested_by'],
            'system:receipt_pending:' . (int) $row['id'],
            'Receipt pending: ' . (string) $row['release_no'],
            'Released materials are waiting for site receipt confirmation.',
            'releases.php',
            'warning'
        ) ? 1 : 0;
        notification_add_rule_count($summary, 'receipt_pending', $created);
    }

    $shortageRows = $pdo->query(
        "SELECT i.id, r.request_no, m.material_code, i.shortage_qty, u.name AS unit_name
         FROM material_request_items i
         JOIN material_requests r ON r.id = i.request_id
         JOIN materials m ON m.id = i.material_id
         JOIN units u ON u.id = m.unit_id
         WHERE i.shortage_qty > 0
           AND NOT EXISTS (
               SELECT 1
               FROM purchase_request_items pri
               JOIN purchase_requests pr ON pr.id = pri.purchase_request_id
               WHERE pri.source_type = 'material_request'
                 AND pri.source_item_id = i.id
                 AND pri.status NOT IN ('rejected','cancelled','completed')
                 AND pr.status NOT IN ('rejected','cancelled','completed')
           )
         ORDER BY i.shortage_qty DESC
         LIMIT 40"
    )->fetchAll();

    foreach ($shortageRows as $row) {
        $created = notify_role_once(
            $pdo,
            'procurement',
            'system:shortage:' . (int) $row['id'],
            'Shortage needs procurement: ' . (string) $row['material_code'],
            (string) $row['request_no'] . ' needs ' . notification_qty($row['shortage_qty']) . ' ' . (string) $row['unit_name'] . ' for supplier ordering.',
            'purchase_request_create.php',
            'warning'
        );
        notification_add_rule_count($summary, 'shortages', $created);
    }
}

function generate_procurement_notifications(PDO $pdo, array &$summary): void
{
    $purchaseRequests = $pdo->query(
        "SELECT id, pr_no, priority
         FROM purchase_requests
         WHERE status = 'submitted'
         ORDER BY FIELD(priority, 'urgent', 'high', 'normal', 'low'), created_at
         LIMIT 30"
    )->fetchAll();

    foreach ($purchaseRequests as $row) {
        $created = notify_role_once(
            $pdo,
            'president',
            'system:purchase_request_approval:' . (int) $row['id'],
            'Purchase request needs approval: ' . (string) $row['pr_no'],
            'A procurement request is waiting for presidential approval.',
            'purchase_request_view.php?id=' . (int) $row['id'],
            in_array((string) $row['priority'], ['urgent', 'high'], true) ? 'warning' : 'info'
        );
        notification_add_rule_count($summary, 'purchase_request_approval', $created);
    }

    $submittedOrders = $pdo->query(
        "SELECT id, po_no
         FROM purchase_orders
         WHERE status = 'submitted'
         ORDER BY created_at
         LIMIT 30"
    )->fetchAll();

    foreach ($submittedOrders as $row) {
        $created = notify_role_once(
            $pdo,
            'president',
            'system:purchase_order_approval:' . (int) $row['id'],
            'Purchase order needs approval: ' . (string) $row['po_no'],
            'A supplier order is waiting for presidential approval.',
            'purchase_order_view.php?id=' . (int) $row['id'],
            'info'
        );
        notification_add_rule_count($summary, 'purchase_order_approval', $created);
    }

    $orders = $pdo->query(
        "SELECT id, po_no, expected_delivery
         FROM purchase_orders
         WHERE status IN ('open','partially_received')
           AND expected_delivery IS NOT NULL
           AND expected_delivery <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
         ORDER BY expected_delivery
         LIMIT 30"
    )->fetchAll();

    foreach ($orders as $row) {
        $overdue = strtotime((string) $row['expected_delivery']) < strtotime(date('Y-m-d'));
        $title = ($overdue ? 'Overdue order: ' : 'Order due soon: ') . (string) $row['po_no'];
        $message = 'Expected delivery is ' . date('M d, Y', strtotime((string) $row['expected_delivery'])) . '.';
        $key = 'system:purchase_order_due:' . (int) $row['id'];
        notification_add_rule_count($summary, 'purchase_order_due', notify_role_once($pdo, 'procurement', $key, $title, $message, 'purchase_orders.php', $overdue ? 'danger' : 'warning'));
    }
}

function generate_transfer_notifications(PDO $pdo, array &$summary): void
{
    $submitted = $pdo->query(
        "SELECT id, transfer_no
         FROM material_transfers
         WHERE status = 'submitted'
         ORDER BY created_at
         LIMIT 30"
    )->fetchAll();

    foreach ($submitted as $row) {
        $created = notify_role_once(
            $pdo,
            'warehouse_manager',
            'system:transfer_approval:' . (int) $row['id'],
            'Transfer needs approval: ' . (string) $row['transfer_no'],
            'A stock transfer is waiting for manager approval.',
            'transfer_view.php?id=' . (int) $row['id'],
            'info'
        );
        notification_add_rule_count($summary, 'transfer_approval', $created);
    }

    $inTransit = $pdo->query(
        "SELECT id, transfer_no, requested_by, status
         FROM material_transfers
         WHERE status IN ('in_transit','partially_received','discrepancy')
         ORDER BY updated_at DESC
         LIMIT 30"
    )->fetchAll();

    foreach ($inTransit as $row) {
        $severity = (string) $row['status'] === 'discrepancy' ? 'danger' : 'warning';
        $created = notify_role_once(
            $pdo,
            'warehouse_personnel',
            'system:transfer_receipt:' . (int) $row['id'],
            'Transfer needs receipt: ' . (string) $row['transfer_no'],
            'Transferred stock is still pending destination receipt or discrepancy review.',
            'transfer_view.php?id=' . (int) $row['id'],
            $severity
        );
        notification_add_rule_count($summary, 'transfer_receipt', $created);

        if (!empty($row['requested_by'])) {
            $createdForRequester = notify_user_once(
                $pdo,
                (int) $row['requested_by'],
                'system:transfer_receipt:' . (int) $row['id'],
                'Transfer needs receipt: ' . (string) $row['transfer_no'],
                'Transferred stock is still pending destination receipt or discrepancy review.',
                'transfer_view.php?id=' . (int) $row['id'],
                $severity
            ) ? 1 : 0;
            notification_add_rule_count($summary, 'transfer_receipt', $createdForRequester);
        }
    }
}

function generate_review_notifications(PDO $pdo, array &$summary): void
{
    $damageRows = $pdo->query(
        "SELECT id, damage_no
         FROM damage_reports
         WHERE status = 'submitted'
         ORDER BY created_at
         LIMIT 30"
    )->fetchAll();

    foreach ($damageRows as $row) {
        $created = notify_role_once(
            $pdo,
            'warehouse_manager',
            'system:damage_review:' . (int) $row['id'],
            'Damage report needs review: ' . (string) $row['damage_no'],
            'A damaged-material report is waiting for approval.',
            'damage_report_view.php?id=' . (int) $row['id'],
            'warning'
        );
        notification_add_rule_count($summary, 'damage_review', $created);
    }

    $countRows = $pdo->query(
        "SELECT id, count_no
         FROM stock_counts
         WHERE status = 'submitted'
         ORDER BY created_at
         LIMIT 30"
    )->fetchAll();

    foreach ($countRows as $row) {
        $created = notify_role_once(
            $pdo,
            'warehouse_manager',
            'system:stock_count_review:' . (int) $row['id'],
            'Stock count needs review: ' . (string) $row['count_no'],
            'A stock count variance is waiting for approval.',
            'stock_count_view.php?id=' . (int) $row['id'],
            'info'
        );
        notification_add_rule_count($summary, 'stock_count_review', $created);
    }

    $adjustmentRows = $pdo->query(
        "SELECT id, adjustment_no
         FROM inventory_adjustments
         WHERE status = 'submitted'
         ORDER BY created_at
         LIMIT 30"
    )->fetchAll();

    foreach ($adjustmentRows as $row) {
        $created = notify_role_once(
            $pdo,
            'warehouse_manager',
            'system:adjustment_review:' . (int) $row['id'],
            'Adjustment needs review: ' . (string) $row['adjustment_no'],
            'An inventory adjustment is waiting for approval.',
            'adjustment_view.php?id=' . (int) $row['id'],
            'info'
        );
        notification_add_rule_count($summary, 'adjustment_review', $created);
    }
}

function generate_ai_recommendation_notifications(PDO $pdo, array &$summary): void
{
    $rows = $pdo->query(
        "SELECT ar.id, ar.title, ar.urgency, m.material_code
         FROM ai_recommendations ar
         JOIN materials m ON m.id = ar.material_id
         WHERE ar.status = 'open' AND ar.urgency IN ('critical','high')
         ORDER BY FIELD(ar.urgency, 'critical', 'high'), ar.created_at DESC
         LIMIT 30"
    )->fetchAll();

    foreach ($rows as $row) {
        $severity = (string) $row['urgency'] === 'critical' ? 'danger' : 'warning';
        $title = 'AI action: ' . (string) $row['material_code'];
        $message = (string) $row['title'] . ' is marked ' . (string) $row['urgency'] . '.';
        $key = 'system:ai_recommendation:' . (int) $row['id'];
        notification_add_rule_count($summary, 'ai_recommendations', notify_role_once($pdo, 'warehouse_manager', $key, $title, $message, 'ai_recommendations.php', $severity));
        notification_add_rule_count($summary, 'ai_recommendations', notify_role_once($pdo, 'procurement', $key, $title, $message, 'ai_recommendations.php', $severity));
    }
}

function generate_escalation_notifications(PDO $pdo, array &$summary): void
{
    $approvalHours = 24;
    if (high_value_table_exists($pdo, 'escalation_rules')) {
        $stmt = $pdo->prepare("SELECT threshold_hours FROM escalation_rules WHERE rule_key = 'approval_overdue' AND is_active = 1 LIMIT 1");
        $stmt->execute();
        $configured = $stmt->fetchColumn();
        if ($configured !== false) {
            $approvalHours = max(1, (int) $configured);
        }
    }

    $requests = $pdo->prepare(
        "SELECT id, request_no, priority, submitted_at
         FROM material_requests
         WHERE status IN ('submitted','under_review')
           AND COALESCE(submitted_at, created_at) <= DATE_SUB(NOW(), INTERVAL ? HOUR)
         ORDER BY COALESCE(submitted_at, created_at)
         LIMIT 30"
    );
    $requests->execute([$approvalHours]);
    foreach ($requests->fetchAll() as $row) {
        $created = notify_role_once(
            $pdo,
            'warehouse_manager',
            'system:approval_overdue:' . (int) $row['id'],
            'Overdue approval: ' . (string) $row['request_no'],
            'This ' . (string) $row['priority'] . ' request has waited more than ' . $approvalHours . ' hour(s) for review.',
            'request_view.php?id=' . (int) $row['id'],
            'danger'
        );
        notification_add_rule_count($summary, 'approval_overdue', $created);
    }

    $orders = $pdo->query(
        "SELECT id, po_no, expected_delivery
         FROM purchase_orders
         WHERE status IN ('open','partially_received')
           AND expected_delivery < CURDATE()
         ORDER BY expected_delivery
         LIMIT 30"
    )->fetchAll();
    foreach ($orders as $row) {
        $created = notify_role_once(
            $pdo,
            'procurement',
            'system:po_overdue:' . (int) $row['id'],
            'Overdue purchase order: ' . (string) $row['po_no'],
            'Expected delivery was ' . date('M d, Y', strtotime((string) $row['expected_delivery'])) . '. Follow up with the supplier.',
            'purchase_order_view.php?id=' . (int) $row['id'],
            'danger'
        );
        notification_add_rule_count($summary, 'po_overdue', $created);
    }

    $lowStock = $pdo->query(
        "SELECT b.id, m.material_code, m.material_name, l.name AS location_name,
                ROUND(GREATEST(b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity, 0), 2) AS available_qty
         FROM inventory_balances b
         JOIN materials m ON m.id = b.material_id
         JOIN locations l ON l.id = b.location_id
         WHERE m.is_active = 1 AND m.approval_status = 'approved'
           AND GREATEST(b.quantity_on_hand - b.reserved_quantity - b.damaged_quantity, 0) <= 0
         ORDER BY m.material_code
         LIMIT 30"
    )->fetchAll();
    foreach ($lowStock as $row) {
        $created = notify_role_once(
            $pdo,
            'warehouse_manager',
            'system:low_stock_escalation:' . (int) $row['id'],
            'Critical low stock: ' . (string) $row['material_code'],
            (string) $row['material_name'] . ' has no available stock at ' . (string) $row['location_name'] . '.',
            'reports_low_stock.php',
            'danger'
        );
        notification_add_rule_count($summary, 'low_stock_escalation', $created);
    }
}


function generate_accountability_notifications(PDO $pdo, array &$summary): void
{
    // Site receipt aging: released material should not remain unconfirmed indefinitely.
    $overdueReceipts = $pdo->query(
        "SELECT rel.id, rel.release_no, req.requested_by,
                rel.release_date AS released_at
         FROM material_releases rel
         JOIN material_requests req ON req.id = rel.request_id
         WHERE rel.status IN ('released','partially_received')
           AND rel.release_date <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY rel.release_date
         LIMIT 40"
    )->fetchAll();

    foreach ($overdueReceipts as $row) {
        if (!empty($row['requested_by'])) {
            $created = notify_user_once(
                $pdo,
                (int) $row['requested_by'],
                'system:site_receipt_overdue:' . (int) $row['id'],
                'Overdue site receipt: ' . (string) $row['release_no'],
                'Released materials have been waiting more than 24 hours for site receipt confirmation.',
                'release_view.php?id=' . (int) $row['id'],
                'danger'
            ) ? 1 : 0;
            notification_add_rule_count($summary, 'site_receipt_overdue', $created);
        }
    }

    if (high_value_table_exists($pdo, 'receipt_discrepancies')) {
        $rows = $pdo->query(
            "SELECT d.id, d.release_id, rel.release_no, m.material_code, d.variance_qty
             FROM receipt_discrepancies d
             JOIN material_releases rel ON rel.id = d.release_id
             JOIN materials m ON m.id = d.material_id
             WHERE d.status = 'open'
             ORDER BY d.created_at
             LIMIT 40"
        )->fetchAll();
        foreach ($rows as $row) {
            $created = notify_role_once(
                $pdo,
                'warehouse_manager',
                'system:receipt_discrepancy:' . (int) $row['id'],
                'Receipt discrepancy: ' . (string) $row['release_no'],
                (string) $row['material_code'] . ' has an unresolved variance of ' . notification_qty($row['variance_qty']) . '.',
                'release_view.php?id=' . (int) $row['release_id'],
                'danger'
            );
            notification_add_rule_count($summary, 'receipt_discrepancy', $created);
        }
    }

    if (high_value_table_exists($pdo, 'manual_stock_out_requests')) {
        $rows = $pdo->query(
            "SELECT r.id, r.request_no, m.material_code, r.quantity
             FROM manual_stock_out_requests r
             JOIN materials m ON m.id = r.material_id
             WHERE r.status = 'submitted'
             ORDER BY r.created_at
             LIMIT 40"
        )->fetchAll();
        foreach ($rows as $row) {
            $created = notify_role_once(
                $pdo,
                'warehouse_manager',
                'system:stock_out_approval:' . (int) $row['id'],
                'Emergency stock-out needs approval: ' . (string) $row['request_no'],
                (string) $row['material_code'] . ' requests ' . notification_qty($row['quantity']) . ' for an exception stock-out.',
                'stock_out.php',
                'warning'
            );
            notification_add_rule_count($summary, 'stock_out_approval', $created);
        }
    }

    if (high_value_table_exists($pdo, 'workflow_cancellations')) {
        $rows = $pdo->query(
            "SELECT id, entity_type, entity_id
             FROM workflow_cancellations
             WHERE status = 'requested'
             ORDER BY created_at
             LIMIT 40"
        )->fetchAll();
        foreach ($rows as $row) {
            $title = 'Cancellation needs review';
            $message = ucfirst(str_replace('_', ' ', (string) $row['entity_type'])) . ' #' . (int) $row['entity_id'] . ' has a pending cancellation/void request.';
            $key = 'system:workflow_cancellation:' . (int) $row['id'];
            notification_add_rule_count($summary, 'workflow_cancellation', notify_role_once($pdo, 'warehouse_manager', $key, $title, $message, 'workflow_control.php', 'warning'));
            notification_add_rule_count($summary, 'workflow_cancellation', notify_role_once($pdo, 'president', $key, $title, $message, 'workflow_control.php', 'warning'));
        }
    }

    if (high_value_table_exists($pdo, 'return_to_supplier_records')) {
        $rows = $pdo->query(
            "SELECT r.id, r.return_no, r.status, m.material_code
             FROM return_to_supplier_records r
             JOIN materials m ON m.id = r.material_id
             WHERE r.status IN ('submitted','approved')
             ORDER BY r.created_at
             LIMIT 40"
        )->fetchAll();
        foreach ($rows as $row) {
            if ((string) $row['status'] === 'submitted') {
                $created = notify_role_once(
                    $pdo,
                    'warehouse_manager',
                    'system:rts_approval:' . (int) $row['id'],
                    'Supplier return needs approval: ' . (string) $row['return_no'],
                    (string) $row['material_code'] . ' is waiting for return-to-supplier approval.',
                    'workflow_control.php',
                    'warning'
                );
                notification_add_rule_count($summary, 'supplier_return_approval', $created);
            } else {
                $created = notify_role_once(
                    $pdo,
                    'warehouse_personnel',
                    'system:rts_posting:' . (int) $row['id'],
                    'Approved supplier return ready to ship: ' . (string) $row['return_no'],
                    (string) $row['material_code'] . ' is approved and waiting for physical shipment/posting.',
                    'workflow_control.php',
                    'info'
                );
                notification_add_rule_count($summary, 'supplier_return_posting', $created);
            }
        }
    }

    if (high_value_table_exists($pdo, 'disposal_writeoffs')) {
        $rows = $pdo->query(
            "SELECT w.id, w.writeoff_no, w.status, m.material_code
             FROM disposal_writeoffs w
             JOIN materials m ON m.id = w.material_id
             WHERE w.status IN ('submitted','approved')
             ORDER BY w.created_at
             LIMIT 40"
        )->fetchAll();
        foreach ($rows as $row) {
            if ((string) $row['status'] === 'submitted') {
                $created = notify_role_once(
                    $pdo,
                    'warehouse_manager',
                    'system:writeoff_approval:' . (int) $row['id'],
                    'Write-off needs approval: ' . (string) $row['writeoff_no'],
                    (string) $row['material_code'] . ' is waiting for disposal/write-off approval.',
                    'workflow_control.php',
                    'warning'
                );
                notification_add_rule_count($summary, 'writeoff_approval', $created);
            } else {
                $created = notify_role_once(
                    $pdo,
                    'warehouse_personnel',
                    'system:writeoff_posting:' . (int) $row['id'],
                    'Approved write-off ready to post: ' . (string) $row['writeoff_no'],
                    (string) $row['material_code'] . ' is approved and waiting for physical posting.',
                    'workflow_control.php',
                    'info'
                );
                notification_add_rule_count($summary, 'writeoff_posting', $created);
            }
        }
    }
}

function generate_needed_notifications(PDO $pdo): array
{
    $summary = [
        'created' => 0,
        'rules' => [
            'low_stock' => 0,
            'request_approval' => 0,
            'request_release' => 0,
            'receipt_pending' => 0,
            'shortages' => 0,
            'purchase_request_approval' => 0,
            'purchase_order_due' => 0,
            'transfer_approval' => 0,
            'transfer_receipt' => 0,
            'damage_review' => 0,
            'stock_count_review' => 0,
            'adjustment_review' => 0,
            'ai_recommendations' => 0,
            'approval_overdue' => 0,
            'po_overdue' => 0,
            'low_stock_escalation' => 0,
            'site_receipt_overdue' => 0,
            'receipt_discrepancy' => 0,
            'stock_out_approval' => 0,
            'workflow_cancellation' => 0,
            'supplier_return_approval' => 0,
            'supplier_return_posting' => 0,
            'writeoff_approval' => 0,
            'writeoff_posting' => 0,
        ],
    ];

    generate_low_stock_notifications($pdo, $summary);
    generate_request_notifications($pdo, $summary);
    generate_procurement_notifications($pdo, $summary);
    generate_transfer_notifications($pdo, $summary);
    generate_review_notifications($pdo, $summary);
    generate_ai_recommendation_notifications($pdo, $summary);
    generate_escalation_notifications($pdo, $summary);
    generate_accountability_notifications($pdo, $summary);

    return $summary;
}
