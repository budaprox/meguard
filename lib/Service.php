<?php
// MEGuard – restarts SpamAssassin (spamd) from the web interface

declare(strict_types=1);

require_once __DIR__ . '/Spamd.php';

final class Service
{
    public const SPAMD_TASK = 'MEGuard - SpamAssassin spamd';

    /** @return array{ok:bool, message:string} */
    public static function restartSpamd(): array
    {
        if (!function_exists('exec')) return ['ok' => false, 'message' => 'PHP exec() is disabled.'];
        if (stripos(PHP_OS, 'WIN') !== 0) return ['ok' => false, 'message' => 'Works on Windows servers only.'];
        @set_time_limit(90);
        meg_errlog('spamd restart from the web interface (' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ')');

        $task = self::SPAMD_TASK;
        exec('schtasks /Query /TN "' . $task . '" /FO CSV /NH 2>&1', $out, $rc);
        if ($rc !== 0) return ['ok' => false, 'message' => 'Not found in Task Scheduler: "' . $task . '". Run tools\\install-spamd.cmd.'];
        $running = stripos(implode(' ', $out), 'Running') !== false || stripos(implode(' ', $out), 'Fut') !== false;

        // stop the running spamd; if its watchdog runs, it restarts it within 10 s
        // (taskkill: 0 = stopped, 128 = was not running; anything else is usually a permission problem)
        exec('taskkill /F /IM spamd.exe 2>&1', $ko, $krc);
        if ($krc !== 0 && $krc !== 128) {
            return ['ok' => false, 'message' => self::permHint(implode(' ', $ko))];
        }
        if (!$running) {
            exec('schtasks /Run /TN "' . $task . '" 2>&1', $o2, $rc2);
            if ($rc2 !== 0) return ['ok' => false, 'message' => self::permHint(implode(' ', $o2))];
        }

        $cfg = meg_config()['spamd'];
        $s = new Spamd($cfg['host'], (int)$cfg['port'], (int)$cfg['timeout']);
        $deadline = time() + 45;
        sleep(3);
        while (time() < $deadline) {
            if ($s->ping()) {
                $r = $s->check("Subject: test\r\n\r\nXJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X\r\n");
                if ($r['ok'] && $r['score'] >= 100) {
                    Db::meta('spamd_restarted', (string)time());
                    return ['ok' => true, 'message' => ''];
                }
                return ['ok' => false, 'message' => 'spamd started but the test scan failed: ' . ($r['error'] ?: 'score ' . $r['score'])];
            }
            sleep(2);
        }
        return ['ok' => false, 'message' => 'spamd did not start within 45 seconds. See data\\logs\\spamd.log.'];
    }

    /** Explains a permission error: the web interface probably does not run as a service account. */
    private static function permHint(string $detail): string
    {
        $who = trim((string)@exec('whoami'));
        return t('alert.sa_perm', ['user' => $who !== '' ? $who : '?']) . ($detail !== '' ? ' (' . mb_substr(trim($detail), 0, 160) . ')' : '');
    }
}
