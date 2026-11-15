<?php
// MEGuard – imports MxScan lists and keyword rules from the mdb_export CSV files
// Usage: php tools\import_mxscan.php <export-folder> [<export-folder2> ...]
// Can be run repeatedly: existing entries are not duplicated.

declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/bootstrap.php';

$dirs = array_slice($argv, 1);
if (!$dirs) { fwrite(STDERR, "Usage: php import_mxscan.php <export-folder> [...]\n"); exit(1); }

function readCsv(string $file): array
{
    if (!is_file($file)) return [];
    $fh = fopen($file, 'rb');
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);
    $head = fgetcsv($fh, 0, ',', '"', '');
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if (count($r) !== count($head)) continue;
        $rows[] = array_combine($head, $r);
    }
    fclose($fh);
    return $rows;
}

$db = Db::get();
$insList = $db->prepare('INSERT OR IGNORE INTO lists(list, kind, value, comment, created) VALUES(?,?,?,?,?)');
$findRule = $db->prepare('SELECT id FROM rules WHERE name = ? AND scope = ? AND expr = ?');
$insRule = $db->prepare('INSERT INTO rules(name, type, scope, expr, score, enabled, comment, created) VALUES(?,?,?,?,?,?,?,?)');

$map = [
    'tblWhitelist_Sender'    => ['white', 'sender',    'WhitelistValue'],
    'tblWhitelist_Recipient' => ['white', 'recipient', 'WhitelistValue'],
    'tblWhitelist_IP'        => ['white', 'ip',        'WhitelistValue'],
    'tblBlackList_Sender'    => ['black', 'sender',    'BlacklistValue'],
    'tblBlackList_Recipient' => ['black', 'recipient', 'BlacklistValue'],
    'tblBlackList_IP'        => ['black', 'ip',        'BlacklistValue'],
];
$scopes = ['1' => 'body', '2' => 'subject', '3' => 'all', '4' => 'headers', '0' => 'all'];

$db->beginTransaction();
foreach ($dirs as $dir) {
    echo "== $dir\n";
    if (!is_dir($dir) || !is_file("$dir/mxscan-config__tblKeywordFilters.csv")) {
        echo "   ERROR: the folder does not exist or holds no MxScan export (mxscan-config__*.csv)\n";
        continue;
    }
    foreach ($map as $tbl => [$list, $kind, $col]) {
        $n = 0;
        foreach (readCsv("$dir/mxscan-config__$tbl.csv") as $r) {
            $v = strtolower(trim($r[$col] ?? ''));
            if ($v === '') continue;
            $insList->execute([$list, $kind, $v, trim($r['Comment'] ?? ''), time()]);
            $n += $insList->rowCount();
        }
        printf("   %-24s +%d\n", $tbl, $n);
    }
    $n = 0;
    foreach (readCsv("$dir/mxscan-config__tblKeywordFilters.csv") as $r) {
        $ft = $r['FilterType'] ?? '0';
        if ($ft === '1') continue; // encrypted character-set filters: not usable
        $name = trim($r['FilterName']);
        if ($ft === '2') {
            $type = 'special'; $scope = 'all'; $expr = strtoupper($name);
        } else {
            $expr = trim(str_replace("\r", '', $r['FilterExpression']));
            if ($expr === '') continue;
            $type = ($r['FilterExpressionType'] ?? '0') === '1' || preg_match('/\\\\[bswd]|\(\?:/', $expr) ? 'regex' : 'text';
            $scope = $scopes[$r['SearchScope'] ?? '0'] ?? 'all';
        }
        $findRule->execute([$name, $scope, $expr]);
        if ($findRule->fetchColumn()) continue;
        $score = (float)str_replace(',', '.', $r['Score'] ?? '0');
        $enabled = strtolower($r['Enabled'] ?? 'true') === 'true' ? 1 : 0;
        $insRule->execute([$name, $type, $scope, $expr, $score, $enabled, trim($r['Comment'] ?? ''), time()]);
        $n++;
    }
    printf("   %-24s +%d\n", 'tblKeywordFilters', $n);

    foreach (readCsv("$dir/mxscan-config__tblFilterAction.csv") as $r) {
        if (($r['DomainID'] ?? '') !== '1') continue;
        printf("   MxScan thresholds: %s / %s / %s\n", $r['ActionWeightLow'], $r['ActionWeightMedium'], $r['ActionWeightHigh']);
    }
}
$db->commit();

$c = $db->query("SELECT list, kind, COUNT(*) n FROM lists GROUP BY list, kind ORDER BY list, kind")->fetchAll();
echo "\nTotal in the database:\n";
foreach ($c as $r) printf("   %-6s %-10s %d\n", $r['list'], $r['kind'], $r['n']);
printf("   rules      %d\n", $db->query('SELECT COUNT(*) FROM rules')->fetchColumn());
