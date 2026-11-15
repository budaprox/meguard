<?php
// MEGuard – compares config.php with config.sample.php: which settings (introduced in a newer version)
// are missing from config.php. A missing setting is not an error – MEGuard uses the built-in default –
// it just does not show that it can be changed.

declare(strict_types=1);

final class ConfigCheck
{
    /**
     * @return array<int, array{path:string, snippet:string}>  the missing settings (dotted, e.g. "health.alert_from")
     *                 and their text to copy from config.sample.php (with the explaining comment)
     */
    public static function missing(): array
    {
        $sampleFile = meg_path(MEG_ROOT, 'config.sample.php');
        $userFile = meg_path(MEG_ROOT, 'config.php');
        if (!is_file($sampleFile) || !is_file($userFile)) return [];
        try {
            $sample = (static fn($f) => require $f)($sampleFile);
            $user = (static fn($f) => require $f)($userFile);
        } catch (Throwable) {
            return [];
        }
        if (!is_array($sample) || !is_array($user)) return [];
        $paths = [];
        self::walk($sample, $user, '', $paths);
        if (!$paths) return [];
        $lines = file($sampleFile, FILE_IGNORE_NEW_LINES) ?: [];
        $out = [];
        foreach ($paths as $p) $out[] = ['path' => $p, 'snippet' => self::snippet($lines, $p)];
        return $out;
    }

    private static function walk(array $sample, array $user, string $prefix, array &$paths): void
    {
        foreach ($sample as $k => $v) {
            if (is_int($k)) return;                             // list (e.g. dnsbl, trusted_ips): its items are not required
            $path = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (!array_key_exists($k, $user)) { $paths[] = $path; continue; }
            if (is_array($v) && is_array($user[$k]) && $v && !array_is_list($v)) self::walk($v, $user[$k], $path, $paths);
        }
    }

    /** The setting's text from config.sample.php: the preceding comment + the line / block itself */
    private static function snippet(array $lines, string $path): string
    {
        $parts = explode('.', $path);
        $key = end($parts);
        $depth = count($parts);
        $indent = str_repeat(' ', 4 * $depth);
        $start = null;
        foreach ($lines as $i => $l) {
            if (preg_match('/^' . preg_quote($indent, '/') . "'" . preg_quote($key, '/') . "'\\s*=>/", $l)) { $start = $i; break; }
        }
        if ($start === null) return '';
        $from = $start;
        while ($from > 0 && preg_match('/^\s*\/\//', $lines[$from - 1])) $from--;
        $to = $start;
        if (preg_match('/\[\s*(\/\/.*)?$/', $lines[$start])) {          // multi-line block: up to its "],"
            for ($j = $start + 1; $j < count($lines); $j++) {
                if (preg_match('/^' . preg_quote($indent, '/') . '\],?\s*$/', $lines[$j])) { $to = $j; break; }
            }
        }
        return implode("\n", array_slice($lines, $from, $to - $from + 1));
    }
}
