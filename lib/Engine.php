<?php
// MEGuard – the filter's decision logic

declare(strict_types=1);

require_once __DIR__ . '/Message.php';
require_once __DIR__ . '/Rules.php';
require_once __DIR__ . '/Spamd.php';
require_once __DIR__ . '/Dnsbl.php';
require_once __DIR__ . '/GeoIp.php';
require_once __DIR__ . '/Geo.php';
require_once __DIR__ . '/Quarantine.php';

final class Engine
{
    public const STATUS_HEADER = 'X-MEGuard-Status';

    private array $cfg;
    private PDO $db;

    public function __construct()
    {
        $this->cfg = meg_config();
        $this->db = Db::get();
    }

    /**
     * Processes one message. Never throws: on any error the message passes through untouched.
     */
    public function process(string $file, string $connector): void
    {
        $t0 = microtime(true);
        $log = [
            'ts' => time(), 'msgfile' => basename($file), 'connector' => $connector,
            'result' => 'error', 'action' => 'pass', 'score' => null, 'sa_score' => null,
            'details' => [],
        ];
        try {
            if (!in_array(strtoupper($connector), array_map('strtoupper', $this->cfg['connectors']), true)) {
                return; // other connector (e.g. SF, LS): not handled and not logged
            }
            $m = Message::locate($this->cfg['queues_dir'], $connector, $file);
            if (!$m) {
                $log['result'] = 'skipped';
                $log['details']['note'] = 'message file not found';
                $this->write($log, $t0);
                return;
            }
            $m->load();
            $this->diagnostic($m);
            $this->evaluate($m, $log);
        } catch (Throwable $e) {
            $log['result'] = 'error';
            $log['action'] = 'pass';
            $log['details']['error'] = $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
            meg_errlog('ERROR ' . $file . ': ' . $log['details']['error']);
        }
        $this->write($log, $t0);
    }

