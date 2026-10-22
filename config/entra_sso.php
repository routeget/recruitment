<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Microsoft Entra ID Enterprise Single Sign-On (OIDC + PKCE S256)
    |--------------------------------------------------------------------------
    |
    | Default audience mode is 'organizations' (work or school accounts across
    | Microsoft Entra directories only). Consumer personal Microsoft accounts
    | (tid = 9188040d-6c67-4c5b-b112-36a304b66dad) are rejected by default.
    |
    */

    'enabled' => (bool) env('MICROSOFT_ENTRA_SSO_ENABLED', false),

    'client_id' => (string) env('MICROSOFT_ENTRA_CLIENT_ID', ''),

    'client_secret' => (string) env('MICROSOFT_ENTRA_CLIENT_SECRET', ''),

    'tenant_mode' => (string) env('MICROSOFT_ENTRA_TENANT_MODE', 'organizations'),

    'allow_personal_msa' => (bool) env('MICROSOFT_ENTRA_ALLOW_PERSONAL_MSA', false),

    'authority_host' => rtrim((string) env('MICROSOFT_ENTRA_AUTHORITY_HOST', 'https://login.microsoftonline.com'), '/'),

    'redirect_uri' => (string) env('MICROSOFT_ENTRA_REDIRECT_URI', '/auth/entra/callback'),

    'post_logout_redirect_uri' => (string) env('MICROSOFT_ENTRA_POST_LOGOUT_REDIRECT_URI', '/login'),

    'scopes' => ['openid', 'profile', 'email'],

    'clock_skew_seconds' => (int) env('MICROSOFT_ENTRA_CLOCK_SKEW_SECONDS', 60),

    'state_ttl_seconds' => (int) env('MICROSOFT_ENTRA_STATE_TTL_SECONDS', 600),

    'jwks_cache_ttl_seconds' => (int) env('MICROSOFT_ENTRA_JWKS_CACHE_TTL_SECONDS', 3600),

    'consumer_tenant_id' => '9188040d-6c67-4c5b-b112-36a304b66dad',
];
