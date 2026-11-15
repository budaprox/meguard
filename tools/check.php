<?php
// MEGuard – installation check
// Usage: php tools\check.php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/Spamd.php';
require dirname(__DIR__) . '/lib/Dnsbl.php';

$ok = true;
function line(bool $good, string $what, string $info = ''): void
{
    global $ok;
    if (!$good) $ok = false;
    printf("  [%s] %s%s\n", $good ? ' OK ' : 'FAIL', $what, $info !== '' ? "  – $info" : '');
}
function warn(string $what, string $info = ''): void
{
    printf("  [WARN] %s%s\n", $what, $info !== '' ? "  – $info" : '');
}

$cfg = meg_config();
echo "MEGuard " . MEG_VERSION . " – installation check\n\n";
echo "Settings: " . (is_file(MEG_ROOT . '/config.php') ? 'config.php' : 'config.sample.php (no config.php yet!)') . "\n\n";

echo "PHP\n";
line(PHP_VERSION_ID >= 80100, 'PHP version', PHP_VERSION);
foreach (['pdo_sqlite', 'mbstring', 'iconv'] as $ext) line(extension_loaded($ext), "extension: $ext");

echo "\nMailEnable\n";
line(is_dir($cfg['mailenable_root']), 'MailEnable folder', $cfg['mailenable_root']);
$in = meg_path($cfg['queues_dir'], 'SMTP', 'Inbound', 'Messages');
line(is_dir($in), 'SMTP inbound queue', $in);

echo "\nData folder\n";
meg_ensure_dir(meg_data());
$probe = meg_data('write-test.tmp');
line(@file_put_contents($probe, 'x') === 1, 'writable', meg_data());
@unlink($probe);
try { Db::get(); line(true, 'database'); } catch (Throwable $e) { line(false, 'database', $e->getMessage()); }
$docroot = realpath(meg_data());
if ($docroot && preg_match('#\\\\(htdocs|www)\\\\#i', $docroot)) warn('The data folder is inside a web folder – this is not safe!');

echo "\nSpamAssassin (spamd)\n";
$sp = $cfg['spamd'];
if (empty($sp['enabled'])) warn('disabled in the settings');
else {
    $s = new Spamd($sp['host'], (int)$sp['port'], (int)$sp['timeout']);
    $p = $s->ping();
    line($p, "spamd reachable", "{$sp['host']}:{$sp['port']}");
    if ($p) {
        $gtube = "Subject: test\r\n\r\nXJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X\r\n";
        $r = $s->check($gtube);
        line($r['ok'] && $r['score'] >= 100, 'test spam detected (GTUBE)', $r['ok'] ? 'score: ' . $r['score'] : $r['error']);
    }
}

echo "\nDNS blocklists\n";
$a = Dnsbl::lookupA('2.0.0.127.zen.spamhaus.org');
if (!$a) line(false, 'Spamhaus test', 'no answer');
elseif (str_starts_with($a[0], '127.255.255.')) line(false, 'Spamhaus test', 'Spamhaus refuses queries from your DNS server (' . $a[0] . ') – use your own resolver');
else line(true, 'Spamhaus test', implode(',', $a));
$u = Dnsbl::lookupA('test.uribl.com.multi.uribl.com');
if (!$u) warn('URIBL test', 'no answer');
elseif (in_array('127.0.0.1', $u, true)) warn('URIBL test', 'uribl.com blocks your DNS server (127.0.0.1) – the SpamAssassin URIBL rules will not work');
else line(true, 'URIBL test', implode(',', $u));

echo "\nCountry filter\n";
require_once MEG_ROOT . '/lib/Geo.php';
$gc = Geo::load(); $gs = Geo::status();
if (!$gc['enabled']) echo "  off (web interface → Country filter)\n";
elseif (!$gs) line(false, 'country filter', 'has not run yet – tools\\geo-update.cmd -Install');
elseif (empty($gs['ok'])) line(false, 'country filter', (string)($gs['message'] ?? ''));
else line(true, 'country filter active', ($gs['rules'] ?? 0) . ' rules, ' . ($gs['blocks'] ?? 0) . ' blocked ranges, last run: ' . ($gs['time'] ?? '?'));

echo "\nHealth watchdog\n";
if (stripos(PHP_OS, 'WIN') === 0) {
    exec('schtasks /Query /TN "MEGuard - health" 2>&1', $ho, $hrc);
    if ($hrc !== 0) warn('not installed', 'run as administrator: tools\\install-health.cmd');
    else line(true, 'scheduled task: MEGuard - health');
}
if (($cfg['health']['alert_to'] ?? '') === '') warn('no alert address', "config.php → 'health' => ['alert_to' => 'you@example.com']");
else line(true, 'alerts go to: ' . $cfg['health']['alert_to']);

echo "\nWeb interface\n";
echo "  PHP: " . PHP_BINARY . "\n";
$webTask = false;
if (stripos(PHP_OS, 'WIN') === 0) { exec('schtasks /Query /TN "MEGuard - web" 2>&1', $wo, $wrc); $webTask = ($wrc === 0); }
if ($webTask) {
    // proxy mode (Apache mod_proxy / IIS ARR): MEGuard's own PHP serves the UI on 127.0.0.1:8099
    $fp = @fsockopen('127.0.0.1', 8099, $e1, $e2, 3);
    line((bool)$fp, 'MEGuard web service (127.0.0.1:8099)', $fp ? '' : 'not running – Task Scheduler: "MEGuard - web"');
    if ($fp) fclose($fp);
}

if (($cfg['web']['password_hash'] ?? '') === '') warn('no password set', 'run: tools\\setpass.cmd');
else line(true, 'password set');
if (empty($cfg['web']['allowed_ips'])) warn('no IP restriction (allowed_ips)');

echo "\n" . ($ok ? "All good.\n" : "Something needs fixing (see above).\n");
