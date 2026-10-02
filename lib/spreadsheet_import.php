<?php
declare(strict_types=1);

const STOCK_IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const STOCK_IMPORT_MAX_ROWS = 1000;

function stock_import_headers(): array
{
    return ['material_code', 'location_code', 'quantity', 'reference_no', 'remarks'];
}

function normalize_stock_import_header(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/', '', trim($header)) ?? '';
    return strtolower((string) preg_replace('/\s+/', '_', $header));
}

function normalize_stock_import_quantity(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
        throw new DomainException('Quantity must be a positive number with at most two decimal places.');
    }

    [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $integer = ltrim($integer, '0');
    $integer = $integer === '' ? '0' : $integer;
    $fraction = str_pad($fraction, 2, '0');

    if (strlen($integer) > 12) {
        throw new DomainException('Quantity is too large.');
    }
    if ($integer === '0' && $fraction === '00') {
        throw new DomainException('Quantity must be greater than zero.');
    }

    return $integer . '.' . $fraction;
}

function stock_import_header_map(array $headerRow): array
{
    $allowed = stock_import_headers();
    $required = ['material_code', 'location_code', 'quantity'];
    $map = [];

    foreach ($headerRow as $index => $rawHeader) {
        $header = normalize_stock_import_header((string) $rawHeader);
        if ($header === '') {
            throw new DomainException('Spreadsheet headers cannot be blank.');
        }
        if (!in_array($header, $allowed, true)) {
            throw new DomainException("Unsupported spreadsheet header: {$header}.");
        }
        if (array_key_exists($header, $map)) {
            throw new DomainException("Duplicate spreadsheet header: {$header}.");
        }
        $map[$header] = (int) $index;
    }

    foreach ($required as $header) {
        if (!array_key_exists($header, $map)) {
            throw new DomainException("Missing required spreadsheet header: {$header}.");
        }
    }

    return $map;
}

function stock_import_row_is_blank(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string) $value) !== '') {
            return false;
        }
    }
    return true;
}

function normalize_stock_import_row(array $row, array $headerMap, int $rowNumber): array
{
    foreach ($row as $index => $value) {
        $trimmed = trim((string) $value);
        $isNegativeQuantity = ($headerMap['quantity'] ?? null) === $index && str_starts_with($trimmed, '-');
        if ($trimmed !== '' && !$isNegativeQuantity && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            throw new DomainException("Row {$rowNumber} contains a formula-like value.");
        }
    }

    $normalized = ['row_number' => $rowNumber];
    foreach (stock_import_headers() as $header) {
        $index = $headerMap[$header] ?? null;
        $normalized[$header] = $index === null ? '' : trim((string) ($row[$index] ?? ''));
    }
    try {
        $normalized['quantity'] = normalize_stock_import_quantity($normalized['quantity']);
        $normalized['quantity_error'] = null;
    } catch (DomainException $exception) {
        $normalized['quantity_error'] = $exception->getMessage();
    }

    return $normalized;
}

function parse_stock_import_csv(string $path): array
{
    if (!is_file($path)) {
        throw new DomainException('The uploaded CSV file could not be found.');
    }
    $size = filesize($path);
    if ($size === false || $size > STOCK_IMPORT_MAX_BYTES) {
        throw new DomainException('The uploaded file must not exceed 5 MB.');
    }

    $contents = file_get_contents($path);
    if ($contents === false || str_contains($contents, "\0")) {
        throw new DomainException('The CSV file is not valid text.');
    }
    if (preg_match('//u', $contents) !== 1) {
        throw new DomainException('The CSV file must use UTF-8 encoding.');
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new DomainException('The CSV file could not be opened.');
    }

    try {
        $headerRow = fgetcsv($handle);
        if ($headerRow === false || stock_import_row_is_blank($headerRow)) {
            throw new DomainException('The CSV file must contain a header row.');
        }
        $headerMap = stock_import_header_map($headerRow);
        $columnCount = count($headerRow);
        $rows = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if (stock_import_row_is_blank($row)) {
                continue;
            }
            if (count($row) > $columnCount) {
                throw new DomainException("Row {$rowNumber} has more columns than the header row.");
            }
            if (count($rows) >= STOCK_IMPORT_MAX_ROWS) {
                throw new DomainException('The spreadsheet must not contain more than 1,000 data rows.');
            }
            $rows[] = normalize_stock_import_row($row, $headerMap, $rowNumber);
        }
    } finally {
        fclose($handle);
    }

    return ['headers' => stock_import_headers(), 'rows' => $rows];
}

