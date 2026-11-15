<?php
// MEGuard – backup: a consistent database snapshot + the settings in a dated ZIP.
// Run: tools\backup.cmd (by hand) or the "MEGuard - backup" scheduled task (tools\install-backup.cmd).
// The ZIPs go to config.php 'backup' => 'dir' (default C:\MEGuard\backup);
// let your existing backup system take them from there (e.g. to FTP). ZIPs older than keep_days are deleted.

declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

$cfg = meg_config();
$b = ($cfg['backup'] ?? []) + [
    'dir' => MEG_ROOT . DIRECTORY_SEPARATOR . 'backup',
    'keep_days' => 21,
    'quarantine' => false,
    'bayes_dir' => $cfg['bayes']['dbpath'] ?? 'C:\\Windows\\System32\\config\\systemprofile\\.spamassassin',
];
$logFile = meg_path(meg_data('logs'), 'backup.log');
meg_ensure_dir(dirname($logFile));
$say = function (string $s) use ($logFile) {
    $line = date('Y-m-d H:i:s') . '  ' . $s;
    echo $line, PHP_EOL;
    @file_put_contents($logFile, $line . "\r\n", FILE_APPEND);
};

$dir = rtrim((string)$b['dir'], '\\/');
meg_ensure_dir($dir);
$stamp = date('Y-m-d_His');
$tmp = meg_path($dir, 'tmp-' . $stamp);
meg_ensure_dir($tmp);

try {
    // 1. consistent database snapshot (safe while MEGuard is running)
    $dbFile = meg_data('meguard.sqlite');
    if (is_file($dbFile)) {
        $snap = meg_path($tmp, 'meguard.sqlite');
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 30]);
        $pdo->exec('PRAGMA busy_timeout = 30000');
        $pdo->exec("VACUUM INTO " . $pdo->quote($snap));
        $pdo = null;
        $say('Database snapshot: ' . round(filesize($snap) / 1048576, 1) . ' MB');
    } else {
        $say('WARNING: no database: ' . $dbFile);
    }

    // 2. files to back up: [source, path in the ZIP]
    $items = [
        [meg_path(MEG_ROOT, 'config.php'), 'config.php'],
        [meg_path($cfg['ipban_dir'] ?? meg_path(MEG_ROOT, 'ipban'), 'ipban.config'), 'ipban/ipban.config'],
        [meg_path(MEG_ROOT, 'spamassassin', 'etc', 'spamassassin', 'local.cf'), 'spamassassin/local.cf'],
        [meg_data('geo.json'), 'data/geo.json'],                       // country filter settings
        [meg_path(MEG_ROOT, 'php', 'php.ini'), 'php/php.ini'],         // only with MEGuard's own PHP
    ];
    foreach (glob(meg_path(rtrim((string)$b['bayes_dir'], '\\/'), 'bayes_*')) ?: [] as $f) {
        if (is_file($f) && !str_ends_with($f, '.lock')) $items[] = [$f, 'bayes/' . basename($f)];
    }
    if (!empty($b['quarantine'])) {
        $q = meg_data('quarantine');
        if (is_dir($q)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($q, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $fi) if ($fi->isFile()) $items[] = [$fi->getPathname(), 'quarantine/' . str_replace('\\', '/', substr($fi->getPathname(), strlen($q) + 1))];
        }
    }
    $n = 0;
    foreach ($items as [$src, $dst]) {
        if (!is_file($src)) { if ($dst !== 'php/php.ini') $say('  (missing: ' . $src . ')'); continue; }
        $to = meg_path($tmp, str_replace('/', DIRECTORY_SEPARATOR, $dst));
        meg_ensure_dir(dirname($to));
        if (@copy($src, $to)) $n++; else $say('  ERROR, cannot copy: ' . $src);
    }
    $say("Files: $n");

    file_put_contents(meg_path($tmp, 'RESTORE.txt'), implode("\r\n", [
        'MEGuard backup – ' . date('Y-m-d H:i') . ' – ' . ($cfg['server_name'] ?? gethostname()),
        '',
        'Restore (after a fresh MEGuard install, as administrator):',
        '1. Stop the "MEGuard - health" and "MEGuard - SpamAssassin spamd" tasks (Task Scheduler), or simply reboot at the end.',
        '2. meguard.sqlite      -> C:\\MEGuard\\data\\meguard.sqlite',
        '   config.php          -> C:\\MEGuard\\config.php',
        '   data\\geo.json       -> C:\\MEGuard\\data\\geo.json',
        '   ipban\\ipban.config  -> C:\\MEGuard\\ipban\\ipban.config (then restart the IPBan service)',
        '   spamassassin\\local.cf -> C:\\MEGuard\\spamassassin\\etc\\spamassassin\\local.cf',
        '   bayes\\*             -> ' . $b['bayes_dir'],
        '   php\\php.ini         -> C:\\MEGuard\\php\\php.ini (only if present)',
        '3. Restart SpamAssassin (web interface: Tools -> Resources).',
        'SpamAssassin rules, country lists and program files need no backup: they are downloaded again on install/update.',
    ]) . "\r\n");

    // 3. compress (ZipArchive if available; otherwise Windows' own tool)
    $zip = meg_path($dir, 'MEGuard-' . $stamp . '.zip');
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('cannot create the ZIP: ' . $zip);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fi) if ($fi->isFile()) $z->addFile($fi->getPathname(), str_replace('\\', '/', substr($fi->getPathname(), strlen($tmp) + 1)));
        $z->close();
    } else {
        $cmd = 'powershell.exe -NoProfile -NonInteractive -Command "Compress-Archive -Path \'' . $tmp . '\\*\' -DestinationPath \'' . $zip . '\' -Force" 2>&1';
        exec($cmd, $out, $rc);
        if ($rc !== 0 || !is_file($zip)) throw new RuntimeException('Compress-Archive error: ' . implode(' ', $out));
    }
    $say('Done: ' . $zip . ' (' . round(filesize($zip) / 1048576, 1) . ' MB)');
    $ok = true;
    // the watchdog uses this to notice missed backups
    try { Db::meta('backup_last', (string)time()); } catch (Throwable $e) { $say('WARNING: cannot write the timestamp to the database: ' . $e->getMessage()); }
} catch (Throwable $e) {
    $say('ERROR: ' . $e->getMessage());
    $ok = false;
}

// delete the temporary folder
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $fi) $fi->isDir() ? @rmdir($fi->getPathname()) : @unlink($fi->getPathname());
@rmdir($tmp);

// delete old backups (only after a successful backup)
if ($ok) {
    $limit = time() - 86400 * max(1, (int)$b['keep_days']);
    $del = 0;
    foreach (glob(meg_path($dir, 'MEGuard-*.zip')) ?: [] as $f) if (filemtime($f) < $limit && @unlink($f)) $del++;
    if ($del) $say("Old backups deleted: $del");
}
exit($ok ? 0 : 1);
