<?php

namespace App\Support;

/**
 * SSRF protection for server-side HTTP calls to customer-supplied URLs (webhooks).
 *
 * - only http/https, only ports 80/443 (or explicitly allowed)
 * - no credentials in the URL
 * - every resolved IPv4/IPv6 address must be public (no loopback, private, link-local,
 *   reserved, CGNAT, metadata ranges)
 * - the caller must pin the resolved IP (CURLOPT_RESOLVE) and disable redirects,
 *   so DNS rebinding and redirect tricks cannot reach internal hosts.
 */
class OutboundUrlGuard
{
    /** @var callable|null test hook: fn(string $host): array<string> */
    public static $resolver = null;

    /**
     * @return array{ok: bool, reason?: string, host?: string, port?: int, ip?: string}
     */
    public static function check(?string $url): array
    {
        if (! is_string($url) || $url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'reason' => 'Invalid URL.'];
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'reason' => 'Only http and https URLs are allowed.'];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'reason' => 'Credentials in the URL are not allowed.'];
        }

        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return ['ok' => false, 'reason' => 'Internal hostnames are not allowed.'];
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! in_array($port, [80, 443, 8080, 8443], true)) {
            return ['ok' => false, 'reason' => 'Only ports 80, 443, 8080 and 8443 are allowed.'];
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if (empty($ips)) {
            return ['ok' => false, 'reason' => 'Host name could not be resolved.'];
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return ['ok' => false, 'reason' => 'URL points to a private or reserved network address.'];
            }
        }

        return ['ok' => true, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /** Guzzle options that pin the vetted IP and forbid redirects. */
    public static function httpOptions(array $check): array
    {
        $ip = $check['ip'];
        $resolveIp = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return [
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_RESOLVE => ["{$check['host']}:{$check['port']}:{$resolveIp}"],
            ],
        ];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Ranges PHP's flags do not cover.
        $blockedV4 = ['100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4'];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach ($blockedV4 as $cidr) {
                if (self::inCidr4($ip, $cidr)) {
                    return false;
                }
            }

            return true;
        }

        // IPv6, compared on the binary address (text prefixes can be bypassed, e.g.
        // 0:0:0:0:0:ffff:7f00:1 = 127.0.0.1). Blocked: every range that embeds or tunnels IPv4
        // (mapped, compatible, NAT64, 6to4, Teredo) and unique-local, link-local, multicast, docs.
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return false;
        }
        $blockedV6 = [
            '::/96',           // IPv4-compatible, ::, ::1
            '::ffff:0:0/96',   // IPv4-mapped
            '64:ff9b::/96',    // NAT64
            '64:ff9b:1::/48',  // local NAT64
            '100::/64',        // discard
            '2001::/32',       // Teredo
            '2001:db8::/32',   // documentation
            '2002::/16',       // 6to4
            'fc00::/7',        // unique local
            'fe80::/10',       // link local
            'fec0::/10',       // site local (deprecated)
            'ff00::/8',        // multicast
        ];
        foreach ($blockedV6 as $cidr) {
            if (self::inCidr6($bin, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr6(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = inet_pton($net);
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($bin, $netBin, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    private static function resolve(string $host): array
    {
        if (self::$resolver) {
            return (self::$resolver)($host);
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $r) {
            if (! empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
            if (! empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if (empty($ips)) {
            $v4 = @gethostbynamel($host);
            $ips = $v4 ?: [];
        }

        return array_values(array_unique($ips));
    }

    private static function inCidr4(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $mask = -1 << (32 - (int) $bits);

        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
}
