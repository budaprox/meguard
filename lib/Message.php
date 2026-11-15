<?php
// MEGuard – reads a MailEnable queue file and message

declare(strict_types=1);

final class Message
{
    public string $id;            // file name, e.g. ABC123.MAI
    public string $cmdPath;       // queue (command) file
    public string $msgPath;       // message
    public array  $cmd = [];      // queue file fields
    public string $raw = '';      // the message (at most read_limit bytes)
    public int    $size = 0;
    public string $headerBlock = '';
    public array  $headers = [];  // lower-case name => [values]

    private ?string $bodyText = null;

    public const READ_LIMIT = 1048576; // 1 MB for content checks

    public static function locate(string $queuesDir, string $connector, string $file): ?self
    {
        $file = basename($file);
        if (!preg_match('/^[A-Za-z0-9_\-]+\.MAI$/i', $file)) return null;
        $connector = preg_replace('/[^A-Za-z]/', '', $connector);
        $inbound = meg_path($queuesDir, $connector, 'Inbound');
        $cmd = meg_path($inbound, $file);
        $msg = meg_path($inbound, 'Messages', $file);
        if (!is_file($msg)) return null;
        $m = new self();
        $m->id = $file;
        $m->cmdPath = $cmd;
        $m->msgPath = $msg;
        return $m;
    }

    public function load(): void
    {
        if (is_file($this->cmdPath)) {
            $this->cmd = self::parseCommand((string)file_get_contents($this->cmdPath));
        }
        $this->size = (int)filesize($this->msgPath);
        $fh = fopen($this->msgPath, 'rb');
        $this->raw = $fh ? (string)fread($fh, self::READ_LIMIT) : '';
        if ($fh) fclose($fh);
        $this->parseHeaders();
    }