    private function evaluate(Message $m, array &$log): void
    {
        $cfg = $this->cfg;
        $ip = $m->clientIp();
        $sender = $m->sender();
        $from = $m->fromAddress();
        $rcpts = $m->recipients();
        $subject = $m->subject();

        $log += [];
        $log['ip'] = $ip;
        $log['auth'] = $m->isAuthenticated() ? 1 : 0;
        $log['account'] = $m->authUser() ?: ($m->cmd['Account'] ?? '');
        $log['sender'] = $sender;
        $log['from_hdr'] = mb_substr($m->fromHeader(), 0, 300);
        $log['recipients'] = implode(', ', $rcpts);
        $log['subject'] = mb_substr($subject, 0, 500);
        $log['size'] = $m->size;

        // Already scanned (e.g. released from quarantine)
        $status = $m->header(self::STATUS_HEADER);
        if ($status !== null) {
            $log['result'] = str_starts_with(strtolower($status), 'released') ? 'released' : 'rescanned';
            $log['action'] = 'pass';
            return;
        }

        // Outgoing message (authenticated user)
        if ($log['auth'] && empty($cfg['scan_authenticated'])) {
            $log['result'] = 'outgoing';
            $log['action'] = 'pass';
            return;
        }

        // Trusted IP (local web mail etc.)
        foreach ($cfg['trusted_ips'] as $t) {
            if (Rules::ipMatches($ip, $t)) {
                $log['result'] = 'trusted';
                $log['action'] = 'pass';
                return;
            }
        }

        $rules = new Rules($this->db);
        $senders = array_values(array_unique(array_filter([$sender, $from])));

        // Whitelist – sender-based whitelisting can be forged, so it does not apply when SPF fails
        $w = $rules->findList('white', $ip, $senders, $rcpts);
        if ($w && $w[2] === 'sender') {
            $spf = strtolower(trim($m->header('received-spf') ?? ''));
            if (preg_match('/^(fail|softfail)\b/', $spf)) {
                $log['details']['whitelist_ignored'] = $w[1] . ' (SPF ' . strtok($spf, ' ') . ')';
                $w = null;
            }
        }
        if ($w) {
            $rules->countListHit($w[0]);
            $log['result'] = 'whitelisted';
            $log['details']['list'] = ['kind' => $w[2], 'value' => $w[1]];
            $this->apply($m, $log, 'pass', null, ['X-MEGuard-Status: whitelisted']);
            return;
        }
        // Feketelista
        if ($b = $rules->findList('black', $ip, $senders, $rcpts)) {
            $rules->countListHit($b[0]);
            $auto = !empty($b[3]);
            $log['result'] = $auto ? 'autoban' : 'blacklisted';
            $log['details']['list'] = ['kind' => $b[2], 'value' => $b[1]] + ($auto ? ['auto' => true] : []);
            $act = $cfg['actions']['blacklist'] ?? 'quarantine';
            $this->apply($m, $log, $act, $cfg['tag_subject']['blacklist'] ?? '[SPAM]',
                ['X-MEGuard-Status: ' . ($auto ? 'blacklisted (auto)' : 'blacklisted (' . $b[2] . ')')]);
            return;
        }

        // Scoring
        $score = 0.0;
        $ctx = [
            'subject' => $subject, 'headers' => $m->decodedHeaders(),
            'sender' => $sender, 'from' => $from, 'recipients' => $rcpts,
        ];
        $kw = $rules->scoreKeywords($m, $ctx);
        $rules->countRuleHits($kw['hits']);
        $score += $kw['score'];
        $log['details']['keywords'] = $kw['hits'];

        $dn = Dnsbl::check($ip, $cfg['dnsbl'] ?? []);
        $score += $dn['score'];
        $log['details']['dnsbl'] = $dn['hits'];
        if ($dn['errors']) $log['details']['dnsbl_errors'] = $dn['errors'];

        // Country score: the country of the sending server (not of the sender – Gmail/Outlook mail
        // comes from the providers' US/European servers)
        if ($co = $this->countryScore($ip)) {
            $score += $co['score'];
            $log['details']['country'] = $co;
        }

        $sp = $cfg['spamd'];
        if (!empty($sp['enabled'])) {
            if ($m->size <= (int)$sp['max_size']) {
                $raw = self::fixReceived((string)file_get_contents($m->msgPath), $ip, $cfg['server_name']);
                $r = (new Spamd($sp['host'], (int)$sp['port'], (int)$sp['timeout']))->check($raw);
                if ($r['ok']) {
                    $score += $r['score'];
                    $log['sa_score'] = $r['score'];
                    $log['details']['sa_symbols'] = $r['symbols'];
                } else {
                    $log['details']['sa_error'] = $r['error'];
                }
            } else {
                $log['details']['sa_skipped'] = 'size';
            }
        }

        $score = round($score, 2);
        $log['score'] = $score;

        // Repeat sender: many suspicious messages from one IP, with random senders or the same subject to many recipients
        if ($burst = $this->burst($ip, $score, $sender ?: $from, $rcpts, $subject)) {
            $id = $rules->autoBlacklistIp($ip, $burst['comment'], (int)($cfg['burst']['ban_days'] ?? 30));
            $rules->countListHit($id);
            $log['result'] = 'autoban';
            $log['details']['list'] = ['kind' => 'ip', 'value' => $ip, 'auto' => true];
            $log['details']['burst'] = $burst;
            $act = $cfg['actions']['blacklist'] ?? 'quarantine';
            $this->apply($m, $log, $act, $cfg['tag_subject']['blacklist'] ?? '[SPAM]',
                ['X-MEGuard-Status: blacklisted (auto)', sprintf('X-MEGuard-Score: %.1f', $score)]);
            return;
        }
        $th = $cfg['thresholds'];
        $level = null;
        if ($score >= $th['high']) $level = 'high';
        elseif ($score >= $th['medium']) $level = 'medium';
        elseif ($score >= $th['low']) $level = 'low';

        $hdr = sprintf('X-MEGuard-Score: %.1f', $score);
        if ($level === null) {
            $log['result'] = 'pass';
            $this->apply($m, $log, 'pass', null, ['X-MEGuard-Status: clean', $hdr]);
            return;
        }
        $log['result'] = 'spam-' . $level;
        $this->apply($m, $log, $cfg['actions'][$level] ?? 'tag', $cfg['tag_subject'][$level] ?? '[SPAM?]',
            ['X-MEGuard-Status: spam-' . $level, $hdr]);
    }

