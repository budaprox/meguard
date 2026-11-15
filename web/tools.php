<?php
require __DIR__ . '/_init.php';
require_once dirname(__DIR__) . '/lib/Tools.php';
require_once dirname(__DIR__) . '/lib/Spamd.php';
require_login();

$si = Tools::sysinfo();
$queues = Tools::queues();
$sp = meg_config()['spamd'];
$spOk = (new Spamd($sp['host'], (int)$sp['port'], 3))->ping();

// last SpamAssassin rule update: sa-update rewrites share\<version>\updates_spamassassin_org.cf
// only when a new rule set arrived; its first line is "# UPDATE version 1939780"
$saRules = null;
$saDir = (string)(meg_config()['spamassassin_dir'] ?? meg_path(MEG_ROOT, 'spamassassin'));
foreach (glob(meg_path($saDir, 'share', '*', 'updates_spamassassin_org.cf')) ?: [] as $f) {
    $mt = (int)@filemtime($f);
    if ($saRules && $saRules['time'] >= $mt) continue;
    $first = (string)@file_get_contents($f, false, null, 0, 200);
    $saRules = ['time' => $mt, 'ver' => preg_match('/UPDATE version\s+(\d+)/', $first, $m) ? $m[1] : ''];
}

$pct = fn(float $v): string => fmt_num($v, $v < 10 ? 1 : 0) . (is_hu() ? ' %' : '%');
$lvl = fn(float $v): string => $v >= 90 ? 'hi' : ($v >= 70 ? 'mid' : 'lo');
$gb  = fn(float $b): string => fmt_num($b / 1073741824, 1) . ' GB';

$svcs = [];
foreach ($si['services'] ?? [] as $s) $svcs[$s['name']] = $s;
$mailSet = array_values(array_filter(Tools::MAIL_SET, fn($n) => isset($svcs[$n])));
$desc = fn(string $n): string => ($k = 'tools.svc.' . $n) && t($k) !== $k ? t($k) : '';

page_head(t('tools.title'), 'tools.php');
?>
<div class="pagehead">
  <h1><?= h(t('tools.title')) ?></h1>
  <a href="tools.php" class="btn small"><?= h(t('tools.refresh')) ?></a>
</div>

<?php if (empty($si['ok'])): ?>
  <div class="flash err"><?= h($si['error'] ?? '?') ?></div>
<?php else:
  $memUsed = 100 * (1 - $si['memFree'] / max(1, $si['memTotal']));
  $g = $si['groups'];
?>
<h2><?= h(t('tools.load')) ?> <small class="muted"><?= h(t('tools.measured', ['t' => date('H:i:s')])) ?></small></h2>
<section class="tiles">
  <div class="tile lv-<?= $lvl((float)$si['cpu']) ?>"><div class="n"><?= $pct((float)$si['cpu']) ?></div><div class="l"><?= h(t('tools.cpu', ['n' => $si['cpus']])) ?></div><div class="meter"><span data-w="<?= (int)$si['cpu'] ?>"></span></div></div>
  <div class="tile lv-<?= $lvl($memUsed) ?>"><div class="n"><?= $pct($memUsed) ?></div><div class="l"><?= h(t('tools.mem', ['u' => $gb($si['memTotal'] - $si['memFree']), 't' => $gb($si['memTotal'])])) ?></div><div class="meter"><span data-w="<?= (int)$memUsed ?>"></span></div></div>
  <?php foreach (['mailenable' => 'MailEnable', 'spamd' => 'SpamAssassin', 'ipban' => 'IPBan', 'apache' => 'Apache', 'php' => 'PHP', 'mysql' => 'MySQL'] as $k => $label): if (empty($g[$k])) continue; ?>
    <div class="tile lv-<?= $lvl((float)$g[$k]['cpu']) ?>"><div class="n"><?= $pct((float)$g[$k]['cpu']) ?></div><div class="l"><?= h($label) ?> · <?= h(fmt_size((int)$g[$k]['mem'])) ?></div><div class="meter"><span data-w="<?= (int)$g[$k]['cpu'] ?>"></span></div></div>
  <?php endforeach; ?>
