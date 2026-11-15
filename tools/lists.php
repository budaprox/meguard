<?php
// MEGuard – list maintenance
//
//     php tools\lists.php backup                 save every list to CSV (data\backup\lists-YYYYMMDD-HHMM.csv)
//     php tools\lists.php clear-black            delete the whole blacklist (automatic backup first)
//     php tools\lists.php prune-white            preview: whitelist entries that have never matched
//     php tools\lists.php prune-white --apply    ...and delete them (automatic backup first)
//     php tools\lists.php import <backup.csv>    import lists from another server's backup (keeps existing ones)
//
// prune-white only deletes entries that have been on the list for at least 14 days without a single match.

declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/bootstrap.php';

$cmd = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);
$minDays = 14;
$db = Db::get();

function backup(PDO $db): string
{
    $dir = meg_data('backup');
    meg_ensure_dir($dir);
    $file = meg_path($dir, 'lists-' . date('Ymd-Hi') . '.csv');
    $fh = fopen($file, 'wb');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['list', 'kind', 'value', 'comment', 'hits', 'last_hit', 'created'], ',', '"', '');
    foreach ($db->query('SELECT list, kind, value, comment, hits, last_hit, created FROM lists ORDER BY list, kind, value') as $r) {
        $r['last_hit'] = $r['last_hit'] ? date('Y-m-d H:i', (int)$r['last_hit']) : '';
        $r['created'] = $r['created'] ? date('Y-m-d H:i', (int)$r['created']) : '';
        fputcsv($fh, array_values($r), ',', '"', '');
    }
    fclose($fh);
    return $file;
}

switch ($cmd) {
    case 'backup':
        echo "Saved: " . backup($db) . "\n";
        break;

    case 'clear-black':
        $n = (int)$db->query("SELECT COUNT(*) FROM lists WHERE list = 'black'")->fetchColumn();
        echo "Backup: " . backup($db) . "\n";
        $db->exec("DELETE FROM lists WHERE list = 'black'");
        echo "Blacklist cleared ($n entries).\n";
        break;

    case 'prune-white':
        $limit = time() - $minDays * 86400;
        $st = $db->prepare("SELECT id, kind, value, created FROM lists WHERE list = 'white' AND hits = 0 AND created < ? ORDER BY kind, value");
        $st->execute([$limit]);
        $rows = $st->fetchAll();
        $young = (int)$db->query("SELECT COUNT(*) FROM lists WHERE list = 'white' AND hits = 0 AND created >= $limit")->fetchColumn();
        $used = (int)$db->query("SELECT COUNT(*) FROM lists WHERE list = 'white' AND hits > 0")->fetchColumn();
        foreach ($rows as $r) printf("  %-10s %s\n", $r['kind'], $r['value']);
        printf("\n%d entries never matched (on the list for at least %d days).\n", count($rows), $minDays);
        printf("%d entries have matched – they stay.\n", $used);
        if ($young) printf("%d entries are younger than %d days – left alone for now.\n", $young, $minDays);
        if (!$rows) break;
        if (!$apply) { echo "\nPreview only. To delete: tools\\lists.cmd prune-white --apply\n"; break; }
        echo "Backup: " . backup($db) . "\n";
        $del = $db->prepare('DELETE FROM lists WHERE id = ?');
        $db->beginTransaction();
        foreach ($rows as $r) $del->execute([$r['id']]);
        $db->commit();
        echo count($rows) . " entries deleted.\n";
        break;

    case 'import':
        $file = $argv[2] ?? '';
        if (!is_file($file)) { echo "No such file: $file\n"; break; }
        $fh = fopen($file, 'rb');
        if (fread($fh, 3) !== "\xEF\xBB\xBF") rewind($fh);
        $head = fgetcsv($fh, 0, ',', '"', '');
        if (!$head || !in_array('value', $head, true)) { echo "This is not a MEGuard list backup.\n"; break; }
        $ins = $db->prepare('INSERT OR IGNORE INTO lists(list, kind, value, comment, created) VALUES(?,?,?,?,?)');
        $n = 0; $all = 0;
        $db->beginTransaction();
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (count($r) !== count($head)) continue;
            $r = array_combine($head, $r);
            if (!in_array($r['list'], ['white', 'black'], true) || !in_array($r['kind'], ['sender', 'recipient', 'ip'], true)) continue;
            $all++;
            $ins->execute([$r['list'], $r['kind'], strtolower(trim($r['value'])), $r['comment'] ?? '', time()]);
            $n += $ins->rowCount();
        }
        $db->commit();
        fclose($fh);
        echo "$all entries in the backup, $n of them were new.\n";
        break;

    default:
        echo "Usage:\n  lists.cmd backup\n  lists.cmd clear-black\n  lists.cmd prune-white [--apply]\n  lists.cmd import <backup.csv>\n";
}
