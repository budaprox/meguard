<?php
// MEGuard – web interface: shared part (access, session, layout)

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/Engine.php';
require_once dirname(__DIR__) . '/lib/ConfigCheck.php';

$CFG = meg_config();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:");

// ---- Behind a local reverse proxy (Apache / IIS) the real client IP is in a header ----
if (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $fwd = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    if (filter_var($fwd, FILTER_VALIDATE_IP)) $_SERVER['REMOTE_ADDR'] = $fwd;
}

// ---- Force HTTPS (not needed when opened locally on the server) ---------------------
$isHttps = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$isHttps && !$isLocal && ($CFG['web']['force_https'] ?? true)) {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}
if ($isHttps) header('Strict-Transport-Security: max-age=31536000');

// ---- IP restriction --------------------------------------------------------
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$allowed = $CFG['web']['allowed_ips'] ?? [];
if ($allowed) {
    $okIp = false;
    foreach ($allowed as $a) if (Rules::ipMatches($remote, $a)) { $okIp = true; break; }
    if (!$okIp) { http_response_code(403); exit(htmlspecialchars(t('denied'))); }
}

// ---- Munkamenet ----------------------------------------------------------
session_name('MEGUARDSESS');
session_set_cookie_params([
    'lifetime' => 0, 'path' => dirname($_SERVER['SCRIPT_NAME']) ?: '/',
    'httponly' => true, 'samesite' => 'Strict',
    'secure' => $isHttps,
]);
session_start();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function csrf_check(): bool { return hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? '')); }

function flash(?string $msg = null, string $kind = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $kind]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $to): never { header('Location: ' . $to); exit; }

function logged_in(): bool
{
    $cfg = meg_config();
    $ttl = 60 * (int)($cfg['web']['session_minutes'] ?? 240);
    if (empty($_SESSION['auth']) || ($_SESSION['seen'] ?? 0) < time() - $ttl) return false;
    $_SESSION['seen'] = time();
    return true;
}

function require_login(): void
{
    if (!logged_in()) redirect('login.php');
}

// ---- Layout ---------------------------------------------------------
function page_head(string $title, string $active = ''): void
{
    $cfg = meg_config();
    // main menu; the Tools submenu drops down
    $nav = ['index.php' => 'nav.log', 'attacks.php' => 'nav.attacks', 'quarantine.php' => 'nav.quarantine'];
    $sub = ['tools.php' => 'nav.resources', 'rules.php' => 'nav.rules', 'lists.php' => 'nav.lists', 'geo.php' => 'nav.geo'];
    echo '<!doctype html><html lang="' . h($cfg['lang'] ?? 'en') . '"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' · ' . h(t('app')) . '</title>';
    echo '<link rel="stylesheet" href="style.css?v=' . MEG_VERSION . '"><script src="app.js?v=' . MEG_VERSION . '" defer></script></head><body>';
    echo '<header class="top"><div class="brand"><span class="logo">◆</span> ' . h(t('app')) . ' <small>' . h($cfg['server_name']) . '</small></div>';
    if (logged_in()) {
        echo '<nav>';
        foreach ($nav as $href => $key) {
            echo '<a href="' . $href . '"' . ($active === $href ? ' class="on"' : '') . '>' . h(t($key)) . '</a>';
        }
        $subOn = isset($sub[$active]);
        echo '<div class="dd"><button type="button" class="dd-btn' . ($subOn ? ' on' : '') . '" aria-haspopup="true" aria-expanded="false">'
            . h(t('nav.tools')) . ' <span class="caret">▾</span></button><div class="dd-menu">';
        foreach ($sub as $href => $key) {
            echo '<a href="' . $href . '"' . ($active === $href ? ' class="on"' : '') . '>' . h(t($key)) . '</a>';
        }
        echo '</div></div>';
        echo '<form method="post" action="action.php" class="logout">' . csrf_field() . '<input type="hidden" name="do" value="logout"><button>' . h(t('nav.logout')) . '</button></form>';
        echo '</nav>';
    }
    echo '</header><main>';
    if ($f = flash()) echo '<div class="flash ' . h($f[1]) . '">' . h($f[0]) . '</div>';
    if (logged_in()) {
        try {
            // SpamAssassin errors in the last hour: unreachable and slow (timeout) counted separately
            $st = Db::get()->prepare("SELECT ts, details FROM messages WHERE ts > ? AND details LIKE '%sa_error%'");
            $st->execute([time() - 3600]);
            $saErr = 0; $saLast = 0; $slow = 0;
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$ts, $det]) {
                $e = (string)(json_decode((string)$det, true)['sa_error'] ?? '');
                if (str_contains($e, 'timeout') || str_contains($e, 'időtúllépés')) { $slow++; continue; }
                $saErr++; $saLast = max($saLast, (int)$ts);
            }
            // if a successful SpamAssassin scan happened after the error, the error is over
            $okLast = (int)Db::get()->query('SELECT MAX(ts) FROM messages WHERE sa_score IS NOT NULL')->fetchColumn();
            $restarted = (int)(Db::meta('spamd_restarted') ?? 0);
            if ($saErr > 0 && $okLast < $saLast && $restarted < $saLast) {
                echo '<div class="flash err alertrow"><div><b>' . h(t('alert.sa_title')) . '</b> ' . h(t('alert.sa_text', ['n' => $saErr, 't' => date('H:i', $saLast)])) . '</div>'
                   . '<form method="post" action="action.php" class="inline" data-busy="' . h(t('alert.sa_busy')) . '">' . csrf_field()
                   . '<input type="hidden" name="do" value="sa_restart"><button class="small primary">' . h(t('alert.sa_button')) . '</button></form></div>';
            } elseif ($slow > 0) {
                echo '<div class="flash warn">' . h(t('alert.sa_slow', ['n' => $slow, 's' => (int)($cfg['spamd']['timeout'] ?? 20)])) . '</div>';
            }
        } catch (Throwable) { }
        // new settings in config.sample.php that are missing from config.php
        try {
            $miss = ConfigCheck::missing();
            if ($miss) {
                $sig = md5(implode('|', array_column($miss, 'path')));
                if (Db::meta('cfg_dismiss') !== $sig) {
                    $names = array_slice(array_column($miss, 'path'), 0, 4);
                    echo '<div class="flash warn alertrow"><div><b>' . h(t('cfg.banner_title', ['n' => count($miss)])) . '</b> '
                       . h(t('cfg.banner_text', ['list' => implode(', ', $names) . (count($miss) > 4 ? ', …' : '')])) . ' '
                       . '<a href="tools.php#config">' . h(t('cfg.banner_link')) . '</a></div>'
                       . '<form method="post" action="action.php" class="inline">' . csrf_field()
                       . '<input type="hidden" name="do" value="cfg_dismiss"><input type="hidden" name="sig" value="' . h($sig) . '">'
                       . '<button class="small">' . h(t('cfg.banner_hide')) . '</button></form></div>';
                }
            }
        } catch (Throwable) { }
    }
    if (($cfg['mode'] ?? 'live') !== 'live') echo '<div class="flash warn">monitor mode – ' . h(t('act.monitor')) . '</div>';
}