</section>
<p class="muted small"><?= h(t('tools.load_help', ['b' => $si['boot'] ?? '?', 'os' => $si['os'] ?? ''])) ?></p>

<div class="cols2">
<section class="card diskcard">
  <h2><?= h(t('tools.disks')) ?></h2>
  <?php $nd = count($si['disks']); ?>
  <div class="disks <?= $nd <= 1 ? 'n1' : ($nd === 2 ? 'n2' : ($nd <= 4 ? 'n4' : 'nmany')) ?>">
    <?php foreach ($si['disks'] as $d): $used = 100 * (1 - $d['free'] / max(1, $d['size'])); ?>
      <div class="disk lv-<?= $lvl($used) ?>">
        <div class="donut" data-p="<?= round($used, 1) ?>"><span><b><?= $pct($used) ?></b><small><?= h(t('tools.used')) ?></small></span></div>
        <div class="mono"><b><?= h($d['drive']) ?></b></div>
        <div class="small muted"><?= h(t('tools.free', ['f' => $gb($d['free']), 't' => $gb($d['size'])])) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <h2><?= h(t('tools.procs')) ?></h2>
  <div class="tablewrap"><table class="list">
    <thead><tr><th><?= h(t('tools.proc')) ?></th><th class="num">CPU</th><th class="num"><?= h(t('tools.memory')) ?></th></tr></thead>
    <?php $procs = $si['procs']; usort($procs, fn($a, $b) => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);
    foreach ($procs as $p): ?>
      <tr><td class="mono"><?= h($p['name']) ?></td><td class="num"><?= $pct((float)$p['cpu']) ?></td><td class="num"><?= h(fmt_size((int)$p['mem'])) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
</section>
</div>
<?php endif; ?>

<section class="card">
  <h2><?= h(t('tools.queues')) ?></h2>
  <?php if (!$queues): ?><p class="muted"><?= h(t('tools.noqueues', ['d' => meg_config()['queues_dir']])) ?></p><?php else: ?>
  <div class="tablewrap"><table class="list">
    <thead><tr><th><?= h(t('tools.queue')) ?></th><th class="num"><?= h(t('tools.waiting')) ?></th><th class="num"><?= h(t('view.size')) ?></th><th><?= h(t('tools.oldest')) ?></th></tr></thead>
    <?php foreach ($queues as $q): $age = $q['oldest'] ? time() - $q['oldest'] : 0; ?>
      <tr<?= $q['count'] === 0 ? ' class="dim"' : '' ?>>
        <td><?= h($q['connector']) ?> · <?= h(t('tools.dir.' . strtolower($q['dir'])) !== 'tools.dir.' . strtolower($q['dir']) ? t('tools.dir.' . strtolower($q['dir'])) : $q['dir']) ?></td>
        <td class="num"><b><?= (int)$q['count'] ?></b></td>
        <td class="num"><?= $q['count'] ? h(fmt_size((int)$q['size'])) : '' ?></td>
        <td><?php if ($q['oldest']): ?><span class="<?= $age > 3600 ? 'warntext' : '' ?>"><?= h(fmt_time($q['oldest'])) ?> (<?= h(t('tools.ago', ['m' => (int)round($age / 60)])) ?>)</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <p class="muted small"><?= h(t('tools.queues_help')) ?></p>
  <?php endif; ?>
</section>

