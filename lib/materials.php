<?php
declare(strict_types=1);

require_once __DIR__ . '/master_data.php';
require_once __DIR__ . '/numbering.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/audit.php';

function normalize_material_identity(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    return preg_replace('/\s+/u', ' ', $value) ?? $value;
}

function material_operational_predicate(string $alias = 'm'): string
{
    if (!preg_match('/^[a-z][a-z0-9_]*$/i', $alias)) {
        throw new InvalidArgumentException('Unsupported SQL alias.');
    }

    return "{$alias}.approval_status = 'approved' AND {$alias}.is_active = 1";
}

function material_visibility_clause(string $role, int $userId): array
{
    if (in_array($role, ['admin', 'warehouse_manager'], true)) {
        return ['sql' => '1 = 1', 'params' => []];
    }

    return [
        'sql' => "(m.approval_status = 'approved' OR m.submitted_by = ?)",
        'params' => [$userId],
    ];
}

function material_input_values(array $data): array
{
    return [
        'material_name' => trim((string) ($data['material_name'] ?? '')),
        'description' => trim((string) ($data['description'] ?? '')),
        'category_id' => ($data['category_id'] ?? '') !== '' ? (int) $data['category_id'] : null,
        'unit_id' => (int) ($data['unit_id'] ?? 0),
        'brand_spec' => trim((string) ($data['brand_spec'] ?? '')),
        'min_stock' => (float) ($data['min_stock'] ?? 0),
        'max_stock' => (float) ($data['max_stock'] ?? 0),
        'reorder_point' => (float) ($data['reorder_point'] ?? 0),
        'safety_stock' => (float) ($data['safety_stock'] ?? 0),
        'lead_time_days' => (int) ($data['lead_time_days'] ?? 0),
        'preferred_supplier_id' => ($data['preferred_supplier_id'] ?? '') !== '' ? (int) $data['preferred_supplier_id'] : null,
    ];
}

function material_duplicate_check(PDO $pdo, array $data, ?int $excludeId = null): array
{
    $values = material_input_values($data);
    $stmt = $pdo->query('SELECT id, material_code, material_name, unit_id, brand_spec, approval_status FROM materials ORDER BY id');
    $targetName = normalize_material_identity($values['material_name']);
    $targetBrand = normalize_material_identity($values['brand_spec']);
    $possible = null;

    foreach ($stmt->fetchAll() as $row) {
        if ($excludeId !== null && (int) $row['id'] === $excludeId) {
            continue;
        }
        if (normalize_material_identity((string) $row['material_name']) !== $targetName) {
            continue;
        }

        $sameUnit = (int) $row['unit_id'] === $values['unit_id'];
        $sameBrand = normalize_material_identity((string) ($row['brand_spec'] ?? '')) === $targetBrand;
        if ($sameUnit && $sameBrand) {
            return ['type' => 'exact', 'material' => $row];
        }
        $possible ??= ['type' => 'possible', 'material' => $row];
    }

    return $possible ?? ['type' => 'none', 'material' => null];
}