function page_foot(): void
{
    echo '</main><footer>MEGuard ' . MEG_VERSION . '</footer></body></html>';
}

function badge(string $result): string
{
    return '<span class="badge r-' . h($result) . '">' . h(t('res.' . $result)) . '</span>';
}

/**
 * Country score tag for the lists (Log, Quarantine), from the details JSON; only if the country added points.
 * "tip" = without the country score the message would have stayed below the spam threshold.
 * @return array{html:string, tip:bool}
 */
function country_tag(?string $detailsJson, $score): array
{
    if (!$detailsJson || !str_contains($detailsJson, '"country"')) return ['html' => '', 'tip' => false];
    $co = (json_decode($detailsJson, true) ?: [])['country'] ?? null;
    if (!$co || (float)($co['score'] ?? 0) <= 0) return ['html' => '', 'tip' => false];
    $low = (float)(meg_config()['thresholds']['low'] ?? 8);
    $tip = $score !== null && (float)$score - (float)$co['score'] < $low;
    $title = t($tip ? 'co.tip' : 'co.hit', ['cc' => $co['cc'], 's' => $co['score']]);
    return ['html' => ' <span class="cotag' . ($tip ? ' tip' : '') . '" title="' . h($title) . '">' . h($co['cc'] . ' +' . (0 + $co['score'])) . '</span>', 'tip' => $tip];
}

function is_hu(): bool
{
    return (meg_config()['lang'] ?? 'en') === 'hu';
}

/** number in the interface language's style: 1,234.5 (en) or 1 234,5 (hu) */
function fmt_num(float $v, int $dec = 0): string
{
    return is_hu() ? number_format($v, $dec, ',', ' ') : number_format($v, $dec, '.', ',');
}

function fmt_date(int $ts): string
{
    return date(is_hu() ? 'Y.m.d.' : 'Y-m-d', $ts);
}

function fmt_time(int $ts, bool $full = false): string
{
    if (!$full && date('Ymd', $ts) === date('Ymd')) return date('H:i:s', $ts);
    return date(is_hu() ? 'Y.m.d. H:i' : 'Y-m-d H:i', $ts);
}

function fmt_size(int $b): string
{
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024) . ' KB';
    return round($b / 1048576, 1) . ' MB';
}

// daily maintenance (clean up quarantine and old log entries)
if (Db::meta('cleanup') !== date('Ymd')) {
    try { Quarantine::cleanup(); Db::meta('cleanup', date('Ymd')); } catch (Throwable) { }
}
