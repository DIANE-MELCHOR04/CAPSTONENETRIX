<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/materials.php';
require_once __DIR__ . '/adjustments.php';
require_once __DIR__ . '/transfers.php';
require_once __DIR__ . '/returns.php';
require_once __DIR__ . '/damage.php';
require_once __DIR__ . '/procurement.php';
require_once __DIR__ . '/requests.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/duplicate_guard.php';

const BULK_IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const BULK_IMPORT_MAX_ROWS = 1000;

function bulk_import_definitions(): array
{
    return [
        'materials' => [
            'title' => 'Materials', 'eyebrow' => 'MASTER DATA', 'return' => 'materials.php',
            'permissions' => ['create_materials'],
            'headers' => ['material_name','unit','category','brand_spec','preferred_supplier','minimum_stock','maximum_stock','reorder_point','safety_stock','lead_time_days','description'],
            'required' => ['material_name','unit'],
            'description' => 'Create many material master records at once. Material codes are generated automatically and existing approval rules are preserved.',
        ],
        'sites' => [
            'title' => 'Sites', 'eyebrow' => 'MASTER DATA', 'return' => 'sites.php', 'permissions' => ['manage_master_data'],
            'headers' => ['site_name','address','status'], 'required' => ['site_name'],
            'description' => 'Create project sites in bulk. Site codes are generated automatically.',
        ],
        'locations' => [
            'title' => 'Locations', 'eyebrow' => 'MASTER DATA', 'return' => 'sites.php', 'permissions' => ['manage_master_data'],
            'headers' => ['site_code','location_name','location_type','status'], 'required' => ['location_name','location_type'],
            'description' => 'Create warehouse, site, or quarantine locations. Location codes are generated automatically.',
        ],
        'suppliers' => [
            'title' => 'Suppliers', 'eyebrow' => 'PROCUREMENT DATA', 'return' => 'suppliers.php', 'permissions' => ['manage_suppliers'],
            'headers' => ['company','contact_person','phone','address','status'], 'required' => ['company'],
            'description' => 'Create supplier master records in bulk while checking duplicate company names.',
        ],
        'stock_counts' => [
            'title' => 'Stock Counts', 'eyebrow' => 'PHYSICAL COUNT', 'return' => 'stock_counts.php', 'permissions' => ['manage_stock_counts'],
            'headers' => ['location_code','material_code','counted_quantity','remarks'], 'required' => ['location_code','material_code','counted_quantity'],
            'description' => 'Create one submitted stock count per location. System quantity and variance are calculated during posting.',
        ],
        'material_requests' => [
            'title' => 'Material Requests', 'eyebrow' => 'MATERIAL REQUEST', 'return' => 'requests.php', 'permissions' => ['create_requests'],
            'headers' => ['request_group','site_code','date_needed','priority','material_code','quantity','remarks'], 'required' => ['request_group','site_code','date_needed','material_code','quantity'],
            'description' => 'Rows with the same request_group become one draft material request with multiple material lines.',
        ],
        'purchase_requests' => [
            'title' => 'Purchase Requests', 'eyebrow' => 'PROCUREMENT', 'return' => 'purchase_requests.php', 'permissions' => ['manage_procurement'],
            'headers' => ['request_group','needed_by','priority','material_code','quantity','remarks'], 'required' => ['request_group','material_code','quantity'],
            'description' => 'Rows with the same request_group become one draft purchase request for procurement review.',
        ],
        'transfers' => [
            'title' => 'Transfers', 'eyebrow' => 'INVENTORY TRANSFER', 'return' => 'transfers.php', 'permissions' => ['create_transfers'],
            'headers' => ['transfer_group','source_location_code','destination_location_code','material_code','quantity','remarks'], 'required' => ['transfer_group','source_location_code','destination_location_code','material_code','quantity'],
            'description' => 'Rows with the same transfer_group become one submitted transfer for manager approval.',
        ],
        'returns' => [
            'title' => 'Returns', 'eyebrow' => 'RETURN CONTROL', 'return' => 'returns.php', 'permissions' => ['post_returns'],
            'headers' => ['return_group','source_site_code','receiving_location_code','material_code','quantity','condition','remarks'], 'required' => ['return_group','source_site_code','receiving_location_code','material_code','quantity'],
            'description' => 'Create submitted material returns without posting inventory until the normal return workflow is completed.',
        ],
        'adjustments' => [
            'title' => 'Adjustments', 'eyebrow' => 'BALANCE CONTROL', 'return' => 'adjustments.php', 'permissions' => ['manage_adjustments'],
            'headers' => ['adjustment_group','location_code','material_code','adjustment_quantity','adjustment_type','reason','remarks'], 'required' => ['adjustment_group','location_code','material_code','adjustment_quantity','adjustment_type','reason'],
            'description' => 'Create submitted adjustments for approval. Inventory is not changed by the import itself.',
        ],
        'damage_reports' => [
            'title' => 'Damaged Materials', 'eyebrow' => 'DAMAGE CONTROL', 'return' => 'damage_reports.php', 'permissions' => ['manage_damage'],
            'headers' => ['damage_group','location_code','material_code','damaged_quantity','reason','remarks'], 'required' => ['damage_group','location_code','material_code','damaged_quantity','reason'],
            'description' => 'Create submitted damage reports for warehouse-manager review before any inventory write-off.',
        ],
        'supplier_deliveries' => [
            'title' => 'Supplier Deliveries', 'eyebrow' => 'PROCUREMENT', 'return' => 'purchase_orders.php', 'permissions' => ['receive_supplier_deliveries'],
            'headers' => ['delivery_group','po_no','location_code','material_code','received_quantity','delivery_reference','remarks'], 'required' => ['delivery_group','po_no','location_code','material_code','received_quantity'],
            'description' => 'Validate received quantities against open purchase orders, then post the confirmed delivery into inventory.',
        ],
    ];
}

function bulk_import_definition(string $type): array
{
    $definitions = bulk_import_definitions();
    if (!isset($definitions[$type])) {
        throw new InvalidArgumentException('Unsupported bulk import type.');
    }
    return $definitions[$type];
}

function bulk_import_can(string $type, ?string $role = null): bool
{
    $role ??= (string) ($_SESSION['role'] ?? '');
    $definition = bulk_import_definition($type);
    foreach ($definition['permissions'] as $permission) {
        if (role_can($role, (string) $permission)) {
            return true;
        }
    }
    return false;
}

function require_bulk_import_permission(string $type): void
{
    require_login();
    if (!bulk_import_can($type)) {
        http_response_code(403);
        die('You do not have permission to use this bulk import.');
    }
}

