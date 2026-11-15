<?php
// MEGuard – SpamAssassin Bayes training (nightly, from the "MEGuard - Bayes" task, as SYSTEM)
//
// Learned as spam:
//     - messages in the quarantine (not learned yet),
//     - messages set aside in data\learn\spam (deleted from quarantine, deleted automatically / blacklisted),
//     - messages at least N days old in users' Junk folders (by then anyone who wanted them has rescued them).
// Learned as ham:
//     - messages released from quarantine (data\learn\ham) – this also corrects an earlier spam lesson.
//
// Manual run: tools\bayes-learn.cmd  (as administrator; uses the SYSTEM account's database: --dbpath)

declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require_once MEG_ROOT . '/lib/Db.php';

$cfg = meg_config();
$b = ($cfg['bayes'] ?? []) + [
    'enabled' => true,
    'dbpath' => 'C:\\Windows\\System32\\config\\systemprofile\\.spamassassin',
    'junk_folders' => ['Junk E-mail', 'Lev&AOk-lszem&AOk-t', 'Junk', 'Spam'],
    'junk_min_age_days' => 7,
    'max_per_run' => 3000,
];
$logFile = meg_path(meg_data('logs'), 'bayes.log');
meg_ensure_dir(dirname($logFile));
$say = function (string $s) use ($logFile) {
    $line = date('Y-m-d H:i:s') . '  ' . $s;
    echo $line, PHP_EOL;
    @file_put_contents($logFile, $line . "\r\n", FILE_APPEND);
};
if (empty($b['enabled'])) { $say('Bayes training is disabled (config: bayes.enabled).'); exit(0); }

$sa = meg_path(MEG_ROOT, 'spamassassin');
$learn = meg_path($sa, 'sa-learn.exe');
if (!is_file($learn)) { $say('ERROR: not found: ' . $learn); exit(1); }

// only one run at a time
$lock = fopen(meg_path(meg_data(), 'bayes.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { $say('A training run is already in progress, exiting.'); exit(0); }

$state = json_decode((string)Db::meta('bayes_state'), true) ?: [];
$prev = (int)($state['last'] ?? 0);           // time of the previous run (0 = first run: everything)
$now = time();
$max = (int)$b['max_per_run'];

// ---- collect -----------------------------------------------------------------
$spam = [];
$ham = [];
$spool = fn(string $k) => meg_data('learn', $k);

// 1. quarantine: arrived since the previous run
foreach (glob(meg_path(meg_data('quarantine'), '*'), GLOB_ONLYDIR) ?: [] as $d) {
    foreach (glob(meg_path($d, '*')) ?: [] as $f) {
        if (preg_match('/\.CMD$/i', $f)) continue;
        if (@filemtime($f) >= $prev - 3600) $spam[] = $f;
    }
}
// 2. messages set aside
foreach (glob(meg_path($spool('spam'), '*')) ?: [] as $f) if (is_file($f)) $spam[] = $f;
foreach (glob(meg_path($spool('ham'), '*')) ?: [] as $f) if (is_file($f)) $ham[] = $f;

// 3. Junk folders: messages at least N days old that crossed that age since the previous run
$age = 86400 * max(0, (int)$b['junk_min_age_days']);
$upper = $now - $age;
$lower = $prev ? $prev - $age : 0;
$junk = [];
$po = meg_path($cfg['mailenable_root'], 'Postoffices');
foreach ((array)$b['junk_folders'] as $name) {
    foreach (glob(meg_path($po, '*', 'MAILROOT', '*', $name), GLOB_ONLYDIR) ?: [] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fi) {
            if (!$fi->isFile() || !preg_match('/\.mai$/i', $fi->getFilename())) continue;
            $t = $fi->getMTime();
            if ($t < $upper && $t >= $lower) $junk[$fi->getPathname()] = $t;
        }
    }
}
arsort($junk);                                   // newest first
$spam = array_merge($spam, array_keys($junk));
$spam = array_slice(array_values(array_unique($spam)), 0, $max);
$ham = array_slice($ham, 0, $max);

$say(sprintf('Training starts: %d spam (of which Junk: %d), %d ham.', count($spam), min(count($junk), count($spam)), count($ham)));

// ---- training: files go to a temporary folder (command-line length), sa-learn reads from there ----
$run = function (string $kind, array $files) use ($sa, $learn, $b, $say): ?int {
    if (!$files) return 0;
    $tmp = meg_data('learn', 'batch-' . $kind);
    meg_ensure_dir($tmp);
    foreach (glob(meg_path($tmp, '*')) ?: [] as $f) @unlink($f);
    $i = 0;
    foreach ($files as $f) { if (@copy($f, meg_path($tmp, sprintf('%06d.eml', ++$i)))) continue; }
    $cmd = '"' . $learn . '" --dbpath "' . $b['dbpath'] . '" --' . $kind . ' "' . $tmp . '" 2>&1';
    $old = getcwd();
    chdir($sa);                                   // SpamAssassin finds its rules relative to its own folder
    exec($cmd, $out, $rc);
    chdir($old);
    foreach (glob(meg_path($tmp, '*')) ?: [] as $f) @unlink($f);
    @rmdir($tmp);
    $msg = trim(implode(' ', array_filter($out, fn($l) => stripos($l, 'registryboundaries') === false)));
    $say(sprintf('  sa-learn --%s: exit code %d. %s', $kind, $rc, $msg));
    if ($rc !== 0) return null;
    return preg_match('/Learned tokens from (\d+) message/i', $msg, $m) ? (int)$m[1] : 0;
};

$ls = $run('spam', $spam);
$lh = $run('ham', $ham);

// set-aside messages can be deleted after successful training
if ($ls !== null) foreach (glob(meg_path($spool('spam'), '*')) ?: [] as $f) @unlink($f);
if ($lh !== null) foreach (glob(meg_path($spool('ham'), '*')) ?: [] as $f) @unlink($f);

// status: what the database holds
$old = getcwd(); chdir($sa);
exec('"' . $learn . '" --dbpath "' . $b['dbpath'] . '" --dump magic 2>&1', $dump);
chdir($old);
$nspam = $nham = null;
foreach ($dump as $l) {
    // line: "0.000   0   <value>   0  non-token data: nspam"
    if (preg_match('/^\s*[\d.]+\s+\d+\s+(\d+)\s+\d+\s+non-token data: nspam/', $l, $m)) $nspam = (int)$m[1];
    if (preg_match('/^\s*[\d.]+\s+\d+\s+(\d+)\s+\d+\s+non-token data: nham/', $l, $m)) $nham = (int)$m[1];
}
if ($ls !== null && $lh !== null) $state['last'] = $now;
$state['run'] = $now;
$state['learned_spam'] = $ls;
$state['learned_ham'] = $lh;
$state['nspam'] = $nspam;
$state['nham'] = $nham;
Db::meta('bayes_state', json_encode($state));
$say(sprintf('Done. The Bayes database now holds %s spam and %s ham.', $nspam ?? '?', $nham ?? '?'));
