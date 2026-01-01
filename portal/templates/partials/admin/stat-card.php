<?php
declare(strict_types=1);

/**
 * Stat Card Partial
 *
 * Renders a statistics card for the admin dashboard.
 */

/**
 * Render a stat card
 *
 * @param string $label The stat label
 * @param int|string $value The stat value
 * @param string $icon HTML entity icon
 * @param string $link URL to link to
 * @param string $variant Color variant: primary, warning, info, default
 * @return string Rendered HTML
 */
function renderStatCard(string $label, int|string $value, string $icon, string $link, string $variant = 'default'): string
{
    $variantClass = match ($variant) {
        'primary' => 'stat-primary',
        'warning' => 'stat-warning',
        'info' => 'stat-info',
        default => 'stat-default',
    };

    $linkUrl = url($link);

    return <<<HTML
    <a href="{$linkUrl}" class="stat-card {$variantClass}">
        <div class="stat-icon">{$icon}</div>
        <div class="stat-content">
            <div class="stat-value">{$value}</div>
            <div class="stat-label">{$label}</div>
        </div>
    </a>
    HTML;
}
?>

<style>
.stat-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: var(--bg-secondary, #f5f5f5);
    border-radius: 8px;
    text-decoration: none;
    color: inherit;
    transition: background-color 0.2s, transform 0.1s;
}

.stat-card:hover {
    background: var(--bg-hover, #e8e8e8);
    transform: translateY(-2px);
}

.stat-icon {
    font-size: 2rem;
    opacity: 0.8;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1.2;
}

.stat-label {
    font-size: 0.875rem;
    color: var(--text-secondary);
}

.stat-primary .stat-icon,
.stat-primary .stat-value {
    color: var(--primary, #D32F2F);
}

.stat-warning .stat-icon,
.stat-warning .stat-value {
    color: var(--warning, #ff9800);
}

.stat-info .stat-icon,
.stat-info .stat-value {
    color: var(--info, #1976D2);
}
</style>
