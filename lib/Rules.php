<?php
// MEGuard – whitelists/blacklists and keyword rules

declare(strict_types=1);

final class Rules
{
    private array $lists = [];   // [list][kind] => [ [id, value], ... ]
    private array $rules = [];

    public function __construct(private PDO $db)
    {
        $st = $db->prepare('SELECT id, list, kind, value, auto FROM lists WHERE expires IS NULL OR expires > ?');
        $st->execute([time()]);
        foreach ($st as $r) {
            $this->lists[$r['list']][$r['kind']][] = [(int)$r['id'], $r['value'], (int)($r['auto'] ?? 0)];
        }
        $this->rules = $db->query('SELECT * FROM rules WHERE enabled = 1 ORDER BY id')->fetchAll();
    }

    // ---- Address patterns -------------------------------------------------------------

    /**
     * Patterns (compatible with MxScan):
     *     someone@example.com   exact address
     *     example.com           the domain and its subdomains
     *     *@example.com         every address of the domain
     *     *.gov.uk              any subdomain
     *     *yandex.com           anything ending like this
     */
    public static function addrMatches(string $addr, string $pattern): bool
    {
        $addr = strtolower(trim($addr));
        $p = strtolower(trim($pattern));
        if ($addr === '' || $p === '') return false;
        $p = str_replace('(@)', '@', $p);
        $domain = substr(strrchr($addr, '@') ?: '', 1);

        if (!str_contains($p, '*') && !str_contains($p, '?')) {
            if ($addr === $p) return true;
            if (!str_contains($p, '@')) {
                $p = ltrim($p, '@.');
                return $domain === $p || str_ends_with($domain, '.' . $p);
            }
            return false;
        }
        $re = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($p, '/')) . '$/';
        if (preg_match($re, $addr)) return true;
        // *.gov.hu -> a domainre is
        if (!str_contains($p, '@') && $domain !== '' && preg_match($re, $domain)) return true;
        return false;
    }

    public static function ipMatches(string $ip, string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($ip === '' || $pattern === '') return false;
        if (str_contains($pattern, '/')) {
            [$net, $bits] = explode('/', $pattern, 2);
            $ipb = @inet_pton($ip); $netb = @inet_pton(trim($net));
            if ($ipb === false || $netb === false || strlen($ipb) !== strlen($netb)) return false;
            $bits = (int)$bits;
            $bytes = intdiv($bits, 8); $rem = $bits % 8;
            if (strncmp($ipb, $netb, $bytes) !== 0) return false;
            if ($rem === 0) return true;
            $mask = chr((0xFF << (8 - $rem)) & 0xFF);
            return (($ipb[$bytes] & $mask) === ($netb[$bytes] & $mask));
        }
        if (str_contains($pattern, '*')) {
            $re = '/^' . str_replace('\*', '[0-9a-f:.]*', preg_quote($pattern, '/')) . '$/i';
            return (bool)preg_match($re, $ip);
        }
        return $ip === $pattern;
    }

    /** @return array|null [id, value, kind, auto?] the first match (auto = automatic "repeat sender" block) */
    public function findList(string $list, string $ip, array $senders, array $recipients): ?array
    {
        foreach ($this->lists[$list]['ip'] ?? [] as [$id, $v, $auto]) {
            if (self::ipMatches($ip, $v)) return [$id, $v, 'ip', $auto];
        }
        foreach ($this->lists[$list]['sender'] ?? [] as [$id, $v]) {
            foreach ($senders as $s) if (self::addrMatches($s, $v)) return [$id, $v, 'sender'];
        }
        foreach ($this->lists[$list]['recipient'] ?? [] as [$id, $v]) {
            foreach ($recipients as $r) if (self::addrMatches($r, $v)) return [$id, $v, 'recipient'];
        }
        return null;
    }

    /** Automatic, expiring blacklist entry (IP); an existing one is only extended. */
    public function autoBlacklistIp(string $ip, string $comment, int $days): int
    {
        $exp = time() + 86400 * max(1, $days);
        $st = $this->db->prepare("SELECT id, auto FROM lists WHERE list = 'black' AND kind = 'ip' AND value = ?");
        $st->execute([$ip]);
        if ($r = $st->fetch()) {
            if ((int)$r['auto']) $this->db->prepare('UPDATE lists SET expires = ?, comment = ? WHERE id = ?')->execute([$exp, $comment, $r['id']]);
            return (int)$r['id'];
        }
        $this->db->prepare("INSERT INTO lists (list, kind, value, comment, created, expires, auto) VALUES ('black', 'ip', ?, ?, ?, ?, 1)")
            ->execute([$ip, $comment, time(), $exp]);
        return (int)$this->db->lastInsertId();
    }

    public function countListHit(int $id): void
    {
        try {
            $this->db->prepare('UPDATE lists SET hits = hits + 1, last_hit = ? WHERE id = ?')->execute([time(), $id]);
        } catch (Throwable) { /* not critical */ }
    }

    // ---- Kulcsszavak --------------------------------------------------------

    public static function fold(string $s): string
    {
        // lower case without accents (hírlevél -> hirlevel)
        $s = mb_strtolower($s, 'UTF-8');
        if (class_exists('Normalizer')) {
            $n = Normalizer::normalize($s, Normalizer::FORM_D);
            if (is_string($n)) return (string)preg_replace('/\p{Mn}+/u', '', $n);
        }
        return strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ą' => 'a', 'ć' => 'c', 'č' => 'c', 'ç' => 'c', 'ď' => 'd', 'ę' => 'e',
            'ě' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ł' => 'l', 'ľ' => 'l', 'ĺ' => 'l',
            'ń' => 'n', 'ň' => 'n', 'ñ' => 'n', 'ô' => 'o', 'õ' => 'o', 'ř' => 'r', 'ŕ' => 'r', 'ś' => 's', 'š' => 's',
            'ş' => 's', 'ť' => 't', 'ů' => 'u', 'û' => 'u', 'ý' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z', 'ß' => 'ss',
        ]);
    }

    /**
     * @return array{score: float, hits: array<int, array{name:string, score:float}>}
     */
    public function scoreKeywords(Message $m, array $ctx): array
    {
        $texts = [
            'subject' => $ctx['subject'],
            'headers' => $ctx['headers'],
            'body'    => null, // lazy loading
        ];
        $folded = [];
        $get = function (string $scope) use (&$texts, &$folded, $m): array {
            if ($scope === 'body' && $texts['body'] === null) $texts['body'] = $m->bodyText();
            if ($scope === 'all') {
                return [$this->getAll($texts, $m), null];
            }
            $t = $texts[$scope] ?? '';
            if (!isset($folded[$scope])) $folded[$scope] = self::fold($t);
            return [$t, $folded[$scope]];
        };

        $total = 0.0; $hits = [];
        foreach ($this->rules as $r) {
            $hit = false;
            try {
                if ($r['type'] === 'special') {
                    $hit = $this->special($r['expr'], $ctx, $m);
                } else {
                    [$text, $f] = $get($r['scope']);
                    if ($f === null) $f = self::fold($text);
                    if ($r['type'] === 'regex') {
                        $re = '~' . str_replace('~', '\~', $r['expr']) . '~iu';
                        $hit = @preg_match($re, $text) === 1 || @preg_match($re, $f) === 1;
                    } else {
                        foreach (preg_split('/\r\n|\n|\r/', $r['expr']) as $alt) {
                            $alt = trim($alt);
                            if ($alt === '') continue;
                            if (mb_stripos($text, $alt) !== false || str_contains($f, self::fold($alt))) { $hit = true; break; }
                        }
                    }
                }
            } catch (Throwable) { $hit = false; }
            if ($hit) {
                $total += (float)$r['score'];
                $hits[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'score' => (float)$r['score']];
            }
        }
        return ['score' => $total, 'hits' => $hits];
    }

    private function getAll(array &$texts, Message $m): string
    {
        if ($texts['body'] === null) $texts['body'] = $m->bodyText();
        return $texts['headers'] . "\n" . $texts['body'];
    }

    private function special(string $what, array $ctx, Message $m): bool
    {
        switch (strtoupper($what)) {
            case 'BLANK_FROM_ADDRESS':   return $ctx['from'] === '' && $ctx['sender'] === '';
            case 'BLANK_SUBJECT':        return trim($ctx['subject']) === '';
            case 'BLANK_BODY':           return trim($m->bodyText()) === '';
            case 'SAME_SENDER_RECIPIENT':
                $s = $ctx['from'] ?: $ctx['sender'];
                return $s !== '' && count($ctx['recipients']) === 1 && $ctx['recipients'][0] === $s;
        }
        return false;
    }

    public function countRuleHits(array $hits): void
    {
        if (!$hits) return;
        try {
            $st = $this->db->prepare('UPDATE rules SET hits = hits + 1, last_hit = ? WHERE id = ?');
            foreach ($hits as $h) $st->execute([time(), $h['id']]);
        } catch (Throwable) { }
    }
}