function bulk_import_panel(string $type): string
{
    if (!bulk_import_can($type)) {
        return '';
    }
    $definition = bulk_import_definition($type);
    $typeEsc = e($type);
    $titleEsc = e((string) $definition['title']);
    $descriptionEsc = e((string) $definition['description']);
    $columns = implode(', ', array_map(static fn(string $header): string => str_replace('_', ' ', $header), $definition['headers']));
    $csrf = e(csrf_token());
    return <<<HTML
<section class="form-card ops-section bulk-import-panel">
    <div class="card-heading">
        <div><span class="eyebrow">BULK ENTRY</span><h2>Import {$titleEsc}</h2><p>{$descriptionEsc}</p></div>
        <a class="btn secondary" href="bulk_import.php?action=template&amp;type={$typeEsc}">Download Template</a>
    </div>
    <form class="stock-import-upload" action="bulk_import.php" method="post" enctype="multipart/form-data" data-block-on-submit="Validating spreadsheet">
        <input type="hidden" name="csrf_token" value="{$csrf}">
        <input type="hidden" name="action" value="upload">
        <input type="hidden" name="import_type" value="{$typeEsc}">
        <label>Excel or CSV File<input type="file" name="import_file" accept=".xlsx,.csv" required><small>Columns: {$columns}. Maximum 5 MB and 1,000 data rows.</small></label>
        <button class="btn primary" type="submit">Preview Import</button>
    </form>
</section>
HTML;
}

function ensure_bulk_import_schema(PDO $pdo): void
{
    static $ready = [];
    $id = spl_object_id($pdo);
    if (isset($ready[$id])) {
        return;
    }

    import_enable_mariadb_compat($pdo);
    ensure_import_batch_schema($pdo);

    $requiredColumns = [
        'id', 'import_batch_id', 'row_number', 'data_json', 'resolved_json',
        'validation_status', 'error_message', 'entity_type', 'entity_id', 'created_at',
    ];

    if (!import_schema_selectable($pdo, 'bulk_import_rows', $requiredColumns)) {
        throw new DomainException(
            'The bulk_import_rows table is missing required columns. Run database/import_repair_upgrade.sql in phpMyAdmin on the same database used by this website.'
        );
    }

    $ready[$id] = true;
}

function bulk_import_normalize_header(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/', '', trim($header)) ?? '';
    $header = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $header));
    return trim($header, '_');
}

function bulk_import_row_blank(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string) $value) !== '') return false;
    }
    return true;
}

function bulk_import_validate_headers(array $headerRow, array $allowed, array $required): array
{
    $map = [];
    foreach ($headerRow as $index => $raw) {
        $header = bulk_import_normalize_header((string) $raw);
        if ($header === '') throw new DomainException('Spreadsheet headers cannot be blank.');
        if (!in_array($header, $allowed, true)) throw new DomainException("Unsupported spreadsheet header: {$header}.");
        if (isset($map[$header])) throw new DomainException("Duplicate spreadsheet header: {$header}.");
        $map[$header] = (int) $index;
    }
    foreach ($required as $header) {
        if (!isset($map[$header])) throw new DomainException("Missing required spreadsheet header: {$header}.");
    }
    return $map;
}

function bulk_import_normalize_row(array $row, array $map, array $headers, int $rowNumber): array
{
    foreach ($row as $value) {
        $trimmed = trim((string) $value);
        if ($trimmed !== '' && ($trimmed[0] === '=' || $trimmed[0] === '@' || (($trimmed[0] === '+' || $trimmed[0] === '-') && !preg_match('/^[+-]?\d+(?:\.\d+)?$/', $trimmed)))) {
            throw new DomainException("Row {$rowNumber} contains a formula-like value.");
        }
    }
    $result = ['_row_number' => $rowNumber];
    foreach ($headers as $header) {
        $index = $map[$header] ?? null;
        $result[$header] = $index === null ? '' : trim((string) ($row[$index] ?? ''));
    }
    return $result;
}

function bulk_import_parse_csv(string $path, array $definition): array
{
    $size = filesize($path);
    if ($size === false || $size > BULK_IMPORT_MAX_BYTES) throw new DomainException('The uploaded file must not exceed 5 MB.');
    $contents = file_get_contents($path);
    if ($contents === false || str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) throw new DomainException('The CSV file must be valid UTF-8 text.');
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new DomainException('The CSV file could not be opened.');
    try {
        $header = fgetcsv($handle);
        if ($header === false || bulk_import_row_blank($header)) throw new DomainException('The CSV file must contain a header row.');
        $map = bulk_import_validate_headers($header, $definition['headers'], $definition['required']);
        $rows = []; $rowNumber = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if (bulk_import_row_blank($row)) continue;
            if (count($rows) >= BULK_IMPORT_MAX_ROWS) throw new DomainException('The spreadsheet must not contain more than 1,000 data rows.');
            $rows[] = bulk_import_normalize_row($row, $map, $definition['headers'], $rowNumber);
        }
    } finally { fclose($handle); }
    return $rows;
}

function bulk_import_xml(string $xml, string $label): SimpleXMLElement
{
    $previous = libxml_use_internal_errors(true);
    try {
        $parsed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if (!$parsed instanceof SimpleXMLElement) throw new DomainException("The XLSX {$label} XML is malformed.");
        return $parsed;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
}

function bulk_import_zip_entry(ZipArchive $zip, string $name): string
{
    $value = $zip->getFromName($name);
    if (!is_string($value)) throw new DomainException("The XLSX file is missing {$name}.");
    return $value;
}

function bulk_import_validate_zip(ZipArchive $zip): void
{
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) throw new DomainException('The XLSX archive contains an unreadable entry.');
        $name = (string) ($stat['name'] ?? ''); $size = (int) ($stat['size'] ?? 0);
        $normalized = str_replace('\\', '/', $name);
        if ($name === '' || $normalized !== $name || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) || in_array('..', explode('/', $normalized), true)) {
            throw new DomainException('The XLSX archive contains an unsafe path.');
        }
        $lower = strtolower($name);
        foreach (['vbaproject.bin','externallinks/','embeddings/','oleobject'] as $blocked) {
            if (str_contains($lower, $blocked)) throw new DomainException('Macro-enabled or embedded XLSX content is not supported.');
        }
        if ($size > 10 * 1024 * 1024) throw new DomainException('The XLSX archive contains an oversized entry.');
        $total += $size;
        if ($total > 25 * 1024 * 1024) throw new DomainException('The XLSX archive expands beyond the allowed size.');
    }
}

function bulk_import_relationship_target(string $target): string
{
    // OOXML relationships may legally use either a workbook-relative target
    // (worksheets/sheet1.xml) or an absolute package target
    // (/xl/worksheets/sheet1.xml). Accept both while still rejecting external
    // URLs, drive paths and traversal segments.
    $target = str_replace('\\', '/', trim($target));
    if ($target === '' || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $target)) {
        throw new DomainException('The XLSX workbook contains an unsafe relationship target.');
    }

    $absolutePackagePath = str_starts_with($target, '/');
    $target = ltrim($target, '/');
    $parts = explode('/', $target);
    if (in_array('..', $parts, true)) {
        throw new DomainException('The XLSX workbook contains a path-traversal relationship.');
    }
    $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== '' && $p !== '.'));
    if ($parts === []) {
        throw new DomainException('The XLSX worksheet target is empty.');
    }

    $normalized = implode('/', $parts);
    if ($absolutePackagePath || str_starts_with(strtolower($normalized), 'xl/')) {
        if (!str_starts_with(strtolower($normalized), 'xl/')) {
            throw new DomainException('The XLSX worksheet target is outside the workbook package.');
        }
        return $normalized;
    }
    return 'xl/' . $normalized;
}

