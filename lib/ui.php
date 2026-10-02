<?php
declare(strict_types=1);

function ui_status_meta(string $status): array
{
    $normalized = strtolower(trim(str_replace(' ', '_', $status)));
    if ($normalized === '') {
        $normalized = 'unknown';
    }

    $labels = [
        'critical' => 'Critical',
        'ready_for_release' => 'Ready for release',
        'reserved' => 'Ready for Release',
        'under_review' => 'Under review',
        'for_procurement' => 'For procurement',
        'partially_approved' => 'Partially approved',
        'partially_reserved' => 'Partially Reserved — Procurement Needed',
        'partially_released' => 'Partially released',
        'partially_received' => 'Partially received',
    ];

    $safeClass = (string) preg_replace('/[^a-z0-9_-]/', '', $normalized);
    $label = $labels[$normalized] ?? ucfirst(str_replace('_', ' ', $normalized));

    return ['class' => $safeClass, 'label' => $label];
}

function ui_status_badge(string $status, ?string $label = null): string
{
    $meta = ui_status_meta($status);

    return '<span class="status-badge workflow-badge ' . e($meta['class']) . '">' .
        e($label ?? $meta['label']) .
        '</span>';
}

function ui_empty_state(string $title, string $body, ?array $action = null): string
{
    $html = '<div class="empty-state" role="status">' .
        '<div class="empty-state-mark" aria-hidden="true"></div>' .
        '<h3>' . e($title) . '</h3>' .
        '<p>' . e($body) . '</p>';

    if ($action && !empty($action['label']) && !empty($action['url'])) {
        $allowedClasses = ['primary', 'secondary', 'danger'];
        $class = in_array((string) ($action['class'] ?? 'primary'), $allowedClasses, true)
            ? (string) ($action['class'] ?? 'primary')
            : 'primary';
        $html .= '<a class="btn ' . e($class) . '" href="' . e((string) $action['url']) . '">' .
            e((string) $action['label']) .
            '</a>';
    }

    return $html . '</div>';
}

function ui_page_intro(
    string $eyebrow,
    string $title,
    string $description,
    ?array $action = null
): string {
    $html = '<section class="ops-page-intro">' .
        '<div class="ops-page-intro-copy">' .
        '<span class="eyebrow">' . e($eyebrow) . '</span>' .
        '<h1>' . e($title) . '</h1>' .
        '<p>' . e($description) . '</p>' .
        '</div>';

    if ($action && !empty($action['label']) && !empty($action['url'])) {
        $allowedClasses = ['primary', 'secondary', 'danger'];
        $class = in_array((string) ($action['class'] ?? 'primary'), $allowedClasses, true)
            ? (string) ($action['class'] ?? 'primary')
            : 'primary';
        $html .= '<a class="btn ' . e($class) . ' ops-page-primary-action" href="' .
            e((string) $action['url']) . '">' . e((string) $action['label']) . '</a>';
    }

    return $html . '</section>';
}