    /**
     * @return array{cc:string, score:float, kind:string}|null
     *     kind: 'allowed' (allowed country), 'risk' (high-risk spam source), 'other' (other, not allowed)
     */
    private function countryScore(string $ip): ?array
    {
        $c = ($this->cfg['country_score'] ?? []) + ['enabled' => false];
        if (empty($c['enabled']) || !Dnsbl::isPublicIp($ip)) return null;
        $cc = GeoIp::country($ip);
        if ($cc === null) return null;
        $allow = $c['allow'] ?? null;
        if (!is_array($allow) || !$allow) $allow = Geo::load()['countries'];   // the Country filter page's list
        $allow = array_map('strtoupper', $allow);
        if (in_array($cc, $allow, true)) return ['cc' => $cc, 'score' => 0.0, 'kind' => 'allowed'];
        $risk = array_map('strtoupper', (array)($c['risk_countries'] ?? []));
        if (in_array($cc, $risk, true)) return ['cc' => $cc, 'score' => (float)($c['risk_score'] ?? 10), 'kind' => 'risk'];
        return ['cc' => $cc, 'score' => (float)($c['other_score'] ?? 8), 'kind' => 'other'];
    }

    /**
     * Detects a "repeat sender" from the last hours of the log (including the current message).
     * @return array|null the reason, if the IP must be blocked automatically
     */
    private function burst(string $ip, float $score, string $sender, array $rcpts, string $subject): ?array
    {
        $b = ($this->cfg['burst'] ?? []) + ['enabled' => true, 'window_hours' => 24, 'min_messages' => 5, 'min_score' => 3.0,
            'min_senders' => 3, 'min_recipients' => 8, 'subject_min_score' => 5.0, 'ban_days' => 30];
        if (empty($b['enabled']) || $score < (float)$b['min_score'] || !Dnsbl::isPublicIp($ip)) return null;
        $st = $this->db->prepare("SELECT sender, recipients, subject FROM messages
            WHERE ip = ? AND ts >= ? AND score IS NOT NULL AND score >= ? AND auth = 0
              AND result NOT IN ('whitelisted', 'trusted', 'outgoing', 'released', 'rescanned')");
        $st->execute([$ip, time() - 3600 * (int)$b['window_hours'], (float)$b['min_score']]);
        $rows = $st->fetchAll();
        $rows[] = ['sender' => $sender, 'recipients' => implode(', ', $rcpts), 'subject' => $subject];
        $n = count($rows);
        if ($n < (int)$b['min_messages']) return null;
        $senders = [];
        $bySubj = [];
        foreach ($rows as $r) {
            $senders[strtolower(trim((string)$r['sender']))] = 1;
            $subj = mb_strtolower(trim((string)$r['subject']));
            foreach (preg_split('/\s*,\s*/', strtolower((string)$r['recipients']), -1, PREG_SPLIT_NO_EMPTY) as $rc) $bySubj[$subj][$rc] = 1;
        }
        $ns = count($senders);
        $nr = $bySubj ? max(array_map('count', $bySubj)) : 0;
        // 1. strong signal: many different (random) sender addresses from the same IP
        $bySenders = $ns >= (int)$b['min_senders'];
        // 2. same subject to many recipients – a legitimate newsletter can look like this, so a higher score is required
        $bySubject = $nr >= (int)$b['min_recipients'] && $score >= (float)$b['subject_min_score'];
        if (!$bySenders && !$bySubject) return null;
        $hours = (int)$b['window_hours'];
        return [
            'messages' => $n, 'senders' => $ns, 'recipients_same_subject' => $nr, 'hours' => $hours,
            'comment' => sprintf('auto: %d messages in %d h, %d different senders, same subject to %d recipients (%s)',
                $n, $hours, $ns, $nr, date('Y-m-d H:i')),
        ];
    }

    /** Carries out the decision */
    private function apply(Message $m, array &$log, string $action, ?string $prefix, array $headers): void
    {
        $cfg = $this->cfg;
        if (($cfg['mode'] ?? 'live') !== 'live') {
            $log['action'] = 'monitor';
            $log['details']['would'] = $action;
            return;
        }
        $headers[0] .= '; ' . $cfg['server_name'] . '; ' . date('Y-m-d H:i:s');
        switch ($action) {
            case 'delete':
                Quarantine::spool('spam', $m->msgPath);   // for Bayes training
                @unlink($m->msgPath);
                @unlink($m->cmdPath);
                $log['action'] = 'delete';
                return;
            case 'quarantine':
                $log['qpath'] = Quarantine::put($m);
                $log['action'] = 'quarantine';
                return;
            case 'tag':
                $add = $headers;
                if (!empty($cfg['junk_header'])) $add[] = $cfg['junk_header'];
                $m->rewrite($add, $prefix);
                $log['action'] = 'tag';
                return;
            default:
                $m->rewrite($headers, null);
                $log['action'] = 'pass';
        }
    }

    /**
     * On receipt MailEnable puts this at the top of the message: "Received: from ([127.0.0.1]) with MailEnable ...".
     * SpamAssassin would conclude that the message came from the local machine (ALL_TRUSTED) and skip
     * the IP-based checks. In the COPY sent to SpamAssassin this line is rewritten with the real
     * sending IP. The delivered message is not changed.
     */
    public static function fixReceived(string $raw, string $ip, string $server): string
    {
        if (!Dnsbl::isPublicIp($ip)) return $raw;
        $hdrEnd = strpos($raw, "\r\n\r\n");
        if ($hdrEnd === false) $hdrEnd = strlen($raw);
        $head = substr($raw, 0, $hdrEnd);
        if (!preg_match('/^Received: from \(\[127\.0\.0\.1\]\) with MailEnable([^\r\n]*)/mi', $head, $mm, PREG_OFFSET_CAPTURE)) {
            return $raw;
        }
        $rdns = @gethostbyaddr($ip);
        $host = ($rdns && $rdns !== $ip) ? $rdns : 'unknown';
        $server = preg_replace('/[^A-Za-z0-9.\-]/', '', $server) ?: 'localhost';
        $line = 'Received: from ' . $host . ' (' . ($host === 'unknown' ? '' : $host . ' ') . '[' . $ip . ']) by ' . $server . ' with MailEnable' . $mm[1][0];
        return substr($raw, 0, $mm[0][1]) . $line . substr($raw, $mm[0][1] + strlen($mm[0][0]));
    }

    private function diagnostic(Message $m): void
    {
        $n = (int)($this->cfg['diagnostic_capture'] ?? 0);
        if ($n <= 0) return;
        $dir = meg_data('diag');
        meg_ensure_dir($dir);
        $have = count(glob(meg_path($dir, '*.cmd.txt')) ?: []);
        if ($have >= $n) return;
        @copy($m->cmdPath, meg_path($dir, $m->id . '.cmd.txt'));
        @file_put_contents(meg_path($dir, $m->id . '.headers.txt'), $m->headerBlock);
    }

    private function write(array $log, float $t0): void
    {
        try {
            $st = $this->db->prepare('INSERT INTO messages
                (ts, msgfile, connector, ip, auth, account, sender, from_hdr, recipients, subject, size, result, action, score, sa_score, details, duration_ms, qpath)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([
                $log['ts'], $log['msgfile'], $log['connector'], $log['ip'] ?? '', $log['auth'] ?? 0, $log['account'] ?? '',
                $log['sender'] ?? '', $log['from_hdr'] ?? '', $log['recipients'] ?? '', $log['subject'] ?? '', $log['size'] ?? 0,
                $log['result'], $log['action'], $log['score'], $log['sa_score'],
                json_encode($log['details'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                (int)round((microtime(true) - $t0) * 1000), $log['qpath'] ?? null,
            ]);
        } catch (Throwable $e) {
            meg_errlog('LOG ERROR ' . $log['msgfile'] . ': ' . $e->getMessage());
        }
    }
}
