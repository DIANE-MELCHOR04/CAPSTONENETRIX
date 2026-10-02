<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';

function allowed_attachment_entity(string $entityType): bool
{
    return in_array($entityType, ['request','purchase_order','damage_report','return','supplier_delivery','stock_count'], true);
}


function assert_attachment_record_access(PDO $pdo, string $entityType, int $entityId): void
{
    if (!allowed_attachment_entity($entityType) || $entityId <= 0) {
        http_response_code(404);
        die('Attachment target not found.');
    }
    $role = (string) ($_SESSION['role'] ?? '');
    $userId = (int) (current_user()['id'] ?? 0);
    $permissions = [
        'request' => ['create_requests','view_requests','view_own_requests','approve_requests','manage_procurement','release_materials'],
        'purchase_order' => ['manage_procurement','approve_procurement','receive_supplier_deliveries'],
        'damage_report' => ['manage_damage','report_site_damage','approve_damage'],
        'return' => ['create_returns','post_returns','view_returns'],
        'supplier_delivery' => ['receive_supplier_deliveries','manage_procurement','approve_procurement'],
        'stock_count' => ['manage_stock_counts','approve_stock_counts','view_stock_counts'],
    ];
    $permitted = false;
    foreach ($permissions[$entityType] ?? [] as $permission) {
        if (role_can($role, $permission)) { $permitted = true; break; }
    }
    if (!$permitted) {
        http_response_code(403);
        die('You do not have permission to access this attachment.');
    }

    // Site Engineers are additionally scoped to records they own at their site.
    if ($role === 'site_engineer') {
        if ($entityType === 'request') {
            $stmt=$pdo->prepare('SELECT requested_by,site_id FROM material_requests WHERE id=?'); $stmt->execute([$entityId]); $row=$stmt->fetch();
            if(!$row || (int)$row['requested_by']!==$userId || (int)$row['site_id']!==(int)(current_user_site_id()??0)) { http_response_code(403); die('You may only access attachments for your own site records.'); }
        } elseif ($entityType === 'damage_report') {
            $stmt=$pdo->prepare('SELECT d.reported_by,l.site_id FROM damage_reports d JOIN locations l ON l.id=d.location_id WHERE d.id=?'); $stmt->execute([$entityId]); $row=$stmt->fetch();
            if(!$row || (int)$row['reported_by']!==$userId || (int)$row['site_id']!==(int)(current_user_site_id()??0)) { http_response_code(403); die('You may only access attachments for your own site records.'); }
        } elseif ($entityType === 'return') {
            $stmt=$pdo->prepare('SELECT returned_by,source_site_id FROM material_returns WHERE id=?'); $stmt->execute([$entityId]); $row=$stmt->fetch();
            if(!$row || (int)$row['returned_by']!==$userId || (int)$row['source_site_id']!==(int)(current_user_site_id()??0)) { http_response_code(403); die('You may only access attachments for your own site records.'); }
        } else {
            http_response_code(403);
            die('You do not have permission to access this attachment type.');
        }
    }
}

function attachment_rows(PDO $pdo, string $entityType, int $entityId): array
{
    if (!allowed_attachment_entity($entityType)) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT a.*, u.full_name FROM attachments a JOIN users u ON u.id=a.uploaded_by WHERE a.entity_type=? AND a.entity_id=? ORDER BY a.created_at DESC');
    $stmt->execute([$entityType, $entityId]);
    return $stmt->fetchAll();
}

function store_attachment(PDO $pdo, string $entityType, int $entityId, array $file, int $uploadedBy): int
{
    if (!allowed_attachment_entity($entityType) || $entityId <= 0) {
        throw new InvalidArgumentException('Unsupported attachment target.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Choose a file to upload.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        throw new RuntimeException('Attachment must be between 1 byte and 8 MB.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xls',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only PDF, JPG, PNG, XLSX, and XLS files are allowed.');
    }
    $dir = dirname(__DIR__) . '/storage/attachments/' . $entityType;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create attachment storage directory.');
    }
    $storedName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $absolute = $dir . '/' . $storedName;
    if (!move_uploaded_file($tmp, $absolute)) {
        throw new RuntimeException('Could not store the uploaded file.');
    }
    $original = basename((string) ($file['name'] ?? ('attachment.' . $allowed[$mime])));
    $relative = 'storage/attachments/' . $entityType . '/' . $storedName;
    $pdo->prepare('INSERT INTO attachments (entity_type,entity_id,file_name,stored_path,mime_type,file_size,uploaded_by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$entityType,$entityId,$original,$relative,$mime,$size,$uploadedBy]);
    $id = (int) $pdo->lastInsertId();
    write_audit($pdo, $uploadedBy, 'attach', $entityType, $entityId, 'Attachment uploaded: ' . $original);
    return $id;
}
