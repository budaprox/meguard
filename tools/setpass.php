<?php
// MEGuard – creates the password hash for the web interface
// Usage: php tools\setpass.php   (asks for the password and prints the line for config.php)

declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;

echo "New password for the MEGuard web interface (at least 10 characters): ";
$p = trim((string)fgets(STDIN));
if (mb_strlen($p) < 10) { echo "\nToo short.\n"; exit(1); }
$h = password_hash($p, PASSWORD_DEFAULT);
echo "\nPaste this into the 'web' section of config.php:\n\n        'password_hash' => '" . $h . "',\n\n";
