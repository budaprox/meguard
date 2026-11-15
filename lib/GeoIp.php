<?php
// MEGuard – IP address → country code (data\geo\ip4.dat / ip6.dat, built weekly by tools\geo-ipdb.php)

declare(strict_types=1);

final class GeoIp
{
    /** @return string|null two-letter country code (e.g. 'US'), or null if unknown / no index */
    public static function country(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) return null;
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xFF\xFF")) $bin = substr($bin, 12); // ::ffff:a.b.c.d
        [$file, $klen] = strlen($bin) === 4 ? ['ip4.dat', 4] : ['ip6.dat', 16];
        $path = meg_data('geo', $file);
        $size = @filesize($path);
        $rec = 2 * $klen + 2;
        if (!$size || $size % $rec !== 0) return null;
        $h = @fopen($path, 'rb');
        if (!$h) return null;
        $lo = 0;
        $hi = intdiv($size, $rec) - 1;
        $found = null;
        while ($lo <= $hi) {
            $mid = ($lo + $hi) >> 1;
            fseek($h, $mid * $rec);
            $r = fread($h, $rec);
            if (strlen($r) !== $rec) break;
            if (strcmp($bin, substr($r, 0, $klen)) < 0) { $hi = $mid - 1; continue; }
            if (strcmp($bin, substr($r, $klen, $klen)) > 0) { $lo = $mid + 1; continue; }
            $found = substr($r, 2 * $klen, 2);
            break;
        }
        fclose($h);
        return $found;
    }

    /** Index status for the interface: [updated (unix time), number of IPv4 ranges] or null */
    public static function info(): ?array
    {
        $p = meg_data('geo', 'ip4.dat');
        return is_file($p) ? [(int)filemtime($p), intdiv((int)filesize($p), 10)] : null;
    }
}
