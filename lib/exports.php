<?php
declare(strict_types=1);

function csv_safe_value($value): string
{
    if ($value === null) {
        return '';
    }

    $text = (string) $value;
    if ($text !== '' && preg_match('/^[=+\-@]/', $text) === 1) {
        return "'" . $text;
    }

    return $text;
}

function send_csv(string $filename, array $headers, array $rows): void
{
    if (headers_sent()) {
        throw new RuntimeException('CSV headers were already sent.');
    }

    $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'export.csv';
    if (!str_ends_with(strtolower($safeFilename), '.csv')) {
        $safeFilename .= '.csv';
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $stream = fopen('php://output', 'wb');
    if ($stream === false) {
        throw new RuntimeException('Unable to open CSV output stream.');
    }

    fputcsv($stream, array_map('csv_safe_value', $headers));
    foreach ($rows as $row) {
        fputcsv($stream, array_map('csv_safe_value', is_array($row) ? array_values($row) : [$row]));
    }

    fclose($stream);
    exit;
}
