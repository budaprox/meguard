<?php
// MEGuard – builds the IP → country index from the Regional Internet Registry (RIR) lists.
// Run by tools\geo-update.ps1 (weekly "MEGuard - geo" task) after downloading the lists; can be run by hand.
// Bemenet:  data\geo\{ripencc,arin,apnic,lacnic,afrinic}.txt
// Output:  data\geo\ip4.dat (10-byte records: start, end, country code)
//                    data\geo\ip6.dat (34-byte records: start, end, country code) – sorted by start, for binary search
// The filter (lib\GeoIp.php) looks up the sending server's country in these.

declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
ini_set('memory_limit', '512M');
require dirname(__DIR__) . '/lib/bootstrap.php';

$dir = meg_data('geo');
$files = [];
foreach (['ripencc', 'arin', 'apnic', 'lacnic', 'afrinic'] as $k) {
    $f = meg_path($dir, "$k.txt");
    if (is_file($f)) $files[] = $f;
}
if (count($files) < 5) {
    fwrite(STDERR, 'ERROR: RIR lists missing in ' . $dir . ' (' . count($files) . "/5). Run tools\\geo-update.cmd first\n");
    exit(1);
}

/** IPv6: set the bits after the prefix to 1 (last address of the range) */
function v6_end(string $start, int $len): string
{
    $b = $start;
    for ($i = 0; $i < 16; $i++) {
        $bitsHere = max(0, min(8, $len - $i * 8));   // number of network bits in this byte
        $mask = $bitsHere >= 8 ? 0 : (0xFF >> $bitsHere);
        $b[$i] = chr(ord($b[$i]) | $mask);
    }
    return $b;
}

$v4 = [];
$v6 = [];
foreach ($files as $f) {
    $h = fopen($f, 'r');
    while (($line = fgets($h)) !== false) {
        if ($line === '' || $line[0] === '#') continue;
        $c = explode('|', rtrim($line, "\r\n"));
        if (count($c) < 7) continue;
        [, $cc, $type, $start, $value, , $status] = $c;
        if (strlen($cc) !== 2 || $cc === 'ZZ' || ($status !== 'allocated' && $status !== 'assigned')) continue;
        // compact binary rows (start|end|country): little memory, and a plain sort orders by start address
        if ($type === 'ipv4') {
            $s = ip2long($start);
            if ($s === false) continue;
            $v4[] = pack('NN', $s, min(0xFFFFFFFF, $s + (int)$value - 1)) . strtoupper($cc);
        } elseif ($type === 'ipv6') {
            $s = @inet_pton($start);
            if ($s === false || strlen($s) !== 16) continue;
            $v6[] = $s . v6_end($s, (int)$value) . strtoupper($cc);
        }
    }
    fclose($h);
}

// sort and merge adjacent ranges of the same country
sort($v4, SORT_STRING);
$out4 = '';
$n4 = 0;
$cur = null;
foreach ($v4 as $r) {
    $x = unpack('Ns/Ne', $r);
    $cc = substr($r, 8, 2);
    if ($cur && $cur[2] === $cc && $x['s'] <= $cur[1] + 1) { $cur[1] = max($cur[1], $x['e']); continue; }
    if ($cur) { $out4 .= pack('NN', $cur[0], $cur[1]) . $cur[2]; $n4++; }
    $cur = [$x['s'], $x['e'], $cc];
}
if ($cur) { $out4 .= pack('NN', $cur[0], $cur[1]) . $cur[2]; $n4++; }
unset($v4);

sort($v6, SORT_STRING);
$n6 = count($v6);
$out6 = implode('', $v6);
unset($v6);

if ($n4 < 50000) {
    fwrite(STDERR, "ERROR: too few IPv4 ranges ($n4) – the lists are incomplete, the old index is kept.\n");
    exit(1);
}
foreach (['ip4.dat' => $out4, 'ip6.dat' => $out6] as $name => $data) {
    $tmp = meg_path($dir, $name . '.tmp');
    file_put_contents($tmp, $data);
    $dst = meg_path($dir, $name);
    if (!@rename($tmp, $dst)) {   // if the filter is reading it right now, retry a moment later
        usleep(500000);
        if (!@rename($tmp, $dst)) { @unlink($dst); rename($tmp, $dst); }
    }
}
echo "IP-to-country index ready: IPv4 $n4, IPv6 $n6 ranges\n";
exit(0);