function bulk_import_shared_strings(ZipArchive $zip): array
{
    if ($zip->locateName('xl/sharedStrings.xml') === false) return [];
    $xml = bulk_import_xml(bulk_import_zip_entry($zip, 'xl/sharedStrings.xml'), 'shared strings');
    $ns = $xml->getNamespaces(true); $main = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $xml->registerXPathNamespace('s', $main); $strings = [];
    foreach ($xml->xpath('//s:si') ?: [] as $item) {
        $item->registerXPathNamespace('s', $main); $parts = [];
        foreach ($item->xpath('.//s:t') ?: [] as $text) $parts[] = (string) $text;
        $strings[] = implode('', $parts);
    }
    return $strings;
}

function bulk_import_cell_column(string $reference): int
{
    if (!preg_match('/^([A-Z]+)\d+$/i', $reference, $m)) throw new DomainException('The XLSX worksheet contains an invalid cell reference.');
    $column = 0;
    foreach (str_split(strtoupper($m[1])) as $letter) {
        $column = ($column * 26) + (ord($letter) - 64);
        if ($column > 40) throw new DomainException('The XLSX worksheet contains too many columns.');
    }
    return $column - 1;
}

function bulk_import_inline_text(SimpleXMLElement $node, string $namespace): string
{
    $node->registerXPathNamespace('s', $namespace); $parts = [];
    foreach ($node->xpath('.//s:t') ?: [] as $text) $parts[] = (string) $text;
    return implode('', $parts);
}

function bulk_import_worksheet_rows(string $sheetXml, array $sharedStrings): array
{
    $reader = new XMLReader();
    if (!$reader->XML($sheetXml, null, LIBXML_NONET | LIBXML_COMPACT)) throw new DomainException('The XLSX worksheet could not be opened.');
    $rows = [];
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
            $row = bulk_import_xml($reader->readOuterXML(), 'worksheet row');
            $ns = $row->getNamespaces(true); $main = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $rowNumber = (int) ($row->attributes()['r'] ?? 0);
            if ($rowNumber < 1) throw new DomainException('The XLSX worksheet contains an invalid row number.');
            $cells = [];
            foreach ($row->children($main)->c as $cell) {
                $attributes = $cell->attributes(); $column = bulk_import_cell_column((string) ($attributes['r'] ?? '')); $type = (string) ($attributes['t'] ?? '');
                $children = $cell->children($main);
                if (isset($children->f)) throw new DomainException("Row {$rowNumber} contains a formula cell.");
                if ($type === 'inlineStr') $value = bulk_import_inline_text($children->is, $main);
                elseif ($type === 's') { $index = (int) ((string) $children->v); if (!array_key_exists($index, $sharedStrings)) throw new DomainException('The XLSX worksheet references a missing shared string.'); $value = $sharedStrings[$index]; }
                elseif ($type === 'str' || $type === 'n' || $type === '') $value = (string) $children->v;
                else throw new DomainException("The XLSX worksheet contains an unsupported cell type: {$type}.");
                $cells[$column] = $value;
            }
            $rows[$rowNumber] = $cells;
        }
    } finally { $reader->close(); }
    return $rows;
}

function bulk_import_parse_xlsx(string $path, array $definition): array
{
    if (!class_exists('ZipArchive') || !class_exists('XMLReader') || !function_exists('simplexml_load_string')) throw new DomainException('XLSX import needs the PHP ZIP, XMLReader, and SimpleXML extensions. Enable them in PHP, or use CSV import.');
    $size = filesize($path);
    if ($size === false || $size > BULK_IMPORT_MAX_BYTES) throw new DomainException('The uploaded file must not exceed 5 MB.');
    $signature = file_get_contents($path, false, null, 0, 4);
    if ($signature === false || !str_starts_with($signature, "PK\x03\x04")) throw new DomainException('The XLSX file signature is invalid.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new DomainException('The XLSX archive could not be opened.');
    try {
        bulk_import_validate_zip($zip);
        $workbook = bulk_import_xml(bulk_import_zip_entry($zip, 'xl/workbook.xml'), 'workbook');
        $ns = $workbook->getNamespaces(true); $main = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'; $relNs = $ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $sheets = $workbook->children($main)->sheets->children($main)->sheet;
        if (count($sheets) !== 1) throw new DomainException('The XLSX file must contain exactly one worksheet.');
        $sheet = $sheets[0]; $state = strtolower((string) ($sheet->attributes()['state'] ?? 'visible'));
        if ($state !== '' && $state !== 'visible') throw new DomainException('The import worksheet must be visible.');
        $relationshipId = (string) ($sheet->attributes($relNs)['id'] ?? '');
        if ($relationshipId === '') throw new DomainException('The XLSX worksheet relationship is missing.');
        $rels = bulk_import_xml(bulk_import_zip_entry($zip, 'xl/_rels/workbook.xml.rels'), 'relationships');
        $relsNs = $rels->getNamespaces(true); $relsMain = $relsNs[''] ?? 'http://schemas.openxmlformats.org/package/2006/relationships';
        $target = '';
        foreach ($rels->children($relsMain)->Relationship as $relationship) {
            $attrs = $relationship->attributes();
            if ((string) ($attrs['Id'] ?? '') === $relationshipId) { $target = (string) ($attrs['Target'] ?? ''); break; }
        }
        if ($target === '') throw new DomainException('The XLSX worksheet target could not be resolved.');
        $sheetPath = bulk_import_relationship_target($target);
        $rowsByNumber = bulk_import_worksheet_rows(bulk_import_zip_entry($zip, $sheetPath), bulk_import_shared_strings($zip));
        if (!$rowsByNumber) throw new DomainException('The XLSX worksheet is empty.');
        ksort($rowsByNumber); $first = array_key_first($rowsByNumber);
        if ($first === null) throw new DomainException('The XLSX worksheet is empty.');
        $headerRow = $rowsByNumber[$first];
        if (bulk_import_row_blank($headerRow)) throw new DomainException('The XLSX file must contain a header row.');
        $maxIndex = $headerRow ? max(array_keys($headerRow)) : -1; $denseHeader = [];
        for ($i = 0; $i <= $maxIndex; $i++) $denseHeader[$i] = (string) ($headerRow[$i] ?? '');
        $map = bulk_import_validate_headers($denseHeader, $definition['headers'], $definition['required']);
        $result = [];
        foreach ($rowsByNumber as $rowNumber => $row) {
            if ($rowNumber === $first || bulk_import_row_blank($row)) continue;
            if (count($result) >= BULK_IMPORT_MAX_ROWS) throw new DomainException('The spreadsheet must not contain more than 1,000 data rows.');
            $result[] = bulk_import_normalize_row($row, $map, $definition['headers'], (int) $rowNumber);
        }
        return $result;
    } finally { $zip->close(); }
}

function bulk_import_parse_file(string $path, string $extension, array $definition): array
{
    return match (strtolower($extension)) {
        'csv' => bulk_import_parse_csv($path, $definition),
        'xlsx' => bulk_import_parse_xlsx($path, $definition),
        default => throw new DomainException('Only .xlsx and .csv files are supported.'),
    };
}

function bulk_decimal(string $value, bool $allowZero = false, bool $allowNegative = false): float
{
    $value = trim($value);
    if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) throw new DomainException('Enter a number with at most two decimal places.');
    $number = (float) $value;
    if (!$allowNegative && $number < 0) throw new DomainException('Value cannot be negative.');
    if (!$allowZero && abs($number) < 0.000001) throw new DomainException('Value must be greater than zero.');
    return round($number, 2);
}

