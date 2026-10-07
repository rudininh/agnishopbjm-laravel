<?php

namespace App\Services;

/** DNS-only seam; address policy and connection pinning remain in the real consumer. */
class StockHubGitaImageAddressResolver
{
    public function resolve(string $host): array { return $this->addresses($host, []); }

    private function addresses(string $host, array $seen): array
    {
        if (count($seen) >= 8 || in_array($host, $seen, true)) { throw new \DomainException('Alamat gambar publik belum dapat dipastikan.'); }
        $seen[] = $host;
        $records = @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);
        if (!is_array($records) || $records === []) { throw new \DomainException('Alamat gambar publik belum dapat dipastikan.'); }
        $addresses = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) { $addresses[] = $record['ip']; }
            if (isset($record['ipv6'])) { $addresses[] = $record['ipv6']; }
            if (($record['type'] ?? '') === 'CNAME' && is_string($record['target'] ?? null)) { $addresses = [...$addresses, ...$this->addresses(strtolower(rtrim($record['target'], '.')), $seen)]; }
        }
        return array_values(array_unique($addresses));
    }
}
