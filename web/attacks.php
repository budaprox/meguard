<?php
require __DIR__ . '/_init.php';
require_once dirname(__DIR__) . '/lib/IPBanInfo.php';
require_login();

$range = ($_GET['range'] ?? '24h') === '7d' ? '7d' : '24h';
page_head(t('att.title'), 'attacks.php');

if (!IPBanInfo::installed()): ?>
  <h1><?= h(t('att.title')) ?></h1>
  <p class="muted"><?= h(t('att.noipban', ['d' => IPBanInfo::dir()])) ?></p>
<?php page_foot(); return; endif;

$now = time();
if ($range === '7d') { $since = strtotime('today') - 6 * 86400; $step = 86400; $buckets = 7; }
else { $since = (int)(floor($now / 3600) * 3600) - 23 * 3600; $step = 3600; $buckets = 24; }

$ev = IPBanInfo::events($since);
$banned = IPBanInfo::banned();
$bannedSet = array_flip(array_column($banned, 'ip'));
// banned list: search and paging
$bq = trim((string)($_GET['bq'] ?? ''));
$bannedShown = $bq === '' ? $banned : array_values(array_filter($banned, fn($b) => str_contains($b['ip'], $bq)));
$per = 50;
$pages = max(1, (int)ceil(count($bannedShown) / $per));
$bp = min($pages, max(1, (int)($_GET['bp'] ?? 1)));
$bannedPage = array_slice($bannedShown, ($bp - 1) * $per, $per);
// fill in missing source/try counts from the last 7 days of logs (e.g. for bans carried over from an older IPBan)
// 7-day try count and source of the banned IPs, from the log
$fill = []; $ev7 = $ev;
if ($bannedPage) {
    $ev7 = $range === '7d' ? $ev : IPBanInfo::events(strtotime('today') - 6 * 86400);
    foreach ($ev7['failures'] as [, , $fip, $fsrc]) {
        $fill[$fip]['n'] = ($fill[$fip]['n'] ?? 0) + 1;
        $fill[$fip]['src'][IPBanInfo::label($fsrc)] = true;
    }
}
$qs = fn(array $over) => '?' . h(http_build_query(array_merge(['range' => $range, 'bq' => $bq, 'bp' => $bp], $over)));

$sources = ['RDP' => 0, 'SMTP' => 0, 'IMAP' => 0, 'POP' => 0];
$series = [];
$byIp = []; $byUser = [];
foreach ($ev['failures'] as [$ts, , $ip, $src, $user]) {
    $lab = IPBanInfo::label($src);
    $sources[$lab] = ($sources[$lab] ?? 0) + 1;
    $b = (int)floor(($ts - $since) / $step);
    if ($b >= 0 && $b < $buckets) $series[$b][$lab] = ($series[$b][$lab] ?? 0) + 1;
    $byIp[$ip]['n'] = ($byIp[$ip]['n'] ?? 0) + 1;
    $byIp[$ip]['src'][$lab] = true;
    $byIp[$ip]['last'] = max($byIp[$ip]['last'] ?? 0, $ts);
    if ($user !== '' && $lab !== 'RDP') $byUser[strtolower($user)][$lab] = ($byUser[strtolower($user)][$lab] ?? 0) + 1;
}
uasort($byIp, fn($a, $b) => $b['n'] <=> $a['n']);
$existing = IPBanInfo::existingAddresses();
uksort($byUser, function ($a, $b) use ($byUser, $existing) {
    if ($existing !== null) {
        $ea = isset($existing[$a]); $eb = isset($existing[$b]);
        if ($ea !== $eb) return $eb <=> $ea; // existing accounts first
    }
    return array_sum($byUser[$b]) <=> array_sum($byUser[$a]);
});
$existCount = $existing === null ? null : count(array_filter(array_keys($byUser), fn($u) => isset($existing[$u])));
$total = array_sum($sources);
$max = 1;
for ($i = 0; $i < $buckets; $i++) $max = max($max, array_sum($series[$i] ?? []));
$colors = ['RDP' => 'rdp', 'SMTP' => 'smtp', 'IMAP' => 'imap', 'POP' => 'pop'];
?>
<div class="headrow">
  <h1><?= h(t('att.title')) ?></h1>
  <div class="tabs small">
    <a href="?range=24h" class="<?= $range === '24h' ? 'on white' : '' ?>"><?= h(t('att.24h')) ?></a>
    <a href="?range=7d" class="<?= $range === '7d' ? 'on white' : '' ?>"><?= h(t('att.7d')) ?></a>
  </div>
</div>

