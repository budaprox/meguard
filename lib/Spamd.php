<?php
// MEGuard – SpamAssassin (spamd) kliens, SPAMC/1.5 protokoll

declare(strict_types=1);

final class Spamd
{
    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 783,
        private int $timeout = 20
    ) {}

    /** @return array{ok:bool, score:float, required:float, is_spam:bool, symbols:string[], error:string} */
    public function check(string $message): array
    {
        $res = ['ok' => false, 'score' => 0.0, 'required' => 0.0, 'is_spam' => false, 'symbols' => [], 'error' => ''];
        $fp = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, 5);
        if (!$fp) { $res['error'] = "connect: $errstr ($errno)"; return $res; }
        stream_set_timeout($fp, $this->timeout);

        $req = "SYMBOLS SPAMC/1.5\r\nContent-length: " . strlen($message) . "\r\nUser: meguard\r\n\r\n";
        if (@fwrite($fp, $req . $message) === false) { fclose($fp); $res['error'] = 'write error'; return $res; }
        @stream_socket_shutdown($fp, STREAM_SHUT_WR);

        $resp = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === false) break;
            $resp .= $chunk;
            $meta = stream_get_meta_data($fp);
            if ($meta['timed_out']) { fclose($fp); $res['error'] = 'timeout'; return $res; }
        }
        fclose($fp);

        if (!preg_match('#^SPAMD/[\d.]+\s+(\d+)\s+(.*)#', $resp, $m)) { $res['error'] = 'invalid response'; return $res; }
        if ((int)$m[1] !== 0) { $res['error'] = 'spamd hiba: ' . trim($m[2]); return $res; }
        if (preg_match('/^Spam:\s*(True|False|Yes|No)\s*;\s*(-?[\d.]+)\s*\/\s*(-?[\d.]+)/mi', $resp, $s)) {
            $res['is_spam'] = in_array(strtolower($s[1]), ['true', 'yes'], true);
            $res['score'] = (float)$s[2];
            $res['required'] = (float)$s[3];
        } else { $res['error'] = 'missing Spam: line'; return $res; }
        $p = strpos($resp, "\r\n\r\n");
        if ($p !== false) {
            $syms = trim(substr($resp, $p + 4));
            if ($syms !== '') $res['symbols'] = array_values(array_filter(array_map('trim', explode(',', $syms))));
        }
        $res['ok'] = true;
        return $res;
    }

    public function ping(): bool
    {
        $fp = @stream_socket_client("tcp://{$this->host}:{$this->port}", $e, $s, 5);
        if (!$fp) return false;
        stream_set_timeout($fp, 5);
        fwrite($fp, "PING SPAMC/1.5\r\n\r\n");
        $r = (string)fgets($fp);
        fclose($fp);
        return str_contains($r, 'PONG');
    }
}
