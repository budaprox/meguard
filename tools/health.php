<?php
// MEGuard – health watchdog. The "MEGuard - health" scheduled task runs it every 3 minutes.
//   - SpamAssassin (GTUBE test): if it is not running, restarted at the 2nd check; if it runs but is slow (e.g. disk
//       load from nightly backups), more patient: sa_slow_fails / busy_hours / busy_factor (config.php → health)
//   - MailEnable services and IPBan: an automatic-start service that has stopped is started
//   - backup: warns if the daily backup last succeeded more than 26 hours ago
//   - every action is logged (data\logs\health.log) and, if configured, e-mailed
// Manual run (test): tools\health.cmd

declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/lib/bootstrap.php';
require_once MEG_ROOT . '/lib/Spamd.php';
require_once MEG_ROOT . '/lib/Service.php';
require_once MEG_ROOT . '/lib/Tools.php';

$cfg = meg_config();
$hc = array_merge(['enabled' => true, 'spamd' => true, 'services' => true, 'alert_to' => '', 'alert_from' => '', 'smtp' => '127.0.0.1:25',
    'sa_timeout' => 60, 'sa_slow_fails' => 5, 'busy_hours' => '00:00-06:00', 'busy_factor' => 2], $cfg['health'] ?? []);
if (!$hc['enabled']) exit(0);
$isWin = stripos(PHP_OS, 'WIN') === 0;
$events = [];

function hlog(string $msg): void
{
    meg_ensure_dir(meg_data('logs'));
    file_put_contents(meg_data('logs', 'health.log'), date('Y-m-d H:i:s') . "\t" . $msg . "\n", FILE_APPEND);
    if (PHP_SAPI === 'cli') echo $msg, "\n";
}

/**
 * GTUBE test. Returns 'ok' | 'dead' (cannot connect: spamd is not running) | 'slow' (connects,
 * but does not answer in time – typically heavy disk load, e.g. during nightly backups)
 */
function sa_probe(array $sp, int $timeout): string
{
    $s = new Spamd($sp['host'], (int)$sp['port'], $timeout);
    $r = $s->check("Subject: test\r\n\r\nXJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X\r\n");
    if ($r['ok'] && $r['score'] >= 100) return 'ok';
    return (str_starts_with($r['error'], 'connect') || str_starts_with($r['error'], 'kapcsolódás')) ? 'dead' : 'slow';
}

