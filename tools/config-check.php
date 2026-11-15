<?php
// MEGuard – compares config.php with config.sample.php (missing, newer settings)
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require_once MEG_ROOT . '/lib/ConfigCheck.php';
$miss = ConfigCheck::missing();
if (!$miss) { echo "config.php is up to date: every setting is present.\n"; exit(0); }
echo count($miss) . " setting(s) missing from config.php (they currently use their defaults):\n\n";
foreach ($miss as $m) {
    echo "=== " . $m['path'] . "\n" . ($m['snippet'] !== '' ? $m['snippet'] . "\n" : '') . "\n";
}
echo "Copy the text above into config.php (inside the return [ ... ]; brackets).\n";