<section class="tiles">
  <div class="tile t-spam"><div class="n"><?= $total ?></div><div class="l"><?= h(t('att.failures')) ?></div></div>
  <?php foreach ($sources as $k => $n): if (!isset($colors[$k]) && !$n) continue; ?>
    <div class="tile t-<?= $colors[$k] ?? 'out' ?>"><div class="n"><?= $n ?></div><div class="l"><?= h($k) ?></div></div>
  <?php endforeach; ?>
  <div class="tile t-clean"><div class="n"><?= count($banned) ?></div><div class="l"><?= h(t('att.bannednow')) ?></div></div>
  <div class="tile"><div class="n"><?= $ev['bans'] ?></div><div class="l"><?= h(t('att.newbans')) ?></div></div>
</section>

<section class="card chart">
  <div class="bars">
    <?php for ($i = 0; $i < $buckets; $i++): $s = $series[$i] ?? []; $t0 = $since + $i * $step;
      $lbl = $range === '7d' ? date(is_hu() ? 'm.d.' : 'M j', $t0) : date('H', $t0);
      $tip = ($range === '7d' ? fmt_date($t0) : date('H:00', $t0)) . ' – ' . implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($s), $s)); ?>
      <div class="bar" title="<?= h($tip) ?>">
        <div class="stack">
          <?php foreach (array_reverse(array_keys($colors)) as $k): if (empty($s[$k])) continue; ?>
            <div class="seg <?= $colors[$k] ?>" data-h="<?= round($s[$k] / $max * 100, 2) ?>"></div>
          <?php endforeach; ?>
        </div>
        <span><?= ($range === '7d' || $i % 3 === 0) ? h($lbl) : '' ?></span>
      </div>
    <?php endfor; ?>
  </div>
  <div class="legend">
    <?php foreach ($colors as $k => $c): ?><span class="sw <?= $c ?>"></span><?= h($k) ?> <?php endforeach; ?>
    <span class="muted">· 0 – <?= $max ?> / <?= $range === '7d' ? h(t('att.perday')) : h(t('att.perhour')) ?></span>
  </div>
</section>

<section class="card wlbox">
  <h2><?= h(t('att.wl_title')) ?> <small class="muted"><?= count($wlist = IPBanInfo::whitelist()) ?></small></h2>
  <p class="muted small"><?= h(t('att.wl_help')) ?></p>
  <form method="post" action="action.php" class="bsearch"><?= csrf_field() ?><input type="hidden" name="do" value="wl_add">
    <input type="text" name="ip" required class="mono" placeholder="<?= h(t('att.wl_ph')) ?>">
    <button><?= h(t('att.wl_add')) ?></button>
  </form>
  <?php if ($wlist): ?>
  <div class="wlchips">
    <?php foreach ($wlist as $w): ?>
      <form method="post" action="action.php" class="inline wlchip"><?= csrf_field() ?><input type="hidden" name="do" value="wl_del"><input type="hidden" name="ip" value="<?= h($w) ?>">
        <span class="mono"><?= h($w) ?></span> <button class="small" title="<?= h(t('att.wl_del')) ?>" onclick="return confirm('<?= h(t('att.wl_del')) ?>: <?= h($w) ?>?');">×</button></form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<div class="grid2">