function bulk_date(string $value, bool $optional = false): ?string
{
    $value = trim($value);
    if ($value === '' && $optional) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new DomainException('Date must use YYYY-MM-DD format.');
    return $value;
}

function bulk_lookup(PDO $pdo, string $sql, array $params, string $message): array
{
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $row = $stmt->fetch();
    if (!$row) throw new DomainException($message);
    return $row;
}

function bulk_operational_material(PDO $pdo, string $code): array
{
    return bulk_lookup($pdo, "SELECT id, material_code, material_name FROM materials WHERE material_code = ? AND is_active = 1 AND approval_status = 'approved'", [$code], "Material code {$code} was not found or is not approved.");
}

function bulk_active_location(PDO $pdo, string $code): array
{
    return bulk_lookup($pdo, 'SELECT id, code, name, site_id FROM locations WHERE code = ? AND is_active = 1', [$code], "Location code {$code} was not found or is inactive.");
}

function bulk_active_site(PDO $pdo, string $code): array
{
    return bulk_lookup($pdo, "SELECT id, code, name FROM sites WHERE code = ? AND status = 'active'", [$code], "Site code {$code} was not found or is not active.");
}

function bulk_import_validate_row(PDO $pdo, string $type, array $row): array
{
    $resolved = []; $errors = [];
    $add = static function (Throwable $e) use (&$errors): void { $errors[] = $e->getMessage(); };
    try {
        switch ($type) {
            case 'materials':
                if ($row['material_name'] === '') throw new DomainException('Material name is required.');
                if (mb_strlen($row['material_name']) > 180) throw new DomainException('Material name is too long.');
                $unit = bulk_lookup($pdo, 'SELECT id, name FROM units WHERE (LOWER(name)=? OR LOWER(code)=?) AND is_active=1 LIMIT 1', [strtolower($row['unit']),strtolower($row['unit'])], 'Unit was not found or is inactive.');
                $resolved['unit_id'] = (int) $unit['id'];
                if ($row['category'] !== '') { $cat = bulk_lookup($pdo, 'SELECT id FROM material_categories WHERE LOWER(name)=? AND is_active=1 LIMIT 1', [strtolower($row['category'])], 'Category was not found or is inactive.'); $resolved['category_id'] = (int) $cat['id']; } else $resolved['category_id'] = null;
                if ($row['preferred_supplier'] !== '') { $sup = bulk_lookup($pdo, 'SELECT id FROM suppliers WHERE LOWER(company)=? AND is_active=1 LIMIT 1', [strtolower($row['preferred_supplier'])], 'Preferred supplier was not found or is inactive.'); $resolved['preferred_supplier_id'] = (int) $sup['id']; } else $resolved['preferred_supplier_id'] = null;
                foreach (['minimum_stock','maximum_stock','reorder_point','safety_stock'] as $field) $resolved[$field] = $row[$field] === '' ? 0.0 : bulk_decimal($row[$field], true);
                if ($row['lead_time_days'] !== '' && !preg_match('/^\d+$/', $row['lead_time_days'])) throw new DomainException('Lead time days must be a whole non-negative number.');
                $resolved['lead_time_days'] = (int) ($row['lead_time_days'] === '' ? 0 : $row['lead_time_days']);
                $dup = $pdo->prepare("SELECT material_code FROM materials WHERE LOWER(material_name)=? AND LOWER(COALESCE(brand_spec,''))=? LIMIT 1");
                $dup->execute([strtolower($row['material_name']),strtolower($row['brand_spec'])]); $existing = $dup->fetchColumn();
                if ($existing !== false) throw new DomainException('Material already exists as ' . $existing . '.');
                break;
            case 'sites':
                if ($row['site_name'] === '') throw new DomainException('Site name is required.');
                $status = strtolower($row['status'] ?: 'active'); if (!in_array($status,['planning','active','suspended','completed','closed'],true)) throw new DomainException('Unsupported site status.');
                $resolved['status'] = $status; $stmt=$pdo->prepare('SELECT id FROM sites WHERE LOWER(name)=? LIMIT 1'); $stmt->execute([strtolower($row['site_name'])]); if($stmt->fetchColumn()!==false) throw new DomainException('A site with this name already exists.');
                break;
            case 'locations':
                if ($row['location_name'] === '') throw new DomainException('Location name is required.');
                $typeValue = strtolower($row['location_type']); if (!in_array($typeValue,['warehouse','site','quarantine'],true)) throw new DomainException('Location type must be warehouse, site, or quarantine.');
                $status = strtolower($row['status'] ?: 'active'); if (!in_array($status,['active','inactive'],true)) throw new DomainException('Location status must be active or inactive.');
                $resolved['location_type']=$typeValue; $resolved['is_active']=$status==='active'?1:0;
                if ($row['site_code'] !== '') { $site=bulk_lookup($pdo,'SELECT id FROM sites WHERE code=?',[$row['site_code']],"Site code {$row['site_code']} was not found."); $resolved['site_id']=(int)$site['id']; } else $resolved['site_id']=null;
                $stmt=$pdo->prepare('SELECT id FROM locations WHERE LOWER(name)=? LIMIT 1'); $stmt->execute([strtolower($row['location_name'])]); if($stmt->fetchColumn()!==false) throw new DomainException('A location with this name already exists.');
                break;
            case 'suppliers':
                if ($row['company'] === '') throw new DomainException('Company is required.');
                $status=strtolower($row['status']?:'active'); if(!in_array($status,['active','inactive'],true)) throw new DomainException('Supplier status must be active or inactive.'); $resolved['is_active']=$status==='active'?1:0;
                $stmt=$pdo->prepare('SELECT id FROM suppliers WHERE LOWER(company)=? LIMIT 1'); $stmt->execute([strtolower($row['company'])]); if($stmt->fetchColumn()!==false) throw new DomainException('This supplier company already exists.');
                break;
            case 'stock_counts':
                $location=bulk_active_location($pdo,$row['location_code']); $material=bulk_operational_material($pdo,$row['material_code']);
                $resolved=['location_id'=>(int)$location['id'],'material_id'=>(int)$material['id'],'counted_quantity'=>bulk_decimal($row['counted_quantity'],true)];
                break;
            case 'material_requests':
                if ($row['request_group']==='') throw new DomainException('Request group is required.');
                $site=bulk_active_site($pdo,$row['site_code']); $material=bulk_operational_material($pdo,$row['material_code']); $priority=strtolower($row['priority']?:'normal'); if(!in_array($priority,['low','normal','high','urgent'],true)) throw new DomainException('Unsupported priority.');
                if ((string) ($_SESSION['role'] ?? '') === 'site_engineer' && (int) $site['id'] !== (int) (current_user_site_id() ?? 0)) throw new DomainException('Material requests may only be imported for your assigned site.');
                $resolved=['site_id'=>(int)$site['id'],'material_id'=>(int)$material['id'],'date_needed'=>bulk_date($row['date_needed']),'priority'=>$priority,'quantity'=>bulk_decimal($row['quantity'])];
                break;
            case 'purchase_requests':
                if ($row['request_group']==='') throw new DomainException('Request group is required.');
                $material=bulk_operational_material($pdo,$row['material_code']); $priority=strtolower($row['priority']?:'normal'); if(!in_array($priority,['low','normal','high','urgent'],true)) throw new DomainException('Unsupported priority.');
                $resolved=['material_id'=>(int)$material['id'],'needed_by'=>bulk_date($row['needed_by'],true),'priority'=>$priority,'quantity'=>bulk_decimal($row['quantity'])];
                break;
            case 'transfers':
                if($row['transfer_group']==='') throw new DomainException('Transfer group is required.'); $source=bulk_active_location($pdo,$row['source_location_code']); $dest=bulk_active_location($pdo,$row['destination_location_code']); if((int)$source['id']===(int)$dest['id']) throw new DomainException('Source and destination locations must be different.'); $material=bulk_operational_material($pdo,$row['material_code']);
                $resolved=['source_location_id'=>(int)$source['id'],'destination_location_id'=>(int)$dest['id'],'material_id'=>(int)$material['id'],'quantity'=>bulk_decimal($row['quantity'])]; break;
            case 'returns':
                if($row['return_group']==='') throw new DomainException('Return group is required.'); $site=bulk_active_site($pdo,$row['source_site_code']); $location=bulk_active_location($pdo,$row['receiving_location_code']); $material=bulk_operational_material($pdo,$row['material_code']); $condition=strtolower($row['condition']?:'reusable'); return_item_effect($condition);
                $resolved=['source_site_id'=>(int)$site['id'],'receiving_location_id'=>(int)$location['id'],'material_id'=>(int)$material['id'],'quantity'=>bulk_decimal($row['quantity']),'condition'=>$condition]; break;
            case 'adjustments':
                if($row['adjustment_group']==='') throw new DomainException('Adjustment group is required.'); $location=bulk_active_location($pdo,$row['location_code']); $material=bulk_operational_material($pdo,$row['material_code']); $qty=bulk_decimal($row['adjustment_quantity'],false,true); $typeValue=strtolower($row['adjustment_type']); if(!in_array($typeValue,['positive','negative','physical_count','encoding_correction','damage','loss','found_stock','other'],true)) throw new DomainException('Unsupported adjustment type.'); if(trim($row['reason'])==='') throw new DomainException('Reason is required.');
                $balanceStmt=$pdo->prepare('SELECT quantity_on_hand FROM inventory_balances WHERE material_id=? AND location_id=? LIMIT 1'); $balanceStmt->execute([(int)$material['id'],(int)$location['id']]); $before=(float)($balanceStmt->fetchColumn() ?: 0); adjustment_after_quantity($before,$qty);
                $resolved=['location_id'=>(int)$location['id'],'material_id'=>(int)$material['id'],'quantity'=>$qty,'adjustment_type'=>$typeValue]; break;
            case 'damage_reports':
                if($row['damage_group']==='') throw new DomainException('Damage group is required.'); $location=bulk_active_location($pdo,$row['location_code']); $material=bulk_operational_material($pdo,$row['material_code']); if(trim($row['reason'])==='') throw new DomainException('Reason is required.');
                $resolved=['location_id'=>(int)$location['id'],'material_id'=>(int)$material['id'],'quantity'=>bulk_decimal($row['damaged_quantity'])]; break;
            case 'supplier_deliveries':
                if($row['delivery_group']==='') throw new DomainException('Delivery group is required.'); $location=bulk_active_location($pdo,$row['location_code']); $material=bulk_operational_material($pdo,$row['material_code']);
                if (current_user_site_id() !== null && (int) $location['site_id'] !== (int) current_user_site_id()) throw new DomainException('Supplier deliveries may only be imported for your assigned site.');
                $order=bulk_lookup($pdo,"SELECT id FROM purchase_orders WHERE po_no=? AND status IN ('open','partially_received')",[$row['po_no']],"Purchase order {$row['po_no']} was not found or cannot receive deliveries.");
                $item=bulk_lookup($pdo,'SELECT id, ordered_qty, received_qty FROM purchase_order_items WHERE purchase_order_id=? AND material_id=? AND status<>\'cancelled\' LIMIT 1',[(int)$order['id'],(int)$material['id']],"Material {$row['material_code']} is not an open item on purchase order {$row['po_no']}.");
                $qty=bulk_decimal($row['received_quantity']); $remaining=(float)$item['ordered_qty']-(float)$item['received_qty']; if($qty>$remaining+0.00001) throw new DomainException('Received quantity exceeds the remaining purchase-order quantity (' . money_or_qty($remaining) . ').');
                assert_unique_supplier_delivery_reference($pdo, (int)$order['id'], trim((string)$row['delivery_reference']));
                $resolved=['location_id'=>(int)$location['id'],'material_id'=>(int)$material['id'],'purchase_order_id'=>(int)$order['id'],'purchase_order_item_id'=>(int)$item['id'],'quantity'=>$qty]; break;
        }
    } catch (Throwable $e) { $add($e); }
    return ['resolved'=>$resolved,'errors'=>$errors];
}

