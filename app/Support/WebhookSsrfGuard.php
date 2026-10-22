<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use InvalidArgumentException;

class WebhookSsrfGuard
{
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'metadata.google.internal',
        'metadata',
        'instance-data',
    ];

    /**
     * Optional custom DNS resolver for deterministic testing of DNS rebinding.
     *
     * @var (Closure(string): array<int, string>)|null
     */
    private ?Closure $dnsResolver = null;

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver
     */
    public function setDnsResolver(?Closure $resolver): void
    {
        $this->dnsResolver = $resolver;
    }

    /**
     * Validates that a webhook target URL is HTTPS and does not target loopback, RFC1918 private,
     * link-local/metadata (169.254.0.0/16), IPv6 local, or reserved network ranges.
     */
    public function assertSafeTargetUrl(string $url): void
    {
        $trimmed = trim($url);
        if ($trimmed === '' || filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('WEBHOOK_SSRF_BLOCKED: Webhook target_url must be a valid URL.');
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts)) {
            throw new InvalidArgumentException('WEBHOOK_SSRF_BLOCKED: Unable to parse webhook target_url.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new InvalidArgumentException('WEBHOOK_SSRF_BLOCKED: Outbound webhook URLs must use HTTPS.');
        }

        if (! empty($parts['user']) || ! empty($parts['pass'])) {
            throw new InvalidArgumentException('WEBHOOK_SSRF_BLOCKED: Webhook URLs must not contain embedded credentials.');
        }

        $rawHost = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($rawHost === '') {
            throw new InvalidArgumentException('WEBHOOK_SSRF_BLOCKED: Webhook URL is missing a valid host.');
        }

        if (
            in_array($rawHost, self::BLOCKED_HOSTNAMES, true)
            || str_ends_with($rawHost, '.localhost')
            || str_ends_with($rawHost, '.local')
            || str_ends_with($rawHost, '.internal')
        ) {
            throw new InvalidArgumentException("WEBHOOK_SSRF_BLOCKED: Host '{$rawHost}' is a prohibited internal or loopback hostname.");
        }

        // If host is an IPv4 or IPv6 literal, validate directly
        if (filter_var($rawHost, FILTER_VALIDATE_IP) !== false) {
            $this->assertPublicIp($rawHost);

            return;
        }

        // Resolve DNS records and verify every resolved IP is public
        $resolvedIps = $this->resolveHostIps($rawHost);
        foreach ($resolvedIps as $ip) {
            $this->assertPublicIp($ip);
        }
    }

    public function assertPublicIp(string $ip): void
    {
        $cleanIp = trim($ip, '[]');

        if (filter_var($cleanIp, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("WEBHOOK_SSRF_BLOCKED: Invalid IP address '{$cleanIp}'.");
        }

        $isPublic = filter_var(
            $cleanIp,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        if (! $isPublic) {
            throw new InvalidArgumentException(
                "WEBHOOK_SSRF_BLOCKED: Target IP '{$cleanIp}' resolves to a prohibited loopback, private, link-local, or reserved range."
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolveHostIps(string $host): array
    {
        if ($this->dnsResolver !== null) {
            return ($this->dnsResolver)($host);
        }

        $ips = gethostbynamel($host);
        if (! is_array($ips)) {
            return [];
        }

        return array_values(array_unique($ips));
    }
}