<?php if (!empty($si['ok'])): ?>
<section class="card">
  <div class="pagehead">
    <h2><?= h(t('tools.services')) ?></h2>
    <?php if ($mailSet): ?>
    <form method="post" action="action.php" class="inline" data-busy="<?= h(t('tools.busy')) ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="svc_restart"><input type="hidden" name="set" value="mail">
      <button class="primary small" data-confirm="<?= h(t('tools.confirm_mail', ['s' => implode(', ', $mailSet)])) ?>"><?= h(t('tools.restart_mail')) ?></button>
    </form>
    <?php endif; ?>
  </div>
  <div class="tablewrap"><table class="list">
    <thead><tr><th><?= h(t('tools.service')) ?></th><th><?= h(t('tools.status')) ?></th><th class="hide-m"><?= h(t('tools.start')) ?></th><th></th></tr></thead>
    <?php foreach ($svcs as $n => $s): $run = $s['status'] === 'Running'; ?>
      <tr>
        <td><b class="mono"><?= h($n) ?></b> <span class="muted hide-m"><?= h($s['display']) ?></span><?php if ($d = $desc($n)): ?><div class="small muted"><?= h($d) ?></div><?php endif; ?>
          <?php if (stripos($n, 'MxScan') === 0 && $s['start'] !== 'Disabled'): ?><div class="small warntext"><?= h(t('tools.mxscan_hint')) ?></div><?php endif; ?></td>
        <td><span class="badge <?= $run ? 'r-pass' : ($s['start'] === 'Disabled' ? '' : 'r-error') ?>"><?= h(t('tools.st.' . strtolower($s['status'])) !== 'tools.st.' . strtolower($s['status']) ? t('tools.st.' . strtolower($s['status'])) : $s['status']) ?></span></td>
        <td class="hide-m"><?= h($s['start']) ?></td>
        <td class="num">
          <?php if (Tools::canRestart($n)): ?>
          <form method="post" action="action.php" class="inline" data-busy="<?= h(t('tools.busy')) ?>">
            <?= csrf_field() ?><input type="hidden" name="do" value="svc_restart"><input type="hidden" name="name" value="<?= h($n) ?>">
            <button class="small" data-confirm="<?= h(t('tools.confirm', ['s' => $n])) ?>"><?= h($run ? t('tools.restart') : t('tools.startbtn')) ?></button>
          </form>
          <?php elseif (stripos($n, 'Apache') === 0): ?><span class="small muted hide-m"><?= h(t('tools.apache_note')) ?></span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</section>
<?php endif; ?>

<section class="card">
  <h2><?= h(t('tools.meguard')) ?></h2>
  <div class="tablewrap"><table class="list">
    <tr>
      <td><b>SpamAssassin</b> <span class="muted mono"><?= h($sp['host'] . ':' . $sp['port']) ?></span>
        <div class="small muted"><?php if ($saRules): $old = time() - $saRules['time'] > 14 * 86400; ?>
          <span class="<?= $old ? 'warntext' : '' ?>"><?= h(t('tools.sa_rules', ['t' => fmt_time($saRules['time'], true), 'v' => $saRules['ver'] !== '' ? $saRules['ver'] : '?'])) ?><?= $old ? ' – ' . h(t('tools.sa_rules_old')) : '' ?></span>
        <?php else: ?><?= h(t('tools.sa_rules_none')) ?><?php endif; ?></div></td>
      <td><span class="badge <?= $spOk ? 'r-pass' : 'r-error' ?>"><?= h($spOk ? t('tools.responds') : t('tools.noresp')) ?></span></td>
      <td class="num">
        <form method="post" action="action.php" class="inline" data-busy="<?= h(t('alert.sa_busy')) ?>">
          <?= csrf_field() ?><input type="hidden" name="do" value="sa_restart">
          <button class="small" data-confirm="<?= h(t('tools.confirm', ['s' => 'SpamAssassin'])) ?>"><?= h(t('tools.restart')) ?></button>
        </form>
      </td>
    </tr>
    <?php if (!empty(meg_config()['country_score']['enabled'])): require_once dirname(__DIR__) . '/lib/GeoIp.php'; $gi = GeoIp::info(); $giOld = $gi && time() - $gi[0] > 14 * 86400; ?>
    <tr>
      <td><b><?= h(t('tools.geoip')) ?></b>
        <div class="small muted"><?php if ($gi): ?><span class="<?= $giOld ? 'warntext' : '' ?>"><?= h(t('tools.geoip_state', ['t' => fmt_time($gi[0], true), 'n' => fmt_num((float)$gi[1])])) ?></span><?php else: ?><span class="warntext"><?= h(t('tools.geoip_none')) ?></span><?php endif; ?></div></td>
      <td><span class="badge <?= $gi && !$giOld ? 'r-pass' : 'r-error' ?>"><?= h($gi ? ($giOld ? t('tools.geoip_old') : t('tools.geoip_ok')) : t('tools.geoip_missing')) ?></span></td>
      <td></td>
    </tr>
    <?php endif; ?>
    <?php foreach ($si['tasks'] ?? [] as $tk): $res = (int)$tk['result']; ?>
      <tr>
        <td><span class="mono"><?= h($tk['name']) ?></span>
          <div class="small muted"><?= $tk['last'] ? h(t('tools.lastrun', ['t' => $tk['last']])) : '' ?>
          <?php if ($tk['last'] && !in_array($res, [0, 267009, 267011], true)): ?> <span class="warntext"><?= h(t('tools.exitcode', ['c' => $res > 0xFFFF ? sprintf('0x%X', $res) : (string)$res])) ?></span><?php endif; ?></div></td>
        <td><span class="badge <?= in_array($tk['state'], ['Running', 'Ready'], true) ? 'r-pass' : 'r-error' ?>"><?= h(t('tools.st.' . strtolower($tk['state'])) !== 'tools.st.' . strtolower($tk['state']) ? t('tools.st.' . strtolower($tk['state'])) : $tk['state']) ?></span></td>
        <td></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</section>
