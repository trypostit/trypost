<?php

declare(strict_types=1);

namespace App\Services\Http;

/**
 * Every IPv4 and IPv6 address a host name resolves to.
 */
class HostResolver
{
    /**
     * DNS records and the system resolver (hosts file, numeric forms such as
     * `2130706433` or `127.1`) together, so every address the request could
     * reach is checked.
     *
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $fromDns = collect($records)
            ->map(fn (array $record): ?string => data_get($record, 'ip') ?? data_get($record, 'ipv6'))
            ->filter()
            ->values()
            ->all();

        return array_values(array_unique([...$fromDns, ...(@gethostbynamel($host) ?: [])]));
    }
}
