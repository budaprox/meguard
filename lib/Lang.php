<?php
// MEGuard – interface texts

declare(strict_types=1);

function t(string $key, array $vars = []): string
{
    static $s = null;
    if ($s === null) {
        $lang = meg_config()['lang'] ?? 'en';
        $file = MEG_ROOT . '/lang/' . preg_replace('/[^a-z]/', '', $lang) . '.php';
        $s = is_file($file) ? require $file : [];
        $en = require MEG_ROOT . '/lang/en.php';
        $s += $en;
    }
    $txt = $s[$key] ?? $key;
    foreach ($vars as $k => $v) $txt = str_replace('{' . $k . '}', (string)$v, $txt);
    return $txt;
}