/** are we inside the 'busy_hours' window (e.g. '00:00-06:00'; may span midnight: '22:00-05:00') */
function in_busy_hours(string $range): bool
{
    if (!preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', trim($range), $m)) return false;
    $now = (int)date('G') * 60 + (int)date('i');
    $a = (int)$m[1] * 60 + (int)$m[2];
    $b = (int)$m[3] * 60 + (int)$m[4];
    return $a <= $b ? ($now >= $a && $now < $b) : ($now >= $a || $now < $b);
}

/** sc.exe prints language-independent numbers: STATE 1=stopped 4=running; START_TYPE 2=automatic */
function svc_state(string $name): ?array
{
    exec('sc query "' . $name . '" 2>&1', $o, $rc);
    if ($rc !== 0 || !preg_match('/STATE\s*:\s*(\d+)/', implode("\n", $o), $m)) return null;
    exec('sc qc "' . $name . '" 2>&1', $q);
    preg_match('/START_TYPE\s*:\s*(\d+)/', implode("\n", $q), $t);
    return ['state' => (int)$m[1], 'start' => (int)($t[1] ?? 0)];
}

// ---- SpamAssassin -------------------------------------------------------------------
// Patient logic: if spamd is not running (cannot connect), act at the 2nd check;
// if it runs but is slow, act after sa_slow_fails slow checks in a row (default 5 × 3 min = about 15 min),
// within busy_hours (nightly backups) after busy_factor times that (default 2 × 15 = about 30 min).
if ($hc['spamd']) {
    $sp = $cfg['spamd'];
    $probe = sa_probe($sp, (int)$hc['sa_timeout']);
    if ($probe === 'ok') {
        $prev = (int)(Db::meta('health_sa_fail') ?? 0);
        if ($prev > 0) {
            Db::meta('health_sa_fail', '0');
            hlog("SpamAssassin answers again (after $prev failed checks, no action needed)");
        }
    } else {
        $fails = (int)(Db::meta('health_sa_fail') ?? 0) + 1;
        Db::meta('health_sa_fail', (string)$fails);
        $busy = in_busy_hours((string)$hc['busy_hours']);
        $need = $probe === 'dead' ? 2 : max(2, (int)$hc['sa_slow_fails'] * ($busy ? max(1, (int)$hc['busy_factor']) : 1));
        hlog('SpamAssassin ' . ($probe === 'dead' ? 'is not running (cannot connect)' : 'did not answer within ' . (int)$hc['sa_timeout'] . ' s')
            . " – $fails/$need" . ($busy && $probe === 'slow' ? ' (busy hours, more patient)' : ''));
        if ($fails >= $need && $isWin) {
            $task = Service::SPAMD_TASK;
            exec('schtasks /Query /TN "' . $task . '" /FO CSV /NH 2>&1', $o, $rc);
            $running = $rc === 0 && preg_match('/Running|Fut/i', implode(' ', $o));
            if ($fails >= $need + 2) {
                // the quick restart did not help: restart the whole watchdog task
                exec('schtasks /End /TN "' . $task . '" 2>&1');
                exec('taskkill /F /IM spamd.exe 2>&1');
                sleep(3);
                exec('schtasks /Run /TN "' . $task . '" 2>&1');
                hlog('full restart of the watchdog task');
            } elseif ($running) {
                exec('taskkill /F /IM spamd.exe 2>&1');
                hlog('spamd stopped, its watchdog restarts it within 10 s');
            } else {
                exec('schtasks /Run /TN "' . $task . '" 2>&1');
                hlog('the watchdog task was not running – started');
            }
            // spamd loads its rules at startup: slower under load, so wait generously
            $ok = false;
            $deadline = time() + ($busy ? 240 : 120);
            sleep(15);
            while (time() < $deadline) {
                if (sa_probe($sp, (int)$hc['sa_timeout']) === 'ok') { $ok = true; break; }
                sleep(10);
            }
            if ($ok) {
                Db::meta('health_sa_fail', '0');
                Db::meta('spamd_restarted', (string)time());
                hlog('SpamAssassin works again');
                $events[] = 'SpamAssassin ' . ($probe === 'dead' ? 'had stopped' : "did not answer $fails checks in a row") . '; MEGuard restarted it automatically and it works again.';
            } else {
                hlog('SpamAssassin still does not work after the restart');
                $events[] = "SpamAssassin is not working and the automatic restart did not help (failed check #$fails). Until it is fixed, mail passes without a SpamAssassin check. See data\\logs\\spamd.log";
            }
        }
    }
}

// ---- services ---------------------------------------------------------------------
if ($hc['services'] && $isWin) {
    foreach (array_merge(Tools::MAIL_SET, ['MELCS', 'MEHTTPS', 'IPBan']) as $name) {
        $s = svc_state($name);
        if (!$s || $s['start'] !== 2 || $s['state'] !== 1) continue;   // automatic start type and stopped only
        hlog("$name stopped – starting it");
        exec('net start "' . $name . '" 2>&1', $o, $rc);
        sleep(2);
        $s2 = svc_state($name);
        $ok = $s2 && $s2['state'] === 4;
        hlog($ok ? "$name started" : "$name could not be started: " . trim(implode(' ', $o)));
        $events[] = $ok ? "The $name service had stopped; MEGuard restarted it." : "The $name service has stopped and could not be started!";
    }
}

// ---- backup ----------------------------------------------------------------------------
// If daily backups are set up (there was a successful backup) but the last success is older
// than 26 hours, warn once a day.
$bl = (int)(Db::meta('backup_last') ?? 0);
if ($bl > 0 && time() - $bl > 26 * 3600) {
    $warned = (int)(Db::meta('health_backup_warn') ?? 0);
    if (time() - $warned > 86400) {
        Db::meta('health_backup_warn', (string)time());
        $h = (int)round((time() - $bl) / 3600);
        hlog("the MEGuard backup has not completed for $h hours");
        $events[] = "The daily MEGuard backup has not completed for $h hours (last success: " . date('Y-m-d H:i', $bl) . "). Check data\\logs\\backup.log and the \"MEGuard - backup\" scheduled task.";
    }
}

// ---- alert ----------------------------------------------------------------------------
if ($events && $hc['alert_to'] !== '') {
    $last = (int)(Db::meta('health_mail_last') ?? 0);
    if (time() - $last > 1800) {   // at most one e-mail every half hour
        Db::meta('health_mail_last', (string)time());
        $err = send_alert($hc, $cfg['server_name'], $events);
        hlog($err === '' ? 'alert e-mail sent to ' . $hc['alert_to'] : 'alert e-mail error: ' . $err);
    }
}
exit(0);

/** simple SMTP delivery to the local mail server (no external dependencies) */
function send_alert(array $hc, string $server, array $events): string
{
    [$host, $port] = array_pad(explode(':', $hc['smtp'], 2), 2, '25');
    $from = $hc['alert_from'] ?: $hc['alert_to'];
    $fp = @stream_socket_client("tcp://$host:$port", $en, $es, 10);
    if (!$fp) return "connect: $es";
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string { $r = ''; while (($l = fgets($fp)) !== false) { $r .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $r; };
    $cmd = function (string $c, string $want) use ($fp, $read): void {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!str_starts_with($r, $want)) throw new RuntimeException(trim($c . ' → ' . $r));
    };
    try {
        $cmd('', '220');
        $cmd('EHLO ' . gethostname(), '250');
        $cmd("MAIL FROM:<$from>", '250');
        foreach (array_map('trim', explode(',', $hc['alert_to'])) as $to) $cmd("RCPT TO:<$to>", '25');
        $cmd('DATA', '354');
        $subj = '=?UTF-8?B?' . base64_encode("MEGuard ($server): automatic action taken") . '?=';
        $body = "MEGuard – $server – " . date('Y-m-d H:i') . "\r\n\r\n" . implode("\r\n\r\n", $events) . "\r\n\r\nDetails: data\\logs\\health.log\r\n";
        $msg = "From: MEGuard <$from>\r\nTo: {$hc['alert_to']}\r\nSubject: $subj\r\nDate: " . date('r') . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nX-MEGuard-Alert: 1\r\n\r\n"
             . preg_replace('/^\./m', '..', $body);
        fwrite($fp, $msg . "\r\n.\r\n");
        $cmd('', '250');
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return '';
    } catch (Throwable $e) {
        @fclose($fp);
        return $e->getMessage();
    }
}
