<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;

/** Resolve every address, reject non-public destinations, then pin one TLS connection. */
class StockHubGitaImageAddress
{
    public function __construct(private StockHubGitaImageAddressResolver $resolver) {}

    public function validate(mixed $url): array
    {
        $parts = is_string($url) ? parse_url($url) : false;
        $this->need(is_array($parts) && filter_var($url, FILTER_VALIDATE_URL) !== false && ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass']) && (!isset($parts['port']) || $parts['port'] === 443));
        $authority = strtolower($parts['host'] ?? '');
        $host = str_starts_with($authority, '[') && str_ends_with($authority, ']') ? substr($authority, 1, -1) : $authority;
        $this->need($host !== '' && !in_array($host, ['localhost','localhost.localdomain'], true) && !str_ends_with($host, '.local') && !str_ends_with($host, '.localhost') && !str_ends_with($host, '.internal'));
        if (filter_var($host, FILTER_VALIDATE_IP)) { $addresses = [$host]; }
        else {
            $this->need(preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host) === 1 && str_contains($host, '.') && !ctype_digit(str_replace('.', '', $host)));
            $addresses = $this->resolver->resolve($host);
        }
        $this->need(is_array($addresses) && count($addresses) > 0);
        foreach ($addresses as $address) { $this->need(is_string($address) && $this->publicAddress($address)); }
        $addresses = array_values(array_unique($addresses));
        usort($addresses, fn ($a,$b) => (str_contains($a, ':') <=> str_contains($b, ':')) ?: strcmp($a, $b));
        $address = $addresses[0];
        $connectHost = str_contains($address, ':') ? '['.$address.']' : $address;
        return ['url' => $url, 'host' => $host, 'authority' => $authority.(isset($parts['port']) ? ':'.$parts['port'] : ''), 'address' => $address, 'connect_url' => (string) (new Uri($url))->withHost($connectHost)];
    }

    private function publicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'] as $range) { if ($this->inRange($address, $range)) { return false; } }
            return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        $packed = @inet_pton($address);
        if (!is_string($packed) || strlen($packed) !== 16) { return false; }
        // Mapped addresses inherit IPv4 policy; all other usable public IPv6 must be global unicast.
        if (substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") { return $this->publicAddress(inet_ntop(substr($packed, 12))); }
        foreach (['2001::/32','2001:2::/48','2001:10::/28','2001:20::/28','2001:db8::/32','2002::/16','3fff::/20'] as $range) { if ($this->inRange($address, $range)) { return false; } }
        return (ord($packed[0]) & 0xe0) === 0x20 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function inRange(string $address, string $range): bool
    {
        [$network,$bits] = explode('/', $range); $packed = inet_pton($address); $prefix = inet_pton($network);
        if (strlen($packed) !== strlen($prefix)) { return false; }
        $bytes = intdiv((int) $bits, 8); $remaining = (int) $bits % 8;
        return substr($packed, 0, $bytes) === substr($prefix, 0, $bytes) && ($remaining === 0 || (ord($packed[$bytes]) & (0xff << (8 - $remaining))) === (ord($prefix[$bytes]) & (0xff << (8 - $remaining))));
    }

    private function need(bool $ok): void { if (!$ok) { throw new \DomainException('Gambar harus memiliki URL HTTPS publik.'); } }
}
