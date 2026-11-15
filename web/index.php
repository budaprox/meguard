<?php
require __DIR__ . '/_init.php';
require_login();

$db = Db::get();
$q = trim((string)($_GET['q'] ?? ''));
$view = (string)($_GET['view'] ?? 'incoming');
$day1 = (string)($_GET['from'] ?? date('Y-m-d'));
$day2 = (string)($_GET['to'] ?? $day1);
$page = max(0, (int)($_GET['p'] ?? 0));
$per = 200;

$t1 = strtotime($day1 . ' 00:00:00') ?: strtotime('today');
$t2 = (strtotime($day2 . ' 00:00:00') ?: $t1) + 86400;

$where = ['ts >= ?', 'ts < ?'];
$args = [$t1, $t2];
if ($view === 'incoming') $where[] = "result NOT IN ('outgoing','skipped')";
if ($view === 'spam') $where[] = "result IN ('blacklisted','autoban','spam-low','spam-medium','spam-high')";
if ($q !== '') {
    $where[] = '(sender LIKE ? OR recipients LIKE ? OR subject LIKE ? OR ip LIKE ? OR from_hdr LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    array_push($args, $like, $like, $like, $like, $like);
}
$w = implode(' AND ', $where);

// totals for the whole period (unfiltered)
$st = $db->prepare("SELECT result, COUNT(*) n FROM messages WHERE ts >= ? AND ts < ? GROUP BY result");
$st->execute([$t1, $t2]);
$cnt = [];
foreach ($st as $r) $cnt[$r['result']] = (int)$r['n'];
$sum = fn(array $keys) => array_sum(array_map(fn($k) => $cnt[$k] ?? 0, $keys));
$tiles = [
    'stat.clean'    => [$sum(['pass', 'trusted', 'released', 'rescanned']), 'clean'],
    'stat.lists'    => [$sum(['whitelisted']), 'white'],
    'stat.spam'     => [$sum(['blacklisted', 'autoban', 'spam-low', 'spam-medium', 'spam-high']), 'spam'],
    'stat.outgoing' => [$sum(['outgoing']), 'out'],
];

// hourly distribution (single-day view)
$hours = [];
if ($t2 - $t1 === 86400) {
    $st = $db->prepare("SELECT CAST((ts - ?) / 3600 AS INTEGER) h,
        SUM(CASE WHEN result IN ('blacklisted','autoban','spam-low','spam-medium','spam-high') THEN 1 ELSE 0 END) spam,
        SUM(CASE WHEN result IN ('pass','whitelisted','trusted','released','rescanned') THEN 1 ELSE 0 END) clean
        FROM messages WHERE ts >= ? AND ts < ? GROUP BY h");
    $st->execute([$t1, $t1, $t2]);
    foreach ($st as $r) $hours[(int)$r['h']] = [(int)$r['clean'], (int)$r['spam']];
}

$st = $db->prepare("SELECT COUNT(*) FROM messages WHERE $w");
$st->execute($args);
$total = (int)$st->fetchColumn();

$st = $db->prepare("SELECT id, ts, ip, auth, sender, recipients, subject, result, action, score, details FROM messages WHERE $w ORDER BY ts DESC, id DESC LIMIT $per OFFSET " . ($page * $per));
$st->execute($args);
$rows = $st->fetchAll();

page_head(t('log.title'), 'index.php');
?>
<section class="tiles">
  <?php foreach ($tiles as $k => [$n, $cls]): ?>
    <div class="tile t-<?= $cls ?>"><div class="n"><?= $n ?></div><div class="l"><?= h(t($k)) ?></div></div>
  <?php endforeach; ?>
</section>

<?php if ($hours):
    $max = 1;
    foreach ($hours as [$c, $s]) $max = max($max, $c + $s); ?>
<section class="card chart">
  <div class="bars">
    <?php for ($i = 0; $i < 24; $i++): [$c, $s] = $hours[$i] ?? [0, 0]; ?>
      <div class="bar" title="<?= sprintf('%02d:00 – %d / %d', $i, $c, $s) ?>">
        <div class="stack">
          <div class="seg spam" data-h="<?= round($s / $max * 100, 1) ?>"></div>
          <div class="seg clean" data-h="<?= round($c / $max * 100, 1) ?>"></div>
        </div>
        <span><?= $i % 3 === 0 ? sprintf('%02d', $i) : '' ?></span>
      </div>
    <?php endfor; ?>
  </div>
  <div class="legend"><span class="sw clean"></span><?= h(t('stat.clean')) ?> <span class="sw spam"></span><?= h(t('stat.spam')) ?> <span class="muted">· 0 – <?= $max ?></span></div>
</section>
<?php endif; ?>

<form class="filters card" method="get">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="<?= h(t('log.search')) ?>">
  <select name="view">
    <option value="incoming"<?= $view === 'incoming' ? ' selected' : '' ?>><?= h(t('log.incoming')) ?></option>
    <option value="spam"<?= $view === 'spam' ? ' selected' : '' ?>><?= h(t('log.spamonly')) ?></option>
    <option value="all"<?= $view === 'all' ? ' selected' : '' ?>><?= h(t('log.all')) ?></option>
  </select>
  <label><?= h(t('log.from')) ?> <input type="date" name="from" value="<?= h(date('Y-m-d', $t1)) ?>"></label>
  <label><?= h(t('log.to')) ?> <input type="date" name="to" value="<?= h(date('Y-m-d', $t2 - 86400)) ?>"></label>
  <button class="primary"><?= h(t('log.filter')) ?></button>
  <span class="muted"><?= h(t('log.total', ['n' => $total])) ?></span>
</form>

<?php if (!$rows): ?>
  <p class="muted center"><?= h(t('log.empty')) ?></p>
<?php else: ?>
<div class="tablewrap">
<table class="log">
  <thead><tr>
    <th><?= h(t('log.time')) ?></th><th><?= h(t('log.result')) ?></th><th class="num"><?= h(t('log.score')) ?></th>
    <th><?= h(t('log.sender')) ?></th><th><?= h(t('log.recipient')) ?></th><th><?= h(t('log.subject')) ?></th><th><?= h(t('log.ip')) ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr data-href="view.php?id=<?= (int)$r['id'] ?>">
      <td class="nowrap"><?= h(fmt_time((int)$r['ts'], $t2 - $t1 > 86400)) ?></td>
      <td><?= badge($r['result']) ?><?php if ($r['action'] === 'quarantine'): ?> <span class="tag">Q</span><?php endif; ?></td>
      <td class="num nowrap"><?= $r['score'] === null ? '' : h(number_format((float)$r['score'], 1)) ?><?= country_tag($r['details'], $r['score'])['html'] ?></td>
      <td class="ell"><a href="view.php?id=<?= (int)$r['id'] ?>"><?= h($r['sender'] ?: '—') ?></a></td>
      <td class="ell"><?= h($r['recipients']) ?></td>
      <td class="ell subj"><?= h($r['subject']) ?></td>
      <td class="nowrap mono"><?= h($r['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($total > ($page + 1) * $per): ?>
  <p class="center"><a class="btn" href="?<?= h(http_build_query(['q' => $q, 'view' => $view, 'from' => $day1, 'to' => $day2, 'p' => $page + 1])) ?>"><?= h(t('log.more')) ?> →</a></p>
<?php endif; ?>
<?php endif;
page_foot();
