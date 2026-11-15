<?php
// MEGuard – reads IPBan data (log + database) and unbans

declare(strict_types=1);

final class IPBanInfo
{
    public static function dir(): string
    {
        return rtrim(meg_config()['ipban_dir'] ?? (MEG_ROOT . DIRECTORY_SEPARATOR . 'ipban'), '\\/');
    }

    public static function installed(): bool
    {
        return is_dir(self::dir()) && is_file(meg_path(self::dir(), 'logfile.txt'));
    }

    /**
     * Summary of log events since the given time.
     * @return array{failures: array, bans: int, unbans: int, from: int}
     */
    public static function events(int $since): array
    {
        $files = glob(meg_path(self::dir(), 'logfile*.txt')) ?: [];
        // of the older, closed files only those within the period are needed
        $files = array_filter($files, fn($f) => filemtime($f) >= $since);
        usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));

        $fail = []; $bans = 0; $unbans = 0; $banCount = [];
        foreach ($files as $f) {
            foreach (self::parseFile($f) as $e) {
                if ($e[0] < $since) continue;
                if ($e[1] === 'F') $fail[] = $e;
                elseif ($e[1] === 'B') { $bans++; if (!empty($e[2])) $banCount[$e[2]] = (int)($e[3] ?? 0); }
                elseif ($e[1] === 'U') $unbans++;
            }
        }
        return ['failures' => $fail, 'bans' => $bans, 'unbans' => $unbans, 'from' => $since, 'ban_count' => $banCount];
    }

    /** Events of one log file; closed files are cached. */
    private static function parseFile(string $file): array
    {
        $isCurrent = strcasecmp(basename($file), 'logfile.txt') === 0;
        $key = 'ipbanlog2:' . basename($file) . ':' . filesize($file) . ':' . filemtime($file);
        if (!$isCurrent) {
            $c = Db::meta($key);
            if ($c !== null) return json_decode($c, true) ?: [];
        }
        $out = [];
        $fh = @fopen($file, 'rb');
        if (!$fh) return [];
        while (($line = fgets($fh)) !== false) {
            if (stripos($line, 'ban') === false && !str_contains($line, 'Login failure')) continue;
            if (preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)[^|]*\|[^|]*\|[^|]*\|(.*)$/', $line, $m) !== 1) continue;
            $ts = strtotime($m[1]);
            $msg = trim($m[2]);
            if (preg_match('/^Login failure: ([0-9A-Fa-f:.]+), ([^,]*), ([^,]+),/', $msg, $x)) {
                $out[] = [$ts, 'F', $x[1], trim($x[3]), trim($x[2])];
            } elseif (str_starts_with($msg, 'Banning ip address')) {
                // "Banning ip address: 1.2.3.4, user name: , ..., count: 6, ..."
                $bip = preg_match('/^Banning ip address:?\s*([0-9A-Fa-f:.]+)/', $msg, $y) ? $y[1] : '';
                $bcnt = preg_match('/count:\s*(\d+)/', $msg, $z) ? (int)$z[1] : 0;
                $out[] = [$ts, 'B', $bip, $bcnt];
            } elseif (str_starts_with($msg, 'Un-banning ip address')) {
                $out[] = [$ts, 'U'];
            }
        }
        fclose($fh);
        if (!$isCurrent) {
            try { Db::meta($key, json_encode($out)); } catch (Throwable) { }
        }
        return $out;
    }

    /** IPs currently banned, from the IPBan database. */
    public static function banned(): array
    {
        $file = meg_path(self::dir(), 'ipban.sqlite');
        if (!is_file($file)) return [];
        try {
            $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
            $cols = array_column($pdo->query('PRAGMA table_info(IPAddresses)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('IPAddressText', $cols, true) || !in_array('BanDate', $cols, true)) return [];
            $sel = ['IPAddressText ip', 'BanDate', 'BanEndDate', 'FailedLoginCount cnt'];
            foreach (['Source', 'UserName', 'State'] as $c) if (in_array($c, $cols, true)) $sel[] = $c;
            $rows = $pdo->query('SELECT ' . implode(',', $sel) . ' FROM IPAddresses WHERE BanDate IS NOT NULL AND BanDate > 0 ORDER BY BanDate DESC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
        $now = time();
        $out = [];
        foreach ($rows as $r) {
            $start = self::toTs($r['BanDate']);
            $end = self::toTs($r['BanEndDate'] ?? 0);
            if ($end && $end < $now) continue; // expired
            $out[] = [
                'ip' => (string)$r['ip'], 'start' => $start, 'end' => $end, 'count' => (int)$r['cnt'],
                'source' => (string)($r['Source'] ?? ''), 'user' => (string)($r['UserName'] ?? ''),
            ];
        }
        return $out;
    }

    /** IPBan timestamps: milliseconds or .NET ticks, or a date string */
    private static function toTs(mixed $v): int
    {
        if ($v === null || $v === '' || $v === 0) return 0;
        if (is_numeric($v)) {
            $n = (float)$v;
            if ($n > 6.0e17) return (int)(($n - 621355968000000000) / 10000000); // .NET ticks
            if ($n > 1.0e11) return (int)($n / 1000);                           // milliseconds
            return (int)$n;
        }
        return (int)(strtotime((string)$v) ?: 0);
    }

    /** Unban: IPBan unbans the addresses found in unban.txt on its next cycle. */
    public static function unban(string $ip): void
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('invalid IP');
        $f = meg_path(self::dir(), 'unban.txt');
        if (file_put_contents($f, $ip . "\r\n", FILE_APPEND | LOCK_EX) === false) throw new RuntimeException('not writable: ' . $f);
    }

    /** Source name -> short label */
    public static function label(string $source): string
    {
        $s = strtolower($source);
        if (str_contains($s, 'smtp')) return 'SMTP';
        if (str_contains($s, 'imap')) return 'IMAP';
        if (str_contains($s, 'pop')) return 'POP';
        if (str_contains($s, 'rdp')) return 'RDP';
        if ($s === 'mailenable') return 'SMTP';
        return $source !== '' ? $source : '?';
    }

    /** Addresses that exist in MailEnable (lower case), from ADDRESS-MAP.TAB; null if unreadable. */
    public static function existingAddresses(): ?array
    {
        static $cache = null;
        if ($cache !== null) return $cache ?: null;
        $cfg = meg_config();
        $file = $cfg['address_map'] ?? meg_path($cfg['mailenable_root'], 'Config', 'ADDRESS-MAP.TAB');
        $txt = @file_get_contents($file);
        if ($txt === false) { $cache = []; return null; }
        $set = [];
        if (preg_match_all('/\[SMTP:([^\]]+)\]/i', $txt, $m)) {
            foreach ($m[1] as $a) {
                $a = strtolower(trim($a));
                $set[$a] = true;
                // the IMAP/POP user name may be just the local part of the address
                $local = strstr($a, '@', true);
                if ($local !== false && !isset($set[$local])) $set[$local] = true;
            }
        }
        $cache = $set;
        return $set ?: null;
    }
}