function stock_import_xml(string $xml, string $label): SimpleXMLElement
{
    $previous = libxml_use_internal_errors(true);
    try {
        $parsed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if (!$parsed instanceof SimpleXMLElement) {
            throw new DomainException("The XLSX {$label} XML is malformed.");
        }
        return $parsed;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function stock_import_zip_entry(ZipArchive $zip, string $name): string
{
    $contents = $zip->getFromName($name);
    if (!is_string($contents)) {
        throw new DomainException("The XLSX file is missing {$name}.");
    }
    return $contents;
}

function stock_import_validate_zip(ZipArchive $zip): void
{
    $totalSize = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        if (!is_array($stat)) {
            throw new DomainException('The XLSX archive contains an unreadable entry.');
        }
        $name = (string) ($stat['name'] ?? '');
        $size = (int) ($stat['size'] ?? 0);
        $normalized = str_replace('\\', '/', $name);
        $segments = explode('/', $normalized);
        if ($name === '' || $normalized !== $name || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) || in_array('..', $segments, true)) {
            throw new DomainException('The XLSX archive contains an unsafe path.');
        }
        $lower = strtolower($name);
        foreach (['vbaproject.bin', 'externallinks/', 'embeddings/', 'oleobject'] as $blocked) {
            if (str_contains($lower, $blocked)) {
                throw new DomainException('Macro-enabled or embedded XLSX content is not supported.');
            }
        }
        if ($size > 10 * 1024 * 1024) {
            throw new DomainException('The XLSX archive contains an oversized entry.');
        }
        $totalSize += $size;
        if ($totalSize > 25 * 1024 * 1024) {
            throw new DomainException('The XLSX archive expands beyond the allowed size.');
        }
    }
}

function stock_import_relationship_target(string $target): string
{
    // Accept both common OOXML forms: worksheets/sheet1.xml and
    // /xl/worksheets/sheet1.xml. Absolute package paths are valid OOXML and
    // are not filesystem paths.
    $target = str_replace('\\', '/', trim($target));
    if ($target === '' || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $target)) {
        throw new DomainException('The XLSX workbook contains an unsafe relationship target.');
    }
    $absolutePackagePath = str_starts_with($target, '/');
    $target = ltrim($target, '/');
    $segments = explode('/', $target);
    if (in_array('..', $segments, true)) {
        throw new DomainException('The XLSX workbook contains a path-traversal relationship.');
    }
    $segments = array_values(array_filter($segments, static fn(string $part): bool => $part !== '' && $part !== '.'));
    if ($segments === []) {
        throw new DomainException('The XLSX worksheet target is empty.');
    }
    $normalized = implode('/', $segments);
    if ($absolutePackagePath || str_starts_with(strtolower($normalized), 'xl/')) {
        if (!str_starts_with(strtolower($normalized), 'xl/')) {
            throw new DomainException('The XLSX worksheet target is outside the workbook package.');
        }
        return $normalized;
    }
    return 'xl/' . $normalized;
}

function stock_import_shared_strings(ZipArchive $zip): array
{
    if ($zip->locateName('xl/sharedStrings.xml') === false) {
        return [];
    }
    $xml = stock_import_xml(stock_import_zip_entry($zip, 'xl/sharedStrings.xml'), 'shared strings');
    $namespaces = $xml->getNamespaces(true);
    $mainNamespace = $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $xml->registerXPathNamespace('s', $mainNamespace);
    $strings = [];
    foreach ($xml->xpath('//s:si') ?: [] as $item) {
        $item->registerXPathNamespace('s', $mainNamespace);
        $parts = [];
        foreach ($item->xpath('.//s:t') ?: [] as $text) {
            $parts[] = (string) $text;
        }
        $strings[] = implode('', $parts);
    }
    return $strings;
}

function stock_import_cell_column(string $reference): int
{
    if (!preg_match('/^([A-Z]+)\d+$/i', $reference, $matches)) {
        throw new DomainException('The XLSX worksheet contains an invalid cell reference.');
    }
    $column = 0;
    foreach (str_split(strtoupper($matches[1])) as $letter) {
        $column = ($column * 26) + (ord($letter) - 64);
        if ($column > 5) {
            throw new DomainException('The XLSX worksheet contains columns outside the five-column import contract.');
        }
    }
    return $column - 1;
}

