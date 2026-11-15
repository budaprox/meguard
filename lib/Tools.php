<?php
// MEGuard – Tools page: system status, MailEnable queues, service restarts

declare(strict_types=1);

final class Tools
{
    /** order used by the "restart mail services" button (whichever exist) */
    public const MAIL_SET = ['MEMTAS', 'MEPOCS', 'MESMTPCS', 'MEPOPS', 'MEIMAPS'];

    public static function isWindows(): bool { return stripos(PHP_OS, 'WIN') === 0; }

    /** A tools\sysinfo.ps1 kimenete (kb. 2–3 mp) */
    public static function sysinfo(): array
    {
        if (!self::isWindows()) {
            $f = meg_data('sysinfo-sample.json');   // for development
            return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : ['ok' => false, 'error' => 'Works on Windows servers only.'];
        }
        if (!function_exists('exec')) return ['ok' => false, 'error' => 'PHP exec() is disabled.'];
        @set_time_limit(60);
        $ps = MEG_ROOT . '\\tools\\sysinfo.ps1';
        exec('powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' . $ps . '" 2>&1', $out, $rc);
        $raw = trim(implode("\n", $out));
        $j = json_decode($raw, true);
        if (!is_array($j)) return ['ok' => false, 'error' => 'Cannot read the system status (' . mb_substr($raw, 0, 200) . ')'];
        foreach (['groups', 'procs', 'services', 'tasks', 'disks'] as $k) $j[$k] = (array)($j[$k] ?? []);
        // PowerShell 5 may turn a one-element array into an object
        foreach (['procs', 'services', 'tasks', 'disks'] as $k) if ($j[$k] && !array_is_list($j[$k])) $j[$k] = [$j[$k]];
        return $j;
    }

    /** MailEnable queues: Queues\<connector>\<direction>\Messages\*.MAI */
    public static function queues(): array
    {
        $root = meg_config()['queues_dir'];
        $res = [];
        foreach (glob($root . '/*/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $md = $dir . DIRECTORY_SEPARATOR . 'Messages';
            if (!is_dir($md)) continue;
            $n = 0; $oldest = 0; $size = 0;
            foreach (new DirectoryIterator($md) as $f) {
                if (!$f->isFile() || strcasecmp($f->getExtension(), 'MAI') !== 0) continue;
                $n++; $size += $f->getSize();
                $m = $f->getMTime();
                if ($oldest === 0 || $m < $oldest) $oldest = $m;
            }
            $res[] = ['connector' => basename(dirname($dir)), 'dir' => basename($dir), 'count' => $n, 'size' => $size, 'oldest' => $oldest];
        }
        usort($res, fn($a, $b) => [$a['connector'], $a['dir']] <=> [$b['connector'], $b['dir']]);
        return $res;
    }

    public static function canRestart(string $name): bool
    {
        return (bool)preg_match('/^ME[A-Z]{2,10}$/', $name) || $name === 'IPBan';
    }

    /** @param string[] $names  @return array{ok:bool, message:string} */
    public static function restart(array $names): array
    {
        $names = array_values(array_filter($names, [self::class, 'canRestart']));
        if (!$names) return ['ok' => false, 'message' => 'no restartable service'];
        if (!self::isWindows()) return ['ok' => false, 'message' => 'Works on Windows servers only.'];
        if (!function_exists('exec')) return ['ok' => false, 'message' => 'PHP exec() is disabled.'];
        @set_time_limit(180);
        meg_errlog('service restart from the web interface: ' . implode(',', $names) . ' (' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ')');
        $list = "'" . implode("','", $names) . "'";
        // one by one, in the given order; -Force also restarts dependent services
        $cmd = "\$ErrorActionPreference='Stop'; foreach(\$n in @($list)){ \$s = Get-Service -Name \$n -ErrorAction SilentlyContinue; if(-not \$s){ continue }; Restart-Service -Name \$n -Force; } "
             . "\$bad = @(Get-Service -Name $list -ErrorAction SilentlyContinue | Where-Object { \$_.Status -ne 'Running' } | ForEach-Object { \$_.Name }); if(\$bad.Count){ 'NOTRUNNING:' + (\$bad -join ',') } else { 'OK' }";
        exec('powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "' . str_replace('"', '\\"', $cmd) . '" 2>&1', $out, $rc);
        $txt = trim(implode(' ', $out));
        if (str_ends_with($txt, 'OK')) return ['ok' => true, 'message' => ''];
        if (preg_match('/NOTRUNNING:(\S+)/', $txt, $m)) return ['ok' => false, 'message' => t('tools.notrunning', ['s' => $m[1]])];
        if (stripos($txt, 'Access is denied') !== false || stripos($txt, 'megtagad') !== false || stripos($txt, 'Cannot open') !== false) {
            $who = trim((string)@exec('whoami'));
            return ['ok' => false, 'message' => t('tools.perm', ['user' => $who ?: '?'])];
        }
        return ['ok' => false, 'message' => mb_substr($txt, 0, 300)];
    }
}