<?php
$hl = meg_data('logs', 'health.log');
$lines = is_file($hl) ? array_slice(file($hl, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -15) : [];
?>
<section class="card">
  <h2><?= h(t('tools.health')) ?></h2>
  <?php if (!$lines): ?><p class="muted"><?= h(t('tools.health_none')) ?></p><?php else: ?>
  <pre class="logtail"><?= h(implode("\n", array_reverse($lines))) ?></pre>
  <?php endif; ?>
  <p class="muted small"><?= h(t('tools.health_help')) ?></p>
</section>
<?php
// Bayes training status (written by tools\bayes-learn.php)
$bs = json_decode((string)Db::meta('bayes_state'), true) ?: [];
?>
<section class="card">
  <h2><?= h(t('tools.bayes')) ?></h2>
  <?php if (!$bs): ?><p class="muted"><?= h(t('tools.bayes_none')) ?></p><?php else: ?>
  <p><?= h(t('tools.bayes_state', [
      'spam' => $bs['nspam'] ?? '?', 'ham' => $bs['nham'] ?? '?',
      'when' => fmt_time((int)($bs['run'] ?? 0), true),
      'ls' => $bs['learned_spam'] ?? '?', 'lh' => $bs['learned_ham'] ?? '?'])) ?></p>
  <?php if (($bs['nspam'] ?? 0) < 100 || ($bs['nham'] ?? 0) < 100): ?><p class="muted"><?= h(t('tools.bayes_notyet')) ?></p><?php endif; ?>
  <?php endif; ?>
  <p class="muted small"><?= h(t('tools.bayes_help')) ?></p>
</section>
<?php $miss = ConfigCheck::missing(); ?>
<section class="card" id="config">
  <h2><?= h(t('cfg.title')) ?></h2>
  <?php if (!$miss): ?>
    <p class="muted"><?= h(t('cfg.ok')) ?></p>
  <?php else: ?>
    <p><?= h(t('cfg.intro', ['n' => count($miss)])) ?></p>
    <?php foreach ($miss as $m): ?>
      <h3 class="mono cfgpath"><?= h($m['path']) ?></h3>
      <?php if ($m['snippet'] !== ''): ?>
        <div class="cfgsnip"><pre class="logtail"><?= h($m['snippet']) ?></pre><button type="button" class="small copybtn" data-copied="<?= h(t('cfg.copied')) ?>"><?= h(t('cfg.copy')) ?></button></div>
      <?php endif; ?>
    <?php endforeach; ?>
    <p class="muted small"><?= h(t('cfg.help')) ?></p>
  <?php endif; ?>
</section>
<?php page_foot();