function stock_import_inline_text(SimpleXMLElement $node, string $namespace): string
{
    $node->registerXPathNamespace('s', $namespace);
    $parts = [];
    foreach ($node->xpath('.//s:t') ?: [] as $text) {
        $parts[] = (string) $text;
    }
    return implode('', $parts);
}

function stock_import_worksheet_rows(string $sheetXml, array $sharedStrings): array
{
    $reader = new XMLReader();
    if (!$reader->XML($sheetXml, null, LIBXML_NONET | LIBXML_COMPACT)) {
        throw new DomainException('The XLSX worksheet could not be opened.');
    }

    $rows = [];
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }
            $rowXml = $reader->readOuterXML();
            $row = stock_import_xml($rowXml, 'worksheet row');
            $namespaces = $row->getNamespaces(true);
            $mainNamespace = $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
            $rowAttributes = $row->attributes();
            $rowNumber = (int) ($rowAttributes['r'] ?? 0);
            if ($rowNumber < 1) {
                throw new DomainException('The XLSX worksheet contains an invalid row number.');
            }
            $cells = [];
            foreach ($row->children($mainNamespace)->c as $cell) {
                $cellAttributes = $cell->attributes();
                $reference = (string) ($cellAttributes['r'] ?? '');
                $column = stock_import_cell_column($reference);
                $type = (string) ($cellAttributes['t'] ?? '');
                $children = $cell->children($mainNamespace);
                if (isset($children->f)) {
                    throw new DomainException("Row {$rowNumber} contains a formula cell.");
                }
                if ($type === 'inlineStr') {
                    $value = stock_import_inline_text($children->is, $mainNamespace);
                } elseif ($type === 's') {
                    $sharedIndex = (int) ((string) $children->v);
                    if (!array_key_exists($sharedIndex, $sharedStrings)) {
                        throw new DomainException('The XLSX worksheet references a missing shared string.');
                    }
                    $value = $sharedStrings[$sharedIndex];
                } elseif ($type === 'str' || $type === 'n' || $type === '') {
                    $value = (string) $children->v;
                } else {
                    throw new DomainException("The XLSX worksheet contains an unsupported cell type: {$type}.");
                }
                $cells[$column] = $value;
            }
            $rows[$rowNumber] = $cells;
        }
    } finally {
        $reader->close();
    }
    return $rows;
}

