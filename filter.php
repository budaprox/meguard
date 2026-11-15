<?php
// MEGuard – entry point for the MailEnable MTA pickup event
// Called as: php.exe filter.php <message file.MAI> <connector, e.g. SMTP>
// Always exits with 0: on any error the message passes through untouched.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

set_time_limit(90);
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    require __DIR__ . '/lib/bootstrap.php';
    require __DIR__ . '/lib/Engine.php';
    $file = $argv[1] ?? '';
    $connector = $argv[2] ?? 'SMTP';
    if ($file !== '') (new Engine())->process($file, $connector);
} catch (Throwable $e) {
    if (function_exists('meg_errlog')) meg_errlog('FATAL: ' . $e->getMessage());
}
exit(0);