    public static function parseCommand(string $txt): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $txt) as $line) {
            $p = strpos($line, '=');
            if ($p === false) continue;
            $k = trim(substr($line, 0, $p));
            if ($k === '' || isset($out[$k])) continue;
            $out[$k] = substr($line, $p + 1);
        }
        return $out;
    }

    private function parseHeaders(): void
    {
        $pos = strpos($this->raw, "\r\n\r\n");
        $sepLen = 4;
        if ($pos === false) { $pos = strpos($this->raw, "\n\n"); $sepLen = 2; }
        $this->headerBlock = $pos === false ? $this->raw : substr($this->raw, 0, $pos);
        $this->headers = self::splitHeaders($this->headerBlock);
    }

    public static function splitHeaders(string $block): array
    {
        $out = [];
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $block);
        foreach (preg_split('/\r?\n/', (string)$unfolded) as $line) {
            $p = strpos($line, ':');
            if ($p === false) continue;
            $out[strtolower(trim(substr($line, 0, $p)))][] = trim(substr($line, $p + 1));
        }
        return $out;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    // ---- Queue file fields --------------------------------------------------------

    public function clientIp(): string
    {
        return trim($this->cmd['ClientIP'] ?? $this->cmd['IPAddress'] ?? '');
    }

    public function isAuthenticated(): bool
    {
        $a = trim($this->cmd['AuthenticationStatus'] ?? '');
        $u = trim($this->cmd['User'] ?? '');
        return ($a !== '' && $a !== '0') || $u !== '';
    }

    public function authUser(): string
    {
        return trim($this->cmd['User'] ?? '');
    }

    public static function stripMeAddr(string $a): string
    {
        // [SMTP:valaki@pelda.hu] -> valaki@pelda.hu
        $a = trim($a);
        if (preg_match('/^\[(?:SMTP|SF|LS|[A-Z]+):(.*)\]$/i', $a, $m)) $a = $m[1];
        return strtolower(trim($a, " <>\t"));
    }

    public function sender(): string
    {
        return self::stripMeAddr($this->cmd['Sender'] ?? '');
    }

    /** @return string[] */
    public function recipients(): array
    {
        $r = [];
        foreach (explode(';', $this->cmd['Recipients'] ?? '') as $x) {
            $x = self::stripMeAddr($x);
            if ($x !== '') $r[] = $x;
        }
        return array_values(array_unique($r));
    }

    // ---- Headers --------------------------------------------------------------

    public static function decodeMime(string $s): string
    {
        if (strpos($s, '=?') === false) return self::toUtf8($s, '');
        $d = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($d === false || $d === '') $d = @mb_decode_mimeheader($s);
        return (string)$d;
    }

    public function subject(): string
    {
        $s = $this->header('subject');
        if ($s === null) $s = $this->cmd['Subject'] ?? '';
        return self::decodeMime($s);
    }

    public function fromHeader(): string
    {
        return self::decodeMime($this->header('from') ?? '');
    }

    public function fromAddress(): string
    {
        $f = $this->header('from') ?? '';
        if (preg_match('/<([^>]+)>/', $f, $m)) return strtolower(trim($m[1]));
        if (preg_match('/[^\s"<>]+@[^\s"<>]+/', $f, $m)) return strtolower(trim($m[0]));
        return '';
    }

    public function decodedHeaders(): string
    {
        $out = '';
        foreach ($this->headers as $name => $vals) {
            foreach ($vals as $v) $out .= $name . ': ' . self::decodeMime($v) . "\n";
        }
        return $out;
    }

    // ---- Body (text for the keyword rules) -------------------------------------

    public function bodyText(): string
    {
        if ($this->bodyText !== null) return $this->bodyText;
        $parts = [];
        self::collectText($this->raw, $parts, 0);
        $txt = implode("\n", $parts);
        return $this->bodyText = mb_substr($txt, 0, 300000);
    }

    private static function collectText(string $entity, array &$out, int $depth): void
    {
        if ($depth > 6 || count($out) > 20) return;
        $pos = strpos($entity, "\r\n\r\n"); $sl = 4;
        if ($pos === false) { $pos = strpos($entity, "\n\n"); $sl = 2; }
        if ($pos === false) return;
        $h = self::splitHeaders(substr($entity, 0, $pos));
        $body = substr($entity, $pos + $sl);
        $ctype = strtolower($h['content-type'][0] ?? 'text/plain');
        $cte = strtolower(trim($h['content-transfer-encoding'][0] ?? ''));

        if (str_starts_with($ctype, 'multipart/')) {
            if (!preg_match('/boundary\s*=\s*"?([^";]+)"?/i', $h['content-type'][0] ?? '', $m)) return;
            $b = '--' . trim($m[1]);
            $chunks = explode($b, $body);
            array_shift($chunks); // preambulum
            foreach ($chunks as $c) {
                if (str_starts_with($c, '--')) break; // closing boundary
                self::collectText(ltrim($c, "\r\n"), $out, $depth + 1);
            }
            return;
        }
        if (!str_starts_with($ctype, 'text/') && $ctype !== '') return;

        if ($cte === 'base64') $body = (string)base64_decode(preg_replace('/\s+/', '', $body), false);
        elseif ($cte === 'quoted-printable') $body = quoted_printable_decode($body);

        $charset = '';
        if (preg_match('/charset\s*=\s*"?([^";\s]+)"?/i', $h['content-type'][0] ?? '', $m)) $charset = $m[1];
        $body = self::toUtf8($body, $charset);

        if (str_contains($ctype, 'html')) {
            $body = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', ' ', $body);
            $body = html_entity_decode(strip_tags((string)$body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $out[] = (string)preg_replace('/[ \t\x{00A0}]+/u', ' ', (string)$body);
    }

    public static function toUtf8(string $s, string $charset): string
    {
        $charset = strtoupper(trim($charset));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
            if (mb_check_encoding($s, 'UTF-8')) return $s;
            $charset = 'ISO-8859-2';
        }
        $r = @iconv($charset, 'UTF-8//IGNORE', $s);
        if ($r === false) $r = @mb_convert_encoding($s, 'UTF-8', 'ISO-8859-2');
        return (string)$r;
    }

    // ---- Modification -------------------------------------------------------------

    /**
     * New headers at the top of the message and an optional subject prefix.
     * Written back atomically (temporary file + rename).
     */
    public function rewrite(array $addHeaders, ?string $subjectPrefix): void
    {
        $data = (string)file_get_contents($this->msgPath);
        $pos = strpos($data, "\r\n\r\n");
        $nl = "\r\n";
        if ($pos === false) { $pos = strpos($data, "\n\n"); $nl = "\n"; }
        if ($pos === false) $pos = strlen($data);
        $head = substr($data, 0, $pos);
        $rest = substr($data, $pos);

        if ($subjectPrefix !== null && $subjectPrefix !== '') {
            $count = 0;
            $head = preg_replace_callback('/^(Subject:[ \t]*)(.*)$/mi', function ($m) use ($subjectPrefix) {
                if (stripos($m[2], $subjectPrefix) !== false) return $m[0];
                return $m[1] . $subjectPrefix . ' ' . $m[2];
            }, $head, 1, $count);
            if ($count === 0) $head .= $nl . 'Subject: ' . $subjectPrefix;
        }
        $prefix = '';
        foreach ($addHeaders as $h) $prefix .= $h . $nl;
        $new = $prefix . $head . $rest;

        $tmp = $this->msgPath . '.meg' . getmypid();
        if (file_put_contents($tmp, $new) !== strlen($new)) { @unlink($tmp); throw new RuntimeException('write error: ' . $tmp); }
        if (!@rename($tmp, $this->msgPath)) { @unlink($tmp); throw new RuntimeException('rename error: ' . $this->msgPath); }
    }
}