function bulk_import_group_config(string $type): ?array
{
    return match ($type) {
        'material_requests' => ['key'=>'request_group','same'=>['site_code','date_needed','priority'],'item'=>'material_code'],
        'purchase_requests' => ['key'=>'request_group','same'=>['needed_by','priority'],'item'=>'material_code'],
        'transfers' => ['key'=>'transfer_group','same'=>['source_location_code','destination_location_code'],'item'=>'material_code'],
        'returns' => ['key'=>'return_group','same'=>['source_site_code','receiving_location_code'],'item'=>'material_code'],
        'adjustments' => ['key'=>'adjustment_group','same'=>['location_code','adjustment_type','reason'],'item'=>'material_code'],
        'damage_reports' => ['key'=>'damage_group','same'=>['location_code','reason'],'item'=>'material_code'],
        'supplier_deliveries' => ['key'=>'delivery_group','same'=>['po_no','location_code','delivery_reference'],'item'=>'material_code'],
        default => null,
    };
}

function stage_bulk_import(PDO $pdo, string $type, array $file, int $userId): array
{
    import_enable_mariadb_compat($pdo);
    $definition=bulk_import_definition($type); ensure_bulk_import_schema($pdo);
    $error=(int)($file['error']??UPLOAD_ERR_NO_FILE); if($error!==UPLOAD_ERR_OK) throw new DomainException('Choose a valid spreadsheet to upload.');
    $tmp=(string)($file['tmp_name']??''); $name=basename((string)($file['name']??'import.xlsx')); $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION)); if(!in_array($ext,['xlsx','csv'],true)) throw new DomainException('Only .xlsx and .csv files are supported.');
    if(!is_uploaded_file($tmp) && PHP_SAPI!=='cli') throw new DomainException('The uploaded file could not be verified.');
    $rows=bulk_import_parse_file($tmp,$ext,$definition); if(!$rows) throw new DomainException('The spreadsheet does not contain any data rows.');
    $fingerprintRows=array_map(static function(array $row):array{unset($row['_row_number']);return $row;},$rows); $fileHash=hash('sha256',(string)json_encode($fingerprintRows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); $prior=duplicate_import_file_batch($pdo,$type,$fileHash,$userId); if($prior) throw new DomainException('This spreadsheet content was already posted as import batch #'.(int)$prior['id'].'. Duplicate re-import blocked even if the file was renamed or re-saved.');
    $validated=[]; $seen=[]; $groupSeen=[]; $deliveryReferenceSeen=[]; $groupConfig=bulk_import_group_config($type);
    foreach($rows as $row){ $result=bulk_import_validate_row($pdo,$type,$row); $errors=$result['errors'];
        if($groupConfig){ $group=(string)$row[$groupConfig['key']]; if($group!==''){
            if(!isset($groupSeen[$group])) $groupSeen[$group]=['same'=>array_intersect_key($row,array_flip($groupConfig['same'])),'items'=>[]];
            else foreach($groupConfig['same'] as $field){ if((string)$groupSeen[$group]['same'][$field] !== (string)$row[$field]) $errors[]="Rows in {$group} must use the same {$field}."; }
            $itemKey=strtolower(trim((string)$row[$groupConfig['item']])); if(isset($groupSeen[$group]['items'][$itemKey])) $errors[]='The same material appears more than once in this group.'; else $groupSeen[$group]['items'][$itemKey]=true;
            if($type==='supplier_deliveries' && trim((string)$row['delivery_reference'])!==''){
                $deliveryKey=strtolower(trim((string)$row['po_no']).'|'.trim((string)$row['delivery_reference']));
                if(isset($deliveryReferenceSeen[$deliveryKey]) && $deliveryReferenceSeen[$deliveryKey]!==$group) $errors[]='The same delivery reference is assigned to more than one delivery group for this PO.';
                else $deliveryReferenceSeen[$deliveryKey]=$group;
            }
        }} else {
            $identity=match($type){'materials'=>strtolower($row['material_name'].'|'.$row['brand_spec']),'sites'=>strtolower($row['site_name']),'locations'=>strtolower($row['location_name']),'suppliers'=>strtolower($row['company']),'stock_counts'=>strtolower($row['location_code'].'|'.$row['material_code']),default=>''};
            if($identity!=='' && isset($seen[$identity])) $errors[]='Duplicate row identity appears in this spreadsheet.'; elseif($identity!=='') $seen[$identity]=true;
        }
        $validated[]=['row'=>$row,'resolved'=>$result['resolved'],'errors'=>array_values(array_unique($errors))];
    }
    $valid=count(array_filter($validated,static fn($r)=>$r['errors']===[])); $invalid=count($validated)-$valid; $status=$invalid===0?'validated':'draft'; $summary=['valid_rows'=>$valid,'invalid_rows'=>$invalid,'file_error'=>null];
    $pdo->beginTransaction();
    try{
        $pdo->prepare('INSERT INTO import_batches (batch_type,file_name,status,row_count,created_by,notes,file_sha256,validated_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$type,$name,$status,count($validated),$userId,json_encode($summary,JSON_UNESCAPED_UNICODE),$fileHash,date('Y-m-d H:i:s')]);
        $batchId=(int)$pdo->lastInsertId(); $insert=$pdo->prepare('INSERT INTO bulk_import_rows (`import_batch_id`,`row_number`,`data_json`,`resolved_json`,`validation_status`,`error_message`) VALUES (?,?,?,?,?,?)');
        foreach($validated as $entry){ $row=$entry['row']; $insert->execute([$batchId,(int)$row['_row_number'],json_encode($row,JSON_UNESCAPED_UNICODE),json_encode($entry['resolved'],JSON_UNESCAPED_UNICODE),$entry['errors']===[]?'valid':'invalid',$entry['errors']===[]?null:implode(' ',$entry['errors'])]); }
        $pdo->commit();
    }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    return ['batch_id'=>$batchId,'status'=>$status];
}

function get_owned_bulk_import(PDO $pdo, int $batchId, int $userId): array
{
    ensure_bulk_import_schema($pdo); $stmt=$pdo->prepare('SELECT * FROM import_batches WHERE id=? AND created_by=? LIMIT 1'); $stmt->execute([$batchId,$userId]); $batch=$stmt->fetch();
    if(!$batch || !isset(bulk_import_definitions()[(string)$batch['batch_type']])) throw new DomainException('This bulk import is unavailable.');
    $batch['summary']=json_decode((string)($batch['notes']??'{}'),true)?:[]; return $batch;
}

function bulk_import_rows(PDO $pdo, int $batchId): array
{
    $stmt=$pdo->prepare('SELECT * FROM bulk_import_rows WHERE `import_batch_id`=? ORDER BY `row_number`,`id`'); $stmt->execute([$batchId]); $rows=$stmt->fetchAll();
    foreach($rows as &$row){ $row['data']=json_decode((string)$row['data_json'],true)?:[]; $row['resolved']=json_decode((string)$row['resolved_json'],true)?:[]; } unset($row); return $rows;
}

function bulk_group_rows(array $rows, string $field): array
{
    $groups=[]; foreach($rows as $row){ $key=(string)($row['data'][$field]??''); $groups[$key][]=$row; } return $groups;
}

function bulk_mark_posted(PDO $pdo,int $rowId,string $entityType,int $entityId):void
{
    $pdo->prepare("UPDATE bulk_import_rows SET validation_status='posted',entity_type=?,entity_id=? WHERE id=?")->execute([$entityType,$entityId,$rowId]);
}

function confirm_bulk_import(PDO $pdo, int $batchId, int $userId): array
{
    import_enable_mariadb_compat($pdo);
    $batch=get_owned_bulk_import($pdo,$batchId,$userId); $type=(string)$batch['batch_type']; require_bulk_import_permission($type);
    if($batch['status']==='posted') return ['already_posted'=>true,'created'=>0]; if($batch['status']!=='validated') throw new DomainException('Fix every invalid spreadsheet row before confirming this import.');
    $rows=bulk_import_rows($pdo,$batchId); if(!$rows) throw new DomainException('This import has no rows to post.'); $created=0; $user=current_user(); $role=(string)($user['role']??'');
    $pdo->beginTransaction();
    try{
        switch($type){
            case 'materials': foreach($rows as $r){$d=$r['data'];$x=$r['resolved'];$createdRow=create_material($pdo,['material_name'=>$d['material_name'],'description'=>$d['description'],'category_id'=>$x['category_id'],'unit_id'=>$x['unit_id'],'brand_spec'=>$d['brand_spec'],'min_stock'=>$x['minimum_stock'],'max_stock'=>$x['maximum_stock'],'reorder_point'=>$x['reorder_point'],'safety_stock'=>$x['safety_stock'],'lead_time_days'=>$x['lead_time_days'],'preferred_supplier_id'=>$x['preferred_supplier_id']],$userId,$role,true); bulk_mark_posted($pdo,(int)$r['id'],'material',(int)$createdRow['id']);$created++;} break;
            case 'sites': foreach($rows as $r){$d=$r['data'];$x=$r['resolved'];$code=next_master_code($pdo,'site');$pdo->prepare('INSERT INTO sites (code,name,address,status) VALUES (?,?,?,?)')->execute([$code,$d['site_name'],$d['address'],$x['status']]);$id=(int)$pdo->lastInsertId();write_audit($pdo,$userId,'create','site',$id,'Site imported: '.$code);bulk_mark_posted($pdo,(int)$r['id'],'site',$id);$created++;} break;
            case 'locations': foreach($rows as $r){$d=$r['data'];$x=$r['resolved'];$code=next_master_code($pdo,'location');$pdo->prepare('INSERT INTO locations (site_id,code,name,type,is_active) VALUES (?,?,?,?,?)')->execute([$x['site_id'],$code,$d['location_name'],$x['location_type'],$x['is_active']]);$id=(int)$pdo->lastInsertId();write_audit($pdo,$userId,'create','location',$id,'Location imported: '.$code);bulk_mark_posted($pdo,(int)$r['id'],'location',$id);$created++;} break;
            case 'suppliers': foreach($rows as $r){$d=$r['data'];$x=$r['resolved'];$pdo->prepare('INSERT INTO suppliers (company,contact_person,phone,address,is_active) VALUES (?,?,?,?,?)')->execute([$d['company'],$d['contact_person'],$d['phone'],$d['address'],$x['is_active']]);$id=(int)$pdo->lastInsertId();write_audit($pdo,$userId,'create','supplier',$id,'Supplier imported: '.$d['company']);bulk_mark_posted($pdo,(int)$r['id'],'supplier',$id);$created++;} break;
            case 'stock_counts':
                foreach(bulk_group_rows($rows,'location_code') as $group){$first=$group[0];$locationId=(int)$first['resolved']['location_id'];$no=next_named_document_no($pdo,'stock_counts','count_no',setting_value($pdo,'stock_count_prefix','SC'));$pdo->prepare('INSERT INTO stock_counts (count_no,location_id,counted_by,status,remarks) VALUES (?,?,?,?,?)')->execute([$no,$locationId,$userId,'submitted','Bulk spreadsheet import']);$id=(int)$pdo->lastInsertId();$insert=$pdo->prepare('INSERT INTO stock_count_items (stock_count_id,material_id,system_qty,counted_qty,variance_qty,status,remarks) VALUES (?,?,?,?,?,?,?)');foreach($group as $r){$d=$r['data'];$x=$r['resolved'];$balance=ensure_inventory_balance($pdo,(int)$x['material_id'],$locationId);$system=(float)$balance['quantity_on_hand'];$counted=(float)$x['counted_quantity'];$insert->execute([$id,$x['material_id'],$system,$counted,stock_count_variance($system,$counted),'submitted',$d['remarks']]);bulk_mark_posted($pdo,(int)$r['id'],'stock_count',$id);}write_audit($pdo,$userId,'create','stock_count',$id,'Stock count imported and submitted.');notify_role($pdo,'warehouse_manager','Stock count ready for review',$no.' needs approval.','stock_count_view.php?id='.$id);$created++;} break;
            case 'material_requests':
                if ($role === 'site_engineer') { foreach ($rows as $r) { if ((int) $r['resolved']['site_id'] !== (int) (current_user_site_id() ?? 0)) throw new DomainException('Material requests may only be posted for your assigned site.'); } }
                foreach(bulk_group_rows($rows,'request_group') as $group){$first=$group[0];$d0=$first['data'];$x0=$first['resolved'];$no=next_request_no($pdo);$pdo->prepare("INSERT INTO material_requests (request_no,requested_by,site_id,request_date,date_needed,priority,remarks,status) VALUES (?,?,?,CURDATE(),?,?,?,'draft')")->execute([$no,$userId,$x0['site_id'],$x0['date_needed'],$x0['priority'],'Imported group '.$d0['request_group']]);$id=(int)$pdo->lastInsertId();$insert=$pdo->prepare("INSERT INTO material_request_items (request_id,material_id,requested_qty,item_status,remarks) VALUES (?,?,?,'draft',?)");foreach($group as $r){$d=$r['data'];$x=$r['resolved'];$insert->execute([$id,$x['material_id'],$x['quantity'],$d['remarks']]);bulk_mark_posted($pdo,(int)$r['id'],'material_request',$id);}write_audit($pdo,$userId,'create','material_request',$id,'Draft material request imported.');$created++;} break;
            case 'purchase_requests':
                foreach(bulk_group_rows($rows,'request_group') as $group){$first=$group[0];$d0=$first['data'];$x0=$first['resolved'];$no=next_named_document_no($pdo,'purchase_requests','pr_no',setting_value($pdo,'purchase_request_prefix','PR'));$pdo->prepare("INSERT INTO purchase_requests (pr_no,requested_by,needed_by,priority,source_type,status,remarks) VALUES (?,?,?,?,?,'draft',?)")->execute([$no,$userId,$x0['needed_by'],$x0['priority'],'manual','Imported group '.$d0['request_group']]);$id=(int)$pdo->lastInsertId();$insert=$pdo->prepare("INSERT INTO purchase_request_items (purchase_request_id,material_id,requested_qty,source_type,status,remarks) VALUES (?,?,?,'manual','draft',?)");foreach($group as $r){$d=$r['data'];$x=$r['resolved'];$insert->execute([$id,$x['material_id'],$x['quantity'],$d['remarks']]);bulk_mark_posted($pdo,(int)$r['id'],'purchase_request',$id);}write_audit($pdo,$userId,'create','purchase_request',$id,'Draft purchase request imported.');$created++;} break;
            case 'transfers':
                foreach(bulk_group_rows($rows,'transfer_group') as $group){$first=$group[0];$x0=$first['resolved'];$no=next_named_document_no($pdo,'material_transfers','transfer_no',setting_value($pdo,'transfer_prefix','TR'));$pdo->prepare("INSERT INTO material_transfers (transfer_no,source_location_id,destination_location_id,requested_by,status,remarks) VALUES (?,?,?,?,'submitted',?)")->execute([$no,$x0['source_location_id'],$x0['destination_location_id'],$userId,'Bulk spreadsheet import']);$id=(int)$pdo->lastInsertId();$insert=$pdo->prepare("INSERT INTO material_transfer_items (transfer_id,material_id,requested_qty,status,remarks) VALUES (?,?,?,'submitted',?)");foreach($group as $r){$d=$r['data'];$x=$r['resolved'];$insert->execute([$id,$x['material_id'],$x['quantity'],$d['remarks']]);bulk_mark_posted($pdo,(int)$r['id'],'material_transfer',$id);}write_audit($pdo,$userId,'create','material_transfer',$id,'Transfer imported and submitted.');notify_role($pdo,'warehouse_manager','Transfer ready for review',$no.' is ready for approval.','transfer_view.php?id='.$id);$created++;} break;
            case 'returns':
                foreach(bulk_group_rows($rows,'return_group') as $group){$first=$group[0];$x0=$first['resolved'];$no=next_named_document_no($pdo,'material_returns','return_no',setting_value($pdo,'return_prefix','RET'));$pdo->prepare("INSERT INTO material_returns (return_no,source_site_id,receiving_location_id,returned_by,status,remarks) VALUES (?,?,?,?,'submitted',?)")->execute([$no,$x0['source_site_id'],$x0['receiving_location_id'],$userId,'Bulk spreadsheet import']);$id=(int)$pdo->lastInsertId();$insert=$pdo->prepare("INSERT INTO material_return_items (return_id,material_id,returned_qty,condition_status,status,remarks) VALUES (?,?,?,?, 'submitted',?)");foreach($group as $r){$d=$r['data'];$x=$r['resolved'];$insert->execute([$id,$x['material_id'],$x['quantity'],$x['condition'],$d['remarks']]);bulk_mark_posted($pdo,(int)$r['id'],'material_return',$id);}write_audit($pdo,$userId,'create','material_return',$id,'Return imported and submitted.');notify_role($pdo,'warehouse_personnel','Material return ready for posting',$no.' is ready for warehouse receiving.','return_view.php?id='.$id);$created++;} break;
            case 'adjustments':
                foreach(bulk_group_rows($rows,'adjustment_group') as $group){$first=$group[0];$d0=$first['data'];$x0=$first['resolved'];$no=next_named_document_no($pdo,'inventory_adjustments','adjustment_no',setting_value($pdo,'adjustment_prefix','ADJ'));$pdo->prepare("INSERT INTO inventory_adjustments (adjustment_no,location_id,adjustment_type,requested_by,status,reason) VALUES (?,?,?,?,'submitted',?)")->execute([$no,$x0['location_id'],$x0['adjustment_type'],$userId,$d0['reason']]);$id=(int)$pdo->lastInsertId();$insert=$pdo->prepare("INSERT INTO inventory_adjustment_items (adjustment_id,material_id,before_qty,adjustment_qty,after_qty,status,remarks) VALUES (?,?,?,?,?,'submitted',?)");foreach($group as $r){$d=$r['data'];$x=$r['resolved'];$balance=ensure_inventory_balance($pdo,(int)$x['material_id'],(int)$x0['location_id']);$before=(float)$balance['quantity_on_hand'];$after=adjustment_after_quantity($before,(float)$x['quantity']);$insert->execute([$id,$x['material_id'],$before,$x['quantity'],$after,$d['remarks']]);bulk_mark_posted($pdo,(int)$r['id'],'inventory_adjustment',$id);}write_audit($pdo,$userId,'create','inventory_adjustment',$id,'Adjustment imported and submitted.');notify_role($pdo,'warehouse_manager','Adjustment ready for review',$no.' needs approval.','adjustment_view.php?id='.$id);$created++;} break;
            case 'damage_reports':
                foreach(bulk_group_rows($rows,'damage_group') as $group){$first=$group[0];$d0=$first['data'];$x0=$first['resolved'];$no=next_named_document_no($pdo,'damage_reports','damage_no',setting_value($pdo,'damage_prefix','DMG'));$pdo->prepare("INSERT INTO damage_reports (damage_no,location_id,reported_by,status,reason) VALUES (?,?,?,'submitted',?)")->execute([$no,$x0['location_id'],$userId,$d0['reason']]);$id=(int)$pdo->lastInsertId();$insert=$pdo->prepare("INSERT INTO damage_report_items (damage_report_id,material_id,damaged_qty,decision_qty,status,remarks) VALUES (?,?,?,?,'submitted',?)");foreach($group as $r){$d=$r['data'];$x=$r['resolved'];$insert->execute([$id,$x['material_id'],$x['quantity'],$x['quantity'],$d['remarks']]);bulk_mark_posted($pdo,(int)$r['id'],'damage_report',$id);}write_audit($pdo,$userId,'create','damage_report',$id,'Damage report imported and submitted.');notify_role($pdo,'warehouse_manager','Damage report ready for review',$no.' needs approval.','damage_report_view.php?id='.$id);$created++;} break;
            case 'supplier_deliveries':
                if (current_user_site_id() !== null) { foreach ($rows as $r) { $locationStmt=$pdo->prepare('SELECT site_id FROM locations WHERE id=?'); $locationStmt->execute([(int)$r['resolved']['location_id']]); if ((int)$locationStmt->fetchColumn() !== (int)current_user_site_id()) throw new DomainException('Supplier deliveries may only be posted for your assigned site.'); } }
                foreach(bulk_group_rows($rows,'delivery_group') as $group){$first=$group[0];$d0=$first['data'];$x0=$first['resolved'];$received=[];$remarks=[];foreach($group as $r){$received[(int)$r['resolved']['purchase_order_item_id']]=(float)$r['resolved']['quantity'];if(trim((string)$r['data']['remarks'])!=='')$remarks[]=(string)$r['data']['remarks'];} $id=post_supplier_delivery($pdo,(int)$x0['purchase_order_id'],(int)$x0['location_id'],$received,$userId,implode('; ',array_unique($remarks)),trim((string)$d0['delivery_reference'])); foreach($group as $r)bulk_mark_posted($pdo,(int)$r['id'],'supplier_delivery',$id);$created++;} break;
        }
        $pdo->prepare("UPDATE import_batches SET status='posted',posted_at=NOW(),posted_by=? WHERE id=? AND status='validated'")->execute([$userId,$batchId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['already_posted'=>false,'created'=>$created];
}

function cancel_bulk_import(PDO $pdo,int $batchId,int $userId):void
{
    $batch=get_owned_bulk_import($pdo,$batchId,$userId); if($batch['status']==='posted') throw new DomainException('Posted imports cannot be cancelled.'); $pdo->prepare("UPDATE import_batches SET status='failed',notes=? WHERE id=?")->execute([json_encode(['cancelled'=>true]),$batchId]);
}