function material_by_id(PDO $pdo, int $materialId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT m.*, c.name AS category_name, u.name AS unit_name, s.company AS supplier_name,
                submitter.full_name AS submitted_by_name, reviewer.full_name AS reviewed_by_name
         FROM materials m
         LEFT JOIN material_categories c ON c.id = m.category_id
         JOIN units u ON u.id = m.unit_id
         LEFT JOIN suppliers s ON s.id = m.preferred_supplier_id
         LEFT JOIN users submitter ON submitter.id = m.submitted_by
         LEFT JOIN users reviewer ON reviewer.id = m.reviewed_by
         WHERE m.id = ? LIMIT 1'
    );
    $stmt->execute([$materialId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function begin_material_transaction(PDO $pdo): bool
{
    if ($pdo->inTransaction()) {
        return false;
    }
    $pdo->beginTransaction();
    return true;
}

function finish_material_transaction(PDO $pdo, bool $ownsTransaction): void
{
    if ($ownsTransaction) {
        $pdo->commit();
    }
}

function rollback_material_transaction(PDO $pdo, bool $ownsTransaction): void
{
    if ($ownsTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

function assert_material_input(array $data): array
{
    $errors = validate_material_input($data);
    if ($errors !== []) {
        throw new InvalidArgumentException(implode(' ', $errors));
    }
    return material_input_values($data);
}

function notify_material_reviewers(PDO $pdo, int $materialId, string $code, string $name, string $event): void
{
    $title = $event === 'resubmitted' ? 'Material resubmitted: ' . $code : 'Material needs review: ' . $code;
    $message = $name . ($event === 'resubmitted' ? ' was corrected and resubmitted.' : ' was submitted for approval.');
    $key = 'system:material_review:' . $event . ':' . $materialId;
    notify_role_once($pdo, 'warehouse_manager', $key, $title, $message, 'material_view.php?id=' . $materialId, 'info');
    notify_role_once($pdo, 'admin', $key, $title, $message, 'material_view.php?id=' . $materialId, 'info');
}

function create_material(PDO $pdo, array $data, int $userId, string $role, bool $confirmPossibleDuplicate = false): array
{
    $values = assert_material_input($data);
    $ownsTransaction = begin_material_transaction($pdo);

    try {
        $duplicate = material_duplicate_check($pdo, $values);
        if ($duplicate['type'] === 'exact') {
            throw new DomainException('This material already exists as ' . $duplicate['material']['material_code'] . '.');
        }
        if ($duplicate['type'] === 'possible' && !$confirmPossibleDuplicate) {
            throw new DomainException('A material with the same name already exists. Confirm the material variant before submitting.');
        }

        $code = next_master_code($pdo, 'material');
        $autoApproved = in_array($role, ['admin', 'warehouse_manager'], true);
        $status = $autoApproved ? 'approved' : 'pending';
        $active = $autoApproved ? 1 : 0;
        $reviewerId = $autoApproved ? $userId : null;
        $reviewRemarks = $autoApproved ? 'Created by authorized master-data manager.' : null;

        $stmt = $pdo->prepare(
            'INSERT INTO materials
                (material_code, material_name, description, category_id, unit_id, brand_spec,
                 min_stock, max_stock, reorder_point, safety_stock, lead_time_days, preferred_supplier_id,
                 is_active, approval_status, submitted_by, submitted_at, reviewed_by, reviewed_at, review_remarks)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, IF(? IS NULL, NULL, NOW()), ?)'
        );
        $stmt->execute([
            $code, $values['material_name'], $values['description'], $values['category_id'], $values['unit_id'], $values['brand_spec'],
            $values['min_stock'], $values['max_stock'], $values['reorder_point'], $values['safety_stock'], $values['lead_time_days'], $values['preferred_supplier_id'],
            $active, $status, $userId, $reviewerId, $reviewerId, $reviewRemarks,
        ]);
        $materialId = (int) $pdo->lastInsertId();

        write_audit($pdo, $userId, $autoApproved ? 'material.created' : 'material.submitted', 'material', $materialId, $code . ' - ' . $values['material_name'] . ' (' . $status . ')');
        if (!$autoApproved) {
            notify_material_reviewers($pdo, $materialId, $code, $values['material_name'], 'submitted');
        }

        finish_material_transaction($pdo, $ownsTransaction);
        return ['id' => $materialId, 'code' => $code, 'status' => $status];
    } catch (Throwable $error) {
        rollback_material_transaction($pdo, $ownsTransaction);
        throw $error;
    }
}

function review_material(PDO $pdo, int $materialId, int $reviewerId, string $decision, string $remarks): array
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Unsupported material review decision.');
    }
    $remarks = trim($remarks);
    if ($decision === 'rejected' && $remarks === '') {
        throw new InvalidArgumentException('Rejection remarks are required.');
    }

    $ownsTransaction = begin_material_transaction($pdo);
    try {
        $lock = $pdo->prepare('SELECT id, material_code, material_name, submitted_by, approval_status FROM materials WHERE id = ? FOR UPDATE');
        $lock->execute([$materialId]);
        $material = $lock->fetch();
        if (!$material || $material['approval_status'] !== 'pending') {
            throw new DomainException('This submission has already been reviewed or is unavailable.');
        }

        $stmt = $pdo->prepare(
            "UPDATE materials
             SET approval_status = ?, is_active = ?, reviewed_by = ?, reviewed_at = NOW(), review_remarks = ?
             WHERE id = ? AND approval_status = 'pending'"
        );
        $stmt->execute([$decision, $decision === 'approved' ? 1 : 0, $reviewerId, $remarks !== '' ? $remarks : null, $materialId]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('This submission has already been reviewed.');
        }

        write_audit($pdo, $reviewerId, 'material.' . $decision, 'material', $materialId, (string) $material['material_code'] . ($remarks !== '' ? ' - ' . $remarks : ''));
        if ($material['submitted_by'] !== null) {
            notify_user_once(
                $pdo,
                (int) $material['submitted_by'],
                'system:material_' . $decision . ':' . $materialId,
                'Material ' . $decision . ': ' . $material['material_code'],
                $decision === 'approved' ? 'The material is now available for operational use.' : 'Review remarks: ' . $remarks,
                'material_view.php?id=' . $materialId,
                $decision === 'approved' ? 'success' : 'warning'
            );
        }

        finish_material_transaction($pdo, $ownsTransaction);
        $result = material_by_id($pdo, $materialId);
        if (!$result) {
            throw new RuntimeException('The reviewed material could not be loaded.');
        }
        return ['id' => $materialId, 'code' => $result['material_code'], 'status' => $result['approval_status'], 'is_active' => (int) $result['is_active']];
    } catch (Throwable $error) {
        rollback_material_transaction($pdo, $ownsTransaction);
        throw $error;
    }
}

function resubmit_material(PDO $pdo, int $materialId, array $data, int $userId, bool $confirmPossibleDuplicate = false): array
{
    $values = assert_material_input($data);
    $ownsTransaction = begin_material_transaction($pdo);
    try {
        $lock = $pdo->prepare('SELECT * FROM materials WHERE id = ? FOR UPDATE');
        $lock->execute([$materialId]);
        $material = $lock->fetch();
        if (!$material || $material['approval_status'] !== 'rejected' || (int) $material['submitted_by'] !== $userId) {
            throw new DomainException('Only the original submitter can correct a rejected material.');
        }

        $duplicate = material_duplicate_check($pdo, $values, $materialId);
        if ($duplicate['type'] === 'exact') {
            throw new DomainException('This material already exists as ' . $duplicate['material']['material_code'] . '.');
        }
        if ($duplicate['type'] === 'possible' && !$confirmPossibleDuplicate) {
            throw new DomainException('A material with the same name already exists. Confirm the material variant before resubmitting.');
        }

        $stmt = $pdo->prepare(
            "UPDATE materials SET material_name = ?, description = ?, category_id = ?, unit_id = ?, brand_spec = ?,
                min_stock = ?, max_stock = ?, reorder_point = ?, safety_stock = ?, lead_time_days = ?, preferred_supplier_id = ?,
                approval_status = 'pending', is_active = 0, submitted_at = NOW(), reviewed_by = NULL, reviewed_at = NULL, review_remarks = NULL
             WHERE id = ? AND approval_status = 'rejected' AND submitted_by = ?"
        );
        $stmt->execute([
            $values['material_name'], $values['description'], $values['category_id'], $values['unit_id'], $values['brand_spec'],
            $values['min_stock'], $values['max_stock'], $values['reorder_point'], $values['safety_stock'], $values['lead_time_days'], $values['preferred_supplier_id'],
            $materialId, $userId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('This material can no longer be resubmitted.');
        }

        write_audit($pdo, $userId, 'material.resubmitted', 'material', $materialId, (string) $material['material_code'] . ' resubmitted for approval.');
        notify_material_reviewers($pdo, $materialId, (string) $material['material_code'], $values['material_name'], 'resubmitted');
        finish_material_transaction($pdo, $ownsTransaction);

        return ['id' => $materialId, 'code' => (string) $material['material_code'], 'status' => 'pending'];
    } catch (Throwable $error) {
        rollback_material_transaction($pdo, $ownsTransaction);
        throw $error;
    }
}

function update_approved_material(PDO $pdo, int $materialId, array $data, int $userId, bool $isActive = true): array
{
    $values = assert_material_input($data);
    $ownsTransaction = begin_material_transaction($pdo);
    try {
        $stmt = $pdo->prepare(
            "UPDATE materials SET material_name = ?, description = ?, category_id = ?, unit_id = ?, brand_spec = ?,
                min_stock = ?, max_stock = ?, reorder_point = ?, safety_stock = ?, lead_time_days = ?, preferred_supplier_id = ?, is_active = ?
             WHERE id = ? AND approval_status = 'approved'"
        );
        $stmt->execute([
            $values['material_name'], $values['description'], $values['category_id'], $values['unit_id'], $values['brand_spec'],
            $values['min_stock'], $values['max_stock'], $values['reorder_point'], $values['safety_stock'], $values['lead_time_days'], $values['preferred_supplier_id'],
            $isActive ? 1 : 0, $materialId,
        ]);
        if ($stmt->rowCount() < 1 && material_by_id($pdo, $materialId) === null) {
            throw new DomainException('Approved material was not found.');
        }
        write_audit($pdo, $userId, $isActive ? 'material.updated' : 'material.deactivated', 'material', $materialId, 'Approved material master updated.');
        finish_material_transaction($pdo, $ownsTransaction);
        return material_by_id($pdo, $materialId) ?? [];
    } catch (Throwable $error) {
        rollback_material_transaction($pdo, $ownsTransaction);
        throw $error;
    }
}
