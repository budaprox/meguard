<?php
// MEGuard – DNS blocklists for the sending IP address

declare(strict_types=1);

final class Dnsbl
{
    public static function isPublicIp(string $ip): bool
    {
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public static function reverseName(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_reverse(explode('.', $ip)));
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $hex = bin2hex((string)inet_pton($ip));
            return implode('.', array_reverse(str_split($hex)));
        }
        return null;
    }

    /** @return string[] az A rekordok */
    public static function lookupA(string $name): array
    {
        $r = @dns_get_record($name, DNS_A);
        if (!is_array($r)) return [];
        $out = [];
        foreach ($r as $x) if (isset($x['ip'])) $out[] = $x['ip'];
        return $out;
    }

    /**
     * @param array $lists config 'dnsbl'
     * @return array{score: float, hits: array, errors: array}
     */
    public static function check(string $ip, array $lists): array
    {
        $out = ['score' => 0.0, 'hits' => [], 'errors' => []];
        if (!self::isPublicIp($ip)) return $out;
        $rev = self::reverseName($ip);
        if ($rev === null) return $out;
        foreach ($lists as $l) {
            if (empty($l['enabled'])) continue;
            $answers = self::lookupA($rev . '.' . $l['zone']);
            if (!$answers) continue;
            $valid = [];
            foreach ($answers as $a) {
                // 127.255.255.x = the list refused the query (e.g. from a public DNS resolver)
                if (str_starts_with($a, '127.255.255.')) { $out['errors'][] = $l['zone'] . ' blocked (' . $a . ')'; continue; }
                if (!str_starts_with($a, '127.')) continue;
                if ($l['hit'] === '*' || $l['hit'] === $a) $valid[] = $a;
            }
            if ($valid) {
                $out['score'] += (float)$l['score'];
                $out['hits'][] = ['zone' => $l['zone'], 'answer' => implode(',', $valid), 'score' => (float)$l['score']];
            }
        }
        return $out;
    }
}
