<?php
// MEGuard – common bootstrap

declare(strict_types=1);

define('MEG_VERSION', '0.3.1');
define('MEG_ROOT', dirname(__DIR__));

function meg_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $file = MEG_ROOT . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($file)) $file = MEG_ROOT . DIRECTORY_SEPARATOR . 'config.sample.php';
    $cfg = require $file;
    $cfg['data_dir'] = rtrim($cfg['data_dir'], '\\/');
    $cfg['queues_dir'] = $cfg['queues_dir'] ?? ($cfg['mailenable_root'] . DIRECTORY_SEPARATOR . 'Queues');
    // time zone: MailEnable and IPBan write local time to their logs
    $tz = $cfg['timezone'] ?? (ini_get('date.timezone') ?: 'UTC');
    @date_default_timezone_set($tz);
    return $cfg;
}

function meg_path(string ...$parts): string
{
    return implode(DIRECTORY_SEPARATOR, $parts);
}

function meg_data(string ...$parts): string
{
    $dir = meg_config()['data_dir'];
    return meg_path($dir, ...$parts);
}

function meg_ensure_dir(string $dir): void
{
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

/** Error log in data\logs (plain text, one file per day) */
function meg_errlog(string $msg): void
{
    $dir = meg_data('logs');
    meg_ensure_dir($dir);
    @file_put_contents(
        meg_path($dir, 'meguard-' . date('Ymd') . '.log'),
        date('H:i:s') . "\t" . getmypid() . "\t" . $msg . "\r\n",
        FILE_APPEND | LOCK_EX
    );
}

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Lang.php';
