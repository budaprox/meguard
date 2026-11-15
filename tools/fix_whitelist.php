<?php
// MEGuard – one-off whitelist clean-up
// By default it only prints what it would change. To apply: php tools\fix_whitelist.php --apply

declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$db = Db::get();

// 1) to delete: domains anyone can register under, and overly broad patterns
$delete = [
    '*@gmail.com' => 'free mail provider',
    '*@wp.pl'     => 'free mail provider',
    '*@o2.pl'     => 'free mail provider',
    '*abv.bg'     => 'free mail provider',
    '*.org.pl'    => 'anyone can register under it',
    '*gov.hu'     => 'too broad (e.g. fakegov.hu); *.gov.hu is kept',
];
// 2) pontos csere
$replace = [
    '*ryanair*' => 'ryanair.com',
    '*wizzair*' => 'wizzair.com',
];
// 3) to check by hand (left untouched)
$review = ['*@rackforest*', 'rajmund*', 'sasvaritrans*'];

$plan = [];
$rows = $db->query("SELECT id, value, hits FROM lists WHERE list = 'white' AND kind = 'sender'")->fetchAll();
$existing = array_column($rows, 'id', 'value');

foreach ($rows as $r) {
    $v = $r['value'];
    if (isset($delete[$v])) { $plan[] = ['del', $r, null, $delete[$v]]; continue; }
    if (isset($replace[$v])) { $plan[] = ['mod', $r, $replace[$v], 'exact domain']; continue; }
    if (in_array($v, $review, true)) { $plan[] = ['chk', $r, null, 'ambiguous – check it by hand']; continue; }
    // *something.com  ->  something.com   (the star without @ or dot would also let look-alike domains through)
    if (preg_match('/^\*([a-z0-9][^*]*)$/i', $v, $m) && $m[1][0] !== '.' && $m[1][0] !== '@') {
        $plan[] = ['mod', $r, $m[1], str_contains($m[1], '@') ? 'exact address' : 'exact domain + subdomains'];
    }
}

if (!$plan) { echo "Nothing to do, the whitelist is fine.\n"; exit; }

$labels = ['del' => 'DELETE ', 'mod' => 'REPLACE', 'chk' => 'CHECK  '];
foreach ($plan as [$op, $r, $to, $why]) {
    printf("  %s %-26s %s  (%s)\n", $labels[$op], $r['value'], $to ? "-> $to" : '', $why);
}

if (!$apply) {
    echo "\nPreview only, nothing was changed.\nTo apply:  tools\\fix_whitelist.cmd --apply\n";
    exit;
}

$db->beginTransaction();
foreach ($plan as [$op, $r, $to]) {
    if ($op === 'del') {
        $db->prepare('DELETE FROM lists WHERE id = ?')->execute([$r['id']]);
    } elseif ($op === 'mod') {
        if (isset($existing[$to])) {
            // the corrected form already exists: take over the hit count, delete the old one
            $db->prepare('UPDATE lists SET hits = hits + ? WHERE id = ?')->execute([(int)$r['hits'], $existing[$to]]);
            $db->prepare('DELETE FROM lists WHERE id = ?')->execute([$r['id']]);
        } else {
            $db->prepare('UPDATE lists SET value = ? WHERE id = ?')->execute([$to, $r['id']]);
            $existing[$to] = $r['id'];
        }
    }
}
$db->commit();
echo "\nDone. The changes apply from the next message.\n";
