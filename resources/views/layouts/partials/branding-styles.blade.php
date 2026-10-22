@php
    $rawTokens = is_array($platformBranding['theme_tokens'] ?? null) ? $platformBranding['theme_tokens'] : [];
    $primaryActions = \App\Services\PlatformConfigService::sanitizeHexColor($rawTokens['primary_actions'] ?? null, '#0078D4');
    $headerDark = \App\Services\PlatformConfigService::sanitizeHexColor($rawTokens['header_dark'] ?? null, '#002050');
    $headerEmphasis = \App\Services\PlatformConfigService::sanitizeHexColor($rawTokens['header_emphasis'] ?? null, '#004578');
    $surfaceMain = \App\Services\PlatformConfigService::sanitizeHexColor($rawTokens['surface_main'] ?? null, '#FFFFFF');
    $bgCanvas = \App\Services\PlatformConfigService::sanitizeHexColor($rawTokens['bg_canvas'] ?? null, '#F5F5F5');
    $surfaceSecondary = \App\Services\PlatformConfigService::sanitizeHexColor($rawTokens['surface_secondary'] ?? null, '#F1F5F9');
    $textMain = \App\Services\PlatformConfigService::sanitizeHexColor($rawTokens['text_main'] ?? null, '#323130');
    $textSecondary = \App\Services\PlatformConfigService::sanitizeHexColor($rawTokens['text_secondary'] ?? null, '#605E5C');
    $borderInput = \App\Services\PlatformConfigService::sanitizeHexColor($rawTokens['border_input'] ?? null, '#D2D0CE');
    $borderDivider = \App\Services\PlatformConfigService::sanitizeHexColor($rawTokens['border_divider'] ?? null, '#EDEBE9');
    $hoverBg = \App\Services\PlatformConfigService::sanitizeHexColor($rawTokens['hover_bg'] ?? null, '#F3F9FD');
    $selectedRowBg = \App\Services\PlatformConfigService::sanitizeHexColor($rawTokens['selected_row_bg'] ?? null, '#EFF6FC');
    $selectedControl = \App\Services\PlatformConfigService::sanitizeHexColor($rawTokens['selected_control'] ?? null, '#DEECF9');
@endphp
<style id="urap-branding-tokens">
    :root {
        --color-primary-actions: {{ $primaryActions }};
        --color-hyperlinks: {{ $primaryActions }};
        --color-header-dark: {{ $headerDark }};
        --color-header-emphasis: {{ $headerEmphasis }};
        --color-surface-main: {{ $surfaceMain }};
        --color-bg-canvas: {{ $bgCanvas }};
        --color-surface-secondary: {{ $surfaceSecondary }};
        --color-text-main: {{ $textMain }};
        --color-text-secondary: {{ $textSecondary }};
        --color-border-input: {{ $borderInput }};
        --color-border-divider: {{ $borderDivider }};
        --color-hover-bg: {{ $hoverBg }};
        --color-selected-row-bg: {{ $selectedRowBg }};
        --color-selected-control: {{ $selectedControl }};
    }
</style>
