<?php
require __DIR__ . '/_init.php';
require_login();

// only quarantined messages that still exist (within retention); maintenance has already deleted older ones
$since = strtotime(date('Y-m-d', time() - 86400 * (int)$CFG['quarantine_days']));
$db = Db::get();
$cnt = $db->prepare("SELECT COUNT(*) FROM messages WHERE action = 'quarantine' AND released = 0 AND ts >= ?");
$cnt->execute([$since]);
$total = (int)$cnt->fetchColumn();
$onlyCo = !empty($_GET['co']);
$st = $db->prepare("SELECT id, ts, ip, sender, recipients, subject, result, score, details FROM messages
    WHERE action = 'quarantine' AND released = 0 AND ts >= ? ORDER BY ts DESC" . ($onlyCo ? '' : ' LIMIT 500'));
$st->execute([$since]);
$rows = [];
$nTip = 0;
foreach ($st as $r) {
    $r['co'] = country_tag($r['details'], $r['score']);
    if ($r['co']['tip']) $nTip++;
    if ($onlyCo && !$r['co']['tip']) continue;
    $rows[] = $r;
    if (count($rows) >= 500) break;
}

page_head(t('q.title'), 'quarantine.php');
?>
<h1><?= h(t('q.title')) ?></h1>
<p class="muted"><?php if ($total): ?><strong><?= h(t('q.count', ['n' => $total])) ?></strong> <?php endif; ?><?= h(t('q.hint', ['d' => $CFG['quarantine_days']])) ?></p>
<?php if ($nTip || $onlyCo): ?>
<p class="qfilter">
  <?php if ($onlyCo): ?><a class="btn small" href="quarantine.php"><?= h(t('q.co_all')) ?></a> <span class="muted"><?= h(t('q.co_showing', ['n' => count($rows)])) ?></span>
  <?php else: ?><a class="btn small" href="quarantine.php?co=1"><?= h(t('q.co_only', ['n' => $nTip])) ?></a> <span class="muted small"><?= h(t('q.co_help')) ?></span><?php endif; ?>
</p>
<?php endif; ?>
<?php if (!$rows): ?>
  <p class="muted center"><?= h(t('q.empty')) ?></p>
<?php else: ?>
<div class="tablewrap">
<table class="log">
  <thead><tr>
    <th><?= h(t('log.time')) ?></th><th><?= h(t('log.result')) ?></th><th class="num"><?= h(t('log.score')) ?></th>
    <th><?= h(t('log.sender')) ?></th><th><?= h(t('log.recipient')) ?></th><th><?= h(t('log.subject')) ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr data-href="view.php?id=<?= (int)$r['id'] ?>">
      <td class="nowrap"><?= h(fmt_time((int)$r['ts'], true)) ?></td>
      <td><?= badge($r['result']) ?></td>
      <td class="num nowrap"><?= $r['score'] === null ? '' : h(number_format((float)$r['score'], 1)) ?><?= $r['co']['html'] ?></td>
      <td class="ell"><a href="view.php?id=<?= (int)$r['id'] ?>"><?= h($r['sender'] ?: '—') ?></a></td>
      <td class="ell"><?= h($r['recipients']) ?></td>
      <td class="ell subj"><?= h($r['subject']) ?></td>
      <td class="nowrap">
        <form method="post" action="action.php" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="release"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="small primary"><?= h(t('view.release')) ?></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif;
page_foot();
