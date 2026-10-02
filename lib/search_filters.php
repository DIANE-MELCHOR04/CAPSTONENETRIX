<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';

function filter_text(array $source, string $key, int $maxLength = 120): string
{
    $value = trim((string) ($source[$key] ?? ''));
    $value = preg_replace('/[[:cntrl:]]+/', ' ', $value) ?? '';
    $value = preg_replace('/\s+/', ' ', $value) ?? '';

    if ($maxLength > 0 && strlen($value) > $maxLength) {
        $value = substr($value, 0, $maxLength);
    }

    return $value;
}

function filter_choice(array $source, string $key, array $allowed): string
{
    $value = trim((string) ($source[$key] ?? ''));
    return in_array($value, $allowed, true) ? $value : '';
}

function filter_int_choice(array $source, string $key, array $allowed = []): int
{
    $value = (int) ($source[$key] ?? 0);
    if ($value <= 0) {
        return 0;
    }

    if ($allowed && !in_array($value, array_map('intval', $allowed), true)) {
        return 0;
    }

    return $value;
}

function filter_date(array $source, string $key): string
{
    $value = trim((string) ($source[$key] ?? ''));
    if ($value === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function filter_like_pattern(string $value): string
{
    return '%' . strtr($value, [
        '\\' => '\\\\',
        '%' => '\\%',
        '_' => '\\_',
    ]) . '%';
}

function filter_where_like(array &$where, array &$params, array $columns, string $value): void
{
    if ($value === '' || !$columns) {
        return;
    }

    $parts = [];
    $pattern = filter_like_pattern($value);
    foreach ($columns as $column) {
        $parts[] = "{$column} LIKE ? ESCAPE '\\\\'";
        $params[] = $pattern;
    }

    $where[] = '(' . implode(' OR ', $parts) . ')';
}

function filter_where_equals(array &$where, array &$params, string $column, $value): void
{
    if ($value === '' || $value === 0 || $value === null) {
        return;
    }

    $where[] = "{$column} = ?";
    $params[] = $value;
}

function filter_where_date_from(array &$where, array &$params, string $column, string $date): void
{
    if ($date === '') {
        return;
    }

    $where[] = "DATE({$column}) >= ?";
    $params[] = $date;
}

function filter_where_date_to(array &$where, array &$params, string $column, string $date): void
{
    if ($date === '') {
        return;
    }

    $where[] = "DATE({$column}) <= ?";
    $params[] = $date;
}

function filter_where_between_dates(array &$where, array &$params, string $column, string $from, string $to): void
{
    filter_where_date_from($where, $params, $column, $from);
    filter_where_date_to($where, $params, $column, $to);
}

function filter_where_sql(array $where): string
{
    return $where ? 'WHERE ' . implode(' AND ', $where) : '';
}

function filter_export_url(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null || $value === 0 || $value === '0') {
            unset($query[$key]);
        }
    }

    return '?' . http_build_query($query);
}

function filter_badge(int $count): string
{
    return '<span class="status-chip filter-count">Results ' . number_format($count) . '</span>';
}

function bool_filter_value(array $source, string $key): string
{
    return filter_choice($source, $key, ['active', 'inactive']);
}

function filter_bool_condition(string $value): ?int
{
    if ($value === 'active') {
        return 1;
    }

    if ($value === 'inactive') {
        return 0;
    }

    return null;
}

function render_status_options(array $options, string $selected, string $allLabel = 'All statuses'): void
{
    echo '<option value="">' . e($allLabel) . '</option>';
    foreach ($options as $option) {
        $label = ucfirst(str_replace('_', ' ', (string) $option));
        echo '<option value="' . e($option) . '"' . ($selected === $option ? ' selected' : '') . '>' . e($label) . '</option>';
    }
}