function parse_stock_import_xlsx(string $path): array
{
    if (!class_exists('ZipArchive') || !class_exists('XMLReader') || !function_exists('simplexml_load_string')) {
        throw new DomainException('XLSX import needs the PHP ZIP, XMLReader, and SimpleXML extensions. Enable them in PHP, or use CSV import.');
    }
    if (!is_file($path)) {
        throw new DomainException('The uploaded XLSX file could not be found.');
    }
    $size = filesize($path);
    if ($size === false || $size > STOCK_IMPORT_MAX_BYTES) {
        throw new DomainException('The uploaded file must not exceed 5 MB.');
    }
    $signature = file_get_contents($path, false, null, 0, 4);
    if ($signature === false || !str_starts_with($signature, "PK\x03\x04")) {
        throw new DomainException('The XLSX file signature is invalid.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new DomainException('The XLSX archive could not be opened.');
    }

    try {
        stock_import_validate_zip($zip);
        $workbook = stock_import_xml(stock_import_zip_entry($zip, 'xl/workbook.xml'), 'workbook');
        $workbookNamespaces = $workbook->getNamespaces(true);
        $mainNamespace = $workbookNamespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relationshipNamespace = $workbookNamespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $sheets = $workbook->children($mainNamespace)->sheets->children($mainNamespace)->sheet;
        if (count($sheets) !== 1) {
            throw new DomainException('The XLSX file must contain exactly one worksheet.');
        }
        $sheet = $sheets[0];
        $sheetAttributes = $sheet->attributes();
        $state = strtolower((string) ($sheetAttributes['state'] ?? 'visible'));
        if ($state !== '' && $state !== 'visible') {
            throw new DomainException('The import worksheet must be visible.');
        }
        $relationshipId = (string) $sheet->attributes($relationshipNamespace)['id'];
        if ($relationshipId === '') {
            throw new DomainException('The XLSX worksheet relationship is missing.');
        }

        $relationships = stock_import_xml(stock_import_zip_entry($zip, 'xl/_rels/workbook.xml.rels'), 'relationships');
        $relationshipNamespaces = $relationships->getNamespaces(true);
        $packageNamespace = $relationshipNamespaces[''] ?? 'http://schemas.openxmlformats.org/package/2006/relationships';
        $target = '';
        foreach ($relationships->children($packageNamespace)->Relationship as $relationship) {
            $attributes = $relationship->attributes();
            if ((string) $attributes['Id'] === $relationshipId) {
                $target = (string) $attributes['Target'];
                break;
            }
        }
        if ($target === '') {
            throw new DomainException('The XLSX worksheet relationship cannot be resolved.');
        }
        $sheetPath = stock_import_relationship_target($target);
        $sharedStrings = stock_import_shared_strings($zip);
        $matrix = stock_import_worksheet_rows(stock_import_zip_entry($zip, $sheetPath), $sharedStrings);
    } finally {
        $zip->close();
    }

    if (!isset($matrix[1]) || stock_import_row_is_blank($matrix[1])) {
        throw new DomainException('The XLSX file must contain headers in row 1.');
    }
    ksort($matrix[1]);
    $lastHeaderColumn = max(array_keys($matrix[1]));
    $headerRow = [];
    for ($column = 0; $column <= $lastHeaderColumn; $column++) {
        $headerRow[] = (string) ($matrix[1][$column] ?? '');
    }
    $headerMap = stock_import_header_map($headerRow);
    $rows = [];
    foreach ($matrix as $rowNumber => $cells) {
        if ($rowNumber === 1 || stock_import_row_is_blank($cells)) {
            continue;
        }
        if ($cells !== [] && max(array_keys($cells)) > $lastHeaderColumn) {
            throw new DomainException("Row {$rowNumber} has more columns than the header row.");
        }
        if (count($rows) >= STOCK_IMPORT_MAX_ROWS) {
            throw new DomainException('The spreadsheet must not contain more than 1,000 data rows.');
        }
        $row = [];
        for ($column = 0; $column <= $lastHeaderColumn; $column++) {
            $row[] = (string) ($cells[$column] ?? '');
        }
        $rows[] = normalize_stock_import_row($row, $headerMap, (int) $rowNumber);
    }

    return ['headers' => stock_import_headers(), 'rows' => $rows];
}

function write_stock_import_template(string $destination): void
{
    if (!class_exists('ZipArchive')) {
        throw new DomainException('Downloading the XLSX template needs the PHP ZIP extension to be enabled.');
    }
    $zip = new ZipArchive();
    if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new DomainException('The XLSX template could not be created.');
    }

    $headers = '';
    foreach (stock_import_headers() as $index => $header) {
        $column = chr(65 + $index);
        $escaped = htmlspecialchars($header, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $headers .= '<c r="' . $column . '1" s="3" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
    }
    $created = gmdate('Y-m-d\TH:i:s\Z');
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
        'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Centralized Inventory Forecasting System</Application></Properties>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>Centralized Inventory Forecasting System</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created></cp:coreProperties>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Stock Import" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="0"/><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0D7271"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="2" width="20" style="1" customWidth="1"/><col min="3" max="3" width="14" style="2" customWidth="1"/><col min="4" max="4" width="22" style="1" customWidth="1"/><col min="5" max="5" width="36" style="1" customWidth="1"/></cols><sheetData><row r="1" ht="22" customHeight="1">' . $headers . '</row></sheetData><autoFilter ref="A1:E1"/></worksheet>',
    ];

    try {
        foreach ($files as $name => $contents) {
            if (!$zip->addFromString($name, $contents)) {
                throw new DomainException("The XLSX template entry {$name} could not be written.");
            }
        }
    } finally {
        $zip->close();
    }
}

function assert_stock_import_upload_type(string $path, string $extension, string $reportedMime): void
{
    $extension = strtolower($extension);
    $mime = strtolower(trim(explode(';', $reportedMime, 2)[0]));
    $allowed = match ($extension) {
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'],
        default => throw new DomainException('Only .xlsx and .csv files are supported.'),
    };
    if (!in_array($mime, $allowed, true)) {
        throw new DomainException('The uploaded file type does not match an accepted spreadsheet format.');
    }
    if ($extension === 'xlsx') {
        $signature = file_get_contents($path, false, null, 0, 4);
        if ($signature === false || !str_starts_with($signature, "PK\x03\x04")) {
            throw new DomainException('The XLSX file signature is invalid.');
        }
    }
}

function parse_stock_import_file(string $path, string $extension): array
{
    return match (strtolower($extension)) {
        'csv' => parse_stock_import_csv($path),
        'xlsx' => parse_stock_import_xlsx($path),
        default => throw new DomainException('Only .xlsx and .csv files are supported.'),
    };
}
