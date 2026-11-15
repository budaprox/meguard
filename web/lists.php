<?php
require __DIR__ . '/_init.php';
require_login();

$list = ($_GET['list'] ?? 'white') === 'black' ? 'black' : 'white';
$kind = in_array($_GET['kind'] ?? '', ['sender', 'recipient', 'ip'], true) ? $_GET['kind'] : 'sender';

$db = Db::get();
$counts = [];
foreach ($db->query('SELECT list, kind, COUNT(*) n FROM lists GROUP BY list, kind') as $r) $counts[$r['list']][$r['kind']] = (int)$r['n'];
$st = $db->prepare('SELECT * FROM lists WHERE list = ? AND kind = ? ORDER BY value');
$st->execute([$list, $kind]);
$rows = $st->fetchAll();

page_head(t('lists.title'), 'lists.php');
?>
<h1><?= h(t('lists.title')) ?></h1>
<div class="tabs">
  <?php foreach (['white', 'black'] as $l): foreach (['sender', 'recipient', 'ip'] as $k): ?>
    <a class="<?= $l ?><?= ($l === $list && $k === $kind) ? ' on' : '' ?>" href="?list=<?= $l ?>&amp;kind=<?= $k ?>">
      <?= h(t('lists.' . $l)) ?> · <?= h(t('lists.' . $k)) ?> <small><?= $counts[$l][$k] ?? 0 ?></small></a>
  <?php endforeach; endforeach; ?>
</div>

<form method="post" action="action.php" class="card addform">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="listadd"><input type="hidden" name="list" value="<?= $list ?>"><input type="hidden" name="kind" value="<?= $kind ?>">
  <input name="value" required placeholder="<?= h(t('lists.value')) ?>" class="mono">
  <input name="comment" placeholder="<?= h(t('lists.comment')) ?>">
  <button class="primary"><?= h(t('lists.add')) ?></button>
  <p class="muted small"><?= h(t('lists.help')) ?></p>
</form>

<input type="search" class="listfilter" placeholder="<?= h(t('lists.filter')) ?>" data-filter="#listtable">
<div class="tablewrap">
<table class="log" id="listtable">
  <thead><tr><th><?= h(t('lists.value')) ?></th><th><?= h(t('lists.comment')) ?></th><th class="num"><?= h(t('lists.hits')) ?></th><th><?= h(t('lists.last')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="mono"><?= h($r['value']) ?></td>
      <td><?php if (!empty($r['auto'])): ?><span class="badge r-auto"><?= h(t('lists.auto')) ?></span> <?php endif; ?><?= h($r['comment']) ?>
        <?php if (!empty($r['expires'])): ?><br><span class="muted small"><?= h(t((int)$r['expires'] > time() ? 'lists.expires' : 'lists.expired', ['d' => fmt_time((int)$r['expires'], true)])) ?></span><?php endif; ?></td>
      <td class="num"><?= (int)$r['hits'] ?></td>
      <td class="nowrap"><?= $r['last_hit'] ? h(fmt_time((int)$r['last_hit'], true)) : '' ?></td>
      <td><form method="post" action="action.php" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="listdel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="small danger" data-confirm="<?= h(t('confirm.delete')) ?>"><?= h(t('lists.del')) ?></button></form></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_foot();