<section>
  <h2><?= h(t('att.bannedlist')) ?> <small class="muted"><?= count($banned) ?></small></h2>
  <?php if ($banned): ?>
  <form method="get" class="bsearch">
    <input type="hidden" name="range" value="<?= h($range) ?>">
    <input type="search" name="bq" value="<?= h($bq) ?>" placeholder="<?= h(t('att.search')) ?>" class="mono">
    <button><?= h(t('log.filter')) ?></button>
    <?php if ($bq !== ''): ?><a href="<?= $qs(['bq' => '', 'bp' => 1]) ?>"><?= h(t('att.clear')) ?></a><?php endif; ?>
  </form>
  <?php endif; ?>
  <?php if (!$bannedShown): ?><p class="muted"><?= h(t('att.none')) ?></p><?php else: ?>
  <div class="tablewrap"><table class="log">
    <thead><tr><th><?= h(t('log.ip')) ?></th><th><?= h(t('att.source')) ?></th><th><?= h(t('att.level')) ?></th><th class="num" title="<?= h(t('att.tries7_tip')) ?>"><?= h(t('att.tries7')) ?></th><th><?= h(t('att.since')) ?></th><th><?= h(t('att.until')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($bannedPage as $b): ?>
      <tr>
        <td class="mono nowrap"><?= h($b['ip']) ?></td>
        <?php $src = $b['source'] !== '' ? IPBanInfo::label($b['source']) : implode(', ', array_keys($fill[$b['ip']]['src'] ?? []));
              $tries = $fill[$b['ip']]['n'] ?? 0;
              $dur = ($b['end'] && $b['start']) ? $b['end'] - $b['start'] : 0;
              if (!$dur) { $lvl = '∞'; $lc = 'lvl3'; }
              elseif ($dur <= 2 * 3600) { $lvl = t('att.lvl_hour'); $lc = 'lvl1'; }
              elseif ($dur <= 2 * 86400) { $lvl = t('att.lvl_day'); $lc = 'lvl2'; }
              else { $lvl = t('att.lvl_week'); $lc = 'lvl3'; } ?>
        <td><?= $src !== '' ? h($src) : '<span class="muted" title="' . h(t('att.legacy_tip')) . '">' . h(t('att.legacy')) . '</span>' ?></td>
        <td><span class="lvl <?= $lc ?>"><?= h($lvl) ?></span></td>
        <td class="num"><?= $tries ?: '' ?></td>
        <td class="nowrap"><?= $b['start'] ? h(fmt_time($b['start'], true)) : '' ?></td>
        <td class="nowrap"><?= $b['end'] ? h(fmt_time($b['end'], true)) : '∞' ?></td>
        <td><form method="post" action="action.php" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="unban"><input type="hidden" name="ip" value="<?= h($b['ip']) ?>"><button class="small"><?= h(t('att.unban')) ?></button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php if ($bp > 1): ?><a class="btn" href="<?= $qs(['bp' => $bp - 1]) ?>">← <?= h(t('att.prev')) ?></a><?php endif; ?>
    <span class="muted"><?= h(t('att.page', ['p' => $bp, 'n' => $pages, 'c' => count($bannedShown)])) ?></span>
    <?php if ($bp < $pages): ?><a class="btn" href="<?= $qs(['bp' => $bp + 1]) ?>"><?= h(t('att.next')) ?> →</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</section>

<section>
  <h2><?= h(t('att.topips')) ?></h2>
  <?php if (!$byIp): ?><p class="muted"><?= h(t('att.none')) ?></p><?php else: ?>
  <div class="tablewrap"><table class="log">
    <thead><tr><th><?= h(t('log.ip')) ?></th><th><?= h(t('att.source')) ?></th><th class="num"><?= h(t('att.tries')) ?></th><th><?= h(t('att.last')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach (array_slice($byIp, 0, 15, true) as $ip => $r): ?>
      <tr>
        <td class="mono nowrap"><?= h((string)$ip) ?></td>
        <td><?= h(implode(', ', array_keys($r['src']))) ?></td>
        <td class="num"><?= $r['n'] ?></td>
        <td class="nowrap"><?= h(fmt_time($r['last'], $range === '7d')) ?></td>
        <td><?php if (isset($bannedSet[(string)$ip])): ?><span class="badge r-blacklisted"><?= h(t('att.isbanned')) ?></span>
          <?php elseif (isset($ev['ban_count'][(string)$ip])): ?><span class="badge" title="<?= h(t('att.expired_tip')) ?>"><?= h(t('att.expired')) ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <h2 class="mt"><?= h(t('att.topusers')) ?></h2>
  <p class="muted small"><?= h(t('att.topusers_help')) ?>
    <?php if ($existCount !== null): ?><br><?= h(t('att.exist_summary', ['e' => $existCount, 'n' => count($byUser)])) ?><?php endif; ?></p>
  <?php if (!$byUser): ?><p class="muted"><?= h(t('att.none')) ?></p><?php else: ?>
  <div class="tablewrap"><table class="log">
    <thead><tr><th><?= h(t('att.account')) ?></th><th><?= h(t('att.source')) ?></th><th class="num"><?= h(t('att.tries')) ?></th></tr></thead>
    <tbody>
    <?php foreach (array_slice($byUser, 0, 25, true) as $u => $r): $ex = $existing === null ? null : isset($existing[(string)$u]); ?>
      <tr<?= $ex === false ? ' class="dim"' : '' ?>>
        <td class="mono"><?= h((string)$u) ?><?php if ($ex): ?> <span class="badge r-blacklisted" title="<?= h(t('att.exists_tip')) ?>"><?= h(t('att.exists')) ?></span><?php endif; ?></td>
        <td><?= h(implode(', ', array_keys($r))) ?></td>
        <td class="num"><?= array_sum($r) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
</div>
<?php page_foot();
